<?php
/**
 * Тестовый скрипт для проверки архитектуры
 * Запускается без базы данных
 */

declare(strict_types=1);

echo "===========================================\n";
echo "PsyTest Platform - Проверка архитектуры\n";
echo "===========================================\n\n";

// 1. Проверка структуры файлов
echo "1. Проверка структуры файлов...\n";

$requiredFiles = [
    'config.php',
    'composer.json',
    'public/index.php',
    'core/Database.php',
    'core/Router.php',
    'core/SessionManager.php',
    'core/ModuleLoader.php',
    'core/PDFGenerator.php',
    'core/View.php',
    'core/Security.php',
    'modules/TestModuleInterface.php',
    'modules/BaseTestModule.php',
    'modules/smil/SmilModule.php',
    'controllers/HomeController.php',
    'controllers/TestController.php',
    'controllers/ResultController.php',
    'services/PaymentService.php',
    'services/AIInterpretationService.php',
    'templates/layout.twig',
    'templates/test-wrapper.twig',
    'templates/result-page.twig',
    'public/css/main.css',
    'public/js/main.js',
    'database/schema.sql',
];

$missingFiles = [];
foreach ($requiredFiles as $file) {
    if (!file_exists(__DIR__ . '/' . $file)) {
        $missingFiles[] = $file;
    }
}

if (empty($missingFiles)) {
    echo "   ✓ Все файлы на месте\n";
} else {
    echo "   ✗ Отсутствуют файлы:\n";
    foreach ($missingFiles as $file) {
        echo "     - $file\n";
    }
}

// 2. Проверка PHP синтаксиса
echo "\n2. Проверка синтаксиса PHP файлов...\n";

$phpFiles = [
    'config.php',
    'core/Database.php',
    'core/Router.php',
    'core/SessionManager.php',
    'modules/TestModuleInterface.php',
    'modules/BaseTestModule.php',
    'modules/smil/SmilModule.php',
];

$syntaxErrors = [];
foreach ($phpFiles as $file) {
    $filepath = __DIR__ . '/' . $file;
    exec("php -l " . escapeshellarg($filepath) . " 2>&1", $output, $returnCode);
    if ($returnCode !== 0) {
        $syntaxErrors[] = $file;
    }
    $output = [];
}

if (empty($syntaxErrors)) {
    echo "   ✓ Синтаксических ошибок нет\n";
} else {
    echo "   ✗ Ошибки синтаксиса в:\n";
    foreach ($syntaxErrors as $file) {
        echo "     - $file\n";
    }
}

// 3. Проверка конфигурации
echo "\n3. Проверка конфигурации...\n";

if (file_exists(__DIR__ . '/.env')) {
    echo "   ✓ Файл .env существует\n";
} else {
    echo "   ⚠ Файл .env отсутствует (скопируйте из .env.example)\n";
}

$config = require __DIR__ . '/config.php';
if ($config) {
    echo "   ✓ config.php загружается\n";
    echo "   - APP_NAME: " . $config->appName() . "\n";
    echo "   - APP_ENV: " . $config->getString('APP_ENV') . "\n";
    echo "   - APP_DEBUG: " . ($config->isDebug() ? 'true' : 'false') . "\n";
}

// 4. Проверка модуля СМИЛ
echo "\n4. Проверка модуля СМИЛ...\n";

if (file_exists(__DIR__ . '/modules/smil/SmilModule.php')) {
    require_once __DIR__ . '/modules/TestModuleInterface.php';
    require_once __DIR__ . '/modules/BaseTestModule.php';
    require_once __DIR__ . '/modules/smil/SmilModule.php';
    
    try {
        $module = new \PsyTest\Modules\Smil\SmilModule();
        $metadata = $module->getMetadata();
        
        echo "   ✓ Модуль загружается\n";
        echo "   - Название: " . $metadata['name'] . "\n";
        echo "   - Вопросов: " . $metadata['question_count'] . "\n";
        echo "   - Шкал: " . count($metadata['scales']) . "\n";
        echo "   - Время: ~" . $metadata['estimated_time'] . " мин\n";
        
        // Проверка вопросов
        $questions = $module->getQuestions();
        echo "   - Загружено вопросов: " . count($questions) . "\n";
        
        // Проверка расчёта результатов (тестовые данные)
        $testAnswers = [];
        foreach ($questions as $q) {
            $testAnswers[$q['id']] = true; // Все "Да"
        }
        
        $results = $module->calculateResults($testAnswers);
        echo "   ✓ Расчёт результатов работает\n";
        echo "   - T-баллы посчитаны: " . (isset($results['t_scores']) ? 'да' : 'нет') . "\n";
        echo "   - Валидность проверена: " . (isset($results['validity']) ? 'да' : 'нет') . "\n";
        
        // Проверка интерпретации
        $interpretation = $module->generateInterpretation($results);
        echo "   ✓ Интерпретация работает\n";
        echo "   - Summary: " . (isset($interpretation['summary']) ? 'есть' : 'нет') . "\n";
        
        // Проверка рендеринга
        $html = $module->renderResults($results);
        echo "   ✓ Рендеринг HTML работает (длина: " . strlen($html) . " символов)\n";
        
    } catch (\Exception $e) {
        echo "   ✗ Ошибка: " . $e->getMessage() . "\n";
    }
}

// 5. Проверка шаблонов
echo "\n5. Проверка Twig шаблонов...\n";

$templateFiles = [
    'templates/layout.twig',
    'templates/test-wrapper.twig',
    'templates/result-page.twig',
    'templates/tests-list.twig',
];

foreach ($templateFiles as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        $size = filesize(__DIR__ . '/' . $file);
        echo "   ✓ $file ($size байт)\n";
    } else {
        echo "   ✗ $file отсутствует\n";
    }
}

// 6. Проверка CSS/JS
echo "\n6. Проверка статики...\n";

$staticFiles = [
    'public/css/main.css' => 'CSS стили',
    'public/js/main.js' => 'Main JS',
    'public/js/test-taking.js' => 'Test Taking JS',
    'public/js/results.js' => 'Results JS',
];

foreach ($staticFiles as $file => $desc) {
    if (file_exists(__DIR__ . '/' . $file)) {
        $size = filesize(__DIR__ . '/' . $file);
        echo "   ✓ $desc: $file (" . round($size / 1024, 2) . " KB)\n";
    } else {
        echo "   ✗ $desc отсутствует\n";
    }
}

// 7. Проверка прав доступа
echo "\n7. Проверка прав доступа...\n";

$dirs = ['storage', 'storage/pdfs', 'storage/logs', 'storage/cache'];
foreach ($dirs as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (is_dir($path)) {
        if (is_writable($path)) {
            echo "   ✓ $dir - записываемый\n";
        } else {
            echo "   ⚠ $dir - нет прав на запись\n";
        }
    } else {
        echo "   ⚠ $dir - не существует\n";
    }
}

// Итоги
echo "\n===========================================\n";
echo "Проверка завершена!\n";
echo "===========================================\n\n";

echo "Следующие шаги:\n";
echo "1. Установите PHP 8.1+ и Composer\n";
echo "2. Запустите: composer install\n";
echo "3. Настройте базу данных (MySQL/MariaDB)\n";
echo "4. Запустите: php bin/install-db.php\n";
echo "5. Запустите встроенный сервер: php -S localhost:8000 -t public\n";
echo "6. Откройте: http://localhost:8000/tests\n\n";

echo "Подробная инструкция в файле QUICKSTART.md\n";
