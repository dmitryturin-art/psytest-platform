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


## 2026-09-15

### 08.B15 — staging-выкладка K5d (`975b653`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-975b653`; deployed runtime `975b653` (merge PR #103). Миграций нет.
- Сделано: артефакт `release-975b653.tar.gz`, SHA-256 `9366fa96…9285` совпал; `.env` из прежнего релиза (с `AI_WORKER_PHP_BIN`); pre-deploy dump `backups/pre-deploy-975b653.sql.gz` (gzip -t OK); `public_html`/`current` атомарно на `releases/975b653`.
- Проверки: HTTPS основные маршруты `200`, `/vendor/toastui-editor/toastui-editor-all.min.js` `200`, `/test/smil` `404`.
- Наблюдение: воркер, запущенный вручную по SSH через `nohup … &`, дважды умирал при закрытии SSH-сессии (задания оставались `running` до `releaseStuck`); воркер, запущенный из веб-запроса лаунчером, отработал штатно (clear ready за 219 с). Для ручных запусков по SSH использовать `setsid`.
- Rollback: `public_html` → `releases/33dbdc4/public`, `current` → `releases/33dbdc4`.

### 07.K5d — визуальный редактор разбора (Toast UI) и обновление статуса в карточке кейса

- Этап / ветка / commit: этап 07, `codex/07-k5d-wysiwyg-editor` от `main` `248cddf`; commits `8a9f0f4` (vendor), `17fec09`, `33784a2`.
- Причина (замечания владельца 15.09): «Обновить» была ссылкой на тот же URL с якорем и не перезагружала страницу; автообновление ждало завершения всех заданий; редактор Markdown в textarea неудобен.
- Сделано: «Обновить» — кнопка с `location.reload()` и noscript-fallback; `owner-case.js` перезагружает при изменении статуса любого вида и продолжает опрос, пока есть pending/running. Редактор: Toast UI Editor 3.2.2 (MIT) локально в `public/vendor/toastui-editor/` (728 КБ, источник и sha256 в `VERSION.txt`, самодостаточная сборка с uicdn.toast.com, npm-сборка не самодостаточна), WYSIWYG по умолчанию с переключением в Markdown, русская локаль, тулбар ограничен тем, что рендерит `ReportMarkdown` (заголовки, жирный/курсив, списки, таблица, цитата, линия); textarea остаётся полем формы (progressive enhancement); `ReportMarkdown::inline` скрывает экранирование пунктуации `\.` из вывода редактора без новых тегов и ссылок; fixture `ai-report-sample-toastui.md` рендерится байт-в-байт как исходник.
- Проверки и evidence: исполнитель — полный `composer test` 520 tests / 29785 assertions OK; analyse/lint/architecture/baseline OK; `bin/build-release.sh` содержит vendor-файлы; браузер: реальная перезагрузка, перезагрузка при готовности одного из двух черновиков, редактирование таблицы и сохранение версии №2, предпросмотр совпадает, 390×844 без переполнения, console errors 0. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**.
- Не сделано: unit-тестов JS нет (контракты по тексту); noscript-fallback проверен по разметке.

### 08.B14 — staging-выкладка K1c + K5a3/K5a4 + G2 (`33dbdc4`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-33dbdc4`; deployed runtime `33dbdc4` (merge PR #101; включает #98 свёрнутую анкету, #99 переподключение, #100 глоссарий партии 2). Миграций нет.
- Сделано: артефакт `release-33dbdc4.tar.gz`, SHA-256 `790ae838…d07b` совпал; `.env` из прежнего релиза + `AI_WORKER_PHP_BIN=/usr/local/bin/php8.3`; `storage/cache` создан; pre-deploy dump `backups/pre-deploy-33dbdc4.sql.gz` (gzip -t OK); `public_html`/`current` атомарно на `releases/33dbdc4`.
- Проверки: HTTPS основные маршруты `200`, `/test/smil` `404`; задания владельца по СМИЛ-кейсу «Дмитрий»: см. отчёт владельцу (повтор заказа через кнопку «Повторить» после выкладки).
- Rollback: `public_html` → `releases/8d3ccfa/public`, `current` → `releases/8d3ccfa`.

### 07.K5a3 / 07.K5a4 — фоновый обработчик отдельным процессом, UX заказа, переподключение к БД перед транзакцией

