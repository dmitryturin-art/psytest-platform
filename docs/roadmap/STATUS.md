# Текущий статус программы

Обновлён: 2026-08-24. Это единственная оперативная панель; порядок фиксации перед паузой находится в [CHECKPOINT.md](CHECKPOINT.md).

## Сейчас

- Активные этапы: [00 — governance и воспроизводимый baseline](phases/00-governance-baseline.md) + [02 — клиническая безопасность и бесплатный пилот](phases/02-clinical-privacy-pilot.md) + [04 — внедрение выбранного направления A](phases/04-ui-ux-redesign.md) + [08 — staging на проверке владельца](phases/08-production-deployment.md).
- Состояние: `test.23time.ru` работает на release `5da9ab5`: HTTPS redirect, PHP 8.3, MySQL 5.7, 7 migrations. Сессионная cookie имеет `Secure`, `HttpOnly`, `SameSite=Lax`; динамические security headers больше не дублируются. Rollback `779a2b2` сохранён. Новая главная `/`, каталог `/tests`, мобильное прохождение и обновлённое парное сравнение Лазаруса доступны на staging.
- Управление знаниями: локальный Graphify имеет обязательную freshness-проверку перед архитектурным query; после 00K граф `STALE`, потому что обновление требует явного разрешения на передачу изменённых исходников внешнему LLM. В этом пакете граф не используется как evidence.
- Governance 00F: старт сессии теперь требует только status, phase активного пакета и чистое понимание Git-state; остальные документы читаются по типу задачи и явно отражаются в отчёте.
- Governance 00G–00H: `STATUS.md` стал единственной оперативной панелью, `CHECKPOINT.md` — только протоколом; исходный audit-plan 2026-08-15 перенесён в архив, а рабочей навигацией по findings остаётся traceability.
- Governance 00I: четыре text-contract test миграций заменяются одним integration test фактически применённой схемы; список маршрутов для документации читается из роутера.
- Governance 00J: по D-032 удаляются неподключённые AI-consent и country/crisis scaffolding; cleanup-миграция удаляет их таблицы из уже развёрнутых БД, а schema test контролирует итоговое отсутствие.
- Governance 00K: CI разделён на единичный fast gate и условную матрицу DB-тестов. В PR MySQL 5.7/8.0 запускаются только для слоя данных, migrations, зависимостей и CI; каждый push в `main` по-прежнему выполняет полную DB-матрицу перед deployment.
- 04.0F: подтверждён дефект PDF результата Лазаруса с парным сравнением — компактный PDF-layout не получал `is_pdf`, поэтому в документ попадала широкая web-таблица. Исправление подготовлено в `codex/04-fix-lazarus-pair-pdf-overflow`: pair result PDF использует компактную таблицу на A4 landscape без разрыва строк; scoring не менялся. Нужны CI и staging deployment.
- Последний выложенный пакет: 04.0E `5da9ab5` — meter совпадения приведён к единому компоненту шкалы, раскрытие подробностей стало заметным control, а pair PDF получил отдельную compact landscape-компоновку. CI PHP 8.3/MySQL 5.7 и 8.0, затем внешний HTTPS smoke — success.
- Baseline commit: `6c51cc3` (`main` на начало аудита).
- Состояние продукта: quality gates, dependency safety, legacy payment containment, CSRF и границы result-token улучшены; публичная продажа пока не готова к запуску.
- Последние завершённые work packages: CI для PHP 8.3 (`0c91adf`), Linux-совместимый autoload Лазаруса (`b82347e`), CSRF enforcement (`e42eb89`) и прозрачный протокол статусов (`ed3d896`).
- 01.4 принят в `main`: lookup результата разделён с pair-reference; GitHub Actions [31904747962](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31904747962) — success. 01.5A route/session integrity и 01.5B server validation подтверждены CI. 01.5C устранил конфликт `uq_partner_token`; release gate снова зелёный.

## Готовность этапов

| Этап | Состояние | Прогресс/условие перехода |
|---|---|---|
| 00 | В работе | governance-каркас и baseline готовы; 00K убирает двойной запуск общего gate, сохраняя MySQL 5.7/8.0 для DB-risk PR и каждого push в `main`; production runbook относится к 08 |
| 01 | Завершён | containment/security boundaries, validation, web-root hygiene и PAIR-01 подтверждены CI |
| 02 | В работе | lifecycle, BDI safety, factual privacy copy, реестр методик и owner dashboard реализованы; IP/User-Agent не собираются, неподключённый AI-consent задел снят по D-032. Нужны staging/pilot evidence, automated BDI browser coverage и legal review |
| 03, 05–07, 09 | Не начаты | открываются по exit criteria предыдущих этапов; исследования допустимы раньше без release |
| 04 | В работе | владелец выбрал A; новая главная/каталог и мобильное прохождение выложены на staging. После замечания владельца подготовлен 04.0F для фактического compact/landscape PDF парного результата; нужны CI и повторная выкладка. Защищённый SMIL-график не меняется |
| 08 | На проверке владельца (staging) | публичный HTTPS staging работает; cookie/headers проверены внешним smoke, payment/AI/admin выключены, rollback готов; production не начат |

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

1. Владелец принимает направление A на [staging](https://test.23time.ru/) и проверяет основные тесты.
2. Завершить CI для 04.0F, выложить исправленный PDF Лазаруса на staging и повторно проверить полный парный result PDF.
3. Настроить ежедневный retention cleanup через панель Beget.
4. Подготовить короткий бесплатный пилот только после приёмки staging.
5. Отдельно запланировать будущие ссылки в results/PDF из [issue #9](https://github.com/dmitryturin-art/psytest-platform/issues/9).

## Решения владельца, нужные сейчас

После выкладки 04.0F нужна повторная проверка владельцем полного PDF парного результата Лазаруса. Scoring, клинический текст и SMIL-график не менялись.

## Checkpoint

[CHECKPOINT.md](CHECKPOINT.md) содержит только протокол команды «сделай checkpoint»; актуальное состояние находится выше в этом файле.
