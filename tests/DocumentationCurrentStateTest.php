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

        preg_match_all('/\$router->(?:get|post)\(\'([^\']+)\'/', $routes, $matches);
        $registeredRoutes = array_values(array_unique($matches[1] ?? []));

        self::assertNotEmpty($registeredRoutes, 'No public routes were parsed from public/index.php.');

        foreach ($registeredRoutes as $route) {
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
        self::assertStringContainsString('не включает payment', $development);
        // Этап 07 включает ИИ намеренно, но заполненный ключ сам по себе ничего
        // не запускает — документация обязана говорить именно это.
        self::assertStringContainsString('Ключ хранится только в environment', $development);
        self::assertStringContainsString('заполненный ключ ничего не включает', $development);
        self::assertStringContainsString('соответствует фактическому `TestModuleInterface`', $newModuleGuide);
        self::assertStringNotContainsString('renderResults', $newModuleGuide, 'Module HTML rendering is retired; sections are the only path.');
        self::assertStringNotContainsString('Chart.js', $newModuleGuide, 'External chart libraries are banned.');
        self::assertStringNotContainsString('bin/install-db.php', $architectureCheck);
        self::assertStringNotContainsString('QUICKSTART.md', $architectureCheck);
        self::assertStringContainsString('composer migrate', $architectureCheck);
    }

    /**
     * Pins the new-module recipe to the walkthrough actually performed on a clean
     * clone (26.08.2026). Each assertion corresponds to a step that failed or
     * printed a warning when the previous version of the guide was followed literally.
     */
    public function testNewModuleGuideMatchesTheVerifiedWalkthroughRecipe(): void
    {
        $projectRoot = dirname(__DIR__);
        $guide = (string) file_get_contents($projectRoot . '/docs/creating-new-test.md');
        $referenceMigration = (string) file_get_contents(
            $projectRoot . '/database/migrations/20260708123506_add_lazarus_test.php'
        );
        $composer = (string) file_get_contents($projectRoot . '/composer.json');

        // Phinx AbstractMigration has no insert(); the documented snippet must use
        // the same API as the reference migration it points at.
        self::assertStringNotContainsString(
            "\$this->insert('tests'",
            $guide,
            'AbstractMigration::insert() does not exist — the snippet must use table()->insert()->saveData().'
        );
        self::assertStringContainsString('->saveData()', $guide);
        self::assertStringContainsString("table('tests')->insert(", $referenceMigration);
        self::assertStringContainsString('->saveData()', $referenceMigration);

        // Registering the methodology is a hard gate step, not a footnote:
        // MethodologyRegistryContractTest fails without it.
        self::assertStringContainsString('methodology-registry.json', $guide);

        // A new module needs its own PSR-4 entry, otherwise every composer install
        // prints a psr-4 compliance warning for the kebab-case module directory.
        self::assertStringContainsString('"PsyTest\\\\Modules\\\\MyTest\\\\": "modules/my-test/"', $guide);

        // bin/check-architecture.php only covers the five current modules; the guide
        // must not claim that editing it is required for a green gate.
        self::assertStringNotContainsString('добавьте новый модуль в requiredFiles', $guide);

        // Module fixtures live in kebab-case directories and can never satisfy the
        // PsyTest\Tests\ => tests/ rule; they are loaded by ModuleLoader, not Composer.
        self::assertStringContainsString('"exclude-from-classmap"', $composer);
        self::assertStringContainsString('"tests/fixtures/"', $composer);
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

    public function testQualityGateCoversBegetAndCurrentMysqlVersions(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/quality.yml');

        self::assertStringContainsString("mysql: ['5.7', '8.0']", $workflow);
        self::assertStringContainsString('image: mysql:${{ matrix.mysql }}', $workflow);
        self::assertStringContainsString('run: composer migrate', $workflow);
        self::assertStringContainsString('run: composer test:fast', $workflow);
        self::assertStringContainsString('run: composer test:database', $workflow);
        self::assertStringContainsString("if: needs.quality.outputs.database == 'true'", $workflow);
    }
}
