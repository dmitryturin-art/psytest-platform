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
        $pairComparisonHtml = null;
        if ($pairComparison && $module->supportsPairMode()) {
            $pairComparisonHtml = $module->comparePairResults(
                $session['calculated_results'],
                $this->getPartnerResults($pairComparison, $session['id'])
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
        $fullPath = __DIR__ . '/..' . $pdfPath;
        if (!file_exists($fullPath)) {
            http_response_code(500);
            echo 'PDF generation failed';
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

        // Render comparison
        $comparisonHtml = $module->comparePairResults(
            $session1['calculated_results'],
            $session2['calculated_results']
        );

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
        $fullPath = __DIR__ . '/..' . $pdfPath;
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
     */
    private function renderSectionsToHtml(array $sections): string
    {
        $html = '';
        foreach ($sections as $section) {
            if ($section->block) {
                $html .= $this->view->render($section->block, $section->data);
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
}
