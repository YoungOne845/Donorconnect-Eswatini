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
use App\Services\EligibilityService;
use App\Services\NotificationService;

final class DonorController
{
    public function dashboard(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);

        // Auto-restore eligibility if the post-donation waiting period has elapsed.
        // The dashboard re-queries donor_profiles directly below, so the donor always
        // sees their live, up-to-date eligibility status without waiting for a scheduled job.
        (new EligibilityService())->refreshExpiredDeferrals();

        $db = Database::connection();

        $profileStatement = $db->prepare(
            "SELECT dp.*, u.full_name, u.display_name, u.email, u.phone, u.phone_secondary, u.national_id_last_four, u.password_status,
                    i.name AS recruitment_institution_name
             FROM donor_profiles dp
             JOIN users u ON u.id = dp.user_id
             LEFT JOIN institutions i ON i.id = dp.recruitment_institution_id
             WHERE dp.id = :donor_id"
        );
        $profileStatement->execute(['donor_id' => $user['donor_id']]);
        $profile = $profileStatement->fetch();

        $alertsStatement = $db->prepare(
            "SELECT rm.id AS match_id, rm.total_match_score, rm.donor_response, rm.notification_status,
                    br.id AS request_id, br.request_code, br.blood_type_needed, br.units_required,
                    br.urgency_level, br.hospital_name, br.region, br.town, br.needed_by, br.status
             FROM request_matches rm
             JOIN blood_requests br ON br.id = rm.request_id
             WHERE rm.donor_id = :donor_id AND br.status IN ('active','partially_fulfilled')
             ORDER BY FIELD(br.urgency_level, 'critical','high','medium','low'), rm.created_at DESC
             LIMIT 10"
        );
        $alertsStatement->execute(['donor_id' => $user['donor_id']]);

        $campaignsStatement = $db->prepare(
            "SELECT c.*, cp.participation_status
             FROM campaigns c
             LEFT JOIN campaign_participants cp ON cp.campaign_id = c.id AND cp.donor_id = :donor_id
             WHERE c.status IN ('scheduled','active') AND c.starts_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
               AND (c.target_region IS NULL OR c.target_region = :region)
             ORDER BY c.starts_at ASC LIMIT 6"
        );
        $campaignsStatement->execute(['donor_id' => $user['donor_id'], 'region' => $user['region']]);

        $donations = $db->prepare(
            "SELECT donation_date, donation_type, units, region, town, next_eligible_date, screening_status
             FROM donation_records WHERE donor_id = :donor_id ORDER BY donation_date DESC LIMIT 5"
        );
        $donations->execute(['donor_id' => $user['donor_id']]);

        $appointments = $db->prepare(
            "SELECT ar.*, i.name AS institution_name, i.region AS institution_region, i.town AS institution_town
             FROM appointment_requests ar
             JOIN institutions i ON i.id = ar.institution_id
             WHERE ar.donor_id = :donor_id
             ORDER BY ar.appointment_at DESC LIMIT 6"
        );
        $appointments->execute(['donor_id' => $user['donor_id']]);

        $bloodBanks = $db->query("SELECT id, name, region, town, phone, email FROM institutions WHERE institution_type = 'blood_service' AND is_active = 1 ORDER BY FIELD(name, 'Mbabane Blood Bank','Manzini Blood Bank','Hlathikhulu Blood Bank'), name")->fetchAll();
        $profile['recognition'] = $this->donorStats((int) $profile['total_donations'], $profile['last_donation_date'] ?? null);

        $unread = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
        $unread->execute(['user_id' => $user['id']]);

