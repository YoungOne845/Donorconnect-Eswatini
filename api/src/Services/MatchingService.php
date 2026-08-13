<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

final class MatchingService
{
    private const COMPATIBLE_DONORS = [
        'A+' => ['A+', 'A-', 'O+', 'O-'],
        'A-' => ['A-', 'O-'],
        'B+' => ['B+', 'B-', 'O+', 'O-'],
        'B-' => ['B-', 'O-'],
        'AB+' => ['AB+', 'AB-', 'A+', 'A-', 'B+', 'B-', 'O+', 'O-'],
        'AB-' => ['AB-', 'A-', 'B-', 'O-'],
        'O+' => ['O+', 'O-'],
        'O-' => ['O-'],
    ];

    public function match(int $requestId, int $limit = 50): array
    {
        $db = Database::connection();
        $requestStatement = $db->prepare('SELECT * FROM blood_requests WHERE id = :id LIMIT 1');
        $requestStatement->execute(['id' => $requestId]);
        $request = $requestStatement->fetch();
        if (!$request) {
            throw new HttpException(404, 'Blood request not found.');
        }
        if (!in_array($request['status'], ['active', 'partially_fulfilled'], true)) {
            throw new HttpException(409, 'Only active requests can be matched.');
        }

        $compatible = self::COMPATIBLE_DONORS[$request['blood_type_needed']] ?? [];
        if ($compatible === []) {
            throw new HttpException(422, 'No compatibility rules exist for this blood type.');
        }

        $placeholders = implode(',', array_fill(0, count($compatible), '?'));
        $sql = "SELECT dp.id AS donor_id, dp.user_id, dp.blood_type, dp.region, dp.town, dp.last_donation_date,
                       dp.next_eligible_date, dp.total_donations, dp.preferred_contact_method,
                       u.full_name, u.phone,
                       COALESCE(SUM(CASE WHEN rm.donor_response = 'accepted' THEN 1 ELSE 0 END), 0) AS accepted_count,
                       COALESCE(SUM(CASE WHEN rm.donor_response IN ('accepted','declined') THEN 1 ELSE 0 END), 0) AS responded_count
                FROM donor_profiles dp
                JOIN users u ON u.id = dp.user_id
                LEFT JOIN request_matches rm ON rm.donor_id = dp.id
                WHERE dp.verification_status = 'verified'
                  AND dp.eligibility_status = 'eligible'
                  AND dp.availability_status = 'available'
                  AND dp.consent_to_notifications = 1
                  AND u.account_status = 'active'
                  AND dp.blood_type IN ({$placeholders})
                  AND (dp.next_eligible_date IS NULL OR dp.next_eligible_date <= CURDATE())
                GROUP BY dp.id
                LIMIT " . max(1, min($limit, 200));
        $statement = $db->prepare($sql);
        $statement->execute($compatible);
        $candidates = $statement->fetchAll();

        $results = [];
        $upsert = $db->prepare(
            "INSERT INTO request_matches
             (request_id, donor_id, compatibility_score, location_score, availability_score, eligibility_score, responsiveness_score, total_match_score)
             VALUES (:request_id, :donor_id, :compatibility_score, :location_score, :availability_score, :eligibility_score, :responsiveness_score, :total_match_score)
             ON DUPLICATE KEY UPDATE
                compatibility_score = VALUES(compatibility_score),
                location_score = VALUES(location_score),
                availability_score = VALUES(availability_score),
                eligibility_score = VALUES(eligibility_score),
                responsiveness_score = VALUES(responsiveness_score),
                total_match_score = VALUES(total_match_score)"
        );

        foreach ($candidates as $candidate) {
            $compatibility = $candidate['blood_type'] === $request['blood_type_needed'] ? 40.0 : 32.0;
            $location = $candidate['town'] === $request['town']
                ? 25.0
                : ($candidate['region'] === $request['region'] ? 15.0 : 5.0);
            $availability = 15.0;
            $eligibility = 10.0;
            $responded = (int) $candidate['responded_count'];
            $accepted = (int) $candidate['accepted_count'];
            $responsiveness = $responded > 0 ? round(($accepted / $responded) * 10, 2) : 5.0;
            $total = round($compatibility + $location + $availability + $eligibility + $responsiveness, 2);

            $scores = [
                'request_id' => $requestId,
                'donor_id' => (int) $candidate['donor_id'],
                'compatibility_score' => $compatibility,
                'location_score' => $location,
                'availability_score' => $availability,
                'eligibility_score' => $eligibility,
                'responsiveness_score' => $responsiveness,
                'total_match_score' => $total,
            ];
            $upsert->execute($scores);
            $results[] = array_merge($candidate, $scores);
        }

        usort($results, fn (array $a, array $b): int => $b['total_match_score'] <=> $a['total_match_score']);
        return $results;
    }
}
