-- ====================================================================
-- Database Migration: database-update-37.sql
-- Module: Student Study Plan Persistent Authentication
-- Description: Creates student_login_tokens table for secure, hashed,
--              rotatable persistent login tokens for PEPP JOURNEY portal.
-- ====================================================================

CREATE TABLE IF NOT EXISTS `student_login_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_user_id` VARCHAR(64) DEFAULT NULL,
  `student_email` VARCHAR(191) NOT NULL,
  `selector` VARCHAR(64) NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  UNIQUE KEY `idx_sp_token_selector` (`selector`),
  KEY `idx_sp_token_email` (`student_email`),
  KEY `idx_sp_token_user_id` (`student_user_id`),
  KEY `idx_sp_token_revoked` (`revoked_at`),
  KEY `idx_sp_token_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
