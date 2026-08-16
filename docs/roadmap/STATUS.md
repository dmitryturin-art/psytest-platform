# Текущий статус программы

Обновлён: 2026-08-16. Это оперативная панель; краткое состояние для паузы находится в [CHECKPOINT.md](CHECKPOINT.md).

## Сейчас

- Активный этап: [01 — containment и безопасность](phases/01-containment-security.md).
- Состояние: работа поставлена на паузу после checkpoint. Следующая package-ветка: `01.5C migration repair`.
- Последний опубликованный commit: `e8f1f53` в `main`; выпускной GitHub Actions [31933926559](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31933926559) завершился ошибкой миграции и не даёт разрешения на деплой.
- Baseline commit: `6c51cc3` (`main` на начало аудита).
- Состояние продукта: quality gates, dependency safety, legacy payment containment, CSRF и границы result-token улучшены; публичная продажа пока не готова к запуску.
- Последние завершённые work packages: CI для PHP 8.3 (`0c91adf`), Linux-совместимый autoload Лазаруса (`b82347e`), CSRF enforcement (`e42eb89`) и прозрачный протокол статусов (`ed3d896`).
- 01.4 принят в `main`: lookup результата разделён с pair-reference; GitHub Actions [31904747962](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31904747962) — success. 01.5A route/session integrity также подтверждён CI. 01.5B validation и PAIR-01 опубликованы, но release gate красный: `uq_partner_token` создан и в initial schema, и в отдельной миграции.

## Готовность этапов

| Этап | Состояние | Прогресс/условие перехода |
|---|---|---|
| 00 | В работе | governance-каркас и базовый quality gate готовы; требуется отдельная документационная hygiene-проверка |
| 01 | Приостановлен | 01.1–01.5A подтверждены CI; 01.5B/PAIR-01 требуют migration repair и нового зелёного CI |
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

1. CI не проходит чистую миграцию: `uq_partner_token` повторно добавляется в `20260816000000_add_pair_invite_uniqueness.php`.
2. Legacy payment endpoints безопасно retired, но новый YooKassa/AI flow ещё не спроектирован и не реализован.
3. BDI item 9 не создаёт независимый кризисный signal.
4. Документация местами ещё обещает свойства, которых фактический код не обеспечивает.
5. Дополнительные шкалы SMIL: заявлено 200, фактически найдено 23; часть норм противоречива.
6. Два PDF результатов присутствуют в старой Git history; в актуальной ветке они не отслеживаются. Владелец подтвердил, что они обезличены, и решил не переписывать историю.

Полный список и владельцы закрытия: [AUDIT_TRACEABILITY.md](AUDIT_TRACEABILITY.md).

## Следующие пять действий

1. 01.5C: исправить дублирование pair-invite migration и добавить regression на чистую миграцию.
2. Подтвердить 01.5B/PAIR-01 полным зелёным GitHub Actions.
3. Закрывать оставшиеся security findings отдельными regression-тестами до UI-редизайна.
4. Не начинать UI-редизайн до закрытия P0 security/payment containment.
5. После выхода из этапа 01 перейти к privacy и кризисному BDI flow этапа 02.

## Решения владельца, нужные сейчас

Нет. Следующий обязательный вопрос относится к хранению данных и кризисному сообщению на этапе 02. Если в ходе этапа 01 обнаружится изменение клинических расчётов, работа останавливается до согласования.

## Последняя контрольная точка

[CHECKPOINT.md](CHECKPOINT.md) — точное состояние паузы: опубликованный код, красный CI и первый шаг возобновления.
