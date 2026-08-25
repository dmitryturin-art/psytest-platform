<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Drops the legacy nullable ip_address / user_agent columns.
 *
 * Since 02.7A (73ed294) no new row is written with a value; this migration
 * completes the technical metadata minimization by removing the columns
 * (and the pre-02.7A values they still hold) per owner decision D-035.
 * ER §9: an exact IP is not stored without a separate proven purpose.
 */
final class DropLegacyIpUserAgentColumns extends AbstractMigration
{
    public function up(): void
    {
        foreach (['test_sessions', 'activity_log'] as $table) {
            if (!$this->hasTable($table)) {
                continue;
            }
            $tableObject = $this->table($table);
            foreach (['ip_address', 'user_agent'] as $column) {
                if ($tableObject->hasColumn($column)) {
                    $tableObject->removeColumn($column)->save();
                }
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'Legacy IP/user-agent metadata is intentionally removed (D-035); it must not be reintroduced.'
        );
    }
}
