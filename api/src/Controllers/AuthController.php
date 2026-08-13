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
use App\Services\SmsService;

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
            ->required($data, ['full_name','national_id','phone','password','gender','region','town','recruitment_source','emergency_contact_name','emergency_contact_phone'])
            ->string($data, 'full_name', 3, 180)
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->string($data, 'phone', 8, 20)
            ->string($data, 'password', 10, 128)
            ->email($data, 'email', true)
            ->string($data, 'emergency_contact_name', 3, 180)
            ->string($data, 'emergency_contact_phone', 8, 20)
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

        $emergencyPhone = Identity::phone((string) ($data['emergency_contact_phone'] ?? ''));
        if (!Identity::validEswatiniPhone($emergencyPhone)) {
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

        $duplicateSql =
            'SELECT national_id_hash, phone, email FROM users
             WHERE national_id_hash = :national_hash OR phone = :phone';

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
                 (full_name, national_id_encrypted, national_id_hash, national_id_last_four, email, phone, password_hash, password_status, role, account_status)
                 VALUES (:full_name, :encrypted, :hash, :last_four, :email, :phone, :password_hash, 'set', 'donor', 'active')"
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
            $eligibilityDays = ($data['gender'] ?? '') === 'male' ? 60 : 90;

            $profileStatement = $db->prepare(
                "INSERT INTO donor_profiles
                 (user_id, donor_code, blood_type, date_of_birth, gender, region, town, address, availability_status,
                  verification_status, eligibility_status, preferred_contact_method, recruitment_source,
                  recruitment_institution_id, recruitment_campaign_id, referral_code, emergency_contact_name,
                  emergency_contact_phone, consent_to_notifications, profile_completion_score, eligibility_days)
                 VALUES
                 (:user_id, :donor_code, :blood_type, :date_of_birth, :gender, :region, :town, :address, :availability,
                  'pending', 'not_assessed', :contact_method, :source, :institution_id, :campaign_id, :referral_code,
                  :emergency_name, :emergency_phone, :consent, :completion, :eligibility_days)"
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
                'eligibility_days' => $eligibilityDays,
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
        $phone = Identity::phone((string) ($data['phone'] ?? ''));
        if (!Identity::validNationalId($nationalId)) {
            throw new HttpException(422, 'Enter a valid 13-digit national ID.', ['national_id' => 'The national ID must begin with a valid YYMMDD birth date.']);
        }

        $nationalHash = App::crypto()->searchHash($nationalId);
        RateLimiter::hit('login', $request->ip() . '|' . $nationalHash, 8, 15);

        $db = Database::connection();
        $statement = $db->prepare('SELECT * FROM users WHERE national_id_hash = :national_hash LIMIT 1');
        $statement->execute(['national_hash' => $nationalHash]);
        $user = $statement->fetch();

        if (!$user || empty($user['password_hash']) || !password_verify((string) $data['password'], (string) $user['password_hash'])) {
            if ($user) {
                $attempts = (int) $user['failed_login_attempts'] + 1;
                $lockedUntil = $attempts >= 5 ? (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s') : null;
                $update = $db->prepare('UPDATE users SET failed_login_attempts = :attempts, locked_until = :locked_until WHERE id = :id');
                $update->execute(['attempts' => $attempts, 'locked_until' => $lockedUntil, 'id' => $user['id']]);
            }
            if ($user && empty($user['password_hash']) && ($user['role'] ?? '') === 'donor') {
                throw new HttpException(401, 'This donor account has no password yet. Use OTP login, then create a password from your profile.');
            }
            throw new HttpException(401, 'The national ID or password is incorrect.');
        }

        if ($user['account_status'] !== 'active') {
            throw new HttpException(403, 'This account is not currently active. Contact an administrator.');
        }
        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            throw new HttpException(423, 'This account is temporarily locked after repeated login attempts.');
        }

        if (!empty($user['password_hash']) && password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
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


    public function requestOtp(Request $request): never
    {
        $data = $request->json();
        (new Validator())
            ->required($data, ['national_id','phone'])
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->string($data, 'phone', 8, 20)
            ->validate();

        $nationalId = Identity::nationalId((string) $data['national_id']);
        $phone = Identity::phone((string) ($data['phone'] ?? ''));
        if (!Identity::validNationalId($nationalId)) {
            throw new HttpException(422, 'Enter a valid 13-digit national ID.', ['national_id' => 'The national ID must begin with a valid YYMMDD birth date.']);
        }
        if (!Identity::validEswatiniPhone($phone)) {
            throw new HttpException(422, 'Enter the donor phone number linked to this account.', ['phone' => 'Enter a valid Eswatini phone number.']);
        }

        $nationalHash = App::crypto()->searchHash($nationalId);
        RateLimiter::hit('otp_request', $request->ip() . '|' . $nationalHash, 5, 10);

        $db = Database::connection();
        $statement = $db->prepare("SELECT id, full_name, phone, role, account_status FROM users WHERE national_id_hash = :national_hash LIMIT 1");
        $statement->execute(['national_hash' => $nationalHash]);
        $user = $statement->fetch();

        if (!$user || $user['role'] !== 'donor') {
            throw new HttpException(404, 'No donor account was found for this national ID.');
        }
        if ($user['account_status'] !== 'active') {
            throw new HttpException(403, 'This donor account is not currently active. Contact ENBTS support.');
        }
        if (Identity::phone((string) $user['phone']) !== $phone) {
            throw new HttpException(403, 'The phone number does not match this donor account.', ['phone' => 'Use the phone number that was used during registration.']);
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = (new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');

        $db->beginTransaction();
        try {
            $expireOld = $db->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE user_id = :user_id AND consumed_at IS NULL');
            $expireOld->execute(['user_id' => $user['id']]);

            $insert = $db->prepare(
                "INSERT INTO login_otps (user_id, national_id_hash, code_hash, expires_at, request_ip)
                 VALUES (:user_id, :national_hash, :code_hash, :expires_at, :request_ip)"
            );
            $insert->execute([
                'user_id' => $user['id'],
                'national_hash' => $nationalHash,
                'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                'expires_at' => $expiresAt,
                'request_ip' => $request->ip(),
            ]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        (new SmsService())->send((int) $user['id'], (string) $user['phone'], "Your DonorConnect login OTP is {$code}. It expires in 10 minutes.");

        Response::success('OTP sent to the donor phone number on record.', [
            'masked_phone' => substr((string) $user['phone'], 0, 4) . '****' . substr((string) $user['phone'], -2),
            'expires_at' => $expiresAt,
        ]);
    }

    public function verifyOtp(Request $request): never
    {
        $data = $request->json();
        (new Validator())
            ->required($data, ['national_id','otp'])
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->string($data, 'otp', 4, 10)
            ->validate();

        $nationalId = Identity::nationalId((string) $data['national_id']);
        if (!Identity::validNationalId($nationalId)) {
            throw new HttpException(422, 'Enter a valid 13-digit national ID.', ['national_id' => 'The national ID must begin with a valid YYMMDD birth date.']);
        }

        $otp = preg_replace('/\D+/', '', (string) $data['otp']) ?? '';
        if (strlen($otp) !== 6) {
            throw new HttpException(422, 'Enter the 6-digit OTP code.', ['otp' => 'The OTP must be 6 digits.']);
        }

        $nationalHash = App::crypto()->searchHash($nationalId);
        RateLimiter::hit('otp_verify', $request->ip() . '|' . $nationalHash, 8, 15);

        $db = Database::connection();
        $statement = $db->prepare(
            "SELECT lo.*, u.account_status, u.role
             FROM login_otps lo
             JOIN users u ON u.id = lo.user_id
             WHERE lo.national_id_hash = :national_hash
               AND lo.consumed_at IS NULL
               AND lo.expires_at > NOW()
             ORDER BY lo.created_at DESC
             LIMIT 1"
        );
        $statement->execute(['national_hash' => $nationalHash]);
        $row = $statement->fetch();

        if (!$row || !password_verify($otp, $row['code_hash'])) {
            if ($row) {
                $attempts = (int) $row['attempts'] + 1;
                $updateAttempts = $db->prepare('UPDATE login_otps SET attempts = :attempts WHERE id = :id');
                $updateAttempts->execute(['attempts' => $attempts, 'id' => $row['id']]);
            }
            throw new HttpException(401, 'The OTP is incorrect or expired.');
        }
        if ($row['role'] !== 'donor') {
            throw new HttpException(403, 'OTP login is only available for donor accounts.');
        }
        if ($row['account_status'] !== 'active') {
            throw new HttpException(403, 'This donor account is not currently active. Contact ENBTS support.');
        }

        $consume = $db->prepare('UPDATE login_otps SET consumed_at = NOW(), attempts = attempts + 1 WHERE id = :id');
        $consume->execute(['id' => $row['id']]);
        $loginUpdate = $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id');
        $loginUpdate->execute(['id' => $row['user_id']]);

        App::auth()->login((int) $row['user_id']);
        $current = App::auth()->user();
        if (($current['role'] ?? '') === 'donor' && isset($current['donor_id'])) {
            $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description) VALUES (:donor_id, 'login', 'Donor signed in using OTP.')");
            $activity->execute(['donor_id' => $current['donor_id']]);
        }

        Audit::log('OTP_LOGIN', 'Donor signed in using OTP.', 'user', (int) $row['user_id'], null, null, $request);
        Response::success('Welcome back.', ['user' => $current, 'csrf_token' => App::auth()->csrfToken()]);
    }


    public function updatePassword(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $data = $request->json();
        (new Validator())
            ->required($data, ['new_password','confirm_password'])
            ->string($data, 'current_password', 0, 128, true)
            ->string($data, 'new_password', 10, 128)
            ->string($data, 'confirm_password', 10, 128)
            ->validate();

        $newPassword = (string) $data['new_password'];
        if ($newPassword !== (string) $data['confirm_password']) {
            throw new HttpException(422, 'The password confirmation does not match.', ['confirm_password' => 'Re-enter the new password.']);
        }
        if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            throw new HttpException(422, 'Use at least one uppercase letter, one lowercase letter and one number.', ['new_password' => 'Use a stronger password.']);
        }

        $db = Database::connection();
        $statement = $db->prepare('SELECT id, password_hash, password_status FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $user['id']]);
        $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Account not found.');

        $hasPassword = !empty($row['password_hash']) && ($row['password_status'] ?? 'set') === 'set';
        if ($hasPassword && !password_verify((string) ($data['current_password'] ?? ''), (string) $row['password_hash'])) {
            throw new HttpException(401, 'Current password is incorrect.', ['current_password' => 'Enter your current password.']);
        }

        $update = $db->prepare("UPDATE users SET password_hash = :hash, password_status = 'set' WHERE id = :id");
        $update->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $user['id']]);

        $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description) VALUES (:donor_id, 'profile_updated', 'Donor updated account password.')");
        $activity->execute(['donor_id' => $user['donor_id']]);
        Audit::log('DONOR_PASSWORD_UPDATED', 'Donor created or changed their password.', 'user', (int) $user['id'], null, ['password_status' => 'set'], $request);
        Response::success($hasPassword ? 'Password changed successfully.' : 'Password created successfully.');
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

    // ─── Forgot Password Flow ────────────────────────────────────────────────

    /**
     * Step 1: Look up the account and return masked contact details.
     * Does NOT reveal whether the account exists (returns identical shape for not-found).
     */
    public function forgotPasswordRequest(Request $request): never
    {
        $data = $request->json();
        (new Validator())
            ->required($data, ['national_id'])
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->validate();

        $nationalId = Identity::nationalId((string) $data['national_id']);
        if (!Identity::validNationalId($nationalId)) {
            throw new HttpException(422, 'Enter a valid 13-digit national ID.', ['national_id' => 'The national ID must begin with a valid YYMMDD birth date.']);
        }

        RateLimiter::hit('forgot_request', $request->ip(), 10, 30);

        $nationalHash = App::crypto()->searchHash($nationalId);
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, phone, email, account_status FROM users WHERE national_id_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => $nationalHash]);
        $user = $stmt->fetch();

        if (!$user || $user['account_status'] !== 'active') {
            throw new HttpException(404, 'Account not found.');
        }

        $p = (string) $user['phone'];
        $maskedPhone = strlen($p) >= 4 ? substr($p, 0, 3) . '****' . substr($p, -2) : '***';
        $maskedEmail = null;
        if (!empty($user['email'])) {
            $parts = explode('@', (string) $user['email']);
            $maskedEmail = substr($parts[0], 0, 2) . '****@' . ($parts[1] ?? '***');
        }

        Response::success('Account lookup complete.', [
            'found'        => true,
            'masked_phone' => $maskedPhone,
            'masked_email' => $maskedEmail,
            'has_email'    => $maskedEmail !== null,
        ]);
    }

    /**
     * Step 2: Generate a 6-digit reset code and send it via SMS or email.
     */
    public function forgotPasswordSend(Request $request): never
    {
        $data = $request->json();
        (new Validator())
            ->required($data, ['national_id', 'method'])
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->in($data, 'method', ['phone', 'email'])
            ->validate();

        $nationalId = Identity::nationalId((string) $data['national_id']);
        if (!Identity::validNationalId($nationalId)) {
            throw new HttpException(422, 'Enter a valid 13-digit national ID.', ['national_id' => 'Invalid national ID.']);
        }

        RateLimiter::hit('forgot_send', $request->ip(), 5, 15);

        $nationalHash = App::crypto()->searchHash($nationalId);
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, phone, email, account_status FROM users WHERE national_id_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => $nationalHash]);
        $user = $stmt->fetch();

        if (!$user || $user['account_status'] !== 'active') {
            // Timing-safe: pretend we sent it
            Response::success('If an account was found, a reset code has been sent.');
        }

        $method = (string) $data['method'];
        if ($method === 'email' && empty($user['email'])) {
            throw new HttpException(422, 'No email address is registered on this account. Use phone recovery instead.', ['method' => 'No email on file.']);
        }

        $code      = (string) random_int(100000, 999999);
        $expiresAt = (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');

        $db->beginTransaction();
        try {
            // Invalidate any old unused tokens
            $expire = $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL');
            $expire->execute(['uid' => $user['id']]);

            $insert = $db->prepare(
                "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                 VALUES (:uid, :hash, :expires_at)"
            );
            $insert->execute([
                'uid'        => $user['id'],
                'hash'       => hash('sha256', $code),
                'expires_at' => $expiresAt,
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        $message = "Your DonorConnect password reset code is {$code}. It expires in 15 minutes. Do not share this code.";
        (new SmsService())->send((int) $user['id'], (string) $user['phone'], $message);

        Audit::log('FORGOT_PASSWORD_CODE_SENT', 'Password reset code sent.', 'user', (int) $user['id'], null, ['method' => $method], $request);
        Response::success('Reset code sent.', [
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Step 3: Verify the code and set a new password.
     */
    public function forgotPasswordReset(Request $request): never
    {
        $data = $request->json();
        (new Validator())
            ->required($data, ['national_id', 'code', 'new_password', 'confirm_password'])
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->string($data, 'code', 4, 10)
            ->string($data, 'new_password', 10, 128)
            ->string($data, 'confirm_password', 10, 128)
            ->validate();

        $nationalId = Identity::nationalId((string) $data['national_id']);
        if (!Identity::validNationalId($nationalId)) {
            throw new HttpException(422, 'Enter a valid national ID.', ['national_id' => 'Invalid national ID.']);
        }

        $newPassword = (string) $data['new_password'];
        if ($newPassword !== (string) $data['confirm_password']) {
            throw new HttpException(422, 'Passwords do not match.', ['confirm_password' => 'Re-enter the new password.']);
        }
        if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            throw new HttpException(422, 'Password must have at least one uppercase letter, one lowercase letter and one number.', ['new_password' => 'Use a stronger password.']);
        }

        $code = preg_replace('/\D+/', '', (string) $data['code']) ?? '';
        if (strlen($code) !== 6) {
            throw new HttpException(422, 'Enter the 6-digit code sent to your phone.', ['code' => 'Must be 6 digits.']);
        }

        RateLimiter::hit('forgot_reset', $request->ip(), 8, 15);

        $nationalHash = App::crypto()->searchHash($nationalId);
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT prt.*, u.id AS user_id, u.account_status
             FROM password_reset_tokens prt
             JOIN users u ON u.id = prt.user_id
             WHERE u.national_id_hash = :hash
               AND prt.used_at IS NULL
               AND prt.expires_at > NOW()
             ORDER BY prt.created_at DESC
             LIMIT 1"
        );
        $stmt->execute(['hash' => $nationalHash]);
        $row = $stmt->fetch();

        if (!$row || hash('sha256', $code) !== (string) $row['token_hash']) {
            throw new HttpException(401, 'The reset code is incorrect or has expired. Request a new code.');
        }
        if ($row['account_status'] !== 'active') {
            throw new HttpException(403, 'This account is not active. Contact ENBTS support.');
        }

        $db->beginTransaction();
        try {
            $updateToken = $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id');
            $updateToken->execute(['id' => $row['id']]);

            $updateUser = $db->prepare("UPDATE users SET password_hash = :hash, password_status = 'set', failed_login_attempts = 0, locked_until = NULL WHERE id = :id");
            $updateUser->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $row['user_id']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        Audit::log('FORGOT_PASSWORD_RESET', 'Donor reset their password via forgot-password flow.', 'user', (int) $row['user_id'], null, null, $request);
        Response::success('Password has been reset successfully. You can now sign in with your new password.');
    }
}
