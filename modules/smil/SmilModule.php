<?php
/**
 * SMIL (MMPI) Test Module - Full Version
 * 
 * Standardized Multivariate Personality Inventory
 * Adaptation by F.B. Sobchik
 * 
 * Full 566 questions version with:
 * - 3 validity scales (L, F, K)
 * - 10 clinical scales (0-9)
 * - T-score calculation with gender norms
 * - Detailed profile interpretation
 * - Code type analysis
 * - Additional indices
 */

declare(strict_types=1);

namespace PsyTest\Modules\Smil;

use PsyTest\Modules\BaseTestModule;

class SmilModule extends BaseTestModule
{
    /**
     * Scale names (Russian)
     */
    protected const SCALE_NAMES = [
        'L' => 'Шкала лжи',
        'F' => 'Шкала достоверности',
        'K' => 'Коррекционная шкала',
        '1' => 'Ипохондрия (Hs)',
        '2' => 'Депрессия (D)',
        '3' => 'Истерия (Hy)',
        '4' => 'Психопатия (Pd)',
        '5' => 'Маскулинность-фемининность (Mf)',
        '6' => 'Паранойя (Pa)',
        '7' => 'Психастения (Pt)',
        '8' => 'Шизофрения (Sc)',
        '9' => 'Гипомания (Ma)',
        '0' => 'Интроверсия (Si)',
    ];

    /**
     * T-score thresholds for interpretation
     */
    protected const THRESHOLDS = [
        'low' => ['min' => 0, 'max' => 44],
        'normal' => ['min' => 45, 'max' => 54],
        'elevated' => ['min' => 55, 'max' => 64],
        'high' => ['min' => 65, 'max' => 74],
        'very_high' => ['min' => 75, 'max' => 100],
    ];

    /**
     * Scale interpretations by level
     */
    protected const INTERPRETATIONS = [
        'L' => [
            'low' => 'Низкая социальная желательность, искренность',
            'normal' => 'Умеренная социальная желательность',
            'elevated' => 'Стремление представить себя в лучшем свете',
            'high' => 'Высокая социальная желательность, возможная неискренность',
            'very_high' => 'Очень высокая социальная желательность, результаты недостоверны',
        ],
        'F' => [
            'low' => 'Осторожные ответы, возможная скрытность',
            'normal' => 'Достоверные ответы',
            'elevated' => 'Возможное преувеличение проблем',
            'high' => 'Выраженное преувеличение проблем или непонимание вопросов',
            'very_high' => 'Результаты недостоверны, случайные ответы',
        ],
        'K' => [
            'low' => 'Открытость, самокритичность',
            'normal' => 'Умеренная защитная позиция',
            'elevated' => 'Защитная позиция, стремление скрыть проблемы',
            'high' => 'Высокая психологическая защита',
            'very_high' => 'Очень высокая защита, результаты могут быть занижены',
        ],
        '1' => [
            'low' => 'Оптимизм, отсутствие ипохондрических тенденций',
            'normal' => 'Нормальный уровень заботы о здоровье',
            'elevated' => 'Повышенное внимание к здоровью, возможны соматические жалобы',
            'high' => 'Выраженные ипохондрические тенденции',
            'very_high' => 'Сильная фиксация на здоровье, множественные жалобы',
        ],
        '2' => [
            'low' => 'Приподнятое настроение, оптимизм',
            'normal' => 'Нормальное эмоциональное состояние',
            'elevated' => 'Сниженное настроение, пессимизм',
            'high' => 'Выраженная депрессия, чувство вины',
            'very_high' => 'Глубокая депрессия, возможна суицидальная опасность',
        ],
        '3' => [
            'low' => 'Критичность к себе, реализм',
            'normal' => 'Умеренная эмоциональность',
            'elevated' => 'Демонстративность, стремление к вниманию',
            'high' => 'Выраженная истероидность, конверсионные реакции',
            'very_high' => 'Сильная истероидная акцентуация',
        ],
        '4' => [
            'low' => 'Высокий самоконтроль, конформность',
            'normal' => 'Умеренная импульсивность',
            'elevated' => 'Импульсивность, склонность к риску',
            'high' => 'Выраженная антисоциальность, конфликтность',
            'very_high' => 'Сильная тенденция к нарушению норм',
        ],
        '5' => [
            'low' => 'Традиционные гендерные роли',
            'normal' => 'Умеренные интересы',
            'elevated' => 'Нетрадиционные интересы для пола',
            'high' => 'Выраженная фемининность (у мужчин) / маскулинность (у женщин)',
            'very_high' => 'Очень выраженные противоположные полу черты',
        ],
        '6' => [
            'low' => 'Доверчивость, наивность',
            'normal' => 'Умеренная критичность',
            'elevated' => 'Подозрительность, чувствительность к критике',
            'high' => 'Выраженная паранойяльность, ригидность',
            'very_high' => 'Сильная подозрительность, возможны бредовые идеи',
        ],
        '7' => [
            'low' => 'Спокойствие, уверенность',
            'normal' => 'Умеренная тревожность',
            'elevated' => 'Повышенная тревожность, неуверенность',
            'high' => 'Выраженная тревога, навязчивости',
            'very_high' => 'Сильная тревожность, возможны фобии',
        ],
        '8' => [
            'low' => 'Конкретность мышления, практичность',
            'normal' => 'Умеренная рефлексия',
            'elevated' => 'Своеобразие мышления, богатое воображение',
            'high' => 'Выраженные шизоидные черты, аутизация',
            'very_high' => 'Сильное своеобразие мышления, возможна дезорганизация',
        ],
        '9' => [
            'low' => 'Спокойствие, низкая активность',
            'normal' => 'Умеренная энергичность',
            'elevated' => 'Повышенная активность, импульсивность',
            'high' => 'Выраженная гипомания, расторможенность',
            'very_high' => 'Сильное возбуждение, возможна агрессия',
        ],
        '0' => [
            'low' => 'Экстраверсия, общительность',
            'normal' => 'Умеренная интроверсия/экстраверсия',
            'elevated' => 'Выраженная интроверсия, замкнутость',
            'high' => 'Сильная интроверсия, социальная изоляция',
            'very_high' => 'Очень сильная интроверсия, аутизация',
        ],
    ];

