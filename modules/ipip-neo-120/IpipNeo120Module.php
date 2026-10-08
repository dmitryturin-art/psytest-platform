<?php

/**
 * IPIP-NEO-120 — «Большая пятёрка», 5 доменов × 6 граней (09.O1).
 *
 * Пункты и ключи: International Personality Item Pool, public domain,
 * https://ipip.ori.org/30FacetNEO-PI-RItems.htm; номера и порядок —
 * Johnson, J. A. (2014). Journal of Research in Personality, 51, 78–89,
 * табл. 1. Подсчёт — https://ipip.ori.org/newScoringInstructions.htm:
 * ответ 1–5, у обратных пунктов 6 − ответ, грань = сумма 4 пунктов (4–20),
 * домен = сумма 24 пунктов (24–120).
 *
 * Перцентили — по таблице `norms.json`, построенной `bin/ipip-build-norms.php`
 * из открытых данных Johnson (OSF tbmh5/wxvth) по полу и возрасту. Перевод
 * пунктов — PsyTest, на русской выборке не валидирован; об этом говорит
 * подпись норм на странице, в PDF и в контексте ИИ.
 */

declare(strict_types=1);

namespace PsyTest\Modules\IpipNeo120;

use PsyTest\Modules\BaseTestModule;
use PsyTest\Modules\ModuleCapability;
use PsyTest\Modules\ResultSection;

final class IpipNeo120Module extends BaseTestModule
{
    public const DOMAINS = ['N', 'E', 'O', 'A', 'C'];

    private const ITEMS_PER_FACET = 4;
    private const FACETS_PER_DOMAIN = 6;
    private const ITEM_MIN = 1;
    private const ITEM_MAX = 5;

    /** Полосы перцентиля (решение владельца 08.10.2026). */
    public const BAND_LOW_MAX = 30;
    public const BAND_HIGH_MIN = 70;

    public const LEVEL_LOW = 'low';
    public const LEVEL_AVERAGE = 'average';
    public const LEVEL_HIGH = 'high';

    private const LEVEL_NAMES = [
        self::LEVEL_LOW => 'Ниже, чем у большинства',
        self::LEVEL_AVERAGE => 'Как у большинства',
        self::LEVEL_HIGH => 'Выше, чем у большинства',
    ];

    private const DOMAIN_NAMES = [
        'N' => 'Нейротизм',
        'E' => 'Экстраверсия',
        'O' => 'Открытость опыту',
        'A' => 'Доброжелательность',
        'C' => 'Добросовестность',
    ];

    /**
     * Одна фраза на домен и полосу. Написано PsyTest по открытым описаниям
     * доменов J. A. Johnson (IPIP-NEO Narrative Report, public domain с
     * просьбой указывать авторство) — без клинических ярлыков: черты
     * личности не симптомы.
     */
    private const DOMAIN_TEXTS = [
        'N' => [
            self::LEVEL_LOW => 'Тревога, раздражение и подавленность бывают у вас реже, чем у большинства людей, и обычно быстро проходят.',
            self::LEVEL_AVERAGE => 'Тревога, раздражение и грусть бывают у вас примерно так же часто и сильно, как у большинства людей.',
            self::LEVEL_HIGH => 'Вы чаще и сильнее большинства людей переживаете тревогу, раздражение или подавленность и тяжелее переносите напряжение.',
        ],
        'E' => [
            self::LEVEL_LOW => 'Вам нужно меньше общения и внешних впечатлений, чем большинству: вы сдержаннее и охотнее проводите время в тишине или в узком кругу.',
            self::LEVEL_AVERAGE => 'Вы общительны и активны примерно так же, как большинство, и одинаково спокойно чувствуете себя в компании и наедине.',
            self::LEVEL_HIGH => 'Вам нравится быть среди людей: вы энергичнее и разговорчивее большинства и чаще испытываете радостное воодушевление.',
        ],
        'O' => [
            self::LEVEL_LOW => 'Вы больше большинства цените знакомое и практичное: привычные способы, ясные и конкретные задачи.',
            self::LEVEL_AVERAGE => 'Интерес к новому, к идеям и искусству у вас примерно такой же, как у большинства людей.',
            self::LEVEL_HIGH => 'Вы любознательнее большинства: цените новизну, искусство и идеи, охотно пробуете необычное и внимательны к своим чувствам.',
        ],
        'A' => [
            self::LEVEL_LOW => 'В отношениях вы прямее и твёрже большинства: охотнее отстаиваете свои интересы, чем уступаете, и не спешите доверять.',
            self::LEVEL_AVERAGE => 'Вы идёте навстречу людям примерно так же, как большинство, и при этом умеете постоять за себя.',
            self::LEVEL_HIGH => 'Вы чаще большинства идёте навстречу людям: доверяете, сочувствуете, избегаете ссор и цените согласие.',
        ],
        'C' => [
            self::LEVEL_LOW => 'Вы спонтаннее большинства: меньше планируете, легче откладываете дела и чаще действуете по настроению.',
            self::LEVEL_AVERAGE => 'Собранность и организованность у вас примерно такие же, как у большинства людей.',
            self::LEVEL_HIGH => 'Вы организованнее и настойчивее большинства: планируете, доводите начатое до конца и надёжны в обязательствах.',
        ],
    ];

