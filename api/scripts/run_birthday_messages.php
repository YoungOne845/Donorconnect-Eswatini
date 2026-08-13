<?php

declare(strict_types=1);

/**
 * DonorConnect — Automatic Birthday Notification Cron
 * ====================================================
 * Sends a personalised birthday message to every active donor whose
 * date_of_birth matches today's month and day.
 *
 * Idempotent: uses system_settings to record a run stamp per date so
 * running this script twice on the same day sends no duplicate messages.
 *
 * Schedule with Windows Task Scheduler:
 *   Action : C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\donorconnect_enbts_rebuild\api\scripts\run_birthday_messages.php
 *   Trigger : Daily at 08:00
 *
 * Or on Linux/XAMPP cron:
 *   0 8 * * * php /var/www/html/donorconnect_enbts_rebuild/api/scripts/run_birthday_messages.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;
use App\Services\NotificationService;

$db        = Database::connection();
$today     = date('Y-m-d');
$stampKey  = "birthday_run_{$today}";

// ── Idempotency guard ─────────────────────────────────────────────────────────
$check = $db->prepare("SELECT `value` FROM system_settings WHERE `key` = :k LIMIT 1");
$check->execute(['k' => $stampKey]);
if ($check->fetchColumn() === 'sent') {
    echo "[{$today}] Birthday messages already sent today. Skipping.\n";
    return;
}

// ── Find donors with a birthday today ─────────────────────────────────────────
$statement = $db->prepare(
    "SELECT dp.id AS donor_id, dp.date_of_birth, dp.preferred_contact_method,
            u.id AS user_id, u.full_name, u.display_name
     FROM donor_profiles dp
     JOIN users u ON u.id = dp.user_id
     WHERE u.account_status = 'active'
       AND u.role            = 'donor'
       AND dp.consent_to_notifications = 1
       AND MONTH(dp.date_of_birth) = MONTH(CURDATE())
       AND DAY(dp.date_of_birth)   = DAY(CURDATE())"
);
$statement->execute();
$donors = $statement->fetchAll();

if (empty($donors)) {
    echo "[{$today}] No donor birthdays today.\n";
} else {
    $service = new NotificationService();
    $sent    = 0;

    foreach ($donors as $donor) {
        $firstName = $donor['display_name'] ?: explode(' ', $donor['full_name'])[0];
        $age       = (int) date_diff(
            date_create($donor['date_of_birth']),
            date_create('today')
        )->y;

        // ── Hardcoded birthday message ─────────────────────────────────────────
        $title   = "Happy Birthday, {$firstName}! 🎂";
        $message = "On behalf of everyone at ENBTS and DonorConnect — Happy Birthday! "
                 . "You are {$age} years old today and your generous spirit makes a real difference. "
                 . "Every donation you make gives someone else the gift of more birthdays. "
                 . "Thank you for being part of our lifesaving community. Keep donating, keep inspiring!";

        $service->create(
            (int) $donor['user_id'],
            'birthday',
            $title,
            $message,
            '/app/dashboard',
            null,
            null,
            $donor['preferred_contact_method'] === 'sms'  // send SMS if donor prefers it
        );
        $sent++;
    }

    echo "[{$today}] Birthday messages sent to {$sent} donor(s).\n";
    foreach ($donors as $d) {
        echo "  → {$d['full_name']} (DOB: {$d['date_of_birth']})\n";
    }
}

// ── Stamp completion so re-runs are skipped ───────────────────────────────────
$db->prepare(
    "INSERT INTO system_settings (`key`, `value`) VALUES (:k, 'sent')
     ON DUPLICATE KEY UPDATE `value` = 'sent', updated_at = NOW()"
)->execute(['k' => $stampKey]);

echo "[{$today}] Done.\n";
