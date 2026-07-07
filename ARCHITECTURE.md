# ARCHITECTURE.md — PsyTest Platform

## 1. Обзор

PsyTest Platform — веб-платформа для проведения и обработки психологических тестирований. Поддерживает батарею из 4 психометрических методик (СМИЛ, BDI, HADS, BAI) с автоматическим расчётом шкал, валидацией, интерпретацией и генерацией PDF-отчётов.

**Стек технологий:**

| Компонент | Технология | Версия |
|-----------|-----------|--------|
| Язык | PHP | 8.1+ |
| Шаблонизатор | Twig | 3.x |
| БД | MySQL | 8.x |
| Frontend графика | Chart.js | 4.4 (CDN) |
| Тестирование | PHPUnit | 10.5 |
| AI-интерпретации | OpenRouter / DeepSeek | API |

Архитектура — собственный MVC без использования фреймворков. Паттерны: Singleton (Database, View), Factory (LoggerFactory), Strategy (модули тестов), VO (ResultSection).

---

## 2. Структура проекта

```
hyptest/
├── public/                    # Document root
│   ├── index.php              # Entry point, autoload, bootstrap
│   └── assets/                # CSS, JS, изображения
├── config/                    # Конфигурация БД (phinx)
├── core/                      # MVC фреймворк (Router, View, Database, ...)
├── controllers/               # Контроллеры
├── services/                  # Сервисы (AI, Email, Payment)
├── modules/                   # Психометрические модули
│   ├── smil/                  # СМИЛ (566 вопросов)
│   ├── beck-depression/       # BDI (21 вопрос)
│   ├── hads/                  # HADS (14 вопросов)
│   └── beck-anxiety/          # BAI (21 вопрос)
├── templates/                 # Twig-шаблоны
├── database/                  # SQL-схемы, миграции
├── tests/                     # PHPUnit тесты
│   ├── Unit/                  # Юнит-тесты
│   ├── Integration/           # Интеграционные тесты
│   └── Fixtures/              # Тестовые JSON-данные
├── vendor/                    # Composer dependencies
├── composer.json
├── phinx.php                  # Миграции Phinx
├── config.php                 # Главный конфиг (anonymous class + .env)
├── ARCHITECTURE.md            # Данный файл
├── DEVELOPMENT.md             # Руководство разработчика
└── README.md                  # Описание проекта
```

---

## 3. Архитектура

### Жизненный цикл запроса

```
HTTP Request
    │
    ▼
public/index.php
    ├── autoload (composer)
    ├── bootstrap
    │   ├── Config::instance() (config.php + .env)
    │   ├── Database::getInstance() (Singleton, MySQL)
    │   └── SessionManager::getInstance() (Singleton, sessions)
    │
    ▼
Router::dispatch($uri, $method)
    ├── Parse route → {controller, action, params}
    ├── Security::check()
    └── Container resolution
    │
    ▼
Controller::action()
    ├── Security::validateCsrf()
    ├── ModuleLoader::load($slug) — если нужен модуль
    ├── Business logic
    │   ├── TestModule::calculateResults()
    │   ├── AIInterpretationService (опционально)
    │   └── PDFGenerator (опционально)
    │
    ▼
View::render($template, $data)
    └── Twig 3.x → HTML response
```

### Паттерн модулей

Каждый тест реализован как самостоятельный модуль, загружаемый через `ModuleLoader`. Модуль реализует `TestModuleInterface` и наследует `BaseTestModule`. Это позволяет добавлять новые тесты без изменения контроллеров.

```
TestModuleInterface (10 методов)
        ▲
BaseTestModule (абстрактный, ~280 строк)
        ▲
┌───────┴────────┬────────────────┬─────────────┐
SmilModule       BeckDepression   HadsModule   BeckAnxiety
```

---

## 4. Маршрутизация

Маршруты определяются в `core/Router.php` (~225 строк). Поддерживает GET/POST с параметрами.

