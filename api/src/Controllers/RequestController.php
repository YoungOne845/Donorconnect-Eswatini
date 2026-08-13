<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Audit;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\MatchingService;
use App\Services\NotificationService;
use App\Services\SmsService;

final class RequestController
{
    public function index(Request $request): never
    {
        $user = App::auth()->requireRoles(['hospital','staff','admin']);
        $status = trim((string) $request->query('status', ''));
        $urgency = trim((string) $request->query('urgency_level', ''));
        $search = trim((string) $request->query('search', ''));

        $where = ['1=1'];
        $params = [];
        if ($user['role'] === 'hospital') {
            if (!empty($user['institution_id'])) {
                $where[] = '(br.hospital_id = :institution_id OR br.created_by = :created_by)';
                $params['institution_id'] = $user['institution_id'];
                $params['created_by'] = $user['id'];
            } else {
                $where[] = 'br.created_by = :created_by';
                $params['created_by'] = $user['id'];
            }
        }
        if ($status !== '') {
            $where[] = 'br.status = :status';
            $params['status'] = $status;
        }
        if ($urgency !== '') {
            $where[] = 'br.urgency_level = :urgency';
            $params['urgency'] = $urgency;
        }
        if ($search !== '') {
            // PDO named parameters must be unique per statement.
            $where[] = '(br.request_code LIKE :s_code OR br.hospital_name LIKE :s_hospital OR br.town LIKE :s_town)';
            $searchLike = "%{$search}%";
            $params['s_code']     = $searchLike;
            $params['s_hospital'] = $searchLike;
            $params['s_town']     = $searchLike;
        }

        $statement = Database::connection()->prepare(
            "SELECT br.*, u.full_name AS created_by_name,
                    COUNT(rm.id) AS donors_matched,
                    SUM(CASE WHEN rm.notification_status IN ('sent','seen') THEN 1 ELSE 0 END) AS donors_notified,
                    SUM(CASE WHEN rm.donor_response = 'accepted' THEN 1 ELSE 0 END) AS accepted,
                    SUM(CASE WHEN rm.donor_response = 'declined' THEN 1 ELSE 0 END) AS declined
             FROM blood_requests br
             JOIN users u ON u.id = br.created_by
             LEFT JOIN request_matches rm ON rm.request_id = br.id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY br.id
             ORDER BY FIELD(br.status,'active','partially_fulfilled','draft','fulfilled','cancelled','expired'),
                      FIELD(br.urgency_level,'critical','high','medium','low'), br.created_at DESC"
        );
        $statement->execute($params);
        Response::success('Blood requests loaded.', $statement->fetchAll());
    }

