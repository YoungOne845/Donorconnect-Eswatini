<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Identity;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class SetupController
{
    public function createFirstAdmin(Request $request): never
    {
        if ((string) Env::get('APP_ENV', 'production') !== 'setup') {
            throw new HttpException(404, 'API route not found.');
        }

        $token = (string) $request->header('X-Setup-Token', '');
        $expected = (string) Env::get('SETUP_TOKEN', '');
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            throw new HttpException(403, 'Invalid setup token.');
        }

        $data = $request->json();
        (new Validator())
            ->required($data, ['full_name','national_id','phone','password'])
            ->string($data, 'full_name', 3, 180)
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->string($data, 'phone', 8, 20)
            ->string($data, 'password', 10, 128)
            ->email($data, 'email', true)
            ->validate();

        $nationalId = Identity::nationalId((string) $data['national_id']);
        if (!Identity::validNationalId($nationalId)) {
            throw new HttpException(422, 'National ID is invalid.', [
                'national_id' => 'Enter a valid 13-digit national ID whose first six digits form a YYMMDD birth date.',
            ]);
        }

        $phone = Identity::phone((string) $data['phone']);
        if (!Identity::validEswatiniPhone($phone)) {
            throw new HttpException(422, 'Phone number is invalid.');
        }

        $password = (string) $data['password'];
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new HttpException(422, 'Password is not strong enough.', [
                'password' => 'Use at least one uppercase letter, one lowercase letter and one number.',
            ]);
        }

        $db = Database::connection();
        if ((int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0) {
            throw new HttpException(409, 'An administrator account already exists.');
        }

        $crypto = App::crypto();
        $statement = $db->prepare(
            "INSERT INTO users
             (full_name, national_id_encrypted, national_id_hash, national_id_last_four,
              email, phone, password_hash, role, account_status)
             VALUES
             (:name, :national_id_encrypted, :national_id_hash, :national_id_last_four,
              :email, :phone, :password, 'admin', 'active')"
        );
        $statement->execute([
            'name' => trim((string) $data['full_name']),
            'national_id_encrypted' => $crypto->encrypt($nationalId),
            'national_id_hash' => $crypto->searchHash($nationalId),
            'national_id_last_four' => substr($nationalId, -4),
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'phone' => $phone,
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        Response::success(
            'First administrator created. Change APP_ENV from setup to production immediately.',
            ['id' => (int) $db->lastInsertId()],
            201
        );
    }
}
