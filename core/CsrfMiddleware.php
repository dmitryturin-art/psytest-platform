<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Rejects unsafe browser requests without a valid session-bound CSRF token.
 * Provider webhooks must be explicitly listed as exceptions and use their own
 * authentication mechanism; no route is exempt by default.
 */
final class CsrfMiddleware
{
    /** @param list<string> $excludedPaths */
    public function __construct(private array $excludedPaths = [])
    {
    }

    /** @param array<string, string> $params */
    public function __invoke(string $method, string $uri, array &$params): ?string
    {
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || in_array($uri, $this->excludedPaths, true)) {
            return null;
        }

        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
        if (Security::verifyCsrfToken(is_string($token) ? $token : null)) {
            return null;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');

        return json_encode([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], JSON_UNESCAPED_UNICODE);
    }
}
