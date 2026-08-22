<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAiProcessingConsents extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'CREATE TABLE ai_processing_consents (
                id CHAR(36) PRIMARY KEY,
                session_id CHAR(36) NOT NULL,
                checkout_reference CHAR(36) NOT NULL,
                purpose VARCHAR(64) NOT NULL,
                notice_version VARCHAR(64) NOT NULL,
                provider_code VARCHAR(100) NOT NULL,
                report_kind VARCHAR(32) NOT NULL,
                allowed_data JSON NOT NULL,
                consented_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ai_consent_checkout (checkout_reference),
                INDEX idx_ai_consent_session (session_id),
                CONSTRAINT fk_ai_consent_session FOREIGN KEY (session_id)
                    REFERENCES test_sessions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE ai_processing_consents');
    }
}
