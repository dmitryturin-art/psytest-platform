<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddOwnerDashboardLoginAttempts extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'CREATE TABLE owner_login_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                was_successful TINYINT(1) NOT NULL DEFAULT 0,
                attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_owner_login_attempted_at (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE owner_login_attempts');
    }
}
