<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    private ?array $cachedUser = null;

    public function __construct(private readonly PDO $db)
    {
    }

    public function user(): ?array
    {
        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return null;
        }

        $statement = $this->db->prepare(
            "SELECT u.id, u.institution_id, u.full_name, u.national_id_last_four, u.email, u.phone, u.phone_secondary,
                    u.role, u.account_status, u.last_login_at, u.created_at,
                    i.name AS institution_name, i.institution_type
             FROM users u
             LEFT JOIN institutions i ON i.id = u.institution_id
             WHERE u.id = :id
             LIMIT 1"
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();

        if (!$user || $user['account_status'] !== 'active') {
            $this->logout();
            return null;
        }

        if ($user['role'] === 'donor') {
            $profileStatement = $this->db->prepare(
                "SELECT id AS donor_id, donor_code, blood_type, verification_status, eligibility_status,
                        availability_status, region, town, total_donations
                 FROM donor_profiles WHERE user_id = :user_id LIMIT 1"
            );
            $profileStatement->execute(['user_id' => $userId]);
            $profile = $profileStatement->fetch();
            if ($profile) {
                $user = array_merge($user, $profile);
            }
        }

        return $this->cachedUser = $user;
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if (!$user) {
            throw new HttpException(401, 'Authentication is required.');
        }
        return $user;
    }

    public function requireRoles(array $roles): array
    {
        $user = $this->requireUser();
        if (!in_array($user['role'], $roles, true)) {
            throw new HttpException(403, 'You do not have permission to perform this action.');
        }
        return $user;
    }

    public function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['authenticated_at'] = time();
        $this->cachedUser = null;
    }

    public function logout(): void
    {
        $this->cachedUser = null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function requireCsrf(Request $request): void
    {
        $provided = (string) $request->header('X-CSRF-Token', '');
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            throw new HttpException(419, 'The security token is missing or expired. Refresh the page and try again.');
        }
    }
}
