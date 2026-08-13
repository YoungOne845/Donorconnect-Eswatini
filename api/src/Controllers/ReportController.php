<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\EligibilityService;

final class ReportController
{
    public function overview(Request $request): never
    {
        App::auth()->requireRoles(['staff','admin']);
        (new EligibilityService())->refreshExpiredDeferrals();
        $db = Database::connection();

        $summary = $db->query(
            "SELECT
                (SELECT COUNT(*) FROM donor_profiles) AS total_donors,
                (SELECT COUNT(*) FROM donor_profiles WHERE verification_status = 'verified') AS verified_donors,
                (SELECT COUNT(*) FROM donor_profiles WHERE eligibility_status = 'eligible') AS eligible_donors,
                (SELECT COUNT(*) FROM donor_profiles WHERE availability_status = 'available') AS available_donors,
                (SELECT COUNT(*) FROM donor_profiles WHERE total_donations > 0) AS donors_who_donated,
                (SELECT COUNT(*) FROM donor_profiles WHERE total_donations > 1) AS repeat_donors,
                (SELECT COALESCE(SUM(total_donations), 0) FROM donor_profiles) AS total_donations,
                (SELECT COUNT(*) FROM campaigns WHERE status IN ('scheduled','active')) AS active_campaigns,
                (SELECT COUNT(*) FROM blood_requests WHERE status IN ('active','partially_fulfilled')) AS active_requests,
                (SELECT COUNT(*) FROM donor_profiles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_donors_30_days"
        )->fetch();

        $monthly = $db->query(
            "SELECT DATE_FORMAT(months.month_start, '%Y-%m') AS month,
                    COALESCE(registrations.count, 0) AS registrations,
                    COALESCE(donations.count, 0) AS donations
             FROM (
                SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL n MONTH), '%Y-%m-01') AS month_start
                FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11) nums
             ) months
             LEFT JOIN (
                SELECT DATE_FORMAT(created_at, '%Y-%m-01') AS month_start, COUNT(*) AS count
                FROM donor_profiles WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m-01')
             ) registrations ON registrations.month_start = months.month_start
             LEFT JOIN (
                SELECT DATE_FORMAT(donation_date, '%Y-%m-01') AS month_start, COUNT(*) AS count
                FROM donation_records WHERE donation_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                GROUP BY DATE_FORMAT(donation_date, '%Y-%m-01')
             ) donations ON donations.month_start = months.month_start
             ORDER BY months.month_start"
        )->fetchAll();

        $bloodTypes = $db->query("SELECT blood_type AS label, COUNT(*) AS value FROM donor_profiles GROUP BY blood_type ORDER BY value DESC")->fetchAll();
        $regions = $db->query("SELECT region AS label, COUNT(*) AS value FROM donor_profiles GROUP BY region ORDER BY value DESC")->fetchAll();
        $eligibility = $db->query("SELECT eligibility_status AS label, COUNT(*) AS value FROM donor_profiles GROUP BY eligibility_status ORDER BY value DESC")->fetchAll();
        $sources = $db->query(
            "SELECT recruitment_source AS label, COUNT(*) AS registrations,
                    SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) AS verified,
                    SUM(CASE WHEN total_donations > 0 THEN 1 ELSE 0 END) AS converted_to_donor,
                    SUM(CASE WHEN total_donations > 1 THEN 1 ELSE 0 END) AS repeat_donors
             FROM donor_profiles GROUP BY recruitment_source ORDER BY registrations DESC"
        )->fetchAll();
        $campaigns = $db->query(
            "SELECT c.id, c.title, c.campaign_type, c.status, c.starts_at,
                    COUNT(cp.id) AS participants,
                    SUM(CASE WHEN cp.participation_status = 'attended' THEN 1 ELSE 0 END) AS attended,
                    SUM(CASE WHEN cp.participation_status = 'donated' THEN 1 ELSE 0 END) AS donated
             FROM campaigns c LEFT JOIN campaign_participants cp ON cp.campaign_id = c.id
             GROUP BY c.id ORDER BY c.starts_at DESC LIMIT 10"
        )->fetchAll();

        $total = max(1, (int) $summary['total_donors']);
        $donorsWhoDonated = (int) $summary['donors_who_donated'];
        $summary['verification_rate'] = round(((int) $summary['verified_donors'] / $total) * 100, 1);
        $summary['first_donation_conversion_rate'] = round(($donorsWhoDonated / $total) * 100, 1);
        $summary['repeat_donor_rate'] = $donorsWhoDonated > 0 ? round(((int) $summary['repeat_donors'] / $donorsWhoDonated) * 100, 1) : 0;

        Response::success('Donor pool reports loaded.', [
            'summary' => $summary,
            'monthly_activity' => $monthly,
            'blood_type_distribution' => $bloodTypes,
            'regional_distribution' => $regions,
            'eligibility_distribution' => $eligibility,
            'recruitment_sources' => $sources,
            'campaign_performance' => $campaigns,
        ]);
    }
}
