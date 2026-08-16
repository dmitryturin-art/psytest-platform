# Текущий статус программы

Обновлён: 2026-08-16. Это оперативная панель; краткое состояние для паузы находится в [CHECKPOINT.md](CHECKPOINT.md).

## Сейчас

- Активный этап: [02 — клиническая безопасность, privacy и бесплатный пилот](phases/02-clinical-privacy-pilot.md).
- Состояние: этап 01 завершён; для этапа 02 приняты retention (180 дней / бессрочный therapist-режим) и отдельный AI-consent. 02.1 подтверждён CI; 02.2A добавляет server-side BDI safety signal и ожидает commit/CI.
- Последний опубликованный commit: `6152177` в `main`; GitHub Actions [31947662859](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31947662859) — success, включая чистую migration chain для retention lifecycle.
- Baseline commit: `6c51cc3` (`main` на начало аудита).
- Состояние продукта: quality gates, dependency safety, legacy payment containment, CSRF и границы result-token улучшены; публичная продажа пока не готова к запуску.
- Последние завершённые work packages: CI для PHP 8.3 (`0c91adf`), Linux-совместимый autoload Лазаруса (`b82347e`), CSRF enforcement (`e42eb89`) и прозрачный протокол статусов (`ed3d896`).
- 01.4 принят в `main`: lookup результата разделён с pair-reference; GitHub Actions [31904747962](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31904747962) — success. 01.5A route/session integrity и 01.5B server validation подтверждены CI. 01.5C устранил конфликт `uq_partner_token`; release gate снова зелёный.

## Готовность этапов

| Этап | Состояние | Прогресс/условие перехода |
|---|---|---|
| 00 | В работе | governance-каркас и базовый quality gate готовы; требуется отдельная документационная hygiene-проверка |
| 01 | Завершён | containment/security boundaries, validation, web-root hygiene и PAIR-01 подтверждены CI |
| 02 | В работе | 02.1 lifecycle подтверждён; 02.2A server-side BDI signal готов локально, Crisis UI/text/resources ещё впереди |
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

1. Legacy payment endpoints безопасно retired, но новый YooKassa/AI flow ещё не спроектирован и не реализован.
3. BDI item 9 не создаёт независимый кризисный signal.
4. Документация местами ещё обещает свойства, которых фактический код не обеспечивает.
5. Дополнительные шкалы SMIL: заявлено 200, фактически найдено 23; часть норм противоречива.
6. Два PDF результатов присутствуют в старой Git history; в актуальной ветке они не отслеживаются. Владелец подтвердил, что они обезличены, и решил не переписывать историю.

Полный список и владельцы закрытия: [AUDIT_TRACEABILITY.md](AUDIT_TRACEABILITY.md).

## Следующие пять действий

1. Завершить и опубликовать 02.2A server-side BDI signal; затем подтвердить CI.
2. Реализовать Crisis UI и country/resource strategy по утверждённому тексту.
3. Закрывать privacy findings отдельными regression-тестами до UI-редизайна.
4. Не начинать UI-редизайн до закрытия P0 security/payment containment.
5. Спроектировать consent record и provider boundary до возврата AI-функций.

## Решения владельца, нужные сейчас

Нет. Перед публикацией BDI safety-flow понадобится утверждение кризисного текста и первого набора ресурсов. Если в ходе этапа 02 обнаружится изменение клинических расчётов, работа останавливается до согласования.

## Последняя контрольная точка

[CHECKPOINT.md](CHECKPOINT.md) — состояние после закрытия этапа 01 и вопросы для этапа 02.
