<?php
/**
 * Test Controller
 * 
 * Handles test taking flow
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\View;
use PsyTest\Modules\TestModuleInterface;

class TestController
{
    private Database $db;
    private View $view;
    private ModuleLoader $moduleLoader;
    private SessionManager $sessionManager;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->view = View::getInstance();
        $this->moduleLoader = (new ModuleLoader(null, $this->db))->discover();
        $this->sessionManager = new SessionManager($this->db);
    }
    
    /**
     * Start a test
     * GET /test/{slug}
     */
    public function start(string $slug): void
    {
        // Get module
        $module = $this->moduleLoader->getModule($slug);
        if (!$module) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }
        
        $metadata = $module->getMetadata();
        
        // Check if test is active in database
        $test = $this->db->selectOne('SELECT * FROM tests WHERE slug = ? AND is_active = 1', [$slug]);
        if (!$test) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }
        
        // Create new session
        $session = $this->sessionManager->createSession($test['id'], [
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        
        // Get questions
        $questions = $module->getQuestions();
        
        // Shuffle questions if needed (for some tests)
        // shuffle($questions);
        
        echo $this->view->render('test-wrapper', [
            'test' => array_merge($test, $metadata),
            'session' => $session,
            'questions' => $questions,
        ]);
    }
    
    /**
     * Save answers (AJAX)
     * POST /test/{slug}/save
     */
    public function save(string $slug): void
    {
        header('Content-Type: application/json');
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || empty($input['session_token'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid request']);
            return;
        }
        
        // Verify session
        $session = $this->sessionManager->getSessionByToken($input['session_token']);
        if (!$session) {
            echo json_encode(['success' => false, 'error' => 'Session not found']);
            return;
        }
        
        // Save answers
        $answers = $input['answers'] ?? [];
        $this->sessionManager->saveAnswers($session['id'], $answers);
        
        echo json_encode(['success' => true]);
    }
    
    /**
     * Submit test for scoring
     * POST /test/{slug}/submit
     */
    public function submit(string $slug): void
    {
        // Get module
        $module = $this->moduleLoader->getModule($slug);
        if (!$module) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }
        
        // Get session from POST data
        $sessionId = $_POST['session_id'] ?? null;
        if (!$sessionId) {
            http_response_code(400);
            echo 'Invalid session';
            return;
        }

        // Debug logging
        error_log("BAI Submit - Session ID: {$sessionId}");
        error_log("BAI Submit - POST answers: " . print_r($_POST['answers'] ?? [], true));

        $session = $this->sessionManager->getSessionById($sessionId);
        if (!$session || $session['test_id'] !== $this->getTestIdBySlug($slug)) {
            http_response_code(404);
            echo 'Session not found';
            return;
        }
        
        // Collect all answers from POST
        $answers = $_POST['answers'] ?? [];
        
        // Normalize answers - convert string values to proper types
        $normalizedAnswers = [];
        foreach ($answers as $questionId => $answer) {
            // Convert to integer if it's a numeric string (for BAI: 0,1,2,3)
            if (is_numeric($answer)) {
                $normalizedAnswers[$questionId] = (int) $answer;
            } else {
                $normalizedAnswers[$questionId] = $answer === 'true' || $answer === true;
            }
        }

        // Merge with previously saved answers
        $allAnswers = array_merge($session['answers'], $normalizedAnswers);

        // Save final answers
        $this->sessionManager->saveAnswers($sessionId, $allAnswers);

        // Calculate results
        $rawResults = $module->calculateResults($allAnswers);
        
        // Generate interpretation
        $interpretation = $module->generateInterpretation($rawResults);
        
        // Complete session
        $this->sessionManager->completeSession($sessionId, array_merge($rawResults, [
            'interpretation' => $interpretation,
        ]));
        
        // Redirect to results page
        header('Location: /result/' . $slug . '/' . $session['session_token']);
        exit;
    }
    
    /**
     * Start pair test
     * GET /test/{slug}/pair?partner={token}
     */
    public function pairStart(string $slug): void
    {
        $partnerToken = $_GET['partner'] ?? null;
        if (!$partnerToken) {
            http_response_code(400);
            echo 'Partner token required';
            return;
        }
        
        // Verify partner session
        $partnerSession = $this->sessionManager->getSessionByToken($partnerToken);
        if (!$partnerSession) {
            http_response_code(404);
            echo 'Partner session not found';
            return;
        }
        
        // Get module
        $module = $this->moduleLoader->getModule($slug);
        if (!$module || !$module->supportsPairMode()) {
            http_response_code(400);
            echo 'This test does not support pair mode';
            return;
        }
        
        $metadata = $module->getMetadata();
        
        // Get test from DB
        $test = $this->db->selectOne('SELECT * FROM tests WHERE slug = ? AND is_active = 1', [$slug]);
        if (!$test) {
            http_response_code(404);
            echo $this->view->render('error-page');
            return;
        }
        
        // Create new session with partner token
        $session = $this->sessionManager->createSession($test['id'], [
            'partner_token' => $partnerToken,
        ]);
        
        $questions = $module->getQuestions();
        
        echo $this->view->render('test-wrapper', [
            'test' => array_merge($test, $metadata),
            'session' => $session,
            'questions' => $questions,
            'is_pair' => true,
            'partner_token' => $partnerToken,
        ]);
    }
    
    /**
     * Submit pair test
     * POST /test/{slug}/pair/submit
     */
    public function pairSubmit(string $slug): void
    {
        // Similar to submit, but creates pair comparison
        $module = $this->moduleLoader->getModule($slug);
        if (!$module || !$module->supportsPairMode()) {
            http_response_code(400);
            echo 'This test does not support pair mode';
            return;
        }
        
        $sessionId = $_POST['session_id'] ?? null;
        $partnerToken = $_POST['partner_token'] ?? null;
        
        if (!$sessionId || !$partnerToken) {
            http_response_code(400);
            echo 'Missing required data';
            return;
        }
        
        // Process submission similar to regular submit...
        // Then create pair comparison
        
        echo json_encode(['success' => true, 'redirect' => '/pair/{comparison_id}']);
    }
    
    /**
     * Get test ID by slug
     */
    private function getTestIdBySlug(string $slug): int
    {
        $test = $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug]);
        return $test['id'] ?? 0;
    }
}
