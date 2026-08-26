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
use PsyTest\Modules\ModuleCapability;
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

    /** @var array<string, list<string>> Рекомендации по уровням. */
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

    /** Режимы отчёта ИИ (docs/lazarus-ai-report-prompts.md §1). */
    private const AI_MODE_INDIVIDUAL = 'individual';
    private const AI_MODE_PAIR = 'pair';

    /** Домен считается слабым при собственной оценке не выше этого значения. */
    private const AI_WEAK_DOMAIN_MAX = 5;

    /** Расхождение восприятия считается заметным начиная с этой величины. */
    private const AI_LARGE_GAP_MIN = 3;

    private const TOTAL_QUESTIONS = 16;
    private const MAX_SCORE_PER_ITEM = 10;

    /** @var list<string> Короткие подписи пунктов для оси X веб-графика. */
    private const CHART_SHORT_LABELS = [
        'Общение', 'Общение', 'Интим.', 'Финансы', 'Время', 'Соц.жизнь',
        'Родит.', 'Союзнич.', 'Досуг', 'Ценности', 'Эмоц.близ.', 'Доверие',
        'Привычки', 'Семья парт.', 'Своя семья', 'Внешность',
    ];

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

        // Score badge — без названия уровня в title (избегаем дублирования).
        // description берём из константы, не из $results['interpretation']
        // (там после generateInterpretation лежит массив [summary,recommendations]).
        $sections[] = new ResultSection(
            type: ResultSection::TYPE_SCORE_BADGE,
            title: 'Результат',
            data: [
                'score'       => $total,
                'max'         => $max,
                'level'       => $level,
                'level_label' => $levelName,
                'description' => self::INTERPRETATIONS[$level] ?? '',
                'thresholds'  => self::THRESHOLDS,
            ],
            block: 'blocks/score-badge.twig',
            order: 10,
        );

        // Таблица по 16 пунктам с двумя оценками и расхождением.
        $sections[] = new ResultSection(
            type: ResultSection::TYPE_SCALES_TABLE,
            title: 'Профиль по пунктам',
            data: [
                'scales' => $this->buildItemTable($results),
            ],
            block: 'blocks/lazarus-items.twig',
            order: 20,
        );

        // Интерпретация — текстовый блок (summary).
        $sections[] = new ResultSection(
            type: ResultSection::TYPE_INTERPRETATION,
            title: 'Интерпретация',
            data: ['text' => $interp['summary']],
            block: 'blocks/lazarus-interpretation.twig',
            order: 30,
        );

        $sections[] = new ResultSection(
            type: ResultSection::TYPE_RECOMMENDATIONS,
            title: 'Рекомендации',
            data: ['items' => $interp['recommendations']],
            block: 'blocks/recommendations.twig',
            order: 40,
        );

        // Если есть результат сравнения пары — блок сравнения (для обоих партнёров).
        if (isset($results['pair_comparison'])) {
            // Веб-график совмещённых профилей — отдельная секция; в печатную
            // версию не попадает: PDF использует утверждённую компактную таблицу.
            if (empty($results['is_pdf'])) {
                $sections[] = new ResultSection(
                    type: ResultSection::TYPE_PAIR_CHART,
                    title: 'График совмещённых профилей',
                    data: ['chart' => $this->pairChartData($results['pair_comparison'])],
                    block: 'blocks/pair-chart.twig',
                    order: 45,
                );
            }

            $sections[] = new ResultSection(
                type: ResultSection::TYPE_PAIR_COMPARISON,
                title: 'Сравнение с партнёром',
                data: [
                    'comparison' => $results['pair_comparison'],
                    'is_pdf' => !empty($results['is_pdf']),
                    'is_result_pdf' => !empty($results['is_pdf']),
                ],
                block: 'blocks/pair-comparison.twig',
                order: 50,
            );
        } elseif ($this->supportsPairMode() && empty($results['is_pdf'])) {
            // Сравнения ещё нет — показать приглашение (только на web-странице,
            // не в PDF: приглашение-ссылка не нужна в распечатанном документе).
            $sections[] = new ResultSection(
                type: ResultSection::TYPE_PAIR_INVITE,
                title: 'Пригласить партнёра',
                data: [],
                block: 'blocks/pair-invite.twig',
                order: 60,
            );
        }

        return $sections;
    }

    /**
     * {@inheritDoc}
     */
    public function getCapabilities(): array
    {
        return [ModuleCapability::PAIR, ModuleCapability::PDF];
    }

    /**
     * {@inheritDoc}
     */
    public function getAnswerSchema(): array
    {
        return [
            'answer_type' => 'scale10',
            'key_template' => 'dual',
            'extra_keys' => ['gender'],
            'requires_gender' => false,
        ];
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
            'Совпадение собственных оценок отношений: %.1f%%. %s',
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
     * {@inheritDoc}
     *
     * Форма полезной нагрузки зафиксирована в docs/lazarus-ai-report-prompts.md §2.
     * Наружу уходят только рассчитанные значения и тексты пунктов методики:
     * ни токенов, ни идентификаторов сессии, ни email, ни имён, ни свободного
     * текста респондента — их в модуле просто нет.
     *
     * @param array<string, mixed> $results
     *
     * @return array<string, mixed>|null
     */
    public function aiReportContext(array $results, string $mode): ?array
    {
        return match ($mode) {
            self::AI_MODE_INDIVIDUAL => $this->aiIndividualContext($results),
            self::AI_MODE_PAIR => $this->aiPairContext($results),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $results Результат calculateResults().
     *
     * @return array<string, mixed>|null
     */
    private function aiIndividualContext(array $results): ?array
    {
        if (!isset($results['self_scores'], $results['partner_scores'])) {
            return null;
        }

        $items = [];
        $weakDomains = [];
        $largeGaps = [];

        foreach ($this->getQuestions() as $question) {
            $id = (int) $question['id'];
            $self = $results['self_scores'][$id] ?? null;
            $partnerExpected = $results['partner_scores'][$id] ?? null;
            $gap = $results['perception_gaps'][$id] ?? null;
            $domain = (string) ($question['domain'] ?? '');

            $items[] = [
                'id' => $id,
                'domain' => $domain,
                'text' => (string) $question['text'],
                'self' => $self,
                'partner_expected' => $partnerExpected,
                'gap' => $gap,
            ];

            if ($self !== null && $self <= self::AI_WEAK_DOMAIN_MAX && $domain !== '') {
                $weakDomains[] = $domain;
            }
            if ($gap !== null && abs((int) $gap) >= self::AI_LARGE_GAP_MIN && $domain !== '') {
                $largeGaps[] = $domain;
            }
        }

        return [
            'test' => 'lazarus',
            'mode' => self::AI_MODE_INDIVIDUAL,
            'items' => $items,
            'totals' => [
                'self' => $results['total_self'] ?? null,
                'partner_expected' => $results['total_partner'] ?? null,
                'max' => $results['max_score'] ?? null,
            ],
            'level' => $results['level'] ?? null,
            'level_name' => $results['level_name'] ?? null,
            'weak_domains' => array_values(array_unique($weakDomains)),
            'large_perception_gaps' => array_values(array_unique($largeGaps)),
        ];
    }

    /**
     * @param array<string, mixed> $comparison Результат comparePairResults().
     *
     * @return array<string, mixed>|null
     */
    private function aiPairContext(array $comparison): ?array
    {
        if (!isset($comparison['items']) || !is_array($comparison['items'])) {
            return null;
        }

        $partner1 = $this->aiIndividualContext($comparison['results_1'] ?? []);
        $partner2 = $this->aiIndividualContext($comparison['results_2'] ?? []);

        if ($partner1 === null || $partner2 === null) {
            return null;
        }

        $items = [];
        foreach ($comparison['items'] as $item) {
            $items[] = [
                'id' => (int) $item['id'],
                'domain' => (string) ($item['domain'] ?? ''),
                'text' => (string) ($item['text'] ?? ''),
                'partner1_self' => $item['p1_self'] ?? null,
                'partner2_self' => $item['p2_self'] ?? null,
                'difference' => $item['difference'] ?? null,
                'partner1_accuracy' => $item['p1_accuracy'] ?? null,
                'partner2_accuracy' => $item['p2_accuracy'] ?? null,
            ];
        }

        return [
            'test' => 'lazarus',
            'mode' => self::AI_MODE_PAIR,
            'items' => $items,
            'overall_agreement' => $comparison['overall_agreement'] ?? null,
            'partner1' => $partner1,
            'partner2' => $partner2,
        ];
    }

    /**
     * Подготовить данные веб-графика совмещённых профилей пары.
     * Вся геометрия считается здесь; шаблон blocks/pair-chart.twig только рисует.
     *
     * @param array<string, mixed> $comparison Результат comparePairResults().
     *
     * @return array<string, mixed>
     */
    public function pairChartData(array $comparison): array
    {
        $width = 940;
        $height = 330;
        $left = 36;
        $right = 16;
        $top = 26;
        $bottom = 64;
        $step = ($width - $left - $right) / self::TOTAL_QUESTIONS;
        $baseY = $height - $bottom;
        $x = static fn (int $i): float => $left + $step * $i + $step / 2;
        $y = static fn (float $score): float => $top + (10 - $score) / 10 * ($height - $top - $bottom);

        $grid = [];
        foreach ([0, 2, 4, 6, 8, 10] as $value) {
            $grid[] = ['y' => round($y((float) $value), 1), 'label' => $value];
        }

        $pointsP1 = [];
        $pointsP2 = [];
        $dots = [];
        $bands = [];
        $labels = [];

        foreach ($comparison['items'] ?? [] as $index => $item) {
            $i = (int) $index;
            $p1 = isset($item['p1_self']) ? (int) $item['p1_self'] : null;
            $p2 = isset($item['p2_self']) ? (int) $item['p2_self'] : null;
            $diff = isset($item['difference']) ? (int) $item['difference'] : null;
            $domain = self::CHART_SHORT_LABELS[$i] ?? '';
            $text = mb_substr((string) ($item['text'] ?? ''), 0, 110);

            if ($p1 !== null) {
                $pointsP1[] = round($x($i), 1) . ',' . round($y((float) $p1), 1);
            }
            if ($p2 !== null) {
                $pointsP2[] = round($x($i), 1) . ',' . round($y((float) $p2), 1);
            }

            $dot = [
                'x' => round($x($i), 1),
                'y1' => $p1 !== null ? round($y((float) $p1), 1) : null,
                'y2' => $p2 !== null ? round($y((float) $p2), 1) : null,
                'i' => $i + 1,
                'domain' => $domain,
                'text' => $text,
                'v1' => $p1,
                'v2' => $p2,
                'd' => $diff,
            ];

            if ($p1 !== null || $p2 !== null) {
                $dots[] = $dot;
            }

            if ($diff !== null && abs($diff) >= 3) {
                $bandLeft = $x(max(0, $i - 1)) + ($i === 0 ? 0 : $step / 2);
                $bandRight = $x(min(self::TOTAL_QUESTIONS - 1, $i + 1)) - ($i === self::TOTAL_QUESTIONS - 1 ? 0 : $step / 2);
                $bands[] = ['x' => round($bandLeft, 1), 'width' => round($bandRight - $bandLeft, 1)];
            }

            $labels[] = [
                'x' => round($x($i), 1),
                'num' => $i + 1,
                'domain' => $domain,
            ];
        }

        $firstX = round($x(0), 1);
        $lastX = round($x(self::TOTAL_QUESTIONS - 1), 1);
        $pointsP1String = implode(' ', $pointsP1);
        $pointsP2String = implode(' ', $pointsP2);

        return [
            'width' => $width,
            'height' => $height,
            'labels_y' => $baseY + 32,
            'grid' => $grid,
            'bands' => $bands,
            'points_p1' => $pointsP1String,
            'points_p2' => $pointsP2String,
            'fill_p1' => trim($pointsP1String . " {$lastX},{$baseY} {$firstX},{$baseY}"),
            'fill_p2' => trim($pointsP2String . " {$lastX},{$baseY} {$firstX},{$baseY}"),
            'dots' => $dots,
            'labels' => $labels,
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
