# Архитектура PsyTest Platform

Статус: **сводка актуализирована 2026-09-09**. Module API v2, UI, базовый AI-контур и одноразовые owner invitations реализованы. Полные кабинеты, редакции/одобрение отчётов и YooKassa остаются в [ROADMAP.md](ROADMAP.md).

## Обзор

PsyTest — PHP-приложение для бесплатного прохождения психологических методик и выдачи базового результата. Реализованы пять модулей: СМИЛ, BDI, HADS, BAI и Lazarus. Платёжный контур и YooKassa не реализованы; старые payment endpoints отвечают `410 Gone`. Новый бесплатный AI-контур работает через `core/Ai/`: реестр промптов, адаптер, очередь `ai_reports`, генерация после HTTP-ответа и polling. Вход задания заморожен при постановке: `AiReportContextBuilder` собирает разрешённый контекст, и он вместе с промптом пишется в `context_snapshot`/`prompt_snapshot`, а обработчик отправляет провайдеру именно снимок (аудит R2); задания без снимка — только те, что поставлены до миграции. Политика согласия, клиентских черновиков и удаления имеет открытые дефекты [ревью 08.09](docs/audit/2026-09-08-delivery-review.md).

| Слой | Фактическая технология |
|---|---|
| Runtime | PHP 8.3+ |
| Шаблоны | Twig 3 |
| Данные | MySQL 5.7 или 8.0 / InnoDB, Phinx migrations; обе версии проходят CI |
| PDF | Dompdf |
| Проверки | PHPUnit 10, PHPStan, PHP-CS-Fixer |
| График СМИЛ | Chart.js CDN и protected classic-profile JS |

Это собственное MVC без фреймворка. `public/` — единственный web root; весь HTTP-трафик входит через `public/index.php`.

## Карта исходников

```text
public/       front controller, CSS/JS и статические assets
controllers/  HTTP-координация
core/         Router, Database, CSRF, sessions, lifecycle, PDF и module loader
modules/      методики, вопросы, scoring и result sections
services/     legacy AI/payment/email code — ЗАМОРОЖЕНО до этапов 06/07 (D-033); публично не вызываются
templates/    Twig-страницы и result blocks
database/     Phinx migrations и schema snapshot
tests/        unit, integration и contract/regression tests
docs/roadmap/ product rules, phases, status, data map и worklog
```

## Основной request flow

```text
HTTP request
  -> public/.htaccess (HTTPS redirect + security headers)
  -> public/index.php
  -> Router + CsrfMiddleware
  -> controller
  -> ModuleLoader / SessionManager / domain service
  -> Twig HTML, JSON или PDF
```

`TestController` создаёт session, сохраняет валидированные ответы и вычисляет результат методом модуля. `SessionManager` переводит её из `partial` в `completed` одним условным update; после этого ответы, демография и рассчитанный результат неизменяемы. `ResultController` получает result только по `session_token`, проверяет соответствие route slug тесту и рендерит `ResultSection` через `result-layout.twig`. PDF-ветка рендерит те же секции через общий `core/ResultSectionRenderer.php` (единственный dispatch секций в HTML, включая статический SMIL-график для печати). Контроллеры не ссылаются на конкретные классы модулей — только `TestModuleInterface`; веб-график пары — декларативный метод `pairChartData(): ?array` интерфейса (реализует Lazarus). Контракт закреплён `RendererContractTest` (19 тестов).

## Публичные маршруты

Источник истины — [public/index.php](public/index.php). Все POST-маршруты проходят `CsrfMiddleware`, кроме retired `/webhook/yoomoney`, который не принимает payload и отвечает `410`.

