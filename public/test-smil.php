<?php
/**
 * Web interface for testing SMIL calculations
 * Allows quick testing with different filling modes
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Smil\SmilModule;

// Handle form submission
$results = null;
$error = null;
$sessionToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $mode = $_POST['mode'] ?? 'random';
        $gender = $_POST['gender'] ?? 'male';
        
        $db = Database::getInstance();
        $sessionManager = new SessionManager($db);
        $module = new SmilModule();

        // Get test from DB
        $test = $db->selectOne('SELECT * FROM tests WHERE slug = ?', ['smil']);
        if (!$test) {
            throw new Exception('Тест СМИЛ не найден в БД');
        }

        // Create session
        $session = $sessionManager->createSession($test['id'], [
            'email' => 'test-web@example.com',
            'name' => 'Веб-тест ' . date('Y-m-d H:i:s'),
            'demographics' => json_encode(['gender' => $gender, 'age' => 30]),
        ]);

        $sessionToken = $session['session_token'];

        // Get all questions
        // For pattern mode, load questions with scale keys
        if ($mode === 'pattern') {
            $questionsFile = __DIR__ . '/../modules/smil/questions-566-correct.json';
            $questionsData = json_decode(file_get_contents($questionsFile), true);
            $questions = $questionsData['questions'] ?? [];
        } else {
            $questions = $module->getQuestions();
        }
        
        // Generate answers based on mode
        $answers = generateAnswers($questions, $mode);
        $answers['gender'] = $gender;

        // Save answers
        $sessionManager->saveAnswers($session['id'], $answers);

        // Calculate results
        $rawResults = $module->calculateResults($answers);
        $interpretation = $module->generateInterpretation($rawResults);

        // Complete session
        $sessionManager->completeSession($session['id'], array_merge($rawResults, [
            'interpretation' => $interpretation,
        ]));

        $results = [
            'raw' => $rawResults,
            'interpretation' => $interpretation,
        ];

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/**
 * Generate answers based on mode
 */
function generateAnswers(array $questions, string $mode): array
{
    $answers = [];
    
    switch ($mode) {
        case 'all-true':
            foreach ($questions as $question) {
                $answers[$question['id']] = 1;
            }
            break;

        case 'all-false':
            foreach ($questions as $question) {
                $answers[$question['id']] = 0;
            }
            break;

        case 'all-unknown':
            foreach ($questions as $question) {
                $answers[$question['id']] = 2;
            }
            break;

        case 'pattern':
            // Pattern for elevated Depression (scale 2) and Anxiety (scale 7)
            foreach ($questions as $question) {
                $id = $question['id'];
                $scale = $question['scale'] ?? null;
                $direction = $question['direction'] ?? 1;
                $isControl = $question['is_control'] ?? false;
                
                // Control questions: always answer "Да" (1) for validity
                if ($isControl) {
                    $answers[$id] = 1;
                } elseif ($scale === '2' || $scale === '7') {
                    $answers[$id] = ($direction === 1) ? 1 : 0;
                } elseif ($scale === 'L' || $scale === 'F') {
                    $answers[$id] = ($direction === 1) ? 0 : 1;
                } elseif ($scale === 'K') {
                    $answers[$id] = ($id % 2 === 0) ? 1 : 0;
                } elseif ($scale === '9') {
                    $answers[$id] = ($direction === 1) ? 0 : 1;
                } elseif ($scale === '0') {
                    $answers[$id] = ($direction === 1) ? 1 : 0;
                } else {
                    $answers[$id] = ($id % 3 === 0) ? 1 : 0;
                }
            }
            break;

        case 'random':
        default:
            foreach ($questions as $question) {
                $id = $question['id'];
                $isControl = $question['is_control'] ?? false;
                
                // Control questions: always answer "Да" (1) for validity
                if ($isControl) {
                    $answers[$id] = 1;
                } else {
                    $rand = mt_rand(0, 100);
                    if ($rand < 10) {
                        $answers[$id] = 2;  // Не знаю
                    } elseif ($rand < 55) {
                        $answers[$id] = 1;  // Да
                    } else {
                        $answers[$id] = 0;  // Нет
                    }
                }
            }
            break;
    }

    return $answers;
}

/**
 * Get level name in Russian
 */
function getLevelName(string $level): string
{
    return [
        'low' => 'Низкий',
        'normal' => 'Норма',
        'elevated' => 'Повышенный',
        'high' => 'Высокий',
        'very_high' => 'Очень высокий',
    ][$level] ?? $level;
}

/**
 * Get level CSS class
 */
