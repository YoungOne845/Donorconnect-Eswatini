<?php

declare(strict_types=1);

// Bootstrapping the application first before producing any output
// to prevent PHP header warnings when bootstrap.php modifies headers or starts sessions.
require_once __DIR__ . '/../bootstrap.php';

/**
 * DonorConnect — Daily Maintenance Script
 * ====================================================
 * Combines all daily background operations:
 * 1. Automatic Birthday Messages
 * 2. Restore Eligibility for Expired Deferrals and Post-Donation Waiting Periods
 */

echo "Starting DonorConnect Daily Maintenance...\n";
echo "====================================================\n";

// 1. Run Birthday Messages
require_once __DIR__ . '/run_birthday_messages.php';

// 2. Run Eligibility Restoration
use App\Services\EligibilityService;

echo "\nRunning Expired Deferrals Restoration...\n";
$eligibilityService = new EligibilityService();
$restored = $eligibilityService->refreshExpiredDeferrals();
echo "Restored eligibility for {$restored} donor(s).\n";

echo "====================================================\n";
echo "Daily Maintenance Completed Successfully.\n";
