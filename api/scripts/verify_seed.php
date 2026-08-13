<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
use App\Core\Database;

$db = Database::connection();

$line = str_repeat('─', 54);
echo "\n{$line}\n DonorConnect — Database state after seed\n{$line}\n\n";

// Institutions
echo "INSTITUTIONS\n";
foreach ($db->query("SELECT institution_type, COUNT(*) c FROM institutions GROUP BY institution_type ORDER BY c DESC") as $r) {
    printf("  %-28s %3d\n", $r['institution_type'] . ':', $r['c']);
}
$iTotal = $db->query("SELECT COUNT(*) FROM institutions")->fetchColumn();
printf("  %-28s %3d\n", 'TOTAL:', $iTotal);

// Donors – verification
echo "\nDONOR VERIFICATION STATUS\n";
foreach ($db->query("SELECT verification_status, COUNT(*) c FROM donor_profiles GROUP BY verification_status") as $r) {
    printf("  %-24s %4d\n", $r['verification_status'] . ':', $r['c']);
}

// Donors – eligibility
echo "\nDONOR ELIGIBILITY STATUS\n";
foreach ($db->query("SELECT eligibility_status, COUNT(*) c FROM donor_profiles GROUP BY eligibility_status") as $r) {
    printf("  %-28s %4d\n", $r['eligibility_status'] . ':', $r['c']);
}

// Blood types
echo "\nBLOOD TYPE MIX  (verified donors only)\n";
foreach ($db->query("SELECT blood_type, COUNT(*) c FROM donor_profiles WHERE verification_status='verified' GROUP BY blood_type ORDER BY c DESC") as $r) {
    printf("  %-8s %4d\n", $r['blood_type'] . ':', $r['c']);
}

// Totals
$donors    = $db->query("SELECT COUNT(*) FROM donor_profiles")->fetchColumn();
$donRecs   = $db->query("SELECT COUNT(*) FROM donation_records")->fetchColumn();
$users     = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$adminChk  = $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
echo "\nSUMMARY\n";
printf("  %-28s %4d\n", 'Total users:', $users);
printf("  %-28s %4d\n", 'Donor profiles:', $donors);
printf("  %-28s %4d\n", 'Donation records:', $donRecs);
printf("  %-28s %4d\n", 'Admin accounts:', $adminChk);
echo "\n{$line}\n";

// Protected institution guard check
echo "INSTITUTION PROTECTION CHECK\n";
$protected = ['Mbabane Blood Bank','Manzini Blood Bank','Hlathikhulu Blood Bank'];
foreach ($protected as $name) {
    $row = $db->prepare("SELECT id, is_active FROM institutions WHERE name=:n LIMIT 1");
    $row->execute(['n' => $name]);
    $inst = $row->fetch();
    $status = $inst ? "  id={$inst['id']}, active={$inst['is_active']}  ✓ PRESENT" : "  !! MISSING";
    printf("  %-35s %s\n", $name . ':', $status);
}
echo "{$line}\n\n";
