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

### 00C. Честная документация и GitHub README

- Сверить `README.md`, `DEVELOPMENT.md`, `ARCHITECTURE.md` с кодом; желаемое состояние вынести в roadmap.
- README должен ясно отделять готовые бесплатные функции от планируемых payment/AI/admin flows.
- Добавить локальный quick start, проверки, security/reporting note, ссылки на архитектуру и roadmap.
- Проверить все относительные Markdown-ссылки.

### 00D. Минимальный CI gate

- Настроить повторяемые lint/test/analyse/dependency checks без ложнозелёных scripts.
- Generated/cache/debug artifacts не должны попадать в Git или web root.
- Не начинать массовое исправление кода в этом этапе: каждое найденное нарушение маршрутизировать в 01/03/04.

## Проверка

- `git diff --check`; Markdown link checker или эквивалентный локальный скрипт.
- Свежий baseline полного gate из `AGENTS.md` с записанными известными отклонениями.
- `git status --short` не содержит `graphify-out/`, секретов, cache и случайных файлов.
- Новый агент по `ROADMAP.md` и `CHECKPOINT.md` способен назвать этап, следующий work package и риски без чтения чата.

## Exit criteria

- Все документы из индекса существуют, взаимно связаны и не противоречат решениям владельца.
- Governance-пакет закоммичен; README опубликован в GitHub.
- Baseline воспроизводим, broken gates исправлены либо имеют отдельную задачу с точным условием закрытия.
- `DOC-01` закрыт для локальной разработки; production-часть остаётся этапу 08.

## Покрытие аудита

`DOC-01`, начало `CODE-01`; организационная защита для всех остальных findings.
