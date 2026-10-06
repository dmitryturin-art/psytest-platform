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
        self::assertStringContainsString("\$router->get('/admin/invited-case/{sessionId}/reports/{reportId}/versions'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/{reportId}/revisions'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/{reportId}/restore'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/{reportId}/publish'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/{reportId}/unpublish'", $routes);
        self::assertStringContainsString("\$router->post('/admin/invited-case/{sessionId}/reports/notify'", $routes);
        self::assertStringContainsString("\$router->get('/admin/prompts'", $routes);
        self::assertStringContainsString("\$router->post('/admin/prompts/settings'", $routes);
        self::assertStringContainsString("\$router->get('/admin/prompts/{test}/{mode}/{kind}'", $routes);
        self::assertStringContainsString("\$router->get('/admin/prompts/{test}/{mode}/{kind}/preview'", $routes);
        self::assertStringContainsString("\$router->post('/admin/prompts/{test}/{mode}/{kind}/versions'", $routes);
        self::assertStringContainsString("\$router->post('/admin/prompts/{test}/{mode}/{kind}/publish'", $routes);
        self::assertStringContainsString("\$router->post('/admin/prompts/{test}/{mode}/{kind}/reset'", $routes);
        self::assertStringContainsString("\$router->post('/admin/prompts/{test}/{mode}/{kind}/trial'", $routes);
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

        // С 07.K6b заказ разбирает OwnerCaseReportOrder: контекст — только поле формы.
        $order = (string) file_get_contents($this->projectRoot . '/core/OwnerCaseReportOrder.php');
        self::assertStringContainsString('->submit($sessionId, (string) $case[\'test_slug\'], $mode, $this->postData())', $requestAction);
        self::assertStringContainsString("\$post['owner_context']", $order);
        foreach (['client_label', 'owner_note', 'client_id', 'label'] as $ownerOnlyField) {
            self::assertStringNotContainsString($ownerOnlyField, $requestAction, $ownerOnlyField);
            self::assertStringNotContainsString($ownerOnlyField, $order, $ownerOnlyField);
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
    public function testTheClientNotificationIsOwnerOnlyManualAndCarriesOnlyTheResultLink(): void
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

        // Решение владельца 15.09: письмо несёт ссылку на страницу результата
        // (клиент мог её закрыть), но не подпись клиента и не текст разбора.
        $body = substr($notifier, (int) strpos($notifier, 'public static function body('));
        self::assertStringContainsString('$resultLink', $body);
        foreach (['label', 'owner_note', 'content'] as $forbidden) {
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

    /**
     * Редактор промптов (07.WP9).
     *
     * Здесь редактируется рабочий инструмент владельца, а не чьи-то результаты:
     * каждый маршрут закрыт `requireOwner()`, каждое изменяющее действие идёт
     * POST-ом под общим CSRF, а на страницах нет ни одного поля с данными
     * клиента — предпросмотр строится на синтетическом контексте.
     */
    public function testPromptEditorRoutesRequireTheOwnerAndChangeStateOnlyByPost(): void
    {
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');

        foreach ([
            'public function prompts(): void',
            'public function savePromptSettings(): void',
            'public function createPromptVersion(string $test, string $mode, string $kind): void',
            'public function publishPromptVersion(string $test, string $mode, string $kind): void',
            'public function resetPromptVersion(string $test, string $mode, string $kind): void',
        ] as $signature) {
            $offset = strpos($controller, $signature);
            self::assertIsInt($offset, "Нет действия {$signature}.");
            self::assertStringContainsString(
                'requireOwner()',
                substr($controller, $offset, 260),
                "Действие {$signature} обязано начинаться с проверки владельца.",
            );
        }

        // Карточка ключа и предпросмотр закрыты той же проверкой через общий
        // сборщик данных страницы.
        $view = strpos($controller, 'private function promptKeyView(');
        self::assertIsInt($view);
        self::assertStringContainsString('requireOwner()', substr($controller, $view, 260));
    }

    public function testPromptTemplatesCarryCsrfAndNoClientData(): void
    {
        $list = (string) file_get_contents($this->projectRoot . '/templates/owner-prompts.twig');
        $card = (string) file_get_contents($this->projectRoot . '/templates/owner-prompt-key.twig');

        foreach ([$list, $card] as $template) {
            self::assertStringContainsString('csrf_field()', $template);

            // Ни сессий, ни клиентов, ни токенов результата на этих страницах нет.
            foreach (['session_token', 'client.', 'case.', 'result_reference', 'invite.token'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $template, "Шаблон промптов не должен упоминать «{$forbidden}».");
            }
        }

        self::assertStringContainsString('name="ai_enabled"', $list);
        self::assertStringContainsString('name="ai_model"', $list);
        // Режим глоссария СМИЛ сохраняется рядом с моделью (07.G6), а
        // предпросмотр показывает, каким режимом собрана нагрузка и сколько
        // в ней знаков, — иначе сравнивать разборы не с чем.
        self::assertStringContainsString('name="smil_glossary_mode" value="full"', $list);
        self::assertStringContainsString('name="smil_glossary_mode" value="compact"', $list);
        self::assertStringContainsString('preview.glossary_mode', $card);
        self::assertStringContainsString('preview.length', $card);
        self::assertStringContainsString('name="confirm_publish" value="1" required', $card);
        self::assertStringContainsString('name="confirm_trial" value="1" required', $card);
        self::assertStringContainsString('/reset', $card);
        self::assertStringContainsString('name="allows_owner_context"', $card);
    }

    public function testOwnerNavigationLinksThePromptEditor(): void
    {
        foreach (['owner-dashboard', 'owner-clients', 'owner-client', 'owner-invited-case'] as $template) {
            self::assertStringContainsString(
                '/admin/prompts',
                (string) file_get_contents($this->projectRoot . '/templates/' . $template . '.twig'),
                "В навигации кабинета ({$template}) нет ссылки на промпты.",
            );
        }
    }

    /**
     * Заказ черновиков возвращает к разделу «ИИ-разбор» (07.K5a3).
     *
     * Карточка кейса — это результат теста целиком; без якоря специалист после
     * заказа видит верх страницы, а сообщение об успехе или отказе остаётся вне
     * поля зрения. Поэтому и редирект, и флеш привязаны к самому разделу.
     */
    public function testOrderingDraftsReturnsToTheAiSectionWithItsOwnFlash(): void
    {
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');
        $template = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');

        self::assertStringContainsString("'#owner-case-ai'", $controller);
        self::assertStringContainsString('caseAiAnchor($sessionId)', $controller);
        self::assertStringContainsString('id="owner-case-ai"', $template);
        // Флеш живёт внутри раздела; наверху он остаётся только когда раздела нет.
        self::assertStringContainsString('{% if flash and not aiSectionShown %}', $template);
        self::assertStringContainsString('owner-case-ai-waiting', $template);
        self::assertStringContainsString('owner-spinner', $template);
        self::assertStringContainsString(
            'Черновики готовятся, обычно 2–5 минут. Страница обновится сама.',
            $template,
        );
        self::assertStringContainsString('.owner-spinner', (string) file_get_contents($this->projectRoot . '/public/css/main.css'));
    }

    /**
     * Опрос состояния черновиков — owner-only и только на карточке кейса.
     *
     * Токен результата в кабинете не рендерится (см. тест выше), и опрос не
     * должен становиться вторым способом его раздать: запрос идёт сессией
     * кабинета на owner-only JSON.
     */
    public function testTheCaseStatusPollIsOwnerOnlyAndLoadedOnlyOnTheCaseCard(): void
    {
        $script = (string) file_get_contents($this->projectRoot . '/public/js/owner-case.js');

        self::assertStringNotContainsString('session_token', $script);
        self::assertStringNotContainsString('Bearer', $script);
        self::assertStringNotContainsString('/result/', $script);
        self::assertStringContainsString('data-case-reports', $script);

        foreach (['owner-dashboard', 'owner-clients', 'owner-client', 'owner-report-editor'] as $template) {
            self::assertStringNotContainsString(
                'js/owner-case.js',
                (string) file_get_contents($this->projectRoot . '/templates/' . $template . '.twig'),
                "Опрос состояния подключён вне карточки кейса ({$template}).",
            );
        }

        $case = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');
        self::assertStringContainsString("asset('js/owner-case.js')", $case);
        self::assertStringNotContainsString('session_token', $case);
        self::assertStringContainsString('/reports/status', $case);
    }

    /**
     * «Обновить» действительно обновляет страницу (07.K5d).
     *
     * Раньше это была ссылка на текущий адрес с якорем: браузер по ней только
     * прокручивает страницу и ничего не перезапрашивает, поэтому специалист
     * нажимал кнопку и видел прежнее состояние черновиков.
     */
    public function testTheRefreshControlReloadsThePageInsteadOfJumpingToTheAnchor(): void
    {
        $case = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');
        $script = (string) file_get_contents($this->projectRoot . '/public/js/owner-case.js');

        self::assertStringContainsString('<button type="button" class="btn btn-outline" data-reload>Обновить</button>', $case);
        self::assertStringNotContainsString('#owner-case-ai">Обновить</a>', $case);

        // Без JS ту же работу делает GET с меняющимся параметром: адрес
        // отличается от текущего, и браузер идёт на сервер.
        self::assertStringContainsString('<noscript>', $case);
        self::assertStringContainsString('<input type="hidden" name="_" value="{{ "now"|date("U") }}">', $case);

        self::assertStringContainsString("querySelectorAll('[data-reload]')", $script);
        self::assertStringContainsString('window.location.reload()', $script);
    }

    /**
     * Опрос реагирует на изменение статуса любого вида разбора (07.K5d).
     *
     * Прежний скрипт перезагружал страницу, только когда не осталось ни одного
     * незавершённого задания. Понятный разбор при этом мог быть готов минутами
     * раньше профессионального заключения, а специалист всё это время видел
     * «задание в работе» и не получал ссылку на редактор.
     */
    public function testThePollReloadsOnAnyStatusChangeAndKeepsPollingWhileWorkRemains(): void
    {
        $script = (string) file_get_contents($this->projectRoot . '/public/js/owner-case.js');
        $case = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');

        // Решение оформлено отдельными функциями, поэтому его видно по тексту.
        self::assertMatchesRegularExpression(
            '/var statusChanged = function \(before, after\) \{.*?return before\[kind\] !== after\[kind\];.*?\};/s',
            $script,
        );
        self::assertMatchesRegularExpression(
            '/if \(statusChanged\(initial, current\)\) \{.*?window\.location\.reload\(\);/s',
            $script,
        );
        // Пока есть незавершённые задания, опрос продолжается.
        self::assertMatchesRegularExpression(
            '/if \(!stillWorking\(current\)\) \{\s*stop\(\);/s',
            $script,
        );
        self::assertStringContainsString('if (!stillWorking(initial)) return;', $script);

        // Снимок статусов берётся из разметки карточки, а адрес опроса — из секции.
        self::assertStringContainsString('.owner-case-ai-item[data-kind]', $script);
        self::assertStringContainsString('data-kind="{{ item.kind }}" data-status="{{ item.status }}"', $case);
        self::assertStringContainsString('data-status-url="{{ basePath }}/admin/invited-case/{{ case.id }}/reports/status"', $case);
    }

    /**
     * Визуальный редактор разбора работает на локальной библиотеке (07.K5d).
     *
     * Кабинет не ходит во внешние сервисы, поэтому Toast UI Editor лежит в
     * `public/vendor` и должен быть под контролем версий: иначе он не попадёт
     * в релизный артефакт (`bin/build-release.sh` сверяет его с `git ls-files`).
     * Textarea остаётся источником правды: без JS страница работает как прежде.
     */
    public function testTheVisualEditorIsLocalAndKeepsTheTextareaAsTheSourceOfTruth(): void
    {
        $editor = (string) file_get_contents($this->projectRoot . '/templates/owner-report-editor.twig');
        $script = (string) file_get_contents($this->projectRoot . '/public/js/owner-report-editor.js');

        foreach (
            [
                'vendor/toastui-editor/toastui-editor-all.min.js',
                'vendor/toastui-editor/toastui-editor.min.css',
                'vendor/toastui-editor/i18n/ru-ru.min.js',
            ] as $asset
        ) {
            self::assertStringContainsString("asset('" . $asset . "')", $editor);
            self::assertFileExists($this->projectRoot . '/public/' . $asset);
        }

        $tracked = [];
        exec('git -C ' . escapeshellarg($this->projectRoot) . ' ls-files public/vendor/toastui-editor', $tracked);
        self::assertContains('public/vendor/toastui-editor/toastui-editor-all.min.js', $tracked);
        self::assertContains('public/vendor/toastui-editor/LICENSE', $tracked, 'Лицензия библиотеки лежит рядом с файлами.');
        self::assertContains('public/vendor/toastui-editor/VERSION.txt', $tracked, 'Источник и sha256 файлов зафиксированы.');

        // Никаких внешних CDN в рантайме.
        foreach ([$editor, $script] as $source) {
            self::assertStringNotContainsString('cdn.', $source);
            self::assertStringNotContainsString('//unpkg', $source);
        }

        // Поле формы остаётся прежним, а библиотека только правит его значение.
        self::assertStringContainsString('name="content"', $editor);
        self::assertStringContainsString('data-markdown-editor', $editor);
        self::assertStringContainsString('data-markdown-editor-form', $editor);
        self::assertStringNotContainsString('session_token', $editor);
        self::assertStringContainsString('textarea.value = editor.getMarkdown();', $script);
        self::assertStringContainsString("initialEditType: 'wysiwyg'", $script);
        self::assertStringContainsString("previewStyle: 'tab'", $script);
        self::assertStringContainsString("language: 'ru-RU'", $script);
        self::assertStringContainsString('usageStatistics: false', $script);

        // Тулбар не предлагает разметку, которую рендерер отчёта не поддерживает.
        foreach (['link', 'image', 'codeblock', 'task', 'strike'] as $forbidden) {
            self::assertStringNotContainsString("'" . $forbidden . "'", $script, "Кнопка «{$forbidden}» не поддерживается ReportMarkdown.");
        }

        // Серверный предпросмотр остаётся на том же белом списке разметки.
        self::assertStringContainsString('latest_html|raw', $editor);
        self::assertStringContainsString(
            'ReportMarkdown::toHtml',
            (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php'),
        );
    }

    /**
     * Выгрузка кейса (07.K5j).
     *
     * Оба выхода закрыты владельцем и проверкой владения кейсом, ссылки в
     * карточке не несут токен, а печатная страница закрыта от индексации: в
     * ней клинический материал клиента.
     */
    public function testTheCaseExportIsOwnerOnlyTokenlessAndNeverIndexed(): void
    {
        $routes = (string) file_get_contents($this->projectRoot . '/public/index.php');
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');
        $card = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');
        $page = (string) file_get_contents($this->projectRoot . '/templates/owner-case-export.twig');

        self::assertStringContainsString("\$router->get('/admin/invited-case/{sessionId}/export.pdf'", $routes);
        self::assertStringContainsString("\$router->get('/admin/invited-case/{sessionId}/print'", $routes);

        // Обе выгрузки идут через `ownedCase()`, то есть через `requireOwner()`
        // и `claimedCaseForOwner()`; отдельного пути доступа у них нет.
        self::assertStringContainsString('private function caseExport(string $sessionId): ?array', $controller);
        self::assertStringContainsString('$case = $this->ownedCase($sessionId);', $controller);
        self::assertStringContainsString("header('X-Robots-Tag: noindex, nofollow');", $controller);
        // Документ собирается на лету: в storage он не записывается.
        self::assertStringContainsString("->generate(\$html, 'case_export.pdf', false)", $controller);

        // Ссылки выгрузки — обычные owner-адреса, без токена и без `/result/`.
        self::assertStringContainsString('/export.pdf', $card);
        self::assertStringContainsString('formtarget="_blank"', $card);
        self::assertStringContainsString('name="include_professional"', $card);
        self::assertStringContainsString('name="include_answers"', $card);
        self::assertStringNotContainsString('session_token', $card);

        self::assertStringContainsString('noindex, nofollow', $page);
        self::assertStringContainsString('@media print', $page);
        self::assertStringContainsString('page-break-before: always', $page);
        self::assertStringNotContainsString('session_token', $page);
        self::assertStringNotContainsString('/result/', $page);
    }

    /**
     * Кабинет не показывает значения столбцов вместо состояния (замечание 15.09).
     *
     * Специалист читал `completed` и `therapist_case` — это схема базы, а не
     * состояние работы с клиентом. Перевод живёт в одном месте, `OwnerLabels`.
     */
    public function testOwnerTemplatesTranslateStatusesInsteadOfPrintingColumnValues(): void
    {
        foreach (['owner-invited-case', 'owner-dashboard'] as $name) {
            $template = (string) file_get_contents($this->projectRoot . '/templates/' . $name . '.twig');

            self::assertStringNotContainsString('>completed<', $template, $name);
            self::assertStringNotContainsString('{{ case.status }}', $template, $name);
            self::assertStringNotContainsString('{{ case.retention_class }}', $template, $name);
            self::assertStringContainsString('status_label', $template, $name);
        }

        self::assertStringContainsString('retention_label', (string) file_get_contents(
            $this->projectRoot . '/templates/owner-dashboard.twig',
        ));

        $labels = (string) file_get_contents($this->projectRoot . '/core/OwnerLabels.php');
        foreach (['завершено', 'в процессе', 'удалено', 'анонимная', 'кейс специалиста'] as $word) {
            self::assertStringContainsString($word, $labels);
        }
    }

    /**
     * Форма приглашения (07.K6a): одноразовый ключ, блокировка кнопки и
     * «Новый клиент…». Без JS поле имени видно с подсказкой, повтор ловит сервер.
     */
    public function testInviteFormCarriesAOneTimeKeyAndOffersANewClient(): void
    {
        $template = (string) file_get_contents($this->projectRoot . '/templates/owner-dashboard.twig');
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');
        $script = (string) file_get_contents($this->projectRoot . '/public/js/owner-forms.js');

        self::assertStringContainsString('<input type="hidden" name="form_key" value="{{ invite_form_key }}">', $template);
        self::assertStringContainsString("'invite_form_key' => \$this->inviteSubmission()->issueKey()", $controller);
        self::assertStringContainsString('->submit($this->postData(), $this->activeTestIds())', $controller);

        self::assertStringContainsString('<option value="__new__">Новый клиент…</option>', $template);
        self::assertSame('__new__', \PsyTest\Core\OwnerInviteSubmission::NEW_CLIENT);
        self::assertStringContainsString('name="new_client_label" type="text" maxlength="120"', $template);
        self::assertStringContainsString('только если выбран «Новый клиент…»', $template);
        // Поле не обязательно в разметке: required ставит только скрипт в режиме нового клиента.
        self::assertStringNotContainsString('name="new_client_label" type="text" maxlength="120" required', $template);

        self::assertStringContainsString('data-submit-once', $template);
        self::assertStringContainsString('data-busy-text="Создаём…"', $template);
        self::assertStringContainsString("asset('js/owner-forms.js')", $template);
        self::assertStringContainsString('button.disabled = true', $script);
        self::assertStringContainsString("select.value === '__new__'", $script);
    }

    /**
     * Остальные формы кабинета, создающие сущности или платные задания
     * (07.K6b): у каждой одноразовый ключ и блокировка кнопки.
     */
    public function testEntityAndPaidOrderFormsCarryAOneTimeKey(): void
    {
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');

        $clients = (string) file_get_contents($this->projectRoot . '/templates/owner-clients.twig');
        self::assertStringContainsString('<input type="hidden" name="form_key" value="{{ client_form_key }}">', $clients);
        self::assertStringContainsString("'client_form_key' => \$this->clientSubmission()->issueKey()", $controller);

        $client = (string) file_get_contents($this->projectRoot . '/templates/owner-client.twig');
        self::assertStringContainsString('<input type="hidden" name="form_key" value="{{ invite_form_key }}">', $client);
        self::assertSame(2, substr_count($controller, "'invite_form_key' => \$this->inviteSubmission()->issueKey()"));

        $case = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');
        self::assertStringContainsString('name="form_key" value="{{ order_keys.all }}"', $case);
        self::assertStringContainsString('name="form_key" value="{{ order_keys[item.kind] }}"', $case);
        self::assertStringContainsString('name="form_key" value="{{ form_key }}"', $case, 'Макрос «Заказать заново».');
        self::assertSame(2, substr_count($case, 'reorder_form(case, item, csrf_token, basePath, order_keys[item.kind])'));
        self::assertStringContainsString("'order_keys' => \$ai['available'] ? \$this->caseReportOrder()->issueKeys() : null", $controller);

        // Каждая форма заказа на карточке кейса — с ключом и блокировкой.
        preg_match_all('#<form method="post" action="[^"]*/reports/request"[^>]*>.*?</form>#s', $case, $orders);
        self::assertCount(3, $orders[0]);
        foreach ($orders[0] as $form) {
            self::assertStringContainsString('data-submit-once', $form);
            self::assertStringContainsString('name="form_key"', $form);
            self::assertStringContainsString('data-busy-text="Заказываем…"', $form);
        }

        foreach ([$clients, $client] as $template) {
            self::assertStringContainsString('data-submit-once', $template);
            self::assertStringContainsString('data-busy-text="Создаём…"', $template);
        }
        self::assertMatchesRegularExpression('#/reports/notify" class="owner-action-form" data-submit-once>#', $case);

        $editor = (string) file_get_contents($this->projectRoot . '/templates/owner-report-editor.twig');
        self::assertStringContainsString('/restore" class="owner-action-form" data-submit-once>', $editor);
        self::assertStringContainsString('/publish" class="owner-action-form owner-publish-form" data-submit-once>', $editor);

        foreach ([$clients, $client, $case, $editor] as $template) {
            self::assertStringContainsString("asset('js/owner-forms.js')", $template);
        }
    }

    /**
     * Кнопка «Удалить» есть только у отозванного неоткрытого приглашения
     * (07.K6c); у ожидающего остаётся «Отозвать», у открытых — ссылка на кейс.
     */
    public function testOnlyRevokedInvitesOfferDeletion(): void
    {
        $routes = (string) file_get_contents($this->projectRoot . '/public/index.php');
        self::assertStringContainsString("\$router->post('/admin/invites/delete', [OwnerController::class, 'deleteInvite'])", $routes);

        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($this->projectRoot . '/templates'), ['cache' => false]);
        \PsyTest\Core\TemplateFunctions::register($twig);
        $row = static fn (string $id, string $status, string $display, ?string $session): array => [
            'id' => $id, 'status' => $status, 'display_status' => $display, 'claimed_session_id' => $session,
            'test_name' => 'Методика ' . $id, 'created_at' => '2026-09-27 10:00:00', 'owner_note' => null,
            'client_label' => null, 'client_id' => null,
        ];
        $html = $twig->render('owner-dashboard.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf',
            'invites' => [
                $row('pending-1', 'pending', 'pending', null),
                $row('revoked-1', 'revoked', 'revoked', null),
                $row('claimed-1', 'claimed', 'completed', '11111111-1111-4111-8111-111111111111'),
            ],
            'tests' => [], 'clients' => [], 'invite_form_key' => 'k',
        ]);

        self::assertSame(1, substr_count($html, 'action="/admin/invites/delete"'));
        self::assertSame(1, substr_count($html, 'action="/admin/invites/revoke"'));
        self::assertMatchesRegularExpression(
            '#action="/admin/invites/delete" class="owner-action-form" data-submit-once>\s*<input type="hidden" name="csrf_token" value="synthetic-csrf">\s*<input type="hidden" name="invite_id" value="revoked-1">#',
            $html,
        );
        self::assertStringContainsString('data-busy-text="Удаляем…">Удалить</button>', $html);
    }

    /**
     * Архив, корзина и фильтры (07.K8): пять POST-маршрутов, список-таблица с
     * общей формой действий, подтверждение без JavaScript и скрипт-улучшение.
     */
    public function testInviteArchiveTrashRoutesAndTableMarkup(): void
    {
        $routes = (string) file_get_contents($this->projectRoot . '/public/index.php');
        foreach (['archive' => 'archiveInvites', 'unarchive' => 'unarchiveInvites', 'trash' => 'trashInvites', 'restore' => 'restoreInvites', 'purge' => 'purgeInvites'] as $path => $method) {
            self::assertStringContainsString("\$router->post('/admin/invites/{$path}', [OwnerController::class, '{$method}'])", $routes);
        }

        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($this->projectRoot . '/templates'), ['cache' => false]);
        \PsyTest\Core\TemplateFunctions::register($twig);
        $row = static fn (string $id, string $status, string $display, ?string $session, array $extra = []): array => $extra + [
            'id' => $id, 'status' => $status, 'display_status' => $display, 'claimed_session_id' => $session,
            'test_name' => 'Методика ' . $id, 'test_slug' => 'bdi', 'created_at' => '2026-09-27 10:00:00', 'owner_note' => null,
            'client_label' => null, 'client_id' => null, 'archived_at' => null, 'trashed_at' => null, 'purge_at' => null,
        ];
        $render = fn (array $invites, string $status = 'active') => $twig->render('owner-dashboard.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf',
            'invites' => $invites, 'invite_tests' => [['id' => 1, 'slug' => 'bdi', 'name' => 'BDI']],
            'clients' => [['id' => '11111111-1111-4111-8111-111111111111', 'label' => 'Клиент']],
            'filter' => \PsyTest\Core\InviteFilter::fromQuery(['status' => $status], ['bdi']),
            'filter_url' => $status === 'active' ? '/admin' : '/admin?status=' . $status,
            'invite_counts' => ['active' => 2, 'archived' => 1, 'trash' => 3],
            'invite_form_key' => 'k', 'bulk_form_key' => 'bulk-key', 'trash_days' => 30,
        ]);

        $html = $render([
            $row('pending-1', 'pending', 'pending', null),
            $row('done-1', 'claimed', 'completed', '11111111-1111-4111-8111-111111111111'),
        ]);
        // Фильтры — GET-форма, счётчики на месте.
        self::assertStringContainsString('<form method="get" action="/admin" class="filter-bar"', $html);
        foreach (['name="client"', 'name="test"', 'name="status"', 'name="q"'] as $field) {
            self::assertStringContainsString($field, $html);
        }
        self::assertStringContainsString('в архиве: 1', $html);
        self::assertStringContainsString('в корзине: 3', $html);
        // Общая форма действий несёт CSRF, одноразовый ключ и адрес возврата.
        self::assertMatchesRegularExpression(
            '#id="invite-bulk-form" class="invite-bulk-form" data-bulk-form>\s*<input type="hidden" name="csrf_token" value="synthetic-csrf">\s*<input type="hidden" name="form_key" value="bulk-key">\s*<input type="hidden" name="return" value="/admin">#',
            $html,
        );
        // Ожидающее приглашение: «Отозвать», но не архив и не корзина; завершённое — наоборот.
        self::assertSame(1, substr_count($html, 'action="/admin/invites/revoke"'));
        self::assertSame(1, substr_count($html, 'name="invite_id" value="done-1" data-confirm="trash"'));
        self::assertStringNotContainsString('value="pending-1" data-confirm', $html);
        self::assertStringContainsString('formaction="/admin/invites/archive" name="invite_id" value="done-1"', $html);
        self::assertStringContainsString('name="invite_ids[]" value="done-1" form="invite-bulk-form"', $html);
        self::assertStringContainsString('<dialog class="owner-dialog" id="owner-invite-dialog"', $html);
        self::assertStringContainsString('js/owner-invites.js', $html);
        // Диалог называет последствия: срок, состав удаляемого и сохранность карточки клиента.
        self::assertStringContainsString('окончательно удалён через 30 дней: ответы, результат, разборы и их версии, выгрузки. Карточка клиента останется. До этого его можно восстановить.', $html);

        // Корзина: «Восстановить» и «Удалить сейчас», дата удаления, ссылка на кейс остаётся.
        $trash = $render([$row('trash-1', 'claimed', 'completed', '11111111-1111-4111-8111-111111111111', [
            'trashed_at' => '2026-09-30 10:00:00', 'purge_at' => '2026-10-30 10:00:00',
        ])], 'trash');
        self::assertStringContainsString('formaction="/admin/invites/restore" name="invite_id" value="trash-1"', $trash);
        self::assertStringContainsString('formaction="/admin/invites/purge" name="invite_id" value="trash-1" data-confirm="purge"', $trash);
        self::assertStringContainsString('будет удалён 30.10.2026', $trash);
        self::assertStringContainsString('/admin/invited-case/11111111-1111-4111-8111-111111111111', $trash);
        self::assertStringNotContainsString('name="invite_id" value="trash-1" data-confirm="trash"', $trash);

        // Отозванное неоткрытое: мгновенное «Удалить» остаётся, корзины нет.
        $revoked = $render([$row('revoked-1', 'revoked', 'revoked', null)]);
        self::assertSame(1, substr_count($revoked, 'action="/admin/invites/delete"'));
        self::assertStringNotContainsString('value="revoked-1" data-confirm="trash"', $revoked);
    }

    public function testTrashAndPurgeNeverRunFromASingleClickWithoutConfirmation(): void
    {
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');
        $body = substr($controller, (int) strpos($controller, 'private function bulkInviteAction('));
        $body = substr($body, 0, (int) strpos($body, 'public function viewInvitedCase('));
        self::assertStringContainsString('$this->requireOwner()', $body);
        self::assertStringContainsString('in_array($action, OwnerInviteBulkAction::CONFIRMED_ACTIONS, true)', $body);
        self::assertStringContainsString("(\$post['confirmed'] ?? null) !== '1'", $body);
        self::assertStringContainsString("'owner-invites-confirm'", $body);
        self::assertStringContainsString('OwnerInviteBulkAction::safeReturn(', $body);
        self::assertSame(['trash', 'purge'], \PsyTest\Core\OwnerInviteBulkAction::CONFIRMED_ACTIONS);

        $confirm = (string) file_get_contents($this->projectRoot . '/templates/owner-invites-confirm.twig');
        self::assertStringContainsString('name="confirmed" value="1"', $confirm);
        self::assertStringContainsString('name="csrf_token"', $confirm);
        self::assertStringContainsString('name="form_key"', $confirm);
        self::assertStringContainsString('name="confirm_delete" value="delete" required', $confirm);

        // Диалог — только улучшение: скрипт сам ставит подтверждение, сервер его требует.
        $script = (string) file_get_contents($this->projectRoot . '/public/js/owner-invites.js');
        self::assertStringContainsString("confirmedField.value = '1'", $script);
        self::assertStringContainsString('requestSubmit(button)', $script);
    }

    public function testCasePageIsReadOnlyInTheTrashAndOffersRestore(): void
    {
        $controller = (string) file_get_contents($this->projectRoot . '/controllers/OwnerController.php');
        self::assertStringContainsString("if (\$writable && \$case['trashed_at'] !== null)", $controller);
        // Заказ черновиков, уведомление и все правки отчёта идут через проверку записи.
        self::assertSame(3, substr_count($controller, '$this->ownedCase($sessionId, true)'));
        self::assertStringContainsString('private function editableReport(', $controller);

        $template = (string) file_get_contents($this->projectRoot . '/templates/owner-invited-case.twig');
        self::assertStringContainsString('В корзине, будет удалён {{ case.purge_at|date("d.m.Y") }}', $template);
        self::assertStringContainsString('action="{{ basePath }}/admin/invites/restore"', $template);
    }

    public function testCleanupCronPurgesTheTrash(): void
    {
        $cron = (string) file_get_contents($this->projectRoot . '/bin/cleanup-sessions.php');
        self::assertStringContainsString('purgeTrash(', $cron);
        self::assertStringContainsString('TestInviteService::TRASH_RETENTION_DAYS', $cron);
        self::assertStringContainsString("'invites_purged'", $cron);
        self::assertStringContainsString("'cases_purged'", $cron);
        self::assertSame(30, \PsyTest\Core\TestInviteService::TRASH_RETENTION_DAYS);
    }
}
