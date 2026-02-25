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

class BeckAnxietyModule extends BaseTestModule
{
    /**
     * Пороговые значения для интерпретации
     * Официальные cutoff scores BAI
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
            'Не откладывайте визит к специалисту, особенно если тревога мешает повседневной жизни',
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
            'min_score' => 0,
            'clinical_cutoff' => 22,
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

        // Подсчёт общего балла
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
        $percentage = $maxScore > 0 ? round(($totalScore / $maxScore) * 100) : 0;

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
            'disclaimer' => 'Результат носит ознакомительный характер и не является диагнозом. ' .
                           'Для профессиональной оценки обратитесь к специалисту.',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function renderResults(array $results): string
    {
        $totalScore = $results['total_score'] ?? 0;
        $maxScore = $results['max_score'] ?? 63;
        $percentage = $results['percentage'] ?? 0;
        $level = $results['level'] ?? 'minimal';
        $levelName = $results['level_name'] ?? self::LEVEL_NAMES[$level] ?? $level;
        $interpretation = $results['interpretation'] ?? self::INTERPRETATIONS[$level] ?? '';
        $recommendations = $results['recommendations'] ?? [];
        
        // Цветовая индикация уровня
        $levelColors = [
            'minimal' => '#27ae60',
            'moderate' => '#f39c12',
            'high' => '#e74c3c',
        ];
        $levelColor = $levelColors[$level] ?? '#95a5a6';
        
        $html = '<div class="bai-results">';
        
        // Заголовок результата
        $html .= '<div class="results-header">';
        $html .= '<h2>Результаты тестирования</h2>';
        $html .= '<p class="test-subtitle">Шкала тревоги Бека (BAI)</p>';
        $html .= '</div>';
        
        // Основной балл
        $html .= '<div class="score-card">';
        $html .= '<div class="score-main">';
        $html .= sprintf('<span class="score-value">%d</span>', $totalScore);
        $html .= sprintf('<span class="score-max">из %d</span>', $maxScore);
        $html .= '</div>';
        $html .= sprintf('<div class="score-percentage">%d%% от максимума</div>', $percentage);
        $html .= sprintf(
            '<div class="score-level" style="color: %s"><strong>%s</strong></div>',
            $levelColor,
            $levelName
        );
        $html .= '</div>';
        
        // Визуальная шкала
        $html .= '<div class="severity-scale-container">';
        $html .= '<h3>Шкала выраженности тревоги</h3>';
        $html .= '<div class="severity-scale">';
        
        // Минимальная (0-21)
        $minimalWidth = (21 / 63) * 100;
        $html .= sprintf(
            '<div class="scale-segment minimal" style="width: %d%%" title="0-21: Неглубокая тревога"></div>',
            $minimalWidth
        );
        
        // Средняя (22-35)
        $moderateWidth = ((35 - 22 + 1) / 63) * 100;
        $html .= sprintf(
            '<div class="scale-segment moderate" style="width: %d%%" title="22-35: Средняя тревога"></div>',
            $moderateWidth
        );
        
        // Высокая (36-63)
        $highWidth = ((63 - 36 + 1) / 63) * 100;
        $html .= sprintf(
            '<div class="scale-segment high" style="width: %d%%" title="36-63: Высокая тревога"></div>',
            $highWidth
        );
        
        // Маркер результата
        $markerPosition = ($totalScore / 63) * 100;
        $html .= sprintf(
            '<div class="scale-marker" style="left: %d%%"></div>',
            $markerPosition
        );
        
        $html .= '</div>';
        
        // Подписи к шкале
        $html .= '<div class="scale-labels">';
        $html .= '<span class="label">0</span>';
        $html .= '<span class="label">21</span>';
        $html .= '<span class="label">35</span>';
        $html .= '<span class="label">63</span>';
        $html .= '</div>';
        
        // Легенда
        $html .= '<div class="scale-legend">';
        $html .= '<div class="legend-item"><span class="dot minimal"></span> 0-21: Неглубокая тревога</div>';
        $html .= '<div class="legend-item"><span class="dot moderate"></span> 22-35: Средняя тревога</div>';
        $html .= '<div class="legend-item"><span class="dot high"></span> 36-63: Высокая тревога</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Интерпретация
        $html .= '<div class="interpretation-card">';
        $html .= '<h3>📋 Интерпретация результата</h3>';
        $html .= sprintf('<p class="interpretation-text">%s</p>', is_string($interpretation) ? $interpretation : ($interpretation['text'] ?? ''));
        $html .= '</div>';
        
        // Топ симптомов (если есть детализация)
        if (!empty($results['symptom_scores'])) {
            $topSymptoms = $this->getTopSymptoms($results['symptom_scores'], 5);

            if (!empty($topSymptoms)) {
                $html .= '<div class="symptoms-card">';
                $html .= '<h3>⚠️ Наиболее выраженные симптомы</h3>';
                $html .= '<p class="symptoms-note"><small>Показаны топ-5 симптомов с наибольшими баллами. Остальные симптомы имеют меньшую выраженность.</small></p>';
                $html .= '<ul class="symptoms-list">';
                foreach ($topSymptoms as $symptom) {
                    $intensity = $this->getSymptomIntensity($symptom['score']);
                    $html .= sprintf(
                        '<li><span class="symptom-name">%s</span> <span class="symptom-score %s">%d/3</span></li>',
                        $symptom['text'],
                        $intensity,
                        $symptom['score']
                    );
                }
                $html .= '</ul>';
                $html .= '</div>';
            }
        }
        
        // Рекомендации
        if (!empty($recommendations) && is_array($recommendations)) {
            $html .= '<div class="recommendations-card">';
            $html .= '<h3>💡 Рекомендации</h3>';
            $html .= '<ul class="recommendations-list">';
            foreach ($recommendations as $rec) {
                $html .= sprintf('<li>%s</li>', is_string($rec) ? $rec : '');
            }
            $html .= '</ul>';
            $html .= '</div>';
        }
        
        // Дисклеймер
        $html .= '<div class="disclaimer-card">';
        $html .= '<p><strong>Важно:</strong> Данный результат носит ознакомительный характер ' .
                 'и не является клиническим диагнозом. Шкала тревоги Бека — это скрининговый ' .
                 'инструмент. Для постановки диагноза и назначения лечения обратитесь к ' .
                 'квалифицированному специалисту (психологу, психотерапевту, психиатру).</p>';
        $html .= '</div>';
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsPairMode(): bool
    {
        return false;
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
        $filtered = array_filter($symptomScores, fn($s) => $s['score'] > 0);
        
        // Сортируем по убыванию баллов
        usort($filtered, fn($a, $b) => $b['score'] - $a['score']);
        
        return array_slice($filtered, 0, $limit);
    }
    
    /**
     * Получить интенсивность симптома (для CSS класса)
     */
    protected function getSymptomIntensity(int $score): string
    {
        if ($score >= 3) return 'high';
        if ($score >= 2) return 'moderate';
        if ($score >= 1) return 'low';
        return 'none';
    }
}
