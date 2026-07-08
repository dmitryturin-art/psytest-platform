<?php

/**
 * Опросник супружеской удовлетворённости (Лазарус, 1997)
 *
 * 16 пунктов, каждый оценивается дважды: «Я» (своя удовлетворённость) и
 * «Партнёр» (как, по мнению респондента, ответил бы партнёр). Шкала 1–10.
 * Поддерживает парный режим: два партнёра проходят отдельно, система
 * сравнивает их ответы и perception gaps.
 */

declare(strict_types=1);

namespace PsyTest\Modules\Lazarus;

use PsyTest\Modules\BaseTestModule;
use PsyTest\Modules\ResultSection;

final class LazarusModule extends BaseTestModule
{
    /** @var array<string, array{min: int, max: int}> Уровни по суммарному баллу. */
    private const THRESHOLDS = [
        'dissatisfied' => ['min' => 16, 'max' => 79],
        'satisfied'    => ['min' => 80, 'max' => 160],
    ];

    /** @var array<string, string> Человеческие названия уровней. */
    private const LEVEL_NAMES = [
        'dissatisfied' => 'Неудовлетворённость отношениями',
        'satisfied'    => 'Удовлетворённость отношениями',
    ];

    /** @var array<string, string> Краткие интерпретации уровней. */
    private const INTERPRETATIONS = [
        'dissatisfied' => 'Суммарный показатель ниже 80 свидетельствует о значительной неудовлетворённости супружескими отношениями. Стоит обратить внимание на пункты с наиболее низкими оценками и обсудить их с партнёром.',
        'satisfied'    => 'Суммарный показатель 80 и выше свидетельствует об общей удовлетворённости отношениями. Тем не менее полезно обсудить отдельные пункты с низкими оценками.',
    ];

    /** @var list<string> Рекомендации по уровням. */
    private const RECOMMENDATIONS = [
        'dissatisfied' => [
            'Обсудите с партнёром пункты, оценённые наиболее низко, — что именно вызывает неудовлетворённость.',
            'Рассмотрите возможность обращения к семейному психологу для работы над отношениями.',
            'Сравните свои оценки с тем, как, по вашему мнению, ответил бы партнёр, — расхождения часто указывают на зоны непонимания.',
        ],
        'satisfied' => [
            'Результаты в целом благополучны. Полезно периодически обсуждать с партнёром, какие стороны отношений ценятся особенно высоко.',
            'Обратите внимание на отдельные пункты с более низкими оценками — это зоны для возможного улучшения.',
        ],
    ];

    private const TOTAL_QUESTIONS = 16;
    private const MAX_SCORE_PER_ITEM = 10;

