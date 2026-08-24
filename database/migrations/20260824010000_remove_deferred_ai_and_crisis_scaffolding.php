<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Removes unconnected pre-implementation tables.
 *
 * Historical create migrations remain in the chain so an already deployed
 * database can apply this cleanup safely. On a clean database this migration
 * also leaves no deferred table behind.
 */
final class RemoveDeferredAiAndCrisisScaffolding extends AbstractMigration
{
    public function up(): void
    {
        foreach (['ai_processing_consents', 'crisis_resources'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'Deferred AI consent and crisis-resource tables are intentionally removed until their product stages begin.'
        );
    }
}
