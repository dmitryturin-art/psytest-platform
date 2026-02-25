# PsyTest Platform - Документация для разработки с ИИ

## 📋 Оглавление

1. [Обзор архитектуры](#обзор-архитектуры)
2. [Структура проекта](#структура-проекта)
3. [Как это работает](#как-это-работает)
4. [Создание нового теста](#создание-нового-теста)
5. [API Reference](#api-reference)
6. [Правила кодирования для ИИ](#правила-кодирования-для-ии)
7. [Частые задачи и решения](#частые-задачи-и-решения)

---

## 🏗️ Обзор архитектуры

### Тип архитектуры
**Модульная MVC-архитектура** с разделением на:
- **Ядро (Core)** - базовые компоненты
- **Модули тестов** - изолированные тестовые методики
- **Контроллеры** - обработка запросов
- **Сервисы** - бизнес-логика (платежи, AI, email)
- **Представление (Views)** - Twig шаблоны

### Ключевые принципы

```
┌─────────────────────────────────────────────────────────┐
│                    PUBLIC (Web Root)                     │
│  index.php → Router → Controllers → Services → Database  │
└─────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────┐
│                     MODULES (Tests)                      │
│  TestModuleInterface ← BaseTestModule ← ConcreteModule   │
└─────────────────────────────────────────────────────────┘
```

### Поток данных

```
User Request
    ↓
public/index.php (Entry Point)
    ↓
Router (Маршрутизация)
    ↓
Controller (Обработка)
    ↓
ModuleLoader → TestModule (Бизнес-логика теста)
    ↓
SessionManager (Сохранение сессии)
    ↓
Database (Хранение)
    ↓
View (Рендеринг Twig)
    ↓
HTML Response
```

---

## 📁 Структура проекта

```
hyptest/
├── 📂 bin/                    # CLI утилиты
│   ├── install-db.php         # Установка БД
│   └── cleanup-sessions.php   # Очистка сессий (cron)
│
├── 📂 config/                 # Конфигурация
│   └── config.php             # Главный конфиг + .env loader
│
├── 📂 core/                   # Ядро системы (НЕ МЕНЯТЬ БЕЗ НУЖДЫ)
│   ├── Database.php           # PDO wrapper, singleton
│   ├── Router.php             # Маршрутизация
│   ├── SessionManager.php     # Управление сессиями
│   ├── ModuleLoader.php       # Загрузка модулей тестов
│   ├── PDFGenerator.php       # DomPDF генерация
│   ├── View.php               # Twig рендеринг
│   ├── Security.php           # Security helpers
│   └── LoggerFactory.php      # Monolog логирование
│
├── 📂 controllers/            # Контроллеры
│   ├── HomeController.php     # Главная, список тестов
│   ├── TestController.php     # Прохождение тестов
│   ├── ResultController.php   # Просмотр результатов
│   └── ApiController.php      # API, webhooks
│
├── 📂 modules/                # Модули тестов
│   ├── TestModuleInterface.php    # Интерфейс для всех тестов
│   ├── BaseTestModule.php         # Базовый класс
│   └── {test-name}/               # Папка теста
│       ├── {TestName}Module.php   # Класс модуля
│       ├── metadata.json          # Метаданные теста
│       └── questions.json         # Вопросы (опционально)
│
├── 📂 services/               # Сервисы
│   ├── PaymentService.php         # ЮMoney интеграция
│   ├── AIInterpretationService.php # OpenRouter AI
│   └── EmailService.php           # Email рассылка
│
├── 📂 templates/              # Twig шаблоны
│   ├── layout.twig            # Базовый макет
│   ├── test-wrapper.twig      # Оболочка теста
│   ├── result-page.twig       # Страница результатов
│   ├── tests-list.twig        # Список тестов
│   └── ...
│
├── 📂 public/                 # Публичная директория (web root)
│   ├── index.php              # Entry point
│   ├── demo.php               # Демо страница
│   ├── css/
│   │   └── main.css           # Основные стили
│   ├── js/
│   │   ├── main.js            # Общие скрипты
│   │   ├── test-taking.js     # Прохождение теста
│   │   └── results.js         # Графики результатов
│   └── uploads/               # Загруженные файлы
│
├── 📂 database/               # База данных
│   └── schema.sql             # SQL схема
│
├── 📂 storage/                # Хранилище (вне web-root)
│   ├── pdfs/                  # Сгенерированные PDF
│   ├── logs/                  # Логи
│   └── cache/                 # Twig кэш
│
├── composer.json              # Зависимости PHP
├── .env                       # Переменные окружения
└── .env.example               # Пример .env
```

---

## ⚙️ Как это работает

### 1. Инициализация приложения

**Файл:** `public/index.php`

```php
// 1. Загрузка конфигурации
$configLoader = require __DIR__ . '/../config.php';

// 2. Подключение autoload
require_once __DIR__ . '/../vendor/autoload.php';

// 3. Инициализация ядра
$db = Database::getInstance();
$router = new Router();
$moduleLoader = (new ModuleLoader(null, $db))->discover();
$sessionManager = new SessionManager($db);

// 4. Регистрация маршрутов
$router->get('/tests', [HomeController::class, 'tests']);
$router->get('/test/{slug}', [TestController::class, 'start']);
// ...

// 5. Dispatch
$response = $router->dispatch();
```

### 2. Маршрутизация

**Файл:** `core/Router.php`

```php
// Поддерживаемые методы
$router->get('/path', $handler);
$router->post('/path', $handler);
$router->put('/path', $handler);
$router->delete('/path', $handler);

// Параметры в URL
$router->get('/test/{slug}', $handler);
// {slug} передаётся в handler как аргумент
```

### 3. Загрузка модулей тестов

**Файл:** `core/ModuleLoader.php`

```php
// discover() сканирует папку modules/
// Для каждой подпапки:
// 1. Ищет {Name}Module.php
// 2. Проверяет реализацию TestModuleInterface
// 3. Регистрирует модуль

$moduleLoader = new ModuleLoader(null, $db);
$moduleLoader->discover();

// Получение модуля
$module = $moduleLoader->getModule('smil');
$metadata = $module->getMetadata();
```

### 4. Жизненный цикл теста

```
1. GET /test/smil
   ↓
   TestController->start('smil')
   ↓
   SessionManager->createSession($testId)
   ↓
   Render test-wrapper.twig с вопросами

2. POST /test/smil/submit
   ↓
   TestController->submit('smil')
   ↓
   SessionManager->getSessionById($id)
   ↓
   SmilModule->calculateResults($answers)
   ↓
   SmilModule->generateInterpretation($scores)
   ↓
   SessionManager->completeSession($id, $results)
   ↓
   Redirect /result/smil/{token}

3. GET /result/smil/{token}
   ↓
   ResultController->show('smil', $token)
   ↓
   SessionManager->getSessionByToken($token)
   ↓
   SmilModule->renderResults($results)
   ↓
   Render result-page.twig
```

---

## 🧩 Создание нового теста

### Шаг 1: Структура папок

Создайте папку для теста в `modules/`:

```
modules/beck-depression/
├── BeckDepressionModule.php   # Основной класс
├── metadata.json              # Метаданные
└── questions.json             # Вопросы (опционально)
```

### Шаг 2: Метаданные (metadata.json)

```json
{
  "slug": "beck-depression",
  "name": "Опросник Бека (BDI)",
  "description": "Шкала депрессии Бека для оценки уровня депрессии",
  "question_count": 21,
  "estimated_time": 5,
  "scales": [
    {"code": "BDI", "name": "Общий балл", "description": "Суммарный показатель"}
  ],
  "version": "1.0",
  "author": "Aaron T. Beck"
}
```

### Шаг 3: Вопросы (questions.json)

```json
[
  {
    "id": 1,
    "text": "Как вы оцениваете своё настроение?",
    "options": [
      {"value": 0, "text": "Я не чувствую грусти"},
      {"value": 1, "text": "Я чувствую грусть"},
      {"value": 2, "text": "Я всё время грущу"},
      {"value": 3, "text": "Я очень несчастен"}
    ],
    "scale": "BDI"
  },
  {
    "id": 2,
    "text": "Как вы смотрите в будущее?",
    "options": [
      {"value": 0, "text": "Я не разочарован"},
      {"value": 1, "text": "Я разочарован"},
      {"value": 2, "text": "Я не жду ничего хорошего"},
      {"value": 3, "text": "Будущее безнадёжно"}
    ],
    "scale": "BDI"
  }
]
```

### Шаг 4: Класс модуля (BeckDepressionModule.php)

```php
<?php
/**
 * Beck Depression Inventory Module
 */

declare(strict_types=1);

namespace PsyTest\Modules\BeckDepression;

use PsyTest\Modules\BaseTestModule;

class BeckDepressionModule extends BaseTestModule
{
    /**
     * Пороговые значения для интерпретации
     */
    protected const THRESHOLDS = [
        'minimal' => ['min' => 0, 'max' => 13],
        'mild' => ['min' => 14, 'max' => 19],
        'moderate' => ['min' => 20, 'max' => 28],
        'severe' => ['min' => 29, 'max' => 63],
    ];

    /**
     * Интерпретации для каждого уровня
     */
    protected const INTERPRETATIONS = [
        'minimal' => 'Депрессия отсутствует или минимальна',
        'mild' => 'Лёгкая депрессия, возможны колебания настроения',
        'moderate' => 'Умеренная депрессия, рекомендуется консультация',
        'severe' => 'Выраженная депрессия, требуется профессиональная помощь',
    ];

    /**
     * {@inheritDoc}
     */
    public function getMetadata(): array
    {
        return array_merge(parent::getMetadata(), [
            'scoring_type' => 'sum', // sum, average, t-score
            'max_score' => 63,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getQuestions(): array
    {
        if ($this->questions === null) {
            $this->questions = $this->loadQuestionsFromJson('questions.json');
        }
        
        return $this->questions;
    }

    /**
     * {@inheritDoc}
     */
    public function calculateResults(array $answers): array
    {
        // Подсчёт суммы баллов
        $totalScore = 0;
        $scaleScores = [];
        
        foreach ($answers as $questionId => $answer) {
            // Находим вопрос и его вес
            $question = $this->findQuestionById((int)$questionId);
            if ($question) {
                $points = $this->getPointsForAnswer($question, $answer);
                $totalScore += $points;
                
                // Группировка по шкалам
                $scale = $question['scale'] ?? 'total';
                $scaleScores[$scale] = ($scaleScores[$scale] ?? 0) + $points;
            }
        }
        
        // Определение уровня
        $level = $this->getLevel($totalScore);
        
        return [
            'total_score' => $totalScore,
            'scale_scores' => $scaleScores,
            'level' => $level,
            'max_score' => self::THRESHOLDS['severe']['max'],
            'answered_count' => count($answers),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function generateInterpretation(array $scores): array
    {
        $level = $scores['level'] ?? 'minimal';
        $totalScore = $scores['total_score'] ?? 0;
        
        return [
            'summary' => sprintf(
                'Ваш результат: %d баллов. %s',
                $totalScore,
                self::INTERPRETATIONS[$level] ?? 'Требуется интерпретация'
            ),
            'level' => $level,
            'level_name' => $this->getLevelName($level),
            'recommendations' => $this->getRecommendations($level),
            'disclaimer' => 'Результат носит ознакомительный характер',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function renderResults(array $results): string
    {
        $totalScore = $results['total_score'] ?? 0;
        $level = $results['level'] ?? 'minimal';
        $maxScore = $results['max_score'] ?? 63;
        
        // Расчёт процента
        $percentage = round(($totalScore / $maxScore) * 100);
        
        $html = '<div class="beck-results">';
        
        // Блок с общим баллом
        $html .= '<div class="score-block">';
        $html .= '<h3>Общий балл</h3>';
        $html .= sprintf('<div class="score-value">%d из %d</div>', $totalScore, $maxScore);
        $html .= sprintf('<div class="score-percentage">%d%%</div>', $percentage);
        $html .= '</div>';
        
        // Визуальная шкала
        $html .= '<div class="severity-scale">';
        $html .= '<div class="scale-segment minimal">0-13</div>';
        $html .= '<div class="scale-segment mild">14-19</div>';
        $html .= '<div class="scale-segment moderate">20-28</div>';
        $html .= '<div class="scale-segment severe">29-63</div>';
        $html .= sprintf('<div class="marker" style="left: %d%%"></div>', $percentage);
        $html .= '</div>';
        
        // Интерпретация
        $html .= '<div class="interpretation">';
        $html .= '<h4>Интерпретация</h4>';
        $html .= sprintf('<p>%s</p>', self::INTERPRETATIONS[$level] ?? '');
        $html .= '</div>';
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsPairMode(): bool
    {
        return false; // BDI не поддерживает парный режим
    }
    
    // ============================================
    // Вспомогательные методы
    // ============================================
    
    /**
     * Найти вопрос по ID
     */
    protected function findQuestionById(int $id): ?array
    {
        $questions = $this->getQuestions();
        foreach ($questions as $question) {
            if ($question['id'] === $id) {
                return $question;
            }
        }
        return null;
    }
    
    /**
     * Получить баллы за ответ
     */
    protected function getPointsForAnswer(array $question, mixed $answer): int
    {
        if (!isset($question['options'])) {
            return 0;
        }
        
        foreach ($question['options'] as $option) {
            if ($option['value'] == $answer || $option['text'] == $answer) {
                return (int) $option['value'];
            }
        }
        
        return 0;
    }
    
    /**
     * Определить уровень по баллам
     */
    protected function getLevel(int $score): string
    {
        foreach (self::THRESHOLDS as $level => $range) {
            if ($score >= $range['min'] && $score <= $range['max']) {
                return $level;
            }
        }
        return 'minimal';
    }
    
    /**
     * Название уровня
     */
    protected function getLevelName(string $level): string
    {
        $names = [
            'minimal' => 'Минимальный',
            'mild' => 'Лёгкий',
            'moderate' => 'Умеренный',
            'severe' => 'Выраженный',
        ];
        return $Names[$level] ?? $level;
    }
    
    /**
     * Рекомендации по уровню
     */
    protected function getRecommendations(string $level): array
    {
        $recommendations = [
            'minimal' => [
                'Поддерживайте здоровый образ жизни',
                'Регулярно занимайтесь физической активностью',
            ],
            'mild' => [
                'Обратите внимание на режим сна',
                'Практикуйте техники релаксации',
                'Рассмотрите консультацию с психологом',
            ],
            'moderate' => [
                'Рекомендуется консультация специалиста',
                'Рассмотрите психотерапию',
                'Обратитесь к врачу для оценки состояния',
            ],
            'severe' => [
                'Необходима профессиональная помощь',
                'Обратитесь к психиатру или психотерапевту',
                'Не откладывайте визит к специалисту',
            ],
        ];
        
        return $recommendations[$level] ?? [];
    }
}
```

### Шаг 5: Регистрация в базе данных

```sql
INSERT INTO tests (name, slug, module_class, description, is_active, sort_order) 
VALUES (
    'Опросник Бека (BDI)',
    'beck-depression',
    'PsyTest\\Modules\\BeckDepression\\BeckDepressionModule',
    'Шкала депрессии Бека для оценки уровня депрессии',
    1,
    2
);
```

Или через CLI:

```bash
php bin/install-db.php  # Пересоздаст БД с тестом
```

### Шаг 6: Проверка

```bash
# Проверка архитектуры
php test-architecture.php

# Проверка модуля
php -r "
require 'vendor/autoload.php';
\$module = new PsyTest\Modules\BeckDepression\BeckDepressionModule();
print_r(\$module->getMetadata());
"
```

---

## 📚 API Reference

### Core Classes

#### Database
```php
// Расположение: core/Database.php
// Singleton, PDO wrapper

$db = Database::getInstance();

// SELECT
$rows = $db->select('SELECT * FROM table WHERE id = ?', [$id]);
$row = $db->selectOne('SELECT * FROM table WHERE id = ?', [$id]);

// INSERT
$id = $db->insert('table', ['column' => 'value']);

// UPDATE
$affected = $db->update('table', ['column' => 'new'], 'id = ?', [$id]);

// DELETE
$deleted = $db->delete('table', 'id = ?', [$id]);

// Transaction
$db->beginTransaction();
try {
    // ...
    $db->commit();
} catch (\Exception $e) {
    $db->rollback();
}
```

#### Router
```php
// Расположение: core/Router.php

$router = new Router();

// Маршруты
$router->get('/path', $handler);
$router->post('/path', $handler);
$router->get('/path/{param}', $handler); // {param} передаётся в handler

// Middleware
$router->middleware(function($method, $uri, &$params) {
    // ...
    return null; // continue
});

// Dispatch
$response = $router->dispatch();
```

#### SessionManager
```php
// Расположение: core/SessionManager.php

$sessionManager = new SessionManager($db);

// Создание сессии
$session = $sessionManager->createSession($testId, [
    'email' => 'user@example.com',
    'ip_address' => '127.0.0.1',
]);

// Получение
$session = $sessionManager->getSessionByToken($token);
$session = $sessionManager->getSessionById($id);

// Сохранение ответов
$sessionManager->saveAnswers($sessionId, $answers);

// Завершение
$sessionManager->completeSession($sessionId, $results);

// Удаление (GDPR)
$sessionManager->deleteSession($sessionId);
```

#### ModuleLoader
```php
// Расположение: core/ModuleLoader.php

$moduleLoader = new ModuleLoader(null, $db);
$moduleLoader->discover(); // Сканировать modules/

// Получение модуля
$module = $moduleLoader->getModule('smil');
$metadata = $moduleLoader->getModuleMetadata('smil');

// Все активные модули
$modules = $moduleLoader->getActiveModules();
```

#### View (Twig)
```php
// Расположение: core/View.php

$view = View::getInstance();

// Рендер
$html = $view->render('template-name', [
    'variable' => 'value',
]);

// Сразу вывод
$view->display('template-name', $data);

// Shared variables
$view->share('key', 'value');
```

### TestModuleInterface

```php
// Расположение: modules/TestModuleInterface.php

interface TestModuleInterface
{
    public function getMetadata(): array;
    public function getQuestions(): array;
    public function calculateResults(array $answers): array;
    public function generateInterpretation(array $scores): array;
    public function renderResults(array $results): string;
    public function supportsPairMode(): bool;
    public function comparePairResults(array $results1, array $results2): array;
}
```

---

## 🤖 Правила кодирования для ИИ

### 1. Структура кода

```php
// ✅ ПРАВИЛЬНО:
declare(strict_types=1);           // Всегда в начале
namespace PsyTest\Modules\Name;    // Правильное пространство
use PsyTest\Core\Database;         // Импорты после namespace

class ClassName                  // PascalCase для классов
{
    private Type $property;      // Типизированные свойства
    
    public function methodName(  // camelCase для методов
        Type $param1,
        Type $param2
    ): ReturnType {              // ReturnType обязателен
        // ...
    }
}

// ❌ НЕПРАВИЛЬНО:
// Нет declare(strict_types=1)
// Нет типизации
// Неправильный namespace
```

### 2. Обработка ошибок

```php
// ✅ ПРАВИЛЬНО:
try {
    $result = $this->db->selectOne($sql, $params);
    if (!$result) {
        return null; // Явная проверка
    }
    return $result;
} catch (\PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    throw $e; // Или возвращаем null
}

// ❌ НЕПРАВИЛЬНО:
// Игнорирование ошибок
// Нет проверок на null
```

### 3. Работа с базой данных

```php
// ✅ ПРАВИЛЬНО:
// Использовать prepared statements
$sql = "SELECT * FROM table WHERE id = :id";
$row = $db->selectOne($sql, ['id' => $id]);

// Проверка существования
if (!$row) {
    return null;
}

// ❌ НЕПРАВИЛЬНО:
// Прямая подстановка (SQL injection!)
$sql = "SELECT * FROM table WHERE id = $id";
```

### 4. Twig шаблоны

```twig
{# ✅ ПРАВИЛЬНО: #}
{# Проверка существования переменной #}
{% if variable is defined and variable %}
    {{ variable|e }}  {# Экранирование #}
{% endif %}

{# Безопасный HTML #}
{{ html_content|raw }}  {# Только доверенный контент #}

{# ❌ НЕПРАВИЛЬНО: #}
{{ variable }}  {# Без проверки #}
{{ user_input|raw }}  {# Опасно! #}
```

### 5. Безопасность

```php
// ✅ ПРАВИЛЬНО:
// CSRF проверка
Security::requireCsrf($_POST['csrf_token'] ?? null);

// Экранирование вывода
echo Security::h($userInput);

// Валидация email
if (!Security::isValidEmail($email)) {
    throw new InvalidArgumentException('Invalid email');
}

// ❌ НЕПРАВИЛЬНО:
// Прямой вывод пользовательских данных
echo $_POST['input'];  {# XSS уязвимость #}
```

### 6. Логирование

```php
// ✅ ПРАВИЛЬНО:
use Monolog\Logger;

$logger = LoggerFactory::getLogger('module');
$logger->info('Action completed', ['data' => $data]);
$logger->error('Error occurred', ['error' => $e->getMessage()]);

// ❌ НЕПРАВИЛЬНО:
echo "Debug: $value";  // В продакшене
error_log($sensitiveData);  // Конфиденциальные данные
```

### 7. Разделение ответственности (Модуль vs Шаблон)

**ВАЖНОЕ ПРАВИЛО:** Не дублируйте контент между модулем и шаблоном!

#### ✅ ПРАВИЛЬНО:

**Модуль теста** (`renderResults()`):
```php
public function renderResults(array $results): string
{
    $html = '<div class="test-results">';
    
    // Рендерит ТОЛЬКО специфичный для теста контент:
    $html .= $this->renderScoreCard($results);        // Карточка баллов
    $html .= $this->renderScaleChart($results);       // График шкал
    $html .= $this->renderSymptomList($results);      // Симптомы
    $html .= $this->renderInterpretation($results);   // Интерпретация теста
    $html .= $this->renderRecommendations($results);  // Рекомендации
    
    // НЕ добавляет общий дисклеймер!
    $html .= '</div>';
    
    return $html;
}
```

**Шаблон** (`result-page.twig`):
```twig
<!-- Results Content -->
<div class="results-content">
    {{ results_html|raw }}  {# Контент из модуля #}
</div>

<!-- Disclaimer -->
<div class="results-disclaimer">
    <p>⚠️ Важно: Результаты данного тестирования носят ознакомительный характер...</p>
</div>
```

#### ❌ НЕПРАВИЛЬНО:

**Модуль теста:**
```php
// НЕ ДЕЛАЙТЕ ТАК:
$html .= '<div class="disclaimer">';
$html .= '<p>⚠️ Важно: Результаты данного тестирования...</p>';
$html .= '</div>';
```

**Шаблон:**
```twig
<!-- НЕ ДЕЛАЙТЕ ТАК: -->
{% if interpretation %}
<div class="interpretation-block">
    <h2>Интерпретация</h2>
    {{ interpretation.summary }}  {# Дублирует то, что уже есть в results_html #}
</div>
{% endif %}
```

#### Принцип единого источника:

| Контент | Где рендерить | Почему |
|---------|--------------|--------|
| Баллы теста | Модуль | Специфично для теста |
| Графики/шкалы | Модуль | Визуализация данных теста |
| Интерпретация | Модуль | Бизнес-логика теста |
| Рекомендации | Модуль | Зависит от результатов теста |
| **Общий дисклеймер** | **Шаблон** | **Одинаковый для всех тестов** |
| **Кнопки действий** | **Шаблон** | **UI, не бизнес-логика** |
| **Хедер/футер** | **Шаблон** | **Общая разметка** |

#### Проверка на дублирование:

Перед добавлением кода задайте вопрос:
- **"Этот контент одинаков для всех тестов?"**
  - ✅ Да → Добавьте в **шаблон** (`result-page.twig`)
  - ❌ Нет → Добавьте в **модуль** (`renderResults()`)

---

## 🔧 Частые задачи и решения

### Задача 1: Добавить новый вопрос в тест

**Файл:** `modules/{test}/questions.json`

```json
{
  "id": 51,
  "text": "Текст вопроса",
  "scale": "SCALE_NAME",
  "direction": "direct"
}
```

**Обновление metadata.json:**
```json
{
  "question_count": 51
}
```

### Задача 2: Изменить логику подсчёта

**Файл:** `modules/{test}/{Test}Module.php`

```php
public function calculateResults(array $answers): array
{
    // Изменить логику здесь
    // Вернуть массив с результатами
}
```

### Задача 3: Добавить новый шаблон

**Файл:** `templates/new-template.twig`

```twig
{% extends "layout.twig" %}

{% block title %}Заголовок{% endblock %}

{% block content %}
    <div class="content">
        {{ variable }}
    </div>
{% endblock %}
```

**Использование в контроллере:**
```php
echo $this->view->render('new-template', [
    'variable' => 'value',
]);
```

### Задача 4: Добавить API endpoint

**Файл:** `public/index.php`

```php
$router->get('/api/test/{slug}', [ApiController::class, 'testInfo']);
```

**Файл:** `controllers/ApiController.php`

```php
public function testInfo(string $slug): array
{
    $module = $this->moduleLoader->getModule($slug);
    return [
        'success' => true,
        'data' => $module->getMetadata(),
    ];
}
```

### Задача 5: Работа с сессиями

```php
// Создать сессию
$session = $sessionManager->createSession($testId);

// Сохранить промежуточные ответы
$sessionManager->saveAnswers($sessionId, $answers);

// Завершить с результатами
$sessionManager->completeSession($sessionId, $results);

// Получить по токену (для страницы результатов)
$session = $sessionManager->getSessionByToken($token);

// Удалить (GDPR)
$sessionManager->deleteSession($sessionId);
```

### Задача 6: Генерация PDF

```php
$pdfGenerator = new PDFGenerator();

// Тестовые результаты
$pdfPath = $pdfGenerator->generateTestResult($session, $test, $html);

// AI интерпретация
$pdfPath = $pdfGenerator->generateAIInterpretation($session, $test, $text);

// Отдача файла
header('Content-Type: application/pdf');
readfile(__DIR__ . '/../' . $pdfPath);
```

### Задача 7: Интеграция с AI (OpenRouter)

```php
$aiService = new AIInterpretationService();

$result = $aiService->generateInterpretation($session, $test);

// $result['text'] - текст интерпретации
// $result['pdf_path'] - путь к PDF
```

### Задача 8: Платежи (ЮMoney)

```php
$paymentService = new PaymentService();

// Создание платежа
$payment = $paymentService->createPayment($session, 499);

// Перенаправление на оплату
header('Location: ' . $payment['payment_url']);

// Webhook обработчик в ApiController::yoomoneyWebhook()
```

---

## 📝 Чек-лист для нового теста

- [ ] Создана папка `modules/{test-name}/`
- [ ] Создан `{TestName}Module.php` с реализацией интерфейса
- [ ] Создан `metadata.json` с описанием
- [ ] Создан `questions.json` с вопросами (если нужно)
- [ ] Модуль расширяет `BaseTestModule`
- [ ] Реализованы все методы интерфейса
- [ ] Добавлена запись в таблицу `tests`
- [ ] Протестировано через `test-architecture.php`
- [ ] Проверено прохождение теста в браузере
- [ ] Проверены результаты и интерпретация

---

## 🆘 Отладка

### Включить debug режим

**.env:**
```env
APP_DEBUG=true
```

### Просмотр логов

```bash
tail -f storage/logs/app.log
tail -f storage/logs/server.log
```

### Проверка архитектуры

```bash
php test-architecture.php
```

### Тестирование модуля

```bash
php -r "
require 'vendor/autoload.php';
\$module = new PsyTest\Modules\Smil\SmilModule();
print_r(\$module->getMetadata());
\$results = \$module->calculateResults([1 => true, 2 => false]);
print_r(\$results);
"
```

---

## 📞 Поддержка ИИ

При разработке с помощью ИИ:

1. **Всегда указывайте контекст:**
   - "Я работаю в проекте PsyTest Platform"
   - "Использую PHP 8.5, Twig 3, MySQL 9"
   - "Следую PSR-4 и PSR-12"

2. **Предоставляйте примеры:**
   - "Как SmilModule реализует calculateResults"
   - "Как HomeController использует ModuleLoader"

3. **Проверяйте результат:**
   - Запустите `test-architecture.php`
   - Проверьте в браузере
   - Посмотрите логи

4. **Документируйте изменения:**
   - Что изменено
   - Какие файлы затронуты
   - Как тестировать

---

**Версия документации:** 1.0  
**Последнее обновление:** 2026-02-25
