<?php

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\PDFGenerator;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\ResultSectionRenderer;
use PsyTest\Core\Security;
use PsyTest\Core\VisitorAccountService;
use PsyTest\Core\VisitorAccountSession;

/**
 * Добровольный кабинет посетителя.
 *
 * Кабинет не обязателен и ничего не добавляет к прохождению теста: он только
 * собирает в одном месте результаты, которые посетитель сам туда положил
 * (PRODUCT_RULES §12, D-053). Ни одна страница здесь не выводит bearer-токен
 * результата — доступ в кабинете держится на владении аккаунтом.
 */
final class AccountController extends BaseController
{
    private VisitorAccountService $accounts;
    private ResultPresenter $presenter;

    public function __construct()
    {
        parent::__construct();
        $this->accounts = VisitorAccountService::fromConfig($this->db);
        $this->presenter = new ResultPresenter($this->db, $this->sessionManager);
    }

    public function loginForm(): void
    {
        $this->privateHeaders();
        if ($this->currentAccount() !== null) {
            $this->redirect('/account');
        }

        $returnTo = self::safeReturnPath($_GET['return'] ?? null);
        $this->rememberReturn($returnTo);

        echo $this->view->render('account-login', ['return_to' => $returnTo]);
    }

    /**
     * Запрос ссылки входа.
     *
     * Ответ один и тот же для любого ввода: иначе форма отвечала бы на вопрос
     * «есть ли у вас аккаунт с таким адресом» кому угодно.
     */
    public function requestLogin(): void
    {
        $this->privateHeaders();

        $this->rememberReturn(self::safeReturnPath($_POST['return'] ?? null));

        $email = $_POST['email'] ?? '';
        if (is_string($email)) {
            $this->accounts->requestLogin($email);
        }

        echo $this->view->render('account-login-sent');
    }

    /**
     * Страница подтверждения входа.
     *
     * GET ничего не погашает намеренно: почтовые сканеры и превью-боты
     * открывают ссылки из письма раньше человека, и одноразовый токен сгорал
     * бы до того, как посетитель до него дойдёт. Вход происходит только по
     * явной отправке формы ниже.
     */
    public function loginConfirm(string $token): void
    {
        $this->privateHeaders();

        if (!VisitorAccountService::isLoginTokenFormat($token)) {
            http_response_code(404);
            echo $this->view->render('account-login-invalid');

            return;
        }

        echo $this->view->render('account-login-confirm', ['token' => $token]);
    }

    /**
     * Вход по нажатой кнопке подтверждения.
     *
     * Ссылка погашается здесь, а не в GET: POST не выполняется префетчем
     * почтового клиента и защищён общим CSRF-middleware.
     */
    public function login(string $token): void
    {
        $this->privateHeaders();

        $account = $this->accounts->consumeLogin($token);
        if ($account === null) {
            http_response_code(404);
            echo $this->view->render('account-login-invalid');

            return;
        }

        // Куда вернуться, помнит серверная сессия того же браузера: путь
        // результата содержит его bearer-токен, и в ссылке письма ему не место
        // — письмо живёт в почтовом ящике дольше и копируется свободнее.
        $returnTo = $this->takeReturn();
        VisitorAccountSession::login($account['id'], $account['email']);
        $this->redirect($returnTo ?? '/account');
    }

    public function logout(): void
    {
        VisitorAccountSession::logout();
        $this->redirect('/');
    }

    public function index(): void
    {
        $account = $this->requireAccount();

        echo $this->view->render('account-history', [
            'account' => $account,
            'history' => $this->accounts->history($account['id']),
            'flash' => $this->takeFlash(),
        ]);
    }

    public function showResult(string $sessionId): void
    {
        $account = $this->requireAccount();

        [$session, $test, $module] = $this->ownedResult($account['id'], $sessionId);
        if ($session === null || $test === null || $module === null) {
            return;
        }

        echo $this->view->render('result-layout', $this->presenter->viewData(
            $session,
            $test,
            $module,
            '/account/results/' . $sessionId,
            true,
        ) + ['visitor_account' => $account]);
    }

