-- ====================================================================
-- Database Migration: database-update-38.sql
-- Module: Student Study Plan Single-Device Session & Security Audit
-- Description:
--   1. student_active_sessions: Enforces strict single-active-device login.
--   2. student_login_audit: Secure server-side audit logs for student logins,
--      device information, and forced logout tracking.
--   3. student_login_attempts: Rate limiting & brute-force throttling.
-- ====================================================================

-- 1. Single Active Device Sessions Table
CREATE TABLE IF NOT EXISTS `student_active_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_user_id` VARCHAR(64) DEFAULT NULL,
  `student_email` VARCHAR(191) NOT NULL,
  `active_session_id` VARCHAR(64) NOT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `last_activity_at` DATETIME NOT NULL,
  UNIQUE KEY `idx_sas_email` (`student_email`),
  KEY `idx_sas_user_id` (`student_user_id`),
  KEY `idx_sas_session_id` (`active_session_id`),
  KEY `idx_sas_last_activity` (`last_activity_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Student Login Audit & Security Log Table
CREATE TABLE IF NOT EXISTS `student_login_audit` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_user_id` VARCHAR(64) DEFAULT NULL,
  `student_email` VARCHAR(191) NOT NULL,
  `login_timestamp` DATETIME NOT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `approximate_location` VARCHAR(191) DEFAULT 'Unknown',
  `browser` VARCHAR(100) DEFAULT 'Unknown',
  `browser_version` VARCHAR(50) DEFAULT NULL,
  `device_type` VARCHAR(50) DEFAULT 'Unknown',
  `operating_system` VARCHAR(100) DEFAULT 'Unknown',
  `os_version` VARCHAR(50) DEFAULT NULL,
  `network_provider` VARCHAR(191) DEFAULT 'Unknown',
  `login_method` VARCHAR(50) NOT NULL, -- 'email_dob', 'remember_token'
  `session_id_ref` VARCHAR(64) DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL, -- 'success', 'failed', 'status_blocked', 'throttled'
  `logout_timestamp` DATETIME DEFAULT NULL,
  `forced_logout_reason` VARCHAR(100) DEFAULT NULL, -- 'single_device_conflict', 'status_downgrade', 'manual_logout', 'session_expired'
  `revocation_timestamp` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  KEY `idx_sla_email` (`student_email`),
  KEY `idx_sla_user_id` (`student_user_id`),
  KEY `idx_sla_session` (`session_id_ref`),
  KEY `idx_sla_status` (`status`),
  KEY `idx_sla_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Student Login Attempts Table (Rate Limiting)
CREATE TABLE IF NOT EXISTS `student_login_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(64) NOT NULL,
  `student_email` VARCHAR(191) DEFAULT NULL,
  `attempted_at` DATETIME NOT NULL,
  KEY `idx_sla_ip_time` (`ip_address`, `attempted_at`),
  KEY `idx_sla_email_time` (`student_email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
