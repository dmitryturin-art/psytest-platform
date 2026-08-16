# Текущий статус программы

Обновлён: 2026-08-16. Это оперативная панель; краткое состояние для паузы находится в [CHECKPOINT.md](CHECKPOINT.md).

## Сейчас

- Активный этап: [01 — containment и безопасность](phases/01-containment-security.md).
- Состояние: этап 01 продолжается. Следующая package-ветка: `PAIR-02 bind pair submit`.
- Последний опубликованный commit: `21c77c7` в `main`; GitHub Actions [31940056207](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31940056207) — success, включая SEC-05 web-root regression.
- Baseline commit: `6c51cc3` (`main` на начало аудита).
- Состояние продукта: quality gates, dependency safety, legacy payment containment, CSRF и границы result-token улучшены; публичная продажа пока не готова к запуску.
- Последние завершённые work packages: CI для PHP 8.3 (`0c91adf`), Linux-совместимый autoload Лазаруса (`b82347e`), CSRF enforcement (`e42eb89`) и прозрачный протокол статусов (`ed3d896`).
- 01.4 принят в `main`: lookup результата разделён с pair-reference; GitHub Actions [31904747962](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31904747962) — success. 01.5A route/session integrity и 01.5B server validation подтверждены CI. 01.5C устранил конфликт `uq_partner_token`; release gate снова зелёный.

## Готовность этапов

| Этап | Состояние | Прогресс/условие перехода |
|---|---|---|
| 00 | В работе | governance-каркас и базовый quality gate готовы; требуется отдельная документационная hygiene-проверка |
| 01 | В работе | 01.1–01.5C, SEC-04 и SEC-05 подтверждены CI; следующий пакет — P1 PAIR-02 |
| 02–09 | Не начаты | открываются по exit criteria предыдущих этапов; исследования допустимы раньше без release |

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
| Browser smoke | пройден частично | Найдены blank sticky nav, progress 20/21, accessibility и responsive-дефекты |

Свежие команды и точный вывод добавляются в [WORKLOG.md](WORKLOG.md); эта таблица не заменяет повторный baseline run.

## Активные риски

1. `pairSubmit` ещё не подтверждает, что session второго партнёра создана именно для переданного invite token.
2. Legacy payment endpoints безопасно retired, но новый YooKassa/AI flow ещё не спроектирован и не реализован.
3. BDI item 9 не создаёт независимый кризисный signal.
4. Документация местами ещё обещает свойства, которых фактический код не обеспечивает.
5. Дополнительные шкалы SMIL: заявлено 200, фактически найдено 23; часть норм противоречива.
6. Два PDF результатов присутствуют в старой Git history; в актуальной ветке они не отслеживаются. Владелец подтвердил, что они обезличены, и решил не переписывать историю.

Полный список и владельцы закрытия: [AUDIT_TRACEABILITY.md](AUDIT_TRACEABILITY.md).

## Следующие пять действий

1. PAIR-02: bind `session_id` второго партнёра к source invite token до сохранения/расчёта.
2. Закрыть P1 expiry/ownership boundaries для парного приглашения отдельными regression-тестами.
3. Закрывать оставшиеся security findings отдельными regression-тестами до UI-редизайна.
4. Не начинать UI-редизайн до закрытия P0 security/payment containment.
5. После выхода из этапа 01 перейти к privacy и кризисному BDI flow этапа 02.

## Решения владельца, нужные сейчас

Нет. Следующий обязательный вопрос относится к хранению данных и кризисному сообщению на этапе 02. Если в ходе этапа 01 обнаружится изменение клинических расчётов, работа останавливается до согласования.

## Последняя контрольная точка

[CHECKPOINT.md](CHECKPOINT.md) — текущее состояние после закрытия SEC-05 и следующий pair-boundary package.
