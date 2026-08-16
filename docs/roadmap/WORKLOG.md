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

## 2026-08-16

### 02.3B — fail-closed crisis resource registry foundation

- Этап / ветка / commit: этап 02, `codex/02-crisis-resource-registry-foundation`, commit pending.
- Цель: подготовить deployable storage boundary для вручную проверяемых кризисных ресурсов, не публикуя ни один контакт и не создавая clinical UI.
- Сделано: добавлена только incremental migration `crisis_resources` и синхронизированный schema snapshot. Каждая будущая запись имеет country/language/type, contact-or-URL, официальный source URL, дату/автора проверки и `active`; default `active = 0`. Реестр не имеет FK к session, не хранит IP и не получает seed data.
- Решения: country может быть `NULL` только для международного fallback. Никакой ресурс не станет доступен без будущего reader/query policy, а срок актуальности не придумывается: автоматическое скрытие по `verified_at` ожидает owner-approved threshold.
- Проверки и evidence: RED — migration contract не находил отсутствующую migration; GREEN — contract проверяет единственный incremental `CREATE`, все обязательные поля, индексы, snapshot и `down()`. Локальная `composer migrate` применила migration к development БД; полный gate: `composer validate`, `composer audit`, PHPUnit 131 tests/1248 assertions, PHPStan, lint, architecture check, baseline 148 и `git diff --check` — pass. CI pending.
- Изменённые файлы: incremental migration, `database/schema.sql`, migration contract test, текущие architecture/roadmap docs.
- Не сделано / риски: нет контактов, UI, resource reader, trusted GeoIP adapter, session choice persistence и freshness policy; это намеренная граница, а не готовый crisis-flow.
- Следующий шаг: review/commit/fast-forward/push/CI; затем запросить owner-approved текст, ресурсы и freshness threshold.

### 02.3A — manual-first CountryResolver boundary

- Этап / ветка / commit: этап 02, `codex/02-country-resolver` → `main`, `5942587`.
- Цель: подготовить безопасную, не зависящую от IP доменную границу для будущего выбора кризисных ресурсов, не добавляя пока публичный Crisis UI, контакты или GeoIP-инфраструктуру.
- Сделано: добавлены immutable `CountryResolution` и pure `CountryResolver`. Приоритет строго такой: ручной ISO-код → выбор текущей сессии → явно переданная доверенная server-side подсказка → `unknown`. Невалидные значения отклоняются; класс не читает `$_SERVER`, не разбирает IP и не вызывает внешние API.
- Решения: текущие `X-Forwarded-For`/Cloudflare-заголовки не имеют trusted-proxy boundary и не используются для кризисной географии. HTTP/proxy adapter, хранение ручного выбора и resource registry остаются следующими изолированными пакетами.
- Проверки и evidence: RED — 4 теста не находили отсутствующий `CountryResolver`; GREEN — 4 теста/4 assertions. Полный локальный gate: `composer validate`, `composer audit`, PHPUnit 130 tests/1232 assertions, PHPStan, lint, architecture check, baseline 148 и `git diff --check` — pass. PHPStan/lint выполнялись на PHP 8.5.3 при target PHP 8.3. GitHub Actions [31948360267](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31948360267) — success на PHP 8.3 и чистой MySQL migration chain.
- Изменённые файлы: `core/CountryResolution.php`, `core/CountryResolver.php`, `tests/CountryResolverTest.php`, `ARCHITECTURE.md`, phase/status/traceability docs.
- Не сделано / риски: не создаётся и не публикуется ни один кризисный контакт; browser UI, подтверждённый текст, session persistence, trusted proxy/local GeoIP integration и registry намеренно не реализованы без утверждённого product baseline.
- Следующий шаг: review diff, commit, fast-forward merge/push и дождаться CI; после этого — запросить owner-approved кризисный текст и стартовые ресурсы для 02.2B/02.3B.

### PAIR-03/04 — expiry boundaries, invite race и safe DB error logging

- Этап / ветка / commit: этап 01, `codex/01-pair-expiry-boundaries` → `main`, `897b29b`; `codex/01-pair-invite-race` → `main`, `af48b61`.
- Сделано: PAIR-03 покрывает истёкшие source invite и partner session. PAIR-04 использует DB unique constraint атомарно: конкурентная вторая пара не создаётся и route получает conflict вместо driver error. Database wrapper сохраняет только SQLSTATE-код, без raw driver text и bound token.
- Проверки и evidence: локальный полный gate `af48b61` — 116 tests/1193 assertions, PHPStan/lint/baseline/architecture/diff check pass. GitHub Actions [31940661228](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31940661228) — success.
- Следующий шаг: 02.1 — owner-интервью по retention/consent; затем data map и BDI safety-flow.

