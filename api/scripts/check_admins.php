<?php
require __DIR__ . '/../bootstrap.php';
use App\Core\Database;

$db = Database::connection();
$rows = $db->query("SELECT id, email, phone, role, created_at FROM users WHERE role != 'donor' ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
echo "Non-donor accounts:\n";
foreach ($rows as $r) {
    echo "  [{$r['role']}] {$r['email']} | phone: {$r['phone']}\n";
}
