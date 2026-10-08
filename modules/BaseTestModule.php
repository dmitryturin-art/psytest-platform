<?php

/**
 * Base Abstract Test Module
 *
 * Provides common functionality for all test modules
 */

declare(strict_types=1);

namespace PsyTest\Modules;

abstract class BaseTestModule implements TestModuleInterface
{
    protected string $modulePath;
    protected array $metadata;
    protected ?array $questions = null;
    private static array $questionsCache = []; // Статический кэш

    public function __construct()
    {
        // Auto-detect module path
        $reflector = new \ReflectionClass($this);
        $this->modulePath = dirname($reflector->getFileName());

        $this->initialize();
    }

    /**
     * Initialize module (override in child classes)
     */
    protected function initialize(): void
    {
        // Load metadata from JSON if exists
        $metadataFile = $this->modulePath . '/metadata.json';
        if (file_exists($metadataFile)) {
            $this->metadata = json_decode(file_get_contents($metadataFile), true) ?? [];
        }
    }

    /**
     * Get module path
     */
    public function getModulePath(): string
    {
        return $this->modulePath;
    }

    /**
     * Load questions from JSON file
     */
    protected function loadQuestionsFromJson(string $filename = 'questions.json'): array
    {
        $filepath = $this->modulePath . '/' . $filename;

        // Проверка статического кэша
        if (isset(self::$questionsCache[$filepath])) {
            return self::$questionsCache[$filepath];
        }

        if (!file_exists($filepath)) {
            return [];
        }

        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$filepath}");
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "Failed to parse JSON in {$filepath}: " . json_last_error_msg()
            );
        }

        // Handle both formats: array of questions or object with "questions" key
        $questions = $data['questions'] ?? $data ?? [];

        // Сохранение в статический кэш
        self::$questionsCache[$filepath] = $questions;

        return $questions;
    }

    /**
     * Get metadata (override if not using JSON)
     */
    public function getMetadata(): array
    {
        return array_merge([
            'slug' => $this->getSlug(),
            'name' => 'Test',
            'description' => '',
            'question_count' => 0,
            'estimated_time' => 10,
            'scales' => [],
        ], $this->metadata);
    }

    /**
     * Respondent instruction from metadata.json `instruction` (07.K13).
     *
     * Only non-empty string paragraphs are kept; the text is plain and is
     * escaped by the template, never rendered as HTML.
     *
     * @return list<string>
     */
    public function getInstruction(): array
    {
        $raw = $this->metadata['instruction'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $paragraphs = [];
        foreach ($raw as $paragraph) {
            if (is_string($paragraph) && trim($paragraph) !== '') {
                $paragraphs[] = trim($paragraph);
            }
        }

        return $paragraphs;
    }

    /**
     * Get questions (override in child classes)
     */
    public function getQuestions(): array
    {
        if ($this->questions === null) {
            $this->questions = $this->loadQuestionsFromJson();
        }

        return $this->questions;
    }

    /**
     * Calculate results (must be implemented)
     */
    abstract public function calculateResults(array $answers): array;

    /**
     * Generate interpretation (must be implemented)
     */
    abstract public function generateInterpretation(array $scores): array;

    /**
     * Default: return empty sections array.
     * Override in each test module to provide its result structure.
     */
    public function buildSections(array $results): array
    {
        return [];
    }

    /**
     * Default capability set; modules override to declare what they support.
     *
     * @return list<string>
     */
    public function getCapabilities(): array
    {
        return [ModuleCapability::PDF];
    }

    /**
     * Default answer schema for option-based questionnaires.
     *
     * @return array<string, mixed>
     */
    public function getAnswerSchema(): array
    {
        return [
            'answer_type' => 'options',
            'key_template' => 'plain',
            'extra_keys' => ['gender', 'age'],
            'requires_gender' => false,
            'requires_age' => false,
            'age_range' => ['min' => 14, 'max' => 100],
        ];
    }

    /**
     * Derived from the PAIR capability (do not override; declare capabilities instead).
     */
    public function supportsPairMode(): bool
    {
        return in_array(ModuleCapability::PAIR, $this->getCapabilities(), true);
    }

    /**
     * Web pair chart data (renderer contract; override to provide a chart).
     */
    public function pairChartData(array $comparison): ?array
    {
        return null;
    }

    /**
     * Structured payload for an external AI report (07.WP10, D-056).
     *
     * Universal default for every methodology: what the respondent already
     * sees on the result page, in structured form — test, score with its
     * maximum, level, subscales and the module's own interpretation text.
     * Nothing about the person travels with it: no names, contacts, notes,
     * tokens or ids (PRODUCT_RULES §6, §11). Item answers are not here: they
     * are added by the context builder only when the owner allowed it
     * ({@see aiReportItems()}).
     *
     * Whether this payload is sent at all is the owner's switch per
     * methodology ({@see \PsyTest\Core\Ai\AiReportAvailability}). Modules
     * with a richer context (SMIL, Lazarus) override this method.
     *
     * Pair mode has no universal shape: null unless a module overrides it.
     */
    public function aiReportContext(array $results, string $mode): ?array
    {
        if ($mode !== 'individual' || $results === []) {
            return null;
        }

        $metadata = $this->getMetadata();
        $context = [
            'test' => (string) $metadata['slug'],
            'test_name' => (string) $metadata['name'],
            'mode' => 'individual',
        ];

        $badges = [];
        foreach ($this->buildSections($results) as $section) {
            if ($section instanceof ResultSection && $section->type === ResultSection::TYPE_SCORE_BADGE) {
                $badges[] = self::aiScoreFromBadge($section);
            }
        }

        if (count($badges) === 1) {
            // One score on the page: it is the total.
            $badge = $badges[0];
            $context['total'] = ['score' => $badge['score'], 'max' => $badge['max']];
            $context += array_filter([
                'level' => $badge['level'],
                'level_name' => $badge['level_name'],
                'interpretation' => $badge['interpretation'],
            ], static fn (mixed $value): bool => $value !== null);
        } elseif ($badges !== []) {
            // Several scores (HADS): each is a subscale with its own level.
            // A sum across subscales is not shown on the page and is not sent.
            $context['subscales'] = $badges;
        } else {
            $context += self::aiScoreFromResults($results);
        }

        $ranges = self::aiScoreRanges($metadata['score_ranges'] ?? null);
        if ($ranges !== []) {
            $context['score_ranges'] = $ranges;
        }

        if (is_numeric($results['answered_count'] ?? null) && is_numeric($results['total_questions'] ?? null)) {
            $context['completeness'] = [
                'answered' => (int) $results['answered_count'],
                'total' => (int) $results['total_questions'],
            ];
        }

        return $context;
    }

    /**
     * Respondent's answers to every item, for the AI context — only when the
     * owner ticked «Передавать модели ответы по пунктам» (07.WP10).
     *
     * Question text is the one the respondent saw (gendered text when the
     * module has it); the answer is the option label and its value. Free text
     * typed by the respondent is never part of an answer schema here.
     *
     * @param array<int|string, mixed> $answers Raw session answers.
     *
     * @return list<array{number: int, text: string, answer_label: string, value: int|string|null}>
     */
    public function aiReportItems(array $answers): array
    {
        $gender = $answers['gender'] ?? null;
        $items = [];

        foreach (array_values($this->getQuestions()) as $index => $question) {
            if (!is_array($question)) {
                continue;
            }

            $id = (string) ($question['id'] ?? $index + 1);
            $raw = $answers[$id] ?? $answers[(int) $id] ?? null;
            $value = is_int($raw) || (is_string($raw) && $raw !== '') ? $raw : null;

            $items[] = [
                'number' => $index + 1,
                'text' => self::aiQuestionText($question, $gender),
                'answer_label' => self::aiAnswerLabel($question, $value),
                'value' => is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1 ? (int) $value : $value,
            ];
        }

        return $items;
    }

    /**
     * Default: item answers leave the platform only by the owner's checkbox.
     */
    public function aiReportSendsItemsAlways(): bool
    {
        return false;
    }

    /** @return array{title: string, score: mixed, max: mixed, level: ?string, level_name: ?string, interpretation: ?string} */
    private static function aiScoreFromBadge(ResultSection $section): array
    {
        $data = $section->data;

        return [
            'title' => $section->title,
            'score' => is_numeric($data['score'] ?? null) ? $data['score'] + 0 : null,
            'max' => is_numeric($data['max'] ?? null) ? $data['max'] + 0 : null,
            'level' => is_string($data['level'] ?? null) ? $data['level'] : null,
            'level_name' => is_string($data['level_label'] ?? null) && $data['level_label'] !== '' ? $data['level_label'] : null,
            'interpretation' => is_string($data['description'] ?? null) && trim($data['description']) !== '' ? trim($data['description']) : null,
        ];
    }

    /**
     * Fallback for modules without a score badge on the result page.
     *
     * @param array<string, mixed> $results
     *
     * @return array<string, mixed>
     */
    private static function aiScoreFromResults(array $results): array
    {
        $context = [];
        if (is_numeric($results['total_score'] ?? null)) {
            $context['total'] = [
                'score' => $results['total_score'] + 0,
                'max' => is_numeric($results['max_score'] ?? null) ? $results['max_score'] + 0 : null,
            ];
        }
        foreach (['level', 'level_name', 'interpretation'] as $key) {
            if (is_string($results[$key] ?? null) && trim($results[$key]) !== '') {
                $context[$key] = trim($results[$key]);
            }
        }

        return $context;
    }

    /** @return list<array{level: string, level_name: string, min: int|float, max: int|float}> */
    private static function aiScoreRanges(mixed $ranges): array
    {
        if (!is_array($ranges)) {
            return [];
        }

        $out = [];
        foreach ($ranges as $range) {
            if (!is_array($range) || !isset($range['level'], $range['min'], $range['max'])) {
                continue;
            }
            $out[] = [
                'level' => (string) $range['level'],
                'level_name' => (string) ($range['name'] ?? $range['level']),
                'min' => $range['min'] + 0,
                'max' => $range['max'] + 0,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $question */
    private static function aiQuestionText(array $question, mixed $gender): string
    {
        if (is_string($question['text'] ?? null) && $question['text'] !== '') {
            return $question['text'];
        }
        if ($gender === 'female' && is_string($question['text_female'] ?? null)) {
            return $question['text_female'];
        }
        if ($gender === 'male' && is_string($question['text_male'] ?? null)) {
            return $question['text_male'];
        }

        return (string) ($question['text_male'] ?? $question['text_female'] ?? '');
    }

    /** @param array<string, mixed> $question */
    private static function aiAnswerLabel(array $question, int|string|null $value): string
    {
        if ($value === null) {
            return 'Нет ответа';
        }

        if (is_array($question['options'] ?? null)) {
            foreach ($question['options'] as $option) {
                if (is_array($option) && (string) ($option['value'] ?? '') === (string) $value) {
                    return (string) ($option['text'] ?? $value);
                }
            }

            return 'Нет ответа';
        }

        // Ternary questionnaires (SMIL) carry no options: labels as on the form.
        return match ((string) $value) {
            '1' => 'Верно',
            '0' => 'Неверно',
            '2' => 'Не знаю',
            default => (string) $value,
        };
    }

    /**
     * Compare pair results (override if pair mode supported)
     */
    public function comparePairResults(array $results1, array $results2): array
    {
        return [
            'results_1' => $results1,
            'results_2' => $results2,
            'differences' => [],
        ];
    }

    /**
     * Get slug from class name
     */
    protected function getSlug(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();
        return strtolower(str_replace('Module', '', $className));
    }

    /**
     * Calculate T-scores (standardized scores)
     *
     * @param float $rawScore Raw score
     * @param float $mean Population mean
     * @param float $stdDev Population standard deviation
     * @return float T-score (mean=50, SD=10)
     */
    protected function calculateTScore(float $rawScore, float $mean, float $stdDev): float
    {
        if ($stdDev == 0) {
            return 50.0;
        }

        $zScore = ($rawScore - $mean) / $stdDev;
        $tScore = 50 + ($zScore * 10);

        return round($tScore, 1);
    }

    /**
     * Normalize score to a range
     */
    protected function normalizeScore(float $score, float $min, float $max): float
    {
        if ($max == $min) {
            return 0;
        }

        return ($score - $min) / ($max - $min);
    }

    /**
     * Get interpretation level based on score
     */
    protected function getInterpretationLevel(float $score, array $thresholds): string
    {
        foreach ($thresholds as $threshold) {
            if ($score >= $threshold['min'] && $score <= $threshold['max']) {
                return $threshold['level'];
            }
        }

        return 'normal';
    }

    /**
     * Sanitize answer value
     */
    protected function sanitizeAnswer(mixed $answer): mixed
    {
        if (is_string($answer)) {
            return trim($answer);
        }

        return $answer;
    }

    /**
     * Validate answers structure
     */
    protected function validateAnswers(array $answers, array $questions): bool
    {
        $questionIds = array_column($questions, 'id');

        foreach ($answers as $questionId => $answer) {
            if (!in_array($questionId, $questionIds)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get custom test template (default: null = use default)
     */
    public function getTestTemplate(): ?string
    {
        return null;
    }

    /**
     * Get custom JavaScript for test (default: null = use default)
     */
    public function getCustomJavaScript(): ?string
    {
        return null;
    }

    /**
     * Get demographics requirements (default: no demographics)
     */
    public function getDemographicsRequirements(): array
    {
        return [
            'gender' => false,
            'age' => false,
            'min_age' => 14,
            'max_age' => 100,
        ];
    }

    /**
     * Очистить кэш вопросов (для тестов)
     */
    public static function clearQuestionsCache(): void
    {
        self::$questionsCache = [];
    }
}
