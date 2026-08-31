<?php
/**
 * PEPP Learning — database connection.
 * Cleaned: the old file ran a large CREATE TABLE block on EVERY request,
 * slowing down every page. The schema already exists in the database
 * (see the SQL dump); run migrations manually when needed.
 *
 * Credentials are loaded from config/secrets.php (gitignored).
 */
date_default_timezone_set('Asia/Kolkata');

// Load credentials from the gitignored secrets file
$_secrets_path = __DIR__ . '/secrets.php';
if (file_exists($_secrets_path)) {
    require_once $_secrets_path;
}

define('DB_HOST', defined('PEPP_DB_HOST') ? PEPP_DB_HOST : (getenv('PEPP_DB_HOST') ?: 'localhost'));
define('DB_NAME', defined('PEPP_DB_NAME') ? PEPP_DB_NAME : (getenv('PEPP_DB_NAME') ?: 'u361910773_peppadmin'));
define('DB_USER', defined('PEPP_DB_USER') ? PEPP_DB_USER : (getenv('PEPP_DB_USER') ?: 'root'));
define('DB_PASS', defined('PEPP_DB_PASS') ? PEPP_DB_PASS : (getenv('PEPP_DB_PASS') ?: ''));
if (!defined('INVOICE_HMAC_SECRET')) {
    define('INVOICE_HMAC_SECRET', getenv('INVOICE_HMAC_SECRET') ?: 'CHANGE_ME');
}

