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

final class ENBTSController
{
    private const BLOOD_TYPES = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
    private const DISPATCH_STATUSES = ['assigned','accepted','packed','in_transit','delivered','rejected','cancelled'];

    private function centralBankId(): int
    {
        $statement = Database::connection()->prepare("SELECT id FROM institutions WHERE name = 'Mbabane Blood Bank' AND institution_type = 'blood_service' LIMIT 1");
        $statement->execute();
        $id = $statement->fetchColumn();
        if (!$id) throw new HttpException(500, 'Mbabane Blood Bank is missing. Run the ENBTS seed script.');
        return (int) $id;
    }

    private function requireCentralAdmin(): array
    {
        $user = App::auth()->requireRoles(['admin']);
        if ((int) ($user['institution_id'] ?? 0) !== $this->centralBankId()) {
            throw new HttpException(403, 'Only Mbabane Central Admin can perform this national coordination task.');
        }
        return $user;
    }

    public function inventory(Request $request): never
    {
        $user = App::auth()->requireRoles(['staff','admin']);
        $db = Database::connection();
        $where = ['i.institution_type = \'blood_service\''];
        $params = [];
        if ($user['role'] === 'staff') {
            $where[] = 'i.id = :institution_id';
            $params['institution_id'] = $user['institution_id'];
        }
        $statement = $db->prepare(
            "SELECT bi.*, i.name AS institution_name, i.region, i.town,
                    CASE WHEN bi.available_units <= 5 THEN 1 ELSE 0 END AS is_critical
             FROM blood_inventory bi
             JOIN institutions i ON i.id = bi.institution_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY FIELD(i.name, 'Mbabane Blood Bank','Manzini Blood Bank','Hlathikhulu Blood Bank'),
                      FIELD(bi.blood_type,'O-','O+','A-','A+','B-','B+','AB-','AB+')"
        );
        $statement->execute($params);
        Response::success('Blood inventory loaded.', $statement->fetchAll());
    }

    public function updateInventory(Request $request): never
    {
        $user = App::auth()->requireRoles(['staff','admin']);
        $data = $request->json();
        (new Validator())
            ->required($data, ['institution_id','blood_type','available_units'])
            ->integer($data, 'institution_id', 1)
            ->in($data, 'blood_type', self::BLOOD_TYPES)
            ->integer($data, 'available_units', 0, 100000)
            ->integer($data, 'reserved_units', 0, 100000, true)
            ->integer($data, 'expired_units', 0, 100000, true)
            ->validate();

        $institutionId = (int) $data['institution_id'];
        if ($user['role'] === 'staff' && (int) ($user['institution_id'] ?? 0) !== $institutionId) {
            throw new HttpException(403, 'Branch staff may update only their own blood bank inventory.');
        }

        $db = Database::connection();
        $bank = $db->prepare("SELECT id FROM institutions WHERE id = :id AND institution_type = 'blood_service' AND is_active = 1");
        $bank->execute(['id' => $institutionId]);
        if (!$bank->fetch()) throw new HttpException(422, 'Select an active ENBTS blood bank.');

        $statement = $db->prepare(
            "INSERT INTO blood_inventory (institution_id, blood_type, available_units, reserved_units, expired_units, critical_threshold, last_updated_by)
             VALUES (:institution_id, :blood_type, :available_units, :reserved_units, :expired_units, :critical_threshold, :user_id)
             ON DUPLICATE KEY UPDATE available_units = VALUES(available_units), reserved_units = VALUES(reserved_units),
                                     expired_units = VALUES(expired_units), critical_threshold = VALUES(critical_threshold),
                                     last_updated_by = VALUES(last_updated_by)"
        );
        $statement->execute([
            'institution_id' => $institutionId,
            'blood_type' => $data['blood_type'],
            'available_units' => (int) $data['available_units'],
            'reserved_units' => (int) ($data['reserved_units'] ?? 0),
            'expired_units' => (int) ($data['expired_units'] ?? 0),
            'critical_threshold' => 5,
            'user_id' => $user['id'],
        ]);
        Audit::log('INVENTORY_UPDATED', 'Blood inventory was updated.', 'blood_inventory', $institutionId, null, $data, $request);
        $this->checkAndTriggerAutoRequest($institutionId, $data['blood_type'], (int) $user['id']);
        Response::success('Blood inventory updated.');
    }

