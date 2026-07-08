<?php

/**
 * Beck Anxiety Inventory (BAI) Module
 *
 * Шкала тревоги Бека для оценки выраженности тревоги
 *
 * @author Aaron T. Beck
 * @version 1.0
 */

declare(strict_types=1);

namespace PsyTest\Modules\BeckAnxiety;

use PsyTest\Modules\BaseTestModule;
use PsyTest\Modules\ResultSection;

class BeckAnxietyModule extends BaseTestModule
{
    /**
     * Пороговые значения для интерпретации
     */
    protected const THRESHOLDS = [
        'minimal' => ['min' => 0, 'max' => 21],
        'moderate' => ['min' => 22, 'max' => 35],
        'high' => ['min' => 36, 'max' => 63],
    ];

    /**
     * Интерпретации для каждого уровня
     */
    protected const INTERPRETATIONS = [
        'minimal' => 'Значение до 21 балла включительно свидетельствует о незначительном уровне тревоги',
        'moderate' => 'Значение от 22 до 35 баллов означает среднюю выраженность тревоги',
        'high' => 'Значение выше 36 баллов (при максимуме в 63 балла) свидетельствует об очень высокой тревоге',
    ];

    /**
     * Названия уровней
     */
    protected const LEVEL_NAMES = [
        'minimal' => 'Незначительная тревога',
        'moderate' => 'Средняя тревога',
        'high' => 'Высокая тревога',
    ];

    /**
     * Рекомендации по уровню тревоги
     */
    protected const RECOMMENDATIONS = [
        'minimal' => [
            'Уровень тревоги находится в пределах нормы',
            'Продолжайте практиковать здоровые coping-стратегии',
            'Поддерживайте баланс между работой и отдыхом',
            'Регулярная физическая активность поможет поддерживать эмоциональное равновесие',
        ],
        'moderate' => [
            'Уровень тревоги повышен, но находится в допустимых пределах',
            'Обратите внимание на источники стресса в вашей жизни',
            'Практикуйте техники релаксации (дыхательные упражнения, медитация)',
            'Рассмотрите возможность консультации с психологом для работы с тревогой',
            'Убедитесь, что вы получаете достаточно сна и отдыха',
        ],
        'high' => [
            'Уровень тревоги значительно повышен',
            'Рекомендуется обратиться к специалисту (психологу, психотерапевту)',
            'Высокая тревога может влиять на повседневное функционирование',
            'Специалист поможет подобрать эффективные стратегии управления тревогой',
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

        // Подсчёт суммы баллов
        foreach ($answers as $questionId => $answer) {
            $question = $this->findQuestionById((int) $questionId);
            if ($question) {
                $points = $this->getPointsForAnswer($question, $answer);
                $totalScore += $points;
                $answeredCount++;

                // Сохраняем баллы по симптомам
                $symptomScores[$questionId] = [
                    'text' => $question['text'],
                    'score' => $points,
                    'max_score' => 3,
                ];
            }
        }

        // Определение уровня тревоги
        $level = $this->getLevel($totalScore);
        $levelName = self::LEVEL_NAMES[$level] ?? $level;
        $interpretation = self::INTERPRETATIONS[$level] ?? '';

        // Расчёт процента от максимума
        $maxScore = 63; // 21 вопрос × 3 балла
        $percentage = (int) round(($totalScore / $maxScore) * 100);

        return [
            'total_score' => $totalScore,
            'max_score' => $maxScore,
            'percentage' => $percentage,
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

        // Формируем summary
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

        // interpretation can be a string (from calculateResults) or an array (from generateInterpretation merged in DB)
        $rawInterp = $results['interpretation'] ?? '';
        if (is_array($rawInterp)) {
            $interpretation = $rawInterp['interpretation_text'] ?? $rawInterp['summary'] ?? '';
        } else {
            $interpretation = $rawInterp;
        }

        $rawRec = $results['recommendations'] ?? [];
        $recommendations = is_array($rawRec) && isset($rawRec['summary']) ? ($rawRec['recommendations'] ?? []) : $rawRec;

        return [
            new ResultSection(
                type: ResultSection::TYPE_SCORE_BADGE,
                title: 'Уровень тревоги',
                data: [
                    'score' => $total,
                    'max' => $maxScore,
                    'level' => $level,
                    'level_label' => $levelName,
                    'description' => $interpretation,
                    'thresholds' => self::THRESHOLDS,
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
     * Получить топ симптомов по баллам
     */
    protected function getTopSymptoms(array $symptomScores, int $limit = 5): array
    {
        // Фильтруем только симптомы с баллами > 0
        $filtered = array_filter($symptomScores, fn ($s) => $s['score'] > 0);

        // Сортируем по убыванию баллов
        usort($filtered, fn ($a, $b) => $b['score'] - $a['score']);

        return array_slice($filtered, 0, $limit);
    }

    /**
     * Получить интенсивность симптома (для CSS класса)
     */
    protected function getSymptomIntensity(int $score): string
    {
        if ($score >= 3) {
            return 'high';
        }
        if ($score >= 2) {
            return 'moderate';
        }
        if ($score >= 1) {
            return 'low';
        }
        return 'none';
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
