-- ======================================================================
-- PEPP Learning ERP — Database Update 63
-- Admin User Preferences System
--
-- Adds persistent per-admin configuration storage (e.g. Student Mentoring
-- Last Call sort direction, sorting lock state, and table preferences).
--
-- Safe, idempotent, non-destructive migration.
-- ======================================================================

CREATE TABLE IF NOT EXISTS `admin_user_preferences` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NOT NULL,
    `preference_key` VARCHAR(100) NOT NULL,
    `preference_value` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_admin_pref` (`admin_id`, `preference_key`),
    KEY `idx_aup_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
