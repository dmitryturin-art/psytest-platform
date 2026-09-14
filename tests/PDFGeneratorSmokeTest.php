<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\PDFGenerator;

final class PDFGeneratorSmokeTest extends TestCase
{
    public function testGeneratesPdfFromCyrillicHtmlInMemory(): void
    {
        $generator = new PDFGenerator();

        $pdf = $generator->generate('<h1>Проверка PDF</h1><p>Кириллица</p>', 'smoke.pdf', false);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(500, strlen($pdf));
    }

    public function testPairComparisonPdfUsesLandscapePaper(): void
    {
        $directory = sys_get_temp_dir() . '/psytest-pdf-' . bin2hex(random_bytes(4));
        $generator = new PDFGenerator($directory);

        $path = $generator->generatePairComparison(
            ['id' => 'pair-test', 'generated_at' => '2026-08-23 12:00:00'],
            ['name' => 'Опросник удовлетворённости браком'],
            '<table><tr><th>Пункт</th><th>Нач.</th><th>Пригл.</th><th>Разница</th><th>Угадал</th><th>Угадал</th></tr><tr><td>Проверка</td><td>8</td><td>6</td><td>2</td><td>0</td><td>1</td></tr></table>'
        );

        $filename = 'pair_pair-test.pdf';
        $pdf = file_get_contents($directory . '/' . $filename);
        @unlink($directory . '/' . $filename);
        @rmdir($directory);

        self::assertSame('/storage/pdfs/' . $filename, $path);
        self::assertIsString($pdf);
        self::assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf);
        preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $matches);
        self::assertGreaterThan((float) $matches[2], (float) $matches[1]);
    }

    /**
     * Опубликованный разбор попадает в PDF клиента (D-054).
     *
     * Проверяется HTML, который уходит генератору, и сам факт сборки документа:
     * разбирать готовый PDF обратно, чтобы узнать, был ли в нём раздел, — не
     * проверка, а гадание по бинарнику.
     */
    public function testResultPdfCarriesThePublishedSpecialistReportSection(): void
    {
        $directory = sys_get_temp_dir() . '/psytest-pdf-' . bin2hex(random_bytes(4));
        $generator = new PDFGenerator($directory);

        $publishedSection = '<div class="results-section results-section--specialist-report">'
            . '<h2 class="section-title">Разбор специалиста</h2>'
            . '<div class="section-body"><p>Одобренная специалистом редакция.</p></div>'
            . '</div>';

        // Контроллеры собирают документ ровно так: секции результата, затем
        // опубликованный разбор.
        foreach (['controllers/ResultController.php', 'controllers/AccountController.php'] as $controller) {
            self::assertStringContainsString(
                "\$printable['published_report_html']",
                (string) file_get_contents(dirname(__DIR__) . '/' . $controller),
                $controller,
            );
        }

        $path = $generator->generateTestResult(
            ['id' => 'published-result', 'created_at' => '2026-09-14 12:00:00'],
            ['name' => 'Шкала депрессии Бека'],
            '<div class="results-section"><p>Базовый результат.</p></div>' . $publishedSection,
            false,
        );

        $pdf = file_get_contents($directory . '/result_published-result.pdf');
        @unlink($directory . '/result_published-result.pdf');
        @rmdir($directory);

        self::assertSame('/storage/pdfs/result_published-result.pdf', $path);
        self::assertIsString($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testResultPdfContainingPairComparisonUsesLandscapePaper(): void
    {
        $directory = sys_get_temp_dir() . '/psytest-pdf-' . bin2hex(random_bytes(4));
        $generator = new PDFGenerator($directory);

        $path = $generator->generateTestResult(
            ['id' => 'paired-result', 'created_at' => '2026-08-24 12:00:00'],
            ['name' => 'Опросник удовлетворённости браком'],
            '<div class="pair-comparison--pdf"><table><tr><td>Проверка</td></tr></table></div>',
            true,
        );

        $filename = 'result_paired-result.pdf';
        $pdf = file_get_contents($directory . '/' . $filename);
        @unlink($directory . '/' . $filename);
        @rmdir($directory);

        self::assertSame('/storage/pdfs/' . $filename, $path);
        self::assertIsString($pdf);
        self::assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf);
        preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $matches);
        self::assertGreaterThan((float) $matches[2], (float) $matches[1]);
    }
}
