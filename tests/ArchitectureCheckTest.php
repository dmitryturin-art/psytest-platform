<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureCheckTest extends TestCase
{
    public function testCheckerRunsFromProjectRoot(): void
    {
        $projectRoot = dirname(__DIR__);
        $process = proc_open(
            [PHP_BINARY, $projectRoot . '/bin/check-architecture.php'],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            sys_get_temp_dir(),
        );

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stdout . "\n" . $stderr);
        self::assertSame('', $stderr);
        self::assertStringContainsString('Все файлы на месте', $stdout);
        self::assertStringContainsString('Синтаксических ошибок нет', $stdout);
        self::assertStringContainsString('config.php загружается', $stdout);
        self::assertStringNotContainsString('/bin/config.php', $stdout);
    }
}
