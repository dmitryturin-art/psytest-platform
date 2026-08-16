<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSessionRetentionClass extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE test_sessions ADD COLUMN retention_class VARCHAR(32) NOT NULL DEFAULT 'anonymous' COMMENT 'Lifecycle classification' AFTER status");
        $this->execute('ALTER TABLE test_sessions ADD INDEX idx_retention_created (retention_class, created_at)');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE test_sessions DROP INDEX idx_retention_created');
        $this->execute('ALTER TABLE test_sessions DROP COLUMN retention_class');
    }
}