    private const FACET_NOTE = 'Грань — 4 утверждения, поэтому её оценка грубее, чем у домена из 24.';

    /** @var array<string, mixed>|null */
    private ?array $norms = null;

    public function getMetadata(): array
    {
        return array_merge(parent::getMetadata(), [
            'scoring_type' => 'sum',
            'gender_specific_norms' => true,
        ]);
    }

    /**
     * Вопросы с общим набором вариантов ответа из metadata.json: в файле
     * вопросов он не повторяется 120 раз.
     */
    public function getQuestions(): array
    {
        if ($this->questions === null) {
            $options = $this->metadata['answer_options'] ?? [];
            $this->questions = array_map(
                static fn (array $question): array => $question + ['options' => $options],
                $this->loadQuestionsFromJson('questions.json'),
            );
        }

        return $this->questions;
    }

    public function getCapabilities(): array
    {
        return [ModuleCapability::PDF];
    }

    public function getAnswerSchema(): array
    {
        $demographics = $this->getDemographicsRequirements();

        return [
            'answer_type' => 'options',
            'key_template' => 'plain',
            'extra_keys' => ['gender', 'age'],
            'requires_gender' => true,
            // Возраст выбирает возрастную группу норм (решение владельца 08.10.2026).
            'requires_age' => true,
            'age_range' => ['min' => (int) $demographics['min_age'], 'max' => (int) $demographics['max_age']],
        ];
    }

    /**
     * @return array{gender: bool, age: bool, min_age: int, max_age: int}
     */
    public function getDemographicsRequirements(): array
    {
        $raw = $this->metadata['requires_demographics'] ?? [];

        return [
            'gender' => true,
            'age' => true,
            'min_age' => (int) ($raw['min_age'] ?? 18),
            'max_age' => (int) ($raw['max_age'] ?? 99),
        ];
    }

