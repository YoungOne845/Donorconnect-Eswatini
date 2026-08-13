<?php
require __DIR__ . '/../bootstrap.php';
use App\Core\Database;

$db = Database::connection();

$updates = [
    ['name' => 'Sihle Mhlanga',        'old_phone' => '76123456', 'new_phone' => '+26879586436'],
    ['name' => 'Thandolwethu Magaya',  'old_phone' => '78123456', 'new_phone' => '+26878294833'],
];

foreach ($updates as $u) {
    // Find user by full_name
    $stmt = $db->prepare("SELECT id, full_name, phone FROM users WHERE full_name = :name LIMIT 1");
    $stmt->execute(['name' => $u['name']]);
    $user = $stmt->fetch();

    if (!$user) {
        echo "NOT FOUND: {$u['name']}\n";
        continue;
    }

    $update = $db->prepare("UPDATE users SET phone = :phone WHERE id = :id");
    $update->execute(['phone' => $u['new_phone'], 'id' => $user['id']]);

    echo "UPDATED: {$user['full_name']} | old: {$user['phone']} -> new: {$u['new_phone']}\n";
}

echo "\nDone.\n";
