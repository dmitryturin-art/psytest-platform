<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\OwnerDashboardAuthenticator;

final class OwnerDashboardAuthenticatorTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->delete('owner_login_attempts', '1 = 1');
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    public function testRequiresArgon2idAndCreatesAnExpiringOwnerSession(): void
    {
        $bcrypt = new OwnerDashboardAuthenticator(
            $this->db,
            password_hash('secret', PASSWORD_BCRYPT),
            60,
            3,
            900,
        );
        self::assertFalse($bcrypt->isConfigured());
        self::assertFalse($bcrypt->authenticate('secret'));

        $authenticator = new OwnerDashboardAuthenticator(
            $this->db,
            password_hash('secret', PASSWORD_ARGON2ID),
            60,
            3,
            900,
        );
        self::assertTrue($authenticator->isConfigured());
        self::assertFalse($authenticator->isAuthenticated());
        self::assertTrue($authenticator->authenticate('secret'));
        self::assertTrue($authenticator->isAuthenticated());
        self::assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS count FROM owner_login_attempts WHERE was_successful = 1')['count']);
    }

    public function testAppliesGlobalFailedLoginLimitWithoutStoringSourceIdentity(): void
    {
        $authenticator = new OwnerDashboardAuthenticator(
            $this->db,
            password_hash('secret', PASSWORD_ARGON2ID),
            60,
            3,
            900,
        );

        self::assertFalse($authenticator->authenticate('wrong'));
        self::assertFalse($authenticator->authenticate('wrong'));
        self::assertFalse($authenticator->authenticate('wrong'));
        self::assertFalse($authenticator->authenticate('secret'));
        self::assertSame(3, (int) $this->db->selectOne('SELECT COUNT(*) AS count FROM owner_login_attempts WHERE was_successful = 0')['count']);
    }
}
