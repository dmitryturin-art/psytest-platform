# Модульный контракт + миграция вывода в Twig — План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Переработать `TestModuleInterface`: модуль отдаёт структурированные `ResultData`, HTML-рендеринг переносится в переиспользуемые Twig-блоки. Убрать `renderResults()` из контракта и ~700 строк HTML-генерации из `SmilModule.php`.

**Architecture:** Страница результата = общая оболочка (`result-layout.twig`) + переиспользуемые блоки (таблица шкал, график профиля, бейдж балла, интерпретация). Модуль отдаёт список секций с типом и данными. Twig отрисовывает. Для принципиально другой механики (Люшер, ТАТ) — модуль может отдать свой шаблон через `getResultTemplate()`.

**Tech Stack:** PHP 8.1+, Twig 3, PHPUnit 10.

## Global Constraints

- PHP 8.1+ (declare(strict_types=1) в новых файлах).
- НЕ менять логику расчётов (raw/T-баллы/валидность) — это План 3.
- Обратная совместимость внутри плана: старый `renderResults()` остаётся deprecated до финального переключения.
- Все шаблоны — `.twig` (в `templates/blocks/`).
- Ветка: `refactor/technical-debt-phase1`.

---

## File Structure

**Новые файлы:**
- `modules/ResultData.php` — структура данных результата (value object / plain array contract).
- `modules/ResultSection.php` — одна секция результата (тип + данные).
- `templates/result-layout.twig` — общая оболочка страницы результата.
- `templates/blocks/score-badge.twig` — бейдж балла + уровень (для BAI/BDI/HADS).
- `templates/blocks/scales-table.twig` — таблица шкал с сырыми/T-баллами и визуализацией.
- `templates/blocks/profile-chart.twig` — график профиля MMPI (canvas + data-атрибуты для Chart.js).
- `templates/blocks/interpretation.twig` — блок интерпретации шкал.
- `templates/blocks/validity.twig` — блок валидности (L/F/K/QC/? + предупреждения).
- `templates/blocks/recommendations.twig` — блок рекомендаций.
- `templates/blocks/indices.twig` — блок дополнительных индексов.

**Модифицируемые файлы:**
- `modules/TestModuleInterface.php` — новые методы `buildSections()`, `score()`, deprecated `renderResults()`.
- `modules/BaseTestModule.php` — дефолтные реализации, хелперы.
- `controllers/ResultController.php` — переход на `buildSections()` вместо `renderResults()`.
- `core/PDFGenerator.php` — PDF из рендеренного Twig, а не из сырого HTML модуля.
- `modules/smil/SmilModule.php` — переработка renderXxx → методы данных + buildSections().
- `modules/beck-anxiety/BeckAnxietyModule.php` — migration to new contract.
- `templates/result-page.twig` — убирает `results_html|raw`, использует `result-layout.twig`.

---

## ResultData контракт

```php
// ResultSection: одна секция на странице результата
// [
//   'type'    => 'profile_chart' | 'scales_table' | 'score_badge' | 'validity' | 'interpretation' | 'recommendations' | 'indices' | 'raw_html',
//   'title'   => string,
//   'data'    => array,       // данные для twig-блока
//   'block'   => string|null, // имя twig-блока, или null для 'raw_html'
//   'order'   => int,         // порядок сортировки
// ]
```

```php
// buildSections(array $results): array — новый метод в TestModuleInterface
// Возвращает список ResultSection, который result-layout.twig отрисовывает.
```

---

### Task 1: Создание ResultData и ResultSection контрактов

**Files:**
- Create: `modules/ResultSection.php`
- Create: `tests/ResultSectionTest.php`

**Interfaces:**
- Consumes: ничего.
- Produces: `PsyTest\Modules\ResultSection` — plain class, используемый всеми задачами.

- [ ] **Step 1: Создать ResultSection.php**

