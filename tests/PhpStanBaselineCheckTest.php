<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class PhpStanBaselineCheckTest extends TestCase
{
    public function testBaselineCheckerPassesForTheCommittedBaseline(): void
    {
        $projectRoot = dirname(__DIR__);
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($projectRoot . '/bin/check-phpstan-baseline.php');

        exec($command . ' 2>&1', $output, $exitCode);

        $report = implode("\n", $output);

        self::assertSame(0, $exitCode, $report);
        self::assertStringContainsString('149 entries (cap 149)', $report);
    }
}
