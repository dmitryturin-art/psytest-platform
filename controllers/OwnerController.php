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
use PsyTest\Core\TherapistClientService;

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
    private TherapistClientService $clients;
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
        $lifecycle = new SessionLifecycleService(
            $this->db,
            new RetentionPolicy($config->anonymousRetentionDays()),
            $config->pdfStoragePath(),
        );
        $this->cases = new TherapistCaseService($this->db, $lifecycle);
        $this->clients = new TherapistClientService($this->db, $lifecycle);
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
            'clients' => $this->clients->listForOwner(),
        ]);
    }

    public function clients(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        echo $this->view->render('owner-clients', [
            'flash' => $this->takeFlash(),
            'clients' => $this->clients->listForOwner(),
        ]);
    }

    public function createClient(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $label = $_POST['label'] ?? '';
        $note = $_POST['note'] ?? '';
        if (!$this->isValidClientInput($label, $note)) {
            $this->setFlash(['type' => 'error', 'message' => 'Не удалось создать карточку: подпись обязательна (до 120 символов), заметка — до 1000 символов.']);
            $this->redirect('/admin/clients');
        }

        $clientId = $this->clients->create((string) $label, (string) $note);
        $this->setFlash(['type' => 'success', 'message' => 'Карточка клиента создана.']);
        $this->redirect('/admin/clients/' . $clientId);
    }

    public function viewClient(string $clientId): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $card = Security::isValidUuid($clientId) ? $this->clients->findForOwner($clientId) : null;
        if ($card === null) {
            $this->notFound();

            return;
        }

        echo $this->view->render('owner-client', [
            'flash' => $this->takeFlash(),
            'client' => $card['client'],
            'assignments' => $card['assignments'],
            'history' => $card['history'],
            'invite_tests' => array_values($this->moduleLoader->getActiveModules()),
        ]);
    }

    public function updateClient(string $clientId): void
    {
        if (!$this->requireOwner()) {
            return;
        }
        if (!Security::isValidUuid($clientId)) {
            $this->notFound();

            return;
        }

        $label = $_POST['label'] ?? '';
        $note = $_POST['note'] ?? '';
        $updated = $this->isValidClientInput($label, $note)
            && $this->clients->update($clientId, (string) $label, (string) $note);

        $this->setFlash($updated
            ? ['type' => 'success', 'message' => 'Карточка клиента обновлена.']
            : ['type' => 'error', 'message' => 'Не удалось обновить карточку: подпись обязательна (до 120 символов), заметка — до 1000 символов.']);
        $this->redirect('/admin/clients/' . $clientId);
    }

    public function createClientInvite(string $clientId): void
    {
        if (!$this->requireOwner()) {
            return;
        }
        if (!Security::isValidUuid($clientId) || !$this->clients->exists($clientId)) {
            $this->notFound();

            return;
        }

        $testId = $this->validTestId($_POST['test_id'] ?? null);
        $note = $_POST['owner_note'] ?? '';
        if ($testId === null || !is_string($note) || mb_strlen(trim($note)) > 1000) {
            $this->setFlash(['type' => 'error', 'message' => 'Не удалось создать назначение: выберите поддерживаемую методику и сократите заметку до 1000 символов.']);
            $this->redirect('/admin/clients/' . $clientId);
        }

        $invite = $this->invites->create($testId, trim($note), $clientId);
        $this->setFlash([
            'type' => 'success',
            'message' => 'Назначение создано. Скопируйте ссылку сейчас: повторно она в кабинете не показывается.',
            'invite_url' => $this->appUrl . '/invite/' . $invite['token'],
        ]);
        $this->redirect('/admin/clients/' . $clientId);
    }

    public function deleteClient(string $clientId): void
    {
        if (!$this->requireOwner()) {
            return;
        }
        if (!Security::isValidUuid($clientId)) {
            $this->notFound();

            return;
        }

        $confirmed = ($_POST['confirm_delete'] ?? null) === 'delete';
        if (!$confirmed || !$this->clients->delete($clientId)) {
            $this->setFlash(['type' => 'error', 'message' => 'Удаление не выполнено. Подтвердите удаление галочкой и попробуйте ещё раз.']);
            $this->redirect('/admin/clients/' . $clientId);
        }

        $this->setFlash(['type' => 'success', 'message' => 'Карточка клиента, её назначения, результаты и файлы удалены без возможности восстановления.']);
        $this->redirect('/admin/clients');
    }

    public function deleteInvitedCase(string $sessionId): void
    {
        if (!$this->requireOwner()) {
            return;
        }
        if (!Security::isValidUuid($sessionId)) {
            $this->notFound();

            return;
        }

        $clientId = $_POST['client_id'] ?? '';
        $confirmed = ($_POST['confirm_delete'] ?? null) === 'delete';
        $deleted = $confirmed && $this->cases->deleteAssignedCase($sessionId);

        $this->setFlash($deleted
            ? ['type' => 'success', 'message' => 'Кейс, его заметка и известные связанные файлы удалены без возможности восстановления.']
            : ['type' => 'error', 'message' => 'Удаление не выполнено. Подтвердите удаление галочкой и откройте кейс заново.']);

        $this->redirect(is_string($clientId) && Security::isValidUuid($clientId)
            ? '/admin/clients/' . $clientId
            : '/admin');
    }

    public function createInvite(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $testId = $this->validTestId($_POST['test_id'] ?? null);
        $note = $_POST['owner_note'] ?? '';
        $rawClientId = $_POST['client_id'] ?? '';
        $clientId = is_string($rawClientId) && $rawClientId !== '' ? $rawClientId : null;
        $clientIsValid = $clientId === null
            || (Security::isValidUuid($clientId) && $this->clients->exists($clientId));
        if ($testId === null || !$clientIsValid || !is_string($note) || mb_strlen(trim($note)) > 1000) {
            $this->setFlash(['type' => 'error', 'message' => 'Не удалось создать приглашение: выберите поддерживаемую методику, существующую карточку клиента и сократите заметку до 1000 символов.']);
            $this->redirect('/admin');
        }

        $invite = $this->invites->create($testId, trim($note), $clientId);
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
        $presenter = new InvitedCasePresenter();
        $case['answer_rows'] = $presenter->answers($module, $case['answers']);
        $case['result_sections'] = $presenter->resultSections($module, $case['calculated_results']);

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

    private function validTestId(mixed $rawTestId): ?int
    {
        $testId = filter_var($rawTestId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $availableIds = array_map(
            static fn (array $test): int => (int) $test['id'],
            $this->moduleLoader->getActiveModules(),
        );

        return is_int($testId) && in_array($testId, $availableIds, true) ? $testId : null;
    }

    private function isValidClientInput(mixed $label, mixed $note): bool
    {
        return is_string($label)
            && is_string($note)
            && trim($label) !== ''
            && mb_strlen(trim($label)) <= TherapistClientService::LABEL_MAX_LENGTH
            && mb_strlen(trim($note)) <= TherapistClientService::NOTE_MAX_LENGTH;
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
