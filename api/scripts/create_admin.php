<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\Identity;

$options = getopt('', ['name:', 'national-id:', 'phone:', 'email::', 'password:', 'institution::']);
$name = trim((string) ($options['name'] ?? ''));
$nationalId = Identity::nationalId((string) ($options['national-id'] ?? ''));
$phone = Identity::phone((string) ($options['phone'] ?? ''));
$email = trim((string) ($options['email'] ?? '')) ?: null;
$password = (string) ($options['password'] ?? '');
$institutionId = isset($options['institution']) ? (int) $options['institution'] : null;

$validPassword = strlen($password) >= 10
    && preg_match('/[A-Z]/', $password)
    && preg_match('/[a-z]/', $password)
    && preg_match('/[0-9]/', $password);

if ($name === '' || !Identity::validNationalId($nationalId) || !Identity::validEswatiniPhone($phone) || !$validPassword) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "php create_admin.php --name=\"Admin Name\" --national-id=0412227100041 --phone=76123456 --email=admin@example.com --password=StrongPass123 [--institution=1]\n");
    fwrite(STDERR, "The national ID must contain 13 digits and begin with a valid YYMMDD birth date.\n");
    exit(1);
}

$db = Database::connection();
$crypto = App::crypto();
$nationalHash = $crypto->searchHash($nationalId);

$duplicateSql = 'SELECT id FROM users WHERE national_id_hash = :national_hash OR phone = :phone';
$duplicateParams = [
    'national_hash' => $nationalHash,
    'phone' => $phone,
];

if ($email !== null) {
    $duplicateSql .= ' OR email = :email';
    $duplicateParams['email'] = $email;
}

$duplicateSql .= ' LIMIT 1';
$duplicate = $db->prepare($duplicateSql);
$duplicate->execute($duplicateParams);
if ($duplicate->fetch()) {
    fwrite(STDERR, "Could not create administrator: the national ID, phone number or email is already in use.\n");
    exit(1);
}

$statement = $db->prepare(
    "INSERT INTO users
     (institution_id, full_name, national_id_encrypted, national_id_hash, national_id_last_four,
      email, phone, password_hash, role, account_status)
     VALUES
     (:institution_id, :name, :national_id_encrypted, :national_id_hash, :national_id_last_four,
      :email, :phone, :password, 'admin', 'active')"
);

try {
    $statement->execute([
        'institution_id' => $institutionId,
        'name' => $name,
        'national_id_encrypted' => $crypto->encrypt($nationalId),
        'national_id_hash' => $nationalHash,
        'national_id_last_four' => substr($nationalId, -4),
        'email' => $email,
        'phone' => $phone,
        'password' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    fwrite(STDOUT, "Administrator created with internal ID " . $db->lastInsertId() . ". Sign in using the national ID.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Could not create administrator: {$exception->getMessage()}\n");
    exit(1);
}
