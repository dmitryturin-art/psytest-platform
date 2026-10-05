<?php

/**
 * Канонический профиль СМИЛ растром для PDF (07.WP7b).
 *
 * Веб-график (`public/js/smil-profile-classic.js`) — это бланк Собчик
 * `public/images/smil-profile-bg.png` (560×621) и SVG-наложение поверх него:
 * две отдельные ломаные (L, F, K и 1–9, 0) тёмно-синей линией толщиной 4,
 * точки радиусом 5 с белой обводкой, зелёные в норме 30–70 T и малиновые вне
 * её. Здесь то же самое рисуется средствами GD на том же бланке, в три раза
 * крупнее (1680×1863) — для печати на ширину страницы.
 *
 * Высота точки считается по линиям самого бланка (T = 70 лежит на линии
 * «70»); таблица линий — `SmilProfileGrid`, общая с веб-графиком (05.C1).
 * Подсказки с точными значениями на бумаге невозможны, поэтому рядом с
 * каждой точкой подписано её T — печатная замена hover-подсказки.
 */

declare(strict_types=1);

namespace PsyTest\Modules\Smil;

use PsyTest\Modules\ProfileChartImageRenderer;

final class SmilProfileImageRenderer implements ProfileChartImageRenderer
{
    /** Размер бланка в единицах веб-графика (viewBox SVG). */
    public const BASE_WIDTH = 560;
    public const BASE_HEIGHT = 621;

    /** Во сколько раз PNG крупнее веб-графика. */
    public const SCALE = 3;

    /** Диапазон T — общий с веб-графиком (`SmilProfileGrid`). */
    public const T_MIN = SmilProfileGrid::T_MIN;
    public const T_MAX = SmilProfileGrid::T_MAX;

    /** X-центры шкал из веб-графика: L, F, K, затем 1–9, 0. */
    public const VALIDITY_X = [102, 138, 168];
    public const CLINICAL_X = [208, 238, 270, 304, 338, 373, 412, 444, 478, 513];

    /** Вертикальная линия бланка между K и 1 (разрыв профиля). */
    public const DIVIDER_X = 178;

    /**
     * Y линий бланка по T (координаты бланка 560×621). Единый источник —
     * `SmilProfileGrid::LINE_Y`; веб-график получает ту же таблицу.
     */
    public const GRID_Y = SmilProfileGrid::LINE_Y;

    /** Цвета веб-графика: darkblue, darkgreen, crimson, white. */
    public const COLOR_LINE = [0, 0, 139];
    public const COLOR_NORMAL = [0, 100, 0];
    public const COLOR_DEVIATION = [220, 20, 60];

    /** Толщина линии и радиус точки — как в SVG (в единицах бланка). */
    public const LINE_WIDTH = 4.0;
    public const POINT_RADIUS = 5.0;
    public const POINT_STROKE = 1.0;

    /** Подпись T у точки: кегль в единицах бланка. */
    public const LABEL_SIZE = 9.0;

    private readonly string $backgroundPath;
    private readonly ?string $fontPath;

    public function __construct(?string $backgroundPath = null, ?string $fontPath = null)
    {
        $this->backgroundPath = $backgroundPath ?? self::defaultBackgroundPath();
        $this->fontPath = $fontPath ?? self::defaultFontPath();
    }

    /** Бланк — тот же файл, что подкладывает веб-график. */
    public static function defaultBackgroundPath(): string
    {
        return dirname(__DIR__, 2) . '/public/images/smil-profile-bg.png';
    }

    /**
     * Шрифт с кириллицей из DomPDF — тот же DejaVu, которым набран PDF.
     * Свой бинарный шрифт в репозиторий не добавляется.
     */
    public static function defaultFontPath(): string
    {
        return dirname(__DIR__, 2) . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagecreatefrompng')
            && function_exists('imagepng')
            && is_file(self::defaultBackgroundPath());
    }

