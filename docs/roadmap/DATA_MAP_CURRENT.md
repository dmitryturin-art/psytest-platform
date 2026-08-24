# Current-state data map

Статус: **фактическая инвентаризация на 2026-08-22**. Это не privacy policy и не утверждённая retention policy. Документ описывает только то, что видно в текущем коде и schema, чтобы следующий пакет не строился на ложных обещаниях.

## Область

Основные источники: `database/migrations/20260708050511_init_schema.php`, `core/SessionManager.php`, `controllers/ResultController.php`, `controllers/ApiController.php`, `core/PDFGenerator.php` и текущий публичный privacy text в `HomeController`.

## Карта данных

| Данные | Текущее место | Текущая цель/flow | Доступ/передача | Удаление и известный долг |
|---|---|---|---|---|
| Ответы, рассчитанные результаты, пол/возраст | `test_sessions.answers`, `calculated_results`, `demographics` | Бесплатный результат, PDF, сравнение пары; явно назначенный therapist-case | Доступ по bearer result token; минимальный `/admin` owner lookup принимает token только в POST и не выводит его в URL | Public `deleteSession()` — soft-delete и очистка clinical-полей. Плановый `SessionLifecycleService` физически удаляет 180-day anonymous session, pair-данные, известные PDF и session-bound activity records; owner может физически удалить therapist-case с подтверждением. |
| Result token / partner token | `test_sessions.session_token`, `partner_token` | Уникальная ссылка на результат / relation для пары | Token не должен попадать в logs/analytics; partner token не credential | Сессия перестаёт быть доступна после `expires_at`/soft-delete. Anonymous lifecycle удаляет строку после 180 дней при настроенном cleanup scheduler; therapist-case явно назначается и удаляется владельцем через защищённый `/admin`. |
| Email и имя | `test_sessions.user_email`, `user_name` | Опциональная будущая выдача отчёта | Legacy API/email code существует, но payment/AI routes retired | `deleteSession()` обнуляет поля. Нельзя публиковать обещание email delivery до нового delivery flow. |
| IP и user-agent | nullable legacy-колонки `test_sessions`, `activity_log` | Для новых сессий и событий не собираются и сохраняются как `NULL` | Не входят в clinical/AI context | Старые строки, созданные до 02.7A, могут содержать значения до их обычного lifecycle/delete; массовая ретроактивная очистка отдельно не выполнялась. |
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

1. Нужна ли ретроактивная очистка IP/user-agent из строк, созданных до 02.7A.
2. Точный текст consent и перечень допустимых AI providers до включения AI flow.

## Ближайшая реализация после решений

1. Подтвердить production scheduler/monitoring для уже реализованного anonymous lifecycle.
2. Определить срок хранения обезличенных operational records.
3. Определить и реализовать отдельную semantics ручного delete для technical records, generated files и будущих financial records.
4. Перед public launch провести юридическую проверку privacy copy на основе этого data map и фактического поведения.
