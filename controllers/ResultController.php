<?php

/**
 * Result Controller
 *
 * Handles result display and PDF generation
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\ClinicalSafetyNotice;
use PsyTest\Core\PDFGenerator;
use PsyTest\Core\ReportMarkdown;
use PsyTest\Core\ResultSectionRenderer;

class ResultController extends BaseController
{
    private PDFGenerator $pdfGenerator;
    private ResultSectionRenderer $sectionRenderer;

    public function __construct()
    {
        parent::__construct();
        $this->pdfGenerator = new PDFGenerator();
        $this->sectionRenderer = ResultSectionRenderer::forView($this->view);
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

        // Get results
        $results = $session['calculated_results'];

        // Attach pair comparison data (if any) so buildSections can render it.
        $this->enrichWithPairComparison($results, $session, $module);

        $sections = $module->buildSections($results);

        echo $this->view->render('result-layout', [
            'test' => $test,
            'session' => $session,
            'sections' => $sections,
            'results' => $results,
            'clinical_safety_notice' => ClinicalSafetyNotice::fromResults($results),
            'ai_report' => $this->reportViewData($slug, $session),
        ]);
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
     * Данные блока расширенного разбора для страницы результата.
     *
     * Блок показывается, только если для этой методики и режима действительно
     * опубликован промпт: иначе посетителю предлагалась бы кнопка, которая
     * ничего не сделает.
     *
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>|null
     */
    private function reportViewData(string $slug, array $session): ?array
    {
        $mode = $this->reportMode($session);
        $registry = PromptRegistry::default();
        $reports = new AiReportRepository($this->db);

        $kinds = [];
        foreach ([Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL] as $kind) {
            if ($registry->published($slug, $mode, $kind) === null) {
                continue;
            }

            $report = $reports->findFor((string) $session['id'], $mode, $kind);

            $kinds[] = [
                'kind' => $kind,
                'title' => $kind === Prompt::KIND_CLEAR ? 'Понятный разбор' : 'Профессиональное заключение',
                'status' => $report['status'] ?? 'none',
                'html' => ($report['status'] ?? '') === AiReportRepository::STATUS_READY
                    ? ReportMarkdown::toHtml((string) $report['content'])
                    : null,
                'failure_reason' => $report['failure_reason'] ?? null,
            ];
        }

        return $kinds === [] ? null : ['mode' => $mode, 'kinds' => $kinds];
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

        $kind = $_POST['kind'] ?? '';
        if (!in_array($kind, [Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL], true)) {
            $this->redirect('/result/' . $slug . '/' . $token);
        }

        $mode = $this->reportMode($session);

        // Промпт спрашивается здесь, а не в обработчике: если разбор для этой
        // методики не открыт, посетитель узнаёт об этом сразу, а не через
        // несколько минут ожидания. Наличие опубликованного промпта и означает,
        // что разбор для этого сочетания методики, режима и вида разрешён.
        $prompt = PromptRegistry::default()->published($slug, $mode, $kind);
        if ($prompt === null) {
            $this->redirect('/result/' . $slug . '/' . $token);
        }

        (new AiReportRepository($this->db))->request(
            (string) $session['id'],
            $slug,
            $mode,
            $kind,
            $prompt,
        );

        $this->redirect('/result/' . $slug . '/' . $token);
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

        $kind = $_GET['kind'] ?? Prompt::KIND_CLEAR;
        $reports = new AiReportRepository($this->db);
        $report = $reports->findFor((string) $session['id'], $this->reportMode($session), (string) $kind);

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
     * Парный разбор делается, когда сравнение уже собрано; иначе одиночный.
     *
     * @param array<string, mixed> $session
     */
    private function reportMode(array $session): string
    {
        return $this->sessionManager->getPairComparisonBySession((string) $session['id']) !== null
            ? 'pair'
            : 'individual';
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

        // Get results
        $results = $session['calculated_results'];

        // Attach pair comparison (if any) so the PDF includes it, and mark
        // as PDF so buildSections suppresses the invite-to-partner block
        // (an invite link has no place in a printed document).
        $this->enrichWithPairComparison($results, $session, $module);
        $includesPairComparison = isset($results['pair_comparison']);
        $results['is_pdf'] = true;

        $sections = $module->buildSections($results);
        $resultsHtml = $this->sectionRenderer->renderToHtml($sections);

        // Generate PDF
        $pdfPath = $this->pdfGenerator->generateTestResult(
            $session,
            $test,
            $resultsHtml,
            $includesPairComparison,
        );

        // Send file
        $fullPath = dirname(__DIR__) . $pdfPath;
        if (!file_exists($fullPath)) {
            http_response_code(500);
            echo 'PDF generation failed: ' . $fullPath;
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="result_' . $slug . '_' . date('YmdHis') . '.pdf"');
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

    /**
     * Get partner results for comparison
     */
    private function getPartnerResults(array $comparison, string $currentSessionId): array
    {
        $partnerSessionId = $comparison['session_1_id'] === $currentSessionId
            ? $comparison['session_2_id']
            : $comparison['session_1_id'];

        $partnerSession = $this->sessionManager->getSessionById($partnerSessionId);

        return $partnerSession['calculated_results'] ?? [];
    }

    /**
     * Attach pair comparison data to $results (in place) when a pair
     * comparison exists for this session. Shared by show() and pdf() so
     * both render the comparison block consistently.
     *
     * @param array<string, mixed>                &$results Calculated results (modified).
     * @param array<string, mixed>                $session  Session row.
     * @param \PsyTest\Modules\TestModuleInterface $module   Module instance.
     */
    private function enrichWithPairComparison(array &$results, array $session, \PsyTest\Modules\TestModuleInterface $module): void
    {
        if (!$module->supportsPairMode()) {
            return;
        }
        $pairComparison = $this->sessionManager->getPairComparisonBySession($session['id']);
        if (!$pairComparison) {
            return;
        }
        $partnerResults = $this->getPartnerResults($pairComparison, $session['id']);
        $results['pair_comparison'] = $module->comparePairResults(
            $session['calculated_results'],
            $partnerResults
        );
    }
}
