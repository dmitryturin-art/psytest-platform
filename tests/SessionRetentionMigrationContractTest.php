<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class SessionRetentionMigrationContractTest extends TestCase
{
    public function testBootstrapSchemaDoesNotDuplicateTheIncrementalRetentionMigration(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/20260708050511_init_schema.php');

        self::assertStringNotContainsString('`retention_class`', $schema);
        self::assertStringNotContainsString('`idx_retention_created`', $schema);
    }

    public function testIncrementalMigrationAddsTheSameRetentionContract(): void
    {
        $migration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/20260816010000_add_session_retention_class.php');

        self::assertStringContainsString('ADD COLUMN retention_class VARCHAR(32)', $migration);
        self::assertStringContainsString('ADD INDEX idx_retention_created (retention_class, created_at)', $migration);
        self::assertStringContainsString('DROP COLUMN retention_class', $migration);
    }
}
