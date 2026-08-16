# Архитектура PsyTest Platform

Статус: **фактическое состояние на 2026-08-16**. Здесь описан работающий код, а будущие AI, YooKassa, кабинет терапевта, UI redesign и Module API v2 — в [ROADMAP.md](ROADMAP.md).

## Обзор

PsyTest — PHP-приложение для бесплатного прохождения психологических методик и выдачи базового результата. Реализованы пять модулей: СМИЛ, BDI, HADS, BAI и Lazarus. Платная интерпретация, YooKassa и новый AI-контур не реализованы; старые public endpoints этого контура отвечают `410 Gone`.

| Слой | Фактическая технология |
|---|---|
| Runtime | PHP 8.3+ |
| Шаблоны | Twig 3 |
| Данные | MySQL 8 / InnoDB, Phinx migrations |
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
services/     legacy AI/payment/email code; AI/payment публично не вызываются
templates/    Twig-страницы и result blocks
database/     Phinx migrations и schema snapshot
tests/        unit, integration и contract/regression tests
docs/roadmap/ product rules, phases, status, data map и worklog
```

## Основной request flow

```text
HTTP request
  -> public/index.php
  -> Router + security headers + CsrfMiddleware
  -> controller
  -> ModuleLoader / SessionManager / domain service
  -> Twig HTML, JSON или PDF
```

`TestController` создаёт session, сохраняет валидированные ответы и вычисляет результат методом модуля. `ResultController` получает result только по `session_token`, проверяет соответствие route slug тесту и рендерит `ResultSection` через `result-layout.twig`.

## Публичные маршруты

Источник истины — [public/index.php](public/index.php). Все POST-маршруты проходят `CsrfMiddleware`, кроме retired `/webhook/yoomoney`, который не принимает payload и отвечает `410`.

| Метод | Маршрут | Обработчик | Состояние |
|---|---|---|---|
| GET | `/` | `HomeController::index` | каталог / redirect |
| GET | `/tests` | `HomeController::tests` | каталог |
| GET | `/test/{slug}` | `TestController::start` | начало теста |
| POST | `/test/{slug}/save` | `TestController::save` | autosave |
| POST | `/test/{slug}/submit` | `TestController::submit` | validation и scoring |
| GET | `/test/{slug}/pair` | `TestController::pairStart` | второй партнёр Lazarus |
| POST | `/test/{slug}/pair/submit` | `TestController::pairSubmit` | завершение пары |
| GET | `/result/{slug}/{token}` | `ResultController::show` | базовый результат |
| GET | `/result/{slug}/{token}/pdf` | `ResultController::pdf` | PDF результата |
| GET | `/result/{slug}/{token}/pair-status` | `ResultController::pairStatus` | polling pair flow |
| POST | `/result/{token}/delete` | `ResultController::delete` | отдельный delete route; token без slug |
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

Каждый модуль реализует `TestModuleInterface` и обычно наследует `BaseTestModule`:

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
| BDI | `modules/beck-depression/` | 21 | machine-readable item-9 safety signal после validation |
| HADS | `modules/hads/` | 14 | две подшкалы |
| BAI | `modules/beck-anxiety/` | 21 | суммарная шкала |
| Lazarus | `modules/lazarus/` | 16 | одиночный и pair flow |

Происхождение текстов, норм и коммерческие права методик ведутся в [реестре методик](docs/roadmap/METHODOLOGY_REGISTRY.md).

## Сессии, доступ и удаление

`test_sessions.session_token` — bearer credential результата. Он открывает только свою сессию: `getSessionTestForRoute()` связывает token с тестом из route. `partner_token` — reference приглашения пары, не альтернативный credential.

Новая session получает `retention_class = anonymous`. Независимо существуют access TTL (`expires_at`), срок physical retention 180 дней от `created_at`, public soft-delete и плановый `SessionLifecycleService`, физически удаляющий просроченные anonymous sessions и известные artifacts.

`therapist_case` предусмотрен policy и schema, но защищённое назначение и ручное удаление не реализованы. Фактические границы — в [DATA_MAP_CURRENT.md](docs/roadmap/DATA_MAP_CURRENT.md), целевая policy — в [RETENTION_POLICY.md](docs/roadmap/RETENTION_POLICY.md).

## Безопасность и privacy границы

- state-changing browser routes защищены CSRF;
- production web root допускает только `public/index.php` как PHP entry point;
- result token, ответы, отчёты и секреты не должны попадать в логи, fixtures или Git;
- приложение не использует IP как достоверную страну и не вызывает GeoIP API;
- точный IP и user-agent пока собираются и требуют отдельной minimization policy;
- public privacy text не заявляет encryption, отсутствие будущих third parties или немедленное полное физическое удаление.

`ClinicalSafetySignal` извлекает machine-readable BDI item-9 signal, а `CountryResolver` чисто выбирает country hint. Public crisis text, resources reader и browser flow не готовы: их нельзя подменять произвольным текстом или контактами.

## Legacy integrations и целевой контур

`AIInterpretationService`, `PaymentService`, legacy `ApiController` methods и старые AI/payment tables — исторические слои. Они не доказывают готовность оплаты или AI и не должны подключаться к новым public routes.

Новая YooKassa state machine относится к этапу 06. Новый AI flow с отдельным consent, provider boundary, versioned prompts, report audiences и кабинетом терапевта относится к этапу 07. Критерии и release gates — в [ROADMAP.md](ROADMAP.md).

## База данных и миграции

`database/migrations/` — source of truth. `database/schema.sql` — snapshot итоговой схемы, изменяемый осознанно вместе с migration chain. В CI чистая MySQL-проверка использует `composer migrate`.

Таблицы включают tests, test sessions, pair comparisons, activity log, legacy AI/payment records и `crisis_resources`. Нельзя строить новую функцию на legacy финансовых таблицах: clinical и financial records разделяются в этапе 06.

## Проверки и рабочая дисциплина

Полный локальный gate указан в [AGENTS.md](AGENTS.md):

```bash
composer validate --strict --no-check-publish
composer audit
composer test
composer analyse
composer lint
php bin/check-architecture.php
composer baseline:check
```

Актуальные status, evidence и следующий work package — в [STATUS.md](docs/roadmap/STATUS.md), [WORKLOG.md](docs/roadmap/WORKLOG.md) и [CHECKPOINT.md](docs/roadmap/CHECKPOINT.md). Новый модуль относится к этапу 03: текущий контракт имеет специальные случаи и будет заменён Module API v2.
