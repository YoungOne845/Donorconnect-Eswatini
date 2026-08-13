<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\Identity;
use App\Services\MatchingService;
use App\Services\NotificationService;

$db = Database::connection();
$crypto = App::crypto();

echo "Starting Live Real-Time System Simulation...\n";
echo str_repeat("=", 80) . "\n";

    // 1. Clean up existing transactional tables to ensure a clean slate
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $db->exec("TRUNCATE TABLE dispatch_assignments;");
    $db->exec("TRUNCATE TABLE request_matches;");
    $db->exec("TRUNCATE TABLE notifications;");
    $db->exec("TRUNCATE TABLE blood_requests;");
    $db->exec("DELETE FROM donor_activity_logs WHERE activity_type IN ('notification_sent', 'request_accepted', 'request_declined');");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "1. Cleared previous transactional tables for a clean slate.\n";

    $db->beginTransaction();
    try {

    // 2. Reset and partition donor profiles to realistic states (70% eligible/available, 20% temporarily deferred, 10% unavailable)
    $db->exec("UPDATE donor_profiles SET 
        verification_status = 'verified', 
        consent_to_notifications = 1;");
    $db->exec("UPDATE donor_profiles SET 
        eligibility_status = 'eligible', 
        availability_status = 'available', 
        next_eligible_date = NULL
        WHERE id % 10 < 7;");
    $db->exec("UPDATE donor_profiles SET 
        eligibility_status = 'temporarily_deferred', 
        availability_status = 'available', 
        next_eligible_date = DATE_ADD(CURDATE(), INTERVAL 60 DAY),
        last_donation_date = DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        WHERE id % 10 IN (7, 8);");
    $db->exec("UPDATE donor_profiles SET 
        eligibility_status = 'eligible', 
        availability_status = 'not_available', 
        next_eligible_date = NULL
        WHERE id % 10 = 9;");
    $db->exec("UPDATE users SET account_status = 'active' WHERE role = 'donor';");
    
    // Reset simulation stock values to ensure simulation idempotency
    $db->exec("UPDATE blood_inventory SET available_units = 18 WHERE institution_id = 1 AND blood_type = 'O-';");
    $db->exec("UPDATE blood_inventory SET available_units = 28 WHERE institution_id = 3 AND blood_type = 'O+';");
    $db->exec("UPDATE blood_inventory SET available_units = 30 WHERE institution_id = 2 AND blood_type = 'A+';");
    
    echo "2. Partitioned donor profiles realistically (70% eligible/available, 20% deferred, 10% unavailable) and reset stock levels for idempotency.\n";

    // 3. Ensure hospital users exist for each target hospital
    $hospitals = [
        6 => ['name' => 'Mbabane Government Hospital', 'nid' => '9401011234567', 'phone' => '+26876500001', 'email' => 'mbabane.blooddesk@hospital.org.sz'],
        7 => ['name' => 'Raleigh Fitkin Memorial (RFM) Hospital', 'nid' => '9402021234567', 'phone' => '+26876500002', 'email' => 'rfm.blooddesk@hospital.org.sz'],
        8 => ['name' => 'Good Shepherd Hospital', 'nid' => '9403031234567', 'phone' => '+26876500003', 'email' => 'goodshepherd.blooddesk@hospital.org.sz'],
        9 => ['name' => 'Piggs Peak Government Hospital', 'nid' => '9404041234567', 'phone' => '+26876500004', 'email' => 'piggspeak.blooddesk@hospital.org.sz'],
    ];

    $checkUser = $db->prepare("SELECT id FROM users WHERE email = :email OR phone = :phone LIMIT 1");
    $insertUser = $db->prepare(
        "INSERT INTO users (institution_id, full_name, national_id_encrypted, national_id_hash, national_id_last_four, email, phone, password_hash, role, account_status)
         VALUES (:institution_id, :full_name, :encrypted, :hash, :last_four, :email, :phone, :password_hash, 'hospital', 'active')"
    );

    $hospitalUsers = [4 => 4]; // Nhlangano hospital user ID is 4
    foreach ($hospitals as $instId => $info) {
        $checkUser->execute(['email' => $info['email'], 'phone' => Identity::phone($info['phone'])]);
        $uid = $checkUser->fetchColumn();
        if ($uid) {
            $hospitalUsers[$instId] = (int) $uid;
        } else {
            $nid = Identity::nationalId($info['nid']);
            $phone = Identity::phone($info['phone']);
            $insertUser->execute([
                'institution_id' => $instId,
                'full_name' => $info['name'] . ' Blood Desk',
                'encrypted' => $crypto->encrypt($nid),
                'hash' => $crypto->searchHash($nid),
                'last_four' => substr($nid, -4),
                'email' => $info['email'],
                'phone' => $phone,
                'password_hash' => password_hash('Hospital@2026', PASSWORD_DEFAULT)
            ]);
            $hospitalUsers[$instId] = (int) $db->lastInsertId();
        }
    }
    echo "3. Created/verified hospital desk users for Mbabane, RFM, Good Shepherd, and Piggs Peak.\n";

    // 4. Create requests for Nhlangano Hospital (User ID = 4, Institution ID = 4)
    $nhlanganoRequests = [
        ['type' => 'O+', 'units' => 10, 'urgency' => 'critical', 'ref' => 'REF-9921', 'desc' => 'Multiple trauma patients admitted from highway collision. Urgent replacement.'],
        ['type' => 'B-', 'units' => 5, 'urgency' => 'high', 'ref' => 'REF-8812', 'desc' => 'Scheduled neonatal cardiac surgical backup units.'],
        ['type' => 'A+', 'units' => 8, 'urgency' => 'medium', 'ref' => 'REF-1122', 'desc' => 'General maternity ward reservation for high-risk deliveries.'],
        ['type' => 'O-', 'units' => 6, 'urgency' => 'critical', 'ref' => 'REF-1010', 'desc' => 'Universal donor units required for immediate stabilizing resuscitation in ER.'],
        ['type' => 'AB+', 'units' => 4, 'urgency' => 'low', 'ref' => 'REF-3344', 'desc' => 'Regular therapeutic transfusion for leukemia patient.']
    ];

    $insertReq = $db->prepare(
        "INSERT INTO blood_requests
          (request_code, hospital_id, created_by, blood_type_needed, units_required, units_fulfilled, urgency_level,
           hospital_name, region, town, needed_by, status, clinical_reference, description)
         VALUES (:code, :hospital_id, :created_by, :blood_type, :units, :units_fulfilled, :urgency,
                 :hospital_name, :region, :town, :needed_by, :status, :clinical_reference, :description)"
    );

    $reqIds = [];
    foreach ($nhlanganoRequests as $idx => $r) {
        $code = 'BR-20260615-NH0' . ($idx + 1);
        $status = ($idx === 0) ? 'partially_fulfilled' : 'active';
        $fulfilled = ($idx === 0) ? 6 : 0;
        $insertReq->execute([
            'code' => $code,
            'hospital_id' => 4,
            'created_by' => 4,
            'blood_type' => $r['type'],
            'units' => $r['units'],
            'units_fulfilled' => $fulfilled,
            'urgency' => $r['urgency'],
            'hospital_name' => 'Nhlangano Hospital',
            'region' => 'Shiselweni',
            'town' => 'Nhlangano',
            'needed_by' => date('Y-m-d H:i:s', strtotime('+2 days')),
            'status' => $status,
            'clinical_reference' => $r['ref'],
            'description' => $r['desc']
        ]);
        $reqIds[$code] = (int) $db->lastInsertId();
    }
    echo "4. Created 5 distinct blood requests for Nhlangano Hospital.\n";

    // 5. Create requests for other hospitals
    $otherRequests = [
        ['inst' => 7, 'name' => 'Raleigh Fitkin Memorial (RFM) Hospital', 'reg' => 'Manzini', 'town' => 'Manzini', 'type' => 'A+', 'units' => 12, 'urgency' => 'high', 'ref' => 'RFM-A1', 'desc' => 'Ward inventory matching critical alert level.', 'status' => 'partially_fulfilled', 'fulfilled' => 8],
        ['inst' => 7, 'name' => 'Raleigh Fitkin Memorial (RFM) Hospital', 'reg' => 'Manzini', 'town' => 'Manzini', 'type' => 'O+', 'units' => 15, 'urgency' => 'medium', 'ref' => 'RFM-O1', 'desc' => 'High ICU consumption rate require buffer replacement.', 'status' => 'active', 'fulfilled' => 0],
        ['inst' => 8, 'name' => 'Good Shepherd Hospital', 'reg' => 'Lubombo', 'town' => 'Siteki', 'type' => 'AB-', 'units' => 4, 'urgency' => 'high', 'ref' => 'GSH-AB1', 'desc' => 'Rare blood type emergency matching.', 'status' => 'active', 'fulfilled' => 0],
        ['inst' => 9, 'name' => 'Piggs Peak Government Hospital', 'reg' => 'Hhohho', 'town' => 'Piggs Peak', 'type' => 'O-', 'units' => 15, 'urgency' => 'critical', 'ref' => 'PPH-O2', 'desc' => 'Immediate supply for ER stabilization.', 'status' => 'partially_fulfilled', 'fulfilled' => 14]
    ];

    foreach ($otherRequests as $idx => $r) {
        $code = 'BR-20260615-OTH' . ($idx + 1);
        $insertReq->execute([
            'code' => $code,
            'hospital_id' => $r['inst'],
            'created_by' => $hospitalUsers[$r['inst']],
            'blood_type' => $r['type'],
            'units' => $r['units'],
            'units_fulfilled' => $r['fulfilled'],
            'urgency' => $r['urgency'],
            'hospital_name' => $r['name'],
            'region' => $r['reg'],
            'town' => $r['town'],
            'needed_by' => date('Y-m-d H:i:s', strtotime('+3 days')),
            'status' => $r['status'],
            'clinical_reference' => $r['ref'],
            'description' => $r['desc']
        ]);
        $reqIds[$code] = (int) $db->lastInsertId();
    }
    echo "5. Created requests for other hospitals (RFM Hospital, Good Shepherd, Piggs Peak Hospital).\n";

    // 6. Run donor compatibility matching for all created requests
    $matchingService = new MatchingService();
    foreach ($reqIds as $code => $rid) {
        $matches = $matchingService->match($rid, 15);
        // Simulate sending SMS/Web notifications for matched donors
        $getMatches = $db->prepare("SELECT rm.id, rm.donor_id, dp.user_id, dp.blood_type, dp.preferred_contact_method FROM request_matches rm JOIN donor_profiles dp ON dp.id = rm.donor_id WHERE rm.request_id = :rid");
        $getMatches->execute(['rid' => $rid]);
        $matchesList = $getMatches->fetchAll();
        
        $updateMatch = $db->prepare("UPDATE request_matches SET notification_status = 'sent', notified_at = NOW() WHERE id = :id");
        $notificationService = new NotificationService();
        foreach ($matchesList as $m) {
            $notificationService->create(
                (int) $m['user_id'],
                'blood_request',
                'Blood Request Matching',
                "Urgent blood request code {$code} needs {$m['blood_type']}. Please log in to accept.",
                '/app/dashboard',
                $rid,
                null,
                false
            );
            $updateMatch->execute(['id' => $m['id']]);
        }
    }
    echo "6. Run Matching algorithm for all requests. Populated compatibility scores & triggered web notifications.\n";

    // 7. Seed dispatch assignments to simulate live actions
    $insertDispatch = $db->prepare(
        "INSERT INTO dispatch_assignments (request_id, assigned_bank_id, assigned_by, blood_type, units_assigned, status, dispatch_notes, accepted_at, packed_at, in_transit_at, delivered_at)
         VALUES (:request_id, :assigned_bank_id, :assigned_by, :blood_type, :units_assigned, :status, :dispatch_notes, :accepted_at, :packed_at, :in_transit_at, :delivered_at)"
    );

    // Dispatch 1: Hlathikhulu Blood Bank (3) delivered 6 units O+ to Nhlangano Hospital for Request 1
    $insertDispatch->execute([
        'request_id' => $reqIds['BR-20260615-NH01'],
        'assigned_bank_id' => 3,
        'assigned_by' => 3, // Hlathikhulu Branch Operator
        'blood_type' => 'O+',
        'units_assigned' => 6,
        'status' => 'delivered',
        'dispatch_notes' => 'Rapid ambulance transit completed. Cold chain maintained.',
        'accepted_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
        'packed_at' => date('Y-m-d H:i:s', strtotime('-1.5 hours')),
        'in_transit_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        'delivered_at' => date('Y-m-d H:i:s', strtotime('-10 minutes'))
    ]);

    // Dispatch 2: Mbabane Blood Bank (1) in_transit 4 units O+ to Nhlangano Hospital for Request 1
    $insertDispatch->execute([
        'request_id' => $reqIds['BR-20260615-NH01'],
        'assigned_bank_id' => 1,
        'assigned_by' => 1, // Mbabane Central Admin
        'blood_type' => 'O+',
        'units_assigned' => 4,
        'status' => 'in_transit',
        'dispatch_notes' => 'Dispatched on vehicle #3. Currently on road.',
        'accepted_at' => date('Y-m-d H:i:s', strtotime('-40 minutes')),
        'packed_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
        'in_transit_at' => date('Y-m-d H:i:s', strtotime('-20 minutes')),
        'delivered_at' => null
    ]);

    // Dispatch 3: Manzini Blood Bank (2) packed 5 units B- to Nhlangano Hospital for Request 2
    $insertDispatch->execute([
        'request_id' => $reqIds['BR-20260615-NH02'],
        'assigned_bank_id' => 2,
        'assigned_by' => 2, // Manzini Branch Operator
        'blood_type' => 'B-',
        'units_assigned' => 5,
        'status' => 'packed',
        'dispatch_notes' => 'Packed in cooler bag A. Awaiting dispatch driver.',
        'accepted_at' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
        'packed_at' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
        'in_transit_at' => null,
        'delivered_at' => null
    ]);

    // Dispatch 4: Manzini Blood Bank (2) delivered 8 units A+ to RFM Hospital for Request 6
    $insertDispatch->execute([
        'request_id' => $reqIds['BR-20260615-OTH1'],
        'assigned_bank_id' => 2,
        'assigned_by' => 2,
        'blood_type' => 'A+',
        'units_assigned' => 8,
        'status' => 'delivered',
        'dispatch_notes' => 'Direct delivery by staff operator.',
        'accepted_at' => date('Y-m-d H:i:s', strtotime('-3 hours')),
        'packed_at' => date('Y-m-d H:i:s', strtotime('-2.5 hours')),
        'in_transit_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
        'delivered_at' => date('Y-m-d H:i:s', strtotime('-1.5 hours'))
    ]);

    // Dispatch 5: Mbabane Blood Bank (1) assigned 4 units A+ to RFM Hospital for Request 6
    $insertDispatch->execute([
        'request_id' => $reqIds['BR-20260615-OTH1'],
        'assigned_bank_id' => 1,
        'assigned_by' => 1,
        'blood_type' => 'A+',
        'units_assigned' => 4,
        'status' => 'assigned',
        'dispatch_notes' => 'Scheduled for delivery tomorrow morning.',
        'accepted_at' => null,
        'packed_at' => null,
        'in_transit_at' => null,
        'delivered_at' => null
    ]);

    // Dispatch 6: Mbabane Blood Bank (1) delivered 14 units O- to Piggs Peak Hospital for Request BR-20260615-OTH4
    $insertDispatch->execute([
        'request_id' => $reqIds['BR-20260615-OTH4'],
        'assigned_bank_id' => 1,
        'assigned_by' => 1,
        'blood_type' => 'O-',
        'units_assigned' => 14,
        'status' => 'delivered',
        'dispatch_notes' => 'Bulk supply dispatch delivered to Piggs Peak. Low stock warning threshold reached.',
        'accepted_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        'packed_at' => date('Y-m-d H:i:s', strtotime('-45 minutes')),
        'in_transit_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
        'delivered_at' => date('Y-m-d H:i:s', strtotime('-5 minutes'))
    ]);
    echo "7. Seeded 6 dispatch assignments across different states (Delivered, In Transit, Packed, Assigned).\n";

    // 8. Deduct units from inventory according to delivered dispatches
    // Hlathikhulu Blood Bank (3) O+ units: deduct 6.
    $db->exec("UPDATE blood_inventory SET available_units = CASE WHEN available_units >= 6 THEN available_units - 6 ELSE 0 END WHERE institution_id = 3 AND blood_type = 'O+';");
    // Manzini Blood Bank (2) A+ units: deduct 8.
    $db->exec("UPDATE blood_inventory SET available_units = CASE WHEN available_units >= 8 THEN available_units - 8 ELSE 0 END WHERE institution_id = 2 AND blood_type = 'A+';");
    // Mbabane Blood Bank (1) O- units: deduct 14 (starts at 18, goes to 4).
    $db->exec("UPDATE blood_inventory SET available_units = CASE WHEN available_units >= 14 THEN available_units - 14 ELSE 0 END WHERE institution_id = 1 AND blood_type = 'O-';");
    echo "8. Deducted inventory levels for delivered dispatches.\n";

    // 9. Simulate the Telemetry Alert / Automated Emergency Request Trigger
    // Update Mbabane Blood Bank critical threshold for O- to 5 (available is 4, which is below 5, triggering alerts)
    $db->exec("UPDATE blood_inventory SET critical_threshold = 5 WHERE institution_id = 1 AND blood_type = 'O-';");
    echo "9. Updated Mbabane Blood Bank inventory for O- critical threshold to 5 (available units is 4, below threshold).\n";

    $db->commit();
    echo "Initial database seeding committed successfully.\n";

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error during database initialization: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

// 10. Run the checkAndTriggerAutoRequest using Reflection to simulate real-time telemetry trigger
try {
    echo "Invoking checkAndTriggerAutoRequest using Reflection for Mbabane Blood Bank O-...\n";
    $controller = new \App\Controllers\ENBTSController();
    $method = new \ReflectionMethod($controller, 'checkAndTriggerAutoRequest');
    $method->setAccessible(true);
    
    // Call the method: Mbabane (ID = 1), blood type 'O-', user ID = 1
    $method->invoke($controller, 1, 'O-', 1);
    
    echo "Reflection call completed!\n";

    // Let's query the database to print the resulting AUTO request details
    $req = $db->query("SELECT * FROM blood_requests WHERE request_code LIKE 'AUTO-%' ORDER BY created_at DESC LIMIT 1")->fetch();
    if ($req) {
        echo "SUCCESS: Auto Request Created!\n";
        echo "------------------------------------------------------------\n";
        echo "Request ID:         " . $req['id'] . "\n";
        echo "Request Code:       " . $req['request_code'] . "\n";
        echo "Blood Type Needed:  " . $req['blood_type_needed'] . "\n";
        echo "Units Required:     " . $req['units_required'] . "\n";
        echo "Urgency Level:      " . $req['urgency_level'] . "\n";
        echo "Hospital / Bank:    " . $req['hospital_name'] . "\n";
        echo "Description:        " . $req['description'] . "\n";
        echo "Status:             " . $req['status'] . "\n";
        echo "Created At:         " . $req['created_at'] . "\n";
        echo "------------------------------------------------------------\n";

        // Query how many matches were generated
        $matchCount = $db->prepare("SELECT COUNT(*) FROM request_matches WHERE request_id = :rid");
        $matchCount->execute(['rid' => $req['id']]);
        $count = $matchCount->fetchColumn();
        echo "Matches generated:  " . $count . " compatible donors\n";

        // Query how many notifications were sent
        $notifCount = $db->prepare("SELECT COUNT(*) FROM notifications WHERE request_id = :rid");
        $notifCount->execute(['rid' => $req['id']]);
        $ncount = $notifCount->fetchColumn();
        echo "Notifications sent: " . $ncount . " donors notified\n";
    } else {
        echo "FAILURE: No AUTO requests were created. Check ENBTSController logic.\n";
    }

} catch (\Throwable $e) {
    echo "Error invoking checkAndTriggerAutoRequest: " . $e->getMessage() . "\n";
}

echo str_repeat("=", 80) . "\n";
echo "Simulation Finished Successfully!\n";
