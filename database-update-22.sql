-- ============================================================================
-- PEPP Learning ERP — Database Update 22
-- Employee Management + Admin Types + Student Mentoring + Staff Registration
-- ============================================================================
-- DO NOT RUN until approved by Super Admin.
-- Run in phpMyAdmin or MySQL CLI on the production database.
-- ============================================================================

-- 1. admins table — Add admin_type column
ALTER TABLE admins ADD COLUMN IF NOT EXISTS admin_type VARCHAR(20) NOT NULL DEFAULT 'erp_admin' AFTER role;
UPDATE admins SET admin_type = 'superadmin' WHERE role = 'super_admin' AND admin_type = 'erp_admin';

-- 2. employees table — Employee personal + employment details
CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT DEFAULT NULL,
  employee_id VARCHAR(10) NOT NULL,

  full_name VARCHAR(200) NOT NULL,
  gender ENUM('Male','Female','Other','Prefer not to say') NOT NULL,
  blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
  date_of_birth DATE NOT NULL,

  mobile_country_code VARCHAR(10) DEFAULT '+91',
  mobile_number VARCHAR(20) NOT NULL,
  email VARCHAR(255) NOT NULL,
  emergency_country_code VARCHAR(10) DEFAULT '+91',
  emergency_contact VARCHAR(20) NOT NULL,

  address TEXT NOT NULL,
  pincode VARCHAR(10) NOT NULL,
  country VARCHAR(100) DEFAULT 'India',
  state VARCHAR(100) NOT NULL,
  place_post_office VARCHAR(200) NOT NULL,

  aadhaar_encrypted VARCHAR(255) NOT NULL,
  aadhaar_masked VARCHAR(20) NOT NULL,
  bank_name VARCHAR(200) NOT NULL,
  bank_account_encrypted VARCHAR(255) NOT NULL,
  bank_account_masked VARCHAR(30) NOT NULL,
  ifsc_code VARCHAR(11) NOT NULL,
  upi_id VARCHAR(100) DEFAULT NULL,

  application_for ENUM('employee','faculty','intern') NOT NULL,
  designation VARCHAR(200) NOT NULL,
  department VARCHAR(100) NOT NULL,
  joining_date DATE NOT NULL,
  probation_till DATE DEFAULT NULL,
  contract_validity_from DATE NOT NULL,
  contract_validity_till DATE NOT NULL,
  monthly_salary DECIMAL(12,2) NOT NULL,

  appointment_reference VARCHAR(100) NOT NULL,
  appointment_snapshot LONGTEXT NOT NULL,
  appointment_generated_at DATETIME NOT NULL,

  application_id INT DEFAULT NULL,

  status ENUM('active','inactive') DEFAULT 'active',
  created_by VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_emp_employee_id (employee_id),
  UNIQUE KEY uq_emp_email (email),
  UNIQUE KEY uq_emp_appt_ref (appointment_reference),
  KEY idx_emp_admin (admin_id),
  KEY idx_emp_application (application_id),
  KEY idx_emp_status (status),
  KEY idx_emp_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. employee_custom_fields — Extensible metadata definitions
