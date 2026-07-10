<?php

/**
 * Test Controller
 *
 * Handles test taking flow
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Modules\TestModuleInterface;
use Ramsey\Uuid\Uuid;

class TestController extends BaseController
{
    /**
     * Start a test
     * GET /test/{slug}
     */
    public function start(string $slug): void
    {
        // Get module
        $module = $this->getModuleOrFail($slug);
        $metadata = $module->getMetadata();

        // Check if test is active in database
        $test = $this->getTestOrFail($slug);

        // Create new session
        $session = $this->sessionManager->createSession($test['id'], [
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        // Get questions
        $questions = $module->getQuestions();

        // Get template (custom or default)
        $template = $module->getTestTemplate() ?? 'test-wrapper';

        echo $this->view->render($template, [
            'test' => array_merge($test, $metadata),
            'session' => $session,
            'questions' => $questions,
            'module' => $module, // Pass module for custom JS/demographics
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

        // Save demographics if provided
        $demographics = $input['demographics'] ?? [];
        if (!empty($demographics)) {
            $this->sessionManager->saveDemographics($session['id'], $demographics);
        }

        echo json_encode(['success' => true]);
    }

    /**
     * Submit test for scoring
     * POST /test/{slug}/submit
     */
    public function submit(string $slug): void
    {
        // Get module
        $module = $this->getModuleOrFail($slug);

        // Get session from POST data
        $sessionId = $_POST['session_id'] ?? null;
        if (!$sessionId || !Uuid::isValid($sessionId)) {
            $this->errorResponse('Invalid session ID format', 400);
        }

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

        // Merge demographics from form into answers (for calculateResults)
        $formDemographics = $_POST['demographics'] ?? [];
        if (!empty($formDemographics)) {
            $this->sessionManager->saveDemographics($sessionId, $formDemographics);
        }
        // Also merge demographics from session (saved via AJAX)
        if (!empty($session['demographics'])) {
            $allAnswers = array_merge($allAnswers, $session['demographics']);
        }
        // Form demographics take precedence over AJAX-saved ones
        if (!empty($formDemographics)) {
            $allAnswers = array_merge($allAnswers, $formDemographics);
        }

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

        // Verify partner session (strictly by session_token — see pairSubmit note)
        $partnerSession = $this->sessionManager->getSessionBySessionToken($partnerToken);
        if (!$partnerSession) {
            http_response_code(404);
            echo 'Partner session not found';
            return;
        }

        // Get module
        $module = $this->getModuleOrFail($slug);
        if (!$module->supportsPairMode()) {
            http_response_code(400);
            echo 'This test does not support pair mode';
            return;
        }

        $metadata = $module->getMetadata();

        // Get test from DB
        $test = $this->getTestOrFail($slug);

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
     *
     * Second partner submits their answers. Calculates their results,
     * completes the session, then creates a pair comparison linking the
     * first partner's session (found via partner_token).
     */
    public function pairSubmit(string $slug): void
    {
        $module = $this->getModuleOrFail($slug);
        if (!$module->supportsPairMode()) {
            http_response_code(400);
            echo 'This test does not support pair mode';
            return;
        }

        $sessionId = $_POST['session_id'] ?? null;
        $partnerToken = $_POST['partner_token'] ?? null;

        if (!$sessionId || !Uuid::isValid($sessionId) || !$partnerToken) {
            http_response_code(400);
            echo 'Missing or invalid session/partner data';
            return;
        }

        $session = $this->sessionManager->getSessionById($sessionId);
        if (!$session || $session['test_id'] !== $this->getTestIdBySlug($slug)) {
            http_response_code(404);
            echo 'Session not found';
            return;
        }

        // Collect & normalize answers (same logic as submit()).
        $answers = $_POST['answers'] ?? [];
        $normalizedAnswers = [];
        foreach ($answers as $questionId => $answer) {
            if (is_numeric($answer)) {
                $normalizedAnswers[$questionId] = (int) $answer;
            } else {
                $normalizedAnswers[$questionId] = $answer === 'true' || $answer === true;
            }
        }

        $allAnswers = array_merge($session['answers'], $normalizedAnswers);
        $formDemographics = $_POST['demographics'] ?? [];
        if (!empty($formDemographics)) {
            $this->sessionManager->saveDemographics($sessionId, $formDemographics);
        }
        if (!empty($session['demographics'])) {
            $allAnswers = array_merge($allAnswers, $session['demographics']);
        }
        if (!empty($formDemographics)) {
            $allAnswers = array_merge($allAnswers, $formDemographics);
        }
        $this->sessionManager->saveAnswers($sessionId, $allAnswers);

        // Calculate results & complete this (second partner's) session.
        $rawResults = $module->calculateResults($allAnswers);
        $rawResults['is_pair_partner'] = true;
        $interpretation = $module->generateInterpretation($rawResults);
        $this->sessionManager->completeSession($sessionId, array_merge($rawResults, [
            'interpretation' => $interpretation,
        ]));

        // Find the first partner's session strictly by their own session_token.
        // getSessionBySessionToken() (not getSessionByToken) — the latter also
        // matches partner_token and could return the second partner's own session
        // instead, since both share the same partner_token value.
        $partnerSession = $this->sessionManager->getSessionBySessionToken($partnerToken);
        if (!$partnerSession || empty($partnerSession['calculated_results'])) {
            // First partner hasn't completed yet — redirect to own result page.
            header('Location: /result/' . $slug . '/' . $session['session_token']);
            exit;
        }

        $comparison = $module->comparePairResults(
            $partnerSession['calculated_results'],
            $rawResults
        );

        $comparisonRecord = $this->sessionManager->createPairComparison(
            (int) $session['test_id'],
            $partnerSession['id'],
            $sessionId,
            $comparison
        );

        // Redirect Partner 2 to THEIR OWN result page. The result page (show())
        // finds the comparison via getPairComparisonBySession() and renders the
        // comparison block alongside their personal scores — same as Partner 1.
        // No separate /pair/{id} page is needed for the normal flow.
        header('Location: /result/' . $slug . '/' . $session['session_token']);
        exit;
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
