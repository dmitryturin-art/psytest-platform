> Порядок исполнения и канонические ID пакетов — [сводный план S/K/O](2026-09-08-delivery-review.md). Ниже инвентарь; локальные 07-A—E не являются отдельной очередью.

# Аудит кабинетов и приглашений — 2026-09-08

## Объём и состояние

Проверены маршруты, контроллеры, session/lifecycle-сервисы, схема, шаблоны и тесты
текущей ветки `codex/07-cabinets-audit`. Это read-only аудит: production, данные и
секреты не использовались.

### Что уже работает

- Мини-кабинет владельца защищён Argon2id-паролем, PHP-session, CSRF, HTTPS в
  production и `Cache-Control: no-store, private`:
  `public/index.php:76-82`, `controllers/OwnerController.php:31-45,152-180`.
- Владелец может через POST найти существующую сессию по result token, явно
  перевести завершённую anonymous-сессию в `therapist_case` или полностью её
  удалить: `controllers/OwnerController.php:100-149`,
  `core/TherapistCaseService.php:23-87`.
- Разделение сроков хранения уже корректно выделено: новая публичная сессия
  anonymous, а therapist case не попадает в 180-дневную очистку. Удаление case
  удаляет session и известные PDF-артефакты:
  `core/RetentionPolicy.php:10-38`, `core/SessionLifecycleService.php:28-72`.
  Это подтверждает `tests/Integration/TherapistCaseServiceTest.php:46-77`.
- Result token остаётся bearer credential только своей сессии и проверяется
  вместе с test slug: `core/SessionManager.php:83-108`,
  `controllers/ResultController.php:41-78`. Страница результата даёт самому
  посетителю базовый результат, PDF, копирование ссылки и удаление:
  `templates/result-layout.twig:21-26`.

### Чего нет для сценария специалиста

Сценарий «создать в кабинете ссылку на Лазаруса, послать клиенту, потом увидеть
его ответы и результат» пока не реализован.

- Нет сущности, миграции, сервиса, маршрута или UI для приглашения. Обычный
  `/test/{slug}` сразу создаёт anonymous session
  (`controllers/TestController.php:25-44`, `core/SessionManager.php:42-80`).
- Нет автоматической связи invitation → session → therapist case, нет статусов
  создано / открыто / пройдено, отзыва, истечения или списка клиентов.
- В dashboard нет списка кейсов, перехода к результату, просмотра ответов или
  результатов; его единственная форма принимает уже известный result token
  (`templates/owner-dashboard.twig:25-59`).
- `partner_token` нельзя переиспользовать: он служит только парному сценарию
  Lazarus и явно не является credential результата
  (`core/SessionManager.php:83-89`, `controllers/TestController.php:237-248`).
- Проектный документ уже точно описывает целевой поток и помечает его
  нереализованным: `docs/roadmap/INVITE_FLOW.md:3,20-26,39-70,78-86`.

### Чего нет для посетителя

Личного кабинета посетителя сейчас нет: отсутствуют identity/account/magic-link
таблицы, маршруты, сервисы и шаблоны истории. Поля `user_email` и `user_name` в
старой session-схеме не являются аккаунтом и не заполняются обычным flow
(`database/schema.sql:28-53`, `core/SessionManager.php:39-66,207-217`).

Это нельзя заменить автоматическим поиском по email, cookie или IP.
`PRODUCT_RULES.md` требует явного присоединения результата, скрытия bearer token
из кабинета и предварительного решения про identity, recovery, deletion,
retention, consent и account takeover (`docs/roadmap/PRODUCT_RULES.md:150-156`).

## Границы доступа

- Владелец видит ответы и базовый результат только у case, который был создан
  собственным приглашением либо явно назначен им; ответ/результат не должны
  раскрываться через guessable ID или URL списка. Для кабинета рекомендуется
  отдельный owner-only read route по case ID: он избавляет рабочий экран от
  необходимости раскрывать публичный bearer token.
- Респондент по приглашению получает свой бесплатный базовый результат сразу;
  подпись приглашения не показывается ему и не передаётся AI
  (`docs/roadmap/INVITE_FLOW.md:25-26,39-47`).
