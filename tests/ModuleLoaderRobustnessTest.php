<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\ModuleLoader;

/**
 * Discovery must survive a hostile modules directory: a stray subdirectory is
 * not an error, and one broken module must not take the whole catalog down.
 */
final class ModuleLoaderRobustnessTest extends TestCase
{
    private string $root = '';
    private string $errorLog = '';
    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psytest-loader-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/modules', 0777, true);

        $this->errorLog = $this->root . '/error.log';
        touch($this->errorLog);
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        self::removeTree($this->root);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) ? self::removeTree($child) : unlink($child);
        }

        rmdir($path);
    }

    private function writeModule(string $dir, string $className, string $body): void
    {
        $path = $this->root . '/modules/' . $dir;
        mkdir($path, 0777, true);
        file_put_contents($path . '/metadata.json', json_encode([
            'slug' => $dir,
            'name' => $className,
            'question_count' => 1,
            'estimated_time' => 1,
        ]));
        file_put_contents($path . '/questions.json', json_encode([
            ['id' => 1, 'text' => 'q', 'options' => [['value' => 0, 'text' => 'a']]],
        ]));
        file_put_contents($path . '/' . $className . 'Module.php', $body);
    }

    private function logContents(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    /**
     * @param mixed $payload
     */
    private function isUsableRegistry($payload): bool
    {
        $method = new \ReflectionMethod(ModuleLoader::class, 'isUsableRegistry');

        return (bool) $method->invoke(null, $payload);
    }

    public function testCacheKeyIsScopedToTheModulesPath(): void
    {
        $method = new \ReflectionMethod(ModuleLoader::class, 'cacheKey');

        $defaultKey = $method->invoke(new ModuleLoader(null, null));
        $fixtureKey = $method->invoke(new ModuleLoader($this->root . '/modules', null));

        self::assertNotSame($defaultKey, $fixtureKey, 'Registries of different modules paths must not share one APCu entry.');
    }

    public function testOnlyARegistryOfLiveModuleInstancesIsTrusted(): void
    {
        $module = new class () extends \PsyTest\Modules\BaseTestModule {
            public function calculateResults(array $answers): array
            {
                return [];
            }

            public function generateInterpretation(array $scores): array
            {
                return ['summary' => '', 'recommendations' => []];
            }

            public function buildSections(array $results): array
            {
                return [];
            }
        };

        $live = ['demo' => [
            'instance' => $module,
            'metadata' => ['slug' => 'demo'],
            'path' => '/modules/demo',
            'class' => $module::class,
        ]];

        self::assertTrue($this->isUsableRegistry($live));

        // A payload that survived a release swap without its class definition.
        $incomplete = $live;
        $incomplete['demo']['instance'] = unserialize('O:8:"GoneAway":0:{}');
        self::assertFalse($this->isUsableRegistry($incomplete));

        self::assertFalse($this->isUsableRegistry(false), 'An APCu miss must not be treated as a registry.');
        self::assertFalse($this->isUsableRegistry([]), 'An empty registry means the scan never ran.');
        self::assertFalse($this->isUsableRegistry(['demo' => ['metadata' => []]]), 'A registry entry without an instance is unusable.');
    }

    public function testStrayDirectoryIsNotReportedAsABrokenModule(): void
    {
        mkdir($this->root . '/modules/golden');
        file_put_contents($this->root . '/modules/golden/fixture.json', '{}');

        $loader = (new ModuleLoader($this->root . '/modules', null))->discover();

        self::assertSame([], $loader->getAllModules());
        self::assertSame('', trim($this->logContents()), 'A directory that is not a module must not be logged as one.');
    }

    public function testDirectoryDeclaringAModuleStillReportsItsMissingFile(): void
    {
        mkdir($this->root . '/modules/half-built');
        file_put_contents($this->root . '/modules/half-built/metadata.json', '{"slug":"half-built"}');

        (new ModuleLoader($this->root . '/modules', null))->discover();

        self::assertStringContainsString('HalfBuiltModule.php', $this->logContents());
    }

    public function testModuleThrowingAnErrorDoesNotAbortDiscovery(): void
    {
        $this->writeModule('healthy-one', 'HealthyOne', <<<'SRC'
            <?php
            namespace PsyTest\Tests\LoaderFixtures\HealthyOne;
            use PsyTest\Modules\BaseTestModule;
            use PsyTest\Modules\ResultSection;
            final class HealthyOneModule extends BaseTestModule
            {
                public function calculateResults(array $answers): array { return ['total' => 0]; }
                public function generateInterpretation(array $scores): array { return ['summary' => '', 'recommendations' => []]; }
                public function buildSections(array $results): array { return []; }
            }
            SRC);

        $this->writeModule('fatal-one', 'FatalOne', <<<'SRC'
            <?php
            namespace PsyTest\Tests\LoaderFixtures\FatalOne;
            use PsyTest\Modules\BaseTestModule;
            final class FatalOneModule extends BaseTestModule
            {
                protected function initialize(): void { throw new \TypeError('broken module'); }
                public function calculateResults(array $answers): array { return []; }
                public function generateInterpretation(array $scores): array { return ['summary' => '', 'recommendations' => []]; }
                public function buildSections(array $results): array { return []; }
            }
            SRC);

        $loader = (new ModuleLoader($this->root . '/modules', null))->discover();

        self::assertArrayHasKey('healthy-one', $loader->getAllModules(), 'A broken module must not hide the healthy ones.');
        self::assertArrayNotHasKey('fatal-one', $loader->getAllModules());
        self::assertStringContainsString('broken module', $this->logContents());
    }
}