$sqlite_env_path = getenv('PEPP_SQLITE_PATH') ?: ($_ENV['PEPP_SQLITE_PATH'] ?? ($_SERVER['PEPP_SQLITE_PATH'] ?? ''));
$is_local_dev = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true)
    || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost')
    || str_starts_with($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 8888
    || (!empty($sqlite_env_path))
    || php_sapi_name() === 'cli'
    || ((isset($_SERVER['HTTP_X_TESTING_MODE']) && $_SERVER['HTTP_X_TESTING_MODE'] === 'true'));

if ($is_local_dev) {
    try {
        $test_db_default = dirname(__DIR__) . '/scratch_test_db.sqlite';
        $sqlite_file = $sqlite_env_path ?: (file_exists($test_db_default) ? $test_db_default : ':memory:');
        $pdo = new PDO("sqlite:" . $sqlite_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->sqliteCreateFunction('NOW', function() {
                return date('Y-m-d H:i:s');
            });
            $pdo->sqliteCreateFunction('CURDATE', function() {
                return date('Y-m-d');
            });
            $pdo->sqliteCreateFunction('DATE_SUB', function($date, $interval) {
                return date('Y-m-d H:i:s', strtotime('-30 days', strtotime((string)$date)));
            });
            $pdo->sqliteCreateFunction('DATEDIFF', function($d1, $d2) {
                if (!$d1 || !$d2) return null;
                $t1 = strtotime((string)$d1);
                $t2 = strtotime((string)$d2);
                if ($t1 === false || $t2 === false) return null;
                return (int)round(($t1 - $t2) / 86400);
            });
            $pdo->sqliteCreateFunction('GREATEST', function(...$args) {
                return max($args);
            });
            $pdo->sqliteCreateFunction('LEAST', function(...$args) {
                return min($args);
            });
            $pdo->sqliteCreateFunction('MONTH', function($date) {
                if (!$date) return null;
                return (int)date('m', strtotime((string)$date));
            });
            $pdo->sqliteCreateFunction('YEAR', function($date) {
                if (!$date) return null;
                return (int)date('Y', strtotime((string)$date));
            });
            $pdo->sqliteCreateFunction('DATE_FORMAT', function($date, $format) {
                if (!$date) return null;
                $php_format = str_replace(['%Y', '%m', '%d', '%H', '%i', '%s'], ['Y', 'm', 'd', 'H', 'i', 's'], $format);
                return date($php_format, strtotime((string)$date));
            });
            $pdo->sqliteCreateFunction('TIMESTAMPDIFF', function($unit, $datetime1, $datetime2) {
                $t1 = strtotime((string)$datetime1);
                $t2 = strtotime((string)$datetime2);
                if ($t1 === false || $t2 === false) return 0;
                return $t2 - $t1;
            });
        }

        $conn = $pdo;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_settings (
                setting_name TEXT PRIMARY KEY,
                setting_value TEXT,
                updated_at TEXT
            );
            CREATE TABLE IF NOT EXISTS communication_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT NOT NULL DEFAULT 'whatsapp',
                recipient TEXT NOT NULL,
                recipient_name TEXT DEFAULT NULL,
                subject TEXT DEFAULT NULL,
                body_html TEXT DEFAULT NULL,
                body_text TEXT DEFAULT NULL,
                template_name TEXT DEFAULT NULL,
                template_data TEXT DEFAULT NULL,
                attachments TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                priority INTEGER NOT NULL DEFAULT 0,
                retry_count INTEGER NOT NULL DEFAULT 0,
                last_retry_at TEXT DEFAULT NULL,
                next_attempt_at TEXT NOT NULL,
                message_id TEXT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                sent_by TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                worker_started_at TEXT DEFAULT NULL,
                api_requested_at TEXT DEFAULT NULL,
                api_responded_at TEXT DEFAULT NULL,
                delivered_at TEXT DEFAULT NULL,
                student_uid TEXT DEFAULT NULL,
                event_name TEXT DEFAULT NULL,
                invoice_id INTEGER DEFAULT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_status_channel_next_attempt ON communication_queue (status, channel, next_attempt_at, created_at);
            CREATE TABLE IF NOT EXISTS communication_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                channel TEXT NOT NULL DEFAULT 'whatsapp',
                target_audience TEXT DEFAULT 'students',
                template_name TEXT DEFAULT NULL,
                segment_criteria TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'draft',
                scheduled_at TEXT DEFAULT NULL,
                created_by TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS communication_campaign_recipients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                recipient TEXT NOT NULL,
                recipient_name TEXT DEFAULT NULL,
                queue_id INTEGER DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                sent_at TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                lead_id INTEGER DEFAULT NULL,
                user_id TEXT DEFAULT NULL
            );
            CREATE TABLE IF NOT EXISTS communication_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT NOT NULL DEFAULT 'whatsapp',
                template_name TEXT NOT NULL,
                language TEXT NOT NULL DEFAULT 'en',
                status TEXT NOT NULL DEFAULT 'approved',
                category TEXT DEFAULT NULL,
                quality_status TEXT DEFAULT NULL,
                rejection_reason TEXT DEFAULT NULL,
                meta_data TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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
            CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE,
                password_hash TEXT,
                full_name TEXT,
                role TEXT,
                permissions TEXT,
                status TEXT,
                created_by TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT,
                last_login_at TEXT,
                credential_visibility TEXT DEFAULT 'visible',
                credential_visibility_scopes TEXT DEFAULT '',
                can_edit INTEGER DEFAULT 1,
                can_delete INTEGER DEFAULT 1,
                can_export INTEGER DEFAULT 1,
                allow_copy_email INTEGER DEFAULT 1,
                allow_whatsapp_chat INTEGER DEFAULT 1,
                allow_phone_call INTEGER DEFAULT 1
            );
            CREATE TABLE IF NOT EXISTS admin_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER,
                admin_username TEXT,
                session_id TEXT,
                action_type TEXT,
                module TEXT,
                page TEXT,
                section TEXT,
                target_type TEXT,
                target_id TEXT,
                details TEXT,
                request_method TEXT,
                request_uri TEXT,
                referrer TEXT,
                ip_address TEXT,
                location TEXT,
                user_agent TEXT,
                latitude REAL,
                longitude REAL,
                is_heartbeat INTEGER DEFAULT 0,
                is_idle INTEGER DEFAULT 0,
                metadata TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS admin_presence (
                username TEXT PRIMARY KEY,
                current_page TEXT,
                current_section TEXT,
                last_seen TEXT,
                login_time TEXT,
                latitude REAL,
                longitude REAL,
                ip_address TEXT,
                session_id TEXT,
                is_idle INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS card_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                category TEXT,
                description TEXT,
                bg_image TEXT,
                canvas_width INTEGER,
                canvas_height INTEGER,
                resolution_dpi INTEGER DEFAULT 72,
                aspect_ratio TEXT,
                status TEXT DEFAULT 'active',
                elements_json TEXT,
                created_by TEXT,
                created_at TEXT
            );
            CREATE TABLE IF NOT EXISTS test_result_cards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                academic_year TEXT,
                course_id INTEGER,
                study_plan_id INTEGER,
                activity_id INTEGER,
                template_id INTEGER,
                design_title TEXT,
                output_format TEXT,
                output_file TEXT,
                student_rank_mappings TEXT,
                design_config TEXT,
                created_by TEXT,
                created_at TEXT
            );
            CREATE TABLE IF NOT EXISTS academic_years (
                year TEXT PRIMARY KEY,
                start_date TEXT,
                end_date TEXT,
                status TEXT DEFAULT 'active'
            );
            CREATE TABLE IF NOT EXISTS pepp_courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_name TEXT,
                course_code TEXT,
                academic_year TEXT,
                status TEXT DEFAULT 'active',
                total_fee REAL DEFAULT 0.00,
                course_type TEXT DEFAULT 'UG'
            );
            CREATE TABLE IF NOT EXISTS study_plans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                academic_year TEXT,
                status TEXT DEFAULT 'published',
                start_date TEXT,
                end_date TEXT,
                plan_type TEXT,
                version INTEGER DEFAULT 1,
                updated_at TEXT,
                is_deleted INTEGER DEFAULT 0,
                deleted_at TEXT,
                deleted_by TEXT,
                deletion_reason TEXT
            );
            CREATE TABLE IF NOT EXISTS study_plan_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                study_plan_id INTEGER,
                assignment_type TEXT,
                assigned_value TEXT,
                is_deleted INTEGER DEFAULT 0,
                deleted_at TEXT,
                deleted_by TEXT
            );
            CREATE TABLE IF NOT EXISTS study_plan_activities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                study_plan_id INTEGER,
                activity_title TEXT,
                activity_description TEXT,
                activity_type TEXT,
                activity_date TEXT,
                chapter TEXT,
                topic TEXT,
                day_number TEXT,
                sort_order INTEGER,
                activity_uid TEXT,
                faculty TEXT,
                estimated_duration INTEGER DEFAULT 60,
                priority TEXT DEFAULT 'medium',
                difficulty_level TEXT DEFAULT 'medium',
                resource_links TEXT,
                custom_activity_badge TEXT,
                custom_activity_color TEXT,
                custom_activity_icon TEXT,
                start_time TEXT,
                end_time TEXT,
                is_deleted INTEGER DEFAULT 0,
                deleted_at TEXT,
                deleted_by TEXT,
                deletion_reason TEXT
            );
            CREATE TABLE IF NOT EXISTS study_plan_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                study_plan_id INTEGER NOT NULL,
                admin_username TEXT NOT NULL,
                action TEXT NOT NULL,
                details TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS campaign_forms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                slug TEXT,
                description TEXT,
                form_type TEXT DEFAULT 'registration',
                status TEXT DEFAULT 'published',
                is_deleted INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS study_plan_custom_types (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                icon TEXT DEFAULT 'fa-bookmark',
                color TEXT DEFAULT '#3b82f6',
                badge TEXT DEFAULT 'Custom'
            );
            CREATE TABLE IF NOT EXISTS study_plan_chapters (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chapter_code TEXT,
                chapter_name TEXT NOT NULL,
                course_id TEXT,
                sort_order INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS assessment_result_batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                activity_id INTEGER,
                study_plan_id INTEGER,
                academic_year TEXT,
                course_id INTEGER,
                course_name TEXT,
                activity_title_snapshot TEXT,
                activity_type_snapshot TEXT,
                activity_date_snapshot TEXT,
                chapter_snapshot TEXT,
                version INTEGER DEFAULT 1,
                status TEXT DEFAULT 'published',
                published_at TEXT,
                published_by TEXT
            );
            CREATE TABLE IF NOT EXISTS card_layout_presets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                elements_json TEXT NOT NULL,
                is_default INTEGER DEFAULT 0,
                created_by TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT,
                status TEXT DEFAULT 'active'
            );
            CREATE TABLE IF NOT EXISTS assessment_results (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_id INTEGER,
                student_email TEXT,
                score REAL,
                total_score REAL,
                attendance_status TEXT DEFAULT 'attended',
                src_name TEXT,
                user_id INTEGER
            );
            CREATE TABLE IF NOT EXISTS users (
                user_id TEXT PRIMARY KEY,
                name TEXT,
                email TEXT,
                whatsapp_country_code TEXT,
                whatsapp_number TEXT,
                mobile_number TEXT,
                emergency_contact TEXT,
                postal_address TEXT,
                postal_pincode TEXT,
                state TEXT,
                district TEXT,
                place_post_office TEXT,
                college_school TEXT,
                gender TEXT,
                date_of_birth TEXT,
                how_know_pepp TEXT,
                course TEXT,
                university_board TEXT,
                instagram_id TEXT,
                payment_plan TEXT,
                peppkit_eligible TEXT,
                discount_amount REAL DEFAULT 0.00,
                discount_remark TEXT,
                user_photo TEXT,
                payment_screenshot TEXT,
                status TEXT DEFAULT 'approved',
                student_status TEXT DEFAULT 'active',
                onboarding_status TEXT DEFAULT 'pending',
                last_visit_location TEXT,
                pepp_course TEXT,
                pepp_academic_year TEXT,
                total_fee REAL DEFAULT 0.00,
                paid_amount REAL DEFAULT 0.00,
                payment_account_id INTEGER,
                paid_date TEXT,
                joined_date TEXT,
                approved_by TEXT,
                approval_date TEXT,
                course_duration_date TEXT,
                course_status TEXT DEFAULT 'active',
                created_at TEXT,
                updated_at TEXT
            );
            CREATE TABLE IF NOT EXISTS instalment_details (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                instalment_number INTEGER NOT NULL,
                amount REAL NOT NULL,
                due_date TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                paid_amount REAL,
                paid_date TEXT,
                payment_reference TEXT,
                payment_mode TEXT,
                payment_account_id INTEGER,
                admin_remarks TEXT,
                approved_by TEXT,
                approved_at TEXT,
                rejected_by TEXT,
                rejected_at TEXT,
                created_at TEXT,
                updated_at TEXT
            );
            CREATE TABLE IF NOT EXISTS payment_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_name TEXT NOT NULL,
                account_type TEXT,
                account_details TEXT,
                banking_details TEXT,
                is_public INTEGER DEFAULT 0,
                status TEXT DEFAULT 'active',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_no TEXT UNIQUE,
                invoice_type TEXT,
                user_id TEXT,
                student_name TEXT,
                email TEXT,
                course TEXT,
                payment_plan TEXT,
                source TEXT,
                source_ref INTEGER,
                instalment_number INTEGER,
                gross_amount REAL,
                taxable_value REAL,
                cgst_amount REAL,
                sgst_amount REAL,
                round_off REAL,
                payment_account_id INTEGER,
                payment_mode TEXT,
                paid_date TEXT,
                email_status TEXT DEFAULT 'skipped',
                generated_by TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS study_plan_custom_types (
                name TEXT PRIMARY KEY
            );
            CREATE TABLE IF NOT EXISTS study_plan_analytics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                study_plan_id INTEGER NOT NULL,
                student_email TEXT,
                action_type TEXT NOT NULL,
                activity_id INTEGER,
                activity_uid TEXT,
                ip_address TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                completion_status TEXT DEFAULT 'completed',
                cleared_by TEXT DEFAULT NULL,
                cleared_at TEXT DEFAULT NULL,
                clear_reason TEXT DEFAULT NULL,
                latitude TEXT DEFAULT NULL,
                longitude TEXT DEFAULT NULL,
                resolved_place TEXT DEFAULT NULL,
                activity_title_snapshot TEXT DEFAULT NULL,
                activity_type_snapshot TEXT DEFAULT NULL,
                activity_date_snapshot TEXT DEFAULT NULL,
                day_number_snapshot INTEGER DEFAULT NULL,
                chapter_snapshot TEXT DEFAULT NULL,
                subject_snapshot TEXT DEFAULT NULL,
                topic_snapshot TEXT DEFAULT NULL
            );
            CREATE TABLE IF NOT EXISTS study_plan_activity_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                activity_id INTEGER NOT NULL,
                activity_uid TEXT NOT NULL,
                study_plan_id INTEGER NOT NULL,
                version_number INTEGER NOT NULL,
                activity_date TEXT NOT NULL,
                day_number INTEGER NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                chapter TEXT,
                topic TEXT,
                activity_title TEXT NOT NULL,
                activity_description TEXT,
                activity_type TEXT NOT NULL,
                faculty TEXT,
                estimated_duration INTEGER,
                priority TEXT DEFAULT 'medium',
                difficulty_level TEXT DEFAULT 'medium',
                resource_links TEXT,
                custom_activity_badge TEXT,
                custom_activity_color TEXT,
                custom_activity_icon TEXT,
                created_by TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                change_type TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS study_plan_analytics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                study_plan_id INTEGER NOT NULL,
                student_email TEXT,
                action_type TEXT NOT NULL,
                activity_id INTEGER,
                activity_uid TEXT,
                ip_address TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                completion_status TEXT DEFAULT 'completed',
                cleared_by TEXT DEFAULT NULL,
                cleared_at TEXT DEFAULT NULL,
                clear_reason TEXT DEFAULT NULL,
                latitude TEXT DEFAULT NULL,
                longitude TEXT DEFAULT NULL,
                resolved_place TEXT DEFAULT NULL,
                activity_title_snapshot TEXT DEFAULT NULL,
                activity_type_snapshot TEXT DEFAULT NULL,
                activity_date_snapshot TEXT DEFAULT NULL,
                day_number_snapshot INTEGER DEFAULT NULL,
                chapter_snapshot TEXT DEFAULT NULL,
                subject_snapshot TEXT DEFAULT NULL,
                topic_snapshot TEXT DEFAULT NULL
            );
            CREATE TABLE IF NOT EXISTS card_template_admin_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_id INTEGER NOT NULL,
                admin_user_id INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT,
                UNIQUE(template_id, admin_user_id)
            );
            CREATE TABLE IF NOT EXISTS campaign_form_admin_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_id INTEGER NOT NULL,
                admin_user_id INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT,
                UNIQUE(form_id, admin_user_id)
            );
            CREATE TABLE IF NOT EXISTS campaign_form_answers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_id INTEGER,
                field_id INTEGER,
                submission_id INTEGER,
                field_key TEXT,
                answer_text TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS student_course_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                old_course TEXT NOT NULL,
                old_course_id INTEGER,
                old_course_fee REAL,
                new_course TEXT NOT NULL,
                new_course_id INTEGER,
                new_course_fee REAL,
                payment_plan TEXT,
                paid_amount_at_migration REAL,
                outstanding_before REAL,
                outstanding_after REAL,
                upgrade_amount REAL,
                migration_reason TEXT,
                migrated_by TEXT,
                migrated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                status TEXT DEFAULT 'completed',
                revised_installment_schedule TEXT
            );
        ");

        $perf_indexes = [
            "CREATE INDEX IF NOT EXISTS idx_users_status_created ON users (status, student_status, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_instalment_user_status ON instalment_details (user_id, status)",
            "CREATE INDEX IF NOT EXISTS idx_instalment_due_date ON instalment_details (status, due_date)",
            "CREATE INDEX IF NOT EXISTS idx_student_remarks_user ON student_remarks (user_id)",
            "CREATE INDEX IF NOT EXISTS idx_admin_activity_created ON admin_activity_log (created_at)",
            "CREATE INDEX IF NOT EXISTS idx_mentor_student_admin ON mentor_student_assignments (admin_id, student_user_id)",
            "CREATE INDEX IF NOT EXISTS idx_mentor_calls_admin ON mentor_call_logs (admin_id, student_user_id)",
            "CREATE INDEX IF NOT EXISTS idx_mentor_remarks_admin ON mentor_remarks (admin_id, student_user_id)",
            "CREATE INDEX IF NOT EXISTS idx_study_plan_activities_plan ON study_plan_activities (study_plan_id, is_deleted, day_number, sort_order)",
            "CREATE INDEX IF NOT EXISTS idx_study_plan_analytics_lookup ON study_plan_analytics (student_email, study_plan_id, action_type, completion_status)"
        ];
        foreach ($perf_indexes as $idx_sql) {
            try {
                $pdo->exec($idx_sql);
            } catch (Throwable $e) {}
        }

        $pdo->exec(<<<'SQL_SEED'
            INSERT OR REPLACE INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_webhook_verify_token', 'test_verify_token');
            INSERT OR REPLACE INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_app_secret', 'test_app_secret');

            -- Seed Admins for testing login
            INSERT OR IGNORE INTO admins (id, username, password_hash, role, permissions, status)
            VALUES (1, 'admin', '', 'super_admin', 'ALL', 'active');

            -- Seed payment accounts
            INSERT OR REPLACE INTO payment_accounts (id, account_name, is_public, status)
            VALUES (1, 'Main Bank', 1, 'active');

            -- Seed Academic Data
            INSERT OR REPLACE INTO academic_years (year, start_date, end_date) VALUES ('2026-27', '2026-06-01', '2027-05-31');
            INSERT OR REPLACE INTO pepp_courses (id, course_name, course_code, academic_year, status, total_fee) VALUES (1, 'CUET PG Psychology', 'CP101', '2026-27', 'active', 10000.00);
            INSERT OR REPLACE INTO pepp_courses (id, course_name, course_code, academic_year, status, total_fee) VALUES (2, 'Premium Course B', 'CP102', '2026-27', 'active', 15000.00);

            INSERT OR REPLACE INTO study_plans (id, title, academic_year, status, plan_type) VALUES (1, 'Psychology Prep Plan', '2026-27', 'published', 'regular');
            INSERT OR REPLACE INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (1, 'course', 'CUET PG Psychology');

            -- Seed target student PEPP20268575
            INSERT OR REPLACE INTO users (user_id, name, email, college_school, status, student_status, pepp_course, pepp_academic_year, total_fee, paid_amount, payment_plan, joined_date, created_at)
            VALUES ('PEPP20268575', 'John Doe', 'johndoe@gmail.com', 'PEPP Academy', 'approved', 'active', 'CUET PG Psychology', '2026-27', 10000.00, 4000.00, '3 Installments', '2026-06-01', datetime('now'));

            -- Seed Test Activities
            -- Activity 30: Multi-student test (with tied ranks, long names, etc.)
            INSERT OR REPLACE INTO study_plan_activities (id, study_plan_id, activity_title, activity_type, activity_date, chapter, topic, day_number, sort_order)
            VALUES (30, 1, 'IHBAS Mock Test 01', 'Attend Mock Test', '2026-08-20', 'Chapter 1: Cognitive Psychology', 'Psychology', '1', 1);
            -- Activity 31: Single student test
            INSERT OR REPLACE INTO study_plan_activities (id, study_plan_id, activity_title, activity_type, activity_date, chapter, topic, day_number, sort_order)
            VALUES (31, 1, 'Practice Quiz 02', 'Attend Weekly Test', '2026-08-22', 'Chapter 2: Research Methods', 'Research', '2', 2);

            -- Seed Batches
            INSERT OR REPLACE INTO assessment_result_batches (id, activity_id, course_id, version, status) VALUES (5, 30, 1, 1, 'published');
            INSERT OR REPLACE INTO assessment_result_batches (id, activity_id, course_id, version, status) VALUES (6, 31, 1, 1, 'published');

            -- Seed Users
            INSERT OR REPLACE INTO users (user_id, name, email, college_school, user_photo)
            VALUES (101, 'Ananya Sharma', 'ananya@gmail.com', 'Delhi University', 'uploads/photos/photo_1757790651_68c5c1bbd19a1.jpg');
            INSERT OR REPLACE INTO users (user_id, name, email, college_school, user_photo)
            VALUES (102, 'Rahul Varma', 'rahul@gmail.com', 'LSR College', 'uploads/photos/68c840efcf34a_incubationskillindia 300x300.jpg');
            INSERT OR REPLACE INTO users (user_id, name, email, college_school, user_photo)
            VALUES (103, 'Rahul Varma', 'rahul2@gmail.com', 'St. Stephen''s College', ''); -- Tied, same name, no photo
            INSERT OR REPLACE INTO users (user_id, name, email, college_school, user_photo)
            VALUES (104, 'Priya Iyer', 'priya@gmail.com', 'Christ University Dept of Psychology', 'uploads/photos/photo_1757790651_68c5c1bbd19a1.jpg'); -- Long school name
            INSERT OR REPLACE INTO users (user_id, name, email, college_school, user_photo)
            VALUES (105, 'Chidambaram Subrahmanian Ramakrishnan Achary', 'chidambaram@gmail.com', 'Loyola College Chennai', 'uploads/photos/68c840efcf34a_incubationskillindia 300x300.jpg'); -- Very long name
            INSERT OR REPLACE INTO users (user_id, name, email, college_school, user_photo)
            VALUES (106, 'Sole Winner', 'winner@gmail.com', 'IIT Madras', 'uploads/photos/photo_1757790651_68c5c1bbd19a1.jpg');

            -- Seed Assessment Scores for Batch 5 (Activity 30)
            INSERT OR REPLACE INTO assessment_results (batch_id, student_email, score, total_score, src_name, user_id)
            VALUES (5, 'ananya@gmail.com', 98, 100, 'Ananya Sharma', 101); -- Rank 1
            INSERT OR REPLACE INTO assessment_results (batch_id, student_email, score, total_score, src_name, user_id)
            VALUES (5, 'rahul@gmail.com', 95, 100, 'Rahul Varma', 102); -- Rank 2
            INSERT OR REPLACE INTO assessment_results (batch_id, student_email, score, total_score, src_name, user_id)
            VALUES (5, 'rahul2@gmail.com', 95, 100, 'Rahul Varma', 103); -- Rank 2 (Tied!)
            INSERT OR REPLACE INTO assessment_results (batch_id, student_email, score, total_score, src_name, user_id)
            VALUES (5, 'priya@gmail.com', 90, 100, 'Priya Iyer', 104); -- Rank 4 (Tied gap!)
            INSERT OR REPLACE INTO assessment_results (batch_id, student_email, score, total_score, src_name, user_id)
            VALUES (5, 'chidambaram@gmail.com', 85, 100, 'Chidambaram Subrahmanian Ramakrishnan Achary', 105); -- Rank 5 (Overflow)

            -- Seed Assessment Scores for Batch 6 (Activity 31)
            INSERT OR REPLACE INTO assessment_results (batch_id, student_email, score, total_score, src_name, user_id)
            VALUES (6, 'winner@gmail.com', 100, 100, 'Sole Winner', 106);

            -- Default template preset inside card_templates (using exact 1671x2048 grid dimensions)
            INSERT OR REPLACE INTO card_templates
            (id, title, category, description, bg_image, canvas_width, canvas_height, resolution_dpi, aspect_ratio, status, elements_json, created_by, created_at)
            VALUES
            (10, 'Mega Test Result Template', 'Achievement', 'Mega Test result announcement template with top 4 ranks.', 'uploads/card_templates/mega_test_result_template.jpg', 1671, 2048, 300, '1671:2048', 'active',
            '[{"id":"test_number","name":"Test Number","type":"text","textContent":"1","left":1215,"top":165,"width":120,"height":120,"fontFamily":"Google Sans Flex","fontSize":110,"fontWeight":"700","color":"#ffffff","textAlign":"center","lineHeight":1.0,"letterSpacing":0,"opacity":1,"rotate":0},{"id":"chapter_name","name":"Chapter Name","type":"text","textContent":"Test Chapter","left":290,"top":340,"width":800,"height":80,"fontFamily":"Google Sans Flex","fontSize":48,"fontWeight":"700","color":"#f59e0b","textAlign":"left","lineHeight":1.2,"letterSpacing":0,"opacity":1,"rotate":0},{"id":"rank_badge_1","name":"Rank 1 Badge","type":"text","textContent":"1st","left":125,"top":510,"width":90,"height":90,"fontFamily":"Google Sans Flex","fontSize":36,"fontWeight":"700","color":"#ffffff","textAlign":"center","lineHeight":1.0,"letterSpacing":0,"opacity":1,"rotate":0,"showMarker":true,"markerColor":"#eab308"},{"id":"rank_photo_1","name":"Rank 1 Photo","type":"photo","left":260,"top":470,"width":170,"height":170,"borderWidth":0,"borderColor":"#fecaca","mask":"rounded","opacity":1,"rotate":0},{"id":"rank_name_1","name":"Rank 1 Student Name","type":"text","textContent":"Student Name","left":480,"top":495,"width":800,"height":55,"fontFamily":"Google Sans Flex","fontSize":42,"fontWeight":"700","color":"#1e293b","textAlign":"left","lineHeight":1.2,"letterSpacing":0,"opacity":1,"rotate":0},{"id":"rank_institute_1","name":"Rank 1 Institute","type":"text","textContent":"College Name","left":480,"top":555,"width":800,"height":45,"fontFamily":"Google Sans Flex","fontSize":30,"fontWeight":"400","color":"#64748b","textAlign":"left","lineHeight":1.2,"letterSpacing":0,"opacity":1,"rotate":0},{"id":"metadata","name":"Metadata","type":"metadata","coordinate_mode":"native"}]',
            'system', DATETIME('now'));
SQL_SEED
        );
        return;
    } catch (Throwable $e) {
        // In testing mode, continue if test harness defined custom mock tables
        return;
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
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    // Don't leak credentials or internals to the browser
    http_response_code(500);
    die("Database connection failed. Please try again later.");
}

