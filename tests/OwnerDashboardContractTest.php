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
        self::assertStringContainsString("\$router->post('/admin/case/attach'", $routes);
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
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/request'", $routes);
        self::assertStringContainsString("\$router->get('/admin/invited-case/{sessionId}/reports/status'", $routes);
        self::assertStringContainsString("\$router->get('/admin/invited-case/{sessionId}/reports/{reportId}/edit'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/{reportId}/revisions'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/{reportId}/restore'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/{reportId}/publish'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/{reportId}/unpublish'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/notify'", $routes);
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

    /**
     * Привязка найденной сессии к карточке клиента (07.K2c).
     *
     * Форма живёт на той же странице, что и разовый поиск по токену, поэтому
     * подчиняется тем же правилам: токен результата не рендерится, действие
     * идёт POST-ом под общим CSRF, а сессия посетителя сюда не попадает.
     */
    public function testAttachingAFoundSessionIsAPostFormWithoutTheResultToken(): void
    {
        $template = (string) file_get_contents($this->projectRoot . '/templates/owner-dashboard.twig');
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');

        self::assertStringContainsString('/admin/case/attach', $template);
        self::assertStringContainsString('name="new_client_label"', $template);
        self::assertStringContainsString('name="session_id" value="{{ case.id }}"', $template);
        self::assertStringNotContainsString('case.session_token', $template);
        self::assertStringNotContainsString('session_token', $template);
        self::assertStringContainsString("case.retention_class != 'account'", $template);
        self::assertStringContainsString('attachToClient', $controller);
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

    /**
     * Парное прохождение в карточке кейса (07.K1b).
     *
     * Парный блок кабинета собирается тем же презентером, что и страница
     * клиента, но остаётся кабинетом: ни bearer-токена, ни адреса клиентского
     * результата, ни приглашения второму партнёру здесь быть не может.
     */
    public function testPairCaseCardReusesTheClientRenderWithoutClientOnlyActions(): void
    {
        $template = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');
        $presenter = (string) file_get_contents($this->projectRoot . '/core/ResultPresenter.php');

        self::assertStringContainsString('case.pair.sections', $template);
        self::assertStringContainsString('case.pair.questionnaires', $template);
        self::assertStringNotContainsString('session_token', $template);
        self::assertStringNotContainsString('/result/', $template);
        self::assertStringNotContainsString('pair-invite', $template);

        // Расчёт не дублируется: карточка берёт готовые парные секции.
        self::assertStringContainsString('pairViewData', $controller);
        self::assertStringContainsString('pairViewData', $presenter);
        self::assertStringNotContainsString('comparePairResults', $controller);
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

    /**
     * Редактор разбора остаётся кабинетом специалиста (D-054).
     *
     * Он показывает черновик модели и неопубликованные правки, поэтому его
     * шаблоны обязаны жить по тем же правилам, что и остальной кабинет: без
     * bearer-токена результата и под CSRF на каждом изменяющем действии.
     */
    public function testReportEditorStaysOwnerOnlyAndConfirmsPublication(): void
    {
        $editor = (string) file_get_contents($this->projectRoot . '/templates/owner-report-editor.twig');
        $invitedCase = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');

        foreach ([$editor, $invitedCase] as $template) {
            self::assertStringContainsString('name="csrf_token"', $template);
            self::assertStringNotContainsString('session_token', $template);
        }

        self::assertStringContainsString('name="confirm_publish" value="publish" required', $editor);
        self::assertStringContainsString('name="ai_consent" value="1" required', $invitedCase);
        self::assertStringContainsString('name="owner_context"', $invitedCase);

        // Каждое действие редактора закрыто владельческой проверкой доступа.
        foreach (
            [
                'requestCaseReports',
                'caseReportStatus',
                'editCaseReport',
                'saveCaseReportRevision',
                'restoreCaseReportRevision',
                'publishCaseReport',
                'unpublishCaseReport',
            ] as $action
        ) {
            self::assertStringContainsString('public function ' . $action . '(', $controller, $action);
        }
        self::assertStringContainsString('private function ownedCase(', $controller);
        self::assertStringContainsString('requireOwner()', $controller);
    }

    /**
     * Клинический контекст пишет специалист руками.
     *
     * Подпись и заметка карточки клиента не подставляются в него автоматически:
     * это личные данные, а наружу уходит обезличенный контекст (§11).
     */
    public function testOwnerContextIsNeverPrefilledFromClientLabelOrNote(): void
    {
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');
        $requestAction = substr(
            $controller,
            (int) strpos($controller, 'public function requestCaseReports('),
            (int) strpos($controller, 'public function caseReportStatus(') - (int) strpos($controller, 'public function requestCaseReports('),
        );

        self::assertStringContainsString("\$_POST['owner_context']", $requestAction);
        foreach (['client_label', 'owner_note', 'client_id', 'label'] as $ownerOnlyField) {
            self::assertStringNotContainsString($ownerOnlyField, $requestAction, $ownerOnlyField);
        }

        $template = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');
        $orderForm = substr(
            $template,
            (int) strpos($template, 'name="owner_context"') - 400,
            600,
        );
        self::assertStringNotContainsString('case.client_label', $orderForm);
        self::assertStringNotContainsString('case.owner_note', $orderForm);
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

    /**
     * Email клиента живёт только в кабинете (D-054, PRODUCT_RULES §11).
     *
     * Он появился ради одного письма, поэтому проверяется именно то, что он
     * никуда больше не утекает: ни на страницу респондента, ни в контекст
     * модели, ни в презентер кейса, ни в журнал действий.
     */
    public function testClientEmailNeverLeavesTheDashboard(): void
    {
        $respondentPage = (string) file_get_contents($this->projectRoot . '/templates/test-invite-start.twig');
        $presenter = (string) file_get_contents($this->projectRoot . '/core/InvitedCasePresenter.php');

        foreach (['client_email', 'clients.email', 'client.email'] as $ownerOnlyField) {
            self::assertStringNotContainsString($ownerOnlyField, $respondentPage, $ownerOnlyField);
            self::assertStringNotContainsString($ownerOnlyField, $presenter, $ownerOnlyField);
        }
        self::assertStringNotContainsString('email', $presenter);

        foreach (glob($this->projectRoot . '/core/Ai/*.php') ?: [] as $aiSource) {
            self::assertStringNotContainsString('email', (string) file_get_contents($aiSource), $aiSource);
        }

        // Карточка кейса знает только «адрес есть/нет», сам адрес там не рендерится.
        $invitedCase = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');
        self::assertStringContainsString('notify.has_email', $invitedCase);
        self::assertStringNotContainsString('client.email', $invitedCase);
        self::assertStringNotContainsString('notify.email', $invitedCase);

        // В журнал действий адрес не пишется: он вообще не проходит через него.
        $clientService = (string) file_get_contents($this->projectRoot . '/core/TherapistClientService.php');
        $auditEvent = substr(
            $clientService,
            (int) strpos($clientService, 'private function writeOwnerAuditEvent('),
        );
        self::assertStringNotContainsString('email', $auditEvent);
    }

    /**
     * Уведомление — отдельное явное действие специалиста (D-054).
     *
     * Автоматической отправки нет: письмо уходит только из своего маршрута под
     * `requireOwner()`, и ни публикация, ни готовность черновика его не зовут.
     */
    public function testTheClientNotificationIsOwnerOnlyManualAndCarriesNeitherLinkNorReport(): void
    {
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');
        $notifier = (string) file_get_contents($this->projectRoot . '/core/ClientReportNotifier.php');

        self::assertStringContainsString('public function notifyClientAboutReport(', $controller);
        self::assertStringContainsString('ownedCase($sessionId)', $controller);

        foreach (['publishCaseReport', 'saveCaseReportRevision', 'requestCaseReports'] as $action) {
            $start = (int) strpos($controller, 'public function ' . $action . '(');
            $body = substr($controller, $start, 1600);
            self::assertStringNotContainsString('ClientReportNotifier', $body, $action);
        }
        self::assertStringNotContainsString('ClientReportNotifier', (string) file_get_contents($this->projectRoot . '/core/Ai/AiReportRepository.php'));

        // Ссылка на результат — bearer-токен, и в письме ей не место.
        $body = substr($notifier, (int) strpos($notifier, 'public static function body('));
        foreach (['session_token', '/result/', 'appUrl', 'http'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body, $forbidden);
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
