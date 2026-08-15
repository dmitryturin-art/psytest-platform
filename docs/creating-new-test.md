# Руководство по созданию нового психологического теста

**Версия:** 1.0  
**Дата:** 26 февраля 2026  
**Для:** Разработчиков

---

## 📋 Содержание

1. [Введение](#введение)
2. [Быстрый старт](#быстрый-старт)
3. [Детальное руководство](#детальное-руководство)
4. [Примеры](#примеры)
5. [Чек-лист](#чек-лист)
6. [FAQ](#faq)

---

## 🎯 Введение

Это руководство поможет вам создать новый психологический тест для PsyTest Platform. Процесс занимает 30-60 минут для простого теста и 2-4 часа для сложного.

### Что вам понадобится

- ✅ Знание PHP 8.3+
- ✅ Понимание психометрии (базовое)
- ✅ Текст вопросов теста
- ✅ Алгоритм расчета результатов
- ✅ Интерпретации шкал

### Типы тестов

| Тип | Сложность | Пример | Время |
|-----|-----------|--------|-------|
| Простой | ⭐ | Beck Anxiety | 30 мин |
| Средний | ⭐⭐ | Big Five | 1-2 часа |
| Сложный | ⭐⭐⭐ | СМИЛ (MMPI) | 4+ часа |

---

## ⚡ Быстрый старт

### Шаг 1: Создайте структуру

```bash
# Создайте директорию модуля
mkdir -p modules/my-test

# Создайте необходимые файлы
cd modules/my-test
touch MyTestModule.php metadata.json questions.json README.md
```

### Шаг 2: Заполните метаданные

**Файл:** `metadata.json`

```json
{
  "slug": "my-test",
  "name": "Мой психологический тест",
  "description": "Краткое описание теста",
  "question_count": 20,
  "estimated_time": 10,
  "scales": [
    {
      "code": "A",
      "name": "Шкала A",
      "description": "Описание шкалы A"
    }
  ],
  "requires_demographics": {
    "gender": false,
    "age": false
  },
  "version": "1.0",
  "author": "Автор теста"
}
```

### Шаг 3: Добавьте вопросы

**Файл:** `questions.json`

```json
[
  {
    "id": 1,
    "text": "Текст первого вопроса",
    "type": "yes_no",
    "scale": "A",
    "direction": 1
  },
  {
    "id": 2,
    "text": "Текст второго вопроса",
    "type": "yes_no",
    "scale": "A",
    "direction": -1
  }
]
```

### Шаг 4: Создайте класс модуля

**Файл:** `MyTestModule.php`

```php
<?php
declare(strict_types=1);

namespace PsyTest\Modules\MyTest;

use PsyTest\Modules\BaseTestModule;

class MyTestModule extends BaseTestModule
{
    public function calculateResults(array $answers): array
    {
        $score = 0;
        $questions = $this->getQuestions();
        
        foreach ($answers as $questionId => $answer) {
            $question = $this->findQuestion($questions, $questionId);
            if ($question) {
                $direction = $question['direction'] ?? 1;
                $score += ($direction === 1) ? ($answer ? 1 : 0) : ($answer ? 0 : 1);
            }
        }
        
        return [
            'scores' => ['A' => $score],
            'total' => $score,
        ];
    }
    
    public function generateInterpretation(array $scores): array
    {
        $score = $scores['scores']['A'] ?? 0;
        
        if ($score < 5) {
            $level = 'low';
            $text = 'Низкий уровень';
        } elseif ($score < 15) {
            $level = 'normal';
            $text = 'Нормальный уровень';
        } else {
            $level = 'high';
            $text = 'Высокий уровень';
        }
        
        return [
            'summary' => $text,
            'scales' => [
                'A' => [
                    'score' => $score,
                    'level' => $level,
                    'interpretation' => $text,
                ]
            ],
            'recommendations' => [],
        ];
    }
    
    public function renderResults(array $results): string
    {
        $html = '<div class="test-results">';
        $html .= '<h2>Результаты теста</h2>';
        $html .= '<p>Ваш балл: ' . ($results['total'] ?? 0) . '</p>';
        $html .= '</div>';
        return $html;
    }
    
    private function findQuestion(array $questions, int $id): ?array
    {
        foreach ($questions as $question) {
            if ($question['id'] == $id) {
                return $question;
            }
        }
        return null;
    }
}
```

### Шаг 5: Зарегистрируйте в БД

```sql
INSERT INTO tests (name, slug, module_class, description, is_active, sort_order)
VALUES (
    'Мой психологический тест',
    'my-test',
    'PsyTest\\Modules\\MyTest\\MyTestModule',
    'Краткое описание теста',
    1,
    10
);
```

### Шаг 6: Протестируйте

```bash
# Создайте тестовую сессию
php bin/test-module.php my-test

# Откройте в браузере
open http://localhost:8000/test/my-test
```

---

## 📚 Детальное руководство

### Структура модуля

```
modules/my-test/
├── MyTestModule.php           # Главный класс модуля
├── metadata.json              # Метаданные теста
├── questions.json             # Вопросы теста
├── interpretations.json       # Интерпретации (опционально)
├── norms.json                 # Нормативные данные (опционально)
├── README.md                  # Документация модуля
└── views/                     # Кастомные шаблоны (опционально)
    ├── test.twig
    └── results.twig
```

---

### Метаданные (metadata.json)

#### Обязательные поля

```json
{
  "slug": "my-test",              // URL-идентификатор (a-z, 0-9, -)
  "name": "Название теста",       // Отображаемое название
  "description": "Описание",      // Краткое описание
  "question_count": 20,           // Количество вопросов
  "estimated_time": 10            // Время прохождения (минуты)
}
```

#### Опциональные поля

```json
{
  "scales": [                     // Шкалы теста
    {
      "code": "A",                // Код шкалы
      "name": "Название",         // Название шкалы
      "description": "Описание"   // Описание шкалы
    }
  ],
  "requires_demographics": {      // Требуемые демографические данные
    "gender": true,               // Требуется пол
    "age": true,                  // Требуется возраст
    "min_age": 16,                // Минимальный возраст
    "max_age": 65                 // Максимальный возраст
  },
  "version": "1.0",               // Версия теста
  "author": "Автор",              // Автор методики
  "source": "Источник",           // Источник/ссылка
  "language": "ru",               // Язык теста
  "supports_pair_mode": false     // Поддержка парного режима
}
```

---

### Вопросы (questions.json)

#### Формат вопроса

```json
{
  "id": 1,                        // Уникальный ID (обязательно)
  "text": "Текст вопроса",        // Текст вопроса (обязательно)
  "type": "yes_no",               // Тип вопроса (обязательно)
  "scale": "A",                   // Шкала (опционально)
  "direction": 1,                 // Направление подсчета (опционально)
  "weight": 1.0,                  // Вес вопроса (опционально)
  "reverse": false                // Обратный подсчет (опционально)
}
```

#### Типы вопросов

**1. Да/Нет (yes_no)**

```json
{
  "id": 1,
  "text": "Вы часто чувствуете тревогу?",
  "type": "yes_no",
  "scale": "anxiety"
}
```

**2. Шкала Лайкерта (likert)**

```json
{
  "id": 2,
  "text": "Насколько вы согласны с утверждением?",
  "type": "likert",
  "scale": "A",
  "options": [
    {"value": 0, "label": "Совершенно не согласен"},
    {"value": 1, "label": "Не согласен"},
    {"value": 2, "label": "Нейтрально"},
    {"value": 3, "label": "Согласен"},
    {"value": 4, "label": "Полностью согласен"}
  ]
}
```

**3. Множественный выбор (multiple_choice)**

```json
{
  "id": 3,
  "text": "Выберите наиболее подходящий вариант",
  "type": "multiple_choice",
  "scale": "B",
  "options": [
    {"value": "a", "label": "Вариант A", "score": 1},
    {"value": "b", "label": "Вариант B", "score": 2},
    {"value": "c", "label": "Вариант C", "score": 3}
  ]
}
```

**4. Числовой ввод (numeric)**

```json
{
  "id": 4,
  "text": "Сколько часов вы спите в день?",
  "type": "numeric",
  "scale": "sleep",
  "min": 0,
  "max": 24,
  "step": 0.5
}
```

---

### Класс модуля (MyTestModule.php)

#### Базовая структура

```php
<?php
declare(strict_types=1);

namespace PsyTest\Modules\MyTest;

use PsyTest\Modules\BaseTestModule;

class MyTestModule extends BaseTestModule
{
    /**
     * Расчет результатов
     * 
     * @param array $answers Ответы пользователя
     * @return array Результаты расчета
     */
    public function calculateResults(array $answers): array
    {
        // Ваша логика расчета
        return [
            'scores' => [],
            'raw_scores' => [],
            'percentiles' => [],
        ];
    }
    
    /**
     * Генерация интерпретации
     * 
     * @param array $scores Рассчитанные баллы
     * @return array Интерпретация
     */
    public function generateInterpretation(array $scores): array
    {
        // Ваша логика интерпретации
        return [
            'summary' => '',
            'scales' => [],
            'recommendations' => [],
        ];
    }
    
    /**
     * Рендеринг результатов
     * 
     * @param array $results Полные результаты
     * @return string HTML
     */
    public function renderResults(array $results): string
    {
        // Ваш HTML
        return '<div>...</div>';
    }
}
```

#### Методы BaseTestModule

Доступные методы из базового класса:

```php
// Загрузка вопросов
protected function loadQuestionsFromJson(string $filename): array

// Расчет T-score
protected function calculateTScore(float $rawScore, float $mean, float $stdDev): float

// Нормализация балла
protected function normalizeScore(float $score, float $min, float $max): float

// Определение уровня
protected function getInterpretationLevel(float $score, array $thresholds): string

// Валидация ответов
protected function validateAnswers(array $answers, array $questions): bool
```

---

### Расчет результатов

#### Простой подсчет баллов

```php
public function calculateResults(array $answers): array
{
    $score = 0;
    $questions = $this->getQuestions();
    
    foreach ($answers as $questionId => $answer) {
        $question = $this->findQuestion($questions, $questionId);
        
        if ($question && $question['scale'] === 'A') {
            $direction = $question['direction'] ?? 1;
            
            if ($direction === 1) {
                $score += $answer ? 1 : 0;
            } else {
                $score += $answer ? 0 : 1;
            }
        }
    }
    
    return [
        'scores' => ['A' => $score],
        'total' => $score,
    ];
}
```

#### Расчет с весами

```php
public function calculateResults(array $answers): array
{
    $score = 0.0;
    $questions = $this->getQuestions();
    
    foreach ($answers as $questionId => $answer) {
        $question = $this->findQuestion($questions, $questionId);
        
        if ($question) {
            $weight = $question['weight'] ?? 1.0;
            $score += $answer * $weight;
        }
    }
    
    return [
        'scores' => ['weighted' => $score],
        'total' => $score,
    ];
}
```

#### Расчет с нормами (T-scores)

```php
public function calculateResults(array $answers): array
{
    $rawScore = $this->calculateRawScore($answers);
    $gender = $answers['gender'] ?? 'male';
    
    // Загрузить нормы
    $norms = $this->loadNorms();
    $mean = $norms[$gender]['mean'] ?? 50;
    $stdDev = $norms[$gender]['stdDev'] ?? 10;
    
    // Рассчитать T-score
    $tScore = $this->calculateTScore($rawScore, $mean, $stdDev);
    
    return [
        'raw_score' => $rawScore,
        't_score' => $tScore,
        'gender' => $gender,
    ];
}

private function loadNorms(): array
{
    $filepath = $this->modulePath . '/norms.json';
    $content = file_get_contents($filepath);
    return json_decode($content, true);
}
```

---

### Интерпретация результатов

#### Простая интерпретация

```php
public function generateInterpretation(array $scores): array
{
    $score = $scores['total'] ?? 0;
    
    if ($score < 10) {
        $level = 'low';
        $text = 'Низкий уровень тревожности';
    } elseif ($score < 20) {
        $level = 'normal';
        $text = 'Нормальный уровень тревожности';
    } else {
        $level = 'high';
        $text = 'Высокий уровень тревожности';
    }
    
    return [
        'summary' => $text,
        'level' => $level,
        'score' => $score,
    ];
}
```

#### Интерпретация по шкалам

```php
public function generateInterpretation(array $scores): array
{
    $interpretations = [];
    
    foreach ($scores['scales'] as $scale => $score) {
        $interpretations[$scale] = [
            'score' => $score,
            'level' => $this->getLevel($score),
            'text' => $this->getInterpretationText($scale, $score),
        ];
    }
    
    return [
        'summary' => $this->generateSummary($interpretations),
        'scales' => $interpretations,
        'recommendations' => $this->generateRecommendations($interpretations),
    ];
}

private function getLevel(float $score): string
{
    if ($score < 45) return 'low';
    if ($score < 55) return 'normal';
    if ($score < 65) return 'elevated';
    if ($score < 75) return 'high';
    return 'very_high';
}
```

#### Загрузка интерпретаций из JSON

```php
public function generateInterpretation(array $scores): array
{
    $interpretations = $this->loadInterpretations();
    $result = [];
    
    foreach ($scores['scales'] as $scale => $score) {
        $level = $this->getLevel($score);
        $scaleData = $interpretations[$scale] ?? [];
        
        $result[$scale] = [
            'score' => $score,
            'level' => $level,
            'name' => $scaleData['name'] ?? $scale,
            'text' => $scaleData['levels'][$level] ?? '',
        ];
    }
    
    return [
        'summary' => $this->generateSummary($result),
        'scales' => $result,
    ];
}

private function loadInterpretations(): array
{
    $filepath = $this->modulePath . '/interpretations.json';
    $content = file_get_contents($filepath);
    return json_decode($content, true);
}
```

**Файл:** `interpretations.json`

```json
{
  "A": {
    "name": "Шкала A",
    "levels": {
      "low": "Низкий уровень: ...",
      "normal": "Нормальный уровень: ...",
      "elevated": "Повышенный уровень: ...",
      "high": "Высокий уровень: ...",
      "very_high": "Очень высокий уровень: ..."
    }
  }
}
```

---

### Рендеринг результатов

#### Простой HTML

```php
public function renderResults(array $results): string
{
    $html = '<div class="test-results">';
    $html .= '<h2>Результаты теста</h2>';
    
    $html .= '<div class="score-summary">';
    $html .= '<p class="total-score">Общий балл: ' . ($results['total'] ?? 0) . '</p>';
    $html .= '</div>';
    
    $html .= '<div class="interpretation">';
    $html .= '<h3>Интерпретация</h3>';
    $html .= '<p>' . htmlspecialchars($results['interpretation']['summary'] ?? '') . '</p>';
    $html .= '</div>';
    
    $html .= '</div>';
    return $html;
}
```

#### С таблицей шкал

```php
public function renderResults(array $results): string
{
    $html = '<div class="test-results">';
    
    // Таблица шкал
    $html .= '<table class="scales-table">';
    $html .= '<thead><tr><th>Шкала</th><th>Балл</th><th>Уровень</th></tr></thead>';
    $html .= '<tbody>';
    
    foreach ($results['interpretation']['scales'] ?? [] as $scale => $data) {
        $levelClass = $data['level'] ?? 'normal';
        $html .= '<tr class="level-' . $levelClass . '">';
        $html .= '<td>' . htmlspecialchars($data['name'] ?? $scale) . '</td>';
        $html .= '<td>' . ($data['score'] ?? 0) . '</td>';
        $html .= '<td>' . $this->getLevelName($levelClass) . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    $html .= '</div>';
    
    return $html;
}

private function getLevelName(string $level): string
{
    $names = [
        'low' => 'Низкий',
        'normal' => 'Норма',
        'elevated' => 'Повышенный',
        'high' => 'Высокий',
        'very_high' => 'Очень высокий',
    ];
    return $names[$level] ?? $level;
}
```

#### С графиком (Chart.js)

```php
public function renderResults(array $results): string
{
    $scales = array_keys($results['scores'] ?? []);
    $scores = array_values($results['scores'] ?? []);
    
    $scalesJson = json_encode($scales);
    $scoresJson = json_encode($scores);
    
    $html = '<div class="test-results">';
    $html .= '<div class="chart-container">';
    $html .= '<canvas id="resultsChart" data-labels=\'' . $scalesJson . '\' data-scores=\'' . $scoresJson . '\'></canvas>';
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}
```

---

## 💡 Примеры

### Пример 1: Простой тест (Beck Anxiety)

**Характеристики:**
- 21 вопрос
- Шкала Лайкерта (0-3)
- Одна шкала
- Простой подсчет

**Файл:** `modules/beck-anxiety/BeckAnxietyModule.php`

```php
<?php
declare(strict_types=1);

namespace PsyTest\Modules\BeckAnxiety;

use PsyTest\Modules\BaseTestModule;

class BeckAnxietyModule extends BaseTestModule
{
    public function calculateResults(array $answers): array
    {
        $totalScore = 0;
        
        foreach ($answers as $questionId => $answer) {
            if (is_numeric($answer)) {
                $totalScore += (int) $answer;
            }
        }
        
        return [
            'total_score' => $totalScore,
            'level' => $this->getAnxietyLevel($totalScore),
        ];
    }
    
    public function generateInterpretation(array $scores): array
    {
        $score = $scores['total_score'] ?? 0;
        $level = $scores['level'] ?? 'minimal';
        
        $interpretations = [
            'minimal' => 'Минимальный уровень тревоги',
            'mild' => 'Легкая тревога',
            'moderate' => 'Умеренная тревога',
            'severe' => 'Выраженная тревога',
        ];
        
        return [
            'summary' => $interpretations[$level],
            'score' => $score,
            'level' => $level,
        ];
    }
    
    public function renderResults(array $results): string
    {
        $score = $results['total_score'] ?? 0;
        $level = $results['level'] ?? 'minimal';
        
        $html = '<div class="beck-results">';
        $html .= '<h2>Результаты теста Beck Anxiety Inventory</h2>';
        $html .= '<div class="score-box level-' . $level . '">';
        $html .= '<p class="score">' . $score . ' баллов</p>';
        $html .= '<p class="level">' . $results['interpretation']['summary'] . '</p>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    private function getAnxietyLevel(int $score): string
    {
        if ($score <= 7) return 'minimal';
        if ($score <= 15) return 'mild';
        if ($score <= 25) return 'moderate';
        return 'severe';
    }
}
```

---

### Пример 2: Тест с несколькими шкалами

**Характеристики:**
- 50 вопросов
- Да/Нет
- 5 шкал
- T-scores

**Файл:** `modules/big-five/BigFiveModule.php`

```php
<?php
declare(strict_types=1);

namespace PsyTest\Modules\BigFive;

use PsyTest\Modules\BaseTestModule;

class BigFiveModule extends BaseTestModule
{
    private const SCALES = ['O', 'C', 'E', 'A', 'N'];
    
    public function calculateResults(array $answers): array
    {
        $rawScores = $this->calculateRawScores($answers);
        $tScores = $this->convertToTScores($rawScores);
        
        return [
            'raw_scores' => $rawScores,
            't_scores' => $tScores,
        ];
    }
    
    private function calculateRawScores(array $answers): array
    {
        $scores = array_fill_keys(self::SCALES, 0);
        $questions = $this->getQuestions();
        
        foreach ($answers as $questionId => $answer) {
            $question = $this->findQuestion($questions, $questionId);
            
            if ($question) {
                $scale = $question['scale'];
                $direction = $question['direction'] ?? 1;
                
                if (isset($scores[$scale])) {
                    $scores[$scale] += ($direction === 1) ? ($answer ? 1 : 0) : ($answer ? 0 : 1);
                }
            }
        }
        
        return $scores;
    }
    
    private function convertToTScores(array $rawScores): array
    {
        $norms = $this->loadNorms();
        $tScores = [];
        
        foreach ($rawScores as $scale => $rawScore) {
            $mean = $norms[$scale]['mean'] ?? 5;
            $stdDev = $norms[$scale]['stdDev'] ?? 2;
            $tScores[$scale] = $this->calculateTScore($rawScore, $mean, $stdDev);
        }
        
        return $tScores;
    }
    
    private function loadNorms(): array
    {
        return [
            'O' => ['mean' => 5.0, 'stdDev' => 2.0],
            'C' => ['mean' => 5.0, 'stdDev' => 2.0],
            'E' => ['mean' => 5.0, 'stdDev' => 2.0],
            'A' => ['mean' => 5.0, 'stdDev' => 2.0],
            'N' => ['mean' => 5.0, 'stdDev' => 2.0],
        ];
    }
    
    private function findQuestion(array $questions, int $id): ?array
    {
        foreach ($questions as $question) {
            if ($question['id'] == $id) {
                return $question;
            }
        }
        return null;
    }
}
```

---

## ✅ Чек-лист

### Перед началом

- [ ] Изучена методика теста
- [ ] Есть текст всех вопросов
- [ ] Известен алгоритм расчета
- [ ] Есть интерпретации результатов
- [ ] Определены требуемые демографические данные

### Создание модуля

- [ ] Создана директория `modules/my-test/`
- [ ] Создан файл `metadata.json`
- [ ] Создан файл `questions.json`
- [ ] Создан файл `MyTestModule.php`
- [ ] Создан файл `README.md`

### Реализация

- [ ] Метод `calculateResults()` реализован
- [ ] Метод `generateInterpretation()` реализован
- [ ] Метод `renderResults()` реализован
- [ ] Все вопросы добавлены
- [ ] Интерпретации корректны

### Тестирование

- [ ] Модуль загружается без ошибок
- [ ] Вопросы отображаются корректно
- [ ] Расчет результатов работает
- [ ] Интерпретация генерируется
- [ ] HTML рендерится правильно

### Регистрация

- [ ] Тест добавлен в БД
- [ ] Тест активен (`is_active = 1`)
- [ ] Тест отображается в списке
- [ ] Ссылка работает

### Документация

- [ ] README.md заполнен
- [ ] Описана методика
- [ ] Указаны источники
- [ ] Примеры результатов добавлены

---

## ❓ FAQ

### Как добавить демографические данные?

В `metadata.json`:

```json
{
  "requires_demographics": {
    "gender": true,
    "age": true,
    "min_age": 16,
    "max_age": 65
  }
}
```

В коде:

```php
public function calculateResults(array $answers): array
{
    $gender = $answers['gender'] ?? 'male';
    $age = $answers['age'] ?? 25;
    
    // Использовать в расчетах
}
```

### Как добавить кастомный шаблон?

1. Создайте файл `views/results.twig`
2. Переопределите метод:

```php
public function getResultTemplate(): ?string
{
    return 'my-test-results';
}
```

3. Создайте шаблон в `templates/my-test-results.twig`

### Как добавить кастомный JavaScript?

```php
public function getCustomJavaScript(): ?string
{
    return '/js/my-test.js';
}
```

### Как поддержать парный режим?

```php
public function supportsPairMode(): bool
{
    return true;
}

public function comparePairResults(array $results1, array $results2): array
{
    return [
        'results_1' => $results1,
        'results_2' => $results2,
        'differences' => $this->calculateDifferences($results1, $results2),
    ];
}
```

### Как добавить PDF-отчет?

PDF генерируется автоматически из HTML результатов. Для кастомизации:

1. Добавьте CSS для печати
2. Используйте классы `.pdf-only` и `.screen-only`

### Как валидировать ответы?

```php
public function calculateResults(array $answers): array
{
    $questions = $this->getQuestions();
    
    if (!$this->validateAnswers($answers, $questions)) {
        throw new \InvalidArgumentException('Invalid answers');
    }
    
    // Расчет...
}
```

---

## 📚 Дополнительные ресурсы

- [Архитектура системы](architecture.md)
- [API документация](api/)
- [Примеры модулей](../modules/)
- [Психометрия: основы](https://example.com)

---

**Конец руководства**
