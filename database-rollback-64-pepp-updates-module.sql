-- ============================================================
-- PEPP Learning — PEPP Updates Module Rollback
-- Rollback: database-rollback-64-pepp-updates-module.sql
-- Safety: Drops ONLY the 12 PEPP Updates tables in reverse FK order.
--         Never alters or drops any existing ERP tables.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Drop junction tables and dependent audit/recipient tables first
DROP TABLE IF EXISTS `updates_post_keywords`;
DROP TABLE IF EXISTS `updates_post_categories`;
DROP TABLE IF EXISTS `updates_subscriber_categories`;
DROP TABLE IF EXISTS `updates_subscriber_events`;
DROP TABLE IF EXISTS `updates_delivery_recipients`;
DROP TABLE IF EXISTS `updates_delivery_campaigns`;
DROP TABLE IF EXISTS `updates_visits`;

-- 2. Drop primary entity tables
DROP TABLE IF EXISTS `updates_keywords`;
DROP TABLE IF EXISTS `updates_posts`;
DROP TABLE IF EXISTS `updates_categories`;
DROP TABLE IF EXISTS `updates_subscribers`;
DROP TABLE IF EXISTS `updates_settings`;

SET FOREIGN_KEY_CHECKS = 1;
