<?php

/**
 * Сетка бланка Собчик для канонического профиля СМИЛ (05.C1).
 *
 * Единственный источник соответствия «T-балл → высота точки» для обоих
 * графиков на бланке `public/images/smil-profile-bg.png` (560×621):
 * веб-графика (`public/js/smil-profile-classic.js` получает таблицу в данных
 * секции профиля, ключ `grid`) и PNG для PDF (`SmilProfileImageRenderer`).
 *
 * Y — центр горизонтальной линии бланка с подписью T, в координатах бланка
 * (= viewBox SVG веб-графика), измерен по самому изображению как среднее по
 * ширине сетки. Независимая перепроверка 05.C1 (взвешенный центр тёмных
 * пикселей строки, x 50–505) расходится с таблицей не больше чем на 0,8 px.
 * Между линиями — линейная интерполяция; T вне 20…100 прижимается к границам,
 * как в расчёте T-баллов.
 */

declare(strict_types=1);

namespace PsyTest\Modules\Smil;

final class SmilProfileGrid
{
    public const T_MIN = 20;
    public const T_MAX = 100;

    /** T линии бланка → её Y в координатах бланка 560×621. */
    public const LINE_Y = [
        20 => 561.6,
        30 => 508.5,
        40 => 451.7,
        50 => 398.1,
        60 => 343.5,
        70 => 291.5,
        80 => 238.3,
        90 => 185.3,
        100 => 130.3,
        110 => 74.9,
        120 => 20.7,
    ];

    /**
     * Y точки на бланке для T-балла: интерполяция между соседними линиями.
     */
    public static function tScoreToY(float $tScore): float
    {
        $t = max((float) self::T_MIN, min((float) self::T_MAX, $tScore));
        $lower = (int) (floor($t / 10) * 10);
        $upper = min(120, $lower + 10);
        if ($upper === $lower) {
            return self::LINE_Y[$lower];
        }
        $ratio = ($t - $lower) / ($upper - $lower);

        return self::LINE_Y[$lower] + (self::LINE_Y[$upper] - self::LINE_Y[$lower]) * $ratio;
    }

    /**
     * Таблица для веб-графика: диапазон прижатия и пары [T, y] по возрастанию T.
     *
     * @return array{t_min: int, t_max: int, lines: list<array{0: int, 1: float}>}
     */
    public static function forChart(): array
    {
        $lines = [];
        foreach (self::LINE_Y as $t => $y) {
            $lines[] = [$t, $y];
        }

        return [
            't_min' => self::T_MIN,
            't_max' => self::T_MAX,
            'lines' => $lines,
        ];
    }
}
