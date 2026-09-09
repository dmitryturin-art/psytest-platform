<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTestInvites extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE test_invites (
                id CHAR(36) PRIMARY KEY,
                test_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                owner_note TEXT NULL,
                status ENUM('pending', 'claimed', 'revoked') NOT NULL DEFAULT 'pending',
                claimed_session_id CHAR(36) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                claimed_at TIMESTAMP NULL DEFAULT NULL,
                revoked_at TIMESTAMP NULL DEFAULT NULL,
                CONSTRAINT uq_test_invites_token_hash UNIQUE (token_hash),
                CONSTRAINT uq_test_invites_claimed_session UNIQUE (claimed_session_id),
                CONSTRAINT fk_test_invites_test FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE,
                CONSTRAINT fk_test_invites_session FOREIGN KEY (claimed_session_id) REFERENCES test_sessions(id) ON DELETE SET NULL,
                INDEX idx_test_invites_owner (created_at),
                INDEX idx_test_invites_status_expiry (status, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE test_invites');
    }
}
