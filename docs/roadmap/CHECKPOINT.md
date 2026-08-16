# Текущий checkpoint

Обновлён: 2026-08-16

## Где мы

- Проект: PsyTest Platform.
- Активный этап: 02 — клиническая безопасность, privacy и бесплатный пилот.
- Последний опубликованный code package: `a14f5eb` в `main`; GitHub Actions [31949538307](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31949538307) — **success** на PHP 8.3/MySQL. 02.4A privacy truthfulness опубликован и подтверждён.
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
- SEC-05 удалил публичные dev-harness, оставил только `public/index.php` как PHP front controller, ввёл response hardening и regression-тесты. Локальная browser/HTTP QA подтвердила 404 у прежних diagnostic routes.
- PAIR-02 подтверждает, что submitted session второго партнёра связана именно с source invite token до записи ответов и расчёта.
- PAIR-03 подтверждает expiry boundaries; PAIR-04 переводит конкурентный duplicate invite в `409`, а DB-логи больше не включают driver messages с bound values.
- Составлен factual data map текущего кода и приняты owner-решения: `anonymous` clinical-данные — 180 дней, явный `therapist_case` — бессрочно с ручным удалением; AI-передача требует отдельного consent только при заказе расширенного разбора.
- 02.1 опубликован в `main`: `87925ba` реализует lifecycle, а `6152177` исправляет clean migration chain. GitHub Actions [31947662859](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31947662859) — success на PHP 8.3/MySQL.
- 02.2A в `codex/02-bdi-safety-signal`: validated BDI item 9 с оценкой 1–3 создаёт структурированный safety signal, независимый от total; локальный gate зелёный, UI/текст/ресурсы не начаты.
- 02.2A опубликован в `main` как `16c4730`; GitHub Actions [31948009328](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31948009328) — success. UI, клинический текст, country/resource registry не начаты и требуют решения владельца.
- 02.3A `5942587` добавляет pure `CountryResolver`: ручной выбор страны имеет приоритет над session choice, затем над подготовленной trusted подсказкой; некорректные значения приводят к `unknown`. Он не читает IP/HTTP-заголовки, не делает GeoIP-запросов и не сохраняет IP. Локальный full gate: 130 tests/1232 assertions, PHPStan, lint, architecture и baseline — pass; GitHub Actions `31948360267` — success на PHP 8.3/MySQL.
- 02.3B `50794fa` создаёт пустой fail-closed registry: контакт/URL, официальный источник, дата/автор проверки и `active = 0`. Реестр не связан с результатами или IP, не содержит seed-контактов и ещё не имеет reader/UI или срока актуальности. Локальная migration и full gate прошли (131 tests/1248 assertions); GitHub Actions `31948774257` — success на PHP 8.3/MySQL.
- 02.4A `a14f5eb` приводит public privacy/delete copy и актуальные docs к реальному поведению: нет claims о шифровании, отсутствии будущих получателей или мгновенном физическом удалении. Есть source-level regression test, browser QA `/privacy` на desktop/390×844, полный local gate (134 tests/1264 assertions) и GitHub CI [31949538307](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31949538307) — success.

## Ближайшие действия

1. Получить owner-approved Crisis UI text/resources и freshness threshold для 02.2B/02.3C.
2. После утверждения текста и регионального strategy реализовать deterministic item-9 safety flow и public reader.
3. Добавить browser cases и закрытый бесплатный pilot только после проверенного end-to-end flow.
4. Не менять SMIL/Lazarus scoring без отдельного clinical-risk work package.

## Известные блокеры и риски

- Новый платный flow пока не существует: legacy endpoints намеренно retired до проектирования YooKassa/AI orders.
- `therapist_case`, production scheduler для 180-day cleanup, protected delete UX и AI-consent пока не реализованы; public copy теперь явно отделяет current state от будущего flow.
- BDI item 9 имеет server-side signal, но ещё не имеет самостоятельного пользовательского safety-flow, утверждённого текста или resource registry.
- Полная документация ещё содержит legacy-слои; 02.4A исправляет privacy/routes точечно, а DOC-01 остаётся в работе.
- Дополнительные шкалы SMIL требуют отдельной верификации; базовые 13 заморожены.
- Два старых PDF остаются только в истории; владелец подтвердил их обезличенность и отказался от history rewrite. Новые generated PDF игнорируются и не попадают в Git.

## Что спросить у владельца сейчас

Перед публикацией BDI safety-flow понадобится утверждение кризисного текста и начальных ресурсов; визуальное интервью — в этапе 04.

## Возобновление

- 2026-08-16: владелец принял D-024/D-025: 180-day anonymous retention, бессрочный явный therapist-case с ручным удалением и отдельный AI-consent в checkout. Добавлена целевая [RETENTION_POLICY.md](RETENTION_POLICY.md); следующий шаг — 02.1 lifecycle/classification design.

## Протокол команды «сделай checkpoint»

1. Обновить этот файл фактическим состоянием, branch и dirty files.
2. Обновить `STATUS.md` и добавить запись в `WORKLOG.md`.
3. Зафиксировать новые решения в `DECISIONS.md`, уроки — в `LESSONS.md`.
4. Запустить доступные узкие проверки или явно написать, что не запускалось.
5. Сохранить краткое решение/следующий шаг в agentmemory без секретов и персональных данных.
6. Коммитить только целостный проверенный пакет; иначе записать точный незакоммиченный diff и продолжить с него позже.

## Протокол видимости для владельца

После checkpoint агент обязан сообщить: что готово, активный этап/work package, branch/commit, результат проверок, следующий номерованный шаг и одно из состояний `продолжаю` / `остановлено`. О субагентах сообщается только по прямому вопросу владельца или при реальном блокере. Команда «работай по плану» разрешает продолжать без подтверждения между безопасными work packages.
