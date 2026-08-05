-- Database update 13: Study Plans Management System tables
CREATE TABLE IF NOT EXISTS `study_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `academic_year` varchar(50) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `theme` varchar(50) NOT NULL DEFAULT 'default',
  `layout` varchar(50) NOT NULL DEFAULT 'timeline',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('draft','published','scheduled','archived') NOT NULL DEFAULT 'draft',
  `publish_start` datetime DEFAULT NULL,
  `publish_end` datetime DEFAULT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `is_template` tinyint(1) NOT NULL DEFAULT 0,
  `custom_settings` longtext DEFAULT NULL, -- JSON for custom styles, banners, motivation quotes, faculty details
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_plan_activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `study_plan_id` int(11) NOT NULL,
  `activity_date` date NOT NULL,
  `day_number` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `chapter` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `subtopic` varchar(255) DEFAULT NULL,
  `activity_title` varchar(255) NOT NULL,
  `activity_description` text DEFAULT NULL,
  `activity_type` varchar(100) NOT NULL,
  `faculty` varchar(255) DEFAULT NULL,
  `mentor` varchar(255) DEFAULT NULL,
  `estimated_duration` int(11) DEFAULT NULL, -- in minutes
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `difficulty_level` enum('easy','medium','hard') NOT NULL DEFAULT 'medium',
  `resource_links` text DEFAULT NULL,
  `custom_activity_badge` varchar(100) DEFAULT NULL,
  `custom_activity_color` varchar(50) DEFAULT NULL,
  `custom_activity_icon` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_spa_plan` (`study_plan_id`),
  KEY `idx_spa_date` (`activity_date`),
  CONSTRAINT `fk_spa_plan` FOREIGN KEY (`study_plan_id`) REFERENCES `study_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_plan_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `study_plan_id` int(11) NOT NULL,
  `assignment_type` enum('all','course','batch','student') NOT NULL,
  `assigned_value` varchar(255) NOT NULL, -- e.g. Course name, academic year, student_id, batch
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_spa_assign_plan` (`study_plan_id`),
  CONSTRAINT `fk_spa_assign_plan` FOREIGN KEY (`study_plan_id`) REFERENCES `study_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_plan_custom_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `icon` varchar(100) NOT NULL,
  `color` varchar(50) NOT NULL,
  `badge` varchar(100) DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_custom_type_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_plan_audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `study_plan_id` int(11) DEFAULT NULL,
  `admin_username` varchar(100) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp_audit_plan` (`study_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_plan_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `study_plan_id` int(11) NOT NULL,
  `student_email` varchar(255) DEFAULT NULL,
  `action_type` enum('view','download','complete_activity') NOT NULL,
  `activity_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp_anal_plan` (`study_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
