<?php

declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\View;
use PsyTest\Modules\ResultSection;
use PsyTest\Modules\Smil\SmilModule;
use PsyTest\Modules\Smil\SmilProfileGrid as Grid;
use PsyTest\Modules\Smil\SmilProfileImageRenderer;

/**
 * Сетка бланка Собчик — единый источник высоты точек канонического профиля
 * для веб-графика и PNG для PDF (05.C1).
 *
 * Прежде веб-график считал y собственной линейной шкалой (T 20→580,
 * T 100→40), не совпадавшей с линиями бланка: F = 87 стояла у «100».
 * Здесь проверяется: таблица совпадает с линиями самого изображения,
 * значения на линиях и интерполяция, сервер отдаёт таблицу веб-графику,
 * JS-функция даёт те же y, что PHP, и прежние контракты графика целы.
 */
final class SmilProfileGridTest extends TestCase
{
    private const JS_PATH = '/public/js/smil-profile-classic.js';
    private const BLANK_PATH = '/public/images/smil-profile-bg.png';

    /** T-баллы эталонной сессии (`smil-reference-answers-valid.json`): L F K 1–9 0. */
    private const REFERENCE_SCORES = [49, 87, 63, 65, 58, 71, 73, 55, 89, 94, 88, 57, 58];

    public function testTableMatchesTheLinesOfTheBlankImage(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatefrompng')) {
            self::markTestSkipped('Нужен GD с PNG для замера линий бланка.');
        }
        $image = imagecreatefrompng(dirname(__DIR__, 2) . self::BLANK_PATH);
        self::assertNotFalse($image);
        self::assertSame(SmilProfileImageRenderer::BASE_WIDTH, imagesx($image));
        self::assertSame(SmilProfileImageRenderer::BASE_HEIGHT, imagesy($image));

