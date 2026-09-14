<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Добровольный кабинет посетителя (07.K3, D-053).
 *
 * Идентичность — только нормализованный email: он нужен открытым, потому что
 * по нему уходит одноразовая ссылка входа. Пароля, профиля и контактов нет.
 * Токены входа хранятся только хешем; IP и user-agent не записываются вовсе
 * (ER §9, миграция `drop_legacy_ip_user_agent_columns`).
 *
 * `rate_key` — канонический вид того же адреса (локальная часть до первого
 * `+`, домен в нижнем регистре). Лимит запросов считается по нему, потому что
 * `a+1@x` и `a+2@x` доставляются в один ящик: без канонизации счётчик
 * обходился бы добавлением любого суффикса. Письмо при этом уходит на
 * исходный нормализованный `email` — канонический ключ адресом не является.
 *
 * `test_sessions.account_id` — ТОЛЬКО явная привязка по кнопке посетителя.
 * ON DELETE SET NULL здесь не «сирота»: отвязанная сессия возвращается в
 * анонимный класс и снова подпадает под 180 дней от `created_at`.
 *
 * Явные DEFAULT NULL у timestamp-колонок оставлены ради MySQL 5.7, где первая
 * TIMESTAMP-колонка иначе получает неявный NOT NULL DEFAULT CURRENT_TIMESTAMP
 * ON UPDATE.
 */
final class AddVisitorAccounts extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE visitor_accounts (
                id CHAR(36) PRIMARY KEY,
                email VARCHAR(254) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uq_visitor_accounts_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute(
            "CREATE TABLE visitor_login_tokens (
                id CHAR(36) PRIMARY KEY,
                email VARCHAR(254) NOT NULL,
                rate_key VARCHAR(254) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_visitor_login_tokens_hash (token_hash),
                INDEX idx_visitor_login_tokens_email_created (email, created_at),
                INDEX idx_visitor_login_tokens_rate_key_created (rate_key, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->execute('ALTER TABLE test_sessions ADD COLUMN account_id CHAR(36) NULL AFTER retention_class');
        $this->execute('ALTER TABLE test_sessions ADD INDEX idx_test_sessions_account (account_id)');
        $this->execute(
            'ALTER TABLE test_sessions
             ADD CONSTRAINT fk_test_sessions_account
             FOREIGN KEY (account_id) REFERENCES visitor_accounts(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        // Без аккаунтов класс `account` не имеет смысла: такие сессии обязаны
        // вернуться под обычный анонимный срок, иначе откат оставил бы данные
        // вне любой очистки.
        $this->execute("UPDATE test_sessions SET retention_class = 'anonymous' WHERE retention_class = 'account'");
        $this->execute('ALTER TABLE test_sessions DROP FOREIGN KEY fk_test_sessions_account');
        $this->execute('ALTER TABLE test_sessions DROP INDEX idx_test_sessions_account');
        $this->execute('ALTER TABLE test_sessions DROP COLUMN account_id');
        $this->execute('DROP TABLE visitor_login_tokens');
        $this->execute('DROP TABLE visitor_accounts');
    }
}
