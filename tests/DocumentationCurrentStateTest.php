<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class DocumentationCurrentStateTest extends TestCase
{
    private const CURRENT_STATE_DOCUMENTS = [
        'README.md',
        'DEVELOPMENT.md',
        'ARCHITECTURE.md',
    ];

    public function testArchitectureDocumentsEveryCurrentPublicRoute(): void
    {
        $projectRoot = dirname(__DIR__);
        $routes = (string) file_get_contents($projectRoot . '/public/index.php');
        $architecture = (string) file_get_contents($projectRoot . '/ARCHITECTURE.md');

        foreach ([
            '/',
            '/tests',
            '/test/{slug}',
            '/test/{slug}/save',
            '/test/{slug}/submit',
            '/test/{slug}/pair',
            '/test/{slug}/pair/submit',
            '/result/{slug}/{token}',
            '/result/{slug}/{token}/pdf',
            '/result/{slug}/{token}/pair-status',
            '/result/{token}/delete',
            '/pair/{id}',
            '/pair/{id}/pdf',
            '/api/health',
            '/privacy',
            '/terms',
            '/deleted',
            '/error/{code}',
            '/interpretation/{token}',
            '/interpretation/{token}/pay',
            '/webhook/yoomoney',
        ] as $route) {
            self::assertStringContainsString("'{$route}'", $routes, $route);
            self::assertStringContainsString("`{$route}`", $architecture, $route);
        }
    }

    public function testCurrentDeveloperDocsDoNotAdvertiseRetiredCommandsOrRoutes(): void
    {
        $projectRoot = dirname(__DIR__);
        $development = (string) file_get_contents($projectRoot . '/DEVELOPMENT.md');
        $architecture = (string) file_get_contents($projectRoot . '/ARCHITECTURE.md');
        $routes = (string) file_get_contents($projectRoot . '/public/index.php');
        $newModuleGuide = (string) file_get_contents($projectRoot . '/docs/creating-new-test.md');
        $architectureCheck = (string) file_get_contents($projectRoot . '/bin/check-architecture.php');

        self::assertStringNotContainsString('test-architecture.php', $development);
        self::assertStringNotContainsString('public/demo.php', $development);
        self::assertStringNotContainsString("'/api/yoomoney/webhook'", $routes);
        self::assertStringContainsString('Маршрута `/api/yoomoney/webhook` нет.', $architecture);
        self::assertStringContainsString('php bin/check-architecture.php', $development);
        self::assertStringContainsString('`410 Gone`', $architecture);
        self::assertStringContainsString('не включают payment или AI', $development);
        self::assertStringContainsString('Исторический черновик — не исполнять как текущую инструкцию', $newModuleGuide);
        self::assertStringNotContainsString('bin/install-db.php', $architectureCheck);
        self::assertStringNotContainsString('QUICKSTART.md', $architectureCheck);
        self::assertStringContainsString('composer migrate', $architectureCheck);
    }

    public function testCurrentStateDocumentationLinksResolveLocally(): void
    {
        $projectRoot = dirname(__DIR__);

        foreach (self::CURRENT_STATE_DOCUMENTS as $document) {
            $contents = (string) file_get_contents($projectRoot . '/' . $document);
            preg_match_all('/!?\[[^\]]*]\(([^)]+)\)/', $contents, $matches);

            foreach ($matches[1] as $target) {
                $target = trim((string) $target, " <>\"");

                if ($target === '' || $target[0] === '#' || preg_match('#^[a-z]+://#i', $target) === 1) {
                    continue;
                }

                $path = explode('#', $target, 2)[0];
                self::assertFileExists($projectRoot . '/' . $path, "{$document}: {$target}");
            }
        }
    }
}
