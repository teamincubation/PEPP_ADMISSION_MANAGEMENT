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
                KEY `idx_field_form` (`form_id`),
                CONSTRAINT `fk_field_form` FOREIGN KEY (`form_id`) REFERENCES `campaign_forms` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

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
                KEY `idx_submission_form` (`form_id`),
                CONSTRAINT `fk_submission_form` FOREIGN KEY (`form_id`) REFERENCES `campaign_forms` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Self-healing columns for soft deletion in submissions
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM campaign_form_submissions LIKE 'is_deleted'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE campaign_form_submissions ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `deleted_at` DATETIME NULL");
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
    } catch (Exception $dbEx) {
        error_log("PEPP self-healing DB check failed: " . $dbEx->getMessage());
    }
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    // Don't leak credentials or internals to the browser
    http_response_code(500);
    die("Database connection failed. Please try again later.");
}
