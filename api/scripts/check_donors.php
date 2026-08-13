<?php
require __DIR__ . '/../bootstrap.php';
use App\Core\Database;

$db = Database::connection();

$total = $db->query("SELECT COUNT(*) FROM users WHERE role = 'donor'")->fetchColumn();
echo "Total donor accounts: $total\n\n";

$rows = $db->query("SELECT u.id, u.email, u.phone, d.first_name, d.last_name, d.status
    FROM users u
    LEFT JOIN donors d ON d.user_id = u.id
    WHERE u.role = 'donor'
    ORDER BY u.created_at DESC
    LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

echo "Latest 20 donors:\n";
foreach ($rows as $r) {
    echo "  ID:{$r['id']} | {$r['first_name']} {$r['last_name']} | {$r['email']} | Phone:{$r['phone']} | Status:{$r['status']}\n";
}
