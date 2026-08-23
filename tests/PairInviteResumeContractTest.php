<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class PairInviteResumeContractTest extends TestCase
{
    public function testUnfinishedPartnerSessionIsResumedInsteadOfRejected(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/controllers/TestController.php');

        self::assertStringContainsString('getPairSessionForSourceToken($partnerToken)', $controller);
        self::assertStringContainsString("\$session['status'] === 'completed'", $controller);
        self::assertStringContainsString('createPairSession($test[\'id\'], $partnerToken)', $controller);
        self::assertStringNotContainsString("echo 'Partner invite has already been used'", $controller);
    }

    public function testPairInviteErrorsAreRenderedInRussian(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/pair-invite-error.twig');

        self::assertStringContainsString('Все тесты', $template);
        self::assertStringNotContainsString('Partner invite', $template);
    }
}
