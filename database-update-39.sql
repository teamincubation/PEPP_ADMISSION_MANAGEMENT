-- ====================================================================
-- Database Migration: database-update-39.sql
-- Module: Approved Staff Management & Staff-Admin Account Linking
-- Description:
--   1. employees.status: Modify column to VARCHAR(30) to accommodate canonical
--      staff employment statuses (active, probation, contract, notice_period,
--      on_leave, inactive, suspended, resigned, contract_ended, terminated, completed).
--   2. employees.linked_at, employees.linked_by: Add timestamp and admin username
--      audit metadata for Staff ↔ Admin account linking.
--   3. Index optimizations on employees(admin_id), employees(status), and
--      employees(application_for) for high-performance directory filtering.
-- ====================================================================

-- 1. Modify employees.status to VARCHAR(30)
ALTER TABLE `employees` MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'active';

-- 2. Add linking metadata columns if not present
ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `linked_at` DATETIME DEFAULT NULL AFTER `admin_id`,
  ADD COLUMN IF NOT EXISTS `linked_by` VARCHAR(100) DEFAULT NULL AFTER `linked_at`;

-- 3. Add performance indexes for employee directory filtering & account linking
ALTER TABLE `employees`
  ADD INDEX IF NOT EXISTS `idx_emp_admin_id` (`admin_id`),
  ADD INDEX IF NOT EXISTS `idx_emp_status` (`status`),
  ADD INDEX IF NOT EXISTS `idx_emp_app_for` (`application_for`);
