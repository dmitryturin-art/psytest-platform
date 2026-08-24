<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CiScopeClassifierTest extends TestCase
{
    /**
     * @param list<string> $paths
     */
    #[DataProvider('changedPathProvider')]
    public function testClassifiesDatabaseMatrixScope(array $paths, bool $expected): void
    {
        $projectRoot = dirname(__DIR__);
        $process = proc_open(
            [PHP_BINARY, $projectRoot . '/bin/classify-ci-scope.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $projectRoot,
        );

        self::assertIsResource($process);

        fwrite($pipes[0], implode("\n", $paths) . "\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertSame('database=' . ($expected ? 'true' : 'false') . "\n", $stdout);
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function changedPathProvider(): iterable
    {
        yield 'PDF and template changes use the fast gate' => [[
            'controllers/ResultController.php',
            'core/PDFGenerator.php',
            'modules/lazarus/LazarusModule.php',
            'templates/blocks/pair-comparison.twig',
            'public/css/main.css',
            'tests/PDFGeneratorSmokeTest.php',
        ], false];

        yield 'documentation uses the fast gate' => [[
            'docs/roadmap/STATUS.md',
            'CHANGELOG.md',
        ], false];

        yield 'migration requires both databases' => [[
            'database/migrations/20260825000000_example.php',
        ], true];

        yield 'persistence code requires both databases' => [[
            'core/SessionManager.php',
        ], true];

        yield 'integration service changes require both databases' => [[
            'services/PaymentService.php',
        ], true];

        yield 'dependency changes require both databases' => [[
            'composer.lock',
        ], true];
    }
}