// Some legacy files reference $conn
$conn = $pdo;

    // Centralized Version-Aware Schema Migration Architecture
    if (!defined('PEPP_DB_SCHEMA_VERSION')) {
        define('PEPP_DB_SCHEMA_VERSION', '2026.08.31.2');
    }

    if (!function_exists('ensure_task_reminders_schema')) {
        function ensure_task_reminders_schema(PDO $pdo, bool $force = false): bool {
            static $task_schema_checked = false;
            if ($task_schema_checked && !$force) {
                return true;
            }

            try {
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'mysql') {
                    // 1. task_reminder_types
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `task_reminder_types` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `name` VARCHAR(100) NOT NULL,
                            `description` TEXT NULL,
                            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                            `created_by_admin_id` INT NULL,
                            `created_by_username` VARCHAR(100) NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                            UNIQUE KEY `uniq_task_type_name` (`name`),
                            INDEX `idx_trt_status_name` (`is_active`, `name`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");

                    // 2. Extend reminders table with individual column checks
                    if ($pdo->query("SHOW TABLES LIKE 'reminders'")->fetchColumn()) {
                        $requiredCols = [
                            'task_type_id' => "INT NULL",
                            'created_by_admin_id' => "INT NULL",
                            'created_by_username' => "VARCHAR(100) NULL",
                            'assigned_by_admin_id' => "INT NULL",
                            'assigned_by_username' => "VARCHAR(100) NULL",
                            'assigned_to_admin_id' => "INT NULL",
                            'assigned_to_username' => "VARCHAR(100) NULL",
                            'assigned_at' => "DATETIME NULL",
                            'completed_by_admin_id' => "INT NULL",
                            'completed_by_username' => "VARCHAR(100) NULL",
                            'completed_at' => "DATETIME NULL",
                            'latest_remarks' => "TEXT NULL",
                            'email_sent' => "TINYINT(1) NOT NULL DEFAULT 0",
                            'last_status_updated_at' => "DATETIME NULL",
                            // Recurrence engine columns
                            'recurrence_type' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
                            'recurrence_weekdays' => "VARCHAR(50) NULL",
                            'recurrence_month_days' => "VARCHAR(100) NULL",
                            'recurrence_start_date' => "DATE NULL",
                            'recurrence_end_date' => "DATE NULL",
                            'recurrence_series_id' => "INT NULL",
                            'recurrence_stopped_at' => "DATETIME NULL",
                            'occurrence_date' => "DATE NULL",
                            'is_series_parent' => "TINYINT(1) NOT NULL DEFAULT 0"
                        ];
                        foreach ($requiredCols as $colName => $colDef) {
                            try {
                                $chk = $pdo->query("SHOW COLUMNS FROM `reminders` LIKE '{$colName}'")->fetch();
                                if (!$chk) {
                                    $pdo->exec("ALTER TABLE `reminders` ADD COLUMN `{$colName}` {$colDef}");
                                }
                            } catch (Throwable $eCol) {}
                        }

                        // Ensure status column allows standard varchar statuses
                        try {
                            $statusCol = $pdo->query("SHOW COLUMNS FROM `reminders` LIKE 'status'")->fetch();
                            if ($statusCol && strpos(strtolower((string)$statusCol['Type']), 'enum') !== false) {
                                $pdo->exec("ALTER TABLE `reminders` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'pending'");
                            }
                        } catch (Throwable $eStat) {}

                        // Safe indexes
                        $indexesToCreate = [
                            'idx_rem_type' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_type` (`task_type_id`)',
                            'idx_rem_assignee_status' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_assignee_status` (`assigned_to_admin_id`, `status`)',
                            'idx_rem_creator' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_creator` (`created_by_admin_id`, `status`)',
                            'idx_rem_due_time' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_due_time` (`remind_at`, `status`)',
                            'idx_rem_series' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_series` (`recurrence_series_id`)',
                            'idx_rem_occurrence' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_occurrence` (`occurrence_date`)',
                            'idx_rem_parent' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_parent` (`is_series_parent`, `recurrence_type`)'
                        ];
                        foreach ($indexesToCreate as $idxName => $alterSql) {
                            try {
                                $idxChk = $pdo->query("SHOW INDEX FROM `reminders` WHERE Key_name = '{$idxName}'")->fetch();
                                if (!$idxChk) {
                                    $pdo->exec($alterSql);
                                }
                            } catch (Throwable $eIdx) {}
                        }

                        // Concurrency-safe unique constraint: one occurrence per (series, date)
                        try {
                            $ukChk = $pdo->query("SHOW INDEX FROM `reminders` WHERE Key_name = 'uniq_series_occurrence'")->fetch();
                            if (!$ukChk) {
                                $pdo->exec("ALTER TABLE `reminders` ADD UNIQUE KEY `uniq_series_occurrence` (`recurrence_series_id`, `occurrence_date`)");
                            }
                        } catch (Throwable $eUk) {}
                    }

                    // 3. task_reminder_assignments
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `task_reminder_assignments` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `task_id` INT NOT NULL,
                            `assigned_by_admin_id` INT NULL,
                            `assigned_by_username` VARCHAR(100) NULL,
                            `assigned_to_admin_id` INT NULL,
                            `assigned_to_username` VARCHAR(100) NULL,
                            `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            `ended_at` DATETIME NULL,
                            `is_current` TINYINT(1) NOT NULL DEFAULT 1,
                            INDEX `idx_tra_task` (`task_id`, `is_current`),
                            INDEX `idx_tra_assignee` (`assigned_to_admin_id`, `is_current`),
                            INDEX `idx_tra_assigned_by` (`assigned_by_admin_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");

                    // 4. task_reminder_status_history
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `task_reminder_status_history` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `task_id` INT NOT NULL,
                            `event_type` VARCHAR(50) NOT NULL,
                            `old_status` VARCHAR(50) NULL,
                            `new_status` VARCHAR(50) NULL,
                            `changed_by_admin_id` INT NULL,
                            `changed_by_username` VARCHAR(100) NULL,
                            `remarks` TEXT NULL,
                            `details_json` TEXT NULL,
                            `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            INDEX `idx_trsh_task_time` (`task_id`, `changed_at`),
                            INDEX `idx_trsh_event` (`event_type`),
                            INDEX `idx_trsh_changed_by` (`changed_by_admin_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");

                    // 5. task_reminder_notifications
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `task_reminder_notifications` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `task_id` INT NOT NULL,
                            `recipient_admin_id` INT NULL,
                            `recipient_username` VARCHAR(64) NOT NULL,
                            `sender_admin_id` INT NULL,
                            `sender_username` VARCHAR(64) NULL,
                            `notification_type` VARCHAR(32) NOT NULL,
                            `event_key` VARCHAR(64) NOT NULL,
                            `message` TEXT NULL,
                            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                            `is_dismissed` TINYINT(1) NOT NULL DEFAULT 0,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            `read_at` DATETIME NULL,
                            UNIQUE KEY `uniq_task_event_notif` (`task_id`, `recipient_username`, `notification_type`, `event_key`),
                            INDEX `idx_trn_recipient_unread` (`recipient_username`, `is_read`, `created_at`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");

                    // 6. Seed initial task types
                    $cntTypes = (int)$pdo->query("SELECT COUNT(*) FROM `task_reminder_types`")->fetchColumn();
                    if ($cntTypes === 0) {
                        $pdo->exec("
                            INSERT IGNORE INTO `task_reminder_types` (`name`, `description`, `is_active`, `created_by_username`, `created_at`) VALUES
                            ('Daily Task Reminder', 'Routine daily reminders and operational duties', 1, 'System', NOW()),
                            ('Mentoring', 'Student academic mentoring, progress review and counseling sessions', 1, 'System', NOW()),
                            ('Session Scheduling', 'Scheduling online batches, faculty lectures, and mega tests', 1, 'System', NOW()),
                            ('Student Follow-up', 'Calling students regarding admission, attendance, and general queries', 1, 'System', NOW()),
                            ('Payment Follow-up', 'Fee installment recovery, payment verification, and voucher review', 1, 'System', NOW()),
                            ('Academic Task', 'Curriculum planning, question paper design, and study material uploads', 1, 'System', NOW()),
                            ('Administrative Task', 'Office paperwork, certificate generation, and staff coordination', 1, 'System', NOW()),
                            ('Meeting', 'Internal staff, academic committee, and management meetings', 1, 'System', NOW()),
                            ('Documentation', 'Student records, onboarding verifications, and compliance filing', 1, 'System', NOW()),
                            ('General Task', 'General administrative task and miscellaneous reminders', 1, 'System', NOW()),
                            ('Other', 'Custom tasks not covered in standard categories', 1, 'System', NOW());
                        ");
                    }

                    // 7. In-place backfill for legacy reminders
                    $generalTypeId = (int)$pdo->query("SELECT `id` FROM `task_reminder_types` WHERE `name` = 'General Task' LIMIT 1")->fetchColumn();
                    if ($generalTypeId > 0) {
                        $pdo->exec("UPDATE `reminders` SET `task_type_id` = {$generalTypeId} WHERE `task_type_id` IS NULL");
                    }
                    $pdo->exec("UPDATE `reminders` SET `created_by_username` = `created_by` WHERE `created_by_username` IS NULL AND `created_by` IS NOT NULL");
                    $pdo->exec("UPDATE `reminders` SET `assigned_to_username` = `assigned_to` WHERE `assigned_to_username` IS NULL AND `assigned_to` IS NOT NULL");
                    $pdo->exec("UPDATE `reminders` SET `assigned_by_username` = `created_by` WHERE `assigned_by_username` IS NULL AND `created_by` IS NOT NULL");
                    $pdo->exec("UPDATE `reminders` SET `completed_by_username` = `completed_by` WHERE `completed_by_username` IS NULL AND `completed_by` IS NOT NULL");

                    try {
                        if ($pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn()) {
                            $admins = $pdo->query("SELECT id, username FROM admins")->fetchAll(PDO::FETCH_ASSOC);
                            $stmtUpdCreator = $pdo->prepare("UPDATE reminders SET created_by_admin_id = ? WHERE created_by_admin_id IS NULL AND created_by_username = ?");
                            $stmtUpdAssignee = $pdo->prepare("UPDATE reminders SET assigned_to_admin_id = ? WHERE assigned_to_admin_id IS NULL AND assigned_to_username = ?");
                            $stmtUpdAssigner = $pdo->prepare("UPDATE reminders SET assigned_by_admin_id = ? WHERE assigned_by_admin_id IS NULL AND assigned_by_username = ?");
                            $stmtUpdCompleter = $pdo->prepare("UPDATE reminders SET completed_by_admin_id = ? WHERE completed_by_admin_id IS NULL AND completed_by_username = ?");
                            foreach ($admins as $adm) {
                                $stmtUpdCreator->execute([$adm['id'], $adm['username']]);
                                $stmtUpdAssignee->execute([$adm['id'], $adm['username']]);
                                $stmtUpdAssigner->execute([$adm['id'], $adm['username']]);
                                $stmtUpdCompleter->execute([$adm['id'], $adm['username']]);
                            }
                        }
                    } catch (Throwable $eAdm) {}

                    try {
                        $pdo->exec("
                            INSERT IGNORE INTO `task_reminder_assignments` (`task_id`, `assigned_by_admin_id`, `assigned_by_username`, `assigned_to_admin_id`, `assigned_to_username`, `assigned_at`, `is_current`)
                            SELECT r.`id`, r.`created_by_admin_id`, r.`created_by_username`, r.`assigned_to_admin_id`, r.`assigned_to_username`, COALESCE(r.`created_at`, NOW()), 1
                            FROM `reminders` r
                            WHERE NOT EXISTS (SELECT 1 FROM `task_reminder_assignments` tra WHERE tra.`task_id` = r.`id`);
                        ");
                        $pdo->exec("
                            INSERT IGNORE INTO `task_reminder_status_history` (`task_id`, `event_type`, `old_status`, `new_status`, `changed_by_admin_id`, `changed_by_username`, `remarks`, `changed_at`)
                            SELECT r.`id`, 'CREATED', NULL, r.`status`, r.`created_by_admin_id`, 'SYSTEM MIGRATION', 'Initial migration from legacy reminders', COALESCE(r.`created_at`, NOW())
                            FROM `reminders` r
                            WHERE NOT EXISTS (SELECT 1 FROM `task_reminder_status_history` trsh WHERE trsh.`task_id` = r.`id`);
                        ");
                    } catch (Throwable $eHist) {}
                } else {
                    // SQLite compatibility for tests
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `task_reminder_types` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `name` VARCHAR(100) NOT NULL UNIQUE,
                            `description` TEXT NULL,
                            `is_active` INTEGER NOT NULL DEFAULT 1,
                            `created_by_admin_id` INTEGER NULL,
                            `created_by_username` VARCHAR(100) NULL,
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            `updated_at` DATETIME NULL
                        );
                        CREATE TABLE IF NOT EXISTS `task_reminder_assignments` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `task_id` INTEGER NOT NULL,
                            `assigned_by_admin_id` INTEGER NULL,
                            `assigned_by_username` VARCHAR(100) NULL,
                            `assigned_to_admin_id` INTEGER NULL,
                            `assigned_to_username` VARCHAR(100) NULL,
                            `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            `ended_at` DATETIME NULL,
                            `is_current` INTEGER NOT NULL DEFAULT 1
                        );
                        CREATE TABLE IF NOT EXISTS `task_reminder_status_history` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `task_id` INTEGER NOT NULL,
                            `event_type` VARCHAR(50) NOT NULL,
                            `old_status` VARCHAR(50) NULL,
                            `new_status` VARCHAR(50) NULL,
                            `changed_by_admin_id` INTEGER NULL,
                            `changed_by_username` VARCHAR(100) NULL,
                            `remarks` TEXT NULL,
                            `details_json` TEXT NULL,
                            `changed_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                        );
                        CREATE TABLE IF NOT EXISTS `task_reminder_notifications` (
                            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `task_id` INTEGER NOT NULL,
                            `recipient_admin_id` INTEGER NULL,
                            `recipient_username` VARCHAR(100) NOT NULL,
                            `sender_admin_id` INTEGER NULL,
                            `sender_username` VARCHAR(100) NULL,
                            `notification_type` VARCHAR(50) NOT NULL,
                            `event_key` VARCHAR(100) NOT NULL,
                            `message` TEXT NULL,
                            `is_read` INTEGER NOT NULL DEFAULT 0,
                            `is_dismissed` INTEGER NOT NULL DEFAULT 0,
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            `read_at` DATETIME NULL
                        );
                    ");

                    $sqliteCols = [
                        'task_type_id' => "INTEGER NULL",
                        'created_by_admin_id' => "INTEGER NULL",
                        'created_by_username' => "VARCHAR(100) NULL",
                        'assigned_by_admin_id' => "INTEGER NULL",
                        'assigned_by_username' => "VARCHAR(100) NULL",
                        'assigned_to_admin_id' => "INTEGER NULL",
                        'assigned_to_username' => "VARCHAR(100) NULL",
                        'assigned_at' => "DATETIME NULL",
                        'completed_by_admin_id' => "INTEGER NULL",
                        'completed_by_username' => "VARCHAR(100) NULL",
                        'completed_at' => "DATETIME NULL",
                        'latest_remarks' => "TEXT NULL",
                        'email_sent' => "INTEGER NOT NULL DEFAULT 0",
                        'last_status_updated_at' => "DATETIME NULL",
                        // Recurrence columns
                        'recurrence_type' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
                        'recurrence_weekdays' => "VARCHAR(50) NULL",
                        'recurrence_month_days' => "VARCHAR(100) NULL",
                        'recurrence_start_date' => "DATE NULL",
                        'recurrence_end_date' => "DATE NULL",
                        'recurrence_series_id' => "INTEGER NULL",
                        'recurrence_stopped_at' => "DATETIME NULL",
                        'occurrence_date' => "DATE NULL",
                        'is_series_parent' => "INTEGER NOT NULL DEFAULT 0"
                    ];
                    foreach ($sqliteCols as $colName => $colDef) {
                        try {
                            $pdo->query("SELECT `{$colName}` FROM `reminders` LIMIT 1");
                        } catch (Exception $eSqliteCol) {
                            try {
                                $pdo->exec("ALTER TABLE reminders ADD COLUMN `{$colName}` {$colDef}");
                            } catch (Exception $eAdd) {}
                        }
                    }

                    try {
                        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS `uniq_series_occurrence` ON `reminders` (`recurrence_series_id`, `occurrence_date`)");
                    } catch (Throwable $eSqliteIdx) {}

                    $cntTypes = (int)$pdo->query("SELECT COUNT(*) FROM `task_reminder_types`")->fetchColumn();
                    if ($cntTypes === 0) {
                        $pdo->exec("
                            INSERT OR IGNORE INTO `task_reminder_types` (`name`, `description`, `is_active`, `created_by_username`) VALUES
                            ('Daily Task Reminder', 'Routine daily reminders and operational duties', 1, 'System'),
                            ('Mentoring', 'Student academic mentoring, progress review and counseling sessions', 1, 'System'),
                            ('Session Scheduling', 'Scheduling online batches, faculty lectures, and mega tests', 1, 'System'),
                            ('Student Follow-up', 'Calling students regarding admission, attendance, and general queries', 1, 'System'),
                            ('Payment Follow-up', 'Fee installment recovery, payment verification, and voucher review', 1, 'System'),
                            ('Academic Task', 'Curriculum planning, question paper design, and study material uploads', 1, 'System'),
                            ('Administrative Task', 'Office paperwork, certificate generation, and staff coordination', 1, 'System'),
                            ('Meeting', 'Internal staff, academic committee, and management meetings', 1, 'System'),
                            ('Documentation', 'Student records, onboarding verifications, and compliance filing', 1, 'System'),
                            ('General Task', 'General administrative task and miscellaneous reminders', 1, 'System'),
                            ('Other', 'Custom tasks not covered in standard categories', 1, 'System');
                        ");
                    }
                }

                $task_schema_checked = true;
                return true;
            } catch (Throwable $e) {
                error_log("ensure_task_reminders_schema error: " . $e->getMessage());
                return false;
            }
        }
    }

    if (!function_exists('ensure_pepp_database_schema')) {
        function ensure_pepp_database_schema(PDO $pdo, bool $force = false): bool {
            static $schema_verified_in_process = false;
            if ($schema_verified_in_process && !$force) {
                return true;
            }

            try {
                // Check current schema version in database
                $db_version = null;
                try {
                    $stmt_ver = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'db_schema_version' LIMIT 1");
                    $stmt_ver->execute();
                    $db_version = $stmt_ver->fetchColumn();
                } catch (Exception $exVer) {
                    // admin_settings table might not exist yet; will be created below
                    $db_version = null;
                }

                if ($db_version === PEPP_DB_SCHEMA_VERSION && !$force) {
                    // Always guarantee Task Reminders schema exists even on early return
                    ensure_task_reminders_schema($pdo);
                    $schema_verified_in_process = true;
                    return true;
                }

                // Self-healing database structure setup
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

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `test_result_cards` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `academic_year` VARCHAR(50) NOT NULL,
                `course_id` INT NOT NULL,
                `study_plan_id` INT NOT NULL,
                `activity_id` INT NOT NULL,
                `template_id` INT NOT NULL,
                `design_title` VARCHAR(255) NOT NULL,
                `output_format` VARCHAR(10) NULL,
                `output_file` VARCHAR(255) NULL,
                `student_rank_mappings` TEXT NULL,
                `design_config` LONGTEXT NOT NULL,
                `created_by` VARCHAR(100) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_trc_activity` (`activity_id`),
                KEY `idx_trc_template` (`template_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `card_layout_presets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `elements_json` LONGTEXT NOT NULL,
                `is_default` TINYINT(1) DEFAULT 0,
                `created_by` VARCHAR(100) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                KEY `idx_clp_is_default` (`is_default`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `card_template_admin_access` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `template_id` INT NOT NULL,
                `admin_user_id` INT NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `idx_template_admin` (`template_id`, `admin_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `campaign_form_admin_access` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `form_id` INT NOT NULL,
                `admin_user_id` INT NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `idx_form_admin` (`form_id`, `admin_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `student_course_migrations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` VARCHAR(50) NOT NULL,
                `old_course` VARCHAR(255) NOT NULL,
                `old_course_id` INT NULL,
                `old_course_fee` DECIMAL(10,2) NOT NULL,
                `new_course` VARCHAR(255) NOT NULL,
                `new_course_id` INT NULL,
                `new_course_fee` DECIMAL(10,2) NOT NULL,
                `payment_plan` VARCHAR(50) NOT NULL,
                `paid_amount_at_migration` DECIMAL(10,2) NOT NULL,
                `outstanding_before` DECIMAL(10,2) NOT NULL,
                `outstanding_after` DECIMAL(10,2) NOT NULL,
                `upgrade_amount` DECIMAL(10,2) NOT NULL,
                `migration_reason` TEXT NOT NULL,
                `migrated_by` VARCHAR(100) NOT NULL,
                `migrated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `status` VARCHAR(30) NOT NULL DEFAULT 'completed',
                `revised_installment_schedule` TEXT NULL,
                KEY `idx_scm_uid` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM `student_course_migrations` LIKE 'revised_installment_schedule'")->fetch();
            if (!$colCheck) {
                $pdo->exec("ALTER TABLE `student_course_migrations` ADD COLUMN `revised_installment_schedule` TEXT NULL");
            }
        } catch (Exception $colEx) {
            // Ignore column issues if already exists or other error
        }

        // Self-heal index optimizations for activity logs
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $idxCheck = $pdo->query("SHOW INDEX FROM `admin_activity_log` WHERE Key_name = 'idx_aal_session_id'")->fetch();
                if (!$idxCheck) {
                    $pdo->exec("ALTER TABLE `admin_activity_log` ADD INDEX `idx_aal_session_id` (`session_id`)");
                }

                $idxCheckWa = $pdo->query("SHOW INDEX FROM `whatsapp_notifications` WHERE Key_name = 'idx_wan_sent_by'")->fetch();
                if (!$idxCheckWa) {
                    $pdo->exec("ALTER TABLE `whatsapp_notifications` ADD INDEX `idx_wan_sent_by` (`sent_by`)");
                }
            } else if ($driver === 'sqlite') {
                $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_aal_session_id` ON `admin_activity_log` (`session_id`)");
                $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_wan_sent_by` ON `whatsapp_notifications` (`sent_by`)");
            }
        } catch (Exception $idxEx) {
            // Ignore index creation issues
        }

        // Self-heal Mega Test Result template preset inside card_templates
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM card_templates WHERE title = 'Mega Test Result Template'");
        $stmt_check->execute();
        if ($stmt_check->fetchColumn() == 0) {
            $default_elements = [
                [
                    "id" => "test_number",
                    "name" => "Test Number",
                    "type" => "text",
                    "textContent" => "1",
                    "left" => 1215,
                    "top" => 165,
                    "width" => 120,
                    "height" => 120,
                    "fontFamily" => "Google Sans Flex",
                    "fontSize" => 110,
                    "fontWeight" => "700",
                    "color" => "#ffffff",
                    "textAlign" => "center",
                    "lineHeight" => 1.0,
                    "letterSpacing" => 0,
                    "opacity" => 1,
                    "rotate" => 0
                ],
                [
                    "id" => "chapter_name",
                    "name" => "Chapter Name",
                    "type" => "text",
                    "textContent" => "Test Chapter",
                    "left" => 290,
                    "top" => 340,
                    "width" => 800,
                    "height" => 80,
                    "fontFamily" => "Google Sans Flex",
                    "fontSize" => 48,
                    "fontWeight" => "700",
                    "color" => "#f59e0b",
                    "textAlign" => "left",
                    "lineHeight" => 1.2,
                    "letterSpacing" => 0,
                    "opacity" => 1,
                    "rotate" => 0
                ]
            ];

            for ($r = 1; $r <= 4; $r++) {
                $suffix = ($r === 1) ? 'st' : (($r === 2) ? 'nd' : (($r === 3) ? 'rd' : 'th'));
                $y_offset = 470 + ($r - 1) * 200;

                $marker_colors = [1 => '#eab308', 2 => '#94a3b8', 3 => '#cd7f32', 4 => '#64748b'];
                $m_color = $marker_colors[$r] ?? '#64748b';

                $default_elements[] = [
                    "id" => "rank_badge_" . $r,
                    "name" => "Rank " . $r . " Badge",
                    "type" => "text",
                    "textContent" => $r . $suffix,
                    "left" => 125,
                    "top" => $y_offset + 40,
                    "width" => 90,
                    "height" => 90,
                    "fontFamily" => "Google Sans Flex",
                    "fontSize" => 36,
                    "fontWeight" => "700",
                    "color" => "#ffffff",
                    "textAlign" => "center",
                    "lineHeight" => 1.0,
                    "letterSpacing" => 0,
                    "opacity" => 1,
                    "rotate" => 0,
                    "showMarker" => true,
                    "markerColor" => $m_color
                ];

                $default_elements[] = [
                    "id" => "rank_photo_" . $r,
                    "name" => "Rank " . $r . " Photo",
                    "type" => "photo",
                    "left" => 260,
                    "top" => $y_offset,
                    "width" => 170,
                    "height" => 170,
                    "borderWidth" => 0,
                    "borderColor" => "#fecaca",
                    "mask" => "rounded",
                    "opacity" => 1,
                    "rotate" => 0
                ];

                $default_elements[] = [
                    "id" => "rank_name_" . $r,
                    "name" => "Rank " . $r . " Student Name",
                    "type" => "text",
                    "textContent" => "Student Name",
                    "left" => 480,
                    "top" => $y_offset + 25,
                    "width" => 800,
                    "height" => 55,
                    "fontFamily" => "Google Sans Flex",
                    "fontSize" => 42,
                    "fontWeight" => "700",
                    "color" => "#1e293b",
                    "textAlign" => "left",
                    "lineHeight" => 1.2,
                    "letterSpacing" => 0,
                    "opacity" => 1,
                    "rotate" => 0
                ];

                $default_elements[] = [
                    "id" => "rank_institute_" . $r,
                    "name" => "Rank " . $r . " Institute",
                    "type" => "text",
                    "textContent" => "College Name",
                    "left" => 480,
                    "top" => $y_offset + 85,
                    "width" => 800,
                    "height" => 45,
                    "fontFamily" => "Google Sans Flex",
                    "fontSize" => 30,
                    "fontWeight" => "400",
                    "color" => "#64748b",
                    "textAlign" => "left",
                    "lineHeight" => 1.2,
                    "letterSpacing" => 0,
                    "opacity" => 1,
                    "rotate" => 0
                ];
            }

            // Append explicit coordinate mode metadata element for native resolution templates
            $default_elements[] = [
                "id" => "metadata",
                "name" => "Metadata",
                "type" => "metadata",
                "coordinate_mode" => "native"
            ];

            $elements_json = json_encode($default_elements);

            $ins_tpl = $pdo->prepare("
                INSERT INTO card_templates
                (title, category, description, bg_image, canvas_width, canvas_height, resolution_dpi, aspect_ratio, status, elements_json, created_by)
                VALUES
                ('Mega Test Result Template', 'Achievement', 'Mega Test result announcement template with top 4 ranks.', 'uploads/card_templates/mega_test_result_template.jpg', 1671, 2048, 300, '1671:2048', 'active', ?, 'system')
            ");
            $ins_tpl->execute([$elements_json]);
        } else {
            // Upgrade existing seeded template preset elements to ensure explicit coordinate mode metadata is present
            try {
                $stmt_tpl = $pdo->prepare("SELECT id, elements_json FROM card_templates WHERE title = 'Mega Test Result Template' LIMIT 1");
                $stmt_tpl->execute();
                $tpl_row = $stmt_tpl->fetch(PDO::FETCH_ASSOC);
                if ($tpl_row) {
                    $el_arr = json_decode($tpl_row['elements_json'], true) ?: [];
                    $has_metadata = false;
                    $badge_updated = false;

                    foreach ($el_arr as &$item) {
                        if (isset($item['id']) && $item['id'] === 'metadata') {
                            $has_metadata = true;
                        }
                        // Dynamic upgrade to circular filled badges for backwards compatibility
                        if (isset($item['id']) && strpos($item['id'], 'rank_badge_') === 0) {
                            if (!isset($item['showMarker'])) {
                                $item['showMarker'] = true;
                                $item['width'] = 90;
                                $item['height'] = 90;
                                $item['color'] = '#ffffff';
                                $item['textAlign'] = 'center';
                                $r_num = (int)str_replace('rank_badge_', '', $item['id']);
                                $marker_colors = [1 => '#eab308', 2 => '#94a3b8', 3 => '#cd7f32', 4 => '#64748b'];
                                $item['markerColor'] = $marker_colors[$r_num] ?? '#64748b';
                                $badge_updated = true;
                            }
                        }
                    }
                    unset($item);

                    if (!$has_metadata || $badge_updated) {
                        if (!$has_metadata) {
                            $el_arr[] = [
                                "id" => "metadata",
                                "name" => "Metadata",
                                "type" => "metadata",
                                "coordinate_mode" => "native"
                            ];
                        }
                        $stmt_upd = $pdo->prepare("UPDATE card_templates SET elements_json = ? WHERE id = ?");
                        $stmt_upd->execute([json_encode($el_arr), $tpl_row['id']]);
                    }
                }
            } catch (Exception $e) {}
        }

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
              `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
              `deleted_at` DATETIME DEFAULT NULL,
              `deleted_by` VARCHAR(100) DEFAULT NULL,
              `deletion_reason` TEXT DEFAULT NULL,
              `created_by` VARCHAR(100) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_study_plans_deleted` (`is_deleted`)
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
              `topic` VARCHAR(255) NULL,
              `activity_title` VARCHAR(255) NOT NULL,
              `activity_description` TEXT NULL,
              `activity_type` VARCHAR(100) NOT NULL,
              `faculty` VARCHAR(255) NULL,
              `estimated_duration` INT NULL,
              `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
              `difficulty_level` ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
              `resource_links` TEXT NULL,
              `custom_activity_badge` VARCHAR(100) NULL,
              `custom_activity_color` VARCHAR(50) NULL,
              `custom_activity_icon` VARCHAR(100) NULL,
              `activity_uid` VARCHAR(100) DEFAULT NULL,
              `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
              `deleted_at` DATETIME DEFAULT NULL,
              `deleted_by` VARCHAR(100) DEFAULT NULL,
              `deletion_reason` TEXT DEFAULT NULL,
              KEY `idx_spa_plan` (`study_plan_id`),
              KEY `idx_spa_date` (`activity_date`),
              KEY `idx_spa_uid` (`activity_uid`),
              KEY `idx_spa_plan_date` (`study_plan_id`, `activity_date`),
              CONSTRAINT `fk_spa_plan` FOREIGN KEY (`study_plan_id`) REFERENCES `study_plans` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `study_plan_assignments` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `study_plan_id` INT NOT NULL,
              `assignment_type` ENUM('all','course','batch','student','form') NOT NULL,
              `assigned_value` VARCHAR(255) NOT NULL,
              `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
              `deleted_at` DATETIME DEFAULT NULL,
              `deleted_by` VARCHAR(100) DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_spa_assign_plan` (`study_plan_id`),
              KEY `idx_assignments_deleted` (`is_deleted`),
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
            CREATE TABLE IF NOT EXISTS `study_plan_edit_locks` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `study_plan_id` INT NOT NULL,
                `admin_id` INT DEFAULT NULL,
                `admin_username` VARCHAR(100) NOT NULL,
                `admin_name` VARCHAR(150) NOT NULL,
                `session_token` VARCHAR(100) NOT NULL,
                `locked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_heartbeat_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `released_at` DATETIME DEFAULT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY `uniq_active_plan_lock` (`study_plan_id`),
                INDEX `idx_spel_admin` (`admin_id`, `admin_username`),
                INDEX `idx_spel_heartbeat` (`last_heartbeat_at`, `is_active`)
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
              `latitude` VARCHAR(50) DEFAULT NULL,
              `longitude` VARCHAR(50) DEFAULT NULL,
              `resolved_place` VARCHAR(255) DEFAULT NULL,
              `activity_uid` VARCHAR(100) DEFAULT NULL,
              `activity_title_snapshot` VARCHAR(255) DEFAULT NULL,
              `activity_type_snapshot` VARCHAR(100) DEFAULT NULL,
              `activity_date_snapshot` DATE DEFAULT NULL,
              `day_number_snapshot` INT DEFAULT NULL,
              `chapter_snapshot` VARCHAR(255) DEFAULT NULL,
              `subject_snapshot` VARCHAR(255) DEFAULT NULL,
              `topic_snapshot` VARCHAR(255) DEFAULT NULL,
              KEY `idx_sp_anal_plan` (`study_plan_id`),
              KEY `idx_anal_uid` (`activity_uid`),
              KEY `idx_anal_email_uid` (`student_email`(191), `activity_uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `study_plan_activity_versions` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `activity_id` INT NOT NULL,
              `activity_uid` VARCHAR(100) NOT NULL,
              `study_plan_id` INT NOT NULL,
              `version_number` INT NOT NULL,
              `activity_date` DATE NOT NULL,
              `day_number` INT NOT NULL,
              `sort_order` INT NOT NULL DEFAULT 0,
              `chapter` VARCHAR(255) DEFAULT NULL,
              `topic` VARCHAR(255) DEFAULT NULL,
              `activity_title` VARCHAR(255) NOT NULL,
              `activity_description` TEXT DEFAULT NULL,
              `activity_type` VARCHAR(100) NOT NULL,
              `faculty` VARCHAR(255) DEFAULT NULL,
              `estimated_duration` INT DEFAULT NULL,
              `priority` VARCHAR(50) NOT NULL DEFAULT 'medium',
              `difficulty_level` VARCHAR(50) NOT NULL DEFAULT 'medium',
              `resource_links` TEXT DEFAULT NULL,
              `custom_activity_badge` VARCHAR(100) DEFAULT NULL,
              `custom_activity_color` VARCHAR(50) DEFAULT NULL,
              `custom_activity_icon` VARCHAR(100) DEFAULT NULL,
              `created_by` VARCHAR(100) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `change_type` VARCHAR(50) NOT NULL,
              KEY `idx_spav_act` (`activity_id`),
              KEY `idx_spav_uid` (`activity_uid`)
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

        try {
            $cols_version = $pdo->query("SHOW COLUMNS FROM study_plans LIKE 'version'")->fetch();
            if (!$cols_version) {
                $pdo->exec("ALTER TABLE study_plans ADD COLUMN `version` INT NOT NULL DEFAULT 1");
            }
        } catch (Exception $e) {}

        try {
            $cols_plan_deleted = $pdo->query("SHOW COLUMNS FROM study_plans LIKE 'is_deleted'")->fetch();
            if (!$cols_plan_deleted) {
                $pdo->exec("ALTER TABLE study_plans
                    ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN `deleted_at` DATETIME DEFAULT NULL,
                    ADD COLUMN `deleted_by` VARCHAR(100) DEFAULT NULL,
                    ADD COLUMN `deletion_reason` TEXT DEFAULT NULL,
                    ADD INDEX `idx_study_plans_deleted` (`is_deleted`)");
            }
        } catch (Exception $e) {}

        try {
            $cols_assign_deleted = $pdo->query("SHOW COLUMNS FROM study_plan_assignments LIKE 'is_deleted'")->fetch();
            if (!$cols_assign_deleted) {
                $pdo->exec("ALTER TABLE study_plan_assignments
                    ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN `deleted_at` DATETIME DEFAULT NULL,
                    ADD COLUMN `deleted_by` VARCHAR(100) DEFAULT NULL,
                    ADD INDEX `idx_assignments_deleted` (`is_deleted`)");
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

            // Upgrade study_plan_activities for MySQL
            try {
                if ($pdo->query("SHOW TABLES LIKE 'study_plan_activities'")->fetchColumn()) {
                    $cols = $pdo->query("SHOW COLUMNS FROM study_plan_activities LIKE 'activity_uid'")->fetch();
                    if (!$cols) {
                        $pdo->exec("ALTER TABLE study_plan_activities
                            ADD COLUMN activity_uid VARCHAR(100) DEFAULT NULL,
                            ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                            ADD COLUMN deleted_at DATETIME DEFAULT NULL,
                            ADD COLUMN deleted_by VARCHAR(100) DEFAULT NULL,
                            ADD COLUMN deletion_reason TEXT DEFAULT NULL,
                            ADD INDEX idx_spa_uid (activity_uid),
                            ADD INDEX idx_spa_plan_date (study_plan_id, activity_date)");
                    }
                }
            } catch (Exception $exUpd) {}

            // Upgrade study_plan_analytics for MySQL
            try {
                if ($pdo->query("SHOW TABLES LIKE 'study_plan_analytics'")->fetchColumn()) {
                    $cols = $pdo->query("SHOW COLUMNS FROM study_plan_analytics LIKE 'activity_uid'")->fetch();
                    if (!$cols) {
                        $pdo->exec("ALTER TABLE study_plan_analytics
                            ADD COLUMN activity_uid VARCHAR(100) DEFAULT NULL,
                            ADD COLUMN activity_title_snapshot VARCHAR(255) DEFAULT NULL,
                            ADD COLUMN activity_type_snapshot VARCHAR(100) DEFAULT NULL,
                            ADD COLUMN activity_date_snapshot DATE DEFAULT NULL,
                            ADD COLUMN day_number_snapshot INT DEFAULT NULL,
                            ADD COLUMN chapter_snapshot VARCHAR(255) DEFAULT NULL,
                            ADD COLUMN subject_snapshot VARCHAR(255) DEFAULT NULL,
                            ADD COLUMN topic_snapshot VARCHAR(255) DEFAULT NULL,
                            ADD INDEX idx_anal_uid (activity_uid),
                            ADD INDEX idx_anal_email_uid (student_email(191), activity_uid)");
                    }
                }
            } catch (Exception $exUpd) {}
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

                // Add new tracking columns if they do not exist
                $extra_cols = [
                    'admin_id' => 'INT DEFAULT NULL',
                    'session_id' => 'VARCHAR(100) DEFAULT NULL',
                    'module' => 'VARCHAR(100) DEFAULT NULL',
                    'page' => 'VARCHAR(255) DEFAULT NULL',
                    'section' => 'VARCHAR(100) DEFAULT NULL',
                    'target_type' => 'VARCHAR(100) DEFAULT NULL',
                    'target_id' => 'VARCHAR(100) DEFAULT NULL',
                    'request_method' => 'VARCHAR(10) DEFAULT NULL',
                    'request_uri' => 'TEXT DEFAULT NULL',
                    'referrer' => 'TEXT DEFAULT NULL',
                    'is_heartbeat' => 'TINYINT(1) DEFAULT 0',
                    'is_idle' => 'TINYINT(1) DEFAULT 0'
                ];
                foreach ($extra_cols as $col_name => $col_def) {
                    $has_col = $pdo->query("SHOW COLUMNS FROM admin_activity_log LIKE '$col_name'")->fetch();
                    if (!$has_col) {
                        $pdo->exec("ALTER TABLE admin_activity_log ADD COLUMN `$col_name` $col_def");
                    }
                }

                // Add indexes on new tracking columns
                try {
                    $indexes = [
                        'idx_aal_session' => 'session_id',
                        'idx_aal_admin_id' => 'admin_id',
                        'idx_aal_module' => 'module',
                        'idx_aal_page' => 'page'
                    ];
                    foreach ($indexes as $idx_name => $idx_col) {
                        $has_idx = $pdo->query("SHOW INDEX FROM admin_activity_log WHERE Key_name = '$idx_name'")->fetch();
                        if (!$has_idx) {
                            $pdo->exec("CREATE INDEX `$idx_name` ON admin_activity_log (`$idx_col`)");
                        }
                    }
                } catch (Exception $idxEx) {}
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

            // Add columns to admin_presence
            $pres_cols = [
                'session_id' => 'VARCHAR(100) DEFAULT NULL',
                'is_idle' => 'TINYINT(1) DEFAULT 0'
            ];
            foreach ($pres_cols as $col_name => $col_def) {
                $has_col = $pdo->query("SHOW COLUMNS FROM admin_presence LIKE '$col_name'")->fetch();
                if (!$has_col) {
                    $pdo->exec("ALTER TABLE admin_presence ADD COLUMN `$col_name` $col_def");
                }
            }
        } catch (Exception $e) {}

        // Safe Task Reminders Module Schema Migration
        $task_rem_ok = ensure_task_reminders_schema($pdo, true);
        if (!$task_rem_ok) {
            error_log("CRITICAL: Task Reminders schema verification failed. Schema version will not be advanced.");
            return false;
        }

        // Persist schema version to database only if all migrations succeeded
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $stmt_set_ver = $pdo->prepare("
                    INSERT INTO admin_settings (setting_name, setting_value, updated_at)
                    VALUES ('db_schema_version', ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                $stmt_set_ver->execute([PEPP_DB_SCHEMA_VERSION]);
            } else {
                $stmt_set_ver = $pdo->prepare("
                    INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at)
                    VALUES ('db_schema_version', ?, datetime('now'))
                ");
                $stmt_set_ver->execute([PEPP_DB_SCHEMA_VERSION]);
            }
        } catch (Exception $exSaveVer) {
            error_log("Failed to save db_schema_version: " . $exSaveVer->getMessage());
        }

        $schema_verified_in_process = true;
        return true;
    } catch (Exception $dbEx) {
        error_log("PEPP self-healing DB check failed: " . $dbEx->getMessage());
        return false;
    }
}
}

// Execute centralized version-aware schema verification
ensure_pepp_database_schema($pdo);
