<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Карточка клиента специалиста.
 *
 * Хранится только подпись владельца и его заметка: контактов клиента
 * платформа не собирает (PRODUCT_RULES §11, INVITE_FLOW). Приглашение
 * ссылается на карточку необязательно, поэтому старые приглашения без
 * клиента остаются валидными, а удаление карточки уносит их каскадом.
 *
 * Явные DEFAULT NULL у timestamp-колонок оставлены ради MySQL 5.7, где
 * первая TIMESTAMP-колонка иначе получает неявный NOT NULL DEFAULT
 * CURRENT_TIMESTAMP ON UPDATE.
 */
final class AddTherapistClients extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE therapist_clients (
                id CHAR(36) PRIMARY KEY,
                label VARCHAR(120) NOT NULL,
                note TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_therapist_clients_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute('ALTER TABLE test_invites ADD COLUMN client_id CHAR(36) NULL AFTER test_id');
        $this->execute('ALTER TABLE test_invites ADD INDEX idx_test_invites_client (client_id)');
        $this->execute(
            'ALTER TABLE test_invites
             ADD CONSTRAINT fk_test_invites_client
             FOREIGN KEY (client_id) REFERENCES therapist_clients(id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE test_invites DROP FOREIGN KEY fk_test_invites_client');
        $this->execute('ALTER TABLE test_invites DROP INDEX idx_test_invites_client');
        $this->execute('ALTER TABLE test_invites DROP COLUMN client_id');
        $this->execute('DROP TABLE therapist_clients');
    }
}
