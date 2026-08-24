-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 30 : CARD TEMPLATE ADMIN ACCESS
-- Migration #30
-- Date: 2026-08-24
-- ============================================================================

CREATE TABLE IF NOT EXISTS `card_template_admin_access` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `template_id` INT NOT NULL,
    `admin_user_id` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_template_admin` (`template_id`, `admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
