<?php

/**
 * Демонстрационный модуль-образец (Module API v2).
 *
 * Показывает минимальный набор, необходимый для добавления нового типа
 * теста: metadata.json + questions.json + класс, наследующий BaseTestModule.
 * Используется документацией docs/creating-new-test.md и контрактом
 * DemoModuleContractTest. В каталог не регистрируется (нет записи в БД).
 */

declare(strict_types=1);

namespace PsyTest\Tests\Fixtures\Demo;

use PsyTest\Modules\BaseTestModule;
use PsyTest\Modules\ResultSection;

final class DemoWellbeingModule extends BaseTestModule
{
    private const MAX_SCORE = 12;

    private const THRESHOLDS = [
        'low' => ['min' => 0, 'max' => 4],
        'moderate' => ['min' => 5, 'max' => 8],
        'high' => ['min' => 9, 'max' => 12],
    ];

    private const LEVEL_NAMES = [
        'low' => 'Сниженное самочувствие',
        'moderate' => 'Умеренное самочувствие',
        'high' => 'Хорошее самочувствие',
    ];

    public function calculateResults(array $answers): array
    {
        $total = 0;
        foreach ($this->getQuestions() as $question) {
            $total += (int) ($answers[$question['id']] ?? 0);
        }
        $level = $this->level($total);

        return [
            'total' => $total,
            'max_score' => self::MAX_SCORE,
            'level' => $level,
            'level_name' => self::LEVEL_NAMES[$level],
        ];
    }

    /**
     * @param array<string, mixed> $scores
     *
     * @return array{summary: string, recommendations: list<string>}
     */
    public function generateInterpretation(array $scores): array
    {
        $total = (int) ($scores['total'] ?? 0);
        $level = $this->level($total);

        return [
            'summary' => self::LEVEL_NAMES[$level] . ': ' . $total . ' из ' . self::MAX_SCORE,
            'recommendations' => $level === 'low'
                ? ['Обратите внимание на режим отдыха и сна']
                : ['Значимых замечаний нет'],
        ];
    }

    /**
     * @param array<string, mixed> $results
     *
     * @return list<ResultSection>
     */
    public function buildSections(array $results): array
    {
        $total = (int) ($results['total'] ?? 0);
        $level = $this->level($total);
        $interpretation = $this->generateInterpretation($results);

        return [
            new ResultSection(
                type: ResultSection::TYPE_SCORE_BADGE,
                title: 'Самочувствие',
                data: [
                    'score' => $total,
                    'max' => self::MAX_SCORE,
                    'level' => $level,
                    'level_label' => self::LEVEL_NAMES[$level],
                    'description' => '',
                    'thresholds' => self::THRESHOLDS,
                ],
                block: 'blocks/score-badge.twig',
                order: 10,
            ),
            new ResultSection(
                type: ResultSection::TYPE_INTERPRETATION,
                title: 'Интерпретация',
                data: [
                    'scales' => [
                        [
                            'code' => 'W',
                            'name' => 'Самочувствие',
                            'score' => $total,
                            'max' => self::MAX_SCORE,
                            'level' => $level,
                            'level_name' => self::LEVEL_NAMES[$level],
                            'detail' => $interpretation['summary'],
                        ],
                    ],
                ],
                block: 'blocks/interpretation.twig',
                order: 20,
            ),
            new ResultSection(
                type: ResultSection::TYPE_RECOMMENDATIONS,
                title: 'Рекомендации',
                data: ['items' => $interpretation['recommendations']],
                block: 'blocks/recommendations.twig',
                order: 30,
            ),
        ];
    }

    private function level(int $total): string
    {
        foreach (self::THRESHOLDS as $name => $range) {
            if ($total >= $range['min'] && $total <= $range['max']) {
                return $name;
            }
        }

        return 'moderate';
    }
}
