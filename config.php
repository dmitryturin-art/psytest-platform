<?php
/**
 * PsyTest Platform Configuration
 * 
 * Loads environment variables and provides configuration access
 */

declare(strict_types=1);

// Load environment variables from .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (trim($line) === '' || str_starts_with(trim($line), '#')) {
            continue;
        }
        
        // Parse KEY=VALUE
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            if (getenv($key) === false) {
                putenv("$key=$value");
            }
        }
    }
}

return new class {
    
    public function get(string $key, mixed $default = null): mixed {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
    
    public function getString(string $key, string $default = ''): string {
        return (string) $this->get($key, $default);
    }
    
    public function getInt(string $key, int $default = 0): int {
        return (int) $this->get($key, $default);
    }
    
    public function getBool(string $key, bool $default = false): bool {
        $value = $this->get($key);
        if ($value === false) {
            return $default;
        }
        return in_array(strtolower((string) $value), ['true', '1', 'yes', 'on'], true);
    }
    
    public function getArray(string $key, array $default = []): array {
        $value = $this->get($key);
        if ($value === false) {
            return $default;
        }
        return json_decode((string) $value, true) ?? $default;
    }
    
    // Database configuration
    public function db(): array {
        return [
            'host' => $this->getString('DB_HOST', 'localhost'),
            'name' => $this->getString('DB_NAME', 'psytest'),
            'user' => $this->getString('DB_USER', 'root'),
            'pass' => $this->getString('DB_PASS', ''),
            'charset' => $this->getString('DB_CHARSET', 'utf8mb4'),
        ];
    }
    
    public function dsn(): string {
        $db = $this->db();
        return "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
    }
    
    // Application settings
    public function appName(): string {
        return $this->getString('APP_NAME', 'PsyTest');
    }
    
    public function appUrl(): string {
        return rtrim($this->getString('APP_URL', 'http://localhost'), '/');
    }
    
    public function isDebug(): bool {
        return $this->getBool('APP_DEBUG', true);
    }
    
    public function isProduction(): bool {
        return $this->getString('APP_ENV', 'development') === 'production';
    }
    
    // Session settings
    public function sessionTtlDays(): int {
        return $this->getInt('SESSION_TTL_DAYS', 30);
    }
    
    public function sessionTokenLength(): int {
        return $this->getInt('SESSION_TOKEN_LENGTH', 32);
    }
    
    // Security
    public function csrfEnabled(): bool {
        return $this->getBool('CSRF_ENABLED', true);
    }
    
    public function encryptionKey(): string {
        $key = $this->getString('ENCRYPTION_KEY');
        if (empty($key)) {
            throw new RuntimeException('ENCRYPTION_KEY is not set');
        }
        return $key;
    }
    
    // YooMoney
    public function yoomoneyShopId(): string {
        return $this->getString('YOOMONEY_SHOP_ID');
    }
    
    public function yoomoneyApiKey(): string {
        return $this->getString('YOOMONEY_API_KEY');
    }
    
    public function yoomoneyWebhookSecret(): string {
        return $this->getString('YOOMONEY_WEBHOOK_SECRET');
    }
    
    // OpenRouter AI
    public function openrouterApiKey(): string {
        $key = $this->getString('OPENROUTER_API_KEY');
        if (empty($key) && !$this->isProduction()) {
            return ''; // Allow empty in development
        }
        return $key;
    }
    
    public function openrouterModel(): string {
        return $this->getString('OPENROUTER_MODEL', 'deepseek/deepseek-chat');
    }
    
    // Email
    public function mailFrom(): string {
        return $this->getString('MAIL_FROM', 'noreply@psytest.local');
    }
    
    public function mailConfig(): array {
        return [
            'host' => $this->getString('MAIL_HOST'),
            'port' => $this->getInt('MAIL_PORT', 587),
            'user' => $this->getString('MAIL_USER'),
            'pass' => $this->getString('MAIL_PASS'),
            'encryption' => $this->getString('MAIL_ENCRYPTION', 'tls'),
        ];
    }
    
    // File storage
    public function pdfStoragePath(): string {
        return $this->getString('PDF_STORAGE_PATH', __DIR__ . '/storage/pdfs');
    }
    
    public function uploadMaxSize(): int {
        return $this->getInt('UPLOAD_MAX_SIZE', 10485760);
    }
    
    // Logging
    public function logLevel(): string {
        return $this->getString('LOG_LEVEL', 'info');
    }
    
    public function logPath(): string {
        return $this->getString('LOG_PATH', __DIR__ . '/storage/logs');
    }
};
