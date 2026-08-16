<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class MethodologyRegistryContractTest extends TestCase
{
    private const REGISTRY_PATH = __DIR__ . '/../docs/roadmap/methodology-registry.json';

    /** @return array<string, mixed> */
    private function registry(): array
    {
        $contents = file_get_contents(self::REGISTRY_PATH);

        self::assertNotFalse($contents);

        /** @var array<string, mixed> $registry */
        $registry = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $registry;
    }

    public function testRegistryCoversEveryCurrentModuleExactlyOnce(): void
    {
        $registry = $this->registry();
        self::assertSame(1, $registry['schema_version']);
        self::assertIsArray($registry['methodologies']);

        $registrySlugs = array_column($registry['methodologies'], 'slug');
        self::assertSame($registrySlugs, array_values(array_unique($registrySlugs)));
        sort($registrySlugs);

        $moduleSlugs = array_map(
            static function (string $path): string {
                $contents = file_get_contents($path);
                self::assertNotFalse($contents);

                /** @var array{slug: string} $metadata */
                $metadata = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

                return $metadata['slug'];
            },
            glob(__DIR__ . '/../modules/*/metadata.json') ?: []
        );
        sort($moduleSlugs);

        self::assertSame($moduleSlugs, $registrySlugs);
    }

    public function testRegistryEntryReferencesItsImplementedModuleFiles(): void
    {
        $registry = $this->registry();

        foreach ($registry['methodologies'] as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['slug']);
            self::assertIsArray($entry['implementation']);
            self::assertFileExists(__DIR__ . '/../' . $entry['implementation']['metadata']);
            self::assertFileExists(__DIR__ . '/../' . $entry['implementation']['questions']);
            self::assertGreaterThan(0, $entry['implementation']['question_count']);

            $metadataContents = file_get_contents(__DIR__ . '/../' . $entry['implementation']['metadata']);
            self::assertNotFalse($metadataContents);

            /** @var array{slug: string, question_count: int} $metadata */
            $metadata = json_decode($metadataContents, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($metadata['slug'], $entry['slug']);
            self::assertSame($metadata['question_count'], $entry['implementation']['question_count']);

            self::assertIsArray($entry['provenance']);
            self::assertContains($entry['provenance']['status'], ['partial', 'verified']);
            self::assertNotEmpty($entry['provenance']['evidence']);
            self::assertNotEmpty($entry['provenance']['missing']);
        }
    }

    public function testVerifiedRightsRequireDocumentedEvidence(): void
    {
        $registry = $this->registry();

        foreach ($registry['methodologies'] as $entry) {
            self::assertIsArray($entry);
            self::assertIsArray($entry['rights']);
            self::assertContains($entry['rights']['status'], ['unverified', 'verified']);
            self::assertIsArray($entry['rights']['required_evidence']);
            self::assertNotEmpty($entry['rights']['required_evidence']);

            if ($entry['rights']['status'] === 'verified') {
                self::assertArrayHasKey('review_evidence', $entry['rights']);
                self::assertNotEmpty($entry['rights']['review_evidence']);
            }
        }
    }

    public function testUnverifiedRightsBlockNewPublicContentAndPaidInterpretation(): void
    {
        $registry = $this->registry();

        foreach ($registry['methodologies'] as $entry) {
            self::assertIsArray($entry);
            self::assertIsArray($entry['release_gate']);

            if ($entry['rights']['status'] === 'unverified') {
                self::assertSame('blocked', $entry['release_gate']['paid_interpretation'], $entry['slug']);
                self::assertSame('blocked', $entry['release_gate']['public_new_content'], $entry['slug']);
            }
        }
    }
}
