<?php

/**
 * Тест на СДВГ у взрослых (авторский опросник PsyTest, 09.A1).
 *
 * Скрининг без нормативной выборки: считается число признаков невнимательности
 * и гиперактивности/импульсивности и проверяются три уточнения. Результат —
 * одно из трёх сообщений; диагноза опросник не ставит.
 */

declare(strict_types=1);

namespace PsyTest\Modules\AdhdAdult;

use PsyTest\Modules\BaseTestModule;
use PsyTest\Modules\ModuleCapability;
use PsyTest\Modules\ResultSection;

class AdhdAdultModule extends BaseTestModule
{
    public const INATTENTION_ITEMS = [1, 2, 3, 4, 5, 6, 7, 8, 9];
    public const HYPERACTIVITY_ITEMS = [10, 11, 12, 13, 14, 15, 16, 17, 18];
    public const GROUP_MAX = 9;

    /** Признак засчитывается при ответе «часто» (3) или «очень часто» (4). */
    public const SYMPTOM_MIN_VALUE = 3;
    /** Группа значима при счёте не ниже этого порога. */
    public const GROUP_THRESHOLD = 5;

    public const CHILDHOOD_ITEM = 19;
    /** Вопрос «несколько вариантов»: ответ — список значений выбранных сфер. */
    public const SPHERES_ITEM = 20;
    public const INTERFERENCE_ITEM = 21;

    public const CHILDHOOD_YES = 1;
    /** «Заметно» и «сильно». */
    public const INTERFERENCE_MIN_VALUE = 2;
    public const SPHERES_MIN = 2;

    public const CONCLUSION_EXPRESSED = 'expressed';
    public const CONCLUSION_PARTIAL = 'partial';
    public const CONCLUSION_NONE = 'none';

    protected const CONCLUSION_TITLES = [
        self::CONCLUSION_EXPRESSED => 'Признаки выражены',
        self::CONCLUSION_PARTIAL => 'Признаки есть, картина неполная',
        self::CONCLUSION_NONE => 'Выраженных признаков нет',
    ];

    protected const CONCLUSION_TEXTS = [
        self::CONCLUSION_EXPRESSED => 'По вашим ответам набирается число признаков, при котором стоит обратиться к психиатру для очной оценки. Признаки были в детстве, проявляются в нескольких сферах и заметно мешают — это те условия, на которые смотрит врач.',
        self::CONCLUSION_PARTIAL => 'Трудности заметны, но похожую картину дают тревога, депрессия, недосып и перегрузка. Имеет смысл обсудить это со специалистом.',
        self::CONCLUSION_NONE => 'По вашим ответам число признаков ниже того, при котором обычно думают о СДВГ. Если трудности всё же мешают, о них стоит поговорить со специалистом: у них может быть другая причина.',
    ];

    protected const SIGNATURE = 'Авторский опросник PsyTest, составлен по диагностическим признакам СДВГ у взрослых; на выборке пока не проверен. Результат — не диагноз и не заменяет консультацию врача.';

    protected const CHILDHOOD_LABELS = [1 => 'Да', 0 => 'Нет', 2 => 'Не помню'];

    /**
     * Сферы в порядке вариантов вопроса 20. «Другое» — сфера; исключающий
     * вариант «нигде не проявляются» (`none`) сферой не считается.
     */
    protected const SPHERE_LABELS = [
        'work' => 'работа или учёба',
        'home' => 'дом и быт',
        'relations' => 'отношения с людьми',
        'money' => 'деньги и документы',
        'other' => 'другое',
    ];

    protected const INTERFERENCE_LABELS = [0 => 'Не мешают', 1 => 'Немного', 2 => 'Заметно', 3 => 'Сильно'];

    public function getMetadata(): array
    {
        return array_merge(parent::getMetadata(), [
            'scoring_type' => 'count',
            'gender_specific_norms' => false,
        ]);
    }

    public function getQuestions(): array
    {
        if ($this->questions === null) {
            $this->questions = $this->loadQuestionsFromJson('questions.json');
        }

        return $this->questions;
    }

    public function getCapabilities(): array
    {
        return [ModuleCapability::PDF];
    }

    /**
     * @return array{gender: bool, age: bool, min_age: int, max_age: int}
     */
    public function getDemographicsRequirements(): array
    {
        return ['gender' => false, 'age' => false, 'min_age' => 18, 'max_age' => 100];
    }