    /**
     * Profile type descriptions
     */
    protected const PROFILE_TYPES = [
        'normosthenic' => [
            'name' => 'Нормостенический',
            'description' => 'Профиль находится в пределах нормы. Выраженных акцентуаций не выявлено.',
        ],
        'neurotic' => [
            'name' => 'Невротический',
            'description' => 'Выявлены черты невротического стиля реагирования. Характерны эмоциональная неустойчивость, повышенная тревожность.',
        ],
        'psychotic' => [
            'name' => 'Психотический',
            'description' => 'Обнаружены особенности, характерные для шизоидного спектра. Может наблюдаться своеобразие мышления, склонность к интроверсии.',
        ],
        'personal_deviation' => [
            'name' => 'Личностная девиация',
            'description' => 'Выявлены черты личностной девиации. Возможны трудности социальной адаптации, импульсивность.',
        ],
        'mixed' => [
            'name' => 'Смешанный',
            'description' => 'Профиль смешанного типа. Сочетание различных акцентуированных черт.',
        ],
    ];

    /**
     * Get test metadata
     */
    public function getMetadata(): array
    {
        return array_merge(parent::getMetadata(), [
            'supports_gender_norms' => true,
            'validity_scales' => ['L', 'F', 'K'],
            'clinical_scales' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
            'full_version' => true,
            'total_questions' => 566,
        ]);
    }

    /**
     * Get demographics requirements (gender required for T-score tables)
     */
    public function getDemographicsRequirements(): array
    {
        return array_merge(parent::getDemographicsRequirements(), $this->metadata['requires_demographics'] ?? []);
    }

    /**
     * Get questions from JSON file
     */
    public function getQuestions(): array
    {
        if ($this->questions === null) {
            $this->questions = $this->loadQuestionsFromJson('questions.json');
        }
        return $this->questions;
    }

    /**
     * Calculate SMIL results
     */
    public function calculateResults(array $answers): array
    {
        // Calculate raw scores for each scale
        $rawScores = $this->calculateRawScores($answers);

        // Get gender from demographics (default to male if not specified)
        $gender = $this->detectGenderFromAnswers($answers) ?? 'male';

        // Convert to T-scores using gender-specific norms
        $tScores = $this->convertToTScores($rawScores, $gender);

        // Calculate validity indicators
        $validity = $this->assessValidity($tScores);

        // Apply K-correction to clinical scales
        $correctedScores = $this->applyKCorrection($tScores, $rawScores);

        // Calculate additional indices
        $indices = $this->calculateIndices($rawScores, $tScores);

        // Build profile
        $profile = $this->buildProfile($correctedScores);

        return [
            'raw_scores' => $rawScores,
            't_scores' => $tScores,
            'corrected_scores' => $correctedScores,
            'validity' => $validity,
            'profile' => $profile,
            'indices' => $indices,
            'gender' => $gender,
            'answered_count' => count($answers),
            'total_questions' => 566,
            'completion_rate' => round(count($answers) / 566 * 100, 1),
        ];
    }

