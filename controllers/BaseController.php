<?php
/**
 * Base Controller
 * 
 * Provides common functionality for all controllers
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

use PsyTest\Core\Database;
use PsyTest\Core\View;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\TestModuleInterface;

abstract class BaseController
{
    protected Database $db;
    protected View $view;
    protected ModuleLoader $moduleLoader;
    protected SessionManager $sessionManager;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->view = View::getInstance();
        $this->moduleLoader = (new ModuleLoader(null, $this->db))->discover();
        $this->sessionManager = new SessionManager($this->db);
    }
    
    /**
     * Get module or render 404 error
     */
    protected function getModuleOrFail(string $slug): TestModuleInterface
    {
        $module = $this->moduleLoader->getModule($slug);
        
        if (!$module) {
            http_response_code(404);
            echo $this->view->render('error-page', [
                'error' => 'Test not found',
                'message' => "Test '{$slug}' does not exist or is not available."
            ]);
            exit;
        }
        
        return $module;
    }
    
    /**
     * Get test from database or fail
     */
    protected function getTestOrFail(string $slug): array
    {
        $test = $this->db->selectOne(
            'SELECT * FROM tests WHERE slug = ? AND is_active = 1',
            [$slug]
        );
        
        if (!$test) {
            http_response_code(404);
            echo $this->view->render('error-page', [
                'error' => 'Test not found',
                'message' => "Test '{$slug}' is not available."
            ]);
            exit;
        }
        
        return $test;
    }
    
    /**
     * Render JSON response
     */
    protected function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    /**
     * Render error response
     */
    protected function errorResponse(string $message, int $statusCode = 400): void
    {
        $this->jsonResponse(['error' => $message], $statusCode);
    }
}