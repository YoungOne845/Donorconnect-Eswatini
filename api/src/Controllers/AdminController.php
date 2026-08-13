<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Audit;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Identity;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use PDOException;

final class AdminController
{
    private const INSTITUTION_TYPES = ['hospital','blood_service','school','university','church','workplace','community_organisation','other'];
    private const REGIONS = ['Hhohho','Manzini','Lubombo','Shiselweni'];

    public function institutions(Request $request): never
    {
        $statement = Database::connection()->query('SELECT * FROM institutions ORDER BY is_active DESC, name ASC');
        Response::success('Institutions loaded.', $statement->fetchAll());
    }

    public function createInstitution(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $data = $request->json();

        (new Validator())
            ->required($data, ['name','institution_type','region','town'])
            ->string($data, 'name', 3, 180)
            ->in($data, 'institution_type', self::INSTITUTION_TYPES)
            ->in($data, 'region', self::REGIONS)
            ->string($data, 'town', 2, 120)
            ->email($data, 'email', true)
            ->validate();

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone !== '' && !Identity::validEswatiniPhone($phone)) {
            throw new HttpException(422, 'Institution phone number is invalid.', ['phone' => 'Enter a valid Eswatini phone number.']);
        }

        $db = Database::connection();
        $statement = $db->prepare(
            'INSERT INTO institutions (name, institution_type, phone, email, region, town, address, is_active)
             VALUES (:name, :type, :phone, :email, :region, :town, :address, :is_active)'
        );
        $statement->execute([
            'name' => trim((string) $data['name']),
            'type' => $data['institution_type'],
            'phone' => $phone !== '' ? Identity::phone($phone) : null,
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'region' => $data['region'],
            'town' => trim((string) $data['town']),
            'address' => trim((string) ($data['address'] ?? '')) ?: null,
            'is_active' => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ]);

        $id = (int) $db->lastInsertId();
        Audit::log('INSTITUTION_CREATED', 'An institution was added.', 'institution', $id, null, [
            'name' => $data['name'],
            'type' => $data['institution_type'],
        ], $request);

