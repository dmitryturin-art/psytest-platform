# Текущий статус программы

Обновлён: 2026-08-15. Это оперативная панель; краткое состояние для паузы находится в [CHECKPOINT.md](CHECKPOINT.md).

## Сейчас

- Активный этап: [01 — containment и безопасность](phases/01-containment-security.md).
- Активная package-ветка: checkpoint-пакет `codex/00-checkpoint-pause`; разработка поставлена на паузу по запросу владельца.
- Последняя принятая security-ветка: `codex/01-token-boundaries`; опубликована в GitHub через `main` до `0d6a947`.
- Baseline commit: `6c51cc3` (`main` на начало аудита).
- Состояние продукта: quality gates, dependency safety, legacy payment containment, CSRF и границы result-token улучшены; публичная продажа пока не готова к запуску.
- Последние завершённые work packages: CI для PHP 8.3 (`0c91adf`), Linux-совместимый autoload Лазаруса (`b82347e`), CSRF enforcement (`e42eb89`) и прозрачный протокол статусов (`ed3d896`).
- 01.4 принят в `main`: lookup результата разделён с pair-reference; GitHub Actions [31904747962](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31904747962) ещё выполняется.

## Готовность этапов

| Этап | Состояние | Прогресс/условие перехода |
|---|---|---|
| 00 | В работе | governance-каркас и базовый quality gate готовы; требуется отдельная документационная hygiene-проверка |
| 01 | Пауза | 01.1–01.4 завершены локально и интегрированы; для 01.4 ожидается GitHub CI |
| 02–09 | Не начаты | открываются по exit criteria предыдущих этапов; исследования допустимы раньше без release |

## Baseline, обнаруженный аудитом

| Проверка | Результат на 2026-08-15 | Интерпретация |
|---|---|---|
| PHPUnit | 100 tests, 1146 assertions — pass | Полезная база; security packages добавляют целевые регрессии, browser flow ещё не покрыт полностью |
| Composer validate | pass | `composer.json` синтаксически корректен |
| PHP syntax/style | pass | Не доказывает корректность поведения |
| PHPStan | pass, baseline 148 | Новые ошибки запрещены; baseline нужно постепенно уменьшать |
| Architecture check | pass: 5 модулей, шаблоны и статика | project root исправлен и покрыт узким regression-тестом |
| PHPStan baseline guard | pass: ровно 148 entries | `composer baseline:check` запрещает незаметный рост baseline |
| Dependency audit | pass: `dompdf` 3.1.6, audit clean | `DEP-01` закрыт в `7272e51`; lock воспроизводим для PHP 8.3 |
| Browser smoke | пройден частично | Найдены blank sticky nav, progress 20/21, accessibility и responsive-дефекты |

Свежие команды и точный вывод добавляются в [WORKLOG.md](WORKLOG.md); эта таблица не заменяет повторный baseline run.

## Активные риски

1. Сервер не гарантирует модульную валидацию ответов и согласованность slug.
2. Legacy payment endpoints безопасно retired, но новый YooKassa/AI flow ещё не спроектирован и не реализован.
3. BDI item 9 не создаёт независимый кризисный signal.
4. Документация местами ещё обещает свойства, которых фактический код не обеспечивает.
5. Дополнительные шкалы SMIL: заявлено 200, фактически найдено 23; часть норм противоречива.
6. Два PDF результатов присутствуют в старой Git history; в актуальной ветке они не отслеживаются. Владелец подтвердил, что они обезличены, и решил не переписывать историю.

Полный список и владельцы закрытия: [AUDIT_TRACEABILITY.md](AUDIT_TRACEABILITY.md).

## Следующие пять действий

1. Подтвердить GitHub Actions для 01.4 token boundaries.
2. 01.5: проверить slug/session integrity и обязательную серверную validation.
3. Закрывать оставшиеся security findings отдельными regression-тестами до UI-редизайна.
4. Не начинать UI-редизайн до закрытия P0 security/payment containment.
5. После выхода из этапа 01 перейти к privacy и кризисному BDI flow этапа 02.

## Решения владельца, нужные сейчас

Нет. Следующий обязательный вопрос относится к хранению данных и кризисному сообщению на этапе 02. Если в ходе этапа 01 обнаружится изменение клинических расчётов, работа останавливается до согласования.

## Последняя контрольная точка

[CHECKPOINT.md](CHECKPOINT.md) — состояние во время локально проверенного 01.4, перед commit/CI.
