<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class DeploymentArtifactContractTest extends TestCase
{
    public function testProductionDependenciesIncludeMigrationRunner(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('robmorgan/phinx', $manifest['require']);
        self::assertArrayNotHasKey('robmorgan/phinx', $manifest['require-dev']);
    }

    public function testReleaseBuilderUsesCommittedTreeAndRefusesUnsafeOutputPath(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/bin/build-release.sh');

        self::assertStringContainsString('git archive --format=tar HEAD', $script);
        self::assertStringContainsString('"$SOURCE/" "$STAGE/"', $script);
        self::assertStringContainsString('^tmp/release-[A-Za-z0-9._-]+$', $script);
        self::assertStringNotContainsString('./ "$STAGE/"', $script);
    }

    /**
     * Локальная библиотека редактора доезжает до сервера (07.K5d).
     *
     * Исключение `/vendor` в сборке привязано к корню репозитория, а
     * `public/vendor` — совсем другой каталог: это файлы страницы, без которых
     * визуальный редактор просто не откроется. Проверяются оба условия: файл
     * под контролем версий (сборка сверяет артефакт с `git ls-files public`)
     * и исключение не может задеть web root.
     */
    public function testEditorLibraryShipsWithTheArtifact(): void
    {
        $root = dirname(__DIR__);
        $script = (string) file_get_contents($root . '/bin/build-release.sh');

        self::assertStringContainsString("--exclude '/vendor'", $script);
        self::assertStringNotContainsString("--exclude 'vendor'", $script);
        self::assertStringContainsString('git ls-files public', $script);

        $tracked = [];
        exec('git -C ' . escapeshellarg($root) . ' ls-files public/vendor', $tracked);
        self::assertContains('public/vendor/toastui-editor/toastui-editor-all.min.js', $tracked);
    }
}