| # | HTTP | Маршрут | Controller | Action | Описание |
|---|------|---------|-----------|--------|----------|
| 1 | GET | `/` | HomeController | index | Главная страница |
| 2 | GET | `/tests` | HomeController | testsList | Список доступных тестов |
| 3 | GET | `/test/{slug}` | TestController | start | Начало прохождения теста |
| 4 | POST | `/test/{slug}/save` | TestController | save | Сохранение ответа (AJAX) |
| 5 | POST | `/test/{slug}/submit` | TestController | submit | Завершение теста |
| 6 | GET | `/result/{token}` | ResultController | show | Просмотр результатов |
| 7 | GET | `/result/{token}/pdf` | ResultController | pdf | Генерация PDF |
| 8 | DELETE | `/result/{token}` | ResultController | delete | Удаление результатов |
| 9 | GET | `/result/{token}/interpretation` | ResultController | interpretation | AI-интерпретация |
| 10 | POST | `/result/{token}/interpretation/generate` | ResultController | generateInterpretation | Запуск генерации |
| 11 | GET | `/result/{token}/profile` | ResultController | showProfile | Профиль СМИЛ |
| 12 | POST | `/result/{token}/delete` | ResultController | deleteConfirm | Подтверждение удаления |
| 13 | GET | `/api/health` | ApiController | health | Health-check |
| 14 | POST | `/api/yoomoney/webhook` | ApiController | yoomoneyWebhook | Webhook оплаты |
| 15 | GET | `/api/yoomoney/success` | ApiController | paymentSuccess | Успешная оплата |
| 16 | GET | `/privacy` | HomeController | privacy | Политика конфиденциальности |
| 17 | GET | `/terms` | HomeController | terms | Условия использования |
| 18 | POST | `/test/{slug}/demographics` | TestController | saveDemographics | Сохранение демографии |
| 19 | GET | `/result/{token}/resend` | ResultController | resendEmail | Повторная отправка email |
| 20 | GET | `/admin` | HomeController | admin | Админ-панель (базовая) |

---

## 5. Core классы

### Router — `core/Router.php` (~225 строк)

```php
class Router
{
    private array $routes = [];

    public function get(string $path, string $handler): void;
    public function post(string $path, string $handler): void;
    public function delete(string $path, string $handler): void;
    public function dispatch(string $uri, string $method): mixed;
    private function parseRoute(string $route, string $uri): ?array;
}
```

Маршруты с параметрами: `/test/{slug}`, `/result/{token}` — извлекаются через `parseRoute()` с поддержкой параметров в URI.

### Database — `core/Database.php` (~241 строк)

```php
class Database      // Singleton
{
    private static ?Database $instance = null;
    private PDO $pdo;

    public static function getInstance(): Database;
    private function __construct(array $config);   // PDO + MySQL
    public function getConnection(): PDO;
    public function query(string $sql, array $params = []): PDOStatement;
    public function fetch(string $sql, array $params = []): ?array;
    public function fetchAll(string $sql, array $params = []): array;
    public function insert(string $table, array $data): string;  // lastInsertId
    public function update(string $table, array $data, string $where, array $params = []): int;
    public function delete(string $table, string $where, array $params = []): int;
    public function transaction(callable $callback): mixed;
}
```

### View — `core/View.php` (~172 строки)

```php
class View         // Singleton + Twig 3.x
{
    private static ?View $instance = null;
    private Twig\Environment $twig;

    public static function getInstance(): View;
    private function __construct(string $templateDir);
    public function render(string $template, array $data = []): string;
}
```

Twig-окружение: кеширование (управляется `isDebug()`), пользовательские функции `csrf_field()` и `asset()`.

### ModuleLoader — `core/ModuleLoader.php` (~226 строк)

```php
class ModuleLoader
{
    public function load(string $slug): ?TestModuleInterface;
    public function discover(): array;                  // все зарегистрированные модули
    public function getModuleMetadata(string $slug): ?array;
    public function registerModule(string $slug, string $className): void;
    private function validateModule(TestModuleInterface $module): bool;
}
```

Обнаружение модулей: сканирует `modules/{slug}/`, ищет `{Name}Module.php` + `metadata.json` + `questions.json`.

### SessionManager — `core/SessionManager.php` (~375 строк)

```php
class SessionManager   // Singleton
{
    private static ?SessionManager $instance = null;

    public static function getInstance(): SessionManager;
    public function start(): void;
    public function set(string $key, mixed $value): void;
    public function get(string $key, mixed $default = null): mixed;
    public function remove(string $key): void;
    public function regenerateId(): void;
    public function destroy(): void;
    public function isValid(int $ttlDays): bool;      // проверка TTL сессии
}
```

### Security — `core/Security.php` (~334 строки)

