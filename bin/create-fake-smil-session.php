<?php
/**
 * Script to create fake SMIL test session with results
 * Run once to create test data for viewing results page
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Smil\SmilModule;

echo "===========================================\n";
echo "Создание тестовой сессии СМИЛ\n";
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
        'demographics' => json_encode(['gender' => 'male']),
    ]);

    echo "Сессия создана: {$session['id']}\n";
    echo "Токен: {$session['session_token']}\n\n";

    // Generate fake answers (50 questions for demo)
    $questions = $module->getQuestions();
    $answers = [];

    echo "Генерация ответов (50 вопросов)...\n";

    // Create pattern: mix of true/false to get interesting results
    foreach ($questions as $question) {
        $id = $question['id'];
        
        // Pattern: mostly false (0) with some true (1) for elevated scales
        if ($id <= 50) {
            // First 10: mostly true (for elevated L scale - fake good)
            if ($id <= 10) {
                $answers[$id] = ($id % 3 === 0) ? false : true;
            }
            // Next 20: mix for elevated F scale
            elseif ($id <= 30) {
                $answers[$id] = ($id % 4 === 0) ? true : false;
            }
            // Last 20: pattern for clinical scales
            else {
                $answers[$id] = ($id % 2 === 0) ? true : false;
            }
        }
    }

    // Save answers
    $sessionManager->saveAnswers($session['id'], $answers);
    echo "Ответы сохранены: " . count($answers) . " вопросов\n\n";

    // Calculate results
    echo "Расчёт результатов...\n";
    $rawResults = $module->calculateResults($answers);
    $interpretation = $module->generateInterpretation($rawResults);

    // Complete session
    $sessionManager->completeSession($session['id'], array_merge($rawResults, [
        'interpretation' => $interpretation,
    ]));

    echo "Результаты сохранены!\n\n";

    // Display summary
    echo "===========================================\n";
    echo "РЕЗУЛЬТАТЫ\n";
    echo "===========================================\n\n";

    echo "Достоверность:\n";
    echo "  L (Ложь): {$rawResults['validity']['L_score']}\n";
    echo "  F (Достоверность): {$rawResults['validity']['F_score']}\n";
    echo "  K (Коррекция): {$rawResults['validity']['K_score']}\n";
    echo "  F-K индекс: {$rawResults['validity']['FK_index']}\n";
    echo "  Достоверно: " . ($rawResults['validity']['is_valid'] ? 'Да' : 'Нет') . "\n\n";

    echo "T-баллы (скорректированные):\n";
    foreach ($rawResults['corrected_scores'] as $scale => $score) {
        if (in_array($scale, ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'])) {
            echo "  Шкала $scale: $score\n";
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

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
