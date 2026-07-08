<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Initial schema: 6 tables + seed of the 4 tests (SMIL, BDI, HADS, BAI).
 *
 * Mirrors database/schema.sql. Replaces the previous ad-hoc setup
 * (bin/install-db.php). Run via `composer migrate` (= `phinx migrate`).
 */
final class InitSchema extends AbstractMigration
{
    public function up(): void
    {
        // TESTS — catalogue of registered test modules.
        $this->execute("
            CREATE TABLE IF NOT EXISTS `tests` (
              `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `name` VARCHAR(255) NOT NULL COMMENT 'Display name of the test',
              `slug` VARCHAR(100) NOT NULL UNIQUE COMMENT 'URL-friendly identifier',
              `module_class` VARCHAR(255) NOT NULL COMMENT 'Fully qualified module class name',
              `description` TEXT COMMENT 'Test description',
              `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Whether test is available',
              `sort_order` INT DEFAULT 0 COMMENT 'Display order',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_slug` (`slug`),
              INDEX `idx_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // TEST SESSIONS — one test run per row.
        $this->execute("
            CREATE TABLE IF NOT EXISTS `test_sessions` (
              `id` CHAR(36) PRIMARY KEY COMMENT 'UUID',
              `test_id` INT UNSIGNED NOT NULL,
              `session_token` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Public access token',
              `partner_token` VARCHAR(64) DEFAULT NULL COMMENT 'Token for pair comparison',
              `user_email` VARCHAR(255) DEFAULT NULL COMMENT 'Optional user email',
              `user_name` VARCHAR(255) DEFAULT NULL COMMENT 'Optional user name',
              `demographics` JSON DEFAULT NULL COMMENT 'Age, gender, etc.',
              `answers` JSON NOT NULL COMMENT 'User answers',
              `calculated_results` JSON NOT NULL COMMENT 'Calculated scores',
              `status` ENUM('partial', 'completed', 'expired', 'deleted') DEFAULT 'partial',
              `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'For audit purposes',
              `user_agent` VARCHAR(500) DEFAULT NULL COMMENT 'For audit purposes',
              `completed_at` TIMESTAMP NULL DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `expires_at` TIMESTAMP NOT NULL COMMENT 'Session expiration',
              FOREIGN KEY (`test_id`) REFERENCES `tests`(`id`) ON DELETE CASCADE,
              INDEX `idx_session_token` (`session_token`),
              INDEX `idx_partner_token` (`partner_token`),
              INDEX `idx_status` (`status`),
              INDEX `idx_expires` (`expires_at`),
              INDEX `idx_test_status` (`test_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // PAIR COMPARISONS — links two sessions for pair-mode results.
        $this->execute("
            CREATE TABLE IF NOT EXISTS `pair_comparisons` (
              `id` CHAR(36) PRIMARY KEY COMMENT 'UUID',
              `test_id` INT UNSIGNED NOT NULL,
              `session_1_id` CHAR(36) NOT NULL,
              `session_2_id` CHAR(36) NOT NULL,
              `comparison_data` JSON NOT NULL COMMENT 'Comparison results',
              `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `expires_at` TIMESTAMP NOT NULL,
              FOREIGN KEY (`test_id`) REFERENCES `tests`(`id`) ON DELETE CASCADE,
              FOREIGN KEY (`session_1_id`) REFERENCES `test_sessions`(`id`) ON DELETE CASCADE,
              FOREIGN KEY (`session_2_id`) REFERENCES `test_sessions`(`id`) ON DELETE CASCADE,
              INDEX `idx_sessions` (`session_1_id`, `session_2_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // AI INTERPRETATIONS — paid AI-generated interpretations.
        $this->execute("
            CREATE TABLE IF NOT EXISTS `ai_interpretations` (
              `id` CHAR(36) PRIMARY KEY COMMENT 'UUID',
              `session_id` CHAR(36) NOT NULL,
              `payment_id` VARCHAR(255) DEFAULT NULL COMMENT 'YooMoney payment ID',
              `payment_status` ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
              `payment_amount` DECIMAL(10,2) DEFAULT NULL,
              `payment_completed_at` TIMESTAMP NULL DEFAULT NULL,
              `interpretation_text` LONGTEXT COMMENT 'AI-generated interpretation',
              `pdf_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to generated PDF',
              `email_sent_at` TIMESTAMP NULL DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              FOREIGN KEY (`session_id`) REFERENCES `test_sessions`(`id`) ON DELETE CASCADE,
              INDEX `idx_session` (`session_id`),
              INDEX `idx_payment` (`payment_id`),
              INDEX `idx_status` (`payment_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // ACTIVITY LOG — audit trail.
        $this->execute("
            CREATE TABLE IF NOT EXISTS `activity_log` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `session_id` CHAR(36) DEFAULT NULL,
              `test_id` INT UNSIGNED DEFAULT NULL,
              `action` VARCHAR(100) NOT NULL COMMENT 'Action performed',
              `details` JSON DEFAULT NULL COMMENT 'Additional context',
              `ip_address` VARCHAR(45) DEFAULT NULL,
              `user_agent` VARCHAR(500) DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`session_id`) REFERENCES `test_sessions`(`id`) ON DELETE SET NULL,
              FOREIGN KEY (`test_id`) REFERENCES `tests`(`id`) ON DELETE SET NULL,
              INDEX `idx_session` (`session_id`),
              INDEX `idx_action` (`action`),
              INDEX `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // PAYMENT TRANSACTIONS — YooMoney transaction log.
        $this->execute("
            CREATE TABLE IF NOT EXISTS `payment_transactions` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `transaction_id` VARCHAR(255) NOT NULL UNIQUE COMMENT 'YooMoney transaction ID',
              `session_id` CHAR(36) NOT NULL,
              `interpretation_id` CHAR(36) DEFAULT NULL,
              `amount` DECIMAL(10,2) NOT NULL,
              `currency` CHAR(3) DEFAULT 'RUB',
              `status` VARCHAR(50) NOT NULL,
              `payment_method` VARCHAR(50) DEFAULT NULL,
              `raw_payload` JSON DEFAULT NULL COMMENT 'Full webhook payload',
              `processed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`session_id`) REFERENCES `test_sessions`(`id`) ON DELETE CASCADE,
              FOREIGN KEY (`interpretation_id`) REFERENCES `ai_interpretations`(`id`) ON DELETE SET NULL,
              INDEX `idx_transaction` (`transaction_id`),
              INDEX `idx_session` (`session_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // SEED: register the 4 test modules. Idempotent — skip if a slug exists.
        $seeds = [
            ['СМИЛ (адаптация MMPI, Ф. Собчик)', 'smil', 'PsyTest\\Modules\\Smil\\SmilModule', 1],
            ['Шкала тревоги Бека (BAI)', 'beck-anxiety', 'PsyTest\\Modules\\BeckAnxiety\\BeckAnxietyModule', 2],
            ['Шкала депрессии Бека (BDI)', 'bdi', 'PsyTest\\Modules\\BeckDepression\\BeckDepressionModule', 3],
            ['Госпитальная шкала тревоги и депрессии (HADS)', 'hads', 'PsyTest\\Modules\\Hads\\HadsModule', 4],
        ];
        foreach ($seeds as [$name, $slug, $class, $order]) {
            // slug is a hard-coded constant, safe to inline.
            $exists = $this->fetchRow("SELECT id FROM `tests` WHERE `slug` = '$slug'");
            if (!$exists) {
                $this->table('tests')->insert([
                    'name'         => $name,
                    'slug'         => $slug,
                    'module_class' => $class,
                    'is_active'    => 1,
                    'sort_order'   => $order,
                ])->saveData();
            }
        }
    }

    public function down(): void
    {
        // Drop in reverse dependency order. Safe: IF EXISTS.
        $this->execute('DROP TABLE IF EXISTS `payment_transactions`;');
        $this->execute('DROP TABLE IF EXISTS `activity_log`;');
        $this->execute('DROP TABLE IF EXISTS `ai_interpretations`;');
        $this->execute('DROP TABLE IF EXISTS `pair_comparisons`;');
        $this->execute('DROP TABLE IF EXISTS `test_sessions`;');
        $this->execute('DROP TABLE IF EXISTS `tests`;');
    }
}
