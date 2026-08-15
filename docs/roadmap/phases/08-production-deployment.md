# Этап 08 — staging и production

Статус: **Не начат**. Доступ к серверу запрашивается только здесь и сначала используется read-only обследование.

## Цель

Разместить отдельное приложение на инфраструктуре 23time.ru предсказуемо, с TLS, секретами, наблюдаемостью, резервным копированием и проверенным откатом.

## Work packages

1. **Read-only hosting survey.** PHP/extensions, DB, web server, document roots, cron/queue, disk, backups, DNS/SSL, ограничения процессов; никаких изменений на первом проходе.
2. **Topology decision.** Подтвердить `test.23time.ru` или иной route, изоляцию от WordPress/WooCommerce, общий/отдельный YooKassa shop и сетевые webhook paths.
3. **Staging.** Отдельные database/secrets/provider sandbox, anonymized fixtures, HTTP auth/access restriction и deployment artifact.
4. **Configuration/secrets.** Environment matrix, rotation, least privilege, production debug off, writable paths вне public root.
5. **Database release.** Проверяемые migrations, preflight, backup, restore и rollback/forward-fix procedure.
6. **Deployment automation.** Repeatable build/install/cache/migrate/smoke steps; releases directory или эквивалентная атомарная смена версии.
7. **Operations.** Structured logs без ответов/секретов, health checks, error/payment/job alerts, retention и disk monitoring.
8. **Go-live.** Staging acceptance, backup/restore drill, controlled release window, smoke/free flow/sandbox or approved real payment, rollback criteria.
9. **Runbooks/docs.** Обновить фактическую архитектуру, recovery, incident response, secret rotation и человеческий changelog.

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
