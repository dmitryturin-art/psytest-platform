# Current-state data map

Статус: **фактическая инвентаризация на 2026-08-22**. Это не privacy policy и не утверждённая retention policy. Документ описывает только то, что видно в текущем коде и schema, чтобы следующий пакет не строился на ложных обещаниях.

## Область

Основные источники: `database/migrations/20260708050511_init_schema.php`, `core/SessionManager.php`, `controllers/ResultController.php`, `controllers/ApiController.php`, `core/PDFGenerator.php` и текущий публичный privacy text в `HomeController`.

## Карта данных

| Данные | Текущее место | Текущая цель/flow | Доступ/передача | Удаление и известный долг |
|---|---|---|---|---|
| Ответы, рассчитанные результаты, пол/возраст | `test_sessions.answers`, `calculated_results`, `demographics` | Бесплатный результат, PDF, сравнение пары; явно или invitation-автоматически назначенный therapist-case | Доступ по bearer result token; минимальный `/admin` owner lookup принимает token только в POST, invitation case открывается owner-only route по внутреннему session ID | Public `deleteSession()` — soft-delete и очистка clinical-полей. Плановый `SessionLifecycleService` физически удаляет 180-day anonymous session, pair-данные, известные PDF и session-bound activity records; owner может физически удалить therapist-case с подтверждением. |
| Персональное приглашение | `test_invites`: SHA-256 token hash, test scope, срок, статус, owner note, claimed session | Владелец копирует одноразовую ссылку на поддерживаемую методику; GET информирует, POST «Начать тест» создаёт связанный case | Raw token не хранится и не логируется; owner note/list/case доступны только защищённому `/admin` | Неоткрытое приглашение можно отозвать; claim запрещён после 14 дней. Удаление кейса удаляет и строку приглашения вместе с owner note; удаление карточки клиента уносит его приглашения каскадом. |
| Карточка клиента специалиста | `therapist_clients`: подпись владельца (`label`), необязательная заметка (`note`), даты; `test_invites.client_id` — необязательная связь назначения с карточкой | Владелец ведёт клиента с несколькими назначениями и историей завершённых результатов | Только защищённый `/admin`; подпись и заметка не показываются респонденту, не попадают в ссылку-приглашение, в AI-контекст, в `activity_log` и в результат | Владелец удаляет карточку целиком: сессии её назначений удаляются через `SessionLifecycleService` (ответы, результаты, PDF, `ai_reports`, session-bound activity records), приглашения — каскадом по FK. Контакты клиента (email, телефон, мессенджер) не хранятся вовсе. |
| Аккаунт посетителя | `visitor_accounts`: нормализованный email, `created_at`, `last_login_at`; `visitor_login_tokens`: SHA-256 ссылки входа, срок 15 минут, отметка использования; `test_sessions.account_id` | Добровольная история своих прохождений; email хранится открыто, потому что по нему уходит ссылка входа | Доступ по PHP-сессии посетителя, независимой от owner-сессии; страницы кабинета отдаются `Cache-Control: no-store, private` и `X-Robots-Tag: noindex`; `session_token` в HTML кабинета не выводится; email не передаётся ИИ и третьим лицам | Привязка только явным действием посетителя при владении result token (`retention_class = account`); отвязка возвращает `anonymous` без продления 180 дней от `created_at`; удаление аккаунта проводит каждую привязанную session через `SessionLifecycleService` и удаляет аккаунт с его токенами. IP и user-agent не записываются. Восстановление — только повторный вход по тому же адресу. |
| Result token / partner token | `test_sessions.session_token`, `partner_token` | Уникальная ссылка на результат / relation для пары | Token не должен попадать в logs/analytics; partner token не credential | Сессия перестаёт быть доступна после `expires_at`/soft-delete. Anonymous lifecycle удаляет строку после 180 дней при настроенном cleanup scheduler; therapist-case явно назначается и удаляется владельцем через защищённый `/admin`. |
| Email и имя | `test_sessions.user_email`, `user_name` | Опциональная будущая выдача отчёта | Legacy API/email code существует, но payment/AI routes retired | `deleteSession()` обнуляет поля. Нельзя публиковать обещание email delivery до нового delivery flow. |
| IP и user-agent | отсутствуют в схеме (колонки `test_sessions`/`activity_log` удалены миграцией `20260825120000`) | Не собираются вовсе с 02.7A; колонки и старые значения удалены 02.7C | Не входят в clinical/AI context | Ретроактивная очистка выполнена решением владельца D-035; точный IP не хранится нигде (ER §9). |
| Pair comparison | `pair_comparisons.comparison_data` и ссылки на две sessions | Завершённый парный результат Лазаруса | Показывается через result flow | При 180-day physical cleanup сессии FK удаляет связанные rows, а lifecycle заранее удаляет известный pair PDF. Public soft-delete пока не равен physical cleanup. |
| Activity records | `activity_log` | Технический audit: создание, сохранение, завершение, удаление | Локальная БД | Новые records не содержат IP/user-agent. После public soft-delete часть событий остаётся с `session_id = NULL`; отдельный срок хранения operational records ещё не утверждён. |
| PDF | `storage/pdfs`, path в legacy `ai_interpretations` | Бесплатный PDF результата и будущая выдача отчёта | Локальная файловая система; generated files игнорируются Git | Плановый anonymous lifecycle удаляет известные result/interpretation/pair PDFs. Public soft-delete не удаляет файл немедленно. |
| Payment/AI record | legacy `ai_interpretations`, `payment_transactions` | Legacy model; production routes retired | Новый YooKassa/AI flow ещё не существует | Будущая модель обязана отделить фискальные записи от clinical answers и использовать отдельный explicit consent snapshot. |

## Что публично обещать нельзя до реализации

- Что «все данные зашифрованы».
- Что данные никогда не передаются третьим лицам: будущий AI provider и YooKassa будут отдельными получателями при соответствующем flow.
- Что удаление уже уничтожает все связанные данные и generated files.
- Что IP используется для надёжного определения страны или кризисной помощи.
- Что email/AI report автоматически отправляется: legacy routes выключены, а новый workflow ещё не создан.

## Принятые целевые решения

1. Anonymous-данные хранятся 180 календарных дней по реализованной lifecycle-policy; завершённый `therapist_case` владелец явно назначает и удаляет через минимальный защищённый `/admin`.
2. Передача данных внешнему AI требует отдельного consent при checkout расширенной интерпретации. Public checkout/capture, AI flow, утверждённый текст, provider list и серверная consent-запись ещё не реализованы.

## Решения владельца, необходимые до реализации

1. Точный текст consent и перечень допустимых AI providers до включения AI flow.

## Ближайшая реализация после решений

1. Подтвердить production scheduler/monitoring для уже реализованного anonymous lifecycle.
2. Определить срок хранения обезличенных operational records.
3. Определить и реализовать отдельную semantics ручного delete для technical records, generated files и будущих financial records.
4. Перед public launch провести юридическую проверку privacy copy на основе этого data map и фактического поведения.
