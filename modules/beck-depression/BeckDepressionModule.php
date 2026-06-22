<?php

/**
 * Beck Depression Inventory (BDI) Module
 *
 * Шкала депрессии Бека для оценки выраженности депрессии
 *
 * @author Aaron T. Beck
 * @adaptation Н.В. Тарабрина (2001)
 * @version 1.0
 */

declare(strict_types=1);

namespace PsyTest\Modules\BeckDepression;

use PsyTest\Modules\BaseTestModule;
use PsyTest\Modules\ResultSection;

class BeckDepressionModule extends BaseTestModule
{
    /**
     * Пороговые значения для интерпретации
     */
    protected const THRESHOLDS = [
        'minimal' => ['min' => 0, 'max' => 13],
        'mild' => ['min' => 14, 'max' => 19],
        'moderate' => ['min' => 20, 'max' => 28],
        'severe' => ['min' => 29, 'max' => 63],
    ];

    /**
     * Интерпретации для каждого уровня
     */
    protected const INTERPRETATIONS = [
        'minimal' => 'Уровень депрессии находится в пределах нормы (0-13 баллов)',
        'mild' => 'Лёгкая степень депрессии (14-19 баллов)',
        'moderate' => 'Умеренная степень депрессии (20-28 баллов)',
        'severe' => 'Тяжёлая степень депрессии (29-63 баллов)',
    ];

    /**
     * Названия уровней
     */
    protected const LEVEL_NAMES = [
        'minimal' => 'Минимальная депрессия',
        'mild' => 'Лёгкая депрессия',
        'moderate' => 'Умеренная депрессия',
        'severe' => 'Тяжёлая депрессия',
    ];

    /**
     * Рекомендации по уровню депрессии
     */
    protected const RECOMMENDATIONS = [
        'minimal' => [
            'Уровень депрессии находится в пределах нормы',
            'Продолжайте поддерживать здоровый образ жизни',
            'Регулярная физическая активность помогает поддерживать эмоциональное равновесие',
            'Поддерживайте социальные контакты и общение с близкими',
        ],
        'mild' => [
            'Уровень депрессии слегка повышен',
            'Обратите внимание на режим сна и питания',
            'Практикуйте техники релаксации и управления стрессом',
            'Рассмотрите возможность консультации с психологом',
            'Регулярная физическая активность может помочь улучшить настроение',
        ],
        'moderate' => [
            'Уровень депрессии умеренно повышен',
            'Рекомендуется обратиться к психологу или психотерапевту',
            'Сочетание психотерапии и здорового образа жизни даёт наилучшие результаты',
            'Не изолируйтесь — поддерживайте контакты с близкими',
            'Обсудите с врачом возможные физиологические причины симптомов',
        ],
        'severe' => [
            'Уровень депрессии значительно повышен',
            'Настоятельно рекомендуется обратиться к специалисту (психиатру, психотерапевту)',
            'Тяжёлая депрессия требует профессионального лечения',
            'Специалист поможет подобрать эффективную терапию',
            'При появлении суицидальных мыслей немедленно обратитесь за помощью',
        ],
    ];

