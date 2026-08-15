<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\CsrfMiddleware;
use PsyTest\Core\Router;
use PsyTest\Core\Security;

final class CsrfMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        http_response_code(200);
    }

    public function testRejectsMissingTokenForStateChangingRoute(): void
    {
        $response = $this->dispatchProtectedPost();

        self::assertSame(403, http_response_code());
        self::assertSame(['success' => false, 'error' => 'Invalid CSRF token'], json_decode($response, true));
    }

    public function testRejectsInvalidTokenForStateChangingRoute(): void
    {
        Security::generateCsrfToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'invalid';

        $response = $this->dispatchProtectedPost();

        self::assertSame(403, http_response_code());
        self::assertSame(['success' => false, 'error' => 'Invalid CSRF token'], json_decode($response, true));
    }

    public function testAllowsValidHeaderTokenForJsonRequest(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Security::generateCsrfToken();

        $response = $this->dispatchProtectedPost();

        self::assertSame(200, http_response_code());
        self::assertSame('accepted', $response);
    }

    public function testAllowsValidFormToken(): void
    {
        $_POST['csrf_token'] = Security::generateCsrfToken();

        $response = $this->dispatchProtectedPost();

        self::assertSame(200, http_response_code());
        self::assertSame('accepted', $response);
    }

    public function testAllowsReusingValidSessionTokenAcrossRequests(): void
    {
        $token = Security::generateCsrfToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        self::assertSame('accepted', $this->dispatchProtectedPost());
        self::assertSame('accepted', $this->dispatchProtectedPost());
        self::assertSame(200, http_response_code());
    }

    public function testAllowsExplicitRetiredWebhookException(): void
    {
        $router = new Router();
        $router->middleware(new CsrfMiddleware(['/webhook/yoomoney']));
        $router->post('/webhook/yoomoney', static fn (): string => 'retired');

        self::assertSame('retired', $router->dispatch('/webhook/yoomoney', 'POST'));
        self::assertSame(200, http_response_code());
    }

    public function testAjaxAndDeleteClientsSendTheCsrfHeader(): void
    {
        $projectRoot = dirname(__DIR__);

        self::assertStringContainsString('csrfToken:', (string) file_get_contents($projectRoot . '/templates/test-wrapper.twig'));
        self::assertStringContainsString("'X-CSRF-Token': TEST_CONFIG.csrfToken", (string) file_get_contents($projectRoot . '/public/js/test-taking.js'));
        self::assertStringContainsString("'X-CSRF-Token': '{{ csrf_token }}'", (string) file_get_contents($projectRoot . '/templates/result-layout.twig'));
        self::assertStringContainsString("'X-CSRF-Token': '{{ csrf_token }}'", (string) file_get_contents($projectRoot . '/templates/result-page.twig'));
    }

    private function dispatchProtectedPost(): string
    {
        $router = new Router();
        $router->middleware(new CsrfMiddleware());
        $router->post('/mutate', static fn (): string => 'accepted');

        return $router->dispatch('/mutate', 'POST');
    }
}
