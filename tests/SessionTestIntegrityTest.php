<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\SessionTestIntegrity;

final class SessionTestIntegrityTest extends TestCase
{
    public function testMatchesOnlyTheSessionOwningTest(): void
    {
        self::assertTrue(SessionTestIntegrity::matches(['test_id' => '4'], ['id' => 4]));
        self::assertFalse(SessionTestIntegrity::matches(['test_id' => 4], ['id' => 5]));
    }

    public function testRejectsIncompleteRows(): void
    {
        self::assertFalse(SessionTestIntegrity::matches([], ['id' => 4]));
        self::assertFalse(SessionTestIntegrity::matches(['test_id' => 4], []));
    }

    public function testAllSlugBoundFlowsUseTheSharedGuard(): void
    {
        $projectRoot = dirname(__DIR__);
        $resultController = (string) file_get_contents($projectRoot . '/controllers/ResultController.php');
        $testController = (string) file_get_contents($projectRoot . '/controllers/TestController.php');

        // Показ результата, PDF, опрос парного статуса и поток расширенного
        // разбора: каждый привязанный к slug поток обязан идти через общую защиту.
        self::assertSame(4, substr_count($resultController, 'getSessionTestForRoute($session, $slug)'));
        self::assertSame(5, substr_count($testController, 'getSessionTestForRoute('));
        self::assertStringContainsString('getSessionTestForRoute($partnerSession, $slug)', $testController);
    }

    public function testPairSubmitBindsTheSecondSessionToItsInvite(): void
    {
        $testController = (string) file_get_contents(dirname(__DIR__) . '/controllers/TestController.php');
        $normalSubmit = substr($testController, 0, (int) strpos($testController, 'public function pairStart'));
        $pairSubmit = substr($testController, (int) strpos($testController, 'public function pairSubmit'));

        self::assertIsString($normalSubmit);
        self::assertIsString($pairSubmit);
        self::assertStringContainsString(
            'isPairSessionBoundToSourceToken($sessionId, $partnerToken)',
            $pairSubmit,
        );
        self::assertStringNotContainsString('isPairSessionBoundToSourceToken', $normalSubmit);
    }

    public function testPairStartTranslatesInviteRaceIntoConflict(): void
    {
        $testController = (string) file_get_contents(dirname(__DIR__) . '/controllers/TestController.php');
        $pairStart = substr(
            $testController,
            (int) strpos($testController, 'public function pairStart'),
            (int) strpos($testController, 'public function pairSubmit') - (int) strpos($testController, 'public function pairStart'),
        );

        self::assertIsString($pairStart);
        self::assertStringContainsString('createPairSession($test[\'id\'], $partnerToken)', $pairStart);
        self::assertStringContainsString('renderPairInviteError(409', $pairStart);
    }

    public function testAiConsentAndTherapistDraftBoundaryAreEnforcedServerSide(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/ResultController.php');
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/blocks/ai-report.twig');

        self::assertStringContainsString("(\$_POST['ai_consent'] ?? null) !== '1'", $controller);
        self::assertStringContainsString("=== 'therapist_case'", $controller);
        self::assertStringContainsString("['status' => 'restricted']", $controller);
        self::assertStringContainsString('name="ai_consent"', $template);

        // Публикация одобренной редакции (D-054) не открыла клиенту статусы
        // заданий: `report-status` по-прежнему отвечает только «restricted».
        $statusAction = substr(
            $controller,
            (int) strpos($controller, 'public function reportStatus('),
            (int) strpos($controller, 'private function reportSessionOrFail(') - (int) strpos($controller, 'public function reportStatus('),
        );
        self::assertStringNotContainsString('published', $statusAction);
        self::assertStringNotContainsString('AiReportRevisionService', $statusAction);

        // На клиентской странице публикуется только понятный разбор и только
        // через presenter: черновиков и профессионального заключения в
        // restricted-ветке шаблона нет.
        $presenter = (string) file_get_contents(dirname(__DIR__) . '/core/ResultPresenter.php');
        self::assertStringContainsString('publishedContent', $presenter);
        self::assertStringContainsString("'restricted' => true", $presenter);

        $restrictedBranch = substr(
            $template,
            (int) strpos($template, '{% if ai_report.restricted %}'),
            (int) strpos($template, '{% for item in ai_report.kinds %}') - (int) strpos($template, '{% if ai_report.restricted %}'),
        );
        self::assertStringContainsString('ai_report.published.html', $restrictedBranch);
        self::assertStringNotContainsString('item.html', $restrictedBranch);
        self::assertStringNotContainsString('ai_consent', $restrictedBranch);
    }
}
