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
use PsyTest\Core\TestInviteService;
use PsyTest\Modules\TestModuleInterface;
use Ramsey\Uuid\Uuid;

class TestController extends BaseController
{
    /** Render a bearer-link preview without consuming the invitation. */
    public function invite(string $token): void
    {
        $service = new TestInviteService($this->db, $this->sessionManager);
        $invite = $service->preview($token);
        $resume = null;
        if ($invite === null) {
            // Та же ссылка, уже открытая и не завершённая: предлагаем продолжить (07.K12).
            $resumable = $service->resumable($token);
            if ($resumable === null) {
                $this->notFoundTest('invite');

                return;
            }
            $invite = ['test_name' => $resumable['test']['name'], 'test_slug' => $resumable['test']['slug']];
            $resume = [
                'answered' => TestInviteService::answeredCount($resumable['session']['answers']),
                'total' => $this->totalQuestions((string) $resumable['test']['slug']),
            ];
        }

        echo $this->view->render('test-invite-start', [
            'token' => $token,
            'test_name' => $invite['test_name'],
            'resume' => $resume,
            // Инструкция методики перед началом (07.K13); при продолжении — свёрнута.
            'instruction' => $this->instructionFor((string) ($invite['test_slug'] ?? '')),
        ]);
    }

    /** Claim a one-time owner invitation, independently from pair links. */
    public function startInvite(string $token): void
    {
        $service = new TestInviteService($this->db, $this->sessionManager);
        $claimed = $service->claim($token);
        $resumed = false;
        if ($claimed === null) {
            $claimed = $service->resumable($token);
            $resumed = $claimed !== null;
        }
        if ($claimed === null) {
            $this->notFoundTest('invite');

            return;
        }

        $test = $this->getTestOrFail((string) $claimed['test']['slug']);
        $module = $this->getModuleOrFail((string) $test['slug']);
        $template = $module->getTestTemplate() ?? 'test-wrapper';

        echo $this->view->render($template, [
            'test' => array_merge($test, $module->getMetadata()),
            'session' => $claimed['session'],
            'questions' => $module->getQuestions(),
            'module' => $module,
            'is_test_invite' => true,
            // Продолжение (07.K12): сохранённые ответы и анкета подставляются в TEST_CONFIG.
            'saved_answers' => $resumed ? $claimed['session']['answers'] : [],
            'saved_demographics' => $resumed ? $claimed['session']['demographics'] : [],
            'is_resume' => $resumed,
            // Инструкцию уже показала стартовая страница приглашения: здесь она свёрнута (07.K13).
            'instruction' => $module->getInstruction(),
            'instruction_collapsed' => true,
        ]);
    }

    /** @return list<string> */
    private function instructionFor(string $slug): array
    {
        $module = $slug === '' ? null : $this->moduleLoader->getModule($slug);

        return $module === null ? [] : $module->getInstruction();
    }

    private function totalQuestions(string $slug): int
    {
        $module = $this->moduleLoader->getModule($slug);

        return $module === null ? 0 : (int) ($module->getMetadata()['question_count'] ?? 0);
    }

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
            'instruction' => $module->getInstruction(),
        ]);
    }

    /**
     * Доступ к методике по прямой ссылке `/test/{slug}`.
     *
     * Публичная методика доступна всем. Закрытая не открывается этим путём
     * никогда: единственный вход в неё — личное одноразовое приглашение из
     * кабинета специалиста (`/invite/{token}`), которое само создаёт сессию.
     * Общий ключ доступа снят решением владельца 14.09.2026.
     *
     * @param array<string, mixed> $test Строка методики из БД.
     */
    private function grantsInviteAccess(array $test): bool
    {
        return ($test['visibility'] ?? 'public') !== 'invite';
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
        if (is_array($answers)) {
            $answers = AnswerValidator::withoutExtraKeys($this->getModuleOrFail($slug), $answers);
        }
        if (!is_array($answers) || AnswerValidator::validatePartial($this->getModuleOrFail($slug), $answers) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid answers']);
            return;
        }
        // Сохранение дополняет, а не заменяет (07.K12): пустой набор и устаревшая
        // вкладка не стирают то, что уже записано.
        $this->sessionManager->mergeAnswers($session['id'], $answers);

        // Save demographics if provided
        $demographics = $this->savableDemographics($input['demographics'] ?? []);
        if ($demographics !== []) {
            $this->sessionManager->saveDemographics($session['id'], $demographics);
        }

        echo json_encode(['success' => true]);
    }

    /**
     * Из анкеты при промежуточном сохранении принимаются только пол и возраст
     * с допустимыми значениями; всё остальное отбрасывается.
     *
     * @return array<string, int|string>
     */
    private function savableDemographics(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $clean = [];
        if (in_array($input['gender'] ?? null, ['male', 'female'], true)) {
            $clean['gender'] = $input['gender'];
        }
        $age = $input['age'] ?? null;
        if ((is_int($age) || (is_string($age) && preg_match('/\A\d{1,3}\z/', $age) === 1)) && (int) $age >= 1 && (int) $age <= 120) {
            $clean['age'] = (int) $age;
        }

        return $clean;
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
        if ($session['status'] === 'completed') {
            header('Location: /result/' . $slug . '/' . $session['session_token']);
            exit;
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

        // Calculate results
        $rawResults = $module->calculateResults($allAnswers);

        // Generate interpretation
        $interpretation = $module->generateInterpretation($rawResults);

        // Persist answers and result in one conditional transition. A second
        // concurrent submit cannot alter an already completed clinical record.
        $this->sessionManager->finalizeSession(
            $sessionId,
            $allAnswers,
            array_merge($rawResults, ['interpretation' => $interpretation]),
            $formDemographics !== [] ? $formDemographics : null,
        );

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
            'instruction' => $module->getInstruction(),
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
        if ($session['status'] === 'completed') {
            header('Location: /result/' . $slug . '/' . $session['session_token']);
            exit;
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
        if (!empty($session['demographics'])) {
            $allAnswers = AnswerMerger::overlay($allAnswers, $session['demographics']);
        }
        if (!empty($formDemographics)) {
            $allAnswers = AnswerMerger::overlay($allAnswers, $formDemographics);
        }
        if (AnswerValidator::validate($module, $allAnswers, true) !== []) {
            $this->errorResponse('Некорректные или неполные ответы', 422);
        }
        // Calculate results & complete this (second partner's) session.
        $rawResults = $module->calculateResults($allAnswers);
        $rawResults['is_pair_partner'] = true;
        $interpretation = $module->generateInterpretation($rawResults);
        if (!$this->sessionManager->finalizeSession(
            $sessionId,
            $allAnswers,
            array_merge($rawResults, ['interpretation' => $interpretation]),
            $formDemographics !== [] ? $formDemographics : null,
        )) {
            header('Location: /result/' . $slug . '/' . $session['session_token']);
            exit;
        }

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
