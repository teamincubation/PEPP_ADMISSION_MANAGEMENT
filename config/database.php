<?php
/**
 * PEPP Learning — database connection.
 * Cleaned: the old file ran a large CREATE TABLE block on EVERY request,
 * slowing down every page. The schema already exists in the database
 * (see the SQL dump); run migrations manually when needed.
 */
date_default_timezone_set('Asia/Kolkata');

define('DB_HOST', 'localhost');
define('DB_NAME', 'u361910773_peppadmin');
define('DB_USER', 'u361910773_admindash');
define('DB_PASS', 'PL@AdmInc2025#');
define('INVOICE_HMAC_SECRET', 'PEPP_InvoiceSecret_2026_Key_Secure_Rand');

if (isset($_SERVER['HTTP_X_TESTING_MODE']) && $_SERVER['HTTP_X_TESTING_MODE'] === 'true') {
    try {
        $pdo = new PDO("sqlite::memory:");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn = $pdo;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_settings (
                setting_name TEXT PRIMARY KEY,
                setting_value TEXT,
                updated_at TEXT
            );
            CREATE TABLE IF NOT EXISTS communication_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT,
                recipient TEXT,
                status TEXT,
                retry_count INTEGER DEFAULT 0,
                message_id TEXT,
                error_message TEXT,
                updated_at TEXT
            );
            CREATE TABLE IF NOT EXISTS communication_webhook_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider TEXT,
                event_type TEXT,
                payload TEXT,
                processed INTEGER DEFAULT 0,
                created_at TEXT,
                processed_at TEXT
            );
            CREATE TABLE IF NOT EXISTS whatsapp_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone TEXT,
                status TEXT,
                updated_at TEXT
            );
            INSERT OR REPLACE INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_webhook_verify_token', 'test_verify_token');
            INSERT OR REPLACE INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_app_secret', 'test_app_secret');
        ");
        return;
    } catch (Exception $e) {
        die("Testing mock DB error: " . $e->getMessage());
    }
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // Align the connection collation with the tables (utf8mb4_unicode_ci) so
    // comparing a column to a bound PHP string never raises
    // "Illegal mix of collations". Without this, parameters default to
    // utf8mb4_general_ci and clash with unicode_ci columns.
    try { $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Exception $e) { /* older servers */ }
    $pdo->exec("SET time_zone = '+05:30'");

    // Some legacy files reference $conn
    $conn = $pdo;

    // Self-healing database structure setup
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `student_remarks` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` VARCHAR(50) NOT NULL,
                `remark` TEXT NOT NULL,
                `created_by` VARCHAR(100) NOT NULL,
                `reminder_id` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_stud_rem_uid` (`user_id`),
                KEY `idx_stud_rem_reminder` (`reminder_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `student_peppkit` (
                `user_id` VARCHAR(50) PRIMARY KEY,
                `status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
                `tracking_id` VARCHAR(100) NULL,
                `updated_by` VARCHAR(100) NULL,
                `updated_at` DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `card_templates` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `category` VARCHAR(100) NOT NULL,
                `description` TEXT NULL,
                `bg_image` VARCHAR(255) NOT NULL,
                `canvas_width` INT NOT NULL,
                `canvas_height` INT NOT NULL,
                `resolution_dpi` INT NOT NULL DEFAULT 72,
                `aspect_ratio` VARCHAR(50) NOT NULL,
                `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
                `elements_json` LONGTEXT NOT NULL,
                `created_by` VARCHAR(100) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `custom_fonts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `font_name` VARCHAR(100) NOT NULL,
                `font_file` VARCHAR(255) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `university_logos` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(150) NOT NULL,
                `logo_file` VARCHAR(255) NOT NULL,
                `width` INT NOT NULL DEFAULT 100,
                `height` INT NOT NULL DEFAULT 100,
                `dpi` INT NOT NULL DEFAULT 72,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Campaign Form Builder Tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `campaign_forms` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `slug` VARCHAR(100) UNIQUE NOT NULL,
                `status` ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
                `publish_schedule_start` DATETIME NULL,
                `publish_schedule_end` DATETIME NULL,
                `is_public` TINYINT(1) NOT NULL DEFAULT 1,
                `allowed_emails` TEXT NULL,
                `limit_per_user` INT NOT NULL DEFAULT 0,
                `submission_limit` INT NOT NULL DEFAULT 0,
                `password` VARCHAR(255) NULL,
                `theme` VARCHAR(50) NOT NULL DEFAULT 'default',
                `thank_you_title` VARCHAR(255) NULL DEFAULT 'Thank You!',
                `thank_you_text` TEXT NULL,
                `webhook_url` VARCHAR(255) NULL,
                `enable_captcha` TINYINT(1) NOT NULL DEFAULT 0,
                `notify_emails` VARCHAR(255) NULL,
                `confirmation_email_subject` VARCHAR(255) NULL,
                `confirmation_email_body` TEXT NULL,
                `auto_redirect_whatsapp` TINYINT(1) NOT NULL DEFAULT 0,
                `whatsapp_group_link` VARCHAR(255) NULL,
                `banner_image` VARCHAR(255) NULL,
                `created_by` VARCHAR(100) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_form_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Self-healing columns for WhatsApp auto redirect & Banner Image
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM campaign_forms LIKE 'auto_redirect_whatsapp'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE campaign_forms ADD COLUMN `auto_redirect_whatsapp` TINYINT(1) NOT NULL DEFAULT 0");
            }
            $cols = $pdo->query("SHOW COLUMNS FROM campaign_forms LIKE 'whatsapp_group_link'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE campaign_forms ADD COLUMN `whatsapp_group_link` VARCHAR(255) NULL");
            }
            $cols = $pdo->query("SHOW COLUMNS FROM campaign_forms LIKE 'banner_image'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE campaign_forms ADD COLUMN `banner_image` VARCHAR(255) NULL");
            }
        } catch (Exception $e) {}

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `campaign_form_fields` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `form_id` INT NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `label` VARCHAR(255) NOT NULL,
                `placeholder` VARCHAR(255) NULL,
                `default_value` VARCHAR(255) NULL,
                `field_name` VARCHAR(100) NOT NULL,
                `is_required` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order` INT NOT NULL DEFAULT 0,
                `validation_rules` TEXT NULL,
                `choices` TEXT NULL,
                `conditional_logic` TEXT NULL,
                `error_message` VARCHAR(255) NULL,
                `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
                KEY `idx_field_form` (`form_id`),
                CONSTRAINT `fk_field_form` FOREIGN KEY (`form_id`) REFERENCES `campaign_forms` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Self-healing columns for fields soft deletion
        try {
            $cols_f = $pdo->query("SHOW COLUMNS FROM campaign_form_fields LIKE 'is_deleted'")->fetch();
            if (!$cols_f) {
                $pdo->exec("ALTER TABLE campaign_form_fields ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (Exception $e) {}

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `campaign_form_submissions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `form_id` INT NOT NULL,
                `respondent_identifier` VARCHAR(255) NULL,
                `ip_address` VARCHAR(45) NOT NULL,
                `user_agent` VARCHAR(255) NOT NULL,
                `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
                `deleted_at` DATETIME NULL,
                `is_converted_lead` TINYINT(1) NOT NULL DEFAULT 0,
                `converted_lead_id` INT NULL,
                `latitude` VARCHAR(50) NULL,
                `longitude` VARCHAR(50) NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                KEY `idx_submission_form` (`form_id`),
                CONSTRAINT `fk_submission_form` FOREIGN KEY (`form_id`) REFERENCES `campaign_forms` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Self-healing columns for soft deletion, lead conversion tracking, maps geolocation, and read/unread status
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM campaign_form_submissions LIKE 'is_deleted'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE campaign_form_submissions ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `deleted_at` DATETIME NULL");
            }
            $cols_lead = $pdo->query("SHOW COLUMNS FROM campaign_form_submissions LIKE 'is_converted_lead'")->fetch();
            if (!$cols_lead) {
                $pdo->exec("ALTER TABLE campaign_form_submissions ADD COLUMN `is_converted_lead` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `converted_lead_id` INT NULL");
            }
            $cols_lat = $pdo->query("SHOW COLUMNS FROM campaign_form_submissions LIKE 'latitude'")->fetch();
            if (!$cols_lat) {
                $pdo->exec("ALTER TABLE campaign_form_submissions ADD COLUMN `latitude` VARCHAR(50) NULL, ADD COLUMN `longitude` VARCHAR(50) NULL");
            }
            $cols_read = $pdo->query("SHOW COLUMNS FROM campaign_form_submissions LIKE 'is_read'")->fetch();
            if (!$cols_read) {
                $pdo->exec("ALTER TABLE campaign_form_submissions ADD COLUMN `is_read` TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (Exception $e) {}

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `campaign_form_answers` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `submission_id` INT NOT NULL,
                `field_id` INT NOT NULL,
                `answer_text` LONGTEXT NULL,
                `file_path` VARCHAR(255) NULL,
                KEY `idx_answer_sub` (`submission_id`),
                KEY `idx_answer_field` (`field_id`),
                CONSTRAINT `fk_answer_sub` FOREIGN KEY (`submission_id`) REFERENCES `campaign_form_submissions` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_answer_field` FOREIGN KEY (`field_id`) REFERENCES `campaign_form_fields` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `campaign_form_analytics` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `form_id` INT NOT NULL,
                `ip_address` VARCHAR(45) NOT NULL,
                `device` VARCHAR(20) NOT NULL,
                `browser` VARCHAR(50) NOT NULL,
                `referrer` VARCHAR(255) NULL,
                `visited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_analytic_form` (`form_id`),
                CONSTRAINT `fk_analytic_form` FOREIGN KEY (`form_id`) REFERENCES `campaign_forms` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $cols = $pdo->query("SHOW COLUMNS FROM reminders LIKE 'student_id'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE reminders ADD COLUMN student_id VARCHAR(50) NULL");
        }
        
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN student_status ENUM('active','inactive','suspended','completed','dropout') DEFAULT 'active'");
        } catch (Exception $alterEx) {}

        // Study Plans Module Tables Self-Healing
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `study_plans` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `title` VARCHAR(255) NOT NULL,
              `academic_year` VARCHAR(50) NOT NULL,
              `course_id` INT NULL,
              `description` TEXT NULL,
              `cover_image` VARCHAR(255) NULL,
              `theme` VARCHAR(50) NOT NULL DEFAULT 'default',
              `layout` VARCHAR(50) NOT NULL DEFAULT 'timeline',
              `start_date` DATE NOT NULL,
              `end_date` DATE NOT NULL,
              `status` ENUM('draft','published','scheduled','archived') NOT NULL DEFAULT 'draft',
              `publish_start` DATETIME NULL,
              `publish_end` DATETIME NULL,
              `version` INT NOT NULL DEFAULT 1,
              `is_template` TINYINT(1) NOT NULL DEFAULT 0,
              `custom_settings` LONGTEXT NULL,
              `created_by` VARCHAR(100) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `study_plan_activities` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `study_plan_id` INT NOT NULL,
              `activity_date` DATE NOT NULL,
              `day_number` INT NOT NULL,
              `sort_order` INT NOT NULL DEFAULT 0,
              `chapter` VARCHAR(255) NULL,
              `subject` VARCHAR(255) NULL,
              `topic` VARCHAR(255) NULL,
              `subtopic` VARCHAR(255) NULL,
              `activity_title` VARCHAR(255) NOT NULL,
              `activity_description` TEXT NULL,
              `activity_type` VARCHAR(100) NOT NULL,
              `faculty` VARCHAR(255) NULL,
              `mentor` VARCHAR(255) NULL,
              `estimated_duration` INT NULL,
              `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
              `difficulty_level` ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
              `resource_links` TEXT NULL,
              `custom_activity_badge` VARCHAR(100) NULL,
              `custom_activity_color` VARCHAR(50) NULL,
              `custom_activity_icon` VARCHAR(100) NULL,
              KEY `idx_spa_plan` (`study_plan_id`),
              KEY `idx_spa_date` (`activity_date`),
              CONSTRAINT `fk_spa_plan` FOREIGN KEY (`study_plan_id`) REFERENCES `study_plans` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `study_plan_assignments` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `study_plan_id` INT NOT NULL,
              `assignment_type` ENUM('all','course','batch','student','form') NOT NULL,
              `assigned_value` VARCHAR(255) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_spa_assign_plan` (`study_plan_id`),
              CONSTRAINT `fk_spa_assign_plan` FOREIGN KEY (`study_plan_id`) REFERENCES `study_plans` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `study_plan_custom_types` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `name` VARCHAR(100) NOT NULL UNIQUE,
              `icon` VARCHAR(100) NOT NULL,
              `color` VARCHAR(50) NOT NULL,
              `badge` VARCHAR(100) NULL,
              `created_by` VARCHAR(100) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `study_plan_audit_logs` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `study_plan_id` INT NULL,
              `admin_username` VARCHAR(100) NOT NULL,
              `action` VARCHAR(100) NOT NULL,
              `details` TEXT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `study_plan_analytics` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `study_plan_id` INT NOT NULL,
              `student_email` VARCHAR(255) NULL,
              `action_type` ENUM('view','download','complete_activity') NOT NULL,
              `activity_id` INT NULL,
              `ip_address` VARCHAR(45) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `completion_status` ENUM('completed', 'cleared') NOT NULL DEFAULT 'completed',
              `cleared_by` VARCHAR(255) DEFAULT NULL,
              `cleared_at` DATETIME DEFAULT NULL,
              `clear_reason` TEXT DEFAULT NULL,
              KEY `idx_sp_anal_plan` (`study_plan_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `study_plan_chapters` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `academic_year` VARCHAR(50) NOT NULL,
              `course_id` INT NOT NULL,
              `chapter_name` VARCHAR(255) NOT NULL,
              `chapter_code` VARCHAR(50) DEFAULT NULL,
              `subject_name` VARCHAR(255) DEFAULT NULL,
              `description` TEXT DEFAULT NULL,
              `created_by` VARCHAR(100) DEFAULT 'System',
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_spc_acad_course` (`academic_year`, `course_id`),
              KEY `idx_spc_course` (`course_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        try {
            $pdo->exec("ALTER TABLE study_plan_assignments MODIFY COLUMN assignment_type ENUM('all','course','batch','student','form') NOT NULL");
        } catch (Exception $e) {}

        try {
            $cols_anal = $pdo->query("SHOW COLUMNS FROM study_plan_analytics LIKE 'latitude'")->fetch();
            if (!$cols_anal) {
                $pdo->exec("ALTER TABLE study_plan_analytics ADD COLUMN `latitude` VARCHAR(50) DEFAULT NULL, ADD COLUMN `longitude` VARCHAR(50) DEFAULT NULL");
            }
        } catch (Exception $e) {}

        try {
            $cols_anal_place = $pdo->query("SHOW COLUMNS FROM study_plan_analytics LIKE 'resolved_place'")->fetch();
            if (!$cols_anal_place) {
                $pdo->exec("ALTER TABLE study_plan_analytics ADD COLUMN `resolved_place` VARCHAR(255) DEFAULT NULL");
            }
        } catch (Exception $e) {}

        try {
            $cols_plan = $pdo->query("SHOW COLUMNS FROM study_plans LIKE 'plan_type'")->fetch();
            if (!$cols_plan) {
                $pdo->exec("ALTER TABLE study_plans ADD COLUMN `plan_type` ENUM('date_wise', 'day_wise') NOT NULL DEFAULT 'date_wise', ADD COLUMN `total_days` INT DEFAULT NULL");
            }
        } catch (Exception $e) {}

        // Self-healing columns for communication_queue table
        try {
            if ($pdo->query("SHOW TABLES LIKE 'communication_queue'")->fetchColumn()) {
                $cols = $pdo->query("SHOW COLUMNS FROM communication_queue LIKE 'student_uid'")->fetch();
                if (!$cols) {
                    $pdo->exec("ALTER TABLE communication_queue ADD COLUMN student_uid VARCHAR(50) DEFAULT NULL AFTER recipient_name");
                }
                $cols = $pdo->query("SHOW COLUMNS FROM communication_queue LIKE 'event_name'")->fetch();
                if (!$cols) {
                    $pdo->exec("ALTER TABLE communication_queue ADD COLUMN event_name VARCHAR(100) DEFAULT NULL AFTER template_name");
                }
                $cols = $pdo->query("SHOW COLUMNS FROM communication_queue LIKE 'invoice_id'")->fetch();
                if (!$cols) {
                    $pdo->exec("ALTER TABLE communication_queue ADD COLUMN invoice_id INT DEFAULT NULL AFTER attachments");
                }
            }
        } catch (Exception $e) {}

        // ── Assessment Results tables (fresh-install only, CREATE IF NOT EXISTS) ──
        // Migration #24 is the authoritative production schema.
        // This block only ensures the tables exist on fresh installs.
        // Does NOT ALTER existing tables. Does NOT touch study_plan_analytics.
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `assessment_result_batches` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `activity_id` INT NOT NULL,
                    `study_plan_id` INT NOT NULL,
                    `academic_year` VARCHAR(50) NOT NULL,
                    `course_id` INT NOT NULL,
                    `course_name` VARCHAR(255) NOT NULL,
                    `activity_title_snapshot` VARCHAR(255) NOT NULL,
                    `activity_type_snapshot` VARCHAR(100) NOT NULL,
                    `activity_date_snapshot` DATE NULL,
                    `chapter_snapshot` VARCHAR(255) NULL,
                    `version` INT NOT NULL DEFAULT 1,
                    `status` ENUM('draft','published','replaced') NOT NULL DEFAULT 'draft',
                    `source_filename` VARCHAR(255) NULL,
                    `total_rows` INT NOT NULL DEFAULT 0,
                    `matched_students` INT NOT NULL DEFAULT 0,
                    `unmatched_emails` INT NOT NULL DEFAULT 0,
                    `attended_count` INT NOT NULL DEFAULT 0,
                    `not_attended_count` INT NOT NULL DEFAULT 0,
                    `in_progress_count` INT NOT NULL DEFAULT 0,
                    `review_required_count` INT NOT NULL DEFAULT 0,
                    `uploaded_by` VARCHAR(100) NOT NULL,
                    `published_by` VARCHAR(100) NULL,
                    `published_at` DATETIME NULL,
                    `replaced_by_batch_id` INT NULL,
                    `replace_reason` TEXT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                    KEY `idx_arb_activity` (`activity_id`),
                    KEY `idx_arb_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `assessment_results` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `batch_id` INT NOT NULL,
                    `student_email` VARCHAR(255) NOT NULL,
                    `user_id` VARCHAR(50) NULL,
                    `attendance_status` ENUM('attended','not_attended','in_progress','review_required') NOT NULL,
                    `src_learner_details` VARCHAR(255) NULL,
                    `src_name` VARCHAR(255) NULL,
                    `src_mobile` VARCHAR(100) NULL,
                    `src_attempt` VARCHAR(100) NULL,
                    `src_status` VARCHAR(100) NULL,
                    `src_evaluation` VARCHAR(100) NULL,
                    `src_submitted_on` VARCHAR(100) NULL,
                    `src_answered` VARCHAR(50) NULL,
                    `score` DECIMAL(10,2) NULL,
                    `total_score` DECIMAL(10,2) NULL,
                    `src_accuracy` VARCHAR(50) NULL,
                    `accuracy_numeric` DECIMAL(8,4) NULL,
                    `src_avg_q_per_hr` VARCHAR(50) NULL,
                    `avg_q_per_hr_numeric` INT NULL,
                    `correct` INT NULL,
                    `wrong` INT NULL,
                    `skipped` INT NULL,
                    `src_time_spent` VARCHAR(100) NULL,
                    `time_spent_seconds` INT NULL,
                    `src_export` TEXT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_ar_batch` (`batch_id`),
                    KEY `idx_ar_email` (`student_email`(191)),
                    UNIQUE KEY `uq_ar_batch_email` (`batch_id`, `student_email`(191))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {}

        // Self-healing columns for Admin Geolocation and Metadata tracking
        try {
            // 1. admin_activity_log
            if ($pdo->query("SHOW TABLES LIKE 'admin_activity_log'")->fetchColumn()) {
                $cols = $pdo->query("SHOW COLUMNS FROM admin_activity_log LIKE 'latitude'")->fetch();
                if (!$cols) {
                    $pdo->exec("ALTER TABLE admin_activity_log 
                        ADD COLUMN latitude DECIMAL(10, 8) DEFAULT NULL, 
                        ADD COLUMN longitude DECIMAL(11, 8) DEFAULT NULL, 
                        ADD COLUMN metadata TEXT DEFAULT NULL");
                }
            }
            // 2. track_records
            if ($pdo->query("SHOW TABLES LIKE 'track_records'")->fetchColumn()) {
                $cols = $pdo->query("SHOW COLUMNS FROM track_records LIKE 'latitude'")->fetch();
                if (!$cols) {
                    $pdo->exec("ALTER TABLE track_records 
                        ADD COLUMN latitude DECIMAL(10, 8) DEFAULT NULL, 
                        ADD COLUMN longitude DECIMAL(11, 8) DEFAULT NULL, 
                        ADD COLUMN metadata TEXT DEFAULT NULL");
                }
            }
            // 3. whatsapp_notifications
            if ($pdo->query("SHOW TABLES LIKE 'whatsapp_notifications'")->fetchColumn()) {
                $cols = $pdo->query("SHOW COLUMNS FROM whatsapp_notifications LIKE 'latitude'")->fetch();
                if (!$cols) {
                    $pdo->exec("ALTER TABLE whatsapp_notifications 
                        ADD COLUMN latitude DECIMAL(10, 8) DEFAULT NULL, 
                        ADD COLUMN longitude DECIMAL(11, 8) DEFAULT NULL, 
                        ADD COLUMN metadata TEXT DEFAULT NULL");
                }
            }
            // 4. admin_presence table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `admin_presence` (
                    `username` VARCHAR(100) PRIMARY KEY,
                    `current_page` VARCHAR(255) NOT NULL,
                    `current_section` VARCHAR(100) DEFAULT NULL,
                    `last_seen` DATETIME NOT NULL,
                    `login_time` DATETIME NOT NULL,
                    `latitude` DECIMAL(10, 8) DEFAULT NULL,
                    `longitude` DECIMAL(11, 8) DEFAULT NULL,
                    `ip_address` VARCHAR(64) DEFAULT NULL,
                    KEY `idx_ap_seen` (`last_seen`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Exception $e) {}

    } catch (Exception $dbEx) {
        error_log("PEPP self-healing DB check failed: " . $dbEx->getMessage());
    }
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    // Don't leak credentials or internals to the browser
    http_response_code(500);
    die("Database connection failed. Please try again later.");
}
