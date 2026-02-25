<?php
/**
 * View Renderer
 * 
 * Twig template rendering with shared variables
 */

declare(strict_types=1);

namespace PsyTest\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;

class View
{
    private static ?View $instance = null;
    private Environment $twig;
    private array $sharedData = [];
    
    private function __construct()
    {
        $templatesPath = __DIR__ . '/../templates';
        $cachePath = __DIR__ . '/../storage/cache/twig';
        
        // Create cache directory if needed
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }
        
        $loader = new FilesystemLoader($templatesPath);
        
        $configLoader = require __DIR__ . '/../config.php';
        $isDebug = $configLoader->isDebug();
        
        $this->twig = new Environment($loader, [
            'cache' => $isDebug ? false : $cachePath,
            'debug' => $isDebug,
            'auto_reload' => $isDebug,
            'strict_variables' => $isDebug,
        ]);
        
        // Add debug extension
        if ($isDebug) {
            $this->twig->addExtension(new DebugExtension());
        }
        
        // Add global variables
        $this->twig->addGlobal('appName', $configLoader->appName());
        $this->twig->addGlobal('basePath', $this->getBasePath());
        $this->twig->addGlobal('isDebug', $isDebug);
        
        // Add custom functions
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', function() {
            return '<input type="hidden" name="csrf_token" value="' . $this->generateCsrfToken() . '">';
        }, ['is_safe' => ['html']]));
        
        $this->twig->addFunction(new \Twig\TwigFunction('asset', function(string $path) {
            return $this->getBasePath() . '/' . ltrim($path, '/');
        }));
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Render a template
     * 
     * @param string $template Template name (without .twig extension)
     * @param array $data Template variables
     * @return string Rendered HTML
     */
    public function render(string $template, array $data = []): string
    {
        // Merge shared data
        $data = array_merge($this->sharedData, $data);
        
        // Add CSRF token
        $data['csrf_token'] = $this->generateCsrfToken();
        
        return $this->twig->render($template . '.twig', $data);
    }
    
    /**
     * Render and send response
     */
    public function display(string $template, array $data = []): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->render($template, $data);
    }
    
    /**
     * Set shared data for all templates
     */
    public function share(string $key, mixed $value): void
    {
        $this->sharedData[$key] = $value;
    }
    
    /**
     * Set multiple shared variables
     */
    public function shareArray(array $data): void
    {
        $this->sharedData = array_merge($this->sharedData, $data);
    }
    
    /**
     * Get base path
     */
    public function getBasePath(): string
    {
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if ($basePath === '/' || $basePath === '\\') {
            return '';
        }
        // Go up one level from public/
        return dirname($basePath);
    }
    
    /**
     * Generate CSRF token
     */
    public function generateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verify CSRF token
     */
    public function verifyCsrfToken(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Get Twig environment
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }
}
