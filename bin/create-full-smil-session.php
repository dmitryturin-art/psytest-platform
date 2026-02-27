<?php
/**
 * Script to create FULL fake SMIL test session with all 566 questions
 * Supports multiple filling modes for testing
 * 
 * Usage:
 *   php bin/create-full-smil-session.php [options]
 * 
 * Options:
 *   --random      - Random answers (default)
 *   --all-true    - All answers "Верно" (true)
 *   --all-false   - All answers "Неверно" (false)
 *   --all-unknown - All answers "Не знаю" (unknown)
 *   --pattern     - Specific pattern for elevated scales 2 and 7
 *   --gender=male|female - Gender for norms (default: male)
 *   --help        - Show help
 */

 declare(strict_types=1);

 require_once __DIR__ . '/../vendor/autoload.php';

 use PsyTest\Core\Database;
 use PsyTest\Core\SessionManager;
 use PsyTest\Modules\Smil\SmilModule;

 // Parse command line arguments
 $options = [
     'mode' => 'random',
     'gender' => 'male',
     'help' => false,
 ];

 foreach ($argv as $arg) {
     if ($arg === '--random') {
         $options['mode'] = 'random';
     } elseif ($arg === '--all-true') {
         $options['mode'] = 'all-true';
     } elseif ($arg === '--all-false') {
         $options['mode'] = 'all-false';
     } elseif ($arg === '--all-unknown') {
         $options['mode'] = 'all-unknown';
     } elseif ($arg === '--pattern') {
         $options['mode'] = 'pattern';
     } elseif (str_starts_with($arg, '--gender=')) {
         $options['gender'] = substr($arg, 9);
     } elseif ($arg === '--help' || $arg === '-h') {
         $options['help'] = true;
     }
 }

 // Show help
 if ($options['help']) {
     echo "Создание тестовой сессии СМИЛ с заполненными ответами\n\n";
     echo "Usage: php bin/create-full-smil-session.php [options]\n\n";
     echo "Options:\n";
     echo "  --random        Случайные ответы (по умолчанию)\n";
     echo "  --all-true      Все ответы \"Верно\"\n";
     echo "  --all-false     Все ответы \"Неверно\"\n";
     echo "  --all-unknown   Все ответы \"Не знаю\"\n";
     echo "  --pattern       Паттерн для повышенных шкал 2 и 7\n";
     echo "  --gender=VALUE  Пол для норм (male|female, по умолчанию: male)\n";
     echo "  --help          Показать справку\n";
     exit(0);
 }

 echo "===========================================\n";
 echo "Создание ПОЛНОЙ тестовой сессии СМИЛ\n";
 echo "(566 вопросов, режим: {$options['mode']})\n";
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
         'demographics' => json_encode(['gender' => $options['gender'], 'age' => 30]),
     ]);

     echo "Сессия создана: {$session['id']}\n";
     echo "Токен: {$session['session_token']}\n";
     echo "Пол: {$options['gender']}\n\n";

     // Get all questions
     $questions = $module->getQuestions();
     $totalQuestions = count($questions);
     
     echo "Всего вопросов: {$totalQuestions}\n";
     echo "Генерация ответов (режим: {$options['mode']})...\n\n";

     // Generate answers based on mode
     $answers = generateAnswers($questions, $options['mode']);

     // Add gender to answers for T-score calculation
     $answers['gender'] = $options['gender'];

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
     displayResults($rawResults, $interpretation);

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

 /**
  * Generate answers based on mode
  */
 function generateAnswers(array $questions, string $mode): array
 {
     $answers = [];
     
     switch ($mode) {
         case 'all-true':
             // All answers "Верно" (true = 1)
             foreach ($questions as $question) {
                 $answers[$question['id']] = 1;
             }
             break;

         case 'all-false':
             // All answers "Неверно" (false = 0)
             foreach ($questions as $question) {
                 $answers[$question['id']] = 0;
             }
             break;

         case 'all-unknown':
             // All answers "Не знаю" (unknown = 2)
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
                 
                 if ($scale === '2' || $scale === '7') {
                     // Answer to ELEVATE depression and anxiety
                     $answers[$id] = ($direction === 1) ? 1 : 0;
                 } elseif ($scale === 'L' || $scale === 'F') {
                     // Keep validity scales LOW (normal)
                     $answers[$id] = ($direction === 1) ? 0 : 1;
                 } elseif ($scale === 'K') {
                     // Moderate K (normal defense)
                     $answers[$id] = ($id % 2 === 0) ? 1 : 0;
                 } elseif ($scale === '9') {
                     // LOW Ma (low energy)
                     $answers[$id] = ($direction === 1) ? 0 : 1;
                 } elseif ($scale === '0') {
                     // HIGH Si (introversion)
                     $answers[$id] = ($direction === 1) ? 1 : 0;
                 } else {
                     // Mixed answers for other scales (normal range)
                     $answers[$id] = ($id % 3 === 0) ? 1 : 0;
                 }
             }
             break;

         case 'random':
         default:
             // Random answers with some "не знаю"
             foreach ($questions as $question) {
                 $rand = mt_rand(0, 100);
                 if ($rand < 10) {
                     // 10% "Не знаю"
                     $answers[$question['id']] = 2;
                 } elseif ($rand < 55) {
                     // 45% "Верно"
                     $answers[$question['id']] = 1;
                 } else {
                     // 45% "Неверно"
                     $answers[$question['id']] = 0;
                 }
             }
             break;
     }

     return $answers;
 }

 /**
  * Display results summary
  */
 function displayResults(array $rawResults, array $interpretation): void
 {
     echo "===========================================\n";
     echo "РЕЗУЛЬТАТЫ\n";
     echo "===========================================\n\n";

     echo "Достоверность:\n";
     echo "  L (Ложь): {$rawResults['validity']['L_score']} T\n";
     echo "  F (Достоверность): {$rawResults['validity']['F_score']} T\n";
     echo "  K (Коррекция): {$rawResults['validity']['K_score']} T\n";
     echo "  F-K индекс: {$rawResults['validity']['FK_index']}\n";
     echo "  Достоверно: " . ($rawResults['validity']['is_valid'] ? 'Да ✓' : 'Нет ✗') . "\n\n";

     echo "Сырые баллы:\n";
     foreach ($rawResults['raw_scores'] as $scale => $score) {
         echo "  Шкала $scale: $score\n";
     }
     echo "\n";

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
         } else {
             echo "  Шкала $scale: $score T\n";
         }
     }
     echo "\n";

     // Display additional scales (first 10)
     if (!empty($rawResults['additional_scores'])) {
         echo "Дополнительные шкалы (первые 10):\n";
         $count = 0;
         foreach ($rawResults['additional_scores'] as $code => $data) {
             if ($count++ >= 10) break;
             echo "  {$data['name']} ($code): {$data['t']} T (raw: {$data['raw']})\n";
         }
         echo "\n";
     }

     echo "Тип профиля: {$rawResults['profile']['profile_type']}\n";
     echo "Код профиля: {$rawResults['profile']['code_type']}\n\n";

     if (isset($interpretation['summary'])) {
         echo "Интерпретация:\n";
         echo "  {$interpretation['summary']}\n\n";
     }

     // Display indices
     if (!empty($rawResults['indices'])) {
         echo "Индексы:\n";
         echo "  F-K индекс: {$rawResults['indices']['FK_index']}\n";
         echo "  Индекс тревоги: {$rawResults['indices']['anxiety_index']}\n";
         echo "  Индекс депрессии: {$rawResults['indices']['depression_index']}\n\n";
     }
 }
