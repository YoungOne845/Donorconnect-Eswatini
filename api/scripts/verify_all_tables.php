<?php
require __DIR__ . '/../bootstrap.php';
use App\Core\Database;

$db = Database::connection();

// Get all tables referenced in PHP code vs what exists in DB
$existing = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Existing tables: " . implode(', ', $existing) . "\n\n";

// Test every table that controllers reference
$testTables = [
    'profile_update_requests',
    'users', 'donor_profiles', 'donation_records',
    'donor_deferrals', 'donor_activity_logs',
    'eligibility_assessments', 'blood_requests',
    'request_matches', 'blood_inventory',
    'campaigns', 'campaign_participants',
    'institutions', 'notifications',
    'appointment_requests', 'dispatch_assignments',
    'sms_logs', 'audit_logs', 'system_settings',
    'login_otps', 'password_reset_tokens',
    'branch_campaign_requests', 'ussd_requests', 'ussd_logs',
];

echo "Checking all referenced tables:\n";
foreach ($testTables as $table) {
    try {
        $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "  [OK] $table ($count rows)\n";
    } catch (Exception $e) {
        echo "  [MISSING] $table — " . $e->getMessage() . "\n";
    }
}