```php
<?php
declare(strict_types=1);

namespace PsyTest\Modules;

final class ResultSection
{
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly array $data,
        public readonly ?string $block = null,
        public readonly int $order = 0,
    ) {}

    /** Section types */
    public const TYPE_PROFILE_CHART = 'profile_chart';
    public const TYPE_SCALES_TABLE = 'scales_table';
    public const TYPE_SCORE_BADGE = 'score_badge';
    public const TYPE_VALIDITY = 'validity';
    public const TYPE_INTERPRETATION = 'interpretation';
    public const TYPE_RECOMMENDATIONS = 'recommendations';
    public const TYPE_INDICES = 'indices';
    public const TYPE_RAW_HTML = 'raw_html';
}
```

- [ ] **Step 2: Создать тест ResultSectionTest.php**

```php
<?php
declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\ResultSection;

final class ResultSectionTest extends TestCase
{
    public function testSectionCreation(): void
    {
        $section = new ResultSection(
            type: ResultSection::TYPE_SCORE_BADGE,
            title: 'Общий балл',
            data: ['score' => 21, 'max' => 63],
            order: 10,
        );
        $this->assertSame(ResultSection::TYPE_SCORE_BADGE, $section->type);
        $this->assertSame('Общий балл', $section->title);
        $this->assertSame(21, $section->data['score']);
        $this->assertSame(10, $section->order);
        $this->assertNull($section->block);
    }

    public function testSectionWithBlock(): void
    {
        $section = new ResultSection(
            type: ResultSection::TYPE_PROFILE_CHART,
            title: 'Профиль',
            data: ['scores' => []],
            block: 'blocks/profile-chart.twig',
            order: 5,
        );
        $this->assertSame('blocks/profile-chart.twig', $section->block);
    }
}
```

- [ ] **Step 3: Запустить тесты**

Run: `composer test -- --filter=ResultSectionTest`
Expected: `2/2 passing`.

- [ ] **Step 4: Commit**

```bash
git add modules/ResultSection.php tests/ResultSectionTest.php
git commit -m "feat: add ResultSection value object for structured results"
```

---

### Task 2: Метод buildSections в TestModuleInterface и BaseTestModule

**Files:**
- Modify: `modules/TestModuleInterface.php`
- Modify: `modules/BaseTestModule.php`

**Interfaces:**
- Consumes: `ResultSection` из Task 1.
- Produces: `buildSections()` на контракте; `renderResults()` помечен deprecated но сохранён.

- [ ] **Step 1: Добавить buildSections в интерфейс**

В `TestModuleInterface.php` добавить после `calculateResults()`:

```php
    /**
     * Build result sections for structured rendering.
     *
     * Each section is a ResultSection with type, title, data, and optional twig block.
     * Sections are rendered by result-layout.twig using reusable block components.
     *
     * @param array $results Calculated results from calculateResults()
     * @return ResultSection[] Ordered list of result sections
     */
    public function buildSections(array $results): array;
```

- [ ] **Step 2: Добавить дефолтную реализацию в BaseTestModule**

```php
    /**
     * Default: return empty sections array.
     * Override in each test module to provide its result structure.
     */
    public function buildSections(array $results): array
    {
        return [];
    }
```

- [ ] **Step 3: Пометить renderResults deprecated (но НЕ удалять)**

В `TestModuleInterface`, над `renderResults()`:
```php
    /** @deprecated Use buildSections() + Twig blocks. Kept for backward compat; will be removed in Plan 3. */
    public function renderResults(array $results): string;
```

- [ ] **Step 4: Запустить проверку**

Run: `composer test && php test-architecture.php`
Expected: 2 теста проходят (ResultSection), архитектура без ошибок. Модули не сломаны (renderResults всё ещё работает).

- [ ] **Step 5: Commit**

```bash
git add modules/TestModuleInterface.php modules/BaseTestModule.php
git commit -m "feat(contract): add buildSections() to TestModuleInterface

Adds structured result rendering contract. renderResults() marked @deprecated,
kept for backward compatibility until all modules migrate."
```

---

### Task 3: Создание result-layout.twig (общая оболочка)

**Files:**
- Create: `templates/result-layout.twig`

