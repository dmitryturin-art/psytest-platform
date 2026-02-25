<?php
/**
 * Script to create fake SMIL test session with ELEVATED results
 * Run to create test data with high Depression and Anxiety for demo
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Smil\SmilModule;

echo "===========================================\n";
echo "Создание тестовой сессии СМИЛ\n";
echo "с ПОВЫШЕННЫМИ шкалами Депрессии и Тревоги\n";
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

    // Generate fake answers with pattern for elevated scales
    $questions = $module->getQuestions();
    $answers = [];

    echo "Генерация ответов с паттерном для повышенных шкал...\n";

    foreach ($questions as $question) {
        $id = $question['id'];
        $scale = $question['scale'] ?? null;
        $direction = $question['direction'] ?? 1;
        
        if ($id <= 50) {
            // Pattern for elevated scale 2 (Depression) and 7 (Psychastenia/Anxiety)
            if ($scale === '2' || $scale === '7') {
                // Answer in direction that increases score
                $answers[$id] = ($direction === 1) ? true : false;
            }
            // Scale L (Lie) - moderate
            elseif ($scale === 'L') {
                $answers[$id] = ($id % 2 === 0) ? true : false;
            }
            // Scale F (Validity) - low to normal
            elseif ($scale === 'F') {
                $answers[$id] = false;
            }
            // Scale K (Correction) - moderate
            elseif ($scale === 'K') {
                $answers[$id] = ($id % 3 === 0) ? true : false;
            }
            // Other scales - mixed
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
