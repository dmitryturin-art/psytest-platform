<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Controllers\RetiredPaymentController;

final class RetiredPaymentControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        http_response_code(200);
    }

    public function testLegacyInterpretationPageIsRetiredWithoutLoadingData(): void
    {
        $response = (new RetiredPaymentController())->interpretation('ignored-token');

        self::assertSame(410, http_response_code());
        self::assertStringContainsString('временно недоступна', $response);
        self::assertStringContainsString('Базовый результат теста остаётся доступным бесплатно', $response);
    }

    public function testLegacyPaymentWebhookDoesNotProcessPayload(): void
    {
        $response = (new RetiredPaymentController())->yoomoneyWebhook();

        self::assertSame(410, http_response_code());
        self::assertSame([
            'status' => 'retired',
            'message' => 'Legacy payment endpoint is unavailable.',
        ], json_decode($response, true, flags: JSON_THROW_ON_ERROR));
    }
}
