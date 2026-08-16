# Этап 01 — containment и безопасность

Статус: **В работе**. Пакеты containment, dependency safety, CSRF, 01.4–01.5C, server-side validation и SEC-05 выполнены и подтверждены GitHub CI; следующий пакет — P1 PAIR-02. До выхода из этапа платный CTA скрыт.

## Цель

Убрать известные P0-уязвимости и недоступные сценарии, не меняя психометрические результаты и не строя преждевременно новый payment flow.

## Work packages

1. **Containment платного пути.** Regression-тестом подтвердить broken route/template, скрыть все платные CTA и вернуть безопасный нейтральный ответ на legacy endpoints. Явно пометить YooMoney path как retired.
2. **Dependency safety.** Обновить `dompdf` минимум до 3.1.6, проверить audit, генерацию PDF, кириллицу, график SMIL и print layout; после этого добавить безопасный `composer.lock` в Git и удалить его временное ignore rule.

Статус dependency safety: **завершено в `7272e51`**; Dompdf 3.1.6, чистый audit и in-memory Cyrillic PDF smoke получены. Проверка старых PDF не требуется по решению владельца; визуальная регрессия SMIL-графика остаётся отдельной UI-проверкой.
3. **CSRF enforcement.** Инвентаризировать mutating routes, добавить единый middleware/guard, проверить missing/invalid/reused token и совместимость форм.
4. **Типизированные ссылки.** 01.4 устранил `OR`-lookup: публичный доступ к результату разрешён только через `session_token`, а `partner_token` остаётся ссылкой пары. Отдельно остаются purpose/admin tokens, pair invite single-use и более точная expiry/revocation policy.
5. **Route/session integrity.** 01.5A сверяет `slug` с `test_id` сохранённой session для result/PDF/status/autosave/submit/pair-flow; unknown и подменённые slug отклоняются до scoring. Подтверждено CI.
6. **Server-side validation.** 01.5B добавил обязательные полнота/тип/диапазон/allowed-values checks для текущих модулей; подтверждено GitHub CI `31939695568`.
7. **Pair boundaries.** PAIR-01 добавил single-use приглашения, а 01.5C восстановил clean migration path. До закрытия P1 остаются ownership/expiry/cross-session regression-cases.
8. **Web-root hygiene.** `21c77c7` удалил debug/test entrypoints, добавил public PHP allowlist, response hardening и HTTP/browser evidence; SEC-05 закрыт.

Каждый пункт выполняется отдельным небольшим work package/коммитом; dependency, auth и payment containment не смешиваются.

## Запрещено в этом этапе

- Реализовывать production YooKassa или AI reports.
- Рефакторить scoring SMIL/Lazarus.
- Одновременно делать визуальный редизайн.

## Проверка

- Negative HTTP tests для CSRF, token purpose, slug mismatch, invalid answers и pair access.
- `composer audit` без known advisories; PDF smoke fixtures.
- Browser smoke: бесплатное прохождение/результат доступны, сломанных платных ссылок нет.
- Полный quality gate; PHPStan baseline не растёт.

## Exit criteria

Все `SEC-*`, `DEP-01`, containment-части `PAY-01..03` и `PAIR-01` закрыты evidence-ссылками. Бесплатные flows работают как до изменений.

## Покрытие аудита

`SEC-01..05`, `DEP-01`, `PAY-01..03` (containment), `PAIR-01`, часть `SEC-04`.