    public function dispatches(Request $request): never
    {
        $user = App::auth()->requireRoles(['hospital','staff','admin']);
        $db = Database::connection();
        $where = ['1=1'];
        $params = [];
        if ($user['role'] === 'staff') {
            $where[] = '(da.assigned_bank_id = :institution_id OR br.hospital_id = :institution_id)';
            $params['institution_id'] = $user['institution_id'];
        } elseif ($user['role'] === 'hospital') {
            $where[] = '(br.hospital_id = :institution_id OR br.created_by = :user_id)';
            $params['institution_id'] = $user['institution_id'] ?? 0;
            $params['user_id'] = $user['id'];
        }
        $statement = $db->prepare(
            "SELECT da.*, br.request_code, br.hospital_name, br.region AS hospital_region, br.town AS hospital_town,
                    br.urgency_level, br.units_required, br.units_fulfilled,
                    i.name AS assigned_bank_name, u.full_name AS assigned_by_name
             FROM dispatch_assignments da
             JOIN blood_requests br ON br.id = da.request_id
             JOIN institutions i ON i.id = da.assigned_bank_id
             JOIN users u ON u.id = da.assigned_by
             WHERE " . implode(' AND ', $where) . "
             ORDER BY FIELD(da.status,'assigned','accepted','packed','in_transit','delivered','rejected','cancelled'), da.created_at DESC"
        );
        $statement->execute($params);
        Response::success('Dispatch assignments loaded.', $statement->fetchAll());
    }

    public function createDispatch(Request $request): never
    {
        $user = $this->requireCentralAdmin();
        $data = $request->json();
        (new Validator())
            ->required($data, ['request_id','assigned_bank_id','units_assigned'])
            ->integer($data, 'request_id', 1)
            ->integer($data, 'assigned_bank_id', 1)
            ->integer($data, 'units_assigned', 1, 1000)
            ->string($data, 'dispatch_notes', 0, 2000, true)
            ->validate();
        $db = Database::connection();
        $requestRow = $db->prepare("SELECT * FROM blood_requests WHERE id = :id AND status IN ('active','partially_fulfilled')");
        $requestRow->execute(['id' => (int) $data['request_id']]);
        $bloodRequest = $requestRow->fetch();
        if (!$bloodRequest) throw new HttpException(404, 'Active hospital request not found.');

        $inventory = $db->prepare('SELECT available_units FROM blood_inventory WHERE institution_id = :bank AND blood_type = :blood_type');
        $inventory->execute(['bank' => (int) $data['assigned_bank_id'], 'blood_type' => $bloodRequest['blood_type_needed']]);
        $available = (int) ($inventory->fetchColumn() ?: 0);
        if ($available < (int) $data['units_assigned']) {
            throw new HttpException(409, "Selected blood bank has only {$available} available unit(s) of {$bloodRequest['blood_type_needed']}.");
        }

        $statement = $db->prepare(
            "INSERT INTO dispatch_assignments (request_id, assigned_bank_id, assigned_by, blood_type, units_assigned, dispatch_notes)
             VALUES (:request_id, :bank_id, :assigned_by, :blood_type, :units, :notes)"
        );
        $statement->execute([
            'request_id' => (int) $data['request_id'],
            'bank_id' => (int) $data['assigned_bank_id'],
            'assigned_by' => $user['id'],
            'blood_type' => $bloodRequest['blood_type_needed'],
            'units' => (int) $data['units_assigned'],
            'notes' => trim((string) ($data['dispatch_notes'] ?? '')) ?: null,
        ]);
        $id = (int) $db->lastInsertId();
        Audit::log('DISPATCH_ASSIGNED', 'Mbabane Central assigned a blood dispatch.', 'dispatch_assignment', $id, null, $data, $request);
        Response::success('Dispatch assignment created.', ['id' => $id], 201);
    }