**Context:** Новая оболочка страницы результата, которая заменит вставку `results_html|raw`. Циклит по sections и рендерит каждый через соответствующий twig-блок.

**Interfaces:**
- Consumes: `sections` (array of ResultSection), `test`, `session`, `basePath`, `appName`.
- Produces: полная HTML-страница результата с общей шапкой, дисклеймером и блоками.

- [ ] **Step 1: Создать result-layout.twig**

```twig
{% extends "layout.twig" %}

{% block title %}Результаты: {{ test.name }} — {{ appName }}{% endblock %}
{% block body_class %}results-page test-{{ test.slug }}{% endblock %}

{% block content %}
<div class="results-wrapper">
    <div class="results-header">
        <h1 class="results-title">{{ test.name }}</h1>
        <p class="results-subtitle">Результаты тестирования</p>
        <div class="results-meta">
            <span class="meta-item">📅 {{ session.created_at|date("d.m.Y H:i") }}</span>
            <span class="meta-item">🔗 ID: {{ session.id|slice(0, 8) }}...</span>
        </div>
    </div>

    <div class="results-actions">
        <a href="{{ basePath }}/result/{{ test.slug }}/{{ session.session_token }}/pdf"
           class="btn btn-secondary" target="_blank">📄 Скачать PDF</a>
        {% if ai_interpretation_available %}
        <a href="{{ basePath }}/interpretation/{{ session.session_token }}"
           class="btn btn-primary">✨ AI-интерпретация</a>
        {% endif %}
        <button class="btn btn-outline" onclick="copyResultLink()">🔗 Копировать ссылку</button>
        <button class="btn btn-outline" onclick="openDeleteModal()">🗑️ Удалить данные</button>
    </div>

    <div class="results-content">
        {% for section in sections %}
        <div class="results-section results-section--{{ section.type }}">
            {% if section.title %}
            <h2 class="section-title">{{ section.title }}</h2>
            {% endif %}
            <div class="section-body">
                {{ include(section.block, section.data, ignore_missing = true) }}
            </div>
        </div>
        {% endfor %}
    </div>

    <div class="results-disclaimer">
        <p><strong>Важно:</strong> Результаты носят ознакомительный характер и не являются диагнозом. Для профессиональной интерпретации обратитесь к квалифицированному специалисту.</p>
    </div>
</div>

{{ include('blocks/_delete-modal.twig') }}
{% endblock %}

{% block extra_js %}
<script>
const RESULT_CONFIG = {
    sessionId: '{{ session.id }}',
    sessionToken: '{{ session.session_token }}',
    testSlug: '{{ test.slug }}',
    basePath: '{{ basePath }}',
};
</script>
<script src="{{ basePath }}/js/results.js"></script>
{% endblock %}
```

- [ ] **Step 2: Создать blocks/_delete-modal.twig (вынесенный модал)**

```twig
<div class="modal" id="deleteModal" style="display: none">
    <div class="modal-content modal-danger">
        <h3>🗑️ Удалить мои данные?</h3>
        <p>Это действие необратимо удалит все результаты тестирования.</p>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeDeleteModal()">Отмена</button>
            <button class="btn btn-danger" onclick="confirmDelete()">Да, удалить</button>
        </div>
    </div>
</div>
<div class="toast" id="copyToast" style="display: none">✓ Ссылка скопирована</div>
```

- [ ] **Step 3: Commit**

```bash
git add templates/result-layout.twig templates/blocks/_delete-modal.twig
git commit -m "feat(ui): add result-layout.twig — reusable result page shell

Replaces inline results_html rendering. Iterates over modules' buildSections()
output and renders each block. Common header, actions, disclaimer, delete modal."
```

---

### Task 4: Twig-блоки для общих элементов

**Files:**
- Create: `templates/blocks/score-badge.twig` — бейдж балла + уровень.
- Create: `templates/blocks/scales-table.twig` — таблица шкал.

**Context:** Переиспользуемые компоненты, которые будут служить BAI, BDI, HADS (score-badge) и СМИЛ (scales-table).