- Этап / ветка / commit: этап 07, `codex/07-k5a3-background-worker` (`3c12c08`, `bc2a9d0`, `08da74f`, после rebase другие sha) и `codex/07-k5a4-txn-reconnect` (`8560169`, merge #99).
- Причина (обследование staging 15.09): PHP на Beget — `apache2handler` за nginx, `fastcgi_finish_request` нет, nginx отдаёт 504 через ~60 с; схема «ответить и продолжить в том же процессе» не работала — владелец получил 504 при заказе черновиков, задание зависло `running`, а `markReady` затем упал с «MySQL server has gone away» (транзакция K5a открывалась на соединении, закрытом сервером за минуты ожидания модели; `wait_timeout` 30 с).
- Сделано: `BackgroundWorkerLauncher` — при заданном `AI_WORKER_PHP_BIN` и доступном `exec` запускает `nohup php bin/generate-ai-reports.php --limit=N` отсоединённым процессом (лог `storage/logs/ai-worker.log`, троттлинг 10 с через `storage/cache/ai-worker.lock`), оба пути заказа (результат посетителя и карточка кейса) делают обычный 303, fallback — прежний `ResponseFinisher`; кабинет: редирект на `#owner-case-ai`, flash внутри раздела, спиннер и опрос статуса раз в 10 с (`public/js/owner-case.js`, owner-only JSON), перезагрузка при завершении, «Повторить» при failed. `Database::beginTransaction()` вне транзакции сначала выполняет `SELECT 1` через путь с переподключением; regression в `DatabaseReconnectTest`.
- Проверки и evidence: исполнитель — полный `composer test` 511 tests / 29666 assertions OK; analyse/lint/architecture/baseline OK; браузер: мгновенный 303, флеш в разделе, лог воркера с обработкой двух заданий, спиннер, автоперезагрузка, «Повторить»; desktop и 390×844 без console errors. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден** (после rebase на main с K5a4 и G2).
- Эксплуатация: в `.env` staging задаётся `AI_WORKER_PHP_BIN=/usr/local/bin/php8.3`; cron не обязателен (`CRON_AI_REPORTS.md`). Ручной запуск воркера 15.09 для двух заданий владельца показал разрыв соединения до фикса.
- Следующий шаг: выкладка; владелец повторяет заказ черновиков; WP8 после публикации v3.

### 07.G2 — глоссарий для 19 шкал партии S3.2

- Этап / ветка / commit: этап 07, `codex/07-g2-glossary-batch2` (rebase на `main`); commit `609cc92` (после rebase `7ed5e0f`).
- Цель: полное покрытие 35 runtime-шкал пояснениями для модели; тексты утверждены владельцем 15.09.
- Сделано: 19 записей в `additional-scales-glossary.json` (D5, Hy1, Hy4, Hy-O, Hy-S — по подтверждённым прототипам; Невротизм — пересказ Собчик; 13 шкал без прототипа — по названию и составу ключа с явными оговорками «самостоятельного вывода не даёт»); `additional_scales_without_glossary` для полного контекста пуст; порог размера контекста в тесте 24 → 28 тыс. знаков (фактически ≈25,7 тыс.).
- Проверки: `AiReportContextContractTest` + `tests/Smil` — 73 tests / 26100 assertions OK; `composer test:fast` 403 OK; analyse/lint/baseline OK.
- Следующий шаг: выкладка вместе с 07.K5a3 (фоновый обработчик) и 07.K5a4 (переподключение к БД).

### 08.B13 — staging-выкладка S3.2 + 07.G1 (`8d3ccfa`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-8d3ccfa`; deployed runtime `8d3ccfa` (merge PR #96; включает #95 S3.2). Миграций нет.
- Сделано: артефакт `release-8d3ccfa.tar.gz`, SHA-256 `a436f063…29ba` совпал; `.env` из прежнего релиза; pre-deploy dump `backups/pre-deploy-8d3ccfa.sql.gz` (gzip -t OK); `public_html`/`current` атомарно на `releases/8d3ccfa`.
- Проверки: HTTPS `/`, `/tests`, health, `/admin/login` — `200`; `/test/smil` — `404`; страница результата синтетической СМИЛ-сессии — `200` (35 шкал).
- Rollback: `public_html` → `releases/e223062/public`, `current` → `releases/e223062`.
- Следующий шаг: владелец публикует промпты СМИЛ v3 из кабинета «Промпты»; 07.G2 (глоссарий партии 2 на утверждение); S3.3.

### 07.G1 — глоссарий дополнительных шкал СМИЛ для ИИ-разбора

- Этап / ветка / commit: этап 07, `codex/07-smil-ai-glossary` (rebase на `main` после S3.2); commits `db36e20`, `9959d07`, `4bc00ec`, `e14f22a` + fix ведущего.
- Цель (решение владельца 15.09): модель получает вместе с числами пояснения по дополнительным шкалам и правило уровней; тексты утверждены владельцем 15.09 после сверки с западными прототипами.
- Сделано: `modules/smil/additional-scales-glossary.json` — 16 записей партии S3.1 (meaning/high/low/relates_to/western_name/source; пересказ Собчик там, где есть описание, иначе литература MMPI; без цитат и диагнозов) и блок `levels` (выше 70T выражено, 56–70 повышено, 50–55 средний, ниже 50 снижено; принципы трактовки); `SmilModule::aiReportContext` отдаёт `additional_scales_glossary` только по переданным шкалам, `additional_scales_without_glossary` — явный список шкал без пояснения (партия S3.2 ждёт глоссария), `levels`; промпты `prompts/smil/individual.{professional,clear}.v3.md` с фразой «использовать переданные пояснения и уровни, не придумывать отсутствующих шкал» — черновики в блоке `manifest.json → drafts`, опубликованные v2 не тронуты (публикация из кабинета решением владельца); реестр, ARCHITECTURE, DATA_MAP.
- Проверки: `AiReportContextContractTest` + `tests/Smil` + `PromptRegistryContractTest` — **85 tests / 26242 assertions OK**; `composer test:fast` 402 OK; analyse/lint OK; длина JSON-контекста СМИЛ ≈12,7 тыс. символов. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден** (после rebase на S3.2 контракт «пояснение у каждой шкалы» заменён на «пояснение либо явная пометка», fix `d4034de`).
- Не сделано: глоссарий для 19 шкал партии S3.2 — отдельный пакет 07.G2 на утверждение владельца; сравнение разборов по структурированному входу и по PDF (WP8) — после публикации v3.
- Следующий шаг: выкладка; владелец публикует v3 из кабинета «Промпты»; 07.G2; S3.3.

### 05.S3.2 — вторая партия дополнительных шкал СМИЛ (19 клинических подшкал) и сверка с западными ключами

- Этап / ветка / commit: этап 05, `codex/05-s3-2-clinical-batch` от `main` `0bb6a67`; commits `028819c`, `b619800`, `7c6b3a4`, `fdaddf9`, `9043df5` (после rebase другие sha).
- Цель: партия, утверждённая владельцем 15.09 (20 записей); по результатам сверки с западными ключами №72 выведена (нормы утрачены), итого 19; №87 включена как `verified` с пометкой об опечатке источника.
- Сверка (субагент с веб-поиском, read-only, `docs/smil-western-crosscheck.md`): гипотеза «нумерация пунктов у Собчик = оригинальный MMPI-566» подтверждена тремя методами (Welsh A/R по структуре 38T+1F и 40F; подшкалы вкладываются в базовые шкалы Соломина 17/17, 31/31 и т.д.; длины совпадают с MMPI-1, а не MMPI-2: Es 68, Do 28, Re 32, Ho 50). Подтверждены прототипы №42 D1, №43 D4, №46 D5, №51 D-O, №56 D-S, №87 Hy4, №49 Gough Do, №62 Barron Es, №77 Ho, №57 Dy, №174 Re, №167 Pv (вероятно). Опечатка №87 «верно/неверно» доказана; нормы №72 — дубль строки №74; №58/№167 нормы недостоверны; №9 не MacAndrew, ключ кандидатов не найден.
- Сделано: `bin/smil-build-batch.php` собирает партии списком (batch 1 + 2, поле `batch`), `additional-scales-v2.json` — 35 шкал (партия 1 не изменена, проверено запись-в-запись); калькулятор отдаёт `note`; результат и PDF показывают текст пометки; `additional_scales_count` 35; эталоны `bin/smil-additional-reference.py` → `tests/fixtures/smil-additional-reference.json` (8 наборов × 35 шкал), инварианты с закрытым списком исключений; реестр с колонкой «Западный прототип», README модуля, AUDIT_TRACEABILITY (35/113).
- Проверки и evidence: исполнитель — полный `composer test` 497 tests / 29802 assertions OK (до исключения №72); ведущий после правки — targeted `tests/Smil` + golden + sections + AI-контекст **81 tests / 25994 assertions OK**, `smil-build-batch.php --check` «35 шкал, файл совпадает с транскрипцией», analyse/lint/architecture/baseline OK, golden базовых без diff; `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**. Браузер (исполнитель): 36 строк на момент проверки, PDF 200, desktop и 390×844 без console errors.
- Инцидент: субагент прервался лимитом API на правке документации; ведущий завершил документацию и коммит.
- Следующий шаг: выкладка; глоссарий для ИИ (07.G1) поверх; S3.3 — психотический/психопатический спектр (№61, 138–144, 146–148, 152, 153, 156–158, 170, 182, 183, 187).

### 08.B12 — staging-выкладка S3.1 (`e223062`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-e223062`; deployed runtime `e223062` (merge PR #93). Миграций нет.
- Сделано: артефакт `release-e223062.tar.gz`, SHA-256 `28535fd1…fc6b` совпал; `.env` из прежнего релиза; pre-deploy dump `backups/pre-deploy-e223062.sql.gz` (gzip -t OK); `public_html`/`current` атомарно на `releases/e223062`.
- Проверки: HTTPS `/`, `/tests`, health, `/admin/login` — `200`; `/test/smil` — `404`; страница результата существующей синтетической СМИЛ-сессии (25.08) — `200`, секция «Проверенные по Собчик (2003)» присутствует; ссылка передана владельцу для визуальной приёмки.
- Rollback: `public_html` → `releases/2bd89f4/public`, `current` → `releases/2bd89f4`.
- Следующий шаг: приёмка владельцем страницы результата; выбор партии S3.2; ответ по интерпретирующим текстам Собчик и AI-контексту.

### 05.S3.1 — первая партия дополнительных шкал СМИЛ по источнику (16 шкал)

- Этап / ветка / commit: этап 05, `codex/05-s3-1-first-batch` от `main` `938e17f`; commits `67ce474`, `223bfe9`, `aa0f977`, `75d0a56`.
- Цель: по утверждению владельца (15.09) заменить 23 неподтверждённых runtime-кода на 16 шкал из транскрипции S2 с независимыми эталонами; №9 отложена; базовые 13 шкал, T-баллы и график неприкосновенны.
- Сделано: `modules/smil/additional-scales-v2.json` генерируется `bin/smil-build-batch.php` из транскрипции (id `sobchik-NNN`, ключи как напечатано, нормы по полу, источник, `status: verified`); старые `additional-scales-norms.json`/`additional-scales.json` и самопорождённый fixture удалены; `AdditionalScalesCalculator`: raw = совпадения true/false, «не знаю» и пропуски не считаются, T = 50 + 10(raw − M)/σ по полу респондента, округление и clamp [20, 100] как у базовых; секция «Дополнительные шкалы» — одна группа «Проверенные по Собчик (2003)» с raw/max, T, M/σ, страницей источника, нейтральной подписью выше/в пределах/ниже нормы; PDF та же таблица; metadata `additional_scales_count` 16; интерпретирующие тексты для новых шкал не вводились.
- Независимая проверка: `bin/smil-additional-reference.py` (Python, только stdlib, по транскрипции, не по PHP-данным) считает эталоны для 4 наборов ответов × 2 пола → `tests/fixtures/smil-additional-batch1-reference.json`; `AdditionalScalesReferenceTest` сверяет calculator побайтово по raw и с допуском 0.01 по T; `AdditionalScalesInvariantsTest` — 16 шкал, равенство транскрипции, 1–566, без дублей/пересечений, M ≤ max_raw, σ > 0, старых кодов нет. Ведущий дополнительно сверил v2 с транскрипцией скриптом: 0 расхождений.
- Проверки и evidence: исполнитель — `composer test -- tests/Smil` 48 tests / 21255 assertions OK; полный `composer test` **495 tests / 24951 assertions OK**; analyse/lint/architecture OK; baseline уменьшен 145 → 141 (типизированные сигнатуры); `bin/verify-smil-keys.php` OK; **golden базовых 13 шкал и `smil-reference-scores.json` не изменились** (`git diff` пуст, `simulate-smil-test.php` diff=0 по 13 T-баллам); браузер: канонический график без изменений, 16 строк, PDF 200, desktop и 390×844 без console errors (18px overflow таблиц на 390 — предсуществующий). Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**.
- Решения: коды A/R/Es/Do/Re/CYN сохранены как буквы, но определения новые (по источнику); `O-H` → `OH`, №57 → `DPN`; clamp [20, 100] вместо прежнего [20, 120].
- Не сделано: №9 «Алкоголизм» (обрезан скан); 97 записей источника остаются `missing`; SMIL-ADD-01 — частично (16/113).
- Следующий шаг: выкладка по подтверждению; S3.2 — следующая партия по выбору владельца (кандидаты без аномалий из реестра).

### 05.S2 — транскрипция приложения Собчик двумя независимыми проходами

- Этап / ветка / commit: этап 05, `codex/05-s2-transcription` от `main` `2d84a39`.
- Цель: перенести PDF-стр. 197–216 (113 записей ключей и норм) в versioned input вне runtime с визуальной сверкой, как требовал S1; runtime не менять.
- Сделано: страницы отрендерены PyMuPDF (220 dpi, кропы до 2400 dpi); два субагента транскрибировали независимо (A — по порядку, B — в обратном порядке, без доступа к данным проекта); скрипт сверки: **113/113 записей совпали по ключам, счётчикам и нормам**, различия только в двух заголовках-опечатках; ведущий просмотрел спорные позиции стр. 201. Результат — `docs/smil-additional-scales-transcription.json` (числа как напечатано, аномалии в `notes`), тест `AdditionalScalesTranscriptionTest` (113 записей, счётчики = длины, 1–566, без дублей/пересечений, №9 без норм), реестр дополнен разделом S2 со сверкой runtime и предложением партии S3.1.
- Находки: №9 «Алкоголизм» обрезан в самом скане (нет «неверно» и норм) — нужен другой источник; 8 runtime-шкал с кандидатами в источнике имеют почти нулевое пересечение ключей, 15 кодов MMPI-2 в источнике отсутствуют; runtime-списки повторяют базовые шкалы. Ни одна из 23 не подтверждаема; S3 — замена набора, не исправление.
- Проверки: `composer test -- tests/Smil` — **29 tests / 16471 assertions OK** (включая golden и новый тест транскрипции); lint OK; architecture OK; scoring, нормы, fixtures runtime не менялись (`git diff modules/` пуст).
- Следующий шаг: решение владельца по партии S3.1 (16 предложенных), источнику для №9 и аномальным нормам.

### 08.B11 — staging-выкладка K5c + 00.D1 + WP9 (`2bd89f4`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-2bd89f4`; deployed runtime `2bd89f4` (merge PR #90; включает #87/#88 ссылку в письме, #89 долги).
- Сделано: артефакт `release-2bd89f4.tar.gz`, SHA-256 `e7777b14…c33d` совпал; `.env` из прежнего релиза; pre-deploy dump `backups/pre-deploy-2bd89f4.sql.gz` (gzip -t OK); `AddPromptVersions` применена (с посевом `ai_enabled=1`); `public_html`/`current` атомарно на `releases/2bd89f4`.
- Проверки: см. smoke в отчёте владельцу: основные маршруты `200`, `/favicon.svg` `200`, `/admin/prompts` без входа `303`, `/test/smil` `404`.
- Rollback: `public_html` → `releases/be4342e/public`, `current` → `releases/be4342e`; `phinx rollback -t 20260915040000` из `releases/2bd89f4`.
- Следующий шаг: ротация SSH-пароля владельцем; S2 (СМИЛ, дополнительные шкалы) — план партии и приёмка владельца.

### 07.WP9 — промпты из кабинета: версии, предпросмотр, публикация, откат, выключатель ИИ

- Этап / ветка / commit: этап 07, `codex/07-wp9-prompt-editor` (rebase на `main` `d945ac9`); commits `e113cfb`, `b23dc4a`, `e861e88` (после rebase другие sha).
- Цель (WP9, уточнение владельца 26.08): редактирование промптов без доступа к файлам; файлы в `prompts/` остаются исходником в Git, правки владельца — в БД поверх них.
- Сделано: миграция `20260915050000_add_prompt_versions` (`prompt_versions`, `prompt_publications`, `ai_settings`); `PromptRegistry::published()` отдаёт версию из БД-публикации, иначе manifest; `availableVersions()` объединяет файловые и кабинетные версии; `AiSettings` (`ai_enabled`, `ai_model`); `AiClient::complete` отказывает при выключенном ИИ (задания уходят в failed с причиной), страница результата и кабинет показывают «Разбор временно недоступен»; `AiProviderSettings` учитывает `ai_model` из БД; кабинет `/admin/prompts` (список ключей по методикам, выключатель, модель с datalist от провайдера), карточка ключа (версии, текст, новая версия с заметкой, предпросмотр на синтетическом fixture через `PromptFixtureContext` без вызова провайдера, публикация с подтверждением, возврат к manifest, пробный вызов на fixture без сохранения).
- Проверки и evidence: исполнитель — `composer test` (полный, изолированная БД) **462 tests / 4163 assertions OK** (`PromptVersionsTest` 9 tests / 38 assertions); analyse/lint/architecture/baseline OK; rollback/migrate OK; браузер: список, предпросмотр, версия 3 из редактора, публикация (чекбокс обязателен), `published()` отдаёт версию из кабинета, возврат к manifest; выключатель убирает формы заказа на странице результата; desktop и 390×844 без console errors. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**. Независимое ревью: маршруты, path traversal, запись файлов, fixture, |raw, миграция — без находок; 3 находки закрыты fix-коммитом: обработчик очереди при выключенном ИИ закрывает задания как failed вместо пропуска, пробный вызов ограничен 90 секундами, строка ai_enabled=1 сеется миграцией; архитектурный риск коллизии номеров файловых и кабинетных версий записан как долг.
- Решения: кабинетные версии всегда «published» при выдаче (публикация из кабинета и есть одобрение); выключатель fail-open (нет строки — включено); предпросмотр строится через модуль по его схеме ответов, а не по тестовым fixtures.
- Не сделано: пробный вызов провайдера в браузере не выполнялся (ключа локально нет); end-to-end тест `promptTrial` с фейковым транспортом отсутствует — покрыт на уровне `AiClientContractTest`.
- Следующий шаг: выкладка; затем S2 — дополнительные шкалы СМИЛ по реестру S1 партиями с приёмкой владельца.

### 07.K5c — ссылка на результат в письме-уведомлении и синхронизация контрактов

- Этап / ветка / commit: этап 07, `codex/07-k5c-notify-link` (`cd41bab`, merge #87) и `codex/07-k5c-notify-link-contracts` (`aa2086d`, merge #88).
- Цель: по решению владельца 15.09 (D-054, уточнение) письмо «Ваш разбор готов» содержит ссылку на страницу результата клиента: он мог закрыть её. Имени, подписи и текста разбора в письме нет; добавлено предупреждение не пересылать ссылку.
- Сделано: `ClientReportNotifier` получает `appUrl`, запрос отдаёт `session_token` и `test_slug` только для формирования ссылки; текст политики приватности, `PRODUCT_RULES` §4/§11, DATA_MAP и два контрактных теста приведены к решению.
- Инцидент процесса: PR #87 был смержен ведущим при красном fast gate из-за ошибки в условии автоматического мержа (падали два контракта «письмо без ссылки»). Исправлено #88 в течение нескольких минут; условие мержа ужесточено до «все проверки pass/skipping». На staging сломанное состояние не выкладывалось.
- Проверки: `ClientReportNotifierTest` 6 tests / 68 assertions OK; `composer test:fast` 367 tests OK после #88; CI #88 зелёный.

### 00.D1 — четыре мелких долга

- Этап / ветка / commit: `codex/00-small-debts` (rebase на `aa2086d`); commits `f8a8223`, `b49090c`, `85f7dd0`, `a4a59de`.
- Сделано: (1) Лазарус — `ResultPresenter::results()` считает пару в каноническом порядке (начавший/приглашённый) для обоих партнёров, страница и PDF второго партнёра больше не подписывают его как «Начавший», карточка «это вы» подсвечивается (scoring и `comparePairResults` не менялись); (2) favicon: SVG/PNG/ICO из `bin/generate-favicon.php`, ссылки в layout, contract в `PublicWebRootTest`, артефакт релиза содержит файлы; (3) СМИЛ — `buildSections()` на пустых/неполных результатах без Warning `is_valid` (расчёт и график не тронуты, golden-тесты проходят); (4) PDF-файлы удаляются только после commit транзакции (`pendingArtifacts` для вложенного вызова из удаления клиента/аккаунта; при rollback файлы остаются).
- Проверки и evidence: исполнитель — `composer test` (полный, изолированная БД) **462 tests / 4136 assertions OK** (+12); analyse/lint/architecture/baseline OK; `bin/build-release.sh` OK; браузер: синтетическая пара 144/160 и 64/160 — обе страницы с верными подписями, полилинии графика идентичны; favicon без 404; desktop и 390×844. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**.
- Не сделано: визуальная проверка текста парного PDF (нет poppler локально) — порядок колонок покрыт тестом по HTML PDF-секции.

## 2026-09-14

### 08.B10 — staging-выкладка K1b (`be4342e`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-be4342e`; deployed runtime `be4342e` (merge PR #85). Новых миграций нет.
- Сделано: артефакт `release-be4342e.tar.gz`, SHA-256 `96e154b9…cef1` совпал; `.env` из прежнего релиза; pre-deploy dump `backups/pre-deploy-be4342e.sql.gz` (gzip -t OK); `public_html`/`current` атомарно на `releases/be4342e`.
- Проверки: HTTPS `/`, `/tests`, health, `/admin/login`, `/account/login` — `200`; `/test/smil` — `404`.
- Rollback: `public_html` → `releases/869986d/public`, `current` → `releases/869986d`.
- Следующий шаг: владелец проверяет парную карточку кейса «Дмитрий», ротирует SSH-пароль; решение WP9 или O1.

### 07.K1b — парное прохождение в карточке кейса специалиста

- Этап / ветка / commit: этап 07, `codex/07-k1b-pair-case-card` от `main` `92ecb57`; commits `9136d68`, `e6d5ae3`.
- Цель (замечание владельца): карточка кейса для сессии Лазаруса из пары показывала одного партнёра и выглядела индивидуальной. Решение владельца: есть пара — парный результат основной, индивидуальный второстепенный; пары нет — индивидуальный вид.
- Сделано: `ResultPresenter::pairViewData` отдаёт только парные секции (график совмещённых профилей, сравнение) без токена и client-only actions; `InvitedCasePresenter::pair` — обе анкеты с подписями «Партнёр 1 — начавший» / «Партнёр 2 — приглашённый» и пометкой «этот кейс»; карточка: бейдж «парное прохождение», парный блок основным, индивидуальный в свёрнутом `details`; раздел «ИИ-разбор» в режиме пары. Вторая сессия пары кейсом не становится, её токен не выводится. Парный расчёт в кабинете — канонический порядок (начавший/приглашённый), как в AI-контексте.
- Проверки и evidence: исполнитель — `composer test` (полный, изолированная БД) **450 tests / 4041 assertions OK** (`OwnerPairCaseCardTest` 5 тестов + контракт); analyse/lint/architecture/baseline OK; браузер: реальный парный прогон → привязка → карточки обоих партнёров с графиком, сравнением 128/160 и 96/160, 80% совпадения, как на странице клиента; одиночный Лазарус и BDI — прежний вид; desktop и 390×844 без console errors. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**.
- Найдено попутно (долг, не чинилось): клиентская страница результата второго партнёра подписывает его оценки как «Начавший» (`ResultPresenter::results()` подставляет текущую сессию как `results_1`); кабинет этого не повторяет. Отдельный пакет для клиентского рендера.
- Следующий шаг: выкладка; завтра решение владельца WP9 или O1.

### 08.B9 — staging-выкладка K2c (`869986d`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-869986d`; deployed runtime `869986d` (merge PR #83). Новых миграций нет.
- Сделано: артефакт `release-869986d.tar.gz`, SHA-256 `82156738…1f7e` совпал; `.env` из прежнего релиза; pre-deploy dump `backups/pre-deploy-869986d.sql.gz` (gzip -t OK); `public_html`/`current` атомарно на `releases/869986d`.
- Проверки: HTTPS `/`, `/tests`, health, `/admin/login`, `/account/login` — `200`; `/test/smil` — `404`.
- Rollback: `public_html` → `releases/c9cff61/public`, `current` → `releases/c9cff61`.
- Следующий шаг: владелец привязывает Лазарус-кейсы к клиенту «Дмитрий» через кабинет и ротирует SSH-пароль deploy-аккаунта.

### 07.K2c — привязка найденной сессии к карточке клиента

- Этап / ветка / commit: этап 07, `codex/07-k2c-attach-session-to-client` (rebase на `main` `30f6648`); commits `6de1a0c`, `146b03a`.
- Цель: по запросу владельца дать штатный способ привязать уже пройденную сессию (найденную по ссылке результата) к карточке клиента, чтобы кейс попал в карточку кейса с разделом «ИИ-разбор»; прямые записи в staging-БД для этого не используются.
- Сделано: `TherapistCaseService::attachToClient` (одна транзакция: при необходимости новый клиент, claimed-приглашение через `TestInviteService::bindExistingSession` с неизвестным никому токеном и `expires_at = now`, `retention_class → therapist_case`, audit без идентификаторов); отказ для partial/deleted/account-сессий и уже привязанных; `POST /admin/case/attach`; форма в блоке «Найденная сессия» с выбором клиента или созданием нового, подсказка про парный Лазарус; редактор открывает legacy ready-отчёты без ревизий (создаёт №1).
- Проверки и evidence: исполнитель — `composer test` (полный, изолированная БД) **444 tests / 3964 assertions OK**; analyse/lint/architecture/baseline OK; браузер: анонимный Лазарус → поиск по ссылке → привязка к новому клиенту → карточка кейса с «ИИ-разбор» (синтетические legacy ready-отчёты → редактор создал ревизию №1) → карточка клиента → повторная привязка отвергнута → удаление клиента унесло всё; desktop и mobile без console errors. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**.
- Инцидент безопасности (низкий): субагент при локальной настройке пароля кабинета вывел `tail .env`, и строка `DEPLOY_SSH_PASSWORD` попала в транскрипт его сессии (не в Git и не в логи). Рекомендована ротация SSH-пароля deploy-аккаунта владельцем после выкладки.
- Следующий шаг: выкладка; владелец привязывает парный и индивидуальный Лазарус к клиенту «Дмитрий» через кабинет.

### 08.B8 — staging-выкладка K5a1/K5a2/K5b (`c9cff61`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-c9cff61`; deployed runtime `c9cff61` (merge PR #81; включает #79 фоновую обработку из кабинета и #80 подсказку раздела ИИ).
- Сделано: артефакт `release-c9cff61.tar.gz`, SHA-256 `30f23048…a00c` совпал; `.env` из прежнего релиза; pre-deploy dump `backups/pre-deploy-c9cff61.sql.gz` (14 таблиц, gzip -t OK); `AddClientEmail` применена; `public_html`/`current` атомарно на `releases/c9cff61`.
- Проверки: HTTPS `/`, `/tests`, health, `/admin/login`, `/account/login` — `200`; `/test/smil` — `404`. ИИ-провайдер (`AI_BASE_URL` routerai) доступен с сервера (HTTP 200 на `/models`), в БД 4 готовых разбора Лазаруса (пара `acf7ad58` + индивидуальный `0aacd9e1`).
- Не сделано: запрошенная владельцем ручная привязка существующих Лазарус-сессий к карточке «Дмитрий» — прямые записи в staging-БД заблокированы политикой автономного режима; вместо этого делается штатная функция «Привязать к клиенту» (07.K2c).
- Rollback: `public_html` → `releases/c8b5feb/public`, `current` → `releases/c8b5feb`; `phinx rollback -t 20260915030000` из `releases/c9cff61`.

### 07.K5b — email клиента в карточке и уведомление о готовом разборе

- Этап / ветка / commit: этап 07, `codex/07-k5b-client-email` (rebase на `main` `9207766`); commits `0296211`, `e9ebaee`, `400186e`. Попутные пакеты в main: `de05405` (07.K5a1 — черновики из кабинета дообрабатываются после ответа, общий `ResponseFinisher`; без этого на shared-хостинге без cron задания специалиста зависали в pending) и `9207766` (07.K5a2 — раздел «ИИ-разбор» показывает пояснение для методик без промптов вместо скрытия; по замечанию владельца).
- Цель (D-054, часть 2): необязательный email в карточке клиента и кнопка «Уведомить клиента на email» после публикации; письмо без текста разбора и без ссылок.
- Сделано: миграция `20260915040000_add_client_email` (`therapist_clients.email NULL`, `ai_reports.client_notified_at`); `TherapistClientService` валидирует/нормализует email; `ClientReportNotifier` отправляет только при опубликованной clear-ревизии и заполненном email, лимит 1 раз в 10 минут на разбор (по часам БД), сбой отправки — лог без адреса и возврат false; `POST /admin/invited-case/{id}/reports/notify`; в карточке кейса кнопка активна/disabled с подсказкой, шаблон получает только факт наличия email; PRODUCT_RULES §4/§11, DATA_MAP, RETENTION_POLICY и политика приватности обновлены.
- Проверки и evidence: исполнитель — `composer test` (полный, изолированная БД) **439 tests / 3926 assertions OK** (`ClientReportNotifierTest` 6 tests / 68 assertions); analyse/lint/architecture/baseline OK; rollback/migrate OK; браузер (СМИЛ-кейс): поле email, активная кнопка, письмо в debug-логе без ссылок и текста разбора, повтор через <10 минут отклонён, disabled-кнопка без email; desktop и 390×844 без console errors. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**.
- Наблюдение (долг): `SmilModule.php:759` даёт Warning `Undefined array key "is_valid"` для пустых результатов синтетической сессии — предсуществующий дефект отображения, не трогался.
- Следующий шаг: выкладка K5a1/K5a2/K5b одним релизом; затем WP9 (промпты из кабинета) или O1 (IPIP) по выбору владельца.

### 08.B7 — staging-выкладка K5a (`c8b5feb`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-c8b5feb`; deployed runtime `c8b5feb` (merge PR #77).
- Сделано: артефакт `release-c8b5feb.tar.gz`, SHA-256 `4da6fc01…37bf` совпал; `.env` из прежнего релиза; pre-deploy dump `backups/pre-deploy-c8b5feb.sql.gz` (13 таблиц, gzip -t OK); `AddAiReportRevisions` применена; `public_html`/`current` атомарно на `releases/c8b5feb`.
- Проверки: HTTPS `/`, `/tests`, health, `/privacy`, `/admin/login`, `/account/login` — `200`; `/test/smil` — `404`. Заказ черновиков на staging не делался: доступность AI-провайдера с сервера проверяется отдельно (08.4 фиксировал недоступность OpenRouter).
- Rollback: `public_html` → `releases/66df1cb/public`, `current` → `releases/66df1cb`; `phinx rollback -t 20260915020000` из `releases/c8b5feb`.
- Следующий шаг: K5b — email клиента в карточке и уведомление о готовом разборе.

### 07.K5a — редактор разбора, версии и публикация на странице клиента

- Этап / ветка / commit: этап 07, `codex/07-k5a-report-editor` от `main` `d036ff7`; commits `b907273`, `b70a38b`, `055da47`, `1ff031b`.
- Цель (D-054): специалист заказывает черновики для кейса по приглашению, правит понятную версию с историей версий и публикует её; клиент видит одобренный текст на своей единственной странице результата и в PDF, отдельных ссылок нет.
- Сделано: миграция `20260915030000_add_ai_report_revisions` (`ai_report_revisions` неизменяемые, `ai_reports.published_revision_id/published_at`); `AiReportRepository::markReady` создаёт ревизию №1 `ai` в той же транзакции; `AiReportRevisionService` (revisions/save/restore/publish только clear+therapist_case/unpublish/publishedContent); карточка кейса: заказ черновиков с чекбоксом передачи обезличенных данных и `owner_context` (уходит только в профессиональный промпт), статусы, owner-only JSON статуса; редактор `owner-report-editor.twig` с версиями, предпросмотром, публикацией и снятием; `ResultPresenter` показывает клиенту `therapist_case` только опубликованную ревизию, PDF получает раздел «Разбор специалиста»; черновики/статусы/профессиональное заключение клиенту по-прежнему недоступны (K0b).
- Проверки и evidence: исполнитель — `composer test` (полный, изолированная БД) **426 tests / 3788 assertions OK**; analyse/lint/architecture/baseline OK; rollback/migrate OK; браузер на Лазарусе (BDI без промптов — отрицательный контроль): заказ → синтетический ready через `markReady` → редактор → версия → публикация → страница клиента с ровно опубликованным текстом → PDF 200 application/pdf → снятие → ожидание; desktop и 390×844 без console errors. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден** (после rebase, до fix ревью). Независимое ревью границы клиента (субагент): изоляция клиента, публикация только clear+therapist_case, IDOR по reportId/revisionId, CSRF, Markdown — без находок; одна находка (гонка двух одновременных сохранений давала 500) закрыта fix-коммитом с regression-тестом; targeted `AiReportRevisionServiceTest` — 8 tests / 33 assertions OK, analyse/lint OK.
- Решения: D-054; ревизии никогда не изменяются, удаляются только каскадом; поллинг в кабинете без JS.
- Не сделано: email-уведомление клиента и поле email в карточке — K5b; промпты из кабинета (WP9) — позже.
- Следующий шаг: выкладка; K5b.

### 08.B6 — staging-выкладка K4 (`66df1cb`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-66df1cb`; deployed runtime `66df1cb` (merge PR #75).
- Сделано: артефакт `release-66df1cb.tar.gz`, SHA-256 `daa22571…dc655` совпал; `.env` из прежнего релиза (MAIL_TRANSPORT=mail, MAIL_FROM сохранены); pre-deploy dump `backups/pre-deploy-66df1cb.sql.gz` (13 таблиц, gzip -t OK); `AddAiReportSnapshots` применена; `public_html`/`current` атомарно на `releases/66df1cb`.
- Проверки: HTTPS `/`, `/tests`, health (`ok`), `/privacy`, `/admin/login`, `/account/login` — `200`; `/test/smil` — `404`. Реальный ИИ-заказ на staging не делался.
- Rollback: `public_html` → `releases/4e63510/public`, `current` → `releases/4e63510`; `phinx rollback -t 20260915010000` из `releases/66df1cb`.
- Следующий шаг: K5 — редактор разборов, revisions, одобрение и доставка клиенту.

### 07.K4 — неизменяемый снимок задания ИИ-разбора (R2)

- Этап / ветка / commit: этап 07, `codex/07-k4-ai-snapshot` от `main` `840b1f2`; commits `1d7ecfa`, `273f31c`, `9f4bd2c`.
- Цель: закрыть R2 — задание фиксирует реально отправленный вход, а не номер версии промпта, который обработчик потом заменял живыми данными.
- Сделано: миграция `20260915020000_add_ai_report_snapshots` (`ai_reports.context_snapshot`, `prompt_snapshot` MEDIUMTEXT NULL); `AiReportContextBuilder` строит разрешённый структурированный контекст при постановке; `AiReportRepository::request` пишет оба снимка в ту же INSERT, повтор failed-задания снимок не пересобирает; `Prompt::toSnapshot()/fromSnapshot()`; `AiReportGenerator` использует только снимок, legacy-строки без снимка идут прежним путём с одной строкой в логе без клинических данных; `bin/generate-ai-reports.php` и `ResultController::requestReport` обновлены.
- Проверки и evidence: исполнитель — `composer test` (полный, изолированная БД) **413 tests / 3674 assertions OK** (+6 в `AiReportSnapshotTest`: v1 → publish v2 → отправлена v1; изменённый SQL-результат не влияет; legacy-путь; повтор failed без пересборки; >64 КБ без усечения; удаление сессии уносит снимок); analyse/lint/architecture/baseline OK; rollback/migrate OK. Реальный провайдер не вызывался. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**.
- Решения: старые строки миграцией не заполняются (исторический вход восстановить нельзя, подставлять текущий — ложь). Снимок хранится столько же, сколько отчёт, и удаляется с ним.
- Следующий шаг: выкладка на staging; затем K5 — редактор разборов, revisions и явная отправка клиенту.

### 08.B5 — staging-выкладка K3 (`4e63510`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-4e63510`; deployed runtime `4e63510` (merge PR #72; включает #71 карточку клиента без дубля).
- Сделано: артефакт `release-4e63510.tar.gz`, SHA-256 `c0a55a04…7748` совпал; `.env` скопирован из прежнего релиза, добавлены `MAIL_TRANSPORT=mail`, `MAIL_FROM=info@23time.ru`; pre-deploy dump `backups/pre-deploy-4e63510.sql.gz` (11 таблиц, gzip -t OK); `AddVisitorAccounts` применена; `public_html` и `current` атомарно на `releases/4e63510`.
- Проверки и evidence: HTTPS `/`, `/tests`, health, `/privacy`, `/admin/login`, `/account/login` — `200`; `/account` и `/account/results/{uuid}` без входа — `303` на `/account/login`; `/account/login/zzzz` — `404`; `/test/smil` — `404`; на страницах кабинета `X-Robots-Tag: noindex`, `Cache-Control: no-store`; политика приватности содержит текст о кабинете. Доставка письма через `mail()` хостинга подтверждена владельцем 14.09 (письмо от `info@23time.ru` получено); D-053 подтверждено; запасной вариант — SMTP Beget настройками `MAIL_HOST/PORT/USER/PASS`.
- Rollback: `public_html` → `releases/a7999f0/public`, `current` → `releases/a7999f0`; откат миграции `phinx rollback -t 20260914020000` из `releases/4e63510`.
- Следующий шаг: подтверждение доставки письма и D-053 владельцем; K4.

### 07.K3 — добровольный кабинет посетителя (вход по email)

- Этап / ветка / commit: этап 07, `codex/07-k3-visitor-account` от `main` `bab48a8`; commits `21e4548`, `9f7047f`, `617bb9a`, `f83e1b2`, `fb496d6`, `fc3b0aa`, `64cf75c`, `53e1961`.
- Цель: посетитель по желанию входит по одноразовой ссылке на email, явно сохраняет свои результаты в кабинет, видит историю, отвязывает и удаляет всё. Тесты без регистрации остаются; результат не привязывается автоматически по email/cookie/IP.
- Сделано: миграция `20260915010000_add_visitor_accounts` (`visitor_accounts`, `visitor_login_tokens` с sha256 токена, `rate_key`, сроком по часам БД; `test_sessions.account_id` FK SET NULL); `RetentionPolicy::ACCOUNT` — сохранённые результаты не попадают в 180-дневную очистку, при отвязке возвращаются в `anonymous`; `VisitorAccountService` (запрос ссылки с лимитами 3/15 мин по каноническому адресу без `+tag` и 20/15 мин на платформу, атомарный single-use consume, attach только с bearer-токеном completed anonymous сессии, detach, history без токена, deleteAccount транзакционно через lifecycle); `VisitorAccountSession` (`session_regenerate_id(true)`, независимость от owner-сессии); `AccountController` и маршруты `/account/*`; GET ссылки показывает подтверждение, погашение только POST (почтовые сканеры не сжигают ссылку); `/account/results/{id}` и `/pdf` рендерят результат по владению без токена в HTML (общий `ResultPresenter`, публичная страница не изменилась); кнопка «Сохранить в мой кабинет» на странице результата; почта — PHPMailer: `SmtpMailer`, `PhpMailMailer` (`MAIL_TRANSPORT=mail`, без пароля), `LogMailer` (без ссылки в логе); сбой отправки логируется без адреса и не меняет ответ; политика приватности описывает кабинет; `config.php` резолвит относительные `PDF_STORAGE_PATH`/`LOG_PATH` от корня проекта (на сервере эти ключи не заданы — поведение не меняется). PHPStan baseline уменьшен до 145.
- Решения: D-053 (предварительное, требует подтверждения владельца): identity — email, magic-link 15 минут single-use, привязка только явная, удаление аккаунта удаляет привязанные результаты, recovery = повторный вход, IP не хранятся. Почта — `info@23time.ru` через `mail()` хостинга; SMTP Beget как запасной вариант настройками.
- Проверки и evidence: исполнитель (изолированная БД) — полный `composer test` 407 tests / 3624 assertions OK, validate/audit/analyse/lint/architecture/baseline OK, rollback/migrate OK; browser QA: BDI → «Войти, чтобы сохранить» → письмо из debug-лога → подтверждение → вход → «Сохранить» → `/account` → результат и PDF без токена → отвязка → удаление (строки и PDF исчезли, чужие целы), desktop и 390×844 без console errors. Независимое ревью безопасности (субагент): 3 находки (обход лимита через `+tag`/нет общего лимита; 500 при сбое почты; GET сжигал ссылку) закрыты `fc3b0aa`, `64cf75c`, `53e1961`. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**.ниже.
- Попутно найдено и исправлено: часы PHP и MySQL на сервере расходятся на 3 часа — сроки считает БД; относительные пути storage резолвились от cwd `public/`.
- Не сделано / риски: заказ ИИ-разбора из кабинета посетителя не делается (форма несёт bearer-токен) — блок read-only с подсказкой; `public/favicon.ico` отсутствует (404 на каждой странице) — мелкий долг; `?return=` содержит result token (как и сам URL результата), в письмо не попадает.
- Следующий шаг: выкладка на staging с `MAIL_TRANSPORT=mail`, `MAIL_FROM=info@23time.ru`; затем K4 (immutable snapshot для AI-очереди, R2).

### 08.B4 — staging-выкладка 02.11 + K2 (`a7999f0`)

- Этап / ветка / commit: этап 08, `codex/08-deploy-a7999f0`; deployed runtime `a7999f0` (merge PR #69; включает #67 стек недели, #68 снятие ключа, #69 K2).
- Цель: перенести на `test.23time.ru` снятие общего ключа СМИЛ и карточки клиентов с мгновенным откатом.
- Сделано: артефакт `release-a7999f0.tar.gz` собран `bin/build-release.sh` из tracked tree, SHA-256 `58fb45e4…12bd8` совпал после загрузки; распакован в `releases/a7999f0`, `.env` скопирован из прежнего релиза (600); pre-deploy dump `backups/pre-deploy-a7999f0.sql.gz` (gzip -t OK, 10 таблиц); `DropTestAccessKey` и `AddTherapistClients` применены Phinx под PHP 8.3; `public_html` и `current` атомарно переведены на `releases/a7999f0`.
- Проверки и evidence: сервер — MySQL 5.7.21 (матрица CI 5.7/8.0 остаётся), PHP 8.3.20. Smoke HTTPS: `/`, `/tests`, `/api/health` (`status: ok`), `/privacy`, `/terms`, `/admin/login` — `200`; `/test/smil` — `404` (закрыт, вход только по приглашению); HTTP → `301`. Без входа `/admin/clients` и `/admin/clients/{uuid}` — `303` на `/admin/login`. Свежих ошибок в `storage/logs` нет. Прохождения и синтетические сессии на staging не создавались.
- Наблюдение (не дефект приложения): точный путь `/admin` без cookie отдаёт 273-байтовую заглушку nginx Beget (anti-bot cookie `beget=begetok` + reload); с cookie маршрут работает штатно. Остальные пути кабинета заглушкой не затронуты.
- Эксплуатация: home deploy-аккаунта `qdesign_gpt2` — это корень сайта (`/home/q/qdesign/test.23time.ru`), `~/test.23time.ru` не существует; `cd current` затем `..` ведёт в `releases/`, для backups использовать `~/backups`. Значение `DEPLOY_SSH_PASSWORD` в локальном `.env` содержало `\r` — при чтении обрезать.
- Rollback: `public_html` → `releases/release-c8e2b28/public`, `current` → `releases/release-c8e2b28`; dump и прежние releases сохранены. Откат миграций: `phinx rollback -t 20260909010000` из `releases/a7999f0` (вернёт пустую колонку `access_key` без ключа — прежние `?key=` ссылки всё равно не заработают).
- Следующий шаг: K3 — кабинет посетителя (identity/recovery/retention → magic-link → история).

### 07.K2 — карточки клиентов и назначения в кабинете специалиста

- Этап / ветка / commit: этап 07, `codex/07-k2-therapist-clients` поверх `codex/02-remove-shared-access-key`; commits `d5da370`, `32ebf67`, `718abc8`, `93187ac`, `1f16c2a`.
- Цель: специалист ведёт карточку клиента с несколькими назначениями (приглашениями), видит их состояния и историю результатов, удаляет клиента целиком. Контакты клиента не хранятся; подпись владельца не покидает кабинет.
- Сделано: миграция `20260914020000_add_therapist_clients` (`therapist_clients`: подпись ≤120, заметка ≤1000; `test_invites.client_id` FK ON DELETE CASCADE, старые приглашения без клиента валидны); `TherapistClientService` (create/update/list/card/delete в одной транзакции с `SessionLifecycleService`); маршруты `/admin/clients`, `/admin/clients/{id}` (карточка, изменение, назначение из карточки, удаление с подтверждением), `POST /admin/invited-case/{id}/delete`; в `/admin` — опциональный выбор клиента при быстром приглашении и подпись клиента в списке. `SessionLifecycleService::deleteSessionAndArtifacts` и `SessionManager::deleteSession` (путь посетителя) удаляют строку `test_invites` вместе с сессией, чтобы owner_note не пережила кейс; статус «результат удалён» без ссылки для осиротевших и soft-deleted кейсов.
- Решения: клиентская сущность отделена от `retention_class`; label никогда не попадает на страницу респондента, в URL, AI-контекст, activity_log. Независимое ревью (субагент): находка про путь самоудаления посетителем исправлена в этом же пакете (`1f16c2a`); 404 для несуществующих карточек унифицирован.
- Проверки и evidence: исполнитель (изолированная БД) — полный `composer test` 383 tests / 3426 assertions OK, analyse/lint/architecture/baseline OK, rollback/migrate OK; browser QA полного сценария (клиент → назначение BDI → прохождение → «завершено» → кейс → удаление) на desktop и 390×844 без console errors и горизонтальной прокрутки. Ведущий: после rebase и переименования миграции `bin/local-gate.sh` на Docker MySQL 5.7.44 — **пройден**; после fix ревью targeted `composer test` — 17 tests / 167 assertions OK, analyse/lint OK; `composer test:fast` — см. итог ниже.
- Изменённые файлы: `core/TherapistClientService.php`, `core/TestInviteService.php`, `core/SessionLifecycleService.php`, `core/SessionManager.php`, `controllers/OwnerController.php`, `public/index.php`, `public/css/main.css`, `templates/owner-clients.twig`, `templates/owner-client.twig`, `templates/owner-dashboard.twig`, `templates/owner-invited-case.twig`, миграция, тесты `TherapistClientServiceTest`, `OwnerDashboardContractTest`, `MigratedSchemaTest`, `ARCHITECTURE.md`, `DATA_MAP_CURRENT.md`.
- Не сделано / риски (долг): PDF-файлы удаляются до транзакции БД, поэтому при сбое на N-й сессии клиента файлы предыдущих уже стёрты (согласованность БД сохраняется) — вынести unlink после commit отдельным пакетом; `created_at` (MySQL NOW) и `completed_at` (PHP date) расходятся на часовой пояс в карточке — отдельный фикс TZ. Graphify не обновлялся и как evidence не используется.
- Следующий шаг: выкладка на staging (02.11 + K2), затем K3 — кабинет посетителя.

### 02.11 — снятие общего ключа доступа к закрытой методике

- Этап / ветка / commit: этап 02 (доступ), `codex/02-remove-shared-access-key`, `fd94074` + docs.
- Цель: по решению владельца (D-052) СМИЛ открывается только личным одноразовым приглашением из кабинета; временная затычка с `?key=` и `bin/test-access-link.php` удаляется, как и предусматривал INVITE_FLOW п. 5.
- Сделано: `TestController::grantsInviteAccess()` сведён к проверке `visibility` — закрытая методика по `/test/{slug}` всегда 404, PHP-session flag и сравнение ключа удалены; миграция `20260914010000_drop_test_access_key` снимает `tests.access_key` (down возвращает пустую колонку); скрипт `bin/test-access-link.php` удалён; `TestVisibilityContractTest` переписан на новые инварианты (нет ключа в guard, 404 даже с `?key=`, приглашение — единственный вход, колонки нет в схеме).
- Проверки и evidence (субагент-исполнитель на изолированной БД): `composer migrate` — 13 up; targeted `composer test` — 13 tests / 78 assertions OK; полный `composer test` — 376 tests / 3309 assertions OK; `composer analyse` OK; `composer lint` 0 правок; architecture, baseline 147 — OK; rollback до `20260909010000` и повторный migrate — OK. Ведущий: `bin/local-gate.sh` на Docker MySQL 5.7 — результат ниже в записи K2 (общий прогон стека).
- Решения: D-052. Историческая миграция `add_test_visibility` не переписывается: на чистой БД ключ создаётся и тут же снимается следующей миграцией.
- Не сделано / риски: на staging ключ продолжает работать до выкладки этой миграции; после выкладки старые ссылки `?key=` перестают открывать СМИЛ.
- Следующий шаг: K2 — клиенты и назначения (параллельно выполняется).

## 2026-09-13

### 07.K1a — читаемая карточка кейса по приглашению

- Этап / ветка / commit: этап 07, `codex/07-completed-session-immutable`; commit указан после финальной проверки.
- Цель: по прямому запросу владельца заменить raw JSON в защищённом кейсе специалиста нормальной карточкой базового результата и заполненной анкеты для каждой поддерживаемой методики, не меняя scoring, AI-flow или клиентскую страницу результата.
- Сделано: `InvitedCasePresenter` сопоставляет уже сохранённые ответы с текстами вопросов и выбранными вариантами; шкалы с options показывают человеческий текст и балл, СМИЛ — «Верно/Неверно/Не знаю», Лазарус — отдельные оценки «Я» и «Партнёр». `OwnerController` получает только существующий модуль и сохранённые results, затем использует его проверенные `buildSections()` для базовой интерпретации; client-only Lazarus pair invitation отфильтрован, чтобы owner card не генерировала bearer-link action. JSON-поля больше не передаются в Twig и не отображаются.
- Проверки и evidence: RED — новый `InvitedCasePresenterTest` падал без presenter; GREEN — targeted PHPUnit **5 tests / 38 assertions, OK** для BAI, BDI, HADS, Лазаруса, СМИЛ и owner contract. Browser QA на локальном синтетическом завершённом BAI case: показаны 21 текстовый ответ и базовый результат, raw JSON blocks отсутствуют; desktop 1440×1000 и mobile 390×844 имеют `scrollWidth = innerWidth`, console errors/warnings отсутствуют. Синтетические invite/session после QA удалены. Свежий `bin/local-gate.sh` — **OK**: validate/audit, PHPStan 6, CS Fixer, architecture, baseline, MySQL 5.7.44 migrations и полный PHPUnit.
- Решения и границы: BAI/клиентский extended-report block не менялись. Для `therapist_case` расширенный AI draft по D-034 остаётся отдельным будущим flow: сначала просмотр/правка/одобрение специалистом, затем явная отправка. Внешний AI не вызывался и новые данные не передавались.
- Graphify: freshness после пакета — **STALE**: 181 changed (112 code, 69 documents), 26 deleted. Incremental semantic update не запускался, чтобы не расходовать внешний лимит без следующего architectural query; stale graph не используется как evidence. Fallback — прямое чтение controller/module/template, module-wide regression и browser QA; обновить до CURRENT перед следующим architectural query, ориентир 14.09.2026.
- Следующий шаг: владелец проверяет эту карточку после controlled staging deployment; затем K2 (клиенты/назначения) либо отдельно принимает модель редактирования/одобрения AI-разбора.

### 08.B3 — ротация пароля кабинета владельца на staging

- Этап / ветка / commit: этап 08, `codex/07-completed-session-immutable`; operational configuration change, runtime code не менялся.
- Цель: по прямому запросу владельца заменить забытый временный пароль `/admin` на тестовом сайте без помещения пароля или Argon2id-хэша в Git, логи или документацию.
- Сделано: штатный `bin/owner-password.php` с интерактивным скрытым вводом сформировал Argon2id-строку на сервере; в `current/.env` заменено только значение dashboard hash. До замены создана закрытая backup-копия конфигурации, временный файл hash сразу удалён. Пароль и hash не выводились и не сохранялись в репозитории.
- Проверки и evidence: config распознаёт Argon2id hash; mode server `.env` — `600`; HTTPS `/admin/login` — `200`. Логин POST намеренно не автоматизировался, чтобы не передавать пароль в команду/логи; generator до записи подтверждает созданный hash через `password_verify`.
- Следующий шаг: владелец входит в `/admin`, создаёт invitation; самостоятельная смена пароля в UI — отдельный security work package, если потребуется.

### 08.B2 — контролируемая staging-выкладка R6/R8/K1

- Этап / ветка / commit: этап 08, `codex/07-completed-session-immutable`, deployed runtime `c8e2b28` (R6 `92206fc`, R8 `ac77df0`, K1 `3db772e`).
- Цель: безопасно перенести проверенный release на рабочий `test.23time.ru`, применить универсальные invitations migration и сохранить мгновенный откат.
- Сделано: локальный artifact `release-c8e2b28` собран только из tracked Git tree, SHA-256 совпал после upload; перед миграцией создан и проверен сжатый pre-deploy dump. `AddTestInvites` применена, затем `public_html` и `current` атомарно переведены на новый release. Предыдущий runtime `2e276b3` сохранён как rollback target; releases и backup не удалялись.
- Проверки и evidence: локальный `bin/local-gate.sh` для runtime уже **OK** (validate/audit, PHPStan 6, CS Fixer, architecture, baseline, MySQL 5.7.44 migrations и полный PHPUnit). На сервере entrypoint синтаксически валиден PHP 8.3; встроенный architecture checker не применим без PATH override, поскольку shared-hosting default CLI — PHP 5.6. После switch HTTPS `/`, `/tests`, `/api/health`, `/privacy`, `/terms` — все `200`; error logs, изменённых в окно smoke, нет. Маршруты прохождения не открывались, поэтому synthetic/клиентские сессии не создавались.
- Ограничения: это staging/рабочий сайт, не production go-live; payment, AI и новый full E2E не включались. Ротация временно переданных SSH-credentials остаётся действием владельца после завершения доступа.
- Следующий шаг: K2 — клиенты и назначения поверх invitation flow; отдельный production gate остаётся вне этого пакета.

### 07.K4a — R8: completed-сессия неизменяема

- Этап / ветка / commit: этап 07, `codex/07-completed-session-immutable`, runtime commit `ac77df0`.
- Цель: до следующей выкладки запретить повторному или конкурентному submit менять клинические ответы, демографию и рассчитанный результат уже завершённой сессии.
- Сделано: `SessionManager` теперь ограничивает все mutable operations статусом `partial`; новый `finalizeSession()` одной условной SQL-операцией записывает final answers/results и переводит состояние в `completed`. Обычный и парный flows используют этот переход; completed-запрос сразу ведёт к существующему result, а проигравший concurrent pair submit не создаёт повторное comparison.
- Проверки и evidence: до исправления новый DB regression падал на первом `assertFalse` (saveAnswers возвращал true). После: `composer migrate && composer test -- SessionSubmissionImmutabilityTest LazarusE2ETest TestInviteServiceTest` — **11 tests / 65 assertions, OK**; PHP syntax — OK; `composer analyse` — OK. Свежий `bin/local-gate.sh` — **OK**: Composer validate/audit, PHPStan level 6, CS Fixer, architecture, baseline, MySQL 5.7.44 migrations и полный PHPUnit.
- Документация: STATUS, phase 07, AUDIT_TRACEABILITY и ARCHITECTURE синхронизированы; R8 закрыт. `CHANGELOG.md` не менялся: результат для посетителя не меняет видимый сценарий, но теперь гарантированно стабилен.
- Graphify: freshness после пакета — **STALE**: 179 changed (110 code, 69 documents), 26 deleted. Incremental semantic update не запускался; до CURRENT graph не используется как evidence. Fallback — исходники lifecycle и DB regression; обновить до следующего architectural query.
- Следующий шаг: staging release с R6 + R8 по отдельному подтверждению владельца; R2 snapshot остаётся отдельным большим AI-пакетом.

### 08.B1 — R6: артефакт только из tracked Git tree

- Этап / ветка / commit: этап 08, `codex/08-release-artifact-whitelist`, runtime commit `92206fc`.
- Цель: устранить R6 до следующей выкладки — ignored/untracked файл из рабочей копии не должен попадать в release archive, но tracked public assets должны сохраняться.
- Сделано: `bin/build-release.sh` сначала получает source через `git archive HEAD`, потом применяет существующий whitelist/exclude-проход к этому source. Путь вывода ограничен формой `tmp/release-<safe-name>`, поэтому builder не удалит произвольный путь. Проверка каждого tracked `public/` файла и production `composer install` сохранены.
- Проверки и evidence: `DeploymentArtifactContractTest` — **2 tests / 6 assertions, OK**. Небезопасный output path отклонён. Реальная сборка `tmp/release-r6-proof.tar.gz` прошла, SHA-256 `88c870d0805e885bd4958f5fa060ffae36a5d7162538d8f0d3328d4f7595d64c`; специально созданный ignored `node_modules/r6-ignored-release-sentinel` отсутствовал и в staged release, и в архиве. Свежий `bin/local-gate.sh` — **OK**: Composer validate/audit, PHPStan level 6, CS Fixer, architecture, baseline, MySQL 5.7.44 migrations и полный PHPUnit.
- Документация: STATUS, phase 08, staging runbook и AUDIT_TRACEABILITY синхронизированы; R6 закрыт. `CHANGELOG.md` не менялся: эффект технический, без изменения пользовательского поведения.
- Graphify: freshness после пакета — **STALE**: 178 changed (109 code, 69 documents), 26 deleted. Incremental semantic update не запускался; до CURRENT graph не используется как evidence. Fallback — прямое чтение builder, archive smoke и tests; обновить до следующего architectural query.
- Следующий шаг: по отдельному подтверждению владельца подготовить контролируемую staging-вкладку с backup/rollback и smoke; production go-live этим пакетом не разрешён и не выполнялся.

## 2026-09-09

### 07.K1 — универсальные одноразовые приглашения

- Этап / ветка / commit: 07, `codex/07-universal-test-invites`; commit указан после финального review.
- Цель: дать специалисту один вертикальный сценарий «создать → скопировать → пройти выбранную методику → увидеть открытие/завершение, ответы и результат» для любой поддерживаемой методики, не смешивая его с Lazarus pair token.
- Сделано: migration `20260909010000` создаёт `test_invites`: test scope, SHA-256 хеш bearer-токена, owner-only note, pending/claimed/revoked, expiry и one-to-one claimed session. Владелец выбирает методику из runtime-supported ModuleLoader list, получает ссылку только во flash copy-field, отзывает неоткрытую ссылку и видит последние состояния. GET `/invite/{token}` не меняет БД и показывает утверждённое информирование; CSRF-protected POST `/invite/{token}/start` в транзакции atomically claim-ит invite и создаёт `therapist_case`. Защищённый owner route показывает raw answers/results по session ID без result token в URL. Legacy visibility key не удалён: его безопасный вывод из обращения без обрыва действующих ссылок — отдельный compatibility package.
- Решения: D-051 — 14 календарных дней и утверждённая клиентская формулировка. Контакты клиента не добавлялись; AI/scoring/нормы не менялись.
- Проверки и evidence: `composer migrate && composer test -- tests/Integration/TestInviteServiceTest.php tests/Integration/MigratedSchemaTest.php tests/OwnerDashboardContractTest.php tests/Mysql57SchemaCompatibilityTest.php` — 8 tests / 73 assertions OK; `composer analyse` — OK; `composer lint` первоначально нашёл порядок import, затем исправлен. Финальный `bin/local-gate.sh` — **OK** на MySQL 5.7.44 (validate, audit, PHPStan, CS Fixer, architecture, baseline, migrations, полный PHPUnit). В браузере проверен preview и CSRF-старт синтетического BDI invite; после проверки synthetic row/session и временный localhost server удалены.
- Graphify: freshness — STALE (177 changed / 26 deleted); incremental semantic update не запускался, чтобы не расходовать внешний лимит без отдельной необходимости. До CURRENT graph не используется как evidence; fallback — прямое чтение исходников, applied schema и regression tests. Повторить freshness/update до следующего architectural query, ориентир 10.09.2026.
- Следующий шаг: K2 — клиенты/назначения поверх invitation flow, отдельным work package. Push/merge/deploy не выполнялись.

### 07.K0b — согласие на внешний AI и граница клиентского черновика

- Этап / ветка / commit: 07, `codex/07-ai-consent-draft-boundary`; commit указан после финального review.
- Сделано: форма разборов содержит утверждённое just-in-time согласие. `ResultController` повторно проверяет `ai_consent=1` до постановки задания, поэтому поддельный POST без согласия не вызывает провайдера. Для `therapist_case` HTML показывает только ограничение, `report-status` отвечает `restricted`, а запрос нового разбора отвергается; базовый результат не затронут.
- Проверки и evidence: syntax PHP — OK; targeted `SessionTestIntegrityTest` + `SessionCookiePolicyTest` — 10 tests / 31 assertions OK; полный `bin/local-gate.sh` — OK на MySQL 5.7.44.
- Решение: D-050. Согласие не сохраняет имя, email или result token и не расширяет AI-context; публичная формулировка утверждена владельцем.
- Следующий шаг: K1 — универсальные приглашения для всех поддерживаемых тестов. Push/merge/deploy не выполнялись.

### 07.K0a — soft-delete удаляет AI-артефакты

- Этап / ветка / commit: 07, `codex/07-delete-ai-artifacts`; commit указан после финального review.
- Сделано: `SessionManager::deleteSession()` в одной транзакции удаляет все `ai_reports` сессии и затем обезличивает сессию. Удалённая строка не может быть обновлена запоздавшим worker.
- Проверки и evidence: DB regression создаёт ready/pending/running отчёты и owner_context, вызывает публично используемый `deleteSession`, проверяет удаление всех трёх и невозможность resurrection через `markReady`; `composer migrate && composer test -- tests/AiReportQueueTest.php` — 9 tests / 44 assertions OK; полный `bin/local-gate.sh` — OK на MySQL 5.7.44.
- Решение владельца D-049: K1 универсален для любого поддерживаемого теста; весь трек дополнительных шкал СМИЛ отложен после основного пользовательского и кабинетного контура.
- Следующий шаг: K0b, затем универсальный K1. Push/merge/deploy не выполнялись.

### 05.S1 — реестр приложения Собчик и сверка 23 runtime-шкал

- Этап / ветка / commit: 05, `codex/05-additional-scales-inventory`; commit указан после финального review.
- Цель: получить проверяемый знаменатель дополнительных шкал выбранного источника и сверить действующие 23 только чтением, не трогая защищённый scoring core.
- Сделано: создан [реестр приложения](../smil-additional-scales-registry.md). Визуально просмотрены PDF-стр. 196–217 скана Собчик; ключи занимают 197–216 (печатные 195–214), а 217 — профильный бланк. В источнике 113 отдельных записей с номерами 1–212 и пропусками, а не доказанные «200+». У каждой записи есть стабильный ID, название, PDF/печатная страница, происхождение, статус ключей/норм и runtime-статус. True/false и M/σ оставлены `source-present / not-transcribed` для отдельного S2, чтобы OCR/ручная перепись не стала неявной публикацией непроверенных данных.
- Сверка: настоящий calculator получает ровно 23 определения только из `additional-scales-norms.json`; `additional-scales.json` в текущем `SmilModule` не вызывается и не является вторым calculator. Итог текущих 23: **0 verified, 9 disputed, 14 missing**. Для A, R, Es, Do, Re, MAC, O-H и CYN есть проверяемые расхождения с соответствующими записями; у R, Es и Do `M >` число реальных runtime-ключей. Это evidence неподтверждённости, не основание самовольно менять ключи, нормы или fixtures.
- Решения: знаменатель S2/S3 — 113 записей именно этого приложения. Правовой статус публикации русской формы остаётся `unconfirmed`. S2 транскрибирует PDF-стр. 197–216 и сверяет глазами; S3 допускается только отдельными утверждёнными партиями 10–20 шкал с независимыми reference cases.
- Проверки и evidence: структурная проверка реестра — 113 ID, 0 дублей, 23 runtime-строки; `git diff --check` — OK; `composer test -- tests/Smil/AdditionalScalesCalculatorTest.php` — **4 tests / 103 assertions, OK**; полный `bin/local-gate.sh` вне sandbox — **OK** (Composer validate/audit, PHPStan, CS Fixer, architecture, baseline, миграции и полный PHPUnit на MySQL 5.7.44). Изменений в `modules/smil/` или `tests/fixtures/` нет.
- Graphify: freshness после пакета остаётся STALE; массовый update намеренно не запускался по прямому правилу START_HERE, а stale-граф не использовался как evidence. Fallback — визуальная проверка PDF, исходники и targeted/full tests; обновить до CURRENT до первого архитектурного query, не позднее 10.09.2026.
- Изменённые файлы: `docs/smil-additional-scales-registry.md`, `docs/roadmap/STATUS.md`, `docs/roadmap/phases/05-smil-professional-parity.md`, `docs/roadmap/WORKLOG.md`.
- Следующий шаг: S2; независимо от него безопасно продолжать K0a в отдельном work package. Push/merge/deploy не выполнялись.

### 07.19 — завершение после лимита: инструкция исполнителю и R3

- Этап/ветка: 07, `codex/07-actionable-handoff`, поверх `be84cbb`; commit пакета `fix(ai): terminate exhausted jobs and finalize agent handoff`.
- Прерывание: 08.09 были готовы незакоммиченные START_HERE/AGENTS/правила и исправление AiReportRepository с тестом. 09.09 владелец попросил коротко завершить для передачи дешёвой модели; новые продуктовые пакеты не начинались.
- Сделано: START_HERE содержит исходные файлы, приёмку S1/K0a и очередь. AGENTS/индексы ведут к нему. Убрано безусловное Graphify/memory чтение из инженерного алгоритма; закреплены поведенческие проверки доступа/удаления и сквозная приёмка кабинетов.
- R3: releaseStuck переводит stale running с attempts >= MAX_ATTEMPTS в failed, остальные в pending. UPDATE повторно проверяет running/stale и не затирает уже ready. Полный lease-протокол не реализован. DB regression последней попытки добавлен. Отдельный запуск теста на исходном коде до правки не зафиксирован; первопричина подтверждена условиями releaseStuck/claimNext.
- Свежие проверки 09.09: **bin/local-gate.sh — весь gate пройден**, включая audit, analyse, lint, architecture, baseline, миграции и полный PHPUnit на изолированном MySQL 5.7.44. Штатный wrapper не выводит число тестов. Предыдущие DNS/Docker ограничения сняты разрешённым запуском вне sandbox. Production не затронут. Итоговый diff check выполнен перед коммитом.
- Graphify: STALE (повторная проверка выполнена); массовая extraction документов отложена по просьбе экономить лимит. До следующего graph query обновить, ориентир 10.09; до CURRENT — rg/исходники/тесты. Манифест не меняли.
- Прочитанное: AGENTS, START_HERE, новейший WORKLOG, STATUS, phase 07, ROADMAP, ENGINEERING_RULES, CHECKPOINT, AUDIT_TRACEABILITY для R3, diff/целевые исходники/тесты. Клинические источники и другие phase повторно не читались. Сохранение в agentmemory не завершено; каноническая инструкция сохранена в Git.
- Отдельный запрос о клиенте: поиск Лазаруса за **08.09 по Москве** не завершён. Первоначальный key/batch SSH отклонён; владелец уточнил парольный вход и явно разрешил безопасно использовать прежний пароль. Результата DB lookup после лимита нет; отсутствие прохождения не утверждается. Реквизиты/клиентские данные в Git не сохраняются.
- Следующий шаг: S1 для одного агента; K0a отдельно параллельно, если доступен. Повторный аудит не нужен. Push/merge/deploy не выполнялись.

## 2026-09-08

### 07.18 — аудит, три линии развития и исправление CI scope

- Этап / ветка / commit: 07, `codex/07-cabinets-audit`, исходный `d9999af`; commit этого пакета — `fix(ci): cover AI persistence and record delivery review` (SHA в Git, чтобы не создавать self-reference).
- Цель: оценить готовность к работе психолога и передать ограниченные пакеты следующим агентам; учтены уточнения владельца про все дополнительные шкалы СМИЛ, параллельные кабинеты и свободные аналоги.
- Сделано: выборочное ревью с проверкой исходников, отдельный инвентарь кабинетов; план S1–S4 / K0–K5 / O1–O3.n с зависимостями и приёмкой. D-048 фиксирует согласованную параллельность. STATUS сокращён до текущей сводки, исторические противоречия сняты; phase 05/07/09 и индексы синхронизированы, архитектурная сводка исправлена, migrate добавлен в оставшиеся примеры gate. Прежние записи WORKLOG не переписаны.
- Исправление: CI scope не включал DB matrix для `core/Ai/AiReportRepository.php`. Воспроизведение до правки: классификатор возвращал `database=false`; после добавления `core/Ai/` — `database=true`. Regression test в существующем CiScopeClassifierTest.
- Проверки и evidence: узкий PHPUnit — 7 tests / 28 assertions; итоговый `composer test:fast` — **337 tests / 3063 assertions, OK**, PHP 8.5.3; `git diff --check` — OK. Baseline до правки: validate, architecture, baseline guard 147 — OK; PHPStan level 6 и CS Fixer — OK в последовательном режиме (штатный multiprocessing ограничен sandbox EPERM). Финальная проверка изменённого PHP: `php -l` обоих файлов — OK; CS Fixer с явным config и `--sequential` — 0/2 исправлений. Первый targeted lint без `--config` отказал из-за нескольких путей; повтор с config успешен.
- Ограничения: `composer audit` — DNS Packagist/curl 6; Docker недоступен, migrate и DB integration gate не запускались. Полный gate не зелёный по результатам этого сеанса; DB/production/scoring/UI изменения не выполнялись. Браузерный и клинический аудит не заявляются.
- Найдено, не исправлялось в runtime: R1 consent/клиентский draft, R2 snapshot, R3 stuck attempts, R6 release из рабочего дерева, R7 soft-delete AI artifact, R8 повторный submit. До внедрения кабинетов — K0 и отдельные regression fixes. Не смешиваем их с фиксом CI и не выдаём статическое ревью за воспроизведение реальной утечки.
- Расхождение хронологии: верхняя дата журнала была 26.08, но commits `2e276b3` и `d9999af` от 27.08 фиксируют исправление блокировки PHP-сессии при генерации и запись выкладки. В STATUS внесён последний документированный release `2e276b3`/rollback `78bdf24`; текущий сервер этим аудитом не подтверждён.
- Graphify: на старте STALE 166 changed/26 deleted; после документов STALE 168 changed/26 deleted. Обновление требует смысловой extraction изменённых документов; при ограниченном лимите владельца массовый LLM-пересчёт отложен. Срок: до первого архитектурного query следующей сессии, ориентир 09.09.2026. Fallback: rg, прямое чтение исходников, тесты; stale-граф не использован как evidence, manifest не менялся.
- Фактически прочитанное и границы перечислены в `docs/audit/2026-09-08-delivery-review.md`. AUDIT_TRACEABILITY прежнего аудита и agentmemory не читались; прежние audit findings не закрываем. Уточнение владельца сохранено в DECISIONS, не только в переписке.
- Делегирование: два Terra — независимые ревью, Luna — baseline и узкий CI fix; ведущий интегрировал выводы/документы. Субагенты не коммитили.
- Дополнительный запрос владельца: проверка сегодняшнего Лазаруса на живом `test.23time.ru` поручена Terra read-only; SSH отказал до DB query. Данные, идентификаторы и ссылки клиентов в документацию не записываются. Продолжение требует рабочего доступа.
- Следующий шаг: **S1 и K0 параллельно**, затем K1 с индивидуальным Лазарусом; O1 свободным агентом без вытеснения двух главных линий. Push/merge/deploy в этот пакет не входят.

## 2026-08-26

### 00P — порядок работ, правило неприкосновенности СМИЛ, два кабинета и опись элементов

- Этап / ветка / commit: governance, `codex/00-plan-cabinets-smil-design`. Только документы, кода не касается.
- Повод: владелец подвёл итог дня и задал порядок дальнейших работ. Ничего не реализуется — решения фиксируются до начала работ.
- **D-044, порядок.** Сначала методики и кабинеты, оплата откладывается. Причина отложения правовая: платная выдача упирается в подтверждение прав на русские формы, а бесплатный контур ими не блокируется. Этап 06 не начинается.
- **D-045, СМИЛ не ломать.** Владелец сообщил, что тест сравнивался и проверялся многократно и работает верно, и что уже случалось, как в него вмешивались «на исправление», а затем выяснялось, что прав был тест, а расходились критерии. Правило записано жёстко. Отдельно снята двусмысленность моего же предложения: сверка ключей существующих 23 шкал выполняется **только чтением** и приносится отчётом — изменений в коде она не влечёт, риска для работающего расчёта нет. Замечу, что эта сверка не новая идея: она уже стояла в плане этапа 05 пунктом «Repair first».
- **D-046, два кабинета.** Посетитель проходит тест без регистрации и получает ссылку на результат, как сейчас; дополнительно может войти по email и получить небольшой личный кабинет с историей прохождений — без пароля, профиля и настроек. Специалисту нужен рабочий кабинет: правка промптов, ведение клиентов, правка и отправка отчётов. Нынешний мини-кабинет закрывает только поиск сессии, назначение кейса и удаление, то есть является отправной точкой, а не целью.
- **D-047, первая альтернативная методика — IPIP.** Выбор определило замечание владельца: СМИЛ закрыт приглашением, и наружу не остаётся ни одной серьёзной методики для личностного профиля. IPIP закрывает именно эту дыру — многопрофильный опросник, а не узкая шкала, и единственный из просмотренного с явным разрешением на коммерцию. Побочно проверит модульную систему на настоящей методике, а не на демонстрационной.
- **Опись элементов интерфейса** — `docs/UI_KIT.md`. Составлена по фактическому содержимому `main.css`, а не по замыслу: кнопки, карточка блока результата, раскрывающийся блок, плашки-уведомления, шкала с отметкой, таблицы, правило подключения стилей через `asset()`. Правило: новый блок берёт существующий класс, а свой заводится только если нужного элемента в описи нет — и тогда он туда добавляется. Повод конкретный и мой: блок расширенного разбора был сделан со своим оформлением, хотя рядом на той же странице уже жил раскрывающийся блок «Подробное сравнение».
- Честно записано, чего у меня нет: навыка, который следил бы за единообразием оформления живого сайта. Доступные навыки по дизайну относятся к оформлению артефактов, а не к поддержке CSS проекта. Вместо этого в план поставлены живая страница элементов и тест, который падает, если появился новый общий элемент, а на странице его нет.
- Проверки и evidence: `bin/local-gate.sh --fast` зелёный; изменений в коде нет. Панели `STATUS.md` и `docs/roadmap/README.md` приведены к новому порядку.
- Не сделано / риски: ничего из перечисленного не реализовано — это план. Этап 06 остаётся не начатым сознательно.
- Следующий шаг: сверка ключей 23 шкал СМИЛ чтением, отчёт владельцу.

### 07.17 — стили не доезжали из-за кэша; оформление разбора приведено к образцу

- Этап / ветка / commit: этап 07, `codex/07-report-look`.
- **Подтверждено главное: расписание не нужно.** На рабочем сервере нажатие кнопки вернуло ответ за 1,15 с, после чего разбор дошёл до готового сам, без единого запуска обработчика: статус прошёл `running → ready`, получилось 14 526 символов HTML. Замечание владельца про избыточность cron оказалось верным, и самодоготовка на Beget работает.
- **Найдена причина, по которой владелец не видел оформления.** Стили на сервер доехали, но `main.css` подключался без версии, и браузер показывал старую копию из кэша. Правка общая, а не точечная: функция `asset()` добавляет к адресу время изменения файла, и все ссылки на стили и скрипты в шаблонах переведены на неё. Прежний костыль в `test-wrapper.twig`, где версия подставлялась текущим временем при каждой загрузке, тоже заменён — он ломал кэширование вовсе.
- Оформление приведено к образцу, на который указал владелец: свёрнутый разбор выглядит как блок «Подробное сравнение по 16 пунктам» — голубая шапка, круглая кнопка «плюс», превращающаяся в «минус», левая полоса цвета основного акцента. Одинаковые по смыслу элементы должны выглядеть одинаково.
- Попутный рефакторинг, вызванный правкой: набор функций шаблонов вынесен в `core/TemplateFunctions.php`. Шаблоны рендерит не только `View` — их собирают шесть тестов со своим Twig, и добавление новой функции сломало их все разом. Теперь набор общий, и следующая функция ничего не сломает.
- Признаю две собственные ошибки в этом пакете: правка `View.php` скриптом вырезала лишнее и оставила несбалансированные скобки; вставка регистрации в тесты регулярным выражением попала не в те места — в одном файле метод остался без `return`, в другом строка легла в чужой метод. Оба файла восстановлены вручную, весь gate зелёный.
- Проверки и evidence: полный `bin/local-gate.sh` зелёный на MySQL 5.7.44, 361 тест. Версионирование проверено на отрисованной странице: все три подключаемых файла получают `?v=<время изменения>`.
- Не сделано / риски: правки ждут выкладки — владелец пока видит предыдущий релиз.
- Следующий шаг: выкладка.

### 07.16 — оформление разбора и отказ от расписания

- Этап / ветка / commit: этап 07, `codex/07-report-styles`.
- Два замечания владельца по выложенному блоку, оба справедливые.
- **Первое: блок не был оформлен вовсе.** Я написал шаблон с классами и не добавил к ним ни одной строки стилей, поэтому на странице всё слилось в сплошной текст: заголовки видов отчёта не отличались от содержимого, «разбор готовится» читалось как обычный абзац, таблицы шли без рамок, а сам разбор продолжал страницу результата без всякой границы. Добавлены стили в языке существующего оформления — белая карточка с теми же токенами, что у блока парного сравнения; каждый вид отчёта отделён линией и меткой; ожидание оформлено как состояние с крутящимся индикатором (отключается при `prefers-reduced-motion`); таблицы получили рамки, фон заголовка и горизонтальную прокрутку внутри себя, чтобы не растягивать страницу на телефоне.
- **Второе: готовый разбор сворачивается.** Он занимает несколько страниц, и без сворачивания сливался с результатом теста. Теперь это `details` со сводкой «готов — нажмите, чтобы прочитать», свёрнутый по умолчанию. Работает без JavaScript.
- **Третье, содержательное: расписание убрано.** Владелец назвал cron избыточным усложнением, и это верно. Теперь заказ разбора отвечает браузеру сразу и закрывает соединение, после чего **тот же процесс** доводит работу до конца: `ignore_user_abort(true)`, снятие лимита времени и `fastcgi_finish_request()` там, где он есть, иначе выталкивание ответа и продолжение. Посетитель может закрыть вкладку. Прерывание процесса хостингом ничего не ломает: задание, застрявшее дольше получаса, возвращается в очередь само, а попыток не больше трёх — прогон откладывается, но не теряется.
- Документ `CRON_AI_REPORTS.md` переписан: расписание больше не требуется, описано как работает самодоготовка, оставлен ручной запуск для разбора завалов и названы признаки, по которым расписание всё-таки понадобится — если окажется, что хостинг обрывает процессы после ответа и задания регулярно уходят в повтор.
- Проверки и evidence: PHPStan `[OK]`, `bin/local-gate.sh --fast` зелёный. Самодоготовку предстоит проверить на сервере: под встроенным сервером разработки она ведёт себя иначе, и настоящий ответ даст только рабочий хостинг.
- Не сделано / риски: **работоспособность самодоготовки на Beget не подтверждена** — это и есть главный вопрос выкладки. Если процесс обрывается после ответа, вернёмся к расписанию, которое для этого и описано.
- Следующий шаг: выкладка и проверка, доходит ли разбор до конца без ручного запуска.

### 07.15 — заказ разбора со страницы результата и расписание для обработчика

- Этап / ветка / commit: этап 07, `codex/07-report-ui`.
- Повод: разбор запускался командой на сервере — тот самый «инструмент для технаря», который владелец уже справедливо критиковал в истории с ключом доступа.
- Сделано: на странице результата появился блок расширенного разбора с двумя вариантами — понятным и профессиональным. Кнопка ставит задание и возвращает на страницу; пока разбор готовится, показывается ожидание, а страница сама опрашивает состояние и подставляет готовый текст. Разметку строит сервер: ответ модели — внешний текст, и вставлять его в страницу без разбора нельзя. Блок показывается только если для этой методики и режима действительно опубликован промпт, иначе посетителю предлагалась бы кнопка, которая ничего не сделает. Режим определяется сам: парный, если сравнение уже собрано, иначе одиночный. Возврат после заказа идёт кодом 303, чтобы обновление страницы не повторяло заказ.
- Расписание: `docs/roadmap/CRON_AI_REPORTS.md` — команда, периодичность раз в пять минут и объяснение, почему частый запуск безопасен (условный захват задания, предел в три задания за прогон, возврат зависших, потолок попыток). Скрипт проверяет настройки провайдера до того, как брать задания, поэтому расписание можно поставить и раньше настройки.
- Четыре собственных контракта проекта поймали упущения, и все четыре по делу: PHPStan нашёл вызов несуществующего метода перенаправления, а затем недостижимый код после метода, объявленного как `never`; текстовый контракт документации потребовал внести оба новых маршрута в `ARCHITECTURE.md`; контракт защиты сессий пересчитал число потоков, привязанных к slug, и потребовал признать новый поток — он действительно идёт через общую защиту; тест клинического уведомления упал на переменной шаблона, которая приходит не всегда, и шаблон теперь проверяет её определённость.
- Проверки и evidence: сквозной прогон на локальном сервере — страница показывает оба варианта, нажатие даёт 303 и ставит задание (режим `pair` определился сам), страница отдаёт ожидание, опрос возвращает состояние, обработчик готовит разбор за 238,7 с, после чего страница показывает текст с заголовками, таблицами и списками. Внутри блока разбора только теги из белого списка. Полный `bin/local-gate.sh` зелёный на MySQL 5.7.44.
- Не сделано / риски: расписание на сервере ставит владелец через панель — из SSH это недоступно. Оформление блока минимальное, отдельной вёрсткой не занимался.
- Следующий шаг: выкладка и постановка расписания владельцем.

### 07.14 — показателю согласия дано определение прямо в нагрузке

- Этап / ветка / commit: этап 07, `codex/07-agreement-definition`.
- Повод: первый парный разбор по данным владельца получился, и в нём модель написала, что определение показателя `overall_agreement` ей не передали, поэтому она учитывает его как формальный индикатор, а не как клинический критерий. Владелец согласился, что голое число ни о чём не говорит и в отчёте выглядит странно.
- Оценка: модель повела себя правильно — она не стала выдумывать смысл незнакомого числа, а честно назвала границу своего знания. Дефект был на нашей стороне: мы отдавали значение без объяснения.
- Сделано: вместо голого `overall_agreement` в нагрузку уходит `agreement` с двумя полями — значением и определением. Определение повторяет то, что видит человек на странице результата, чтобы отчёт и страница говорили одно и то же: это близость собственных оценок партнёров, 100 % означает совпадение по всем шестнадцати пунктам, а ожидаемые оценки партнёра в показатель не входят. Последнее существенно: без этой оговорки показатель легко спутать с точностью взаимных предсказаний, которая считается отдельно.
- Проверки и evidence: `AiReportContextContractTest` — 18 тестов / 144 assertions; форма нового поля и наличие обеих частей определения закреплены. Полный `bin/local-gate.sh` зелёный на MySQL 5.7.44.
- Прочие замечания владельца по первому разбору: текст «суховат», но принят как есть — промпт не меняется. Объём вышел за рамку промпта (12 456 символов при заявленных 800–1500 словах), в ограничениях модель упомянула контекст, которого во входных данных нет; оба наблюдения записаны, но правок пока не требуют.
- Не сделано / риски: правка ждёт выкладки; после неё разбор по парной ссылке владельца стоит повторить, чтобы увидеть разницу.
- Следующий шаг: выкладка и повторный разбор.

### 07.13 — соединение с БД переживает долгий вызов провайдера

- Этап / ветка / commit: этап 07, `codex/07-db-reconnect`.
- Первопричина, найденная на рабочем сервере: `wait_timeout` у MySQL на Beget равен **30 секундам**, а подготовка разбора занимает больше двух минут. К моменту записи результата соединения с базой уже нет, и первый разбор на сервере не сохранился вовсе — задание осталось в состоянии `running` с пустым содержимым, а в логе была только строка `Database query failed [HY000]`.
- Сделано: все запросы идут через один метод `Database::execute()`, там и добавлено восстановление — при потере соединения оно открывается заново, и запрос повторяется один раз. Повтор безопасен именно для этого случая: раз соединение потеряно, запрос до сервера не дошёл и выполниться не мог.
- Тест вскрыл изъян в самой правке. Первая версия отказывалась переподключаться внутри транзакции — это правильно, повтор вне транзакции молча разорвал бы атомарность, — но объект после этого оставался **непригодным навсегда**: PDO продолжал считать транзакцию открытой, и все последующие запросы падали там же. Теперь соединение восстанавливается и в этом случае тоже, а исключение всё равно пробрасывается: транзакция потеряна в любом случае, и вызывающий обязан об этом узнать.
- Второй раз ошибся и сам тест: он убивал **собственное** соединение, из-за чего повтор переподключался и убивал уже новое. Переписан так, как разрыв устраивает сервер, — снаружи, отдельным соединением.
- Существующий тест безопасности поймал третью вещь: `DatabaseErrorLoggingTest` запрещает трогать `$e->getMessage()` в этом файле, потому что драйверное сообщение может содержать значения параметров запроса, то есть данные респондентов. Определение потери соединения по тексту сообщения убрано; осталась проверка только по коду драйвера (2006 и 2013), что и точнее, и безопаснее. Правило проекта сработало ровно так, как задумано.
- Проверки и evidence: `DatabaseReconnectTest` (3 теста) — запрос после разрыва проходит на новом соединении, запись после долгой паузы доходит, отказ внутри транзакции пробрасывается и не прячется за повтором. Полный `bin/local-gate.sh` зелёный на MySQL 5.7.44: 361 тест.
- Не сделано / риски: правка ждёт выкладки; до неё разборы на сервере по-прежнему не сохраняются. Зависшее задание владельца остаётся в `running` и вернётся в очередь автоматически через полчаса либо по явному запросу.
- Следующий шаг: выкладка и повтор разбора по парной ссылке владельца.

### 08.4 — выкладка `0687412` и найденный блокер: OpenRouter недоступен с рабочего сервера

- Этап / ветка / commit: этапы 08 и 07, `codex/07-provider-reachability`; выложен `main` = `0687412`.
- Выкладка: по PRODUCTION_RUNBOOK §1. sha256 совпал побайтово, pre-deploy дамп 25 415 байт, миграция `ai_reports` применена, переключение атомарное, откат на `78c5c4e` готов. Внешний smoke: главная, каталог, страница Лазаруса и health — 200; СМИЛ по-прежнему 404 без ключа; парный результат владельца открывается. Перед выкладкой дождался зелёной матрицы MySQL 5.7/8.0.
- Настройки ИИ перенесены на сервер: ключ передан файлом и вписан скриптом, в вывод и в Git не попадал; заданы `AI_BASE_URL`, `AI_MODEL` (`qwen/qwen3.7-plus`) и `AI_TIMEOUT_SECONDS`. Первая передача не долетела молча — исправлено с проверкой размера и префикса файла на сервере до записи.
- **Найден блокер, к коду отношения не имеющий.** Разбор по парному результату владельца не сделался: провайдер ответил 403. Диагностика с сервера: тот же ответ приходит **и вовсе без ключа** (`{"success":false,"error":"Access denied by security policy."}`), то есть это не аутентификация; исходящий HTTPS в порядке — github и packagist отвечают 200; ключ на сервере цел (73 символа, верный префикс) и с машины разработчика работает. Значит OpenRouter закрывает доступ с адреса российского хостинга на своём входе.
- Проверено, что доступно с сервера: `api.deepseek.com`, `dashscope-intl.aliyuncs.com` (Qwen от Alibaba в OpenAI-совместимом режиме), `api.openai.com`, `api.proxyapi.ru` отвечают 401, то есть доступны и ждут ключ; `api.vsegpt.ru` — 200. Заблокирован только OpenRouter.
- Смена провайдера **правки кода не потребует**: адаптер строился вокруг OpenAI-совместимого endpoint именно ради этого (D-039). Нужны `AI_BASE_URL` и `AI_API_KEY` нового провайдера — это владельческое решение.
- Попутно исправлена собственная неточность: сообщение об ошибке трактовало 403 как «ключ отклонён» и отправило бы владельца искать несуществующую проблему с ключом. Теперь 403 называется «доступ запрещён — ключ либо адрес отправителя» и, как и прежде, не повторяется: повторять запрос с того же адреса бессмысленно. Закреплено тестом.
- Проверки и evidence: `AiClientContractTest` — 16 тестов / 47 assertions. Локально вся цепочка разбора работает; на сервере не работает только из-за блокировки провайдера.
- Не сделано / риски: ИИ-разборы на рабочем сайте не делаются до смены провайдера. Задание владельца осталось в состоянии `failed` с записанной причиной — после смены провайдера его можно попросить заново, попытки не исчерпаны.
- Следующий шаг: выбор провайдера владельцем, затем повтор разбора по его парной ссылке.

### 07.12 — фоновая генерация разборов: очередь, обработчик, сквозная проверка

- Этап / ветка / commit: этап 07, `codex/07-report-generation`.
- Цель: разбор делается около двух минут, поэтому веб-запрос его ждать не может — работа выносится в фон.
- Сделано: `AiReportRepository` (постановка задания, атомарный захват, исходы), `AiReportGenerator` (собирает контекст у модуля, берёт промпт из реестра, зовёт адаптер, честно записывает исход) и `bin/generate-ai-reports.php` для cron рядом с уже настроенной ночной очисткой.
- Решения по устройству очереди: (1) Захват задания атомарный — статус меняется условием `WHERE status = pending`, и работа достаётся тому запуску, чей `UPDATE` затронул строку; два одновременных cron не возьмут одно задание дважды. (2) Повторный запрос переиспользует запись благодаря уникальному индексу — кнопка, нажатая дважды, не плодит задания и не перезапускает готовый отчёт. (3) Неудавшееся задание можно попросить заново, пока не исчерпаны три попытки, и прежняя причина отказа при этом стирается. (4) Задание, застрявшее в работе дольше получаса, возвращается в очередь: обработчик может умереть посреди вызова, и иначе такая запись висела бы вечно. (5) Любой сбой закрывает задание — даже неожиданный: незакрытое задание блокировало бы повтор.
- Добавлена постановка задания по токену результата (`--request`): пока на странице нет кнопки, разбор иначе нечем запустить, кроме правки базы руками.
- Сквозная проверка на живом провайдере: прохождение Лазаруса по HTTP → постановка задания → обработчик → готовый разбор в базе → рендеринг. Первый прогон вскрыл повторение уже известной беды: в локальном `.env` оставался маршрутизатор `openrouter/free`, и он отдал **клинический разбор модели для кода** (`cohere/north-mini-code:free`). Это второй случай после классификатора безопасности, поэтому модель по умолчанию в `.env.example` заменена на конкретную (`qwen/qwen3.7-plus`, D-041), а прежняя формулировка про удобство маршрутизатора заменена на прямое предупреждение с двумя фактами. После замены: ответ за 91,5 с, 4122 символа, модель та, что запрошена.
- Проверки и evidence: `AiReportQueueTest` — 7 тестов / 30 assertions, включая проверку, что задание не берётся дважды, что исчерпавшее попытки не берётся вовсе, что зависшее возвращается в очередь и что удаление сессии уносит разборы каскадом. Сохранённый разбор прогнан через рендерер: 7868 байт HTML, только теги из белого списка. Полный `bin/local-gate.sh` зелёный на MySQL 5.7.44.
- Не сделано / риски: кнопки на странице результата пока нет — следующий пакет. Cron на сервере под этот скрипт ещё не настроен.
- Следующий шаг: выкладка, настройка cron и разбор по парному результату владельца — он дал свою ссылку как испытательный стенд и отметил, что его собственный образец делался по тем же данным и на той же модели, то есть сравнение будет прямым.

### 07.11 — хранение ИИ-разборов и безопасный рендеринг Markdown

- Этап / ветка / commit: этап 07, `codex/07-report-storage`. Фундамент под видимый разбор: без хранения и рендеринга отчёт некуда положить и нечем показать.
- Хранение: миграция `20260827120000` создаёт `ai_reports`. Запись несёт сессию, методику, режим, вид отчёта, ключ и версию промпта, запрошенную и фактически ответившую модель, статус (`pending`/`running`/`ready`/`failed`), сам текст, клинический контекст специалиста, причину отказа, число попыток и токены. Внешний ключ на `test_sessions` с каскадным удалением: разбор — клинический документ, построенный на результате сессии, и удаление кейса в кабинете обязано уносить его вместе с сессией, а не оставлять сиротой. Уникальный индекс на сочетание сессии, режима и вида отчёта — повторный запрос переиспользует запись, а не плодит дубли.
- Рендеринг: `core/ReportMarkdown.php`. Текст приходит от внешней модели, то есть это неконтролируемый ввод, который попадёт на страницу. Поэтому порядок обратный привычному — **сначала экранируется всё**, и только потом в уже безопасный текст добавляются собственные теги: к моменту появления первого тега любой `<` уже стал `&lt;`. Поддерживаются заголовки, абзацы, списки, таблицы, цитаты, выделение и код. **Ссылок и картинок нет намеренно:** модель не должна уводить читателя со страницы, а без ссылок исчезает и весь класс проблем со схемами вроде `javascript:`. Заголовки начинаются с уровня h2 — h1 принадлежит странице результата. Широкая таблица оборачивается в прокручиваемый контейнер, чтобы не ломать вёрстку на телефоне.
- Тест сначала был написан неверно, и это стоит записать: он требовал, чтобы слова `onerror`, `onclick`, `href=` не встречались в выводе вообще. Рендерер отрабатывал правильно — они оставались **обычным текстом** внутри экранированного `&lt;img…&gt;`, — но проверка падала. Переписан на настоящее свойство: каждый тег в выводе разбирается и обязан принадлежать белому списку, а внутри тега не должно быть ни обработчиков событий, ни `href`, ни `src`. Заодно добавлена проверка, что враждебный ввод **остаётся видимым текстом**, а не исчезает молча: читатель не должен получить документ, из которого что-то пропало без следа.
- PHPStan нашёл вторую содержательную вещь: накопление блоков было сделано замыканием с передачей по ссылке, из-за чего анализатор считал ветку таблиц недостижимой, а метод отрисовки таблицы — неиспользуемым. Это не придирка: код действительно плохо читался. Переписан на разбор строки в `classify()` и закрытие блока в `closeBlock()` — обычные методы вместо замыкания.
- Проверки и evidence: `ReportMarkdownTest` — 28 тестов / 234 assertions, включая девять образцов враждебного ввода и настоящий образец отчёта модели. Полный `bin/local-gate.sh` зелёный на MySQL 5.7.44.
- Не сделано / риски: генерации в фоне и кнопки на странице результата пока нет — это следующий пакет. Владелец дал ссылку на свой парный результат Лазаруса как испытательный стенд для проверки будущего разбора.
- Следующий шаг: фоновая генерация по cron и показ разбора на странице результата.

### 07.10 — спроектированы приглашения клиентов; открытые аналоги внесены в план

- Этап / ветка / commit: этапы 07 и 09, `codex/07-invite-flow-plan`. Только документы, кода не касается.
- Повод: владелец разобрал нынешний доступ по общему ключу и назвал его неудобным по делу — ссылку негде хранить, перевыпуск требует SSH и запуска PHP-скрипта, ключ один на всех, и ничто не связывает прохождение с конкретным клиентом. Возражение принято полностью: консольный скрипт ставился, чтобы за вечер прекратить публичную публикацию текстов СМИЛ, а не как продуктовое решение.
- Спроектировано (`docs/roadmap/INVITE_FLOW.md`): приглашение создаётся в кабинете и подписывается так, чтобы владелец узнал своего клиента; подпись видна только в кабинете, респонденту не показывается и ИИ не передаётся. Отправляет владелец своими средствами — копирование, письмо, Telegram, Max, — потому что **платформа не отправляет ничего сама и не хранит контакты клиентов**: ни адреса, ни телефона, ни аккаунта в базе не появляется. Это одновременно удобнее владельцу и честнее по §11, а заодно снимает вопрос, что делать с контактами при удалении кейса. Прохождение привязывается к приглашению с первой секунды, без нынешнего ручного поиска сессии по токену результата. Кабинет показывает состояние — создано, открыто, пройдено — и ведёт от приглашения к результату, а затем к ИИ-разбору.
- Решения по устройству, записанные до реализации: приглашение выдаётся на клиента, а не на методику, поэтому отзыв одного приглашения не ломает ссылки у остальных, в отличие от перевыпуска общего ключа; у приглашения есть срок годности, чтобы забытая ссылка не открывала методику вечно; подпись приглашения и клинический контекст для ИИ — **разные поля с разными правилами**, они не смешиваются.
- Явно записано, что `bin/test-access-link.php` и общий ключ методики удаляются вместе с реализацией приглашений. Видимость методики (`public` / `invite`) остаётся: разделение правильное, меняется только способ выдачи доступа.
- Открытые аналоги внесены в этап 09 таблицей: IPIP вместо СМИЛ и 16PF, PHQ-9 вместо BDI, GAD-7 вместо BAI, HADS заменить или убрать, CSI как пара к Лазарусу. Порядок и границы записаны там же, включая то, что платный контур строится на IPIP как на единственном источнике с явным разрешением на коммерцию, и что **замена методики — клиническое решение владельца, а не техническое**: PHQ-9 не тождественен BDI ни пунктами, ни порогами.
- Проверки и evidence: `bin/local-gate.sh --fast` зелёный; изменений в коде нет.
- Не сделано / риски: приглашения не реализованы — до них работает затычка с общим ключом, и она остаётся именно затычкой. Аналоги не реализованы по решению владельца: сначала доводится основной контур.
- Следующий шаг: по приоритету владельца — довести платный разбор Лазаруса и сохранение результатов клиентов для просмотра в кабинете.

### 02.10 — СМИЛ закрыт ссылкой-приглашением (D-043)

- Этап / ветка / commit: этап 02, `codex/02-smil-invite-only`.
- Цель: прекратить публичное распространение текстов СМИЛ, не убирая методику из платформы — владелец продолжает давать её своим клиентам.
- Сделано: у методик появилась видимость (`public` / `invite`) и ключ доступа — миграция `20260827090000`, СМИЛ переведён в `invite` со сгенерированным ключом. Публичный каталог спрашивает у загрузчика отдельный список `getPublicModules()`, куда закрытые методики не попадают. Страница прохождения закрытой методики без верного ключа отвечает **404, а не 403**: «запрещено» подтверждало бы посторонним сам факт существования методики. Ключ сверяется `hash_equals`, успешный ключ запоминается в сессии браузера, чтобы перезагрузка не выбрасывала респондента. Закрытая методика без заполненного ключа недоступна никому — незаполненная настройка не должна открывать доступ.
- Инструмент: `bin/test-access-link.php` показывает готовую ссылку, перевыпускает ключ (прежние ссылки перестают работать) и умеет вернуть методику в публичный каталог.
- Границы решения: это **шлюз, а не опознание человека**. Ссылку можно переслать. Персональные приглашения на каждого клиента — часть купонного контура этапа 06. Для нынешней задачи достаточно: публичного распространения текстов больше нет.
- Проверки и evidence: `TestVisibilityContractTest` — 7 тестов, включая проверку, что каталог спрашивает именно публичный список, что отказ отдаёт 404, что ключ сверяется в постоянном времени и что пустой ключ закрывает доступ, а не открывает. Фактическое поведение проверено по HTTP: каталог показывает Лазаруса, HADS и шкалы Бека и не показывает СМИЛ; `/test/smil` без ключа — 404, с неверным ключом — 404, с верным — 200, после ключа сессия держит доступ — 200; `/test/lazarus` не затронут — 200. Полный gate зелёный.
- Не сделано / риски: Лазарус остаётся открытым по решению владельца до появления открытого аналога (CSI, см. `open-instruments-review.md`). HADS, BDI и BAI остаются публичными и несвободными — вопрос перемещён, а не снят.
- Следующий шаг: выкладка на рабочий сайт и внесение открытых аналогов в план.

### 09.0 — разведка открытых методик на замену текущему набору

- Этап / ветка / commit: этап 09 (исследование на будущее), `codex/09-open-instruments-review`. Только документ, кода не касается.
- Цель: по запросу владельца выяснить, какие распространённые методики можно использовать без обращения к правообладателю, включая аналог опросника Кеттела и аналог Лазаруса.
- Главное различие, вокруг которого построен обзор: «свободно использовать» почти всегда означает свободно в клинической и исследовательской работе, и это **не** то же самое, что право включить методику в коммерческий продукт. Наш случай — продажа ИИ-разбора поверх результата — попадает ровно в эту разницу, поэтому в таблицах отдельная колонка про явное разрешение на коммерцию.
- Проверено у первоисточников: **IPIP** — общественное достояние, разрешение дано автоматически для любой цели, коммерческой или некоммерческой, обращаться к авторам не требуется. **PHQ-9 и GAD-7** — правообладатель прямо вывел эти материалы из-под своих общих ограничений и разрешил свободное скачивание и использование, но коммерческое применение отдельно не оговорено. **HADS** — защищена международным авторским правом во всех языках, требуется разрешение, лицензия платная. **CSI** (Couples Satisfaction Index) — свободен после заполнения формы, авторы не выдвигают отдельных условий под конкретное применение.
- Найдено то, чего владелец не ожидал: закрытие СМИЛ за токеном не решает вопрос целиком. HADS подтверждённо лицензируемая и платная, BDI и BAI — коммерческие инструменты Pearson, и все три остаются публичными. Проблема не исчезает, а перемещается.
- По запросу про Кеттела: **16PF не свободен**, права у IPAT (ныне PSI Services). Множество сайтов с этим опросником характеризует практику, а не правовой статус. Открытый аналог существует и создавался именно как свободная замена коммерческим личностным опросникам — тот же IPIP, из пунктов которого собраны шкалы, соответствующие и Big Five, и шкалам 16PF.
- Практический вывод для платного контура: **строить его разумно на IPIP** — единственный из просмотренного, где право на коммерцию сформулировано прямо, а не выводится из умолчания.
- Аналог Лазаруса на будущее: **CSI** (Funk & Rogge, 2007), версии на 4, 16 и 32 пункта, парный режим строится так же. Это даёт сценарий владельца — Лазарус в закрытый контур, открытый CSI как публичная и коммерчески пригодная методика; коммерческое применение CSI требует уточнения у авторов, в отличие от IPIP.
- Проверки и evidence: `docs/open-instruments-review.md`. Обзор разделяет проверенное у источника и взятое из общих сведений; статусы WHO-5, CES-D, шкалы Розенберга, PSS и AUDIT помечены как требующие подтверждения. Отдельно оговорено, что замена методики — клиническое решение владельца: PHQ-9 не тождественен BDI, у него другие пункты, пороги и структура. Текстов самих методик документ не содержит. `bin/local-gate.sh --fast` зелёный, изменений в коде нет.
- Не сделано / риски: построчная проверка условий у каждого правообладателя не выполнялась; клиническая сопоставимость замен не оценивалась. Немедленных действий обзор не требует — коммерческого использования сейчас нет.
- Следующий шаг: закрытие СМИЛ за токеном по решению владельца.

### 07.9 — структурированная нагрузка СМИЛ для ИИ

- Этап / ветка / commit: этап 07, `codex/07-smil-ai-context`.
- Цель: дать промпту СМИЛ данные, без которых он не может быть прогнан.
- **Расчёт не тронут:** в `SmilModule.php` только добавлены строки, ни одна существующая не изменена и не удалена (`git diff --stat`: вставки, ноль удалений). Новый метод читает готовый результат `calculateResults()` и ничего не пересчитывает.
- Что уходит наружу: идентификатор формы методики, блок достоверности (L, F, K, индекс F−K, число «не знаю», признак достоверности и предупреждения), десять базовых шкал с T-баллом и уровнем, ведущие шкалы, тип и код профиля, индексы, посчитанные дополнительные шкалы с T и сырым баллом, полнота заполнения.
- Что сознательно **не** уходит и закреплено тестами: формулировки 566 пунктов и ответы респондента по пунктам — модель работает с профилем, а не с отдельными утверждениями, а тексты пунктов принадлежат авторской адаптации; собственные интерпретирующие тексты платформы по каждой шкале — иначе модель пересказывает наш готовый вывод вместо собственного анализа; нормативные M и σ дополнительных шкал — T-балл посчитан на нашей стороне, отправлять нормативные данные наружу незачем; пол в чистом виде — он выражен идентификатором формы, потому что клинически значима именно форма, по которой считался профиль.
- Тест поймал реальную утечку при разработке: ведущие шкалы приходят из расчёта целыми объектами и несли наш интерпретирующий текст. Утечка закрыта в коде, форма ведущих шкал закреплена отдельной проверкой.
- Живой прогон нашёл вторую проблему: внутренний код типа профиля модель повторяет буквально, и в клинический отчёт попало «psychotic». Рядом с кодом теперь идёт русская подпись; сам код сохранён для прослеживаемости.
- Проверки и evidence: `AiReportContextContractTest` — 18 тестов / 141 assertion, включая проверку, что ни одна из первых сорока формулировок пунктов не встречается в JSON. Полный `bin/local-gate.sh` зелёный на MySQL 5.7.44. Живые прогоны на `qwen/qwen3.7-plus`: профессиональный отчёт 10 074 символа со структурой точно по промпту (достоверность → профиль → дополнительные шкалы, с разделением «измерение / интегративный вывод / гипотеза») и 37 строк таблиц; понятный отчёт 8 180 символов. Нагрузка 4.3 КБ.
- Не сделано / риски: snapshot расчёта для воспроизводимости отчёта не сделан. Парного режима у СМИЛ нет и не предполагается.
- Следующий шаг: закрытие СМИЛ за токеном по решению владельца и разведка методик без правообладателей.

### 02.9 — позиция владельца по правам на методики (D-042)

- Этап / ветка / commit: этап 02, `codex/02-rights-position`. Только документы, кода не касается.
- Цель: зафиксировать выясненную владельцем позицию по правам и точно разграничить, что она закрывает, а что нет.
- Позиция владельца: использование методик бесплатно, права требуются для продажи самой методики. Предметом продажи в платформе является только ИИ-интерпретация результата; прохождение, сами методики и базовые результаты остаются бесплатными навсегда. Записано как D-042; технически разделение уже закреплено — capability `paid_interpretation` управляет доступом к разбору и не может закрыть базовый результат (D-002).
- Что позиция **не** закрывает, и это записано в самом решении: два вопроса, которые относятся к воспроизведению, а не к продаже. (а) Публикация текстов пунктов на публичном сайте — распространение адаптации, существующее независимо от платности, то есть относящееся и к бесплатной части. (б) Перенос ключей дополнительных шкал из руководства Собчик в продукт — воспроизведение существенной части авторского труда, и это отличается от применения методики в практике сильнее, чем остальные случаи. Оба ведутся в реестре методик.
- Отдельно остаётся неподтверждённым происхождение конкретных редакций: наборы вопросов брались со стороннего сайта, а не у правообладателя, и владелец сам отмечает расхождение наших формулировок с тем источником.
- Проверки и evidence: формулировка риска №6 в `STATUS.md` переписана — вместо «блокирует платный разбор» теперь названо, что именно снято позицией владельца и что осталось открытым. `bin/local-gate.sh --fast` зелёный; изменений в коде нет.
- Не сделано / риски: реестр методик (`methodology-registry.json`) не переразмечался — статусы `provenance` и `rights` остаются прежними, потому что позиция владельца касается продуктовой модели, а не происхождения конкретных редакций.
- Следующий шаг: структурированная нагрузка для СМИЛ.

### 03.6 — этап 03 закрыт; стек этапа 07 слит в main; решение о модели

- Этап / ветка / commit: этап 03, `codex/03-close-stage`.
- Цель: закрыть Module API v2 решением владельца, разгрузить накопившийся стек веток и зафиксировать выбор модели.
- Закрытие этапа 03: все пять exit criteria выполнены и подтверждены evidence — golden-тесты не менялись, контракт-тесты проходят для каждого модуля и сервер отклоняет неполные и недопустимые ответы, демонстрационный модуль добавляется без правок ядра, slug-ветвлений нет и адаптер не понадобился, документация проверена сквозным walkthrough на чистом клоне (03.4). Статус проставлен в phase-файле и синхронизирован в `ROADMAP.md`, `docs/roadmap/README.md` и `STATUS.md` тем же пакетом. Остаток по WP2 (immutable DTO) и WP8 (сокращение PHPStan baseline) закрытию не мешает и продолжается отдельно.
- Слияние стека: семь веток этапов 05 и 07 и запись о выкладке 08.3 доведены до `main` по одной, в порядке зависимостей (PR #47, #42, #43, #44, #45, #48, #49, #50). Каждая ветка получала `main` слиянием, конфликты были только в журнальных файлах и разрешались одинаково — записи из `main` новее и встают выше. После каждого разрешения прогонялся локальный gate. Открытых PR не осталось.
- Решение D-041: рабочая модель `qwen/qwen3.7-plus`, выбор не окончательный. Модель меняется значением `AI_MODEL` без правки кода; DeepSeek рассматривается как замена, если окажется дешевле при сопоставимом качестве. Отчёт хранит фактически ответившую модель отдельно от запрошенной, поэтому смена модели не делает прежние отчёты необъяснимыми.
- Попутно: цитата из руководства Собчик в `docs/smil-sources-review.md` сокращена до схемы записи. Для инженерного вывода достаточно структуры — номер, название, два списка пунктов и нормы отдельно для мужчин и женщин; полный ключ воспроизводить в репозитории незачем, он берётся из источника при реализации.
- Проверки и evidence: локальный gate — статический контур целиком зелёный (validate, audit, PHPStan level 6, lint, architecture, baseline 147/147) плюс PHPUnit fast. Матрица MySQL 5.7/8.0 в этот раз выполнялась облачным CI: Docker на машине владельца выключен под другой проект, и локальный gate честно об этом сообщил вместо тихого пропуска.
- Не сделано / риски: активными остаются этапы 02, 07 и 08. Незакрытым остаётся риск №6 — происхождение и права на русские формы методик; он блокирует платную выдачу разбора, но не бесплатный контур этапа 07.
- Следующий шаг: этап 07 — структурированная нагрузка для СМИЛ, затем блок дополнительных сведений от специалиста.

### 08.3 — выкладка релиза `7b2053a` (исправление маркера шкалы)

- Этап / ветка / commit: этап 08, `codex/08-release-7b2053a`; выложен `main` = `7b2053a`.
- Цель: по просьбе владельца показать исправление шкалы на рабочем сайте, не дожидаясь готовности этапа 07.
- Сделано: выкладка по PRODUCTION_RUNBOOK §1. sha256 `095ee8cd…76f9f` совпал на сервере побайтово; `.env` скопирован с правами 600; pre-deploy дамп `backups/pre-deploy-7b2053a.sql.gz` (22 108 байт, `gzip -t` прошёл); миграции no-op; переключение `public_html` и `current` атомарное. Ветка исправления шкалы намеренно отпочкована от `main`, а не от стека этапа 07 — поэтому релиз не тащит незавершённую работу по ИИ.
- Проверки и evidence: CI на `main` — fast gate + MySQL 5.7 + MySQL 8.0 success. Первый прогон упал на `composer audit`: `curl error 28` при обращении к packagist, то есть сетевой сбой раннера, а не дефект кода; после перезапуска зелено. Внешний smoke: `/`, `/tests`, `/test/lazarus`, `/api/health` — 200. Живая проверка дефекта: создана сессия Лазаруса с суммой 75 (зона «неудовлетворённость»), страница результата отдаёт `left: 41%` при границе зоны 44.1 % — маркер в красной зоне; до исправления он был бы на 46.9 %, то есть в зелёной.
- Не сделано / риски: откат готов — предыдущий релиз `releases/db922e8` на месте, данные откатываются `backups/pre-deploy-7b2053a.sql.gz`. Стек этапа 07 (PR #42–#45) остаётся не слитым и на сайт не попал.
- Следующий шаг: этап 07 — таблицы в промптах новой версией, сбор возраста для СМИЛ, структурированная нагрузка СМИЛ.

### 04.1 — маркер на шкале результата считался от нуля, а зоны рисуются от минимума

- Этап / ветка / commit: этап 04 (дефект в закрытом этапе), `codex/04-score-scale-marker`.
- Цель: устранить дефект отображения, замеченный владельцем на странице парного результата Лазаруса: отметки 129 и 145 стояли ближе друг к другу, чем следует из чисел.
- Первопричина: в `templates/blocks/score-scale.twig` положение маркера считалось как `score / max * 100`, то есть от нуля, тогда как цветные зоны растягиваются флексом от минимума первой зоны. У Лазаруса шкала начинается с 16, поэтому весь маркер был смещён вправо: балл 16 рисовался на 10 % полосы вместо 0 %, балл 80 — на 50 % вместо 44.4 %.
- Клиническое следствие: **баллы 71–79 при уровне «неудовлетворённость» рисовались маркером внутри зелёной зоны «удовлетворённость»** — картинка противоречила выводу. Расхождение владельца подтвердилось и численно: расстояние между 129 и 145 составляло 10.0 п.п. вместо 11.1 п.п.
- Охват проверен по всем модулям: затронут только Лазарус. У BAI, BDI, HADS и SMIL шкала начинается с нуля, и прежняя формула там случайно совпадала с верной — ни один балл не попадал в чужую зону. Исправление сделано обобщённо, чтобы дефект не всплыл на следующей методике с ненулевым минимумом.
- Сделано: ось шкалы берётся из порогов — минимум первой зоны и максимум последней; результат ограничен диапазоном 0–100 %. Формула scoring не затронута: меняется только положение метки, ни один балл и ни один уровень не пересчитываются.
- Проверки и evidence: `ScoreScaleMarkerTest` (6 тестов / 391 assertion) — минимальный балл на левом краю, максимальный на правом, **все 64 балла зоны «неудовлетворённость» проверены на попадание в красную зону**, расстояние между 129 и 145 соответствует оси, нулевые шкалы остались на прежних позициях, и все текущие модули держат маркер внутри своей зоны. Тест проверен на способность ловить дефект: с возвращённой старой формулой падают три теста из шести. Живая проверка в браузерном контуре: сессия Лазаруса с суммой 75 отдаёт `left: 41%` при границе зоны 44.1 % — маркер в красной зоне; до правки он был бы на 46.9 %, то есть в зелёной.
- Изменённые файлы: `templates/blocks/score-scale.twig`, `tests/ScoreScaleMarkerTest.php` (новый), `docs/roadmap/WORKLOG.md`, `docs/roadmap/STATUS.md`, `CHANGELOG.md`.
- Не сделано / риски: рисков нет, изменение чисто визуальное и покрыто тестами. Замечено попутно: ширины зон считаются как `max - min + 1` (включительно), а ось — как `max - min`, из-за чего граница зоны стоит на 44.1 % вместо 44.4 %. Расхождение 0.3 п.п. незаметно и на выводы не влияет; фиксировать отдельно не стал.
- Следующий шаг: вернуться к этапу 07 — структурированная нагрузка для СМИЛ.
### 05.0 — обзор источников СМИЛ: подростковая форма и дополнительные шкалы

- Этап / ветка / commit: этап 05 (исследование до реализации), `codex/05-smil-sources-review`.
- Цель: ответить на два вопроса владельца до любых правок — одинаковы ли нормы подростковой и взрослой форм и есть ли в источниках подростковые вопросы и дополнительные шкалы. **Кода методики пакет не касается: ни одна строка `modules/smil/` не менялась** (прямое указание владельца).
- Просмотрено: руководство Собчик (224 стр., скан без текстового слоя — читалось постранично по оглавлению), Соломин (72 стр.), «MMPI. Общие сведения» (138 стр.) и Березин–Мирошников (190 стр.) — три последних сплошным поиском по словам «подрост», «юношес», «13—15», «школьн», «детск», «возраст».
- **Подростковая форма: в наших источниках её нет.** Оглавление Собчик не содержит раздела о подростковом варианте вовсе — только Введение, СМИЛ как модифицированный MMPI, Базовый профиль, Примеры интерпретации, Дополнительные шкалы и Приложение. Три текстовых источника упоминают подростков исключительно как поправку к **интерпретации** взрослого профиля: повышение 4-й шкалы может быть нормальным для подростка (Соломин, с. 36), повышенная 5-я шкала часто встречается в нормативном профиле подростков (Общие сведения, с. 101), профиль 9"4-/2 — вариант подростковой нормы, а у взрослого означает эмоциональную незрелость (там же, с. 119). Гипотеза владельца «нормы одни, интерпретация разная» этими цитатами **поддерживается по смыслу, но документально не подтверждается**: подростковых норм не приводит ни один источник. Практический вывод: подростковую форму сейчас не на чем строить — нет ни вопросов, ни норм, ни документа, разрешающего применять взрослые нормы к подросткам. Нужен другой источник.
- **Дополнительные шкалы: полные ключи есть в Приложении Собчик — это меняет оценку риска №5.** Раздел «Дополнительные шкалы» (с. 137) прямо говорит, что автором адаптировано более 200 шкал, что их нормативный разброс 30T–70T и что перевод идёт по формуле `T = 50 + 10(X − M)/σ`, а ключи приведены в Приложении. Ключи там действительно лежат в пригодном для реализации виде: номер, название, список пунктов «верно», список пунктов «неверно» и **отдельные M и σ для мужчин и женщин**. Просмотренные страницы дают номера 46–51 (с. 198) и 194–206 (с. 213); список идёт сплошной нумерацией и занимает ориентировочно с. 195–222 книги, около шести шкал на страницу. Разрыв «23 реализовано против 200 заявленных» объясняется не отсутствием источника, а тем, что источник — скан без текстового слоя.
- Записано в `docs/smil-sources-review.md` с цитатами и номерами страниц, включая предложенный порядок работ: сначала сверить ключи уже реализованных 23 шкал против Приложения (расхождение там было бы дефектом работающего расчёта и важнее расширения), затем OCR с контрольной суммой по числу пунктов, которое в источнике указано явно, затем добавление шкалами по одной с golden-фикстурой и ссылкой на страницу.
- Проверки и evidence: `bin/local-gate.sh --fast` зелёный; изменений в коде нет, документ добавлен. Русского языка для установленного `tesseract` нет — OCR скана потребует отдельной установки.
- Не сделано / риски: формулировки наших 566 вопросов с изданием Собчик **не сверялись** — владелец отмечает расхождение с вариантом psytests.org, откуда набор брался; это отдельная и более важная проверка. Ключи наших 23 дополнительных шкал против Приложения не проверялись. Права на публикацию русских форм (риск №6) обзором не затрагиваются: наличие текста в источнике не равно разрешению его публиковать.
- Следующий шаг: по решению владельца — либо сверка существующих вопросов и шкал с источником, либо возврат к этапу 07 и структурированной нагрузке СМИЛ.
### 07.8 — возраст не спрашивается: форму методики задаёт выбор респондента (D-040)

- Этап / ветка / commit: этап 07, `codex/07-smil-form-variant`; переработка пакета 07.7 по уточнению владельца.
- Причина переработки: владелец уточнил устройство методики. Подростковые варианты СМИЛ предназначены для 13–15 лет и отличаются от взрослых **только формулировкой вопросов**. Значит форму задаёт сам выбор респондента — мужчина, женщина, мальчик, девочка, — и отдельный вопрос о возрасте не нужен ни для подсчёта, ни для выбора формы. Возраст и прочие сведения о себе человек или специалист сообщает блоком дополнительной информации при заказе расширенного разбора, там же, где указывает свой запрос.
- Сделано: СМИЛ больше не требует возраст ни в схеме ответов, ни в метаданных — поле в форме прохождения не выводится. Решение записано как D-040 с полным следствием для реализации. Общий слой при этом сохранил умение проверять возраст: шаблон прохождения умеет показывать это поле по метаданным, и если методика когда-нибудь его включит, значение должно проверяться, а не приниматься без ограничений — до пакета 07.7 оно проходило валидатор насквозь как «лишний ключ».
- Найдено попутно: модуль, переопределяющий `getAnswerSchema()` целиком, не имел новых ключей — Лазарус написан до их появления. Валидатор дополняет схему значениями по умолчанию вместо падения на отсутствующем индексе, поэтому появление нового ключа в контракте не ломает существующие модули.
- Проверки и evidence: `AgeCollectionContractTest` переписан — ни одна методика не спрашивает возраст при прохождении, но валидатор по-прежнему отклоняет отсутствующий, заниженный, завышенный и нечисловой возраст у методики, которая его объявит (проверено на анонимном модуле с диапазоном 13–15). `AnswerValidatorTest` и `AnswerSchemaContractTest` зелёные. Scoring не менялся ни в этом пакете, ни в 07.7.
- Не сделано / риски: подростковый набор вопросов в платформе отсутствует, четырёхзначный выбор демографии не реализован — это отдельный пакет вместе с подтверждением происхождения и прав на русскую подростковую форму (риск №6). **Вопрос владельцу до реализации: одинаковы ли нормы перевода в T-баллы для подростковой и взрослой форм.** Если различаются, это меняет scoring и требует отдельного доказательного пакета с источником.
- Следующий шаг: структурированная нагрузка для СМИЛ — она несёт идентификатор формы, а не возраст.

### 07.7 — сбор и проверка возраста для СМИЛ

- Этап / ветка / commit: этап 07, `codex/07-smil-age`.
- Цель: по решению владельца («спросишь») собирать возраст при прохождении СМИЛ — он нужен для клинического прочтения профиля и станет различителем взрослой и подростковой форм методики.
- Найдено попутно: возраст **вообще не проверялся на сервере**. Он числился «лишним ключом» в схеме ответов и пропускался валидатором, то есть даже для методики, где поле обязательно в форме, на сервер можно было прислать что угодно или не прислать ничего. Форма при этом уже умела показывать поле — не хватало только объявления в метаданных и серверной проверки.
- Сделано: схема ответов расширена парой `requires_age` + `age_range`, по образцу уже существующего `requires_gender`; `AnswerValidator` проверяет возраст и возвращает `invalid_age`. СМИЛ объявляет возраст обязательным в границах 16–100, те же границы записаны в metadata, откуда их берёт форма — валидатор и форма читают одну границу, а не две разные. Контракт схемы в `TestModuleInterface` дополнен новыми ключами (без этого PHPStan справедливо считал их несуществующими).
- Неподвижное ограничение соблюдено: **возраст не участвует в подсчёте**. Нормы Собчик в этой реализации гендерные; тест сравнивает `raw_scores`, `t_scores`, `validity`, `profile`, `indices` и `additional_scores` при возрасте 16, 100 и без возраста вовсе — все совпадают. Golden-фикстуры не менялись.
- Проверки и evidence: `AgeCollectionContractTest` (5 тестов / 32 assertions): объявление и границы, отказ при отсутствии возраста и при 15, 101, «тридцать», −5 и 39.5, приём 16, «39» и 100 (из формы возраст приходит строкой), неизменность scoring, отсутствие лишнего вопроса у остальных методик. Два существующих теста строили набор ответов СМИЛ без возраста и справедливо покраснели: `AnswerValidatorTest` дополнен возрастом, а `AnswerSchemaContractTest` научен строить демографию из самой схемы, а не списком. Полный `bin/local-gate.sh` зелёный на MySQL 5.7.44. Проверено в браузерном контуре: на `/test/smil` поле возраста отрисовано с `min="16"`, `max="100"` и подписью «Минимальный возраст: 16 лет», на `/test/lazarus` поля нет.
- Не сделано / риски: **нижняя граница 16 требует подтверждения владельца** — взята как граница взрослой формы СМИЛ. Подростковая форма как отдельная методика не заводилась. Возраст пока никуда не передаётся: в нагрузку для ИИ он попадёт вместе с реализацией `aiReportContext()` для СМИЛ, и это отдельное решение по §11, потому что возраст — персональный признак.
- Следующий шаг: структурированная нагрузка для СМИЛ.
### 07.6 — таблицы в отчётах и точность названий: промпты переведены на v2

- Этап / ветка / commit: этап 07, `codex/07-prompt-tables`.
- Цель: замечание владельца после чтения отчётов Qwen — данные, табличные по природе, читались бы удобнее таблицей.
- Сделано: все шесть промптов подняты до **v2** (v1 остались на диске: откат — это возврат манифеста на меньший номер версии). Добавлен раздел «Оформление»: табличные данные подаются таблицей Markdown, но рассуждения, гипотезы и рекомендации остаются связным текстом; не больше пяти колонок, иначе таблица не читается с телефона; запрет дублировать одни и те же числа таблицей и текстом рядом. Для каждой методики названы опорные таблицы: у Лазаруса — попунктные значения и зоны расхождений, у СМИЛ — шкалы достоверности, десять базовых и дополнительные шкалы с зонами.
- Найдено и исправлено в том же пакете: первый прогон v2 выдал колонку «Оценка партнёра», хотя в одиночном режиме партнёр опросник не проходил вовсе — это ожидаемая респондентом оценка. Подмена искажает вывод, поэтому во все четыре лазарусовских промпта добавлено требование называть величину «ожидаемая оценка партнёра» и никогда — «оценка партнёра». Повторный прогон дал верное название.
- Проверки и evidence: живой прогон `lazarus | individual | professional` на `qwen/qwen3.7-plus` — таблица на пять колонок с верными данными, иноязычных слов нет, названия колонок точные. `PromptRegistryContractTest` — 13 тестов / 199 assertions; правило «файл вне манифеста запрещён» переформулировано: прежние версии обязаны оставаться на диске, иначе откат невозможен, а запрещён файл, чей **ключ** не объявлен. Добавлен тест, что для каждой версии больше первой предыдущая лежит рядом.
- Записано в phase-файл WP7: модель возвращает Markdown с таблицами, значит выдаче нужен шаг Markdown → HTML с белым списком разметки — вставлять ответ модели в страницу как есть нельзя, это внешний неконтролируемый текст.
- Не сделано / риски: остальные пять промптов v2 живьём не прогонялись — правило оформления у них общее, но проверить стоит вместе с нагрузкой СМИЛ.
- Следующий шаг: сбор возраста для СМИЛ и структурированная нагрузка СМИЛ.
### 07.5 — первый живой вызов провайдера и результат ранней проверки формата входа

- Этап / ветка / commit: этап 07, `codex/07-provider-adapter` (продолжение 07.4).
- Цель: выполнить обязательную раннюю проверку — понимает ли модель структурированный JSON так же, как владелец привык работать со скриншотом и PDF.
- Сделано: синтетическая фикстура Лазаруса (реальных данных нет) прогнана через `aiReportContext()` → опубликованный промпт `lazarus | individual | clear` → живой OpenRouter. **Формат подтверждён:** из 4362 байт JSON получен связный разбор на 9863 символа по заданной структуре, числа точные (105 и 109 из 160), выдуманных данных нет. Опасение «модель не поймёт цифры без картинки» не подтвердилось.
- Найдено три вещи, каждая меняет план: (1) Маршрутизатор `openrouter/free` в первой же попытке отдал запрос `nvidia/nemotron-3.5-content-safety:free` — классификатору безопасности вместо текстовой модели; ответ «User Safety: safe», 17 символов. Маршрутизатор непригоден ни для работы, ни для воспроизводимости. (2) `:free`-эндпоинты массово отвечают 429 с `limit_source: upstream_provider_shared_pool`; из шести опробованных моделей ответила одна, `nvidia/nemotron-3-super-120b-a12b:free`, за 44,8 с. (3) Качество бесплатной модели недостаточно: верная структура и точные числа, но русский текст замусорен иноязычными словами — `teamswork`, `largely`, `gaps`, `everyday`, `support`, а также датское `afstande` и испанское `ciertos`. Клиенту такой документ отдавать нельзя.
- Сделано по итогам: в `AiClient` добавлены повторы с растущей паузой для статусов перегрузки и сбоев провайдера (429, 5xx и родственные) — без них поток на общем пуле нерабочий; неретраибельные отказы (401/403 — ключ, 402 — средства, 404 — политика данных) не повторяются и называются словами, при этом тело ответа провайдера по-прежнему не пересказывается. Убран `curl_close()`: с PHP 8.5 он deprecated и печатал предупреждение прямо в вывод.
- Проверки и evidence: `AiClientContractTest` — 15 тестов / 45 assertions, включая успех после двух 429 с паузами 1 и 2 секунды, остановку на заданном пределе попыток и отсутствие повторов на отклонённом ключе. Тесты в сеть не ходят. Живой прогон описан выше. Ключ владельца в вывод и в Git не попадал.
- Не сделано / риски: сравнение с ручным способом владельца не выполнено — его нужно делать на платной модели, иначе сравнивается не формат входа, а дефекты бесплатной модели. Аккаунт владельца не на бесплатном тарифе (`is_free_tier: false`, расход 0), то есть платная модель доступна и стоит доли цента за отчёт — нужно владельческое решение о расходе.
- Следующий шаг: решение владельца о платной модели для сравнения; затем нагрузка для СМИЛ и редактирование промптов из кабинета.

### 07.4 — адаптер провайдера поверх OpenAI-совместимого endpoint (этап 07, WP1)

- Этап / ветка / commit: этап 07, `codex/07-provider-adapter`.
- Цель: дать платформе возможность обратиться к модели, не привязываясь к конкретному провайдеру (D-039).
- Сделано: `core/Ai/AiClient.php` поверх `chat/completions`; `AiTransport` вынесен в интерфейс, боевая реализация `CurlTransport`, в тестах подменяется — сеть в тестах не используется. `AiProviderSettings` читает `AI_BASE_URL`, `AI_API_KEY`, `AI_MODEL`, `AI_TIMEOUT_SECONDS` и как исторические — `OPENROUTER_API_KEY`/`OPENROUTER_MODEL` (ключ владельца уже лежал под старым именем). `AiCompletion` хранит запрошенную и **фактически ответившую** модель отдельно: `openrouter/free` — маршрутизатор, он выбирает исполнителя сам, и без фактического имени отчёт невоспроизводим. `models()` отдаёт каталог провайдера с пометкой бесплатных — для выпадающего списка в кабинете; вписать идентификатор вручную по-прежнему можно, список нужен для удобства, а не как ограничение. Значение по умолчанию `AI_MODEL` — `openrouter/free`; в локальном `.env` модель переключена с `deepseek/deepseek-chat` на `openrouter/free` по замечанию владельца.
- Границы, заданные до запроса: неопубликованный промпт не отправляется; промпт, не принимающий клинический контекст, отказывается его принять; отсутствие ключа останавливает вызов до обращения к сети. Тело ошибки провайдера **не пересказывается** в исключении — в нём может оказаться эхо запроса, то есть клинические данные, а сообщение исключения попадает в лог. Пустой ответ считается отказом, а не отчётом.
- Проверки и evidence: `AiClientContractTest` — 11 тестов / 35 assertions, включая проверку, что описание настроек не раскрывает ключ, и что тело ошибки провайдера не попадает в сообщение исключения. Полный `bin/local-gate.sh` — см. запись ниже. Каталог OpenRouter опрошен ключом владельца: 417 моделей, из них 20 бесплатных; `openrouter/free` в списке присутствует.
- Записано в phase-файл WP9 по уточнению владельца: промпты должны редактироваться из кабинета без доступа к файлам, списком методик с расширенным разбором и их наборами промптов. Решение по хранению: файлы в `prompts/` остаются версионируемым исходным состоянием в Git, правки владельца ложатся в БД поверх них, реестр отдаёт версию из БД, если она есть, иначе файл.
- Не сделано / риски: исходящий вызов из продуктового потока пока никем не делается — клиент есть, вызывающего кода нет. Нагрузка для СМИЛ не написана. Редактирование промптов из кабинета не реализовано. `openrouter/free` как маршрутизатор даёт невоспроизводимые сравнения — для ранней проверки формата нужно пиновать конкретную модель.
- Следующий шаг: ранняя проверка формата входа на синтетической фикстуре Лазаруса — первый реальный вызов провайдера.
### 07.3 — дополнительные шкалы в понятном СМИЛ, публикация шести промптов, решение о провайдере

- Этап / ветка / commit: этап 07, `codex/07-publish-prompts`.
- Цель: закрыть замечание владельца по промптам, зафиксировать их одобрение как решение и записать выбор провайдера до начала работы над адаптером.
- Сделано: (1) Замечание владельца: в профессиональном промпте СМИЛ дополнительные шкалы разбираются, а в понятном о них не было ни слова. Понятный вариант дополнен — дополнительные шкалы названы во вводной части, получили собственный раздел структуры («что каждая заметная шкала означает житейски и как она уточняет или смягчает картину по основным»), с явным указанием разбирать только переданные во входе и пропускать раздел, если их нет; запрет буквально переводить названия шкал распространён и на дополнительные; объём увеличен с 800–1200 до 1000–1500 слов. Правка внесена в v1: версия была черновиком и в боевой поток не попадала. (2) Шесть промптов переведены в `published` (D-038). Манифест теперь несёт `approved_by` и `approved_at`, а контракт-тест переписан: он не требует «всё draft», а требует, чтобы **любой** опубликованный ключ нёс запись об одобрении — без неё статус `published` не пройдёт. Добавлен тест, закрепляющий разбор дополнительных шкал в обоих видах отчёта СМИЛ. (3) Зафиксировано D-039: провайдер OpenRouter, на старте бесплатная модель, адаптер строится вокруг OpenAI-совместимого endpoint.
- Решения: D-038 (промпты одобрены и опубликованы), D-039 (провайдер и требование совместимости). Дальнейшие правки клинической формулировки идут новой версией файла, а не редактированием опубликованной.
- Записано в phase-файл как открытый вопрос: политика данных бесплатных моделей OpenRouter обычно предполагает использование запросов для обучения. Нагрузка обезличена, но содержание клиническое — до первого реального клиента нужно решение владельца о платной модели без обучения на запросах либо явное согласие на текущие условия. Особенно это касается блока клинического контекста от специалиста, который по своей природе не обезличен.
- Проверки и evidence: `bin/local-gate.sh` целиком зелёный на MySQL 5.7.44; `PromptRegistryContractTest` — 12 тестов / 169 assertions. Исходящих вызовов к ИИ по-прежнему нет.
- Не сделано / риски: **`OPENROUTER_API_KEY` в локальном `.env` пуст** — ключ нужно внести, без него адаптер не сможет сделать ни одного вызова. Конкретная бесплатная модель не выбрана: список у OpenRouter меняется, выбирать надо по факту.
- Следующий шаг: WP1 — адаптер провайдера вокруг OpenAI-совместимого `chat/completions`, затем ранняя проверка формата входа на синтетической фикстуре.
### 07.2 — реестр версионированных промптов (этап 07, WP2) + checkpoint

- Этап / ветка / commit: этап 07, `codex/07-prompt-registry`.
- Цель: дать промптам структуру, версии и явную публикацию — до того, как появится провайдер и первый исходящий вызов.
- Сделано: (1) `core/Ai/Prompt.php` и `core/Ai/PromptRegistry.php`. Файлы промптов лежат в `prompts/<test>/<mode>.<kind>.v<N>.md`, `prompts/manifest.json` хранит статус, опубликованную версию, право на клинический контекст и происхождение текста. Откат — уменьшение номера версии в манифесте, а не правка уже существующего текста. `published()` отдаёт промпт только со статусом `published`; `forReview()` отдаёт текущую версию в любом статусе для проверки владельцем на обезличенных фикстурах. Неизвестный ключ не подменяется общим промптом — возвращается `null`. (2) Заведено шесть ключей: `lazarus | individual|pair | professional|clear` (тексты собраны из `docs/lazarus-ai-report-prompts.md` §3, §4.1–4.3) и `smil | individual | professional|clear`. Профессиональный СМИЛ собран из промпта владельца (Obsidian, «Промпты для анализа СМИЛ») плюс общий технический слой и требование сначала оценивать достоверность профиля; понятный СМИЛ — черновик по принципам clear-варианта, владельцем не проверялся. (3) `PromptRegistryContractTest` (11 тестов / 170 assertions).
- Решения: **все шесть промптов в статусе `draft`** — клиническую формулировку публикует владелец, а не разработчик; отдельный тест фиксирует это состояние и потребует обновления вместе с манифестом, когда владелец одобрит текст. Право на клинический контекст (`allows_owner_context`) есть только у профессиональных промптов: контекст пишет специалист и адресует специалисту, в понятный клиентский разбор он не подмешивается.
- Записаны три открытых вопроса дизайна от владельца (в phase-файл, решения не приняты): возраст для СМИЛ (нужен ИИ, но не должен попадать в scoring), поле клинического контекста в кабинете и его судьба по §11, третий режим Лазаруса `pair_personal` — персональный разбор участника пары на полных парных данных.
- Проверки и evidence: `bin/local-gate.sh` целиком зелёный на MySQL 5.7.44 в Docker. Исходящих вызовов к ИИ по-прежнему нет: провайдер не подключён, ключи не используются, наружу ничего не уходит. Scoring, нормы, golden-фикстуры и схема БД не менялись.
- Изменённые файлы: `core/Ai/Prompt.php`, `core/Ai/PromptRegistry.php`, `prompts/manifest.json`, `prompts/lazarus/*.md` (4), `prompts/smil/*.md` (2), `tests/PromptRegistryContractTest.php`, phase 07, `docs/roadmap/STATUS.md`, `docs/roadmap/WORKLOG.md` — всё новое, кроме документов.
- Dirty-state на момент checkpoint: рабочее дерево чистое, всё закоммичено в `codex/07-prompt-registry`, ветка отправлена, PR открыт и не слит. Предыдущий PR #42 (07.1) тоже открыт и не слит.
- Следующий шаг: ранняя проверка формата входа — сверка отчёта из структурированной нагрузки с тем, что владелец получает своим ручным способом, на одних и тех же данных. Для неё нужен выбор провайдера/модели.
### 07.1 — контракт структурированного контекста для ИИ (этап 07, WP3) + checkpoint

- Этап / ветка / commit: этап 07, `codex/07-ai-report-context`.
- Цель: начать этап 07 с того, что по AGENTS.md требует владельческого решения — с объёма данных, уходящих внешнему ИИ. Сначала граница, потом провайдер и промпты.
- Сделано: (1) `aiReportContext(array $results, string $mode): ?array` добавлен в `TestModuleInterface`; Base возвращает `null` — модуль не отдаёт наружу ничего, пока явно не объявит, что можно. (2) Lazarus реализует `individual` и `pair` ровно по форме из `docs/lazarus-ai-report-prompts.md` §2: пункты с доменом и текстом, собственная оценка, ожидаемая оценка партнёра, gap, итоги, уровень, слабые домены (self ≤ 5) и заметные расхождения (|gap| ≥ 3); пороги вынесены в именованные константы. Парная нагрузка добавляет к двум индивидуальным наборам попунктную разницу, точность взаимного восприятия и общий процент согласия. (3) `AiReportContextContractTest` (10 тестов, 90 assertions) стережёт границу, а не удобство формы: точный список ключей, `gap = self − partner_expected`, соответствие порогов документу, отказ на неизвестном режиме и на неполных результатах, отсутствие любого идентифицирующего поля (список из 15 ключей), отсутствие демографии (пол/возраст остаются внутри платформы, хотя в расчёте они есть), отсутствие HTML и полная сериализуемость в JSON.
- Решения: полезную нагрузку формирует модуль, а не общий слой — общий слой не знает специфики методик (PRODUCT_RULES §7), и решение «что можно отправить» принимается там же, где считается результат. SMIL в этот пакет не входит: его нагрузка (валидность L/F/K, десять базовых и дополнительные шкалы) — отдельное решение, которое делается вместе с промптом владельца.
- Замечание владельца (26.08) — вынесено в phase-файл 07 отдельным разделом «Ранняя проверка формата входа», обязательным до WP5–WP7: владелец до сих пор получал разборы, отправляя модели скриншот, печать или PDF (график СМИЛ со всеми шкалами и сырыми данными, таблицу цифр Лазаруса) — модель сама считывала картинку. Платформа будет отправлять структурированный JSON: этого требует PRODUCT_RULES §6 и это заметно дешевле по токенам, но таким входом владелец не пользовался ни разу, и качество на нём не проверено ничем. Поэтому сразу после реестра промптов делается дешёвая ручная сверка на одних и тех же данных: отчёт из `aiReportContext()` против отчёта, который владелец получает своим нынешним способом. Исходы записаны в phase-файле: не хуже — идём дальше; беднее — правим промпт или расширяем нагрузку (расширение требует отдельного решения по §11); не работает — пересматриваем формат входа до того, как на нём построен весь этап.
- Проверки и evidence: `bin/local-gate.sh` целиком — зелёный на MySQL 5.7.44 в Docker: validate, audit, PHPStan level 6, lint, architecture, baseline 147/147, миграции и полный PHPUnit. Ни одного исходящего вызова к ИИ в пакете нет: провайдер не подключён, ключи не используются, отправка наружу не происходит. Scoring, нормы, golden-фикстуры и схема БД не менялись.
- Изменённые файлы: `modules/TestModuleInterface.php`, `modules/BaseTestModule.php`, `modules/lazarus/LazarusModule.php`, `tests/AiReportContextContractTest.php` (новый), `docs/roadmap/phases/07-ai-reports-therapist-office.md`, `docs/roadmap/STATUS.md`, `docs/roadmap/WORKLOG.md`.
- Не сделано / риски: WP1 (адаптер провайдера) и WP2 (реестр промптов) не начаты — ждут двух владельческих решений: провайдер/модель и потолок расходов на разбор. SMIL-нагрузка ждёт промпта владельца. Snapshot для воспроизводимости отчёта не сделан.
- Dirty-state на момент checkpoint: рабочее дерево чистое, всё закоммичено в `codex/07-ai-report-context`, ветка отправлена в origin, PR открыт и не слит.
- Следующий шаг: по команде владельца «продолжай» — WP2 (реестр промптов по ключам `test | mode | report_kind` на основе четырёх лазарусовских промптов), затем WP1 после выбора провайдера.

### 00P — локальный gate на серверной версии MySQL вместо ожидания GitHub Actions

- Этап / ветка / commit: governance/инструментарий, `codex/00-local-gate`.
- Цель: снять зависимость разработки от доступности GitHub Actions, не теряя проверку на той версии БД, которая реально стоит на сервере. Поводом стал сбой Actions 26.08 (runs висели в очереди больше часа и не создавались на push в `main`), из-за которого выкладка ждала чужую инфраструктуру.
- Сделано: (1) `bin/local-gate.sh` — один прогон вместо семи команд: статический контур (validate, audit, PHPStan, lint, architecture, baseline) плюс миграции и полный PHPUnit на **MySQL 5.7 в Docker**. Контейнер поднимается и удаляется сам, ожидание идёт по healthcheck (контейнер отвечает на порт раньше, чем готов принимать запросы), `.env` проекта не трогается. Режим `--fast` работает без Docker и пропускает тесты слоя данных. Скрипт печатает построчный статус, при провале показывает вывод упавшей проверки и возвращает ненулевой код. (2) Поддержка `DB_PORT` в `config.php`, `core/Database.php` и `phinx.php` с дефолтом 3306 — без неё контейнер невозможно подключить рядом с локальной базой на 3306; `DatabasePortConfigurationTest` закрепляет дефолт, явный порт и отсутствие зашитого порта в phinx. (3) `DEVELOPMENT.md` — раздел gate переписан на одну команду с объяснением, почему версия БД именно 5.7.
- Решения: серверная версия проверена напрямую — `test.23time.ru` работает на MySQL **5.7.21** и PHP **8.3.20**; локально у разработчика MySQL 9.6, то есть без контейнера слой данных проверялся не на той версии, что в production. Ровно эта разница уже давала дефект: implicit TIMESTAMP default ломал миграции на 5.7 (пакет 08.1C). GitHub Actions не отключается и остаётся независимой записью в pull request, но перестаёт быть тем, чего ждут. Матрица 8.0 остаётся только в облачном CI как страховка на случай смены хостинга.
- Проверки и evidence: полный `bin/local-gate.sh` — зелёный, MySQL в контейнере 5.7.44, время прогона 52 сек на первом запуске (со скачиванием образа) и **22 сек** на последующих; `--fast` — **6 сек**. Проверено, что gate не является формальностью: во временный файл `core/TemporaryGateProbe.php` внесена ошибка типа, PHPStan-шаг показал `ПРОВАЛ` с текстом ошибки и скрипт завершился с ненулевым кодом; файл удалён, на чистом дереве код возврата 0. `mysql:5.7` не имеет сборки под arm64 — на Apple Silicon запускается с `--platform linux/amd64`, это отражено в скрипте и в документации.
- Изменённые файлы: `bin/local-gate.sh` (новый), `tests/DatabasePortConfigurationTest.php` (новый), `config.php`, `core/Database.php`, `phinx.php`, `.env.example`, `DEVELOPMENT.md`, `docs/roadmap/STATUS.md`, `docs/roadmap/WORKLOG.md`.
- Не сделано / риски: `.github/workflows/quality.yml` не менялся — вопрос «сокращать ли облачную матрицу» остаётся открытым и отдельным. Поведение приложения не менялось: `DB_PORT` по умолчанию 3306, то есть DSN на сервере остаётся прежним.
- Следующий шаг: доработка кабинета владельца (ссылка на просмотр результата и русские подписи статусов) — по замечанию владельца 26.08.

### 08.2 — выкладка релиза `db922e8` на test.23time.ru

- Этап / ветка / commit: этап 08, `codex/08-release-db922e8`; выложен `main` = `db922e8`.
- Цель: перенести на рабочий домен (D-036) накопленные пакеты 00N, 03.4, 03.5, 00O и 02.8 по процедуре PRODUCTION_RUNBOOK §1.
- Сделано: выкладка по всем восьми шагам. (1) `bin/build-release.sh` → `tmp/release-db922e8.tar.gz`, sha256 `1756ff2c…c1c1e`. (2) Загрузка в `backups/`, sha256 на сервере совпал побайтово. (3) Распаковка в `releases/db922e8/`, `.env` скопирован из предыдущего релиза с правами `600` (hash кабинета на месте). (4) Pre-migration dump → `backups/pre-deploy-db922e8.sql.gz` (19 191 байт, 8 таблиц, `gzip -t` прошёл; до дампа 34 сессии, 1 парное сравнение). (5) `php8.3 vendor/bin/phinx migrate` — новых миграций в релизе нет, все 9 уже `up`, прогон no-op. (6) Атомарное переключение `public_html` → `releases/db922e8/public`. (7) `current` → `releases/db922e8`. (8) Smoke.
- Решения: релиз выложен только после зелёной полной матрицы. GitHub Actions с 15:05 до ~16:20 UTC держал runs в очереди и не создавал runs на push в `main`; после восстановления прогоны на `main` (`79064cb`, `83a5f53`, `5e5f2c8`) и отдельный dispatch на вершине дали fast gate + MySQL 5.7 + MySQL 8.0 — success. PR #39 получил ту же полную матрицу перед слиянием.
- Проверки и evidence: внешний HTTPS-smoke — `/`, `/tests`, все пять страниц методик, `/privacy`, `/terms`, `/api/health` → 200. Сквозной живой прогон HADS: страница теста (56 полей ответов) → `POST /submit` 302 → страница результата 200 (13 700 байт, шкалы «Тревога» и «Депрессия») → PDF 200, 22 748 байт, `application/pdf`. Кабинет на новом релизе: вход 303 → `/admin`, страница 200 («Кабинет владельца», «Найти сессию», «Выйти»), выход 303. Логи ошибок сервера и `storage/logs` пусты. Данные целы: до выкладки 34 сессии, после — 45, прирост 11 полностью объясняется моим же smoke (каждый `GET /test/{slug}` создаёт partial-сессию: 10 загрузок страниц + 1 завершённый прогон); `pair_comparisons` без изменений (1), все пять методик активны.
- Ложная тревога при первом smoke: `/test/bdi`, `/privacy`, `/terms` отдали 500 при быстрой серии из десяти запросов подряд, но те же адреса сразу же вернули 200 с полным HTML, логи ошибок пусты, а повторный smoke с паузами дал 200 на всех десяти адресах. Это троттлинг shared-хостинга на серию запросов, а не регресс приложения. Вывод на будущее: внешний smoke прогонять с паузами, иначе хостинг сам создаёт ложные красные результаты.
- Изменённые файлы: `CHANGELOG.md`, `docs/roadmap/STATUS.md`, `docs/roadmap/WORKLOG.md`. На сервере: новый `releases/db922e8`, переключённые `public_html` и `current`, дамп в `backups/`.
- Не сделано / риски: точки отката на месте — предыдущий релиз `releases/c62f34a` сохранён, откат = вернуть `public_html` на `releases/c62f34a/public`, данные откатываются `backups/pre-deploy-db922e8.sql.gz`. **Найден долг:** `bin/build-release.sh` кладёт в артефакт неотслеживаемые файлы рабочего дерева — в релиз попали четыре отладочных скриншота `smil-*.png` (~2,2 МБ) из корня репозитория. Они вне `public/`, поэтому по вебу недоступны и угрозы не создают, но артефакт должен собираться из git-дерева, а не из рабочего каталога.
- Следующий шаг: этап 07 (D-037), WP1–WP3: адаптер провайдера, реестр промптов, структурированный контекст.

### 02.8 — кабинет владельца включён на test.23time.ru + инструмент смены пароля

- Этап / ветка / commit: этап 02, `codex/02-owner-password-tool`; серверная часть выполнена на релизе `c62f34a` без выкладки нового кода.
- Цель: дать владельцу работающий кабинет на рабочем домене (D-036) и простую, повторяемую процедуру смены пароля.
- Сделано: (1) Кабинет включён на `test.23time.ru`. Код кабинета уже был в выложенном релизе `c62f34a` (`OwnerController`, `OwnerDashboardAuthenticator`, оба шаблона), выключало его только пустое `OWNER_DASHBOARD_PASSWORD_HASH`. Argon2id-хэш сгенерирован локально, передан на сервер файлом и вписан в `~/current/.env` скриптом (не через shell-интерполяцию: `$`-разделители Argon2id ломаются в двойных кавычках — первая попытка записала пустое значение и была исправлена). Перед изменением сделана резервная копия `.env` в `~/backups/env-before-owner-dashboard-<timestamp>.bak`; права `600` сохранены; временные файлы с сервера удалены. Открытый пароль на сервер не передавался и в историю команд сервера не попал. (2) `bin/owner-password.php` — генератор строки `.env` со скрытым вводом и подтверждением, режимом `--stdin` для скриптов и тремя проверками до вывода: сборка PHP даёт Argon2id, хэш подтверждает пароль, пустой пароль отклоняется; на пароле короче 8 символов печатается предупреждение, но строка выдаётся. (3) `DEVELOPMENT.md` — раздел «Пароль кабинета владельца»: как задать, как поменять, почему смены пароля из самого кабинета нет.
- Решения: смена пароля делается заменой строки в серверном `.env`, а не формой в кабинете — иначе веб-приложение должно уметь переписывать собственную конфигурацию во время работы, что на shared-хостинге лишний риск. Владелец выбрал простой запоминающийся пароль, зная, что страница входа публична; договорённость — сменить его перед пилотом с реальными клиентами.
- Проверки и evidence: живой HTTPS-прогон на `test.23time.ru` — `GET /admin/login` 200 (форма отрисована), `POST /admin/login` с верным паролем 303 → `/admin`, `GET /admin` 200 отдаёт «Кабинет владельца», поле «Ссылка на результат или токен», кнопку «Найти сессию» и «Выйти»; неверный пароль — 422; `POST /admin/logout` 303, после выхода `/admin` 303 → `/admin/login`. Отмечено: Beget отдаёт JS-заглушку установки cookie `beget=begetok` до выдачи страницы — это защита хостинга от ботов, не поведение приложения. `OwnerPasswordToolTest` (3 теста): строка скрипта принимается настоящим `OwnerDashboardAuthenticator::isConfigured()`, пустой пароль не печатает строку, короткий предупреждает. Полный локальный gate — см. ниже. Схема БД, scoring и данные сессий не затронуты.
- Изменённые файлы: `bin/owner-password.php` (новый), `tests/OwnerPasswordToolTest.php` (новый), `DEVELOPMENT.md`, `docs/roadmap/STATUS.md`, `docs/roadmap/WORKLOG.md`. На сервере: `~/current/.env` (одна строка) + резервная копия.
- Не сделано / риски: пароль простой по решению владельца — единственная защита от подбора это лимит 10 попыток за 15 минут; сменить перед пилотом. GitHub Actions с 15:05 UTC держит запущенные runs в очереди и не создаёт runs на push в `main` — четыре слитых PR (#35–#38) остались без облачной проверки, поэтому выкладка нового релиза отложена до восстановления CI; локальный полный gate на слитом `main` пройден.
- Следующий шаг: дождаться CI и выложить релиз с `main`; затем этап 07, WP1–WP3.

### 00O — решения владельца о статусе домена и порядке этапов 07/06

- Этап / ветка / commit: governance, `codex/00-owner-sequence-decisions`.
- Цель: зафиксировать два решения владельца от 26.08 до начала работ по ним, чтобы план в панелях соответствовал фактическому намерению.
- Сделано: (1) D-036 — `test.23time.ru` считается рабочим сайтом владельца, а не только техническим staging; следствие: выкладка идёт по PRODUCTION_RUNBOOK с pre-deploy дампом и проверенным откатом, регресс на домене недопустим, при этом открытые ограничения этапа 08 (мониторинг, legal review) решением не отменяются и отдельный production-домен не создаётся. (2) D-037 — расширенный ИИ-разбор сначала доводится до работающего состояния **бесплатно**, и только потом закрывается оплатой; порядок этапов меняется, 07 идёт раньше 06. Phase-файл 07 получил раздел о порядке и разбор по work packages (WP5 без привязки к webhook оплаты, WP6–WP7 в бесплатном контуре), phase-файл 06 переформулирован как переключатель доступа к уже работающему разбору. Таблицы этапов синхронизированы в `ROADMAP.md`, `docs/roadmap/README.md` и `STATUS.md`; «следующие пять действий» переписаны под фактический план (слияние стека и выкладка → включение кабинета владельца → WP1–WP3 этапа 07).
- Решения: D-002 (базовые результаты бесплатны навсегда) не отменяется и не ослабляется. Блокер риска №6 сохраняется, но уточнён: он блокирует **платную** выдачу, а не бесплатный контур этапа 07.
- Проверки и evidence: `DocumentationCurrentStateTest` OK; полный gate прогнан на ветке (см. следующий пункт). Кода, scoring, схемы БД изменения не касаются.
- Изменённые файлы: `docs/roadmap/DECISIONS.md`, `docs/roadmap/phases/07-ai-reports-therapist-office.md`, `docs/roadmap/phases/06-orders-coupons-yookassa.md`, `ROADMAP.md`, `docs/roadmap/README.md`, `docs/roadmap/STATUS.md`, `docs/roadmap/WORKLOG.md`.
- Не сделано / риски: этап 07 не начат — зафиксирован только порядок и границы. Выбор ИИ-провайдера, модели и лимита расходов остаётся открытым владельческим решением и внесён в STATUS.
- Следующий шаг: слияние стека PR #35 → #36 → #37, затем выкладка релиза на `test.23time.ru`.

### 03.5 — устойчивость ModuleLoader и удаление мёртвого санитайзера

- Этап / ветка / commit: этап 03, `codex/03-module-loader-robustness`.
- Цель: закрыть три находки экспресс-аудита загрузчика модулей, воспроизведённые тестами до исправления.
- Сделано: (1) `catch (\Exception)` → `catch (\Throwable)` в `loadModule()`. До исправления модуль, чей конструктор бросает `\Error`/`\TypeError`, не перехватывался и обрушивал весь `discover()` — то есть один сломанный модуль ронял каталог тестов и любую страницу прохождения; regression-тест воспроизводил это как fatal. (2) Директория внутри `modules/`, не объявляющая себя модулем (нет `metadata.json` и `questions.json`), пропускается тихо; лог «Module file not found» остаётся только для каталога, который модулем себя объявил, но потерял класс. До исправления `DemoModuleContractTest` печатал эту строку в вывод PHPUnit на каждом прогоне gate. (3) Кэш реестра в APCu привязан к конкретному modules-пути (`cacheKey()` = ключ + md5 пути) и принимается только после проверки `isUsableRegistry()`: реестр должен быть непустым массивом, где каждая запись несёт `instance`/`metadata`/`path`/`class`, а `instance` — живой `TestModuleInterface`. До исправления загрузчик с нестандартным путём делил один ключ с продуктовым, а любой несовместимый payload (например `__PHP_Incomplete_Class` после смены релиза) отдавался наружу как готовый реестр. (4) Удалён мёртвый `Security::sanitizeHtml()` — ноль потребителей по grep (php/twig/js), при этом его allowlist на `strip_tags` пропускал атрибуты вида `onclick`, то есть метод был готовой ловушкой для будущего вызывающего.
- Решения: инстансы модулей продолжают попадать в APCu — переход на кэширование только карты `slug → class/path` с повторной инстанциацией остаётся отдельным пакетом; здесь закрыты только те режимы отказа, которые проверяемы без расширения APCu. Локально `apcu` недоступен (`function_exists('apcu_fetch') === false`), поэтому кэш-ветка покрыта юнит-тестами предиката и ключа, а не интеграционно.
- Проверки и evidence: `ModuleLoaderRobustnessTest` (5 тестов / 12 assertions) — до правок 1 error + 1 failure, после правок зелёный. Полный gate: validate OK, migrate OK, PHPUnit 253 tests / 2061 assertions, PHPStan level 6 `[OK]`, lint 0 of 100 (после `lint:fix` на новом тесте), architecture exit 0, baseline 147/147. Вывод PHPUnit чист: строк `Module file not found` больше нет. Scoring, нормы, golden-фикстуры, схема БД и шаблоны не менялись.
- Изменённые файлы: `core/ModuleLoader.php`, `core/Security.php`, `tests/ModuleLoaderRobustnessTest.php` (новый), `docs/roadmap/STATUS.md`, `docs/roadmap/WORKLOG.md`.
- Не сделано / риски: рисков не осталось. **Вопрос по APCu закрыт проверкой на сервере 26.08** (SSH, только чтение): на `qdesign.beget.tech` для PHP 8.3.20 `function_exists("apcu_fetch")` и `extension_loaded("apcu")` возвращают `false`, `apcu.ini` в `/etc/php/cgi/8.3/conf.d/` отсутствует (в `extensions.ini` строка закомментирована), самого модуля нет в `extension_dir`. Значит вся ветка кэша в `ModuleLoader` на текущем хостинге мертва: описанное окно устаревания путей после выкладки релиза на этом сервере не возникает, а изменения этого пакета в кэш-ветке не меняют production-поведение вообще. Ветка кода сохранена как защита на случай другого хостинга, отдельный пакет «кэшировать карту модулей вместо инстансов» не нужен.
- Следующий шаг: слияние стека PR #35 → #36 → #37; отдельно — владельческое решение о закрытии этапа 03.
### 03.4 — walkthrough creating-new-test.md в чистом окружении (последний exit criterion этапа 03)

- Этап / ветка / commit: этап 03, `codex/03-doc-walkthrough`.
- Цель: закрыть единственный незакрытый exit criterion этапа 03 — пошаговая проверка руководства по добавлению теста на чистой копии репозитория, с исправлением найденных расхождений.
- Сделано: чистый `git clone` `main` (`9a163a6`) в отдельный каталог, `composer install`, отдельная БД `psytest_walkthrough`, `composer migrate` (9 миграций), baseline `composer test` — 248 tests / 2049 assertions OK (совпал с журналом 03.3). Затем модуль `my-test` собран **строго по тексту руководства**, без домысливания. Результат: декларативное ядро подтверждено — обнаружение `ModuleLoader`, схема ответов `{options, plain, [gender, age]}`, отклонение недопустимого значения (`invalid_answer`) и неполного набора (`incomplete_answers`), scoring, интерпретация и секции работают без единой правки контроллеров, рендерера, валидатора и шаблонов. Сквозной HTTP-прогон на встроенном сервере: `/tests` показывает новый тест, `/test/my-test` рендерит вопросы, POST `/test/my-test/submit` завершает сессию (302), HTML-результат отдаёт score-badge 6/60 «Норма», `/result/my-test/{token}/pdf` — валидный PDF 18 242 байт `application/pdf`.
- Найденные дефекты руководства (4) и исправления: (1) **сниппет миграции §5 не работает** — `$this->insert('tests', ...)` даёт `Error: Call to undefined method AddMyTest::insert()`, у Phinx `AbstractMigration` такого метода нет; заменён на реальный `table('tests')->insert([...])->saveData()` с idempotency-guard и `down()`, как в референсной миграции Лазаруса. (2) **Пропущен обязательный шаг** — без записи в `docs/roadmap/methodology-registry.json` падает `MethodologyRegistryContractTest`, то есть gate краснеет; добавлен раздел §5а с полной формой записи и требованиями контракта. (3) **Ложная инструкция §6** — «добавьте новый модуль в requiredFiles и require-блоки `bin/check-architecture.php`»: проверка проходит с exit 0 и без этого, потому что файл содержит захардкоженные блоки только для пяти текущих модулей; текст приведён к факту, а немодульность checker'а зарегистрирована как долг этапа. (4) **Пропущен шаг PSR-4** — новый модуль без явной записи в `composer.json` даёт предупреждение psr-4 на каждом `composer install`/`dump-autoload` (общее правило `PsyTest\Modules\ => ./modules` не сопоставляет kebab-case директорию); шаг добавлен в §1. Попутно: тот же psr-4 warning печатался в чистой установке для `tests/fixtures/demo-wellbeing/DemoWellbeingModule.php` (наследие 03.3) — добавлен `autoload-dev.exclude-from-classmap: ["tests/fixtures/"]`, фикстуры модулей грузит `ModuleLoader`, а не Composer.
- Решения: `bin/check-architecture.php` в этом пакете не переписывается — модуль-агностичный обход это отдельный рефакторинг ~200 строк (WP6); вместо этого руководство говорит правду, а долг записан в phase-файл. Демонстрационный `my-test` живёт только в walkthrough-клоне и в репозиторий не вносится.
- Проверки и evidence: на клоне после исправленного рецепта полный gate зелёный — validate OK, audit clean, migrate OK, PHPUnit 248 tests / 2072 assertions, PHPStan level 6 `[OK]`, lint 0 of 100, architecture exit 0, baseline 147/147. В репозитории после переноса правок полный gate также зелёный: validate OK, audit clean, migrate OK, PHPUnit 249 tests / 2058 assertions (+1 новый text-contract), PHPStan level 6 `[OK]`, lint 0 of 99, architecture exit 0, baseline 147/147. Новый text-contract `DocumentationCurrentStateTest::testNewModuleGuideMatchesTheVerifiedWalkthroughRecipe` закрепляет все четыре исправления, чтобы руководство не откатилось. Scoring, нормы, golden-фикстуры и схема БД не менялись. Graphify: `STALE` — обновление требует разрешения на передачу изменённых исходников внешнему LLM; граф не использовался как evidence.
- Изменённые файлы: `docs/creating-new-test.md`, `composer.json`, `tests/DocumentationCurrentStateTest.php`, `docs/roadmap/phases/03-module-api-v2.md`, `docs/roadmap/STATUS.md`, `docs/roadmap/WORKLOG.md`.
- Не сделано / риски: модуль-агностичный `bin/check-architecture.php` (долг WP6); форма `methodology-registry.json` требует непустой `provenance.missing` даже для `verified` — вопрос к legal review. Рисков для рантайма нет: изменения документационные плюс autoload-исключение для тестовых фикстур.
- Следующий шаг: владельческое решение о закрытии этапа 03; отдельным пакетом — устойчивость `ModuleLoader` (кэш APCu, обработка `\Throwable`).
### 00N — уборка Superpowers: локальные артефакты удалены, история перенесена в архив

- Этап / ветка / commit: governance-followup, `codex/00-superpowers-archive`.
- Цель: устранить остаточную поверхность Superpowers, которая уже не является действующей методологией (AGENTS.md §Приоритет, ENGINEERING_RULES §11), но продолжала занимать место в рабочем контексте и на диске.
- Сделано: (1) `docs/superpowers/` перенесён `git mv` в `docs/archive/superpowers/` — историческая ценность сохранена, но материалы больше не лежат рядом с действующими доками; (2) ссылки в `AGENTS.md` §Приоритет и `ROADMAP.md` §Навигация приведены к новому пути; исторические упоминания в `docs/audit/`, `docs/archive/`, `LESSONS.md` и прошлых записях WORKLOG намеренно не переписывались; (3) локальный неотслеживаемый `.superpowers/` (79 файлов, 6 МБ, артефакты сессий 22.06) перемещён в Корзину — восстановим при необходимости; (4) отключённый плагин `superpowers@claude-plugins-official` 6.3.0 деинсталлирован из окружения владельца (`enabledPlugins` уже стоял в `false`, то есть плагин фактически не работал ни в одной сессии).
- Решения: `docs/superpowers/` не удаляется, а архивируется — на планы 22.06 ссылаются исторические audit-документы; исключение `/.superpowers` в `bin/build-release.sh` оставлено как защита от повторного появления каталога.
- Проверки и evidence: `DocumentationCurrentStateTest` — 4 tests / 88 assertions OK; grep по `tests/`, `bin/`, `.github/` подтвердил, что путь `docs/superpowers` нигде не пинится тестами и CI; полный fast-набор PHPUnit 235 tests / 1957 assertions OK. Код, scoring и схема БД не менялись. Graphify: `STALE` (101 changed) — обновление требует разрешения на передачу изменённых исходников внешнему LLM; граф не использовался как evidence.
- Изменённые файлы: `docs/superpowers/**` → `docs/archive/superpowers/**` (8 файлов, rename), `AGENTS.md`, `ROADMAP.md`, `docs/roadmap/STATUS.md`, `docs/roadmap/WORKLOG.md`.
- Не сделано / риски: рисков нет — изменения только документационные и в локальном окружении; runtime не затронут.
- Следующий шаг: walkthrough `creating-new-test.md` в чистом окружении (последний exit criterion этапа 03).

### 03.3 — демо-модуль, удаление мёртвого хука, актуальное руководство (Module API v2)

- Этап / ветка / commit: этап 03, `codex/03-lazarus-adapter`.
- Цель: доказать exit-criterion «новый тест без изменений ядра», убрать доказанно мёртвую легаси-поверхность и привести `creating-new-test.md` к фактическому состоянию (WP5–WP7).
- Сделано: пакет начат как «legacy adapter + миграция Lazarus», но аудит показал — адаптер не нужен: модули уже на декларативной поверхности v2 (03.1B/C, 03.2), slug-ветвлений в общем слое нет. Переформулирован в три результата. (1) Демо-модуль `tests/fixtures/demo-wellbeing/` (metadata/questions/класс только с calculateResults/generateInterpretation/buildSections) + `DemoModuleContractTest` (4 теста): обнаружение общим ModuleLoader по кастомному пути, отсутствие переопределений кроме доменных методов, валидация через общий AnswerValidator, сквозной web+PDF рендеринг секций; для DB-независимости теста `ModuleLoader` переведён на ленивый доступ к БД. (2) Удалён мёртвый хук `getResultTemplate()` из интерфейса и Base (ноль потребителей — grep по core/controllers/modules/templates); `getCustomJavaScript()` оставлен как живой (test-wrapper.twig). (3) `docs/creating-new-test.md` переписан из «исторического черновика» в актуальное руководство на примере демо-модуля; `DocumentationCurrentStateTest` закрепил новое состояние (маркеры легаси `renderResults`/Chart.js запрещены).
- Решения: WP5 закрыт «выполнено другим путём» без adapter-слоя (записано в phase-файле); демо-модуль живёт в tests/fixtures и не регистрируется в каталоге.
- Проверки и evidence: полный gate — validate OK, audit clean, migrate OK, PHPUnit 248 tests/2049 assertions, PHPStan level 6 `[OK]`, lint 0, architecture exit 0, baseline 147/147 — pass. Golden-фикстуры не менялись. Graphify: `STALE` (93 changed) — semantic-обновление требует разрешения на передачу изменённых исходников внешнему LLM; fallback — прямое чтение исходников, граф не использовался как evidence.
- Изменённые файлы: `core/ModuleLoader.php`, `modules/TestModuleInterface.php`, `modules/BaseTestModule.php`, `tests/fixtures/demo-wellbeing/*` (новое), `tests/DemoModuleContractTest.php` (новый), `tests/DocumentationCurrentStateTest.php`, `docs/creating-new-test.md`, phase 03, STATUS.md.
- Не сделано / риски: walkthrough руководства в чистом окружении не выполнялся — обязательное условие закрытия этапа 03; CODE-01 baseline shrink продолжается отдельными пакетами.
- Следующий шаг: walkthrough creating-new-test.md в чистом окружении → владельческое решение о закрытии этапа 03.

### 03.2 — renderer contract (Module API v2, WP4)

- Этап / ветка / commit: этап 03, `codex/03-renderer-contract`.
- Цель: формализовать контракт рендеринга для единичного результата, pair result, таблиц, шкал и защищённого SMIL chart component — убрать модульные знания из общего слоя.
- Сделано: `core/ResultSectionRenderer.php` — единственный dispatch секций в HTML для PDF-ветки (логика и статический SMIL-chart перенесены из `ResultController` дословно); `pairChartData(array): ?array` добавлен в `TestModuleInterface` (Base — null; реализует Lazarus, ковариантный return), в контроллере удалены `instanceof LazarusModule` и импорт конкретного модуля; `RendererContractTest` (19 тестов) закрепляет: секции всех пяти модулей рендерятся standalone web-блоками и через общий PDF-рендерер, pair chart декларативен (не-chart модули возвращают null), контроллеры не импортируют конкретные классы модулей, канонический SMIL-график (`blocks/profile-chart.twig` + `public/js/smil-profile-classic.js`) защищён от замены. Попутно: stale-baseline entry удалён (148→147, cap обновлён), ResultSection/ResultSectionRenderer добавлены в requiredFiles и require-блоки `bin/check-architecture.php`; ARCHITECTURE.md дополнен описанием рендеринга.
- Решения: web-ветка остаётся на twig-dispatch (`result-layout.twig`), PHP-рендерер покрывает только PDF — strangler без big-bang; блоки могут приходить с именами без `.twig`, нормализация в рендерере сохранена как в прежнем коде.
- Проверки и evidence: полный gate — validate OK, audit clean, migrate OK, PHPUnit 244 tests/2034 assertions (включая новые 19/89), PHPStan level 6 `[OK]`, lint 0 fixes, architecture check exit 0 (все модули, секции рендерятся), baseline 147/147 — pass. `PhpStanBaselineCheckTest` пинил строку «148 entries» — обновлён на 147 вместе с baseline. Graphify: `STALE` (86 changed) — инкрементальное semantic-обновление требует разрешения на передачу изменённых исходников внешнему LLM; fallback — прямое чтение исходников, граф не использовался как evidence.
- Изменённые файлы: `core/ResultSectionRenderer.php` (новый), `modules/TestModuleInterface.php`, `modules/BaseTestModule.php`, `controllers/ResultController.php`, `tests/RendererContractTest.php` (новый), `tests/PhpStanBaselineCheckTest.php`, `bin/check-architecture.php`, `bin/check-phpstan-baseline.php`, `phpstan-baseline.neon`, `ARCHITECTURE.md`, phase 03, STATUS.md.
- Не сделано / риски: миграция модулей на контракт (WP5) только впереди; поведение рантайма не менялось — golden-фикстуры не тронуты.
- Следующий шаг: пакет 03.3 — legacy adapter и миграция Lazarus на Module API v2.

### docs — восстановление хронологии WORKLOG, правила журнала, синхронизация панелей

- Этап / ветка / commit: governance-followup, `codex/00-docs-panels-sync`; `8f37786`, `61c976d`, `40f7258`, `95e220e`, merge `e6eae84` (PR #31).
- Цель: устранить расхождение панелей и процесса, приведшее к ложной картине этапов на старте сессии 25.08.
- Сделано: записи 25.08 (00L→03.1C с деплоями) перенесены из хвоста файла в раздел текущей даты по убыванию, дубли удалены; AGENTS.md — старт сессии читает новейший WORKLOG первым и сверяет панели, запись в WORKLOG обязательна до checkpoint, закрытие этапа синхронизирует таблицы ROADMAP.md и docs/roadmap/README.md; ENGINEERING_RULES §4.9/§11 дополнены; STATUS/ROADMAP/два README синхронизированы (00/04 Завершён, 03 В работе, активные 02+03+08); в репозиторий принят канонический образец расширенного отчёта Лазаруса владельца и черновики промптов.
- Проверки и evidence: `DocumentationCurrentStateTest` — OK (4 tests / 88 assertions); PR fast gate 37s pass; полная матрица на `main` после merge — success (run 32899356005: fast + MySQL 5.7 + MySQL 8.0). Код/scoring/схема БД не менялись; staging остаётся на `c62f34a`.
- Следующий шаг: инженерный пакет 03.2 renderer contract в отдельной сессии.

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
