<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class Mysql57SchemaCompatibilityTest extends TestCase
{
    public function testExplicitExpiryDatesDoNotUseImplicitTimestampDefaults(): void
    {
        $projectRoot = dirname(__DIR__);
        $bootstrap = (string) file_get_contents($projectRoot . '/database/migrations/20260708050511_init_schema.php');
        $snapshot = (string) file_get_contents($projectRoot . '/database/schema.sql');

        foreach ([$bootstrap, $snapshot] as $schema) {
            self::assertSame(2, substr_count($schema, '`expires_at` DATETIME NOT NULL'));
            self::assertStringNotContainsString('`expires_at` TIMESTAMP NOT NULL', $schema);
        }
    }
}