        foreach (Grid::LINE_Y as $t => $expected) {
            // Взвешенный по темноте центр строки сетки (x 50–505) в окне ±4 px.
            $sum = 0.0;
            $weight = 0.0;
            for ($y = (int) floor($expected) - 4; $y <= (int) ceil($expected) + 4; $y++) {
                $rowDark = 0;
                $rowWeight = 0.0;
                for ($x = 50; $x < 505; $x++) {
                    $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    $gray = ($rgb['red'] + $rgb['green'] + $rgb['blue']) / 3;
                    if ($gray < 128) {
                        $rowDark++;
                    }
                    $rowWeight += 255 - $gray;
                }
                // Учитываются только строки самой линии (тёмная почти на всю ширину).
                if ($rowDark > 200) {
                    $sum += $rowWeight * $y;
                    $weight += $rowWeight;
                }
            }
            self::assertGreaterThan(0.0, $weight, "Линия T = {$t} не найдена у y = {$expected}");
            self::assertEqualsWithDelta($expected, $sum / $weight, 1.0, "Линия T = {$t}");
        }
    }

    public function testTensLieOnTheirLinesAndValuesBetweenAreInterpolated(): void
    {
        foreach ([30, 50, 70, 100] as $t) {
            self::assertSame(Grid::LINE_Y[$t], Grid::tScoreToY($t), "T = {$t}");
        }
        self::assertEqualsWithDelta((Grid::LINE_Y[60] + Grid::LINE_Y[70]) / 2, Grid::tScoreToY(65), 0.001);
        self::assertEqualsWithDelta(
            Grid::LINE_Y[80] + (Grid::LINE_Y[90] - Grid::LINE_Y[80]) * 0.7,
            Grid::tScoreToY(87),
            0.001,
        );
        self::assertSame(Grid::tScoreToY(Grid::T_MAX), Grid::tScoreToY(130));
        self::assertSame(Grid::tScoreToY(Grid::T_MIN), Grid::tScoreToY(0));
    }

    public function testPdfRendererUsesTheSameGrid(): void
    {
        self::assertSame(Grid::LINE_Y, SmilProfileImageRenderer::GRID_Y);
        self::assertSame(Grid::T_MIN, SmilProfileImageRenderer::T_MIN);
        self::assertSame(Grid::T_MAX, SmilProfileImageRenderer::T_MAX);
        for ($t = 15; $t <= 105; $t++) {
            self::assertSame(Grid::tScoreToY($t), SmilProfileImageRenderer::tScoreToY($t));
        }
    }

    public function testWebChartReceivesTheGridFromTheProfileSection(): void
    {
        $data = $this->profileSectionData();

        self::assertSame(Grid::forChart(), $data['grid']);
        self::assertSame(Grid::T_MIN, $data['grid']['t_min']);
        self::assertSame(Grid::T_MAX, $data['grid']['t_max']);
        self::assertCount(count(Grid::LINE_Y), $data['grid']['lines']);
        foreach ($data['grid']['lines'] as [$t, $y]) {
            self::assertSame(Grid::LINE_Y[$t], $y);
        }

        $html = View::getInstance()->render('blocks/profile-chart', $data);
        self::assertMatchesRegularExpression('/data-grid="([^"]+)"/', $html);
        preg_match('/data-grid="([^"]+)"/', $html, $m);
        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals(Grid::forChart(), $decoded);
    }

    public function testJavascriptHasNoOwnTScaleAndReadsTheServerGrid(): void
    {
        $js = $this->javascript();

        self::assertStringContainsString("getAttribute('data-grid')", $js);
        self::assertMatchesRegularExpression('/function tScoreToY\(tScore, grid\)/', $js);
        $body = $this->tScoreToYSource($js);
        // Прежняя собственная шкала (580/40) и любые зашитые y линий запрещены.
        self::assertDoesNotMatchRegularExpression('/\b(580|40|561|130)\b/', $body);
        foreach (Grid::LINE_Y as $y) {
            self::assertStringNotContainsString((string) $y, $js, 'Таблица сетки должна приходить с сервера.');
        }
    }

    public function testJavascriptTScoreToYMatchesPhp(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('Node.js недоступен: исполнение JS-функции не проверяется.');
        }

        $tScores = [];
        for ($t = 10.0; $t <= 110.0; $t += 0.5) {
            $tScores[] = $t;
        }
        $tScores = array_merge($tScores, self::REFERENCE_SCORES);

        $script = $this->tScoreToYSource($this->javascript())
            . "\nconst grid = " . json_encode(Grid::forChart(), JSON_THROW_ON_ERROR) . ';'
            . "\nconst ts = " . json_encode($tScores, JSON_THROW_ON_ERROR) . ';'
            . "\nprocess.stdout.write(JSON.stringify(ts.map(t => Number(tScoreToY(t, grid)))));";
        $file = tempnam(sys_get_temp_dir(), 'smil-grid-') . '.js';
        file_put_contents($file, $script);
        try {
            $output = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file));
        } finally {
            @unlink($file);
        }

        $jsY = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($jsY);
        self::assertCount(count($tScores), $jsY);
        foreach ($tScores as $i => $t) {
            // JS округляет до 0,1 для атрибута SVG.
            self::assertEqualsWithDelta(Grid::tScoreToY($t), $jsY[$i], 0.051, "T = {$t}");
        }
        foreach ([30, 50, 70, 100] as $t) {
            $i = array_search((float) $t, $tScores, true);
            self::assertIsInt($i);
            self::assertEqualsWithDelta(Grid::LINE_Y[$t], $jsY[$i], 1.0, "T = {$t} на линии бланка");
        }
    }

    public function testCanonicalChartContractsAreKept(): void
    {
        $js = $this->javascript();

        // Порядок и X-позиции: L F K отдельно, разрыв, затем 1–9, 0.
        self::assertStringContainsString('scores.slice(0, 3);   // L, F, K', $js);
        self::assertStringContainsString('scores.slice(3);      // 1-9, 0', $js);
        self::assertStringContainsString('const validityPositions = [102, 138, 168];', $js);
        self::assertStringContainsString(
            'const clinicalPositions = [208, 238, 270, 304, 338, 373, 412, 444, 478, 513];',
            $js,
        );
        // Две отдельные ломаные: разрыв между K и 1 не соединяется.
        self::assertSame(2, substr_count($js, '${renderCurve('));
        self::assertStringContainsString('viewBox="0 0 ${viewBox.width} ${viewBox.height}"', $js);
        self::assertStringContainsString('/images/smil-profile-bg.png', $js);
        // Цвета, пороги и подсказки.
        self::assertStringContainsString('if (tScore >= 30 && tScore <= 70)', $js);
        self::assertStringContainsString("return 'darkgreen';", $js);
        self::assertStringContainsString("return 'crimson';", $js);
        self::assertStringContainsString('stroke="darkblue" stroke-width="4"', $js);
        self::assertStringContainsString('r="5" stroke="white" stroke-width="1"', $js);
        self::assertStringContainsString('showTooltip(event', $js);
        // Никаких замен профиля.
        self::assertDoesNotMatchRegularExpression('/radar|polarArea|doughnut|<rect|new Chart\(/i', $js);

        $data = $this->profileSectionData();
        self::assertSame(['L', 'F', 'K', '1 Hs', '2 D', '3 Hy', '4 Pd', '5 Mf', '6 Pa', '7 Pt', '8 Sc', '9 Ma', '0 Si'], $data['labels']);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileSectionData(): array
    {
        $module = new SmilModule();
        $answers = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/smil-reference-answers-valid.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($answers);
        $results = $module->calculateResults($answers);
        foreach ($module->buildSections($results) as $section) {
            if ($section->type === ResultSection::TYPE_PROFILE_CHART) {
                self::assertSame('blocks/profile-chart.twig', $section->block);
                self::assertSame(self::REFERENCE_SCORES, array_map('intval', $section->data['scores']));

                return $section->data;
            }
        }
        self::fail('Секция профиля не найдена');
    }

    private function javascript(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . self::JS_PATH);
    }

    private function tScoreToYSource(string $js): string
    {
        $start = strpos($js, 'function tScoreToY(');
        self::assertNotFalse($start);
        $end = strpos($js, "\n    }\n", $start);
        self::assertNotFalse($end);

        return substr($js, $start, $end - $start + 6);
    }
}
