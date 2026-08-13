<?php
require __DIR__ . '/../bootstrap.php';
use App\Core\Database;

$db = Database::connection();

// Search by last 4 digits of NID shown on USSD: 0123
$rows = $db->query("SELECT u.id, u.full_name, u.phone, u.national_id_last_four, dp.donor_code
    FROM users u
    LEFT JOIN donor_profiles dp ON dp.user_id = u.id
    WHERE u.role = 'donor'
    AND (u.national_id_last_four = '0123' OR u.phone LIKE '%76123%' OR u.phone LIKE '%78123%')
    ORDER BY u.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($rows) . " match(es):\n";
foreach ($rows as $r) {
    echo "  ID:{$r['id']} | {$r['full_name']} | Phone:{$r['phone']} | NID_last4:{$r['national_id_last_four']} | Code:{$r['donor_code']}\n";
}

echo "\n--- Last 10 registered donors ---\n";
$recent = $db->query("SELECT u.id, u.full_name, u.phone, u.national_id_last_four FROM users u WHERE u.role='donor' ORDER BY u.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($recent as $r) {
    echo "  ID:{$r['id']} | {$r['full_name']} | Phone:{$r['phone']} | NID_last4:{$r['national_id_last_four']}\n";
}
