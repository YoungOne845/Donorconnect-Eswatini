<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\Identity;
use App\Core\Crypto;

$db = Database::connection();
$crypto = App::crypto();

function upsertInstitution(PDO $db, string $name, string $type, string $region, string $town, string $phone, string $email, string $address): int
{
    $select = $db->prepare('SELECT id FROM institutions WHERE name = :name LIMIT 1');
    $select->execute(['name' => $name]);
    $id = $select->fetchColumn();
    if ($id) {
        $update = $db->prepare('UPDATE institutions SET institution_type = :type, region = :region, town = :town, phone = :phone, email = :email, address = :address, is_active = 1 WHERE id = :id');
        $update->execute(['type' => $type, 'region' => $region, 'town' => $town, 'phone' => $phone, 'email' => $email, 'address' => $address, 'id' => $id]);
        return (int) $id;
    }
    $insert = $db->prepare('INSERT INTO institutions (name, institution_type, phone, email, region, town, address, is_active) VALUES (:name, :type, :phone, :email, :region, :town, :address, 1)');
    $insert->execute(['name' => $name, 'type' => $type, 'phone' => $phone, 'email' => $email, 'region' => $region, 'town' => $town, 'address' => $address]);
    return (int) $db->lastInsertId();
}

function upsertUser(PDO $db, Crypto $crypto, array $account): int
{
    $nationalId = Identity::nationalId($account['national_id']);
    $phone = Identity::phone($account['phone']);
    $hash = $crypto->searchHash($nationalId);
    $select = $db->prepare('SELECT id FROM users WHERE national_id_hash = :hash OR phone = :phone LIMIT 1');
    $select->execute(['hash' => $hash, 'phone' => $phone]);
    $id = $select->fetchColumn();
    $params = [
        'institution_id' => $account['institution_id'],
        'full_name' => $account['full_name'],
        'encrypted' => $crypto->encrypt($nationalId),
        'hash' => $hash,
        'last_four' => substr($nationalId, -4),
        'email' => $account['email'],
        'phone' => $phone,
        'password_hash' => password_hash($account['password'], PASSWORD_DEFAULT),
        'role' => $account['role'],
    ];
    if ($id) {
        $params['id'] = $id;
        $update = $db->prepare("UPDATE users SET institution_id = :institution_id, full_name = :full_name, national_id_encrypted = :encrypted,
            national_id_hash = :hash, national_id_last_four = :last_four, email = :email, phone = :phone, password_hash = :password_hash,
            role = :role, account_status = 'active', failed_login_attempts = 0, locked_until = NULL WHERE id = :id");
        $update->execute($params);
        return (int) $id;
    }
    $insert = $db->prepare("INSERT INTO users (institution_id, full_name, national_id_encrypted, national_id_hash, national_id_last_four, email, phone, password_hash, role, account_status)
        VALUES (:institution_id, :full_name, :encrypted, :hash, :last_four, :email, :phone, :password_hash, :role, 'active')");
    $insert->execute($params);
    return (int) $db->lastInsertId();
}

$db->beginTransaction();
try {
    $mbabane = upsertInstitution($db, 'Mbabane Blood Bank', 'blood_service', 'Hhohho', 'Mbabane', '+26824040001', 'mbabane.central@enbts.org.sz', 'Central ENBTS Blood Bank, Mbabane');
    $manzini = upsertInstitution($db, 'Manzini Blood Bank', 'blood_service', 'Manzini', 'Manzini', '+26825050002', 'manzini.branch@enbts.org.sz', 'ENBTS Manzini Branch Blood Bank');
    $hlathi = upsertInstitution($db, 'Hlathikhulu Blood Bank', 'blood_service', 'Shiselweni', 'Hlathikhulu', '+26822070003', 'hlathikhulu.branch@enbts.org.sz', 'ENBTS Hlathikhulu Branch Blood Bank');
    $nhlangano = upsertInstitution($db, 'Nhlangano Hospital', 'hospital', 'Shiselweni', 'Nhlangano', '+26822070010', 'blooddesk@nhlanganohospital.org.sz', 'Nhlangano Hospital blood desk');

    // Important: this system has exactly one central admin: Mbabane Blood Bank.
    $db->exec("UPDATE users SET role = 'staff' WHERE role = 'admin'");

    $accounts = [
        ['full_name' => 'Mbabane Central Admin', 'national_id' => '9001011234567', 'phone' => '+26876111111', 'email' => 'mbabane.admin@enbts.org.sz', 'password' => 'Mbabane@2026', 'role' => 'admin', 'institution_id' => $mbabane],
        ['full_name' => 'Manzini Branch Operator', 'national_id' => '9102021234567', 'phone' => '+26876222222', 'email' => 'manzini.operator@enbts.org.sz', 'password' => 'Manzini@2026', 'role' => 'staff', 'institution_id' => $manzini],
        ['full_name' => 'Hlathikhulu Branch Operator', 'national_id' => '9203031234567', 'phone' => '+26876333333', 'email' => 'hlathikhulu.operator@enbts.org.sz', 'password' => 'Hlathi@2026', 'role' => 'staff', 'institution_id' => $hlathi],
        ['full_name' => 'Nhlangano Hospital Blood Desk', 'national_id' => '9304041234567', 'phone' => '+26876444444', 'email' => 'nhlangano.blooddesk@hospital.org.sz', 'password' => 'Hospital@2026', 'role' => 'hospital', 'institution_id' => $nhlangano],
    ];

    foreach ($accounts as $account) {
        upsertUser($db, $crypto, $account);
    }

    $bloodTypes = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
    $stock = [
        $mbabane => ['A+' => 18, 'A-' => 7, 'B+' => 12, 'B-' => 5, 'AB+' => 6, 'AB-' => 2, 'O+' => 22, 'O-' => 8],
        $manzini => ['A+' => 10, 'A-' => 3, 'B+' => 9, 'B-' => 2, 'AB+' => 4, 'AB-' => 1, 'O+' => 14, 'O-' => 4],
        $hlathi => ['A+' => 8, 'A-' => 2, 'B+' => 7, 'B-' => 2, 'AB+' => 3, 'AB-' => 1, 'O+' => 11, 'O-' => 3],
    ];
    $seedInventory = $db->prepare("INSERT INTO blood_inventory (institution_id, blood_type, available_units, reserved_units, expired_units, critical_threshold)
        VALUES (:institution_id, :blood_type, :available_units, 0, 0, 0)
        ON DUPLICATE KEY UPDATE available_units = VALUES(available_units), critical_threshold = VALUES(critical_threshold)");
    foreach ($stock as $bankId => $rows) {
        foreach ($bloodTypes as $type) {
            $seedInventory->execute(['institution_id' => $bankId, 'blood_type' => $type, 'available_units' => $rows[$type] ?? 0]);
        }
    }

    $db->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('enbts_seed_2026_06_13', 'applied') ON DUPLICATE KEY UPDATE `value` = 'applied'")->execute();
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}

echo "ENBTS demo seed complete.\n\n";
echo "Login accounts:\n";
echo "1) Mbabane Central Admin | National ID: 9001011234567 | Password: Mbabane@2026\n";
echo "2) Manzini Branch Operator | National ID: 9102021234567 | Password: Manzini@2026\n";
echo "3) Hlathikhulu Branch Operator | National ID: 9203031234567 | Password: Hlathi@2026\n";
echo "4) Nhlangano Hospital Blood Desk | National ID: 9304041234567 | Password: Hospital@2026\n";