    /**
     * Y точки на бланке (в единицах веб-графика) для T-балла — та же функция,
     * что у веб-графика: `SmilProfileGrid::tScoreToY()`.
     */
    public static function tScoreToY(float $tScore): float
    {
        return SmilProfileGrid::tScoreToY($tScore);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderPng(array $data): string
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('GD with PNG support is not available');
        }
        if (!is_file($this->backgroundPath)) {
            throw new \RuntimeException('SMIL profile background is missing');
        }

        $scores = $this->scores($data);

        $width = self::BASE_WIDTH * self::SCALE;
        $height = self::BASE_HEIGHT * self::SCALE;

        $background = @imagecreatefrompng($this->backgroundPath);
        if ($background === false) {
            throw new \RuntimeException('SMIL profile background is unreadable');
        }
        // Бумага скана — 248–255, а не чистый белый: на странице PDF бланк
        // выглядел серой плашкой. Подъём яркости делает фон белым, линии
        // бланка темнее 10 остаются практически чёрными.
        // Бланк — палитровый PNG; фильтр по палитре в GD в десятки раз
        // медленнее, чем по truecolor-копии.
        imagepalettetotruecolor($background);
        imagefilter($background, IMG_FILTER_BRIGHTNESS, 8);

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('Cannot allocate profile image');
        }
        imagealphablending($image, true);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $this->color($image, [255, 255, 255]));
        imagecopyresampled(
            $image,
            $background,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($background),
            imagesy($background),
        );

        $validity = array_slice($scores, 0, 3);
        $clinical = array_slice($scores, 3);

        // Как в SVG: сначала обе ломаные, затем точки поверх них.
        // Ломаные не соединяются через разрыв между K и 1.
        $this->drawPolyline($image, $validity, self::VALIDITY_X);
        $this->drawPolyline($image, $clinical, self::CLINICAL_X);
        $this->drawPoints($image, $validity, self::VALIDITY_X);
        $this->drawPoints($image, $clinical, self::CLINICAL_X);
        $this->drawLabels($image, $validity, self::VALIDITY_X);
        $this->drawLabels($image, $clinical, self::CLINICAL_X);

        // Truecolor без альфа-канала, а не палитра: палитровый PNG вдвое
        // меньше, но DomPDF перекодирует его через GD и тратит на страницу
        // в пять раз больше времени (замер 07.WP7b: 536 мс против 106 мс),
        // а итоговый PDF выходит даже крупнее.
        imagesavealpha($image, false);

        ob_start();
        $ok = imagepng($image, null, 6);
        $png = (string) ob_get_clean();
        if (!$ok || $png === '') {
            throw new \RuntimeException('PNG encoding failed');
        }

        return $png;
    }

    /**
     * Ровно 13 значений в порядке L, F, K, 1–9, 0 — как их отдаёт модуль.
     *
     * @param array<string, mixed> $data
     * @return list<float>
     */
    private function scores(array $data): array
    {
        $raw = $data['scores'] ?? null;
        if (!is_array($raw) || count($raw) !== 13) {
            throw new \RuntimeException('SMIL profile expects 13 scores');
        }
        $scores = [];
        foreach (array_values($raw) as $value) {
            if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                throw new \RuntimeException('SMIL profile score is not numeric');
            }
            $scores[] = (float) $value;
        }

        return $scores;
    }

    /**
     * @param list<float> $scores
     * @param list<int> $xs
     */
    private function drawPolyline(\GdImage $image, array $scores, array $xs): void
    {
        $color = $this->color($image, self::COLOR_LINE);
        $half = self::LINE_WIDTH * self::SCALE / 2;
        for ($i = 0; $i < count($scores) - 1; $i++) {
            [$x1, $y1] = $this->point($xs[$i], $scores[$i]);
            [$x2, $y2] = $this->point($xs[$i + 1], $scores[$i + 1]);
            $this->thickSegment($image, $x1, $y1, $x2, $y2, $half, $color);
            // Круглые стыки, как у сплошной SVG-линии под точками.
            $this->disc($image, $x1, $y1, $half, $color);
            $this->disc($image, $x2, $y2, $half, $color);
        }
    }

    /**
     * @param list<float> $scores
     * @param list<int> $xs
     */
    private function drawPoints(\GdImage $image, array $scores, array $xs): void
    {
        $white = $this->color($image, [255, 255, 255]);
        foreach ($scores as $i => $score) {
            [$x, $y] = $this->point($xs[$i], $score);
            $fill = $this->color($image, self::isNormal($score) ? self::COLOR_NORMAL : self::COLOR_DEVIATION);
            $this->disc($image, $x, $y, (self::POINT_RADIUS + self::POINT_STROKE / 2) * self::SCALE, $white);
            $this->disc($image, $x, $y, (self::POINT_RADIUS - self::POINT_STROKE / 2) * self::SCALE, $fill);
        }
    }

    /**
     * Значение T над точкой на белой плашке — замена hover-подсказки.
     *
     * @param list<float> $scores
     * @param list<int> $xs
     */
    private function drawLabels(\GdImage $image, array $scores, array $xs): void
    {
        if ($this->fontPath === null || !is_file($this->fontPath) || !function_exists('imagettftext')) {
            return;
        }
        $size = self::LABEL_SIZE * self::SCALE * 0.75; // px → pt для FreeType (96 dpi)
        $white = $this->color($image, [255, 255, 255]);
        foreach ($scores as $i => $score) {
            [$x, $y] = $this->point($xs[$i], $score);
            $text = (string) (int) round($score);
            $box = imagettfbbox($size, 0, $this->fontPath, $text);
            if ($box === false) {
                return;
            }
            $textWidth = $box[2] - $box[0];
            $textHeight = $box[1] - $box[7];
            $pad = (int) round(1.2 * self::SCALE);
            $baseline = (int) round($y - (self::POINT_RADIUS + 4) * self::SCALE);
            $left = (int) round($x - $textWidth / 2);
            imagefilledrectangle(
                $image,
                $left - $pad,
                $baseline - $textHeight - $pad,
                $left + $textWidth + $pad,
                $baseline + $pad,
                $white,
            );
            $fill = $this->color($image, self::isNormal($score) ? self::COLOR_NORMAL : self::COLOR_DEVIATION);
            imagettftext($image, $size, 0, $left - $box[0], $baseline, $fill, $this->fontPath, $text);
        }
    }

    /** Норма веб-графика: 30–70 T включительно. */
    public static function isNormal(float $tScore): bool
    {
        return $tScore >= 30 && $tScore <= 70;
    }

    /**
     * @return array{0: float, 1: float} Пиксельные координаты в PNG.
     */
    private function point(int $baseX, float $score): array
    {
        return [$baseX * self::SCALE, self::tScoreToY($score) * self::SCALE];
    }

    /**
     * Толстый отрезок как залитый четырёхугольник со сглаженным контуром:
     * `imageantialias` в GD не работает при толщине больше 1, поэтому
     * сглаживается тонкий контур вокруг заливки.
     */
    private function thickSegment(
        \GdImage $image,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $half,
        int $color,
    ): void {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $length = sqrt($dx * $dx + $dy * $dy);
        if ($length < 0.001) {
            return;
        }
        $nx = -$dy / $length * $half;
        $ny = $dx / $length * $half;
        $points = [
            (int) round($x1 + $nx), (int) round($y1 + $ny),
            (int) round($x2 + $nx), (int) round($y2 + $ny),
            (int) round($x2 - $nx), (int) round($y2 - $ny),
            (int) round($x1 - $nx), (int) round($y1 - $ny),
        ];
        imagefilledpolygon($image, $points, $color);
        imageantialias($image, true);
        imagepolygon($image, $points, $color);
        imageantialias($image, false);
    }

    /** Круг со сглаженным краем. */
    private function disc(\GdImage $image, float $x, float $y, float $radius, int $color): void
    {
        $cx = (int) round($x);
        $cy = (int) round($y);
        $diameter = (int) round($radius * 2);
        imagefilledellipse($image, $cx, $cy, $diameter, $diameter, $color);
        imageantialias($image, true);
        imageellipse($image, $cx, $cy, $diameter, $diameter, $color);
        imageantialias($image, false);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private function color(\GdImage $image, array $rgb): int
    {
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        if ($color === false) {
            throw new \RuntimeException('Cannot allocate color');
        }

        return $color;
    }
}
