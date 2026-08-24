# Этап 00 — управление и воспроизводимый baseline

Статус: **В работе**. Владелец результата: инженер-агент. Продуктовый код в governance-пакете не меняется.

## Цель

Создать единственную понятную систему управления работой и честную точку отсчёта, чтобы следующий агент восстанавливал контекст по файлам, а зелёные проверки действительно что-то означали.

## Work packages

### 00A. Каноническая документация

- Ввести `ROADMAP`, status, decisions, product/engineering rules, traceability, worklog, checkpoint, lessons и человеческий changelog.
- Разбить реализацию на этапы 00–09; связать каждый audit finding с этапом.
- Удалить обязательность Superpowers из действующих инструкций, сохранив старые материалы только как историю.
- Добавить generated Graphify output в `.gitignore`; не коммитить его.

### 00B. Воспроизводимый baseline

- На отдельной ветке повторить Composer, PHPUnit, PHPStan, syntax/style, architecture/module checks.
- Записать версии PHP/Composer, команду, exit code и итог в `WORKLOG.md`.
- Починить неверный project root в architecture checker тестом на запуск из корня.
- Зафиксировать PHPStan baseline 149 и запретить рост в CI.

Состояние: **завершено в `b6756dd`**. Architecture checker исправлен и проверяет все пять имеющихся модулей; добавлен локальный guard `composer baseline:check` на 149 baseline entries. Подключение этого guard в CI переносится в 00D после безопасного обновления зависимостей.

### 00C. Честная документация и GitHub README

- Сверить `README.md`, `DEVELOPMENT.md`, `ARCHITECTURE.md` с кодом; желаемое состояние вынести в roadmap.
- README должен ясно отделять готовые бесплатные функции от планируемых payment/AI/admin flows.
- Добавить локальный quick start, проверки, security/reporting note, ссылки на архитектуру и roadmap.
- Проверить все относительные Markdown-ссылки.

Состояние: **current-state rewrite готов локально, publication/CI — следующий шаг**. `DocumentationCurrentStateTest` сверяет все registered public routes с `ARCHITECTURE.md`, запрещает устаревшие команды и проверяет локальные ссылки в README, DEVELOPMENT и ARCHITECTURE. Production runbook и фактическая production-конфигурация остаются этапом 08.

### 00D. Минимальный CI gate

- Настроить повторяемые lint/test/analyse/dependency checks без ложнозелёных scripts.
- Generated/cache/debug artifacts не должны попадать в Git или web root.
- Не начинать массовое исправление кода в этом этапе: каждое найденное нарушение маршрутизировать в 01/03/04.

### 00E. Freshness локальной карты проекта

- `graphify-out/` остаётся игнорируемым локальным артефактом, но перед архитектурным query проверяется командой `php bin/check-graphify-freshness.php`.
- После work package с изменениями кода, шаблонов, CSS или документации граф обновляется инкрементно либо в worklog фиксируется причина, срок повтора и безопасный fallback.
- Автоматизируется только контроль состояния; semantic extraction не вызывает внешний AI-провайдер без явного решения.

### 00F. Бюджет чтения и отчётность пакетов

- Старт сессии разделён на обязательный минимум и контекстные материалы по типу пакета.
- В инженерные правила добавляется обязательное объявление и отчёт фактически прочитанных файлов.
- Реализация не ослабляет product/security/scoring constraints: она убирает чтение нерелевантных документов «на всякий случай».

### 00G. Единая оперативная панель

- `STATUS.md` — единственный current-state документ.
- `CHECKPOINT.md` хранит только протокол команды «сделай checkpoint».
- Ссылки документации не обещают вторую копию состояния.

### 00H. Архив исходного audit-plan

- Исходный технический план 2026-08-15 хранится в `docs/archive/`.
- `AUDIT_TRACEABILITY.md` остаётся единственной рабочей навигацией по audit findings.

### 00I. Поведенческие schema-contracts

- Текстовые проверки четырёх миграций заменяются одним read-only integration test фактической схемы после `phinx migrate`.
- Роуты для current-state документации извлекаются из `public/index.php`, а не поддерживаются вторым захардкоженным списком.

### 00J. Снятие неподключённых заделов

- По D-032 удалить `AiProcessingConsentService`, `CountryResolver`/`CountryResolution` и их тесты.
- Добавить необратимую cleanup-миграцию: в уже развёрнутой БД удалить `ai_processing_consents` и `crisis_resources`; в чистой migration chain итоговая схема также не содержит этих таблиц.
- Обновить schema snapshot, current-state docs и traceability; не менять legacy AI/payment tables, scoring или BDI notice.

### 00K. CI по риску без дублирования общего gate

- Быстрые PHPUnit-тесты, dependency audit, PHPStan, formatting, baseline и architecture check выполняются один раз на PHP 8.3.
- Только DB-зависимые тесты и чистая цепочка migrations выполняются в матрице MySQL 5.7/8.0.
- В pull request матрица включается для migrations, persistence-кода, DB-тестов, зависимостей и самой CI-конфигурации. Каждый push в `main` и ручной запуск сохраняют полный DB-gate перед deployment.
- Классификация путей и разделение PHPUnit-групп защищены regression-тестами; scoring, продуктовый runtime и migrations не меняются.

## Проверка

- `git diff --check`; Markdown link checker или эквивалентный локальный скрипт.
- Свежий baseline полного gate из `AGENTS.md` с записанными известными отклонениями.
- `git status --short` не содержит `graphify-out/`, секретов, cache и случайных файлов.
- `php bin/check-graphify-freshness.php` возвращает `CURRENT` перед использованием Graphify как навигационной карты.
- Новый агент по `ROADMAP.md` и `STATUS.md` способен назвать этап, следующий work package и риски без чтения чата.

## Exit criteria

- Все документы из индекса существуют, взаимно связаны и не противоречат решениям владельца.
- Governance-пакет закоммичен; README опубликован в GitHub.
- Baseline воспроизводим, broken gates исправлены либо имеют отдельную задачу с точным условием закрытия.
- `DOC-01` закрыт для локальной разработки; production-часть остаётся этапу 08.

## Покрытие аудита

`DOC-01`, начало `CODE-01`; организационная защита для всех остальных findings. 00F–00H внедряют рекомендации внешнего review от 2026-08-24 по контролю контекстного бюджета.
