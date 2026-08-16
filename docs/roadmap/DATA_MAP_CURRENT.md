# Current-state data map

Статус: **фактическая инвентаризация на 2026-08-16**. Это не privacy policy и не утверждённая retention policy. Документ описывает только то, что видно в текущем коде и schema, чтобы следующий пакет не строился на ложных обещаниях.

## Область

Основные источники: `database/migrations/20260708050511_init_schema.php`, `core/SessionManager.php`, `controllers/ResultController.php`, `controllers/ApiController.php`, `core/PDFGenerator.php` и текущий публичный privacy text в `HomeController`.

## Карта данных

| Данные | Текущее место | Текущая цель/flow | Доступ/передача | Удаление и известный долг |
|---|---|---|---|---|
| Ответы, рассчитанные результаты, пол/возраст | `test_sessions.answers`, `calculated_results`, `demographics` | Бесплатный результат, PDF, сравнение пары | Доступ по bearer result token; PDF создаётся локально | `deleteSession()` очищает три поля, но lifecycle связанных pair/PDF/log records ещё не доказан интеграционным тестом. |
| Result token / partner token | `test_sessions.session_token`, `partner_token` | Уникальная ссылка на результат / relation для пары | Token не должен попадать в logs/analytics; partner token не credential | Сессия перестаёт быть доступна после `expires_at`/delete; retention самой строки ещё не утверждён. |
| Email и имя | `test_sessions.user_email`, `user_name` | Опциональная будущая выдача отчёта | Legacy API/email code существует, но payment/AI routes retired | `deleteSession()` обнуляет поля. Нельзя публиковать обещание email delivery до нового delivery flow. |
| IP и user-agent | `test_sessions`, `activity_log` | Сейчас записываются автоматически как технические метаданные | Внешнему AI не должны передаваться | Не очищаются `deleteSession()` и точный срок не определён. Это расходится с целевым принципом минимизации и требует решения/рефакторинга 02. |
| Pair comparison | `pair_comparisons.comparison_data` и ссылки на две sessions | Завершённый парный результат Лазаруса | Показывается через result flow | FK для удаления session есть, но expiry/physical cleanup и PDF lifecycle ещё не оформлены. |
| Activity records | `activity_log` | Технический audit: создание, сохранение, завершение, удаление | Локальная БД | После удаления session остаётся с `session_id = NULL`; IP/user-agent сохраняются. Нужен отдельный retention и minimization policy. |
| PDF | `storage/pdfs`, path в legacy `ai_interpretations` | Бесплатный PDF результата и будущая выдача отчёта | Локальная файловая система; generated files игнорируются Git | Пользовательское удаление пока не доказывает удаление файла. |
| Payment/AI record | legacy `ai_interpretations`, `payment_transactions` | Legacy model; production routes retired | Новый YooKassa/AI flow ещё не существует | Будущая модель обязана отделить фискальные записи от clinical answers и вводить explicit AI consent. |

## Что публично обещать нельзя до реализации

- Что «все данные зашифрованы».
- Что данные никогда не передаются третьим лицам: будущий AI provider и YooKassa будут отдельными получателями при соответствующем flow.
- Что удаление уже уничтожает все связанные данные и generated files.
- Что IP используется для надёжного определения страны или кризисной помощи.
- Что email/AI report автоматически отправляется: legacy routes выключены, а новый workflow ещё не создан.

## Решения владельца, необходимые для целевого data map

1. Сроки хранения анонимных результатов/ответов и отдельный режим для терапевтических клиентов.
2. Нужны ли IP/user-agent после минимального anti-abuse window, и как долго.
3. Точный текст отдельного consent на AI и допустимые providers до запуска AI flow.

## Ближайшая реализация после решений

1. Вынести lifecycle/retention policy в versioned configuration.
2. Уменьшить или ограничить технические метаданные и исключить их из clinical/AI context.
3. Реализовать и протестировать каскадное удаление/анонимизацию для каждой строки и файла из таблицы.
4. Переписать privacy page на основе этого data map и фактического поведения.
