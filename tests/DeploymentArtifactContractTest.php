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
}