| Метод | Маршрут | Обработчик | Состояние |
|---|---|---|---|
| GET | `/` | `HomeController::index` | публичный лендинг с каталогом доступных методик; пока `noindex` по общей policy |
| GET | `/tests` | `HomeController::tests` | каталог |
| GET | `/test/{slug}` | `TestController::start` | начало теста |
| GET | `/invite/{token}` | `TestController::invite` | read-only preview персонального приглашения |
| POST | `/invite/{token}/start` | `TestController::startInvite` | CSRF-защищённое одноразовое связывание invitation и session |
| POST | `/test/{slug}/save` | `TestController::save` | autosave |
| POST | `/test/{slug}/submit` | `TestController::submit` | validation и scoring |
| GET | `/test/{slug}/pair` | `TestController::pairStart` | второй партнёр Lazarus |
| POST | `/test/{slug}/pair/submit` | `TestController::pairSubmit` | завершение пары |
| GET | `/result/{slug}/{token}` | `ResultController::show` | базовый результат |
| GET | `/result/{slug}/{token}/pdf` | `ResultController::pdf` | PDF результата |
| GET | `/result/{slug}/{token}/pair-status` | `ResultController::pairStatus` | polling pair flow |
| POST | `/result/{slug}/{token}/report` | `ResultController::requestReport` | заказ расширенного разбора: ставит задание в очередь |
| GET | `/result/{slug}/{token}/report-status` | `ResultController::reportStatus` | состояние разбора для опроса со страницы |
| POST | `/result/{token}/delete` | `ResultController::delete` | отдельный delete route; token без slug |
| GET | `/account/login` | `AccountController::loginForm` | форма добровольного входа посетителя по email |
| POST | `/account/login` | `AccountController::requestLogin` | запрос одноразовой ссылки; ответ одинаков для любого адреса |
| GET | `/account/login/{token}` | `AccountController::login` | вход по одноразовой ссылке (15 минут, одно открытие) |
| POST | `/account/logout` | `AccountController::logout` | выход посетителя |
| GET | `/account` | `AccountController::index` | история сохранённых результатов и удаление аккаунта |
| GET | `/account/results/{sessionId}` | `AccountController::showResult` | тот же результат по владению аккаунтом, без bearer-токена |
| GET | `/account/results/{sessionId}/pdf` | `AccountController::resultPdf` | PDF того же результата по владению аккаунтом |
| POST | `/account/results/{sessionId}/detach` | `AccountController::detach` | отвязать результат; он возвращается в класс `anonymous` |
| POST | `/account/attach` | `AccountController::attach` | явное сохранение результата в кабинет по кнопке на его странице |
| POST | `/account/delete` | `AccountController::delete` | удалить аккаунт вместе с сохранёнными результатами |
| GET | `/admin/login` | `OwnerController::login` | owner login; выключен без Argon2id hash в server env |
| POST | `/admin/login` | `OwnerController::authenticate` | проверка owner credentials |
| POST | `/admin/logout` | `OwnerController::logout` | завершение owner session |
| GET | `/admin` | `OwnerController::dashboard` | защищённый минимальный owner dashboard |
| POST | `/admin/case/lookup` | `OwnerController::lookupCase` | поиск завершённого кейса по result token |
| POST | `/admin/case/assign` | `OwnerController::assignCase` | явное назначение therapist case |
| POST | `/admin/case/delete` | `OwnerController::deleteCase` | полное ручное удаление кейса |
| POST | `/admin/invites/create` | `OwnerController::createInvite` | создать 14-day invitation для поддерживаемой методики |
| POST | `/admin/invites/revoke` | `OwnerController::revokeInvite` | отозвать неоткрытое invitation |
| GET | `/admin/invited-case/{sessionId}` | `OwnerController::viewInvitedCase` | защищённо показать базовый результат и читаемую анкету invitation case |
| POST | `/admin/invited-case/{sessionId}/delete` | `OwnerController::deleteInvitedCase` | удалить кейс приглашения с его карточки (с подтверждением) |
| GET | `/admin/clients` | `OwnerController::clients` | список карточек клиентов и форма создания |
| POST | `/admin/clients/create` | `OwnerController::createClient` | создать карточку клиента (подпись владельца) |
| GET | `/admin/clients/{clientId}` | `OwnerController::viewClient` | карточка клиента: назначения, история, удаление |
| POST | `/admin/clients/{clientId}/update` | `OwnerController::updateClient` | изменить подпись и заметку карточки |
| POST | `/admin/clients/{clientId}/invites/create` | `OwnerController::createClientInvite` | создать назначение (invitation) для клиента |
| POST | `/admin/clients/{clientId}/delete` | `OwnerController::deleteClient` | удалить карточку со всеми кейсами и файлами |
| GET | `/pair/{id}` | `ResultController::pairShow` | сравнение пары |
| GET | `/pair/{id}/pdf` | `ResultController::pairPdf` | PDF сравнения |
| GET | `/api/health` | `ApiController::health` | health check |
| GET | `/privacy`, `/terms`, `/deleted` | `HomeController` | static pages |
| GET | `/error/{code}` | `HomeController::error` | error page |
| GET | `/interpretation/{token}` | `RetiredPaymentController::interpretation` | `410 Gone` |
| POST | `/interpretation/{token}/pay` | `RetiredPaymentController::payment` | `410 Gone` |
| POST | `/webhook/yoomoney` | `RetiredPaymentController::yoomoneyWebhook` | `410 Gone`, payload не обрабатывается |