    public function create(Request $request): never
    {
        $user = App::auth()->requireRoles(['hospital','admin']);
        $data = $request->json();
        (new Validator())
            ->required($data, ['blood_type_needed','units_required','urgency_level','hospital_name','region','town'])
            ->in($data, 'blood_type_needed', ['A+','A-','B+','B-','AB+','AB-','O+','O-'])
            ->integer($data, 'units_required', 1, 100)
            ->in($data, 'urgency_level', ['low','medium','high','critical'])
            ->string($data, 'hospital_name', 2, 180)
            ->in($data, 'region', ['Hhohho','Manzini','Lubombo','Shiselweni'])
            ->string($data, 'town', 2, 120)
            ->string($data, 'description', 0, 2000, true)
            ->string($data, 'clinical_reference', 0, 100, true)
            ->validate();

        if (!empty($data['needed_by'])) {
            $neededTime = strtotime((string) $data['needed_by']);
            if ($neededTime === false || $neededTime <= time()) {
                throw new HttpException(422, 'Choose a future date and time for needed by.', ['needed_by' => 'Needed by date and time must be in the future.']);
            }
        }

        $sendRealSms = filter_var($data['send_real_sms'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $realSmsPhoneRaw = trim((string) ($data['demo_sms_phone'] ?? ''));
        $realSmsPhone = null;
        if ($sendRealSms) {
            if ($realSmsPhoneRaw === '') {
                throw new HttpException(422, 'Enter the phone number that should receive the emergency SMS.', ['demo_sms_phone' => 'Use a number like 76123456 or +26876123456.']);
            }
            $realSmsPhone = \App\Core\Identity::phone($realSmsPhoneRaw);
            if (!\App\Core\Identity::validEswatiniPhone($realSmsPhone)) {
                throw new HttpException(422, 'Enter a valid Eswatini mobile number for the emergency SMS.', ['demo_sms_phone' => 'Use a number like 76123456 or +26876123456.']);
            }
        }

        $db = Database::connection();
        $code = 'BR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $hospitalId = !empty($data['hospital_id']) ? (int) $data['hospital_id'] : ($user['institution_id'] ?? null);
        $bloodType = $data['blood_type_needed'];
        $unitsRequired = (int) $data['units_required'];
        $realSmsResult = null;

        $db->beginTransaction();
        try {
            $statement = $db->prepare(
                "INSERT INTO blood_requests
                 (request_code, hospital_id, created_by, blood_type_needed, units_required, urgency_level,
                  hospital_name, region, town, needed_by, status, clinical_reference, description)
                 VALUES (:code, :hospital_id, :created_by, :blood_type, :units, :urgency,
                         :hospital_name, :region, :town, :needed_by, 'active', :clinical_reference, :description)"
            );
            $statement->execute([
                'code' => $code,
                'hospital_id' => $hospitalId,
                'created_by' => $user['id'],
                'blood_type' => $bloodType,
                'units' => $unitsRequired,
                'urgency' => $data['urgency_level'],
                'hospital_name' => trim((string) $data['hospital_name']),
                'region' => $data['region'],
                'town' => trim((string) $data['town']),
                'needed_by' => !empty($data['needed_by']) && strtotime((string) $data['needed_by']) !== false ? date('Y-m-d H:i:s', strtotime((string) $data['needed_by'])) : null,
                'clinical_reference' => trim((string) ($data['clinical_reference'] ?? '')) ?: null,
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
            ]);
            $requestId = (int) $db->lastInsertId();

            // 1. Identify supplier blood bank in the region of the request
            $bankQuery = $db->prepare("SELECT id FROM institutions WHERE institution_type = 'blood_service' AND region = :region AND is_active = 1 LIMIT 1");
            $bankQuery->execute(['region' => $data['region']]);
            $bankId = $bankQuery->fetchColumn();
            if (!$bankId) {
                // Fall back to Mbabane Blood Bank (ID 1)
                $bankId = 1;
            }

            // 2. Check available units in that bank's inventory
            $invQuery = $db->prepare("SELECT available_units FROM blood_inventory WHERE institution_id = :bank_id AND blood_type = :blood_type LIMIT 1");
            $invQuery->execute(['bank_id' => (int) $bankId, 'blood_type' => $bloodType]);
            $available = (int) ($invQuery->fetchColumn() ?: 0);

            $unitsDispatched = 0;
            $hasSufficient = false;

            if ($available >= $unitsRequired) {
                $unitsDispatched = $unitsRequired;
                $hasSufficient = true;
            } else {
                $unitsDispatched = $available;
            }

            if ($unitsDispatched > 0) {
                // Create dispatch assignment with status 'in_transit' (departed)
                $dispatchStmt = $db->prepare(
                    "INSERT INTO dispatch_assignments (request_id, assigned_bank_id, assigned_by, blood_type, units_assigned, status, dispatch_notes, in_transit_at, created_at, updated_at)
                     VALUES (:request_id, :bank_id, :assigned_by, :blood_type, :units, 'in_transit', :notes, NOW(), NOW(), NOW())"
                );
                $dispatchStmt->execute([
                    ':request_id' => $requestId,
                    ':bank_id' => (int) $bankId,
                    ':assigned_by' => $user['id'],
                    ':blood_type' => $bloodType,
                    ':units' => $unitsDispatched,
                    ':notes' => $hasSufficient 
                        ? "Automated dispatch: full request of {$unitsRequired} units fulfilled from local stock."
                        : "Automated dispatch: partial fulfilment of {$unitsDispatched} units (out of {$unitsRequired} requested)."
                ]);

                // Deduct from inventory since it's immediately in_transit
                $invDeduct = $db->prepare("UPDATE blood_inventory SET available_units = GREATEST(available_units - :units, 0), last_updated_by = :user_id WHERE institution_id = :bank_id AND blood_type = :blood_type");
                $invDeduct->execute([
                    ':units' => $unitsDispatched,
                    ':user_id' => $user['id'],
                    ':bank_id' => (int) $bankId,
                    ':blood_type' => $bloodType
                ]);
            }

            $db->commit();

            // Presentation-safe real SMS path: send to the demo donor phone immediately when requested.
            // This does not change the core request/inventory workflow. It only uses the existing SmsService.
            if ($sendRealSms && $realSmsPhone !== null) {
                $realSmsMessage = "DonorConnect: There is an emergency blood request at " . trim((string) $data['hospital_name']) . " in " . trim((string) $data['town']) . ". You are a match for the needed blood type. Please confirm availability by logging into the portal or dialing *256# Option 2.";
                $realSmsResult = (new SmsService())->send(null, $realSmsPhone, $realSmsMessage);
            }

            // 3. Trigger replenishment check for the bank if inventory decreased
            if ($unitsDispatched > 0) {
                // Instantiating controller is not possible easily, let's call the check logic directly if we need to.
                // Wait, checkAndTriggerAutoRequest is in ENBTSController, but we can do a replenishment request if needed,
                // or let the update inventory flow handle it when inventory goes down.
                // Let's run a query to check if we need an auto request for replenishment.
                $stmt = $db->prepare("SELECT available_units FROM blood_inventory WHERE institution_id = :bank AND blood_type = :blood_type LIMIT 1");
                $stmt->execute(['bank' => (int) $bankId, 'blood_type' => $bloodType]);
                $invCheck = $stmt->fetch();
                if ($invCheck) {
                    $newAvail = (int) $invCheck['available_units'];
                    $threshold = 5;
                    if ($newAvail <= $threshold) {
                        $checkReq = $db->prepare("SELECT COUNT(*) FROM blood_requests WHERE hospital_id = :bank AND blood_type_needed = :blood_type AND status IN ('active', 'partially_fulfilled')");
                        $checkReq->execute(['bank' => (int) $bankId, 'blood_type' => $bloodType]);
                        $exists = (int) $checkReq->fetchColumn();

                        if ($exists === 0) {
                            $instStmt = $db->prepare("SELECT name, region, town FROM institutions WHERE id = :id LIMIT 1");
                            $instStmt->execute(['id' => (int) $bankId]);
                            $inst = $instStmt->fetch();
                            if ($inst) {
                                $autoCode = 'AUTO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                                $unitsRequiredAuto = max(10, $threshold * 2);
                                $neededBy = date('Y-m-d H:i:s', strtotime('+3 days'));
                                $description = "Automated emergency request generated due to critical low stock in inventory ({$newAvail}/{$threshold} units available).";

                                $insertReq = $db->prepare(
                                    "INSERT INTO blood_requests
                                      (request_code, hospital_id, created_by, blood_type_needed, units_required, urgency_level,
                                       hospital_name, region, town, needed_by, status, description)
                                     VALUES (:code, :hospital_id, :created_by, :blood_type, :units, 'critical',
                                             :hospital_name, :region, :town, :needed_by, 'active', :description)"
                                );
                                $insertReq->execute([
                                    'code' => $autoCode,
                                    'hospital_id' => (int) $bankId,
                                    'created_by' => $user['id'],
                                    'blood_type' => $bloodType,
                                    'units' => $unitsRequiredAuto,
                                    'hospital_name' => $inst['name'],
                                    'region' => $inst['region'],
                                    'town' => $inst['town'],
                                    'needed_by' => $neededBy,
                                    'description' => $description,
                                ]);
                                $autoReqId = (int) $db->lastInsertId();

                                // Notify branch staff and admins of the critical stock level
                                $staffQuery = $db->prepare(
                                    "SELECT id FROM users 
                                     WHERE (role = 'admin' OR (role = 'staff' AND institution_id = :inst_id)) 
                                       AND account_status = 'active'"
                                );
                                $staffQuery->execute(['inst_id' => (int) $bankId]);
                                $staffIds = $staffQuery->fetchAll(\PDO::FETCH_COLUMN);

                                $alertNotificationService = new NotificationService();
                                foreach ($staffIds as $sid) {
                                    $alertNotificationService->create(
                                        (int) $sid,
                                        'general',
                                        'Critical Inventory Alert',
                                        "Critical inventory alert: {$bloodType} is critically low at {$inst['name']} ({$newAvail}/{$threshold} units available). Automated blood request {$autoCode} has been created.",
                                        '/app/inventory',
                                        $autoReqId,
                                        null,
                                        false
                                    );
                                }

                                // Match and notify donors
                                $matchingService = new MatchingService();
                                $matchingService->match($autoReqId, 20);

                                $getMatchesAuto = $db->prepare(
                                    "SELECT rm.id, rm.donor_id, dp.user_id, dp.preferred_contact_method, u.full_name
                                     FROM request_matches rm
                                     JOIN donor_profiles dp ON dp.id = rm.donor_id
                                     JOIN users u ON u.id = dp.user_id
                                     WHERE rm.request_id = :request_id 
                                       AND rm.notification_status IN ('not_sent','failed')
                                       AND dp.eligibility_status = 'eligible'
                                       AND dp.verification_status = 'verified'
                                       AND dp.availability_status = 'available'
                                       AND u.account_status = 'active'
                                     ORDER BY rm.total_match_score DESC LIMIT 20"
                                );
                                $getMatchesAuto->execute(['request_id' => $autoReqId]);
                                $autoRows = $getMatchesAuto->fetchAll();

                                $updateMatchAuto = $db->prepare("UPDATE request_matches SET notification_status = 'sent', notified_at = NOW() WHERE id = :id");
                                $activityAuto = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:donor_id, 'notification_sent', :description, :metadata)");

                                foreach ($autoRows as $match) {
                                    $message = "DonorConnect: There is an emergency blood request at {$inst['name']} in {$inst['town']}. You are a match for the needed blood type. Please confirm availability by logging into the portal or dialing *256# Option 2.";
                                    $alertNotificationService->create(
                                        (int) $match['user_id'],
                                        'blood_request',
                                        'Critical Blood Shortage',
                                        $message,
                                        '/app/dashboard',
                                        $autoReqId,
                                        null,
                                        $match['preferred_contact_method'] === 'sms'
                                    );
                                    $updateMatchAuto->execute(['id' => $match['id']]);
                                    $activityAuto->execute([
                                        'donor_id' => $match['donor_id'],
                                        'description' => "Automated blood request notification sent for {$autoCode}.",
                                        'metadata' => json_encode(['request_id' => $autoReqId], JSON_THROW_ON_ERROR),
                                    ]);
                                }
                            }
                        }
                    }
                }
            }

            // 4. Trigger notifications if stock was insufficient
            if (!$hasSufficient) {
                $matchingService = new MatchingService();
                $matchingService->match($requestId, 20);

                $getMatches = $db->prepare(
                    "SELECT rm.id, rm.donor_id, dp.user_id, dp.preferred_contact_method, u.full_name
                     FROM request_matches rm
                     JOIN donor_profiles dp ON dp.id = rm.donor_id
                     JOIN users u ON u.id = dp.user_id
                     WHERE rm.request_id = :request_id 
                       AND rm.notification_status IN ('not_sent','failed')
                       AND dp.eligibility_status = 'eligible'
                       AND dp.verification_status = 'verified'
                       AND dp.availability_status = 'available'
                       AND u.account_status = 'active'
                     ORDER BY rm.total_match_score DESC LIMIT 20"
                );
                $getMatches->execute(['request_id' => $requestId]);
                $rows = $getMatches->fetchAll();

                $updateMatch = $db->prepare("UPDATE request_matches SET notification_status = 'sent', notified_at = NOW() WHERE id = :id");
                $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:donor_id, 'notification_sent', :description, :metadata)");
                $notificationService = new NotificationService();

                $hospName = trim((string) $data['hospital_name']);
                $hospTown = trim((string) $data['town']);
                foreach ($rows as $match) {
                    $message = "DonorConnect: There is an emergency blood request at {$hospName} in {$hospTown}. You are a match for the needed blood type. Please confirm availability by logging into the portal or dialing *256# Option 2.";
                    $notificationService->create(
                        (int) $match['user_id'],
                        'blood_request',
                        'Blood donation opportunity',
                        $message,
                        '/app/dashboard',
                        $requestId,
                        null,
                        $match['preferred_contact_method'] === 'sms'
                    );
                    $updateMatch->execute(['id' => $match['id']]);
                    $activity->execute([
                        'donor_id' => $match['donor_id'],
                        'description' => "Automated blood request notification sent for {$code}.",
                        'metadata' => json_encode(['request_id' => $requestId], JSON_THROW_ON_ERROR),
                    ]);
                }
            }

            Audit::log('BLOOD_REQUEST_CREATED', 'A blood request was created and auto-processed.', 'blood_request', $requestId, null, ['request_code' => $code, 'blood_type' => $bloodType, 'units' => $unitsRequired, 'real_sms_requested' => $sendRealSms], $request);
            Response::success('Blood request created and processed.', [
                'id' => $requestId,
                'request_code' => $code,
                'auto_dispatched_units' => $unitsDispatched,
                'real_sms' => $realSmsResult,
            ], 201);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function show(Request $request): never
    {
        $user = App::auth()->requireRoles(['hospital','staff','admin']);
        $id = (int) $request->param('id');
        $db = Database::connection();
        $statement = $db->prepare(
            "SELECT br.*, u.full_name AS created_by_name, i.name AS institution_name
             FROM blood_requests br JOIN users u ON u.id = br.created_by
             LEFT JOIN institutions i ON i.id = br.hospital_id WHERE br.id = :id"
        );
        $statement->execute(['id' => $id]);
        $bloodRequest = $statement->fetch();
        if (!$bloodRequest) throw new HttpException(404, 'Blood request not found.');
        $this->assertCanView($user, $bloodRequest);

        $matches = $db->prepare(
            "SELECT rm.*, dp.donor_code, dp.blood_type, dp.region, dp.town, dp.total_donations,
                    dp.eligibility_status, dp.verification_status, dp.availability_status,
                    u.full_name, u.phone, u.email, u.account_status
             FROM request_matches rm
             JOIN donor_profiles dp ON dp.id = rm.donor_id
             JOIN users u ON u.id = dp.user_id
             WHERE rm.request_id = :id ORDER BY rm.total_match_score DESC"
        );
        $matches->execute(['id' => $id]);
        Response::success('Blood request loaded.', ['request' => $bloodRequest, 'matches' => $matches->fetchAll()]);
    }

    public function match(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $id = (int) $request->param('id');
        $data = $request->json();
        $limit = min(200, max(1, (int) ($data['limit'] ?? 50)));
        $matches = (new MatchingService())->match($id, $limit);
        Audit::log('DONORS_MATCHED', 'Suitable donors were ranked for a blood request.', 'blood_request', $id, null, ['count' => count($matches)], $request);
        Response::success('Suitable donors identified and ranked.', ['matches' => $matches, 'count' => count($matches)]);
    }

    public function notify(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $id = (int) $request->param('id');
        $data = $request->json();
        $limit = min(200, max(1, (int) ($data['limit'] ?? 20)));
        $sendSms = filter_var($data['send_sms'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $matchIds = $data['match_ids'] ?? null;
        $db = Database::connection();

        $requestStatement = $db->prepare('SELECT * FROM blood_requests WHERE id = :id');
        $requestStatement->execute(['id' => $id]);
        $bloodRequest = $requestStatement->fetch();
        if (!$bloodRequest) throw new HttpException(404, 'Blood request not found.');
        if (!in_array($bloodRequest['status'], ['active','partially_fulfilled'], true)) throw new HttpException(409, 'Only active requests can notify donors.');

        $isAuto = str_starts_with($bloodRequest['request_code'], 'AUTO-');
        $isCritical = $bloodRequest['urgency_level'] === 'critical';
        if (!$isAuto || !$isCritical) {
            throw new HttpException(403, 'Notifications can only be sent for critical low-inventory requests.');
        }

        $whereMatch = '';
        $params = ['request_id' => $id];
        if (is_array($matchIds) && count($matchIds) > 0) {
            $ids = array_map('intval', $matchIds);
            $whereMatch = ' AND rm.id IN (' . implode(',', $ids) . ')';
        }

        $matches = $db->prepare(
            "SELECT rm.id, rm.donor_id, dp.user_id, dp.preferred_contact_method, u.full_name
             FROM request_matches rm
             JOIN donor_profiles dp ON dp.id = rm.donor_id
             JOIN users u ON u.id = dp.user_id
             WHERE rm.request_id = :request_id 
               AND rm.notification_status IN ('not_sent','failed')
               AND dp.eligibility_status = 'eligible'
               AND dp.verification_status = 'verified'
               AND dp.availability_status = 'available'
               AND u.account_status = 'active'
               {$whereMatch}
             ORDER BY rm.total_match_score DESC LIMIT {$limit}"
        );
        $matches->execute($params);
        $rows = $matches->fetchAll();
        if ($rows === []) throw new HttpException(409, 'There are no unnotified matches. Run matching first or increase the match limit.');

        $notificationService = new NotificationService();
        $update = $db->prepare("UPDATE request_matches SET notification_status = 'sent', notified_at = NOW() WHERE id = :id");
        foreach ($rows as $match) {
            $message = "DonorConnect: There is an emergency blood request at {$bloodRequest['hospital_name']} in {$bloodRequest['town']}. You are a match for the needed blood type. Please confirm availability by logging into the portal or dialing *256# Option 2.";
            $notificationService->create(
                (int) $match['user_id'], 'blood_request', 'Blood donation opportunity', $message,
                '/app/dashboard', $id, null,
                $sendSms && $match['preferred_contact_method'] === 'sms'
            );
            $update->execute(['id' => $match['id']]);
            $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:donor_id, 'notification_sent', :description, :metadata)");
            $activity->execute([
                'donor_id' => $match['donor_id'],
                'description' => 'Blood request notification sent for ' . $bloodRequest['request_code'] . '.',
                'metadata' => json_encode(['request_id' => $id], JSON_THROW_ON_ERROR),
            ]);
        }
        Audit::log('MATCHED_DONORS_NOTIFIED', 'Matched donors were notified.', 'blood_request', $id, null, ['count' => count($rows), 'sms_requested' => $sendSms, 'selective' => !is_null($matchIds)], $request);
        Response::success('Matched donors notified.', ['notified' => count($rows), 'sms_requested' => $sendSms]);
    }

    public function updateStatus(Request $request): never
    {
        $user = App::auth()->requireRoles(['hospital','admin']);
        $id = (int) $request->param('id');
        $data = $request->json();
        (new Validator())
            ->required($data, ['status'])
            ->in($data, 'status', ['draft','active','partially_fulfilled','fulfilled','cancelled','expired'])
            ->integer($data, 'units_fulfilled', 0, 1000, true)
            ->validate();
        $db = Database::connection();
        $statement = $db->prepare('SELECT * FROM blood_requests WHERE id = :id');
        $statement->execute(['id' => $id]);
        $bloodRequest = $statement->fetch();
        if (!$bloodRequest) throw new HttpException(404, 'Blood request not found.');
        $this->assertCanView($user, $bloodRequest);
        $unitsFulfilled = isset($data['units_fulfilled']) ? (int) $data['units_fulfilled'] : (int) $bloodRequest['units_fulfilled'];
        if ($unitsFulfilled > (int) $bloodRequest['units_required']) {
            throw new HttpException(422, 'Fulfilled units cannot exceed required units.', ['units_fulfilled' => 'Enter a value up to ' . $bloodRequest['units_required'] . '.']);
        }
        $update = $db->prepare('UPDATE blood_requests SET status = :status, units_fulfilled = :units WHERE id = :id');
        $update->execute(['status' => $data['status'], 'units' => $unitsFulfilled, 'id' => $id]);
        Audit::log('BLOOD_REQUEST_STATUS_UPDATED', 'Blood request status was updated.', 'blood_request', $id, ['status' => $bloodRequest['status'], 'units_fulfilled' => $bloodRequest['units_fulfilled']], ['status' => $data['status'], 'units_fulfilled' => $unitsFulfilled], $request);
        Response::success('Blood request status updated.');
    }

    private function assertCanView(array $user, array $bloodRequest): void
    {
        if ($user['role'] !== 'hospital') return;
        $owns = (int) $bloodRequest['created_by'] === (int) $user['id'];
        $sameInstitution = !empty($user['institution_id']) && (int) $bloodRequest['hospital_id'] === (int) $user['institution_id'];
        if (!$owns && !$sameInstitution) throw new HttpException(403, 'You cannot access this hospital request.');
    }
}
