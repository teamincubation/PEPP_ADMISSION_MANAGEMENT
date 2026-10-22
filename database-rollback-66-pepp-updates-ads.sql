-- ==============================================================================
-- Rollback 66: PEPP Updates Ad Management & Lead Capture Schema
-- Module: PEPP Updates (https://updates.pepplearning.in)
-- Date: October 2026
--
-- CRITICAL DATA SAFETY WARNING:
-- Executing this rollback after production ads have run and leads have been
-- captured in `updates_ad_leads` WILL PERMANENTLY AND IRREVERSIBLY DESTROY
-- all captured ad lead records, form submissions, and configured ads.
--
-- MANDATORY PREREQUISITES BEFORE EXECUTING THIS ROLLBACK:
-- 1. Create a full table export / CSV backup of `updates_ad_leads` and `updates_ads`.
-- 2. Obtain explicit written authorization from the system administrator.
-- 3. Confirm that no pending conversions or lead follow-ups depend on these records.
--
-- Drops tables in reverse foreign-key dependency order.
-- Never alters or drops any existing core ERP tables (leads, pepp_courses, users).
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Drop dependent lead submissions first
DROP TABLE IF EXISTS `updates_ad_leads`;

-- 2. Drop dependent child configuration tables
DROP TABLE IF EXISTS `updates_ad_fields`;
DROP TABLE IF EXISTS `updates_ad_posts`;

-- 3. Drop primary ad entity table
DROP TABLE IF EXISTS `updates_ads`;

-- 4. Clean up global setting from updates_settings
DELETE FROM `updates_settings` WHERE `setting_key` = 'ad_leads_auto_convert_enabled';

SET FOREIGN_KEY_CHECKS = 1;
