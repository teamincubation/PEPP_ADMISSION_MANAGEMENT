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
