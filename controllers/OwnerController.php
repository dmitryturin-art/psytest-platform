<?php

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\InvitedCasePresenter;
use PsyTest\Core\OwnerDashboardAuthenticator;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\Security;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\TestInviteService;
use PsyTest\Core\TherapistCaseService;

/**
 * Small, single-owner dashboard for explicit therapist-case lifecycle work.
 *
 * It intentionally has no visitor accounts, report editor, payment controls
 * or client list. Client result tokens are accepted only in POST lookup input.
 */
final class OwnerController extends BaseController
{
    private OwnerDashboardAuthenticator $authenticator;
    private TherapistCaseService $cases;
    private TestInviteService $invites;
    private bool $isProduction;
    private string $appUrl;

    public function __construct()
    {
        parent::__construct();

        $config = require dirname(__DIR__) . '/config.php';
        $this->isProduction = $config->isProduction();
        $this->authenticator = new OwnerDashboardAuthenticator(
            $this->db,
            $config->ownerDashboardPasswordHash(),
            $config->ownerDashboardSessionTtlSeconds(),
            $config->ownerDashboardLoginMaxAttempts(),
            $config->ownerDashboardLoginWindowSeconds(),
        );
        $this->cases = new TherapistCaseService(
            $this->db,
            new SessionLifecycleService(
                $this->db,
                new RetentionPolicy($config->anonymousRetentionDays()),
                $config->pdfStoragePath(),
            ),
        );
        $this->invites = new TestInviteService($this->db, $this->sessionManager);
        $this->appUrl = $config->appUrl();
    }

    public function login(): void
    {
        if (!$this->isDashboardAvailable()) {
            return;
        }
        if ($this->authenticator->isAuthenticated()) {
            $this->redirect('/admin');
        }

        echo $this->view->render('owner-login');
    }

    public function authenticate(): void
    {
        if (!$this->isDashboardAvailable()) {
            return;
        }

        $password = $_POST['password'] ?? '';
        if (is_string($password) && $this->authenticator->authenticate($password)) {
            $this->redirect('/admin');
        }

        http_response_code(422);
        echo $this->view->render('owner-login', [
            'error' => 'Не удалось выполнить вход. Проверьте пароль или повторите попытку позже.',
        ]);
    }

    public function logout(): void
    {
        if (!$this->isDashboardAvailable()) {
            return;
        }
        if ($this->authenticator->isAuthenticated()) {
            $this->authenticator->logout();
        }

        $this->redirect('/admin/login');
    }

    public function dashboard(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        echo $this->view->render('owner-dashboard', [
            'flash' => $this->takeFlash(),
            'invite_tests' => array_values($this->moduleLoader->getActiveModules()),
            'invites' => $this->invites->recentForOwner(),
        ]);
    }