    /**
     * @param array<int|string, mixed> $answers
     *
     * @return array<string, mixed>
     */
    public function calculateResults(array $answers): array
    {
        $inattention = self::countSymptoms($answers, self::INATTENTION_ITEMS);
        $hyperactivity = self::countSymptoms($answers, self::HYPERACTIVITY_ITEMS);

        $childhood = self::answerValue($answers, self::CHILDHOOD_ITEM);
        $chosen = self::chosenOptions($answers, self::SPHERES_ITEM);
        $spheres = array_values(array_filter(
            array_keys(self::SPHERE_LABELS),
            static fn (string $sphere): bool => in_array($sphere, $chosen, true),
        ));
        $interference = self::answerValue($answers, self::INTERFERENCE_ITEM);

        $conditions = [
            'childhood' => $childhood === self::CHILDHOOD_YES,
            'spheres' => count($spheres) >= self::SPHERES_MIN,
            'interference' => $interference !== null && $interference >= self::INTERFERENCE_MIN_VALUE,
        ];

        $answered = 0;
        foreach ($this->getQuestions() as $question) {
            $id = (int) $question['id'];
            $isAnswered = $id === self::SPHERES_ITEM ? $chosen !== [] : self::answerValue($answers, $id) !== null;
            if ($isAnswered) {
                $answered++;
            }
        }

        return [
            'inattention_count' => $inattention,
            'hyperactivity_count' => $hyperactivity,
            'group_max' => self::GROUP_MAX,
            'group_threshold' => self::GROUP_THRESHOLD,
            'inattention_significant' => $inattention >= self::GROUP_THRESHOLD,
            'hyperactivity_significant' => $hyperactivity >= self::GROUP_THRESHOLD,
            'childhood' => $childhood,
            'spheres' => $spheres,
            'spheres_count' => count($spheres),
            'interference' => $interference,
            'conditions' => $conditions,
            'conclusion' => self::conclusion($inattention, $hyperactivity, $conditions),
            'answered_count' => $answered,
            'total_questions' => count($this->getQuestions()),
        ];
    }

    /**
     * Вывод по счётам групп и трём уточнениям (чистая функция).
     *
     * @param array{childhood: bool, spheres: bool, interference: bool} $conditions
     */
    public static function conclusion(int $inattention, int $hyperactivity, array $conditions): string
    {
        $significant = $inattention >= self::GROUP_THRESHOLD || $hyperactivity >= self::GROUP_THRESHOLD;
        if (!$significant) {
            return self::CONCLUSION_NONE;
        }

        return $conditions['childhood'] && $conditions['spheres'] && $conditions['interference']
            ? self::CONCLUSION_EXPRESSED
            : self::CONCLUSION_PARTIAL;
    }

    /**
     * @param array<int|string, mixed> $answers
     * @param list<int> $items
     */
    private static function countSymptoms(array $answers, array $items): int
    {
        $count = 0;
        foreach ($items as $id) {
            $value = self::answerValue($answers, $id);
            if ($value !== null && $value >= self::SYMPTOM_MIN_VALUE) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<int|string, mixed> $answers
     */
    private static function answerValue(array $answers, int $id): ?int
    {
        $raw = $answers[$id] ?? $answers[(string) $id] ?? null;

        // AnswerValidator пропускает JSON-true как «1» — считаем так же, а не «нет ответа».
        if ($raw === true) {
            return 1;
        }

        return is_int($raw) || (is_string($raw) && preg_match('/\A\d+\z/', $raw) === 1) ? (int) $raw : null;
    }

    /**
     * Выбранные варианты вопроса «несколько вариантов» (строки, без повторов).
     *
     * @param array<int|string, mixed> $answers
     *
     * @return list<string>
     */
    private static function chosenOptions(array $answers, int $id): array
    {
        $raw = $answers[$id] ?? $answers[(string) $id] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter($raw, 'is_string')));
    }

    /**
     * @param array<string, mixed> $scores
     *
     * @return array<string, mixed>
     */
    public function generateInterpretation(array $scores): array
    {
        $conclusion = (string) ($scores['conclusion'] ?? self::CONCLUSION_NONE);
        if (!isset(self::CONCLUSION_TITLES[$conclusion])) {
            $conclusion = self::CONCLUSION_NONE;
        }

        return [
            'summary' => sprintf(
                '%s. Невнимательность: %d из %d. Гиперактивность и импульсивность: %d из %d.',
                self::CONCLUSION_TITLES[$conclusion],
                (int) ($scores['inattention_count'] ?? 0),
                self::GROUP_MAX,
                (int) ($scores['hyperactivity_count'] ?? 0),
                self::GROUP_MAX,
            ),
            'interpretation_text' => self::CONCLUSION_TEXTS[$conclusion],
            'recommendations' => [],
            'disclaimer' => self::SIGNATURE,
        ];
    }

