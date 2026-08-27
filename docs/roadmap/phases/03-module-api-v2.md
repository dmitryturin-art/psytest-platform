# Этап 03 — Module API v2

Статус: **Завершён** (26.08.2026, решение владельца). Все пять exit criteria выполнены; остаток работ по WP2 (immutable DTO) и WP8 (сокращение baseline) не блокирует закрытие и продолжается отдельными пакетами. Начат 25.08.2026. 03.1A: golden characterization для BAI/BDI/HADS/Lazarus — детерминированные ответы + пин полного вывода calculateResults и generateInterpretation (`tests/fixtures/golden/`, `GoldenModuleOutputsTest`); SMIL уже покрыт своими golden-фикстурами. Любое изменение scoring/интерпретации для этих наборов обязано обновлять фикстуру с указанием источника.

## Цель

Добавление нового типа теста должно происходить через модуль и декларативные capabilities, без новых `if ($slug === ...)` в общих контроллерах и шаблонах.

## Целевой контракт

Модуль отдельно предоставляет: metadata/version, questionnaire schema, answer validation, scoring, validity, base interpretation, result view model, capabilities (`pair`, `chart`, `pdf`, `paid_interpretation`, `clinical_signal`) и migrations/assets. Общий слой отвечает за lifecycle сессии, доступ и renderer.

## Work packages

1. Зафиксировать characterization/golden tests существующих модулей и их score outputs.
2. Ввести immutable DTO для answer set, score result, validity, signal и result sections.
3. Добавить schema validator и capability registry; запретить контроллеру угадывать тип теста по slug. 03.1B: capability registry введён — `ModuleCapability` (pair/chart/pdf/paid_interpretation/clinical_signal), `getCapabilities()` в интерфейсе, `supportsPairMode()` выведен из capability PAIR (переопределения удалены), декларации: Lazarus pair+pdf, SMIL chart+pdf, BDI clinical_signal+pdf, BAI/HADS pdf; контракт-тест `ModuleCapabilityContractTest` закрепляет декларации и деривацию. Slug-ветвлений в контроллерах не было (проверено grep) — реестр защищает от их появления. 03.1C: schema validator формализован — `getAnswerSchema()` в интерфейсе (answer_type/key_template/extra_keys/requires_gender), `AnswerValidator` переписан на декларативную схему (поведение идентично, включая per-question значения для options); `AnswerSchemaContractTest` (21 тест) закрепляет форму схемы, когерентность и поведение валидатора.
4. Создать renderer contract для единичного результата, pair result, таблиц, шкал и защищённого SMIL chart component. 03.2: контракт введён — `core/ResultSectionRenderer.php` стал единственным dispatch секций в HTML для PDF-ветки (статический SMIL-chart перенесён из контроллера дословно); `pairChartData(): ?array` добавлен в `TestModuleInterface` (Base — null, реализует Lazarus), `instanceof LazarusModule` и импорт конкретного модуля удалены из контроллера; `RendererContractTest` (19 тестов) закрепляет: секции всех модулей рендерятся standalone (web) и через общий рендерер (PDF), pair chart декларативен, контроллеры не ссылаются на конкретные модули, канонический SMIL-график (`profile-chart.twig` + `smil-profile-classic.js`) защищён от замены; PHPStan baseline 148→147.
5. Сделать legacy adapter и мигрировать по одному: Lazarus → BDI → BAI/HADS → SMIL. Переосмыслено 26.08: адаптер не понадобился — модули переведены на декларативную поверхность v2 напрямую (03.1B capability registry, 03.1C answer schema, 03.2 renderer contract с `pairChartData()`) под защитой golden-фикстур; отдельный adapter-слой не создавался и не требуется. WP5 закрыт как выполненный другим путём.
6. После parity удалить только доказанно неиспользуемые branches/helpers/templates и дубли. 03.3: удалён мёртвый хук `getResultTemplate()` (ноль потребителей — проверено grep по core/controllers/modules/templates); `getCustomJavaScript()` оставлен — живой потребитель в test-wrapper.twig.
7. Обновить `architecture.md` и `creating-new-test.md` реальным примером минимального модуля. 03.2 обновил ARCHITECTURE.md (renderer); 03.3 переписал `creating-new-test.md` как актуальное руководство с проверенным примером `tests/fixtures/demo-wellbeing/`; text-contract `DocumentationCurrentStateTest` закрепил новое состояние (легаси `renderResults`/Chart.js отсутствуют).
8. Уменьшать PHPStan baseline по затронутым namespaces; общий count не растёт.

## Зарегистрированный долг этапа

- `bin/check-architecture.php` не модуль-агностичен: пять почти одинаковых блоков по ~45 строк с захардкоженными
  `require_once` и списком `requiredFiles` покрывают только текущие модули, новый модуль в smoke-проверку не попадает.
  Обнаружено walkthrough-пакетом 03.4. Gate от этого не краснеет, поэтому руководство приведено к факту, а замена
  блоков на обход обнаруженных `ModuleLoader` модулей вынесена в отдельный пакет (WP6, дублирование).
- `provenance.missing` в `methodology-registry.json` обязан быть непустым по контракту даже для методики со
  статусом `verified` — форму реестра стоит пересмотреть вместе с legal review (риск №6).

## Ограничения

- Базовые SMIL/Lazarus scoring outputs неизменны.
- Не добавлять 110 SMIL-шкал внутри инфраструктурной миграции.
- Схема не должна вынуждать все тесты иметь chart, pair mode или paid interpretation.

## Проверка и exit criteria

- Golden tests до/после дают идентичные базовые результаты. ✓ (03.1A, фикстуры не менялись)
- Contract tests проходят для каждого модуля; invalid/missing answers отклоняются сервером. ✓ (capability/schema/renderer контракты 03.1B/C/03.2)
- Новый демонстрационный модуль добавляется без изменения core controller. ✓ (03.3: `tests/fixtures/demo-wellbeing/` + `DemoModuleContractTest` — обнаружение загрузчиком, схема-валидация, web/PDF-рендеринг без правок ядра)
- Нет новых slug-ветвлений; legacy adapter удалён там, где завершена миграция. ✓ (03.2: контроллеры module-agnostic; адаптер не создавался — см. WP5)
- Документация добавления теста проверена пошагово в чистом окружении. ✓ (26.08, пакет 03.4) Walkthrough выполнен на чистом клоне `main` (`9a163a6`): `composer install` → отдельная БД → `composer migrate` → модуль `my-test` создан строго по тексту руководства → каталог `/tests`, страница прохождения, submit по HTTP, HTML-результат и PDF (18 КБ, `application/pdf`) — всё без единой правки контроллеров, рендерера, валидатора и шаблонов. Найдены и исправлены четыре дефекта руководства (см. WORKLOG 03.4); после исправлений полный gate на клоне зелёный: 248 tests / 2072 assertions, PHPStan `[OK]`, lint 0, architecture exit 0, baseline 147/147.

## Покрытие аудита

Завершение `SEC-04`, `CODE-01`, устранение дублирования и архитектурного долга из аудита.
