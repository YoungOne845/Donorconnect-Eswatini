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

$options = getopt('', ['user-id:', 'national-id:']);
$userId = (int) ($options['user-id'] ?? 0);
$nationalId = Identity::nationalId((string) ($options['national-id'] ?? ''));

if ($userId < 1 || !Identity::validNationalId($nationalId)) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "php assign_national_id.php --user-id=1 --national-id=0412227100041\n");
    fwrite(STDERR, "The national ID must contain 13 digits and begin with a valid YYMMDD birth date.\n");
    exit(1);
}

$db = Database::connection();
$userStatement = $db->prepare('SELECT id, full_name, national_id_hash FROM users WHERE id = :id LIMIT 1');
$userStatement->execute(['id' => $userId]);
$user = $userStatement->fetch();

if (!$user) {
    fwrite(STDERR, "No user exists with internal ID {$userId}.\n");
    exit(1);
}

$crypto = App::crypto();
$nationalHash = $crypto->searchHash($nationalId);
$duplicate = $db->prepare('SELECT id FROM users WHERE national_id_hash = :hash AND id <> :id LIMIT 1');
$duplicate->execute(['hash' => $nationalHash, 'id' => $userId]);
if ($duplicate->fetch()) {
    fwrite(STDERR, "That national ID is already assigned to another account.\n");
    exit(1);
}

$update = $db->prepare(
    'UPDATE users
     SET national_id_encrypted = :encrypted,
         national_id_hash = :hash,
         national_id_last_four = :last_four
     WHERE id = :id'
);
$update->execute([
    'encrypted' => $crypto->encrypt($nationalId),
    'hash' => $nationalHash,
    'last_four' => substr($nationalId, -4),
    'id' => $userId,
]);

fwrite(STDOUT, "National ID assigned to {$user['full_name']} (user {$userId}). This account must now sign in with that national ID.\n");
