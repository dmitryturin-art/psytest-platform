<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Modules\BeckAnxiety\BeckAnxietyModule;
use PsyTest\Modules\BeckDepression\BeckDepressionModule;
use PsyTest\Modules\Hads\HadsModule;
use PsyTest\Modules\Lazarus\LazarusModule;
use PsyTest\Modules\ResultSection;
use PsyTest\Modules\Smil\SmilModule;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderer contract (stage 03, WP 03.2).
 *
 * Pins three properties of the shared rendering layer:
 *  1. Every section produced by every module carries enough data to be
 *     rendered by result-layout.twig (block exists, renders standalone).
 *  2. The PDF branch renders through one shared dispatcher that knows
 *     nothing about concrete modules.
 *  3. Controllers never reference concrete module classes — modules are
 *     reached only through TestModuleInterface (+ ResultSection DTO),
 *     and the pair chart is a declarative interface method, not an
 *     instanceof branch.
 *
 * The canonical SMIL chart component (blocks/profile-chart.twig +
 * smil-profile-classic.js) is guarded against replacement.
 */
final class RendererContractTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/fixtures/golden';

    public static function moduleProvider(): array
    {
        return [
            'beck-anxiety' => [BeckAnxietyModule::class],
            'beck-depression' => [BeckDepressionModule::class],
            'hads' => [HadsModule::class],
            'lazarus' => [LazarusModule::class],
            'smil' => [SmilModule::class],
        ];
    }

    #[DataProvider('moduleProvider')]
    public function testEveryWebSectionBlockExistsAndRendersStandalone(string $moduleClass): void
    {
        $module = new $moduleClass();
        $sections = $module->buildSections($this->webResults($module));

        self::assertNotEmpty($sections, 'A module must produce at least one result section.');

        $twig = $this->strictTwig();
        foreach ($sections as $section) {
            if ($section->block === null) {
                continue;
            }
            $file = dirname(__DIR__) . '/templates/' . $section->block;
            self::assertFileExists($file, "Block template for section type {$section->type} is missing.");

            $html = $twig->render(
                $section->block,
                ['basePath' => '', 'appName' => 'PsyTest']
                + $section->data
                + ['_section_type' => $section->type]
            );
            self::assertNotSame('', trim((string) $html), "Block for {$section->type} rendered empty.");
        }
    }

    public function testLazarusPairSectionsCarryChartBeforeComparison(): void
    {
        $module = new LazarusModule();
        $results = $this->webResults($module);
        $types = array_map(static fn ($s) => $s->type, $module->buildSections($results));

        self::assertContains(ResultSection::TYPE_PAIR_CHART, $types);
        self::assertContains(ResultSection::TYPE_PAIR_COMPARISON, $types);
        self::assertLessThan(
            array_search(ResultSection::TYPE_PAIR_COMPARISON, $types, true),
            array_search(ResultSection::TYPE_PAIR_CHART, $types, true),
            'The overlay chart must precede the detailed table, never replace it.'
        );
    }

    #[DataProvider('moduleProvider')]
    public function testPdfBranchRendersAllSectionsInSharedDispatcher(string $moduleClass): void
    {
        $module = new $moduleClass();
        $results = ['is_pdf' => true] + $this->pdfResults($module);
        $renderer = new \PsyTest\Core\ResultSectionRenderer(
            fn (string $template, array $data): string => $this->strictTwig()->render($template . '.twig', $data)
        );

        $html = $renderer->renderToHtml($module->buildSections($results));

        self::assertNotSame('', trim($html), "PDF rendering produced no HTML for {$moduleClass}.");
    }

    public function testPdfProfileChartIsStaticBarChartWithoutJavaScript(): void
    {
        $module = new SmilModule();
        $results = ['is_pdf' => true] + $this->pdfResults($module);
        $chartSection = null;
        foreach ($module->buildSections($results) as $section) {
            if ($section->type === ResultSection::TYPE_PROFILE_CHART) {
                $chartSection = $section;
            }
        }
        self::assertNotNull($chartSection, 'SMIL PDF output must contain a profile chart section.');

        $renderer = new \PsyTest\Core\ResultSectionRenderer(
            fn (string $template, array $data): string => $this->strictTwig()->render($template . '.twig', $data)
        );
        $html = $renderer->renderToHtml([$chartSection]);

        self::assertStringContainsString('Профиль личности', $html);
        self::assertStringContainsString('Норма', $html);
        self::assertStringNotContainsString('<script', $html, 'PDF chart must be pure HTML/CSS.');
        self::assertStringNotContainsString('<canvas', $html, 'PDF chart must not rely on canvas.');
    }

    #[DataProvider('nonPairChartModuleProvider')]
    public function testModulesWithoutPairChartDeclareNullChartData(string $moduleClass): void
    {
        $module = new $moduleClass();

        self::assertTrue(method_exists($module, 'pairChartData'), 'pairChartData must be part of the module contract.');
        self::assertNull($module->pairChartData([]));
    }

    public static function nonPairChartModuleProvider(): array
    {
        return [
            'beck-anxiety' => [BeckAnxietyModule::class],
            'beck-depression' => [BeckDepressionModule::class],
            'hads' => [HadsModule::class],
            'smil' => [SmilModule::class],
        ];
    }

    public function testLazarusProvidesPairChartDataFromComparison(): void
    {
        $module = new LazarusModule();
        $golden = $this->loadGolden('lazarus-results');
        $comparison = $module->comparePairResults($golden['results'], $golden['results']);

        $chart = $module->pairChartData($comparison);

        self::assertIsArray($chart);
        foreach (['width', 'height', 'grid', 'points_p1', 'points_p2', 'dots'] as $key) {
            self::assertArrayHasKey($key, $chart);
        }
        self::assertCount(16, $chart['dots']);
    }

    public function testControllersNeverReferenceConcreteModules(): void
    {
        foreach (glob(dirname(__DIR__) . '/controllers/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/^use PsyTest\\\\Modules\\\\(\w+);/m', $source, $m);
            foreach ($m[1] as $imported) {
                self::assertContains(
                    $imported,
                    ['TestModuleInterface', 'ResultSection', 'ModuleCapability'],
                    basename($file) . " imports concrete module class {$imported} — controllers must stay module-agnostic."
                );
            }
        }
    }

    public function testCanonicalSmilChartComponentIsUntouched(): void
    {
        $module = new SmilModule();
        $chartBlocks = [];
        foreach ($module->buildSections($this->webResults($module)) as $section) {
            if ($section->type === ResultSection::TYPE_PROFILE_CHART) {
                $chartBlocks[] = $section->block;
            }
        }

        self::assertContains('blocks/profile-chart.twig', $chartBlocks);

        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/blocks/profile-chart.twig');
        self::assertStringContainsString('smil-profile-classic.js', $template);
        self::assertFileExists(dirname(__DIR__) . '/public/js/smil-profile-classic.js');
    }

    /**
     * Web-context results for section building (golden parity for the four
     * pinned modules, deterministic protocol for SMIL).
     *
     * @return array<string, mixed>
     */
    private function webResults(object $module): array
    {
        return $this->baseResults($module, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function pdfResults(object $module): array
    {
        return $this->baseResults($module, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseResults(object $module, bool $isPdf): array
    {
        if ($module instanceof SmilModule) {
            $answers = [];
            $values = [0, 1, 2];
            foreach ($module->getQuestions() as $i => $q) {
                $answers[$q['id']] = $values[$i % 3];
            }
            $answers['gender'] = 'male';
            $results = $module->calculateResults($answers);
        } elseif ($module instanceof LazarusModule) {
            $results = $this->loadGolden('lazarus-results')['results'];
            if (!$isPdf) {
                $results['pair_comparison'] = $module->comparePairResults($results, $results);
            }
        } else {
            $slug = match ($module::class) {
                BeckAnxietyModule::class => 'bai',
                BeckDepressionModule::class => 'bdi',
                HadsModule::class => 'hads',
            };
            $results = $this->loadGolden($slug . '-results')['results'];
        }

        return $results;
    }

    /**
     * @return array{results: array<string, mixed>, interpretation: array<string, mixed>}
     */
    private function loadGolden(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(self::FIXTURE_DIR . '/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function strictTwig(): Environment
    {
        return new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
    }
}