**Interfaces:**
- `score-badge.twig` ожидает: `score` (int), `max` (int), `level` (string), `level_label` (string), `description` (string).
- `scales-table.twig` ожидает: `scales` (array of {code, name, raw, t_score, level}), `show_viz` (bool).

- [ ] **Step 1: Создать blocks/score-badge.twig**

```twig
<div class="score-badge score-badge--{{ level }}">
    <div class="score-badge__value">{{ score }}</div>
    <div class="score-badge__max">из {{ max }}</div>
    <div class="score-badge__level">{{ level_label }}</div>
</div>
{% if description %}
<p class="score-badge__description">{{ description }}</p>
{% endif %}
```

- [ ] **Step 2: Создать blocks/scales-table.twig**

```twig
<table class="scores-table {{ class|default('') }}">
    <thead>
        <tr>
            <th>Шкала</th>
            <th>Название</th>
            <th>Сырой балл</th>
            <th>T-балл</th>
            <th>Уровень</th>
            {% if show_viz|default(false) %}<th>Визуализация</th>{% endif %}
        </tr>
    </thead>
    <tbody>
        {% for s in scales %}
        <tr class="level--{{ s.level }}">
            <td><strong>{{ s.code }}</strong></td>
            <td>{{ s.name }}</td>
            <td>{{ s.raw }}</td>
            <td>{{ s.t_score }}</td>
            <td>{{ s.level_label }}</td>
            {% if show_viz %}<td class="viz-cell"><div class="scale-bar" style="width: {{ s.t_score }}%"></div></td>{% endif %}
        </tr>
        {% endfor %}
    </tbody>
</table>
```

- [ ] **Step 3: Commit**

```bash
git add templates/blocks/score-badge.twig templates/blocks/scales-table.twig
git commit -m "feat(ui): add shared score-badge and scales-table Twig blocks"
```

---

### Task 5: Специализированные Twig-блоки СМИЛ

**Files:**
- Create: `templates/blocks/profile-chart.twig`
- Create: `templates/blocks/validity.twig`
- Create: `templates/blocks/interpretation.twig`
- Create: `templates/blocks/recommendations.twig`
- Create: `templates/blocks/indices.twig`

**Context:** Блоки, специфичные для СМИЛ/MMPI. Переносят логику визуализации из PHP-методов `renderXxx()` в шаблоны.

**Interfaces:**
- `profile-chart.twig`: `scores` (array), `labels` (array), `chart_id` (string).
- `validity.twig`: `validity` (array from assessValidity()), `warnings` (array).
- `interpretation.twig`: `scales` (array of {code, name, level, text}).
- `recommendations.twig`: `items` (array of strings).
- `indices.twig`: `indices` (array of {name, value, description}).

- [ ] **Step 1: Создать blocks/profile-chart.twig**

```twig
<div class="profile-chart-container">
    <canvas id="{{ chart_id|default('smil-profile') }}"
            data-scores="{{ scores|json_encode }}"
            data-labels="{{ labels|json_encode }}"
            width="800" height="400">
    </canvas>
    <div class="chart-legend">
        <span class="legend-low">&#60;45 — низкий</span>
        <span class="legend-norm">45–54 — норма</span>
        <span class="legend-elevated">55–64 — повышенный</span>
        <span class="legend-high">65–74 — высокий</span>
        <span class="legend-very-high">75+ — очень высокий</span>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="{{ basePath }}/js/smil-profile-classic.js"></script>
```

- [ ] **Step 2: Создать blocks/validity.twig**