    public function createInvite(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $testId = filter_var($_POST['test_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $note = $_POST['owner_note'] ?? '';
        $availableIds = array_map(
            static fn (array $test): int => (int) $test['id'],
            $this->moduleLoader->getActiveModules(),
        );
        if ($testId === false || !in_array($testId, $availableIds, true) || !is_string($note) || mb_strlen(trim($note)) > 1000) {
            $this->setFlash(['type' => 'error', 'message' => 'Не удалось создать приглашение: выберите поддерживаемую методику и сократите заметку до 1000 символов.']);
            $this->redirect('/admin');
        }

        $invite = $this->invites->create($testId, trim($note));
        $this->setFlash([
            'type' => 'success',
            'message' => 'Одноразовое приглашение создано. Скопируйте ссылку сейчас: повторно она в кабинете не показывается.',
            'invite_url' => $this->appUrl . '/invite/' . $invite['token'],
        ]);
        $this->redirect('/admin');
    }

    public function revokeInvite(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $inviteId = $_POST['invite_id'] ?? '';
        $revoked = is_string($inviteId) && Security::isValidUuid($inviteId) && $this->invites->revoke($inviteId);
        $this->setFlash($revoked
            ? ['type' => 'success', 'message' => 'Неоткрытое приглашение отозвано.']
            : ['type' => 'error', 'message' => 'Отозвать можно только неоткрытое приглашение.']);
        $this->redirect('/admin');
    }

    public function viewInvitedCase(string $sessionId): void
    {
        if (!$this->requireOwner()) {
            return;
        }
        if (!Security::isValidUuid($sessionId)) {
            $this->notFound();

            return;
        }

        $case = $this->invites->claimedCaseForOwner($sessionId);
        if ($case === null) {
            $this->notFound();

            return;
        }
        $module = $this->moduleLoader->getModule((string) $case['test_slug']);
        if ($module === null) {
            $this->notFound();

            return;
        }
        $case['answer_rows'] = (new InvitedCasePresenter())->answers($module, $case['answers']);
        $case['result_sections'] = $module->buildSections($case['calculated_results']);

        echo $this->view->render('owner-invited-case', ['case' => $case]);
    }

    public function lookupCase(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $input = $_POST['result_reference'] ?? '';
        $token = is_string($input) ? $this->extractResultToken($input) : null;
        $case = $token === null ? null : $this->cases->lookupByResultToken($token);

        echo $this->view->render('owner-dashboard', [
            'case' => $case,
            'lookup_error' => $case === null ? 'Сессия не найдена или уже удалена.' : null,
        ]);
    }

    public function assignCase(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $sessionId = $_POST['session_id'] ?? '';
        $assigned = is_string($sessionId)
            && Security::isValidUuid($sessionId)
            && $this->cases->assignCompletedSession($sessionId);

        $this->setFlash($assigned
            ? ['type' => 'success', 'message' => 'Сессия переведена в режим клиента терапевта. Автоматическая очистка через 180 дней к ней больше не применяется.']
            : ['type' => 'error', 'message' => 'Не удалось назначить кейс: доступна только завершённая анонимная сессия.']);
        $this->redirect('/admin');
    }

    public function deleteCase(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $sessionId = $_POST['session_id'] ?? '';
        $confirmed = ($_POST['confirm_delete'] ?? null) === 'delete';
        $deleted = $confirmed
            && is_string($sessionId)
            && Security::isValidUuid($sessionId)
            && $this->cases->deleteAssignedCase($sessionId);

        $this->setFlash($deleted
            ? ['type' => 'success', 'message' => 'Кейс и известные связанные файлы удалены без возможности восстановления.']
            : ['type' => 'error', 'message' => 'Удаление не выполнено. Проверьте подтверждение и попробуйте найти кейс заново.']);
        $this->redirect('/admin');
    }

    private function requireOwner(): bool
    {
        if (!$this->isDashboardAvailable()) {
            return false;
        }
        if (!$this->authenticator->isAuthenticated()) {
            $this->redirect('/admin/login');
        }

        header('Cache-Control: no-store, private');

        return true;
    }

    private function isDashboardAvailable(): bool
    {
        if (!$this->authenticator->isConfigured()) {
            $this->notFound();

            return false;
        }
        if ($this->isProduction && !Security::isHttps()) {
            http_response_code(403);
            echo 'HTTPS is required for this endpoint.';

            return false;
        }

        return true;
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

    /** @return array{type: string, message: string, invite_url?: string}|null */
    private function takeFlash(): ?array
    {
        $flash = $_SESSION['psytest_owner_dashboard_flash'] ?? null;
        unset($_SESSION['psytest_owner_dashboard_flash']);

        return is_array($flash)
            && isset($flash['type'], $flash['message'])
            && is_string($flash['type'])
            && is_string($flash['message'])
            ? $flash
            : null;
    }

    /** @param array{type: string, message: string, invite_url?: string} $flash */
    private function setFlash(array $flash): void
    {
        $_SESSION['psytest_owner_dashboard_flash'] = $flash;
    }

    private function extractResultToken(string $reference): ?string
    {
        $reference = trim($reference);
        if (preg_match('/\A[a-f0-9]{64}\z/i', $reference) === 1) {
            return $reference;
        }

        $path = parse_url($reference, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $token = end($segments);

        return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1
            ? $token
            : null;
    }
}