    /**
     * {@inheritDoc}
     */
    public function getMetadata(): array
    {
        return array_merge(parent::getMetadata(), [
            'scoring_type' => 'sum',
            'max_score' => 63,
            'gender_specific_norms' => false,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getQuestions(): array
    {
        if ($this->questions === null) {
            $this->questions = $this->loadQuestionsFromJson('questions.json');
        }

        return $this->questions;
    }

    /**
     * {@inheritDoc}
     */
    public function calculateResults(array $answers): array
    {
        $totalScore = 0;
        $answeredCount = 0;
        $symptomScores = [];

        foreach ($answers as $questionId => $answer) {
            $question = $this->findQuestionById((int) $questionId);
            if ($question) {
                $points = $this->getPointsForAnswer($question, $answer);
                $totalScore += $points;
                $answeredCount++;

                $symptomScores[$questionId] = [
                    'text' => $question['text'],
                    'score' => $points,
                    'max_score' => 3,
                ];
            }
        }

        $level = $this->getLevel($totalScore);
        $levelName = self::LEVEL_NAMES[$level] ?? $level;
        $interpretation = self::INTERPRETATIONS[$level] ?? '';

        $maxScore = 63;

        return [
            'total_score' => $totalScore,
            'max_score' => $maxScore,
            'percentage' => $maxScore > 0 ? round(($totalScore / $maxScore) * 100) : 0,
            'level' => $level,
            'level_name' => $levelName,
            'interpretation' => $interpretation,
            'answered_count' => $answeredCount,
            'total_questions' => 21,
            'symptom_scores' => $symptomScores,
            'recommendations' => self::RECOMMENDATIONS[$level] ?? [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function generateInterpretation(array $scores): array
    {
        $totalScore = $scores['total_score'] ?? 0;
        $level = $scores['level'] ?? 'minimal';
        $levelName = $scores['level_name'] ?? self::LEVEL_NAMES[$level] ?? $level;
        $interpretation = $scores['interpretation'] ?? self::INTERPRETATIONS[$level] ?? '';
        $recommendations = $scores['recommendations'] ?? self::RECOMMENDATIONS[$level] ?? [];

        $summary = sprintf(
            'Ваш результат: %d из 63 баллов (%s). %s',
            $totalScore,
            $levelName,
            $interpretation
        );

        return [
            'summary' => $summary,
            'total_score' => $totalScore,
            'level' => $level,
            'level_name' => $levelName,
            'interpretation_text' => $interpretation,
            'recommendations' => $recommendations,
            'disclaimer' => 'Результат носит ознакомительный характер и не заменяет очную консультацию специалиста.',
        ];
    }

    public function buildSections(array $results): array
    {
        $total = $results['total_score'] ?? 0;
        $maxScore = $results['max_score'] ?? 63;
        $level = $results['level'] ?? 'minimal';
        $levelName = $results['level_name'] ?? self::LEVEL_NAMES[$level] ?? '';
        $interpretation = $results['interpretation'] ?? '';
        $recommendations = $results['recommendations'] ?? [];

        return [
            new ResultSection(
                type: ResultSection::TYPE_SCORE_BADGE,
                title: 'Уровень депрессии',
                data: [
                    'score' => $total,
                    'max' => $maxScore,
                    'level' => $level,
                    'level_label' => $levelName,
                    'description' => $interpretation,
                ],
                block: 'blocks/score-badge.twig',
                order: 10,
            ),
            new ResultSection(
                type: ResultSection::TYPE_RECOMMENDATIONS,
                title: 'Рекомендации',
                data: [
                    'items' => $recommendations,
                ],
                block: 'blocks/recommendations.twig',
                order: 20,
            ),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function supportsPairMode(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function comparePairResults(array $results1, array $results2): array
    {
        return [
            'results_1' => $results1,
            'results_2' => $results2,
            'differences' => [],
        ];
    }

    // ============================================
    // Вспомогательные методы
    // ============================================

    /**
     * Найти вопрос по ID
     */
    protected function findQuestionById(int $id): ?array
    {
        $questions = $this->getQuestions();
        foreach ($questions as $question) {
            if ($question['id'] === $id) {
                return $question;
            }
        }
        return null;
    }

    /**
     * Получить баллы за ответ
     */
    protected function getPointsForAnswer(array $question, mixed $answer): int
    {
        if (!isset($question['options'])) {
            return 0;
        }

        foreach ($question['options'] as $option) {
            if ((string) $option['value'] === (string) $answer) {
                return (int) $option['value'];
            }
        }

        return 0;
    }

    /**
     * Определить уровень по баллам
     */
    protected function getLevel(int $score): string
    {
        foreach (self::THRESHOLDS as $level => $range) {
            if ($score >= $range['min'] && $score <= $range['max']) {
                return $level;
            }
        }
        return 'minimal';
    }

    /**
     * Get demographics requirements
     */
    public function getDemographicsRequirements(): array
    {
        return $this->metadata['requires_demographics'] ?? [
            'gender' => false,
            'age' => false,
            'min_age' => 14,
            'max_age' => 100,
        ];
    }
}
