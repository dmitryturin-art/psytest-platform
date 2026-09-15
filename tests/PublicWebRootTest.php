<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class PublicWebRootTest extends TestCase
{
    public function testPublicPhpSurfaceContainsOnlyTheFrontController(): void
    {
        $publicRoot = dirname(__DIR__) . '/public';
        $phpFiles = glob($publicRoot . '/*.php');

        self::assertIsArray($phpFiles);
        self::assertSame(['index.php'], array_map('basename', $phpFiles));
        self::assertFileDoesNotExist($publicRoot . '/demo.php');
        self::assertFileDoesNotExist($publicRoot . '/test-smil.php');
    }

    public function testFrontControllerDefinesProductionResponseHardening(): void
    {
        $entryPoint = file_get_contents(dirname(__DIR__) . '/public/index.php');

        self::assertIsString($entryPoint);
        self::assertStringContainsString("header_remove('X-Powered-By');", $entryPoint);
        self::assertStringContainsString('catch (\\Throwable $e)', $entryPoint);
    }

    public function testApacheIsTheSingleSourceOfSecurityHeaders(): void
    {
        $entryPoint = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
        $htaccess = (string) file_get_contents(dirname(__DIR__) . '/public/.htaccess');
        $headers = [
            'X-Frame-Options',
            'X-XSS-Protection',
            'X-Content-Type-Options',
            'Referrer-Policy',
            'Permissions-Policy',
        ];

        foreach ($headers as $header) {
            self::assertStringContainsString($header, $htaccess);
            self::assertStringNotContainsString($header, $entryPoint);
        }
    }

    public function testHtaccessRoutesMissingResourcesToFrontControllerInPublicRoot(): void
    {
        $htaccess = file_get_contents(dirname(__DIR__) . '/public/.htaccess');

        self::assertIsString($htaccess);
        self::assertStringContainsString('RewriteRule ^ index.php [QSA,L]', $htaccess);
        self::assertStringNotContainsString('public/index.php', $htaccess);
        self::assertStringNotContainsString('RewriteCond %{REQUEST_URI} !^/public/', $htaccess);
    }

    /**
     * Каждая страница раньше просила `/favicon.ico`, которого не было, и
     * получала 404 в консоли. Иконка обязана лежать в web root и быть
     * git-tracked — иначе она не попадёт в релизный артефакт
     * (`bin/build-release.sh` сверяет артефакт с `git ls-files public`).
     */
    public function testFaviconIsServedFromTheWebRootAndIsTracked(): void
    {
        $publicRoot = dirname(__DIR__) . '/public';
        $tracked = [];
        exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' ls-files public', $tracked);

        foreach (['favicon.svg', 'favicon-32.png', 'favicon.ico'] as $file) {
            self::assertFileExists($publicRoot . '/' . $file);
            self::assertContains('public/' . $file, $tracked, "public/$file не под контролем версий.");
        }
    }

    public function testLayoutDeclaresTheFaviconInBothVectorAndRasterForm(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__) . '/templates/layout.twig');

        self::assertStringContainsString('rel="icon" href="{{ basePath }}/favicon.svg"', $layout);
        self::assertStringContainsString('rel="alternate icon" href="{{ basePath }}/favicon-32.png"', $layout);
    }

    public function testHtaccessEnforcesHttpsBehindHostingProxy(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__) . '/public/.htaccess');

        self::assertStringContainsString('RewriteCond %{HTTPS} !=on', $htaccess);
        self::assertStringContainsString('RewriteCond %{HTTP:X-Forwarded-Proto} !https [NC]', $htaccess);
        self::assertStringContainsString('RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]', $htaccess);
    }
}
