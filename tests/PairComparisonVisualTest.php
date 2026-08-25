<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Lazarus\LazarusModule;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Contract tests for the pair-comparison block.
 *
 * The web branch must always keep the detailed per-item table; any chart
 * visualisation is an addition on top of it, never a replacement.
 * The PDF branch is the owner-approved compact landscape layout (04.0F).
 */
final class PairComparisonVisualTest extends TestCase
{
    public function testWebBranchKeepsDetailedSixteenItemTable(): void
    {
        [$html, $comparison] = $this->renderComparison(false);
        $xpath = $this->xpath($html);

        $table = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " pair-comparison__table ")]');
        self::assertNotFalse($table);
        self::assertSame(1, $table->length, 'Web branch must keep the detailed comparison table.');

        $rows = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " pair-comparison__table ")]/tbody/tr');
        self::assertNotFalse($rows);
        self::assertSame(16, $rows->length);

        foreach (['p1_self', 'p2_self', 'difference', 'p1_accuracy', 'p2_accuracy'] as $key) {
            $sample = (string) ($comparison['items'][3][$key] ?? '');
            if ($sample !== '') {
                self::assertStringContainsString(htmlspecialchars((string) $comparison['items'][3][$key], ENT_QUOTES), $html);
            }
        }
    }

    public function testPdfBranchKeepsApprovedCompactTable(): void
    {
        [$html] = $this->renderComparison(true);

        self::assertStringContainsString('pair-comparison--pdf', $html);
        self::assertStringContainsString('pair-comparison__table', $html);
        self::assertSame(17, substr_count($html, '<tr'));
    }

    public function testNoTemplateLoadsExternalChartLibraries(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__) . '/templates')
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                $contents = (string) file_get_contents($file->getPathname());
                self::assertStringNotContainsString('cdn.jsdelivr.net', $contents, $file->getFilename());
                self::assertStringNotContainsString('chart.js@', $contents, $file->getFilename());
            }
        }
    }

    public function testPairChartSectionAddedForWebOnly(): void
    {
        $module = new LazarusModule();
        $comparison = $this->buildComparison();
        $results = [
            'total_self' => $comparison['results_1']['total_self'],
            'max_score' => 160,
            'level' => 'satisfied',
            'level_name' => 'Удовлетворены браком',
            'self_scores' => $comparison['results_1']['self_scores'],
            'partner_scores' => $comparison['results_1']['partner_scores'],
            'pair_comparison' => $comparison,
        ];

        $types = array_map(
            static fn ($section): string => $section->type,
            $module->buildSections($results)
        );
        self::assertContains('pair_chart', $types);
        self::assertGreaterThan(array_search('pair_chart', $types, true), (int) array_search('pair_comparison', $types, true));

        $pdfTypes = array_map(
            static fn ($section): string => $section->type,
            $module->buildSections(['is_pdf' => true] + $results)
        );
        self::assertNotContains('pair_chart', $pdfTypes, 'Chart must never appear in the printed PDF.');
        self::assertContains('pair_comparison', $pdfTypes);
    }

    public function testPairChartBlockRendersOverlayWithTooltipData(): void
    {
        $module = new LazarusModule();
        $comparison = $this->buildComparison();
        $chart = $module->pairChartData($comparison);

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        $html = $twig->render('blocks/pair-chart.twig', ['chart' => $chart]);

        self::assertSame(1, substr_count($html, '<svg'));
        self::assertSame(2, substr_count($html, '<polyline'));
        self::assertSame(2, substr_count($html, '<polygon'));
        self::assertSame(32, substr_count($html, 'class="pv-dot pv-dot--'), '16 items × 2 partners.');
        self::assertSame(32, substr_count($html, 'class="pv-hit"'), 'Every dot needs a 24px touch hit area.');
        self::assertSame(32, substr_count($html, 'tabindex="0"'));
        self::assertGreaterThanOrEqual(1, substr_count($html, 'class="pv-band"'));

        self::assertSame(1, preg_match('/<script type="application\/json" class="pv-tip-data">(.*?)<\/script>/s', $html, $m));
        $dots = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(16, $dots);
        foreach ($dots as $dot) {
            self::assertArrayHasKey('v1', $dot);
            self::assertArrayHasKey('v2', $dot);
            self::assertArrayHasKey('d', $dot);
            self::assertArrayHasKey('domain', $dot);
            self::assertNotSame('', $dot['text']);
        }

        self::assertStringContainsString('aria-label="Пункт 8', $html);
        self::assertStringNotContainsString('<table', $html);
        self::assertStringNotContainsString('cdn.jsdelivr', $html);
    }

    public function testChartTextContrastMeetsWcagAA(): void
    {
        $pairs = [
            ['#667085', '#ffffff'], // подписи осей и легенда графика
            ['#cfd8e0', '#2c3e50'], // текст тултипа
            ['#f5d9ae', '#2c3e50'], // расхождение ±1–2 в тултипе
            ['#f3b3ae', '#2c3e50'], // расхождение ≥3 в тултипе
        ];
        foreach ($pairs as [$fg, $bg]) {
            self::assertGreaterThanOrEqual(
                4.5,
                self::contrastRatio($fg, $bg),
                "Цвет $fg на $bg должен давать контраст не ниже 4.5:1."
            );
        }

        $css = (string) file_get_contents(dirname(__DIR__) . '/public/css/main.css');
        self::assertStringNotContainsString('#9aa5af', $css, 'Подписи осей не должны использовать низкоконтрастный цвет.');
        self::assertStringNotContainsString('pair-item__chart', $css, 'Мёртвые стили отменённой «бабочки» должны быть удалены.');
    }

    private static function contrastRatio(string $fgHex, string $bgHex): float
    {
        $channel = static function (float $v): float {
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        };
        $luminance = static function (string $hex) use ($channel): float {
            $hex = ltrim($hex, '#');

            return 0.2126 * $channel(hexdec(substr($hex, 0, 2)) / 255)
                + 0.7152 * $channel(hexdec(substr($hex, 2, 2)) / 255)
                + 0.0722 * $channel(hexdec(substr($hex, 4, 2)) / 255);
        };
        $l1 = $luminance($fgHex);
        $l2 = $luminance($bgHex);

        return round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function renderComparison(bool $isPdf): array
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);

        $comparison = $this->buildComparison();
        $html = $twig->render('blocks/pair-comparison.twig', [
            'comparison' => $comparison,
            'is_pdf' => $isPdf,
            'is_result_pdf' => $isPdf,
        ]);
        self::assertIsString($html);

        return [$html, $comparison];
    }

    private function buildComparison(): array
    {
        $module = new LazarusModule();
        $questions = $module->getQuestions();
        self::assertCount(16, $questions);

        $self1 = [];
        $self2 = [];
        $partner1 = [];
        $partner2 = [];
        foreach ($questions as $index => $question) {
            $id = (int) $question['id'];
            $a = $index % 10;
            $b = $index < 5 ? $a : ($index * 2) % 11;
            $self1[$id] = $a;
            $self2[$id] = $b;
            $guess = $b + match ($index) {
                6 => 1,
                7 => -2,
                default => 0,
            };
            $partner1[$id] = max(0, min(10, $guess));
            $partner2[$id] = $a;
        }

        $results1 = [
            'total_self' => array_sum($self1),
            'max_score' => 160,
            'level' => 'satisfied',
            'level_name' => 'Удовлетворены браком',
            'self_scores' => $self1,
            'partner_scores' => $partner1,
        ];
        $results2 = [
            'total_self' => array_sum($self2),
            'max_score' => 160,
            'level' => 'dissatisfied',
            'level_name' => 'Не удовлетворены браком',
            'self_scores' => $self2,
            'partner_scores' => $partner2,
        ];

        return $module->comparePairResults($results1, $results2);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