    /**
     * {@inheritDoc}
     *
     * Каждый пункт имеет два ответа: answers[N_self] и answers[N_partner].
     * Возвращает профили для «себя» и «восприятия партнёра», суммарные баллы,
     * уровни и анализ perception gaps.
     */
    public function calculateResults(array $answers): array
    {
        $questions = $this->getQuestions();
        $selfScores = [];
        $partnerScores = [];
        $perceptionGaps = [];
        $answeredCount = 0;

        foreach ($questions as $q) {
            $id = (int) $q['id'];
            $self = $answers[$id . '_self'] ?? null;
            $partner = $answers[$id . '_partner'] ?? null;

            $selfVal = $this->normalizeRating($self);
            $partnerVal = $this->normalizeRating($partner);

            if ($selfVal !== null || $partnerVal !== null) {
                $answeredCount++;
            }

            $selfScores[$id] = $selfVal ?? 0;
            $partnerScores[$id] = $partnerVal ?? 0;
            $perceptionGaps[$id] = $selfVal !== null && $partnerVal !== null
                ? $selfVal - $partnerVal
                : null;
        }

        $totalSelf = array_sum($selfScores);
        $totalPartner = array_sum($partnerScores);
        $level = $totalSelf < 80 ? 'dissatisfied' : 'satisfied';

        return [
            'self_scores'      => $selfScores,
            'partner_scores'   => $partnerScores,
            'perception_gaps'  => $perceptionGaps,
            'total_self'       => $totalSelf,
            'total_partner'    => $totalPartner,
            'max_score'        => self::TOTAL_QUESTIONS * self::MAX_SCORE_PER_ITEM,
            'level'            => $level,
            'level_name'       => self::LEVEL_NAMES[$level],
            'interpretation'   => self::INTERPRETATIONS[$level],
            'answered_count'   => $answeredCount,
            'total_questions'  => self::TOTAL_QUESTIONS,
            'gender'           => $answers['gender'] ?? null,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function generateInterpretation(array $scores): array
    {
        $total = $scores['total_self'] ?? 0;
        $level = $scores['level'] ?? 'dissatisfied';
        $levelName = self::LEVEL_NAMES[$level] ?? $level;
        $interpretation = self::INTERPRETATIONS[$level] ?? '';
        $recommendations = self::RECOMMENDATIONS[$level] ?? [];

        $summary = sprintf(
            'Суммарный балл: %d из %d (%s). %s',
            $total,
            self::TOTAL_QUESTIONS * self::MAX_SCORE_PER_ITEM,
            $levelName,
            $interpretation
        );

        // Найдём домены с наиболее низкими оценками (<=5) для рекомендаций.
        $weakDomains = $this->findWeakDomains($scores);
        if (!empty($weakDomains)) {
            $recommendations[] = 'Зоны для внимания: ' . implode(', ', $weakDomains) . '.';
        }

        // Perception gaps: где респондент сильно расходится во взгляде на партнёра.
        $gapDomains = $this->findLargePerceptionGaps($scores);
        if (!empty($gapDomains)) {
            $recommendations[] = 'Заметные расхождения между своей оценкой и восприятием партнёра: ' . implode(', ', $gapDomains) . '.';
        }

        return [
            'summary'         => $summary,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function buildSections(array $results): array
    {
        $total = $results['total_self'] ?? 0;
        $max = $results['max_score'] ?? 160;
        $level = $results['level'] ?? 'dissatisfied';
        $levelName = $results['level_name'] ?? '';
        $interp = $this->generateInterpretation($results);

        $sections = [];
        $sections[] = new ResultSection(
            type: ResultSection::TYPE_SCORE_BADGE,
            title: 'Удовлетворённость отношениями',
            data: [
                'score'       => $total,
                'max'         => $max,
                'level'       => $level,
                'level_label' => $levelName,
                'description' => $results['interpretation'] ?? '',
                'thresholds'  => self::THRESHOLDS,
            ],
            block: 'blocks/score-badge.twig',
            order: 10,
        );

        // Таблица по 16 пунктам с двумя оценками и perception gap.
        $sections[] = new ResultSection(
            type: ResultSection::TYPE_SCALES_TABLE,
            title: 'Профиль удовлетворённости по пунктам',
            data: [
                'scales' => $this->buildItemTable($results),
            ],
            block: 'blocks/scales-table.twig',
            order: 20,
        );

        $sections[] = new ResultSection(
            type: ResultSection::TYPE_INTERPRETATION,
            title: 'Интерпретация',
            data: ['text' => $interp['summary']],
            block: 'blocks/interpretation.twig',
            order: 30,
        );

        $sections[] = new ResultSection(
            type: ResultSection::TYPE_RECOMMENDATIONS,
            title: 'Рекомендации',
            data: ['items' => $interp['recommendations']],
            block: 'blocks/recommendations.twig',
            order: 40,
        );

        // Если есть результат сравнения пары — блок сравнения.
        if (isset($results['pair_comparison'])) {
            $sections[] = new ResultSection(
                type: ResultSection::TYPE_RAW_HTML,
                title: 'Сравнение с партнёром',
                data: ['html' => $results['pair_comparison_html'] ?? ''],
                order: 50,
            );
        } elseif ($this->supportsPairMode() && !isset($results['is_pair_partner'])) {
            // Партнёр 1, ещё не имеет сравнения — показать приглашение.
            $sections[] = new ResultSection(
                type: ResultSection::TYPE_RAW_HTML,
                title: 'Пригласить партнёра',
                data: ['html' => 'PAIR_INVITE_PLACEHOLDER'],
                order: 60,
            );
        }

        return $sections;
    }

    /**
     * Парный режим поддерживается.
     */
    public function supportsPairMode(): bool
    {
        return true;
    }

    /**
     * Сравнение результатов двух партнёров.
     *
     * @param array<string, mixed> $results1 Результаты Партнёра 1.
     * @param array<string, mixed> $results2 Результаты Партнёра 2.
     *
     * @return array{items: list<array<string, mixed>>, overall_agreement: float, summary: string}
     */
    public function comparePairResults(array $results1, array $results2): array
    {
        $questions = $this->getQuestions();
        $items = [];
        $agreementSum = 0.0;
        $agreementCount = 0;

        foreach ($questions as $q) {
            $id = (int) $q['id'];
            $p1Self = $results1['self_scores'][$id] ?? null;
            $p1Partner = $results1['partner_scores'][$id] ?? null; // perception П1 о П2
            $p2Self = $results2['self_scores'][$id] ?? null;
            $p2Partner = $results2['partner_scores'][$id] ?? null; // perception П2 о П1

            $diff = ($p1Self !== null && $p2Self !== null) ? $p1Self - $p2Self : null;
            $p1Gap = ($p1Self !== null && $p1Partner !== null) ? $p1Self - $p1Partner : null;
            // perception gap Партнёра 1: насколько он угадал ответ Партнёра 2
            $p1Accuracy = ($p1Partner !== null && $p2Self !== null) ? $p1Partner - $p2Self : null;
            $p2Accuracy = ($p2Partner !== null && $p1Self !== null) ? $p2Partner - $p1Self : null;

            $items[] = [
                'id'          => $id,
                'text'        => $q['text'],
                'domain'      => $q['domain'] ?? '',
                'p1_self'     => $p1Self,
                'p2_self'     => $p2Self,
                'difference'  => $diff,
                'p1_perception' => $p1Partner,
                'p2_perception' => $p2Partner,
                'p1_accuracy' => $p1Accuracy,
                'p2_accuracy' => $p2Accuracy,
            ];

            if ($diff !== null) {
                $agreementSum += (10 - abs($diff));
                $agreementCount++;
            }
        }

        $overallAgreement = $agreementCount > 0
            ? round($agreementSum / $agreementCount / 10 * 100, 1)
            : 0.0;

        $summary = sprintf(
            'Общая согласованность ответов: %.1f%%. %s',
            $overallAgreement,
            $overallAgreement >= 80
                ? 'Партнёры в целом согласны в оценке отношений.'
                : 'Есть заметные расхождения в восприятии отношений — стоит обсудить пункты с наибольшей разницей.'
        );

        return [
            'items' => $items,
            'overall_agreement' => $overallAgreement,
            'summary' => $summary,
            'results_1' => $results1,
            'results_2' => $results2,
        ];
    }

    /**
     * Нормализовать ответ в рейтинг 1–10.
     */
    private function normalizeRating(mixed $answer): ?int
    {
        if ($answer === null) {
            return null;
        }
        $val = is_numeric($answer) ? (int) $answer : null;
        if ($val === null) {
            return null;
        }
        return max(1, min(self::MAX_SCORE_PER_ITEM, $val));
    }

    /**
     * Домены с низкими оценками (<=5) для рекомендаций.
     *
     * @param array<string, mixed> $results
     *
     * @return list<string>
     */
    private function findWeakDomains(array $results): array
    {
        $questions = $this->getQuestions();
        $selfScores = $results['self_scores'] ?? [];
        $weak = [];
        foreach ($questions as $q) {
            $id = (int) $q['id'];
            if (($selfScores[$id] ?? 10) <= 5) {
                $weak[] = $q['domain'] ?? ('пункт ' . $id);
            }
        }
        return array_values(array_unique($weak));
    }

    /**
     * Домены с заметными perception gaps (|gap| >= 3).
     *
     * @param array<string, mixed> $results
     *
     * @return list<string>
     */
    private function findLargePerceptionGaps(array $results): array
    {
        $questions = $this->getQuestions();
        $gaps = $results['perception_gaps'] ?? [];
        $large = [];
        foreach ($questions as $q) {
            $id = (int) $q['id'];
            $gap = $gaps[$id] ?? null;
            if ($gap !== null && abs($gap) >= 3) {
                $large[] = $q['domain'] ?? ('пункт ' . $id);
            }
        }
        return array_values(array_unique($large));
    }

    /**
     * Построить таблицу пунктов для scales-table.
     *
     * @param array<string, mixed> $results
     *
     * @return list<array<string, mixed>>
     */
    private function buildItemTable(array $results): array
    {
        $questions = $this->getQuestions();
        $selfScores = $results['self_scores'] ?? [];
        $partnerScores = $results['partner_scores'] ?? [];
        $gaps = $results['perception_gaps'] ?? [];
        $rows = [];

        foreach ($questions as $q) {
            $id = (int) $q['id'];
            $self = $selfScores[$id] ?? 0;
            $partner = $partnerScores[$id] ?? 0;
            $gap = $gaps[$id] ?? 0;

            $rows[] = [
                'code'       => (string) $id,
                'name'       => $q['domain'] ?? '',
                'text'       => $q['text'] ?? '',
                'score'      => $self,
                'partner'    => $partner,
                'gap'        => $gap,
                'level'      => $self <= 5 ? 'low' : ($self >= 8 ? 'high' : 'normal'),
                'level_name' => $self <= 5 ? 'Низкая' : ($self >= 8 ? 'Высокая' : 'Средняя'),
            ];
        }

        return $rows;
    }
}
