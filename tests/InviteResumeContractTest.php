<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

/** Разметка и сценарий продолжения прохождения (07.K12). */
final class InviteResumeContractTest extends TestCase
{
    public function testStartPageHasResumeModeWithProgressAndPrimaryButton(): void
    {
        $page = $this->read('templates/test-invite-start.twig');

        self::assertStringContainsString('{% if resume %}', $page);
        self::assertStringContainsString('data-invite-resume', $page);
        self::assertStringContainsString('Вы уже отвечали: <strong>{{ resume.answered }} из {{ resume.total }}</strong>. Продолжить?', $page);
        self::assertStringContainsString("{{ resume ? 'Продолжить' : 'Начать тест' }}", $page);
        self::assertStringContainsString('btn btn-primary', $page);
    }

    public function testControllerResumesOnlyThroughTheInviteServiceAndPreloadsSavedState(): void
    {
        $controller = $this->read('controllers/TestController.php');

        self::assertStringContainsString('->resumable($token)', $controller);
        self::assertStringContainsString("'saved_answers' => \$resumed ? \$claimed['session']['answers'] : []", $controller);
        self::assertStringContainsString("'saved_demographics' => \$resumed ? \$claimed['session']['demographics'] : []", $controller);
        self::assertStringContainsString('mergeAnswers($session[\'id\'], $answers)', $controller);
        self::assertStringNotContainsString('CONTENT_TYPE', $controller, 'The save endpoint must accept any content type (keepalive/beacon).');
    }

    public function testWrapperExposesPreloadedAnswersAndDemographicsSafelyEncoded(): void
    {
        $wrapper = $this->read('templates/test-wrapper.twig');

        self::assertStringContainsString('answers: {{ (saved_answers ?? {})|json_encode(', $wrapper);
        self::assertStringContainsString('demographics: {{ (saved_demographics ?? {})|json_encode(', $wrapper);
        self::assertStringContainsString("constant('JSON_HEX_TAG')", $wrapper);
        self::assertStringContainsString("resume: {{ is_resume ? 'true' : 'false' }}", $wrapper);
    }

    public function testScriptSavesRightAfterAnAnswerAndFlushesWhenThePageHides(): void
    {
        $script = $this->read('public/js/test-taking.js');

        self::assertMatchesRegularExpression('/const SAVE_DELAY_MS = (\d{1,3});/', $script);
        preg_match('/const SAVE_DELAY_MS = (\d+);/', $script, $m);
        self::assertLessThanOrEqual(300, (int) $m[1]);
        self::assertStringContainsString("document.addEventListener('visibilitychange'", $script);
        self::assertStringContainsString("window.addEventListener('pagehide', flushPendingSave)", $script);
        self::assertStringContainsString('keepalive: keepalive === true', $script);
        self::assertStringContainsString("'X-CSRF-Token': TEST_CONFIG.csrfToken", $script);
        self::assertStringContainsString('currentQuestionIndex = restoreSavedAnswers();', $script);
        self::assertStringContainsString('if (demographicsSection && startTestBtn && hasSavedDemographics())', $script);
    }

    public function testCasePageShowsProgressAndTheOneTimeReissueActionWithCopyButton(): void
    {
        $case = $this->read('templates/owner-invited-case.twig');

        self::assertStringContainsString('<dt>Отвечено</dt>', $case);
        self::assertStringContainsString('{{ case.progress.answered }} из {{ case.progress.total }}', $case);
        self::assertStringContainsString('Ссылка для продолжения', $case);
        self::assertStringContainsString('action="{{ caseUrl }}/resume-link"', $case);
        self::assertStringContainsString('name="form_key" value="{{ resume_form_key }}"', $case);
        self::assertStringContainsString('name="csrf_token"', $case);
        self::assertStringContainsString('data-copy-target="owner-invite-url-field"', $case);
        self::assertStringContainsString("js/owner-copy.js", $case);
    }

    public function testRouteAndOwnerCodeNeverPutTheTokenIntoMessagesOrLogs(): void
    {
        self::assertStringContainsString(
            "'/admin/invited-case/{sessionId}/resume-link', [OwnerController::class, 'issueResumeLink']",
            $this->read('public/index.php'),
        );
        $form = $this->read('core/OwnerCaseResumeLink.php');
        self::assertStringContainsString("'invite_url' => \$this->appUrl . '/invite/' . \$token", $form);
        self::assertStringNotContainsString("'message' => 'Ссылка для продолжения готова' . \$token", $form);
        $service = $this->read('core/TestInviteService.php');
        self::assertStringContainsString("'action' => 'invite_resume_link_issued'", $service);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/' . $path);
    }
}