Маршрута `/api/yoomoney/webhook` нет. Методы legacy `ApiController` и сервисы старой оплаты не являются публичным API.

## Модули и scoring

`ModuleLoader` сканирует `modules/*`, читает класс из PHP-файла, инстанцирует его и регистрирует по `metadata.slug`. Имя директории не всегда равно slug: например, `modules/beck-depression` имеет slug `bdi`.

Каждый модуль реализует `TestModuleInterface` и обычно наследует `BaseTestModule`. Ниже сокращённый пример; полный действующий контракт, включая answer schema, capabilities и AI context, — `modules/TestModuleInterface.php`:

```php
interface TestModuleInterface
{
    public function getMetadata(): array;
    public function getQuestions(): array;
    public function calculateResults(array $answers): array;
    public function buildSections(array $results): array;
    public function generateInterpretation(array $scores): array;
    public function supportsPairMode(): bool;
    public function comparePairResults(array $results1, array $results2): array;
    public function getTestTemplate(): ?string;
    public function getResultTemplate(): ?string;
    public function getCustomJavaScript(): ?string;
}
```

Форма questions и shape результата зависят от модуля. Валидацию до расчёта выполняет `AnswerValidator`. Нельзя менять проверенные scoring core СМИЛ и Lazarus без воспроизводимого дефекта, источника и golden fixture.

| Модуль | Каталог | Вопросов | Особенность |
|---|---|---:|---|
| СМИЛ | `modules/smil/` | 566 | protected канонический profile chart и базовый scoring |
| BDI | `modules/beck-depression/` | 21 | machine-readable item-9 safety signal и утверждённое generic notice после валидированного положительного ответа |
| HADS | `modules/hads/` | 14 | две подшкалы |
| BAI | `modules/beck-anxiety/` | 21 | суммарная шкала |
| Lazarus | `modules/lazarus/` | 16 | одиночный и pair flow |

Происхождение текстов, норм и коммерческие права методик ведутся в [реестре методик](docs/roadmap/METHODOLOGY_REGISTRY.md).

## Сессии, доступ и удаление

`test_sessions.session_token` — bearer credential результата. Он открывает только свою сессию: `getSessionTestForRoute()` связывает token с тестом из route. `partner_token` — reference приглашения пары, не альтернативный credential.

Браузерная PHP-сессия запускается только через `Security::startSession()`: cookie имеет `HttpOnly`, `SameSite=Lax`, общий path `/` и обязательный `Secure` в production (а также при HTTPS в development). Заголовки безопасности задаются один раз в `public/.htaccess`, включая ответы Apache и статические файлы.

Новая session получает `retention_class = anonymous`. Независимо существуют access TTL (`expires_at`), срок physical retention 180 дней от `created_at`, public soft-delete и плановый `SessionLifecycleService`, физически удаляющий просроченные anonymous sessions и известные artifacts.

`therapist_case` назначается либо владельцем через минимальный `/admin` после завершения anonymous-сессии, либо атомарно при CSRF-защищённом старте персонального приглашения. `test_invites` хранит только SHA-256 хеш bearer-токена, test scope, срок, статус, owner-only note и ссылку на созданную session; raw token появляется лишь в одноразовом copy field владельца. `InvitedCasePresenter` возвращает для owner-only case текстовые строки анкеты, сопоставляя сохранённый ответ с текущим модулем; базовый результат рендерится теми же module sections, без raw JSON. Dashboard защищён Argon2id password, session, CSRF и глобальным лимитом неудачных входов. Ручное удаление физически очищает session и известные artifacts, затем оставляет только обезличенное operational событие без идентификаторов кейса; вместе с session удаляется и строка приглашения, чтобы owner note не пережила описываемый ею кейс.

