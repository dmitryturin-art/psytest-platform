<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class OwnerDashboardContractTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__);
    }

    public function testOwnerRoutesAreProtectedByTheExistingGlobalCsrfMiddleware(): void
    {
        $routes = (string) file_get_contents($this->projectRoot . '/public/index.php');
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');

        self::assertStringContainsString("\$router->post('/admin/login'", $routes);
        self::assertStringContainsString("\$router->post('/admin/case/assign'", $routes);
        self::assertStringContainsString("\$router->post('/admin/case/delete'", $routes);
        self::assertStringContainsString('CsrfMiddleware', $routes);
        self::assertStringContainsString('ownerDashboardPasswordHash()', (string) file_get_contents($this->projectRoot . '/config.php'));
        self::assertStringContainsString("'argon2id'", (string) file_get_contents($this->projectRoot . '/core/OwnerDashboardAuthenticator.php'));
        self::assertStringContainsString("Security::isHttps()", $controller);
        self::assertStringContainsString('Cache-Control: no-store, private', $controller);
    }

    public function testDashboardNeverRendersTheClientTokenAndRequiresDeleteConfirmation(): void
    {
        $template = (string) file_get_contents($this->projectRoot . '/templates/owner-dashboard.twig');

        self::assertStringNotContainsString('case.session_token', $template);
        self::assertStringContainsString('name="confirm_delete" value="delete" required', $template);
        self::assertStringContainsString('name="csrf_token"', $template);
        self::assertStringContainsString('name="result_reference"', $template);
    }

    public function testLoginAttemptMigrationContainsNoClientIdentifiers(): void
    {
        $migration = (string) file_get_contents($this->projectRoot . '/database/migrations/20260821010000_add_owner_dashboard_login_attempts.php');

        self::assertStringContainsString('CREATE TABLE owner_login_attempts', $migration);
        self::assertStringNotContainsString('ip_address', $migration);
        self::assertStringNotContainsString('user_agent', $migration);
        self::assertStringNotContainsString('session_id', $migration);
    }
}
