-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 34 : ACTIVITY LOG INDEX OPTIMIZATIONS
-- Migration #34 (Idempotent for Hostinger MySQL/MariaDB)
-- Date: 2026-08-27
-- ============================================================================

ALTER TABLE `admin_activity_log` ADD INDEX `idx_aal_session_id` (`session_id`);
ALTER TABLE `whatsapp_notifications` ADD INDEX `idx_wan_sent_by` (`sent_by`);
