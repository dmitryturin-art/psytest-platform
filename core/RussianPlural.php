<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Склонение существительного после числа: 1 вопрос, 2 вопроса, 5 вопросов.
 */
final class RussianPlural
{
    /**
     * @param string $one  форма для 1, 21, 101… («вопрос»)
     * @param string $few  форма для 2–4, 22–24… («вопроса»)
     * @param string $many форма для 0, 5–20, 25–30… («вопросов»)
     */
    public static function choose(int $number, string $one, string $few, string $many): string
    {
        $n = abs($number);
        $lastTwo = $n % 100;
        $last = $n % 10;

        if ($lastTwo >= 11 && $lastTwo <= 14) {
            return $many;
        }

        return match (true) {
            $last === 1 => $one,
            $last >= 2 && $last <= 4 => $few,
            default => $many,
        };
    }
}