### PAIR-02 — bind submitted pair session to source invite

- Этап / ветка / commit: этап 01, `codex/01-pair-submit-binding` → `main`, `1cc772e`.
- Цель: не допустить, чтобы `pairSubmit` связал произвольную session второго партнёра с чужим source invite token.
- Сделано: `SessionManager::isPairSessionBoundToSourceToken()` проверяет exact session/token pair и active expiry/status; `TestController::pairSubmit()` делает проверку до сохранения answers и scoring. Нормальный `submit()` не менялся.
- Проверки и evidence: RED — controller contract отсутствовал; GREEN — controller placement regression. Lazarus integration доказывает true для правильной пары и false для unrelated source token. Полный локальный gate — 112 tests/1184 assertions, PHPStan/lint/architecture/diff check pass. GitHub Actions [31940284833](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31940284833) — success.
- Следующий шаг: PAIR-03 — expiry negative cases, затем честно закрыть либо оставить PAIR-01.

### SEC-05 — production web-root hygiene

- Этап / ветка / commit: этап 01, `codex/01-web-root-hygiene` → `main`, `21c77c7`.
- Цель: исключить доступ к diagnostic pages, ложным legacy claims и небезопасному test-session generator из production document root, не меняя SMIL scoring или result chart.
- Сделано: удалены `public/demo.php` и `public/test-smil.php`; `PublicWebRootTest` разрешает в `public/` только `index.php` как PHP entrypoint. Front controller удаляет `X-Powered-By`, задаёт Referrer-Policy и Permissions-Policy, а production error path теперь ловит `Throwable`. Apache policy распространяет заголовки также на error responses.
- Проверки и evidence: RED — test фиксировал три публичных PHP-файла; GREEN — 2 tests/9 assertions. Browser/HTTP QA: оба прежних URL вернули 404; `/api/health` вернул security headers без `X-Powered-By`. Полный локальный gate — 110 tests/1178 assertions, PHPStan/lint/architecture/diff check pass. GitHub Actions [31940056207](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31940056207) — success.
- Следующий шаг: PAIR-02 — связать `session_id` второго партнёра с invite token и покрыть negative cross-session case.

### 01.5C — repair duplicate pair-invite migration

- Этап / ветка / commit: этап 01, `codex/01-pair-migration-repair` → `main`, `52883c9`.
- Цель: восстановить deployable migration chain после того, как GitHub MySQL выявил повторное создание `uq_partner_token`, не меняя бизнес-логику парного теста или расчёты.
- Сделано: bootstrap migration возвращена к исторической схеме без нового index; `20260816000000_add_pair_invite_uniqueness.php` остаётся единственным инкрементальным созданием уникальности для уже существующих баз; актуальный `database/schema.sql` продолжает описывать итоговую схему. Добавлен `PairInviteMigrationContractTest`, который не допускает повторного DDL.
- Проверки и evidence: RED — contract-test падал на дублирующем index; GREEN — 1 test/6 assertions. Полный локальный gate — 108 tests/1169 assertions, `composer migrate`, PHPStan, lint, architecture и diff check — pass. GitHub Actions [31939695568](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31939695568) — success на PHP 8.3 и чистой MySQL migration chain.
- Не сделано / риски: PAIR-01 не закрывается целиком этим пакетом: одноразовость готова, но оставшиеся P1 access-boundary cases будут отдельной работой.
- Следующий шаг: SEC-05 — убрать production-доступ к demo/test files, покрыть HTTP-границу и проверить headers/stack traces.

### Checkpoint — пауза перед исправлением pair-invite migration

