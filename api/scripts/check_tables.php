<?php
require __DIR__ . '/../bootstrap.php';
use App\Core\Database;

$db = Database::connection();
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables in DB:\n";
foreach ($tables as $t) {
    $count = $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "  $t => $count rows\n";
}
