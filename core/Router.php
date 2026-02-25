<?php
/**
 * Router
 * 
 * Simple MVC-style router with support for RESTful routes
 */

declare(strict_types=1);

namespace PsyTest\Core;

class Router
{
    private array $routes = [];
    private array $middleware = [];
    private ?string $basePath = null;
    
    /**
     * Set base path for URL generation
     */
    public function setBasePath(string $path): void
    {
        $this->basePath = rtrim($path, '/');
    }
    
    /**
     * Get base path
     */
    public function getBasePath(): string
    {
        return $this->basePath ?? '';
    }
    
    /**
     * Register a GET route
     */
    public function get(string $path, callable|array $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }
    
    /**
     * Register a POST route
     */
    public function post(string $path, callable|array $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }
    
    /**
     * Register a PUT route
     */
    public function put(string $path, callable|array $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }
    
    /**
     * Register a DELETE route
     */
    public function delete(string $path, callable|array $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }
    
    /**
     * Register a route for multiple methods
     */
    public function match(array $methods, string $path, callable|array $handler): self
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $path, $handler);
        }
        return $this;
    }
    
    /**
     * Add a route to the collection
     */
    private function addRoute(string $method, string $path, callable|array $handler): self
    {
        $path = '/' . trim($path, '/');
        
        // Convert path parameters to regex
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';
        
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler,
        ];
        
        return $this;
    }
    
    /**
     * Register global middleware
     */
    public function middleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }
    
    /**
     * Dispatch the request to the appropriate handler
     */
    public function dispatch(?string $uri = null, ?string $method = null): mixed
    {
        $uri = $uri ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $method ?? $_SERVER['REQUEST_METHOD'];
        
        // Remove base path if set
        if ($this->basePath && str_starts_with($uri, $this->basePath)) {
            $uri = substr($uri, strlen($this->basePath));
        }
        
        $uri = '/' . trim($uri, '/');
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            
            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                
                // Run middleware
                foreach ($this->middleware as $middleware) {
                    $result = $middleware($method, $uri, $params);
                    if ($result !== null) {
                        return $result;
                    }
                }
                
                // Call handler
                return $this->callHandler($route['handler'], $params);
            }
        }
        
        // No route found
        http_response_code(404);
        return $this->renderError('Page not found', 404);
    }
    
    /**
     * Call the route handler (supports closures and controller arrays)
     */
    private function callHandler(callable|array $handler, array $params): mixed
    {
        if (is_callable($handler)) {
            return call_user_func_array($handler, $params);
        }
        
        if (is_array($handler) && count($handler) === 2) {
            [$controller, $action] = $handler;
            
            if (is_string($controller) && class_exists($controller)) {
                $instance = new $controller();
                if (method_exists($instance, $action)) {
                    return call_user_func_array([$instance, $action], $params);
                }
            }
        }
        
        throw new \RuntimeException("Invalid route handler");
    }
    
    /**
     * Generate a URL for a named route (future enhancement)
     */
    public function route(string $name, array $params = []): string
    {
        // Named routes can be implemented here
        return $this->basePath . '/' . trim($name, '/');
    }
    
    /**
     * Redirect to a URL
     */
    public function redirect(string $url, int $statusCode = 302): void
    {
        header("Location: $url", true, $statusCode);
        exit;
    }
    
    /**
     * Render JSON response
     */
    public function json(mixed $data, int $statusCode = 200): string
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
    /**
     * Render an error page
     */
    public function renderError(string $message, int $statusCode = 500): string
    {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');
        
        return sprintf(
            '<!DOCTYPE html><html><head><title>Error %d</title></head>' .
            '<body><h1>Error %d</h1><p>%s</p></body></html>',
            $statusCode,
            $statusCode,
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        );
    }
    
    /**
     * Get all registered routes (for debugging)
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