`therapist_clients` — карточка клиента специалиста: подпись владельца и необязательная заметка, без единого контакта клиента. `core/TherapistClientService.php` создаёт и изменяет карточку, собирает её назначения (`test_invites.client_id`) со статусами и историю завершённых прохождений, а при удалении одной транзакцией проводит каждую связанную session через `SessionLifecycleService` и удаляет карточку, унося приглашения каскадом. Приглашение без карточки остаётся полноправным: связь необязательна. Полный кабинет с отчётами относится к этапу 07. Фактические границы — в [DATA_MAP_CURRENT.md](docs/roadmap/DATA_MAP_CURRENT.md), policy — в [RETENTION_POLICY.md](docs/roadmap/RETENTION_POLICY.md).

`visitor_accounts` — добровольный кабинет посетителя (07.K3, D-053). Идентичность — только нормализованный email; пароля и профиля нет, вход идёт по одноразовой ссылке, от которой в `visitor_login_tokens` хранится лишь SHA-256, срок 15 минут и отметка использования. `core/VisitorAccountService.php` гасит токен одним атомарным `UPDATE`, ограничивает 3 запросами на адрес за 15 минут и отвечает одинаково для любого email. Привязка результата (`test_sessions.account_id`, `retention_class = account`) возможна только при одновременном владении аккаунтом и точным `session_token` завершённой anonymous-сессии: email, cookie и IP связь не создают, а `therapist_case` в кабинет посетителя не переходит. Отвязка возвращает сессию в `anonymous` без продления срока; удаление аккаунта проводит каждую привязанную session через `SessionLifecycleService` и удаляет аккаунт с его токенами. Сессия посетителя и owner-сессия независимы: вход посетителя не даёт ничего в `/admin`. Страницы кабинета отдаются с `Cache-Control: no-store, private` и `X-Robots-Tag: noindex`, а `session_token` в их HTML не выводится — общий рендер результата вынесен в `core/ResultPresenter.php`.

## Безопасность и privacy границы

- state-changing browser routes защищены CSRF;
- production web root допускает только `public/index.php` как PHP entry point;
- result token, ответы, отчёты и секреты не должны попадать в логи, fixtures или Git;
- приложение не использует IP как достоверную страну и не вызывает GeoIP API;
- новые test sessions и activity records не сохраняют IP или user-agent; nullable legacy-колонки и старые значения удалены миграцией `20260825120000` (02.7C, D-035);
- public privacy text не заявляет encryption, отсутствие будущих third parties или немедленное полное физическое удаление.

`ClinicalSafetySignal` извлекает machine-readable BDI item-9 signal. `ResultController` показывает утверждённый generic notice только при этом сигнале, без контактов, URL, страны или IP/GeoIP. Country resolver и реестр кризисных ресурсов не реализованы; их добавление требует нового решения владельца.

## Legacy integrations и целевой контур

`AIInterpretationService`, `PaymentService`, legacy `ApiController` methods и старые AI/payment tables — исторические слои. Они не доказывают готовность оплаты или AI и не должны подключаться к новым public routes. Граница согласия требуется уже бесплатному AI-flow и входит в ближайший K0; она не откладывается до оплаты.

Новая YooKassa state machine относится к этапу 06. В этапе 07 уже реализованы provider boundary, versioned prompts и очередь с выдачей. Отдельный consent, snapshots, revisions/approval и полный кабинет остаются незавершёнными. Критерии и release gates — в [ROADMAP.md](ROADMAP.md).

## База данных и миграции

`database/migrations/` — source of truth. `database/schema.sql` — snapshot итоговой схемы, изменяемый осознанно вместе с migration chain. В CI чистая MySQL-проверка использует `composer migrate`.

Таблицы включают tests, test sessions, `test_invites`, `visitor_accounts`/`visitor_login_tokens`, pair comparisons, activity log, новую `ai_reports` (со снимком входа задания) и legacy AI/payment records. Нельзя строить новую функцию на legacy финансовых таблицах: clinical и financial records разделяются в этапе 06.

## Проверки и рабочая дисциплина

Полный локальный gate указан в [AGENTS.md](AGENTS.md):

```bash
composer validate --strict --no-check-publish
composer audit
composer migrate
composer test
composer analyse
composer lint
php bin/check-architecture.php
composer baseline:check
```

Актуальные status, evidence и следующий work package — в [STATUS.md](docs/roadmap/STATUS.md), [WORKLOG.md](docs/roadmap/WORKLOG.md) и [CHECKPOINT.md](docs/roadmap/CHECKPOINT.md). Module API v2 реализован в закрытом этапе 03; новые реальные модули добавляются в этапе 09.
