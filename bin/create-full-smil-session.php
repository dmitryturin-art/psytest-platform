<?php
/**
 * Script to create FULL fake SMIL test session with all 566 questions
 * Creates realistic response pattern for testing
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Smil\SmilModule;

echo "===========================================\n";
echo "Создание ПОЛНОЙ тестовой сессии СМИЛ\n";
echo "(566 вопросов с реалистичными ответами)\n";
echo "===========================================\n\n";

try {
    $db = Database::getInstance();
    $sessionManager = new SessionManager($db);
    $module = new SmilModule();

    // Get test from DB
    $test = $db->selectOne('SELECT * FROM tests WHERE slug = ?', ['smil']);
    if (!$test) {
        throw new Exception('Тест СМИЛ не найден в БД');
    }

    echo "Тест найден: {$test['name']}\n";

    // Create session
    $session = $sessionManager->createSession($test['id'], [
        'email' => 'test@example.com',
        'name' => 'Тестовый пользователь',
        'demographics' => json_encode(['gender' => 'male', 'age' => 30]),
    ]);

    echo "Сессия создана: {$session['id']}\n";
    echo "Токен: {$session['session_token']}\n\n";

    // Get all questions
    $questions = $module->getQuestions();
    $totalQuestions = count($questions);
    
    echo "Всего вопросов: {$totalQuestions}\n";
    echo "Генерация реалистичных ответов...\n\n";

    // Generate realistic answers (pattern for elevated scales 2 and 7)
    $answers = [];
    $scaleScores = [];
    
    foreach ($questions as $question) {
        $id = $question['id'];
        $scale = $question['scale'] ?? null;
        $direction = $question['direction'] ?? 1;
        
        // Initialize scale scores
        if ($scale && !isset($scaleScores[$scale])) {
            $scaleScores[$scale] = 0;
        }
        
        // Pattern for elevated Depression (scale 2) and Anxiety (scale 7)
        // with normal other scales
        if ($id <= 566) {
            if ($scale === '2' || $scale === '7') {
                // Answer to ELEVATE depression and anxiety
                $answers[$id] = ($direction === 1) ? true : false;
                if ($direction === 1) $scaleScores[$scale]++;
            } elseif ($scale === 'L' || $scale === 'F') {
                // Keep validity scales LOW (normal)
                $answers[$id] = ($direction === 1) ? false : true;
            } elseif ($scale === 'K') {
                // Moderate K (normal defense)
                $answers[$id] = ($id % 2 === 0);
            } elseif ($scale === '9') {
                // LOW Ma (low energy)
                $answers[$id] = ($direction === 1) ? false : true;
            } elseif ($scale === '0') {
                // HIGH Si (introversion)
                $answers[$id] = ($direction === 1) ? true : false;
            } else {
                // Mixed answers for other scales (normal range)
                $answers[$id] = ($id % 3 === 0);
            }
        }
    }

    // Save answers
    $sessionManager->saveAnswers($session['id'], $answers);
    echo "✓ Ответы сохранены: " . count($answers) . " вопросов\n\n";

    // Calculate results
    echo "Расчёт результатов...\n";
    $rawResults = $module->calculateResults($answers);
    $interpretation = $module->generateInterpretation($rawResults);

    // Complete session
    $sessionManager->completeSession($session['id'], array_merge($rawResults, [
        'interpretation' => $interpretation,
    ]));

    echo "✓ Результаты сохранены!\n\n";

    // Display summary
    echo "===========================================\n";
    echo "РЕЗУЛЬТАТЫ\n";
    echo "===========================================\n\n";

    echo "Достоверность:\n";
    echo "  L (Ложь): {$rawResults['validity']['L_score']} T\n";
    echo "  F (Достоверность): {$rawResults['validity']['F_score']} T\n";
    echo "  K (Коррекция): {$rawResults['validity']['K_score']} T\n";
    echo "  F-K индекс: {$rawResults['validity']['FK_index']}\n";
    echo "  Достоверно: " . ($rawResults['validity']['is_valid'] ? 'Да ✓' : 'Нет ✗') . "\n\n";

    echo "T-баллы (скорректированные):\n";
    foreach ($rawResults['corrected_scores'] as $scale => $score) {
        if (in_array($scale, ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'])) {
            $level = $rawResults['profile']['scales'][$scale]['level'] ?? 'normal';
            $levelName = [
                'low' => 'Низкий',
                'normal' => 'Норма',
                'elevated' => 'Повышенный',
                'high' => 'Высокий',
                'very_high' => 'Очень высокий',
            ][$level] ?? $level;
            echo "  Шкала $scale: $score T ($levelName)\n";
        }
    }
    echo "\n";

    echo "Тип профиля: {$rawResults['profile']['profile_type']}\n";
    echo "Код профиля: {$rawResults['profile']['code_type']}\n\n";

    echo "Интерпретация:\n";
    echo "  {$interpretation['summary']}\n\n";

    // Result URL
    $resultUrl = "http://localhost:8000/result/smil/{$session['session_token']}";
    echo "===========================================\n";
    echo "ССЫЛКА НА РЕЗУЛЬТАТЫ:\n";
    echo "$resultUrl\n";
    echo "===========================================\n\n";

    echo "Откройте ссылку в браузере для просмотра полного отчёта!\n";

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
