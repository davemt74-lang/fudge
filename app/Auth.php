<?php
final class Auth
{
    public function __construct(private Database $db) {}

    public function attempt(string $email, string $password): bool
    {
        $user = $this->db->one('SELECT * FROM users WHERE email = ? AND status = "active" LIMIT 1', [strtolower(trim($email))]);
        if (!$user || !password_verify($password, $user['password_hash'])) return false;
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        unset($_SESSION['csrf']);
        Security::csrfToken();
        $this->db->exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        audit('auth.login', 'user', (int)$user['id'], null, null);
        return true;
    }

    public function logout(): void
    {
        if (!empty($_SESSION['user_id'])) audit('auth.logout', 'user', (int)$_SESSION['user_id'], null, null);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function user(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        static $cached = null;
        if ($cached !== null) return $cached;
        $cached = $this->db->one('SELECT id, name, email, status, job_title, avatar_url FROM users WHERE id = ? AND status = "active"', [(int)$_SESSION['user_id']]);
        if (!$cached) {
            unset($_SESSION['user_id'], $_SESSION['csrf']);
            return null;
        }
        return $cached;
    }

    public function requireLogin(): void
    {
        if (!$this->user()) redirect('?page=login');
    }
}
