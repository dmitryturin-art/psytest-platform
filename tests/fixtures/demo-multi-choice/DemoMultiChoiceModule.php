<?php

/**
 * Модуль-образец вопроса «несколько вариантов» (`"type": "multi"`).
 *
 * Показывает, как модуль читает такой ответ: это список значений выбранных
 * вариантов. Используется docs/creating-new-test.md и MultiChoiceQuestionTest.
 * В каталог не регистрируется (нет записи в БД).
 */

declare(strict_types=1);

namespace PsyTest\Tests\Fixtures\DemoMulti;

use PsyTest\Modules\BaseTestModule;
use PsyTest\Modules\ResultSection;

final class DemoMultiChoiceModule extends BaseTestModule
{
    /** Варианты вопроса 2, которые считаются сферой («Нигде» — не сфера). */
    private const AREAS = ['work', 'home', 'leisure'];

    public function calculateResults(array $answers): array
    {
        $chosen = is_array($answers[2] ?? null) ? $answers[2] : [];

        return [
            'total' => (int) ($answers[1] ?? 0) + (int) ($answers[3] ?? 0),
            'max_score' => 4,
            'areas' => count(array_intersect(self::AREAS, $chosen)),
        ];
    }

    /**
     * @param array<string, mixed> $scores
     *
     * @return array{summary: string, recommendations: list<string>}
     */
    public function generateInterpretation(array $scores): array
    {
        return [
            'summary' => 'Сумма: ' . (int) ($scores['total'] ?? 0) . ', сфер: ' . (int) ($scores['areas'] ?? 0),
            'recommendations' => [],
        ];
    }

    /**
     * @param array<string, mixed> $results
     *
     * @return list<ResultSection>
     */
    public function buildSections(array $results): array
    {
        return [
            new ResultSection(
                type: ResultSection::TYPE_SCORE_BADGE,
                title: 'Результат',
                data: [
                    'score' => (int) ($results['total'] ?? 0),
                    'max' => 4,
                    'level' => 'normal',
                    'level_label' => 'Сфер: ' . (int) ($results['areas'] ?? 0),
                    'description' => '',
                    'thresholds' => ['normal' => ['min' => 0, 'max' => 4]],
                ],
                block: 'blocks/score-badge.twig',
                order: 10,
            ),
        ];
    }
}
