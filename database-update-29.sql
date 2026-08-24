-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 29 : CARD LAYOUT PRESETS
-- Migration #29
-- Date: 2026-08-24
-- ============================================================================

CREATE TABLE IF NOT EXISTS `card_layout_presets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `elements_json` LONGTEXT NOT NULL,
    `is_default` TINYINT(1) DEFAULT 0,
    `created_by` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    KEY `idx_clp_is_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
