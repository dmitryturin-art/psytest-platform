<?php
/**
 * Security Helper
 * 
 * Common security utilities for XSS protection, CSRF, input sanitization
 */

declare(strict_types=1);

namespace PsyTest\Core;

class Security
{
    /**
     * Sanitize string output (prevent XSS)
     */
    public static function h(?string $string): string
    {
        if ($string === null) {
            return '';
        }
        
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Sanitize string for HTML attribute
     */
    public static function ha(?string $string): string
    {
        if ($string === null) {
            return '';
        }
        
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Sanitize string for JavaScript context
     */
    public static function jsEncode(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }
    
    /**
     * Sanitize HTML (allow only safe tags)
     */
    public static function sanitizeHtml(?string $html): string
    {
        if ($html === null) {
            return '';
        }
        
        // Strip all tags except allowed ones
        $allowedTags = '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre>';
        
        return strip_tags($html, $allowedTags);
    }
    
    /**
     * Sanitize input string
     */
    public static function sanitizeInput(?string $input): string
    {
        if ($input === null) {
            return '';
        }
        
        // Trim whitespace
        $input = trim($input);
        
        // Remove null bytes
        $input = str_replace(chr(0), '', $input);
        
        // Strip tags
        $input = strip_tags($input);
        
        return $input;
    }
    
    /**
     * Sanitize email
     */
    public static function sanitizeEmail(?string $email): string
    {
        if ($email === null) {
            return '';
        }
        
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }
    
    /**
     * Validate email
     */
    public static function isValidEmail(?string $email): bool
    {
        if ($email === null) {
            return false;
        }
        
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Sanitize integer
     */
    public static function sanitizeInt(mixed $value): int
    {
        return (int) $value;
    }
    
    /**
     * Sanitize float
     */
    public static function sanitizeFloat(mixed $value): float
    {
        return (float) $value;
    }
    
    /**
     * Sanitize array of strings
     */
    public static function sanitizeArray(array $array): array
    {
        return array_map(fn($value) => is_string($value) ? self::sanitizeInput($value) : $value, $array);
    }
    
    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string
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
    public static function verifyCsrfToken(?string $token): bool
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
     * Get CSRF token HTML input
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::generateCsrfToken() . '">';
    }
    
    /**
     * Require CSRF token (call at start of POST requests)
     */
    public static function requireCsrf(?string $token = null): void
    {
        $token = $token ?? ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        
        if (!self::verifyCsrfToken($token)) {
            http_response_code(403);
            die('Invalid CSRF token');
        }
    }
    
    /**
     * Rate limiting helper
     */
    public static function rateLimit(string $key, int $maxRequests, int $timeWindow): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $rateKey = 'rate_' . $key;
        $now = time();
        
        if (!isset($_SESSION[$rateKey])) {
            $_SESSION[$rateKey] = ['count' => 0, 'reset' => $now + $timeWindow];
        }
        
        $rate = &$_SESSION[$rateKey];
        
        // Reset if window expired
        if ($now > $rate['reset']) {
            $rate = ['count' => 0, 'reset' => $now + $timeWindow];
        }
        
        $rate['count']++;
        
        return $rate['count'] <= $maxRequests;
    }
    
    /**
     * Check if request is over HTTPS
     */
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on';
    }
    
    /**
     * Require HTTPS
     */
    public static function requireHttps(): void
    {
        if (!self::isHttps()) {
            $httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            header('Location: ' . $httpsUrl, true, 301);
            exit;
        }
    }
    
    /**
     * Get client IP (with proxy handling)
     */
    public static function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? 
              $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
              $_SERVER['HTTP_X_REAL_IP'] ?? 
              $_SERVER['REMOTE_ADDR'] ?? 
              '0.0.0.0';
        
        // Handle multiple IPs in X-Forwarded-For
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        
        return $ip;
    }
    
    /**
     * Validate UUID
     */
    public static function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
    
    /**
     * Validate session token format
     */
    public static function isValidToken(string $token): bool
    {
        return ctype_xdigit($token) && strlen($token) >= 32;
    }
    
    /**
     * Secure file upload validation
     */
    public static function validateUpload(array $file, array $allowedTypes = [], ?int $maxSize = null): array
    {
        $errors = [];
        
        // Check upload error
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload error: ' . $file['error'];
            return ['valid' => false, 'errors' => $errors];
        }
        
        // Check file size
        if ($maxSize !== null && $file['size'] > $maxSize) {
            $errors[] = 'File too large: ' . $file['size'] . ' bytes (max: ' . $maxSize . ')';
        }
        
        // Check MIME type
        if (!empty($allowedTypes)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            
            if (!in_array($mimeType, $allowedTypes, true)) {
                $errors[] = 'Invalid file type: ' . $mimeType;
            }
        }
        
        // Check extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
        
        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'Invalid file extension: ' . $extension;
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * Generate secure random string
     */
    public static function randomString(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Hash password (if needed)
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Verify password hash
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
