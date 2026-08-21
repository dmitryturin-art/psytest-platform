<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Authentication for the single owner dashboard.
 *
 * There is intentionally no public registration, password reset, user list or
 * clinical identity stored here. The dashboard is disabled until deployment
 * config supplies an Argon2id password hash.
 */
final class OwnerDashboardAuthenticator
{
    private const SESSION_KEY = 'psytest_owner_dashboard_authenticated_at';

    public function __construct(
        private readonly Database $db,
        private readonly string $passwordHash,
        private readonly int $sessionTtlSeconds,
        private readonly int $maxAttempts,
        private readonly int $rateLimitWindowSeconds,
    ) {
    }

    public function isConfigured(): bool
    {
        $info = password_get_info($this->passwordHash);

        return $this->passwordHash !== '' && ($info['algoName'] ?? null) === 'argon2id';
    }

    public function isAuthenticated(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        self::startSession();
        $authenticatedAt = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_int($authenticatedAt) || $authenticatedAt + $this->sessionTtlSeconds < time()) {
            unset($_SESSION[self::SESSION_KEY]);

            return false;
        }

        return true;
    }

    public function authenticate(string $password): bool
    {
        self::startSession();
        if (!$this->isConfigured() || $this->isRateLimited()) {
            return false;
        }

        $valid = password_verify($password, $this->passwordHash);
        $this->recordAttempt($valid);
        if (!$valid) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = time();

        return true;
    }

    public function logout(): void
    {
        self::startSession();
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    private function isRateLimited(): bool
    {
        $cutoff = date('Y-m-d H:i:s', time() - $this->rateLimitWindowSeconds);
        $this->db->delete('owner_login_attempts', 'attempted_at < ?', [$cutoff]);
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS count FROM owner_login_attempts WHERE was_successful = 0 AND attempted_at >= ?',
            [$cutoff],
        );

        return (int) ($row['count'] ?? 0) >= $this->maxAttempts;
    }

    private function recordAttempt(bool $successful): void
    {
        $this->db->insert('owner_login_attempts', [
            'was_successful' => $successful ? 1 : 0,
        ]);
    }

    private static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'secure' => Security::isHttps(),
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}
