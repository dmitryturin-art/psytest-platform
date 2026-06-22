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
use PsyTest\Modules\ResultSection;

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
     * Answer values
     */
    protected const ANSWER_YES = 1;
    protected const ANSWER_UNKNOWN = 2;  // "Не знаю" / "?"
    protected const ANSWER_NO = 0;

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
            'additional_scales_count' => 200,
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
            $this->questions = $this->loadQuestionsFromJson('questions-566-full.json');
        }
        return $this->questions;
    }

    /**
     * Get questions with gender-specific text
     *
     * @param string|null $gender Gender ('male' or 'female'), if null returns raw questions
     * @return array Questions with appropriate text for gender
     */
    public function getQuestionsForGender(?string $gender = null): array
    {
        $questions = $this->getQuestions();
        
        // If no gender specified or questions don't have gender variants, return as is
        if ($gender === null) {
            return $questions;
        }
        
        // Map questions to use gender-specific text
        $genderedQuestions = [];
        foreach ($questions as $question) {
            $genderedQuestion = $question;
            
            // Check if question has gender variants
            if (isset($question['text_male']) && isset($question['text_female'])) {
                // Use gender-specific text
                if ($gender === 'male') {
                    $genderedQuestion['text'] = $question['text_male'];
                } else {
                    $genderedQuestion['text'] = $question['text_female'];
                }
            } elseif (!isset($question['text'])) {
                // Fallback: if no 'text' field, use text_male as default
                $genderedQuestion['text'] = $question['text_male'] ?? $question['text_female'] ?? '';
            }
            
            $genderedQuestions[] = $genderedQuestion;
        }
        
        return $genderedQuestions;
    }

    /**
     * Load additional scales from JSON
     */
    protected function loadAdditionalScales(): array
    {
        $filepath = $this->modulePath . '/additional-scales.json';
        if (!file_exists($filepath)) {
            return [];
        }
        $content = file_get_contents($filepath);
        $data = json_decode($content, true) ?? [];
        return $data['scales'] ?? [];
    }

    /**
     * Load interpretations from JSON
     */
    protected function loadInterpretations(): array
    {
        $filepath = $this->modulePath . '/interpretations.json';
        if (!file_exists($filepath)) {
            return [];
        }
        $content = file_get_contents($filepath);
        return json_decode($content, true) ?? [];
    }

    /**
     * Load T-score tables from JSON
     */
    protected function loadTScoreTables(): array
    {
        $filepath = $this->modulePath . '/t-score-tables.json';
        if (!file_exists($filepath)) {
            return [];
        }
        $content = file_get_contents($filepath);
        return json_decode($content, true) ?? [];
    }

    /**
     * Calculate SMIL results - Full version with all scales
     */
    public function calculateResults(array $answers): array
    {
        // Calculate raw scores for basic scales
        $rawScores = $this->calculateRawScores($answers);

        // Get gender from demographics
        $gender = $answers['gender'] ?? 'male';

        // Convert to T-scores using gender-specific norms
        $tScores = $this->convertToTScores($rawScores, $gender);

        // Apply K-correction to clinical scales (already applied in convertToTScores)
        $correctedScores = $tScores;

        // Calculate additional scales
        $additionalScores = $this->calculateAdditionalScales($answers, $gender);

        // Calculate validity indicators (including "?" scale)
        $validity = $this->assessValidity($tScores, $answers);

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
            'additional_scores' => $additionalScores,
            'gender' => $gender,
            'answered_count' => count(array_filter($answers, fn($k) => is_numeric($k), ARRAY_FILTER_USE_KEY)),
            'total_questions' => 566,
            'completion_rate' => round(count(array_filter($answers, fn($k) => is_numeric($k), ARRAY_FILTER_USE_KEY)) / 566 * 100, 1),
        ];
    }

    /**
     * Load additional scales norms from JSON
     */
    protected function loadAdditionalScalesNorms(): array
    {
        $filepath = $this->modulePath . '/additional-scales-norms.json';
        if (!file_exists($filepath)) {
            return [];
        }
        $content = file_get_contents($filepath);
        $data = json_decode($content, true) ?? [];
        
        // Extract scales from the data structure
        return $data['scales'] ?? [];
    }

    /**
     * Calculate additional scales raw scores and T-scores
     *
     * Answer values:
     * - 1 = "Верно" (true)
     * - 0 = "Неверно" (false)
     * - 2 = "Не знаю" (unknown) - not counted for scale calculations
     */
    protected function calculateAdditionalScales(array $answers, string $gender): array
    {
        $normsData = $this->loadAdditionalScalesNorms();
        $results = [];
        
        foreach ($normsData as $category => $scales) {
            foreach ($scales as $code => $info) {
                if (!isset($info['key']) || !isset($info['norms'])) {
                    continue;
                }
                
                // Calculate raw score
                $rawScore = 0;
                $key = $info['key'];
                
                foreach ($key['true'] ?? [] as $questionId) {
                    // Check if answer is truthy (1, true, 'true', '1')
                    // Skip "не знаю" answers (value 2)
                    if (isset($answers[$questionId])) {
                        $answer = $answers[$questionId];
                        if ($answer === 1 || $answer === '1' || $answer === true || $answer === 'true') {
                            $rawScore++;
                        }
                    }
                }
                
                foreach ($key['false'] ?? [] as $questionId) {
                    // Check if answer is falsy (0, false, '', null)
                    // Skip "не знаю" answers (value 2)
                    if (isset($answers[$questionId])) {
                        $answer = $answers[$questionId];
                        if ($answer === 0 || $answer === '0' || $answer === false || $answer === 'false') {
                            $rawScore++;
                        }
                    }
                }
                
                // Get norms for gender
                $norms = $info['norms'][$gender] ?? $info['norms']['male'] ?? [];
                $M = $norms['M'] ?? 0;
                $delta = $norms['delta'] ?? 1;
                
                // Calculate T-score: T = 50 + 10 × (X - M) / δ
                if ($delta == 0) {
                    $tScore = 50;
                } else {
                    $tScore = round(50 + 10 * ($rawScore - $M) / $delta);
                }
                
                // Clamp to valid range (20-120 for MMPI/SMIL)
                $tScore = max(20, min(120, $tScore));
                
                $results[$code] = [
                    'name' => $info['name'] ?? $code,
                    'raw' => $rawScore,
                    't' => $tScore,
                    'M' => $M,
                    'delta' => $delta,
                    'interpretation' => $this->getAdditionalScaleInterpretation($code, $tScore, $category),
                ];
            }
        }
        
        return $results;
    }

    /**
     * Get interpretation for additional scale
     */
    protected function getAdditionalScaleInterpretation(string $code, float $tScore, string $category = ''): string
    {
        $interpretations = $this->loadInterpretations();
        $level = $this->getScoreLevel($tScore);
        
        // Try to find interpretation in additional_scales section
        if (isset($interpretations['additional_scales'][$category][$code]['levels'][$level])) {
            return $interpretations['additional_scales'][$category][$code]['levels'][$level];
        }
        
        // Fallback: try to find in any category
        if (isset($interpretations['additional_scales'])) {
            foreach ($interpretations['additional_scales'] as $cat => $scales) {
                if (isset($scales[$code]['levels'][$level])) {
                    return $scales[$code]['levels'][$level];
                }
            }
        }
        
        return 'Интерпретация отсутствует';
    }

    /**
     * Calculate raw scores for each scale
     *
     * Answer values:
     * - 1 = "Верно" (true)
     * - 0 = "Неверно" (false)
     * - 2 = "Не знаю" (unknown) - not counted for most scales, but affects validity
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
            // Skip "не знаю" answers (value 2) for scale calculations
            // They are tracked separately for validity assessment
            if ($answer === 2 || $answer === '2') {
                continue;
            }
            
            // Convert answer to boolean for backward compatibility
            $answerBool = ($answer === 1 || $answer === '1' || $answer === true || $answer === 'true');
            
            // Find question in questions array
            foreach ($questions as $question) {
                if ($question['id'] == $questionId) {
                    $scale = $question['scale'] ?? null;
                    $direction = $question['direction'] ?? 1;

                    if ($scale && isset($rawScores[$scale])) {
                        if ($direction === 1) {
                            $rawScores[$scale] += $answerBool ? 1 : 0;
                        } else {
                            $rawScores[$scale] += $answerBool ? 0 : 1;
                        }
                    }
                    break;
                }
            }
        }

        return $rawScores;
    }

    /**
     * Calculate "?" scale (unknown/unanswered questions)
     *
     * According to Sobchik methodology:
     * - ≤ 40: acceptable
     * - 41-60: caution, guardedness
     * - 61-70: questionable validity
     * - > 70: protocol invalid
     *
     * @param array $answers User answers
     * @return int Count of "не знаю" answers
     */
    protected function calculateUnknownScale(array $answers): int
    {
        $unknownCount = 0;
        
        // Count answers with value 2 ("не знаю")
        foreach ($answers as $questionId => $answer) {
            // Skip non-numeric keys (like 'gender', 'age', etc.)
            if (!is_numeric($questionId)) {
                continue;
            }
            
            // Check if answer is "не знаю"
            if ($answer === self::ANSWER_UNKNOWN || $answer === (string)self::ANSWER_UNKNOWN) {
                $unknownCount++;
            }
        }
        
        return $unknownCount;
    }

    /**
     * Calculate control questions scale (QC)
     *
     * 27 control questions with instruction "Обведите номер данного утверждения кружочком"
     * Correct answer: "Да" (1)
     *
     * According to Sobchik methodology:
     * - < 20: protocol invalid (low attention)
     * - ≥ 20: acceptable
     *
     * @param array $answers User answers
     * @return int Count of correct answers (0-27)
     */
    protected function calculateControlScale(array $answers): int
    {
        // 27 control questions
        $controlQuestions = [
            14, 33, 48, 63, 66, 69, 121, 123, 133, 151,
            168, 182, 184, 197, 200, 205, 266, 275, 293,
            334, 349, 350, 462, 464, 474, 542, 551
        ];
        
        $correctCount = 0;
        foreach ($controlQuestions as $qNum) {
            // Правильный ответ на контрольный вопрос - "Да" (1)
            if (isset($answers[$qNum]) && ($answers[$qNum] == self::ANSWER_YES || $answers[$qNum] === 1 || $answers[$qNum] === '1')) {
                $correctCount++;
            }
        }
        
        return $correctCount;
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
     * Load basic scales norms from JSON
     */
    protected function loadBasicScalesNorms(): array
    {
        $filepath = $this->modulePath . '/basic_scales_norms.json';
        if (!file_exists($filepath)) {
            return [];
        }
        $content = file_get_contents($filepath);
        $data = json_decode($content, true) ?? [];
        return $data['scales'] ?? [];
    }

    /**
     * Convert raw scores to T-scores using Sobchik formula
     * T = 50 + 10 × (X - M) / δ
     */
    protected function convertToTScores(array $rawScores, string $gender): array
    {
        $norms = $this->loadBasicScalesNorms();
        $tScores = [];
        
        foreach ($rawScores as $scale => $rawScore) {
            if (!isset($norms[$scale])) {
                $tScores[$scale] = 50.0;
                continue;
            }
            
            $scaleNorms = $norms[$scale][$gender] ?? $norms[$scale]['male'];
            $M = $scaleNorms['M'];
            $delta = $scaleNorms['delta'];
            
            // Apply K-correction if needed
            $kFactor = $norms[$scale]['kCorrectionFactor'] ?? null;
            $correctedRaw = $rawScore;
            
            if ($kFactor !== null && isset($rawScores['K'])) {
                $kCorrection = round($rawScores['K'] * $kFactor);
                $correctedRaw = $rawScore + $kCorrection;
            }
            
            // Calculate T-score using formula: T = 50 + 10 × (X - M) / δ
            if ($delta == 0) {
                $tScores[$scale] = 50.0;
            } else {
                $tScore = 50 + 10 * ($correctedRaw - $M) / $delta;
                // Clamp T-score to valid range (20-120 for MMPI/SMIL)
                $tScores[$scale] = round(max(20, min(120, $tScore)));
            }
        }
        
        return $tScores;
    }

    /**
     * Assess validity of results
     *
     * @param array $tScores T-scores for validity scales
     * @param array $answers User answers (to calculate unknown count)
     * @return array Validity assessment
     */
    protected function assessValidity(array $tScores, array $answers): array
    {
        $L = $tScores['L'] ?? 50;
        $F = $tScores['F'] ?? 50;
        $K = $tScores['K'] ?? 50;

        $valid = true;
        $warnings = [];

        // Calculate "?" scale (unknown answers)
        $unknownCount = $this->calculateUnknownScale($answers);

        // Calculate control scale (QC)
        $controlScore = $this->calculateControlScale($answers);

        // Check control scale (QC) - Sobchik methodology
        if ($controlScore < 20) {
            $valid = false;
            $warnings[] = "Протокол недостоверен: низкая внимательность (QC = {$controlScore} < 20)";
        }

        // Check "?" scale (unknown answers) - Sobchik methodology
        if ($unknownCount > 70) {
            $valid = false;
            $warnings[] = "Протокол недостоверен: слишком много ответов \"Не знаю\" ({$unknownCount} > 70)";
        } elseif ($unknownCount > 60) {
            $warnings[] = "Сомнительная достоверность: много ответов \"Не знаю\" ({$unknownCount})";
        } elseif ($unknownCount > 40) {
            $warnings[] = "Настороженность: повышенное количество ответов \"Не знаю\" ({$unknownCount})";
        }

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
            'unknown_count' => $unknownCount,
            'control_score' => $controlScore,
        ];
    }

    /**
     * Apply K-correction to clinical scales with formulas
     */
    protected function applyKCorrection(array $tScores, array $rawScores): array
    {
        $corrected = $tScores;
        $K = $rawScores['K'] ?? 0;

        // Formulas from additional-scales.json
        $formulas = [
            '1' => 0.5,  // +0.5K
            '3' => 0.3,  // +0.3K
            '4' => 0.4,  // +0.4K
            '6' => 0.3,  // +0.3K
            '7' => 1.0,  // +1.0K
            '8' => 0.2,  // +0.2K
            '9' => 0.2,  // +0.2K
            '0' => 0.0,  // No correction
        ];

        foreach ($formulas as $scale => $fraction) {
            if (isset($corrected[$scale]) && $fraction > 0) {
                $kCorrection = round($K * $fraction);
                $corrected[$scale] = round($tScores[$scale] + $kCorrection, 1);
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
        // Handle very high scores (above 100)
        if ($score >= 75) {
            return 'very_high';
        }
        
        foreach (self::THRESHOLDS as $level => $range) {
            if ($score >= $range['min'] && $score <= $range['max']) {
                return $level;
            }
        }

        // Handle very low scores (below 0)
        return 'low';
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
     * Get level name in Russian
     */
    protected function getLevelName(string $level): string
    {
        $names = [
            'low' => 'Низкий',
            'normal' => 'Норма',
            'elevated' => 'Повышенный',
            'high' => 'Высокий',
            'very_high' => 'Очень высокий',
        ];
        return $names[$level] ?? $level;
    }

    /**
     * Calculate marker position for visual scale (0-100%)
     */
    protected function calculateMarkerPosition(float $value): float
    {
        if ($value <= 29) return 5;
        if ($value >= 120) return 95;
        return 15 + (($value - 30) / 90) * 70;
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


    public function buildSections(array $results): array
    {
        $validity = $results['validity'] ?? [];
        $profile = $results['profile'] ?? [];
        $interpretation = $results['interpretation'] ?? [];
        $rawScores = $results['raw_scores'] ?? [];
        $tScores = $results['t_scores'] ?? [];
        $correctedScores = $results['corrected_scores'] ?? [];
        $indices = $results['indices'] ?? [];
        $additionalScores = $results['additional_scores'] ?? [];

        $sections = [];

        if (!$validity['is_valid']) {
            $sections[] = new ResultSection(
                type: ResultSection::TYPE_VALIDITY,
                title: '⚠️ Протокол недостоверен',
                data: $this->buildValidityData($validity),
                block: 'blocks/validity.twig',
                order: 0,
            );
        }

        $sections[] = new ResultSection(
            type: ResultSection::TYPE_VALIDITY,
            title: 'Контрольные шкалы',
            data: $this->buildValidityData($validity),
            block: 'blocks/validity.twig',
            order: 10,
        );

        $sections[] = new ResultSection(
            type: ResultSection::TYPE_PROFILE_CHART,
            title: 'Профиль личности',
            data: $this->buildProfileChartData($correctedScores),
            block: 'blocks/profile-chart.twig',
            order: 20,
        );

        $sections[] = new ResultSection(
            type: ResultSection::TYPE_SCALES_TABLE,
            title: 'Основные шкалы',
            data: $this->buildScalesTableData($rawScores, $tScores, $correctedScores),
            block: 'blocks/scales-table.twig',
            order: 30,
        );

        if (!empty($additionalScores)) {
            $sections[] = new ResultSection(
                type: ResultSection::TYPE_SCALES_TABLE,
                title: 'Дополнительные шкалы',
                data: $this->buildAdditionalScalesData($additionalScores),
                block: 'blocks/scales-table.twig',
                order: 40,
            );
        }

        $sections[] = new ResultSection(
            type: ResultSection::TYPE_INDICES,
            title: 'Дополнительные индексы',
            data: $this->buildIndicesData($indices),
            block: 'blocks/indices.twig',
            order: 50,
        );

        $sections[] = new ResultSection(
            type: ResultSection::TYPE_INTERPRETATION,
            title: 'Интерпретация',
            data: $this->buildInterpretationData($profile, $interpretation),
            block: 'blocks/interpretation.twig',
            order: 60,
        );

        $sections[] = new ResultSection(
            type: ResultSection::TYPE_RECOMMENDATIONS,
            title: 'Рекомендации',
            data: $this->buildRecommendationsData($interpretation),
            block: 'blocks/recommendations.twig',
            order: 70,
        );

        usort($sections, fn($a, $b) => $a->order <=> $b->order);
        return $sections;
    }

    private function buildValidityData(array $validity): array
    {
        return [
            'is_valid' => $validity['is_valid'] ?? false,
            'L_score' => $validity['L_score'] ?? 50,
            'F_score' => $validity['F_score'] ?? 50,
            'K_score' => $validity['K_score'] ?? 50,
            'FK_index' => $validity['FK_index'] ?? 0,
            'unknown_count' => $validity['unknown_count'] ?? 0,
            'control_score' => $validity['control_score'] ?? 0,
            'warnings' => $validity['warnings'] ?? [],
        ];
    }

    private function buildProfileChartData(array $tScores): array
    {
        $scaleOrder = ['L', 'F', 'K', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];
        $scaleLabels = ['L' => 'L', 'F' => 'F', 'K' => 'K', '1' => '1 Hs', '2' => '2 D', '3' => '3 Hy', '4' => '4 Pd', '5' => '5 Mf', '6' => '6 Pa', '7' => '7 Pt', '8' => '8 Sc', '9' => '9 Ma', '0' => '0 Si'];
        $scores = [];
        $labels = [];
        foreach ($scaleOrder as $s) {
            $scores[] = $tScores[$s] ?? 50;
            $labels[] = $scaleLabels[$s] ?? $s;
        }
        return [
            'scores' => $scores,
            'labels' => $labels,
            'chart_id' => 'smil-profile',
        ];
    }

    private function buildScalesTableData(array $rawScores, array $tScores, array $correctedScores): array
    {
        $controlScales = [
            'L' => ['name' => 'Шкала лжи', 'max' => 16, 'M' => 4.20, 'SD' => 2.90, 'formula' => '-'],
            'F' => ['name' => 'Шкала достоверности', 'max' => 65, 'M' => 4.67, 'SD' => 2.78, 'formula' => '-'],
            'K' => ['name' => 'Коррекционная шкала', 'max' => 30, 'M' => 12.10, 'SD' => 5.40, 'formula' => '-'],
        ];

        $clinicalScales = [
            '1' => ['name' => 'Ипохондрия (Hs)', 'max' => 48, 'M' => 12.90, 'SD' => 4.83, 'formula' => '+0.5K'],
            '2' => ['name' => 'Депрессия (D)', 'max' => 60, 'M' => 18.90, 'SD' => 5.00, 'formula' => '-'],
            '3' => ['name' => 'Истерия (Hy)', 'max' => 59, 'M' => 18.65, 'SD' => 5.38, 'formula' => '+0.3K'],
            '4' => ['name' => 'Психопатия (Pd)', 'max' => 62, 'M' => 18.68, 'SD' => 4.11, 'formula' => '+0.4K'],
            '5' => ['name' => 'Маскулинность-фемининность (Mf)', 'max' => 60, 'M' => 36.70, 'SD' => -4.67, 'formula' => '-'],
            '6' => ['name' => 'Паранойя (Pa)', 'max' => 40, 'M' => 7.90, 'SD' => 3.40, 'formula' => '+0.3K'],
            '7' => ['name' => 'Психастения (Pt)', 'max' => 77, 'M' => 25.70, 'SD' => 6.10, 'formula' => '+1.0K'],
            '8' => ['name' => 'Шизофрения (Sc)', 'max' => 108, 'M' => 22.73, 'SD' => 6.36, 'formula' => '+0.2K'],
            '9' => ['name' => 'Гипомания (Ma)', 'max' => 52, 'M' => 17.00, 'SD' => 4.06, 'formula' => '+0.2K'],
            '0' => ['name' => 'Интроверсия (Si)', 'max' => 70, 'M' => 25.00, 'SD' => 10.00, 'formula' => '-'],
        ];

        $scales = [];
        $K = $rawScores['K'] ?? 0;

        foreach ($controlScales as $code => $info) {
            $raw = $rawScores[$code] ?? 0;
            $corrected = $correctedScores[$code] ?? $tScores[$code] ?? 50;
            $level = $this->getScoreLevel($corrected);

            $scales[] = [
                'code' => $code,
                'name' => $info['name'],
                'raw' => $raw,
                'formula' => $info['formula'],
                'correction' => 0,
                'corrected_raw' => $raw,
                'max' => $info['max'],
                'M' => $info['M'],
                'SD' => $info['SD'],
                't_score' => $corrected,
                'level' => $level,
                'level_name' => $this->getLevelName($level),
            ];
        }

        foreach ($clinicalScales as $code => $info) {
            $raw = $rawScores[$code] ?? 0;
            $corrected = $correctedScores[$code] ?? $tScores[$code] ?? 50;
            $level = $this->getScoreLevel($corrected);

            $formula = $info['formula'];
            $correction = 0;
            if ($formula === '+0.5K') $correction = round($K * 0.5);
            elseif ($formula === '+0.3K') $correction = round($K * 0.3);
            elseif ($formula === '+0.4K') $correction = round($K * 0.4);
            elseif ($formula === '+1.0K') $correction = $K;
            elseif ($formula === '+0.2K') $correction = round($K * 0.2);

            $scales[] = [
                'code' => $code,
                'name' => $info['name'],
                'raw' => $raw,
                'formula' => $formula,
                'correction' => $correction,
                'corrected_raw' => $raw + $correction,
                'max' => $info['max'],
                'M' => $info['M'],
                'SD' => $info['SD'],
                't_score' => $corrected,
                'level' => $level,
                'level_name' => $this->getLevelName($level),
            ];
        }

        return ['scales' => $scales];
    }

    private function buildAdditionalScalesData(array $additionalScores): array
    {
        if (empty($additionalScores)) {
            return ['categories' => []];
        }

        $normsData = $this->loadAdditionalScalesNorms();
        $categoryNames = [
            'factor' => 'Факторные шкалы',
            'special' => 'Специальные шкалы',
            'content' => 'Контент-шкалы',
        ];

        $categories = [];
        foreach ($normsData as $category => $scales) {
            if (empty($scales)) continue;

            $items = [];
            foreach ($scales as $code => $info) {
                if (!isset($additionalScores[$code])) continue;

                $score = $additionalScores[$code];
                $tScore = $score['t'] ?? 50;
                $level = $this->getScoreLevel($tScore);
                $markerPos = $this->calculateMarkerPosition($tScore);

                $items[] = [
                    'code' => $code,
                    'name' => $info['name'] ?? $code,
                    'description' => $info['description'] ?? '',
                    't_score' => $tScore,
                    'level' => $level,
                    'marker_position' => round($markerPos, 2),
                    'interpretation' => $score['interpretation'] ?? $this->getAdditionalScaleInterpretation($code, $tScore, $category),
                ];
            }

            if (!empty($items)) {
                $categories[] = [
                    'name' => $categoryNames[$category] ?? $category,
                    'count' => count($items),
                    'items' => $items,
                ];
            }
        }

        return ['categories' => $categories];
    }

    private function buildInterpretationData(array $profile, array $interpretation): array
    {
        $profileType = $profile['profile_type'] ?? 'unknown';
        $codeType = $profile['code_type'] ?? '';
        $typeInfo = self::PROFILE_TYPES[$profileType] ?? ['name' => 'Не определён', 'description' => 'Требуется профессиональная интерпретация'];

        $detailedInterpretations = [
            '1' => [
                'low' => 'Оптимизм, отсутствие ипохондрических тенденций. Человек редко жалуется на здоровье, активен.',
                'normal' => 'Нормальный уровень заботы о здоровье. Адекватное внимание к физическому состоянию.',
                'elevated' => 'Повышенное внимание к здоровью, возможны соматические жалобы. Склонность фиксироваться на телесных ощущениях.',
                'high' => 'Выраженные ипохондрические тенденции. Множественные жалобы на здоровье, поиск болезней.',
                'very_high' => 'Сильная фиксация на здоровье. Возможна ипохондрия, множественные неспецифические жалобы.',
            ],
            '2' => [
                'low' => 'Приподнятое настроение, оптимизм. Высокая энергия, позитивный взгляд на жизнь.',
                'normal' => 'Нормальное эмоциональное состояние. Адекватные реакции на события.',
                'elevated' => 'Сниженное настроение, пессимизм. Возможны периоды подавленности.',
                'high' => 'Выраженная депрессия, чувство вины. Снижение активности, интереса к жизни.',
                'very_high' => 'Глубокая депрессия. Возможны суицидальные мысли, требуется помощь специалиста.',
            ],
            '3' => [
                'low' => 'Критичность к себе, реализм. Трезвая оценка ситуации, сдержанность в эмоциях.',
                'normal' => 'Умеренная эмоциональность. Баланс между контролем и выражением эмоций.',
                'elevated' => 'Демонстративность, стремление к вниманию. Желание нравиться, быть в центре.',
                'high' => 'Выраженная истероидность, конверсионные реакции. Эмоциональная нестабильность.',
                'very_high' => 'Сильная истероидная акцентуация. Возможны психосоматические реакции.',
            ],
            '4' => [
                'low' => 'Высокий самоконтроль, конформность. Следование правилам, осторожность.',
                'normal' => 'Умеренная импульсивность. Баланс между спонтанностью и контролем.',
                'elevated' => 'Импульсивность, склонность к риску. Возможны конфликты с нормами.',
                'high' => 'Выраженная антисоциальность, конфликтность. Трудности с контролем поведения.',
                'very_high' => 'Сильная тенденция к нарушению норм. Возможны проблемы с законом.',
            ],
            '5' => [
                'low' => 'Традиционные гендерные роли. Соответствие стереотипам пола.',
                'normal' => 'Умеренные интересы. Гибкость в проявлении качеств.',
                'elevated' => 'Нетрадиционные интересы для пола. Широкий спектр увлечений.',
                'high' => 'Выраженная фемининность (у мужчин) / маскулинность (у женщин).',
                'very_high' => 'Очень выраженные противоположные полу черты. Нестандартность.',
            ],
            '6' => [
                'low' => 'Доверчивость, наивность. Открытость людям, склонность верить.',
                'normal' => 'Умеренная критичность. Здоровый скептицизм без подозрительности.',
                'elevated' => 'Подозрительность, чувствительность к критике. Ожидание подвоха.',
                'high' => 'Выраженная паранойяльность, ригидность. Обидчивость, злопамятность.',
                'very_high' => 'Сильная подозрительность. Возможны бредовые идеи, проекции.',
            ],
            '7' => [
                'low' => 'Спокойствие, уверенность. Низкая тревожность, решительность.',
                'normal' => 'Умеренная тревожность. Адекватная реакция на стресс.',
                'elevated' => 'Повышенная тревожность, неуверенность. Частые беспокойства.',
                'high' => 'Выраженная тревога, навязчивости. Возможны фобии, ритуалы.',
                'very_high' => 'Сильная тревожность. Тревожное расстройство, панические атаки.',
            ],
            '8' => [
                'low' => 'Конкретность мышления, практичность. Реалистичный взгляд на мир.',
                'normal' => 'Умеренная рефлексия. Баланс между практичностью и творчеством.',
                'elevated' => 'Своеобразие мышления, богатое воображение. Нестандартность.',
                'high' => 'Выраженные шизоидные черты, аутизация. Замкнутость, оторванность.',
                'very_high' => 'Сильное своеобразие мышления. Возможна дезорганизация, странности.',
            ],
            '9' => [
                'low' => 'Спокойствие, низкая активность. Размеренный темп жизни.',
                'normal' => 'Умеренная энергичность. Адекватный уровень активности.',
                'elevated' => 'Повышенная активность, импульсивность. Высокая энергия.',
                'high' => 'Выраженная гипомания, расторможенность. Скачка идей, суетливость.',
                'very_high' => 'Сильное возбуждение. Возможна агрессия, мания.',
            ],
            '0' => [
                'low' => 'Экстраверсия, общительность. Легкость в контактах, открытость.',
                'normal' => 'Умеренная интроверсия/экстраверсия. Гибкость в общении.',
                'elevated' => 'Выраженная интроверсия, замкнутость. Предпочтение одиночества.',
                'high' => 'Сильная интроверсия, социальная изоляция. Трудности в общении.',
                'very_high' => 'Очень сильная интроверсия, аутизация. Избегание контактов.',
            ],
        ];

        $scalesData = [];
        foreach ($profile['scales'] ?? [] as $scale => $data) {
            $level = $data['level'] ?? 'normal';
            $scalesData[] = [
                'code' => $scale,
                'name' => $data['name'] ?? $scale,
                'score' => $data['score'] ?? 0,
                'level' => $level,
                'level_name' => $this->getLevelName($level),
                'interpretation' => $data['interpretation'] ?? '',
                'detail' => $detailedInterpretations[$scale][$level] ?? '',
            ];
        }

        $dominant = [];
        foreach ($profile['dominant'] ?? [] as $d) {
            $dominant[] = ['name' => $d['name'], 'score' => $d['score']];
        }

        return [
            'profile_type' => $profileType,
            'profile_type_name' => $typeInfo['name'],
            'profile_type_description' => $typeInfo['description'],
            'code_type' => $codeType,
            'summary' => $interpretation['summary'] ?? '',
            'scales' => $scalesData,
            'dominant' => $dominant,
        ];
    }

    private function buildRecommendationsData(array $interpretation): array
    {
        return [
            'items' => $interpretation['recommendations'] ?? [],
        ];
    }

    private function buildIndicesData(array $indices): array
    {
        return [
            'FK_index' => $indices['FK_index'] ?? 0,
            'FK_ratio' => $indices['FK_ratio'] ?? 0,
            'anxiety_index' => $indices['anxiety_index'] ?? 0,
            'depression_index' => $indices['depression_index'] ?? 0,
        ];
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