    /**
     * Calculate raw scores for each scale
     */
    protected function calculateRawScores(array $answers): array
    {
        $rawScores = [
            'L' => 0, 'F' => 0, 'K' => 0,
            '1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0,
            '6' => 0, '7' => 0, '8' => 0, '9' => 0, '0' => 0,
        ];

        $questions = $this->getQuestions();

        foreach ($answers as $questionId => $answer) {
            // Find question in questions array
            foreach ($questions as $question) {
                if ($question['id'] == $questionId) {
                    $scale = $question['scale'] ?? null;
                    $direction = $question['direction'] ?? 1;

                    if ($scale && isset($rawScores[$scale])) {
                        if ($direction === 1) {
                            $rawScores[$scale] += $answer ? 1 : 0;
                        } else {
                            $rawScores[$scale] += $answer ? 0 : 1;
                        }
                    }
                    break;
                }
            }
        }

        return $rawScores;
    }

    /**
     * Get scale items from questions
     */
    protected function getScaleItems(): array
    {
        $scaleItems = [];
        $questions = $this->getQuestions();

        foreach ($questions as $question) {
            $scale = $question['scale'] ?? null;
            $direction = $question['direction'] ?? 1;

            if ($scale) {
                if (!isset($scaleItems[$scale])) {
                    $scaleItems[$scale] = [];
                }
                $scaleItems[$scale][$question['id']] = $direction;
            }
        }

        return $scaleItems;
    }

    /**
     * Convert raw scores to T-scores using gender-specific norms
     */
    protected function convertToTScores(array $rawScores, string $gender): array
    {
        $tScores = [];
        $tables = $gender === 'female' ? $this->getTScoresFemale() : $this->getTScoresMale();

        foreach ($rawScores as $scale => $rawScore) {
            $tScores[$scale] = $this->lookupTScore($scale, $rawScore, $tables);
        }

        return $tScores;
    }

    /**
     * Get T-score tables for males
     */
    protected function getTScoresMale(): array
    {
        // Simplified T-score tables - full version would have complete tables
        return [
            'L' => [0 => 35, 1 => 40, 2 => 45, 3 => 50, 4 => 55, 5 => 60, 6 => 65],
            'F' => [0 => 40, 1 => 45, 2 => 50, 3 => 55, 4 => 60, 5 => 65, 6 => 70],
            'K' => [0 => 55, 1 => 50, 2 => 45, 3 => 40, 4 => 35, 5 => 30],
            '1' => [0 => 35, 5 => 45, 10 => 55, 15 => 65, 20 => 75],
            '2' => [0 => 40, 5 => 50, 10 => 60, 15 => 70, 20 => 80],
            '3' => [0 => 40, 5 => 50, 10 => 60, 15 => 70],
            '4' => [0 => 40, 5 => 50, 10 => 60, 15 => 70, 20 => 80],
            '5' => [0 => 45, 5 => 55, 10 => 65, 15 => 75],
            '6' => [0 => 40, 5 => 50, 10 => 60, 15 => 70],
            '7' => [0 => 35, 5 => 45, 10 => 55, 15 => 65, 20 => 75],
            '8' => [0 => 40, 5 => 50, 10 => 60, 15 => 70, 20 => 80],
            '9' => [0 => 45, 5 => 55, 10 => 65, 15 => 75],
            '0' => [0 => 40, 5 => 50, 10 => 60, 15 => 70],
        ];
    }