```twig
<div class="validity-block">
    <h3>Контрольные шкалы</h3>
    <div class="validity-grid">
        <div class="validity-item {{ validity.is_valid ? 'valid' : 'invalid' }}">
            <span class="validity-label">L (ложь)</span>
            <span class="validity-value">{{ validity.L_score }}</span>
        </div>
        <div class="validity-item">
            <span class="validity-label">F (достоверность)</span>
            <span class="validity-value">{{ validity.F_score }}</span>
        </div>
        <div class="validity-item">
            <span class="validity-label">K (коррекция)</span>
            <span class="validity-value">{{ validity.K_score }}</span>
        </div>
        <div class="validity-item">
            <span class="validity-label">? (не знаю)</span>
            <span class="validity-value">{{ validity.unknown_count }}</span>
        </div>
        <div class="validity-item">
            <span class="validity-label">QC (контроль)</span>
            <span class="validity-value">{{ validity.control_score }}/27</span>
        </div>
        <div class="validity-item">
            <span class="validity-label">F-K индекс</span>
            <span class="validity-value">{{ validity.FK_index }}</span>
        </div>
    </div>
    {% if validity.warnings is not empty %}
    <div class="validity-warnings">
        {% for w in validity.warnings %}<p class="warning">{{ w }}</p>{% endfor %}
    </div>
    {% endif %}
    <p class="validity-status {{ validity.is_valid ? 'valid' : 'invalid' }}">
        {{ validity.is_valid ? '✓ Протокол достоверен' : '⚠ Протокол недостоверен' }}
    </p>
</div>
```

- [ ] **Step 3: Commit (5 файлов одним коммитом)**

```bash
git add templates/blocks/profile-chart.twig templates/blocks/validity.twig templates/blocks/interpretation.twig templates/blocks/recommendations.twig templates/blocks/indices.twig
git commit -m "feat(ui): add SMIL-specific Twig blocks (profile, validity, interpretation, etc.)"
```

---

### Task 6: Миграция BeckAnxietyModule на новый контракт

**Files:**
- Modify: `modules/beck-anxiety/BeckAnxietyModule.php`

**Context:** BAI — простой суммирующий тест. Идеален как proof-of-concept нового контракта. `buildSections()` отдаёт 2 секции: score_badge и recommendations.

**Interfaces:**
- Consumes: `ResultSection` из Task 1.
- Produces: `buildSections()` возвращает массив из 2 ResultSection.

- [ ] **Step 1: Реализовать buildSections в BeckAnxietyModule**

```php
    public function buildSections(array $results): array
    {
        $raw = $results['raw_scores'] ?? [];
        $total = $raw['total'] ?? 0;

        $level = $total <= 21 ? 'low' : ($total <= 35 ? 'moderate' : 'high');
        $labels = ['low' => 'Минимальный уровень тревоги', 'moderate' => 'Средний уровень тревоги', 'high' => 'Высокий уровень тревоги'];

        return [
            new ResultSection(
                type: ResultSection::TYPE_SCORE_BADGE,
                title: 'Уровень тревоги',
                data: [
                    'score' => $total,
                    'max' => 63,
                    'level' => $level,
                    'level_label' => $labels[$level],
                    'description' => $results['interpretation']['summary'] ?? '',
                ],
                block: 'blocks/score-badge.twig',
                order: 10,
            ),
            new ResultSection(
                type: ResultSection::TYPE_RECOMMENDATIONS,
                title: 'Рекомендации',
                data: [
                    'items' => $results['interpretation']['recommendations'] ?? [],
                ],
                block: 'blocks/recommendations.twig',
                order: 20,
            ),
        ];
    }
```

- [ ] **Step 2: Обновить ResultController для использования buildSections**

В `ResultController::show()` заменить строки используемые для BAI (slug проверка не нужна — универсально):

Заменить:
```php
$resultsHtml = $module->renderResults($results);
```
На:
```php
$sections = $module->buildSections($results);
$resultsHtml = $this->view->render('result-layout', [
    'test' => $test,
    'session' => $session,
    'sections' => $sections,
    'results' => $results,
    'ai_interpretation_available' => !$aiInterpretation,
    'pair_comparison' => $pairComparison,
]);
```

И для PDF в `ResultController::pdf()` — аналогично использовать buildSections + result-layout.

- [ ] **Step 3: Проверить работоспособность через BAI smoke-test**

