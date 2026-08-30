-- ====================================================================
-- Database Migration: database-update-40.sql
-- Module: Study Plan Designer — Strict Single-Admin Server-Side Edit Lock
-- Description:
--   Creates study_plan_edit_locks table ensuring that only one admin can
--   edit a specific Study Plan at any given time.
--   Prevents race conditions with UNIQUE constraint on study_plan_id.
-- ====================================================================

CREATE TABLE IF NOT EXISTS `study_plan_edit_locks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `study_plan_id` INT NOT NULL,
    `admin_id` INT DEFAULT NULL,
    `admin_username` VARCHAR(100) NOT NULL,
    `admin_name` VARCHAR(150) NOT NULL,
    `session_token` VARCHAR(100) NOT NULL,
    `locked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_heartbeat_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `released_at` DATETIME DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY `uniq_active_plan_lock` (`study_plan_id`),
    INDEX `idx_spel_admin` (`admin_id`, `admin_username`),
    INDEX `idx_spel_heartbeat` (`last_heartbeat_at`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
