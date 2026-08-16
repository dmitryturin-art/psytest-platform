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

        self::assertSame(3, substr_count($resultController, 'getSessionTestForRoute($session, $slug)'));
        self::assertSame(5, substr_count($testController, 'getSessionTestForRoute('));
        self::assertStringContainsString('getSessionTestForRoute($partnerSession, $slug)', $testController);
    }
}
