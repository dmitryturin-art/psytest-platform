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
}
