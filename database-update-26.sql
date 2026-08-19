-- ============================================================================
-- PEPP ERP: Real-Time Online Admin Tracking & Auditing
-- Migration #26
-- Date: 2026-08-20
--
-- Creates the admin_presence table to track active session states, 
-- current page/section navigations, duration, and geo-coordinates.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `admin_presence` (
    `username` VARCHAR(100) PRIMARY KEY,
    `current_page` VARCHAR(255) NOT NULL,
    `current_section` VARCHAR(100) DEFAULT NULL,
    `last_seen` DATETIME NOT NULL,
    `login_time` DATETIME NOT NULL,
    `latitude` DECIMAL(10, 8) DEFAULT NULL,
    `longitude` DECIMAL(11, 8) DEFAULT NULL,
    `ip_address` VARCHAR(64) DEFAULT NULL,
    KEY `idx_ap_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
