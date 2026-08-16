<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class DatabaseErrorLoggingTest extends TestCase
{
    public function testDatabaseErrorsDoNotLogDriverMessagesWithBoundValues(): void
    {
        $database = (string) file_get_contents(dirname(__DIR__) . '/core/Database.php');

        self::assertStringNotContainsString('$e->getMessage()', $database);
        self::assertStringContainsString('Database query failed [', $database);
    }
}
