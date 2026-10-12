<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;

/**
 * 09.A1: the adult ADHD screening is registered hidden from the catalogue.
 * It stays active, so the owner can hand it out by a personal invite; the
 * owner decides when it becomes public.
 */
#[Group('database')]
final class AdhdAdultCatalogVisibilityTest extends TestCase
{
    public function testTheTestIsActiveButInviteOnly(): void
    {
        $row = Database::getInstance()->selectOne(
            'SELECT is_active, visibility, description FROM tests WHERE slug = ?',
            ['adhd-adult'],
        );

        self::assertNotNull($row, 'The registration migration must be applied.');
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame('invite', $row['visibility']);
        self::assertStringContainsString('21 вопрос, около 5 минут', (string) $row['description']);
    }

    public function testThePublicCatalogueDoesNotListItButInvitesCan(): void
    {
        $loader = (new ModuleLoader())->discover();

        self::assertArrayNotHasKey('adhd-adult', $loader->getPublicModules());
        self::assertArrayHasKey('adhd-adult', $loader->getActiveModules());
    }
}
