<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\ModuleLoader;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Положение маркера на шкале результата.
 *
 * Дефект, найденный владельцем 26.08: маркер считался как score/max, то есть от
 * нуля, тогда как цветные зоны рисуются от минимума первой зоны. У Лазаруса шкала
 * начинается с 16, и баллы 71–79 («неудовлетворённость») попадали маркером
 * внутрь зелёной зоны «удовлетворённость» — визуально противоположный вывод.
 */
final class ScoreScaleMarkerTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), ['cache' => false]);
    }

    /** @param array<string, array{min: int, max: int}> $thresholds */
    private function markerPercent(int $score, int $max, array $thresholds): float
    {
        $html = $this->twig()->render('blocks/score-scale.twig', [
            'score' => $score,
            'max' => $max,
            'thresholds' => $thresholds,
            'label' => 'Тест',
        ]);

        self::assertSame(1, preg_match('/score-badge__marker" style="left: ([\d.]+)%/', $html, $m), $html);

        return (float) $m[1];
    }

    /**
     * Доля ширины полосы, которую занимают зоны до конца указанной: шаблон
     * растягивает зоны через flex-grow, поэтому границу считаем так же.
     *
     * @param array<string, array{min: int, max: int}> $thresholds
     */
    private function zoneEdgePercent(array $thresholds, string $zone): float
    {
        $total = 0;
        foreach ($thresholds as $range) {
            $total += $range['max'] - $range['min'] + 1;
        }

        $accumulated = 0;
        foreach ($thresholds as $name => $range) {
            $accumulated += $range['max'] - $range['min'] + 1;
            if ($name === $zone) {
                return $accumulated / $total * 100;
            }
        }

        self::fail("Зона «{$zone}» не найдена.");
    }

    /** @return array<string, array{min: int, max: int}> */
    private function lazarusThresholds(): array
    {
        return [
            'dissatisfied' => ['min' => 16, 'max' => 79],
            'satisfied' => ['min' => 80, 'max' => 160],
        ];
    }

    public function testScaleStartIsTheBottomOfTheFirstZoneNotZero(): void
    {
        $thresholds = $this->lazarusThresholds();

        self::assertSame(0.0, $this->markerPercent(16, 160, $thresholds), 'Минимальный балл — левый край полосы.');
        self::assertSame(100.0, $this->markerPercent(160, 160, $thresholds), 'Максимальный балл — правый край.');
    }

    public function testDissatisfiedScoreNeverLandsInTheSatisfiedZone(): void
    {
        $thresholds = $this->lazarusThresholds();
        $boundary = $this->zoneEdgePercent($thresholds, 'dissatisfied');

        // Именно здесь ломалось: 71–79 рисовались правее границы, в зелёной зоне.
        foreach (range(16, 79) as $score) {
            self::assertLessThanOrEqual(
                $boundary,
                $this->markerPercent($score, 160, $thresholds),
                "Балл {$score} — неудовлетворённость, маркер обязан быть в красной зоне.",
            );
        }
    }

    public function testSatisfiedScoreLandsInTheSatisfiedZone(): void
    {
        $thresholds = $this->lazarusThresholds();
        $boundary = $this->zoneEdgePercent($thresholds, 'dissatisfied');

        foreach ([80, 100, 129, 145, 160] as $score) {
            self::assertGreaterThanOrEqual(
                $boundary,
                $this->markerPercent($score, 160, $thresholds),
                "Балл {$score} — удовлетворённость, маркер обязан быть в зелёной зоне.",
            );
        }
    }

    public function testDistanceBetweenScoresFollowsTheAxisSpan(): void
    {
        // Наблюдение владельца: 129 и 145 стояли ближе друг к другу, чем должны.
        $thresholds = $this->lazarusThresholds();

        $distance = $this->markerPercent(145, 160, $thresholds) - $this->markerPercent(129, 160, $thresholds);

        self::assertEqualsWithDelta((145 - 129) / (160 - 16) * 100, $distance, 0.15);
    }

    public function testZeroBasedScalesKeepTheirCurrentPositions(): void
    {
        // У остальных методик шкала начинается с нуля, и там прежняя формула
        // совпадала с верной — этот тест ловит нечаянное смещение.
        $thresholds = ['minimal' => ['min' => 0, 'max' => 7], 'mild' => ['min' => 8, 'max' => 15], 'severe' => ['min' => 16, 'max' => 63]];

        self::assertSame(0.0, $this->markerPercent(0, 63, $thresholds));
        self::assertSame(50.8, $this->markerPercent(32, 63, $thresholds), '32 из 63 — это 50.8 % шкалы.');
        self::assertSame(100.0, $this->markerPercent(63, 63, $thresholds));
    }

    public function testEveryCurrentModuleKeepsItsMarkerInsideItsOwnZone(): void
    {
        $loader = (new ModuleLoader(null, null))->discover();
        $checked = 0;

        foreach (array_keys($loader->getAllModules()) as $slug) {
            $reflection = new \ReflectionClass($loader->getModule($slug));
            if (!$reflection->hasConstant('THRESHOLDS')) {
                continue;
            }

            /** @var array<string, array{min: int, max: int}> $thresholds */
            $thresholds = $reflection->getConstant('THRESHOLDS');
            $last = end($thresholds);
            $lowerEdge = 0.0;

            foreach ($thresholds as $name => $range) {
                $upperEdge = $this->zoneEdgePercent($thresholds, (string) $name);

                foreach ([$range['min'], $range['max']] as $score) {
                    $marker = $this->markerPercent($score, $last['max'], $thresholds);

                    self::assertGreaterThanOrEqual(
                        $lowerEdge - 0.6,
                        $marker,
                        "{$slug}: балл {$score} левее своей зоны «{$name}».",
                    );
                    self::assertLessThanOrEqual(
                        $upperEdge + 0.6,
                        $marker,
                        "{$slug}: балл {$score} правее своей зоны «{$name}».",
                    );
                    $checked++;
                }

                $lowerEdge = $upperEdge;
            }
        }

        self::assertGreaterThan(0, $checked, 'Предусловие: хотя бы одна методика имеет пороги.');
    }
}
