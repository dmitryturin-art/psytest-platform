# Текущий статус программы

Обновлён: 2026-08-25. Это единственная оперативная панель; порядок фиксации перед паузой находится в [CHECKPOINT.md](CHECKPOINT.md). Панель синхронизирована с новейшими записями [WORKLOG.md](WORKLOG.md); первоисточник хронологии — WORKLOG.

## Сейчас

- Активные этапы: [02 — клиническая безопасность и бесплатный пилот](phases/02-clinical-privacy-pilot.md) + [03 — Module API v2](phases/03-module-api-v2.md) + [08 — staging на проверке владельца](phases/08-production-deployment.md). Этапы 00 и 04 закрыты 25.08; по 03 все exit criteria выполнены (03.4 закрыл walkthrough), нужно решение владельца о закрытии этапа.
- Состояние: `test.23time.ru` работает на release `c62f34a` через стабильную точку `current`: HTTPS redirect, PHP 8.3, MySQL 5.7, 9 migrations. Сессионная cookie имеет `Secure`, `HttpOnly`, `SameSite=Lax`; динамические security headers не дублируются. Откат — на предыдущий релиз через `releases/`. Направление A целиком на staging: главная, каталог, мобильное прохождение, веб-график совмещённых профилей пары и compact PDF; cleanup-cron (03:17) настроен владельцем, restore drill пройден, `PRODUCTION_RUNBOOK.md` готов.
- Этап 03, пакет 03.5: устойчивость `ModuleLoader` — сломанный модуль больше не обрушивает каталог (`\Throwable`), чужая директория в `modules/` не логируется как дефект, кэш APCu привязан к modules-пути и принимается только после проверки живых инстансов; удалён мёртвый `Security::sanitizeHtml()` с небезопасным allowlist. Проверено на сервере 26.08: APCu на Beget для PHP 8.3 не установлен (`extension_loaded("apcu") === false`, модуля нет в extension_dir), поэтому кэш-ветка загрузчика в production мертва и риска устаревания путей после выкладки нет; отдельный пакет по кэшированию не нужен.
- Этап 03, пакет 03.4: последний exit criterion закрыт — walkthrough `creating-new-test.md` выполнен на чистом клоне со сквозным прогоном (каталог → прохождение → HTML-результат → PDF) без правок общего слоя. Руководство содержало четыре дефекта (неработающий сниппет миграции Phinx, пропущенную обязательную запись в реестре методик, ложную инструкцию про `bin/check-architecture.php`, пропущенный шаг PSR-4) — все исправлены и закреплены text-contract тестом. Этап 03 готов к владельческому решению о закрытии.
- Governance 00N: остаточная поверхность Superpowers убрана — `docs/superpowers/` перенесён в `docs/archive/superpowers/` со ссылками в AGENTS.md/ROADMAP.md, локальные артефакты и отключённый плагин удалены из окружения. Методология не действует с этапа 00; изменений в коде нет.
- Управление знаниями: локальный Graphify имеет обязательную freshness-проверку перед архитектурным query; после 00K граф `STALE`, потому что обновление требует явного разрешения на передачу изменённых исходников внешнему LLM. В этом пакете граф не используется как evidence.
- Governance 00F: старт сессии теперь требует только status, phase активного пакета и чистое понимание Git-state; остальные документы читаются по типу задачи и явно отражаются в отчёте.
- Governance 00G–00H: `STATUS.md` стал единственной оперативной панелью, `CHECKPOINT.md` — только протоколом; исходный audit-plan 2026-08-15 перенесён в архив, а рабочей навигацией по findings остаётся traceability.
- Governance 00I: четыре text-contract test миграций заменяются одним integration test фактически применённой схемы; список маршрутов для документации читается из роутера.
- Governance 00J: по D-032 удаляются неподключённые AI-consent и country/crisis scaffolding; cleanup-миграция удаляет их таблицы из уже развёрнутых БД, а schema test контролирует итоговое отсутствие.
- Governance 00K: CI разделён на единичный fast gate и условную матрицу DB-тестов. В PR MySQL 5.7/8.0 запускаются только для слоя данных, migrations, зависимостей и CI; каждый push в `main` по-прежнему выполняет полную DB-матрицу перед deployment.
- Ревью от 25.08 ([docs/audit/2026-08-25-project-review.md](../audit/2026-08-25-project-review.md)) подтвердило зелёный gate и выдало приоритизированный план. Выполнено 00L (архив устаревших доков, `composer migrate` в gate) и 04.0G: мёртвый Chart.js-контур снят, вместо него добавлен нативный веб-график совмещённых профилей пары Лазаруса (вариант C — наложенные линии с красными зонами расхождений и тултипами по точкам) на странице результата и на `/pair/{id}`; детальная web-таблица и компактный PDF не менялись (guard-тесты). Release `4775dc4` выложен на staging (PR #25, CI success, smoke прошёл, данные БД не затронуты: 28 сессий/1 пара; rollback `3a2daa8`). Тултипы проверены владельцем на staging 25.08 — работает, пакет 04.0G принят. Решения владельца от 25.08: D-033 — legacy `services/*` заморожен до этапов 06/07 (не удалять); D-034 — подтверждена продуктовая модель платных разборов и купонного потока (уточнения в phase-файлах 06/07, включая историю прохождений при авторизации). Этап 00 закрыт. 04.0H закрыл UX-03 (touch-зоны 24px, контраст WCAG AA с контрактом), этап 04 закрыт. 02.7C удалил legacy IP/UA-колонки и старые значения (D-035) — минимизация технических метаданных завершена. 08.1F: `current` + cleanup-cron настроен владельцем (03:17 ежедневно). 08.1G: restore drill пройден (дамп восстанавливается целиком, сверка строк сошлась), `PRODUCTION_RUNBOOK.md` готов (предусловия, выкладка, откат, go-live чек-лист). 08.1G: restore drill пройден, PRODUCTION_RUNBOOK.md готов; для бэкапов используется ежедневный автоматический бэкап Beget (решение владельца — свой ночной дамп не нужен). 03.1A golden characterization; 03.1B capability registry; 03.1C answer schema validator (`getAnswerSchema()` + схема-ориентированный `AnswerValidator`); 03.2 renderer contract (`ResultSectionRenderer`, декларативный `pairChartData()`, `RendererContractTest`, baseline 147); 03.3 демо-модуль `tests/fixtures/demo-wellbeing/` (proof «новый тест без изменений ядра»), удаление мёртвого хука `getResultTemplate()`, актуальное руководство creating-new-test.md. WP3–WP7 этапа 03 закрыты (WP5 выполнен без адаптера — см. phase-файл); до закрытия этапа остался walkthrough доки в чистом окружении. Активные фронты: 03 (завершение), 02 и 08 (остались владельческие пункты: legal review, мониторинг, staging acceptance).
- 04.0F: подтверждён дефект PDF результата Лазаруса с парным сравнением — компактный PDF-layout не получал `is_pdf`, поэтому в документ попадала широкая web-таблица. Исправление из PR #23 выложено как `3a2daa8`: pair result PDF использует компактную таблицу на A4 landscape без разрыва строк; scoring не менялся. Автоматические CI/deployment smoke прошли, нужна ручная проверка PDF владельцем.
- Последний выложенный пакет: 04.0F `3a2daa8` — общий PDF результата Лазаруса с парным сравнением фактически переведён на compact landscape-компоновку. Полная CI-матрица PHP 8.3/MySQL 5.7 и 8.0, миграция staging и внешний HTTPS smoke — success; rollback `5da9ab5` готов.
- Baseline commit: `6c51cc3` (`main` на начало аудита).
- Состояние продукта: quality gates, dependency safety, legacy payment containment, CSRF и границы result-token улучшены; публичная продажа пока не готова к запуску.
- Последние завершённые work packages: CI для PHP 8.3 (`0c91adf`), Linux-совместимый autoload Лазаруса (`b82347e`), CSRF enforcement (`e42eb89`) и прозрачный протокол статусов (`ed3d896`).
- 01.4 принят в `main`: lookup результата разделён с pair-reference; GitHub Actions [31904747962](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31904747962) — success. 01.5A route/session integrity и 01.5B server validation подтверждены CI. 01.5C устранил конфликт `uq_partner_token`; release gate снова зелёный.

## Готовность этапов

| Этап | Состояние | Прогресс/условие перехода |
|---|---|---|
| 00 | Завершён | закрыт 25.08: 00A–00M выполнены, находки ревью от 25.08 применены (00L/00M), baseline воспроизводим |
| 01 | Завершён | containment/security boundaries, validation, web-root hygiene и PAIR-01 подтверждены CI |
| 02 | В работе | lifecycle, BDI safety, factual privacy copy, реестр методик, owner dashboard и минимизация метаданных (02.7C/D-035) реализованы. Нужны staging/pilot evidence, automated BDI browser coverage и legal review |
| 03 | Готов к закрытию | 03.1A–03.1C characterization/registry/schema (25.08, PR #28–#30); 03.2 renderer contract (PR #33); 03.3 demo module + dead hook removal; 03.4 walkthrough в чистом окружении выполнен, 4 дефекта руководства исправлены. Все exit criteria ✓ — нужно владельческое решение о закрытии |
| 04 | Завершён | закрыт 25.08: UX-01..03 закрыты (04.0G pair chart принят владельцем, 04.0H touch/контраст), направление A внедрено; SMIL-график не менялся |
| 05–07, 09 | Не начаты | открываются по exit criteria предыдущих этапов; исследования допустимы раньше без release |
| 08 | На проверке владельца (staging) | публичный HTTPS staging работает на `4775dc4`+; cleanup-cron и restore drill готовы (08.1F/G), rollback готов; production не начат |

## Baseline, обнаруженный аудитом

| Проверка | Результат на 2026-08-15 | Интерпретация |
|---|---|---|
| PHPUnit | 103 tests, 1153 assertions — pass | Полезная база; security packages добавляют целевые регрессии, browser flow ещё не покрыт полностью |
| Composer validate | pass | `composer.json` синтаксически корректен |
| PHP syntax/style | pass | Не доказывает корректность поведения |
| PHPStan | pass, baseline 148 | Новые ошибки запрещены; baseline нужно постепенно уменьшать |
| Architecture check | pass: 5 модулей, шаблоны и статика | project root исправлен и покрыт узким regression-тестом |
| PHPStan baseline guard | pass: ровно 148 entries | `composer baseline:check` запрещает незаметный рост baseline |
| Dependency audit | pass: `dompdf` 3.1.6, audit clean | `DEP-01` закрыт в `7272e51`; lock воспроизводим для PHP 8.3 |
| Browser smoke | пройден частично | BDI progress и пустая sticky navigation исправлены на desktop/mobile; остаются accessibility и responsive-дефекты |

Свежие команды и точный вывод добавляются в [WORKLOG.md](WORKLOG.md); эта таблица не заменяет повторный baseline run.

## Активные риски

1. Legacy payment endpoints безопасно retired, но новый YooKassa/AI flow ещё не спроектирован и не реализован.
3. BDI item 9 создаёт server-side signal; 02.2B публикует generic notice без country/resource reader. Desktop/mobile browser QA и PHP 8.3/MySQL CI пройдены; automated browser coverage остаётся отдельным пакетом.
4. 02.4A устраняет ложные public privacy/delete claims; 00C приводит local developer docs к коду, но production runbook и legal review ещё не завершены.
5. Дополнительные шкалы SMIL: заявлено 200, фактически найдено 23; часть норм противоречива.
6. Происхождение, version и права конкретных русских форм всех пяти методик не подтверждены документами в репозитории; это блокирует paid interpretation до отдельной проверки.
7. Два PDF результатов присутствуют в старой Git history; в актуальной ветке они не отслеживаются. Владелец подтвердил, что они обезличены, и решил не переписывать историю.
8. `test.23time.ru` работает по HTTPS; Basic Auth не используется по D-029. До production используются только synthetic/добровольно введённые данные.
9. Staging DB работает на MySQL 5.7.21. 08.1C нашёл и устранил несовместимый implicit TIMESTAMP default; чистые MySQL 5.7 и 8.0 теперь обязательны в CI.

Полный список и владельцы закрытия: [AUDIT_TRACEABILITY.md](AUDIT_TRACEABILITY.md).

## Следующие пять действий

1. Владелец завершает приёмку staging: основные flows направления A и повторная визуальная проверка полного парного result PDF Лазаруса.
2. Legal review и подтверждение происхождения/прав методик (риск №6) — блокер платных разборов перед открытием этапов 06/07.
3. Этап 03, завершение: walkthrough creating-new-test.md в чистом окружении, затем владельческое решение о закрытии этапа.
4. После приёмки staging — короткий бесплатный пилот (exit этапа 02).
5. Отдельно запланировать будущие ссылки в results/PDF из [issue #9](https://github.com/dmitryturin-art/psytest-platform/issues/9).

## Решения владельца, нужные сейчас

- Финальная приёмка staging: полный парный PDF Лазаруса (04.0F follow-up) и основные flows направления A.
- Legal review/provenance методик: без подтверждённых прав платная интерпретация не открывается даже после этапов 06/07.

## Checkpoint

[CHECKPOINT.md](CHECKPOINT.md) содержит только протокол команды «сделай checkpoint»; актуальное состояние находится выше в этом файле.
