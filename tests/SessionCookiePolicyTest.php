<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Security;

final class SessionCookiePolicyTest extends TestCase
{
    private string|false $originalAppEnv;

    protected function setUp(): void
    {
        $this->originalAppEnv = getenv('APP_ENV');
        $this->closeSession();
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_SSL']);
    }

    protected function tearDown(): void
    {
        $this->closeSession();
        if ($this->originalAppEnv === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->originalAppEnv);
        }
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_SSL']);
    }

    public function testProductionSessionCookieFailsClosedWithoutProxyHttpsMetadata(): void
    {
        putenv('APP_ENV=production');

        Security::startSession();

        $params = session_get_cookie_params();
        self::assertTrue($params['secure']);
        self::assertTrue($params['httponly']);
        self::assertSame('Lax', $params['samesite']);
        self::assertSame('/', $params['path']);
    }

    public function testDevelopmentCookieIsSecureOnlyOnHttps(): void
    {
        putenv('APP_ENV=development');
        Security::startSession();
        self::assertFalse(session_get_cookie_params()['secure']);

        $this->closeSession();
        $_SERVER['HTTPS'] = 'on';
        Security::startSession();
        self::assertTrue(session_get_cookie_params()['secure']);
    }

    public function testSessionConsumersUseTheCentralPolicy(): void
    {
        $projectRoot = dirname(__DIR__);
        $view = (string) file_get_contents($projectRoot . '/core/View.php');
        $owner = (string) file_get_contents($projectRoot . '/core/OwnerDashboardAuthenticator.php');

        self::assertStringContainsString('Security::startSession();', $view);
        self::assertStringContainsString('Security::startSession();', $owner);
        self::assertStringNotContainsString('session_start()', $view);
        self::assertStringNotContainsString('session_start()', $owner);
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }
        session_id('');
    }
}
