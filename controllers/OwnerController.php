<?php

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\Ai\AiClient;
use PsyTest\Core\Ai\AiProviderException;
use PsyTest\Core\Ai\AiProviderSettings;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\AiReportGenerator;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiReportRevisionService;
use PsyTest\Core\Ai\AiSettings;
use PsyTest\Core\Ai\CurlTransport;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptFixtureContext;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\ClientReportNotifier;
use PsyTest\Core\InvitedCasePresenter;
use PsyTest\Core\OwnerDashboardAuthenticator;
use PsyTest\Core\ReportMarkdown;
use PsyTest\Core\ResponseFinisher;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\Security;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\TestInviteService;
use PsyTest\Core\TherapistCaseService;
use PsyTest\Core\TherapistClientService;
use PsyTest\Modules\TestModuleInterface;

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
        $this->clients = new TherapistClientService($this->db, $lifecycle);
        $this->invites = new TestInviteService($this->db, $this->sessionManager);
        $this->cases = new TherapistCaseService($this->db, $lifecycle, $this->invites, $this->clients);
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
        $email = $_POST['email'] ?? '';
        if (!$this->isValidClientInput($label, $note, $email)) {
            $this->setFlash(['type' => 'error', 'message' => 'Не удалось создать карточку: подпись обязательна (до 120 символов), заметка — до 1000 символов, email — корректный адрес или пусто.']);
            $this->redirect('/admin/clients');
        }

        $clientId = $this->clients->create((string) $label, (string) $note, (string) $email);
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
        $email = $_POST['email'] ?? '';
        $updated = $this->isValidClientInput($label, $note, $email)
            && $this->clients->update($clientId, (string) $label, (string) $note, (string) $email);

        $this->setFlash($updated
            ? ['type' => 'success', 'message' => 'Карточка клиента обновлена.']
            : ['type' => 'error', 'message' => 'Не удалось обновить карточку: подпись обязательна (до 120 символов), заметка — до 1000 символов, email — корректный адрес или пусто.']);
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
        $case['pair'] = $this->pairSection($sessionId, $module, $presenter, $case);

        $ai = $this->aiSection($sessionId, (string) $case['test_slug']);

        echo $this->view->render('owner-invited-case', [
            'flash' => $this->takeFlash(),
            'case' => $case,
            'ai' => $ai,
            'notify' => $this->notifySection($sessionId, $case, $ai),
        ]);
    }

    /**
     * Парное прохождение в карточке кейса (07.K1b).
     *
     * Контроллер только собирает участников: парные секции считает
     * `ResultPresenter::pairViewData()` — тем же модулем и тем же расчётом,
     * что у клиента, — а подписи и обе анкеты собирает
     * `InvitedCasePresenter::pair()`.
     *
     * Вторая сессия остаётся чужой: её идентификатор дальше контроллера не
     * уходит, `retention_class` не меняется, токен и ссылки не выводятся.
     *
     * @param array<string, mixed> $case
     * @return array<string, mixed>|null
     */
    private function pairSection(
        string $sessionId,
        TestModuleInterface $module,
        InvitedCasePresenter $presenter,
        array $case,
    ): ?array {
        $session = $this->sessionManager->getSessionById($sessionId);
        if ($session === null) {
            return null;
        }

        $pair = (new ResultPresenter($this->db, $this->sessionManager))->pairViewData($session, $module);
        if ($pair === null) {
            return null;
        }

        $partner = $this->sessionManager->getSessionById($pair['partner_session_id']);
        if ($partner === null) {
            return null;
        }

        /** @var array<string|int, mixed> $caseAnswers */
        $caseAnswers = $case['answers'] ?? [];
        /** @var array<string|int, mixed> $partnerAnswers */
        $partnerAnswers = $partner['answers'] ?? [];

        return $presenter->pair($module, $pair, $caseAnswers, $partnerAnswers);
    }

    /**
     * Состояние кнопки «Уведомить клиента на email».
     *
     * Письмо предлагается только когда разбор уже опубликован: до этого
     * уведомлять не о чем. Без адреса в карточке кнопка остаётся видимой, но
     * неактивной — специалисту нужно понимать, почему она не работает, и как
     * это исправить (D-054).
     *
     * @param array<string, mixed> $case
     * @param array<string, mixed> $ai
     * @return array{published: bool, has_email: bool, client_id: ?string, last_at: ?string}
     */
    private function notifySection(string $sessionId, array $case, array $ai): array
    {
        $published = false;
        /** @var list<array<string, mixed>> $kinds */
        $kinds = $ai['kinds'] ?? [];
        foreach ($kinds as $kind) {
            if (($kind['kind'] ?? null) === Prompt::KIND_CLEAR && ($kind['published'] ?? null) !== null) {
                $published = true;
            }
        }

        $clientId = isset($case['client_id']) && is_string($case['client_id']) ? $case['client_id'] : null;

        return [
            'published' => $published,
            'has_email' => $this->clients->hasEmail($clientId),
            'client_id' => $clientId,
            'last_at' => $published
                ? ClientReportNotifier::fromConfig($this->db)->lastNotifiedAt($sessionId)
                : null,
        ];
    }

    /**
     * Отправить клиенту письмо «разбор готов».
     * POST /admin/invited-case/{sessionId}/reports/notify
     */
    public function notifyClientAboutReport(string $sessionId): void
    {
        if ($this->ownedCase($sessionId) === null) {
            return;
        }

        $sent = ClientReportNotifier::fromConfig($this->db)->notify($sessionId);
        $this->setFlash($sent
            ? ['type' => 'success', 'message' => 'Письмо отправлено. В нём нет текста разбора и ссылки: клиент открывает свою страницу результата.']
            : ['type' => 'error', 'message' => 'Письмо не отправлено. Нужны опубликованный разбор и email в карточке клиента; повторное уведомление возможно через ' . ClientReportNotifier::MIN_INTERVAL_MINUTES . ' минут.']);
        $this->redirect('/admin/invited-case/' . $sessionId);
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
        $registry = PromptRegistry::default($this->db);

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
            // Выключатель владельца (07.WP9): заказывать черновики, которые
            // сразу уйдут в отказ, бессмысленно.
            'ai_disabled' => !(new AiSettings($this->db))->isAiEnabled(),
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
        $registry = PromptRegistry::default($this->db);
        $reports = new AiReportRepository($this->db);

        if (!(new AiSettings($this->db))->isAiEnabled()) {
            $this->caseFlashBack($sessionId, false, 'Черновики не заказаны: ' . AiClient::DISABLED_REASON . '.');
        }

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

        if ($queued === 0) {
            $this->caseFlashBack($sessionId, false, 'Для этой методики и режима разбор пока не открыт.');
        }

        // На shared-хостинге нет cron-обработчика очереди: как и на странице
        // результата, задания доводятся до конца в этом же процессе после
        // того, как ответ уже отдан браузеру (07.16–07.17).
        $this->setFlash(['type' => 'success', 'message' => 'Черновики поставлены в очередь. Обновите страницу через несколько минут.']);
        header('Location: /admin/invited-case/' . $sessionId, true, 303);
        header('Content-Length: 0');
        ResponseFinisher::finish();

        $generator = $this->reportGenerator();
        for ($i = 0; $i < $queued; $i++) {
            $job = $reports->claimNext();
            if ($job === null) {
                break;
            }
            $generator->process($job);
        }

        exit;
    }

    private function reportGenerator(): AiReportGenerator
    {
        $aiSettings = new AiSettings($this->db);
        $settings = AiProviderSettings::fromConfig(require dirname(__DIR__) . '/config.php', $aiSettings);

        return new AiReportGenerator(
            new AiReportRepository($this->db),
            new AiReportContextBuilder($this->sessionManager, $this->moduleLoader),
            PromptRegistry::default($this->db),
            new AiClient($settings, new CurlTransport(), ownerSettings: $aiSettings),
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
            'case_attached' => $case === null ? false : $this->invites->claimedCaseForOwner((string) $case['id']) !== null,
            'lookup_error' => $case === null ? 'Сессия не найдена или уже удалена.' : null,
            'invite_tests' => array_values($this->moduleLoader->getActiveModules()),
            'invites' => $this->invites->recentForOwner(),
            'clients' => $this->clients->listForOwner(),
        ]);
    }

    /**
     * Привязать найденную сессию к карточке клиента.
     * POST /admin/case/attach
     *
     * Сессия, пройденная без приглашения, иначе остаётся видимой только через
     * разовый поиск по токену результата. Привязка даёт ей то же место, что и
     * назначенной: карточку кейса и историю клиента.
     */
    public function attachCase(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $sessionId = $_POST['session_id'] ?? '';
        $rawClientId = $_POST['client_id'] ?? '';
        $label = $_POST['new_client_label'] ?? '';
        $note = $_POST['owner_note'] ?? '';

        if (!is_string($sessionId) || !Security::isValidUuid($sessionId) || !is_string($rawClientId) || !is_string($note)) {
            $this->attachFailed();
        }

        $clientId = $rawClientId === '' ? null : $rawClientId;
        if ($clientId !== null && !Security::isValidUuid($clientId)) {
            $this->attachFailed();
        }
        if ($clientId === null && !$this->isValidClientInput($label, '')) {
            $this->attachFailed();
        }
        if (mb_strlen(trim($note)) > TherapistClientService::NOTE_MAX_LENGTH) {
            $this->attachFailed();
        }

        if (!$this->cases->attachToClient($sessionId, $clientId, trim($note), trim((string) $label))) {
            $this->attachFailed();
        }

        $this->setFlash(['type' => 'success', 'message' => 'Сессия привязана к карточке клиента. Она больше не участвует в автоматической 180-дневной очистке.']);
        $this->redirect('/admin/invited-case/' . $sessionId);
    }

    private function attachFailed(): never
    {
        $this->setFlash([
            'type' => 'error',
            'message' => 'Не удалось привязать сессию: нужна завершённая сессия без приглашения, существующая карточка клиента или подпись новой (до 120 символов) и заметка до 1000 символов.',
        ]);
        $this->redirect('/admin');
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

    /**
     * Проверка полей карточки до записи.
     *
     * Пустой email допустим и означает «уведомлять некуда»: контакт клиента
     * остаётся необязательным (D-054).
     */
    private function isValidClientInput(mixed $label, mixed $note, mixed $email = ''): bool
    {
        if (!is_string($email)) {
            return false;
        }
        $email = trim($email);
        $emailIsValid = $email === ''
            || (mb_strlen($email) <= TherapistClientService::EMAIL_MAX_LENGTH && Security::isValidEmail($email));

        return is_string($label)
            && is_string($note)
            && trim($label) !== ''
            && mb_strlen(trim($label)) <= TherapistClientService::LABEL_MAX_LENGTH
            && mb_strlen(trim($note)) <= TherapistClientService::NOTE_MAX_LENGTH
            && $emailIsValid;
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

    // =========================================================== промпты (07.WP9)

    /** Заметка владельца к версии промпта. */
    public const PROMPT_NOTE_MAX_LENGTH = 255;

    /**
     * Список методик с разбором и общие настройки ИИ.
     * GET /admin/prompts
     */
    public function prompts(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $registry = PromptRegistry::default($this->db);
        $settings = new AiSettings($this->db);
        $groups = [];

        foreach ($registry->keys() as $key) {
            [$test, $mode, $kind] = array_map('trim', explode('|', $key));
            $override = $registry->publishedOverride($test, $mode, $kind);
            $version = $override ?? $registry->manifestVersion($test, $mode, $kind);
            $entry = $version === null
                ? null
                : self::findCatalogEntry($registry->versionCatalog($test, $mode, $kind), $version);

            $groups[$test]['test'] = $test;
            $groups[$test]['title'] = $this->testTitle($test);
            $groups[$test]['keys'][] = [
                'test' => $test,
                'mode' => $mode,
                'kind' => $kind,
                'mode_title' => self::modeTitle($mode),
                'kind_title' => self::kindTitle($kind),
                'version' => $version,
                'source' => $entry['source'] ?? PromptRegistry::SOURCE_FILE,
                'created_at' => $entry['created_at'] ?? null,
                'from_manifest' => $override === null,
            ];
        }

        echo $this->view->render('owner-prompts', [
            'flash' => $this->takeFlash(),
            'groups' => array_values($groups),
            'ai_enabled' => $settings->isAiEnabled(),
            'ai_model' => $settings->modelOverride(),
            'env_model' => AiProviderSettings::fromConfig(require dirname(__DIR__) . '/config.php')->model,
            'models' => $this->modelCatalog(),
        ]);
    }

    /**
     * Выключатель разборов и модель.
     * POST /admin/prompts/settings
     */
    public function savePromptSettings(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $settings = new AiSettings($this->db);
        $settings->setAiEnabled(($_POST['ai_enabled'] ?? null) === '1');

        $model = $_POST['ai_model'] ?? '';
        $settings->setModelOverride(is_string($model) ? $model : '');

        $this->setFlash(['type' => 'success', 'message' => 'Настройки ИИ сохранены.']);
        $this->redirect('/admin/prompts');
    }

    /**
     * Карточка одного ключа реестра.
     * GET /admin/prompts/{test}/{mode}/{kind}
     */
    public function promptKey(string $test, string $mode, string $kind): void
    {
        $view = $this->promptKeyView($test, $mode, $kind);
        if ($view === null) {
            return;
        }

        echo $this->view->render('owner-prompt-key', $view);
    }

    /**
     * Предпросмотр запроса на синтетическом контексте — без вызова провайдера.
     * GET /admin/prompts/{test}/{mode}/{kind}/preview
     */
    public function promptPreview(string $test, string $mode, string $kind): void
    {
        $view = $this->promptKeyView($test, $mode, $kind);
        if ($view === null) {
            return;
        }

        $view['preview'] = $this->buildPreview($view['selected'], $test, $mode);

        echo $this->view->render('owner-prompt-key', $view);
    }

    /**
     * Новая версия промпта из кабинета.
     * POST /admin/prompts/{test}/{mode}/{kind}/versions
     */
    public function createPromptVersion(string $test, string $mode, string $kind): void
    {
        if (!$this->requireOwner()) {
            return;
        }
        if (!$this->promptKeyExists($test, $mode, $kind)) {
            $this->notFound();

            return;
        }

        $text = $_POST['text'] ?? '';
        $note = $_POST['note'] ?? '';

        if (!is_string($text) || trim($text) === '') {
            $this->promptFlashBack($test, $mode, $kind, false, 'Версия не сохранена: текст промпта пуст.');
        }
        if (!is_string($note) || mb_strlen(trim($note)) > self::PROMPT_NOTE_MAX_LENGTH) {
            $this->promptFlashBack($test, $mode, $kind, false, 'Версия не сохранена: заметка длиннее ' . self::PROMPT_NOTE_MAX_LENGTH . ' символов.');
        }

        try {
            $version = PromptRegistry::default($this->db)->createOwnerVersion(
                $test,
                $mode,
                $kind,
                $text,
                $note,
                ($_POST['allows_owner_context'] ?? null) === '1',
            );
        } catch (\RuntimeException $e) {
            $this->promptFlashBack($test, $mode, $kind, false, 'Версия не сохранена: ' . $e->getMessage());
        }

        $this->setFlash([
            'type' => 'success',
            'message' => "Версия {$version} сохранена. Она ещё не опубликована — новые заказы по-прежнему идут по текущей версии.",
        ]);
        $this->redirect($this->promptKeyPath($test, $mode, $kind) . '?version=' . $version);
    }

    /**
     * Опубликовать версию для новых заказов.
     * POST /admin/prompts/{test}/{mode}/{kind}/publish
     */
    public function publishPromptVersion(string $test, string $mode, string $kind): void
    {
        if (!$this->requireOwner()) {
            return;
        }
        if (!$this->promptKeyExists($test, $mode, $kind)) {
            $this->notFound();

            return;
        }

        if (($_POST['confirm_publish'] ?? null) !== '1') {
            $this->promptFlashBack($test, $mode, $kind, false, 'Версия не опубликована: нужно подтвердить, что новые заказы пойдут по ней.');
        }

        $version = (int) ($_POST['version'] ?? 0);

        try {
            PromptRegistry::default($this->db)->publishVersion($test, $mode, $kind, $version);
        } catch (\RuntimeException $e) {
            $this->promptFlashBack($test, $mode, $kind, false, 'Версия не опубликована: ' . $e->getMessage());
        }

        $this->promptFlashBack($test, $mode, $kind, true, "Версия {$version} опубликована. Уже поставленные задания не изменились — у них свой снимок промпта.");
    }

    /**
     * Вернуться к версии из manifest.json.
     * POST /admin/prompts/{test}/{mode}/{kind}/reset
     */
    public function resetPromptVersion(string $test, string $mode, string $kind): void
    {
        if (!$this->requireOwner()) {
            return;
        }
        if (!$this->promptKeyExists($test, $mode, $kind)) {
            $this->notFound();

            return;
        }

        PromptRegistry::default($this->db)->resetToManifest($test, $mode, $kind);
        $this->promptFlashBack($test, $mode, $kind, true, 'Ключ возвращён к версии из manifest.json.');
    }

    /**
     * Пробный вызов провайдера на синтетическом контексте.
     * POST /admin/prompts/{test}/{mode}/{kind}/trial
     *
     * Результат показывается на странице и нигде не сохраняется: это проверка
     * формулировки, а не разбор чьего-то результата.
     */
    public function promptTrial(string $test, string $mode, string $kind): void
    {
        $view = $this->promptKeyView($test, $mode, $kind);
        if ($view === null) {
            return;
        }

        if (($_POST['confirm_trial'] ?? null) !== '1') {
            $this->promptFlashBack($test, $mode, $kind, false, 'Пробный вызов не сделан: нужно подтвердить обращение к провайдеру.');
        }

        $aiSettings = new AiSettings($this->db);
        if (!$aiSettings->isAiEnabled()) {
            $this->promptFlashBack($test, $mode, $kind, false, AiClient::DISABLED_REASON . '.');
        }

        /** @var Prompt $prompt */
        $prompt = $view['selected'];
        $preview = $this->buildPreview($prompt, $test, $mode);
        if ($preview['error'] !== null) {
            $this->promptFlashBack($test, $mode, $kind, false, 'Пробный вызов не сделан: ' . $preview['error']);
        }

        $trial = ['text' => null, 'error' => null, 'model' => null];

        try {
            $completion = $this->trialClient($aiSettings)->complete(
                new Prompt(
                    test: $prompt->test,
                    mode: $prompt->mode,
                    kind: $prompt->kind,
                    version: $prompt->version,
                    // Пробный вызов делается и по неопубликованной версии: в
                    // этом он и нужен — проверить текст до публикации.
                    status: Prompt::STATUS_PUBLISHED,
                    text: $prompt->text,
                    allowsOwnerContext: $prompt->allowsOwnerContext,
                    source: $prompt->source,
                ),
                $preview['context'],
            );
            $trial['text'] = ReportMarkdown::toHtml($completion->text);
            $trial['model'] = $completion->servedModel;
        } catch (AiProviderException $e) {
            $trial['error'] = $e->getMessage();
        }

        $view['preview'] = $preview;
        $view['trial'] = $trial;

        echo $this->view->render('owner-prompt-key', $view);
    }

    private function trialClient(AiSettings $aiSettings): AiClient
    {
        // Пробный вызов ждёт ответ синхронно в HTTP-запросе кабинета, поэтому
        // ему нельзя давать боевой таймаут очереди: 90 секунд, иначе страница
        // обрывается веб-сервером на середине.
        return new AiClient(
            AiProviderSettings::fromConfig(require dirname(__DIR__) . '/config.php', $aiSettings)->withTimeout(90),
            new CurlTransport(),
            ownerSettings: $aiSettings,
        );
    }

    /**
     * Общие данные карточки ключа; null — ответ уже отдан (404 или редирект).
     *
     * @return array<string, mixed>|null
     */
    private function promptKeyView(string $test, string $mode, string $kind): ?array
    {
        if (!$this->requireOwner()) {
            return null;
        }
        if (!$this->promptKeyExists($test, $mode, $kind)) {
            $this->notFound();

            return null;
        }

        $registry = PromptRegistry::default($this->db);
        $catalog = $registry->versionCatalog($test, $mode, $kind);
        $override = $registry->publishedOverride($test, $mode, $kind);
        $publishedVersion = $override ?? $registry->manifestVersion($test, $mode, $kind);

        $requested = $_GET['version'] ?? null;
        $selectedVersion = is_string($requested) && preg_match('/\A\d{1,6}\z/', $requested) === 1
            ? (int) $requested
            : (int) $publishedVersion;

        $selected = $registry->version($test, $mode, $kind, $selectedVersion);
        if ($selected === null) {
            $selectedVersion = (int) $publishedVersion;
            $selected = $registry->version($test, $mode, $kind, $selectedVersion);
        }

        if ($selected === null) {
            $this->notFound();

            return null;
        }

        return [
            'flash' => $this->takeFlash(),
            'test' => $test,
            'mode' => $mode,
            'kind' => $kind,
            'test_title' => $this->testTitle($test),
            'mode_title' => self::modeTitle($mode),
            'kind_title' => self::kindTitle($kind),
            'versions' => $catalog,
            'published_version' => $publishedVersion,
            'from_manifest' => $override === null,
            'manifest_version' => $registry->manifestVersion($test, $mode, $kind),
            'selected' => $selected,
            'selected_version' => $selectedVersion,
            'note_max' => self::PROMPT_NOTE_MAX_LENGTH,
            'ai_enabled' => (new AiSettings($this->db))->isAiEnabled(),
            'preview' => null,
            'trial' => null,
        ];
    }

    /**
     * Запрос к модели, собранный на синтетическом контексте методики.
     *
     * @return array{system: string, user: string|null, context: array<string, mixed>, error: string|null}
     */
    private function buildPreview(Prompt $prompt, string $test, string $mode): array
    {
        $module = $this->moduleLoader->getModule($test);

        if ($module === null) {
            return ['system' => $prompt->text, 'user' => null, 'context' => [], 'error' => "Методика «{$test}» не установлена."];
        }

        try {
            $context = PromptFixtureContext::build($module, $mode);
        } catch (\Throwable $e) {
            return ['system' => $prompt->text, 'user' => null, 'context' => [], 'error' => $e->getMessage()];
        }

        return [
            'system' => $prompt->text,
            'user' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'context' => $context,
            'error' => null,
        ];
    }

    private function promptKeyExists(string $test, string $mode, string $kind): bool
    {
        return in_array(Prompt::keyFor($test, $mode, $kind), PromptRegistry::default($this->db)->keys(), true);
    }

    private function promptKeyPath(string $test, string $mode, string $kind): string
    {
        return '/admin/prompts/' . rawurlencode($test) . '/' . rawurlencode($mode) . '/' . rawurlencode($kind);
    }

    private function promptFlashBack(string $test, string $mode, string $kind, bool $ok, string $message): never
    {
        $this->setFlash(['type' => $ok ? 'success' : 'error', 'message' => $message]);
        $this->redirect($this->promptKeyPath($test, $mode, $kind));
    }

    /**
     * @param list<array{version: int, source: string, created_at: string|null, note: string|null}> $catalog
     *
     * @return array{version: int, source: string, created_at: string|null, note: string|null}|null
     */
    private static function findCatalogEntry(array $catalog, int $version): ?array
    {
        foreach ($catalog as $entry) {
            if ($entry['version'] === $version) {
                return $entry;
            }
        }

        return null;
    }

    private function testTitle(string $slug): string
    {
        $module = $this->moduleLoader->getModule($slug);

        return $module === null ? $slug : (string) ($module->getMetadata()['name'] ?? $slug);
    }

    private static function modeTitle(string $mode): string
    {
        return match ($mode) {
            'individual' => 'индивидуальный',
            'pair' => 'парный',
            default => $mode,
        };
    }

    private static function kindTitle(string $kind): string
    {
        return match ($kind) {
            Prompt::KIND_CLEAR => 'понятный разбор',
            Prompt::KIND_PROFESSIONAL => 'профессиональное заключение',
            default => $kind,
        };
    }

    /**
     * Каталог моделей провайдера для подсказки; сеть здесь не обязана работать.
     *
     * @return list<array{id: string, name: string, free: bool}>
     */
    private function modelCatalog(): array
    {
        $settings = AiProviderSettings::fromConfig(require dirname(__DIR__) . '/config.php', new AiSettings($this->db));
        if (!$settings->isConfigured()) {
            return [];
        }

        try {
            $models = (new AiClient($settings, new CurlTransport()))->models();
        } catch (\Throwable) {
            // Список моделей — удобство, а не условие работы страницы:
            // недоступный провайдер не должен ломать редактор промптов.
            return [];
        }

        $catalog = [];
        foreach ($models as $model) {
            $catalog[] = ['id' => $model->id, 'name' => $model->name, 'free' => $model->isFree];
        }

        return $catalog;
    }
}
