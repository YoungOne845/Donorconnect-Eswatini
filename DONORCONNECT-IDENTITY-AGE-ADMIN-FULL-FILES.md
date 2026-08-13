# DonorConnect Identity/Age/Admin Update — Full Replacement Files

Each section below contains the complete replacement file, not a partial snippet.

## `api/src/Core/Identity.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

final class Identity
{
    public const NATIONAL_ID_LENGTH = 13;
    public const MINIMUM_DONOR_AGE = 16;

    public static function nationalId(string $value): string
    {
        return preg_replace('/\D+/', '', trim($value)) ?? '';
    }

    public static function validNationalId(string $value): bool
    {
        $nationalId = self::nationalId($value);

        return strlen($nationalId) === self::NATIONAL_ID_LENGTH
            && ctype_digit($nationalId)
            && self::birthDateFromNationalId($nationalId) instanceof DateTimeImmutable;
    }

    public static function birthDateFromNationalId(string $value): ?DateTimeImmutable
    {
        $nationalId = self::nationalId($value);
        if (strlen($nationalId) !== self::NATIONAL_ID_LENGTH || !ctype_digit($nationalId)) {
            return null;
        }

        $yearPart = (int) substr($nationalId, 0, 2);
        $month = (int) substr($nationalId, 2, 2);
        $day = (int) substr($nationalId, 4, 2);
        $currentTwoDigitYear = (int) date('y');
        $fullYear = $yearPart <= $currentTwoDigitYear ? 2000 + $yearPart : 1900 + $yearPart;

        if (!checkdate($month, $day, $fullYear)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%04d-%02d-%02d', $fullYear, $month, $day)
        );

        if (!$date || $date > new DateTimeImmutable('today')) {
            return null;
        }

        return $date;
    }

    public static function ageFromBirthDate(DateTimeImmutable $birthDate, ?DateTimeImmutable $onDate = null): int
    {
        $referenceDate = $onDate ?? new DateTimeImmutable('today');
        return $birthDate->diff($referenceDate)->y;
    }

    public static function ageFromNationalId(string $value, ?DateTimeImmutable $onDate = null): ?int
    {
        $birthDate = self::birthDateFromNationalId($value);
        return $birthDate ? self::ageFromBirthDate($birthDate, $onDate) : null;
    }

    public static function donorRegistrationDate(string $value): ?DateTimeImmutable
    {
        return self::birthDateFromNationalId($value)?->modify('+' . self::MINIMUM_DONOR_AGE . ' years');
    }

    public static function isOldEnoughToRegister(string $value, ?DateTimeImmutable $onDate = null): bool
    {
        $eligibleOn = self::donorRegistrationDate($value);
        $referenceDate = $onDate ?? new DateTimeImmutable('today');

        return $eligibleOn !== null && $eligibleOn <= $referenceDate;
    }

    public static function humanDate(DateTimeImmutable $date): string
    {
        return $date->format('j F Y');
    }

    public static function phone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '268') && strlen($digits) === 11) {
            return '+' . $digits;
        }
        if (strlen($digits) === 8) {
            return '+268' . $digits;
        }
        return '+' . ltrim($digits, '+');
    }

    public static function validEswatiniPhone(string $value): bool
    {
        return preg_match('/^\+268[2678][0-9]{7}$/', self::phone($value)) === 1;
    }
}
```

## `api/src/Controllers/AuthController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Audit;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Identity;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\NotificationService;

final class AuthController
{
    private const BLOOD_TYPES = ['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'];
    private const REGIONS = ['Hhohho','Manzini','Lubombo','Shiselweni'];
    private const GENDERS = ['male','female','other','prefer_not_to_say'];
    private const SOURCES = ['school','university','church','workplace','community_campaign','hospital','social_media','referral','walk_in','other'];

    public function register(Request $request): never
    {
        RateLimiter::hit('register', $request->ip(), 10, 60);
        $data = $request->json();

        $validator = (new Validator())
            ->required($data, ['full_name','national_id','phone','password','gender','region','town','recruitment_source'])
            ->string($data, 'full_name', 3, 180)
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->string($data, 'phone', 8, 20)
            ->string($data, 'password', 10, 128)
            ->email($data, 'email', true)
            ->in($data, 'gender', self::GENDERS)
            ->in($data, 'region', self::REGIONS)
            ->string($data, 'town', 2, 120)
            ->in($data, 'blood_type', self::BLOOD_TYPES, true)
            ->in($data, 'recruitment_source', self::SOURCES)
            ->in($data, 'preferred_contact_method', ['sms','phone','email','web'], true)
            ->in($data, 'availability_status', ['available','not_available'], true);

        $nationalId = Identity::nationalId((string) ($data['national_id'] ?? ''));
        $phone = Identity::phone((string) ($data['phone'] ?? ''));
        $birthDate = Identity::birthDateFromNationalId($nationalId);

        if (!Identity::validNationalId($nationalId) || !$birthDate) {
            $validator->add('national_id', 'Enter a valid 13-digit national ID. The first six digits must contain a real birth date in YYMMDD format.');
        }

        if (!Identity::validEswatiniPhone($phone)) {
            $validator->add('phone', 'Enter a valid Eswatini phone number.');
        }

        $password = (string) ($data['password'] ?? '');
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $validator->add('password', 'Use at least one uppercase letter, one lowercase letter and one number.');
        }

        $emergencyPhone = trim((string) ($data['emergency_contact_phone'] ?? ''));
        if ($emergencyPhone !== '' && !Identity::validEswatiniPhone($emergencyPhone)) {
            $validator->add('emergency_contact_phone', 'Enter a valid emergency contact phone number.');
        }

        $validator->validate();

        $eligibleOn = Identity::donorRegistrationDate($nationalId);
        if (!$eligibleOn || !Identity::isOldEnoughToRegister($nationalId)) {
            $eligibleDate = $eligibleOn ? Identity::humanDate($eligibleOn) : 'your sixteenth birthday';
            throw new HttpException(
                422,
                "You are under the required age to donate. Based on your national ID details, you will be eligible to register on {$eligibleDate}. Please come back then — we will be happy to welcome you to DonorConnect.",
                ['national_id' => "Registration opens for you on {$eligibleDate}."]
            );
        }

        $age = Identity::ageFromBirthDate($birthDate);
        $db = Database::connection();
        $crypto = App::crypto();
        $nationalHash = $crypto->searchHash($nationalId);
        $email = trim((string) ($data['email'] ?? '')) ?: null;

        $duplicate = $db->prepare(
            'SELECT national_id_hash, phone, email FROM users
             WHERE national_id_hash = :national_hash OR phone = :phone OR (:email IS NOT NULL AND email = :email)
             LIMIT 1'
        );
        $duplicate->execute(['national_hash' => $nationalHash, 'phone' => $phone, 'email' => $email]);
        $existing = $duplicate->fetch();
        if ($existing) {
            $errors = [];
            if (($existing['national_id_hash'] ?? '') === $nationalHash) {
                $errors['national_id'] = 'This national ID is already registered.';
            }
            if (($existing['phone'] ?? '') === $phone) {
                $errors['phone'] = 'This phone number is already registered.';
            }
            if ($email !== null && ($existing['email'] ?? '') === $email) {
                $errors['email'] = 'This email address is already registered.';
            }
            throw new HttpException(409, 'An account already exists with some of these details.', $errors);
        }

        $institutionId = isset($data['recruitment_institution_id']) && $data['recruitment_institution_id'] !== ''
            ? (int) $data['recruitment_institution_id']
            : null;

        if ($institutionId !== null) {
            $check = $db->prepare('SELECT id FROM institutions WHERE id = :id AND is_active = 1');
            $check->execute(['id' => $institutionId]);
            if (!$check->fetch()) {
                throw new HttpException(
                    422,
                    'The selected recruitment institution is invalid.',
                    ['recruitment_institution_id' => 'Select an active institution.']
                );
            }
        }

