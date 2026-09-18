-- Database Migration 45: Enforce Composite Unique Key on instalment_details (user_id, instalment_number)
-- 
-- PREREQUISITES BEFORE EXECUTING THIS SCRIPT ON PRODUCTION:
-- 1. Run CLI forensic audit in dry-run mode:
--    php scripts/audit_installment_integrity.php --mysql --dry-run
-- 2. Execute reconciliation to safely prune unevidenced duplicate pending rows:
--    php scripts/audit_installment_integrity.php --mysql --execute-reconciliation
-- 3. Verify duplicate count is exactly ZERO:
--    php scripts/audit_installment_integrity.php --mysql --verify
-- 4. Confirm database backup is taken and conflicting financial evidence count is 0.
--
-- Note: This constraint can also be applied automatically via CLI after verification:
--    php scripts/audit_installment_integrity.php --mysql --apply-constraint

ALTER TABLE `instalment_details`
ADD UNIQUE KEY `unique_user_installment` (`user_id`, `instalment_number`);