Run: (проверка что BAI работает и buildSections не ломает рендер):
```bash
php -r "
require 'vendor/autoload.php';
\$m = new PsyTest\\Modules\\BeckAnxiety\\BeckAnxietyModule();
\$q = \$m->getQuestions();
\$answers = array_map(fn() => rand(0,3), array_column(\$q, 'id'));
\$answersWithKeys = [];
foreach (\$q as \$i => \$question) \$answersWithKeys[\$question['id']] = \$answers[\$i];
\$r = \$m->calculateResults(\$answersWithKeys);
\$sections = \$m->buildSections(\$r);
echo 'Sections: ' . count(\$sections) . PHP_EOL;
foreach (\$sections as \$s) echo '  - ' . \$s->type . ': ' . \$s->title . PHP_EOL;
"
```
Expected: выводит 2 секции (score_badge, recommendations).

- [ ] **Step 4: Commit**

```bash
git add modules/beck-anxiety/BeckAnxietyModule.php controllers/ResultController.php core/PDFGenerator.php
git commit -m "refactor(bai): migrate BeckAnxietyModule to buildSections() contract

Replaces renderResults() with buildSections() returning ResultSection array.
ResultController now renders through result-layout.twig. BAI is the proof-of-concept
for the new contract."
```

---

### Task 7: Раскладка SmilModule::renderResults на секции данных

**Files:**
- Modify: `modules/smil/SmilModule.php`

**Context:** SmilModule содержит 15 renderXxx методов (~700 строк HTML). Заменяем их на методы, производящие данные, и buildSections().

Сопоставление старых методов → новые секции:

| Старый renderXxx | Тип секции | Данные из |
|------------------|-----------|-----------|
| renderReportHeader | (в result-layout) | — |
| renderValiditySection | validity | assessValidity() |
| renderProfileChart | profile_chart | correctedScores |
| renderRawScoresTable | scales_table | rawScores |
| renderTScoresTable | scales_table | tScores + correctedScores |
| renderCalculationsTable | scales_table (расширенная) | rawScores + correctedScores |
| renderAdditionalScalesTable | scales_table | additionalScores |
| renderProfileTypeSection | interpretation | profile + interpretation |
| renderClinicalScalesDetail | interpretation | profile |
| renderInterpretationSection | interpretation | profile |
| renderRecommendationsSection | recommendations | interpretation |
| renderIndicesSection | indices | indices |
| renderInvalidResults | validity | validity |
| renderNavigation | (в result-layout) | — |

**Подход:** Добавляем методы-сборщики данных (не HTML), затем buildSections(). Старые renderXxx ПОКА НЕ ТРОГАЕМ — удалим в финальном переключении (Task 8).

- [ ] **Step 1: Добавить private методы-сборщики данных для каждой секции**

Добавить в SmilModule методы:
- `buildValidityData(array $validity): array`
- `buildProfileChartData(array $tScores): array`
- `buildScalesTableData(array $rawScores, array $tScores, array $correctedScores): array`
- `buildAdditionalScalesData(array $additionalScores): array`
- `buildInterpretationData(array $profile, array $interpretation): array`
- `buildRecommendationsData(array $interpretation): array`
- `buildIndicesData(array $indices): array`

Каждый метод возвращает чистый массив данных — НЕ HTML.

Пример `buildValidityData`:
```php
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
```

Пример `buildProfileChartData`:
```php
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
```

- [ ] **Step 2: Реализовать buildSections() в SmilModule**

```php
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
```

- [ ] **Step 3: Проверить синтаксис и данные**

Run:
```bash
php -l modules/smil/SmilModule.php
php -r "
require 'vendor/autoload.php';
\$m = new PsyTest\Modules\Smil\SmilModule();
\$q = \$m->getQuestions();
\$answers = [];
foreach (\$q as \$i => \$item) \$answers[\$item['id']] = \$i % 3; // mix of 0,1,2
\$r = \$m->calculateResults(\$answers);
\$sections = \$m->buildSections(\$r);
echo 'Sections: ' . count(\$sections) . PHP_EOL;
foreach (\$sections as \$s) echo '  [' . \$s->order . '] ' . \$s->type . ': ' . \$s->title . PHP_EOL;
"
```
Expected: 7-8 секций с правильными типами и порядком.

