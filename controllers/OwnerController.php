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
use PsyTest\Core\Ai\BackgroundWorkerLauncher;
use PsyTest\Core\Ai\CurlTransport;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptFixtureContext;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\Ai\SmilGlossaryCompactor;
use PsyTest\Core\CaseExportDocx;
use PsyTest\Core\CaseExportPresenter;
use PsyTest\Core\ClientReportNotifier;
use PsyTest\Core\FormOnce;
use PsyTest\Core\InvitedCasePresenter;
use PsyTest\Core\InviteFilter;
use PsyTest\Core\OwnerCaseNoteUpdate;
use PsyTest\Core\OwnerCaseReportOrder;
use PsyTest\Core\OwnerClientSubmission;
use PsyTest\Core\OwnerClientTrashAction;
use PsyTest\Core\OwnerDashboardAuthenticator;
use PsyTest\Core\OwnerInviteBulkAction;
use PsyTest\Core\OwnerInviteClientAttach;
use PsyTest\Core\OwnerInviteSubmission;
use PsyTest\Core\PDFGenerator;
use PsyTest\Core\ReportMarkdown;
use PsyTest\Core\ResponseFinisher;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\ResultSectionRenderer;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\Security;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\TestInviteService;
use PsyTest\Core\TherapistCaseService;
use PsyTest\Core\TherapistClientService;
use PsyTest\Modules\ResultSection;
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
    public const OWNER_CONTEXT_MAX_LENGTH = OwnerCaseReportOrder::OWNER_CONTEXT_MAX_LENGTH;

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

        $tests = array_values($this->moduleLoader->getActiveModules());
        $filter = InviteFilter::fromQuery(
            $_GET,
            array_map(static fn (array $test): string => (string) $test['slug'], $tests),
        );
        $invites = $this->invites->listForOwner($filter);

        echo $this->view->render('owner-dashboard', [
            'flash' => $this->takeFlash(),
            'invite_tests' => $tests,
            'invites' => $invites,
            'filter' => $filter,
            'filter_url' => $filter->toUrl(),
            'invite_counts' => $this->invites->countsForOwner(),
            'clients' => $this->clients->listForOwner(),
            'invite_form_key' => $this->inviteSubmission()->issueKey(),
            'bulk_form_key' => $this->inviteBulk()->issueKey(),
            'attach_form_key' => $this->inviteAttach()->issueKey(),
            'trash_days' => TestInviteService::TRASH_RETENTION_DAYS,
        ]);
    }

    public function clients(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $tests = array_values($this->moduleLoader->getActiveModules());
        $slugs = array_map(static fn (array $test): string => (string) $test['slug'], $tests);
        $test = $_GET['test'] ?? '';
        $test = is_string($test) && in_array($test, $slugs, true) ? $test : '';
        $search = $_GET['q'] ?? '';
        $search = is_string($search) ? trim(mb_substr($search, 0, InviteFilter::QUERY_MAX_LENGTH)) : '';
        // Вкладки «Рабочие» и «Корзина» (07.K11); корзина открывается явным выбором.
        $trashView = ($_GET['view'] ?? '') === 'trash';
        $counts = $this->clients->countsForOwner();

        echo $this->view->render('owner-clients', [
            'flash' => $this->takeFlash(),
            'clients' => $this->clients->listForOwner(100, $search, $test === '' ? null : $test, $trashView),
            'client_view' => $trashView ? 'trash' : 'active',
            'active_count' => $counts['active'],
            'trashed_count' => $counts['trash'],
            'trash_days' => TestInviteService::TRASH_RETENTION_DAYS,
            'return_url' => '/admin/clients' . ($trashView ? '?view=trash' : ''),
            'trash_form_key' => $this->clientTrash()->issueKey(),
            'client_form_key' => $this->clientSubmission()->issueKey(),
            'invite_tests' => $tests,
            'filter_test' => $test,
            'filter_query' => $search,
        ]);
    }

    public function createClient(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $outcome = $this->clientSubmission()->submit($this->postData());
        $this->setFlash(['type' => $outcome['type'], 'message' => $outcome['message']]);
        $this->redirect($outcome['redirect']);
    }

    private function clientSubmission(): OwnerClientSubmission
    {
        return new OwnerClientSubmission($this->clients, $this->formOnce());
    }

    /**
     * Одноразовые ключи форм кабинета (07.K6a–K6b) живут в сессии владельца.
     */
    private function formOnce(): FormOnce
    {
        return new FormOnce($_SESSION);
    }

    /** @return array<string, mixed> */
    private function postData(): array
    {
        /** @var array<string, mixed> $post */
        $post = $_POST;

        return $post;
    }

    /** @return list<int> */
    private function activeTestIds(): array
    {
        return array_map(
            static fn (array $test): int => (int) $test['id'],
            array_values($this->moduleLoader->getActiveModules()),
        );
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

        $slugs = array_map(
            static fn (array $test): string => (string) $test['slug'],
            array_values($this->moduleLoader->getActiveModules()),
        );
        $filter = InviteFilter::fromQuery($_GET, $slugs);
        $clientTrashed = $card['client']['trashed_at'] !== null;
        $archivedCount = count(array_filter($card['assignments'], static fn (array $a): bool => $a['trashed_at'] === null && $a['archived_at'] !== null));
        $trashedCount = count(array_filter($card['assignments'], static fn (array $a): bool => $a['trashed_at'] !== null));
        $activeCount = count($card['assignments']) - $archivedCount - $trashedCount;
        // Рабочий вид — без архива и корзины; они открываются явным выбором.
        $view = in_array($filter->status, [InviteFilter::STATUS_ARCHIVED, InviteFilter::STATUS_TRASH], true)
            ? $filter->status
            : InviteFilter::STATUS_ACTIVE;
        $assignments = array_values(array_filter($card['assignments'], static fn (array $a): bool => $clientTrashed || match ($view) {
            InviteFilter::STATUS_TRASH => $a['trashed_at'] !== null,
            InviteFilter::STATUS_ARCHIVED => $a['trashed_at'] === null && $a['archived_at'] !== null,
            default => $a['trashed_at'] === null && $a['archived_at'] === null,
        } && ($filter->testSlug === null || $a['test_slug'] === $filter->testSlug)));

        echo $this->view->render('owner-client', [
            'flash' => $this->takeFlash(),
            'client' => $card['client'],
            'assignments' => $assignments,
            'history' => $card['history'],
            'assignment_view' => $view,
            'assignment_test' => $filter->testSlug ?? '',
            'active_count' => $activeCount,
            'archived_count' => $archivedCount,
            'trashed_count' => $trashedCount,
            'bulk_form_key' => $this->inviteBulk()->issueKey(),
            'trash_days' => TestInviteService::TRASH_RETENTION_DAYS,
            'return_url' => '/admin/clients/' . $clientId . ($view === InviteFilter::STATUS_ACTIVE ? '' : '?status=' . $view),
            'attach_form_key' => $this->inviteAttach()->issueKey(),
            'clients' => $this->clients->listForOwner(),
            'invite_tests' => array_values($this->moduleLoader->getActiveModules()),
            'invite_form_key' => $this->inviteSubmission()->issueKey(),
            'client_trashed' => $clientTrashed,
            'card_assignment_count' => $activeCount + $archivedCount,
            'trash_form_key' => $this->clientTrash()->issueKey(),
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
        $this->requireActiveClient($clientId);

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
        $this->requireActiveClient($clientId);

        // Та же операция, что «Новое приглашение» на главной, с клиентом из
        // адреса страницы: повтор отправки отсекается тем же ключом (07.K6b).
        $this->setFlash($this->inviteSubmission()->submit($this->postData(), $this->activeTestIds(), $clientId));
        $this->redirect('/admin/clients/' . $clientId);
    }

    /**
     * Карточка в корзине доступна только для чтения (07.K11): правка и новые
     * назначения отклоняются на сервере, а не только скрываются в шаблоне.
     */
    private function requireActiveClient(string $clientId): void
    {
        if ($this->clients->isActive($clientId)) {
            return;
        }
        $this->setFlash(['type' => 'error', 'message' => 'Карточка в корзине: чтобы её менять, сначала восстановите её.']);
        $this->redirect('/admin/clients/' . $clientId);
    }

    private function clientTrash(): OwnerClientTrashAction
    {
        return new OwnerClientTrashAction($this->clients, $this->formOnce());
    }

    public function trashClient(string $clientId): void
    {
        $this->clientTrashAction(OwnerClientTrashAction::TRASH, $clientId);
    }

    public function restoreClient(string $clientId): void
    {
        $this->clientTrashAction(OwnerClientTrashAction::RESTORE, $clientId);
    }

    public function purgeClient(string $clientId): void
    {
        $this->clientTrashAction(OwnerClientTrashAction::PURGE, $clientId);
    }

    /**
     * Прежний адрес удаления: теперь это то же «Удалить сейчас», и только для
     * карточки из корзины. Рабочую карточку он не удаляет.
     */
    public function deleteClient(string $clientId): void
    {
        if (!$this->requireOwner()) {
            return;
        }
        if (Security::isValidUuid($clientId) && $this->clients->isActive($clientId)) {
            $this->setFlash(['type' => 'error', 'message' => 'Удалить сразу нельзя: сначала отправьте карточку в корзину.']);
            $this->redirect('/admin/clients/' . $clientId);
        }

        $this->clientTrashAction(OwnerClientTrashAction::PURGE, $clientId);
    }

    /**
     * В корзину, восстановление и окончательное удаление карточки (07.K11).
     *
     * «В корзину» и «Удалить сейчас» без подтверждения (`confirmed=1`, его
     * ставит диалог или страница подтверждения) ничего не меняют: показывается
     * страница подтверждения, так что клик без JavaScript ничего не удаляет.
     */
    private function clientTrashAction(string $action, string $clientId): void
    {
        if (!$this->requireOwner()) {
            return;
        }
        if (!Security::isValidUuid($clientId) || !$this->clients->exists($clientId)) {
            $this->notFound();

            return;
        }

        $post = $this->postData();
        $trash = $this->clientTrash();
        $return = OwnerInviteBulkAction::safeReturn($post['return'] ?? null);

        if (
            in_array($action, OwnerClientTrashAction::CONFIRMED_ACTIONS, true)
            && ($post['confirmed'] ?? null) !== '1'
        ) {
            $card = $this->clients->findForOwner($clientId);
            echo $this->view->render('owner-client-confirm', [
                'action' => $action,
                'client' => $card['client'] ?? [],
                'assignments_total' => count($card['assignments'] ?? []),
                'return_url' => $return,
                'trash_form_key' => $trash->issueKey(),
                'trash_days' => TestInviteService::TRASH_RETENTION_DAYS,
            ]);

            return;
        }

        $outcome = $trash->submit($action, $clientId, $post);
        $this->setFlash($outcome);
        // Удалённая окончательно карточка больше не открывается: возвращаемся к списку.
        if ($action === OwnerClientTrashAction::PURGE && $outcome['type'] === 'success') {
            $this->redirect(str_contains($return, '/admin/clients/') ? '/admin/clients?view=trash' : $return);
        }
        $this->redirect($return);
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

        $this->setFlash($this->inviteSubmission()->submit($this->postData(), $this->activeTestIds()));
        $this->redirect('/admin');
    }

    private function inviteSubmission(): OwnerInviteSubmission
    {
        return new OwnerInviteSubmission(
            $this->db,
            $this->clients,
            $this->invites,
            $this->formOnce(),
            $this->appUrl,
        );
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

    /**
     * Удаление отозванного приглашения из списка (07.K6c).
     *
     * Идемпотентно: повторная отправка формы по уже удалённой строке даёт
     * спокойное сообщение, а не ошибку. Токена в сообщениях нет.
     */
    public function deleteInvite(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $inviteId = $_POST['invite_id'] ?? '';
        $outcome = is_string($inviteId) && Security::isValidUuid($inviteId)
            ? $this->invites->deleteRevoked($inviteId)
            : TestInviteService::DELETE_REFUSED;
        $this->setFlash(match ($outcome) {
            TestInviteService::DELETE_DONE => ['type' => 'success', 'message' => 'Отозванное приглашение удалено.'],
            TestInviteService::DELETE_MISSING => ['type' => 'success', 'message' => 'Приглашение уже удалено.'],
            default => ['type' => 'error', 'message' => 'Удалить можно только отозванное приглашение, которое не открывали.'],
        });
        $this->redirect('/admin');
    }

    private function inviteBulk(): OwnerInviteBulkAction
    {
        return new OwnerInviteBulkAction($this->invites, $this->cases, $this->formOnce());
    }

    public function archiveInvites(): void
    {
        $this->bulkInviteAction(OwnerInviteBulkAction::ARCHIVE);
    }

    public function unarchiveInvites(): void
    {
        $this->bulkInviteAction(OwnerInviteBulkAction::UNARCHIVE);
    }

    public function trashInvites(): void
    {
        $this->bulkInviteAction(OwnerInviteBulkAction::TRASH);
    }

    public function restoreInvites(): void
    {
        $this->bulkInviteAction(OwnerInviteBulkAction::RESTORE);
    }

    public function purgeInvites(): void
    {
        $this->bulkInviteAction(OwnerInviteBulkAction::PURGE);
    }

    /**
     * Архив, корзина, восстановление и удаление приглашений (07.K8).
     *
     * «В корзину» и «Удалить сейчас» без подтверждения (`confirmed=1`, его
     * ставит диалог или страница подтверждения) ничего не меняют: контроллер
     * показывает страницу подтверждения. Так одиночный клик без JavaScript
     * не удаляет и не отправляет в корзину ничего.
     */
    private function bulkInviteAction(string $action): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $post = $this->postData();
        $bulk = $this->inviteBulk();
        $return = OwnerInviteBulkAction::safeReturn($post['return'] ?? null);
        $ids = OwnerInviteBulkAction::selectedIds($post);

        if (
            $ids !== []
            && in_array($action, OwnerInviteBulkAction::CONFIRMED_ACTIONS, true)
            && ($post['confirmed'] ?? null) !== '1'
        ) {
            echo $this->view->render('owner-invites-confirm', [
                'action' => $action,
                'count' => count($ids),
                'invite_ids' => $ids,
                'return_url' => $return,
                'bulk_form_key' => $bulk->issueKey(),
                'trash_days' => TestInviteService::TRASH_RETENTION_DAYS,
            ]);

            return;
        }

        $this->setFlash($bulk->submit($action, $post));
        $this->redirect($return);
    }

    private function inviteAttach(): OwnerInviteClientAttach
    {
        return new OwnerInviteClientAttach($this->invites, $this->clients, $this->formOnce());
    }

    /**
     * Привязка приглашения к клиенту и смена клиента (07.K9).
     *
     * Запрос без выбранного клиента (`client_id`) — это нажатие пункта меню без
     * JavaScript: ничего не меняется, показывается страница выбора с той же
     * формой, что и диалог. С JavaScript диалог отправляет форму уже с клиентом.
     */
    public function attachInviteClient(): void
    {
        if (!$this->requireOwner()) {
            return;
        }

        $post = $this->postData();
        $return = OwnerInviteBulkAction::safeReturn($post['return'] ?? null);
        $inviteId = $post['invite_id'] ?? null;

        if (!array_key_exists('client_id', $post)) {
            $invite = is_string($inviteId) && Security::isValidUuid($inviteId)
                ? $this->invites->clientOfInvite(strtolower($inviteId))
                : null;
            if ($invite === null) {
                $this->setFlash(['type' => 'error', 'message' => 'Приглашение не найдено: возможно, оно уже удалено.']);
                $this->redirect($return);
            }

            echo $this->view->render('owner-invite-attach', [
                'invite' => $invite,
                'clients' => $this->clients->listForOwner(),
                'return_url' => $return,
                'attach_form_key' => $this->inviteAttach()->issueKey(),
            ]);

            return;
        }

        $this->setFlash($this->inviteAttach()->submit($post));
        $this->redirect($return);
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
        // Тот же актуальный результат, что у клиента, в PDF и во внешнем разборе.
        $case = $this->sessionManager->withFreshResults($case, $module);
        $presenter = new InvitedCasePresenter();
        $case['answer_rows'] = $presenter->answers($module, $case['answers']);
        $case['result_sections'] = $presenter->resultSections($module, $case['calculated_results']);
        // Раскладка рабочего места (04.D3): сводка сверху, подробности свёрнуты.
        $case['workspace'] = $presenter->workspace($case['result_sections']);
        $case['pair'] = $this->pairSection($sessionId, $module, $presenter, $case);

        $ai = $this->aiSection($sessionId, (string) $case['test_slug']);
        $trashed = $case['trashed_at'] !== null;
        if ($trashed) {
            // Только чтение: форм заказа и правки в корзине нет.
            $ai['available'] = false;
        }

        echo $this->view->render('owner-invited-case', [
            'flash' => $this->takeFlash(),
            'case' => $case,
            'trashed' => $trashed,
            // Меню «⋯» в шапке (04.D3): архив, корзина, восстановление — общая форма приглашений.
            'bulk_form_key' => $this->inviteBulk()->issueKey(),
            'trash_days' => TestInviteService::TRASH_RETENTION_DAYS,
            'note_form_key' => $trashed ? null : $this->caseNote()->issueKey(),
            'note_max' => OwnerInviteSubmission::NOTE_MAX_LENGTH,
            // Кейс в корзине — только чтение: привязка и смена клиента недоступны.
            'attach_form_key' => $trashed ? null : $this->inviteAttach()->issueKey(),
            'clients' => $trashed ? [] : $this->clients->listForOwner(),
            'ai' => $ai,
            'notify' => $this->notifySection($sessionId, $case, $ai),
            // У каждой формы заказа на странице свой одноразовый ключ (07.K6b).
            'order_keys' => $ai['available'] ? $this->caseReportOrder()->issueKeys() : null,
        ]);
    }

    private function caseNote(): OwnerCaseNoteUpdate
    {
        return new OwnerCaseNoteUpdate($this->invites, $this->formOnce());
    }

    /**
     * Заметка специалиста к кейсу — правка на месте в шапке карточки (04.D3).
     * POST /admin/invited-case/{sessionId}/note
     *
     * CSRF проверяет общий middleware, повтор — одноразовый ключ формы. Кейс
     * в корзине только для чтения: `ownedCase(..., true)` возвращает к нему с
     * сообщением, ничего не меняя.
     */
    public function updateCaseNote(string $sessionId): void
    {
        if ($this->ownedCase($sessionId, true) === null) {
            return;
        }

        $this->setFlash($this->caseNote()->submit($sessionId, $this->postData()));
        $this->redirect('/admin/invited-case/' . $sessionId);
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
        $session = $this->sessionManager->getRetainedSessionById($sessionId);
        if ($session === null) {
            return null;
        }

        $pair = (new ResultPresenter($this->db, $this->sessionManager))->pairViewData($session, $module);
        if ($pair === null) {
            return null;
        }

        $partner = $this->sessionManager->getRetainedSessionById($pair['partner_session_id']);
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
        if ($this->ownedCase($sessionId, true) === null) {
            return;
        }

        $sent = ClientReportNotifier::fromConfig($this->db)->notify($sessionId);
        $this->setFlash($sent
            ? ['type' => 'success', 'message' => 'Письмо отправлено. В нём нет текста разбора и ссылки: клиент открывает свою страницу результата.']
            : ['type' => 'error', 'message' => 'Письмо не отправлено. Нужны опубликованный разбор и email в карточке клиента; повторное уведомление возможно через ' . ClientReportNotifier::MIN_INTERVAL_MINUTES . ' минут.']);
        $this->redirect('/admin/invited-case/' . $sessionId);
    }

    /**
     * Самолечение очереди: если у кейса есть задание в ожидании, а воркера нет
     * (его убил хостинг, или запуск был погашен троттлингом), просмотр карточки
     * или опрос статуса поднимает воркер заново. Троттлинг лаунчера не даёт
     * плодить процессы при частом опросе.
     */
    private function relaunchWorkerIfQueued(string $sessionId): void
    {
        $pending = $this->db->selectOne(
            'SELECT id FROM ai_reports WHERE session_id = :session_id AND status = :status LIMIT 1',
            ['session_id' => $sessionId, 'status' => AiReportRepository::STATUS_PENDING],
        );
        if ($pending === null) {
            return;
        }

        BackgroundWorkerLauncher::fromConfig(require dirname(__DIR__) . '/config.php')->launch(BackgroundWorkerLauncher::DRAIN_LIMIT);
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
        // Зависшие задания (воркер убит хостингом) возвращает в очередь только
        // сам воркер, а он стартует лишь при новом заказе. Чтобы карточка не
        // показывала «в работе» бесконечно, срок проверяется и при просмотре.
        (new AiReportRepository($this->db))->releaseStuck();
        $this->relaunchWorkerIfQueued($sessionId);

        $session = $this->sessionManager->getRetainedSessionById($sessionId);
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
            $ready = ($report['status'] ?? '') === AiReportRepository::STATUS_READY;
            // Понятный разбор читается в карточке в последней версии (04.D3):
            // это рабочий текст специалиста, клиент видит только опубликованную.
            $latest = $kind === Prompt::KIND_CLEAR && $ready ? $revisions->latest((string) $report['id']) : null;

            $kinds[] = [
                'kind' => $kind,
                'title' => $kind === Prompt::KIND_CLEAR ? 'Понятный разбор' : 'Профессиональное заключение',
                'report_id' => $report['id'] ?? null,
                'status' => $report['status'] ?? 'none',
                'failure_reason' => $report['failure_reason'] ?? null,
                'html' => match (true) {
                    !$ready => null,
                    $kind === Prompt::KIND_PROFESSIONAL => ReportMarkdown::toHtml((string) $report['content']),
                    default => ReportMarkdown::toHtml((string) ($latest['content'] ?? $report['content'])),
                },
                'latest_no' => $latest === null ? null : (int) $latest['revision_no'],
                'requested_at' => $report['created_at'] ?? null,
                'ready_at' => $ready ? ($report['completed_at'] ?? $report['updated_at'] ?? null) : null,
                'published' => $published,
                // Версии смотрят отдельной страницей (профессиональное, 07.K7)
                // или в редакторе (понятный), только когда есть что сравнивать.
                'versions_count' => $report !== null ? $revisions->count((string) $report['id']) : 0,
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
        $case = $this->ownedCase($sessionId, true);
        if ($case === null) {
            return;
        }

        $session = $this->sessionManager->getRetainedSessionById($sessionId);
        if ($session === null) {
            $this->notFound();

            return;
        }

        $mode = (new ResultPresenter($this->db, $this->sessionManager))->reportMode($session);
        $order = $this->caseReportOrder()->submit($sessionId, (string) $case['test_slug'], $mode, $this->postData());
        $queued = $order['queued'];
        // Повтор того же отправления (двойной клик) получает ответ первого и
        // ничего не запускает: задания уже стоят или отработаны.
        if ($order['type'] === 'error' || $queued === 0) {
            $this->caseFlashBack($sessionId, $order['type'] !== 'error', $order['message']);
        }

        $this->setFlash(['type' => 'success', 'message' => $order['message']]);

        // Расписания на хостинге нет, поэтому очередь двигает сам заказ. Если
        // задан CLI PHP (`AI_WORKER_PHP_BIN`), работа уходит в отдельный
        // процесс и переживает 504 от nginx — браузеру остаётся обычный 303.
        // Иначе остаётся прежний путь: ответ отдан, работа доделывается здесь
        // же (07.16–07.17).
        if (BackgroundWorkerLauncher::fromConfig(require dirname(__DIR__) . '/config.php')->launch($queued)) {
            $this->redirect($this->caseAiAnchor($sessionId));
        }

        header('Location: ' . $this->caseAiAnchor($sessionId), true, 303);
        header('Content-Length: 0');
        ResponseFinisher::finish();

        $reports = new AiReportRepository($this->db);
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

    /**
     * Возврат к разделу «ИИ-разбор», а не к началу длинной карточки.
     *
     * Карточка кейса — это весь результат теста целиком; без якоря специалист
     * после заказа видит верх страницы и не понимает, что произошло.
     */
    private function caseAiAnchor(string $sessionId): string
    {
        return '/admin/invited-case/' . $sessionId . '#owner-case-ai';
    }

    private function caseReportOrder(): OwnerCaseReportOrder
    {
        $settings = new AiSettings($this->db);

        return new OwnerCaseReportOrder(
            new AiReportRepository($this->db),
            PromptRegistry::default($this->db),
            $settings,
            new AiReportContextBuilder($this->sessionManager, $this->moduleLoader, $settings),
            $this->formOnce(),
        );
    }

    private function reportGenerator(): AiReportGenerator
    {
        $aiSettings = new AiSettings($this->db);
        $settings = AiProviderSettings::fromConfig(require dirname(__DIR__) . '/config.php', $aiSettings);

        return new AiReportGenerator(
            new AiReportRepository($this->db),
            new AiReportContextBuilder($this->sessionManager, $this->moduleLoader, $aiSettings),
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
        (new AiReportRepository($this->db))->releaseStuck();
        $this->relaunchWorkerIfQueued($sessionId);

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
     * История версий профессионального заключения, только чтение (07.K7).
     * Понятный разбор уходит в свой редактор: история там уже есть.
     * GET /admin/invited-case/{sessionId}/reports/{reportId}/versions
     */
    public function caseReportVersions(string $sessionId, string $reportId): void
    {
        $case = $this->ownedCase($sessionId);
        if ($case === null) {
            return;
        }

        $report = Security::isValidUuid($reportId)
            ? (new AiReportRepository($this->db))->find($reportId)
            : null;
        if ($report === null || (string) $report['session_id'] !== $sessionId) {
            $this->notFound();

            return;
        }
        if ((string) $report['report_kind'] === Prompt::KIND_CLEAR) {
            $this->redirect('/admin/invited-case/' . $sessionId . '/reports/' . $reportId . '/edit');
        }
        if ((string) $report['report_kind'] !== Prompt::KIND_PROFESSIONAL) {
            $this->notFound();

            return;
        }

        $revisions = new AiReportRevisionService($this->db);
        if ((string) $report['status'] === AiReportRepository::STATUS_READY && $revisions->count($reportId) === 0) {
            // Готовый отчёт до введения истории получает версию №1; у отчёта с
            // историей текст не пересевается (профессиональное не правится).
            $revisions->seedFromContent($reportId, (string) ($report['content'] ?? ''));
        }

        $versions = [];
        foreach (array_reverse($revisions->revisions($reportId)) as $revision) {
            $revision['html'] = ReportMarkdown::toHtml((string) $revision['content']);
            $versions[] = $revision;
        }

        header('X-Robots-Tag: noindex, nofollow');

        echo $this->view->render('owner-report-versions', [
            'session_id' => $sessionId,
            'case' => $case,
            'versions' => $versions,
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
        // Из карточки кейса (04.D3) — обратно к разборам, из редактора — в редактор.
        $this->redirect(($_POST['return'] ?? null) === 'case'
            ? $this->caseAiAnchor($sessionId)
            : '/admin/invited-case/' . $sessionId . '/reports/' . $reportId . '/edit');
    }

    /**
     * Выгрузка кейса в PDF.
     * GET /admin/invited-case/{sessionId}/export.pdf
     *
     * Документ собирается на лету и никуда не сохраняется: файл на диске
     * пережил бы удаление кейса и стал бы копией клинических данных вне
     * lifecycle-политики (PRODUCT_RULES §11). Поэтому генератор вызывается
     * без записи в storage, а байты уходят прямо в ответ.
     */
    public function exportCasePdf(string $sessionId): void
    {
        $prepared = $this->caseExport($sessionId);
        if ($prepared === null) {
            return;
        }

        [$document, $module] = $prepared;

        $html = $this->view->render('owner-case-export-pdf', [
            'document' => $document,
            'sections_html' => $this->titledSections($document['sections']),
            // График совмещённых профилей — SVG: DomPDF его не рисует и
            // вываливает наружу голые подписи вместе с подсказкой про курсор.
            // В PDF остаётся таблица сравнения, сам график живёт на версии для
            // печати, где браузер рисует тот же SVG.
            'pair_html' => $document['pair'] === null
                ? ''
                : $this->titledSections(array_values(array_filter(
                    $document['pair']['sections'],
                    static fn ($section): bool => $section->type !== ResultSection::TYPE_PAIR_CHART,
                ))),
        ]);

        // Portrait для всего документа: заключение и разбор — сплошной текст,
        // а таблицы промптами ограничены пятью колонками и помещаются.
        $pdf = (new PDFGenerator())->generate($html, 'case_export.pdf', false);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="case_' . $module . '_' . date('YmdHis') . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /**
     * Выгрузка кейса в Word.
     * GET /admin/invited-case/{sessionId}/export.docx
     *
     * Тот же документ, что PDF и печать (один `CaseExportPresenter`), но
     * редактируемый: специалист дорабатывает заключение в Word. Файл
     * собирается в памяти запроса и на сервере не остаётся.
     */
    public function exportCaseDocx(string $sessionId): void
    {
        $prepared = $this->caseExport($sessionId);
        if ($prepared === null) {
            return;
        }

        [$document, $module] = $prepared;

        // Без ext-zip собрать .docx нельзя: вместо фатальной ошибки — понятное
        // сообщение в карточке кейса, PDF и печать при этом работают.
        if (!class_exists(\ZipArchive::class)) {
            $this->setFlash(['type' => 'error', 'message' => 'Выгрузка в Word недоступна на этом сервере (нет расширения zip). Используйте PDF или версию для печати.']);
            $this->redirect('/admin/invited-case/' . $sessionId);
        }

        $docx = (new CaseExportDocx(
            fn (string $template, array $data): string => $this->view->render($template, $data),
        ))->render($document);

        header('Content-Type: ' . CaseExportDocx::CONTENT_TYPE);
        header('Content-Disposition: attachment; filename="case_' . $module . '_' . date('YmdHis') . '.docx"');
        header('Content-Length: ' . strlen($docx));
        // Клинический материал: ни в индекс, ни в общий кэш.
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        echo $docx;
        exit;
    }

    /**
     * Версия кейса для печати.
     * GET /admin/invited-case/{sessionId}/print
     *
     * Тот же документ страницей браузера: сохранение в PDF или Word делает сам
     * браузер, и специалисту не нужен отдельный конвертер.
     */
    public function exportCasePrint(string $sessionId): void
    {
        $prepared = $this->caseExport($sessionId);
        if ($prepared === null) {
            return;
        }

        // Страница несёт клинический материал: в индекс она не попадает ни
        // при какой ошибке конфигурации.
        header('X-Robots-Tag: noindex, nofollow');

        echo $this->view->render('owner-case-export', [
            'document' => $prepared[0],
            'case_id' => $sessionId,
        ]);
    }

    /**
     * Секции результата для PDF вместе с их заголовками.
     *
     * `ResultSectionRenderer` рендерит только блоки: в PDF результата заголовки
     * секций и не показывались, и «Основные шкалы» от «Дополнительных» было не
     * отличить. Сам рендерер здесь не меняется — заголовок добавляется рядом,
     * чтобы PDF результата остался прежним.
     *
     * @param list<\PsyTest\Modules\ResultSection> $sections
     */
    private function titledSections(array $sections): string
    {
        $renderer = ResultSectionRenderer::forView($this->view);
        $html = '';
        foreach ($sections as $section) {
            if ($section->title !== null && $section->title !== '') {
                $html .= '<h3>' . htmlspecialchars($section->title, ENT_QUOTES) . '</h3>';
            }
            $html .= $renderer->renderToHtml([$section]);
        }

        return $html;
    }

    /**
     * Общая подготовка выгрузки: доступ, модуль и собранный документ.
     *
     * @return array{0: array<string, mixed>, 1: string}|null
     */
    private function caseExport(string $sessionId): ?array
    {
        $case = $this->ownedCase($sessionId);
        if ($case === null) {
            return null;
        }

        $module = $this->moduleLoader->getModule((string) $case['test_slug']);
        if ($module === null) {
            $this->notFound();

            return null;
        }

        $document = (new CaseExportPresenter($this->db, $this->sessionManager))->build(
            $case,
            $module,
            CaseExportPresenter::options($_GET),
        );

        return [$document, (string) $case['test_slug']];
    }

    /**
     * Кейс по приглашению, доступный владельцу, или 404.
     *
     * @return array<string, mixed>|null
     */
    private function ownedCase(string $sessionId, bool $writable = false): ?array
    {
        if (!$this->requireOwner()) {
            return null;
        }

        $case = Security::isValidUuid($sessionId) ? $this->invites->claimedCaseForOwner($sessionId) : null;
        if ($case === null) {
            $this->notFound();

            return null;
        }
        // Кейс в корзине доступен только для чтения: заказ разборов, правка и
        // публикация возвращаются вместе с восстановлением.
        if ($writable && $case['trashed_at'] !== null) {
            $this->setFlash(['type' => 'error', 'message' => 'Кейс в корзине: он только для чтения. Восстановите его, чтобы заказывать и править разборы.']);
            $this->redirect('/admin/invited-case/' . $sessionId);
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
        if ($this->ownedCase($sessionId, true) === null) {
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
        $this->redirect($this->caseAiAnchor($sessionId));
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

    /** Проверка полей карточки до записи — общая с формой приглашения. */
    private function isValidClientInput(mixed $label, mixed $note, mixed $email = ''): bool
    {
        return TherapistClientService::isValidInput($label, $note, $email);
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
            'smil_glossary_mode' => $settings->smilGlossaryMode(),
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

        $glossaryMode = $_POST['smil_glossary_mode'] ?? null;
        $settings->setSmilGlossaryMode(is_string($glossaryMode) ? $glossaryMode : SmilGlossaryCompactor::MODE_FULL);

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
     * @return array{system: string, user: string|null, context: array<string, mixed>, error: string|null, glossary_mode: string|null, length: int|null}
     */
    private function buildPreview(Prompt $prompt, string $test, string $mode): array
    {
        $module = $this->moduleLoader->getModule($test);
        $empty = ['system' => $prompt->text, 'user' => null, 'context' => [], 'glossary_mode' => null, 'length' => null];

        if ($module === null) {
            return $empty + ['error' => "Методика «{$test}» не установлена."];
        }

        try {
            // Настройки кабинета передаются и сюда: предпросмотр обязан
            // показывать нагрузку того же режима, что уйдёт боевым запросом.
            $context = PromptFixtureContext::build($module, $mode, new AiSettings($this->db));
        } catch (\Throwable $e) {
            return $empty + ['error' => $e->getMessage()];
        }

        $user = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return [
            'system' => $prompt->text,
            'user' => $user,
            'context' => $context,
            // Режим и размер видно прямо на странице: владелец сравнивает
            // полный и компактный глоссарий по одному и тому же кейсу (07.G6).
            'glossary_mode' => isset($context['glossary_mode']) ? (string) $context['glossary_mode'] : null,
            // Провайдеру уходит компактный JSON, им и меряем.
            'length' => mb_strlen((string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
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
