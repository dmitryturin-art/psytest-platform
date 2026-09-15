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
}
