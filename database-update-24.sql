-- ============================================================================
-- PEPP ERP: Assessment Results & Score Management
-- Migration #24
-- Date: 2026-08-19
--
-- Creates two new tables for assessment result management.
-- Does NOT modify any existing tables.
-- Does NOT touch study_plan_analytics or any completion data.
-- Idempotent: uses CREATE TABLE IF NOT EXISTS.
-- No DROP, TRUNCATE, or DELETE statements.
-- ============================================================================

-- --------------------------------------------------------------------------
-- Table: assessment_result_batches
-- One row per CSV upload/publication event for a specific test activity.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assessment_result_batches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `activity_id` INT NOT NULL COMMENT 'FK study_plan_activities.id',
    `study_plan_id` INT NOT NULL COMMENT 'FK study_plans.id',
    `academic_year` VARCHAR(50) NOT NULL COMMENT 'e.g. 2026-27',
    `course_id` INT NOT NULL COMMENT 'FK pepp_courses.id',
    `course_name` VARCHAR(255) NOT NULL COMMENT 'Resolved pepp_courses.course_name for student matching',
    `activity_title_snapshot` VARCHAR(255) NOT NULL COMMENT 'activity_title at upload time',
    `activity_type_snapshot` VARCHAR(100) NOT NULL COMMENT 'activity_type at upload time',
    `activity_date_snapshot` DATE NULL COMMENT 'activity_date at upload time',
    `chapter_snapshot` VARCHAR(255) NULL COMMENT 'chapter at upload time',
    `version` INT NOT NULL DEFAULT 1 COMMENT 'Increments on re-upload/replacement',
    `status` ENUM('draft','published','replaced') NOT NULL DEFAULT 'draft',
    `source_filename` VARCHAR(255) NULL COMMENT 'Original uploaded filename',
    `total_rows` INT NOT NULL DEFAULT 0 COMMENT 'Total CSV data rows excluding header',
    `matched_students` INT NOT NULL DEFAULT 0,
    `unmatched_emails` INT NOT NULL DEFAULT 0 COMMENT 'CSV emails not found in users table',
    `attended_count` INT NOT NULL DEFAULT 0 COMMENT 'Status=Submitted AND Evaluation=Completed',
    `not_attended_count` INT NOT NULL DEFAULT 0 COMMENT 'Eligible students absent from CSV',
    `in_progress_count` INT NOT NULL DEFAULT 0 COMMENT 'Status=In Progress or Evaluation not Completed',
    `review_required_count` INT NOT NULL DEFAULT 0 COMMENT 'Unexpected status/evaluation combinations',
    `uploaded_by` VARCHAR(100) NOT NULL,
    `published_by` VARCHAR(100) NULL,
    `published_at` DATETIME NULL,
    `replaced_by_batch_id` INT NULL COMMENT 'Points to the successor batch that replaced this one',
    `replace_reason` TEXT NULL COMMENT 'Admin-provided reason for replacement',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_arb_activity` (`activity_id`),
    KEY `idx_arb_plan` (`study_plan_id`),
    KEY `idx_arb_course` (`course_id`),
    KEY `idx_arb_status` (`status`),
    KEY `idx_arb_year_course` (`academic_year`, `course_name`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Table: assessment_results
-- Individual student result rows within a batch.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assessment_results` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT NOT NULL COMMENT 'FK assessment_result_batches.id',
    `student_email` VARCHAR(255) NOT NULL COMMENT 'Normalized: trimmed, lowercase',
    `user_id` VARCHAR(50) NULL COMMENT 'Resolved users.user_id, NULL if unmatched',
    `attendance_status` ENUM('attended','not_attended','in_progress','review_required') NOT NULL,
    `src_learner_details` VARCHAR(255) NULL COMMENT 'Column 1: Learner Details original',
    `src_name` VARCHAR(255) NULL COMMENT 'Column 2: Name',
    `src_mobile` VARCHAR(100) NULL COMMENT 'Column 3: Mobile may be scientific notation',
    `src_attempt` VARCHAR(100) NULL COMMENT 'Column 4: Attempt e.g. First',
    `src_status` VARCHAR(100) NULL COMMENT 'Column 5: Status e.g. Submitted, In Progress',
    `src_evaluation` VARCHAR(100) NULL COMMENT 'Column 6: Evaluation e.g. Completed, Unassigned',
    `src_submitted_on` VARCHAR(100) NULL COMMENT 'Column 7: Submitted On e.g. 14-08-2026, NA',
    `src_answered` VARCHAR(50) NULL COMMENT 'Column 8: Answered',
    `score` DECIMAL(10,2) NULL COMMENT 'Column 9: Score NULL for not_attended',
    `total_score` DECIMAL(10,2) NULL COMMENT 'Column 10: Total Score',
    `src_accuracy` VARCHAR(50) NULL COMMENT 'Column 11: Accuracy e.g. 93.75 percent',
    `accuracy_numeric` DECIMAL(8,4) NULL COMMENT 'Parsed percentage as decimal 93.75',
    `src_avg_q_per_hr` VARCHAR(50) NULL COMMENT 'Column 12: Avg Q/hr may be NA',
    `avg_q_per_hr_numeric` INT NULL COMMENT 'Parsed integer or NULL',
    `correct` INT NULL COMMENT 'Column 13: Correct',
    `wrong` INT NULL COMMENT 'Column 14: Wrong',
    `skipped` INT NULL COMMENT 'Column 15: Skipped',
    `src_time_spent` VARCHAR(100) NULL COMMENT 'Column 16: Time Spent e.g. 22 mins 37 sec',
    `time_spent_seconds` INT NULL COMMENT 'Parsed total seconds for analytics',
    `src_export` TEXT NULL COMMENT 'Column 17: Export source system ID',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ar_batch` (`batch_id`),
    KEY `idx_ar_email` (`student_email`(191)),
    KEY `idx_ar_user` (`user_id`),
    KEY `idx_ar_attendance` (`attendance_status`),
    KEY `idx_ar_score` (`score`),
    UNIQUE KEY `uq_ar_batch_email` (`batch_id`, `student_email`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
