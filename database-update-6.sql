-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 6 : COLLATION FIX
-- Run ONCE in phpMyAdmin (after updates 1-5). Safe to re-run.
--
-- The reporting "Admin Activity" tab combined admin_activity_log with
-- track_records. The newer tables were created with the server default
-- collation (utf8mb4_general_ci) while the original tables use
-- utf8mb4_unicode_ci, so a direct UNION raised:
--   "Illegal mix of collations for operation 'UNION'".
-- The PHP now merges results without a cross-table UNION, but aligning the
-- collations keeps everything consistent and future-proof.
-- ============================================================================

ALTER TABLE `admins`              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `admin_activity_log`  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `invoices`            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `leads`               CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `lead_activity`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `reminders`           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- DONE. The Reports → Admin Activity tab and the Activity Log page will now
-- load without collation errors.
-- ============================================================================
