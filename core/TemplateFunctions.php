<?php

declare(strict_types=1);

namespace PsyTest\Core;

use Twig\Environment;
use Twig\TwigFunction;

/**
 * Функции, доступные шаблонам.
 *
 * Вынесены отдельно, потому что шаблоны рендерит не только `View`: их собирают
 * и тесты. Пока набор функций жил внутри `View`, добавление новой ломало все
 * тесты рендеринга разом — они строили свой Twig и о функции не знали.
 */
final class TemplateFunctions
{
    /**
     * @param callable(): string|null $csrfToken Источник CSRF-токена; в тестах не нужен.
     */
    public static function register(Environment $twig, string $basePath = '', ?callable $csrfToken = null): void
    {
        $twig->addFunction(new TwigFunction('csrf_field', static function () use ($csrfToken): string {
            $token = $csrfToken !== null ? $csrfToken() : '';

            return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('asset', static function (string $path) use ($basePath): string {
            // К адресу добавляется время изменения файла. Без этого браузер
            // продолжает показывать старую копию стилей после выкладки —
            // именно так новое оформление не доехало до владельца.
            $relative = ltrim($path, '/');
            $file = dirname(__DIR__) . '/public/' . $relative;
            $version = is_file($file) ? '?v=' . filemtime($file) : '';

            return $basePath . '/' . $relative . $version;
        }));
    }
}
