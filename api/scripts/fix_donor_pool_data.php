<?php
/**
 * Script to fix the existing donor pool records:
 * 1. Enforces verification rules:
 *    - Donors with >= 1 donation must be set to 'verified'.
 *    - Donors with 0 donations must be set to 'pending' (representing self-registered / unverified).
 * 2. Selects exactly 5 donors to be permanently deferred:
 *    - Sets their eligibility_status = 'permanently_deferred'.
 *    - Resets next_eligible_date = NULL.
 *    - Adds active 'permanent' deferral records for them.
 *
 * Usage: php api/scripts/fix_donor_pool_data.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;

$db = Database::connection();

// 1. Get first admin ID to record the deferrals
$adminStmt = $db->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
$adminId = (int) ($adminStmt->fetchColumn() ?: 1);

$db->beginTransaction();
try {
    // A. Fix Verification Statuses
    // If total_donations = 0, they should be pending (self-registered)
    $db->exec("
        UPDATE donor_profiles 
        SET verification_status = 'pending', eligibility_status = 'not_assessed', next_eligible_date = NULL
        WHERE total_donations = 0 AND verification_status = 'verified'
    ");
    echo "[OK] Updated unverified (0 donations) donors to pending.\n";

    // If total_donations >= 1, they must be verified
    $db->exec("
        UPDATE donor_profiles 
        SET verification_status = 'verified' 
        WHERE total_donations >= 1 AND verification_status = 'pending'
    ");
    echo "[OK] Updated donors with donations to verified status.\n";

    // B. Fix Deferrals / Permanently Defer exactly 5 donors
    // Clean up any existing permanent deferrals to ensure we have exactly 5 control subjects
    $db->exec("
        UPDATE donor_profiles 
        SET eligibility_status = 'eligible' 
        WHERE eligibility_status = 'permanently_deferred'
    ");
    $db->exec("
        DELETE FROM donor_deferrals 
        WHERE deferral_type = 'permanent'
    ");

    // Select 5 verified donors who have donated at least once
    $stmt = $db->query("
        SELECT id FROM donor_profiles 
        WHERE verification_status = 'verified' AND total_donations >= 1
        LIMIT 5
    ");
    $targetDonors = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($targetDonors) < 5) {
        throw new Exception("Not enough verified donors with donations to select 5. Please seed first.");
    }

    $reasons = [
        'Hepatitis B positive history confirmation.',
        'HIV positive confirmation during routine screen.',
        'Chronic cardiovascular medical condition.',
        'Severe recurring anemia or clinical contraindication.',
        'Severe chronic health disorder.'
    ];

    $deferralInsert = $db->prepare("
        INSERT INTO donor_deferrals (donor_id, recorded_by, deferral_type, reason, starts_on, ends_on, status, notes)
        VALUES (:did, :rby, 'permanent', :reason, CURDATE(), NULL, 'active', 'Seeded permanent deferral.')
    ");

    $profileUpdate = $db->prepare("
        UPDATE donor_profiles 
        SET eligibility_status = 'permanently_deferred', next_eligible_date = NULL, availability_status = 'not_available'
        WHERE id = :did
    ");

    foreach ($targetDonors as $index => $did) {
        $reason = $reasons[$index];
        $deferralInsert->execute(['did' => $did, 'rby' => $adminId, 'reason' => $reason]);
        $profileUpdate->execute(['did' => $did]);
        echo "  ✓ Permanently deferred donor ID {$did} (Reason: {$reason})\n";
    }

    $db->commit();
    echo "[OK] Successfully updated donor pool database and seeded 5 permanent deferrals.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "[ERROR] Migration failed: " . $e->getMessage() . "\n";
}
