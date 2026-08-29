-- ============================================================================
-- PEPP Learning ERP — Database Update 23
-- Student-Level Mentor Assignment and Mentoring History
-- ============================================================================
-- Run in phpMyAdmin or MySQL CLI on the production database.
-- ============================================================================

CREATE TABLE IF NOT EXISTS mentor_student_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_user_id VARCHAR(50) NOT NULL,
  admin_id INT NOT NULL,
  course_name VARCHAR(255) NOT NULL,
  assigned_by VARCHAR(100) NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME DEFAULT NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  active_student_key VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN student_user_id ELSE NULL END) VIRTUAL,
  UNIQUE KEY uq_msa_active_student (active_student_key),
  KEY idx_msa_student (student_user_id),
  KEY idx_msa_admin (admin_id),
  KEY idx_msa_status (status),
  KEY idx_msa_course (course_name),
  KEY idx_msa_student_status (student_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
