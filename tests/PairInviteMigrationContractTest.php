<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;

final class PairInviteMigrationContractTest extends TestCase
{
    public function testPairInviteUniqueIndexHasOneCreationPathInMigrations(): void
    {
        $projectRoot = dirname(__DIR__);
        $initialSchema = file_get_contents($projectRoot . '/database/migrations/20260708050511_init_schema.php');
        $pairInviteMigration = file_get_contents($projectRoot . '/database/migrations/20260816000000_add_pair_invite_uniqueness.php');
        $schemaSnapshot = file_get_contents($projectRoot . '/database/schema.sql');

        self::assertIsString($initialSchema);
        self::assertIsString($pairInviteMigration);
        self::assertIsString($schemaSnapshot);
        self::assertStringNotContainsString('UNIQUE KEY `uq_partner_token`', $initialSchema);
        self::assertStringContainsString('ADD UNIQUE KEY uq_partner_token (partner_token)', $pairInviteMigration);
        self::assertStringContainsString('UNIQUE KEY `uq_partner_token` (`partner_token`)', $schemaSnapshot);
    }
}
