# Этап 03 — Module API v2

Статус: **В работе** (с 25.08.2026). 03.1A: golden characterization для BAI/BDI/HADS/Lazarus — детерминированные ответы + пин полного вывода calculateResults и generateInterpretation (`tests/fixtures/golden/`, `GoldenModuleOutputsTest`); SMIL уже покрыт своими golden-фикстурами. Любое изменение scoring/интерпретации для этих наборов обязано обновлять фикстуру с указанием источника.

## Цель

Добавление нового типа теста должно происходить через модуль и декларативные capabilities, без новых `if ($slug === ...)` в общих контроллерах и шаблонах.

## Целевой контракт

Модуль отдельно предоставляет: metadata/version, questionnaire schema, answer validation, scoring, validity, base interpretation, result view model, capabilities (`pair`, `chart`, `pdf`, `paid_interpretation`, `clinical_signal`) и migrations/assets. Общий слой отвечает за lifecycle сессии, доступ и renderer.

## Work packages

1. Зафиксировать characterization/golden tests существующих модулей и их score outputs.
2. Ввести immutable DTO для answer set, score result, validity, signal и result sections.
3. Добавить schema validator и capability registry; запретить контроллеру угадывать тип теста по slug. 03.1B: capability registry введён — `ModuleCapability` (pair/chart/pdf/paid_interpretation/clinical_signal), `getCapabilities()` в интерфейсе, `supportsPairMode()` выведен из capability PAIR (переопределения удалены), декларации: Lazarus pair+pdf, SMIL chart+pdf, BDI clinical_signal+pdf, BAI/HADS pdf; контракт-тест `ModuleCapabilityContractTest` закрепляет декларации и деривацию. Slug-ветвлений в контроллерах не было (проверено grep) — реестр защищает от их появления. 03.1C: schema validator формализован — `getAnswerSchema()` в интерфейсе (answer_type/key_template/extra_keys/requires_gender), `AnswerValidator` переписан на декларативную схему (поведение идентично, включая per-question значения для options); `AnswerSchemaContractTest` (21 тест) закрепляет форму схемы, когерентность и поведение валидатора.
4. Создать renderer contract для единичного результата, pair result, таблиц, шкал и защищённого SMIL chart component.
5. Сделать legacy adapter и мигрировать по одному: Lazarus → BDI → BAI/HADS → SMIL.
6. После parity удалить только доказанно неиспользуемые branches/helpers/templates и дубли.
7. Обновить `architecture.md` и `creating-new-test.md` реальным примером минимального модуля.
8. Уменьшать PHPStan baseline по затронутым namespaces; общий count не растёт.

## Ограничения

- Базовые SMIL/Lazarus scoring outputs неизменны.
- Не добавлять 110 SMIL-шкал внутри инфраструктурной миграции.
- Схема не должна вынуждать все тесты иметь chart, pair mode или paid interpretation.

## Проверка и exit criteria

- Golden tests до/после дают идентичные базовые результаты.
- Contract tests проходят для каждого модуля; invalid/missing answers отклоняются сервером.
- Новый демонстрационный модуль добавляется без изменения core controller.
- Нет новых slug-ветвлений; legacy adapter удалён там, где завершена миграция.
- Документация добавления теста проверена пошагово в чистом окружении.

## Покрытие аудита

Завершение `SEC-04`, `CODE-01`, устранение дублирования и архитектурного долга из аудита.