- Этап / ветка / commit: этап 01, `codex/checkpoint-pair-migration-20260816`, documentation checkpoint (commit будет создан отдельно); product code остаётся на `main` до `e8f1f53`.
- Цель: сохранить честное состояние после публикации 01.5A, 01.5B и PAIR-01, не продолжая новые функции при красном release gate.
- Сделано: зафиксированы published commits `92bf5e6` (route/session integrity), `2cc5321`/`e8f1f53` (server-side answer validation) и `46dade6` (single-use pair invite). Формулы и presentation SMIL не менялись.
- Проверки и evidence: перед публикацией — `composer test` 107 tests/1163 assertions, PHPStan и lint pass. GitHub Actions [31933926559](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31933926559) failed в шаге migration: `InitSchema` уже создаёт `uq_partner_token`, затем `20260816000000_add_pair_invite_uniqueness.php` получает MySQL 1061 при повторном `ADD UNIQUE KEY`.
- Не сделано / риски: release/deploy запрещён до исправления. SEC-04 и PAIR-01 не переводятся в «Закрыто», поскольку внешний full gate не дошёл до тестов.
- Следующий шаг: **01.5C — migration repair**: выбрать единственный корректный путь создания индекса, добавить regression чистой миграции, затем повторить полный локальный и GitHub gate.
- Состояние: закрыто `52883c9`; продолжение — SEC-05.

### 01.5A — integrity route slug и test session

- Этап / ветка / commit: этап 01.5A, `codex/01-route-session-integrity` → `main`, `92bf5e6`.
- Цель: запретить replay public result token под чужим test slug и смешивание разных тестов в pair flow, не меняя вычисления и доступ по корректной ссылке.
- Сделано: `SessionTestIntegrity` сравнивает `test_id` session с test row. Shared route guard добавлен в result, PDF, pair-status, autosave, submit, pair start и pair submit. Уже созданные результаты отключённого теста сохраняют доступ по корректному slug; старт нового теста по-прежнему требует active test.
- Проверки и evidence: unit/static negative coverage — 3 tests/7 assertions; вместе с Lazarus E2E — 6 tests/30 assertions; полный `composer test` — 103 tests/1153 assertions; PHPStan, lint, architecture check и diff check — pass.
- Проверки и evidence: GitHub Actions [31933655096](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31933655096) — success.
- Follow-up: server-side validation реализована отдельным 01.5B и подтверждена CI `31939695568`; не относится к этому уже закрытому package.
- Следующий шаг: завершён; текущий следующий P0 отмечен в `STATUS.md`.

## 2026-08-15

### 01.4 — границы токенов результата и пары

- Этап / ветка / commit: этап 01.4, `codex/01-token-boundaries` → `main`, `0d6a947`.
- Цель: исключить неоднозначный `session_token OR partner_token` lookup, не меняя scoring и действующий парный сценарий Лазаруса.
- Сделано: удалён loose API `getSessionByToken()`; `getSessionByResultToken()` читает только `session_token`; result, PDF, delete, autosave и pair-flow используют явный метод. `partner_token` документирован как relationship reference, а не credential. Устаревший PHPStan baseline entry снят (149 → 148).
- Проверки и evidence: узкие Lazarus E2E + baseline check — 4 tests/25 assertions; `composer analyse` — pass; полный `composer test` — 100 tests/1146 assertions; `composer lint`, architecture check и `git diff --check` — pass. Всё выполнено на PHP 8.5 при заявленной минимальной платформе PHP 8.3.
- Не сделано / риски: purpose/admin tokens, single-use invite и отдельная policy revocation относятся к последующим небольшим пакетам этапа 01; route slug/session integrity и validation остаются P0.
- Следующий шаг: подтвердить GitHub Actions [31904747962](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31904747962); затем 01.5. После этой записи работа поставлена на паузу по запросу владельца.

### 00E — тихая отчётность об инструментах работы

- Этап / ветка / commit: этап 00E, `codex/00-quiet-agent-status`, commit pending.
- Цель: оставить владельцу понятный статус выполнения, не перегружая обычные отчёты внутренними деталями оркестрации.
- Сделано: правила и checkpoint теперь требуют сообщать о внутреннем распределении работы только по прямому вопросу владельца или при реальном блокере.
- Решения: обязательными остаются сделанное, проверки, точный следующий шаг и состояние `продолжаю` / `остановлено`; правило не скрывает значимые риски или блокеры.
- Проверки и evidence: просмотр diff документации; functional code не менялся.
- Следующий шаг: commit, fast-forward merge и push; затем 01.4 token boundaries.

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
### 02.0 — решения владельца о хранении и AI-consent

