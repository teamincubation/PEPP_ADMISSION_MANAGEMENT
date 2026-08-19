-- ============================================================================
-- PEPP ERP: Admin Geolocation Tracking & Auditing
-- Migration #25
-- Date: 2026-08-20
--
-- Adds latitude, longitude and JSON metadata columns to the three audit logs.
-- ============================================================================

-- 1. Table: admin_activity_log
ALTER TABLE `admin_activity_log` 
    ADD COLUMN `latitude` DECIMAL(10, 8) DEFAULT NULL,
    ADD COLUMN `longitude` DECIMAL(11, 8) DEFAULT NULL,
    ADD COLUMN `metadata` TEXT DEFAULT NULL;

-- 2. Table: track_records
ALTER TABLE `track_records` 
    ADD COLUMN `latitude` DECIMAL(10, 8) DEFAULT NULL,
    ADD COLUMN `longitude` DECIMAL(11, 8) DEFAULT NULL,
    ADD COLUMN `metadata` TEXT DEFAULT NULL;

-- 3. Table: whatsapp_notifications
ALTER TABLE `whatsapp_notifications` 
    ADD COLUMN `latitude` DECIMAL(10, 8) DEFAULT NULL,
    ADD COLUMN `longitude` DECIMAL(11, 8) DEFAULT NULL,
    ADD COLUMN `metadata` TEXT DEFAULT NULL;