Все методы статические — утилитарный класс.

```php
class Security
{
    public static function generateToken(): string;        // CSRF token
    public static function validateToken(string $token): bool;
    public static function check(): void;                   // middleware: CSRF + session
    public static function sanitize(string $input): string;
    public static function validateCsrf(): void;
    public static function generateSessionToken(): string;  // публичный токен результатов
    public static function hashPassword(string $password): string;
    public static function verifyPassword(string $password, string $hash): bool;
}
```

### LoggerFactory — `core/LoggerFactory.php` (~103 строки)

```php
class LoggerFactory   // Factory pattern
{
    public static function create(string $channel = 'app', string $level = 'debug'): Logger;
    public static function createFileLogger(string $path, string $level = 'debug'): Logger;
    public static function createNullLogger(): Logger;
}
```

Использует Monolog. Формат логов: `[%datetime%] %channel%.%level_name%: %message%`.

### PDFGenerator — `core/PDFGenerator.php` (~388 строк)

```php
class PDFGenerator
{
    public function __construct();
    public function generateResultPdf(array $sessionData, TestModuleInterface $module): string;  // binary PDF
    public function generateFromHtml(string $html, array $options = []): string;
    private function buildHtml(array $data, string $css): string;
}
```

Генерирует PDF-отчёты с профилем шкал, интерпретацией и рекомендациями.

---

## 6. Контроллеры

### BaseController — `controllers/BaseController.php` (~93 строки)

```php
abstract class BaseController
{
    protected Database $db;
    protected View $view;
    protected SessionManager $session;

    public function __construct();
    protected function json(array $data, int $code = 200): void;
    protected function redirect(string $url): void;
    protected function render(string $template, array $data = []): void;
}
```

### TestController — `controllers/TestController.php` (~225 строк)

Основной контроллер прохождения тестов.

```php
class TestController extends BaseController
{
    public function start(string $slug): void;           // GET /test/{slug}
    public function save(string $slug): void;            // POST /test/{slug}/save (AJAX)
    public function submit(string $slug): void;          // POST /test/{slug}/submit
    public function saveDemographics(string $slug): void; // POST /test/{slug}/demographics
}
```

**`start()`**: создаёт `test_sessions` с UUID и `session_token`, загружает вопросы модуля, рендерит `test-wrapper.twig`.

**`save()`**: принимает JSON с ответом, валидирует, сохраняет в `answers` (JSON-столбец), возвращает success/fail.

**`submit()`**: финализирует сессию, вызывает `calculateResults()`, сохраняет `calculated_results`, редиректит на `/result/{token}`.

### ResultController — `controllers/ResultController.php` (~310 строк)

```php
class ResultController extends BaseController
{
    public function show(string $token): void;                    // GET /result/{token}
    public function pdf(string $token): void;                      // GET /result/{token}/pdf
    public function delete(string $token): void;                   // DELETE /result/{token}
    public function deleteConfirm(string $token): void;            // POST /result/{token}/delete
    public function interpretation(string $token): void;           // GET /result/{token}/interpretation
    public function generateInterpretation(string $token): void;    // POST .../generate
    public function showProfile(string $token): void;               // GET /result/{token}/profile
    public function resendEmail(string $token): void;               // GET /result/{token}/resend
}
```

Доступ по публичному `session_token` — не требует авторизации. GDPR soft-delete через `delete()`.

### HomeController — `controllers/HomeController.php` (~162 строки)

```php
class HomeController extends BaseController
{
    public function index(): void;           // GET /
    public function testsList(): void;       // GET /tests
    public function privacy(): void;         // GET /privacy
    public function terms(): void;            // GET /terms
    public function admin(): void;           // GET /admin
}
```

### ApiController — `controllers/ApiController.php` (~206 строк)

```php
class ApiController extends BaseController
{
    public function health(): void;                  // GET /api/health
    public function yoomoneyWebhook(): void;         // POST /api/yoomoney/webhook
    public function paymentSuccess(): void;          // GET /api/yoomoney/success
}
```

Webhook YooMoney: валидация подписи (SHA-256 + WebhookSecret), проверка payment_status, обновление `payment_transactions`.

---

## 7. Сервисы

### AIInterpretationService — `services/AIInterpretationService.php` (~272 строки)