CREATE TABLE IF NOT EXISTS employee_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_label VARCHAR(100) NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    field_type ENUM('text','number','email','date','dropdown','textarea','phone') NOT NULL DEFAULT 'text',
    is_required TINYINT(1) DEFAULT 0,
    field_options TEXT DEFAULT NULL,
    sort_order INT DEFAULT 0,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ecf_key (field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. employee_custom_values — Custom field values per employee
CREATE TABLE IF NOT EXISTS employee_custom_values (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  field_id INT NOT NULL,
  field_value TEXT DEFAULT NULL,
  UNIQUE KEY uq_ecv_emp_field (employee_id, field_id),
  CONSTRAINT fk_ecv_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_ecv_field FOREIGN KEY (field_id) REFERENCES employee_custom_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. mentor_course_assignments — Admin-to-course mentoring assignments
CREATE TABLE IF NOT EXISTS mentor_course_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  course_name VARCHAR(255) NOT NULL,
  assigned_by VARCHAR(100) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mca (admin_id, course_name),
  KEY idx_mca_admin (admin_id),
  KEY idx_mca_course (course_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. mentor_call_logs — Call tracking
CREATE TABLE IF NOT EXISTS mentor_call_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_user_id VARCHAR(20) NOT NULL,
  admin_id INT NOT NULL,
  admin_username VARCHAR(100) NOT NULL,
  call_timestamp DATETIME NOT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mcl_student (student_user_id),
  KEY idx_mcl_admin (admin_id),
  KEY idx_mcl_timestamp (call_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. mentor_remarks — Student mentoring remarks
CREATE TABLE IF NOT EXISTS mentor_remarks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_user_id VARCHAR(20) NOT NULL,
  admin_id INT NOT NULL,
  admin_username VARCHAR(100) NOT NULL,
  remark TEXT NOT NULL,
  reminder_id INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mr_student (student_user_id),
  KEY idx_mr_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. indian_banks — Bank name reference table
CREATE TABLE IF NOT EXISTS indian_banks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bank_name VARCHAR(200) NOT NULL,
  bank_code VARCHAR(20) DEFAULT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  UNIQUE KEY uq_bank_name (bank_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO indian_banks (bank_name) VALUES
('State Bank of India'),('Punjab National Bank'),('Bank of Baroda'),
('Canara Bank'),('Union Bank of India'),('Bank of India'),
('Indian Bank'),('Central Bank of India'),('Indian Overseas Bank'),
('UCO Bank'),('Bank of Maharashtra'),('Punjab & Sind Bank'),
('HDFC Bank'),('ICICI Bank'),('Axis Bank'),('Kotak Mahindra Bank'),
('IndusInd Bank'),('Yes Bank'),('IDBI Bank'),('Federal Bank'),
('South Indian Bank'),('Karur Vysya Bank'),('City Union Bank'),
('Tamilnad Mercantile Bank'),('RBL Bank'),('Bandhan Bank'),
('IDFC First Bank'),('DCB Bank'),('Dhanlaxmi Bank'),
('Jammu & Kashmir Bank'),('CSB Bank'),('Nainital Bank'),
('Karnataka Bank'),('Kerala Gramin Bank'),('Baroda UP Gramin Bank'),
('India Post Payments Bank'),('Paytm Payments Bank'),
('Airtel Payments Bank'),('Fino Payments Bank'),
('Jio Payments Bank'),('NSDL Payments Bank'),
('AU Small Finance Bank'),('Equitas Small Finance Bank'),
('Ujjivan Small Finance Bank'),('Jana Small Finance Bank'),
('Suryoday Small Finance Bank'),('Capital Small Finance Bank'),
('ESAF Small Finance Bank'),('Fincare Small Finance Bank'),
('North East Small Finance Bank'),('Shivalik Small Finance Bank'),
('Unity Small Finance Bank'),('Utkarsh Small Finance Bank');

-- 9. staff_registration_requests — Application records
CREATE TABLE IF NOT EXISTS staff_registration_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_reference VARCHAR(30) NOT NULL,

  full_name VARCHAR(200) NOT NULL,
  gender ENUM('Male','Female','Other','Prefer not to say') NOT NULL,
  date_of_birth DATE NOT NULL,
  blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,

  mobile_country_code VARCHAR(10) DEFAULT '+91',
  mobile_number VARCHAR(20) NOT NULL,
  email VARCHAR(255) NOT NULL,
  emergency_country_code VARCHAR(10) DEFAULT '+91',
  emergency_contact VARCHAR(20) NOT NULL,

  address TEXT NOT NULL,
  pincode VARCHAR(10) NOT NULL,
  country VARCHAR(100) DEFAULT 'India',
  state VARCHAR(100) NOT NULL,
  place_post_office VARCHAR(200) NOT NULL,

  aadhaar_encrypted VARCHAR(255) NOT NULL,
  aadhaar_masked VARCHAR(20) NOT NULL,

  bank_name VARCHAR(200) NOT NULL,
  bank_account_encrypted VARCHAR(255) NOT NULL,
  bank_account_masked VARCHAR(30) NOT NULL,
  ifsc_code VARCHAR(11) NOT NULL,
  upi_id VARCHAR(100) DEFAULT NULL,

  application_for ENUM('employee','faculty','intern') NOT NULL,
  custom_field_values LONGTEXT DEFAULT NULL,

  latitude DECIMAL(10,8) NOT NULL,
  longitude DECIMAL(11,8) NOT NULL,
  maps_url TEXT NOT NULL,

  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(500) DEFAULT NULL,
  submitted_at DATETIME NOT NULL,

  status ENUM('pending','under_review','approved','rejected','cancelled')
    NOT NULL DEFAULT 'pending',

  reviewed_by VARCHAR(100) DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  rejection_reason TEXT DEFAULT NULL,

  approved_employee_id VARCHAR(10) DEFAULT NULL,
  designation VARCHAR(200) DEFAULT NULL,
  department VARCHAR(100) DEFAULT NULL,
  joining_date DATE DEFAULT NULL,
  probation_till DATE DEFAULT NULL,
  contract_validity_from DATE DEFAULT NULL,
  contract_validity_till DATE DEFAULT NULL,
  monthly_salary DECIMAL(12,2) DEFAULT NULL,

  appointment_reference VARCHAR(100) DEFAULT NULL,
  appointment_snapshot LONGTEXT DEFAULT NULL,
  appointment_generated_at DATETIME DEFAULT NULL,

  approved_by_admin_id INT DEFAULT NULL,
  approved_by_username VARCHAR(100) DEFAULT NULL,
  approved_at DATETIME DEFAULT NULL,

  employee_record_id INT DEFAULT NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_srr_app_ref (application_reference),
  KEY idx_srr_status (status),
  KEY idx_srr_email (email),
  KEY idx_srr_mobile (mobile_number),
  KEY idx_srr_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. departments — Reference table
CREATE TABLE IF NOT EXISTS departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  department_name VARCHAR(100) NOT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  sort_order INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dept_name (department_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO departments (department_name, sort_order) VALUES
('Learning and Development', 1),
('Technical', 2),
('Accounts & Payments', 3),
('Creative', 4),
('Administration & HR', 5);

-- 11. staff_registration_rate_limits — IP-based rate limiting
CREATE TABLE IF NOT EXISTS staff_registration_rate_limits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45) NOT NULL,
  attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_srrl_ip (ip_address),
  KEY idx_srrl_time (attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Seed sequence counters (admin_settings has UNIQUE on setting_name)
INSERT IGNORE INTO admin_settings (setting_name, setting_value, created_at, updated_at)
VALUES ('emp_id_seq', '124', NOW(), NOW());

INSERT IGNORE INTO admin_settings (setting_name, setting_value, created_at, updated_at)
VALUES ('appt_ref_seq', '1', NOW(), NOW());

INSERT IGNORE INTO admin_settings (setting_name, setting_value, created_at, updated_at)
VALUES ('staff_app_ref_seq', '1', NOW(), NOW());