- Этап / ветка / commit: этап 02.0, `codex/02-retention-consent-decisions` → `main`, `6d8fd58`.
- Цель: превратить ответ владельца в однозначные product и implementation constraints до изменения schema или public privacy copy.
- Решения: anonymous clinical-данные — 180 календарных дней с `created_at`; `therapist_case` — бессрочно только при явном назначении, с ручным удалением; отдельное не-предвыбранное согласие на external AI нужно только при заказе расширенной интерпретации.
- Сделано: решения записаны как D-024/D-025; добавлена целевая [RETENTION_POLICY.md](RETENTION_POLICY.md), обновлены product rules, factual data map, status, phase 02 и checkpoint. Правовая оговорка отделяет продуктовый срок от обязательных финансовых сроков и требует профессиональной проверки до production.
- Проверки и evidence: проверены cross-links документации и актуальный Git diff; функциональный PHP-код и scoring не менялись.
- Следующий шаг: 02.1 — спроектировать явную data-classification/schema и idempotent lifecycle cleanup, затем реализовать отдельным тестируемым package.

### 02.1 — anonymous lifecycle и artifact cleanup

- Этап / ветка / commit: этап 02.1, `codex/02-lifecycle-classification` → `main`, `87925ba`; follow-up migration repair `6152177`.
- Цель: заменить ошибочную очистку «30 дней TTL + 7 дней» на принятое правило 180 дней для anonymous-данных, не удаляя therapist-case автоматически.
- Сделано: добавлены `RetentionPolicy` и `SessionLifecycleService`; migration/schema вводят явный `retention_class` с безопасным default `anonymous`; cron удаляет только anonymous rows на/после 180-го дня, известные result/AI/pair PDF и session-bound activity logs. Связанные pair/legacy DB rows очищаются внешними ключами. `therapist_case` исключён из автоматической очистки.
- Проверки и evidence: `composer migrate` применил `20260816010000`; узко 6 tests/22 assertions; полный gate: Composer validate/audit clean, PHPUnit 122 tests/1215 assertions, PHPStan/lint/architecture/baseline — pass. Ранний sandbox run не имел loopback/network, проверки повторены с локальным разрешённым доступом.
- Сознательно не сделано: защищённое назначение и ручное удаление therapist-case, новые AI jobs/consents и financial retention — следующие отдельные пакеты. Legacy payment data не объявляются финансовым архивом.
- Проверки: GitHub Actions [31947662859](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31947662859) — success на чистой PHP 8.3/MySQL migration chain; 122 tests/1215 assertions, audit, PHPStan, lint, architecture и baseline check прошли.
- Следующий шаг: 02.2 BDI safety signal после утверждения владельцем кризисного текста и первого набора ресурсов.

### 02.1A — repair clean migration path

- Этап / ветка / commit: этап 02.1A, `codex/02-fix-retention-migration-chain` → `main`, `6152177`.
- Причина: GitHub Actions `31947571377` применил чистую migration chain и обнаружил `Duplicate column retention_class`: bootstrap migration ошибочно содержала DDL из инкрементальной migration.
- Исправление: из bootstrap удаляются только дублирующие column/index; итоговый `database/schema.sql` сохраняет полную актуальную схему, а `20260816010000` остаётся единственным источником upgrade для существующих и чистых баз.
- Проверка: GitHub Actions `31947571377` воспроизвёл defect; follow-up [31947662859](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31947662859) — success на чистом MySQL и полном PHP 8.3 gate.

### 02.2A — BDI server-side safety signal

- Этап / ветка / commit: этап 02.2A, `codex/02-bdi-safety-signal` → `main`, `16c4730`.
- Цель: не дать положительному item 9 потеряться в общем BDI total, но не менять clinical score, существующие рекомендации или неутверждённый пользовательский текст.
- Сделано: `ClinicalSafetySignal` создаёт строго структурированный сигнал `bdi_item_9` только для validated значений 1–3 с source question/value и числовой severity; `BeckDepressionModule` сохраняет `safety_signals`. При 0 или некорректном входе сигнал отсутствует. UI, country, IP/GeoIP и contacts не затронуты.
- Проверки и evidence: сначала RED — отсутствующий class и ожидаемый result key; затем unit/module contracts, полный local gate: Composer audit clean, PHPUnit 126 tests/1228 assertions, PHPStan/lint/architecture/baseline/manifest pass. Architecture checker был дополнен явным dependency requirement, иначе его standalone execution не видел новый core-class.
- Проверки: GitHub Actions [31948009328](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31948009328) — success на PHP 8.3/MySQL, включая чистую migration chain, PHPUnit, PHPStan, lint и architecture check.
- Следующий шаг: owner-approved Crisis UI text и начальные resources; затем 02.2B UI/country flow.
