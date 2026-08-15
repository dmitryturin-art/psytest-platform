<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class LegacyPaidFlowContainmentTest extends TestCase
{
    public function testResultTemplatesDoNotAdvertiseRetiredInterpretationFlow(): void
    {
        $projectRoot = dirname(__DIR__);

        foreach (['templates/result-layout.twig', 'templates/result-page.twig'] as $template) {
            $contents = file_get_contents($projectRoot . '/' . $template);

            self::assertIsString($contents);
            self::assertStringNotContainsString('/interpretation/', $contents, $template);
            self::assertStringNotContainsString('ai_interpretation_available', $contents, $template);
        }
    }

    public function testPublicRoutesUseRetiredPaymentController(): void
    {
        $contents = file_get_contents(dirname(__DIR__) . '/public/index.php');

        self::assertIsString($contents);
        self::assertStringContainsString('RetiredPaymentController::class', $contents);
        self::assertStringNotContainsString("[ResultController::class, 'initiatePayment']", $contents);
        self::assertStringNotContainsString("[ApiController::class, 'yoomoneyWebhook']", $contents);
    }
}
