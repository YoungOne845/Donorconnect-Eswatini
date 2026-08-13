<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Audit;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\NotificationService;

final class CampaignController
{
    public function index(Request $request): never
    {
        $user = App::auth()->requireUser();
        $status = trim((string) $request->query('status', ''));
        $db = Database::connection();
        $where = ['1=1'];
        $params = [];
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        } elseif ($user['role'] === 'donor') {
            $where[] = "c.status IN ('scheduled','active','completed')";
        }
        if ($user['role'] === 'donor') {
            $where[] = '(c.target_region IS NULL OR c.target_region = :region)';
            $params['region'] = $user['region'];
        }
        $sql = "SELECT c.*, i.name AS institution_name, u.full_name AS created_by_name,
                       COUNT(cp.id) AS participant_count,
                       SUM(CASE WHEN cp.participation_status = 'donated' THEN 1 ELSE 0 END) AS donations_generated";
        if ($user['role'] === 'donor') {
            $sql .= ", MAX(CASE WHEN cp.donor_id = :donor_id THEN cp.participation_status ELSE NULL END) AS my_status";
            $params['donor_id'] = $user['donor_id'];
        }
        $sql .= " FROM campaigns c
                  LEFT JOIN institutions i ON i.id = c.institution_id
                  JOIN users u ON u.id = c.created_by
                  LEFT JOIN campaign_participants cp ON cp.campaign_id = c.id
                  WHERE " . implode(' AND ', $where) . "
                  GROUP BY c.id ORDER BY c.starts_at DESC";
        $statement = $db->prepare($sql);
        $statement->execute($params);
        Response::success('Campaigns loaded.', $statement->fetchAll());
    }

    public function create(Request $request): never
    {
        $user = App::auth()->requireRoles(['admin']);
        $data = $request->json();
        (new Validator())
            ->required($data, ['title','description','campaign_type','venue','starts_at','status'])
            ->string($data, 'title', 3, 200)
            ->string($data, 'description', 10, 5000)
            ->in($data, 'campaign_type', ['recruitment','donation_drive','awareness','retention','emergency'])
            ->in($data, 'target_region', ['Hhohho','Manzini','Lubombo','Shiselweni'], true)
            ->string($data, 'target_town', 0, 120, true)
            ->in($data, 'target_blood_type', ['A+','A-','B+','B-','AB+','AB-','O+','O-','All'], true)
            ->string($data, 'venue', 2, 200)
            ->in($data, 'status', ['draft','scheduled','active','completed','cancelled'])
            ->integer($data, 'capacity', 1, 100000, true)
            ->validate();

        if (strtotime((string) $data['starts_at']) === false) {
            throw new HttpException(422, 'Campaign start date is invalid.', ['starts_at' => 'Use a valid date and time.']);
        }
        if (!empty($data['ends_at']) && strtotime((string) $data['ends_at']) === false) {
            throw new HttpException(422, 'Campaign end date is invalid.', ['ends_at' => 'Use a valid date and time.']);
        }

        $db = Database::connection();
        $statement = $db->prepare(
            "INSERT INTO campaigns
             (institution_id, created_by, title, description, campaign_type, target_region, target_town,
              target_blood_type, venue, starts_at, ends_at, capacity, status)
             VALUES (:institution_id, :created_by, :title, :description, :campaign_type, :target_region,
                     :target_town, :target_blood_type, :venue, :starts_at, :ends_at, :capacity, :status)"
        );
        $statement->execute([
            'institution_id' => !empty($data['institution_id']) ? (int) $data['institution_id'] : ($user['institution_id'] ?? null),
            'created_by' => $user['id'],
            'title' => trim((string) $data['title']),
            'description' => trim((string) $data['description']),
            'campaign_type' => $data['campaign_type'],
            'target_region' => $data['target_region'] ?? null,
            'target_town' => trim((string) ($data['target_town'] ?? '')) ?: null,
            'target_blood_type' => $data['target_blood_type'] ?? 'All',
            'venue' => trim((string) $data['venue']),
            'starts_at' => date('Y-m-d H:i:s', strtotime((string) $data['starts_at'])),
            'ends_at' => !empty($data['ends_at']) ? date('Y-m-d H:i:s', strtotime((string) $data['ends_at'])) : null,
            'capacity' => !empty($data['capacity']) ? (int) $data['capacity'] : null,
            'status' => $data['status'],
        ]);
        $id = (int) $db->lastInsertId();
        Audit::log('CAMPAIGN_CREATED', 'A donor recruitment or engagement campaign was created.', 'campaign', $id, null, ['title' => $data['title'], 'type' => $data['campaign_type']], $request);
        Response::success('Campaign created.', ['id' => $id], 201);
    }

    public function updateStatus(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $id = (int) $request->param('id');
        $data = $request->json();
        (new Validator())->required($data, ['status'])->in($data, 'status', ['draft','scheduled','active','completed','cancelled'])->validate();
        $db = Database::connection();
        $before = $db->prepare('SELECT * FROM campaigns WHERE id = :id');
        $before->execute(['id' => $id]);
        $campaign = $before->fetch();
        if (!$campaign) throw new HttpException(404, 'Campaign not found.');
        $update = $db->prepare('UPDATE campaigns SET status = :status WHERE id = :id');
        $update->execute(['status' => $data['status'], 'id' => $id]);
        Audit::log('CAMPAIGN_STATUS_UPDATED', 'Campaign status was updated.', 'campaign', $id, ['status' => $campaign['status']], ['status' => $data['status']], $request);
        Response::success('Campaign status updated.');
    }

    public function join(Request $request): never
    {
        $user = App::auth()->requireRoles(['donor']);
        $campaignId = (int) $request->param('id');
        $data = $request->json();
        $status = $data['participation_status'] ?? 'interested';
        (new Validator())->in(['participation_status' => $status], 'participation_status', ['interested','registered','declined'])->validate();
        $db = Database::connection();
        $campaign = $db->prepare("SELECT * FROM campaigns WHERE id = :id AND status IN ('scheduled','active')");
        $campaign->execute(['id' => $campaignId]);
        $campaignRow = $campaign->fetch();
        if (!$campaignRow) throw new HttpException(404, 'Active campaign not found.');

        if (!empty($campaignRow['capacity']) && $status !== 'declined') {
            $count = $db->prepare("SELECT COUNT(*) FROM campaign_participants WHERE campaign_id = :id AND participation_status IN ('interested','registered','attended','donated')");
            $count->execute(['id' => $campaignId]);
            if ((int) $count->fetchColumn() >= (int) $campaignRow['capacity']) {
                throw new HttpException(409, 'This campaign has reached its participant capacity.');
            }
        }

        $statement = $db->prepare(
            "INSERT INTO campaign_participants (campaign_id, donor_id, participation_status, registered_at)
             VALUES (:campaign_id, :donor_id, :status, CASE WHEN :status2 = 'registered' THEN NOW() ELSE NULL END)
             ON DUPLICATE KEY UPDATE participation_status = VALUES(participation_status),
                                     registered_at = CASE WHEN VALUES(participation_status) = 'registered' THEN NOW() ELSE registered_at END"
        );
        $statement->execute(['campaign_id' => $campaignId, 'donor_id' => $user['donor_id'], 'status' => $status, 'status2' => $status]);
        $activity = $db->prepare("INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata) VALUES (:donor_id, 'campaign_joined', :description, :metadata)");
        $activity->execute([
            'donor_id' => $user['donor_id'],
            'description' => 'Campaign response updated to ' . $status . ' for ' . $campaignRow['title'] . '.',
            'metadata' => json_encode(['campaign_id' => $campaignId, 'status' => $status], JSON_THROW_ON_ERROR),
        ]);
        (new NotificationService())->create(
            (int) $user['id'], 'campaign', 'Campaign response saved',
            "Your response for {$campaignRow['title']} has been recorded as {$status}.",
            '/app/campaigns', null, $campaignId
        );
        Audit::log('DONOR_CAMPAIGN_RESPONSE', 'Donor updated campaign participation.', 'campaign', $campaignId, null, ['donor_id' => $user['donor_id'], 'status' => $status], $request);
        Response::success('Campaign response saved.', ['participation_status' => $status]);
    }

    public function invite(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $campaignId = (int) $request->param('id');
        $data = $request->json();
        $limit = min(1000, max(1, (int) ($data['limit'] ?? 200)));
        $db = Database::connection();
        $campaignStatement = $db->prepare('SELECT * FROM campaigns WHERE id = :id');
        $campaignStatement->execute(['id' => $campaignId]);
        $campaign = $campaignStatement->fetch();
        if (!$campaign) throw new HttpException(404, 'Campaign not found.');

        $where = ["dp.verification_status = 'verified'", "u.account_status = 'active'", 'dp.consent_to_notifications = 1'];
        $params = [];
        if (!empty($campaign['target_region'])) {
            $where[] = 'dp.region = :region';
            $params['region'] = $campaign['target_region'];
        }
        if ($campaign['target_blood_type'] !== 'All') {
            $where[] = 'dp.blood_type = :blood_type';
            $params['blood_type'] = $campaign['target_blood_type'];
        }
        $statement = $db->prepare(
            "SELECT dp.id AS donor_id, dp.user_id FROM donor_profiles dp JOIN users u ON u.id = dp.user_id
             WHERE " . implode(' AND ', $where) . " ORDER BY dp.updated_at DESC LIMIT {$limit}"
        );
        $statement->execute($params);
        $donors = $statement->fetchAll();
        $insert = $db->prepare(
            "INSERT INTO campaign_participants (campaign_id, donor_id, participation_status)
             VALUES (:campaign_id, :donor_id, 'invited')
             ON DUPLICATE KEY UPDATE participation_status = IF(participation_status IN ('donated','attended','registered'), participation_status, 'invited')"
        );
        $notifications = new NotificationService();
        foreach ($donors as $donor) {
            $insert->execute(['campaign_id' => $campaignId, 'donor_id' => $donor['donor_id']]);
            $notifications->create(
                (int) $donor['user_id'], 'campaign', 'You are invited to a donor campaign',
                $campaign['title'] . ' will take place at ' . $campaign['venue'] . ' on ' . date('d M Y H:i', strtotime($campaign['starts_at'])) . '.',
                '/app/campaigns', null, $campaignId
            );
        }
        Audit::log('CAMPAIGN_DONORS_INVITED', 'Eligible donors were invited to a campaign.', 'campaign', $campaignId, null, ['count' => count($donors)], $request);
        Response::success('Donors invited to the campaign.', ['invited' => count($donors)]);
    }
}
