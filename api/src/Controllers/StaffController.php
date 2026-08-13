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
use App\Services\NotificationService;

final class StaffController
{
    public function donors(Request $request): never
    {
        $search = trim((string) $request->query('search', ''));
        $bloodType = trim((string) $request->query('blood_type', ''));
        $region = trim((string) $request->query('region', ''));
        $verification = trim((string) $request->query('verification_status', ''));
        $eligibility = trim((string) $request->query('eligibility_status', ''));
        $availability = trim((string) $request->query('availability_status', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            // PDO named parameters must be unique per statement — split :search into four distinct names.
            $where[] = '(u.full_name LIKE :s_name OR u.phone LIKE :s_phone OR dp.donor_code LIKE :s_code OR dp.town LIKE :s_town)';
            $searchLike = "%{$search}%";
            $params['s_name']  = $searchLike;
            $params['s_phone'] = $searchLike;
            $params['s_code']  = $searchLike;
            $params['s_town']  = $searchLike;
        }
        foreach ([
            'blood_type' => $bloodType,
            'region' => $region,
            'verification_status' => $verification,
            'eligibility_status' => $eligibility,
            'availability_status' => $availability,
        ] as $column => $value) {
            if ($value !== '') {
                $where[] = "dp.{$column} = :{$column}";
                $params[$column] = $value;
            }
        }

        $db = Database::connection();
        $whereSql = implode(' AND ', $where);
        $count = $db->prepare("SELECT COUNT(*) FROM donor_profiles dp JOIN users u ON u.id = dp.user_id WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $statement = $db->prepare(
            "SELECT dp.id, dp.donor_code, u.full_name, u.phone, u.phone_secondary, u.email, u.account_status,
                    dp.blood_type, dp.region, dp.town, dp.availability_status, dp.verification_status,
                    dp.eligibility_status, dp.next_eligible_date, dp.total_donations, dp.recruitment_source,
                    dp.created_at
             FROM donor_profiles dp JOIN users u ON u.id = dp.user_id
             WHERE {$whereSql}
             ORDER BY dp.created_at DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $statement->execute($params);
        Response::success('Donors loaded.', [
            'items' => $statement->fetchAll(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage)],
        ]);
    }


    public function createDonor(Request $request): never
    {
        $staff = App::auth()->requireRoles(['staff','admin']);
        $data = $request->json();

        $validator = (new Validator())
            ->required($data, ['full_name','national_id','phone','gender','region','town','recruitment_source','emergency_contact_name','emergency_contact_phone'])
            ->string($data, 'full_name', 3, 180)
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->string($data, 'phone', 8, 20)
            ->string($data, 'phone_secondary', 8, 20, true)
            ->email($data, 'email', true)
            ->string($data, 'emergency_contact_name', 3, 180)
            ->string($data, 'emergency_contact_phone', 8, 20)
            ->in($data, 'gender', ['male','female','other','prefer_not_to_say'])
            ->in($data, 'region', ['Hhohho','Manzini','Lubombo','Shiselweni'])
            ->string($data, 'town', 2, 120)
            ->in($data, 'blood_type', ['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'], true)
            ->in($data, 'recruitment_source', ['school','university','church','workplace','community_campaign','hospital','social_media','referral','walk_in','other'])
            ->in($data, 'preferred_contact_method', ['sms','phone','email','web'], true)
            ->in($data, 'availability_status', ['available','not_available'], true)
            ->string($data, 'address', 0, 2000, true)
            ->string($data, 'password', 0, 128, true)
            ->string($data, 'notes', 0, 1000, true);

        $nationalId = Identity::nationalId((string) ($data['national_id'] ?? ''));
        $phone = Identity::phone((string) ($data['phone'] ?? ''));
        $birthDate = Identity::birthDateFromNationalId($nationalId);

        if (!Identity::validNationalId($nationalId) || !$birthDate) {
            $validator->add('national_id', 'Enter a valid 13-digit national ID. The first six digits must contain a real birth date in YYMMDD format.');
        }
        if (!Identity::validEswatiniPhone($phone)) {
            $validator->add('phone', 'Enter a valid Eswatini phone number.');
        }

        $phoneSecondary = null;
        if (!empty($data['phone_secondary'])) {
            $phoneSecondary = Identity::phone((string) $data['phone_secondary']);
            if (!Identity::validEswatiniPhone($phoneSecondary)) {
                $validator->add('phone_secondary', 'Enter a valid secondary Eswatini phone number.');
            }
        }

        $password = trim((string) ($data['password'] ?? ''));
        if ($password !== '' && (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || mb_strlen($password) < 10)) {
            $validator->add('password', 'Use at least 10 characters with uppercase, lowercase and a number, or leave it blank for OTP-only donor onboarding.');
        }

        $emergencyPhone = Identity::phone((string) ($data['emergency_contact_phone'] ?? ''));
        if (!Identity::validEswatiniPhone($emergencyPhone)) {
            $validator->add('emergency_contact_phone', 'Enter a valid emergency contact phone number.');
        }

        $validator->validate();

        if (!Identity::isOldEnoughToRegister($nationalId)) {
            $eligibleOn = Identity::donorRegistrationDate($nationalId);
            $eligibleDate = $eligibleOn ? Identity::humanDate($eligibleOn) : 'the required age';
            throw new HttpException(422, "This donor is under the minimum registration age. Based on the national ID, registration opens on {$eligibleDate}.", ['national_id' => "Registration opens on {$eligibleDate}."]);
        }

        $db = Database::connection();
        $crypto = App::crypto();
        $nationalHash = $crypto->searchHash($nationalId);
        $email = trim((string) ($data['email'] ?? '')) ?: null;

        $duplicateSql = 'SELECT national_id_hash, phone, email FROM users WHERE national_id_hash = :national_hash OR phone = :phone';
        $duplicateParams = ['national_hash' => $nationalHash, 'phone' => $phone];
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
            if (($existing['national_id_hash'] ?? '') === $nationalHash) $errors['national_id'] = 'This national ID is already registered.';
            if (($existing['phone'] ?? '') === $phone) $errors['phone'] = 'This phone number is already registered.';
            if ($email !== null && ($existing['email'] ?? '') === $email) $errors['email'] = 'This email address is already registered.';
            throw new HttpException(409, 'An account already exists with some of these details.', $errors);
        }

        $institutionId = isset($data['recruitment_institution_id']) && $data['recruitment_institution_id'] !== ''
            ? (int) $data['recruitment_institution_id']
            : ($staff['institution_id'] ?? null);

        if ($institutionId !== null) {
            $check = $db->prepare('SELECT id FROM institutions WHERE id = :id AND is_active = 1');
            $check->execute(['id' => $institutionId]);
            if (!$check->fetch()) {
                throw new HttpException(422, 'The selected recruitment institution is invalid.', ['recruitment_institution_id' => 'Select an active institution.']);
            }
        }

        $passwordProvided = $password !== '';

        $db->beginTransaction();
        try {
            $insertUser = $db->prepare(
                "INSERT INTO users
                 (institution_id, full_name, national_id_encrypted, national_id_hash, national_id_last_four, email, phone, phone_secondary, password_hash, password_status, role, account_status)
                 VALUES (:institution_id, :full_name, :encrypted, :hash, :last_four, :email, :phone, :phone_secondary, :password_hash, :password_status, 'donor', 'active')"
            );
            $insertUser->execute([
                'institution_id' => $institutionId,
                'full_name' => trim((string) $data['full_name']),
                'encrypted' => $crypto->encrypt($nationalId),
                'hash' => $nationalHash,
                'last_four' => substr($nationalId, -4),
                'email' => $email,
                'phone' => $phone,
                'phone_secondary' => $phoneSecondary,
                'password_hash' => $passwordProvided ? password_hash($password, PASSWORD_DEFAULT) : null,
                'password_status' => $passwordProvided ? 'set' : 'unset',
            ]);

            $userId = (int) $db->lastInsertId();
            $donorCode = sprintf('DC-%s-%06d', date('Y'), $userId);
            $birthDateValue = $birthDate->format('Y-m-d');
            $bloodType = $data['blood_type'] ?? 'Unknown';
            $eligibilityDays = ($data['gender'] ?? '') === 'male' ? 60 : 90;

            $insertProfile = $db->prepare(
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
            $insertProfile->execute([
                'user_id' => $userId,
                'donor_code' => $donorCode,
                'blood_type' => $bloodType,
                'date_of_birth' => $birthDateValue,
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
                'description' => 'Donor registered by ENBTS staff at ' . ($staff['institution_name'] ?? 'ENBTS') . '.',
                'metadata' => json_encode([
                    'registered_by' => $staff['id'],
                    'source' => $data['recruitment_source'],
                    'institution_id' => $institutionId,
                    'password_created_by_staff' => $passwordProvided,
                    'otp_login_available' => true,
                    'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                ], JSON_THROW_ON_ERROR),
            ]);

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        (new NotificationService())->create(
            $userId,
            'account',
            'Welcome to DonorConnect',
            !$passwordProvided
                ? 'Your donor profile was created by ENBTS. You can sign in using your National ID and OTP sent to your phone, then create a password from your profile.'
                : 'Your donor profile was created by ENBTS. You can sign in using your National ID and password, or use OTP if you forget the password.',
            '/app/dashboard',
            null,
            null,
            ($data['preferred_contact_method'] ?? 'sms') === 'sms'
        );

        Audit::log('DONOR_REGISTERED_BY_STAFF', 'ENBTS staff registered a donor account.', 'donor_profile', $donorId, null, ['registered_by' => $staff['id'], 'password_provided' => $passwordProvided], $request);
        Response::success('Donor registered successfully.', [
            'donor_id' => $donorId,
            'donor_code' => $donorCode,
            'national_id_masked' => $crypto->mask($nationalId),
            'login_guidance' => !$passwordProvided
                ? 'No password was set. The donor should use National ID + phone OTP, then create a password from their profile.'
                : 'The donor can use password login or National ID + phone OTP login.',
        ], 201);
    }

    public function donor(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::connection();
        $statement = $db->prepare(
            "SELECT dp.*, u.full_name, u.phone, u.phone_secondary, u.email, u.national_id_last_four, u.account_status,
                    ri.name AS recruitment_institution_name
             FROM donor_profiles dp JOIN users u ON u.id = dp.user_id
             LEFT JOIN institutions ri ON ri.id = dp.recruitment_institution_id
             WHERE dp.id = :id LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $donor = $statement->fetch();
        if (!$donor) throw new HttpException(404, 'Donor not found.');
        $donor['national_id_masked'] = '********' . $donor['national_id_last_four'];
        unset($donor['national_id_last_four']);

        $donations = $db->prepare(
            "SELECT dr.*, i.name AS institution_name, c.title AS campaign_title, u.full_name AS recorded_by_name
             FROM donation_records dr
             LEFT JOIN institutions i ON i.id = dr.institution_id
             LEFT JOIN campaigns c ON c.id = dr.campaign_id
             JOIN users u ON u.id = dr.recorded_by
             WHERE dr.donor_id = :id ORDER BY dr.donation_date DESC"
        );
        $donations->execute(['id' => $id]);
        $assessments = $db->prepare(
            "SELECT ea.*, u.full_name AS assessed_by_name FROM eligibility_assessments ea
             JOIN users u ON u.id = ea.assessed_by WHERE ea.donor_id = :id ORDER BY ea.assessment_date DESC"
        );
        $assessments->execute(['id' => $id]);
        $deferrals = $db->prepare(
            "SELECT dd.*, u.full_name AS recorded_by_name FROM donor_deferrals dd
             JOIN users u ON u.id = dd.recorded_by WHERE dd.donor_id = :id ORDER BY dd.created_at DESC"
        );
        $deferrals->execute(['id' => $id]);
        $activity = $db->prepare('SELECT * FROM donor_activity_logs WHERE donor_id = :id ORDER BY created_at DESC LIMIT 50');
        $activity->execute(['id' => $id]);

        $appointments = $db->prepare(
            "SELECT ar.*, i.name AS institution_name
             FROM appointment_requests ar
             JOIN institutions i ON i.id = ar.institution_id
             WHERE ar.donor_id = :id ORDER BY ar.appointment_at DESC"
        );
        $appointments->execute(['id' => $id]);

        $profileUpdateRequests = $db->prepare(
            "SELECT * FROM profile_update_requests
             WHERE donor_id = :id ORDER BY created_at DESC"
        );
        $profileUpdateRequests->execute(['id' => $id]);

        Response::success('Donor details loaded.', [
            'donor' => $donor,
            'donations' => $donations->fetchAll(),
            'assessments' => $assessments->fetchAll(),
            'deferrals' => $deferrals->fetchAll(),
            'activity' => $activity->fetchAll(),
            'appointments' => $appointments->fetchAll(),
            'profile_update_requests' => $profileUpdateRequests->fetchAll(),
        ]);
    }

    public function verify(Request $request): never
    {
        $staff = App::auth()->requireRoles(['staff','admin']);
        $id = (int) $request->param('id');
        $data = $request->json();
        (new Validator())
            ->required($data, ['verification_status','blood_type'])
            ->in($data, 'verification_status', ['verified','rejected'])
            ->in($data, 'blood_type', ['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'])
            ->string($data, 'notes', 0, 1000, true)
            ->integer($data, 'eligibility_days', 14, 730, true)
            ->validate();
        if ($data['verification_status'] === 'verified' && $data['blood_type'] === 'Unknown') {
            throw new HttpException(422, 'A verified donor must have a confirmed blood type.', ['blood_type' => 'Confirm the donor blood type before verification.']);
        }

        $db = Database::connection();
        $beforeStatement = $db->prepare('SELECT dp.*, u.id AS user_id FROM donor_profiles dp JOIN users u ON u.id = dp.user_id WHERE dp.id = :id');
        $beforeStatement->execute(['id' => $id]);
        $before = $beforeStatement->fetch();
        if (!$before) throw new HttpException(404, 'Donor not found.');

        // A donor must have donated at least once before they can be verified
        // (this ensures their info has been confirmed during an actual donation visit).
        if ($data['verification_status'] === 'verified' && (int) $before['total_donations'] < 1) {
            throw new HttpException(409, 'This donor must complete at least one recorded donation before they can be verified. Record their first donation, then verify.', [
                'verification_status' => 'At least 1 donation required before verification.',
            ]);
        }

        $eligibilityDays = isset($data['eligibility_days']) ? (int) $data['eligibility_days'] : null;

        $updateSql = "UPDATE donor_profiles SET verification_status = :status, blood_type = :blood_type,
             blood_type_verified_at = CASE WHEN :status2 = 'verified' THEN NOW() ELSE NULL END";
        $updateParams = ['status' => $data['verification_status'], 'blood_type' => $data['blood_type'], 'status2' => $data['verification_status'], 'id' => $id];
        if ($eligibilityDays !== null) {
            $updateSql .= ', eligibility_days = :eligibility_days';
            $updateParams['eligibility_days'] = $eligibilityDays;
        }
        $updateSql .= ' WHERE id = :id';
        $update = $db->prepare($updateSql);
        $update->execute($updateParams);

        $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:id, 'verified', :description, :metadata)");
        $activity->execute([
            'id' => $id,
            'description' => 'Donor verification status changed to ' . $data['verification_status'] . '.',
            'metadata' => json_encode(['staff_id' => $staff['id'], 'blood_type' => $data['blood_type'], 'notes' => $data['notes'] ?? null, 'eligibility_days' => $eligibilityDays], JSON_THROW_ON_ERROR),
        ]);
        (new NotificationService())->create(
            (int) $before['user_id'], 'account',
            $data['verification_status'] === 'verified' ? 'Donor profile verified' : 'Verification needs attention',
            $data['verification_status'] === 'verified'
                ? 'Your identity and donor profile have been verified. You are now an active member of the DonorConnect donor pool.'
                : 'Your donor verification was not approved. Contact the blood service for guidance.',
            '/app/profile'
        );
        Audit::log('DONOR_VERIFICATION_UPDATED', 'Staff updated donor verification.', 'donor_profile', $id, ['verification_status' => $before['verification_status'], 'blood_type' => $before['blood_type']], ['verification_status' => $data['verification_status'], 'blood_type' => $data['blood_type'], 'eligibility_days' => $eligibilityDays], $request);
        Response::success('Donor verification updated.');
    }

    public function assessEligibility(Request $request): never
    {
        $staff = App::auth()->requireRoles(['staff','admin']);
        $id = (int) $request->param('id');
        $data = $request->json();
        $validator = (new Validator())
            ->required($data, ['outcome'])
            ->in($data, 'outcome', ['eligible','temporarily_deferred','permanently_deferred'])
            ->date($data, 'next_eligible_date', true)
            ->integer($data, 'deferral_days', 1, 3650, true)
            ->string($data, 'reason', 0, 255, true)
            ->string($data, 'notes', 0, 2000, true);

        // Compute next_eligible_date from deferral_days if provided
        $nextEligibleDate = null;
        if ($data['outcome'] === 'temporarily_deferred') {
            if (!empty($data['deferral_days'])) {
                $days = max(1, (int) $data['deferral_days']);
                $nextEligibleDate = date('Y-m-d', strtotime(date('Y-m-d') . " +{$days} days"));
            } elseif (!empty($data['next_eligible_date'])) {
                $nextEligibleDate = $data['next_eligible_date'];
            } else {
                $validator->add('deferral_days', 'Temporary deferrals need either a duration in days or a next eligible date.');
            }
        }
        if ($data['outcome'] !== 'eligible' && empty($data['reason'])) {
            $validator->add('reason', 'Give a reason for the deferral.');
        }
        $validator->validate();

        $db = Database::connection();
        $donorStatement = $db->prepare('SELECT dp.*, u.id AS user_id FROM donor_profiles dp JOIN users u ON u.id = dp.user_id WHERE dp.id = :id');
        $donorStatement->execute(['id' => $id]);
        $donor = $donorStatement->fetch();
        if (!$donor) throw new HttpException(404, 'Donor not found.');
        // Allow assessments for pending donors (not rejected) so first-visit flow works.
        if ($donor['verification_status'] === 'rejected') throw new HttpException(409, 'Cannot assess an explicitly rejected donor. Reinstate verification first.');

        $db->beginTransaction();
        try {
            $assessment = $db->prepare(
                "INSERT INTO eligibility_assessments (donor_id, assessed_by, outcome, next_eligible_date, reason, notes)
                 VALUES (:donor_id, :assessed_by, :outcome, :next_date, :reason, :notes)"
            );
            $assessment->execute([
                'donor_id' => $id, 'assessed_by' => $staff['id'], 'outcome' => $data['outcome'],
                'next_date' => $nextEligibleDate,
                'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
            $update = $db->prepare('UPDATE donor_profiles SET eligibility_status = :status, next_eligible_date = :next_date WHERE id = :id');
            $update->execute(['status' => $data['outcome'], 'next_date' => $nextEligibleDate, 'id' => $id]);

            if ($data['outcome'] !== 'eligible') {
                $deferral = $db->prepare(
                    "INSERT INTO donor_deferrals (donor_id, recorded_by, deferral_type, reason, starts_on, ends_on, notes)
                     VALUES (:donor_id, :recorded_by, :type, :reason, CURDATE(), :ends_on, :notes)"
                );
                $deferral->execute([
                    'donor_id' => $id, 'recorded_by' => $staff['id'],
                    'type' => $data['outcome'] === 'permanently_deferred' ? 'permanent' : 'temporary',
                    'reason' => $data['reason'], 'ends_on' => $nextEligibleDate,
                    'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                ]);

                // Clean up pending matches since the donor is now deferred
                $cleanupMatches = $db->prepare("DELETE FROM request_matches WHERE donor_id = :donor_id AND donor_response = 'pending'");
                $cleanupMatches->execute(['donor_id' => $id]);
            }

            $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:id, 'eligibility_assessed', :description, :metadata)");
            $activity->execute([
                'id' => $id, 'description' => 'Eligibility assessment outcome: ' . str_replace('_', ' ', $data['outcome']) . '.',
                'metadata' => json_encode(['staff_id' => $staff['id'], 'next_eligible_date' => $data['next_eligible_date'] ?? null], JSON_THROW_ON_ERROR),
            ]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        $message = match ($data['outcome']) {
            'eligible' => 'You are currently eligible to donate. Keep your availability updated and watch for nearby campaigns.',
            'temporarily_deferred' => 'You are temporarily deferred. Your next review or eligible date is ' . ($data['next_eligible_date'] ?? 'to be confirmed') . '.',
            default => 'Your donor status has been updated following an eligibility assessment. Contact the blood service if you need clarification.',
        };
        (new NotificationService())->create((int) $donor['user_id'], 'eligibility_reminder', 'Eligibility status updated', $message, '/app/profile');
        Audit::log('ELIGIBILITY_ASSESSED', 'Staff recorded donor eligibility.', 'donor_profile', $id, ['eligibility_status' => $donor['eligibility_status']], ['eligibility_status' => $data['outcome'], 'next_eligible_date' => $data['next_eligible_date'] ?? null], $request);
        Response::success('Eligibility assessment recorded.');
    }

    public function recordDonation(Request $request): never
    {
        $staff = App::auth()->requireRoles(['staff','admin']);
        $id = (int) $request->param('id');
        $data = $request->json();
        (new Validator())
            ->required($data, ['donation_date','donation_type','units','region','town','next_eligible_date'])
            ->date($data, 'donation_date')
            ->in($data, 'donation_type', ['whole_blood','plasma','platelets','other'])
            ->integer($data, 'units', 1, 10)
            ->in($data, 'region', ['Hhohho','Manzini','Lubombo','Shiselweni'])
            ->string($data, 'town', 2, 120)
            ->date($data, 'next_eligible_date')
            ->in($data, 'screening_status', ['pending','passed','failed'], true)
            ->string($data, 'notes', 0, 2000, true)
            ->validate();

        $db = Database::connection();
        $donorStatement = $db->prepare('SELECT dp.*, u.id AS user_id FROM donor_profiles dp JOIN users u ON u.id = dp.user_id WHERE dp.id = :id');
        $donorStatement->execute(['id' => $id]);
        $donor = $donorStatement->fetch();
        if (!$donor) throw new HttpException(404, 'Donor not found.');
        // Allow recording donations for pending (unverified) donors — the first donation is what enables verification.
        if ($donor['verification_status'] === 'rejected') throw new HttpException(409, 'Cannot record a donation for an explicitly rejected donor profile.');

        // Compute next_eligible_date from days if provided, otherwise fall back to explicit date
        $donationDate = (string) $data['donation_date'];
        if (!empty($data['eligibility_days'])) {
            $days = max(1, (int) $data['eligibility_days']);
            $nextEligibleDate = date('Y-m-d', strtotime($donationDate . " +{$days} days"));
        } else {
            $nextEligibleDate = (string) ($data['next_eligible_date'] ?? '');
        }

        $donationTime = strtotime($donationDate);
        $nextEligibleTime = strtotime($nextEligibleDate);

        if ($nextEligibleTime < $donationTime) {
            throw new HttpException(422, "Next eligible date cannot be before the donation date.", [
                'next_eligible_date' => "Must be on or after the donation date."
            ]);
        }
        // Update donor's stored eligibility_days if different
        if (!empty($data['eligibility_days'])) {
            $updateDays = $db->prepare('UPDATE donor_profiles SET eligibility_days = :days WHERE id = :id');
            $updateDays->execute(['days' => max(1, (int) $data['eligibility_days']), 'id' => $id]);
        }

        $screeningStatus = $data['screening_status'] ?? 'passed';
        $db->beginTransaction();
        try {
            $insert = $db->prepare(
                "INSERT INTO donation_records
                 (donor_id, institution_id, campaign_id, recorded_by, donation_date, donation_type, units, region, town, next_eligible_date, screening_status, notes)
                 VALUES (:donor_id, :institution_id, :campaign_id, :recorded_by, :donation_date, :donation_type, :units, :region, :town, :next_date, :screening_status, :notes)"
            );
            $insert->execute([
                'donor_id' => $id,
                'institution_id' => !empty($data['institution_id']) ? (int) $data['institution_id'] : ($staff['institution_id'] ?? null),
                'campaign_id' => !empty($data['campaign_id']) ? (int) $data['campaign_id'] : null,
                'recorded_by' => $staff['id'], 'donation_date' => $donationDate,
                'donation_type' => $data['donation_type'], 'units' => $data['units'],
                'region' => $data['region'], 'town' => trim((string) $data['town']),
                'next_date' => $nextEligibleDate, 'screening_status' => $screeningStatus,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
            $donationId = (int) $db->lastInsertId();
            if ($screeningStatus === 'passed') {
                $update = $db->prepare(
                    "UPDATE donor_profiles SET last_donation_date = :date, next_eligible_date = :next_date,
                     total_donations = total_donations + 1, eligibility_status = 'temporarily_deferred',
                     availability_status = 'available'
                     WHERE id = :id"
                );
                $update->execute(['date' => $donationDate, 'next_date' => $nextEligibleDate, 'id' => $id]);

                // Record a deferral entry so there is a traceable reason for the deferred status,
                // and so EligibilityService can cleanly close it when the waiting period ends.
                // First close any existing post-donation deferral for this donor (idempotent on re-record).
                $closeOldDeferral = $db->prepare(
                    "UPDATE donor_deferrals SET status = 'completed'
                     WHERE donor_id = :donor_id AND deferral_type = 'temporary'
                       AND reason = 'Recently donated — post-donation waiting period in progress.'
                       AND status = 'active'"
                );
                $closeOldDeferral->execute(['donor_id' => $id]);

                $insertDeferral = $db->prepare(
                    "INSERT INTO donor_deferrals (donor_id, recorded_by, deferral_type, reason, starts_on, ends_on, notes)
                     VALUES (:donor_id, :recorded_by, 'temporary', 'Recently donated — post-donation waiting period in progress.',
                             :starts_on, :ends_on, :notes)"
                );
                $insertDeferral->execute([
                    'donor_id'    => $id,
                    'recorded_by' => $staff['id'],
                    'starts_on'   => $donationDate,
                    'ends_on'     => $nextEligibleDate,
                    'notes'       => "Donation recorded on {$donationDate}. Donor will become eligible again on {$nextEligibleDate}.",
                ]);

                // Clean up pending matches since the donor is now deferred
                $cleanupMatches = $db->prepare("DELETE FROM request_matches WHERE donor_id = :donor_id AND donor_response = 'pending'");
                $cleanupMatches->execute(['donor_id' => $id]);
            }
            if (!empty($data['campaign_id'])) {
                $participant = $db->prepare(
                    "INSERT INTO campaign_participants (campaign_id, donor_id, participation_status, registered_at, attended_at)
                     VALUES (:campaign_id, :donor_id, 'donated', NOW(), NOW())
                     ON DUPLICATE KEY UPDATE participation_status = 'donated', attended_at = NOW()"
                );
                $participant->execute(['campaign_id' => $data['campaign_id'], 'donor_id' => $id]);
            }
            $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:id, 'donation_recorded', :description, :metadata)");
            $activity->execute([
                'id' => $id,
                'description' => 'Donation recorded on ' . $data['donation_date'] . '.',
                'metadata' => json_encode(['donation_id' => $donationId, 'units' => $data['units'], 'next_eligible_date' => $data['next_eligible_date']], JSON_THROW_ON_ERROR),
            ]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        $newTotal = (int) $donor['total_donations'] + ($screeningStatus === 'passed' ? 1 : 0);
        $daysUsed = !empty($data['eligibility_days']) ? (int) $data['eligibility_days'] : (int) ($donor['eligibility_days'] ?? 90);
        (new NotificationService())->create(
            (int) $donor['user_id'], 'thank_you', 'Thank you for donating',
            "Your donation has been recorded. You have now completed {$newTotal} donation" . ($newTotal === 1 ? '' : 's') . ". Your next eligible date is {$nextEligibleDate} (in {$daysUsed} days).",
            '/app/dashboard', null, !empty($data['campaign_id']) ? (int) $data['campaign_id'] : null,
            $donor['preferred_contact_method'] === 'sms'
        );
        if (in_array($newTotal, [1, 5, 10, 20, 50], true)) {
            (new NotificationService())->create(
                (int) $donor['user_id'], 'milestone', 'Donation milestone reached',
                "You have reached {$newTotal} recorded donation" . ($newTotal === 1 ? '' : 's') . ". Thank you for remaining part of Eswatini's active donor pool.",
                '/app/dashboard'
            );
            $milestone = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:id, 'milestone_reached', :description, :metadata)");
            $milestone->execute([
                'id' => $id,
                'description' => "Reached {$newTotal} recorded donation" . ($newTotal === 1 ? '' : 's') . '.',
                'metadata' => json_encode(['total_donations' => $newTotal], JSON_THROW_ON_ERROR),
            ]);
        }
        Audit::log('DONATION_RECORDED', 'Staff recorded a donor donation.', 'donor_profile', $id, ['total_donations' => $donor['total_donations']], ['total_donations' => $newTotal, 'donation_date' => $data['donation_date']], $request);
        Response::success('Donation recorded successfully.', ['total_donations' => $newTotal]);
    }


    public function sendDonorMessages(Request $request): never
    {
        $admin = App::auth()->requireRoles(['admin']);
        $data = $request->json();
        (new Validator())
            ->required($data, ['message_type','title','message'])
            ->in($data, 'message_type', ['retention','impact_update','birthday','donation_reminder','general','we_miss_you'])
            ->string($data, 'title', 3, 200)
            ->string($data, 'message', 5, 1000)
            ->in($data, 'blood_type', ['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown','All'], true)
            ->in($data, 'region', ['Hhohho','Manzini','Lubombo','Shiselweni'], true)
            ->integer($data, 'min_donations', 0, 1000, true)
            ->integer($data, 'recently_donated_days', 1, 365, true)
            ->integer($data, 'idle_days_since_eligible', 1, 365, true)
            ->validate();

        $sendSms = filter_var($data['send_sms'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $birthdayOnly = filter_var($data['birthdays_this_month'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $where = ["u.role = 'donor'", "u.account_status = 'active'", 'dp.consent_to_notifications = 1', "dp.eligibility_status <> 'permanently_deferred'"];
        $params = [];

        if (!empty($data['blood_type']) && $data['blood_type'] !== 'All') {
            $where[] = 'dp.blood_type = :blood_type';
            $params['blood_type'] = $data['blood_type'];
        }
        if (!empty($data['region'])) {
            $where[] = 'dp.region = :region';
            $params['region'] = $data['region'];
        }
        if (isset($data['min_donations']) && $data['min_donations'] !== '') {
            $where[] = 'dp.total_donations >= :min_donations';
            $params['min_donations'] = (int) $data['min_donations'];
        }
        if ($birthdayOnly || $data['message_type'] === 'birthday') {
            $where[] = 'MONTH(dp.date_of_birth) = MONTH(CURDATE())';
        }

        // Target only recent donors for impact updates
        if ($data['message_type'] === 'impact_update') {
            $recentlyDonatedDays = isset($data['recently_donated_days']) && $data['recently_donated_days'] !== '' 
                ? (int) $data['recently_donated_days'] 
                : 30;
            $where[] = 'dp.last_donation_date IS NOT NULL AND dp.last_donation_date >= DATE_SUB(CURDATE(), INTERVAL :recently_donated_days DAY)';
            $params['recently_donated_days'] = $recentlyDonatedDays;
        }

        // Target idle eligible donors for retention/we_miss_you
        if ($data['message_type'] === 'we_miss_you') {
            $idleDays = isset($data['idle_days_since_eligible']) && $data['idle_days_since_eligible'] !== '' 
                ? (int) $data['idle_days_since_eligible'] 
                : 30;
            $where[] = "(dp.eligibility_status = 'eligible' OR (dp.eligibility_status = 'temporarily_deferred' AND dp.next_eligible_date <= CURDATE()))";
            $where[] = 'dp.next_eligible_date IS NOT NULL AND dp.next_eligible_date <= DATE_SUB(CURDATE(), INTERVAL :idle_days_since_eligible DAY)';
            $params['idle_days_since_eligible'] = $idleDays;
        }

        // For donation_reminder and retention messages, never send to donors still within their
        // post-donation waiting period — they recently donated and are not yet eligible.
        if (in_array($data['message_type'], ['donation_reminder', 'retention'], true)) {
            $where[] = "(dp.eligibility_status = 'eligible' OR (dp.eligibility_status = 'temporarily_deferred' AND dp.next_eligible_date IS NOT NULL AND dp.next_eligible_date <= CURDATE()))";
        }

        $db = Database::connection();
        $whereSql = implode(' AND ', $where);
        $count = $db->prepare("SELECT COUNT(*) FROM donor_profiles dp JOIN users u ON u.id = dp.user_id WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        if ($total === 0) {
            throw new HttpException(404, 'No consenting donors matched this message target.');
        }

        $statement = $db->prepare(
            "SELECT dp.id AS donor_id, dp.preferred_contact_method, u.id AS user_id, u.full_name
             FROM donor_profiles dp
             JOIN users u ON u.id = dp.user_id
             WHERE " . $whereSql . "
             ORDER BY dp.total_donations DESC, dp.created_at ASC
             LIMIT 500"
        );
        $statement->execute($params);
        $donors = $statement->fetchAll();

        $notificationService = new NotificationService();
        $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:donor_id, 'notification_sent', :description, :metadata)");
        foreach ($donors as $donor) {
            $notificationService->create(
                (int) $donor['user_id'],
                (string) $data['message_type'],
                trim((string) $data['title']),
                trim((string) $data['message']),
                '/app/notifications',
                null,
                null,
                $sendSms && $donor['preferred_contact_method'] === 'sms'
            );
            $activity->execute([
                'donor_id' => $donor['donor_id'],
                'description' => 'Admin engagement message sent: ' . $data['message_type'] . '.',
                'metadata' => json_encode(['message_type' => $data['message_type'], 'sent_by' => $admin['id'], 'title' => $data['title']], JSON_THROW_ON_ERROR),
            ]);
        }

        Audit::log('DONOR_ENGAGEMENT_MESSAGES_SENT', 'Admin sent donor retention or engagement messages.', 'notification', null, null, ['count' => count($donors), 'type' => $data['message_type']], $request);
        Response::success('Donor messages sent.', ['sent' => count($donors), 'sms_requested' => $sendSms]);
    }

    public function createDeferral(Request $request): never
    {
        $staff = App::auth()->requireRoles(['staff','admin']);
        $id = (int) $request->param('id');
        $data = $request->json();
        $validator = (new Validator())
            ->required($data, ['deferral_type','reason','starts_on'])
            ->in($data, 'deferral_type', ['temporary','permanent'])
            ->string($data, 'reason', 3, 255)
            ->date($data, 'starts_on')
            ->date($data, 'ends_on', true)
            ->integer($data, 'deferral_days', 1, 3650, true)
            ->string($data, 'notes', 0, 2000, true);

        // Compute ends_on from deferral_days if provided
        $endsOn = null;
        if ($data['deferral_type'] === 'temporary') {
            if (!empty($data['deferral_days'])) {
                $days = max(1, (int) $data['deferral_days']);
                $endsOn = date('Y-m-d', strtotime($data['starts_on'] . " +{$days} days"));
            } elseif (!empty($data['ends_on'])) {
                $endsOn = $data['ends_on'];
            } else {
                $validator->add('deferral_days', 'Temporary deferrals need either a duration in days or an end date.');
            }
        }
        $validator->validate();

        $db = Database::connection();
        $donorStatement = $db->prepare('SELECT dp.*, u.id AS user_id FROM donor_profiles dp JOIN users u ON u.id = dp.user_id WHERE dp.id = :id');
        $donorStatement->execute(['id' => $id]);
        $donor = $donorStatement->fetch();
        if (!$donor) throw new HttpException(404, 'Donor not found.');

        $db->beginTransaction();
        try {
            $insert = $db->prepare(
                "INSERT INTO donor_deferrals (donor_id, recorded_by, deferral_type, reason, starts_on, ends_on, notes)
                 VALUES (:donor_id, :recorded_by, :type, :reason, :starts_on, :ends_on, :notes)"
            );
            $insert->execute([
                'donor_id' => $id, 'recorded_by' => $staff['id'], 'type' => $data['deferral_type'],
                'reason' => trim((string) $data['reason']), 'starts_on' => $data['starts_on'],
                'ends_on' => $endsOn, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
            $status = $data['deferral_type'] === 'permanent' ? 'permanently_deferred' : 'temporarily_deferred';
            $update = $db->prepare('UPDATE donor_profiles SET eligibility_status = :status, next_eligible_date = :next_date WHERE id = :id');
            $update->execute(['status' => $status, 'next_date' => $endsOn, 'id' => $id]);

            // Clean up pending matches since the donor is now deferred
            $cleanupMatches = $db->prepare("DELETE FROM request_matches WHERE donor_id = :donor_id AND donor_response = 'pending'");
            $cleanupMatches->execute(['donor_id' => $id]);

            $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:id, 'deferred', :description, :metadata)");
            $activity->execute([
                'id' => $id, 'description' => 'A ' . $data['deferral_type'] . ' deferral was recorded.' . ($endsOn ? " Ends on {$endsOn}." : ''),
                'metadata' => json_encode(['reason' => $data['reason'], 'ends_on' => $endsOn, 'deferral_days' => $data['deferral_days'] ?? null], JSON_THROW_ON_ERROR),
            ]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $deferralMsg = $data['deferral_type'] === 'permanent'
            ? 'A permanent deferral has been recorded on your profile. This means you are no longer eligible to donate. Reason: ' . $data['reason'] . '.'
            : 'A temporary deferral has been recorded. Reason: ' . $data['reason'] . ($endsOn ? ". You will be re-assessed and notified when eligible again on {$endsOn}." : '.');
        (new NotificationService())->create((int) $donor['user_id'], 'eligibility_reminder', 'Donation eligibility updated', $deferralMsg, '/app/profile');
        Audit::log('DONOR_DEFERRED', 'Staff recorded a donor deferral.', 'donor_profile', $id, null, ['type' => $data['deferral_type'], 'ends_on' => $endsOn], $request);
        Response::success('Deferral recorded.', ['ends_on' => $endsOn]);
    }

    public function closeDeferral(Request $request): never
    {
        $staff = App::auth()->requireRoles(['staff','admin']);
        $id = (int) $request->param('id');
        $db = Database::connection();
        $statement = $db->prepare('SELECT dd.*, dp.user_id FROM donor_deferrals dd JOIN donor_profiles dp ON dp.id = dd.donor_id WHERE dd.id = :id');
        $statement->execute(['id' => $id]);
        $deferral = $statement->fetch();
        if (!$deferral) throw new HttpException(404, 'Deferral not found.');
        if ($deferral['status'] !== 'active') throw new HttpException(409, 'This deferral is already closed.');
        if ($deferral['deferral_type'] === 'permanent') throw new HttpException(403, 'Permanent deferrals cannot be closed. This is an irreversible medical decision.');

        $db->beginTransaction();
        try {
            $close = $db->prepare("UPDATE donor_deferrals SET status = 'completed' WHERE id = :id");
            $close->execute(['id' => $id]);
            $remaining = $db->prepare("SELECT COUNT(*) FROM donor_deferrals WHERE donor_id = :donor_id AND status = 'active'");
            $remaining->execute(['donor_id' => $deferral['donor_id']]);
            if ((int) $remaining->fetchColumn() === 0) {
                $update = $db->prepare("UPDATE donor_profiles SET eligibility_status = 'eligible', availability_status = 'available', next_eligible_date = NULL WHERE id = :id AND verification_status = 'verified'");
                $update->execute(['id' => $deferral['donor_id']]);
            }
            $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:id, 'eligibility_restored', 'A donor deferral was closed.', :metadata)");
            $activity->execute(['id' => $deferral['donor_id'], 'metadata' => json_encode(['deferral_id' => $id, 'staff_id' => $staff['id']], JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        (new NotificationService())->create((int) $deferral['user_id'], 'eligibility_reminder', 'Deferral closed', 'Your deferral has been reviewed and closed. Check your profile for your current eligibility status.', '/app/profile');
        Audit::log('DEFERRAL_CLOSED', 'Staff closed a donor deferral.', 'donor_deferral', $id, ['status' => 'active'], ['status' => 'completed'], $request);
        Response::success('Deferral closed.');
    }

    public function appointments(Request $request): never
    {
        $staff = App::auth()->requireRoles(['staff','admin']);
        $db = Database::connection();

        $query = "SELECT ar.*, u.full_name, dp.donor_code, i.name AS institution_name
                  FROM appointment_requests ar
                  JOIN donor_profiles dp ON dp.id = ar.donor_id
                  JOIN users u ON u.id = dp.user_id
                  JOIN institutions i ON i.id = ar.institution_id";

        $params = [];
        if ($staff['role'] === 'staff') {
            $query .= " WHERE ar.institution_id = :institution_id";
            $params['institution_id'] = $staff['institution_id'];
        }

        $query .= " ORDER BY ar.appointment_at DESC LIMIT 100";

        $statement = $db->prepare($query);
        $statement->execute($params);

        Response::success('Appointments loaded.', $statement->fetchAll());
    }

    public function reviewAppointment(Request $request): never
    {
        $staff = App::auth()->requireRoles(['staff','admin']);
        $id = (int) $request->param('id');
        $data = $request->json();

        (new Validator())
            ->required($data, ['status'])
            ->in($data, 'status', ['approved','rejected'])
            ->string($data, 'review_notes', 0, 255, true)
            ->validate();

        $db = Database::connection();
        $stmt = $db->prepare("SELECT ar.*, dp.user_id, u.full_name FROM appointment_requests ar JOIN donor_profiles dp ON dp.id = ar.donor_id JOIN users u ON u.id = dp.user_id WHERE ar.id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $appointment = $stmt->fetch();

        if (!$appointment) throw new HttpException(404, 'Appointment request not found.');

        // Staff can only review appointments for their own institution
        if ($staff['role'] === 'staff' && (int)$appointment['institution_id'] !== (int)$staff['institution_id']) {
            throw new HttpException(403, 'You do not have permission to review appointments for this institution.');
        }

        $update = $db->prepare(
            "UPDATE appointment_requests
             SET status = :status, reviewed_by = :reviewed_by, review_notes = :notes, updated_at = NOW()
             WHERE id = :id"
        );
        $update->execute([
            'status' => $data['status'],
            'reviewed_by' => $staff['id'],
            'notes' => trim((string) ($data['review_notes'] ?? '')) ?: null,
            'id' => $id,
        ]);

        // Create notification for the donor
        $statusLabel = $data['status'] === 'approved' ? 'approved' : 'rejected';
        (new NotificationService())->create(
            (int) $appointment['user_id'],
            'general',
            'Appointment request reviewed',
            "Your appointment request for " . date('Y-m-d H:i', strtotime($appointment['appointment_at'])) . " has been {$statusLabel} by the blood bank.",
            '/app/appointments'
        );

        $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:donor_id, :type, :description, :metadata)");
        $activity->execute([
            'donor_id' => $appointment['donor_id'],
            'type' => 'appointment_' . $data['status'],
            'description' => 'Appointment request was ' . $statusLabel . ' by staff.',
            'metadata' => json_encode(['appointment_id' => $id, 'reviewed_by' => $staff['id']], JSON_THROW_ON_ERROR),
        ]);

        Audit::log('APPOINTMENT_REVIEWED', 'Staff reviewed donor appointment request.', 'appointment_request', $id, ['status' => $appointment['status']], ['status' => $data['status']], $request);

        Response::success("Appointment request {$statusLabel} successfully.");
    }

    public function profileUpdateRequests(Request $request): never
    {
        $staff = App::auth()->requireRoles(['staff','admin']);
        $donorId = (int) $request->param('id');

        $db = Database::connection();
        $requests = $db->prepare(
            "SELECT * FROM profile_update_requests
             WHERE donor_id = :donor_id
             ORDER BY created_at DESC"
        );
        $requests->execute(['donor_id' => $donorId]);

        Response::success('Profile update requests loaded.', $requests->fetchAll());
    }

    public function reviewProfileUpdateRequest(Request $request): never
    {
        $staff = App::auth()->requireRoles(['staff','admin']);
        $id = (int) $request->param('id');
        $data = $request->json();

        (new Validator())
            ->required($data, ['status'])
            ->in($data, 'status', ['approved','rejected'])
            ->string($data, 'review_notes', 0, 255, true)
            ->validate();

        $db = Database::connection();
        $stmt = $db->prepare("SELECT * FROM profile_update_requests WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $pur = $stmt->fetch();

        if (!$pur) throw new HttpException(404, 'Profile update request not found.');
        if ($pur['status'] !== 'pending') throw new HttpException(409, 'This profile update request has already been reviewed.');

        $db->beginTransaction();
        try {
            $updatePur = $db->prepare(
                "UPDATE profile_update_requests
                 SET status = :status, reviewed_by = :reviewed_by, review_notes = :notes, updated_at = NOW()
                 WHERE id = :id"
            );
            $updatePur->execute([
                'status' => $data['status'],
                'reviewed_by' => $staff['id'],
                'notes' => trim((string) ($data['review_notes'] ?? '')) ?: null,
                'id' => $id,
            ]);

            if ($data['status'] === 'approved') {
                // Apply the change
                if ($pur['field'] === 'phone') {
                    $updateUser = $db->prepare("UPDATE users SET phone = :val WHERE id = :id");
                    $updateUser->execute(['val' => $pur['new_value'], 'id' => $pur['user_id']]);
                } elseif ($pur['field'] === 'emergency_contact_name') {
                    $updateProfile = $db->prepare("UPDATE donor_profiles SET emergency_contact_name = :val WHERE id = :id");
                    $updateProfile->execute(['val' => $pur['new_value'], 'id' => $pur['donor_id']]);
                } elseif ($pur['field'] === 'emergency_contact_phone') {
                    $updateProfile = $db->prepare("UPDATE donor_profiles SET emergency_contact_phone = :val WHERE id = :id");
                    $updateProfile->execute(['val' => $pur['new_value'], 'id' => $pur['donor_id']]);
                }
            }

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        // Notify donor
        $statusLabel = $data['status'] === 'approved' ? 'approved' : 'rejected';
        $fieldName = str_replace('_', ' ', $pur['field']);
        (new NotificationService())->create(
            (int) $pur['user_id'],
            'account',
            'Profile update request reviewed',
            "Your request to update your {$fieldName} has been {$statusLabel} by the blood bank.",
            '/app/profile'
        );

        $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:donor_id, :type, :description, :metadata)");
        $activity->execute([
            'donor_id' => $pur['donor_id'],
            'type' => 'profile_update_' . $data['status'],
            'description' => 'Profile update request for ' . $fieldName . ' was ' . $statusLabel . '.',
            'metadata' => json_encode(['request_id' => $id, 'field' => $pur['field'], 'new_value' => $pur['new_value']], JSON_THROW_ON_ERROR),
        ]);

        Audit::log('PROFILE_UPDATE_REQUEST_REVIEWED', 'Staff reviewed profile update request.', 'profile_update_request', $id, ['status' => 'pending'], ['status' => $data['status']], $request);

        Response::success("Profile update request {$statusLabel} successfully.");
    }
}