- Клиент терапевта не получает профессиональный или понятный AI draft до
  отдельного явного действия владельца. Профессиональная и клиентская версии,
  как и их revisions, хранятся раздельно. Это обязательное продуктовое правило:
  `docs/roadmap/phases/07-ai-reports-therapist-office.md:19-22,211-215` и
  `docs/roadmap/PRODUCT_RULES.md:55-58`.
- Контакты клиента не хранятся: владелец отправляет приглашение своими средствами
  через copy/mailto/мессенджер composition, а не через рассылку платформы
  (`docs/roadmap/INVITE_FLOW.md:28-37`).

## Рекомендуемые изолированные пакеты

### 07-A — домен приглашений и связь с сессией

Добавить отдельные `therapist_invites` и связь с `test_sessions`; не добавлять
клиентскую идентичность в session и не перегружать `retention_class`.
Приглашение хранит методику, owner label, состояние, срок, отзыв и только хеш
случайного токена. Сессия, начатая по валидному приглашению, связывается с ним в
одной транзакции и получает therapist lifecycle по явному правилу пакета.

Приёмка:

- приглашение открывает только объявленную методику;
- revoked, expired, completed и подменённый token не создают session;
- конкурентные переходы создают не более одной session на приглашение;
- label не попадает в result, URL, activity log, AI context или output;
- каскадное удаление case убирает invitation и label согласно выбранной policy.

До реализации нужно решение владельца о сроке жизни ссылки: это прямо оставлено
открытым в `docs/roadmap/INVITE_FLOW.md:69-70`.

### 07-B — рабочий экран приглашений владельца

В кабинете добавить создание, список, состояние, отзыв и безопасную навигацию к
уже завершённому case. Добавить copy, mailto, Telegram и Max только как
клиентские composition actions, без записи контактов и без серверной отправки.

Приёмка:

- каждый mutation защищён owner auth и CSRF;
- label не виден респонденту, в URL и в публичной странице;
- список показывает created/opened/completed/revoked/expired;
- completed case открывается владельцу по owner-only route и case ID, без
  необходимости рендерить bearer token в dashboard;
- отзыв немедленно запрещает старт, но не удаляет уже завершённый case.

### 07-C — сквозной Lazarus invite flow

Провести приглашённого респондента через индивидуальный Lazarus: ссылка →
старт → сохранение → завершение → состояние в кабинете → owner read view
ответов и базового результата. Существующий парный flow остаётся отдельным.

Приёмка:

- E2E-тест доказывает связку invite/session/case и видимость ответов только
  владельцу;
- приглашённый видит свой базовый результат без регистрации;
- обычный public Lazarus и существующие pair regression/golden tests не меняют
  поведение;
- другой owner/session/token не открывает case, ответы или результат.

### 07-D — privacy/security дизайн и кабинет посетителя

Сначала утвердить модель identity, затем реализовать magic-link вход и историю
в отдельных таблицах. Привязка имеющегося результата должна быть отдельным
явным действием пользователя, а не эвристикой по email/cookie/IP.

Приёмка:

- нет email enumeration; magic link одноразовый, ограниченный по времени,
  вызывает rotation browser session;
- history не выводит result token и не становится alternate credential;
- attach требует явного подтверждения; чужой result не добавляется;
- пользователь может отвязать/удалить данные; 180-day retention anonymous
  данных сохраняется и проверяется интеграционным тестом.

До реализации нужны решения владельца об email provider, recovery и lifecycle
после удаления/отвязки аккаунта — это обязательные предпосылки правила §12.

### 07-E — отчёты, редактор и controlled delivery

После 07-A—C добавить к case отдельные drafts/revisions профессиональной и
клиентской редакций, owner editor и explicit approval/send. Owner context хранить
отдельно от invitation label и передавать внешнему AI только после утверждённого
consent flow.

Приёмка:

- draft и professional version недоступны клиенту по result URL, PDF и любому
  history route;
- выдаётся только явно утверждённая client revision;
- owner edit создаёт revision, не переписывая исходник;
- access tests покрывают client, owner, expired link и удалённый case;
- Markdown/HTML/PDF проходят allowlist rendering.

## Дополнительное наблюдение

`ARCHITECTURE.md` отстаёт от кода: на `ARCHITECTURE.md:3,7,140-144` новый AI
контур назван нереализованным, хотя queue и result routes уже существуют
(`public/index.php:70-71`, `controllers/ResultController.php:165-227`).
Сводные утверждения исправлены в пакете 07.18; сокращённый пример интерфейса явно помечен.
