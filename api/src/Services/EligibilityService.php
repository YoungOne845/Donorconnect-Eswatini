<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class EligibilityService
{
    public function refreshExpiredDeferrals(): int
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            // 1. Find donors whose temporary deferrals in the deferrals table have expired
            $deferralDonors = $db->query(
                "SELECT DISTINCT donor_id FROM donor_deferrals
                 WHERE status = 'active' AND deferral_type = 'temporary' AND ends_on IS NOT NULL AND ends_on <= CURDATE()"
            )->fetchAll(\PDO::FETCH_COLUMN);

            // 2. Find donors whose post-donation waiting periods have expired (with no active deferrals in table)
            $postDonationDonors = $db->query(
                "SELECT id FROM donor_profiles
                 WHERE eligibility_status = 'temporarily_deferred'
                   AND next_eligible_date IS NOT NULL
                   AND next_eligible_date <= CURDATE()
                   AND id NOT IN (
                       SELECT donor_id FROM donor_deferrals WHERE status = 'active'
                   )"
            )->fetchAll(\PDO::FETCH_COLUMN);

            $allDonorIds = array_unique(array_merge($deferralDonors, $postDonationDonors));

            // Complete expired deferrals in the deferrals table
            $completeDeferral = $db->prepare(
                "UPDATE donor_deferrals SET status = 'completed'
                 WHERE donor_id = :donor_id AND status = 'active'
                   AND deferral_type = 'temporary' AND ends_on IS NOT NULL AND ends_on <= CURDATE()"
            );

            // Restore eligibility status in donor_profiles
            $restoreEligibility = $db->prepare(
                "UPDATE donor_profiles SET eligibility_status = 'eligible', next_eligible_date = NULL
                 WHERE id = :donor_id AND verification_status = 'verified'"
            );

            // Insert restoration log
            $logActivity = $db->prepare(
                "INSERT INTO donor_activity_logs (donor_id, activity_type, description)
                 VALUES (:donor_id, 'eligibility_restored', 'Eligibility restored after waiting period ended.')"
            );

            $notificationService = new \App\Services\NotificationService();
            foreach ($allDonorIds as $idStr) {
                $donorId = (int) $idStr;
                $completeDeferral->execute(['donor_id' => $donorId]);
                $restoreEligibility->execute(['donor_id' => $donorId]);
                $logActivity->execute(['donor_id' => $donorId]);

                $getProfile = $db->prepare("SELECT user_id, preferred_contact_method, verification_status FROM donor_profiles WHERE id = :id LIMIT 1");
                $getProfile->execute(['id' => $donorId]);
                $profile = $getProfile->fetch();
                if ($profile && $profile['verification_status'] === 'verified') {
                    $notificationService->create(
                        (int) $profile['user_id'],
                        'donation_reminder',
                        'Eligible to Donate Again',
                        'Good news! Your waiting period has ended and you are now eligible to donate blood again. Keep your availability updated and book an appointment or visit a drive near you.',
                        '/app/dashboard',
                        null,
                        null,
                        $profile['preferred_contact_method'] === 'sms'
                    );
                }
            }

            $db->commit();
            return count($allDonorIds);
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }
}
