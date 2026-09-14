<?php

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\Ai\AiProviderException;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiReportRevisionService;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\InvitedCasePresenter;
use PsyTest\Core\OwnerDashboardAuthenticator;
use PsyTest\Core\ReportMarkdown;
use PsyTest\Core\ResultPresenter;
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
    /** Клинический контекст для модели: короткая справка специалиста, а не файл истории болезни. */
    public const OWNER_CONTEXT_MAX_LENGTH = 4000;

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
        if (!Security::isValidUuid($clientId) || !$this->clients->exists($clientId)) {
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
        if (!Security::isValidUuid($clientId) || !$this->clients->exists($clientId)) {
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

        echo $this->view->render('owner-invited-case', [
            'flash' => $this->takeFlash(),
            'case' => $case,
            'ai' => $this->aiSection($sessionId, (string) $case['test_slug']),
        ]);
    }

    /**
     * Состояние ИИ-разборов кейса для карточки специалиста.
     *
     * Здесь, в отличие от страницы клиента, показывается всё: и статусы
     * заданий, и профессиональное заключение, и неопубликованные правки. Это
     * рабочий материал специалиста, и он закрыт `requireOwner()`.
     *
     * @return array<string, mixed>
     */
    private function aiSection(string $sessionId, string $testSlug): array
    {
        $session = $this->sessionManager->getSessionById($sessionId);
        $mode = $session === null
            ? 'individual'
            : (new ResultPresenter($this->db, $this->sessionManager))->reportMode($session);

        $reports = new AiReportRepository($this->db);
        $revisions = new AiReportRevisionService($this->db);
        $registry = PromptRegistry::default();

        $kinds = [];
        $anyJob = false;
        foreach ([Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL] as $kind) {
            if ($registry->published($testSlug, $mode, $kind) === null) {
                continue;
            }

            $report = $reports->findFor($sessionId, $mode, $kind);
            $anyJob = $anyJob || $report !== null;
            $published = $report === null ? null : $revisions->published((string) $report['id']);

            $kinds[] = [
                'kind' => $kind,
                'title' => $kind === Prompt::KIND_CLEAR ? 'Понятный разбор' : 'Профессиональное заключение',
                'report_id' => $report['id'] ?? null,
                'status' => $report['status'] ?? 'none',
                'failure_reason' => $report['failure_reason'] ?? null,
                'html' => $kind === Prompt::KIND_PROFESSIONAL && ($report['status'] ?? '') === AiReportRepository::STATUS_READY
                    ? ReportMarkdown::toHtml((string) $report['content'])
                    : null,
                'published' => $published,
            ];
        }

        return [
            'available' => $kinds !== [],
            'mode' => $mode,
            'has_jobs' => $anyJob,
            'kinds' => $kinds,
            'owner_context_max' => self::OWNER_CONTEXT_MAX_LENGTH,
        ];
    }

    /**
     * Заказать черновики обоих видов.
     * POST /admin/invited-case/{sessionId}/reports/request
     */
    public function requestCaseReports(string $sessionId): void
    {
        $case = $this->ownedCase($sessionId);
        if ($case === null) {
            return;
        }

        if (($_POST['ai_consent'] ?? null) !== '1') {
            $this->caseFlashBack($sessionId, false, 'Черновики не заказаны: нужно подтвердить передачу обезличенных результатов внешнему AI-сервису.');
        }

        $ownerContext = $_POST['owner_context'] ?? '';
        if (!is_string($ownerContext) || mb_strlen(trim($ownerContext)) > self::OWNER_CONTEXT_MAX_LENGTH) {
            $this->caseFlashBack($sessionId, false, 'Черновики не заказаны: клинический контекст длиннее ' . self::OWNER_CONTEXT_MAX_LENGTH . ' символов.');
        }
        $ownerContext = trim((string) $ownerContext);

        $session = $this->sessionManager->getSessionById($sessionId);
        if ($session === null) {
            $this->notFound();

            return;
        }

        $slug = (string) $case['test_slug'];
        $mode = (new ResultPresenter($this->db, $this->sessionManager))->reportMode($session);
        $registry = PromptRegistry::default();
        $reports = new AiReportRepository($this->db);

        try {
            $context = (new AiReportContextBuilder($this->sessionManager, $this->moduleLoader))
                ->build($sessionId, $slug, $mode);
        } catch (AiProviderException $e) {
            $this->caseFlashBack($sessionId, false, 'Черновики не заказаны: ' . $e->getMessage());
        }

        $queued = 0;
        foreach ([Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL] as $kind) {
            $prompt = $registry->published($slug, $mode, $kind);
            if ($prompt === null) {
                continue;
            }

            // Клинический контекст пишет специалист и адресует специалисту: в
            // понятный клиентский разбор он не подмешивается (phase 07, WP3).
            $reports->request(
                $sessionId,
                $slug,
                $mode,
                $kind,
                $prompt,
                $context,
                $prompt->allowsOwnerContext && $ownerContext !== '' ? $ownerContext : null,
            );
            $queued++;
        }

        $this->caseFlashBack(
            $sessionId,
            $queued > 0,
            $queued > 0
                ? 'Черновики поставлены в очередь. Обновите страницу через несколько минут.'
                : 'Для этой методики и режима разбор пока не открыт.',
        );
    }

    /**
     * Состояние заданий кейса для опроса из кабинета.
     * GET /admin/invited-case/{sessionId}/reports/status
     */
    public function caseReportStatus(string $sessionId): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        header('Content-Type: application/json');

        $case = Security::isValidUuid($sessionId) ? $this->invites->claimedCaseForOwner($sessionId) : null;
        if ($case === null) {
            http_response_code(404);
            echo json_encode(['error' => 'not found']);

            return;
        }

        $section = $this->aiSection($sessionId, (string) $case['test_slug']);
        $statuses = [];
        foreach ($section['kinds'] as $kind) {
            // Только статусы: текст черновика в опрос не отдаётся, его читают
            // на самой карточке и в редакторе.
            $statuses[] = [
                'kind' => $kind['kind'],
                'status' => $kind['status'],
                'published' => $kind['published'] !== null,
            ];
        }

        echo json_encode(['kinds' => $statuses], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Редактор понятного разбора.
     * GET /admin/invited-case/{sessionId}/reports/{reportId}/edit
     */
    public function editCaseReport(string $sessionId, string $reportId): void
    {
        $report = $this->editableReport($sessionId, $reportId);
        if ($report === null) {
            return;
        }

        $revisions = new AiReportRevisionService($this->db);
        // Готовые отчёты, поставленные до введения истории версий, получают
        // версию №1 при первом открытии редактора. Идемпотентно.
        $revisions->seedFromContent($reportId, (string) ($report['content'] ?? ''));

        $latest = $revisions->latest($reportId);
        $published = $revisions->published($reportId);

        echo $this->view->render('owner-report-editor', [
            'flash' => $this->takeFlash(),
            'session_id' => $sessionId,
            'report' => $report,
            'revisions' => array_reverse($revisions->revisions($reportId)),
            'latest' => $latest,
            'latest_html' => $latest === null ? null : ReportMarkdown::toHtml((string) $latest['content']),
            'published' => $published,
            'content_max' => AiReportRevisionService::CONTENT_MAX_LENGTH,
        ]);
    }

    /**
     * Сохранить правку как новую версию.
     * POST /admin/invited-case/{sessionId}/reports/{reportId}/revisions
     */
    public function saveCaseReportRevision(string $sessionId, string $reportId): void
    {
        if ($this->editableReport($sessionId, $reportId) === null) {
            return;
        }

        $markdown = $_POST['content'] ?? '';
        try {
            (new AiReportRevisionService($this->db))->save($reportId, is_string($markdown) ? $markdown : '');
            $this->setFlash(['type' => 'success', 'message' => 'Сохранена новая версия. Клиент увидит её только после публикации.']);
        } catch (\InvalidArgumentException $e) {
            $this->setFlash(['type' => 'error', 'message' => 'Версия не сохранена: ' . $e->getMessage()]);
        }

        $this->redirect('/admin/invited-case/' . $sessionId . '/reports/' . $reportId . '/edit');
    }

    /**
     * Восстановить старую версию как новую.
     * POST /admin/invited-case/{sessionId}/reports/{reportId}/restore
     */
    public function restoreCaseReportRevision(string $sessionId, string $reportId): void
    {
        if ($this->editableReport($sessionId, $reportId) === null) {
            return;
        }

        $revisionId = $_POST['revision_id'] ?? '';
        $restored = is_string($revisionId)
            && Security::isValidUuid($revisionId)
            && (new AiReportRevisionService($this->db))->restore($reportId, $revisionId) !== null;

        $this->setFlash($restored
            ? ['type' => 'success', 'message' => 'Версия восстановлена как новая. Прежние версии сохранены.']
            : ['type' => 'error', 'message' => 'Не удалось восстановить версию.']);
        $this->redirect('/admin/invited-case/' . $sessionId . '/reports/' . $reportId . '/edit');
    }

    /**
     * Опубликовать версию клиенту.
     * POST /admin/invited-case/{sessionId}/reports/{reportId}/publish
     */
    public function publishCaseReport(string $sessionId, string $reportId): void
    {
        if ($this->editableReport($sessionId, $reportId) === null) {
            return;
        }

        $revisionId = $_POST['revision_id'] ?? '';
        $confirmed = ($_POST['confirm_publish'] ?? null) === 'publish';
        $published = $confirmed
            && is_string($revisionId)
            && Security::isValidUuid($revisionId)
            && (new AiReportRevisionService($this->db))->publish($reportId, $revisionId);

        $this->setFlash($published
            ? ['type' => 'success', 'message' => 'Версия опубликована. Клиент видит её на своей странице результата и в PDF.']
            : ['type' => 'error', 'message' => 'Публикация не выполнена. Подтвердите её галочкой и выберите существующую версию.']);
        $this->redirect('/admin/invited-case/' . $sessionId . '/reports/' . $reportId . '/edit');
    }

    /**
     * Снять разбор с публикации.
     * POST /admin/invited-case/{sessionId}/reports/{reportId}/unpublish
     */
    public function unpublishCaseReport(string $sessionId, string $reportId): void
    {
        if ($this->editableReport($sessionId, $reportId) === null) {
            return;
        }

        (new AiReportRevisionService($this->db))->unpublish($reportId);
        $this->setFlash(['type' => 'success', 'message' => 'Разбор снят с публикации. Клиент снова видит ожидание.']);
        $this->redirect('/admin/invited-case/' . $sessionId . '/reports/' . $reportId . '/edit');
    }

    /**
     * Кейс по приглашению, доступный владельцу, или 404.
     *
     * @return array<string, mixed>|null
     */
    private function ownedCase(string $sessionId): ?array
    {
        if (!$this->requireOwner()) {
            return null;
        }

        $case = Security::isValidUuid($sessionId) ? $this->invites->claimedCaseForOwner($sessionId) : null;
        if ($case === null) {
            $this->notFound();

            return null;
        }

        return $case;
    }

    /**
     * Понятный разбор этого кейса, который разрешено редактировать.
     *
     * Редактор открыт только для понятной редакции: профессиональное
     * заключение остаётся материалом специалиста и не правится под клиента
     * (PRODUCT_RULES §4).
     *
     * @return array<string, mixed>|null
     */
    private function editableReport(string $sessionId, string $reportId): ?array
    {
        if ($this->ownedCase($sessionId) === null) {
            return null;
        }

        $report = Security::isValidUuid($reportId)
            ? (new AiReportRepository($this->db))->find($reportId)
            : null;

        if (
            $report === null
            || (string) $report['session_id'] !== $sessionId
            || (string) $report['report_kind'] !== Prompt::KIND_CLEAR
            || (string) $report['status'] !== AiReportRepository::STATUS_READY
        ) {
            $this->notFound();

            return null;
        }

        return $report;
    }

    private function caseFlashBack(string $sessionId, bool $success, string $message): never
    {
        $this->setFlash(['type' => $success ? 'success' : 'error', 'message' => $message]);
        $this->redirect('/admin/invited-case/' . $sessionId);
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
