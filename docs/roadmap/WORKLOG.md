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

## 2026-08-25

### docs — образец и промпты расширенного ИИ-разбора Лазаруса

- Этап / ветка / commit: справочный задел этапа 07; файлы созданы по прямой просьбе владельца, без ветки/коммита (не запрашивались).
- Цель: зафиксировать черновики версионированных промптов (`lazarus | individual|pair | professional|clear`) и обезличенный образец выходного отчёта.
- Сделано: документ с ключами промптов, схемой передаваемых ИИ данных из `LazarusModule.php`, общим техническим слоем и вариативными частями по PRODUCT_RULES §3/§6/§9, демонстрационным `clear`-образцом и планом тестирования на fixtures.
- Инцидент: владелец ссылался на `docs/prompt-for-lazarus.md` как источник собственного образца; при чтении в начале хода файл был пуст (0 байт), и агент записал свою генерацию поверх него в тот же путь. Содержимое владельца утеряно; поиск в локальной истории редакторов, Hot Exit backups, Корзине, Time Machine-снапшотах и логах Codex/Kilo копии не нашёл. Генерация агента перенесена в отдельный `docs/lazarus-ai-report-prompts.md`, `docs/prompt-for-lazarus.md` возвращён в пустое состояние под образец владельца. Урок: файл из @-ссылки с пустым содержимым — повод спросить владельца, а не писать поверх него.
- Решения: нет новых владельческих решений; документы не являются runtime-артефактами и не закрывают пунктов phase-файлов.
- Проверки и evidence: код не менялся, gate не запускался (docs-only). Graphify freshness — `STALE` (82 changed): внешнее semantic-обновление требует отдельного разрешения на передачу изменённых исходников внешнему LLM; fallback — прямое чтение исходников (`LazarusModule.php`, `metadata.json`, `questions.json`, PRODUCT_RULES).
- Изменённые файлы: `docs/prompt-for-lazarus.md` (возвращён в пустое состояние под образец владельца), `docs/lazarus-ai-report-prompts.md` (новый, генерация агента), `docs/roadmap/WORKLOG.md`.
- Не сделано / риски: собственный образец владельца не восстановлен; промпты не проверены на реальном провайдере; реализация backend prompt store относится к этапам 06/07.
- Следующий шаг: владелец восстанавливает/повторно сохраняет свой образец в `docs/prompt-for-lazarus.md`; при открытии этапа 07 промпты переносятся в версионированный draft/published store и прогоняются на fixtures.

### 03.1C — декларативная схема ответов (Module API v2, WP3 часть 2)

- Этап / ветка / commit: этап 03, `codex/03-answer-schema` → `main`.
- Цель: формализовать валидацию ответов через декларативную схему модуля (WP3, вторая половина — schema validator).
- Сделано: `getAnswerSchema(): array` в `TestModuleInterface` (answer_type: ternary/scale10/options, key_template: plain/dual, extra_keys, requires_gender); дефолт options/plain/['gender','age']/false в Base; SMIL — ternary/plain/['gender']/true, Lazarus — scale10/dual/['gender']/false; `AnswerValidator` переписан на схему (без знаний о конкретных модулях), с сохранением исходного поведения: per-question значения для options (не глобальный список), dual-ключи, gender-требование; `AnswerSchemaContractTest` (21 тест) — форма, когерентность (dual⇔scale10, gender⇔ternary), валидные/out-of-range ответы, Lazarus отбраковывает plain-ключи, SMIL требует gender.
- Инварианты: поведение валидатора идентично прежнему (замерено per-question семантикой options); scoring/шаблоны не тронуты.
- Проверки: полный gate — PHPUnit 225 tests/1945 assertions, PHPStan level 6 `[OK] No errors`, lint, architecture (в т.ч. из temp-dir), baseline 148, validate, audit — pass.

### 03.1B — capability registry (Module API v2, WP3 часть 1)

- Этап / ветка / commit: этап 03, `codex/03-golden-characterization` (продолжение) → `main`.
- Цель: перевести неявные флаги возможностей модулей в декларативный реестр (WP3 этапа 03, часть — capability registry).
- Сделано: `modules/ModuleCapability.php` (pair/chart/pdf/paid_interpretation/clinical_signal); `getCapabilities(): list<string>` в `TestModuleInterface`; дефолт `[PDF]` в `BaseTestModule`; `supportsPairMode()` выведен из capability PAIR и больше не переопределяется (удалены override в Lazarus/BDI/SMIL); декларации — Lazarus [pair, pdf], SMIL [chart, pdf], BDI [clinical_signal, pdf], BAI/HADS [pdf] (наследуют дефолт); `ModuleCapabilityContractTest` (10 тестов) закрепляет декларации, валидность констант, отсутствие дублей и деривацию pair. Контроллеры не менялись — slug-ветвлений в них нет (проверено grep), реестр защищает от их появления.
- Попутно: `bin/check-architecture.php` — `modules/ModuleCapability.php` добавлен в requiredFiles и во все ручные require-блоки (без него чекер из чужого cwd падал «Class not found»; поймано ArchitectureCheckTest).
- Инварианты: поведение рантайма не изменилось (supportsPairMode возвращает те же значения); scoring/шаблоны не тронуты.
- Проверки: полный gate — PHPUnit 204 tests/1903 assertions, PHPStan level 6, lint, architecture (в т.ч. из temp-dir), baseline 148, validate, audit — pass.
- Следующий пакет: schema validator ответов (вторая часть WP3).

### 03.1A — golden characterization всех модулей перед рефакторингом

- Этап / ветка / commit: этап 03, `codex/03-golden-characterization` → `main`.
- Цель: WP1 этапа 03 — зафиксировать текущие выводы модулей, чтобы рефакторинг Module API v2 доказывал паритет тестами, а не обещаниями.
- Сделано: `tests/fixtures/golden/` — детерминированные наборы ответов + пин полного вывода `calculateResults` и `generateInterpretation` для BAI, BDI, HADS, Lazarus (SMIL уже покрыт `tests/fixtures/smil-*`); `GoldenModuleOutputsTest` (8 тестов) требует точного совпадения массивов (assertSame) и запрещает менять scoring/тексты без явного обновления фикстуры с источником; фаза 03 переведена «В работе».
- Инварианты: прод-код не менялся вообще (только тесты и фикстуры); BDI safety_signals попали в пин — клинический сигнал тоже под паритетом.
- Проверки: полный gate — PHPUnit 194 tests/1875 assertions, PHPStan level 6, lint, architecture, baseline 148 — pass.

### 08.1G — backup/restore drill и production runbook

- Этап / ветка / commit: этап 08, main.
- Цель: доказать, что pre-deploy дампы реально восстанавливаются, и зафиксировать процедуру production-выкладки до go-live.
- Сделано: полный restore drill на staging — дамп `pre-deploy-c62f34a.sql.gz` восстановлен в ту же базу с префиксом `drill_` (8/8 таблиц; построчная сверка: test_sessions 31=31, activity_log 131=131, pair_comparisons 1=1, tests 5=5; выборка данных читается). phinxlog 9 vs 8 — ожидаемо: дамп сделан до миграции c62f34a. Найдено и задокументировано: (1) CLI-пользователь не может создавать базы — для DR в отдельную базу нужен шаг в панели; (2) при восстановлении в ту же базу требуется переименование и таблиц, и CONSTRAINT-имён (конфликт FK-имён — InnoDB требует уникальности в базе). Drill-таблицы удалены, живые данные не тронуты (31 сессия). Создан `PRODUCTION_RUNBOOK.md`: предусловия владельца, 8-шаговая выкладка, откат кода/данных, проверенная процедура восстановления, честные границы (мониторинг, ночной дамп, фискальные, legal review), go-live чек-лист.
- Проверки: drill-восстановление со сверкой count по 5 таблицам + выборка строк; очистка подтверждена (0 drill-таблиц, 31 сессия на месте).

### 08.1F-доп — cleanup-cron настроен владельцем

- Владелец добавил задание в панели Beget 25.08: `/usr/local/bin/php8.3 /home/q/qdesign/test.23time.ru/current/bin/cleanup-sessions.php >/dev/null 2>&1`, расписание 03:17 ежедневно — точно по CRON_CLEANUP.md.
- Обещание «анонимные данные 180 дней» теперь исполняется автоматически. Первая автоматическая отработка — 26.08 ~03:17; проверка: свежая строка в `current/storage/logs/cleanup.log`.
- Уточнение владельца: PHP 8.2 в его панели — это wp-cron WordPress-сайта 23time.ru (отдельное приложение); платформа test.23time.ru работает на 8.3.20 (замер 25.08).

### 08.1F — стабильная точка релиза и готовность cleanup-cron

