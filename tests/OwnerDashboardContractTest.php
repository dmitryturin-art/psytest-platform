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
        self::assertStringContainsString("\$router->post('/admin/invites/create'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invites/revoke'", $routes);
        self::assertStringContainsString("\$router->get('/admin/invited-case/{sessionId}'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/delete'", $routes);
        self::assertStringContainsString("\$router->get('/admin/clients'", $routes);
        self::assertStringContainsString("\$router->post('/admin/clients/create'", $routes);
        self::assertStringContainsString("\$router->get('/admin/clients/{clientId}'", $routes);
        self::assertStringContainsString("\$router->post('/admin/clients/{clientId}/update'", $routes);
        self::assertStringContainsString("\$router->post('/admin/clients/{clientId}/invites/create'", $routes);
        self::assertStringContainsString("\$router->post('/admin/clients/{clientId}/delete'", $routes);
        self::assertStringContainsString("\$router->post('/invite/{token}/start'", $routes);
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
        self::assertStringContainsString('name="owner_note"', $template);
        self::assertStringNotContainsString('invite.token', $template);
    }

    public function testInvitedCaseRendersReadableDataInsteadOfStoredJson(): void
    {
        $template = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');

        self::assertStringContainsString('case.result_sections', $template);
        self::assertStringContainsString('case.answer_rows', $template);
        self::assertStringNotContainsString('answers_json', $template);
        self::assertStringNotContainsString('results_json', $template);
        self::assertStringContainsString('InvitedCasePresenter', $controller);
    }

    public function testClientCardsStayInsideTheDashboardAndAlwaysConfirmDeletion(): void
    {
        $clientsList = (string) file_get_contents($this->projectRoot . '/templates/owner-clients.twig');
        $clientCard = (string) file_get_contents($this->projectRoot . '/templates/owner-client.twig');
        $invitedCase = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');

        foreach ([$clientsList, $clientCard, $invitedCase] as $template) {
            self::assertStringContainsString('name="csrf_token"', $template);
            self::assertStringNotContainsString('session_token', $template);
            self::assertStringNotContainsString('invite.token', $template);
        }

        self::assertStringContainsString('name="confirm_delete" value="delete" required', $clientCard);
        self::assertStringContainsString('name="confirm_delete" value="delete" required', $invitedCase);
        self::assertStringContainsString('name="label"', $clientsList);
        self::assertStringContainsString('name="client_id"', (string) file_get_contents($this->projectRoot . '/templates/owner-dashboard.twig'));
    }

    public function testClientLabelNeverReachesThePublicInvitePageOrAnAiContext(): void
    {
        $respondentPage = (string) file_get_contents($this->projectRoot . '/templates/test-invite-start.twig');
        $presenter = (string) file_get_contents($this->projectRoot . '/core/InvitedCasePresenter.php');

        foreach (['client_label', 'client_id', 'owner_note', 'therapist_clients'] as $ownerOnlyField) {
            self::assertStringNotContainsString($ownerOnlyField, $respondentPage, $ownerOnlyField);
            self::assertStringNotContainsString($ownerOnlyField, $presenter, $ownerOnlyField);
        }

        foreach (glob($this->projectRoot . '/core/Ai/*.php') ?: [] as $aiSource) {
            self::assertStringNotContainsString('therapist_clients', (string) file_get_contents($aiSource), $aiSource);
            self::assertStringNotContainsString('client_label', (string) file_get_contents($aiSource), $aiSource);
        }
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
