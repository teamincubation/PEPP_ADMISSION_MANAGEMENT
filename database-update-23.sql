-- Database migration update 23: Add completion status, clear metadata, and unique active constraint to study_plan_analytics

ALTER TABLE `study_plan_analytics`
ADD COLUMN IF NOT EXISTS `completion_status` ENUM('completed', 'cleared') NOT NULL DEFAULT 'completed',
ADD COLUMN IF NOT EXISTS `cleared_by` VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `cleared_at` DATETIME DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `clear_reason` TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `active_completion_status` VARCHAR(20) GENERATED ALWAYS AS (IF(`completion_status` = 'completed' AND `action_type` = 'complete_activity', 'completed', NULL)) VIRTUAL;

ALTER TABLE `study_plan_analytics` DROP INDEX IF EXISTS `uq_active_student_completion`;
CREATE UNIQUE INDEX `uq_active_student_completion` ON `study_plan_analytics` (`student_email`, `study_plan_id`, `activity_id`, `active_completion_status`);
