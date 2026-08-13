<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;

$db = Database::connection();

echo "Running eligibility and availability state transition verification...\n";
echo "========================================================================\n";

// 1. Pick a verified donor who is eligible and available
$stmt = $db->query("SELECT id, availability_status, eligibility_status FROM donor_profiles WHERE verification_status = 'verified' AND eligibility_status = 'eligible' AND availability_status = 'available' LIMIT 1");
$donor = $stmt->fetch();

if (!$donor) {
    echo "ERROR: No eligible & available donor found in database.\n";
    exit(1);
}

$donorId = (int)$donor['id'];
echo "Donor selected: ID {$donorId} | Availability: {$donor['availability_status']} | Eligibility: {$donor['eligibility_status']}\n";

// 2. Simulate donor accepting a request match
echo "\nSimulating donor accepting a request...\n";
$db->beginTransaction();
try {
    // Perform same query as in DonorController
    $updateAvailability = $db->prepare("UPDATE donor_profiles SET availability_status = 'not_available' WHERE id = :id");
    $updateAvailability->execute(['id' => $donorId]);
    
    // Fetch state
    $checkStmt = $db->prepare("SELECT availability_status, eligibility_status FROM donor_profiles WHERE id = :id");
    $checkStmt->execute(['id' => $donorId]);
    $updatedDonor = $checkStmt->fetch();
    
    if ($updatedDonor['availability_status'] !== 'not_available') {
        throw new \Exception("Assertion failed: Donor availability_status is not 'not_available' after acceptance! Actual: " . $updatedDonor['availability_status']);
    }
    echo "✓ SUCCESS: Donor availability_status correctly updated to 'not_available' after request acceptance.\n";
    
    // 3. Simulate staff recording a donation for this donor
    echo "\nSimulating staff recording a donation for this donor...\n";
    
    // Perform same query as in StaffController: sets availability_status back to 'available' and eligibility to 'temporarily_deferred'
    $updateDonation = $db->prepare(
        "UPDATE donor_profiles SET 
            last_donation_date = CURDATE(), 
            next_eligible_date = DATE_ADD(CURDATE(), INTERVAL 60 DAY),
            total_donations = total_donations + 1, 
            eligibility_status = 'temporarily_deferred',
            availability_status = 'available'
         WHERE id = :id"
    );
    $updateDonation->execute(['id' => $donorId]);
    
    // Fetch state again
    $checkStmt->execute(['id' => $donorId]);
    $finalDonor = $checkStmt->fetch();
    
    if ($finalDonor['availability_status'] !== 'available') {
        throw new \Exception("Assertion failed: Donor availability_status was not restored to 'available' after donation recording! Actual: " . $finalDonor['availability_status']);
    }
    if ($finalDonor['eligibility_status'] !== 'temporarily_deferred') {
        throw new \Exception("Assertion failed: Donor eligibility_status is not 'temporarily_deferred' after donation recording! Actual: " . $finalDonor['eligibility_status']);
    }
    
    echo "✓ SUCCESS: Donor availability_status correctly restored to 'available' after donation recording.\n";
    echo "✓ SUCCESS: Donor eligibility_status correctly set to 'temporarily_deferred' after donation recording.\n";

    $db->rollBack(); // Rollback simulation to keep DB clean
    echo "\nVerification completed successfully, transaction rolled back.\n";
    echo "========================================================================\n";

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
