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
    private const CACHE_KEY = 'psytest_modules_registry';
    private const CACHE_TTL = 3600; // 1 час

    private array $modules = [];
    private string $modulesPath;
    private ?Database $db = null;

    public function __construct(?string $modulesPath = null, ?Database $db = null)
    {
        $this->modulesPath = $modulesPath ?? __DIR__ . '/../modules';
        $this->db = $db;
    }

    /**
     * Lazy database accessor: module discovery must not require a connection.
     */
    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }

    /**
     * Discover and register all test modules
     */
    public function discover(): self
    {
        // Попытка загрузить из кэша APCu
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($this->cacheKey());
            if (self::isUsableRegistry($cached)) {
                $this->modules = $cached;
                return $this;
            }
        }

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

        // Сохранение в кэш APCu
        if (function_exists('apcu_store')) {
            apcu_store($this->cacheKey(), $this->modules, self::CACHE_TTL);
        }

        return $this;
    }

    /**
     * Load a single module from directory
     */
    private function loadModule(string $dir): void
    {
        $moduleName = basename($dir);
        // Convert kebab-case to PascalCase: beck-anxiety → BeckAnxiety
        $className = str_replace(' ', '', ucwords(str_replace('-', ' ', $moduleName)));
        $moduleFile = $dir . '/' . $className . 'Module.php';

        if (!file_exists($moduleFile)) {
            // Чужая директория внутри modules/ — это не сломанный модуль, а просто
            // не модуль. Сообщаем только если каталог сам объявляет себя модулем.
            if (file_exists($dir . '/metadata.json') || file_exists($dir . '/questions.json')) {
                error_log("Module file not found: $moduleFile");
            }
            return;
        }

        require_once $moduleFile;

        // Get the class name from the module file
        $actualClassName = $this->getModuleClassName($dir);

        if (!class_exists($actualClassName)) {
            error_log("Module class not found: $actualClassName");
            return;
        }

        try {
            $instance = new $actualClassName();

            if (!$instance instanceof TestModuleInterface) {
                error_log("Module $actualClassName does not implement TestModuleInterface");
                return;
            }

            $metadata = $instance->getMetadata();

            $this->modules[$metadata['slug']] = [
                'instance' => $instance,
                'metadata' => $metadata,
                'path' => $dir,
                'class' => $actualClassName,
            ];

        } catch (\Throwable $e) {
            // Ошибка одного модуля (в том числе \Error из его конструктора) не должна
            // обрушивать discover() и вместе с ним весь каталог тестов.
            error_log("Failed to load module $moduleName: " . $e->getMessage());
        }
    }

    /**
     * Кэш реестра привязан к конкретному modules-пути: загрузчик вызывается и с
     * нестандартным путём (тестовые фикстуры), и такие реестры не должны
     * подменять друг друга в общем APCu.
     */
    private function cacheKey(): string
    {
        return self::CACHE_KEY . ':' . md5($this->modulesPath);
    }

    /**
     * Кэш переживает выкладку релиза и изменение классов, поэтому доверяем ему
     * только пока каждая запись несёт живой инстанс модуля. Всё остальное
     * (например `__PHP_Incomplete_Class` после смены релиза или payload прежней
     * формы) отбрасывается в пользу свежего сканирования, а не отдаётся наружу.
     *
     * @param mixed $cached
     */
    private static function isUsableRegistry($cached): bool
    {
        if (!is_array($cached) || $cached === []) {
            return false;
        }

        foreach ($cached as $entry) {
            if (!is_array($entry)) {
                return false;
            }

            if (!isset($entry['instance'], $entry['metadata'], $entry['path'], $entry['class'])) {
                return false;
            }

            if (!$entry['instance'] instanceof TestModuleInterface) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get module class name from directory
     */
    private function getModuleClassName(string $dir): string
    {
        $moduleName = basename($dir);
        // Convert kebab-case to PascalCase: beck-anxiety → BeckAnxiety
        $className = str_replace(' ', '', ucwords(str_replace('-', ' ', $moduleName)));
        $moduleFile = $dir . '/' . $className . 'Module.php';

        // Try to find the actual class in the file
        if (file_exists($moduleFile)) {
            $content = file_get_contents($moduleFile);

            // Extract namespace and class from PHP file
            if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
                $namespace = trim($nsMatches[1]);
                if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                    return $namespace . '\\' . $classMatches[1];
                }
            }
        }

        // Fallback to PSR-4 convention
        return 'PsyTest\\Modules\\' . ucfirst($moduleName) . '\\' . $className . 'Module';
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
        $tests = $this->db()->select($sql);

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
     * Методики для публичного каталога.
     *
     * Методика с `visibility = invite` в каталоге не показывается: её тексты
     * не распространяются публично, а доступ выдаётся владельцем по ссылке.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPublicModules(): array
    {
        return array_filter(
            $this->getActiveModules(),
            static fn (array $test): bool => ($test['visibility'] ?? 'public') === 'public',
        );
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

    /**
     * Очистить кэш модулей (для разработки)
     */
    public function clearCache(): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete($this->cacheKey());
        }
    }
}
