<?php

/**
 * Result Controller
 *
 * Handles result display and PDF generation
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\Ai\AiClient;
use PsyTest\Core\Ai\AiProviderException;
use PsyTest\Core\Ai\AiProviderSettings;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\AiReportGenerator;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiSettings;
use PsyTest\Core\Ai\BackgroundWorkerLauncher;
use PsyTest\Core\Ai\CurlTransport;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\PDFGenerator;
use PsyTest\Core\ReportMarkdown;
use PsyTest\Core\ResponseFinisher;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\ResultSectionRenderer;
use PsyTest\Core\VisitorAccountService;
use PsyTest\Core\VisitorAccountSession;
use PsyTest\Modules\TestModuleInterface;

class ResultController extends BaseController
{
    private PDFGenerator $pdfGenerator;
    private ResultSectionRenderer $sectionRenderer;
    private ResultPresenter $presenter;

    public function __construct()
    {
        parent::__construct();
        $this->pdfGenerator = new PDFGenerator();
        $this->sectionRenderer = ResultSectionRenderer::forView($this->view);
        $this->presenter = new ResultPresenter($this->db, $this->sessionManager);
    }

    /**
     * Show results page
     * GET /result/{slug}/{token}
     */
    public function show(string $slug, string $token): void
    {
        // Get session
        $session = $this->sessionManager->getSessionByResultToken($token);
        if (!$session) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }

        // A result token is bound to one test and cannot be replayed under
        // another route slug.
        $test = $this->getSessionTestForRoute($session, $slug);
        if (!$test) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }

        // Get module
        $module = $this->getModuleOrFail($slug);

        echo $this->view->render('result-layout', $this->presenter->viewData(
            $session,
            $test,
            $module,
            '/result/' . $slug . '/' . $token,
        ) + ['visitor_account' => $this->visitorAccount()]);
    }

    /**
     * Вошедший посетитель, если он есть.
     *
     * Страница результата открывается по ссылке и без входа; аккаунт нужен ей
     * только чтобы показать кнопку сохранения вместо приглашения войти. Права
     * по этому значению не выдаются — привязку отдельно проверяет сервис.
     *
     * @return array{id: string, email: string}|null
     */
    private function visitorAccount(): ?array
    {
        $accountId = VisitorAccountSession::accountId();

        return $accountId === null
            ? null
            : VisitorAccountService::fromConfig($this->db)->find($accountId);
    }

    /**
     * Отпустить браузер и довести разбор до конца вне этого запроса.
     *
     * Расписание для этого не нужно: посетитель получает ответ сразу. Модель
     * отвечает несколько минут, поэтому держать браузер всё это время нельзя —
     * он и сервер оборвут запрос задолго до конца.
     *
     * Предпочтительный путь — отдельный процесс (`AI_WORKER_PHP_BIN`): он
     * переживает и уход посетителя, и 504 от nginx. Там, где CLI PHP не задан
     * (локальный `php -S`), остаётся прежний путь — доработка в этом же
     * процессе после отданного ответа.
     *
     * Если хостинг прервёт процесс на середине, задание останется в работе и
     * вернётся в очередь само (через получасовой возврат зависших), поэтому
     * потеря такого прогона ничего не ломает — только откладывает.
     */
    private function respondThenGenerate(string $path, AiReportRepository $reports): never
    {
        if (BackgroundWorkerLauncher::fromConfig(require dirname(__DIR__) . '/config.php')->launch(1)) {
            $this->redirect($path);
        }

        header('Location: ' . $path, true, 303);
        header('Content-Length: 0');
        ResponseFinisher::finish();

        $job = $reports->claimNext();
        if ($job !== null) {
            $this->reportGenerator()->process($job);
        }

        exit;
    }

    private function reportGenerator(): AiReportGenerator
    {
        $aiSettings = new AiSettings($this->db);
        $settings = AiProviderSettings::fromConfig(require dirname(__DIR__) . '/config.php', $aiSettings);

        return new AiReportGenerator(
            new AiReportRepository($this->db),
            $this->contextBuilder(),
            PromptRegistry::default($this->db),
            new AiClient($settings, new CurlTransport(), ownerSettings: $aiSettings),
        );
    }

    private function contextBuilder(): AiReportContextBuilder
    {
        return new AiReportContextBuilder($this->sessionManager, $this->moduleLoader, new AiSettings($this->db));
    }

    /**
     * Возврат на страницу результата после действия.
     *
     * 303 нужен, чтобы обновление страницы не повторяло POST-запрос: иначе
     * посетитель, нажав «обновить», заказывал бы разбор ещё раз.
     */
    private function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    /**
     * Заказать расширенный разбор.
     * POST /result/{slug}/{token}/report
     *
     * Ставит задание в очередь и возвращается на страницу результата: сам
     * разбор делается фоновым обработчиком, потому что модель отвечает
     * несколько минут и веб-запрос столько ждать не может.
     */
    public function requestReport(string $slug, string $token): void
    {
        [$session, $test] = $this->reportSessionOrFail($slug, $token);
        if ($session === null) {
            return;
        }

        if (($session['retention_class'] ?? null) === 'therapist_case') {
            $this->redirect('/result/' . $slug . '/' . $token);
        }

        // Consent is checked server-side: a forged POST without the checkbox
        // must not create a job or transmit structured results to a provider.
        if (($_POST['ai_consent'] ?? null) !== '1') {
            $this->redirect('/result/' . $slug . '/' . $token);
        }

        $kind = $_POST['kind'] ?? '';
        if (!in_array($kind, [Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL], true)) {
            $this->redirect('/result/' . $slug . '/' . $token);
        }

        // Общий выключатель владельца (07.WP9): задание не ставится вовсе,
        // иначе посетитель ждал бы отчёт, который заведомо уйдёт в отказ.
        if (!(new AiSettings($this->db))->isAiEnabled()) {
            $this->redirect('/result/' . $slug . '/' . $token);
        }

        $mode = $this->presenter->reportMode($session);

        // Промпт спрашивается здесь, а не в обработчике: если разбор для этой
        // методики не открыт, посетитель узнаёт об этом сразу, а не через
        // несколько минут ожидания. Наличие опубликованного промпта и означает,
        // что разбор для этого сочетания методики, режима и вида разрешён.
        $prompt = PromptRegistry::default($this->db)->published($slug, $mode, $kind);
        if ($prompt === null) {
            $this->redirect('/result/' . $slug . '/' . $token);
        }

        // Контекст собирается здесь же, а не в обработчике: снимок задания
        // обязан описывать результат на момент нажатия кнопки (аудит R2).
        // Если разбирать нечего, задание не создаётся вовсе.
        try {
            $context = $this->contextBuilder()->build((string) $session['id'], $slug, $mode);
        } catch (AiProviderException) {
            $this->redirect('/result/' . $slug . '/' . $token);
        }

        $reports = new AiReportRepository($this->db);
        $reports->request((string) $session['id'], $slug, $mode, $kind, $prompt, $context);

        $this->respondThenGenerate('/result/' . $slug . '/' . $token, $reports);
    }

    /**
     * Состояние разбора для опроса со страницы.
     * GET /result/{slug}/{token}/report-status
     */
    public function reportStatus(string $slug, string $token): void
    {
        header('Content-Type: application/json');

        [$session] = $this->reportSessionOrFail($slug, $token, true);
        if ($session === null) {
            return;
        }

        if (($session['retention_class'] ?? null) === 'therapist_case') {
            echo json_encode(['status' => 'restricted']);

            return;
        }

        $kind = $_GET['kind'] ?? Prompt::KIND_CLEAR;
        $reports = new AiReportRepository($this->db);
        $report = $reports->findFor((string) $session['id'], $this->presenter->reportMode($session), (string) $kind);

        if ($report === null) {
            echo json_encode(['status' => 'none']);

            return;
        }

        echo json_encode([
            'status' => $report['status'],
            // Разметку строит сервер: ответ модели — внешний текст, и вставлять
            // его в страницу без разбора нельзя.
            'html' => $report['status'] === AiReportRepository::STATUS_READY
                ? ReportMarkdown::toHtml((string) $report['content'])
                : null,
            'failure_reason' => $report['failure_reason'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function reportSessionOrFail(string $slug, string $token, bool $asJson = false): array
    {
        $session = $this->sessionManager->getSessionByResultToken($token);
        $test = $session !== null ? $this->getSessionTestForRoute($session, $slug) : null;

        if ($session === null || !$test) {
            http_response_code(404);
            echo $asJson ? json_encode(['error' => 'not found']) : $this->view->render('error-page');

            return [null, null];
        }

        return [$session, $test];
    }

    /**
     * Check pair comparison status (for polling).
     * GET /result/{slug}/{token}/pair-status
     *
     * Returns JSON: {has_comparison: bool, comparison_id: ?string}
     * Used by the first partner's result page to auto-refresh when the
     * second partner completes the test.
     */
    public function pairStatus(string $slug, string $token): void
    {
        header('Content-Type: application/json');
        $session = $this->sessionManager->getSessionByResultToken($token);
        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Session not found']);
            return;
        }

        if (!$this->getSessionTestForRoute($session, $slug)) {
            http_response_code(404);
            echo json_encode(['error' => 'Session not found']);
            return;
        }

        $comparison = $this->sessionManager->getPairComparisonBySession($session['id']);
        echo json_encode([
            'has_comparison' => $comparison !== null,
            'comparison_id' => $comparison['id'] ?? null,
        ]);
    }

    /**
     * Generate and download PDF
     * GET /result/{slug}/{token}/pdf
     */
    public function pdf(string $slug, string $token): void
    {
        // Get session
        $session = $this->sessionManager->getSessionByResultToken($token);
        if (!$session) {
            http_response_code(404);
            echo 'Session not found';
            return;
        }

        // A result token is bound to one test and cannot be replayed under
        // another route slug.
        $test = $this->getSessionTestForRoute($session, $slug);
        if (!$test) {
            http_response_code(404);
            echo 'Test not found';
            return;
        }

        // Get module
        $module = $this->getModuleOrFail($slug);

        $this->streamPdf($session, $test, $module);
    }

    /**
     * Собирает и отдаёт PDF результата.
     *
     * Выделено отдельно, потому что тот же документ отдаётся и по ссылке, и по
     * владению аккаунтом; различается только проверка доступа.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $test
     */
    private function streamPdf(array $session, array $test, TestModuleInterface $module): never
    {
        $printable = $this->presenter->pdfSections($session, $module);
        // Опубликованный специалистом разбор идёт после результата — клиенту он
        // приходит в том же документе, отдельной ссылки для него нет (D-054).
        $resultsHtml = $this->sectionRenderer->renderToHtml($printable['sections'])
            . $printable['published_report_html'];

        // Generate PDF
        $pdfPath = $this->pdfGenerator->generateTestResult(
            $session,
            $test,
            $resultsHtml,
            $printable['includes_pair_comparison'],
        );

        // Send file
        $fullPath = dirname(__DIR__) . $pdfPath;
        if (!file_exists($fullPath)) {
            http_response_code(500);
            echo 'PDF generation failed';
            exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="result_' . $test['slug'] . '_' . date('YmdHis') . '.pdf"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    /**
     * Delete session (GDPR)
     * POST /result/{token}/delete
     */
    public function delete(string $token): void
    {
        header('Content-Type: application/json');

        $session = $this->sessionManager->getSessionByResultToken($token);
        if (!$session) {
            echo json_encode(['success' => false, 'error' => 'Session not found']);
            return;
        }

        $success = $this->sessionManager->deleteSession($session['id']);

        echo json_encode(['success' => $success]);
    }

    /**
     * Show pair comparison
     * GET /pair/{id}
     */
    public function pairShow(string $id): void
    {
        $comparison = $this->db->selectOne('SELECT * FROM pair_comparisons WHERE id = ?', [$id]);
        if (!$comparison) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }

        // Get test
        $test = $this->db->selectOne('SELECT * FROM tests WHERE id = ?', [$comparison['test_id']]);
        if (!$test) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }

        // Get module
        $module = $this->getModuleOrFail($test['slug']);

        // Get sessions
        $session1 = $this->sessionManager->getSessionById($comparison['session_1_id']);
        $session2 = $this->sessionManager->getSessionById($comparison['session_2_id']);

        if (!$session1 || !$session2) {
            http_response_code(404);
            echo 'Sessions not found';
            return;
        }

        // Render comparison data + render its twig block to HTML
        $comparisonData = $module->comparePairResults(
            $session1['calculated_results'],
            $session2['calculated_results']
        );
        $comparisonHtml = $this->view->render('blocks/pair-comparison', [
            'comparison' => $comparisonData,
        ]);
        $chartData = $module->pairChartData($comparisonData);
        $chartHtml = $chartData !== null
            ? $this->view->render('blocks/pair-chart', ['chart' => $chartData])
            : '';

        echo $this->view->render('result-page', [
            'test' => $test,
            'session' => $session1,
            'pair_comparison' => $comparison,
            'pair_comparison_html' => $comparisonHtml,
            'pair_chart_html' => $chartHtml,
        ]);
    }

    /**
     * Generate pair comparison PDF
     * GET /pair/{id}/pdf
     */
    public function pairPdf(string $id): void
    {
        $comparison = $this->db->selectOne('SELECT * FROM pair_comparisons WHERE id = ?', [$id]);
        if (!$comparison) {
            http_response_code(404);
            echo 'Comparison not found';
            return;
        }

        // Get test and module
        $test = $this->db->selectOne('SELECT * FROM tests WHERE id = ?', [$comparison['test_id']]);
        $module = $this->moduleLoader->getModule($test['slug']);

        if (!$test || !$module) {
            http_response_code(404);
            echo 'Test or module not found';
            return;
        }

        // Get sessions
        $session1 = $this->sessionManager->getSessionById($comparison['session_1_id']);
        $session2 = $this->sessionManager->getSessionById($comparison['session_2_id']);

        // Render comparison via the module + its twig block (not raw JSON dump)
        $comparisonData = $module->comparePairResults(
            $session1['calculated_results'],
            $session2['calculated_results']
        );
        $comparisonHtml = $this->view->render('blocks/pair-comparison', [
            'comparison' => $comparisonData,
            'is_pdf' => true,
        ]);

        // Generate PDF
        $pdfPath = $this->pdfGenerator->generatePairComparison($comparison, $test, $comparisonHtml);

        // Send file
        $fullPath = dirname(__DIR__) . $pdfPath;
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="pair_comparison_' . date('YmdHis') . '.pdf"');
        header('Content-Length: ' . (file_exists($fullPath) ? filesize($fullPath) : 0));
        readfile($fullPath);
        exit;
    }

    /**
     * AI interpretation page
     * GET /interpretation/{token}
     */
    public function interpretation(string $token): void
    {
        $session = $this->sessionManager->getSessionByResultToken($token);
        if (!$session) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }

        // Check if already purchased
        $existingInterpretation = $this->db->selectOne(
            'SELECT * FROM ai_interpretations WHERE session_id = ? AND payment_status = "completed"',
            [$session['id']]
        );

        if ($existingInterpretation) {
            // Show existing interpretation
            echo $this->view->render('interpretation-page', [
                'session' => $session,
                'interpretation' => $existingInterpretation,
            ]);
            return;
        }

        // Show payment page
        echo $this->view->render('interpretation-payment', [
            'session' => $session,
            'price' => 499, // Example price
        ]);
    }

}
