<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Empty, fail-closed registry for manually verified crisis resources.
 *
 * This migration intentionally seeds no contacts. Publishing/query policy and
 * resource freshness rules are separate owner-approved work packages.
 */
final class AddCrisisResourceRegistry extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE `crisis_resources` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `country_code` CHAR(2) DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2; NULL for international fallback',
              `language_code` VARCHAR(35) NOT NULL COMMENT 'BCP 47 language tag',
              `resource_type` VARCHAR(64) NOT NULL COMMENT 'Emergency service, helpline, directory, etc.',
              `display_name` VARCHAR(255) NOT NULL,
              `contact_or_url` VARCHAR(2048) NOT NULL COMMENT 'Contact number or user-facing URL',
              `official_source_url` VARCHAR(2048) NOT NULL COMMENT 'Source used for manual verification',
              `verified_at` DATE NOT NULL,
              `verified_by` VARCHAR(255) NOT NULL COMMENT 'Owner or authorised verifier identifier',
              `active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Fail closed until explicitly approved for publication',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_country_language_active` (`country_code`, `language_code`, `active`),
              INDEX `idx_active_verified` (`active`, `verified_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE `crisis_resources`');
    }
}