function getLevelClass(string $level): string
{
    return [
        'low' => 'level-low',
        'normal' => 'level-normal',
        'elevated' => 'level-elevated',
        'high' => 'level-high',
        'very_high' => 'level-very-high',
    ][$level] ?? 'level-normal';
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тестирование СМИЛ - Проверка расчетов</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        h1 {
            color: white;
            text-align: center;
            margin-bottom: 30px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .card h2 {
            color: #667eea;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #555;
        }
        
        select, button {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c00;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .success {
            background: #efe;
            border: 1px solid #cfc;
            color: #060;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .result-link {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            margin-top: 10px;
        }
        
        .result-link:hover {
            background: #5a6fd6;
        }
        
        .scales-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }
        
        .scale-item {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .scale-item h4 {
            color: #333;
            margin-bottom: 8px;
        }
        
        .scale-item .score {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }
        
        .scale-item .raw {
            color: #888;
            font-size: 14px;
        }
        
        .level-low { border-left-color: #4CAF50; }
        .level-normal { border-left-color: #2196F3; }
        .level-elevated { border-left-color: #FF9800; }
        .level-high { border-left-color: #f44336; }
        .level-very-high { border-left-color: #9C27B0; }
        
        .level-low .score { color: #4CAF50; }
        .level-normal .score { color: #2196F3; }
        .level-elevated .score { color: #FF9800; }
        .level-high .score { color: #f44336; }
        .level-very-high .score { color: #9C27B0; }
        
        .validity-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .validity-item {
            text-align: center;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .validity-item .value {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }
        
        .validity-item .label {
            color: #666;
            font-size: 14px;
        }
        
        .interpretation {
            background: #f0f4ff;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .interpretation h3 {
            color: #667eea;
            margin-bottom: 12px;
        }
        
        .interpretation p {
            line-height: 1.6;
            color: #444;
        }
        
        .additional-scales {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .indices-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        
        .index-item {
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 8px;
        }
        
        .index-item .name {
            color: #666;
            font-size: 14px;
        }
        
        .index-item .value {
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
        }
        
        .profile-chart {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            height: 200px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .chart-bar {
            flex: 1;
            background: linear-gradient(to top, #667eea, #764ba2);
            border-radius: 4px 4px 0 0;
            position: relative;
            min-width: 30px;
            transition: height 0.3s;
        }
        
        .chart-bar::after {
            content: attr(data-scale);
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 12px;
            font-weight: bold;
            color: #666;
        }
        
        .chart-bar .t-value {
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 11px;
            font-weight: bold;
            color: #667eea;
        }
        
        .formula-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 16px;
            border-radius: 8px;
            margin: 20px 0;
            font-family: monospace;
        }
        
        .formula-box code {
            font-size: 16px;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Тестирование расчетов СМИЛ</h1>
        
        <!-- Form -->
        <div class="card">
            <h2>Параметры тестового заполнения</h2>
            
            <?php if ($error): ?>
                <div class="error">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label for="mode">Режим заполнения:</label>
                        <select id="mode" name="mode">
                            <option value="random">Случайные ответы</option>
                            <option value="all-true">Все "Верно"</option>
                            <option value="all-false">Все "Неверно"</option>
                            <option value="all-unknown">Все "Не знаю"</option>
                            <option value="pattern">Паттерн (шкалы 2↑, 7↑)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="gender">Пол (для норм):</label>
                        <select id="gender" name="gender">
                            <option value="male">Мужской</option>
                            <option value="female">Женский</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit">🚀 Заполнить и показать результаты</button>
            </form>
        </div>
        
        <?php if ($results): ?>
            <!-- Validity -->
            <div class="card">
                <h2>📊 Шкалы достоверности</h2>
                
                <div class="validity-section">
                    <div class="validity-item">
                        <div class="value"><?= $results['raw']['validity']['L_score'] ?></div>
                        <div class="label">L (Ложь)</div>
                    </div>
                    <div class="validity-item">
                        <div class="value"><?= $results['raw']['validity']['F_score'] ?></div>
                        <div class="label">F (Достоверность)</div>
                    </div>
                    <div class="validity-item">
                        <div class="value"><?= $results['raw']['validity']['K_score'] ?></div>
                        <div class="label">K (Коррекция)</div>
                    </div>
                    <div class="validity-item">
                        <div class="value"><?= $results['raw']['validity']['FK_index'] ?></div>
                        <div class="label">F-K индекс</div>
                    </div>
                </div>
                
                <?php if ($results['raw']['validity']['is_valid']): ?>
                    <div class="success">✅ Профиль достоверен</div>
                <?php else: ?>
                    <div class="error">⚠️ Профиль может быть недостоверен</div>
                <?php endif; ?>
                
                <?php if (!empty($results['raw']['validity']['warnings'])): ?>
                    <ul style="margin: 10px 0; padding-left: 20px;">
                        <?php foreach ($results['raw']['validity']['warnings'] as $warning): ?>
                            <li><?= htmlspecialchars($warning) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <!-- Profile Chart -->
            <div class="card">
                <h2>📈 Профиль T-баллов</h2>
                
                <div class="profile-chart">
                    <?php 
                    $scalesOrder = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];
                    foreach ($scalesOrder as $scale): 
                        $score = $results['raw']['corrected_scores'][$scale] ?? 50;
                        $height = min(100, max(0, ($score - 20) * 1.5)); // Scale 20-90 to 0-100%
                    ?>
                        <div class="chart-bar" data-scale="<?= $scale ?>" style="height: <?= $height ?>%;">
                            <span class="t-value"><?= $score ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="formula-box">
                    <strong>Формула расчета:</strong><br>
                    <code>T = 50 + 10 × (X - M) / δ</code>
                    <br><small>где X - сырой балл, M - медиана, δ - стандартное отклонение</small>
                </div>
            </div>
            
            <!-- Clinical Scales -->
            <div class="card">
                <h2>🏥 Клинические шкалы</h2>
                
                <div class="scales-grid">
                    <?php foreach ($results['raw']['profile']['scales'] as $scale => $data): ?>
                        <div class="scale-item <?= getLevelClass($data['level']) ?>">
                            <h4>Шкала <?= $scale ?>: <?= htmlspecialchars($data['name']) ?></h4>
                            <div class="score"><?= $data['score'] ?> T</div>
                            <div class="raw">Сырой балл: <?= $results['raw']['raw_scores'][$scale] ?> | Уровень: <?= getLevelName($data['level']) ?></div>
                            <div style="margin-top: 8px; font-size: 14px; color: #666;">
                                <?= htmlspecialchars($data['interpretation']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 20px; padding: 16px; background: #f0f0f0; border-radius: 8px;">
                    <strong>Тип профиля:</strong> <?= htmlspecialchars($results['raw']['profile']['profile_type']) ?><br>
                    <strong>Код профиля:</strong> <?= htmlspecialchars($results['raw']['profile']['code_type']) ?>
                </div>
            </div>
            
            <!-- Indices -->
            <div class="card">
                <h2>📐 Расчетные индексы</h2>
                
                <div class="indices-grid">
                    <div class="index-item">
                        <div class="name">F-K индекс</div>
                        <div class="value"><?= $results['raw']['indices']['FK_index'] ?></div>
                    </div>
                    <div class="index-item">
                        <div class="name">Индекс тревоги</div>
                        <div class="value"><?= $results['raw']['indices']['anxiety_index'] ?></div>
                    </div>
                    <div class="index-item">
                        <div class="name">Индекс депрессии</div>
                        <div class="value"><?= $results['raw']['indices']['depression_index'] ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Additional Scales -->
            <?php if (!empty($results['raw']['additional_scores'])): ?>
                <div class="card">
                    <h2>📋 Дополнительные шкалы (<?= count($results['raw']['additional_scores']) ?>)</h2>
                    
                    <div class="additional-scales">
                        <div class="scales-grid">
                            <?php foreach ($results['raw']['additional_scores'] as $code => $data): ?>
                                <div class="scale-item <?= getLevelClass($data['t'] >= 65 ? 'high' : ($data['t'] >= 55 ? 'elevated' : 'normal')) ?>">
                                    <h4><?= htmlspecialchars($data['name']) ?> (<?= $code ?>)</h4>
                                    <div class="score"><?= $data['t'] ?> T</div>
                                    <div class="raw">Сырой балл: <?= $data['raw'] ?> | M: <?= $data['M'] ?> | δ: <?= $data['delta'] ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Interpretation -->
            <?php if (!empty($results['interpretation']['summary'])): ?>
                <div class="card">
                    <h2>💬 Интерпретация</h2>
                    
                    <div class="interpretation">
                        <p><?= nl2br(htmlspecialchars($results['interpretation']['summary'])) ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Link -->
            <div class="card">
                <h2>🔗 Ссылка на полный результат</h2>
                <?php if ($sessionToken): ?>
                    <a href="/result/smil/<?= htmlspecialchars($sessionToken) ?>" class="result-link" target="_blank">
                        Открыть страницу результатов →
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