    public function buildSections(array $results): array
    {
        $conclusion = (string) ($results['conclusion'] ?? self::CONCLUSION_NONE);
        if (!isset(self::CONCLUSION_TITLES[$conclusion])) {
            $conclusion = self::CONCLUSION_NONE;
        }

        $spheres = array_map(
            static fn (string $sphere): string => self::SPHERE_LABELS[$sphere],
            array_values(array_filter((array) ($results['spheres'] ?? []), static fn (mixed $sphere): bool => is_string($sphere) && isset(self::SPHERE_LABELS[$sphere]))),
        );
        $childhood = $results['childhood'] ?? null;
        $interference = $results['interference'] ?? null;

        return [
            new ResultSection(
                type: ResultSection::TYPE_INTERPRETATION,
                title: self::CONCLUSION_TITLES[$conclusion],
                data: ['text' => self::CONCLUSION_TEXTS[$conclusion], 'pdf_heading' => self::CONCLUSION_TITLES[$conclusion]],
                block: 'blocks/lazarus-interpretation.twig',
                order: 10,
            ),
            new ResultSection(
                type: ResultSection::TYPE_INDICES,
                title: 'Число признаков',
                data: ['pdf_heading' => 'Число признаков', 'scales' => [
                    ['name' => 'Невнимательность', 'score' => (int) ($results['inattention_count'] ?? 0), 'max' => self::GROUP_MAX, 'threshold' => self::GROUP_THRESHOLD],
                    ['name' => 'Гиперактивность и импульсивность', 'score' => (int) ($results['hyperactivity_count'] ?? 0), 'max' => self::GROUP_MAX, 'threshold' => self::GROUP_THRESHOLD],
                ], 'indices' => [
                    ['name' => 'Невнимательность', 'value' => sprintf('%d из %d', (int) ($results['inattention_count'] ?? 0), self::GROUP_MAX), 'description' => ''],
                    ['name' => 'Гиперактивность и импульсивность', 'value' => sprintf('%d из %d', (int) ($results['hyperactivity_count'] ?? 0), self::GROUP_MAX), 'description' => ''],
                ]],
                block: 'blocks/count-scale.twig',
                order: 20,
            ),
            new ResultSection(
                type: ResultSection::TYPE_INDICES,
                title: 'Ваши ответы на уточняющие вопросы',
                data: ['pdf_heading' => 'Ваши ответы на уточняющие вопросы', 'indices' => [
                    ['name' => 'Похожие трудности в детстве, до 12 лет', 'value' => is_int($childhood) ? (self::CHILDHOOD_LABELS[$childhood] ?? '—') : '—', 'description' => ''],
                    ['name' => 'Где трудности проявляются сейчас', 'value' => $spheres === [] ? 'Нигде не проявляются' : mb_strtoupper(mb_substr($joined = implode(', ', $spheres), 0, 1)) . mb_substr($joined, 1), 'description' => ''],
                    ['name' => 'Насколько мешают жить', 'value' => is_int($interference) ? (self::INTERFERENCE_LABELS[$interference] ?? '—') : '—', 'description' => ''],
                ]],
                block: 'blocks/indices.twig',
                order: 30,
            ),
            new ResultSection(
                type: ResultSection::TYPE_INTERPRETATION,
                title: '',
                data: ['text' => self::SIGNATURE],
                block: 'blocks/lazarus-interpretation.twig',
                order: 40,
            ),
        ];
    }

    /**
     * Контекст для ИИ-разбора: счёт по группам, вывод и уточнения.
     * Разбор по умолчанию выключен (строки в `ai_test_settings` нет).
     */
    public function aiReportContext(array $results, string $mode): ?array
    {
        if ($mode !== 'individual' || $results === []) {
            return null;
        }
        $conclusion = (string) ($results['conclusion'] ?? self::CONCLUSION_NONE);
        if (!isset(self::CONCLUSION_TITLES[$conclusion])) {
            $conclusion = self::CONCLUSION_NONE;
        }
        $metadata = $this->getMetadata();
        $groups = [
            ['code' => 'inattention', 'title' => 'Невнимательность', 'key' => 'inattention_count'],
            ['code' => 'hyperactivity', 'title' => 'Гиперактивность и импульсивность', 'key' => 'hyperactivity_count'],
        ];
        $subscales = [];
        foreach ($groups as $group) {
            $score = (int) ($results[$group['key']] ?? 0);
            $significant = $score >= self::GROUP_THRESHOLD;
            $subscales[] = [
                'code' => $group['code'],
                'title' => $group['title'],
                'score' => $score,
                'max' => self::GROUP_MAX,
                'level' => $significant ? 'significant' : 'not_significant',
                'level_name' => $significant ? 'Число признаков значимо (от ' . self::GROUP_THRESHOLD . ')' : 'Ниже порога (' . self::GROUP_THRESHOLD . ')',
            ];
        }
        $conditions = (array) ($results['conditions'] ?? []);

        return [
            'test' => (string) $metadata['slug'],
            'test_name' => (string) $metadata['name'],
            'mode' => 'individual',
            'subscales' => $subscales,
            'level' => $conclusion,
            'level_name' => self::CONCLUSION_TITLES[$conclusion],
            'interpretation' => self::CONCLUSION_TEXTS[$conclusion],
            // Только рассчитанные условия (да/нет). Сами ответы на вопросы 19–21
            // уходят наружу лишь вместе с ответами по пунктам — по галочке
            // методики (PRODUCT_RULES §6, AiTestSettings::sendsItemAnswers).
            'clarifications' => [
                'childhood_onset' => (bool) ($conditions['childhood'] ?? false),
                'several_spheres' => (bool) ($conditions['spheres'] ?? false),
                'noticeable_interference' => (bool) ($conditions['interference'] ?? false),
            ],
            'completeness' => [
                'answered' => (int) ($results['answered_count'] ?? 0),
                'total' => (int) ($results['total_questions'] ?? 0),
            ],
        ];
    }

    public function comparePairResults(array $results1, array $results2): array
    {
        return ['results_1' => $results1, 'results_2' => $results2, 'differences' => []];
    }
}