- Этап / ветка / commit: этап 08, main (docs + серверная настройка).
- Цель: сделать ежедневную очистку данных настраиваемой без риска устаревания пути и выполнить обязательный старт этапа 08 без решений владельца.
- Сделано: на staging создан симлинк `current` → активный релиз; `bin/cleanup-sessions.php` проверен через стабильный путь (`EXIT=0`, `0 anonymous sessions removed`, лог `storage/logs/cleanup.log` пишется); полная каноническая последовательность выкладки (8 шагов, включая шаг `current`) зафиксирована в `BEGET_STAGING.md`; пошаговая инструкция cron для панели Beget — `docs/roadmap/CRON_CLEANUP.md` (команда, расписание, проверка, границы).
- Инварианты: путь в cron не зависит от будущих релизов; therapist_case скрипт не трогает; retention 180 дней соответствует утверждённой политике.
- Проверки: запуск скрипта на staging через `current` — exit 0; лог-строка подтверждена.
- Далее по этапу 08: runbook production-выкладки, backup/restore drill, monitoring; настройки панели Beget (cron) и фискальные вопросы — за владельцем.

### 02.7C-deploy — выкладка удаления IP/UA-колонок на staging

- Release / ветка: `c62f34a` (PR #27, CI success: fast gate + MySQL 5.7 + 8.0).
- Процедура: первая выкладка через `bin/build-release.sh` — артефакт собран, верификация `git ls-files public` прошла, `smil-profile-bg.png` на месте; sha256 совпал; `.env` сервер-сайд из `1ccd53f`; pre-deploy dump `backups/pre-deploy-c62f34a.sql.gz`.
- Миграция: `20260825120000` применена (2.2s) — `ip_address`/`user_agent` удалены из `test_sessions` и `activity_log`; в схеме не осталось ни одной такой колонки (information_schema = 0).
- Smoke: home/tests/privacy/terms/health/admin/страница результата — 200; данные 31 сессия / 1 пара без изменений. Rollback: `public_html` → `releases/1ccd53f/public` (дамп сохранён).

### 02.7C — удаление legacy IP/user-agent колонок (D-035)

- Этап / ветка / commit: этап 02, `codex/02-drop-legacy-ip-ua` → `main`.
- Цель: завершить минимизацию технических метаданных — владелец одобрил план очистки (D-035).
- Сделано: миграция `20260825120000` удаляет `ip_address`/`user_agent` из `test_sessions` и `activity_log` вместе со старыми значениями (down — IrreversibleMigrationException, по образцу D-032); из `SessionManager` (2 места) и `TherapistCaseService` убраны явные NULL-передачи; `MigratedSchemaTest` получил `assertMissingColumn`-контракт на 4 колонки; `SessionDataMinimizationTest` теперь доказывает, что опции метаданных игнорируются API и колонок не существует; `TherapistCaseServiceTest` убраны ссылки на удалённые колонки; DATA_MAP (строка IP/UA + снят открытый вопрос №1), ARCHITECTURE, фаза 02 (WP11), трейсабилити DATA-01 обновлены.
- Инварианты: scoring, clinical flows и owner-безопасность не тронуты; `owner_login_attempts` IP не хранит по схеме — исключений нет.
- Проверки: полный gate — validate/audit/migrate/PHPUnit 186 tests/1835 assertions/PHPStan level 6/lint/architecture/baseline 148 — pass.

### 04.0H-deploy — выкладка закрытия UX-03 на staging

- Release / ветка: `1ccd53f` (PR #26, CI success: fast gate + MySQL 5.7 + 8.0).
- Процедура: артефакт из lockfile, sha256 совпал (`4aeb72d8…`); `.env` сервер-сайд из `4775dc4`; pre-deploy dump `backups/pre-deploy-1ccd53f.sql.gz`; миграций нет; `public_html` → `releases/1ccd53f/public`.
- Smoke: home 200; health ok; css содержит `.pv-hit`, низкоконтрастный `#9aa5af` отсутствует; `smil-profile-classic.js` отдаётся 200.
- Данные: 28 сессий / 1 пара — без изменений. Rollback: `public_html` → `releases/4775dc4/public`.

### 04.0H — закрытие UX-03 и этапа 04

- Этап / ветка / commit: этап 04, `codex/04-ux03-accessibility` → `main`.
- Цель: закрыть последний finding этапа 04 (UX-03: Lazarus legends/touch/accessibility) и формально завершить этап.
- Сделано: точки парного графика получили невидимые touch-зоны попадания 24px (r=12) с теми же тултипами; подписи осей и легенда графика перекрашены с #9aa5af/#7f8c8d на #667085 (контраст ≥4.5:1, WCAG AA); из main.css удалены 194 строки мёртвых стилей отменённой «бабочки» (0 ссылок из шаблонов); в PairComparisonVisualTest добавлены контраст-контракт (WCAG-расчёт в тесте) и проверка 32 touch-зон; в фазы 06/07 записаны ответы владельца по доставке и лёгкой авторизации.
- Инварианты: SMIL не затронут — profile-chart.twig и smil-profile-classic.js без изменений (0 строк в диффе), CSS-диф не содержит ни одной smil-строки, защитные тесты PublicCatalogPresentationTest/SmilModuleSectionsTest/SmilEndToEndTest и golden-фикстуры зелёные.
- Проверки: полный gate — validate/audit/migrate/PHPUnit 186 tests/1828 assertions/PHPStan level 6/lint/architecture/baseline 148 — pass.
- Следствие: этап 04 закрыт (все UX-01..03 закрыты); активные фронты — 02 и 08.

### 00M — решения владельца по ревью: заморозка legacy, генераторы, закрытие 00

- Этап / ветка / commit: этап 00, `codex/04-pair-comparison-visual` (продолжение), main.
- Цель: исполнить решения владельца от 25.08 по находкам ревью.
- Сделано: D-033 — `services/PaymentService|AIInterpretationService|EmailService` заморожены с пометками в файлах и ARCHITECTURE (не удалять: концепция возвращается на 06/07 в новой модели); D-034 — зафиксирована продуктовая модель платных разборов (база бесплатна всем; платный ИИ-отчёт без обязательной авторизации; купонные клиенты получают отчёт только после правки и одобрения владельца; при авторизации — история прохождений), уточнения добавлены в phase-файлы 06/07; dev-скрипты `create-fake-smil-session.php` и `create-full-smil-session.php` перенесены в `docs/archive/scripts/` (канонический генератор — `bin/simulate-smil-test.php`); этап 00 закрыт (exit criteria выполнены); в `BEGET_STAGING.md` убрана задача ротации SSH/DB-кредов — владелец подтвердил, что её не заказывал.
- Не закрыто намеренно: этап 04 остаётся активным — UX-03 (legends/touch/accessibility Лазаруса) в трейсабилити «Запланировано»; закрывается отдельным пакетом 04.0H с проверкой, затем 04 закрывается.
- Проверки: полный gate — validate/audit/migrate/PHPUnit 185/1817/PHPStan/lint/architecture/baseline — pass (см. commit).

### 04.0G-deploy — выкладка графика пары на staging

- Release / ветка: `4775dc4` (PR #25, merge в `main`), `codex/04-pair-comparison-visual`.
- Процедура: артефакт собран локально из lockfile (vendor --no-dev), sha256 совпал после загрузки (`8bb795fa…`); `.env` скопирован сервер-сайд из `releases/3a2daa8` (mode 600); pre-migration dump `backups/pre-deploy-4775dc4.sql.gz`; `phinx migrate` — новых миграций нет, цепочка уже up; `public_html` атомарно переключён на `releases/4775dc4/public`.
- Smoke: home 200; `/api/health` ok; `main.css` 200 и содержит `pair-chart-block`; security-заголовки на месте.
- Данные не затронуты: в staging БД до и после — 28 test_sessions (все с рассчитанными результатами), 1 pair_comparison. Ответы и результаты живут в MySQL, релиз меняет только код.
- Rollback: атомарно вернуть `public_html` на `releases/3a2daa8/public`; дамп и прежние релизы сохранены.
- Далее: ручная проверка владельцем тултипов графика (наведение и тап) на desktop и 390×844.

### 04.0G — веб-график совмещённых профилей пары (вариант C) с тултипами

- Этап / ветка / commit: этап 04, `codex/04-pair-comparison-visual` → `main`.
- Цель: заменить мёртвый Chart.js-контур (CDN грузился на каждой странице, скрипты не рендерили ни один canvas) нативным графиком наложения профилей партнёров по выбору владельца (вариант C — наложенные профили-линии с красными зонами расхождений).
- Сделано: новый блок `blocks/pair-chart.twig` + секция `pair_chart` (order 45) в `LazarusModule::buildSections()` — только для web, в PDF не попадает; геометрия графика считается в `LazarusModule::pairChartData()`, шаблон только рисует; график добавлен также на страницу `/pair/{id}`; тултипы по точкам (пункт, домен, текст, оценки обоих партнёров, расхождение) на нативном JS с поддержкой наведения, тапа и клавиатурного фокуса; удалены мёртвые `results.js`, `smil-profile.js`, `smil-scale-indicator.js` и Chart.js CDN из `layout.twig` и `result-page.twig`; стили графика и тултипа добавлены в `main.css`.
- Инварианты: детальная таблица сравнения (web) и компактная PDF-таблица 04.0F не менялись — это закреплено новыми guard-тестами; scoring и клинические тексты не тронуты.
- Проверки: полный gate локально — validate/audit/migrate/PHPUnit 185 tests/1817 assertions/PHPStan level 6/lint/architecture/baseline 148 — pass. Новые тесты: секция графика отсутствует в PDF-контексте; 32 точки (16×2), данные тултипов на 16 пунктов, aria-label присутствуют.
- Сознательно не проверено здесь: визуальное поведение тултипов в реальном браузере (390×844 и desktop) — нужна ручная проверка владельцем на staging, как для остальных UI-пакетов этапа 04.

### 00L — применение находок ревью от 25.08: документация и gate

- Этап / ветка / commit: этап 00, `codex/00-governance-review-followup` → `main`.
- Цель: закрыть механические пункты ревью от 25.08 с нулевым продуктовым риском.
- Сделано: `docs/architecture.md` (черновик февраля, ложно помечен «Актуально»), `DEPLOYMENT.md` (описывал retired AI-flow) и `QUICKSTART.md` (рекомендовал PHP 8.2) перенесены в `docs/archive/` с баннером «исторический черновик» и ссылками на актуальные документы; полный gate в `AGENTS.md` дополнен обязательным шагом `composer migrate` перед `composer test` (устраняет ложнопадение `MigratedSchemaTest` на дрейфе локальной БД); `/output/` добавлен в `.gitignore`; ревью от 25.08 сохранено как `docs/audit/2026-08-25-project-review.md`.
- Проверки: полный gate локально — validate/audit/migrate/PHPUnit 180 tests/1647 assertions/PHPStan level 6/lint/architecture/baseline 148 — pass; входящих ссылок на архивированные файлы из живых документов нет (grep).

## 2026-08-24

### 08.5 — staging deployment исправленного PDF Лазаруса

- Этап / ветка / commit: этап 08, `codex/08-deploy-04f-staging`; runtime release `3a2daa8` (PR #23).
- Цель: выложить 04.0F на `test.23time.ru`, не меняя scoring, клинический текст, SMIL-график, payment/AI или production.
- Сделано: production-артефакт собран из точного commit `3a2daa8` с dependencies из lockfile, без `.env` и dev tools; SHA-256 `2c2b874d88f6aa0baaca2b3067704264f1bc23662d43c6757024b653cf3f02e2` совпал после загрузки. Перед необратимой cleanup-миграцией подтверждено, что `ai_processing_consents` и `crisis_resources` существуют, но содержат по 0 строк; сохранён dump `backups/db-pre-3a2daa8-20260824.sql` mode `600`, SHA-256 `e99471ef8171f568b380b456caf4997c3b1854a15cc55daf7a7864736a8e839d`. Миграция применена, обе таблицы отсутствуют, все 8 migrations `up`. `public_html` атомарно переключён с `5da9ab5/public` на `3a2daa8/public`; прошлый release сохранён для rollback.
- Проверки и evidence: post-merge [GitHub Actions 32744081534](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32744081534) — success: fast gate 29 секунд, MySQL 5.7 — 40 секунд, MySQL 8.0 — 46 секунд. Server PHP 8.3 подтвердил entrypoint и Phinx. Внешний smoke: HTTPS `/api/health` — `200`/`ok`, `/tests` — `200`, HTTP `/tests` — `301` на HTTPS, retired interpretation — `410`, выключенная `/admin/login` — `404`; cookie содержит `Secure`, `HttpOnly`, `SameSite=Lax`, dynamic security headers приходят по одному разу. `DocumentationCurrentStateTest` — 4 tests / 88 assertions; `git diff --check` — pass. Graphify freshness — `STALE` (43 changed, 10 deleted): внешнее semantic-обновление без отдельного разрешения не запускалось, граф не использовался; fallback — исходные runbook/status-файлы и прямой server smoke.
- Изменённые файлы: runtime source не менялся; обновлены только roadmap/status/runbook records после deployment.
- Не сделано / риски: владелец ещё должен скачать и визуально принять конкретный полный pair result PDF на staging. Архив содержит безвредные macOS `LIBARCHIVE.xattr.com.apple.provenance` headers, из-за чего `tar` шумно предупреждает при распаковке; содержимое и checksum корректны, но упаковку стоит очистить в будущем deployment automation. Production, retention cron и credential rotation не выполнялись.
- Следующий шаг: владелец проверяет полный pair result PDF Лазаруса на staging; затем отдельно настраивается ежедневный retention cleanup.

### 04.0F — исправление переполнения PDF парного результата Лазаруса

- Этап / ветка / commit: этап 04, `codex/04-fix-lazarus-pair-pdf-overflow`, `dd6eff8`.
- Цель: довести до конца замечание владельца — общая таблица двух участников в скачиваемом result PDF выходила за пригодную компоновку документа, несмотря на заявленный 04.0E landscape polish.
- Первопричина: `ResultController::pdf()` помечал общий массив результатов как PDF, но `LazarusModule::buildSections()` не передавал этот контекст в data парной секции. Поэтому `pair-comparison.twig` выбирал web-ветку с длинными заголовками; landscape применялся только к отдельному `/pair/{id}/pdf`, а обычный `/result/{slug}/{token}/pdf` оставался portrait.
- Сделано: pair-секция явно получает `is_pdf`; result PDF с pair comparison генерируется как A4 landscape; compact pair section начинается с новой страницы, строки не разрываются, а размер и padding позволяют отдельному pair PDF занимать две страницы без одиночной последней строки. Web-result, расчёты и protected SMIL chart не менялись.
- Проверки и evidence: RED — два regression-теста подтвердили portrait result PDF и отсутствие row-break protection; GREEN — targeted PHPUnit 10 tests / 137 assertions. Полный synthetic result на реальных 16 формулировках Лазаруса отрендерен Poppler: вместо 14 страниц с web-таблицей получено 6 landscape-страниц, compact pair table занимает последние две, все шесть колонок и строки находятся в границах. Отдельный pair PDF — 2 страницы A4 landscape. Composer validate и audit — pass; PHPStan, lint, architecture, baseline 148 и diff check — pass. После merge 00K общий fast gate — 167 tests / 1562 assertions. [GitHub Actions 32743794319](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32743794319) — success за 28 секунд; DB matrix корректно skipped для PDF/UI scope.
- Не сделано / риски: на момент завершения code package staging оставался на `5da9ab5`; последующий deployment зафиксирован отдельной записью 08.5. Graphify freshness: `STALE` (33 changed, 10 deleted); внешняя semantic extraction без отдельного разрешения не запускалась, граф не использовался как evidence, fallback — source inspection, PHPUnit и полный Poppler render.
- Следующий шаг: отдельный staging deployment и повторная проверка владельцем; deployment выполнен в 08.5.

### 00K — CI по риску без дублирования общего gate

- Этап / ветка / commit: этап 00, `codex/00-risk-based-ci` → `main`, `58e3f97` (source `8f1362f`, PR #22).
- Цель: сократить время и расход CI на UI/PDF/docs-пакетах, не теряя проверку PHP 8.3 и совместимость staging MySQL 5.7 с MySQL 8.0.
- Сделано: общий fast gate (non-DB PHPUnit, dependency audit, PHPStan, formatting, baseline и architecture) выполняется один раз. Тринадцать DB-зависимых тестов из шести классов выделены в PHPUnit group `database`; только эта группа вместе с чистыми migrations запускается в матрице MySQL 5.7/8.0. В PR матрицу включает проверяемый path-classifier; push в `main` и manual run всегда требуют обе DB-версии.
- Решения: PDF/Twig/CSS/docs проходят быстрый gate; migrations, persistence-код, DB-тесты, Composer dependencies и CI-файлы требуют матрицу. Это оптимизация порядка проверок, а не ослабление release gate: до deployment любое изменение уже находится в `main`, где матрица обязательна.
- Проверки и evidence: classifier/docs regressions — 10 tests / 112 assertions; полный fast gate — 165 tests / 1553 assertions. Composer validate/audit, PHPStan, lint, architecture check, baseline check, YAML syntax и `git diff --check` — pass. Полный локальный `composer test` обнаружил только 13 ожидаемых connection errors DB-группы из-за недоступной MySQL (178 tests / 1553 assertions), поэтому это не заявлялось зелёным DB-gate. [GitHub Actions 32743418402](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32743418402) — success: fast gate 25 секунд, MySQL 5.7 — 42 секунды, MySQL 8.0 — 49 секунд. Graphify freshness: `STALE` (42 changed: 18 code, 23 documents, 1 papers; 10 deleted); граф не использовался, fallback — source inspection и regression tests.
- Изменённые файлы: GitHub Actions workflow, Composer scripts, CI scope classifier, PHPUnit group attributes/tests, current-state developer docs и roadmap records.
- Не сделано / риски: fast-only классификация подтверждена PR #23; полный DB-gate остаётся обязательным на каждом push в `main`. Product runtime, migrations, scoring, clinical copy и staging не менялись.
- Следующий шаг: сохранить полную DB-матрицу перед deployment и использовать fast gate для следующих low-risk PR.

### 00J — снятие неподключённых AI/crisis заделов

- Этап / ветка / commit: этап 00, `codex/00-remove-deferred-scaffolding`, commit pending.
- Цель: выполнить решение владельца по §7.4 внешнего review и D-032 — удалить неподключённые AI-consent и country/crisis resources scaffolding.
- Сделано: удалены `AiProcessingConsentService`, `CountryResolver`/`CountryResolution` и их tests. `schema.sql`, data map, архитектура, rules и traceability приведены к фактическому отсутствию этих сущностей. Новая необратимая migration удаляет `ai_processing_consents` и `crisis_resources`, если они есть в развёрнутой БД; updated `MigratedSchemaTest` требует их отсутствия после полного `phinx migrate`.
- Проверки и evidence: `composer validate --strict --no-check-publish`, `composer audit`, PHPStan, lint, architecture check, baseline check, `DocumentationCurrentStateTest` (4 tests / 86 assertions) и `git diff --check` — pass. Локальная MySQL отсутствует, поэтому фактическую migration chain и schema-test докажет CI на MySQL 5.7 и 8.0. Graphify freshness: `STALE` (32 changed, 10 deleted); инкрементное обновление остановлено политикой среды, так как могло передать изменённый код внешнему LLM без отдельного разрешения. Граф не использован; fallback — source inspection и CI.
- Не сделано / риски: legacy AI/payment tables и будущие этапы 06–07 не входят в пакет. Cleanup-миграция должна быть применена на staging отдельно от merge.
- Следующий шаг: PR/CI; после merge не начинать AI/payment implementation.

### 00I — поведенческие schema-contracts

- Этап / ветка / commit: этап 00, `codex/00-schema-contracts`, commit pending.
- Цель: выполнить третью задачу §7.3 внешнего review: заменить четыре теста на текст миграций проверкой реальной схемы после `phinx migrate` и убрать второй hardcoded route list.
- Сделано: удалены `CrisisResourceRegistryMigrationContractTest`, `AiProcessingConsentMigrationContractTest`, `SessionRetentionMigrationContractTest` и `PairInviteMigrationContractTest`. Новый `Integration/MigratedSchemaTest` read-only проверяет на тестовой БД columns, indexes, отсутствие IP/user-agent в consent, и foreign-key `ON DELETE CASCADE`. `DocumentationCurrentStateTest` извлекает все GET/POST routes из `public/index.php`; найденные им пропуски owner-routes исправлены в current-state `ARCHITECTURE.md`.
- Проверки и evidence: RED — route-derived test обнаружил недокументированный `/admin/logout`; GREEN — `DocumentationCurrentStateTest`: 4 tests / 86 assertions. PHP syntax нового integration test — pass. Локальный `MigratedSchemaTest` ожидаемо не подключился к отсутствующей MySQL (`2002`); в CI `composer migrate` запускается до PHPUnit для MySQL 5.7 и 8.0 и остаётся обязательным доказательством. Первый CI-run выявил неоднозначность имени `REFERENCED_TABLE_NAME` в join metadata; запрос минимально исправлен квалификацией `constraints.` и повторно отправлен. Graphify incremental update не завершился из-за 18 изменённых documentation files и отсутствующего LLM key; граф `STALE` не использовался как доказательство, fallback — source files и CI schema test.
- Не сделано / риски: миграции, schema snapshot и product tables не менялись. §7.4 не начат и ждёт решения владельца.
- Следующий шаг: reviewer check, PR/CI; при зелёном CI завершить §7.3 и остановиться до решения владельца по §7.4.

### 00H — архив исходного audit-plan

- Этап / ветка / commit: этап 00, `codex/00-archive-audit-plan`, commit pending.
- Цель: выполнить вторую механическую задачу §7.3: убрать исторический audit-plan из цепочки старта без потери ссылок и findings.
- Сделано: исходный план 2026-08-15 перемещён в `docs/archive/`; `AGENTS.md` больше не ссылается на него. `AUDIT_TRACEABILITY.md` получил явную ссылку на архив и объявлен единственной рабочей навигацией; README/roadmap-ссылки обновлены.
- Не сделано / риски: содержимое исторического плана не редактировалось и не переинтерпретировалось. Graphify не запускался: пакет меняет только известные documentation paths и не использует граф как доказательство.
- Следующий шаг: отдельный `test` package заменяет миграционные text contracts поведенческой проверкой схемы и убирает дублированный список маршрутов.

### 00G — единая оперативная панель состояния

- Этап / ветка / commit: этап 00, `codex/00-checkpoint-protocol`, commit pending.
- Цель: выполнить первую механическую задачу §7.3 внешнего review: исключить дублирование состояния между `STATUS.md` и `CHECKPOINT.md`.
- Сделано: `CHECKPOINT.md` сокращён до протокола команды «сделай checkpoint» и протокола видимости; `STATUS.md` прямо объявлен единственной оперативной панелью. Все актуальные ссылки переименованы и больше не обещают отдельное состояние для возобновления.
- Не сделано / риски: historical worklog entries не переписывались. Graphify не запускался: пакет меняет только известную governance-документацию и не использует граф как доказательство.
- Следующий шаг: отдельный `chore(docs)` архивирует historical audit-plan из §7.3.

### 00F — бюджет чтения и отчётность work package

- Этап / ветка / commit: этап 00, `codex/00-reading-governance`, commit pending.
- Цель: внедрить §7.1–7.2 внешнего review без ослабления продуктовых, security или psychometric ограничений.
- Сделано: `AGENTS.md` разделяет обязательный старт на три файла и контекстное чтение по типу пакета; §11 инженерных правил требует до начала объявить файлы чтения и в конце назвать фактически прочитанные.
- Не сделано / риски: автоматический лимит бюджета чтения (§7.5 review) не входит в этот пакет. Graphify не запускался: пакет не затрагивает незнакомую подсистему; существующий stale graph не использовался как доказательство.
- Следующий шаг: три независимых пакета §7.3: упрощение checkpoint, архив audit-plan и замена непродуктовых migration-text contracts.

## 2026-08-23

### 08.4 — staging deployment 04.0E результатов Лазаруса

- Этап / ветка / commit: этап 08, `codex/04-pair-result-polish` → `main`, `5da9ab5` (PR #15).
- Цель: выложить проверенный 04.0E без миграций и без изменения scoring либо protected SMIL chart.
- Сделано: артефакт собран из `5da9ab5`, production dependencies установлены из lockfile; SHA-256 совпал после загрузки. `public_html` атомарно переключён с `779a2b2/public` на `5da9ab5/public`; прошлый release сохранён для rollback.
- Проверки и evidence: GitHub Actions [32661544002](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32661544002) — success для PHP 8.3/MySQL 5.7 и 8.0. Внешний smoke: HTTPS `/api/health` и `/tests` — `200`, health `ok`; HTTP `/tests` — `301` → HTTPS. Локальная visual QA synthetic PDF через Poppler: 3 страницы A4 landscape, все шесть колонок помещаются и header повторяется.
- Не сделано / риски: ручная проверка владельцем конкретного pair result на desktop/390×844 и скачанного PDF остаётся следующим acceptance шагом. Calculations и SMIL graph не менялись.
- Следующий шаг: владелец проверяет существующее парное сравнение и его PDF на staging; следующий безопасный пакет — 04.1 result components/UX без SMIL scoring.

### 08.3 — staging deployment результатов Лазаруса

- Этап / ветка / commit: этап 08, `codex/08-staging-lazarus-results-release`, source `779a2b2` (PR #13).
- Цель: выложить проверенный 04.0D без миграций и не менять scoring либо protected SMIL chart.
- Сделано: артефакт собран из `779a2b2`, production dependencies установлены из lockfile; SHA-256 `d60abaa7…3f76f` совпал после загрузки. Новый release распакован вне web root, получил текущий `.env` и storage-каталоги. `public_html` атомарно переключён с `2b0ce92/public` на `779a2b2/public`; прошлый release сохранён для rollback.
- Проверки и evidence: GitHub Actions [32658374725](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32658374725) — success для PHP 8.3/MySQL 5.7 и 8.0. Внешний smoke: HTTPS `/api/health` и `/tests` — `200`, health `ok`; HTTP `/tests` — `301` → HTTPS. CSS и основной каталог доступны.
- Не сделано / риски: полноценная ручная проверка конкретного pair result на desktop/390×844 остаётся владельцу/следующему browser package; calculations, PDF и SMIL graph не менялись.
- Следующий шаг: владелец открывает существующий pair result на staging и оценивает содержание/вёрстку; следующий безопасный пакет 04.0E — общий questionnaire UX либо результатные компоненты без SMIL scoring.

## 2026-08-23

### 04.0E — парный результат: единый meter, раскрытие и PDF

- Этап / ветка / commit: этап 04, `codex/04-pair-result-polish` → `main`, `5da9ab5` (source `88ea0c6`, PR #15).
- Цель: устранить три владелецких дефекта в результатах Лазаруса без затрагивания scoring: устаревшую шкалу совпадения, малозаметное раскрытие и выход таблицы за границы PDF.
- Сделано: agreement использует общий `score-scale.twig` с маркером и пороговыми зонами вместо отдельного gradient bar; `summary` оформлен как заметный control с 48px target, контрастным фоном, focus и состоянием open. PDF route рендерит отдельную короткую таблицу с ключом терминов; генератор использует A4 landscape. Browser-версия и PDF не смешиваются.
- Проверки и evidence: targeted PHPUnit `LazarusPairTest` + `PDFGeneratorSmokeTest` — 8 tests / 128 assertions; architecture и PHPStan baseline checks — pass; `git diff --check` — pass. PDF с синтетическими данными отрендерен Poppler: 3 страницы A4 landscape, все шесть колонок и заголовки помещаются, header повторяется на последующих страницах. Graphify incremental update обработал code, но остановился на изменённых Markdown без LLM key; граф остаётся `STALE` и не используется как доказательство.
- Не сделано / риски: browser visual acceptance обычной pair-страницы владельцем ещё ожидается. В этом пакете не менялись вопросы, расчёты, SMIL или публичный текст результатов.
- Следующий шаг: владелец проверяет staging pair result и PDF; затем 04.1 result components/UX без SMIL scoring.

## 2026-08-22

### 04.0D — единое представление результатов Лазаруса

- Этап / ветка / commit: этап 04, `codex/04-lazarus-results-comparison` → `main`, `779a2b2`.
- Цель: устранить визуальное расхождение между индивидуальной шкалой и pair comparison, сделать все данные пары читаемыми на мобильном экране без изменения расчётов.
- Сделано: шкала балла вынесена в общий Twig partial и используется и individual badge, и в двух карточках pair result. В pair comparison добавлены два сопоставимых суммарных профиля; подробная таблица доступна по раскрытию и на mobile преобразуется в карточки с подписями каждого значения.
- Проверки и evidence: targeted `LazarusPairTest` — 4 tests / 115 assertions; `LazarusModuleTest` — 13 tests / 62 assertions; Twig render smoke, PHPStan, lint, architecture, baseline и diff check — pass; `composer audit` — clean. Полный PHPUnit в локальной среде завершился 13 ранее известных MySQL connection errors (`2002`); CI MySQL 5.7/8.0 остаётся обязательным gate перед staging. Graphify code update прошёл, но для пяти изменённых Markdown-документов инструмент запросил внешний LLM key; до следующей ручной semantic extraction graph считается `STALE` и не используется как доказательство, source files остаются fallback.
- Не сделано / риски: полноценная визуальная проверка pair result на staging остаётся отдельным шагом. Scoring и protected SMIL chart не менялись.
- Следующий шаг: 08.3 staging deployment и визуальная приёмка владельца.

### 04.0C — мобильное прохождение Лазаруса

- Этап / ветка / commit: этап 04, `codex/04-lazarus-mobile-navigation` → `main`, `2b0ce92`.
- Цель: убрать риск промаха по десяти малым кнопкам на телефоне и не сбрасывать человека к заголовку при переходе к следующему вопросу.
- Сделано: варианты ответа на mobile стали сеткой 5×2 с touch targets 44px; программная прокрутка после следующего вопроса удалена; кнопка возврата названа «Предыдущий вопрос».
- Проверки и evidence: contract test `LazarusMobileNavigationContractTest`; GitHub Actions для PR #12 прошёл на PHP 8.3/MySQL 5.7 и 8.0. Release `2b0ce92` атомарно выложен на staging; базовые smoke routes доступны.
- Не сделано / риски: этот пакет не менял results/pair comparison; это 04.0D.

### 08.2 — staging deployment editorial landing/catalog

- Этап / ветка / commit: этап 08, `codex/08-staging-editorial-ui`, release `1559188`; документационный commit ожидается.
- Цель: выложить уже проверенную 04.0B главную и каталог на `test.23time.ru` без изменения прохождения, результатов, scoring, payment/AI или SMIL-графика.
- Сделано: production-артефакт собран из `1559188` с `composer install --no-dev --classmap-authoritative`, без `.env`, с `vendor/bin/phinx`; checksum `403a9fab…f11f2` совпал до и после загрузки. Конфигурация с правами `600` перенесена из текущего release, все 7 migrations подтверждены `up`. `public_html` атомарно переключён с `398ca23/public` на `1559188/public`; прежний release сохранён для rollback.
- Проверки и evidence: синтаксис entrypoint и HomeController на server PHP 8.3 — pass; Phinx status — 7 migrations `up`; внешний smoke — `/`, `/tests`, `/api/health`, `/test/bdi`, `/test/smil` дают `200`, health возвращает `ok`, HTTP `/tests` возвращает `301` на HTTPS. HTML подтверждает новые editorial classes на главной и каталоге. Server architecture script загрузил все пять модулей и их расчёты, но его PHP syntax subsection ложноположительно использует default CLI PHP 5.6 вместо обязательного `/usr/local/bin/php8.3`; это известное ограничение скрипта, не regression релиза.
- Не сделано / риски: нет новых migrations и не выполнялись реальные ответы, PDF или payment/AI flows. В unpack output возникли шумные macOS xattr warnings; release распакован полностью, checksum и smoke успешны. Улучшить способ сборки archive без metadata отдельно, не смешивая с UI.
- Следующий шаг: владелец визуально принимает staging; затем 04.0C отдельно улучшает questionnaire components, не трогая scoring и SMIL chart.

### 04.0B — editorial landing и каталог

- Этап / ветка / commit: этап 04, `codex/04-editorial-catalog`, commit ожидается.
- Цель: применить выбранное владельцем направление A к публичной точке входа и каталогу, не меняя прохождение, результаты, scoring, payment/AI или канонический SMIL-график.
- Сделано: `/` вместо redirect теперь рендерит самостоятельный лендинг с реальным каталогом пяти методик; `/tests` получил компактный редакционный каталог. Вынесен отдельный `editorial-catalog.css`, который действует только на эти две public pages. В footer добавлена скромная ссылка «О специалисте» на `hypnocorrection.ru`.
- Решения: D-031. SEO policy не менялась: общий `noindex` остаётся до отдельной content/privacy/legal проверки. Обещание «бесплатный базовый результат» сохранено; расширенный разбор прямо обозначен как будущая отключённая функция.
- Проверки и evidence: targeted PHPUnit — 7 tests / 112 assertions; PHP syntax и `git diff --check` — pass. Browser QA: `/` и `/tests` имеют по 5 методик, console errors/warnings отсутствуют; 390×844 и 1440×1000 — `scrollWidth = innerWidth`. В mobile catalog найден и исправлен overflow: более специфичный featured selector СМИЛ создавал implicit grid columns.
- Изменённые файлы: `HomeController`, layout/public templates, route-specific CSS, landing regression test, current-state/product/phase records.
- Не сделано / риски: прохождение теста, result pages, PDF/print, Lazarus pair UX, account, checkout и AI не стилизовались. SMIL graph не открывался и не менялся.
- Следующий шаг: owner visual acceptance staging; затем 04.0C questionnaire components после подтверждения.

### 00E — актуальность локального Graphify

- Этап / ветка / commit: этап 00, `codex/00-graphify-freshness`, commit ожидается.
- Цель: исключить использование устаревшей локальной карты кода между пакетами и сессиями без неконтролируемого расхода внешних AI-ключей.
- Сделано: добавлен `bin/check-graphify-freshness.php`; он сравнивает checkout с Graphify manifest и возвращает `CURRENT`, `STALE` или `UNKNOWN`. Правила старта сессии и завершения work package теперь требуют проверку, обновление stale-графа или честно зафиксированное исключение. Обновлённый локальный граф подтвердил `CURRENT`.
- Решения: D-030. `graphify-out/` остаётся ignored navigation artifact; проверка автоматизирована, semantic extraction не вызывает внешнего провайдера автоматически.
- Проверки и evidence: `php -l bin/check-graphify-freshness.php` — pass; `php bin/check-graphify-freshness.php` — `CURRENT`. Инкрементно обработано 77 code и 38 document изменений; AST — 597 nodes/886 edges, semantic fragments — 55 nodes/53 edges. Инструмент сообщил историческое warning об одном старом edge с lowercase confidence `explicit`; это не влияет на свежесть и требует отдельной диагностики качества Graphify.
- Изменённые файлы: freshness script, `AGENTS.md`, engineering/governance/status/decision records.
- Не сделано / риски: обновлённый граф технически свеж, но его русскоязычный semantic query пока может выбирать архивные узлы. Он используется как навигация, а факты по-прежнему подтверждаются исходным кодом.
- Следующий шаг: вернуться к 04.0B и внедрить выбранное владельцем направление A только для публичной главной/каталога.

### 04.0A — три визуальных направления

- Этап / ветка / commit: этап 04, `codex/04-visual-directions`, commit ожидается.
- Цель: до массовой переделки CSS показать владельцу три содержательно одинаковых, но визуально разных направления лендинга и каталога.
- Сделано: автономное интерактивное сравнение A «Тёплая редакционная», B «Ясная современная», C «Живая студия»; реальные пять методик, честная граница бесплатного результата и будущего платного разбора, пример двух форм результата, рабочие фильтры и скромная ссылка на `hypnocorrection.ru`.
- Решения: прототипы находятся только в `docs/prototypes`, не подключены к runtime и не являются обещанием уже выпущенной функции. Защищённый SMIL result, scoring, payment, AI и staging не менялись.
- Проверки и evidence: browser QA на 1440×1000 и 390×844 для всех трёх тем; после исправления мобильной сетки у каждой темы `scrollWidth = innerWidth`; проверены переключатели темы, фильтр «Состояние», вкладка примера разбора и отсутствие console errors/warnings.
- Изменённые файлы: `docs/prototypes/04-visual-directions/*`, status и phase records.
- Не сделано / риски: направление ещё не выбрано; прохождение теста, реальные result pages, checkout и SMIL result намеренно не стилизовались.
- Следующий шаг: владелец выбирает A/B/C либо конкретный гибрид; затем 04.0B фиксирует tokens и применяет выбранное направление сначала к лендингу/каталогу.

### 08.1F — staging cookie и header hardening

- Этап / ветка / PR: этап 08, `codex/08-staging-cookie-hardening`, [PR #4](https://github.com/dmitryturin-art/psytest-platform/pull/4), release `398ca23`.
- Цель: устранить небезопасные параметры обычной PHP-сессии и дублирование response headers без изменения тестов, scoring, UI, payment или AI.
- Сделано: все runtime session starts сведены к `Security::startSession()`; production/HTTPS cookie имеет `Secure`, `HttpOnly`, `SameSite=Lax`, path `/`. Security headers задаются один раз в Apache `.htaccess`; старый дублирующий Router middleware удалён. Архитектура синхронизирована с кодом.
- Проверки: RED/GREEN session/header regressions; targeted PHPUnit — 15 tests / 51 assertions; validate/audit, sequential lint/PHPStan, architecture и baseline — pass. [CI 32588895557](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32588895557) — success на PHP 8.3 с MySQL 5.7 и 8.0.
- Deployment evidence: artifact checksum `40177947…ddc0`; PHP 8.3.20 и 7 migrations `up`; `public_html` атомарно переключён с `2f8f821` на `398ca23`. Внешний smoke: HTTPS `/tests` `200`, HTTP `301`, health `ok`, cookie содержит все три флага, каждый dynamic security header встречается один раз, `/admin/login` `404`, retired interpretation `410`. Старый release сохранён для rollback.
- Ограничение: nginx Beget обслуживает static assets до Apache и не добавляет к ним `.htaccess` headers; это не дублирование и не затрагивает HTML/result responses. Server-wide nginx policy на shared hosting отдельно не настраивалась.
- Следующий шаг: 08.2 — owner acceptance, credential rotation и retention cron; затем короткий бесплатный пилот. Production не активирован.

### 08.1E — HTTPS staging activation

- Этап / ветка / PR: этап 08, `codex/08-beget-staging-activation`, [PR #3](https://github.com/dmitryturin-art/psytest-platform/pull/3).
- Решение владельца: Basic Auth не нужен для текущего staging (D-029). Payment, AI и owner dashboard остаются выключены.
- Server config/DB: `.env` mode `600`, production/debug false; pre-migration dump сохранён; Phinx применил 7 migrations, итог — 10 tables на MySQL 5.7.21.
- HTTPS: панельный redirect не проявился в повторных GET-проверках, поэтому versioned `.htaccess` добавил proxy-aware fail-safe redirect. RED: HTTP `200`; GREEN после switch: HTTP `301` на HTTPS. Regression и [CI](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32585270174) проходят на MySQL 5.7/8.0.
- Activation: archive `2f8f821` checksum `28bae9fa…08b3d`; `public_html` атомарно переключён на release, прежняя директория и tar backup сохранены. `/tests`, `/api/health`, `/privacy`, `/terms` — `200`; health — `ok`; legacy interpretation — `410`; owner login — `404` без hash.
- Browser QA: desktop catalog DOM корректен; mobile 390×844 имеет `scrollWidth = innerWidth = 390`. Synthetic BDI прошёл 21/21, submit создал result `0/63`, validation error отсутствует, console errors отсутствуют. Один synthetic anonymous session остаётся в staging DB и удалится lifecycle-policy.
- Наблюдение: browser automation прохождения была медленной; без отдельного network timing это не записывается как подтверждённая server performance regression.
- Безопасность: во время PTY-ввода DB credential отобразился в tool output. Секрет не попал в Git/Markdown/server logs, но SSH/DB credentials должны быть сменены владельцем после deployment; новые значения не передавать в чат.
- Следующий шаг: owner acceptance, credential rotation, retention cron и короткий пилот; production не активирован.

### 08.1D — production artifact и predeploy backup

- Этап / ветка / PR: этап 08, `codex/08-beget-staging-artifact`, [PR #2](https://github.com/dmitryturin-art/psytest-platform/pull/2).
- Цель: подготовить rollback до первого переключения и загрузить проверяемый release вне web root.
- Сборка: Phinx перенесён в production dependencies, чтобы migrations были доступны без PHPUnit/PHPStan. Архив собран из `e2113ab` с `composer install --no-dev --classmap-authoritative`; `.env` отсутствует, `vendor/bin/phinx` присутствует, checksum `2c5d055c…d5a30`.
- Проверки: contract/docs tests — 5 tests / 102 assertions; analyse/lint/baseline pass; [CI PR #2](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32584034083) — success на MySQL 5.7 и 8.0.
- Сервер: `public_html` заархивирован до изменений; release распакован в `releases/e2113ab` и повторно проверен по checksum/allowlist. Исходный публичный `index.php` сохранил checksum `816e5c7c…45c94`; приложение не активировано, DB и SSL не изменялись.
- Урок сборки: архив с macOS xattrs безопасно распаковался, но создал шумные tar warnings; следующие архивы собирать с отключённым AppleDouble/xattr metadata.
- Следующий шаг: 08.1E после HTTPS — server `.env`, migrations, Basic Auth и atomic web-root switch со smoke/rollback gate.

### 08.1C — MySQL 5.7/8.0 compatibility gate

- Этап / ветка / PR: этап 08, `codex/08-mysql57-compatibility`, [PR #1](https://github.com/dmitryturin-art/psytest-platform/pull/1).
- Цель: до первой staging migration проверить фактическую версию DB Beget и не потерять совместимость с MySQL 8.0.
- RED: первый matrix run обнаружил MySQL 5.7 error 1067 на `expires_at TIMESTAMP NOT NULL`; MySQL 8.0 прошёл.
- GREEN: expiry columns, всегда задаваемые приложением явно, используют `DATETIME NOT NULL` в bootstrap и schema snapshot; regression защищает контракт. [Повторный CI](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32583695549) — success на MySQL 5.7 и 8.0, включая clean migrations, 165 tests, audit, analysis, lint, architecture и baseline.
- Staging DB не изменялась и остаётся пустой. Следующий шаг: 08.1D artifact/backup/rollback; активация ждёт HTTPS.

### 08.1B — public-root rewrite

- Этап / ветка / commit: этап 08, `codex/08-beget-public-root-rewrite`, commit после проверок.
- Цель: сделать front-controller routing совместимым с выделенным `public_html`, куда переносится содержимое `project/public`.
- RED: новый `PublicWebRootTest` воспроизвёл ошибочное направление в `public/index.php`.
- GREEN: `.htaccess` направляет отсутствующие файлы/каталоги в локальный `index.php`; лишний `/public/` guard удалён; targeted/docs tests — 9 tests / 127 assertions; analyse, lint, architecture и baseline checks прошли.
- Ограничение локального gate: полный PHPUnit потребовал остановленную локальную MySQL и завершился 13 connection errors; функциональный full gate проверяется в GitHub Actions с service DB. Это не продуктовая регрессия текущего пакета.
- Сервер: без изменений; пакет меняет только репозиторий.
- Следующий шаг: 08.1C — MySQL 5.7 compatibility gate до первой migration на staging.

### 08.1A — read-only Beget staging survey

- Этап / ветка / commit: этап 08 (staging preparation), `codex/08-beget-hosting-survey`, commit после проверок.
- Цель: проверить реальную инфраструктуру до первой записи на shared hosting и подтвердить topology D-028.
- Сделано: по read-only SSH/HTTP/DB проверены web root, web/CLI PHP, extensions, пустая database, MySQL server version, Git/archive tools, ACL, cron availability и HTTP/HTTPS. Сервер и БД не изменялись; Beget-заглушка сохранена.
- Решения: target — `test.23time.ru`, отдельное приложение и DB; WordPress, YooKassa и AI не затрагиваются. Artifact включает локально собранный `vendor/`, потому что системный Composer 1 не является build path.
- Проверки и evidence: HTTP отвечает Beget-заглушкой на PHP 8.3.20; `/usr/local/bin/php8.3` и extensions доступны; HTTPS connection refused; MySQL 5.7.21 принимает read-only login, DB пуста; `public_html` содержит только исходную заглушку и доступен по ACL.
- Изменённые файлы: Beget inventory, product/decision/status/phase/index/traceability docs и человеческий changelog.
- Не сделано / риски: не создавались файлы/tables/cron/SSL, приложение не активировалось, секреты не сохранялись. Требуются Let's Encrypt и MySQL 5.7 compatibility gate.
- Следующий шаг: 08.1B public-root rewrite regression; затем MySQL 5.7 gate до migrations на staging.

### 02.8B — rendered BDI safety notice regression

- Этап / ветка / commit: этап 02, `codex/02-bdi-rendered-notice-regression` → `main`, `3670c6b`.
- Цель: автоматически проверить не только source-template и domain mapping, но и итоговый HTML результата BDI без подключения тяжёлого E2E-стека.
- Сделано: Twig рендерится с synthetic session для positive/negative notice; DOM assertions проверяют один `role=alert`, точный утверждённый текст, отсутствие links и положение до result actions.
- Решения: Node/Playwright не добавляется ради одного кейса. Desktop/390×844 остаются обязательными staging smoke; общий E2E stack выбирается, когда сможет покрыть несколько критичных flow.
- Проверки и evidence: targeted 7 tests / 34 assertions — pass. Full local gate: Composer validate/audit, PHPUnit 162 tests / 1594 assertions, PHPStan, sequential PHP-CS-Fixer, architecture и baseline 148 — pass. [GitHub Actions 32581403763](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32581403763) — success на PHP 8.3/MySQL.
- Изменённые файлы: новый rendered-result regression test, active phase, status, traceability, worklog и человеческий changelog.
- Не сделано / риски: тест не заменяет реальный responsive browser smoke и не меняет scoring/клинический текст/UI.
- Следующий шаг: закрытый staging по `PILOT_RUNBOOK.md`; перед изменениями сервера нужны точный target/domain и read-only инфраструктурное обследование этапа 08.

### 02.8A — closed free pilot runbook

- Этап / ветка / commit: этап 02, `codex/02-closed-pilot-runbook` → `main`, `289d00c`.
- Цель: подготовить минимальный и понятный порядок закрытого бесплатного пилота без преждевременного production deployment.
- Сделано: определены включённые бесплатные сценарии, staging prerequisites, desktop/mobile smoke-check, две небольшие волны, обезличенный issue log, severity/stop rules и критерии завершения. Оплата, AI, аккаунты и public production явно исключены.
- Решения: поддержка в пилоте идёт через личный канал приглашения владельца; реальные ответы, result tokens и PDF не копируются в журнал замечаний.
- Проверки и evidence: targeted documentation/privacy/notice — 8 tests / 126 assertions. Full local gate — Composer validate/audit, PHPUnit 160 tests / 1583 assertions, PHPStan, sequential PHP-CS-Fixer, architecture и baseline 148 — pass. Первый sandbox-run ожидаемо не видел локальную MySQL/Packagist и не мог открыть formatter worker socket; повтор с разрешённым localhost/network и sequential mode прошёл без изменений кода. [GitHub Actions 32581166614](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32581166614) — success на PHP 8.3/MySQL.
- Изменённые файлы: `docs/roadmap/PILOT_RUNBOOK.md`, индекс, active phase, status, traceability, worklog и человеческий changelog.
- Не сделано / риски: staging не создавался, участники не приглашались, automated BDI browser coverage остаётся отдельным пакетом.
- Следующий шаг: 02.8B — лёгкая автоматизированная проверка фактически отрендерированного BDI result без отдельного тяжёлого browser stack.

### 02.7B — checkout-bound AI consent boundary

- Этап / ветка / commit: этап 02, `codex/02-ai-consent-boundary`, `b5fc7d9`.
- Цель: технически отделить бесплатное прохождение от явного разрешения на будущую передачу структурированных данных AI-провайдеру.
- Сделано: immutable consent snapshot требует completed session и уникальный checkout-reference; сохраняет purpose, notice version, provider, report kind и whitelist разрешённых data scopes. Повтор идентичного запроса идемпотентен, конфликтующий snapshot запрещён, отзыв закрывает проверку разрешения, FK удаляет запись вместе с session.
- Проверки и evidence: RED — migration contract не находил таблицу; первый integration-run выявил текстовое сравнение JSON и был исправлен на семантическое. Targeted 3 tests / 30 assertions. Full gate: 160 tests / 1583 assertions, Composer audit, PHPStan, lint, architecture, baseline — pass; локальная migration применена.
- Не сделано / риски: нет public consent checkbox/text, approved provider list, checkout/order FK, внешнего AI-вызова или оплаты. `checkout_reference` станет order reference на этапе 06; до этого service не подключён к маршрутам.
- Следующий шаг: закрытый бесплатный pilot checklist и проверка exit criteria этапа 02; legal/provider решения не угадывать.

### 02.7A — technical metadata minimization

- Этап / ветка / commit: этап 02, `codex/02-technical-metadata-minimization`, `73ed294`.
- Цель: не собирать точный IP и User-Agent без действующей продуктовой цели перед закрытым пилотом.
- Сделано: новые test sessions и все обычные activity events записывают nullable legacy-поля как `NULL`; удалены неиспользуемые header/IP readers. Публичная privacy-страница и current-state data map синхронизированы с кодом.
- Проверки и evidence: RED — integration test получил переданные fixture IP/User-Agent; GREEN — session и `session_created` event содержат четыре `NULL`. Targeted 8 tests / 120 assertions. Full gate: 158 tests / 1564 assertions, Composer audit, PHPStan, lint, architecture, baseline — pass. Browser QA `/privacy` на 1280×900 и 390×844: новый текст виден, старый отсутствует, overflow/errors нет.
- Не сделано / риски: nullable колонки не удалены; старые значения массово не очищались; срок хранения обезличенных operational records ещё предстоит определить.
- Следующий шаг: 02.7B — явная модель AI consent без включения внешнего AI, либо подготовка закрытого pilot checklist.

### 02.6B — hide empty questionnaire navigation

- Этап / ветка / commit: этап 02, `codex/02-empty-test-navigation`, `0954117`.
- Цель: закрыть `UX-01` — пустая sticky-панель не должна перекрывать первый вопрос, когда обе кнопки недоступны.
- Сделано: видимость контейнера теперь вычисляется вместе с доступностью «Назад» и «Завершить»; на первом вопросе он скрыт, со второго появляется.
- Проверки и evidence: RED/GREEN contract; full gate — 156 tests / 1556 assertions, audit/PHPStan/lint/architecture/baseline pass. Browser QA 1280×900 и 390×844: на первом вопросе `display:none`, высота 0; на втором — `flex`, «Назад» видна; overflow и console errors отсутствуют.
- Не сделано / риски: дизайн панели и общая дизайн-система не менялись.
- Следующий шаг: закрытый бесплатный pilot либо automated BDI safety notice coverage.

### 02.6A — final-answer progress completion

- Этап / ветка / commit: этап 02, `codex/02-bdi-progress-completion`, `89a5e5a`.
- Цель: закрыть известный UX-дефект `UX-02`, при котором BDI после ответа на последний вопрос оставался на `20 / 21`.
- Сделано: progress теперь обновляется непосредственно при сохранении каждого ответа, а не только при переходе к следующему вопросу. Поэтому последний вопрос показывает полное состояние без изменения вопросов, scoring, autosave payload или submit flow.
- Проверки и evidence: RED — новый contract test падал, потому что `saveAnswer()` не обновлял progress; GREEN — 1 test / 1 assertion. Full local gate: Composer validate/audit, PHPUnit 155 tests / 1555 assertions, PHPStan, lint, architecture и baseline 148 — pass. Browser QA: BDI с 21 синтетическим ответом показывает `21 / 21`, `100%` и доступную кнопку завершения на desktop 1280×900 и mobile 390×844; horizontal overflow и console errors отсутствуют.
- Изменённые файлы: `public/js/test-taking.js`, `tests/TestTakingProgressContractTest.php` и roadmap/changelog evidence.
- Не сделано / риски: это не общий UI-редизайн и не automated end-to-end browser suite; внешний вид остальных экранов не менялся.
- Следующий шаг: подготовить закрытый бесплатный pilot или отдельным пакетом добавить автоматизированную browser-проверку утверждённого BDI safety notice.

## 2026-08-21

### 02.4B — protected owner therapist-case lifecycle

- Этап / ветка / commit: этап 02, `codex/02-owner-mini-cabinet` → `main`, `93a6bb1`.
- Цель: реализовать выбранный владельцем минимальный кабинет без публичных аккаунтов: явное назначение completed session в `therapist_case` и полное ручное удаление.
- Сделано: добавлены `/admin/login`, `/admin`, POST lookup/assign/delete. Dashboard fail-closed без `OWNER_DASHBOARD_PASSWORD_HASH` Argon2id; session имеет HttpOnly/Strict cookie (Secure при HTTPS), regeneration и TTL, а неудачные входы глобально ограничены без записи IP, user-agent или client identifier. Lookup принимает token только в POST, не показывает token и не использует его в URL. `TherapistCaseService` допускает assignment только completed anonymous session; manual delete использует lifecycle service для known artifacts и сохраняет только identifier-free owner audit event.
- Проверки и evidence: targeted PHPUnit 6 tests / 45 assertions — pass. Full local gate: PHPUnit 154 tests / 1554 assertions, PHPStan, lint, architecture check, PHPStan baseline 148 и diff check — pass. HTTP smoke: login `303`, authenticated `/admin` `200`. Browser QA login page: desktop и 390×844, без horizontal overflow. [GitHub Actions 32506141069](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32506141069) — success на target PHP 8.3/MySQL, включая migration chain и full quality gate.
- Изменённые файлы: owner controller/templates/CSS, authenticator/case lifecycle services, login-attempt migration/schema, env example, tests и current-state docs.
- Не сделано / риски: это не полный кабинет терапевта, нет AI report viewer/editor, coupons, payment controls, TOTP, user accounts или production setup. Глобальный login limit не хранит IP, поэтому защищает от перебора ценой возможного краткого общего lockout.
- Следующий шаг: подготовить закрытый бесплатный pilot или отдельным небольшим package добавить BDI browser coverage.

## 2026-08-16

### 02.2B — approved BDI safety notice and numeric answer-ID regression

- Этап / ветка / commit: этап 02, `codex/02-bdi-crisis-notice`, `788e590`.
- Цель: показать после BDI item 9 > 0 ровно утверждённое владельцем нейтральное сообщение без country/resource flow и не допустить отклонения полностью заполненной BDI-формы.
- Сделано: result page получает notice только по existing structured signal `bdi_item_9`; блок расположен перед действиями результата, не входит в PDF и не содержит контактов, URL, страны или IP/GeoIP. В ходе HTTP-проверки выявлен P1-регресс: `array_merge()` перенумеровывал числовые ID ответов 1–21 в 0–20 при submit. Введён узкий `AnswerMerger`, использующий `array_replace()`, для обычного и парного submit, а также для присоединения demographics; это сохраняет question IDs и не меняет scoring.
- Решения: D-026 заменяет прежнюю country/resource стратегию. Никаких телефонов, URL, названий служб, selector или reader в public flow не добавляется.
- Проверки и evidence: RED — локальный HTTP POST BDI с 21 значениями `0` возвращал `422 Invalid or incomplete answers`; причина подтверждена: валидатор проходил для массива с ключами 1–21, но не после `array_merge`. GREEN — тот же POST после замены возвращает `302` на result. Fixture с item 9 = 1 возвращает result HTML с точным approved message. Targeted PHPUnit: 16 tests / 61 assertions — pass. Full local gate: Composer validate/audit, PHPUnit 148 tests / 1509 assertions, PHPStan, lint, architecture, baseline и diff check — pass. Browser QA: desktop и mobile screenshots — pass. Commit `788e590` опубликован в `main`; GitHub Actions [32503879209](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32503879209) — success на PHP 8.3/MySQL.
- Изменённые файлы: `core/AnswerMerger.php`, `controllers/TestController.php`, safety notice controller/template/CSS/tests и roadmap records.
- Не сделано / риски: ещё нет automated browser coverage; notice не заменяет emergency resource directory и намеренно не публикует контакты.
- Следующий шаг: отдельный package automated browser coverage; затем protected assignment/manual delete для `therapist_case`.

### 00C — current-state developer documentation

- Этап / ветка / commit: этап 00, `codex/00-current-state-docs`, commit ожидается.
- Цель: убрать из developer-facing current-state документов исполнимые legacy YooMoney/OpenRouter инструкции, неверные маршруты, несуществующие команды и устаревшую структуру без изменения продукта.
- Сделано: `ARCHITECTURE.md` и `DEVELOPMENT.md` переписаны по `public/index.php`, module/session/lifecycle code и roadmap. Старый guide создания модуля получил заметную historical-пометку до этапа 03. `.env.example` поясняет, что legacy credentials не включают public payment/AI и что `ENCRYPTION_KEY` сам по себе не шифрует clinical data. Добавлен `DocumentationCurrentStateTest`.
- Проверки и evidence: RED — test первоначально запрещал даже верное утверждение, что `/api/yoomoney/webhook` отсутствует; GREEN — теперь он проверяет отсутствие registration в коде и явную пометку в docs. Полный local gate: `composer validate`, `composer audit`, PHPUnit 141 tests/1483 assertions, PHPStan, lint, architecture check, baseline 148 и `git diff --check` — pass. Локальный runtime 8.5.3 выше target PHP 8.3; совместимость подтвердит CI после publication.
- Не сделано / риски: production runbook, deployment facts и legal review не подменяются локальной документацией; они остаются этапом 08/02. Создание новой методики не разрешено историческим guide до Module API v2.
- Следующий шаг: staged review, commit, fast-forward merge/push и CI.

### 02.5A — methodology provenance and rights registry

- Этап / ветка / commit: этап 02, `codex/02-methodology-provenance-registry` → `main`, `7240d3b`.
- Цель: заменить неявные имена авторов в metadata на честную, проверяемую инвентаризацию доказательств и release gates, не редактируя вопросы, формулы, нормы или SMIL chart.
- Сделано: добавлены human-readable и machine-readable registry для всех пяти текущих модулей; каждому зафиксированы implementation paths, count, фактические bibliography/source hints, пробелы и required evidence. `MethodologyRegistryContractTest` сверяет registry с фактическими module metadata и запрещает в самом registry считать paid interpretation/new public content допустимыми при `rights.status = unverified`. Исправлены current-state docs: в архитектуре отражён Lazarus как пятый модуль.
- Решения: `unverified` — не обвинение в нарушении и не юридический вывод. Это правило доказательности: code repository не содержит достаточных документов для claim о правах конкретной русской формы. Existing free flows, scoring core и current public wording не менялись.
- Проверки и evidence: RED — contract обнаружил, что directory `beck-depression` и actual metadata slug `bdi` различаются; GREEN — проверка использует canonical metadata slug, 4 tests/123 assertions. Полный local gate: `composer validate`, `composer audit`, PHPUnit 138 tests/1387 assertions, PHPStan, lint, architecture check, baseline 148, JSON parse и `git diff --check` — pass. PHPStan/PHP-CS-Fixer предупредили, что локальный runtime 8.5.3 выше target 8.3. GitHub Actions [31950300793](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31950300793) — success на PHP 8.3/MySQL.
- Изменённые файлы: registry docs/JSON, contract test, roadmap/status/traceability/checkpoint и current-state architecture/README/changelog.
- Не сделано / риски: не проведена правовая или clinical review и не подтверждён ни один licence/permission; SMIL additional scales остаются отдельным этапом 05.
- Следующий шаг: запросить owner-approved Crisis UI text/resources и freshness threshold для 02.2B/02.3C; до этого не выводить crisis message или контакты в public UI.
- Follow-up: этот следующий шаг заменён решением D-026 и 02.2B `788e590`: текст утверждён, а countries/resources намеренно исключены из public flow.

### 02.4A — truthfulness of public privacy and deletion claims

- Этап / ветка / commit: этап 02, `codex/02-privacy-claims-truthfulness` → `main`, `a14f5eb`.
- Цель: убрать с публичных страниц обещания, которых текущий код не подтверждает, не меняя clinical scoring, retention semantics или будущий payment/AI design.
- Сделано: privacy page теперь описывает фактическую обработку answers/results, optional name/email и автоматически записываемых IP/user-agent; bearer-like result link; soft-delete boundary и отдельный lifecycle. AI interpretation/payment прямо обозначены как выключенные. Delete modal больше не обещает мгновенное физическое удаление файлов/технических записей. Обновлены factual data map, retention policy, architecture/routes, README и warning для legacy DEVELOPMENT. Добавлен regression `PrivacyClaimsTruthfulnessTest`.
- Решения: это не legal privacy policy и не новый consent. Срок 180 дней описан как настроенная policy, а production scheduler требует отдельного подтверждения. Mobile navigation, не видимая на 390×844, записана как UX-01 для этапа 04 и не менялась в этом privacy package.
- Проверки и evidence: RED — новый source-level test ловил прежние ложные claims; GREEN — 3 tests/16 assertions. Browser QA `/privacy`: desktop и 390×844 показывают весь новый текст, нет horizontal overflow и console errors. Полный local gate: `composer validate`, `composer audit`, PHPUnit 134 tests/1264 assertions, PHPStan, lint, architecture check, baseline 148 и `git diff --check` — pass. GitHub Actions [31949538307](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31949538307) — success на PHP 8.3/MySQL, включая migration chain. PHPStan/PHP-CS-Fixer предупредили, что локальный runtime 8.5.3 выше target 8.3; compatibility подтверждена CI.
- Изменённые файлы: `HomeController`, delete-copy templates, `PrivacyClaimsTruthfulnessTest`, public/current-state docs и `CHANGELOG`.
- Не сделано / риски: нет encryption-at-rest, legal review, production scheduler monitoring, technical metadata minimization, therapist manual delete или AI consent/provider boundary. Ничего из этого не заявляется как готовое.
- Следующий шаг: запросить owner-approved Crisis UI text/resources и freshness threshold; затем 02.2B/02.3C.

### 02.3B — fail-closed crisis resource registry foundation

- Этап / ветка / commit: этап 02, `codex/02-crisis-resource-registry-foundation` → `main`, `50794fa`.
- Цель: подготовить deployable storage boundary для вручную проверяемых кризисных ресурсов, не публикуя ни один контакт и не создавая clinical UI.
- Сделано: добавлена только incremental migration `crisis_resources` и синхронизированный schema snapshot. Каждая будущая запись имеет country/language/type, contact-or-URL, официальный source URL, дату/автора проверки и `active`; default `active = 0`. Реестр не имеет FK к session, не хранит IP и не получает seed data.
- Решения: country может быть `NULL` только для международного fallback. Никакой ресурс не станет доступен без будущего reader/query policy, а срок актуальности не придумывается: автоматическое скрытие по `verified_at` ожидает owner-approved threshold.
- Проверки и evidence: RED — migration contract не находил отсутствующую migration; GREEN — contract проверяет единственный incremental `CREATE`, все обязательные поля, индексы, snapshot и `down()`. Локальная `composer migrate` применила migration к development БД; полный gate: `composer validate`, `composer audit`, PHPUnit 131 tests/1248 assertions, PHPStan, lint, architecture check, baseline 148 и `git diff --check` — pass. GitHub Actions [31948774257](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31948774257) — success, включая чистую MySQL migration chain.
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
