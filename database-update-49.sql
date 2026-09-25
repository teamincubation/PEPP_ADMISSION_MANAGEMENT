-- ============================================================================
-- Database Migration 49: Add Mega Test Card Indicator to Card Templates
-- PEPP Learning ERP — Card Templates & Mega Test Result Workflow
-- ============================================================================
--
-- PURPOSE:
-- Introduces explicit `is_mega_test_card` boolean flag to `card_templates` table.
-- Allows administrators to explicitly tag templates suitable for Mega Test
-- Result Card generation from the Card Templates tab (cards.php?tab=templates),
-- filtering the Background Template dropdown in Test Result Cards (cards.php?tab=test_results)
-- while preserving all templates in standard Generate Cards (cards.php?tab=generate).
--
-- SAFETY & PRESERVATION GUARANTEES:
-- - Defaults to 0 for existing templates (safe opt-in behavior).
-- - Deterministically marks verified active Mega Test templates (IDs 17 and 23).
-- - Non-destructive: Does NOT delete, truncate, or alter existing template configs.
-- - Preserves all bg_image, canvas_width, canvas_height, and elements_json values.
-- - Idempotent: Can be safely re-run multiple times on MySQL/MariaDB without error.
-- ============================================================================

-- Stored procedure to safely add column if it does not already exist
DROP PROCEDURE IF EXISTS MigrateAddMegaTestCardColumn;
DELIMITER //
CREATE PROCEDURE MigrateAddMegaTestCardColumn()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = DATABASE() 
          AND table_name = 'card_templates' 
          AND column_name = 'is_mega_test_card'
    ) THEN
        ALTER TABLE `card_templates` 
        ADD COLUMN `is_mega_test_card` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;
    END IF;
END //
DELIMITER ;

CALL MigrateAddMegaTestCardColumn();
DROP PROCEDURE IF EXISTS MigrateAddMegaTestCardColumn;

-- Deterministically enable active Mega Test result templates on production (PG: 17, MCP: 23)
UPDATE `card_templates`
SET `is_mega_test_card` = 1
WHERE `id` IN (17, 23)
  AND `status` = 'active';
