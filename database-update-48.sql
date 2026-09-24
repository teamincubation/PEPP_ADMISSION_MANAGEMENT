-- ============================================================================
-- Database Migration 48: Complete Removal of Custom Campaign Form ↔ Study Plan Integration
-- PEPP Learning ERP — Study Plans Architecture
-- ============================================================================
--
-- CRITICAL SAFETY & PRE-EXECUTION PREREQUISITES:
-- 1. Take a full production DB backup (mysqldump / Hostinger Snapshot) BEFORE executing.
--    The production backup serves as the permanent recovery mechanism for the intentionally removed form-assignment rows.
-- 2. Audit BOTH active and soft-deleted form assignment rows via CLI:
--    php scripts/audit_campaign_form_studyplan_impact.php --mysql --dry-run
-- 3. Confirm that no other assignment types ('all', 'course', 'batch', 'student') will be touched.
-- 4. After backup and verification, execute this script or run:
--    php scripts/audit_campaign_form_studyplan_impact.php --mysql --apply-migration
-- 5. Run post-migration verification:
--    php scripts/audit_campaign_form_studyplan_impact.php --mysql --verify
--
-- STRICT DATA PRESERVATION GUARANTEES:
-- - Physically DELETE ONLY rows where: `assignment_type = 'form'`
-- - Do NOT delete any other assignment type ('all', 'course', 'batch', 'student').
-- - Do NOT delete study plans (`study_plans`).
-- - Do NOT delete activities (`study_plan_activities`).
-- - Do NOT delete students (`users`).
-- - Do NOT delete campaign forms (`campaign_forms`).
-- - Do NOT delete campaign submissions (`campaign_form_submissions`).
-- - Do NOT delete campaign answers (`campaign_form_answers`).
-- - Do NOT delete study_plan_analytics (`study_plan_analytics`).
-- PRODUCTION AUDIT BASELINE:
-- Production audit confirms exactly 1 historical form assignment row:
--   id = 89 | study_plan_id = 8 | form_id = 7 | assignment_type = 'form' | is_deleted = 1 | created_at = '2026-08-29 12:09:17'
-- Active form assignments in production: 0.
-- Soft-deleted form assignments in production: 1 (id = 89).
--
-- IDEMPOTENCY:
-- This script can be run multiple times safely without error or unexpected side effects.
-- ============================================================================

-- Step 1: Physically delete ALL assignment rows where assignment_type = 'form' (both active and soft-deleted)
-- This removes the historical row id=89 so MySQL will not throw Error 1265 on ALTER TABLE.
DELETE FROM `study_plan_assignments` 
WHERE `assignment_type` = 'form';

-- Step 2: Verification check (must return 0)
-- In interactive CLI, confirm:
-- SELECT COUNT(*) FROM `study_plan_assignments` WHERE `assignment_type` = 'form';
-- Expected result = 0.

-- Step 3: Modify ENUM column to permanently remove 'form' as an allowable assignment type
ALTER TABLE `study_plan_assignments` 
MODIFY COLUMN `assignment_type` ENUM('all','course','batch','student') NOT NULL;