- [ ] **Step 4: Commit**

```bash
git add modules/smil/SmilModule.php
git commit -m "refactor(smil): add buildSections() with data-producing methods

Adds buildValidityData, buildProfileChartData, buildScalesTableData, etc.
that produce structured arrays (not HTML). buildSections() composes them into
ResultSection array. Old renderXxx() methods preserved for backward compat."
```

---

### Task 8: Финальное переключение — удаление renderResults из SmilModule + ResultController

**Files:**
- Modify: `controllers/ResultController.php` — переход на buildSections во всех путях.
- Modify: `core/PDFGenerator.php` — рендер из Twig.
- Modify: `modules/smil/SmilModule.php` — удаление renderResults + renderXxx (15 методов, ~700 строк).
- Modify: `modules/beck-anxiety/BeckAnxietyModule.php` — удаление renderResults.
- Modify: `modules/BaseTestModule.php` — удаление abstract renderResults.
- Modify: `modules/TestModuleInterface.php` — удаление renderResults из интерфейса.

**Context:** Финальный шаг. Старый renderResults удаляется полностью. Весь рендеринг — через buildSections() + result-layout.twig.

- [ ] **Step 1: Удалить renderResults из TestModuleInterface и BaseTestModule**

В `TestModuleInterface.php` — удалить строки 65-73 (метод `renderResults()`).
В `BaseTestModule.php` — удалить строки 124-126 (abstract `renderResults()`).

- [ ] **Step 2: Удалить renderResults + renderXxx из SmilModule**

Удалить все 15 методов (строки ~1092-1810, сейчас после удаления T-таблиц — строки ~1076-1760).

- [ ] **Step 3: Удалить renderResults из BeckAnxietyModule**

Удалить метод `renderResults()`.

- [ ] **Step 4: Обновить ResultController::show — убрать fallback на renderResults**

В `ResultController::show()` строка `$resultsHtml = $module->renderResults($results);` (56) уже заменена в Task 6. Аналогично для `pdf()` (116).

Убедиться, что `$resultsHtml` больше не передаётся в шаблон как `results_html` — заменено на `sections`.

- [ ] **Step 5: Обновить PDFGenerator — рендер из Twig**

В `PDFGenerator::generateTestResult()` заменить параметр `string $resultsHtml` на `array $sections` + данные сессии, и рендерить PDF через `result-layout.twig`.

Упрощённо (детали адаптировать по месту):
```php
public function generateTestResult(array $session, array $test, array $sections, string $basePath): string
{
    $filename = "result_{$session['id']}.pdf";
    $twig = $this->getTwig(); // или передать View как зависимость
    $html = $twig->render('result-layout.twig', [
        'test' => $test,
        'session' => $session,
        'sections' => $sections,
        'basePath' => $basePath,
        'appName' => 'PsyTest',
    ]);
    return $this->generate($html, $filename, true);
}
```

- [ ] **Step 6: Update ResultController::pdf()**

```php
public function pdf(string $slug, string $token): void
{
    // ... same setup as show() ...
    $sections = $module->buildSections($results);
    $pdfPath = $this->pdfGenerator->generateTestResult($session, $test, $sections, rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'));
    // ... send file ...
}
```

- [ ] **Step 7: Полная проверка**

Run:
```bash
php -l modules/smil/SmilModule.php
php -l modules/beck-anxiety/BeckAnxietyModule.php
php -l controllers/ResultController.php
php -l core/PDFGenerator.php
php test-architecture.php
composer test
```
Expected: синтаксис чист, архитектура без ошибок, тесты проходят.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor!: remove renderResults() from contract — migrate to buildSections()

BREAKING: TestModuleInterface.renderResults() removed. All modules now use
buildSections() returning ResultSection[]. ResultController renders via
result-layout.twig. Removed ~700 lines of HTML generation from SmilModule.
PDFGenerator uses Twig rendering."
```

---

### Task 9: Тесты на buildSections для обоих модулей

**Files:**
- Create: `tests/SmilModuleSectionsTest.php`
- Create: `tests/BeckAnxietyModuleSectionsTest.php`

- [ ] **Step 1: Тест BAI buildSections**

```php
<?php
declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\BeckAnxiety\BeckAnxietyModule;

