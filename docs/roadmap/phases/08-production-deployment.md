# Этап 08 — staging и production

Статус: **Staging на проверке владельца**. `test.23time.ru` активирован; production go-live не начат.

## Цель

Разместить отдельное приложение на инфраструктуре 23time.ru предсказуемо, с TLS, секретами, наблюдаемостью, резервным копированием и проверенным откатом.

## Work packages

1. **Read-only hosting survey.** 08.1A: [inventory Beget](../BEGET_STAGING.md) подтверждает web/CLI PHP 8.3, пустую staging DB, public root и ACL. HTTPS недоступен; DB работает на MySQL 5.7, cron управляется не через SSH shell, системный Composer устарел.
2. **Topology decision.** D-028: `test.23time.ru`, отдельное приложение и отдельная staging DB; WordPress не затрагивается. YooKassa/webhook не входят в бесплатный staging.
3. **Staging.** 08.1B исправил public-root rewrite; 08.1C добавил CI MySQL 5.7/8.0; 08.1D подготовил artifact/backup. 08.1E активировал staging с HTTPS, migrations и browser BDI smoke. Последующее атомарное обновление до `3a2daa8` выложило исправленный PDF Лазаруса и cleanup-миграцию: все 8 migrations `up`, HTTPS/routes/cookie/retired flow проверены smoke, rollback `5da9ab5` сохранён. По D-029 Basic Auth не используется.
4. **Configuration/secrets.** Environment matrix, rotation, least privilege, production debug off, writable paths вне public root.
5. **Database release.** Проверяемые migrations, preflight, backup, restore и rollback/forward-fix procedure.
6. **Deployment automation.** Repeatable build/install/cache/migrate/smoke steps; releases directory или эквивалентная атомарная смена версии.
7. **Operations.** Structured logs без ответов/секретов, health checks, error/payment/job alerts, retention и disk monitoring. 08.1F: стабильная точка `current` → активный релиз, cleanup-скрипт проверен через неё на staging (EXIT=0, лог пишется); готовая инструкция cron для панели Beget — [CRON_CLEANUP.md](../CRON_CLEANUP.md), настройка расписания — за владельцем.
8. **Go-live.** Staging acceptance, backup/restore drill, controlled release window, smoke/free flow/sandbox or approved real payment, rollback criteria.
9. **Runbooks/docs.** 08.1G: backup/restore drill пройден (дамп → 8/8 таблиц, сверка строк; особенности: отдельная база для DR создаётся в панели, same-DB restore требует переименования CONSTRAINT), `PRODUCTION_RUNBOOK.md` зафиксирован до go-live. 08.1H: схема бэкапов — ежедневные автоматические бэкапы Beget + pre-deploy дампы (свой ночной дамп исключён решением владельца); на go-live — разовый drill восстановления из панельного бэкапа. Обновить фактическую архитектуру, recovery, incident response, secret rotation и человеческий changelog.

## Контрольная точка владельца

SSH/панель/DNS доступ, окно работ, допустимый downtime, backup policy и один контролируемый реальный платёж. Любое destructive/production действие предварительно называет точную цель и способ восстановления.

## Проверка и exit criteria

- TLS/headers/cookies/public-root scan; production debug и test endpoints недоступны.
- Restore drill фактически восстановил staging-копию; rollback измерен и записан.
- Полный E2E: бесплатный тест/result, coupon, YooKassa, report generation/delivery по разрешённому сценарию.
- Monitoring получает искусственную тестовую ошибку; секреты отсутствуют в Git/logs.
- `ARCHITECTURE.md`, README и runbooks описывают фактический production; production-часть `DOC-01` закрыта.

## Покрытие аудита

Deployment/operations gaps, окончание `DOC-01` и production-проверка всех security/payment/privacy findings.
