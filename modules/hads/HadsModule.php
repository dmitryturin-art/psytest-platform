<?php

/**
 * Hospital Anxiety and Depression Scale (HADS) Module
 *
 * Госпитальная шкала тревоги и депрессии для скрининга тревоги и депрессии
 * у пациентов соматических стационаров
 *
 * @author A.S. Zigmond, R.P. Snaith (1983)
 * @adaptation Русская адаптация
 * @version 1.0
 */

declare(strict_types=1);

namespace PsyTest\Modules\Hads;

use PsyTest\Modules\BaseTestModule;
use PsyTest\Modules\ResultSection;

class HadsModule extends BaseTestModule
{
    /**
     * Пороговые значения для интерпретации (общие для обеих подшкал)
     */
    protected const THRESHOLDS = [
        'normal' => ['min' => 0, 'max' => 7],
        'subclinical' => ['min' => 8, 'max' => 10],
        'clinical' => ['min' => 11, 'max' => 21],
    ];

    /**
     * Интерпретации для каждого уровня (тревога)
     */
    protected const ANXIETY_INTERPRETATIONS = [
        'normal' => 'Уровень тревоги находится в пределах нормы (0-7 баллов)',
        'subclinical' => 'Субклинически выраженная тревога (8-10 баллов)',
        'clinical' => 'Клинически выраженная тревога (11-21 баллов)',
    ];

    /**
     * Интерпретации для каждого уровня (депрессия)
     */
    protected const DEPRESSION_INTERPRETATIONS = [
        'normal' => 'Уровень депрессии находится в пределах нормы (0-7 баллов)',
        'subclinical' => 'Субклинически выраженная депрессия (8-10 баллов)',
        'clinical' => 'Клинически выраженная депрессия (11-21 баллов)',
    ];

    /**
     * Названия уровней
     */
    protected const LEVEL_NAMES = [
        'normal' => 'Норма',
        'subclinical' => 'Субклинический уровень',
        'clinical' => 'Клинический уровень',
    ];

    /**
     * IDs вопросов подшкалы тревоги (нечетные: 1,3,5,7,9,11,13)
     */
    protected const ANXIETY_ITEMS = [1, 3, 5, 7, 9, 11, 13];

    /**
     * IDs вопросов подшкалы депрессии (четные: 2,4,6,8,10,12,14)
     */
    protected const DEPRESSION_ITEMS = [2, 4, 6, 8, 10, 12, 14];

    /**
     * Рекомендации по уровню
     */
    protected const RECOMMENDATIONS = [
        'normal' => [
            'Уровень тревоги и депрессии находится в пределах нормы',
            'Продолжайте поддерживать здоровый образ жизни',
            'Регулярная физическая активность помогает поддерживать эмоциональное равновесие',
            'Поддерживайте социальные контакты и общение с близкими',
        ],
        'subclinical' => [
            'Отмечается субклинический уровень тревоги или депрессии',
            'Обратите внимание на режим сна, питания и физической активности',
            'Практикуйте техники релаксации и управления стрессом',
            'Рассмотрите возможность консультации с психологом',
            'Повторите тестирование через 2-4 недели для отслеживания динамики',
        ],
        'clinical' => [
            'Отмечается клинически значимый уровень тревоги или депрессии',
            'Рекомендуется обратиться к специалисту (психологу, психотерапевту, психиатру)',
            'Клинически выраженные симптомы требуют профессиональной оценки и лечения',
            'Специалист поможет подобрать эффективную терапию',
            'Не откладывайте обращение за помощью',
        ],
    ];

