<?php
/**
 * Migration: Add eligibility_days column to donor_profiles
 *
 * Run this once to update an existing DonorConnect database.
 * Safe to run multiple times — skips if column already exists.
 *
 * Usage: php api/scripts/update_schema_eligibility_days.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;

$db = Database::connection();

// Check if column already exists
$check = $db->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'donor_profiles'
      AND COLUMN_NAME  = 'eligibility_days'
");
$exists = (int) $check->fetchColumn();

if ($exists) {
    echo "[SKIP] eligibility_days column already exists.\n";
} else {
    $db->exec("
        ALTER TABLE donor_profiles
        ADD COLUMN eligibility_days INT UNSIGNED NOT NULL DEFAULT 90
        AFTER next_eligible_date
    ");
    echo "[OK] eligibility_days column added.\n";
}

// Initialize existing donors: 60 days for males, 90 for females/other
$db->exec("
    UPDATE donor_profiles
    SET eligibility_days = CASE
        WHEN gender = 'male' THEN 60
        ELSE 90
    END
    WHERE eligibility_days = 90
");

$updated = $db->query("SELECT ROW_COUNT()")->fetchColumn();
echo "[OK] Initialized eligibility_days for existing donors.\n";
echo "Done.\n";