    public function updateDispatchStatus(Request $request): never
    {
        $user = App::auth()->requireRoles(['staff','admin']);
        $id = (int) $request->param('id');
        $data = $request->json();
        (new Validator())->required($data, ['status'])->in($data, 'status', self::DISPATCH_STATUSES)->validate();
        $db = Database::connection();
        $statement = $db->prepare('SELECT * FROM dispatch_assignments WHERE id = :id');
        $statement->execute(['id' => $id]);
        $dispatch = $statement->fetch();
        if (!$dispatch) throw new HttpException(404, 'Dispatch assignment not found.');
        if ($user['role'] === 'staff' && (int) $user['institution_id'] !== (int) $dispatch['assigned_bank_id']) {
            throw new HttpException(403, 'This dispatch belongs to another blood bank.');
        }
        $status = (string) $data['status'];
        $oldStatus = (string) $dispatch['status'];
        $timeColumn = in_array($status, ['accepted','packed','in_transit','delivered'], true) ? $status . '_at' : null;
        $db->beginTransaction();
        try {
            $sql = 'UPDATE dispatch_assignments SET status = :status' . ($timeColumn ? ", {$timeColumn} = COALESCE({$timeColumn}, NOW())" : '') . ' WHERE id = :id';
            $update = $db->prepare($sql);
            $update->execute(['status' => $status, 'id' => $id]);

            // 1. Inventory Adjustment (Departed state: 'in_transit' or 'delivered')
            $oldDeparted = in_array($oldStatus, ['in_transit', 'delivered'], true);
            $newDeparted = in_array($status, ['in_transit', 'delivered'], true);

            if ($newDeparted && !$oldDeparted) {
                // Deduct from inventory
                $inv = $db->prepare('UPDATE blood_inventory SET available_units = GREATEST(available_units - :units, 0), last_updated_by = :user_id WHERE institution_id = :bank AND blood_type = :blood_type');
                $inv->execute(['units' => (int) $dispatch['units_assigned'], 'user_id' => $user['id'], 'bank' => (int) $dispatch['assigned_bank_id'], 'blood_type' => $dispatch['blood_type']]);
            } elseif (!$newDeparted && $oldDeparted) {
                // Restore to inventory
                $inv = $db->prepare('UPDATE blood_inventory SET available_units = available_units + :units, last_updated_by = :user_id WHERE institution_id = :bank AND blood_type = :blood_type');
                $inv->execute(['units' => (int) $dispatch['units_assigned'], 'user_id' => $user['id'], 'bank' => (int) $dispatch['assigned_bank_id'], 'blood_type' => $dispatch['blood_type']]);
            }

            // 2. Request Fulfillment Adjustment (Delivered state: 'delivered')
            $oldDelivered = $oldStatus === 'delivered';
            $newDelivered = $status === 'delivered';

            if ($newDelivered && !$oldDelivered) {
                // Add to request fulfillment
                $br = $db->prepare("UPDATE blood_requests SET units_fulfilled = LEAST(units_required, units_fulfilled + :units), status = CASE WHEN units_fulfilled + :units2 >= units_required THEN 'fulfilled' ELSE 'partially_fulfilled' END WHERE id = :id");
                $br->execute(['units' => (int) $dispatch['units_assigned'], 'units2' => (int) $dispatch['units_assigned'], 'id' => (int) $dispatch['request_id']]);
            } elseif (!$newDelivered && $oldDelivered) {
                // Deduct from request fulfillment
                $br = $db->prepare("UPDATE blood_requests SET units_fulfilled = GREATEST(units_fulfilled - :units, 0), status = CASE WHEN units_fulfilled - :units2 <= 0 THEN 'active' ELSE 'partially_fulfilled' END WHERE id = :id");
                $br->execute(['units' => (int) $dispatch['units_assigned'], 'units2' => (int) $dispatch['units_assigned'], 'id' => (int) $dispatch['request_id']]);
            }

            $db->commit();

            // 3. Trigger replenishment check if inventory decreased
            if ($newDeparted && !$oldDeparted) {
                $this->checkAndTriggerAutoRequest((int) $dispatch['assigned_bank_id'], $dispatch['blood_type'], (int) $user['id']);
            }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        Audit::log('DISPATCH_STATUS_UPDATED', 'Dispatch status was updated.', 'dispatch_assignment', $id, ['status' => $dispatch['status']], ['status' => $status], $request);
        Response::success('Dispatch status updated.');
    }

    public function campaignRequests(Request $request): never
    {
        $user = App::auth()->requireRoles(['staff','admin']);
        $where = ['1=1'];
        $params = [];
        if ($user['role'] === 'staff') {
            $where[] = 'bcr.institution_id = :institution_id';
            $params['institution_id'] = $user['institution_id'];
        }
        $statement = Database::connection()->prepare(
            "SELECT bcr.*, i.name AS institution_name, u.full_name AS requested_by_name, reviewer.full_name AS reviewed_by_name
             FROM branch_campaign_requests bcr
             JOIN institutions i ON i.id = bcr.institution_id
             JOIN users u ON u.id = bcr.requested_by
             LEFT JOIN users reviewer ON reviewer.id = bcr.reviewed_by
             WHERE " . implode(' AND ', $where) . " ORDER BY bcr.created_at DESC"
        );
        $statement->execute($params);
        Response::success('Branch campaign requests loaded.', $statement->fetchAll());
    }

    public function createCampaignRequest(Request $request): never
    {
        $user = App::auth()->requireRoles(['staff']);
        $data = $request->json();
        (new Validator())
            ->required($data, ['title','description','campaign_type','venue','starts_at'])
            ->string($data, 'title', 3, 200)
            ->string($data, 'description', 10, 5000)
            ->in($data, 'campaign_type', ['recruitment','donation_drive','awareness','retention','emergency'])
            ->in($data, 'target_region', ['Hhohho','Manzini','Lubombo','Shiselweni'], true)
            ->string($data, 'target_town', 0, 120, true)
            ->in($data, 'target_blood_type', ['A+','A-','B+','B-','AB+','AB-','O+','O-','All'], true)
            ->string($data, 'venue', 2, 200)
            ->integer($data, 'capacity', 1, 100000, true)
            ->validate();
        $statement = Database::connection()->prepare(
            "INSERT INTO branch_campaign_requests (requested_by, institution_id, title, description, campaign_type, target_region, target_town, target_blood_type, venue, starts_at, capacity)
             VALUES (:requested_by, :institution_id, :title, :description, :campaign_type, :target_region, :target_town, :target_blood_type, :venue, :starts_at, :capacity)"
        );
        $statement->execute([
            'requested_by' => $user['id'],
            'institution_id' => $user['institution_id'],
            'title' => trim((string) $data['title']),
            'description' => trim((string) $data['description']),
            'campaign_type' => $data['campaign_type'],
            'target_region' => $data['target_region'] ?? null,
            'target_town' => trim((string) ($data['target_town'] ?? '')) ?: null,
            'target_blood_type' => $data['target_blood_type'] ?? 'All',
            'venue' => trim((string) $data['venue']),
            'starts_at' => date('Y-m-d H:i:s', strtotime((string) $data['starts_at'])),
            'capacity' => !empty($data['capacity']) ? (int) $data['capacity'] : null,
        ]);
        Response::success('Campaign request sent to Mbabane Central.', ['id' => (int) Database::connection()->lastInsertId()], 201);
    }

    /**
     * Hospital patient lookup by national ID.
     *
     * Allows hospital staff to identify an unconscious / non-communicating patient
     * as a registered donor and see their blood type + priority tier instantly.
     *
     * High priority is automatically set when a patient is a Gold-tier donor
     * (7+ donations) — their proven commitment to the blood supply earns them
     * front-of-queue access when they need blood themselves.
     */
    public function patientLookup(Request $request): never
    {
        App::auth()->requireRoles(['hospital', 'staff', 'admin']);

        $raw = trim((string) $request->query('national_id', ''));
        if ($raw === '') {
            throw new HttpException(422, 'Provide the patient\'s national ID number.', ['national_id' => 'Required.']);
        }

        // Normalise and hash — we never store the plain ID
        $crypto    = App::crypto();
        $digits    = preg_replace('/\D+/', '', $raw) ?? '';
        $hash      = $crypto->searchHash($digits);
        $lastFour  = strlen($digits) >= 4 ? substr($digits, -4) : $digits;

        $db        = Database::connection();
        $statement = $db->prepare(
            "SELECT u.id AS user_id, u.full_name, u.national_id_last_four,
                    dp.id AS donor_id, dp.donor_code, dp.blood_type,
                    dp.blood_type_verified_at, dp.total_donations,
                    dp.eligibility_status, dp.verification_status,
                    dp.region, dp.town,
                    dp.emergency_contact_name, dp.emergency_contact_phone
             FROM users u
             JOIN donor_profiles dp ON dp.user_id = u.id
             WHERE u.national_id_hash = :hash
               AND u.account_status   = 'active'
             LIMIT 1"
        );
        $statement->execute(['hash' => $hash]);
        $donor = $statement->fetch();

        if (!$donor) {
            // Partial match by last-four as a convenience hint (no sensitive data exposed)
            Response::success('No registered donor found for this national ID.', [
                'found'            => false,
                'national_id_hint' => "****{$lastFour}",
                'message'          => 'This patient is not a registered DonorConnect donor. Proceed with standard blood-type screening.',
            ]);
        }

        // Compute tier and high-priority flag
        $donations    = (int) $donor['total_donations'];
        $highPriority = $donations >= 7;      // Gold-tier donors are high priority
        $tier         = match (true) {
            $donations >= 7 => 'Gold',
            $donations >= 4 => 'Silver',
            $donations >= 1 => 'Bronze',
            default         => 'New donor',
        };

        $bloodTypeConfirmed = !empty($donor['blood_type_verified_at']) && $donor['blood_type'] !== 'Unknown';

        Audit::log(
            'HOSPITAL_PATIENT_LOOKUP',
            'Hospital looked up a patient by national ID.',
            'user',
            (int) $donor['user_id'],
            null,
            ['last_four' => $lastFour, 'high_priority' => $highPriority],
            $request
        );

        Response::success('Patient record found.', [
            'found'                  => true,
            'donor_code'             => $donor['donor_code'],
            'blood_type'             => $donor['blood_type'],
            'blood_type_confirmed'   => $bloodTypeConfirmed,
            'tier'                   => $tier,
            'total_donations'        => $donations,
            'high_priority'          => $highPriority,
            'priority_reason'        => $highPriority
                ? 'Gold-tier donor (7+ lifetime donations). Flag for priority blood access.'
                : ($donations > 0
                    ? "Active {$tier} donor with {$donations} recorded donation(s)."
                    : 'Registered donor — no donations recorded yet.'),
            'eligibility_status'     => $donor['eligibility_status'],
            'verification_status'    => $donor['verification_status'],
            'region'                 => $donor['region'],
            'town'                   => $donor['town'],
            'emergency_contact'      => $donor['emergency_contact_name']
                ? ['name' => $donor['emergency_contact_name'], 'phone' => $donor['emergency_contact_phone']]
                : null,
        ]);
    }

    public function reviewCampaignRequest(Request $request): never
    {
        $user = $this->requireCentralAdmin();
        $id = (int) $request->param('id');
        $data = $request->json();
        (new Validator())->required($data, ['status'])->in($data, 'status', ['approved','rejected'])->string($data, 'review_notes', 0, 2000, true)->validate();
        $db = Database::connection();
        $rowStatement = $db->prepare('SELECT * FROM branch_campaign_requests WHERE id = :id');
        $rowStatement->execute(['id' => $id]);
        $row = $rowStatement->fetch();
        if (!$row) throw new HttpException(404, 'Campaign request not found.');
        $campaignId = null;
        $newStatus = $data['status'];
        if ($newStatus === 'approved') {
            $insert = $db->prepare(
                "INSERT INTO campaigns (institution_id, created_by, title, description, campaign_type, target_region, target_town, target_blood_type, venue, starts_at, capacity, status)
                 VALUES (:institution_id, :created_by, :title, :description, :campaign_type, :target_region, :target_town, :target_blood_type, :venue, :starts_at, :capacity, 'scheduled')"
            );
            $insert->execute([
                'institution_id' => $row['institution_id'], 'created_by' => $user['id'], 'title' => $row['title'], 'description' => $row['description'],
                'campaign_type' => $row['campaign_type'], 'target_region' => $row['target_region'], 'target_town' => $row['target_town'],
                'target_blood_type' => $row['target_blood_type'], 'venue' => $row['venue'], 'starts_at' => $row['starts_at'], 'capacity' => $row['capacity'],
            ]);
            $campaignId = (int) $db->lastInsertId();
            $newStatus = 'converted';
        }
        $update = $db->prepare('UPDATE branch_campaign_requests SET status = :status, reviewed_by = :reviewed_by, review_notes = :notes, campaign_id = :campaign_id WHERE id = :id');
        $update->execute(['status' => $newStatus, 'reviewed_by' => $user['id'], 'notes' => trim((string) ($data['review_notes'] ?? '')) ?: null, 'campaign_id' => $campaignId, 'id' => $id]);
        Response::success($campaignId ? 'Campaign request approved and converted into a scheduled campaign.' : 'Campaign request reviewed.', ['campaign_id' => $campaignId]);
    }

    private function checkAndTriggerAutoRequest(int $institutionId, string $bloodType, int $userId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT available_units FROM blood_inventory WHERE institution_id = :bank AND blood_type = :blood_type LIMIT 1");
        $stmt->execute(['bank' => $institutionId, 'blood_type' => $bloodType]);
        $inventory = $stmt->fetch();
        if (!$inventory) {
            return;
        }

        $available = (int) $inventory['available_units'];
        $threshold = 5; // Universal critical threshold for all blood types

        if ($available <= $threshold) {
            // Check if there is already an active or partially fulfilled request for this bank and blood type
            $checkReq = $db->prepare("SELECT COUNT(*) FROM blood_requests WHERE hospital_id = :bank AND blood_type_needed = :blood_type AND status IN ('active', 'partially_fulfilled')");
            $checkReq->execute(['bank' => $institutionId, 'blood_type' => $bloodType]);
            $exists = (int) $checkReq->fetchColumn();

            if ($exists === 0) {
                // Fetch institution details
                $instStmt = $db->prepare("SELECT name, region, town FROM institutions WHERE id = :id LIMIT 1");
                $instStmt->execute(['id' => $institutionId]);
                $inst = $instStmt->fetch();
                if (!$inst) return;

                $code = 'AUTO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $unitsRequired = max(10, $threshold * 2);
                $neededBy = date('Y-m-d H:i:s', strtotime('+3 days'));
                $description = "Automated emergency request generated due to critical low stock in inventory ({$available}/{$threshold} units available).";

                $insertReq = $db->prepare(
                    "INSERT INTO blood_requests
                      (request_code, hospital_id, created_by, blood_type_needed, units_required, urgency_level,
                       hospital_name, region, town, needed_by, status, description)
                     VALUES (:code, :hospital_id, :created_by, :blood_type, :units, 'critical',
                             :hospital_name, :region, :town, :needed_by, 'active', :description)"
                );
                $insertReq->execute([
                    'code' => $code,
                    'hospital_id' => $institutionId,
                    'created_by' => $userId,
                    'blood_type' => $bloodType,
                    'units' => $unitsRequired,
                    'hospital_name' => $inst['name'],
                    'region' => $inst['region'],
                    'town' => $inst['town'],
                    'needed_by' => $neededBy,
                    'description' => $description,
                ]);
                $requestId = (int) $db->lastInsertId();

                // Notify branch staff and admins of the critical stock level
                $staffQuery = $db->prepare(
                    "SELECT id FROM users 
                     WHERE (role = 'admin' OR (role = 'staff' AND institution_id = :inst_id)) 
                       AND account_status = 'active'"
                );
                $staffQuery->execute(['inst_id' => $institutionId]);
                $staffIds = $staffQuery->fetchAll(\PDO::FETCH_COLUMN);

                $alertNotificationService = new NotificationService();
                foreach ($staffIds as $sid) {
                    $alertNotificationService->create(
                        (int) $sid,
                        'general',
                        'Critical Inventory Alert',
                        "Critical inventory alert: {$bloodType} is critically low at {$inst['name']} ({$available}/{$threshold} units available). Automated blood request {$code} has been created.",
                        '/app/inventory',
                        $requestId,
                        null,
                        false
                    );
                }

                // Match donors
                $matchingService = new MatchingService();
                $matchingService->match($requestId, 20);

                // Notify donors
                $notificationService = new NotificationService();
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

                foreach ($rows as $match) {
                    $message = "Automated blood request: {$bloodType} is critically low at {$inst['name']} in {$inst['town']}. Please log in to respond.";
                    $notificationService->create(
                        (int) $match['user_id'],
                        'blood_request',
                        'Critical Blood Shortage',
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

                Audit::log('AUTO_BLOOD_REQUEST_CREATED', 'Automated emergency blood request created and matched donors notified.', 'blood_request', $requestId, null, ['request_code' => $code, 'blood_type' => $bloodType, 'units' => $unitsRequired]);
            }
        }
    }
}
