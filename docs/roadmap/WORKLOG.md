# Технический журнал работ

Назначение: воспроизводимая хронология того, что делалось, почему, в какой ветке и с каким доказательством. Новые записи добавляются сверху внутри текущей даты; старые не переписываются задним числом, кроме исправления явной опечатки.

## Шаблон записи

```markdown
### YYYY-MM-DD — короткое название work package

- Этап / ветка / commit:
- Цель:
- Сделано:
- Решения:
- Проверки и evidence:
- Изменённые файлы:
- Не сделано / риски:
- Следующий шаг:
```

## 2026-08-15

### Публикация governance package

- Этап / ветка / commit: local `main` → `origin/main`, `c7bb44e`.
- Цель: опубликовать проверенный roadmap, честный README и repository hygiene в публичном GitHub-репозитории.
- Сделано: GitHub auth восстановлена; четыре локальных commits отправлены в `main`.
- Решения: владелец подтвердил, что два старых PDF обезличены; history rewrite и force-push не нужны.
- Проверки и evidence: `gh auth status` — authenticated; repository visibility — public; `git push origin main` прошёл с `6c51cc3..c7bb44e`.
- Следующий шаг: переключить основную задачу на Terra и начать `codex/00-reproducible-baseline`.

### Локальная интеграция governance

- Этап / ветка / commit: этап 00, `codex/governance-roadmap` → local `main`, fast-forward до `0dad917`.
- Цель: принять проверенный documentation/repository package без merge commit и без функциональных изменений.
- Сделано: три commits интегрированы в локальный `main`; рабочее дерево после merge чистое.
- Проверки и evidence: active Markdown links — OK; `git diff --check` — OK; package reviewed тремя независимыми субагентами.
- Не сделано / риски: remote `origin/main` остаётся на `6c51cc3`, потому что `gh auth status` сообщает invalid token. До push нужно определить visibility и решить, требует ли старая PDF history очистки.
- Следующий шаг: `gh auth login -h github.com`, затем publication safety decision и push.

### Независимый аудит и формирование продукта

- Этап / ветка / commit: подготовительная работа, `main`, commit baseline `6c51cc3`.
- Цель: непредвзято оценить архитектуру, код, безопасность, UI/UX, документацию и коммерческий сценарий.
- Сделано: подготовлены ревью для владельца и детальный технический план; построен локальный Graphify-граф для навигации; проведены code/toolchain/browser проверки и исследование источников SMIL/YooKassa/crisis-flow.
- Решения: все тесты и базовые результаты бесплатны; платная интерпретация — SMIL/Lazarus; цена 120 ₽ configurable; три вида выдачи; одноразовые 100%-ные купоны; два редактируемых отчёта для клиента терапевта; канонический SMIL-график заморожен.
- Проверки и evidence: PHPUnit 85 tests / 1101 assertions; syntax/style checks прошли; PHPStan формально green с baseline 149; architecture check обнаружен сломанным; dompdf 3.1.5 имеет известные advisories.
- Изменённые файлы: `docs/audit/2026-08-15-owner-review.md`, `docs/audit/2026-08-15-agent-implementation-plan.md`; `graphify-out/` создан локально и не входит в Git.
- Не сделано / риски: функциональный код не менялся; платный flow остаётся непригодным к запуску; обнаруженные P0 не закрыты.
- Следующий шаг: создать каноническую систему управления и baseline.

### Управленческий каркас

- Этап / ветка / commit: этап 00, `codex/governance-roadmap`, `0866ae0`.
- Цель: превратить аудит и решения владельца в исполнимую программу, не зависящую от памяти одного чата.
- Сделано: новый индекс roadmap, product/engineering rules, phase structure, decision/status/traceability framework, changelog/worklog/checkpoint/lessons; README приведён к честному текущему состоянию; Superpowers удалён из обязательных инструкций.
- Решения: один work package — одна ветка; аудит обязан иметь полную трассировку; checkpoint не создаёт WIP-коммит автоматически; независимые проверки делегируются быстрым субагентам, интеграция остаётся ведущему.
- Проверки и evidence: active Markdown links — OK (25 файлов); `git diff --check` — OK; три независимых read-only review проверили roadmap, README и publication scope. Во время README-review PHPUnit без доступной MySQL: 85 tests, 1079 assertions, 3 integration errors подключения — это ограничение среды, а не зелёный gate.
- Изменённые файлы: см. staged diff первого governance-коммита.
- Не сделано / риски: код продукта сознательно не затронут; `README.md` исправлен, а полный truthfulness review `ARCHITECTURE.md`/`DEVELOPMENT.md` остаётся work package 00C. Security claims, зависящие от исправлений кода, окончательно синхронизируются в этапе 01; production claims — в этапе 08. GitHub CLI обнаружил недействительный token; публикация ждёт повторной аутентификации.
- Следующий шаг: просмотреть staged diff и сделать первый governance-коммит.

### Очистка состава репозитория перед публикацией

- Этап / ветка / commit: этап 00, `codex/governance-roadmap`, `0943c80`.
- Цель: не отправлять в актуальную ветку сгенерированные результаты и локальные debug-артефакты.
- Сделано: из Git-индекса выведены 2 PDF с индивидуальными результатами и 25 Playwright/root debug artifacts; локальные копии сохранены и покрыты `.gitignore`. Старый `composer.lock` не публикуется: сначала нужно обновить Dompdf, затем начать отслеживать безопасный lockfile.
- Проверки и evidence: PDF визуально проверены целиком — имени/email нет, но присутствуют score profile и session ID; поэтому принято консервативное решение не хранить их в Git. `composer validate` — pass; актуальный `composer audit` — 6 advisories для Dompdf 3.1.5, исправлены в 3.1.6.
- Не сделано / риски: файлы остаются в старой Git history и, вероятно, в remote; history rewrite не выполнялся без отдельного решения.
- Следующий шаг: определить visibility remote после `gh auth login`, затем выбрать обычное удаление или отдельную sanitization-процедуру истории.
