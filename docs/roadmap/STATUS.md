# Текущий статус программы

Обновлён: 2026-08-22. Это оперативная панель; краткое состояние для паузы находится в [CHECKPOINT.md](CHECKPOINT.md).

## Сейчас

- Активные этапы: [02 — клиническая безопасность и бесплатный пилот](phases/02-clinical-privacy-pilot.md) + [08 — подготовка закрытого staging](phases/08-production-deployment.md).
- Состояние: 08.1A подтвердил Beget/PHP 8.3/public root и пустую DB; 08.1B исправил rewrite; 08.1C подтвердил полный gate на MySQL 5.7/8.0. Приложение не выкладывалось: перед активацией нужен HTTPS.
- Последний пакет: 02.8B `3670c6b` — rendered-result regression для BDI notice; локальный полный gate — 162 tests / 1594 assertions; [PHP 8.3/MySQL CI](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32581403763) — success. 02.8A опубликован как `289d00c`.
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
| 08 | В работе (staging) | survey, public-root regression и MySQL 5.7/8.0 gate завершены; HTTPS блокирует активацию, production не начат |

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
8. `test.23time.ru` пока доступен только по HTTP; Basic Auth и работа с тестовыми данными до TLS запрещены.
9. Staging DB работает на MySQL 5.7.21. 08.1C нашёл и устранил несовместимый implicit TIMESTAMP default; чистые MySQL 5.7 и 8.0 теперь обязательны в CI.

Полный список и владельцы закрытия: [AUDIT_TRACEABILITY.md](AUDIT_TRACEABILITY.md).

## Следующие пять действий

1. 08.1D: собрать deployment artifact с `vendor/`, backup и rollback procedure.
2. Выпустить HTTPS для `test.23time.ru` в панели Beget.
3. Подготовить server `.env` и Argon2id owner hash без записи секретов в Git.
4. Активировать Basic-Auth staging и выполнить `PILOT_RUNBOOK.md` на desktop/mobile.
5. Зафиксировать staging evidence и только затем обсуждать production.

## Решения владельца, нужные сейчас

Для staging владелец выпускает Let's Encrypt для `test.23time.ru`. Остальные безопасные подготовительные пакеты агент выполняет самостоятельно; scoring и клинический текст не меняются.

## Последняя контрольная точка

[CHECKPOINT.md](CHECKPOINT.md) — состояние после закрытия этапа 01 и вопросы для этапа 02.
