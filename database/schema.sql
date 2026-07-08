-- PsyTest Platform Database Schema
-- MySQL 5.7+ / MariaDB 10.2+

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================
-- TESTS TABLE
-- ============================================
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

-- ============================================
-- TEST SESSIONS TABLE
-- ============================================
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

-- ============================================
-- PAIR COMPARISONS TABLE
-- ============================================
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

-- ============================================
-- AI INTERPRETATIONS TABLE (Paid)
-- ============================================
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

-- ============================================
-- ACTIVITY LOG TABLE (Audit)
-- ============================================
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

-- ============================================
-- PAYMENT TRANSACTIONS LOG
-- ============================================
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

-- ============================================
-- INITIAL DATA: Register SMIL Test
-- ============================================
INSERT INTO `tests` (`name`, `slug`, `module_class`, `description`, `is_active`, `sort_order`) 
VALUES (
  'СМИЛ (адаптация MMPI, Ф. Собчик)',
  'smil',
  'PsyTest\\Modules\\Smil\\SmilModule',
  'Стандартизированный многофакторный метод исследования личности. Адаптация Ф.Б. Собчик.',
  1,
  1
);

INSERT INTO `tests` (`name`, `slug`, `module_class`, `description`, `is_active`, `sort_order`) 
VALUES (
  'Шкала депрессии Бека (BDI)',
  'bdi',
  'PsyTest\\Modules\\BeckDepression\\BeckDepressionModule',
  'Методика диагностики депрессивных состояний Аарона Бека. 21 вопрос.',
  1,
  3
);

INSERT INTO `tests` (`name`, `slug`, `module_class`, `description`, `is_active`, `sort_order`) 
VALUES (
  'Госпитальная шкала тревоги и депрессии (HADS)',
  'hads',
  'PsyTest\\Modules\\Hads\\HadsModule',
  'Шкала для выявления и оценки тяжести депрессии и тревоги. 14 вопросов.',
  1,
  4
);

-- ============================================
-- CLEANUP: Expired sessions (for cron job)
-- ============================================
-- Run periodically: DELETE FROM test_sessions WHERE expires_at < NOW() AND status != 'deleted';