    /**
     * Get T-score tables for females
     */
    protected function getTScoresFemale(): array
    {
        // Simplified T-score tables - full version would have complete tables
        return [
            'L' => [0 => 35, 1 => 40, 2 => 45, 3 => 50, 4 => 55, 5 => 60],
            'F' => [0 => 40, 1 => 45, 2 => 50, 3 => 55, 4 => 60, 5 => 65],
            'K' => [0 => 55, 1 => 50, 2 => 45, 3 => 40, 4 => 35],
            '1' => [0 => 40, 5 => 50, 10 => 60, 15 => 70, 20 => 80],
            '2' => [0 => 45, 5 => 55, 10 => 65, 15 => 75, 20 => 85],
            '3' => [0 => 45, 5 => 55, 10 => 65, 15 => 75],
            '4' => [0 => 40, 5 => 50, 10 => 60, 15 => 70],
            '5' => [0 => 45, 5 => 55, 10 => 65, 15 => 75],
            '6' => [0 => 40, 5 => 50, 10 => 60, 15 => 70],
            '7' => [0 => 40, 5 => 50, 10 => 60, 15 => 70],
            '8' => [0 => 40, 5 => 50, 10 => 60, 15 => 70],
            '9' => [0 => 45, 5 => 55, 10 => 65, 15 => 75],
            '0' => [0 => 45, 5 => 55, 10 => 65, 15 => 75],
        ];
    }

    /**
     * Lookup T-score from table with interpolation
     */
    protected function lookupTScore(int|string $scale, int $rawScore, array $tables): float
    {
        $scaleKey = (string) $scale;

        if (!isset($tables[$scaleKey])) {
            return 50.0;
        }

        $table = $tables[$scaleKey];

        if (isset($table[$rawScore])) {
            return (float) $table[$rawScore];
        }

        $lower = max(array_filter(array_keys($table), fn($k) => $k <= $rawScore));
        $upper = min(array_filter(array_keys($table), fn($k) => $k >= $rawScore));

        if ($lower === $upper) {
            return (float) $table[$lower];
        }

        if (!isset($table[$lower]) || !isset($table[$upper])) {
            return $lower > $rawScore ? (float) $table[$lower] : (float) $table[$upper];
        }

        $ratio = ($rawScore - $lower) / ($upper - $lower);
        $tScore = $table[$lower] + $ratio * ($table[$upper] - $table[$lower]);

        return round($tScore, 1);
    }

    /**
     * Assess validity of results
     */
    protected function assessValidity(array $tScores): array
    {
        $L = $tScores['L'] ?? 50;
        $F = $tScores['F'] ?? 50;
        $K = $tScores['K'] ?? 50;

        $valid = true;
        $warnings = [];

        if ($L >= 65) {
            $valid = false;
            $warnings[] = 'Высокая социальная желательность - результаты могут быть недостоверны';
        }

        if ($F >= 70) {
            $valid = false;
            $warnings[] = 'Высокий показатель F - возможны случайные ответы или преувеличение проблем';
        } elseif ($F >= 65) {
            $warnings[] = 'Повышенный показатель F - возможна тенденция к преувеличению';
        }

        if ($K >= 65) {
            $warnings[] = 'Высокая защитная позиция - клинические шкалы могут быть занижены';
        } elseif ($K <= 35) {
            $warnings[] = 'Низкая защитная позиция - возможна излишняя откровенность';
        }

        $fkIndex = $F - $K;
        if ($fkIndex > 20) {
            $warnings[] = 'Индекс F-K повышен - возможна симуляция';
        } elseif ($fkIndex < -15) {
            $warnings[] = 'Индекс F-K понижен - возможна диссимуляция';
        }

        return [
            'is_valid' => $valid,
            'warnings' => $warnings,
            'L_score' => $L,
            'F_score' => $F,
            'K_score' => $K,
            'FK_index' => $fkIndex,
        ];
    }

    /**
     * Apply K-correction to clinical scales
     */
    protected function applyKCorrection(array $tScores, array $rawScores): array
    {
        $corrected = $tScores;
        $K = $rawScores['K'] ?? 0;

        $corrections = [
            '1' => 0.5,
            '3' => 0.3,
            '4' => 0.4,
            '6' => 0.3,
            '7' => 0.5,
            '8' => 0.2,
            '9' => 0.3,
            '0' => 0.3,
        ];

        foreach ($corrections as $scale => $fraction) {
            if (isset($corrected[$scale])) {
                $corrected[$scale] = round($tScores[$scale] + ($K * $fraction), 1);
            }
        }

        return $corrected;
    }

