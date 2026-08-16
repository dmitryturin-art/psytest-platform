<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPairInviteUniqueness extends AbstractMigration
{
    public function up(): void
    {
        $duplicates = $this->fetchAll('SELECT partner_token FROM test_sessions WHERE partner_token IS NOT NULL GROUP BY partner_token HAVING COUNT(*) > 1');
        if ($duplicates !== []) {
            throw new RuntimeException('Cannot add pair invite uniqueness while duplicate pair sessions exist.');
        }
        $this->execute('ALTER TABLE test_sessions ADD UNIQUE KEY uq_partner_token (partner_token)');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE test_sessions DROP INDEX uq_partner_token');
    }
}