    public function resultPdf(string $sessionId): void
    {
        $account = $this->requireAccount();

        [$session, $test, $module] = $this->ownedResult($account['id'], $sessionId);
        if ($session === null || $test === null || $module === null) {
            return;
        }

        $printable = $this->presenter->pdfSections($session, $module);
        $pdfPath = (new PDFGenerator())->generateTestResult(
            $session,
            $test,
            ResultSectionRenderer::forView($this->view)->renderToHtml($printable['sections'])
                . $printable['published_report_html'],
            $printable['includes_pair_comparison'],
        );

        $fullPath = dirname(__DIR__) . $pdfPath;
        if (!file_exists($fullPath)) {
            http_response_code(500);
            echo 'PDF generation failed';

            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="result_' . $test['slug'] . '_' . date('YmdHis') . '.pdf"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    /**
     * Сохранить результат в кабинет.
     *
     * Токен результата приходит из формы на самой странице результата: связь
     * возникает только когда посетитель одновременно держит ссылку и вошёл в
     * кабинет. Автоматической привязки по email, cookie или IP нет.
     */
    public function attach(): void
    {
        $account = $this->requireAccount();

        $sessionId = $_POST['session_id'] ?? '';
        $resultToken = $_POST['result_token'] ?? '';
        $returnTo = self::safeReturnPath($_POST['return'] ?? null);

        $attached = is_string($sessionId)
            && is_string($resultToken)
            && $this->accounts->attach($account['id'], $sessionId, $resultToken);

        $this->setFlash($attached
            ? ['type' => 'success', 'message' => 'Результат сохранён в вашем кабинете.']
            : ['type' => 'error', 'message' => 'Не удалось сохранить результат. Откройте страницу результата по своей ссылке и попробуйте снова.']);

        // При неудаче возврат идёт в кабинет: только там показывается причина,
        // а страница результата молча выглядела бы так, будто кнопка не нажата.
        $this->redirect($attached ? ($returnTo ?? '/account') : '/account');
    }

    public function detach(string $sessionId): void
    {
        $account = $this->requireAccount();

        $detached = $this->accounts->detach($account['id'], $sessionId);
        $this->setFlash($detached
            ? ['type' => 'success', 'message' => 'Результат отвязан от кабинета. Сам результат не удалён: он остаётся доступен по вашей ссылке и хранится обычные 180 дней с даты прохождения.']
            : ['type' => 'error', 'message' => 'Не удалось отвязать результат.']);

        $this->redirect('/account');
    }

    public function delete(): void
    {
        $account = $this->requireAccount();

        if (($_POST['confirm_delete'] ?? null) !== 'delete') {
            $this->setFlash(['type' => 'error', 'message' => 'Удаление не выполнено: подтвердите его галочкой.']);
            $this->redirect('/account');
        }

        $this->accounts->deleteAccount($account['id']);
        VisitorAccountSession::logout();

        echo $this->view->render('account-deleted');
    }

    private function rememberReturn(?string $returnTo): void
    {
        Security::startSession();
        if ($returnTo === null) {
            unset($_SESSION['psytest_visitor_account_return']);

            return;
        }

        $_SESSION['psytest_visitor_account_return'] = $returnTo;
    }

    private function takeReturn(): ?string
    {
        Security::startSession();
        $returnTo = $_SESSION['psytest_visitor_account_return'] ?? null;
        unset($_SESSION['psytest_visitor_account_return']);

        return self::safeReturnPath($returnTo);
    }

    /**
     * Результат кабинета вместе с методикой и модулем.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null, 2: \PsyTest\Modules\TestModuleInterface|null}
     */
    private function ownedResult(string $accountId, string $sessionId): array
    {
        $session = $this->accounts->findSessionForAccount($accountId, $sessionId);
        if ($session === null) {
            $this->notFound();

            return [null, null, null];
        }

        $test = $this->db->selectOne('SELECT * FROM tests WHERE id = ?', [$session['test_id']]);
        $module = $test === null ? null : $this->moduleLoader->getModule((string) $test['slug']);
        if ($test === null || $module === null) {
            $this->notFound();

            return [null, null, null];
        }

        return [$session, $test, $module];
    }

    /** @return array{id: string, email: string}|null */
    private function currentAccount(): ?array
    {
        $accountId = VisitorAccountSession::accountId();

        return $accountId === null ? null : $this->accounts->find($accountId);
    }

    /**
     * Кабинет доступен только вошедшему: иначе запрос уходит на форму входа и
     * до кода страницы не доходит.
     *
     * @return array{id: string, email: string}
     */
    private function requireAccount(): array
    {
        $this->privateHeaders();
        $account = $this->currentAccount();
        if ($account === null) {
            $this->redirect('/account/login');
        }

        return $account;
    }

    /**
     * Кабинет не кэшируется и не индексируется.
     *
     * История прохождений — клинические данные: общий кэш и поисковый индекс
     * для них закрыты (PRODUCT_RULES §11–12).
     */
    private function privateHeaders(): void
    {
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
    }

    /**
     * Возврат допускается только на страницу результата этого же сайта.
     *
     * Иначе ссылка «войти, чтобы сохранить» стала бы открытым редиректом:
     * после входа посетителя можно было бы увести на чужой адрес.
     */
    public static function safeReturnPath(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (!str_starts_with($raw, '/result/') || str_starts_with($raw, '//')) {
            return null;
        }
        if (preg_match('#\A/result/[a-z0-9-]{1,100}/[a-f0-9]{64}\z#i', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo $this->view->render('error-page');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    /** @return array{type: string, message: string}|null */
    private function takeFlash(): ?array
    {
        Security::startSession();
        $flash = $_SESSION['psytest_visitor_account_flash'] ?? null;
        unset($_SESSION['psytest_visitor_account_flash']);

        return is_array($flash)
            && isset($flash['type'], $flash['message'])
            && is_string($flash['type'])
            && is_string($flash['message'])
            ? ['type' => $flash['type'], 'message' => $flash['message']]
            : null;
    }

    /** @param array{type: string, message: string} $flash */
    private function setFlash(array $flash): void
    {
        Security::startSession();
        $_SESSION['psytest_visitor_account_flash'] = $flash;
    }
}
