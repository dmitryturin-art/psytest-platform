<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class CrisisResourceRegistryMigrationContractTest extends TestCase
{
    public function testCrisisResourceRegistryIsAddedOnlyByAnIncrementalMigration(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/20260708050511_init_schema.php');
        $migration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/20260816020000_add_crisis_resource_registry.php');
        $snapshot = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');

        self::assertStringNotContainsString('`crisis_resources`', $bootstrap);
        self::assertSame(1, substr_count($migration, 'CREATE TABLE `crisis_resources`'));
        self::assertStringContainsString('CREATE TABLE `crisis_resources`', $migration);
        self::assertStringContainsString('`country_code` CHAR(2) DEFAULT NULL', $migration);
        self::assertStringContainsString('`language_code` VARCHAR(35) NOT NULL', $migration);
        self::assertStringContainsString('`resource_type` VARCHAR(64) NOT NULL', $migration);
        self::assertStringContainsString('`display_name` VARCHAR(255) NOT NULL', $migration);
        self::assertStringContainsString('`contact_or_url` VARCHAR(2048) NOT NULL', $migration);
        self::assertStringContainsString('`official_source_url` VARCHAR(2048) NOT NULL', $migration);
        self::assertStringContainsString('`verified_at` DATE NOT NULL', $migration);
        self::assertStringContainsString('`verified_by` VARCHAR(255) NOT NULL', $migration);
        self::assertStringContainsString('`active` TINYINT(1) NOT NULL DEFAULT 0', $migration);
        self::assertStringContainsString('INDEX `idx_country_language_active` (`country_code`, `language_code`, `active`)', $migration);
        self::assertStringContainsString('INDEX `idx_active_verified` (`active`, `verified_at`)', $migration);
        self::assertStringContainsString('DROP TABLE `crisis_resources`', $migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `crisis_resources`', $snapshot);
    }
}