```php
class AIInterpretationService
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'deepseek/deepseek-chat');
    public function generateInterpretation(array $results, string $testType): string;
    public function generateSummary(array $results): string;
    private function buildPrompt(array $results, string $testType): string;
    private function callApi(string $prompt): string;
    private function parseResponse(string $response): string;
}
```

Провайдер: OpenRouter API. Модель: DeepSeek. Промпт формируется на основе результатов теста с инструкциями на русском языке.

### PaymentService — `services/PaymentService.php` (~256 строк)

```php
class PaymentService
{
    private string $shopId;
    private string $apiKey;
    private string $webhookSecret;

    public function __construct(string $shopId, string $apiKey, string $webhookSecret);
    public function createPayment(float $amount, string $description, string $orderId): array;
    public function verifyWebhook(array $payload, string $signature): bool;
    public function getPaymentStatus(string $paymentId): ?array;
    private function generateSignature(array $data): string;
}
```

Интеграция с YooMoney: создание платежа, верификация вебхуков, проверка статуса.

### EmailService — `services/EmailService.php` (~200 строк)

```php
class EmailService
{
    private string $smtpHost;
    private int $smtpPort;
    private string $fromEmail;
    private string $fromName;

    public function __construct();
    public function sendResultEmail(string $to, array $sessionData, string $pdfPath): bool;
    public function sendNotification(string $to, string $subject, string $body): bool;
    private function createTransport(): \Symfony\Component\Mailer\Transport\TransportInterface;
}
```

Отправка результатов по email с PDF-вложением. Transport: Symfony Mailer.

---

## 8. Система модулей

### TestModuleInterface

```php
interface TestModuleInterface
{
    public function getSlug(): string;
    public function getName(): string;
    public function getDescription(): string;
    public function getQuestions(): array;
    public function calculateResults(array $answers): array;
    public function getScaleCount(): int;
    public function getMaxScore(): int;
    public function getInterpretation(array $results): array;
    public function isValid(array $results): bool;
    public function buildInterpretationOutput(array $results): array;
}
```

### BaseTestModule — `modules/BaseTestModule.php` (~280 строк)

Абстрактный класс с базовой реализацией общих методов. Каждый модуль наследует его и переопределяет специфичную логику расчёта.

```php
abstract class BaseTestModule implements TestModuleInterface
{
    protected string $slug;
    protected array $metadata;
    protected array $questions;
    protected array $norms = [];

    public function __construct();
    protected function loadMetadata(): void;         // metadata.json
    protected function loadQuestions(): void;          // questions.json
    protected function loadNorms(string $file): void;  // JSON нормы
    abstract protected function calculate(array $answers): array;
    public function calculateResults(array $answers): array;
    public function isValid(array $results): bool;
    public function buildInterpretationOutput(array $results): array;
}
```

### ResultSection — `core/ResultSection.php` (~27 строк)

Value Object для структурирования результатов теста.

```php
class ResultSection
{
    public const TYPE_VALIDITY = 'validity';
    public const TYPE_SCALE = 'scale';
    public const TYPE_INTERPRETATION = 'interpretation';
    public const TYPE_RECOMMENDATION = 'recommendation';
    public const TYPE_INDEX = 'index';
    public const TYPE_CHART = 'chart';
    public const TYPE_BADGE = 'badge';
    public const TYPE_PROFILE = 'profile';

    public function __construct(
        string $type,
        string $title,
        array $data = [],
        int $order = 0
    );

    public function getType(): string;
    public function getTitle(): string;
    public function getData(): array;
    public function getOrder(): int;
    public function toArray(): array;
}
```

### Конвенция модулей

```
modules/{slug}/
├── {Name}Module.php       # Класс модуля
├── metadata.json           # Метаданные (имя, описание, версия)
├── questions.json          # Вопросы
└── Scoring/                # Папка с подсчётами (для сложных модулей)
    ├── RawScoreCalculator.php
    ├── TScoreCalculator.php
    ├── ValidityAssessor.php
    └── AdditionalScalesCalculator.php
```

---

## 9. Модуль СМИЛ

Модуль `modules/smil/SmilModule.php` — крупнейший модуль платформы. Реализует адаптированный тест СМИЛ (MMPI-2) по нормам Собчик (2003).

### Характеристики

- **566 вопросов** (извлекаются из `questions-566-full.json`)
- **27 контрольных вопросов** (Validity)
- **Шкала 5** — разделение по полу: 5M (мужские), 5F (женские)
- **~200 дополнительных шкал**