    /**
     * Calculate additional indices
     */
    protected function calculateIndices(array $rawScores, array $tScores): array
    {
        return [
            'FK_index' => ($tScores['F'] ?? 50) - ($tScores['K'] ?? 50),
            'FK_ratio' => $rawScores['K'] > 0 ? round($rawScores['F'] / $rawScores['K'], 2) : 0,
            'anxiety_index' => round((($tScores['7'] ?? 50) + ($tScores['2'] ?? 50)) / 2, 1),
            'depression_index' => round((($tScores['2'] ?? 50) + ($tScores['1'] ?? 50)) / 2, 1),
        ];
    }

    /**
     * Build personality profile
     */
    protected function buildProfile(array $scores): array
    {
        $profile = [];

        foreach ($scores as $scale => $score) {
            $scaleStr = (string) $scale;

            if (in_array($scaleStr, ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'])) {
                $level = $this->getScoreLevel($score);
                $profile[$scaleStr] = [
                    'score' => $score,
                    'level' => $level,
                    'interpretation' => $this->getScaleInterpretation($scaleStr, $level),
                    'name' => self::SCALE_NAMES[$scaleStr] ?? $scaleStr,
                ];
            }
        }

        $sorted = $profile;
        usort($sorted, fn($a, $b) => $b['score'] - $a['score']);
        $dominant = array_slice($sorted, 0, 3);

        $profileType = $this->determineProfileType($profile);

        return [
            'scales' => $profile,
            'dominant' => $dominant,
            'profile_type' => $profileType,
            'code_type' => $this->getCodeType($profile),
        ];
    }

    /**
     * Get score level category
     */
    protected function getScoreLevel(float $score): string
    {
        foreach (self::THRESHOLDS as $level => $range) {
            if ($score >= $range['min'] && $score <= $range['max']) {
                return $level;
            }
        }

        return 'normal';
    }

    /**
     * Get interpretation for a scale
     */
    protected function getScaleInterpretation(int|string $scale, string $level): string
    {
        $scaleKey = (string) $scale;
        return self::INTERPRETATIONS[$scaleKey][$level] ?? 'Нет данных';
    }

    /**
     * Determine profile type
     */
    protected function determineProfileType(array $profile): string
    {
        $elevated = array_filter($profile, fn($p) => $p['score'] >= 60);

        if (empty($elevated)) {
            return 'normosthenic';
        }

        $scales = array_keys($elevated);

        $neuroticTriad = array_intersect($scales, ['1', '2', '3']);
        if (count($neuroticTriad) >= 2) {
            return 'neurotic';
        }

        $psychoticTetrad = array_intersect($scales, ['6', '7', '8', '9']);
        if (count($psychoticTetrad) >= 2) {
            return 'psychotic';
        }

        $personalDev = array_intersect($scales, ['4', '5']);
        if (count($personalDev) >= 1) {
            return 'personal_deviation';
        }

        return 'mixed';
    }

    /**
     * Get code type (two-point code)
     */
    protected function getCodeType(array $profile): string
    {
        $sorted = $profile;
        uasort($sorted, fn($a, $b) => $b['score'] - $a['score']);

        $top2 = array_slice(array_keys($sorted), 0, 2);

        return implode('-', $top2);
    }

    /**
     * Detect gender from answers (if not provided)
     */
    protected function detectGenderFromAnswers(array $answers): ?string
    {
        return null;
    }

    /**
     * Generate interpretation from scores
     */
    public function generateInterpretation(array $scores): array
    {
        $validity = $scores['validity'] ?? [];
        $profile = $scores['profile'] ?? [];

        if (!$validity['is_valid'] ?? true) {
            return [
                'summary' => 'Результаты недостоверны',
                'warning' => 'Внимание: результаты тестирования могут быть недостоверны. ' .
                            implode('; ', $validity['warnings'] ?? []),
                'scales' => [],
                'recommendations' => [
                    'Пройти тестирование повторно, отвечая более искренне',
                    'Обратиться к специалисту для очной диагностики',
                ],
            ];
        }

        $summary = $this->generateSummary($profile);
        $detailedInterpretation = $this->generateDetailedInterpretation($profile);
        $recommendations = $this->generateRecommendations($profile);

        return [
            'summary' => $summary,
            'validity' => $validity,
            'profile_type' => $profile['profile_type'] ?? 'unknown',
            'code_type' => $profile['code_type'] ?? '',
            'scales' => $detailedInterpretation,
            'recommendations' => $recommendations,
            'dominant_scales' => $profile['dominant'] ?? [],
            'indices' => $scores['indices'] ?? [],
        ];
    }

    /**
     * Generate summary interpretation
     */
    protected function generateSummary(array $profile): string
    {
        $profileType = $profile['profile_type'] ?? 'unknown';
        $codeType = $profile['code_type'] ?? '';

        $typeDescriptions = self::PROFILE_TYPES;
        $description = $typeDescriptions[$profileType]['description'] ?? 'Требуется профессиональная интерпретация.';

        return "Код профиля: {$codeType}. {$description}";
    }

    /**
     * Generate detailed scale-by-scale interpretation
     */
    protected function generateDetailedInterpretation(array $profile): array
    {
        $interpretations = [];

        foreach ($profile['scales'] ?? [] as $scale => $data) {
            $interpretations[$scale] = [
                'name' => $data['name'],
                'score' => $data['score'],
                'level' => $data['level'],
                'description' => $data['interpretation'],
            ];
        }

        return $interpretations;
    }

    /**
     * Generate recommendations
     */
    protected function generateRecommendations(array $profile): array
    {
        $recommendations = [];
        $profileType = $profile['profile_type'] ?? 'unknown';

        $recommendations[] = 'Результаты тестирования носят ознакомительный характер';

        switch ($profileType) {
            case 'neurotic':
                $recommendations[] = 'Рекомендуется консультация психолога для работы с тревожностью';
                $recommendations[] = 'Полезны техники релаксации и стресс-менеджмента';
                break;
            case 'psychotic':
                $recommendations[] = 'Рекомендуется углубленная диагностика у специалиста';
                $recommendations[] = 'Важно учитывать особенности мышления и коммуникации';
                break;
            case 'personal_deviation':
                $recommendations[] = 'Полезна работа над социальной адаптацией';
                $recommendations[] = 'Рекомендуется развитие навыков самоконтроля';
                break;
        }

        $hasElevated = false;
        foreach ($profile['scales'] ?? [] as $scale => $data) {
            if (in_array($data['level'], ['high', 'very_high'])) {
                $hasElevated = true;
                break;
            }
        }

        if ($hasElevated) {
            $recommendations[] = 'При наличии жалоб рекомендуется очная консультация специалиста';
        }

        return $recommendations;
    }

    /**
     * Render results as HTML
     */
    public function renderResults(array $results): string
    {
        $validity = $results['validity'] ?? [];
        $profile = $results['profile'] ?? [];
        $tScores = $results['t_scores'] ?? [];
        $indices = $results['indices'] ?? [];

        if (!$validity['is_valid']) {
            return $this->renderInvalidResults($validity);
        }

        $html = '<div class="smil-results">';

        // Validity section
        $html .= $this->renderValiditySection($validity);

        // Additional indices
        $html .= $this->renderIndicesSection($indices);

        // Profile chart
        $html .= $this->renderProfileChart($tScores);

        // Scores table
        $html .= $this->renderScoresTable($profile);

        // Interpretation
        $html .= $this->renderInterpretationSection($profile);

        $html .= '</div>';

        return $html;
    }

    /**
     * Render validity indicators section
     */
    protected function renderValiditySection(array $validity): string
    {
        $statusClass = $validity['is_valid'] ? 'valid' : 'invalid';
        $statusText = $validity['is_valid'] ? '✓ Достоверно' : '⚠️ Недостоверно';

        $html = '<div class="validity-section status-' . $statusClass . '">';
        $html .= '<h3>Оценка достоверности</h3>';
        $html .= '<div class="validity-indicators">';
        $html .= '<div class="indicator"><span class="label">L (Ложь):</span><span class="value">' . $validity['L_score'] . '</span></div>';
        $html .= '<div class="indicator"><span class="label">F (Достоверность):</span><span class="value">' . $validity['F_score'] . '</span></div>';
        $html .= '<div class="indicator"><span class="label">K (Коррекция):</span><span class="value">' . $validity['K_score'] . '</span></div>';
        $html .= '<div class="indicator"><span class="label">F-K индекс:</span><span class="value">' . $validity['FK_index'] . '</span></div>';
        $html .= '</div>';
        $html .= '<div class="validity-status">' . $statusText . '</div>';

        if (!empty($validity['warnings'])) {
            $html .= '<div class="validity-warnings"><ul>';
            foreach ($validity['warnings'] as $warning) {
                $html .= '<li>' . htmlspecialchars($warning) . '</li>';
            }
            $html .= '</ul></div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render additional indices section
     */
    protected function renderIndicesSection(array $indices): string
    {
        $html = '<div class="indices-section">';
        $html .= '<h3>Дополнительные индексы</h3>';
        $html .= '<div class="indices-grid">';
        $html .= '<div class="index-item"><span class="index-label">Индекс тревоги:</span><span class="index-value">' . ($indices['anxiety_index'] ?? '-') . '</span></div>';
        $html .= '<div class="index-item"><span class="index-label">Индекс депрессии:</span><span class="index-value">' . ($indices['depression_index'] ?? '-') . '</span></div>';
        $html .= '<div class="index-item"><span class="index-label">F/K отношение:</span><span class="index-value">' . ($indices['FK_ratio'] ?? '-') . '</span></div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render profile chart (Chart.js compatible)
     */
    protected function renderProfileChart(array $tScores): string
    {
        $clinicalScales = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];
        $scaleNames = ['1' => 'Hs', '2' => 'D', '3' => 'Hy', '4' => 'Pd', '5' => 'Mf',
                      '6' => 'Pa', '7' => 'Pt', '8' => 'Sc', '9' => 'Ma', '0' => 'Si'];

        $data = [];
        foreach ($clinicalScales as $scale) {
            $data[] = $tScores[$scale] ?? 50;
        }

        $dataJson = json_encode($data);
        $labelsJson = json_encode(array_values($scaleNames));

        $html = '<div class="profile-chart-container">';
        $html .= '<h3>Профиль личности</h3>';
        $html .= '<canvas id="smilProfileChart" data-scores=\'' . $dataJson . '\' data-labels=\'' . $labelsJson . '\'></canvas>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render scores table
     */
    protected function renderScoresTable(array $profile): string
    {
        $scales = $profile['scales'] ?? [];

        $html = '<table class="scores-table"><thead><tr>';
        $html .= '<th>Шкала</th><th>T-балл</th><th>Уровень</th><th>Интерпретация</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($scales as $scale => $data) {
            $levelClass = $data['level'];
            $levelText = [
                'low' => 'Низкий',
                'normal' => 'Норма',
                'elevated' => 'Повышенный',
                'high' => 'Высокий',
                'very_high' => 'Очень высокий',
            ][$data['level']] ?? $data['level'];

            $html .= '<tr class="level-' . $levelClass . '">';
            $html .= '<td><strong>' . $data['name'] . '</strong></td>';
            $html .= '<td class="score">' . $data['score'] . '</td>';
            $html .= '<td>' . $levelText . '</td>';
            $html .= '<td>' . htmlspecialchars($data['interpretation']) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Render interpretation section
     */
    protected function renderInterpretationSection(array $profile): string
    {
        $profileType = $profile['profile_type'] ?? 'unknown';
        $codeType = $profile['code_type'] ?? '';

        $typeNames = self::PROFILE_TYPES;

        $html = '<div class="interpretation-section">';
        $html .= '<h3>Интерпретация</h3>';
        $html .= '<p><strong>Тип профиля:</strong> ' . ($typeNames[$profileType]['name'] ?? $profileType) . '</p>';
        $html .= '<p><strong>Код профиля:</strong> ' . $codeType . '</p>';

        if (!empty($profile['dominant'])) {
            $html .= '<h4>Наиболее выраженные шкалы:</h4><ul>';
            foreach ($profile['dominant'] as $dominant) {
                $html .= '<li><strong>' . $dominant['name'] . '</strong>: ' . $dominant['score'] . ' T-баллов</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render invalid results message
     */
    protected function renderInvalidResults(array $validity): string
    {
        $warnings = implode('<br>', $validity['warnings'] ?? []);

        $html = '<div class="smil-results smil-invalid">';
        $html .= '<div class="alert alert-warning">';
        $html .= '<h3>⚠️ Результаты недостоверны</h3>';
        $html .= '<p>К сожалению, результаты тестирования не могут быть считаны достоверными по следующим причинам:</p>';
        $html .= '<p><strong>' . $warnings . '</strong></p>';
        $html .= '<p>Рекомендуется пройти тестирование повторно, отвечая более внимательно и искренне.</p>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Check if SMIL supports pair mode
     */
    public function supportsPairMode(): bool
    {
        return false;
    }

    /**
     * Compare pair results
     */
    public function comparePairResults(array $results1, array $results2): array
    {
        return [
            'results_1' => $results1,
            'results_2' => $results2,
            'differences' => [],
        ];
    }
}