        Response::success('Donor dashboard loaded.', [
            'profile' => $profile,
            'blood_request_alerts' => $alertsStatement->fetchAll(),
            'upcoming_campaigns' => $campaignsStatement->fetchAll(),
            'recent_donations' => $donations->fetchAll(),
            'appointments' => $appointments->fetchAll(),
            'blood_banks' => $bloodBanks,
            'unread_notifications' => (int) $unread->fetchColumn(),
            'journey' => [
                'registered' => true,
                'verified' => $profile['verification_status'] === 'verified',
                'assessed' => $profile['eligibility_status'] !== 'not_assessed',
                'has_donated' => (int) $profile['total_donations'] > 0,
                'repeat_donor' => (int) $profile['total_donations'] > 1,
            ],
        ]);
    }

    public function profile(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $db = Database::connection();
        $statement = $db->prepare(
            "SELECT dp.*, u.full_name, u.display_name, u.email, u.phone, u.phone_secondary, u.national_id_last_four, u.password_status,
                    i.name AS recruitment_institution_name
             FROM donor_profiles dp JOIN users u ON u.id = dp.user_id
             LEFT JOIN institutions i ON i.id = dp.recruitment_institution_id
             WHERE dp.id = :id"
        );
        $statement->execute(['id' => $user['donor_id']]);
        $profile = $statement->fetch();
        if (!$profile) throw new HttpException(404, 'Donor profile not found.');
        $profile['national_id_masked'] = '********' . $profile['national_id_last_four'];
        $profile['recognition'] = $this->donorStats((int) $profile['total_donations'], $profile['last_donation_date'] ?? null);
        unset($profile['national_id_last_four']);
        Response::success('Donor profile loaded.', $profile);
    }

    public function updateProfile(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $data = $request->json();

        $validator = (new Validator())
            ->required($data, ['region', 'town', 'preferred_contact_method'])
            ->string($data, 'display_name', 2, 80, true)
            ->email($data, 'email', true)
            ->string($data, 'phone_secondary', 8, 20, true)
            ->in($data, 'region', ['Hhohho', 'Manzini', 'Lubombo', 'Shiselweni'])
            ->string($data, 'town', 2, 120)
            ->in($data, 'preferred_contact_method', ['sms', 'phone', 'email', 'web']);

        $phoneSecondary = null;
        if (!empty($data['phone_secondary'])) {
            $phoneSecondary = Identity::phone((string) $data['phone_secondary']);
            if (!Identity::validEswatiniPhone($phoneSecondary)) {
                $validator->add('phone_secondary', 'Enter a valid secondary Eswatini phone number.');
            }
        }
        $validator->validate();

        $db = Database::connection();

        // Determine if the donor is verified — verified donors cannot change their legal name
        $verifiedStatement = $db->prepare('SELECT verification_status FROM donor_profiles WHERE id = :id LIMIT 1');
        $verifiedStatement->execute(['id' => $user['donor_id']]);
        $donorRow = $verifiedStatement->fetch();
        $isVerified = $donorRow && $donorRow['verification_status'] === 'verified';

        // Primary phone and emergency contacts are staff-managed — allow email, display_name, and phone_secondary changes
        $duplicate = $db->prepare('SELECT id FROM users WHERE :email IS NOT NULL AND email = :email AND id <> :id LIMIT 1');
        $email = trim((string) ($data['email'] ?? '')) ?: null;
        $duplicate->execute(['email' => $email, 'id' => $user['id']]);
        if ($duplicate->fetch()) throw new HttpException(409, 'The email is already used by another account.');

        $beforeStatement = $db->prepare('SELECT * FROM donor_profiles WHERE id = :id');
        $beforeStatement->execute(['id' => $user['donor_id']]);
        $before = $beforeStatement->fetch();

        $displayName = trim((string) ($data['display_name'] ?? '')) ?: null;

        $db->beginTransaction();
        try {
            if ($isVerified) {
                // Verified: only update email, display_name, phone_secondary — full_name and primary phone are locked
                $updateUser = $db->prepare('UPDATE users SET email = :email, display_name = :display_name, phone_secondary = :phone_secondary WHERE id = :id');
                $updateUser->execute(['email' => $email, 'display_name' => $displayName, 'phone_secondary' => $phoneSecondary, 'id' => $user['id']]);
            } else {
                // Not yet verified: full_name is still changeable (primary phone locked)
                (new Validator())->required($data, ['full_name'])->string($data, 'full_name', 3, 180)->validate();
                $updateUser = $db->prepare('UPDATE users SET full_name = :full_name, email = :email, display_name = :display_name, phone_secondary = :phone_secondary WHERE id = :id');
                $updateUser->execute(['full_name' => trim((string) $data['full_name']), 'email' => $email, 'display_name' => $displayName, 'phone_secondary' => $phoneSecondary, 'id' => $user['id']]);
            }

            // Emergency contacts are NOT updated here — staff-only via DonorDetailPage
            $updateProfile = $db->prepare(
                "UPDATE donor_profiles SET region = :region, town = :town, address = :address,
                 preferred_contact_method = :contact_method, consent_to_notifications = :consent,
                 profile_completion_score = :completion WHERE id = :id"
            );
            $emergencySet = !empty($before['emergency_contact_name']) && !empty($before['emergency_contact_phone']);
            $completion = $email && !empty($data['address']) && $emergencySet ? 100 : 80;
            $updateProfile->execute([
                'region'         => $data['region'],
                'town'           => trim((string) $data['town']),
                'address'        => trim((string) ($data['address'] ?? '')) ?: null,
                'contact_method' => $data['preferred_contact_method'],
                'consent'        => filter_var($data['consent_to_notifications'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'completion'     => $completion,
                'id'             => $user['donor_id'],
            ]);
            $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description) VALUES (:id, 'profile_updated', 'Donor updated profile information.')");
            $activity->execute(['id' => $user['donor_id']]);
            $db->commit();
            Audit::log('DONOR_PROFILE_UPDATED', 'Donor updated their profile.', 'donor_profile', (int) $user['donor_id'], $before, ['region' => $data['region'], 'town' => $data['town']], $request);
            Response::success('Profile updated successfully.');
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public function updateAvailability(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $data = $request->json();
        (new Validator())->required($data, ['availability_status'])->in($data, 'availability_status', ['available','not_available'])->validate();
        $db = Database::connection();
        $update = $db->prepare('UPDATE donor_profiles SET availability_status = :status WHERE id = :id');
        $update->execute(['status' => $data['availability_status'], 'id' => $user['donor_id']]);
        $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:id, 'availability_updated', :description, :metadata)");
        $activity->execute([
            'id' => $user['donor_id'],
            'description' => 'Availability changed to ' . str_replace('_', ' ', $data['availability_status']) . '.',
            'metadata' => json_encode(['availability_status' => $data['availability_status']], JSON_THROW_ON_ERROR),
        ]);
        Audit::log('DONOR_AVAILABILITY_UPDATED', 'Donor changed availability.', 'donor_profile', (int) $user['donor_id'], null, ['availability_status' => $data['availability_status']], $request);
        Response::success('Availability updated.', ['availability_status' => $data['availability_status']]);
    }


    public function appointments(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $db = Database::connection();
        $appointments = $db->prepare(
            "SELECT ar.*, i.name AS institution_name, i.region AS institution_region, i.town AS institution_town
             FROM appointment_requests ar
             JOIN institutions i ON i.id = ar.institution_id
             WHERE ar.donor_id = :donor_id
             ORDER BY ar.appointment_at DESC LIMIT 20"
        );
        $appointments->execute(['donor_id' => $user['donor_id']]);
        $banks = $db->query("SELECT id, name, region, town, phone, email FROM institutions WHERE institution_type = 'blood_service' AND is_active = 1 ORDER BY FIELD(name, 'Mbabane Blood Bank','Manzini Blood Bank','Hlathikhulu Blood Bank'), name")->fetchAll();
        Response::success('Donor appointments loaded.', ['appointments' => $appointments->fetchAll(), 'blood_banks' => $banks]);
    }

    public function bookAppointment(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $data = $request->json();
        (new Validator())
            ->required($data, ['institution_id','appointment_at'])
            ->integer($data, 'institution_id', 1)
            ->string($data, 'appointment_at', 10, 40)
            ->string($data, 'reason', 0, 1000, true)
            ->validate();

        $appointmentAt = strtotime((string) $data['appointment_at']);
        if ($appointmentAt === false || $appointmentAt <= time()) {
            throw new HttpException(422, 'Choose a future date and time for your appointment.', ['appointment_at' => 'Appointment time must be in the future.']);
        }

        $db = Database::connection();
        $profileStmt = $db->prepare("SELECT next_eligible_date FROM donor_profiles WHERE id = :id LIMIT 1");
        $profileStmt->execute(['id' => $user['donor_id']]);
        $profile = $profileStmt->fetch();
        if ($profile && $profile['next_eligible_date']) {
            $nextEligibleTime = strtotime($profile['next_eligible_date'] . ' 00:00:00');
            if ($appointmentAt < $nextEligibleTime) {
                $formattedEligible = date('Y-m-d', $nextEligibleTime);
                throw new HttpException(422, "You are not eligible to book an appointment until {$formattedEligible}.", [
                    'appointment_at' => "Must be on or after {$formattedEligible}."
                ]);
            }
        }

        $bank = $db->prepare("SELECT id, name FROM institutions WHERE id = :id AND institution_type = 'blood_service' AND is_active = 1 LIMIT 1");
        $bank->execute(['id' => (int) $data['institution_id']]);
        $bankRow = $bank->fetch();
        if (!$bankRow) throw new HttpException(422, 'Select an active ENBTS blood bank.', ['institution_id' => 'Invalid blood bank.']);

        $insert = $db->prepare(
            "INSERT INTO appointment_requests (requested_by, institution_id, donor_id, title, appointment_at, reason, status)
             VALUES (:requested_by, :institution_id, :donor_id, :title, :appointment_at, :reason, 'pending')"
        );
        $insert->execute([
            'requested_by' => $user['id'],
            'institution_id' => (int) $data['institution_id'],
            'donor_id' => $user['donor_id'],
            'title' => 'Donor appointment request',
            'appointment_at' => date('Y-m-d H:i:s', $appointmentAt),
            'reason' => trim((string) ($data['reason'] ?? '')) ?: 'Donor is available to donate at this time.',
        ]);
        $appointmentId = (int) $db->lastInsertId();

        $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:id, 'availability_updated', :description, :metadata)");
        $activity->execute([
            'id' => $user['donor_id'],
            'description' => 'Donor requested a donation appointment at ' . $bankRow['name'] . '.',
            'metadata' => json_encode(['appointment_id' => $appointmentId, 'appointment_at' => date('Y-m-d H:i:s', $appointmentAt)], JSON_THROW_ON_ERROR),
        ]);

        $admins = $db->prepare("SELECT id FROM users WHERE account_status = 'active' AND (role = 'admin' OR (role = 'staff' AND institution_id = :bank_id))");
        $admins->execute(['bank_id' => (int) $data['institution_id']]);
        $notificationService = new NotificationService();
        foreach ($admins->fetchAll() as $admin) {
            $notificationService->create(
                (int) $admin['id'],
                'general',
                'New donor appointment request',
                $user['full_name'] . ' is available to donate at ' . $bankRow['name'] . ' on ' . date('Y-m-d H:i', $appointmentAt) . '.',
                '/app/notifications'
            );
        }

        Audit::log('DONOR_APPOINTMENT_REQUESTED', 'Donor requested a donation appointment.', 'appointment_request', $appointmentId, null, ['bank_id' => (int) $data['institution_id']], $request);
        Response::success('Appointment request submitted.', ['id' => $appointmentId], 201);
    }

    public function activity(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $db   = Database::connection();
        // Exclude internal/operational noise — donors only need to see their
        // meaningful donation lifecycle events.
        $statement = $db->prepare(
            "SELECT id, activity_type, description, metadata, created_at
             FROM donor_activity_logs
             WHERE donor_id = :id
               AND activity_type NOT IN ('login','login_failed','notification_sent','notification_read','availability_updated')
             ORDER BY created_at DESC
             LIMIT 100"
        );
        $statement->execute(['id' => $user['donor_id']]);
        Response::success('Donor activity loaded.', $statement->fetchAll());
    }

    public function respondToRequest(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $matchId = (int) $request->param('matchId');
        $data = $request->json();
        (new Validator())->required($data, ['response'])->in($data, 'response', ['accepted','declined'])->string($data, 'message', 0, 255, true)->validate();
        $db = Database::connection();
        $matchStatement = $db->prepare(
            "SELECT rm.*, br.request_code, br.hospital_name, br.blood_type_needed, br.created_by
             FROM request_matches rm JOIN blood_requests br ON br.id = rm.request_id
             WHERE rm.id = :id AND rm.donor_id = :donor_id LIMIT 1"
        );
        $matchStatement->execute(['id' => $matchId, 'donor_id' => $user['donor_id']]);
        $match = $matchStatement->fetch();
        if (!$match) throw new HttpException(404, 'Blood request alert not found.');
        if ($match['donor_response'] !== 'pending') throw new HttpException(409, 'You have already responded to this request.');

        if ($data['response'] === 'accepted') {
            $activeMatchStmt = $db->prepare(
                "SELECT br.request_code 
                 FROM request_matches rm 
                 JOIN blood_requests br ON br.id = rm.request_id 
                 WHERE rm.donor_id = :donor_id 
                   AND rm.donor_response = 'accepted' 
                   AND br.status IN ('active', 'partially_fulfilled') 
                 LIMIT 1"
            );
            $activeMatchStmt->execute(['donor_id' => $user['donor_id']]);
            $activeMatch = $activeMatchStmt->fetch();
            if ($activeMatch) {
                throw new HttpException(422, 'You have already accepted another active blood request (' . $activeMatch['request_code'] . '). You can only commit to one request at a time.');
            }
        }

        $update = $db->prepare("UPDATE request_matches SET donor_response = :response, response_message = :message, responded_at = NOW(), notification_status = 'seen' WHERE id = :id");
        $update->execute(['response' => $data['response'], 'message' => trim((string) ($data['message'] ?? '')) ?: null, 'id' => $matchId]);

        if ($data['response'] === 'accepted') {
            $updateAvailability = $db->prepare("UPDATE donor_profiles SET availability_status = 'not_available' WHERE id = :id");
            $updateAvailability->execute(['id' => $user['donor_id']]);
        }

        $activityType = $data['response'] === 'accepted' ? 'request_accepted' : 'request_declined';
        $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:donor_id, :type, :description, :metadata)");
        $activity->execute([
            'donor_id' => $user['donor_id'], 'type' => $activityType,
            'description' => ucfirst($data['response']) . ' blood request ' . $match['request_code'] . '.',
            'metadata' => json_encode(['request_id' => $match['request_id'], 'match_id' => $matchId], JSON_THROW_ON_ERROR),
        ]);
        (new NotificationService())->create(
            (int) $match['created_by'], 'blood_request', 'Donor response received',
            $user['full_name'] . ' ' . ($data['response'] === 'accepted' ? 'can donate' : 'is not available') . ' for request ' . $match['request_code'] . '.',
            '/app/requests/' . $match['request_id'], (int) $match['request_id']
        );
        Audit::log('DONOR_REQUEST_RESPONSE', 'Donor responded to a blood request.', 'request_match', $matchId, ['response' => 'pending'], ['response' => $data['response']], $request);
        Response::success('Your response has been recorded.', ['response' => $data['response']]);
    }

    public function verifyPassword(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $data = $request->json();
        (new Validator())
            ->required($data, ['password'])
            ->string($data, 'password', 1, 128)
            ->validate();

        $db = Database::connection();
        $statement = $db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $user['id']]);
        $row = $statement->fetch();

        if (!$row || empty($row['password_hash']) || !password_verify((string) $data['password'], (string) $row['password_hash'])) {
            throw new HttpException(401, 'Incorrect password.');
        }

        Response::success('Password verified successfully.');
    }

    public function requestProfileUpdate(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $data = $request->json();

        $validator = (new Validator())
            ->required($data, ['field', 'new_value'])
            ->in($data, 'field', ['phone', 'emergency_contact_name', 'emergency_contact_phone'])
            ->string($data, 'new_value', 1, 255)
            ->string($data, 'reason', 0, 1000, true);

        $newValue = trim((string) ($data['new_value'] ?? ''));

        if ($data['field'] === 'phone') {
            $newValue = Identity::phone($newValue);
            if (!Identity::validEswatiniPhone($newValue)) {
                $validator->add('new_value', 'Enter a valid Eswatini phone number.');
            }
        } elseif ($data['field'] === 'emergency_contact_phone') {
            $newValue = Identity::phone($newValue);
            if (!Identity::validEswatiniPhone($newValue)) {
                $validator->add('new_value', 'Enter a valid Eswatini phone number for the emergency contact.');
            }
        } elseif ($data['field'] === 'emergency_contact_name') {
            if (strlen($newValue) < 3 || strlen($newValue) > 180) {
                $validator->add('new_value', 'Emergency contact name must be between 3 and 180 characters.');
            }
        }

        $validator->validate();

        $db = Database::connection();

        // Check if there is already a pending request for the same field
        $pendingCheck = $db->prepare('SELECT id FROM profile_update_requests WHERE donor_id = :donor_id AND field = :field AND status = \'pending\' LIMIT 1');
        $pendingCheck->execute(['donor_id' => $user['donor_id'], 'field' => $data['field']]);
        if ($pendingCheck->fetch()) {
            throw new HttpException(409, 'You already have a pending change request for this field.');
        }

        $insert = $db->prepare(
            "INSERT INTO profile_update_requests (donor_id, user_id, field, new_value, reason, status)
             VALUES (:donor_id, :user_id, :field, :new_value, :reason, 'pending')"
        );
        $insert->execute([
            'donor_id' => $user['donor_id'],
            'user_id' => $user['id'],
            'field' => $data['field'],
            'new_value' => $newValue,
            'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
        ]);
        $requestId = (int) $db->lastInsertId();

        // Find nearest/recruitment institution for routing notifications
        $donorStmt = $db->prepare('SELECT recruitment_institution_id FROM donor_profiles WHERE id = :id LIMIT 1');
        $donorStmt->execute(['id' => $user['donor_id']]);
        $donorProfile = $donorStmt->fetch();
        $institutionId = $donorProfile['recruitment_institution_id'] ?? null;

        if ($institutionId) {
            $notifiedUsers = $db->prepare("SELECT id FROM users WHERE account_status = 'active' AND (role = 'admin' OR (role = 'staff' AND institution_id = :institution_id))");
            $notifiedUsers->execute(['institution_id' => $institutionId]);
        } else {
            $notifiedUsers = $db->prepare("SELECT id FROM users WHERE account_status = 'active' AND role = 'admin'");
            $notifiedUsers->execute();
        }

        $notificationService = new NotificationService();
        $fieldName = str_replace('_', ' ', $data['field']);
        foreach ($notifiedUsers->fetchAll() as $recipient) {
            $notificationService->create(
                (int) $recipient['id'],
                'general',
                'New profile update request',
                $user['full_name'] . ' requested to change their ' . $fieldName . '.',
                '/app/donors/' . $user['donor_id']
            );
        }

        $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:donor_id, 'profile_update_requested', :description, :metadata)");
        $activity->execute([
            'donor_id' => $user['donor_id'],
            'description' => 'Requested change of ' . $fieldName . ' to: ' . $newValue,
            'metadata' => json_encode(['request_id' => $requestId, 'field' => $data['field'], 'new_value' => $newValue], JSON_THROW_ON_ERROR),
        ]);

        Response::success('Profile update request submitted successfully.', ['id' => $requestId], 201);
    }

    public function profileUpdateRequests(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $db = Database::connection();
        $requests = $db->prepare(
            "SELECT * FROM profile_update_requests
             WHERE donor_id = :donor_id
             ORDER BY created_at DESC"
        );
        $requests->execute(['donor_id' => $user['donor_id']]);
        Response::success('Profile update requests loaded.', $requests->fetchAll());
    }

    /**
     * Compute donor recognition tier and associated insurance benefits.
     *
     * Tier thresholds (earned by donation count):
     *   Bronze  1 – 3 donations  → Priority access + self-insurance (1 person)
     *   Silver  4 – 6 donations  → Priority access + family insurance for 5 members
     *   Gold    7+   donations   → Priority access + family insurance for 10 members
     *
     * Inactivity demotion (based on days since last donation):
     *   270–364 days → WARNING: tier at risk
     *   365+    days → DEMOTED: effective tier drops by one level
     *                  Gold→Silver | Silver→Bronze | Bronze→Bronze (insurance suspended)
     */
    private function donorStats(int $totalDonations, ?string $lastDonationDate): array
    {
        // ── 1. Compute earned tier from donation count ─────────────────────
        if ($totalDonations >= 7) {
            $earnedLevel     = 'Gold';
            $next            = null;
            $insuranceCover  = 10;
            $priorityNote    = 'As a Gold donor you receive top-priority blood access for yourself and your registered family members when blood is scarce.';
            $benefitSummary  = 'Gold donors unlock emergency blood priority for themselves plus insurance cover for up to 10 family members registered with ENBTS.';
            $familyNote      = 'Your loyalty protects up to 10 family members. Gold donors are placed at the head of the priority queue during any blood shortage.';
        } elseif ($totalDonations >= 4) {
            $earnedLevel     = 'Silver';
            $next            = 7 - $totalDonations;
            $insuranceCover  = 5;
            $priorityNote    = 'As a Silver donor you receive priority blood access for yourself and can register up to 5 family members for blood insurance.';
            $benefitSummary  = 'Silver donors receive emergency priority for themselves plus blood insurance cover for up to 5 family members registered with ENBTS.';
            $familyNote      = 'You are ' . $next . ' donation(s) away from Gold — which extends cover to 10 family members.';
        } elseif ($totalDonations >= 1) {
            $earnedLevel     = 'Bronze';
            $next            = 4 - $totalDonations;
            $insuranceCover  = 1;
            $priorityNote    = 'As a Bronze donor you receive blood priority access for yourself in an emergency. Donate more to unlock family cover.';
            $benefitSummary  = 'Bronze donors receive personal emergency blood priority. Reach Silver (4 donations) to add cover for 5 family members.';
            $familyNote      = 'You are ' . $next . ' donation(s) from Silver — which covers 5 family members.';
        } else {
            $earnedLevel     = 'New donor';
            $next            = 1;
            $insuranceCover  = 0;
            $priorityNote    = 'Complete your first donation to unlock Bronze status and personal blood priority access.';
            $benefitSummary  = 'Make your first donation to become a Bronze donor and earn personal emergency blood priority.';
            $familyNote      = 'Start your journey — 1 donation unlocks Bronze status.';
        }

        // ── 2. Apply inactivity demotion logic ─────────────────────────────
        $daysInactive     = null;
        $tierAtRisk       = false;   // 9–12 months — warning
        $isDemoted        = false;   // 12+ months  — effective demotion
        $effectiveLevel   = $earnedLevel;

        if ($lastDonationDate !== null && $earnedLevel !== 'New donor') {
            $lastTs       = strtotime($lastDonationDate);
            $daysInactive = (int) floor((time() - $lastTs) / 86400);

            if ($daysInactive >= 365) {
                // Demote by one tier; Bronze keeps title but insurance is suspended
                $isDemoted = true;
                $effectiveLevel = match ($earnedLevel) {
                    'Gold'   => 'Silver',
                    'Silver' => 'Bronze',
                    default  => 'Bronze',   // Bronze stays Bronze — but cover is suspended below
                };
                if ($earnedLevel === 'Bronze') {
                    // Bronze cannot drop further in title, but insurance is suspended
                    $insuranceCover = 0;
                    $priorityNote   = 'Your Bronze status is currently inactive. Your insurance cover is suspended until you donate again.';
                    $benefitSummary = 'Reactivate your Bronze benefits by making a donation. A single donation restores your personal blood priority.';
                } else {
                    // Recalculate insurance and notes for the demoted tier
                    if ($effectiveLevel === 'Silver') {
                        $insuranceCover = 5;
                        $priorityNote   = 'Your tier has been downgraded to Silver due to inactivity. Family cover is now limited to 5 members.';
                        $benefitSummary = 'Donate again to restore Gold status and 10-member family cover. Silver cover (5 members) remains active.';
                    } else {
                        $insuranceCover = 1;
                        $priorityNote   = 'Your tier has been downgraded to Bronze due to inactivity. Only personal blood priority remains active.';
                        $benefitSummary = 'Donate again to restore Silver status and 5-member family cover. Your personal emergency priority is still active.';
                    }
                }
            } elseif ($daysInactive >= 270) {
                // Warning zone: 9–12 months inactive
                $tierAtRisk = true;
            }
        }

        $level = $effectiveLevel;

        return [
            'level'                       => $level,
            'earned_level'                => $earnedLevel,
            'total_donations'             => $totalDonations,
            'estimated_lives_impacted'    => $totalDonations * 3,
            'next_level_donations_needed' => $next,
            'insurance_cover'             => $insuranceCover,
            'priority_note'               => $priorityNote,
            'benefit_summary'             => $benefitSummary,
            'family_support_note'         => $familyNote,
            'days_since_last_donation'    => $daysInactive,
            'tier_at_risk'                => $tierAtRisk,
            'is_demoted'                  => $isDemoted,
        ];
    }

}