    /**
     * {@inheritDoc}
     */
    public function getMetadata(): array
    {
        return array_merge(parent::getMetadata(), [
            'scoring_type' => 'sum',
            'max_score' => 42,
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
        $anxietyScore = 0;
        $depressionScore = 0;
        $answeredCount = 0;
        $anxietyDetails = [];
        $depressionDetails = [];

        foreach ($answers as $questionId => $answer) {
            $question = $this->findQuestionById((int) $questionId);
            if ($question) {
                $points = $this->getPointsForAnswer($question, $answer);
                $answeredCount++;

                $detail = [
                    'text' => $question['text'],
                    'score' => $points,
                    'max_score' => 3,
                ];

                if (in_array((int) $questionId, self::ANXIETY_ITEMS)) {
                    $anxietyScore += $points;
                    $anxietyDetails[$questionId] = $detail;
                } elseif (in_array((int) $questionId, self::DEPRESSION_ITEMS)) {
                    $depressionScore += $points;
                    $depressionDetails[$questionId] = $detail;
                }
            }
        }

        $anxietyLevel = $this->getLevel($anxietyScore);
        $depressionLevel = $this->getLevel($depressionScore);

        $anxietyLevelName = self::LEVEL_NAMES[$anxietyLevel] ?? $anxietyLevel;
        $depressionLevelName = self::LEVEL_NAMES[$depressionLevel] ?? $depressionLevel;

        $anxietyInterpretation = self::ANXIETY_INTERPRETATIONS[$anxietyLevel] ?? '';
        $depressionInterpretation = self::DEPRESSION_INTERPRETATIONS[$depressionLevel] ?? '';

        // Определяем общий худший уровень для рекомендаций
        $worstLevel = $this->getWorstLevel($anxietyLevel, $depressionLevel);

        $totalScore = $anxietyScore + $depressionScore;

        return [
            'total_score' => $totalScore,
            'max_score' => 42,
            'anxiety_score' => $anxietyScore,
            'depression_score' => $depressionScore,
            'anxiety_max' => 21,
            'depression_max' => 21,
            'anxiety_level' => $anxietyLevel,
            'depression_level' => $depressionLevel,
            'anxiety_level_name' => $anxietyLevelName,
            'depression_level_name' => $depressionLevelName,
            'anxiety_interpretation' => $anxietyInterpretation,
            'depression_interpretation' => $depressionInterpretation,
            'level' => $worstLevel,
            'answered_count' => $answeredCount,
            'total_questions' => 14,
            'anxiety_details' => $anxietyDetails,
            'depression_details' => $depressionDetails,
            'recommendations' => self::RECOMMENDATIONS[$worstLevel] ?? [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function generateInterpretation(array $scores): array
    {
        $anxietyScore = $scores['anxiety_score'] ?? 0;
        $depressionScore = $scores['depression_score'] ?? 0;
        $anxietyLevel = $scores['anxiety_level'] ?? 'normal';
        $depressionLevel = $scores['depression_level'] ?? 'normal';
        $anxietyLevelName = $scores['anxiety_level_name'] ?? self::LEVEL_NAMES[$anxietyLevel] ?? $anxietyLevel;
        $depressionLevelName = $scores['depression_level_name'] ?? self::LEVEL_NAMES[$depressionLevel] ?? $depressionLevel;
        $anxietyInterpretation = $scores['anxiety_interpretation'] ?? '';
        $depressionInterpretation = $scores['depression_interpretation'] ?? '';
        $recommendations = $scores['recommendations'] ?? [];

        $summary = sprintf(
            'Тревога: %d из 21 баллов (%s). Депрессия: %d из 21 баллов (%s).',
            $anxietyScore,
            $anxietyLevelName,
            $depressionScore,
            $depressionLevelName
        );

        return [
            'summary' => $summary,
            'total_score' => $scores['total_score'] ?? 0,
            'anxiety_score' => $anxietyScore,
            'depression_score' => $depressionScore,
            'anxiety_level' => $anxietyLevel,
            'depression_level' => $depressionLevel,
            'anxiety_level_name' => $anxietyLevelName,
            'depression_level_name' => $depressionLevelName,
            'interpretation_text' => $anxietyInterpretation . ' ' . $depressionInterpretation,
            'recommendations' => $recommendations,
            'disclaimer' => 'Результат носит ознакомительный характер и не заменяет очную консультацию специалиста.',
        ];
    }

    public function buildSections(array $results): array
    {
        $anxietyScore = $results['anxiety_score'] ?? 0;
        $depressionScore = $results['depression_score'] ?? 0;
        $anxietyLevel = $results['anxiety_level'] ?? 'normal';
        $depressionLevel = $results['depression_level'] ?? 'normal';
        $anxietyLevelName = $results['anxiety_level_name'] ?? self::LEVEL_NAMES[$anxietyLevel] ?? 'Норма';
        $depressionLevelName = $results['depression_level_name'] ?? self::LEVEL_NAMES[$depressionLevel] ?? 'Норма';
        $anxietyInterpretation = $results['anxiety_interpretation'] ?? '';
        $depressionInterpretation = $results['depression_interpretation'] ?? '';
        $recommendations = $results['recommendations'] ?? [];

        return [
            new ResultSection(
                type: ResultSection::TYPE_SCORE_BADGE,
                title: 'Уровень тревоги',
                data: [
                    'score' => $anxietyScore,
                    'max' => 21,
                    'level' => $anxietyLevel,
                    'level_label' => $anxietyLevelName,
                    'description' => $anxietyInterpretation,
                ],
                block: 'blocks/score-badge.twig',
                order: 10,
            ),
            new ResultSection(
                type: ResultSection::TYPE_SCORE_BADGE,
                title: 'Уровень депрессии',
                data: [
                    'score' => $depressionScore,
                    'max' => 21,
                    'level' => $depressionLevel,
                    'level_label' => $depressionLevelName,
                    'description' => $depressionInterpretation,
                ],
                block: 'blocks/score-badge.twig',
                order: 15,
            ),
            new ResultSection(
                type: ResultSection::TYPE_INTERPRETATION,
                title: 'Интерпретация',
                data: [
                    'text' => $anxietyInterpretation . ' ' . $depressionInterpretation,
                ],
                block: 'blocks/interpretation.twig',
                order: 20,
            ),
            new ResultSection(
                type: ResultSection::TYPE_RECOMMENDATIONS,
                title: 'Рекомендации',
                data: [
                    'items' => $recommendations,
                ],
                block: 'blocks/recommendations.twig',
                order: 30,
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
        return 'normal';
    }

    /**
     * Определить худший уровень из тревоги и депрессии (для рекомендаций)
     */
    protected function getWorstLevel(string $anxietyLevel, string $depressionLevel): string
    {
        $levelPriority = ['normal' => 0, 'subclinical' => 1, 'clinical' => 2];

        $anxietyPriority = $levelPriority[$anxietyLevel] ?? 0;
        $depressionPriority = $levelPriority[$depressionLevel] ?? 0;

        return $anxietyPriority >= $depressionPriority ? $anxietyLevel : $depressionLevel;
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