        $db->beginTransaction();
        try {
            $userStatement = $db->prepare(
                "INSERT INTO users
                 (full_name, national_id_encrypted, national_id_hash, national_id_last_four, email, phone, password_hash, role, account_status)
                 VALUES (:full_name, :encrypted, :hash, :last_four, :email, :phone, :password_hash, 'donor', 'active')"
            );
            $userStatement->execute([
                'full_name' => trim((string) $data['full_name']),
                'encrypted' => $crypto->encrypt($nationalId),
                'hash' => $nationalHash,
                'last_four' => substr($nationalId, -4),
                'email' => $email,
                'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            $userId = (int) $db->lastInsertId();
            $donorCode = sprintf('DC-%s-%06d', date('Y'), $userId);

            $profileStatement = $db->prepare(
                "INSERT INTO donor_profiles
                 (user_id, donor_code, blood_type, date_of_birth, gender, region, town, address, availability_status,
                  verification_status, eligibility_status, preferred_contact_method, recruitment_source,
                  recruitment_institution_id, recruitment_campaign_id, referral_code, emergency_contact_name,
                  emergency_contact_phone, consent_to_notifications, profile_completion_score)
                 VALUES
                 (:user_id, :donor_code, :blood_type, :date_of_birth, :gender, :region, :town, :address, :availability,
                  'pending', 'not_assessed', :contact_method, :source, :institution_id, :campaign_id, :referral_code,
                  :emergency_name, :emergency_phone, :consent, :completion)"
            );
            $profileStatement->execute([
                'user_id' => $userId,
                'donor_code' => $donorCode,
                'blood_type' => $data['blood_type'] ?? 'Unknown',
                'date_of_birth' => $birthDate->format('Y-m-d'),
                'gender' => $data['gender'],
                'region' => $data['region'],
                'town' => trim((string) $data['town']),
                'address' => trim((string) ($data['address'] ?? '')) ?: null,
                'availability' => $data['availability_status'] ?? 'available',
                'contact_method' => $data['preferred_contact_method'] ?? 'sms',
                'source' => $data['recruitment_source'],
                'institution_id' => $institutionId,
                'campaign_id' => !empty($data['recruitment_campaign_id']) ? (int) $data['recruitment_campaign_id'] : null,
                'referral_code' => trim((string) ($data['referral_code'] ?? '')) ?: null,
                'emergency_name' => trim((string) ($data['emergency_contact_name'] ?? '')) ?: null,
                'emergency_phone' => $emergencyPhone !== '' ? Identity::phone($emergencyPhone) : null,
                'consent' => filter_var($data['consent_to_notifications'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'completion' => $email && !empty($data['address']) && $emergencyPhone !== '' ? 100 : 75,
            ]);

            $donorId = (int) $db->lastInsertId();
            $activity = $db->prepare(
                "INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata)
                 VALUES (:donor_id, 'registered', :description, :metadata)"
            );
            $activity->execute([
                'donor_id' => $donorId,
                'description' => 'Donor joined DonorConnect through ' . str_replace('_', ' ', (string) $data['recruitment_source']) . '.',
                'metadata' => json_encode([
                    'source' => $data['recruitment_source'],
                    'institution_id' => $institutionId,
                    'age_at_registration' => $age,
                    'date_of_birth_source' => 'national_id',
                ], JSON_THROW_ON_ERROR),
            ]);

            (new NotificationService())->create(
                $userId,
                'account',
                'Welcome to DonorConnect',
                $age < 18
                    ? 'Your donor journey has started. Because you are under 18, please remember that parental or signed guardian consent may be required when you present to donate.'
                    : 'Your donor journey has started. Complete verification, follow your eligibility status and join upcoming donation campaigns.',
                '/app/profile'
            );

            $db->commit();
            App::auth()->login($userId);
            Audit::log(
                'DONOR_REGISTERED',
                'A new donor joined the national donor pool.',
                'donor_profile',
                $donorId,
                null,
                ['source' => $data['recruitment_source'], 'age' => $age, 'birth_date_source' => 'national_id'],
                $request
            );

            Response::success('Your DonorConnect account has been created.', [
                'user' => App::auth()->user(),
                'csrf_token' => App::auth()->csrfToken(),
                'national_id_masked' => $crypto->mask($nationalId),
                'date_of_birth' => $birthDate->format('Y-m-d'),
                'age' => $age,
                'guardian_consent_notice' => $age < 18
                    ? 'You may need parental or signed guardian consent when presenting to donate.'
                    : null,
            ], 201);
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    public function login(Request $request): never
    {
        $data = $request->json();
        (new Validator())
            ->required($data, ['national_id','password'])
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->string($data, 'password', 1, 128)
            ->validate();

        $nationalId = Identity::nationalId((string) $data['national_id']);
        if (!Identity::validNationalId($nationalId)) {
            throw new HttpException(422, 'Enter a valid 13-digit national ID.', ['national_id' => 'The national ID must begin with a valid YYMMDD birth date.']);
        }

        $nationalHash = App::crypto()->searchHash($nationalId);
        RateLimiter::hit('login', $request->ip() . '|' . $nationalHash, 8, 15);

        $db = Database::connection();
        $statement = $db->prepare('SELECT * FROM users WHERE national_id_hash = :national_hash LIMIT 1');
        $statement->execute(['national_hash' => $nationalHash]);
        $user = $statement->fetch();

        if (!$user || !password_verify((string) $data['password'], $user['password_hash'])) {
            if ($user) {
                $attempts = (int) $user['failed_login_attempts'] + 1;
                $lockedUntil = $attempts >= 5 ? (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s') : null;
                $update = $db->prepare('UPDATE users SET failed_login_attempts = :attempts, locked_until = :locked_until WHERE id = :id');
                $update->execute(['attempts' => $attempts, 'locked_until' => $lockedUntil, 'id' => $user['id']]);
            }
            throw new HttpException(401, 'The national ID or password is incorrect.');
        }

        if ($user['account_status'] !== 'active') {
            throw new HttpException(403, 'This account is not currently active. Contact an administrator.');
        }
        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            throw new HttpException(423, 'This account is temporarily locked after repeated login attempts.');
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $rehash->execute(['hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT), 'id' => $user['id']]);
        }

        $update = $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id');
        $update->execute(['id' => $user['id']]);
        App::auth()->login((int) $user['id']);

        $current = App::auth()->user();
        if (($current['role'] ?? '') === 'donor' && isset($current['donor_id'])) {
            $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description) VALUES (:donor_id, 'login', 'Donor signed in.')");
            $activity->execute(['donor_id' => $current['donor_id']]);
        }

        Audit::log('LOGIN', 'User signed in using the national ID identifier.', 'user', (int) $user['id'], null, null, $request);
        Response::success('Welcome back.', ['user' => $current, 'csrf_token' => App::auth()->csrfToken()]);
    }

    public function logout(Request $request): never
    {
        $user = App::auth()->requireUser();
        Audit::log('LOGOUT', 'User signed out.', 'user', (int) $user['id'], null, null, $request);
        App::auth()->logout();
        Response::success('You have been signed out.');
    }

    public function me(Request $request): never
    {
        Response::success('Authentication state loaded.', [
            'user' => App::auth()->user(),
            'csrf_token' => App::auth()->csrfToken(),
        ]);
    }

    public function csrf(Request $request): never
    {
        Response::success('Security token generated.', ['csrf_token' => App::auth()->csrfToken()]);
    }
}
```

## `api/src/Controllers/AdminController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Audit;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Identity;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use PDOException;

final class AdminController
{
    private const INSTITUTION_TYPES = ['hospital','blood_service','school','university','church','workplace','community_organisation','other'];
    private const REGIONS = ['Hhohho','Manzini','Lubombo','Shiselweni'];

    public function institutions(Request $request): never
    {
        $statement = Database::connection()->query('SELECT * FROM institutions ORDER BY is_active DESC, name ASC');
        Response::success('Institutions loaded.', $statement->fetchAll());
    }

    public function createInstitution(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $data = $request->json();

        (new Validator())
            ->required($data, ['name','institution_type','region','town'])
            ->string($data, 'name', 3, 180)
            ->in($data, 'institution_type', self::INSTITUTION_TYPES)
            ->in($data, 'region', self::REGIONS)
            ->string($data, 'town', 2, 120)
            ->email($data, 'email', true)
            ->validate();

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone !== '' && !Identity::validEswatiniPhone($phone)) {
            throw new HttpException(422, 'Institution phone number is invalid.', ['phone' => 'Enter a valid Eswatini phone number.']);
        }

        $db = Database::connection();
        $statement = $db->prepare(
            'INSERT INTO institutions (name, institution_type, phone, email, region, town, address, is_active)
             VALUES (:name, :type, :phone, :email, :region, :town, :address, :is_active)'
        );
        $statement->execute([
            'name' => trim((string) $data['name']),
            'type' => $data['institution_type'],
            'phone' => $phone !== '' ? Identity::phone($phone) : null,
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'region' => $data['region'],
            'town' => trim((string) $data['town']),
            'address' => trim((string) ($data['address'] ?? '')) ?: null,
            'is_active' => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ]);

        $id = (int) $db->lastInsertId();
        Audit::log('INSTITUTION_CREATED', 'An institution was added.', 'institution', $id, null, [
            'name' => $data['name'],
            'type' => $data['institution_type'],
        ], $request);

        Response::success('Institution created.', ['id' => $id], 201);
    }

    public function deleteInstitution(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $id = (int) $request->param('id');
        $db = Database::connection();

        $institutionStatement = $db->prepare('SELECT * FROM institutions WHERE id = :id LIMIT 1');
        $institutionStatement->execute(['id' => $id]);
        $institution = $institutionStatement->fetch();
        if (!$institution) {
            throw new HttpException(404, 'Institution not found.');
        }

        $userCountStatement = $db->prepare('SELECT COUNT(*) FROM users WHERE institution_id = :id');
        $userCountStatement->execute(['id' => $id]);
        $linkedUsers = (int) $userCountStatement->fetchColumn();
        if ($linkedUsers > 0) {
            throw new HttpException(
                409,
                'This institution still has linked user accounts. Reassign or delete those accounts before deleting the institution.',
                ['institution' => "{$linkedUsers} user account(s) are still linked to this institution."]
            );
        }

        Audit::log(
            'INSTITUTION_DELETED',
            'An administrator deleted an institution.',
            'institution',
            $id,
            $institution,
            null,
            $request
        );

        $delete = $db->prepare('DELETE FROM institutions WHERE id = :id');
        $delete->execute(['id' => $id]);

        Response::success('Institution deleted successfully. Historical campaign, donation and request records remain preserved without the institution link.');
    }

    public function users(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $role = trim((string) $request->query('role', ''));
        $search = trim((string) $request->query('search', ''));
        $where = ['1=1'];
        $params = [];

        if ($role !== '') {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }
        if ($search !== '') {
            $where[] = '(u.full_name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search OR u.national_id_last_four LIKE :search)';
            $params['search'] = "%{$search}%";
        }

        $statement = Database::connection()->prepare(
            "SELECT u.id, u.full_name, u.national_id_last_four, u.email, u.phone, u.role, u.account_status,
                    u.last_login_at, u.created_at, i.name AS institution_name
             FROM users u
             LEFT JOIN institutions i ON i.id = u.institution_id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY u.created_at DESC
             LIMIT 500'
        );
        $statement->execute($params);

        Response::success('Users loaded.', $statement->fetchAll());
    }

    public function createStaffAccount(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $data = $request->json();

        (new Validator())
            ->required($data, ['full_name','national_id','phone','password','role'])
            ->string($data, 'full_name', 3, 180)
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->string($data, 'phone', 8, 20)
            ->string($data, 'password', 10, 128)
            ->email($data, 'email', true)
            ->in($data, 'role', ['hospital','staff','admin'])
            ->validate();

        $nationalId = Identity::nationalId((string) $data['national_id']);
        if (!Identity::validNationalId($nationalId)) {
            throw new HttpException(422, 'National ID is invalid.', [
                'national_id' => 'Enter a valid 13-digit national ID whose first six digits form a real YYMMDD birth date.',
            ]);
        }

        $phone = Identity::phone((string) $data['phone']);
        if (!Identity::validEswatiniPhone($phone)) {
            throw new HttpException(422, 'Phone number is invalid.', ['phone' => 'Enter a valid Eswatini phone number.']);
        }

        $password = (string) $data['password'];
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new HttpException(422, 'Password is not strong enough.', ['password' => 'Use uppercase, lowercase and numbers.']);
        }

        $institutionId = !empty($data['institution_id']) ? (int) $data['institution_id'] : null;
        if (in_array($data['role'], ['hospital','staff'], true) && $institutionId === null) {
            throw new HttpException(422, 'An institution is required for hospital and blood-service staff accounts.', [
                'institution_id' => 'Select the institution this user belongs to.',
            ]);
        }

        $db = Database::connection();
        if ($institutionId !== null) {
            $institution = $db->prepare('SELECT id, institution_type FROM institutions WHERE id = :id AND is_active = 1');
            $institution->execute(['id' => $institutionId]);
            $institutionRecord = $institution->fetch();
            if (!$institutionRecord) {
                throw new HttpException(422, 'Select a valid active institution.', ['institution_id' => 'Institution not found or inactive.']);
            }
            if ($data['role'] === 'hospital' && $institutionRecord['institution_type'] !== 'hospital') {
                throw new HttpException(422, 'Hospital accounts must be linked to a hospital institution.', ['institution_id' => 'Choose an institution of type Hospital.']);
            }
            if ($data['role'] === 'staff' && $institutionRecord['institution_type'] !== 'blood_service') {
                throw new HttpException(422, 'Blood-service staff must be linked to a blood-service institution.', ['institution_id' => 'Choose an institution of type Blood service.']);
            }
        }

        $crypto = App::crypto();
        $nationalHash = $crypto->searchHash($nationalId);
        $email = trim((string) ($data['email'] ?? '')) ?: null;

        $duplicate = $db->prepare(
            'SELECT id, national_id_hash, phone, email FROM users
             WHERE national_id_hash = :national_hash OR phone = :phone OR (:email IS NOT NULL AND email = :email)
             LIMIT 1'
        );
        $duplicate->execute(['national_hash' => $nationalHash, 'phone' => $phone, 'email' => $email]);
        if ($duplicate->fetch()) {
            throw new HttpException(409, 'That national ID, phone number or email is already in use.');
        }

        $statement = $db->prepare(
            "INSERT INTO users
             (institution_id, full_name, national_id_encrypted, national_id_hash, national_id_last_four,
              email, phone, password_hash, role, account_status)
             VALUES
             (:institution_id, :full_name, :national_id_encrypted, :national_id_hash, :national_id_last_four,
              :email, :phone, :password_hash, :role, 'active')"
        );

        try {
            $statement->execute([
                'institution_id' => $institutionId,
                'full_name' => trim((string) $data['full_name']),
                'national_id_encrypted' => $crypto->encrypt($nationalId),
                'national_id_hash' => $nationalHash,
                'national_id_last_four' => substr($nationalId, -4),
                'email' => $email,
                'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $data['role'],
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new HttpException(409, 'That national ID, phone number or email is already in use.');
            }
            throw $exception;
        }

        $id = (int) $db->lastInsertId();
        Audit::log('STAFF_ACCOUNT_CREATED', 'An operational account was created.', 'user', $id, null, [
            'role' => $data['role'],
            'institution_id' => $institutionId,
            'national_id_last_four' => substr($nationalId, -4),
        ], $request);

        Response::success('Account created.', ['id' => $id], 201);
    }

    public function updateUserStatus(Request $request): never
    {
        $current = App::auth()->requireRoles(['admin']);
        $id = (int) $request->param('id');
        $data = $request->json();

        (new Validator())
            ->required($data, ['account_status'])
            ->in($data, 'account_status', ['active','inactive','suspended','pending'])
            ->validate();

        if ($id === (int) $current['id'] && $data['account_status'] !== 'active') {
            throw new HttpException(409, 'You cannot deactivate your own administrator account.');
        }

        $db = Database::connection();
        $before = $db->prepare('SELECT account_status FROM users WHERE id = :id');
        $before->execute(['id' => $id]);
        $old = $before->fetch();
        if (!$old) {
            throw new HttpException(404, 'User not found.');
        }

        $update = $db->prepare('UPDATE users SET account_status = :status WHERE id = :id');
        $update->execute(['status' => $data['account_status'], 'id' => $id]);

        Audit::log('USER_STATUS_UPDATED', 'Administrator updated an account status.', 'user', $id, $old, [
            'account_status' => $data['account_status'],
        ], $request);

        Response::success('Account status updated.');
    }

    public function deleteUser(Request $request): never
    {
        $current = App::auth()->requireRoles(['admin']);
        $id = (int) $request->param('id');

        if ($id === (int) $current['id']) {
            throw new HttpException(409, 'You cannot delete the administrator account you are currently using.');
        }

        $db = Database::connection();
        $userStatement = $db->prepare(
            'SELECT id, full_name, national_id_last_four, email, phone, role, account_status, institution_id
             FROM users WHERE id = :id LIMIT 1'
        );
        $userStatement->execute(['id' => $id]);
        $target = $userStatement->fetch();
        if (!$target) {
            throw new HttpException(404, 'User account not found.');
        }

        if ($target['role'] === 'admin') {
            $adminCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND account_status = 'active'")->fetchColumn();
            if ($adminCount <= 1) {
                throw new HttpException(409, 'The last active administrator account cannot be deleted.');
            }
        }

        $protectedRecords = $this->protectedUserRecordCounts($id, (string) $target['role']);
        $blocking = array_filter($protectedRecords, static fn (int $count): bool => $count > 0);
        if ($blocking !== []) {
            $summary = implode(', ', array_map(
                static fn (string $name, int $count): string => "{$name}: {$count}",
                array_keys($blocking),
                array_values($blocking)
            ));
            throw new HttpException(
                409,
                'This account has protected operational history and cannot be permanently deleted. Set it to inactive instead.',
                ['account' => $summary]
            );
        }

        Audit::log(
            'USER_DELETED',
            'An administrator permanently deleted an unused user account.',
            'user',
            $id,
            $target,
            null,
            $request
        );

        $delete = $db->prepare('DELETE FROM users WHERE id = :id');
        $delete->execute(['id' => $id]);

        Response::success('User account deleted successfully.');
    }

    private function protectedUserRecordCounts(int $userId, string $role): array
    {
        $db = Database::connection();
        $counts = [];

        $queries = [
            'campaigns created' => 'SELECT COUNT(*) FROM campaigns WHERE created_by = :id',
            'eligibility assessments' => 'SELECT COUNT(*) FROM eligibility_assessments WHERE assessed_by = :id',
            'deferrals recorded' => 'SELECT COUNT(*) FROM donor_deferrals WHERE recorded_by = :id',
            'donations recorded' => 'SELECT COUNT(*) FROM donation_records WHERE recorded_by = :id',
            'blood requests created' => 'SELECT COUNT(*) FROM blood_requests WHERE created_by = :id',
        ];

        foreach ($queries as $label => $sql) {
            $statement = $db->prepare($sql);
            $statement->execute(['id' => $userId]);
            $counts[$label] = (int) $statement->fetchColumn();
        }

        if ($role === 'donor') {
            $donorStatement = $db->prepare('SELECT id FROM donor_profiles WHERE user_id = :id LIMIT 1');
            $donorStatement->execute(['id' => $userId]);
            $donorId = (int) ($donorStatement->fetchColumn() ?: 0);

            if ($donorId > 0) {
                $donorQueries = [
                    'donations received' => 'SELECT COUNT(*) FROM donation_records WHERE donor_id = :id',
                    'eligibility history' => 'SELECT COUNT(*) FROM eligibility_assessments WHERE donor_id = :id',
                    'deferral history' => 'SELECT COUNT(*) FROM donor_deferrals WHERE donor_id = :id',
                    'request matches' => 'SELECT COUNT(*) FROM request_matches WHERE donor_id = :id',
                    'campaign participation' => 'SELECT COUNT(*) FROM campaign_participants WHERE donor_id = :id',
                ];

                foreach ($donorQueries as $label => $sql) {
                    $statement = $db->prepare($sql);
                    $statement->execute(['id' => $donorId]);
                    $counts[$label] = (int) $statement->fetchColumn();
                }
            }
        }

        return $counts;
    }
}
```

## `api/src/Controllers/SetupController.php`

```php
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
```

## `api/routes/api.php`

```php
<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CampaignController;
use App\Controllers\DashboardController;
use App\Controllers\DonorController;
use App\Controllers\NotificationController;
use App\Controllers\ReportController;
use App\Controllers\RequestController;
use App\Controllers\SetupController;
use App\Controllers\StaffController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

$router = new Router();
$auth = new AuthController();
$donor = new DonorController();
$staff = new StaffController();
$requests = new RequestController();
$campaigns = new CampaignController();
$notifications = new NotificationController();
$reports = new ReportController();
$admin = new AdminController();
$dashboard = new DashboardController();
$setup = new SetupController();

$router->get('/health', static function (Request $request): never {
    Database::connection()->query('SELECT 1');
    Response::success('DonorConnect API is healthy.', [
        'service' => 'DonorConnect API',
        'version' => '1.0.0',
        'database' => 'connected',
        'server_time' => date(DATE_ATOM),
    ]);
});

$router->get('/auth/csrf', [$auth, 'csrf']);
$router->post('/auth/register', [$auth, 'register']);
$router->post('/auth/login', [$auth, 'login']);
$router->get('/auth/me', [$auth, 'me']);
$router->post('/auth/logout', [$auth, 'logout'], ['auth' => true, 'csrf' => true]);

$router->post('/setup/admin', [$setup, 'createFirstAdmin']);

$router->get('/institutions', [$admin, 'institutions']);
$router->post('/institutions', [$admin, 'createInstitution'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->delete('/institutions/{id}', [$admin, 'deleteInstitution'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);

$router->get('/dashboard', [$dashboard, 'index'], ['auth' => true]);

$router->get('/donor/profile', [$donor, 'profile'], ['auth' => true, 'roles' => ['donor']]);
$router->put('/donor/profile', [$donor, 'updateProfile'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);
$router->patch('/donor/availability', [$donor, 'updateAvailability'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);
$router->get('/donor/activity', [$donor, 'activity'], ['auth' => true, 'roles' => ['donor']]);
$router->post('/donor/matches/{matchId}/respond', [$donor, 'respondToRequest'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);

$router->get('/notifications', [$notifications, 'index'], ['auth' => true]);
$router->patch('/notifications/{id}/read', [$notifications, 'markRead'], ['auth' => true, 'csrf' => true]);
$router->patch('/notifications/read-all', [$notifications, 'markAllRead'], ['auth' => true, 'csrf' => true]);

$router->get('/donors', [$staff, 'donors'], ['auth' => true, 'roles' => ['staff','admin']]);
$router->get('/donors/{id}', [$staff, 'donor'], ['auth' => true, 'roles' => ['staff','admin']]);
$router->patch('/donors/{id}/verify', [$staff, 'verify'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->post('/donors/{id}/eligibility', [$staff, 'assessEligibility'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->post('/donors/{id}/donations', [$staff, 'recordDonation'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->post('/donors/{id}/deferrals', [$staff, 'createDeferral'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->patch('/deferrals/{id}/close', [$staff, 'closeDeferral'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);

$router->get('/requests', [$requests, 'index'], ['auth' => true, 'roles' => ['hospital','staff','admin']]);
$router->post('/requests', [$requests, 'create'], ['auth' => true, 'roles' => ['hospital','staff','admin'], 'csrf' => true]);
$router->get('/requests/{id}', [$requests, 'show'], ['auth' => true, 'roles' => ['hospital','staff','admin']]);
$router->post('/requests/{id}/match', [$requests, 'match'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->post('/requests/{id}/notify', [$requests, 'notify'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->patch('/requests/{id}/status', [$requests, 'updateStatus'], ['auth' => true, 'roles' => ['hospital','staff','admin'], 'csrf' => true]);

$router->get('/campaigns', [$campaigns, 'index'], ['auth' => true]);
$router->post('/campaigns', [$campaigns, 'create'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->patch('/campaigns/{id}/status', [$campaigns, 'updateStatus'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->post('/campaigns/{id}/join', [$campaigns, 'join'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);
$router->post('/campaigns/{id}/invite', [$campaigns, 'invite'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);

$router->get('/reports/overview', [$reports, 'overview'], ['auth' => true, 'roles' => ['staff','admin']]);

$router->get('/admin/users', [$admin, 'users'], ['auth' => true, 'roles' => ['admin']]);
$router->post('/admin/users', [$admin, 'createStaffAccount'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->patch('/admin/users/{id}/status', [$admin, 'updateUserStatus'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->delete('/admin/users/{id}', [$admin, 'deleteUser'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);

return $router;
```

## `api/scripts/create_admin.php`

```php
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

$duplicate = $db->prepare(
    'SELECT id FROM users WHERE national_id_hash = :national_hash OR phone = :phone OR (:email IS NOT NULL AND email = :email) LIMIT 1'
);
$duplicate->execute(['national_hash' => $nationalHash, 'phone' => $phone, 'email' => $email]);
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
```

## `api/scripts/assign_national_id.php`

```php
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
```

## `database/migrations/2026_06_12_national_id_age_and_admin_deletion.sql`

```sql
-- DonorConnect migration: national-ID-first identity and age-controlled donor registration
-- Run this only after every existing user account has been assigned a national ID with:
-- php api/scripts/assign_national_id.php --user-id=USER_ID --national-id=13_DIGIT_ID

USE donorconnect;

-- This result must be 0 before continuing with the ALTER TABLE statement.
SELECT COUNT(*) AS users_missing_national_id
FROM users
WHERE national_id_encrypted IS NULL
   OR national_id_hash IS NULL
   OR national_id_last_four IS NULL;

-- Every account must now have a protected national ID identity.
ALTER TABLE users
    MODIFY national_id_encrypted TEXT NOT NULL,
    MODIFY national_id_hash CHAR(64) NOT NULL,
    MODIFY national_id_last_four CHAR(4) NOT NULL;

-- Record the migration so administrators can confirm it was applied.
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_name VARCHAR(190) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('2026_06_12_national_id_age_and_admin_deletion');
```

## `frontend/src/data/eswatini.js`

```javascript
export const ESWATINI_REGIONS = ['Hhohho', 'Manzini', 'Lubombo', 'Shiselweni']

export const ESWATINI_TOWNS = {
  Hhohho: [
    'Mbabane',
    'Lobamba',
    'Ezulwini',
    'Piggs Peak',
    'Motshane',
    'Ngwenya',
    'Mhlambanyatsi',
    'Bulembu',
    'Other / rural locality',
  ],
  Manzini: [
    'Manzini',
    'Matsapha',
    'Kwaluseni',
    'Malkerns',
    'Mahlanya',
    'Sidvokodvo',
    'Bhunya',
    'Other / rural locality',
  ],
  Lubombo: [
    'Siteki',
    'Big Bend',
    'Simunye',
    'Mhlume',
    'Lomahasha',
    'Mpaka',
    'Tshaneni',
    'Other / rural locality',
  ],
  Shiselweni: [
    'Nhlangano',
    'Hlathikhulu',
    'Lavumisa',
    'Mahamba',
    'Gege',
    'Mkhondvo',
    'Other / rural locality',
  ],
}

export const BLOOD_TYPES = ['Unknown', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']

export const GENDER_OPTIONS = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
  { value: 'prefer_not_to_say', label: 'Prefer not to say' },
]

export const RECRUITMENT_SOURCES = [
  { value: 'school', label: 'School' },
  { value: 'university', label: 'University' },
  { value: 'church', label: 'Church' },
  { value: 'workplace', label: 'Workplace' },
  { value: 'community_campaign', label: 'Community campaign' },
  { value: 'hospital', label: 'Hospital' },
  { value: 'social_media', label: 'Social media' },
  { value: 'referral', label: 'Referral' },
  { value: 'walk_in', label: 'Walk-in' },
  { value: 'other', label: 'Other' },
]

export const INSTITUTION_TYPES = [
  { value: 'hospital', label: 'Hospital' },
  { value: 'blood_service', label: 'Blood service' },
  { value: 'school', label: 'School' },
  { value: 'university', label: 'University' },
  { value: 'church', label: 'Church' },
  { value: 'workplace', label: 'Workplace' },
  { value: 'community_organisation', label: 'Community organisation' },
  { value: 'other', label: 'Other' },
]
```

## `frontend/src/utils/identity.js`

```javascript
export const NATIONAL_ID_LENGTH = 13
export const MINIMUM_DONOR_AGE = 16

export function normalizeNationalId(value) {
  return String(value || '').replace(/\D/g, '').slice(0, NATIONAL_ID_LENGTH)
}

export function parseNationalId(value, today = new Date()) {
  const nationalId = normalizeNationalId(value)
  if (nationalId.length !== NATIONAL_ID_LENGTH) {
    return { valid: false, nationalId }
  }

  const yearPart = Number(nationalId.slice(0, 2))
  const month = Number(nationalId.slice(2, 4))
  const day = Number(nationalId.slice(4, 6))
  const currentTwoDigitYear = today.getFullYear() % 100
  const fullYear = yearPart <= currentTwoDigitYear ? 2000 + yearPart : 1900 + yearPart
  const birthDate = new Date(fullYear, month - 1, day)

  const validDate = birthDate.getFullYear() === fullYear
    && birthDate.getMonth() === month - 1
    && birthDate.getDate() === day
    && birthDate <= startOfDay(today)

  if (!validDate) {
    return { valid: false, nationalId }
  }

  const eligibleOn = new Date(fullYear + MINIMUM_DONOR_AGE, month - 1, day)
  const age = calculateAge(birthDate, today)

  return {
    valid: true,
    nationalId,
    birthDate,
    birthDateIso: toIsoDate(birthDate),
    eligibleOn,
    age,
    isEligible: startOfDay(today) >= startOfDay(eligibleOn),
    requiresGuardianConsentNotice: age >= 16 && age < 18,
  }
}

export function calculateAge(birthDate, today = new Date()) {
  let age = today.getFullYear() - birthDate.getFullYear()
  const monthDifference = today.getMonth() - birthDate.getMonth()
  if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birthDate.getDate())) {
    age -= 1
  }
  return age
}

export function formatLongDate(date) {
  return new Intl.DateTimeFormat('en-SZ', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(date)
}

function startOfDay(date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

function toIsoDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
```

## `frontend/src/pages/RegisterPage.jsx`

```jsx
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  CalendarDays,
  Check,
  Eye,
  EyeOff,
  HeartHandshake,
  ShieldCheck,
} from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import FormMessage from '../components/FormMessage'
import { useAuth } from '../context/AuthContext'
import {
  BLOOD_TYPES,
  ESWATINI_REGIONS,
  ESWATINI_TOWNS,
  GENDER_OPTIONS,
  RECRUITMENT_SOURCES,
} from '../data/eswatini'
import {
  NATIONAL_ID_LENGTH,
  formatLongDate,
  normalizeNationalId,
  parseNationalId,
} from '../utils/identity'

const initialForm = {
  full_name: '',
  national_id: '',
  phone: '',
  email: '',
  password: '',
  gender: '',
  blood_type: 'Unknown',
  region: '',
  town: '',
  address: '',
  availability_status: 'available',
  preferred_contact_method: 'sms',
  recruitment_source: '',
  recruitment_institution_id: '',
  referral_code: '',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  consent_to_notifications: true,
}

export default function RegisterPage() {
  const { user, register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState(initialForm)
  const [institutions, setInstitutions] = useState([])
  const [step, setStep] = useState(1)
  const [showPassword, setShowPassword] = useState(false)
  const [customTown, setCustomTown] = useState('')
  const [state, setState] = useState({ loading: false, message: '', errors: null })

  useEffect(() => {
    api('/institutions')
      .then(setInstitutions)
      .catch(() => setInstitutions([]))
  }, [])

  const identityDetails = useMemo(() => parseNationalId(form.national_id), [form.national_id])
  const townOptions = form.region ? ESWATINI_TOWNS[form.region] || [] : []
  const relevantInstitutions = useMemo(
    () => institutions
      .filter((item) => item.is_active == 1)
      .sort((a, b) => a.name.localeCompare(b.name)),
    [institutions],
  )

  if (user) return <Navigate to="/app/dashboard" replace />

  const update = (field, value) => {
    setForm((current) => ({ ...current, [field]: value }))
    if (state.message || state.errors) {
      setState((current) => ({ ...current, message: '', errors: null }))
    }
  }

  const updateNationalId = (value) => {
    update('national_id', normalizeNationalId(value))
  }

  const updateRegion = (region) => {
    setCustomTown('')
    setForm((current) => ({ ...current, region, town: '' }))
  }

  const updateTown = (value) => {
    setCustomTown('')
    update('town', value)
  }

  const validateCurrentStep = () => {
    if (step !== 1) return true

    if (!identityDetails.valid) {
      setState({
        loading: false,
        message: 'Please check your national ID number.',
        errors: {
          national_id: 'Enter all 13 digits. The first six digits must contain your birth date in YYMMDD format.',
        },
      })
      return false
    }

    if (!identityDetails.isEligible) {
      const eligibleDate = formatLongDate(identityDetails.eligibleOn)
      setState({
        loading: false,
        message: `You are under the required age to donate. Based on your details, you will be eligible to register on ${eligibleDate}. Please come back then — we will be happy to welcome you to DonorConnect.`,
        errors: null,
      })
      return false
    }

    return true
  }

  const submit = async (event) => {
    event.preventDefault()

    if (step < 3) {
      if (!validateCurrentStep()) return
      setStep((value) => value + 1)
      return
    }

    setState({ loading: true, message: '', errors: null })
    try {
      await register({
        ...form,
        town: form.town === 'Other / rural locality' ? customTown.trim() : form.town,
        recruitment_institution_id: form.recruitment_institution_id || null,
      })
      navigate('/app/dashboard', { replace: true })
    } catch (error) {
      setState({ loading: false, message: error.message, errors: error.errors })
    }
  }

  return (
    <div className="register-page">
      <header className="register-header">
        <Link to="/" className="landing-brand">
          <img src="/donor-mark.svg" alt="" />
          <span>DonorConnect</span>
        </Link>
        <Link to="/login" className="button button-ghost">Already registered? Sign in</Link>
      </header>

      <main className="register-shell">
        <aside className="register-aside">
          <Link to="/" className="back-link"><ArrowLeft size={17} /> Back home</Link>
          <span className="hero-kicker">Grow Eswatini&apos;s active donor pool</span>
          <h1>Join once. Stay connected. Donate again.</h1>
          <p>Registration begins your donor lifecycle. Blood-service staff will later verify your details and assess your donation eligibility.</p>
          <div className="register-benefits">
            <span><Check /> Track eligibility and next donation date</span>
            <span><Check /> Receive campaign and milestone updates</span>
            <span><Check /> Control your availability at any time</span>
            <span><Check /> Respond only when you are genuinely available</span>
          </div>
        </aside>

        <section className="register-card">
          <div className="stepper">
            {[1, 2, 3].map((item) => (
              <div key={item} className={item <= step ? 'active' : ''}>
                <span>{item < step ? <Check size={15} /> : item}</span>
                <small>{['Identity', 'Donor profile', 'Engagement'][item - 1]}</small>
              </div>
            ))}
          </div>

          <form onSubmit={submit}>
            <FormMessage message={state.message} errors={state.errors} />

            {step === 1 && (
              <div className="form-section">
                <div className="form-heading compact">
                  <h2>Your identity</h2>
                  <p>Your national ID is your main DonorConnect identifier. It is encrypted, and only a masked form is displayed.</p>
                </div>

                <div className="form-grid two">
                  <label>
                    Full name
                    <input
                      value={form.full_name}
                      onChange={(event) => update('full_name', event.target.value)}
                      autoComplete="name"
                      required
                    />
                  </label>

                  <label>
                    National ID number
                    <input
                      value={form.national_id}
                      onChange={(event) => updateNationalId(event.target.value)}
                      inputMode="numeric"
                      autoComplete="username"
                      maxLength={NATIONAL_ID_LENGTH}
                      placeholder="0412227100041"
                      required
                    />
                    <small>13 digits. The first six digits are your birth date in YYMMDD format.</small>
                  </label>

                  {identityDetails.valid && (
                    <div className={`identity-preview full-span ${identityDetails.isEligible ? 'eligible' : 'underage'}`}>
                      {identityDetails.isEligible ? <ShieldCheck /> : <AlertTriangle />}
                      <div>
                        <strong>
                          {identityDetails.isEligible
                            ? 'Age requirement confirmed'
                            : 'Registration is not open yet'}
                        </strong>
                        <p>
                          Birth date: {formatLongDate(identityDetails.birthDate)} · Current age: {identityDetails.age}
                        </p>
                        {!identityDetails.isEligible && (
                          <p>You can register from <b>{formatLongDate(identityDetails.eligibleOn)}</b>.</p>
                        )}
                        {identityDetails.requiresGuardianConsentNotice && (
                          <p>Because you are under 18, parental or signed guardian consent may be required when you present to donate.</p>
                        )}
                      </div>
                    </div>
                  )}

                  <label>
                    Phone number
                    <input
                      value={form.phone}
                      onChange={(event) => update('phone', event.target.value)}
                      placeholder="76123456"
                      inputMode="tel"
                      autoComplete="tel"
                      required
                    />
                  </label>

                  <label>
                    Email address <span>(optional)</span>
                    <input
                      type="email"
                      value={form.email}
                      onChange={(event) => update('email', event.target.value)}
                      autoComplete="email"
                    />
                  </label>

                  <label>
                    Gender
                    <select value={form.gender} onChange={(event) => update('gender', event.target.value)} required>
                      <option value="">Select gender</option>
                      {GENDER_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                  </label>

                  <div className="derived-date-field">
                    <CalendarDays size={18} />
                    <div>
                      <span>Date of birth</span>
                      <strong>{identityDetails.valid ? formatLongDate(identityDetails.birthDate) : 'Calculated from your national ID'}</strong>
                    </div>
                  </div>

                  <label className="full-span">
                    Create password
                    <div className="password-field">
                      <input
                        type={showPassword ? 'text' : 'password'}
                        value={form.password}
                        onChange={(event) => update('password', event.target.value)}
                        minLength={10}
                        autoComplete="new-password"
                        required
                      />
                      <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label="Toggle password visibility">
                        {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                      </button>
                    </div>
                    <small>At least 10 characters with uppercase, lowercase and a number.</small>
                  </label>
                </div>
              </div>
            )}

            {step === 2 && (
              <div className="form-section">
                <div className="form-heading compact">
                  <h2>Your donor profile</h2>
                  <p>Not knowing your blood type should never block recruitment. Blood-service staff can confirm it later.</p>
                </div>

                <div className="form-grid two">
                  <label>
                    Blood type
                    <select value={form.blood_type} onChange={(event) => update('blood_type', event.target.value)}>
                      {BLOOD_TYPES.map((value) => <option key={value}>{value}</option>)}
                    </select>
                  </label>

                  <label>
                    Current availability
                    <select value={form.availability_status} onChange={(event) => update('availability_status', event.target.value)}>
                      <option value="available">Available</option>
                      <option value="not_available">Not available</option>
                    </select>
                  </label>

                  <label>
                    Region
                    <select value={form.region} onChange={(event) => updateRegion(event.target.value)} required>
                      <option value="">Select region</option>
                      {ESWATINI_REGIONS.map((value) => <option key={value}>{value}</option>)}
                    </select>
                  </label>

                  <label>
                    Town / locality
                    <select
                      value={form.town}
                      onChange={(event) => updateTown(event.target.value)}
                      disabled={!form.region}
                      required
                    >
                      <option value="">{form.region ? 'Select town or locality' : 'Select a region first'}</option>
                      {townOptions.map((value) => <option key={value}>{value}</option>)}
                    </select>
                  </label>

                  {form.town === 'Other / rural locality' && (
                    <label className="full-span">
                      Enter your town or locality
                      <input
                        value={customTown}
                        onChange={(event) => setCustomTown(event.target.value)}
                        placeholder="Type the locality name"
                        required
                      />
                    </label>
                  )}

                  <label className="full-span">
                    Address <span>(optional)</span>
                    <textarea value={form.address} onChange={(event) => update('address', event.target.value)} rows="2" />
                  </label>

                  <label>
                    Emergency contact name <span>(optional)</span>
                    <input value={form.emergency_contact_name} onChange={(event) => update('emergency_contact_name', event.target.value)} />
                  </label>

                  <label>
                    Emergency contact phone <span>(optional)</span>
                    <input
                      value={form.emergency_contact_phone}
                      onChange={(event) => update('emergency_contact_phone', event.target.value)}
                      inputMode="tel"
                    />
                  </label>
                </div>
              </div>
            )}

            {step === 3 && (
              <div className="form-section">
                <div className="form-heading compact">
                  <h2>Recruitment and engagement</h2>
                  <p>This data shows which channels genuinely grow and retain the donor pool.</p>
                </div>

                <div className="form-grid two">
                  <label>
                    How did you join?
                    <select value={form.recruitment_source} onChange={(event) => update('recruitment_source', event.target.value)} required>
                      <option value="">Select source</option>
                      {RECRUITMENT_SOURCES.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                  </label>

                  <label>
                    Recruiting institution <span>(optional)</span>
                    <select value={form.recruitment_institution_id} onChange={(event) => update('recruitment_institution_id', event.target.value)}>
                      <option value="">None selected</option>
                      {relevantInstitutions.map((item) => (
                        <option key={item.id} value={item.id}>{item.name} — {item.town}</option>
                      ))}
                    </select>
                  </label>

                  <label>
                    Preferred contact
                    <select value={form.preferred_contact_method} onChange={(event) => update('preferred_contact_method', event.target.value)}>
                      <option value="sms">SMS</option>
                      <option value="web">Web notification</option>
                      <option value="phone">Phone</option>
                      <option value="email">Email</option>
                    </select>
                  </label>

                  <label>
                    Referral code <span>(optional)</span>
                    <input value={form.referral_code} onChange={(event) => update('referral_code', event.target.value)} />
                  </label>

                  <label className="checkbox-label full-span">
                    <input
                      type="checkbox"
                      checked={form.consent_to_notifications}
                      onChange={(event) => update('consent_to_notifications', event.target.checked)}
                    />
                    <span>I agree to receive donor eligibility, campaign, impact and blood-request notifications. I can change this later.</span>
                  </label>
                </div>

                <div className="registration-note">
                  <HeartHandshake />
                  <div>
                    <strong>Registration is not medical clearance.</strong>
                    <p>DonorConnect supports recruitment and coordination. Medical screening, blood testing and donation approval remain with qualified healthcare professionals.</p>
                  </div>
                </div>
              </div>
            )}

            <div className="form-navigation">
              {step > 1 ? (
                <button type="button" className="button button-secondary" onClick={() => setStep((value) => value - 1)}>Back</button>
              ) : <span />}

              <button
                className="button button-primary"
                disabled={state.loading || (step === 1 && identityDetails.valid && !identityDetails.isEligible)}
              >
                {state.loading
                  ? 'Creating account…'
                  : step < 3
                    ? <>Continue <ArrowRight size={17} /></>
                    : <>Join DonorConnect <HeartHandshake size={18} /></>}
              </button>
            </div>
          </form>
        </section>
      </main>
    </div>
  )
}
```

## `frontend/src/pages/LoginPage.jsx`

```jsx
import { ArrowLeft, Eye, EyeOff, Fingerprint, LogIn } from 'lucide-react'
import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import FormMessage from '../components/FormMessage'
import { useAuth } from '../context/AuthContext'
import { NATIONAL_ID_LENGTH, normalizeNationalId } from '../utils/identity'

export default function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [form, setForm] = useState({ national_id: '', password: '' })
  const [showPassword, setShowPassword] = useState(false)
  const [state, setState] = useState({ loading: false, message: '', errors: null })

  if (user) return <Navigate to="/app/dashboard" replace />

  const submit = async (event) => {
    event.preventDefault()
    setState({ loading: true, message: '', errors: null })

    try {
      await login(form)
      navigate(location.state?.from || '/app/dashboard', { replace: true })
    } catch (error) {
      setState({ loading: false, message: error.message, errors: error.errors })
    }
  }

  return (
    <div className="auth-page">
      <section className="auth-panel auth-story">
        <Link to="/" className="back-link"><ArrowLeft size={17} /> Back to DonorConnect</Link>
        <div className="auth-story-copy">
          <img src="/donor-mark.svg" alt="" />
          <span>Welcome back</span>
          <h1>Your donor journey continues here.</h1>
          <p>Track eligibility, manage availability, join campaigns and stay connected to Eswatini&apos;s donor community.</p>
        </div>
        <div className="auth-quote">
          <strong>One registration should become a lifetime of impact.</strong>
          <span>That is what donor retention looks like.</span>
        </div>
      </section>

      <section className="auth-panel auth-form-panel">
        <form className="auth-form" onSubmit={submit}>
          <div className="form-heading">
            <span className="form-icon"><Fingerprint size={22} /></span>
            <h2>Sign in</h2>
            <p>Your national ID is your main DonorConnect account identifier.</p>
          </div>

          <FormMessage message={state.message} errors={state.errors} />

          <label>
            National ID number
            <input
              value={form.national_id}
              onChange={(event) => setForm({ ...form, national_id: normalizeNationalId(event.target.value) })}
              placeholder="0412227100041"
              inputMode="numeric"
              maxLength={NATIONAL_ID_LENGTH}
              autoComplete="username"
              required
            />
          </label>

          <label>
            Password
            <div className="password-field">
              <input
                type={showPassword ? 'text' : 'password'}
                value={form.password}
                onChange={(event) => setForm({ ...form, password: event.target.value })}
                placeholder="Enter your password"
                autoComplete="current-password"
                required
              />
              <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label="Toggle password visibility">
                {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </div>
          </label>

          <button className="button button-primary button-full" disabled={state.loading}>
            {state.loading ? 'Signing in…' : <>Sign in <LogIn size={18} /></>}
          </button>

          <p className="auth-switch">New to DonorConnect? <Link to="/register">Join the donor pool</Link></p>
        </form>
      </section>
    </div>
  )
}
```

## `frontend/src/pages/UsersPage.jsx`

```jsx
import { AlertTriangle, Plus, Trash2, UserCog } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { api } from '../api/client'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatusBadge from '../components/StatusBadge'
import { formatDate } from '../utils/format'
import { NATIONAL_ID_LENGTH, normalizeNationalId } from '../utils/identity'

const initialForm = {
  full_name: '',
  national_id: '',
  phone: '',
  email: '',
  password: '',
  role: 'staff',
  institution_id: '',
}

export default function UsersPage() {
  const [items, setItems] = useState(null)
  const [institutions, setInstitutions] = useState([])
  const [open, setOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [form, setForm] = useState(initialForm)
  const [state, setState] = useState({ message: '', type: 'error', errors: null })

  const load = async () => {
    try {
      const [users, institutionRows] = await Promise.all([
        api('/admin/users'),
        api('/institutions'),
      ])
      setItems(users)
      setInstitutions(institutionRows)
    } catch (error) {
      setState({ message: error.message, type: 'error', errors: error.errors })
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const availableInstitutions = useMemo(() => {
    const active = institutions.filter((item) => item.is_active == 1)
    if (form.role === 'hospital') return active.filter((item) => item.institution_type === 'hospital')
    if (form.role === 'staff') return active.filter((item) => item.institution_type === 'blood_service')
    return active
  }, [form.role, institutions])

  const updateRole = (role) => {
    setForm((current) => ({ ...current, role, institution_id: '' }))
  }

  const create = async (event) => {
    event.preventDefault()
    setState({ message: '', type: 'error', errors: null })

    try {
      await api('/admin/users', {
        method: 'POST',
        body: {
          ...form,
          institution_id: form.institution_id || null,
        },
      })
      setOpen(false)
      setForm(initialForm)
      setState({ message: 'Operational account created. The user can now sign in with their national ID.', type: 'success', errors: null })
      await load()
    } catch (error) {
      setState({ message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const changeStatus = async (id, account_status) => {
    try {
      await api(`/admin/users/${id}/status`, { method: 'PATCH', body: { account_status } })
      setState({ message: 'Account status updated.', type: 'success', errors: null })
      await load()
    } catch (error) {
      setState({ message: error.message, type: 'error', errors: error.errors })
    }
  }

  const remove = async () => {
    if (!deleteTarget) return

    try {
      await api(`/admin/users/${deleteTarget.id}`, { method: 'DELETE' })
      setDeleteTarget(null)
      setState({ message: 'User account deleted.', type: 'success', errors: null })
      await load()
    } catch (error) {
      setDeleteTarget(null)
      setState({ message: error.message, type: 'error', errors: error.errors })
    }
  }

  return (
    <div className="dashboard-stack">
      <section className="welcome-banner compact-banner">
        <div>
          <span className="eyebrow">Access governance</span>
          <h2>Manage operational roles and institutions.</h2>
          <p>Hospital, blood-service staff and administrator accounts are created by authorised administrators. Every account uses a unique national ID as its login identifier.</p>
        </div>
        <button className="button button-primary" onClick={() => setOpen(true)}><Plus size={18} /> New account</button>
      </section>

      <FormMessage type={state.type} message={state.message} errors={state.errors} />

      <section className="panel">
        {!items ? (
          <div className="panel-loading"><div className="blood-loader" />Loading user accounts…</div>
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>User</th>
                  <th>National ID</th>
                  <th>Role</th>
                  <th>Institution</th>
                  <th>Last login</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <strong>{item.full_name}</strong>
                      <small>{item.phone} • {item.email || 'No email'}</small>
                    </td>
                    <td>{item.national_id_last_four ? `•••••••••${item.national_id_last_four}` : 'Not assigned'}</td>
                    <td><StatusBadge value={item.role} /></td>
                    <td>{item.institution_name || 'Independent / system-wide'}</td>
                    <td>{formatDate(item.last_login_at, true)}</td>
                    <td>
                      <select value={item.account_status} onChange={(event) => changeStatus(item.id, event.target.value)}>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                        <option value="pending">Pending</option>
                      </select>
                    </td>
                    <td>
                      <button className="icon-button danger-icon" onClick={() => setDeleteTarget(item)} title="Delete account">
                        <Trash2 size={18} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <Modal open={open} onClose={() => setOpen(false)} title="Create operational account" wide>
        <form className="form-section" onSubmit={create}>
          <div className="form-grid two">
            <label>
              Full name
              <input value={form.full_name} onChange={(event) => setForm({ ...form, full_name: event.target.value })} required />
            </label>

            <label>
              National ID
              <input
                value={form.national_id}
                onChange={(event) => setForm({ ...form, national_id: normalizeNationalId(event.target.value) })}
                inputMode="numeric"
                maxLength={NATIONAL_ID_LENGTH}
                placeholder="0412227100041"
                required
              />
            </label>

            <label>
              Role
              <select value={form.role} onChange={(event) => updateRole(event.target.value)}>
                <option value="staff">Blood service staff</option>
                <option value="hospital">Hospital user</option>
                <option value="admin">Administrator</option>
              </select>
            </label>

            <label>
              Phone
              <input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} inputMode="tel" required />
            </label>

            <label>
              Email
              <input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} />
            </label>

            <label>
              Institution {form.role !== 'admin' ? <span>(required)</span> : <span>(optional)</span>}
              <select
                value={form.institution_id}
                onChange={(event) => setForm({ ...form, institution_id: event.target.value })}
                required={form.role !== 'admin'}
              >
                <option value="">{form.role === 'admin' ? 'No institution' : 'Select institution'}</option>
                {availableInstitutions.map((item) => (
                  <option key={item.id} value={item.id}>{item.name}</option>
                ))}
              </select>
            </label>

            <label className="full-span">
              Temporary password
              <input
                type="password"
                value={form.password}
                onChange={(event) => setForm({ ...form, password: event.target.value })}
                minLength="10"
                required
              />
              <small>At least 10 characters with uppercase, lowercase and a number.</small>
            </label>
          </div>

          <button className="button button-primary"><UserCog size={17} /> Create account</button>
        </form>
      </Modal>

      <Modal open={Boolean(deleteTarget)} onClose={() => setDeleteTarget(null)} title="Delete user account">
        <div className="danger-dialog">
          <AlertTriangle size={28} />
          <div>
            <strong>Delete {deleteTarget?.full_name}?</strong>
            <p>Unused accounts can be permanently deleted. Accounts with donation, eligibility, campaign, request or staff activity history are protected and must be deactivated instead.</p>
          </div>
        </div>
        <div className="modal-action-row">
          <button className="button button-secondary" onClick={() => setDeleteTarget(null)}>Cancel</button>
          <button className="button button-danger" onClick={remove}><Trash2 size={17} /> Delete account</button>
        </div>
      </Modal>
    </div>
  )
}
```

## `frontend/src/pages/InstitutionsPage.jsx`

```jsx
import { AlertTriangle, Building2, Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api } from '../api/client'
import FormMessage from '../components/FormMessage'
import Modal from '../components/Modal'
import StatusBadge from '../components/StatusBadge'
import { ESWATINI_REGIONS, ESWATINI_TOWNS, INSTITUTION_TYPES } from '../data/eswatini'
import { titleCase } from '../utils/format'

const initial = {
  name: '',
  institution_type: 'hospital',
  phone: '',
  email: '',
  region: 'Hhohho',
  town: '',
  address: '',
  is_active: true,
}

export default function InstitutionsPage() {
  const [items, setItems] = useState(null)
  const [open, setOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [form, setForm] = useState(initial)
  const [customTown, setCustomTown] = useState('')
  const [state, setState] = useState({ message: '', type: 'error', errors: null })

  const load = () => api('/institutions')
    .then(setItems)
    .catch((error) => setState({ message: error.message, type: 'error', errors: error.errors }))

  useEffect(() => {
    void load()
  }, [])

  const updateRegion = (region) => {
    setCustomTown('')
    setForm((current) => ({ ...current, region, town: '' }))
  }

  const submit = async (event) => {
    event.preventDefault()
    setState({ message: '', type: 'error', errors: null })

    try {
      await api('/institutions', {
        method: 'POST',
        body: {
          ...form,
          town: form.town === 'Other / rural locality' ? customTown.trim() : form.town,
        },
      })
      setOpen(false)
      setCustomTown('')
      setForm(initial)
      setState({ message: 'Institution created.', type: 'success', errors: null })
      await load()
    } catch (error) {
      setState({ message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const remove = async () => {
    if (!deleteTarget) return

    try {
      await api(`/institutions/${deleteTarget.id}`, { method: 'DELETE' })
      setDeleteTarget(null)
      setState({ message: 'Institution deleted.', type: 'success', errors: null })
      await load()
    } catch (error) {
      setDeleteTarget(null)
      setState({ message: error.message, errors: error.errors, type: 'error' })
    }
  }

  const townOptions = ESWATINI_TOWNS[form.region] || []

  return (
    <div className="dashboard-stack">
      <section className="welcome-banner compact-banner">
        <div>
          <span className="eyebrow">Network administration</span>
          <h2>Institutions that recruit, verify and request blood.</h2>
          <p>Schools, universities, churches, workplaces, hospitals and blood-service facilities all contribute to the donor ecosystem.</p>
        </div>
        <button className="button button-primary" onClick={() => setOpen(true)}><Plus size={18} /> Add institution</button>
      </section>

      <FormMessage type={state.type} message={state.message} errors={state.errors} />

      <section className="panel">
        {!items ? (
          <div className="panel-loading"><div className="blood-loader" />Loading institutions…</div>
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Institution</th>
                  <th>Type</th>
                  <th>Location</th>
                  <th>Contact</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <strong>{item.name}</strong>
                      <small>{item.address || 'No address recorded'}</small>
                    </td>
                    <td>{titleCase(item.institution_type)}</td>
                    <td>{item.town}<small>{item.region}</small></td>
                    <td>{item.phone || '—'}<small>{item.email || ''}</small></td>
                    <td><StatusBadge value={item.is_active == 1 ? 'active' : 'inactive'} /></td>
                    <td>
                      <button className="icon-button danger-icon" onClick={() => setDeleteTarget(item)} title="Delete institution">
                        <Trash2 size={18} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <Modal open={open} onClose={() => setOpen(false)} title="Add institution" wide>
        <form className="form-section" onSubmit={submit}>
          <div className="form-grid two">
            <label>
              Institution name
              <input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required />
            </label>

            <label>
              Type
              <select value={form.institution_type} onChange={(event) => setForm({ ...form, institution_type: event.target.value })}>
                {INSTITUTION_TYPES.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </label>

            <label>
              Region
              <select value={form.region} onChange={(event) => updateRegion(event.target.value)}>
                {ESWATINI_REGIONS.map((value) => <option key={value}>{value}</option>)}
              </select>
            </label>

            <label>
              Town / locality
              <select value={form.town} onChange={(event) => setForm({ ...form, town: event.target.value })} required>
                <option value="">Select town or locality</option>
                {townOptions.map((value) => <option key={value}>{value}</option>)}
              </select>
            </label>

            {form.town === 'Other / rural locality' && (
              <label className="full-span">
                Enter the town or locality
                <input value={customTown} onChange={(event) => setCustomTown(event.target.value)} required />
              </label>
            )}

            <label>
              Phone
              <input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} inputMode="tel" />
            </label>

            <label>
              Email
              <input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} />
            </label>

            <label className="full-span">
              Address
              <textarea rows="3" value={form.address} onChange={(event) => setForm({ ...form, address: event.target.value })} />
            </label>
          </div>

          <button className="button button-primary"><Building2 size={17} /> Create institution</button>
        </form>
      </Modal>

      <Modal open={Boolean(deleteTarget)} onClose={() => setDeleteTarget(null)} title="Delete institution">
        <div className="danger-dialog">
          <AlertTriangle size={28} />
          <div>
            <strong>Delete {deleteTarget?.name}?</strong>
            <p>The institution can be deleted only when no user accounts are still linked to it. Historical campaign, donation and request records remain preserved.</p>
          </div>
        </div>
        <div className="modal-action-row">
          <button className="button button-secondary" onClick={() => setDeleteTarget(null)}>Cancel</button>
          <button className="button button-danger" onClick={remove}><Trash2 size={17} /> Delete institution</button>
        </div>
      </Modal>
    </div>
  )
}
```

## `frontend/src/styles/identity-admin.css`

```css
.identity-preview {
  display: flex;
  align-items: flex-start;
  gap: 13px;
  padding: 15px 16px;
  border: 1px solid #bbf7d0;
  border-radius: 13px;
  background: #f0fdf4;
}

.identity-preview > svg {
  flex: 0 0 auto;
  color: #15803d;
}

.identity-preview strong,
.identity-preview p {
  display: block;
}

.identity-preview p {
  margin-top: 3px;
  color: #3f5f49;
  font-size: 13px;
}

.identity-preview.underage {
  border-color: #fed7aa;
  background: #fff7ed;
}

.identity-preview.underage > svg {
  color: #c2410c;
}

.identity-preview.underage p {
  color: #7c4a26;
}

.derived-date-field {
  min-height: 75px;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid #e1e5eb;
  border-radius: 11px;
  background: #f8fafc;
}

.derived-date-field > svg {
  color: #b91c1c;
}

.derived-date-field span,
.derived-date-field strong {
  display: block;
}

.derived-date-field span {
  color: #667085;
  font-size: 12px;
  font-weight: 700;
}

.derived-date-field strong {
  margin-top: 3px;
  color: #1f2937;
  font-size: 14px;
}

.button-danger {
  color: #fff;
  background: linear-gradient(135deg, #991b1b, #dc2626);
  box-shadow: 0 10px 24px rgba(153, 27, 27, .2);
}

.button-danger:hover:not(:disabled) {
  box-shadow: 0 14px 30px rgba(153, 27, 27, .3);
}

.danger-icon {
  color: #b91c1c;
}

.danger-icon:hover {
  color: #fff;
  background: #b91c1c;
}

.danger-dialog {
  display: flex;
  align-items: flex-start;
  gap: 15px;
  padding: 16px;
  border: 1px solid #fecaca;
  border-radius: 14px;
  background: #fff7f7;
}

.danger-dialog > svg {
  flex: 0 0 auto;
  color: #b91c1c;
}

.danger-dialog strong {
  display: block;
  color: #7f1d1d;
  font-size: 16px;
}

.danger-dialog p {
  margin-top: 6px;
  font-size: 13px;
}

.modal-action-row {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 20px;
}

@media (max-width: 700px) {
  .modal-action-row {
    flex-direction: column-reverse;
  }

  .modal-action-row .button {
    width: 100%;
  }
}
```

## `frontend/src/main.jsx`

```jsx
import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App'
import { AuthProvider } from './context/AuthContext'
import './styles/index.css'
import './styles/identity-admin.css'

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <App />
      </AuthProvider>
    </BrowserRouter>
  </React.StrictMode>,
)
```