### Пайплайн подсчёта

```
Ответы (answers JSON)
    │
    ▼
RawScoreCalculator (~126 строк)
    ├── Группировка ответов по шкалам
    ├── Учет reverse-scored вопросов
    └── Суммирование сырых баллов
    │
    ▼
TScoreCalculator (~58 строк)
    ├── Нормы Собчик 2003 (basic_scales_norms.json)
    ├── K-коррекция: T = 50 + 10 × K × Z_raw
    └── Ограничение T ∈ [20, 100]
    │
    ▼
ValidityAssessor (~101 строк)
    ├── 27 контрольных вопросов
    ├── Выявление случайных, неискренних ответов
    └── Флаг: valid / invalid / questionable
    │
    ▼
AdditionalScalesCalculator (~79 строк)
    ├── additional-scales-norms.json
    ├── ~200 дополнительных шкал
    └── T-преобразование для каждой
    │
    ▼
buildInterpretationOutput()
    ├── interpretations.json
    ├── Определение типа профиля
    └── Формирование ResultSection[]
```

### T-Score формула

```
Z = (RawScore - M) / SD
T_corrected = 50 + 10 × (Z + K × K_weight)
T_final = clamp(T_corrected, 20, 100)
```

### Профильные типы

Модуль определяет профильный тип на основе сочетания ведущих шкал. Типы и их коды определены в `interpretations.json`.

### Файлы данных

| Файл | Описание |
|------|----------|
| `questions-566-full.json` | Полный набор 566 вопросов |
| `basic_scales_norms.json` | Нормы Собчик для базовых шкал (M, SD, K-вес) |
| `additional-scales-norms.json` | Нормы для ~200 дополнительных шкал |
| `interpretations.json` | Текстовые интерпретации по диапазонам T-баллов |

---

## 10. Модуль BDI (Beck Depression Inventory)

`modules/beck-depression/BeckDepressionModule.php`

- **21 вопрос**
- **Шкала ответов**: 0–3 (отсутствие → тяжёлый симптом)
- **Суммарный балл**: Σ(ответы), max = 63

### Уровни депрессии

| Уровень | Баллы | Ключ |
|---------|-------|------|
| Минимальная | 0–13 | `minimal` |
| Лёгкая | 14–19 | `mild` |
| Умеренная | 20–28 | `moderate` |
| Тяжёлая | 29–63 | `severe` |

---

## 11. Модуль HADS (Hospital Anxiety and Depression Scale)

`modules/hads/HadsModule.php`

- **14 вопросов**
- **Шкала ответов**: 0–3
- **Нечётные вопросы** → Подшкала тревожности (Anxiety)
- **Чётные вопросы** → Подшкала депрессии (Depression)

### Уровни

| Уровень | Баллы | Описание |
|---------|-------|----------|
| Норма | 0–7 | `normal` |
| Субклинический | 8–10 | `subclinical` |
| Клинический | 11–21 | `clinical` |

---

## 12. Модуль BAI (Beck Anxiety Inventory)

`modules/beck-anxiety/BeckAnxietyModule.php`

- **21 вопрос**
- **Шкала ответов**: 0–3
- **Суммарный балл**: Σ(ответы), max = 63

### Уровни тревожности

| Уровень | Баллы | Ключ |
|---------|-------|------|
| Минимальная | 0–21 | `minimal` |
| Умеренная | 22–35 | `moderate` |
| Высокая | 36–63 | `high` |

---

## 13. База данных

### Схема: 6 таблиц

```sql
-- Основные таблицы

CREATE TABLE tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    module_class VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE test_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    session_token CHAR(36) NOT NULL,          -- UUID, публичный доступ
    demographics JSON,                          -- пол, возраст и пр. (зависит от модуля)
    answers JSON,                               -- ответы на вопросы
    calculated_results JSON,                   -- результат calculateResults()
    is_completed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP,
    FOREIGN KEY (test_id) REFERENCES tests(id)
);

CREATE TABLE pair_comparisons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    comparison_type VARCHAR(50),
    comparison_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES test_sessions(id)
);

CREATE TABLE ai_interpretations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    model VARCHAR(100),
    prompt TEXT,
    response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES test_sessions(id)
);

CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT,
    action VARCHAR(100),
    details JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES test_sessions(id)
);

CREATE TABLE payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT,
    yoomoney_payment_id VARCHAR(100),
    amount DECIMAL(10, 2),
    status VARCHAR(50),            -- pending / succeeded / failed
    webhook_payload JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES test_sessions(id)
);
```

