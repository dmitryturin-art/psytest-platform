# Трассировка аудита

Цель: каждое существенное замечание аудита имеет этап, проверяемый результат и место для evidence. `Закрыто` ставится только после теста/проверки и ссылки на commit или worklog.

Статусы: `В работе`, `Запланировано`, `Закрыто`, `Принят риск`, `Не применимо`.

## Security, privacy и clinical safety

| ID | Finding | Риск | Этап | Требуемое доказательство | Статус |
|---|---|---|---|---|---|
| SEC-01 | CSRF генерируется, но не enforced | P0 | 01 | отрицательные HTTP-тесты для всех mutating endpoints | Запланировано |
| SEC-02 | Token lookup использует `session OR partner` | P0 | 01 | раздельные типизированные токены, expiry/revocation и cross-access tests | Запланировано |
| SEC-03 | Route slug не сверяется с session module | P0 | 01 | mismatch возвращает безопасный отказ; regression test | Запланировано |
| SEC-04 | Нет обязательной серверной module validation | P0 | 01/03 | schema/range/completeness tests для каждого модуля | Запланировано |
| SEC-05 | Публичные debug/test files | P0 | 01 | web-root inventory и HTTP 404/deny tests | Запланировано |
| CLIN-01 | BDI item 9 не создаёт самостоятельный crisis signal | P0 | 02 | unit/HTTP/browser cases при низком total и item 9 > 0 | Запланировано |
| DATA-01 | Privacy claims расходятся с plaintext/внешним AI | P0 | 02/07 | точная data map, consent, minimization и проверенные тексты | Запланировано |
| DATA-02 | Удаление данных неполное | P1 | 02 | lifecycle/delete integration tests по всем связанным сущностям | Запланировано |
| PAIR-01 | Границы доступа к приглашению пары недостаточны | P1 | 01 | ownership/expiry/single-use/cross-session tests | Запланировано |

## Dependencies, payment и AI

| ID | Finding | Риск | Этап | Требуемое доказательство | Статус |
|---|---|---|---|---|---|
| DEP-01 | `dompdf` 3.1.5 имеет advisories | P0 | 01 | clean `composer audit`, PDF regression smoke | Запланировано |
| PAY-01 | Route вызывает отсутствующий `initiatePayment` | P0 | 01/06 | CTA скрыт до реализации; затем end-to-end sandbox test | Запланировано |
| PAY-02 | Отсутствует payment template | P0 | 01/06 | нет broken route; затем browser checkout flow | Запланировано |
| PAY-03 | Смешаны YooMoney и YooKassa | P0 | 01/06 | legacy path недоступен; одна YooKassa state machine | Запланировано |
| PAY-04 | Цена 499 ₽ захардкожена | P1 | 06 | backend setting 120 ₽ + immutable order snapshot tests | Запланировано |
| AI-01 | Универсальный flow фактически SMIL-specific | P1 | 07 | prompt registry по test/audience/report, eval fixtures | Запланировано |

## Psychometrics, UX, code и docs

| ID | Finding | Риск | Этап | Требуемое доказательство | Статус |
|---|---|---|---|---|---|
| SMIL-ADD-01 | 200 metadata против 23 фактических шкал; R/Es/Do сомнительны | P0 для проф. продукта | 05 | source registry, invariants, независимые fixtures, owner batch approval | Запланировано |
| UX-01 | Пустая sticky navigation | P1 | 04 | responsive browser snapshots и keyboard test | Запланировано |
| UX-02 | BDI progress заканчивается на 20/21 | P1 | 04 | browser/state regression test | Запланировано |
| UX-03 | Lazarus legends/touch/accessibility неудобны | P1 | 04 | mobile interaction + screen-reader labels + contrast test | Запланировано |
| DOC-01 | README/ARCHITECTURE/DEVELOPMENT расходятся с кодом | P1 | 00/01/08 | claim-to-code review, links, актуальные команды и deployment runbook | В работе |
| CODE-01 | PHPStan baseline подавляет 149 сообщений | P1 | 00/03 | baseline count зафиксирован, не растёт и уменьшается пакетами | В работе |

## Сквозные наблюдения без отдельного audit ID

| Наблюдение | Этапы | Контроль |
|---|---|---|
| Дублирование slug-ветвлений и специальных случаев | 03 | capability contract, DTO и удаление ветвлений после миграции |
| Глобальная загрузка Chart.js и смешанные CSS-слои | 04 | route-specific assets, tokens/components, bundle/browser comparison |
| Все страницы `noindex` | 04/08 | явная SEO policy: приватные результаты закрыты, публичный каталог индексируем по решению владельца |
| Нет доказанного backup/restore/rollback процесса | 08 | staging drill с записанным временем и результатом |
| README заявляет больше готовности, чем продукт | 00/08 | человеческий changelog отделён от желаемого roadmap |

## Как закрывать finding

1. В phase-файле выполнить work package и критерии приёмки.
2. Добавить свежий вывод проверок в `WORKLOG.md`.
3. В колонке «Требуемое доказательство» добавить commit/test/report link и поставить `Закрыто`.
4. Обновить `STATUS.md` и человеческий `CHANGELOG.md`, если эффект заметен владельцу/пользователю.
5. Не закрывать finding только ссылкой на изменённый код без негативного или regression-теста.
