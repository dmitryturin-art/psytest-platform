# Текущий checkpoint

Обновлён: 2026-08-16

## Где мы

- Проект: PsyTest Platform.
- Активный этап: 01 — containment и безопасность.
- Последний опубликованный commit: `e8f1f53` в `main`. Его выпускной GitHub Actions [31933926559](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31933926559) **failed** на миграции; это не состояние, пригодное для деплоя.
- 01.5A (`92bf5e6`) связывает route slug с test session и подтверждён GitHub CI. 01.5B (`2cc5321`, форматирование `e8f1f53`) добавляет серверную validation ответов; PAIR-01 (`46dade6`) запрещает повторное создание приглашения пары. Формулы тестов и корректный пользовательский flow не менялись.
- Канонические источники в порядке приоритета: последнее решение владельца → `PRODUCT_RULES.md`/`DECISIONS.md` → active phase → technical audit → фактическая архитектура. Полная иерархия — в `ROADMAP.md`.

## Что уже сделано

- Проведён полный аудит проекта.
- Зафиксированы решения владельца о бесплатных результатах, платных разборах, YooKassa, купонах, двух типах отчёта, кабинете терапевта, SMIL и размещении на 23time.ru.
- Завершено создание канонической системы управления разработкой в отдельной ветке.
- Superpowers больше не является обязательной методологией.
- Созданы и проверены все этапы 00–09, audit traceability, decision/status/worklog/checkpoint/lessons и честный GitHub README.
- Коммиты: `0943c80` — repository hygiene; `0866ae0` — audited delivery program.

## Что сейчас в работе

- Governance package локально завершён и проверен тремя независимыми reviews.
- Governance publication завершена.
- Воспроизводимый baseline завершён: architecture checker починен, PHPStan baseline capped at 148, evidence — в `WORKLOG.md`.
- Dependency safety завершён: Dompdf 3.1.6, composer audit clean, composer.lock теперь отслеживается и разрешается для PHP 8.3.
- Legacy payment CTA/endpoints safely retired; CSRF middleware введён для browser mutations.
- 01.4 разделяет result-access token и pair-reference: lookup результата использует только `session_token`; локальные и GitHub CI проверки зелёные.
- 01.5A связывает route slug с `test_id` session для result/PDF/status/autosave/submit/pair flow; GitHub CI — success.
- 01.5B валидирует тип, допустимые значения и полноту ответов до autosave/submit для SMIL, BDI, BAI, HADS и Лазаруса. PAIR-01 вводит single-use приглашения. Локальный full gate перед публикацией — 107 tests/1163 assertions, PHPStan и lint — pass.
- Внешний gate не прошёл: initial schema уже содержит `uq_partner_token`, а миграция `20260816000000_add_pair_invite_uniqueness.php` пытается создать такой же index повторно. Поэтому продолжение разработки и деплой поставлены на паузу до маленького migration-fix package.

## Ближайшие действия

1. **01.5C — migration repair:** убрать дублирование `uq_partner_token` между initial schema и инкрементальной миграцией, добавить regression на чистую миграцию, прогнать локальный и GitHub quality gate.
2. После зелёного CI синхронизировать evidence для SEC-04 и PAIR-01.
3. Закрыть SEC-05 web-root hygiene до выхода из этапа 01.
4. Затем перейти к privacy/crisis BDI flow этапа 02.
5. Не менять SMIL/Lazarus scoring без отдельного clinical-risk work package.

## Известные блокеры и риски

- Новый платный flow пока не существует: legacy endpoints намеренно retired до проектирования YooKassa/AI orders.
- Server-side validation и PAIR-01 локально реализованы, но пока не закрыты: CI остановился до тестов на ошибке миграции `uq_partner_token`.
- BDI item 9 не имеет самостоятельного safety-flow.
- Документация расходится с кодом.
- Дополнительные шкалы SMIL требуют отдельной верификации; базовые 13 заморожены.
- Два старых PDF остаются только в истории; владелец подтвердил их обезличенность и отказался от history rewrite. Новые generated PDF игнорируются и не попадают в Git.

## Что спросить у владельца сейчас

Ничего: этап 00 можно завершить без новых продуктовых решений. Следующий обязательный пакет вопросов предусмотрен в этапе 02; визуальное интервью — в этапе 04.

## Возобновление

- 2026-08-16: создана чистая документальная контрольная точка в `codex/checkpoint-pair-migration-20260816`; product-code diff отсутствует. После её публикации работа **остановлена по запросу владельца**. Возобновлять с шага 01.5C, а не с новых product-функций.

## Протокол команды «сделай checkpoint»

1. Обновить этот файл фактическим состоянием, branch и dirty files.
2. Обновить `STATUS.md` и добавить запись в `WORKLOG.md`.
3. Зафиксировать новые решения в `DECISIONS.md`, уроки — в `LESSONS.md`.
4. Запустить доступные узкие проверки или явно написать, что не запускалось.
5. Сохранить краткое решение/следующий шаг в agentmemory без секретов и персональных данных.
6. Коммитить только целостный проверенный пакет; иначе записать точный незакоммиченный diff и продолжить с него позже.

## Протокол видимости для владельца

После checkpoint агент обязан сообщить: что готово, активный этап/work package, branch/commit, результат проверок, следующий номерованный шаг и одно из состояний `продолжаю` / `остановлено`. О субагентах сообщается только по прямому вопросу владельца или при реальном блокере. Команда «работай по плану» разрешает продолжать без подтверждения между безопасными work packages.
