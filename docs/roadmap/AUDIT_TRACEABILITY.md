# Трассировка аудита

Цель: каждое существенное замечание аудита имеет этап, проверяемый результат и место для evidence. `Закрыто` ставится только после теста/проверки и ссылки на commit или worklog.

Статусы: `В работе`, `Запланировано`, `Закрыто`, `Принят риск`, `Не применимо`.

## Security, privacy и clinical safety

| ID | Finding | Риск | Этап | Требуемое доказательство | Статус |
|---|---|---|---|---|---|
| SEC-01 | CSRF генерируется, но не enforced | P0 | 01 | `e42eb89`: middleware + missing/invalid/valid/reused tests; GitHub Actions `31904136305` — success | Закрыто |
| SEC-02 | Token lookup использует `session OR partner` | P0 | 01 | `0d6a947`: `getSessionByResultToken()` lookup только `session_token`; pair reference больше не credential; Lazarus E2E regression; GitHub Actions `31904747962` — success | Закрыто |
| SEC-03 | Route slug не сверяется с session module | P0 | 01 | `92bf5e6`: shared `SessionTestIntegrity`, guards и mismatch regression; GitHub Actions `31933655096` — success | Закрыто |
| SEC-04 | Нет обязательной серверной module validation | P0 | 01/03 | `2cc5321`: type/allowed-values/completeness checks для текущих модулей; full PHP 8.3/MySQL gate `31939695568` — success | Закрыто |
| SEC-05 | Публичные debug/test files | P0 | 01 | `21c77c7`: public PHP allowlist, removed demo/test harnesses, headers и `PublicWebRootTest`; GitHub Actions `31940056207` — success | Закрыто |
| CLIN-01 | BDI item 9 не создаёт самостоятельный crisis signal | P0 | 02 | 02.2A `16c4730`: `ClinicalSafetySignal` создаёт structured signal из валидированного item 9 при низком total; GitHub Actions `31948009328` — success. 02.3A `5942587`: unit-tested `CountryResolver` с manual/session/trusted-hint/unknown precedence без IP и GeoIP API; GitHub Actions `31948360267` — success. 02.3B `50794fa`: fail-closed registry foundation без контактов/reader; GitHub Actions `31948774257` — success. Нужны срок актуальности, проверенные ресурсы, Crisis UI и browser cases после owner-approved текста | В работе |
| DATA-01 | Privacy claims расходятся с plaintext/внешним AI | P0 | 02/07 | 02.4A `a14f5eb`: factual public privacy/delete copy и `PrivacyClaimsTruthfulnessTest` запрещают ложные claims о шифровании, отсутствии будущих получателей и полном мгновенном удалении; GitHub Actions [31949538307](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31949538307) — success. Остаются legal review, minimization IP/user-agent и AI consent/provider boundary | В работе |
| DATA-02 | Удаление данных неполное | P1 | 02 | 02.1 `87925ba` + migration repair `6152177`: `SessionLifecycleService`, retention class, 180-day boundary/therapist exclusion/activity/PDF/pair integration cases; GitHub Actions `31947662859` — success. Ручное therapist-delete, будущие AI/jobs и financial separation остаются следующими пакетами | В работе |
| PAIR-01 | Границы доступа к приглашению пары недостаточны | P1 | 01 | one-use `46dade6`, migration chain `52883c9`, exact binding `1cc772e`, expiry regression `897b29b`, atomic race handling `af48b61`; GitHub Actions `31940661228` — success | Закрыто |

## Dependencies, payment и AI

| ID | Finding | Риск | Этап | Требуемое доказательство | Статус |
|---|---|---|---|---|---|
| DEP-01 | `dompdf` 3.1.5 имеет advisories | P0 | 01 | `7272e51`: `composer audit` clean, full tests 88/1112 и in-memory PDF smoke | Закрыто |
| PAY-01 | Route вызывает отсутствующий `initiatePayment` | P0 | 01/06 | CTA скрыт и legacy endpoint отвечает 410; затем end-to-end YooKassa sandbox test | В работе |
| PAY-02 | Отсутствует payment template | P0 | 01/06 | legacy payment page недоступна (410); затем browser checkout flow | В работе |
| PAY-03 | Смешаны YooMoney и YooKassa | P0 | 01/06 | legacy YooMoney path отвечает 410; затем одна YooKassa state machine | В работе |
| PAY-04 | Цена 499 ₽ захардкожена | P1 | 06 | backend setting 120 ₽ + immutable order snapshot tests | Запланировано |
| AI-01 | Универсальный flow фактически SMIL-specific | P1 | 07 | prompt registry по test/audience/report, eval fixtures | Запланировано |

## Psychometrics, UX, code и docs

| ID | Finding | Риск | Этап | Требуемое доказательство | Статус |
|---|---|---|---|---|---|
| SMIL-ADD-01 | 200 metadata против 23 фактических шкал; R/Es/Do сомнительны | P0 для проф. продукта | 05 | source registry, invariants, независимые fixtures, owner batch approval | Запланировано |
| UX-01 | Пустая sticky navigation | P1 | 04 | responsive browser snapshots и keyboard test; 02.4A повторно подтвердил: на 390×844 navigation не видна, хотя privacy content не переполняется | Запланировано |
| UX-02 | BDI progress заканчивается на 20/21 | P1 | 04 | browser/state regression test | Запланировано |
| UX-03 | Lazarus legends/touch/accessibility неудобны | P1 | 04 | mobile interaction + screen-reader labels + contrast test | Запланировано |
| DOC-01 | README/ARCHITECTURE/DEVELOPMENT расходятся с кодом | P1 | 00/01/08 | claim-to-code review, links, актуальные команды и deployment runbook | В работе |
| CODE-01 | PHPStan baseline подавляет исторические сообщения | P1 | 00/03 | `composer baseline:check` фиксирует 148 entries; далее baseline уменьшается пакетами | В работе |

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
