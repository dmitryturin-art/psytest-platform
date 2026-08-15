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

### 01.3 — CSRF enforcement

- Этап / ветка / commit: этап 01.3, `codex/01-csrf-enforcement`, `e42eb89`.
- Цель: запретить все активные browser state-changing requests без session-bound CSRF token, не затронув scoring и retired webhook.
- Сделано: добавлен единый `CsrfMiddleware` для POST/PUT/PATCH/DELETE; `POST /webhook/yoomoney` — единственное явное исключение, поскольку retired controller отвечает 410 и не обрабатывает payload. AJAX autosave и delete requests передают `X-CSRF-Token`; формы используют hidden token.
- Проверки и evidence: negative missing/invalid token, valid header/form token, repeat token и explicit webhook exception покрыты `CsrfMiddlewareTest`; узко 7 tests/17 assertions; полный `composer test` — 100 tests, 1145 assertions; PHPStan, lint и `git diff --check` — pass.
- Следующий шаг: publish + реальный CI; после него 01.4 token boundaries.

### 00D follow-up — Linux PSR-4 compatibility Лазаруса

- Этап / ветка / commit: этап 00D, `codex/00-fix-lazarus-autoload`, commit pending.
- Причина: первый реальный GitHub Actions run `31903840762` корректно обнаружил 20 ошибок `Class PsyTest\Modules\Lazarus\LazarusModule not found`. На macOS ошибка скрывалась регистронезависимой файловой системой.
- Сделано: добавлено явное Composer PSR-4 mapping для `PsyTest\Modules\Lazarus\` → `modules/lazarus/` и regression-test `class_exists`. Формулы и данные Лазаруса не менялись.
- Проверки и evidence: после `composer dump-autoload --optimize` полный `composer test` — 93 tests, 1128 assertions, exit 0; Composer validate, baseline guard, PHPStan и `git diff --check` — exit 0.
- Процесс: первоначально незакоммиченный diff был создан на `main`; до commit он сразу перенесён в отдельную branch. Урок сохранён в `LESSONS.md`.
- Следующий шаг: commit/push, подтвердить зелёный GitHub Actions run и только затем закрыть 00D.

### 01 — containment legacy платного пути

- Этап / ветка / commit: этап 01, `codex/01-disable-broken-paid-flow`, `541be90`.
- Цель: исключить сломанную оплату и legacy YooMoney/AI side effects, пока новый YooKassa-flow не спроектирован и не проверен.
- Сделано: CTA расширенного разбора снят с обеих result templates; legacy GET/POST interpretation routes и YooMoney webhook переведены на stateless `RetiredPaymentController`. Все три endpoint отвечают `410 Gone`, не подключаются к базе, не создают заказ, не вызывают AI и не принимают webhook payload.
- Проверки и evidence: `vendor/bin/phpunit tests/RetiredPaymentControllerTest.php tests/LegacyPaidFlowContainmentTest.php` — 4 tests, 15 assertions, exit 0; PHP syntax — pass; `composer analyse` — exit 0; `composer lint` — exit 0; `git diff --check` — exit 0.
- Следующий шаг: интегрировать containment package; затем CI или отдельные CSRF/token work packages.

### 01 — dependency safety: Dompdf

- Этап / ветка / commit: этап 01, `codex/01-dompdf-security`, `7272e51`.
- Цель: устранить известные security advisories Dompdf и сделать dependency state воспроизводимым, не меняя тестовые формулы, платёжный flow или интерфейс.
- Сделано: `dompdf/dompdf` обновлён с 3.1.5 до 3.1.6, транзитивный `masterminds/html5` — с 2.10.0 до 2.10.1; безопасный `composer.lock` вновь включён в состав репозитория. По решению владельца минимальная версия проекта поднята с PHP 8.1 до PHP 8.3; Composer разрешает и фиксирует зависимости от этой минимальной платформы. Добавлен узкий in-memory smoke-test PDF с кириллицей: он не читает старые PDF и не создаёт пользовательские отчёты.
- Проверки и evidence: `composer validate --strict --no-check-publish` — exit 0; `composer audit` — `No security vulnerability advisories found`; `composer show dompdf/dompdf --locked` — v3.1.6; `vendor/bin/phpunit tests/PDFGeneratorSmokeTest.php` — 1 test, 2 assertions, exit 0; полный `composer test` — 88 tests, 1112 assertions, exit 0; `composer check-platform-reqs --lock` — pass; `composer baseline:check` — exit 0; `composer analyse` — exit 0; `composer lint` — exit 0 (вне sandbox из-за loopback requirement); `php bin/check-architecture.php` — exit 0; `git diff --check` — exit 0.
- Известные ограничения: старые PDF-артефакты и Git history намеренно не рассматриваются по решению владельца. Smoke не заменяет отдельную browser/print-regression проверку пользовательского SMIL-графика, которая относится к UI-этапу.
- Изменённые файлы: `.gitignore`, `composer.json`, `composer.lock`, актуальные PHP-version docs, `tests/PDFGeneratorSmokeTest.php`, текущая roadmap-документация.
- Следующий шаг: интегрировать dependency package, затем отдельным work package сделать containment сломанного платного пути.

### 00B — воспроизводимый quality baseline

- Этап / ветка / commit: этап 00B, `codex/00-reproducible-baseline`, `b6756dd`.
- Цель: сделать локальные проверки честными и исполнимыми, не меняя расчёты тестов или пользовательский flow.
- Сделано: `bin/check-architecture.php` использует корень проекта, а не каталог `bin/`; checker теперь проверяет также модуль Лазаруса, возвращает ненулевой exit code при найденной ошибке и ловит `Throwable`. Добавлены regression-тест checker-а и `composer baseline:check`, который допускает ровно 149 целых PHPStan baseline entries.
- Решения: несуществующая команда `bin/check-module.php --all` удалена из объявленного общего gate; её можно вводить только вместе с модульным контрактом в этапе 03. CI не добавлялся до исправления dependency audit, чтобы не создать формально зелёную, но неполную проверку.
- Проверки и evidence: PHP 8.5.3; `composer validate --strict --no-check-publish` — exit 0; `php bin/check-architecture.php` — exit 0 (SMIL, BAI, BDI, HADS, Лазарус); architecture regression запускает checker из системного temp-каталога, а не из repository cwd; `composer baseline:check` — exit 0, 149/149; `vendor/bin/phpunit tests/ArchitectureCheckTest.php tests/PhpStanBaselineCheckTest.php` — 2 tests, 9 assertions, exit 0; `composer analyse` — exit 0; `composer lint` — exit 0; `git diff --check` — exit 0.
- Известные ограничения: полный `composer test` в sandbox завершается с 3 integration errors подключения к локальной MySQL (`Operation not permitted`); это не считается pass. `composer lint` прошёл только после разрешённого локального запуска вне sandbox (нужен loopback TCP); `composer audit` остаётся красным из-за Dompdf 3.1.5.
- Изменённые файлы: `bin/check-architecture.php`, `bin/check-phpstan-baseline.php`, тесты architecture/baseline, `composer.json`, текущие roadmap rules/status/traceability.
- Следующий шаг: отдельная ветка security update Dompdf, затем минимальный CI в 00D.

### Продуктовое направление: лендинг и необязательный аккаунт

- Этап / ветка / commit: этапы 04/09, `codex/00-product-landing-account`, commit pending.
- Цель: зафиксировать маркетинговую витрину будущего продукта и историю тестов без принудительной регистрации.
- Решения: отдельный лендинг объясняет бесплатный результат и дополнительный разбор, показывает честные обезличенные примеры и скромно ссылается на hypnocorrection.ru; аккаунт добровольный, anonymous result links сохраняются.
- Не сделано / риски: ни landing, ни account не реализуются до security/privacy gates; для аккаунта нужен отдельный threat model, consent/retention design и usability prototype.
- Следующий шаг: включить лендинг в выбор дизайн-направления этапа 04; account оставить этапу 09 после стабильного production.

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
