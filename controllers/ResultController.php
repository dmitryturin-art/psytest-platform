<?php

/**
 * Result Controller
 *
 * Handles result display and PDF generation
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\PDFGenerator;
use PsyTest\Modules\ResultSection;

class ResultController extends BaseController
{
    private PDFGenerator $pdfGenerator;

    public function __construct()
    {
        parent::__construct();
        $this->pdfGenerator = new PDFGenerator();
    }

    /**
     * Show results page
     * GET /result/{slug}/{token}
     */
    public function show(string $slug, string $token): void
    {
        // Get session
        $session = $this->sessionManager->getSessionByToken($token);
        if (!$session) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }

        // Get test
        $test = $this->db->selectOne('SELECT * FROM tests WHERE slug = ?', [$slug]);
        if (!$test) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }

        // Get module
        $module = $this->getModuleOrFail($slug);

        // Get results
        $results = $session['calculated_results'];

        // Check for AI interpretation
        $aiInterpretation = $this->db->selectOne(
            'SELECT * FROM ai_interpretations WHERE session_id = ? AND payment_status = "completed"',
            [$session['id']]
        );

        // Check for pair comparison
        $pairComparison = $this->sessionManager->getPairComparisonBySession($session['id']);
        if ($pairComparison && $module->supportsPairMode()) {
            $partnerResults = $this->getPartnerResults($pairComparison, $session['id']);
            $results['pair_comparison'] = $module->comparePairResults(
                $session['calculated_results'],
                $partnerResults
            );
        }

        $sections = $module->buildSections($results);

        echo $this->view->render('result-layout', [
            'test' => $test,
            'session' => $session,
            'sections' => $sections,
            'results' => $results,
            'ai_interpretation_available' => !$aiInterpretation,
            'pair_comparison' => $pairComparison,
        ]);
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
        $session = $this->sessionManager->getSessionByToken($token);
        if (!$session) {
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
        $session = $this->sessionManager->getSessionByToken($token);
        if (!$session) {
            http_response_code(404);
            echo 'Session not found';
            return;
        }

        // Get test
        $test = $this->db->selectOne('SELECT * FROM tests WHERE slug = ?', [$slug]);
        if (!$test) {
            http_response_code(404);
            echo 'Test not found';
            return;
        }

        // Get module
        $module = $this->getModuleOrFail($slug);

        // Get results
        $results = $session['calculated_results'];

        $sections = $module->buildSections($results);
        $resultsHtml = $this->renderSectionsToHtml($sections);

        // Generate PDF
        $pdfPath = $this->pdfGenerator->generateTestResult($session, $test, $resultsHtml);

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

        $session = $this->sessionManager->getSessionByToken($token);
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

        echo $this->view->render('result-page', [
            'test' => $test,
            'session' => $session1,
            'pair_comparison' => $comparison,
            'pair_comparison_html' => $comparisonHtml,
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

        // Render comparison HTML
        $comparisonData = $comparison['comparison_data'];
        $comparisonHtml = '<pre>' . htmlspecialchars(is_string($comparisonData) ? $comparisonData : json_encode($comparisonData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';

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
        $session = $this->sessionManager->getSessionByToken($token);
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
     * Render sections array to concatenated HTML blocks for PDF.
     * For profile charts, replaces JS canvas with a static HTML chart.
     */
    private function renderSectionsToHtml(array $sections): string
    {
        $html = '';
        foreach ($sections as $section) {
            if ($section->type === ResultSection::TYPE_PROFILE_CHART) {
                // Replace JS canvas with static HTML chart for PDF
                $html .= $this->renderProfileChartHtml($section->data);
            } elseif ($section->block) {
                // Remove .twig extension if present, View::render will add it
                $template = $section->block;
                if (str_ends_with($template, '.twig')) {
                    $template = substr($template, 0, -5);
                }
                $html .= $this->view->render($template, $section->data);
            } elseif ($section->type === ResultSection::TYPE_RAW_HTML) {
                $html .= $section->data['html'] ?? '';
            }
        }
        return $html;
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
     * Render a static profile chart for PDF output using HTML/CSS.
     * Generates a bar chart compatible with DomPDF (no JavaScript, no SVG).
     *
     * @param array{scores?: list<float|int>, labels?: list<string>} $data Profile chart data with 'scores' and 'labels' arrays.
     *
     * @return string HTML bar chart
     */
    private function renderProfileChartHtml(array $data): string
    {
        $scores = $data['scores'] ?? [];
        $labels = $data['labels'] ?? [];
        $count = count($scores);

        if ($count === 0) {
            return '<p style="color:#999;text-align:center;">Данные профиля недоступны</p>';
        }

        $tMin = 20;
        $tMax = 120;
        $barWidth = 28;
        $maxHeight = 200;

        $bars = '';
        for ($i = 0; $i < $count; $i++) {
            $t = max($tMin, min($tMax, (float) $scores[$i]));
            $pct = (($t - $tMin) / ($tMax - $tMin)) * 100;
            $barH = round(($t - $tMin) / ($tMax - $tMin) * $maxHeight);
            $color = ($t >= 65 || $t <= 35) ? '#c0392b' : '#3498db';

            $bars .= '<div style="display:inline-block;text-align:center;vertical-align:bottom;margin:0 2px;">'
                . '<div style="font-size:7pt;font-weight:bold;color:' . $color . ';">' . (int) $t . '</div>'
                . '<div style="width:' . $barWidth . 'px;height:' . $barH . 'px;background:' . $color . ';margin:0 auto;"></div>'
                . '<div style="font-size:7pt;margin-top:2px;color:#333;">' . htmlspecialchars($labels[$i] ?? '') . '</div>'
                . '</div>';
        }

        return '<div style="margin:1em 0;text-align:center;">'
            . '<h3 style="font-size:11pt;color:#2c3e50;margin:0 0 0.5em 0;">Профиль личности (T-баллы)</h3>'
            . '<div style="border-bottom:2px solid #333;padding-bottom:4px;display:inline-block;">'
            . $bars
            . '</div>'
            . '<div style="font-size:7pt;color:#7f8c8d;margin-top:4px;">Норма: 30–70 T</div>'
            . '</div>';
    }
}
