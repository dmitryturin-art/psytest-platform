# Текущий статус программы

Обновлён: 2026-08-22. Это оперативная панель; краткое состояние для паузы находится в [CHECKPOINT.md](CHECKPOINT.md).

## Сейчас

- Активные этапы: [02 — клиническая безопасность и бесплатный пилот](phases/02-clinical-privacy-pilot.md) + [08 — staging на проверке владельца](phases/08-production-deployment.md).
- Состояние: `test.23time.ru` активирован на release `2f8f821`: HTTPS redirect, PHP 8.3, MySQL 5.7, 7 migrations. Desktop/mobile и synthetic BDI 21/21 smoke прошли; rollback сохранён.
- Последний пакет: 08.1E `2f8f821` + PR #3 — HTTPS staging activation и реальный BDI smoke; [PHP 8.3 / MySQL 5.7 и 8.0 CI](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32585270174) — success. Пакет 02.8B BDI notice остаётся последним clinical package.
- Baseline commit: `6c51cc3` (`main` на начало аудита).
- Состояние продукта: quality gates, dependency safety, legacy payment containment, CSRF и границы result-token улучшены; публичная продажа пока не готова к запуску.
- Последние завершённые work packages: CI для PHP 8.3 (`0c91adf`), Linux-совместимый autoload Лазаруса (`b82347e`), CSRF enforcement (`e42eb89`) и прозрачный протокол статусов (`ed3d896`).
- 01.4 принят в `main`: lookup результата разделён с pair-reference; GitHub Actions [31904747962](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31904747962) — success. 01.5A route/session integrity и 01.5B server validation подтверждены CI. 01.5C устранил конфликт `uq_partner_token`; release gate снова зелёный.

## Готовность этапов

| Этап | Состояние | Прогресс/условие перехода |
|---|---|---|
| 00 | В работе | governance-каркас и baseline готовы; 00C current-state docs и documentation contract test опубликованы как `d4a4e23`; production runbook относится к 08 |
| 01 | Завершён | containment/security boundaries, validation, web-root hygiene и PAIR-01 подтверждены CI |
| 02 | В работе | lifecycle, BDI safety, factual privacy copy, реестр методик и owner dashboard реализованы; IP/User-Agent не собираются, серверный AI consent record и runbook закрытого пилота готовы. Нужны staging/pilot evidence, automated BDI browser coverage и legal review |
| 03–07, 09 | Не начаты | открываются по exit criteria предыдущих этапов; исследования допустимы раньше без release |
| 08 | На проверке владельца (staging) | публичный HTTPS staging работает; payment/AI/admin выключены, rollback готов; production не начат |

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

1. Владелец проверяет внешний вид и основные тесты на [staging](https://test.23time.ru/tests).
2. Сменить SSH/DB credentials после deployment-сессии; новые секреты не передавать в чат.
3. Настроить ежедневный retention cleanup через панель Beget.
4. Провести короткий пилот по `PILOT_RUNBOOK.md`, учитывая D-029 об открытом доступе.
5. По итогам пилота выбрать следующий пакет: UI/UX polish либо оставшиеся P0/P1 перед production.

## Решения владельца, нужные сейчас

Нужна визуальная и продуктовая приёмка владельца staging. Scoring и клинический текст в deployment не менялись.

## Последняя контрольная точка

[CHECKPOINT.md](CHECKPOINT.md) — состояние после закрытия этапа 01 и вопросы для этапа 02.