### Ключевые моменты

- `session_token` — UUID CHAR(36) для публичного доступа без авторизации
- `demographics`, `answers`, `calculated_results` — JSON-столбцы, формат зависит от модуля
- Схема также доступна в `database/schema.sql`
- Миграции управляются через Phinx (`phinx.php`)

---

## 14. Шаблоны Twig

### Основные шаблоны

| Шаблон | Назначение | Строк |
|--------|-----------|-------|
| `layout.twig` | Базовый layout (head, nav, footer) | ~72 |
| `test-wrapper.twig` | Процесс прохождения теста | ~166 |
| `result-layout.twig` | Страница результатов | ~114 |
| `tests-list.twig` | Каталог тестов | ~57 |

### Блоки result-layout.twig

```
{% block validity %}         — Валидность результатов (QC, флаги)
{% block profile_chart %}    — График профиля (Chart.js)
{% block scales_table %}     — Таблица шкал с T-баллами
{% block interpretation %}   — Текстовая интерпретация
{% block recommendations %}  — Рекомендации
{% block indices %}          — Индексы и дополнительные шкалы
{% block score_badge %}       — Бейдж суммарного балла (BDI/HADS/BAI)
{% block _delete_modal %}    — Модальное окно удаления (GDPR)
```

### Пользовательские функции Twig

```twig
{{ csrf_field() }}                  {# Hidden input с CSRF-тoken #}
{{ asset('css/main.css') }}        {# /assets/css/main.css с кешированием #}
```

---

## 15. Frontend

### CSS — `public/assets/css/main.css` (~2555 строк)

- CSS Custom Properties (переменные)
- Mobile-first подход
- Container max-width: 1200px
- Брейкпоинты: 768px, 1024px
- Адаптивный дизайн для всех устройств

### JavaScript

| Файл | Строки | Назначение |
|------|--------|------------|
| `test-taking.js` | ~470 | Auto-advance по вопросам, демография, auto-save (AJAX) |
| `results.js` | ~98 | Интерактивность страницы результатов |
| `smil-profile-classic.js` | ~202 | Отрисовка профиля СМИЛ (Chart.js) |

### Графика

Chart.js 4.4 подключён через CDN. Используется для:
- Профиль СМИЛ (line chart с диапазонами нормы)
- Сравнение шкал (bar chart)

---

## 16. Тесты

PHPUnit 10.5, PSR-4 автозагрузка: `PsyTest\Tests\`

### Метрики

| Метрика | Значение |
|---------|----------|
| Тестов | 65 |
| Утверждений | 894 |
| Файлов | 12 |

### Файлы тестов

```
tests/
├── Unit/
│   ├── RouterTest.php
│   ├── DatabaseTest.php
│   ├── SessionManagerTest.php
│   ├── SecurityTest.php
│   ├── ViewTest.php
│   └── ModuleLoaderTest.php
├── Integration/
│   ├── SmilModuleTest.php
│   ├── BeckDepressionTest.php
│   ├── HadsModuleTest.php
│   └── BeckAnxietyTest.php
└── Fixtures/
    ├── questions-sample.json
    ├── norms-sample.json
    ├── smil-results.json
    ├── bdi-results.json
    ├── hads-results.json
    └── bai-results.json
