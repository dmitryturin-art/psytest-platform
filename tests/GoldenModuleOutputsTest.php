<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Modules\BeckAnxiety\BeckAnxietyModule;
use PsyTest\Modules\BeckDepression\BeckDepressionModule;
use PsyTest\Modules\Hads\HadsModule;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\TestModuleInterface;

/**
 * Golden characterization tests for module score outputs.
 *
 * The fixtures in tests/fixtures/golden pin the exact output of
 * calculateResults() and generateInterpretation() as of 25.08.2026,
 * before the Module API v2 refactor (stage 03). Any change to scoring
 * or interpretation text for these deterministic answer sets must fail
 * here and require an explicit fixture update with a source.
 *
 * SMIL is covered separately by tests/Smil/* golden fixtures.
 */
final class GoldenModuleOutputsTest extends TestCase
{
    public static function moduleProvider(): array
    {
        return [
            'beck-anxiety' => [BeckAnxietyModule::class, 'bai'],
            'beck-depression' => [BeckDepressionModule::class, 'bdi'],
            'hads' => [HadsModule::class, 'hads'],
            'lazarus' => [LazarusModule::class, 'lazarus'],
        ];
    }

    #[DataProvider('moduleProvider')]
    public function testCalculateResultsMatchesGolden(string $moduleClass, string $slug): void
    {
        $module = $this->createModule($moduleClass);
        $answers = $this->loadJson("{$slug}-answers.json");
        $golden = $this->loadJson("{$slug}-results.json");

        $results = $module->calculateResults($answers);

        self::assertSame(
            $golden['results'],
            $results,
            "calculateResults golden parity broken for {$slug} — scoring changed without a fixture update."
        );
    }

    #[DataProvider('moduleProvider')]
    public function testInterpretationMatchesGolden(string $moduleClass, string $slug): void
    {
        $module = $this->createModule($moduleClass);
        $answers = $this->loadJson("{$slug}-answers.json");
        $golden = $this->loadJson("{$slug}-results.json");

        $results = $module->calculateResults($answers);
        $interpretation = $module->generateInterpretation($results);

        self::assertSame(
            $golden['interpretation'],
            $interpretation,
            "generateInterpretation golden parity broken for {$slug} — interpretation changed without a fixture update."
        );
    }

    private function createModule(string $moduleClass): TestModuleInterface
    {
        return new $moduleClass();
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $name): array
    {
        $path = dirname(__DIR__) . '/tests/fixtures/golden/' . $name;
        self::assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
