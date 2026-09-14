<?php
/**
 * PsyTest Platform - Public Entry Point
 * 
 * All requests are routed through this file
 */

declare(strict_types=1);

// Error reporting (disable in production)
$configLoader = require __DIR__ . '/../config.php';
header_remove('X-Powered-By');
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
use PsyTest\Core\CsrfMiddleware;
use PsyTest\Controllers\HomeController;
use PsyTest\Controllers\TestController;
use PsyTest\Controllers\ResultController;
use PsyTest\Controllers\ApiController;
use PsyTest\Controllers\AccountController;
use PsyTest\Controllers\OwnerController;
use PsyTest\Controllers\RetiredPaymentController;

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
$router->get('/invite/{token}', [TestController::class, 'invite']);
$router->post('/invite/{token}/start', [TestController::class, 'startInvite']);
$router->post('/test/{slug}/save', [TestController::class, 'save']);
$router->post('/test/{slug}/submit', [TestController::class, 'submit']);

// Pair mode
$router->get('/test/{slug}/pair', [TestController::class, 'pairStart']);
$router->post('/test/{slug}/pair/submit', [TestController::class, 'pairSubmit']);

// Results
$router->get('/result/{slug}/{token}', [ResultController::class, 'show']);
$router->get('/result/{slug}/{token}/pdf', [ResultController::class, 'pdf']);
$router->get('/result/{slug}/{token}/pair-status', [ResultController::class, 'pairStatus']);
$router->post('/result/{slug}/{token}/report', [ResultController::class, 'requestReport']);
$router->get('/result/{slug}/{token}/report-status', [ResultController::class, 'reportStatus']);
$router->post('/result/{token}/delete', [ResultController::class, 'delete']);

// Добровольный кабинет посетителя. Регистрации нет: вход только по
// одноразовой ссылке на email, и ни один результат не попадает в кабинет без
// явного действия самого посетителя (D-053).
$router->get('/account/login', [AccountController::class, 'loginForm']);
$router->post('/account/login', [AccountController::class, 'requestLogin']);
$router->get('/account/login/{token}', [AccountController::class, 'login']);
$router->post('/account/logout', [AccountController::class, 'logout']);
$router->get('/account', [AccountController::class, 'index']);
$router->get('/account/results/{sessionId}', [AccountController::class, 'showResult']);
$router->get('/account/results/{sessionId}/pdf', [AccountController::class, 'resultPdf']);
$router->post('/account/results/{sessionId}/detach', [AccountController::class, 'detach']);
$router->post('/account/attach', [AccountController::class, 'attach']);
$router->post('/account/delete', [AccountController::class, 'delete']);

// Owner-only clinical lifecycle controls. The routes fail closed until an
// Argon2id password hash is configured outside Git.
$router->get('/admin/login', [OwnerController::class, 'login']);
$router->post('/admin/login', [OwnerController::class, 'authenticate']);
$router->post('/admin/logout', [OwnerController::class, 'logout']);
$router->get('/admin', [OwnerController::class, 'dashboard']);
$router->post('/admin/case/lookup', [OwnerController::class, 'lookupCase']);
$router->post('/admin/case/assign', [OwnerController::class, 'assignCase']);
$router->post('/admin/case/delete', [OwnerController::class, 'deleteCase']);
$router->post('/admin/invites/create', [OwnerController::class, 'createInvite']);
$router->post('/admin/invites/revoke', [OwnerController::class, 'revokeInvite']);
$router->get('/admin/invited-case/{sessionId}', [OwnerController::class, 'viewInvitedCase']);
$router->post('/admin/invited-case/{sessionId}/delete', [OwnerController::class, 'deleteInvitedCase']);
$router->get('/admin/clients', [OwnerController::class, 'clients']);
$router->post('/admin/clients/create', [OwnerController::class, 'createClient']);
$router->get('/admin/clients/{clientId}', [OwnerController::class, 'viewClient']);
$router->post('/admin/clients/{clientId}/update', [OwnerController::class, 'updateClient']);
$router->post('/admin/clients/{clientId}/invites/create', [OwnerController::class, 'createClientInvite']);
$router->post('/admin/clients/{clientId}/delete', [OwnerController::class, 'deleteClient']);

// Pair comparison results
$router->get('/pair/{id}', [ResultController::class, 'pairShow']);
$router->get('/pair/{id}/pdf', [ResultController::class, 'pairPdf']);

// Legacy payment/AI routes are intentionally retired until the new YooKassa flow is ready.
$router->get('/interpretation/{token}', [RetiredPaymentController::class, 'interpretation']);
$router->post('/interpretation/{token}/pay', [RetiredPaymentController::class, 'payment']);

// Retire the legacy YooMoney webhook without accepting or processing payloads.
$router->post('/webhook/yoomoney', [RetiredPaymentController::class, 'yoomoneyWebhook']);

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

// All browser state-changing routes require a session-bound token. The retired
// legacy YooMoney webhook is explicitly exempt because it processes no payload
// and always returns 410; a future provider webhook needs provider validation.
$router->middleware(new CsrfMiddleware(['/webhook/yoomoney']));

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
    
} catch (\Throwable $e) {
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