        Response::success('Institution created.', ['id' => $id], 201);
    }

    /** Names of the three ENBTS blood banks that were seeded as system foundations. */
    private const PROTECTED_INSTITUTION_NAMES = [
        'Mbabane Blood Bank',
        'Manzini Blood Bank',
        'Hlathikhulu Blood Bank',
    ];

    public function deleteInstitution(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $id = (int) $request->param('id');
        $db = Database::connection();

        $institutionStatement = $db->prepare('SELECT * FROM institutions WHERE id = :id LIMIT 1');
        $institutionStatement->execute(['id' => $id]);
        $institution = $institutionStatement->fetch();
        if (!$institution) {
            throw new HttpException(404, 'Institution not found.');
        }

        if (in_array($institution['name'], self::PROTECTED_INSTITUTION_NAMES, true)) {
            throw new HttpException(
                409,
                'This is a protected ENBTS core institution and cannot be deleted. It is required for system operations.'
            );
        }

        $userCountStatement = $db->prepare('SELECT COUNT(*) FROM users WHERE institution_id = :id');
        $userCountStatement->execute(['id' => $id]);
        $linkedUsers = (int) $userCountStatement->fetchColumn();
        if ($linkedUsers > 0) {
            throw new HttpException(
                409,
                'This institution still has linked user accounts. Reassign or delete those accounts before deleting the institution.',
                ['institution' => "{$linkedUsers} user account(s) are still linked to this institution."]
            );
        }

        Audit::log(
            'INSTITUTION_DELETED',
            'An administrator deleted an institution.',
            'institution',
            $id,
            $institution,
            null,
            $request
        );

        $delete = $db->prepare('DELETE FROM institutions WHERE id = :id');
        $delete->execute(['id' => $id]);

        Response::success('Institution deleted successfully. Historical campaign, donation and request records remain preserved without the institution link.');
    }

    public function users(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $role = trim((string) $request->query('role', ''));
        $search = trim((string) $request->query('search', ''));
        $where = ['1=1'];
        $params = [];

        if ($role !== '') {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }
        if ($search !== '') {
            // PDO named parameters must be unique per statement.
            $where[] = '(u.full_name LIKE :s_name OR u.email LIKE :s_email OR u.phone LIKE :s_phone OR u.national_id_last_four LIKE :s_id4)';
            $searchLike = "%{$search}%";
            $params['s_name']  = $searchLike;
            $params['s_email'] = $searchLike;
            $params['s_phone'] = $searchLike;
            $params['s_id4']   = $searchLike;
        }

        $statement = Database::connection()->prepare(
            "SELECT u.id, u.full_name, u.national_id_last_four, u.email, u.phone, u.role, u.account_status,
                    u.last_login_at, u.created_at, i.name AS institution_name
             FROM users u
             LEFT JOIN institutions i ON i.id = u.institution_id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY u.created_at DESC
             LIMIT 500'
        );
        $statement->execute($params);

        Response::success('Users loaded.', $statement->fetchAll());
    }

    public function createStaffAccount(Request $request): never
    {
        App::auth()->requireRoles(['admin']);
        $data = $request->json();

        (new Validator())
            ->required($data, ['full_name','national_id','phone','password','role'])
            ->string($data, 'full_name', 3, 180)
            ->string($data, 'national_id', Identity::NATIONAL_ID_LENGTH, Identity::NATIONAL_ID_LENGTH)
            ->string($data, 'phone', 8, 20)
            ->string($data, 'password', 10, 128)
            ->email($data, 'email', true)
            ->in($data, 'role', ['hospital','staff','admin'])
            ->validate();

        $nationalId = Identity::nationalId((string) $data['national_id']);
        if (!Identity::validNationalId($nationalId)) {
            throw new HttpException(422, 'National ID is invalid.', [
                'national_id' => 'Enter a valid 13-digit national ID whose first six digits form a real YYMMDD birth date.',
            ]);
        }

        $phone = Identity::phone((string) $data['phone']);
        if (!Identity::validEswatiniPhone($phone)) {
            throw new HttpException(422, 'Phone number is invalid.', ['phone' => 'Enter a valid Eswatini phone number.']);
        }

        $password = (string) $data['password'];
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new HttpException(422, 'Password is not strong enough.', ['password' => 'Use uppercase, lowercase and numbers.']);
        }

        $institutionId = !empty($data['institution_id']) ? (int) $data['institution_id'] : null;
        if (in_array($data['role'], ['hospital','staff'], true) && $institutionId === null) {
            throw new HttpException(422, 'An institution is required for hospital and blood-service staff accounts.', [
                'institution_id' => 'Select the institution this user belongs to.',
            ]);
        }

        $db = Database::connection();
        if ($institutionId !== null) {
            $institution = $db->prepare('SELECT id, institution_type FROM institutions WHERE id = :id AND is_active = 1');
            $institution->execute(['id' => $institutionId]);
            $institutionRecord = $institution->fetch();
            if (!$institutionRecord) {
                throw new HttpException(422, 'Select a valid active institution.', ['institution_id' => 'Institution not found or inactive.']);
            }
            if ($data['role'] === 'hospital' && $institutionRecord['institution_type'] !== 'hospital') {
                throw new HttpException(422, 'Hospital accounts must be linked to a hospital institution.', ['institution_id' => 'Choose an institution of type Hospital.']);
            }
            if ($data['role'] === 'staff' && $institutionRecord['institution_type'] !== 'blood_service') {
                throw new HttpException(422, 'Blood-service staff must be linked to a blood-service institution.', ['institution_id' => 'Choose an institution of type Blood service.']);
            }
        }

        $crypto = App::crypto();
        $nationalHash = $crypto->searchHash($nationalId);
        $email = trim((string) ($data['email'] ?? '')) ?: null;

        $duplicateSql =
            'SELECT id, national_id_hash, phone, email FROM users
             WHERE national_id_hash = :national_hash OR phone = :phone';

        $duplicateParams = [
            'national_hash' => $nationalHash,
            'phone' => $phone,
        ];

        if ($email !== null) {
            $duplicateSql .= ' OR email = :email';
            $duplicateParams['email'] = $email;
        }

        $duplicateSql .= ' LIMIT 1';
        $duplicate = $db->prepare($duplicateSql);
        $duplicate->execute($duplicateParams);
        if ($duplicate->fetch()) {
            throw new HttpException(409, 'That national ID, phone number or email is already in use.');
        }

        $statement = $db->prepare(
            "INSERT INTO users
             (institution_id, full_name, national_id_encrypted, national_id_hash, national_id_last_four,
              email, phone, password_hash, role, account_status)
             VALUES
             (:institution_id, :full_name, :national_id_encrypted, :national_id_hash, :national_id_last_four,
              :email, :phone, :password_hash, :role, 'active')"
        );

        try {
            $statement->execute([
                'institution_id' => $institutionId,
                'full_name' => trim((string) $data['full_name']),
                'national_id_encrypted' => $crypto->encrypt($nationalId),
                'national_id_hash' => $nationalHash,
                'national_id_last_four' => substr($nationalId, -4),
                'email' => $email,
                'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $data['role'],
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new HttpException(409, 'That national ID, phone number or email is already in use.');
            }
            throw $exception;
        }

        $id = (int) $db->lastInsertId();
        Audit::log('STAFF_ACCOUNT_CREATED', 'An operational account was created.', 'user', $id, null, [
            'role' => $data['role'],
            'institution_id' => $institutionId,
            'national_id_last_four' => substr($nationalId, -4),
        ], $request);

        Response::success('Account created.', ['id' => $id], 201);
    }

    public function updateUserStatus(Request $request): never
    {
        $current = App::auth()->requireRoles(['admin']);
        $id = (int) $request->param('id');
        $data = $request->json();

        (new Validator())
            ->required($data, ['account_status'])
            ->in($data, 'account_status', ['active','inactive','suspended','pending'])
            ->validate();

        if ($id === (int) $current['id'] && $data['account_status'] !== 'active') {
            throw new HttpException(409, 'You cannot deactivate your own administrator account.');
        }

        $db = Database::connection();
        $before = $db->prepare('SELECT account_status FROM users WHERE id = :id');
        $before->execute(['id' => $id]);
        $old = $before->fetch();
        if (!$old) {
            throw new HttpException(404, 'User not found.');
        }

        $update = $db->prepare('UPDATE users SET account_status = :status WHERE id = :id');
        $update->execute(['status' => $data['account_status'], 'id' => $id]);

        Audit::log('USER_STATUS_UPDATED', 'Administrator updated an account status.', 'user', $id, $old, [
            'account_status' => $data['account_status'],
        ], $request);

        Response::success('Account status updated.');
    }

    public function deleteUser(Request $request): never
    {
        $current = App::auth()->requireRoles(['admin']);
        $id = (int) $request->param('id');

        if ($id === (int) $current['id']) {
            throw new HttpException(409, 'You cannot delete the administrator account you are currently using.');
        }

        $db = Database::connection();
        $userStatement = $db->prepare(
            'SELECT id, full_name, national_id_last_four, email, phone, role, account_status, institution_id
             FROM users WHERE id = :id LIMIT 1'
        );
        $userStatement->execute(['id' => $id]);
        $target = $userStatement->fetch();
        if (!$target) {
            throw new HttpException(404, 'User account not found.');
        }

        if (in_array($target['email'], [
            'mbabane.admin@enbts.org.sz',
            'manzini.operator@enbts.org.sz',
            'hlathikhulu.operator@enbts.org.sz'
        ], true)) {
            throw new HttpException(409, 'This is a protected system seed account and cannot be deleted.');
        }

        if ($target['role'] === 'admin') {
            $adminCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND account_status = 'active'")->fetchColumn();
            if ($adminCount <= 1) {
                throw new HttpException(409, 'The last active administrator account cannot be deleted.');
            }
        }

        $protectedRecords = $this->protectedUserRecordCounts($id, (string) $target['role']);
        $blocking = array_filter($protectedRecords, static fn (int $count): bool => $count > 0);
        if ($blocking !== []) {
            $summary = implode(', ', array_map(
                static fn (string $name, int $count): string => "{$name}: {$count}",
                array_keys($blocking),
                array_values($blocking)
            ));
            throw new HttpException(
                409,
                'This account has protected operational history and cannot be permanently deleted. Set it to inactive instead.',
                ['account' => $summary]
            );
        }

        Audit::log(
            'USER_DELETED',
            'An administrator permanently deleted an unused user account.',
            'user',
            $id,
            $target,
            null,
            $request
        );

        $delete = $db->prepare('DELETE FROM users WHERE id = :id');
        $delete->execute(['id' => $id]);

        Response::success('User account deleted successfully.');
    }

    private function protectedUserRecordCounts(int $userId, string $role): array
    {
        $db = Database::connection();
        $counts = [];

        $queries = [
            'campaigns created' => 'SELECT COUNT(*) FROM campaigns WHERE created_by = :id',
            'eligibility assessments' => 'SELECT COUNT(*) FROM eligibility_assessments WHERE assessed_by = :id',
            'deferrals recorded' => 'SELECT COUNT(*) FROM donor_deferrals WHERE recorded_by = :id',
            'donations recorded' => 'SELECT COUNT(*) FROM donation_records WHERE recorded_by = :id',
            'blood requests created' => 'SELECT COUNT(*) FROM blood_requests WHERE created_by = :id',
        ];

        foreach ($queries as $label => $sql) {
            $statement = $db->prepare($sql);
            $statement->execute(['id' => $userId]);
            $counts[$label] = (int) $statement->fetchColumn();
        }

        if ($role === 'donor') {
            $donorStatement = $db->prepare('SELECT id FROM donor_profiles WHERE user_id = :id LIMIT 1');
            $donorStatement->execute(['id' => $userId]);
            $donorId = (int) ($donorStatement->fetchColumn() ?: 0);

            if ($donorId > 0) {
                $donorQueries = [
                    'donations received' => 'SELECT COUNT(*) FROM donation_records WHERE donor_id = :id',
                    'eligibility history' => 'SELECT COUNT(*) FROM eligibility_assessments WHERE donor_id = :id',
                    'deferral history' => 'SELECT COUNT(*) FROM donor_deferrals WHERE donor_id = :id',
                    'request matches' => 'SELECT COUNT(*) FROM request_matches WHERE donor_id = :id',
                    'campaign participation' => 'SELECT COUNT(*) FROM campaign_participants WHERE donor_id = :id',
                ];

                foreach ($donorQueries as $label => $sql) {
                    $statement = $db->prepare($sql);
                    $statement->execute(['id' => $donorId]);
                    $counts[$label] = (int) $statement->fetchColumn();
                }
            }
        }

        return $counts;
    }
}
