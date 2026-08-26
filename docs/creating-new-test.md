# Руководство по добавлению нового теста (Module API v2)

**Статус:** актуальное руководство, соответствует фактическому `TestModuleInterface` и gate.
**Проверенный пример:** `tests/fixtures/demo-wellbeing/` — минимальный модуль, покрытый `DemoModuleContractTest` (обнаружение загрузчиком, схема-валидация ответов, web/PDF-рендеринг секций).
**Проверено сквозным walkthrough:** 26.08.2026 руководство пройдено по шагам на чистом клоне (`composer install` → `composer migrate` → новый модуль → каталог → прохождение → HTML-результат → PDF) с зелёным полным gate.

Добавление нового типа теста не требует изменений в контроллерах, рендерере, валидаторе или шаблонах. Общий слой не трогается; изменения ограничены новой директорией модуля и четырьмя регистрациями: PSR-4 в `composer.json`, запись в БД, запись в реестре методик и (по желанию) расширение `bin/check-architecture.php`.

---

## 1. Структура модуля

```
modules/my-test/
├── MyTestModule.php   # класс; имя файла = PascalCase(имя директории) + "Module"
├── metadata.json      # метаданные теста
└── questions.json     # вопросы
```

`ModuleLoader` находит модуль автоматически по директории (`my-test` → ищет `MyTestModule.php`) и подключает файл через `require_once`, поэтому в рантайме модуль работает и без Composer. Класс должен наследовать `PsyTest\Modules\BaseTestModule`.

Тем не менее добавьте модуль в PSR-4 карту `composer.json` — по образцу остальных модулей:

```json
"autoload": {
    "psr-4": {
        "PsyTest\\Modules\\MyTest\\": "modules/my-test/"
    }
}
```

Без этой строки `composer install` и `composer dump-autoload` на каждом запуске печатают предупреждение
`Class PsyTest\Modules\MyTest\MyTestModule ... does not comply with psr-4 autoloading standard`:
общее правило `PsyTest\Modules\ => ./modules` не может сопоставить kebab-case директорию с PascalCase-сегментом
namespace. Явная запись убирает предупреждение и делает класс автозагружаемым вне `ModuleLoader` (так работает
`LazarusAutoloadTest`).

## 2. metadata.json

```json
{
  "slug": "my-test",
  "name": "Мой тест",
  "description": "Краткое описание",
  "question_count": 20,
  "estimated_time": 10,
  "scales": [
    {"code": "A", "name": "Шкала A", "description": "Описание шкалы"}
  ],
  "requires_demographics": {"gender": false, "age": false},
  "version": "1.0",
  "author": "Автор",
  "source": "Источник методики",
  "language": "ru"
}
```

Обязательные поля: `slug`, `name`, `question_count`, `estimated_time`. Источник и права на методику обязательны до публикации (см. `docs/roadmap/METHODOLOGY_REGISTRY.md`).

## 3. questions.json

Форма вопроса зависит от декларативной схемы ответов модуля:

- `answer_type: 'options'` — вопрос содержит `options: [{value, text}]` (BAI/BDI/HADS);
- `answer_type: 'ternary'` — ответы «да/нет/не знаю», опционально `text_male`/`text_female` (SMIL);
- `answer_type: 'scale10'`, `key_template: 'dual'` — рейтинг 0–10 с двумя ключами на пункт (Lazarus).

```json
[
  {
    "id": 1,
    "text": "Текст утверждения",
    "scale": "A",
    "options": [
      {"value": 0, "text": "Совершенно неверно"},
      {"value": 3, "text": "Совершенно верно"}
    ]
  }
]
```

## 4. Класс модуля

Обязательно реализуются три доменных метода. Всё остальное дают декларативные значения Base:

| Поведение | Механизм v2 | Значение по умолчанию |
|---|---|---|
| Валидация ответов | `AnswerValidator` по `getAnswerSchema()` | options/plain, extra_keys `[gender, age]` |
| Возможности (pair/chart/pdf/paid_interpretation/clinical_signal) | `getCapabilities()` | `[pdf]` |
| Парный режим | capability `pair`; сравнение — `comparePairResults()` | выключен |
| Веб-график пары | `pairChartData(): ?array` | `null` |
| Рендеринг результата | `buildSections()` + блоки `templates/blocks/*` | — |
| Шаблон прохождения | `getTestTemplate()` | `test-wrapper` |

