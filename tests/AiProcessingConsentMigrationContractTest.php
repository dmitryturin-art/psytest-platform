<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class AiProcessingConsentMigrationContractTest extends TestCase
{
    public function testConsentSchemaIsCheckoutBoundAndContainsNoClientMetadata(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260822010000_add_ai_processing_consents.php'
        );

        foreach (['session_id', 'checkout_reference', 'notice_version', 'provider_code', 'report_kind', 'allowed_data', 'consented_at', 'revoked_at'] as $column) {
            self::assertStringContainsString($column, $migration);
        }

        self::assertStringContainsString('UNIQUE KEY uq_ai_consent_checkout', $migration);
        self::assertStringContainsString('ON DELETE CASCADE', $migration);
        self::assertStringNotContainsString('ip_address', $migration);
        self::assertStringNotContainsString('user_agent', $migration);
    }
}
