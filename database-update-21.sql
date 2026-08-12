-- Database migration update 21: L&D Operations Task Tracker + Settings + Reporting

CREATE TABLE IF NOT EXISTS `ld_work_courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_name` varchar(255) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_course_name` (`course_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ld_work_modes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mode_name` varchar(255) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mode_name` (`mode_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ld_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `admin_username` varchar(100) NOT NULL,
  `admin_name` varchar(150) NOT NULL,
  `admin_role` varchar(50) NOT NULL,
  `course_id` int(11) NOT NULL,
  `course_name` varchar(255) NOT NULL,
  `mode_id` int(11) NOT NULL,
  `mode_name` varchar(255) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `maps_url` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_reason` text DEFAULT NULL,
  `deleted_latitude` decimal(10,8) DEFAULT NULL,
  `deleted_longitude` decimal(11,8) DEFAULT NULL,
  `deleted_maps_url` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ld_tasks_admin_id` (`admin_id`),
  KEY `idx_ld_tasks_course_id` (`course_id`),
  KEY `idx_ld_tasks_mode_id` (`mode_id`),
  KEY `idx_ld_tasks_status` (`status`),
  KEY `idx_ld_tasks_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ld_task_topics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `topic_name` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ld_task_topics_task_id` (`task_id`),
  CONSTRAINT `fk_ld_task_topics_task_id` FOREIGN KEY (`task_id`) REFERENCES `ld_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ld_task_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `admin_username` varchar(100) NOT NULL,
  `action` varchar(20) NOT NULL,
  `previous_values` longtext DEFAULT NULL,
  `new_values` longtext DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `maps_url` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ld_task_audit_task_id` (`task_id`),
  KEY `idx_ld_task_audit_admin_id` (`admin_id`),
  KEY `idx_ld_task_audit_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default Work Courses
INSERT IGNORE INTO `ld_work_courses` (`course_name`, `status`, `sort_order`) VALUES
('Course A', 'active', 0),
('Course B', 'active', 1),
('Course C', 'active', 2);

-- Seed default Work Modes
INSERT IGNORE INTO `ld_work_modes` (`mode_name`, `status`, `sort_order`) VALUES
('Tests', 'active', 0),
('Add New Study Materials', 'active', 1),
('Generate Material Covers', 'active', 2),
('Upload Lecture Videos', 'active', 3),
('Create MCQs', 'active', 4),
('Update Study Materials', 'active', 5),
('Proofreading', 'active', 6),
('Video Editing', 'active', 7),
('Upload Documents', 'active', 8),
('Content Research', 'active', 9),
('Other L&D Work', 'active', 10);