final class BeckAnxietyModuleSectionsTest extends TestCase
{
    public function testBuildSectionsReturnsRecognizedTypes(): void
    {
        $module = new BeckAnxietyModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) $answers[$q['id']] = 0; // Все минимальные ответы
        $results = $module->calculateResults($answers);
        $sections = $module->buildSections($results);
        $this->assertGreaterThanOrEqual(2, count($sections));
        $types = array_map(fn($s) => $s->type, $sections);
        $this->assertContains('score_badge', $types);
        $this->assertContains('recommendations', $types);
    }

    public function testScoreBadgeSectionHasCorrectData(): void
    {
        $module = new BeckAnxietyModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) $answers[$q['id']] = 0;
        $results = $module->calculateResults($answers);
        $sections = $module->buildSections($results);
        $badge = array_values(array_filter($sections, fn($s) => $s->type === 'score_badge'))[0];
        $this->assertSame(0, $badge->data['score']);
        $this->assertSame(63, $badge->data['max']);
        $this->assertSame('low', $badge->data['level']);
    }
}
```

- [ ] **Step 2: Тест SMIL buildSections**

```php
<?php
declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\SmilModule;

final class SmilModuleSectionsTest extends TestCase
{
    public function testBuildSectionsReturnsAllRequiredTypes(): void
    {
        $module = new SmilModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) $answers[$q['id']] = 1; // Все "Верно"
        $results = $module->calculateResults($answers);
        $sections = $module->buildSections($results);
        $types = array_map(fn($s) => $s->type, $sections);
        $this->assertContains('validity', $types);
        $this->assertContains('profile_chart', $types);
        $this->assertContains('scales_table', $types);
        $this->assertContains('interpretation', $types);
    }

    public function testSectionsAreOrdered(): void
    {
        $module = new SmilModule();
        $questions = $module->getQuestions();
        $answers = [];
        foreach ($questions as $q) $answers[$q['id']] = 1;
        $results = $module->calculateResults($answers);
        $sections = $module->buildSections($results);
        $orders = array_map(fn($s) => $s->order, $sections);
        $sorted = $orders;
        sort($sorted);
        $this->assertSame($sorted, $orders, 'Sections should be sorted by order');
    }
}
```

- [ ] **Step 3: Запустить тесты**

Run: `composer test`
Expected: все тесты (ResultSection + BAI + SMIL) проходят.

- [ ] **Step 4: Commit**

```bash
git add tests/
git commit -m "test: add buildSections() tests for BAI and SMIL modules"
```

---

### Task 10: Финальная проверка плана

- [ ] **Step 1: Полный прогон всех проверок**

```bash
php test-architecture.php
composer test
composer analyse
composer lint
```

- [ ] **Step 2: Проверить чистоту git**

```bash
git status
```

- [ ] **Step 3: Проверить размер SmilModule**

```bash
wc -l modules/smil/SmilModule.php
```
Expected: ~950-1050 строк (было 1840, потом 1744, минус 700 renderXxx → ~1050).

- [ ] **Step 4: Commit (если есть незакоммиченные изменения)**

---

## Критерии приёмки плана

- [ ] `TestModuleInterface` не содержит `renderResults()`.
- [ ] `buildSections()` возвращает `ResultSection[]` в BAI и SMIL модулях.
- [ ] `ResultController` рендерит через `result-layout.twig`, а не `results_html|raw`.
- [ ] `PDFGenerator` работает от структурированных данных, а не сырого HTML.
- [ ] SmilModule.php < 1100 строк (было 1744).
- [ ] `composer test` проходит (не менее 4 тестов).
- [ ] `composer analyse` без новых ошибок сверх baseline.
- [ ] `php test-architecture.php` без ошибок.
