<?php

declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\SmilProfileImageRenderer as Renderer;

/**
 * Растровый канонический профиль СМИЛ для PDF (07.WP7b).
 *
 * Проверяется геометрия по пикселям: точка лежит на линии бланка своего T,
 * между K и 1 нет линии графика, значения вне диапазона прижимаются к
 * границам, картинка детерминирована и разумна по размеру.
 */
final class SmilProfileImageRendererTest extends TestCase
{
    /** L, F, K, 1–9, 0 */
    private const SCORES = [46, 70, 55, 62, 75, 58, 66, 40, 72, 81, 64, 57, 28];

    protected function setUp(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagettftext')) {
            self::markTestSkipped('Нужен GD с FreeType: рендер профиля для PDF недоступен в этом окружении.');
        }
    }

    public function testRendersPngOfThePrintSize(): void
    {
        $png = (new Renderer())->renderPng(['scores' => self::SCORES]);

        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $size = getimagesizefromstring($png);
        self::assertIsArray($size);
        self::assertSame(Renderer::BASE_WIDTH * Renderer::SCALE, $size[0]);
        self::assertSame(Renderer::BASE_HEIGHT * Renderer::SCALE, $size[1]);
        self::assertGreaterThanOrEqual(1600, $size[0], 'Запас разрешения для печати на ширину A4.');
        self::assertLessThanOrEqual(250 * 1024, strlen($png), 'PNG не должен раздувать PDF.');
    }

    public function testSameInputGivesSameBytes(): void
    {
        $renderer = new Renderer();

        self::assertSame(
            hash('sha256', $renderer->renderPng(['scores' => self::SCORES])),
            hash('sha256', $renderer->renderPng(['scores' => self::SCORES])),
        );
    }

    public function testGridCalibrationPutsTensOnFormLines(): void
    {
        self::assertSame(Renderer::GRID_Y[70], Renderer::tScoreToY(70));
        self::assertSame(Renderer::GRID_Y[30], Renderer::tScoreToY(30));
        self::assertEqualsWithDelta(
            (Renderer::GRID_Y[60] + Renderer::GRID_Y[70]) / 2,
            Renderer::tScoreToY(65),
            0.001,
        );
        // Бланк монотонен: выше T — выше точка.
        $previous = INF;
        for ($t = Renderer::T_MIN; $t <= Renderer::T_MAX; $t++) {
            $y = Renderer::tScoreToY($t);
            self::assertLessThan($previous, $y);
            $previous = $y;
        }
    }

    public function testPointWithT70LiesOnTheFormLine70(): void
    {
        $image = $this->image(['scores' => self::SCORES]);

        // F = 70: граница нормы, точка зелёная, центр — на линии «70» бланка.
        $x = Renderer::VALIDITY_X[1] * Renderer::SCALE;
        $y = (int) round(Renderer::GRID_Y[70] * Renderer::SCALE);
        self::assertSame('normal', $this->kind($image, $x, $y));

        // Чуть выше и ниже точки, за пределами её радиуса, — уже не точка.
        $outside = (int) round((Renderer::POINT_RADIUS + 3) * Renderer::SCALE);
        self::assertNotSame('normal', $this->kind($image, $x - $outside, $y - $outside));
    }

    public function testDeviationPointsAreCrimson(): void
    {
        $image = $this->image(['scores' => self::SCORES]);

        // Шкала 7 = 81 T.
        $x = Renderer::CLINICAL_X[6] * Renderer::SCALE;
        $y = (int) round(Renderer::tScoreToY(81) * Renderer::SCALE);
        self::assertSame('deviation', $this->kind($image, $x, $y));
    }

    public function testNoCurveCrossesTheGapBetweenKAndScale1(): void
    {
        // K высоко, 1 низко: соединяющая линия пересекла бы весь разрыв.
        $scores = self::SCORES;
        $scores[2] = 95;
        $scores[3] = 25;
        $image = $this->image(['scores' => $scores]);

        $from = (int) ((Renderer::VALIDITY_X[2] + Renderer::POINT_RADIUS + 2) * Renderer::SCALE);
        $to = (int) ((Renderer::CLINICAL_X[0] - Renderer::POINT_RADIUS - 4) * Renderer::SCALE);
        $top = (int) (Renderer::tScoreToY(100) * Renderer::SCALE);
        $bottom = (int) (Renderer::tScoreToY(20) * Renderer::SCALE);
        $hits = 0;
        for ($x = $from; $x <= $to; $x++) {
            for ($y = $top; $y <= $bottom; $y += 2) {
                if ($this->kind($image, $x, $y) === 'line') {
                    $hits++;
                }
            }
        }
        self::assertSame(0, $hits, 'Ломаные достоверности и клинических шкал не соединяются через разрыв.');

        // Контроль: между F и K линия есть.
        $midX = (int) ((Renderer::VALIDITY_X[1] + Renderer::VALIDITY_X[2]) / 2 * Renderer::SCALE);
        $midY = (int) round((Renderer::tScoreToY(70) + Renderer::tScoreToY(95)) / 2 * Renderer::SCALE);
        self::assertSame('line', $this->kind($image, $midX, $midY));
    }

    public function testOutOfRangeScoresAreClampedToTheChartRange(): void
    {
        $scores = self::SCORES;
        $scores[3] = 150;  // шкала 1
        $scores[12] = 5;   // шкала 0
        $image = $this->image(['scores' => $scores]);

        self::assertSame(Renderer::tScoreToY(Renderer::T_MAX), Renderer::tScoreToY(150));
        self::assertSame(Renderer::tScoreToY(Renderer::T_MIN), Renderer::tScoreToY(5));

        $x1 = Renderer::CLINICAL_X[0] * Renderer::SCALE;
        self::assertSame('deviation', $this->kind($image, $x1, (int) round(Renderer::GRID_Y[100] * Renderer::SCALE)));
        $x0 = Renderer::CLINICAL_X[9] * Renderer::SCALE;
        self::assertSame('deviation', $this->kind($image, $x0, (int) round(Renderer::GRID_Y[20] * Renderer::SCALE)));
    }

    public function testRendersWithoutFontJustWithoutValueLabels(): void
    {
        $withFont = (new Renderer())->renderPng(['scores' => self::SCORES]);
        $withoutFont = (new Renderer(null, '/nonexistent/font.ttf'))->renderPng(['scores' => self::SCORES]);

        self::assertNotFalse(getimagesizefromstring($withoutFont));
        self::assertNotSame($withFont, $withoutFont);
    }

    public function testMalformedScoresAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Renderer())->renderPng(['scores' => array_slice(self::SCORES, 0, 12)]);
    }

    public function testMissingBackgroundIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Renderer('/nonexistent/background.png'))->renderPng(['scores' => self::SCORES]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function image(array $data): \GdImage
    {
        $image = imagecreatefromstring((new Renderer())->renderPng($data));
        self::assertInstanceOf(\GdImage::class, $image);

        return $image;
    }

    /**
     * Грубая классификация пикселя по цветам веб-графика.
     */
    private function kind(\GdImage $image, int $x, int $y): string
    {
        $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        [$r, $g, $b] = [$rgb['red'], $rgb['green'], $rgb['blue']];

        if ($b > 100 && $r < 60 && $g < 60) {
            return 'line';
        }
        if ($g > 70 && $r < 50 && $b < 50) {
            return 'normal';
        }
        if ($r > 170 && $g < 80 && $b < 110) {
            return 'deviation';
        }

        return 'other';
    }
}
