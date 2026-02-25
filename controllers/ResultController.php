<?php
/**
 * Result Controller
 * 
 * Handles result display and PDF generation
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\View;
use PsyTest\Core\PDFGenerator;

class ResultController
{
    private Database $db;
    private View $view;
    private ModuleLoader $moduleLoader;
    private SessionManager $sessionManager;
    private PDFGenerator $pdfGenerator;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->view = View::getInstance();
        $this->moduleLoader = (new ModuleLoader(null, $this->db))->discover();
        $this->sessionManager = new SessionManager($this->db);
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
        $module = $this->moduleLoader->getModule($slug);
        if (!$module) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }
        
        // Get results
        $results = $session['calculated_results'];
        
        // Render results HTML
        $resultsHtml = $module->renderResults($results);
        
        // Get interpretation
        $interpretation = $results['interpretation'] ?? null;
        
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
        
        echo $this->view->render('result-page', [
            'test' => $test,
            'session' => $session,
            'results' => $results,
            'results_html' => $resultsHtml,
            'interpretation' => $interpretation,
            'ai_interpretation_available' => !$aiInterpretation,
            'pair_comparison' => $pairComparison,
            'pair_comparison_html' => $pairComparisonHtml,
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
        $module = $this->moduleLoader->getModule($slug);
        if (!$module) {
            http_response_code(404);
            echo 'Module not found';
            return;
        }
        
        // Get results
        $results = $session['calculated_results'];
        $resultsHtml = $module->renderResults($results);
        
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
        $module = $this->moduleLoader->getModule($test['slug']);
        if (!$module) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }
        
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
        $comparisonHtml = $module->renderResults($comparison['comparison_data']);
        
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