    /**
     * @param array<int|string, mixed> $answers 120 ответов 1–5, пол и возраст.
     *
     * @return array<string, mixed>
     */
    public function calculateResults(array $answers): array
    {
        $facetSums = [];
        $answered = 0;
        foreach ($this->getQuestions() as $question) {
            $id = (int) $question['id'];
            $raw = $answers[$id] ?? $answers[(string) $id] ?? null;
            $value = is_numeric($raw) ? (int) $raw : 0;
            if ($value < self::ITEM_MIN || $value > self::ITEM_MAX) {
                // Пропуск не досчитывается: форма требует ответа на каждый пункт,
                // а неполный набор не дойдёт сюда через AnswerValidator.
                continue;
            }
            $answered++;
            $score = $question['keyed'] === '-' ? (self::ITEM_MIN + self::ITEM_MAX) - $value : $value;
            $facet = (string) $question['facet'];
            $facetSums[$facet] = ($facetSums[$facet] ?? 0) + $score;
        }

        $total = count($this->getQuestions());
        $complete = $answered === $total;
        $cellKey = $this->normCellKey($answers['gender'] ?? null, $answers['age'] ?? null);
        $cell = $this->normCell($cellKey);
        $facetNames = $this->facetNames();

        $domains = [];
        $facets = [];
        foreach (self::DOMAINS as $domain) {
            $domainRaw = 0;
            for ($i = 1; $i <= self::FACETS_PER_DOMAIN; $i++) {
                $code = $domain . $i;
                $raw = $facetSums[$code] ?? 0;
                $domainRaw += $raw;
                $percentile = $complete ? $this->percentile($cell, $code, $raw) : null;
                $facets[$code] = [
                    'code' => $code,
                    'domain' => $domain,
                    'name' => $facetNames[$code] ?? $code,
                    'raw' => $raw,
                    'min' => self::ITEMS_PER_FACET * self::ITEM_MIN,
                    'max' => self::ITEMS_PER_FACET * self::ITEM_MAX,
                    'percentile' => $percentile,
                    'level' => $percentile === null ? null : self::band($percentile),
                ];
            }
            $percentile = $complete ? $this->percentile($cell, $domain, $domainRaw) : null;
            $domains[$domain] = [
                'code' => $domain,
                'name' => self::DOMAIN_NAMES[$domain],
                'raw' => $domainRaw,
                'min' => self::ITEMS_PER_FACET * self::FACETS_PER_DOMAIN * self::ITEM_MIN,
                'max' => self::ITEMS_PER_FACET * self::FACETS_PER_DOMAIN * self::ITEM_MAX,
                'percentile' => $percentile,
                'level' => $percentile === null ? null : self::band($percentile),
            ];
        }

        $norms = $this->norms();

        return [
            'domains' => $domains,
            'facets' => $facets,
            'norm_cell' => $cellKey,
            'norm_cell_label' => (string) ($cell['label'] ?? ''),
            'norm_cell_n' => (int) ($cell['n'] ?? 0),
            'norms_label' => (string) ($norms['label'] ?? ''),
            'norms_version' => 'johnson2014-osf-' . (string) ($norms['source']['downloaded_on'] ?? ''),
            'answered_count' => $answered,
            'total_questions' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $scores
     *
     * @return array<string, mixed>
     */
    public function generateInterpretation(array $scores): array
    {
        $parts = [];
        foreach (self::DOMAINS as $domain) {
            $row = $scores['domains'][$domain] ?? null;
            if (!is_array($row) || !is_string($row['level'] ?? null)) {
                continue;
            }
            $parts[] = sprintf('%s: %d-й перцентиль (%s)', self::DOMAIN_NAMES[$domain], (int) $row['percentile'], mb_strtolower(self::LEVEL_NAMES[$row['level']]));
        }

        return [
            'summary' => $parts === [] ? 'Ответы неполные — профиль не рассчитан.' : implode('. ', $parts) . '.',
            'recommendations' => [],
            'disclaimer' => 'Результат описывает черты личности и не является диагнозом. ' . (string) ($scores['norms_label'] ?? ''),
        ];
    }

    /**
     * Страница результата, PDF и карточка кейса — один набор секций.
     *
     * Гость и специалист видят пять доменов и свёрнутые грани; специалисту
     * (`is_specialist_view`, его ставит карточка кейса) добавляется таблица
     * сырых баллов общим механизмом таблицы шкал.
     */
    public function buildSections(array $results): array
    {
        $domains = [];
        foreach (self::DOMAINS as $domain) {
            $row = $results['domains'][$domain] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $facets = [];
            foreach ($results['facets'] ?? [] as $facet) {
                if (is_array($facet) && ($facet['domain'] ?? null) === $domain) {
                    $facets[] = $this->viewRow($facet);
                }
            }
            $domains[] = $this->viewRow($row) + [
                'description' => is_string($row['level'] ?? null) ? self::DOMAIN_TEXTS[$domain][$row['level']] : '',
                'facets' => $facets,
            ];
        }

        $sections = [
            new ResultSection(
                type: ResultSection::TYPE_TRAIT_PROFILE,
                title: 'Пять основных черт',
                data: [
                    'domains' => $domains,
                    'norms_label' => (string) ($results['norms_label'] ?? ''),
                    'cell_label' => (string) ($results['norm_cell_label'] ?? ''),
                    'cell_n' => (int) ($results['norm_cell_n'] ?? 0),
                    'band_low_max' => self::BAND_LOW_MAX,
                    'band_high_min' => self::BAND_HIGH_MIN,
                    'facet_note' => self::FACET_NOTE,
                ],
                block: 'blocks/trait-profile.twig',
                order: 10,
            ),
        ];

        if (!empty($results['is_specialist_view'])) {
            $categories = [];
            foreach ($domains as $domain) {
                $items = [$this->tableRow($domain, true)];
                foreach ($domain['facets'] as $facet) {
                    $items[] = $this->tableRow($facet, false);
                }
                $categories[] = ['name' => $domain['code'] . ' — ' . $domain['name'], 'items' => $items];
            }
            $sections[] = new ResultSection(
                type: ResultSection::TYPE_SCALES_TABLE,
                title: 'Сырые баллы и перцентили',
                data: [
                    'categories' => $categories,
                    'score_label' => 'Перцентиль',
                ],
                block: 'blocks/scales-table.twig',
                order: 20,
            );
        }

        return $sections;
    }

    /**
     * Универсальный контекст WP10 плюс домены и грани с перцентилями.
     *
     * Домены уходят как `subscales` (форма та же, что у HADS, с перцентилем),
     * грани — отдельным списком `facets`, подпись норм — `norms`. Пол и
     * возраст не уходят: модель получает только название группы норм.
     */
    public function aiReportContext(array $results, string $mode): ?array
    {
        $context = parent::aiReportContext($results, $mode);
        if ($context === null || !is_array($results['domains'] ?? null)) {
            return $context;
        }

        $subscales = [];
        foreach (self::DOMAINS as $domain) {
            $row = $results['domains'][$domain] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $level = is_string($row['level'] ?? null) ? $row['level'] : null;
            $subscales[] = [
                'code' => $domain,
                'title' => self::DOMAIN_NAMES[$domain],
                'score' => (int) $row['raw'],
                'min' => (int) $row['min'],
                'max' => (int) $row['max'],
                'percentile' => $row['percentile'] ?? null,
                'level' => $level,
                'level_name' => $level === null ? null : self::LEVEL_NAMES[$level],
                'interpretation' => $level === null ? null : self::DOMAIN_TEXTS[$domain][$level],
            ];
        }

        $facets = [];
        foreach ($results['facets'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $level = is_string($row['level'] ?? null) ? $row['level'] : null;
            $facets[] = [
                'code' => (string) $row['code'],
                'domain' => (string) $row['domain'],
                'title' => (string) $row['name'],
                'score' => (int) $row['raw'],
                'min' => (int) $row['min'],
                'max' => (int) $row['max'],
                'percentile' => $row['percentile'] ?? null,
                'level' => $level,
                'level_name' => $level === null ? null : self::LEVEL_NAMES[$level],
            ];
        }

        $context['subscales'] = $subscales;
        $context['facets'] = $facets;
        $context['norms'] = [
            'description' => (string) ($results['norms_label'] ?? ''),
            'group' => (string) ($results['norm_cell_label'] ?? ''),
            'group_n' => (int) ($results['norm_cell_n'] ?? 0),
            'bands' => sprintf('перцентиль ≤ %d — ниже, чем у большинства; %d–%d — как у большинства; ≥ %d — выше, чем у большинства', self::BAND_LOW_MAX, self::BAND_LOW_MAX + 1, self::BAND_HIGH_MIN - 1, self::BAND_HIGH_MIN),
            'facet_note' => self::FACET_NOTE,
        ];

        return $context;
    }

    public function comparePairResults(array $results1, array $results2): array
    {
        return [
            'results_1' => $results1,
            'results_2' => $results2,
            'differences' => [],
        ];
    }

    /** Полоса по перцентилю: ≤ 30 — ниже, 31–69 — как у большинства, ≥ 70 — выше. */
    public static function band(int $percentile): string
    {
        if ($percentile <= self::BAND_LOW_MAX) {
            return self::LEVEL_LOW;
        }

        return $percentile >= self::BAND_HIGH_MIN ? self::LEVEL_HIGH : self::LEVEL_AVERAGE;
    }

    public static function levelName(string $level): string
    {
        return self::LEVEL_NAMES[$level] ?? $level;
    }

    /**
     * Ячейка норм: пол × возрастная группа; без возраста — пол, все взрослые;
     * без пола — все взрослые.
     */
    public function normCellKey(mixed $gender, mixed $age): string
    {
        $sex = in_array($gender, ['male', 'female'], true) ? $gender : null;
        if ($sex === null) {
            return 'all';
        }

        $years = is_int($age) || (is_string($age) && preg_match('/\A\d{1,3}\z/', $age) === 1) ? (int) $age : null;
        if ($years === null) {
            return $sex . '_all';
        }

        foreach ($this->norms()['cells'] ?? [] as $key => $cell) {
            if (($cell['sex'] ?? null) === $sex
                && $key !== $sex . '_all'
                && $years >= (int) $cell['age_min']
                && $years <= (int) $cell['age_max']
            ) {
                return (string) $key;
            }
        }

        return $sex . '_all';
    }

    /**
     * Перцентиль сырого балла в ячейке норм (`null`, если балл вне шкалы).
     *
     * @param array<string, mixed> $cell
     */
    public function percentile(array $cell, string $scale, int $raw): ?int
    {
        $table = $cell['scales'][$scale] ?? null;
        if (!is_array($table) || $raw < (int) $table['min'] || $raw > (int) $table['max']) {
            return null;
        }

        $value = $table['percentiles'][$raw - (int) $table['min']] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    public function normCell(string $key): array
    {
        $cells = $this->norms()['cells'] ?? [];

        return is_array($cells[$key] ?? null) ? $cells[$key] : (is_array($cells['all'] ?? null) ? $cells['all'] : []);
    }

    /** @return array<string, mixed> */
    public function norms(): array
    {
        if ($this->norms === null) {
            $file = $this->modulePath . '/norms.json';
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $this->norms = is_array($decoded) ? $decoded : [];
        }

        return $this->norms;
    }

    /** @return array<string, string> */
    private function facetNames(): array
    {
        $names = [];
        foreach ($this->getQuestions() as $question) {
            $names[(string) $question['facet']] = (string) $question['facet_name'];
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function viewRow(array $row): array
    {
        $level = is_string($row['level'] ?? null) ? $row['level'] : null;

        return [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'raw' => (int) $row['raw'],
            'min' => (int) $row['min'],
            'max' => (int) $row['max'],
            'percentile' => $row['percentile'] ?? null,
            'level' => $level ?? 'none',
            'level_name' => $level === null ? '—' : self::LEVEL_NAMES[$level],
        ];
    }

    /**
     * Строка таблицы шкал для карточки кейса.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function tableRow(array $row, bool $isDomain): array
    {
        return [
            'code' => $row['code'],
            'name' => $isDomain ? $row['name'] . ' (домен)' : $row['name'],
            'raw' => $row['raw'],
            'max_raw' => $row['max'],
            't_score' => $row['percentile'] ?? '—',
            'level' => $row['level'],
            'level_name' => $row['level_name'],
        ];
    }
}
