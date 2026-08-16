# Текущий checkpoint

Обновлён: 2026-08-16

## Где мы

- Проект: PsyTest Platform.
- Активный этап: 01 — containment и безопасность.
- Последний опубликованный package: `52883c9` в `main`, GitHub Actions [31939695568](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31939695568) — **success**. Цепочка миграций разворачивается на чистой MySQL-базе.
- 01.5A (`92bf5e6`) связывает route slug с test session; 01.5B (`2cc5321`, форматирование `e8f1f53`) добавляет серверную validation ответов; 01.5C (`52883c9`) устраняет дублирующий index в migration chain. Формулы тестов и корректный пользовательский flow не менялись.
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
- 01.5B валидирует тип, допустимые значения и полноту ответов до autosave/submit для SMIL, BDI, BAI, HADS и Лазаруса; SEC-04 закрыт evidence из зелёного CI.
- PAIR-01 уже запрещает повторное создание приглашения; его остальные access-boundary checks остаются отдельным P1-пакетом.
- 01.5C оставляет `uq_partner_token` в инкрементальной миграции для существующих баз, но не дублирует DDL в bootstrap schema. Contract-test и GitHub CI подтверждают чистый deployment path.

## Ближайшие действия

1. **SEC-05 — web-root hygiene:** убрать production-доступ к `public/demo.php` и `public/test-smil.php`, добавить HTTP regression и проверить security headers/stack traces.
2. Закрыть оставшиеся PAIR-01 access-boundary checks отдельными regression-тестами.
3. Затем перейти к privacy/crisis BDI flow этапа 02.
4. Не менять SMIL/Lazarus scoring без отдельного clinical-risk work package.

## Известные блокеры и риски

- Новый платный flow пока не существует: legacy endpoints намеренно retired до проектирования YooKassa/AI orders.
- PAIR-01 частично закрыт: одноразовость доказана, но ownership/cross-session/expiry boundaries ещё требуют отдельного P1 evidence.
- BDI item 9 не имеет самостоятельного safety-flow.
- Документация расходится с кодом.
- Дополнительные шкалы SMIL требуют отдельной верификации; базовые 13 заморожены.
- Два старых PDF остаются только в истории; владелец подтвердил их обезличенность и отказался от history rewrite. Новые generated PDF игнорируются и не попадают в Git.

## Что спросить у владельца сейчас

Ничего: этап 00 можно завершить без новых продуктовых решений. Следующий обязательный пакет вопросов предусмотрен в этапе 02; визуальное интервью — в этапе 04.

## Возобновление

- 2026-08-16: 01.5C завершён в `52883c9`: локально 108 tests/1169 assertions, PHPStan/lint/architecture pass; GitHub CI `31939695568` — success. Активный следующий шаг — SEC-05.

## Протокол команды «сделай checkpoint»

1. Обновить этот файл фактическим состоянием, branch и dirty files.
2. Обновить `STATUS.md` и добавить запись в `WORKLOG.md`.
3. Зафиксировать новые решения в `DECISIONS.md`, уроки — в `LESSONS.md`.
4. Запустить доступные узкие проверки или явно написать, что не запускалось.
5. Сохранить краткое решение/следующий шаг в agentmemory без секретов и персональных данных.
6. Коммитить только целостный проверенный пакет; иначе записать точный незакоммиченный diff и продолжить с него позже.

## Протокол видимости для владельца

После checkpoint агент обязан сообщить: что готово, активный этап/work package, branch/commit, результат проверок, следующий номерованный шаг и одно из состояний `продолжаю` / `остановлено`. О субагентах сообщается только по прямому вопросу владельца или при реальном блокере. Команда «работай по плану» разрешает продолжать без подтверждения между безопасными work packages.
