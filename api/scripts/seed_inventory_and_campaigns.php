<?php

declare(strict_types=1);

/**
 * DonorConnect — Update inventory + seed two campaigns
 *
 * Run:  C:\xampp\php\php.exe api/scripts/seed_inventory_and_campaigns.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;

$db = Database::connection();

// ─── Admin user (needed as campaign creator) ──────────────────────────────────
$adminRow = $db->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
if (!$adminRow) {
    echo "ERROR: No admin found. Run seed_enbts_demo.php first.\n";
    exit(1);
}
$adminId = (int) $adminRow['id'];

// ─── Fetch the 3 ENBTS blood banks ────────────────────────────────────────────
$banks = $db->query(
    "SELECT id, name, region FROM institutions WHERE institution_type = 'blood_service' ORDER BY id ASC"
)->fetchAll();

if (count($banks) < 3) {
    echo "ERROR: Expected 3 blood-service institutions.\n";
    exit(1);
}

// ─── Updated inventory (reflects ~400-donor active pool) ──────────────────────
// Stock scales with known blood type distribution:
//   O+(most common), A+, B+, AB+, then all negatives much rarer.
// Mbabane (central hub) carries more; branches carry proportionally less.
$stock = [
    // Mbabane Blood Bank — main hub, highest volume
    $banks[0]['id'] => ['A+'=>48, 'A-'=>14, 'B+'=>32, 'B-'=>10, 'AB+'=>16, 'AB-'=>5, 'O+'=>62, 'O-'=>18],
    // Manzini Branch
    $banks[1]['id'] => ['A+'=>30, 'A-'=>8,  'B+'=>22, 'B-'=>6,  'AB+'=>10, 'AB-'=>3, 'O+'=>42, 'O-'=>12],
    // Hlathikhulu Branch
    $banks[2]['id'] => ['A+'=>20, 'A-'=>5,  'B+'=>15, 'B-'=>4,  'AB+'=>7,  'AB-'=>2, 'O+'=>28, 'O-'=>8],
];

echo "Updating blood inventory...\n";
$upsert = $db->prepare(
    "INSERT INTO blood_inventory
         (institution_id, blood_type, available_units, reserved_units, expired_units, critical_threshold)
     VALUES (:institution_id, :blood_type, :units, 0, 0, 8)
     ON DUPLICATE KEY UPDATE
         available_units   = VALUES(available_units),
         critical_threshold = VALUES(critical_threshold),
         updated_at        = NOW()"
);

$bloodTypes = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
foreach ($stock as $bankId => $units) {
    $bankName = '';
    foreach ($banks as $b) { if ((int)$b['id'] === $bankId) $bankName = $b['name']; }
    foreach ($bloodTypes as $type) {
        $upsert->execute([
            'institution_id' => $bankId,
            'blood_type'     => $type,
            'units'          => $units[$type] ?? 0,
        ]);
    }
    $total = array_sum($units);
    echo "  ✓ {$bankName}  ({$total} units across all types)\n";
}

// ─── Campaigns ────────────────────────────────────────────────────────────────
echo "\nSeeding campaigns...\n";

// Fetch a Manzini blood bank id for campaign 1
$manziniBank = null;
$hlathiBank  = null;
foreach ($banks as $b) {
    if (str_contains($b['name'], 'Manzini'))    $manziniBank = (int) $b['id'];
    if (str_contains($b['name'], 'Hlathikhulu')) $hlathiBank  = (int) $b['id'];
}
$manziniBank = $manziniBank ?? (int) $banks[1]['id'];
$hlathiBank  = $hlathiBank  ?? (int) $banks[2]['id'];

$campaigns = [
    [
        'institution_id'     => $manziniBank,
        'created_by'         => $adminId,
        'title'              => 'Manzini Winter Blood Drive 2026',
        'description'        => 'ENBTS Manzini Branch invites all eligible donors to give blood this winter. All blood types are urgently needed. Walk-ins welcome — no appointment required. Refreshments provided.',
        'campaign_type'      => 'donation_drive',
        'target_region'      => 'Manzini',
        'target_town'        => 'Manzini',
        'target_blood_type'  => 'All',
        'venue'              => 'ENBTS Manzini Branch, Nkoseluhlaza Street, Manzini',
        'starts_at'          => date('Y-m-d 08:00:00', strtotime('+7 days')),
        'ends_at'            => date('Y-m-d 16:00:00', strtotime('+7 days')),
        'capacity'           => 120,
        'status'             => 'scheduled',
    ],
    [
        'institution_id'     => $hlathiBank,
        'created_by'         => $adminId,
        'title'              => 'Shiselweni Region Donor Recruitment Drive',
        'description'        => 'Join ENBTS as we recruit new blood donors across the Shiselweni region. Staff will register new donors, conduct eligibility assessments and record donations on-site. We especially need O+ and B+ donors. Schools, churches and workplaces are welcome to send group participants.',
        'campaign_type'      => 'recruitment',
        'target_region'      => 'Shiselweni',
        'target_town'        => null,
        'target_blood_type'  => 'All',
        'venue'              => 'Nhlangano Civic Centre, Nhlangano, Shiselweni',
        'starts_at'          => date('Y-m-d 07:30:00', strtotime('+21 days')),
        'ends_at'            => date('Y-m-d 15:00:00', strtotime('+21 days')),
        'capacity'           => 200,
        'status'             => 'scheduled',
    ],
];

$insert = $db->prepare(
    "INSERT INTO campaigns
         (institution_id, created_by, title, description, campaign_type,
          target_region, target_town, target_blood_type, venue,
          starts_at, ends_at, capacity, status)
     VALUES
         (:institution_id, :created_by, :title, :description, :campaign_type,
          :target_region, :target_town, :target_blood_type, :venue,
          :starts_at, :ends_at, :capacity, :status)
     ON DUPLICATE KEY UPDATE status = status"   // noop if duplicate title somehow snuck in
);

// Use title uniqueness to avoid re-seeding on re-run
$checkCampaign = $db->prepare("SELECT id FROM campaigns WHERE title = :title LIMIT 1");

foreach ($campaigns as $c) {
    $checkCampaign->execute(['title' => $c['title']]);
    $existing = $checkCampaign->fetchColumn();
    if ($existing) {
        echo "  ~ Already exists: {$c['title']}\n";
        continue;
    }
    $insert->execute($c);
    $cid = (int) $db->lastInsertId();
    echo "  ✓ [{$c['campaign_type']}] {$c['title']} (id={$cid}, starts {$c['starts_at']})\n";
}

// ─── Summary ──────────────────────────────────────────────────────────────────
$totalUnits    = (int) $db->query("SELECT SUM(available_units) FROM blood_inventory")->fetchColumn();
$campaignCount = (int) $db->query("SELECT COUNT(*) FROM campaigns")->fetchColumn();

echo "\n";
echo str_repeat('─', 54) . "\n";
echo " Done!\n";
printf("  Total blood units in inventory : %d\n", $totalUnits);
printf("  Total campaigns in system      : %d\n", $campaignCount);
echo str_repeat('─', 54) . "\n\n";
