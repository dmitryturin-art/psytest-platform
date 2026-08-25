# Этап 06 — заказы, купоны и YooKassa

Статус: **Не начат**. Зависит от security/privacy gates; production включается только после sandbox и фискального согласования.

## Уточнения владельца (25.08.2026, D-034)

- Базовый результат бесплатен и открыт всем всегда, авторизация не требуется.
- Расширенный ИИ-отчёт платный; покупать может любой посетитель — с авторизацией или без.
- При авторизации у посетителя сохраняется история пройденных тестов — и базовых, и расширенных. Значит, этапу 06 нужен лёгкий контур учётной записи/истории (проектируется заново, к legacy-авторизации не привязывать).
- Купон/персональная ссылка — механизм клиентов терапевта: их расширенный отчёт генерируется, но уходит только в кабинет владельца (см. этап 07, WP6/7), клиент сразу видит лишь бесплатную базу.
- Авторизация — «пока попроще» (владелец, 25.08): лёгкий вход без пароля (например, вход по ссылке на email); полноценная регистрация на старте не планируется.

## Цель

Построить новый надёжный коммерческий контур для необязательного разбора, не блокируя бесплатный результат и не смешивая платёж с AI generation.

## Контрольная точка владельца

Подтвердить магазин YooKassa, тестовые credentials, НДС/предмет/способ расчёта, контакт чека, return/webhook URLs и возможность совместного использования с WooCommerce. Секреты передаются только через защищённую конфигурацию, не чат/Markdown/Git.

## Модель

- `offering`: test/mode, enabled, price, report options.
- `order`: immutable amount/currency/selection snapshot и state.
- `payment_attempt`: provider ID, idempotency key, state, payload fingerprints.
- `coupon`: type/value, scope, expiry, use limit, owner note.
- `entitlement`: право заказать конкретный разбор; возникает после подтверждения или 100%-ного купона.
- AI job/report создаются отдельно на этапе 07.

## Work packages

1. Backend settings: 120 ₽ по умолчанию, capability-based eligibility, audit trail изменений.
2. Order state machine и migrations; повтор submit не создаёт двойной заказ.
3. Coupon generator для владельца: одноразовый 100%, персональная ссылка, revoke/expire/use log; нулевой заказ проходит без обращения к YooKassa.
4. YooKassa adapter: create payment с Idempotence-Key, return flow без доверия browser query.
5. Webhook verification/deduplication/out-of-order handling; entitlement только по подтверждённому состоянию.
6. Receipt payload и fiscal fields; sandbox evidence и контролируемый минимальный real payment только с разрешения владельца.
7. Owner UI: offerings, price, coupon generation, order/payment status и manual diagnostics без показа секретов.
8. Reconciliation/monitoring: зависшие состояния, retry policy, refund/cancel audit.

## Проверка и exit criteria

- Contract/integration tests с YooKassa fixtures: duplicate webhook, retry, cancel, amount mismatch, invalid signature/authenticity flow.
- Browser E2E: бесплатный результат → вариант отчёта → купон или sandbox payment → entitlement.
- BDI/BAI/HADS никогда не получают checkout; SMIL/Lazarus получают только при enabled offering.
- Подтверждены чек, idempotency и отсутствие старого YooMoney path; `PAY-01..04` закрыты.

## Покрытие аудита

`PAY-01`, `PAY-02`, `PAY-03`, `PAY-04`; product decisions D-002..D-008/D-014/D-018.
