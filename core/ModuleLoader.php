<?php
/**
 * Module Loader
 * 
 * Discovers and loads test modules from the modules directory
 */

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Modules\TestModuleInterface;

class ModuleLoader
{
    private array $modules = [];
    private string $modulesPath;
    private ?Database $db = null;
    
    public function __construct(?string $modulesPath = null, ?Database $db = null)
    {
        $this->modulesPath = $modulesPath ?? __DIR__ . '/../modules';
        $this->db = $db ?? Database::getInstance();
    }
    
    /**
     * Discover and register all test modules
     */
    public function discover(): self
    {
        if (!is_dir($this->modulesPath)) {
            throw new \RuntimeException("Modules directory not found: {$this->modulesPath}");
        }
        
        $directories = glob($this->modulesPath . '/*', GLOB_ONLYDIR);
        
        if ($directories === false) {
            return $this;
        }
        
        foreach ($directories as $dir) {
            $this->loadModule($dir);
        }
        
        return $this;
    }
    
    /**
     * Load a single module from directory
     */
    private function loadModule(string $dir): void
    {
        $moduleName = basename($dir);
        $moduleFile = $dir . '/' . ucfirst($moduleName) . 'Module.php';
        
        if (!file_exists($moduleFile)) {
            error_log("Module file not found: $moduleFile");
            return;
        }
        
        require_once $moduleFile;
        
        // Get the class name from the module file
        $className = $this->getModuleClassName($dir);
        
        if (!class_exists($className)) {
            error_log("Module class not found: $className");
            return;
        }
        
        try {
            $instance = new $className();
            
            if (!$instance instanceof TestModuleInterface) {
                error_log("Module $className does not implement TestModuleInterface");
                return;
            }
            
            $metadata = $instance->getMetadata();
            
            $this->modules[$metadata['slug']] = [
                'instance' => $instance,
                'metadata' => $metadata,
                'path' => $dir,
                'class' => $className,
            ];
            
        } catch (\Exception $e) {
            error_log("Failed to load module $moduleName: " . $e->getMessage());
        }
    }
    
    /**
     * Get module class name from directory
     */
    private function getModuleClassName(string $dir): string
    {
        $moduleName = basename($dir);
        $className = ucfirst($moduleName) . 'Module';
        
        // Try to find the actual class in the file
        $content = file_get_contents($dir . '/' . ucfirst($moduleName) . 'Module.php');
        
        // Extract namespace and class from PHP file
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
            $namespace = trim($nsMatches[1]);
            if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                return $namespace . '\\' . $classMatches[1];
            }
        }
        
        // Fallback to PSR-4 convention
        return 'PsyTest\\Modules\\' . ucfirst($moduleName) . '\\' . $className;
    }
    
    /**
     * Get a module by slug
     */
    public function getModule(string $slug): ?TestModuleInterface
    {
        if (!isset($this->modules[$slug])) {
            return null;
        }
        
        return $this->modules[$slug]['instance'];
    }
    
    /**
     * Get module metadata by slug
     */
    public function getModuleMetadata(string $slug): ?array
    {
        if (!isset($this->modules[$slug])) {
            return null;
        }
        
        return $this->modules[$slug]['metadata'];
    }
    
    /**
     * Get all registered modules
     */
    public function getAllModules(): array
    {
        $result = [];
        foreach ($this->modules as $slug => $module) {
            $result[$slug] = $module['metadata'];
        }
        return $result;
    }
    
    /**
     * Get all active modules (from database)
     */
    public function getActiveModules(): array
    {
        $sql = "SELECT * FROM tests WHERE is_active = 1 ORDER BY sort_order, name";
        $tests = $this->db->select($sql);
        
        $result = [];
        foreach ($tests as $test) {
            if (isset($this->modules[$test['slug']])) {
                $result[$test['slug']] = array_merge(
                    $test,
                    $this->modules[$test['slug']]['metadata']
                );
            }
        }
        
        return $result;
    }
    
    /**
     * Check if a module exists
     */
    public function hasModule(string $slug): bool
    {
        return isset($this->modules[$slug]);
    }
    
    /**
     * Get module path
     */
    public function getModulePath(string $slug): ?string
    {
        if (!isset($this->modules[$slug])) {
            return null;
        }
        
        return $this->modules[$slug]['path'];
    }
}