```

### Запуск

```bash
vendor/bin/phpunit                    # все тесты
vendor/bin/phpunit --testsuite Unit   # только юнит-тесты
vendor/bin/phpunit --testsuite Integration  # только интеграционные
```

---

## 17. Конфигурация

### `config.php` — anonymous class + .env parser

```php
// config.php
return new class {
    private array $env = [];

    public function __construct() {
        $this->env = parse_ini_file(__DIR__ . '/.env');
    }

    public function db(): array {
        return [
            'host' => $this->env['DB_HOST'] ?? 'localhost',
            'port' => $this->env['DB_PORT'] ?? '3306',
            'database' => $this->env['DB_NAME'] ?? 'hyptest',
            'username' => $this->env['DB_USER'] ?? 'root',
            'password' => $this->env['DB_PASS'] ?? '',
        ];
    }

    public function isDebug(): bool {
        return ($this->env['APP_DEBUG'] ?? 'false') === 'true';
    }

    public function isProduction(): bool {
        return ($this->env['APP_ENV'] ?? 'production') === 'production';
    }

    public function csrfEnabled(): bool {
        return ($this->env['CSRF_ENABLED'] ?? 'true') === 'true';
    }

    public function sessionTtlDays(): int {
        return (int)($this->env['SESSION_TTL_DAYS'] ?? '30');
    }

    // YooMoney
    public function yoomoneyShopId(): string;
    public function yoomoneyApiKey(): string;
    public function yoomoneyWebhookSecret(): string;

    // OpenRouter / DeepSeek
    public function openrouterApiKey(): string;
    public function openrouterModel(): string;
};
```

### Переменные окружения (.env)

```
APP_ENV=production|development
APP_DEBUG=false|true
DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
CSRF_ENABLED=true|false
SESSION_TTL_DAYS=30
YOOMONEY_SHOP_ID, YOOMONEY_API_KEY, YOOMONEY_WEBHOOK_SECRET
OPENROUTER_API_KEY, OPENROUTER_MODEL=deepseek/deepseek-chat
SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, MAIL_FROM
```

---

## 18. Как добавить новый тест

**7 шагов:**

1. **Создать директорию модуля:**

   ```bash
   mkdir modules/my-test
   ```

2. **Создать `metadata.json`:**

   ```json
   {
     "slug": "my-test",
     "name": "Мой Тест",
     "description": "Описание теста",
     "version": "1.0.0",
     "question_count": 20,
     "scale_count": 5,
     "max_score": 80
   }
   ```

3. **Создать `questions.json`:**

   ```json
   [
     {
       "id": 1,
       "text": "Текст вопроса",
       "options": ["Вариант 0", "Вариант 1", "Вариант 2", "Вариант 3"],
       "scale": "scale_a",
       "reverse": false
     }
   ]
   ```

4. **Создать класс модуля:**

   ```php
   <?php
   namespace PsyTest\Modules\MyTest;

   use PsyTest\Core\BaseTestModule;

   class MyTestModule extends BaseTestModule
   {
       protected function calculate(array $answers): array
       {
           // Логика подсчёта
       }

       public function getInterpretation(array $results): array
       {
           // Логика интерпретации
       }
   }
   ```

5. **Зарегистрировать в БД:**

   ```sql
   INSERT INTO tests (slug, name, description, module_class, is_active)
   VALUES ('my-test', 'Мой Тест', 'Описание', 'PsyTest\\Modules\\MyTest\\MyTestModule', 1);
   ```

6. **Написать PHPUnit тесты:**

   ```php
   class MyTestModuleTest extends TestCase
   {
       // тесты calculateResults(), isValid(), getInterpretation()
   }
   ```

7. **Проверить:**

   ```bash
   vendor/bin/phpunit                      # все тесты проходят
   # Откройте /tests → новый тест в списке
   # Пройдите тест → /result/{token} → результат
   ```

---

## 19. Ключевые архитектурные решения

| Решение | Обоснование |
|---------|-------------|
| **Session tokens для публичного доступа** | Результаты доступны по UUID-ссылке без авторизации. Удобно для деления результатами и email-отправки. |
| **ResultSection VO** | Унифицированная структура данных результатов для шаблонов. Позволяет модулям возвращать результаты в любом порядке с типизацией. |
| **Demographics per module** | Демографические данные (пол, возраст) специфичны для модуля. СМИЛ требует пол для шкалы 5; BDI/HADS/BAI — опционально. |
| **GDPR soft-delete** | Удаление результатов через DELETE-эндпоинт. Данные помечаются как удалённые, не удаляются физически. |
| **Нормы Собчик 2003 для СМИЛ** | Используются русифицированные нормы (M, SD, K-вес) из руководства Собчик 2003 для T-преобразования. |
| **Custom MVC без фреймворка** | Минимум зависимостей, полный контроль над запросами, простой деплой. Twig — единственная внешняя зависимость для шаблонов. |
| **JSON-столбцы для answers/results** | Гибкая схема данных: каждый модуль хранит результаты в своём формате. MySQL JSON functions для запросов. |
| **Chart.js через CDN** | Не требует сборки frontend. Профили СМИЛ строятся динамически в браузере. |
