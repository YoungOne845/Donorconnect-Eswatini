<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class DashboardController
{
    public function index(Request $request): never
    {
        $user = App::auth()->requireUser();
        if ($user['role'] === 'donor') {
            (new DonorController())->dashboard($request);
        }

        $db = Database::connection();
        if ($user['role'] === 'hospital') {
            $where = !empty($user['institution_id']) ? '(hospital_id = :institution_id OR created_by = :user_id)' : 'created_by = :user_id';
            $statement = $db->prepare(
                "SELECT
                    COUNT(*) AS total_requests,
                    SUM(CASE WHEN status IN ('active','partially_fulfilled') THEN 1 ELSE 0 END) AS active_requests,
                    SUM(CASE WHEN status = 'fulfilled' THEN 1 ELSE 0 END) AS fulfilled_requests,
                    COALESCE(SUM(units_required),0) AS units_requested,
                    COALESCE(SUM(units_fulfilled),0) AS units_fulfilled
                 FROM blood_requests WHERE {$where}"
            );
            $params = ['user_id' => $user['id']];
            if (!empty($user['institution_id'])) $params['institution_id'] = $user['institution_id'];
            $statement->execute($params);
            $summary = $statement->fetch();
            $recent = $db->prepare("SELECT * FROM blood_requests WHERE {$where} ORDER BY created_at DESC LIMIT 8");
            $recent->execute($params);
            Response::success('Hospital dashboard loaded.', ['summary' => $summary, 'recent_requests' => $recent->fetchAll()]);
        }

        $summary = $db->query(
            "SELECT
                (SELECT COUNT(*) FROM donor_profiles) AS total_donors,
                (SELECT COUNT(*) FROM donor_profiles WHERE verification_status = 'pending') AS pending_verifications,
                (SELECT COUNT(*) FROM donor_profiles WHERE eligibility_status = 'eligible') AS eligible_donors,
                (SELECT COUNT(*) FROM blood_requests WHERE status IN ('active','partially_fulfilled')) AS active_requests,
                (SELECT COUNT(*) FROM campaigns WHERE status IN ('scheduled','active')) AS active_campaigns,
                (SELECT COUNT(*) FROM donor_profiles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_donors_30_days"
        )->fetch();

        $institutionId = $user['institution_id'] ?? null;
        $isStaff = $user['role'] === 'staff';

        // Count pending appointments
        if ($isStaff && $institutionId) {
            $pendingAppointmentsCountStmt = $db->prepare("SELECT COUNT(*) FROM appointment_requests WHERE status = 'pending' AND institution_id = :institution_id");
            $pendingAppointmentsCountStmt->execute(['institution_id' => $institutionId]);
            $pendingAppointmentsCount = (int) $pendingAppointmentsCountStmt->fetchColumn();

            $pendingAppointmentsListStmt = $db->prepare(
                "SELECT ar.*, u.full_name, dp.donor_code
                 FROM appointment_requests ar
                 JOIN donor_profiles dp ON dp.id = ar.donor_id
                 JOIN users u ON u.id = dp.user_id
                 WHERE ar.status = 'pending' AND ar.institution_id = :institution_id
                 ORDER BY ar.appointment_at ASC LIMIT 5"
            );
            $pendingAppointmentsListStmt->execute(['institution_id' => $institutionId]);
            $pendingAppointmentsList = $pendingAppointmentsListStmt->fetchAll();

            $pendingProfileUpdatesCountStmt = $db->prepare(
                "SELECT COUNT(*) FROM profile_update_requests pur
                 JOIN donor_profiles dp ON dp.id = pur.donor_id
                 WHERE pur.status = 'pending' AND dp.recruitment_institution_id = :institution_id"
            );
            $pendingProfileUpdatesCountStmt->execute(['institution_id' => $institutionId]);
            $pendingProfileUpdatesCount = (int) $pendingProfileUpdatesCountStmt->fetchColumn();
        } else {
            $pendingAppointmentsCount = (int) $db->query("SELECT COUNT(*) FROM appointment_requests WHERE status = 'pending'")->fetchColumn();
            $pendingAppointmentsList = $db->query(
                "SELECT ar.*, u.full_name, dp.donor_code
                 FROM appointment_requests ar
                 JOIN donor_profiles dp ON dp.id = ar.donor_id
                 JOIN users u ON u.id = dp.user_id
                 WHERE ar.status = 'pending'
                 ORDER BY ar.appointment_at ASC LIMIT 5"
            )->fetchAll();

            $pendingProfileUpdatesCount = (int) $db->query("SELECT COUNT(*) FROM profile_update_requests WHERE status = 'pending'")->fetchColumn();
        }

        $summary['pending_appointments'] = $pendingAppointmentsCount;
        $summary['pending_profile_updates'] = $pendingProfileUpdatesCount;

        $recentDonors = $db->query(
            "SELECT dp.id, dp.donor_code, u.full_name, dp.blood_type, dp.region, dp.town, dp.verification_status, dp.eligibility_status, dp.created_at
             FROM donor_profiles dp JOIN users u ON u.id = dp.user_id ORDER BY dp.created_at DESC LIMIT 8"
        )->fetchAll();
        $urgentRequests = $db->query(
            "SELECT id, request_code, blood_type_needed, units_required, urgency_level, hospital_name, region, town, status, created_at
             FROM blood_requests WHERE status IN ('active','partially_fulfilled')
             ORDER BY FIELD(urgency_level,'critical','high','medium','low'), created_at DESC LIMIT 8"
        )->fetchAll();
        Response::success('Operations dashboard loaded.', [
            'summary' => $summary,
            'recent_donors' => $recentDonors,
            'urgent_requests' => $urgentRequests,
            'pending_appointments' => $pendingAppointmentsList
        ]);
    }
}
