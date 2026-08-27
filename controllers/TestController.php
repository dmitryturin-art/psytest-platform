<?php

/**
 * Test Controller
 *
 * Handles test taking flow
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\AnswerMerger;
use PsyTest\Core\AnswerValidator;
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

        if (!$this->grantsInviteAccess($test)) {
            // Отвечаем «не найдено», а не «запрещено»: закрытая методика не
            // должна подтверждать посторонним даже сам факт своего существования.
            $this->notFoundTest($slug);

            return;
        }

        // Create new session
        $session = $this->sessionManager->createSession($test['id']);

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
     * Доступ к методике, закрытой ссылкой-приглашением.
     *
     * Публичная методика доступна всем. Закрытая требует ключ в адресе
     * (`?key=…`); успешный ключ запоминается в сессии браузера, чтобы
     * перезагрузка страницы и переход по шагам не выбрасывали респондента.
     *
     * Это шлюз, а не опознание конкретного человека: персональные приглашения
     * появятся вместе с купонным контуром.
     *
     * @param array<string, mixed> $test Строка методики из БД.
     */
    private function grantsInviteAccess(array $test): bool
    {
        if (($test['visibility'] ?? 'public') !== 'invite') {
            return true;
        }

        $expected = (string) ($test['access_key'] ?? '');
        if ($expected === '') {
            // Закрытая методика без ключа недоступна никому: это безопаснее,
            // чем открыть её из-за незаполненной настройки.
            return false;
        }

        $slug = (string) $test['slug'];
        $sessionKey = 'psytest_invite_' . $slug;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $provided = $_GET['key'] ?? null;
        if (is_string($provided) && hash_equals($expected, $provided)) {
            $_SESSION[$sessionKey] = true;

            return true;
        }

        return ($_SESSION[$sessionKey] ?? false) === true;
    }

    private function notFoundTest(string $slug): void
    {
        http_response_code(404);
        echo $this->view->render('error-page', [
            'error' => 'Test not found',
            'message' => "Test '{$slug}' is not available.",
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
        $session = $this->sessionManager->getSessionByResultToken($input['session_token']);
        if (!$session || !$this->getSessionTestForRoute($session, $slug)) {
            echo json_encode(['success' => false, 'error' => 'Session not found']);
            return;
        }

        // Save answers
        $answers = $input['answers'] ?? [];
        if (!is_array($answers) || AnswerValidator::validate($this->getModuleOrFail($slug), $answers, false) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid answers']);
            return;
        }
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
        if (!$session || !$this->getSessionTestForRoute($session, $slug)) {
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
        $allAnswers = AnswerMerger::overlay($session['answers'], $normalizedAnswers);

        // Merge demographics from form into answers (for calculateResults)
        $formDemographics = $_POST['demographics'] ?? [];
        if (!empty($formDemographics)) {
            $this->sessionManager->saveDemographics($sessionId, $formDemographics);
        }
        // Also merge demographics from session (saved via AJAX)
        if (!empty($session['demographics'])) {
            $allAnswers = AnswerMerger::overlay($allAnswers, $session['demographics']);
        }
        // Form demographics take precedence over AJAX-saved ones
        if (!empty($formDemographics)) {
            $allAnswers = AnswerMerger::overlay($allAnswers, $formDemographics);
        }

        if (AnswerValidator::validate($module, $allAnswers, true) !== []) {
            $this->errorResponse('Invalid or incomplete answers', 422);
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
            $this->renderPairInviteError(400, 'Не найдена ссылка-приглашение', 'Откройте полную ссылку, которую прислал первый участник.');
            return;
        }

        // The pair invite contains the first partner's result-access token.
        $partnerSession = $this->sessionManager->getSessionByResultToken($partnerToken);
        if (!$partnerSession) {
            $this->renderPairInviteError(404, 'Приглашение недоступно', 'Ссылка устарела, удалена или относится к недоступному результату.');
            return;
        }

        // Get test from DB before accepting an invite so a token for one test
        // cannot create a pair session for another.
        $test = $this->getTestOrFail($slug);
        if (!$this->getSessionTestForRoute($partnerSession, $slug)) {
            $this->renderPairInviteError(404, 'Приглашение недоступно', 'Эта ссылка не относится к выбранному тесту.');
            return;
        }

        // Get module
        $module = $this->getModuleOrFail($slug);
        if (!$module->supportsPairMode()) {
            $this->renderPairInviteError(400, 'Парный режим недоступен', 'Для этой методики нельзя создать парное сравнение.');
            return;
        }

        $metadata = $module->getMetadata();

        $session = $this->sessionManager->getPairSessionForSourceToken($partnerToken);
        if ($session && $session['status'] === 'completed') {
            $this->renderPairInviteError(409, 'Партнёр уже завершил тест', 'Сравнение будет доступно на странице результатов первого участника.');
            return;
        }

        if ($session === null) {
            // The database uniqueness constraint resolves concurrent opens. If
            // another request created an unfinished session, resume it instead
            // of treating an unanswered invite as consumed.
            $session = $this->sessionManager->createPairSession($test['id'], $partnerToken);
            if ($session === null) {
                $session = $this->sessionManager->getPairSessionForSourceToken($partnerToken);
                if (!$session || $session['status'] === 'completed') {
                    $this->renderPairInviteError(409, 'Партнёр уже завершил тест', 'Сравнение будет доступно на странице результатов первого участника.');
                    return;
                }
            }
        }

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
            $this->errorResponse('Для этой методики недоступен парный режим', 400);
            return;
        }

        $sessionId = $_POST['session_id'] ?? null;
        $partnerToken = $_POST['partner_token'] ?? null;

        if (!$sessionId || !Uuid::isValid($sessionId) || !$partnerToken) {
            $this->errorResponse('Некорректные данные парного прохождения', 400);
            return;
        }

        $session = $this->sessionManager->getSessionById($sessionId);
        if (
            !$session
            || !$this->getSessionTestForRoute($session, $slug)
            || !$this->sessionManager->isPairSessionBoundToSourceToken($sessionId, $partnerToken)
        ) {
            $this->errorResponse('Парное прохождение не найдено', 404);
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

        $allAnswers = AnswerMerger::overlay($session['answers'], $normalizedAnswers);
        $formDemographics = $_POST['demographics'] ?? [];
        if (!empty($formDemographics)) {
            $this->sessionManager->saveDemographics($sessionId, $formDemographics);
        }
        if (!empty($session['demographics'])) {
            $allAnswers = AnswerMerger::overlay($allAnswers, $session['demographics']);
        }
        if (!empty($formDemographics)) {
            $allAnswers = AnswerMerger::overlay($allAnswers, $formDemographics);
        }
        if (AnswerValidator::validate($module, $allAnswers, true) !== []) {
            $this->errorResponse('Некорректные или неполные ответы', 422);
        }
        $this->sessionManager->saveAnswers($sessionId, $allAnswers);

        // Calculate results & complete this (second partner's) session.
        $rawResults = $module->calculateResults($allAnswers);
        $rawResults['is_pair_partner'] = true;
        $interpretation = $module->generateInterpretation($rawResults);
        $this->sessionManager->completeSession($sessionId, array_merge($rawResults, [
            'interpretation' => $interpretation,
        ]));

        // Resolve the first partner by their own result-access token. A
        // partner_token is a relationship reference, never an access token.
        $partnerSession = $this->sessionManager->getSessionByResultToken($partnerToken);
        if (
            !$partnerSession
            || !$this->getSessionTestForRoute($partnerSession, $slug)
            || empty($partnerSession['calculated_results'])
        ) {
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

    private function renderPairInviteError(int $statusCode, string $title, string $message): void
    {
        http_response_code($statusCode);
        echo $this->view->render('pair-invite-error', [
            'title' => $title,
            'message' => $message,
        ]);
    }
}