```php
<?php
declare(strict_types=1);

namespace PsyTest\Modules\MyTest;

use PsyTest\Modules\BaseTestModule;
use PsyTest\Modules\ResultSection;

final class MyTestModule extends BaseTestModule
{
    private const MAX_SCORE = 60;

    /** @param array<string, mixed> $answers */
    public function calculateResults(array $answers): array
    {
        $total = 0;
        foreach ($this->getQuestions() as $question) {
            $total += (int) ($answers[$question['id']] ?? 0);
        }

        return ['total' => $total, 'max_score' => self::MAX_SCORE];
    }

    /**
     * @param array<string, mixed> $scores
     * @return array{summary: string, recommendations: list<string>}
     */
    public function generateInterpretation(array $scores): array
    {
        return [
            'summary' => 'Сумма: ' . ($scores['total'] ?? 0),
            'recommendations' => [],
        ];
    }

    /**
     * Секции рендерятся общими слоями: web — result-layout.twig,
     * PDF — core/ResultSectionRenderer.php. Формы данных блоков см.
     * templates/blocks/score-badge.twig, interpretation.twig, recommendations.twig.
     *
     * @param array<string, mixed> $results
     * @return list<ResultSection>
     */
    public function buildSections(array $results): array
    {
        return [
            new ResultSection(
                type: ResultSection::TYPE_SCORE_BADGE,
                title: 'Результат',
                data: [
                    'score' => $results['total'],
                    'max' => self::MAX_SCORE,
                    'level' => 'normal',
                    'level_label' => 'Норма',
                    'description' => '',
                    'thresholds' => ['normal' => ['min' => 0, 'max' => self::MAX_SCORE]],
                ],
                block: 'blocks/score-badge.twig',
                order: 10,
            ),
        ];
    }
}
```

Правила:

- **Никакого HTML в модуле** — только данные секций; разметку делают шаблоны.
- **Никаких переопределений `supportsPairMode()`** — только capability `PAIR`.
- Графики: веб-график пары возвращается через `pairChartData()` (геометрия считается в модуле); статический PDF-график профиля рисует общий рендерер из данных секции `profile_chart`.
- Изменения scoring после первого релиза фиксируются golden-тестами (`tests/fixtures/golden/`).

## 5. Регистрация в БД

Миграция по образцу `database/migrations/20260708123506_add_lazarus_test.php`. Phinx `AbstractMigration`
**не имеет метода `insert()`** — вставка идёт через `table()->insert()->saveData()`; миграция обязана быть
идемпотентной и иметь `down()`:

```php
final class AddMyTest extends AbstractMigration
{
    public function up(): void
    {
        $exists = $this->fetchRow("SELECT id FROM `tests` WHERE `slug` = 'my-test'");
        if (!$exists) {
            $this->table('tests')->insert([
                'name'         => 'Мой тест',
                'slug'         => 'my-test',
                'module_class' => 'PsyTest\\Modules\\MyTest\\MyTestModule',
                'description'  => 'Краткое описание',
                'is_active'    => 1,
                'sort_order'   => 10,
            ])->saveData();
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `tests` WHERE `slug` = 'my-test'");
    }
}
```

Загрузчик сопоставляет запись со строкой каталога по `slug`; `module_class` хранится для документирования.

## 5а. Регистрация в реестре методик

Обязательный шаг: без него `MethodologyRegistryContractTest` падает и gate красный. Добавьте запись в
`docs/roadmap/methodology-registry.json` — контракт требует уникальный `slug`, совпадающий с `metadata.json`
(включая `question_count`), непустые `provenance.evidence` и `provenance.missing`, `provenance.status`
`partial` или `verified`, а также блоки `rights` и `release_gate`:

```json
{
  "slug": "my-test",
  "display_name": "Мой тест",
  "implementation": {
    "metadata": "modules/my-test/metadata.json",
    "questions": "modules/my-test/questions.json",
    "question_count": 20
  },
  "provenance": {
    "status": "partial",
    "evidence": ["чем подтверждено происхождение русской формы"],
    "missing": ["чего ещё не хватает до verified"]
  },
  "rights": {
    "status": "unverified",
    "required_evidence": ["какие документы закроют вопрос прав"]
  },
  "release_gate": {
    "paid_interpretation": "blocked",
    "public_new_content": "blocked"
  }
}
```

Реестр — фактическая опись происхождения и прав, а не юридическое заключение. `verified` ставится
только при документальном подтверждении (см. `docs/roadmap/METHODOLOGY_REGISTRY.md`).

## 6. Проверка

```bash
composer validate --strict --no-check-publish
composer migrate          # применить миграцию регистрации
composer test             # включая RendererContractTest / DemoModuleContractTest паттерн
composer analyse && composer lint
php bin/check-architecture.php   # проходит и без правок; см. примечание ниже
composer baseline:check
```

`bin/check-architecture.php` содержит захардкоженные проверки пяти текущих модулей и **не покрывает новые**:
gate проходит зелёным без правок этого файла. Расширение его `requiredFiles` и require-блоков вашим модулем
необязательно, но добавляет ему smoke-покрытие. Модуль-агностичный обход — зарегистрированный долг этапа 03.

Для нового модуля добавьте контрактные тесты по образцу `tests/DemoModuleContractTest.php` и, при изменении поведения существующих модулей, golden-фикстуры с указанием источника.

Порядок, проверенный walkthrough 26.08.2026: директория модуля → PSR-4 в `composer.json` → миграция → запись
в реестре методик → gate. Пропуск реестра даёт красный `MethodologyRegistryContractTest`, пропуск PSR-4 —
предупреждение Composer на каждой установке.

## 7. Ограничения платформы

- Все тесты и базовые результаты бесплатны; платный расширенный разбор — отдельный контур этапа 06/07 и capability `paid_interpretation`.
- Тексты вопросов, нормы и интерпретации публикуются только при подтверждённых правах на русскую адаптацию методики.
- Клинические сигналы (например, BDI item 9) объявляются capability `clinical_signal` и обрабатываются общим слоем `ClinicalSafetyNotice`.
