<?php
/**
 * PsyTest Platform - Public Entry Point
 * 
 * All requests are routed through this file
 */

declare(strict_types=1);

// Error reporting (disable in production)
$configLoader = require __DIR__ . '/../config.php';
if ($configLoader->isDebug()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use PsyTest\Core\Router;
use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use PsyTest\Core\ModuleLoader;
use PsyTest\Controllers\HomeController;
use PsyTest\Controllers\TestController;
use PsyTest\Controllers\ResultController;
use PsyTest\Controllers\ApiController;

// Initialize core components
$db = Database::getInstance();
$router = new Router();
$moduleLoader = (new ModuleLoader(null, $db))->discover();
$sessionManager = new SessionManager($db);

// Set base path
$basePath = dirname($_SERVER['SCRIPT_NAME']);
if ($basePath === '/' || $basePath === '\\') {
    $basePath = '';
}
$router->setBasePath($basePath);

// ============================================
// Routes
// ============================================

// Home / Tests list
$router->get('/', [HomeController::class, 'index']);
$router->get('/tests', [HomeController::class, 'tests']);

// Test taking
$router->get('/test/{slug}', [TestController::class, 'start']);
$router->post('/test/{slug}/save', [TestController::class, 'save']);
$router->post('/test/{slug}/submit', [TestController::class, 'submit']);

// Pair mode
$router->get('/test/{slug}/pair', [TestController::class, 'pairStart']);
$router->post('/test/{slug}/pair/submit', [TestController::class, 'pairSubmit']);

// Results
$router->get('/result/{slug}/{token}', [ResultController::class, 'show']);
$router->get('/result/{slug}/{token}/pdf', [ResultController::class, 'pdf']);
$router->post('/result/{token}/delete', [ResultController::class, 'delete']);

// Pair comparison results
$router->get('/pair/{id}', [ResultController::class, 'pairShow']);
$router->get('/pair/{id}/pdf', [ResultController::class, 'pairPdf']);

// AI Interpretation
$router->get('/interpretation/{token}', [ResultController::class, 'interpretation']);
$router->post('/interpretation/{token}/pay', [ResultController::class, 'initiatePayment']);

// Payment webhook
$router->post('/webhook/yoomoney', [ApiController::class, 'yoomoneyWebhook']);

// API endpoints
$router->get('/api/health', [ApiController::class, 'health']);

// Static pages
$router->get('/privacy', [HomeController::class, 'privacy']);
$router->get('/terms', [HomeController::class, 'terms']);
$router->get('/deleted', [HomeController::class, 'deleted']);

// Error pages
$router->get('/error/{code}', [HomeController::class, 'error']);

// ============================================
// Global Middleware
// ============================================

// Security headers
$router->middleware(function($method, $uri, &$params) use ($configLoader) {
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');

    // XSS protection
    header('X-XSS-Protection: 1; mode=block');

    // Content type sniffing prevention
    header('X-Content-Type-Options: nosniff');

    // HTTPS enforcement (in production)
    if ($configLoader->isProduction() && empty($_SERVER['HTTPS'])) {
        // Uncomment to enforce HTTPS
        // header('Location: https://' . $_SERVER['HTTP_HOST'] . $uri, true, 301);
        // exit;
    }

    return null; // Continue to route
});

// ============================================
// Dispatch
// ============================================

try {
    $response = $router->dispatch();
    
    if (is_string($response)) {
        echo $response;
    } elseif (is_array($response)) {
        header('Content-Type: application/json');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }
    
} catch (\Exception $e) {
    // Log error
    error_log("Application error: " . $e->getMessage());
    
    // Show error page
    if ($configLoader->isDebug()) {
        echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        http_response_code(500);
        echo '<h1>Internal Server Error</h1>';
    }
}
