<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Controllers\AccountController;

final class VisitorAccountContractTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__);
    }

    public function testCabinetTemplatesNeverRenderTheBearerResultToken(): void
    {
        foreach (glob($this->projectRoot . '/templates/account-*.twig') ?: [] as $template) {
            $contents = (string) file_get_contents($template);

            self::assertStringNotContainsString('session_token', $contents, basename($template));
            self::assertStringNotContainsString('result_token', $contents, basename($template));
        }
    }

    public function testEveryAccountStateChangeGoesThroughTheGlobalCsrfMiddleware(): void
    {
        $routes = (string) file_get_contents($this->projectRoot . '/public/index.php');

        foreach ([
            "\$router->get('/account/login'",
            "\$router->post('/account/login'",
            "\$router->get('/account/login/{token}'",
            "\$router->post('/account/login/{token}'",
            "\$router->post('/account/logout'",
            "\$router->get('/account'",
            "\$router->get('/account/results/{sessionId}'",
            "\$router->get('/account/results/{sessionId}/pdf'",
            "\$router->post('/account/results/{sessionId}/detach'",
            "\$router->post('/account/attach'",
            "\$router->post('/account/delete'",
        ] as $route) {
            self::assertStringContainsString($route, $routes, $route);
        }

        // Единственное исключение из CSRF — retired webhook; кабинет в список
        // исключений не попадает и не должен туда попасть.
        self::assertStringContainsString("new CsrfMiddleware(['/webhook/yoomoney'])", $routes);

        foreach (glob($this->projectRoot . '/templates/account-*.twig') ?: [] as $template) {
            $contents = (string) file_get_contents($template);
            if (!str_contains($contents, '<form method="post"')) {
                continue;
            }

            self::assertStringContainsString('csrf_field()', $contents, basename($template));
        }
    }

    /**
     * Ссылка входа погашается только нажатой кнопкой.
     *
     * Почтовые сканеры и превью-боты префетчат GET-ссылки из письма: если бы
     * вход происходил на GET, одноразовый токен сгорал бы до самого посетителя.
     */
    public function testOpeningTheMailedLinkOnlyShowsAConfirmationAndNeverConsumesIt(): void
    {
        $routes = (string) file_get_contents($this->projectRoot . '/public/index.php');
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/AccountController.php');
        $confirm = (string) file_get_contents($this->projectRoot . '/templates/account-login-confirm.twig');

        self::assertStringContainsString(
            "\$router->get('/account/login/{token}', [AccountController::class, 'loginConfirm']);",
            $routes,
        );
        self::assertStringContainsString(
            "\$router->post('/account/login/{token}', [AccountController::class, 'login']);",
            $routes,
        );

        $confirmAction = self::methodBody($controller, 'loginConfirm');
        self::assertStringNotContainsString('consumeLogin', $confirmAction, 'GET не погашает ссылку.');
        self::assertStringContainsString('isLoginTokenFormat', $confirmAction);
        self::assertStringContainsString('account-login-invalid', $confirmAction);

        self::assertStringContainsString('consumeLogin', self::methodBody($controller, 'login'));

        self::assertStringContainsString('method="post"', $confirm);
        self::assertStringContainsString('/account/login/{{ token }}', $confirm);
        self::assertStringContainsString('csrf_field()', $confirm);
        self::assertStringContainsString('Войти в кабинет', $confirm);
        self::assertStringContainsString('Ссылка одноразовая и действует 15 минут', $confirm);
    }

    public function testCabinetPagesAreNeitherCachedNorIndexed(): void
    {
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/AccountController.php');

        self::assertStringContainsString("header('Cache-Control: no-store, private')", $controller);
        self::assertStringContainsString("header('X-Robots-Tag: noindex')", $controller);
    }

    public function testDeletingAnAccountAlwaysNeedsAnExplicitConfirmation(): void
    {
        $history = (string) file_get_contents($this->projectRoot . '/templates/account-history.twig');
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/AccountController.php');

        self::assertStringContainsString('name="confirm_delete" value="delete" required', $history);
        self::assertStringContainsString("(\$_POST['confirm_delete'] ?? null) !== 'delete'", $controller);
    }

    /**
     * Возврат после входа — только на страницу результата этого же сайта.
     */
    public function testReturnPathAcceptsOnlyARelativeResultPath(): void
    {
        $token = str_repeat('a', 64);

        self::assertSame('/result/bdi/' . $token, AccountController::safeReturnPath('/result/bdi/' . $token));
        self::assertNull(AccountController::safeReturnPath('https://evil.test/result/bdi/' . $token));
        self::assertNull(AccountController::safeReturnPath('//evil.test/result/bdi/' . $token));
        self::assertNull(AccountController::safeReturnPath('/admin'));
        self::assertNull(AccountController::safeReturnPath('/result/bdi/short'));
        self::assertNull(AccountController::safeReturnPath(null));
    }

    /**
     * Тело метода контроллера от его объявления до следующего объявления.
     */
    private static function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'public function ' . $method . '(');
        self::assertIsInt($start, 'Метод ' . $method . ' не найден.');

        $next = strpos($source, "\n    public function ", $start + 1);

        return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
    }

    public function testVisitorAndOwnerSessionsStayIndependent(): void
    {
        $visitor = (string) file_get_contents($this->projectRoot . '/core/VisitorAccountSession.php');
        $accountController = (string) file_get_contents($this->projectRoot . '/controllers/AccountController.php');

        self::assertStringContainsString("'psytest_visitor_account_id'", $visitor);
        self::assertStringContainsString('session_regenerate_id(true)', $visitor);
        // Ключ owner-сессии не должен читаться или записываться кабинетом
        // посетителя ни при каких условиях.
        self::assertStringNotContainsString('psytest_owner_dashboard', $visitor);
        self::assertStringNotContainsString('psytest_owner_dashboard', $accountController);
        self::assertStringNotContainsString('OwnerDashboardAuthenticator', $accountController);

        foreach (glob($this->projectRoot . '/templates/owner-*.twig') ?: [] as $template) {
            self::assertStringContainsString(
                '{% set ownerArea = true %}',
                (string) file_get_contents($template),
                basename($template),
            );
        }
    }
}
