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
        self::assertStringContainsString("Referrer-Policy: strict-origin-when-cross-origin", $entryPoint);
        self::assertStringContainsString("Permissions-Policy: geolocation=(), camera=(), microphone=()", $entryPoint);
        self::assertStringContainsString('catch (\\Throwable $e)', $entryPoint);
    }
}
