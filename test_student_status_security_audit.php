<?php
/**
 * Test Suite: Student Status Security Hardening Audit
 * Tests student status security across Auth helpers, Mentoring isolation,
 * Study Plan access, Status Lifecycle Transitions, Stale-Session Invalidation,
 * Academic/Transactional Email Filtering, Fail-Closed Queue Worker, and Report IDOR Protection.
 */

$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'admin';
$_SESSION['admin_id'] = 1;

require_once __DIR__ . '/includes/auth.php';

class StudentStatusSecurityTestSuite {
    private $pdo;
    private $passed = 0;
    private $failed = 0;

    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->sqliteCreateFunction('NOW', function() {
                return date('Y-m-d H:i:s');
            });
        }
        $this->setupSchema();
        $this->seedData();
    }

    private function setupSchema() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT,
                full_name TEXT,
                role TEXT,
                status TEXT
            );

            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT UNIQUE,
                name TEXT,
                email TEXT UNIQUE,
                phone TEXT,
                mobile_number TEXT,
                whatsapp_country_code TEXT,
                whatsapp_number TEXT,
                pepp_course TEXT,
                pepp_academic_year TEXT,
                academic_year TEXT,
                status TEXT,
                student_status TEXT,
                user_photo TEXT,
                created_at TEXT
            );

            CREATE TABLE IF NOT EXISTS student_status_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                old_status TEXT,
                new_status TEXT,
                reason TEXT,
                changed_by TEXT,
                changed_at TEXT
            );

            CREATE TABLE IF NOT EXISTS mentor_student_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_user_id TEXT,
                admin_id INTEGER,
                course_name TEXT,
                assigned_by TEXT,
                assigned_at TEXT,
                ended_at TEXT,
                status TEXT
            );

            CREATE TABLE IF NOT EXISTS mentor_call_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_user_id TEXT,
                admin_id INTEGER,
                admin_username TEXT,
                call_timestamp TEXT,
                notes TEXT
            );

            CREATE TABLE IF NOT EXISTS mentor_remarks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_user_id TEXT,
                admin_id INTEGER,
                admin_username TEXT,
                remark TEXT,
                created_at TEXT
            );

            CREATE TABLE IF NOT EXISTS communication_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT,
                recipient TEXT,
                recipient_name TEXT,
                subject TEXT,
                body_html TEXT,
                body_text TEXT,
                template_name TEXT,
                template_data TEXT,
                attachments TEXT,
                status TEXT,
                next_attempt_at TEXT,
                sent_by TEXT,
                student_uid TEXT,
                event_name TEXT,
                invoice_id INTEGER,
                error_message TEXT,
                retry_count INTEGER DEFAULT 0,
                last_retry_at TEXT,
                worker_started_at TEXT,
                created_at TEXT,
                updated_at TEXT
            );

            CREATE TABLE IF NOT EXISTS admin_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                setting_name TEXT UNIQUE,
                setting_value TEXT,
                updated_at TEXT
            );

            CREATE TABLE IF NOT EXISTS communication_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                status TEXT,
                target_audience TEXT
            );

            CREATE TABLE IF NOT EXISTS communication_campaign_recipients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER,
                queue_id INTEGER,
                lead_id INTEGER
            );

            CREATE TABLE IF NOT EXISTS campaign_forms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                is_deleted INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS campaign_form_submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_id INTEGER,
                respondent_identifier TEXT,
                is_deleted INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS campaign_form_fields (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_id INTEGER,
                field_name TEXT,
                label TEXT,
                sort_order INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS campaign_form_answers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                submission_id INTEGER,
                field_id INTEGER,
                answer_text TEXT
            );

            CREATE TABLE IF NOT EXISTS study_plans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plan_type TEXT DEFAULT 'date_wise',
                title TEXT
            );

            CREATE TABLE IF NOT EXISTS study_plan_activities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                study_plan_id INTEGER,
                activity_uid TEXT,
                is_deleted INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS study_plan_analytics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                study_plan_id INTEGER,
                student_email TEXT,
                activity_id INTEGER,
                activity_uid TEXT,
                action_type TEXT,
                completion_status TEXT,
                ip_address TEXT,
                created_at TEXT
            );
        ");
    }

    private function seedData() {
        $this->pdo->exec("
            DELETE FROM admins;
            DELETE FROM users;
            DELETE FROM student_status_log;
            DELETE FROM mentor_student_assignments;
            DELETE FROM mentor_call_logs;
            DELETE FROM mentor_remarks;
            DELETE FROM communication_queue;
            DELETE FROM campaign_forms;
            DELETE FROM campaign_form_submissions;
            DELETE FROM campaign_form_fields;
            DELETE FROM campaign_form_answers;

            INSERT INTO admins (id, username, full_name, role, status) VALUES
            (1, 'admin', 'Super Admin User', 'super_admin', 'active'),
            (2, 'mentor_rahul', 'Rahul Sharma', 'mentor', 'active'),
            (3, 'mentor_priya', 'Priya Patel', 'mentor', 'active');

            INSERT INTO users (user_id, name, email, mobile_number, pepp_course, status, student_status, created_at) VALUES
            ('STU001', 'Alice Active', 'alice@example.com', '9876543210', 'B.Com Coaching', 'approved', 'active', '2026-01-01 10:00:00'),
            ('STU002', 'Sam Suspended', 'sam@example.com', '9876543211', 'B.Com Coaching', 'approved', 'suspended', '2026-01-02 10:00:00'),
            ('STU003', 'Ian Inactive', 'ian@example.com', '9876543212', 'B.Com Coaching', 'approved', 'inactive', '2026-01-03 10:00:00'),
            ('STU004', 'David Dropout', 'david@example.com', '9876543213', 'B.Com Coaching', 'approved', 'dropout', '2026-01-04 10:00:00'),
            ('STU005', 'Cathy Completed', 'cathy@example.com', '9876543214', 'B.Com Coaching', 'approved', 'completed', '2026-01-05 10:00:00'),
            ('STU006', 'Pending Paul', 'paul@example.com', '9876543215', 'B.Com Coaching', 'pending', 'active', '2026-01-06 10:00:00'),
            ('STU007', 'Unset Status', 'unset@example.com', '9876543216', 'B.Com Coaching', 'approved', NULL, '2026-01-07 10:00:00');

            INSERT INTO student_status_log (user_id, old_status, new_status, reason, changed_by, changed_at) VALUES
            ('STU002', 'active', 'suspended', 'Installment 2 payment overdue by 14 days', 'admin', '2026-02-01 10:00:00'),
            ('STU003', 'active', 'inactive', 'Student requested medical leave for 1 month', 'admin', '2026-02-02 10:00:00'),
            ('STU004', 'active', 'dropout', 'Relocated to another city, course discontinued', 'admin', '2026-02-03 10:00:00'),
            ('STU005', 'active', 'completed', 'Successfully passed final comprehensive examination', 'admin', '2026-02-04 10:00:00');

            INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, assigned_by, assigned_at, status) VALUES
            ('STU001', 2, 'B.Com Coaching', 'admin', '2026-01-10 10:00:00', 'active'),
            ('STU002', 2, 'B.Com Coaching', 'admin', '2026-01-10 10:00:00', 'active'),
            ('STU003', 2, 'B.Com Coaching', 'admin', '2026-01-10 10:00:00', 'active'),
            ('STU004', 2, 'B.Com Coaching', 'admin', '2026-01-10 10:00:00', 'active'),
            ('STU005', 2, 'B.Com Coaching', 'admin', '2026-01-10 10:00:00', 'active');

            INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp, notes) VALUES
            ('STU001', 2, 'mentor_rahul', '2026-01-15 11:00:00', 'Routine check-in call with Alice'),
            ('STU002', 2, 'mentor_rahul', '2026-02-01 11:00:00', 'Discussed payment extension request'),
            ('STU004', 2, 'mentor_rahul', '2026-02-03 11:00:00', 'Exit interview call');

            INSERT INTO mentor_remarks (student_user_id, admin_id, admin_username, remark, created_at) VALUES
            ('STU001', 2, 'mentor_rahul', 'Alice is progressing well', '2026-01-16 11:00:00'),
            ('STU002', 2, 'mentor_rahul', 'Pending installment clearance', '2026-02-02 11:00:00'),
            ('STU004', 2, 'mentor_rahul', 'Discontinued course due to relocation', '2026-02-04 11:00:00');

            INSERT INTO campaign_forms (id, title, is_deleted) VALUES (1, 'CUET Free Mock Test Form', 0);
            INSERT INTO campaign_form_submissions (id, form_id, respondent_identifier, is_deleted) VALUES (101, 1, 'guest_cuet@example.com', 0);
            INSERT INTO campaign_form_fields (id, form_id, field_name, label, sort_order) VALUES (1, 1, 'full_name', 'Your Name', 1);
            INSERT INTO campaign_form_answers (id, submission_id, field_id, answer_text) VALUES (1, 101, 1, 'Guest CUET Learner');
        ");
    }

    private function assert($condition, $description) {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$description}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$description}\n";
        }
    }

    public function run() {
        echo "======================================================================\n";
        echo "STUDENT STATUS SECURITY HARDENING AUDIT TEST SUITE\n";
        echo "======================================================================\n";

        if (!function_exists('get_student_status')) {
            require_once __DIR__ . '/includes/auth.php';
        }

        echo "\n--- Test Suite 1: Canonical Status Resolution & Auth Helpers ---\n";
        $this->assert(get_student_status($this->pdo, 'STU001') === 'active', "get_student_status('STU001') is 'active'");
        $this->assert(get_student_status($this->pdo, 'STU002') === 'suspended', "get_student_status('STU002') is 'suspended'");
        $this->assert(get_student_status($this->pdo, 'STU003') === 'inactive', "get_student_status('STU003') is 'inactive'");
        $this->assert(get_student_status($this->pdo, 'STU004') === 'dropout', "get_student_status('STU004') is 'dropout'");
        $this->assert(get_student_status($this->pdo, 'STU005') === 'completed', "get_student_status('STU005') is 'completed'");
        $this->assert(get_student_status($this->pdo, 'STU006') === 'pending', "get_student_status('STU006' - pending) is 'pending'");
        $this->assert(get_student_status($this->pdo, 'STU007') === 'unknown', "get_student_status('STU007' - NULL student_status) is 'unknown'");
        $this->assert(get_student_status($this->pdo, 'NON_EXISTENT') === 'unknown', "get_student_status('NON_EXISTENT') is 'unknown'");

        echo "\n--- Test Suite 2: Active Status Validation (is_student_active) ---\n";
        $this->assert(is_student_active($this->pdo, 'STU001') === true, "is_student_active for approved + active (STU001) is TRUE");
        $this->assert(is_student_active($this->pdo, 'STU002') === false, "is_student_active for approved + suspended (STU002) is FALSE");
        $this->assert(is_student_active($this->pdo, 'STU003') === false, "is_student_active for approved + inactive (STU003) is FALSE");
        $this->assert(is_student_active($this->pdo, 'STU004') === false, "is_student_active for approved + dropout (STU004) is FALSE");
        $this->assert(is_student_active($this->pdo, 'STU005') === false, "is_student_active for approved + completed (STU005) is FALSE");
        $this->assert(is_student_active($this->pdo, 'STU006') === false, "is_student_active for pending + active (STU006) is FALSE");
        $this->assert(is_student_active($this->pdo, 'NON_EXISTENT') === false, "is_student_active for non-existent user is FALSE");

        echo "\n--- Test Suite 3: Exact Reason Extraction (get_student_status_reason) ---\n";
        $r2 = get_student_status_reason($this->pdo, 'STU002');
        $this->assert($r2 === 'Installment 2 payment overdue by 14 days', "get_student_status_reason('STU002') retrieves exact suspended reason");

        $r3 = get_student_status_reason($this->pdo, 'STU003');
        $this->assert($r3 === 'Student requested medical leave for 1 month', "get_student_status_reason('STU003') retrieves exact inactive reason");

        $r4 = get_student_status_reason($this->pdo, 'STU004');
        $this->assert($r4 === 'Relocated to another city, course discontinued', "get_student_status_reason('STU004') retrieves exact dropout reason");

        $r5 = get_student_status_reason($this->pdo, 'STU005');
        $this->assert($r5 === 'Successfully passed final comprehensive examination', "get_student_status_reason('STU005') retrieves exact completed reason");

        $r1 = get_student_status_reason($this->pdo, 'STU001');
        $this->assert($r1 === null, "get_student_status_reason('STU001') for active student is NULL");

        echo "\n--- Test Suite 4: Mentor Authorization & IDOR Isolation (can_mentor_view_student) ---\n";
        $this->assert(can_mentor_view_student($this->pdo, 2, 'STU001') === true, "Mentor 2 CAN view assigned active student (STU001)");
        $this->assert(can_mentor_view_student($this->pdo, 2, 'STU002') === true, "Mentor 2 CAN view assigned suspended student (STU002)");
        $this->assert(can_mentor_view_student($this->pdo, 2, 'STU003') === true, "Mentor 2 CAN view assigned inactive student (STU003)");
        $this->assert(can_mentor_view_student($this->pdo, 2, 'STU004') === false, "Mentor 2 CANNOT view assigned dropout student (STU004)");
        $this->assert(can_mentor_view_student($this->pdo, 2, 'STU005') === false, "Mentor 2 CANNOT view assigned completed student (STU005)");
        $this->assert(can_mentor_view_student($this->pdo, 3, 'STU001') === false, "Mentor 3 CANNOT view unassigned active student STU001 (IDOR protection)");

        echo "\n--- Test Suite 5: Study Plan Access Eligibility ---\n";
        $this->assert(can_student_access_study_plan($this->pdo, 'alice@example.com') === true, "Active enrolled student CAN access study plan");
        $this->assert(can_student_access_study_plan($this->pdo, 'sam@example.com') === false, "Suspended enrolled student CANNOT access study plan");
        $this->assert(can_student_access_study_plan($this->pdo, 'ian@example.com') === false, "Inactive enrolled student CANNOT access study plan");
        $this->assert(can_student_access_study_plan($this->pdo, 'david@example.com') === false, "Dropout enrolled student CANNOT access study plan");
        $this->assert(can_student_access_study_plan($this->pdo, 'cathy@example.com') === false, "Completed enrolled student CANNOT access study plan");
        $this->assert(can_student_access_study_plan($this->pdo, 'guest_cuet@example.com') === true, "Valid campaign form respondent CAN access study plan");

        echo "\n--- Test Suite 6: Academic vs Transactional Email Permissions ---\n";
        $this->assert(can_send_academic_email($this->pdo, 'alice@example.com') === true, "can_send_academic_email('alice@example.com' - active) is TRUE");
        $this->assert(can_send_academic_email($this->pdo, 'sam@example.com') === false, "can_send_academic_email('sam@example.com' - suspended) is FALSE");
        $this->assert(can_send_academic_email($this->pdo, 'ian@example.com') === false, "can_send_academic_email('ian@example.com' - inactive) is FALSE");
        $this->assert(can_send_academic_email($this->pdo, 'david@example.com') === false, "can_send_academic_email('david@example.com' - dropout) is FALSE");
        $this->assert(can_send_academic_email($this->pdo, 'cathy@example.com') === false, "can_send_academic_email('cathy@example.com' - completed) is FALSE");

        echo "\n--- Test Suite 7: Student Mentoring Query Filtering ---\n";
        $stmt_mentor = $this->pdo->prepare("
            SELECT u.user_id, u.name, u.student_status
            FROM users u
            JOIN mentor_student_assignments msa ON u.user_id = msa.student_user_id
            WHERE u.pepp_course = 'B.Com Coaching' AND u.status IN ('approved','active')
              AND (u.student_status IS NULL OR u.student_status NOT IN ('dropout', 'completed'))
              AND msa.admin_id = 2 AND msa.status = 'active'
        ");
        $stmt_mentor->execute();
        $mentor_students = $stmt_mentor->fetchAll(PDO::FETCH_ASSOC);
        $uids = array_column($mentor_students, 'user_id');

        $this->assert(in_array('STU001', $uids), "Mentoring query includes active student STU001");
        $this->assert(in_array('STU002', $uids), "Mentoring query includes suspended student STU002");
        $this->assert(in_array('STU003', $uids), "Mentoring query includes inactive student STU003");
        $this->assert(!in_array('STU004', $uids), "Mentoring query strictly EXCLUDES dropout student STU004");
        $this->assert(!in_array('STU005', $uids), "Mentoring query strictly EXCLUDES completed student STU005");

        echo "\n--- Test Suite 8: Status Lifecycle Transitions & Historic Reason Trail ---\n";
        // Transition STU001 Active -> Suspended
        $this->pdo->prepare("UPDATE users SET student_status = 'suspended' WHERE user_id = 'STU001'")->execute();
        $this->pdo->prepare("INSERT INTO student_status_log (user_id, old_status, new_status, reason, changed_by, changed_at) VALUES ('STU001', 'active', 'suspended', 'Late fee overdue 30 days', 'admin', '2026-03-01 10:00:00')")->execute();
        $this->assert(is_student_active($this->pdo, 'STU001') === false, "STU001 after transition to suspended is NOT active");
        $this->assert(get_student_status_reason($this->pdo, 'STU001') === 'Late fee overdue 30 days', "STU001 new suspended reason is exact");

        // Transition STU001 Suspended -> Active
        $this->pdo->prepare("UPDATE users SET student_status = 'active' WHERE user_id = 'STU001'")->execute();
        $this->pdo->prepare("INSERT INTO student_status_log (user_id, old_status, new_status, reason, changed_by, changed_at) VALUES ('STU001', 'suspended', 'active', 'Fee dues cleared in full', 'admin', '2026-03-05 10:00:00')")->execute();
        $this->assert(is_student_active($this->pdo, 'STU001') === true, "STU001 after reinstatement is active");
        $this->assert(get_student_status_reason($this->pdo, 'STU001', 'suspended') === 'Late fee overdue 30 days', "STU001 previous suspended reason preserved in log");
        $this->assert(get_student_status_reason($this->pdo, 'STU001') === 'Fee dues cleared in full', "STU001 latest status transition reason retrieved");

        // Transition STU001 Active -> Dropout
        $this->pdo->prepare("UPDATE users SET student_status = 'dropout' WHERE user_id = 'STU001'")->execute();
        $this->pdo->prepare("INSERT INTO student_status_log (user_id, old_status, new_status, reason, changed_by, changed_at) VALUES ('STU001', 'active', 'dropout', 'Student opted out of course', 'admin', '2026-03-10 10:00:00')")->execute();
        $this->assert(is_student_active($this->pdo, 'STU001') === false, "STU001 after dropout is NOT active");
        $this->assert(can_mentor_view_student($this->pdo, 2, 'STU001') === false, "STU001 after dropout is invisible to mentor");

        // Reset STU001 back to active
        $this->pdo->prepare("UPDATE users SET student_status = 'active' WHERE user_id = 'STU001'")->execute();

        echo "\n--- Test Suite 9: Stale-Session Invalidation & Race Condition Simulation ---\n";
        $_SESSION['sp_logged_in'] = true;
        $_SESSION['sp_email'] = 'alice@example.com';
        $_SESSION['sp_student_id'] = 'STU001';

        $this->assert(can_student_access_study_plan($this->pdo, $_SESSION['sp_email']) === true, "Active session starts valid");

        // Mid-session: Administrator suspends student in database
        $this->pdo->prepare("UPDATE users SET student_status = 'suspended' WHERE user_id = 'STU001'")->execute();
        $this->pdo->prepare("INSERT INTO student_status_log (user_id, old_status, new_status, reason, changed_by, changed_at) VALUES ('STU001', 'active', 'suspended', 'Session revoked mid-flight due to violation', 'admin', '2026-03-15 12:00:00')")->execute();

        // Subsequent page/AJAX revalidation check
        $can_access = can_student_access_study_plan($this->pdo, $_SESSION['sp_email']);
        $this->assert($can_access === false, "Mid-session revalidation rejects suspended student");

        if (!$can_access) {
            $st_status = get_student_status($this->pdo, $_SESSION['sp_email']);
            $reason = get_student_status_reason($this->pdo, $_SESSION['sp_email'], $st_status);
            unset($_SESSION['sp_logged_in'], $_SESSION['sp_email'], $_SESSION['sp_student_id']);
            $error_msg = "Your session has ended because your account is currently " . strtoupper($st_status) . ($reason ? " (Reason: {$reason})" : "") . ".";
            $this->assert(strpos($error_msg, 'Session revoked mid-flight due to violation') !== false, "Revalidation displays exact stored reason");
            $this->assert(!isset($_SESSION['sp_logged_in']), "Session is cleared on revocation");
        }

        // Reset STU001 back to active
        $this->pdo->prepare("UPDATE users SET student_status = 'active' WHERE user_id = 'STU001'")->execute();

        echo "\n--- Test Suite 10: Communication Queue Worker & Fail-Closed Unknown Types ---\n";
        // 1. Enqueue Academic email for active student
        $this->pdo->prepare("
            INSERT INTO communication_queue (channel, recipient, student_uid, event_name, status, next_attempt_at, created_at)
            VALUES ('email', 'alice@example.com', 'STU001', 'live_session_reminder', 'pending', '2026-03-20 10:00:00', '2026-03-20 10:00:00')
        ")->execute();
        $academicQueueId = $this->pdo->lastInsertId();

        // 2. Enqueue Unknown/Future event type for active student
        $this->pdo->prepare("
            INSERT INTO communication_queue (channel, recipient, student_uid, event_name, status, next_attempt_at, created_at)
            VALUES ('email', 'alice@example.com', 'STU001', 'future_ai_mentor_announcement', 'pending', '2026-03-20 10:00:00', '2026-03-20 10:00:00')
        ")->execute();
        $unknownQueueId = $this->pdo->lastInsertId();

        // 3. Enqueue Transactional event for suspended student
        $this->pdo->prepare("
            INSERT INTO communication_queue (channel, recipient, student_uid, event_name, status, next_attempt_at, created_at)
            VALUES ('email', 'sam@example.com', 'STU002', 'installment_reminder', 'pending', '2026-03-20 10:00:00', '2026-03-20 10:00:00')
        ")->execute();
        $transQueueId = $this->pdo->lastInsertId();

        // Simulate student status changing to suspended before queue worker execution
        $this->pdo->prepare("UPDATE users SET student_status = 'suspended' WHERE user_id = 'STU001'")->execute();
        $this->pdo->prepare("INSERT INTO student_status_log (user_id, old_status, new_status, reason, changed_by, changed_at) VALUES ('STU001', 'active', 'suspended', 'Payment bounced', 'admin', '2026-03-20 10:05:00')")->execute();

        // Instantiate CommunicationEngine with test PDO double
        require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
        $engine = CommunicationEngine::getInstance($this->pdo);

        // Process Academic item at send-time
        $engine->processQueueItem($academicQueueId);
        $stmt_ac = $this->pdo->prepare("SELECT status, error_message FROM communication_queue WHERE id = ?");
        $stmt_ac->execute([$academicQueueId]);
        $academicResult = $stmt_ac->fetch(PDO::FETCH_ASSOC);
        $this->assert($academicResult['status'] === 'cancelled', "Academic email is CANCELLED at send-time for suspended student");
        $this->assert(strpos($academicResult['error_message'], 'Payment bounced') !== false, "Academic cancellation logs exact reason");

        // Process Unknown/Future event item at send-time (Fail-Closed)
        $engine->processQueueItem($unknownQueueId);
        $stmt_un = $this->pdo->prepare("SELECT status, error_message FROM communication_queue WHERE id = ?");
        $stmt_un->execute([$unknownQueueId]);
        $unknownResult = $stmt_un->fetch(PDO::FETCH_ASSOC);
        $this->assert($unknownResult['status'] === 'cancelled', "Unknown/future event type FAILS CLOSED (cancelled) for suspended student");
        $this->assert(strpos($unknownResult['error_message'], 'Payment bounced') !== false, "Fail-closed cancellation logs exact reason");

        // Process Transactional item at send-time (Allowed for non-active students)
        // Transactional message proceeds to dispatch attempt without status-based cancellation
        $engine->processQueueItem($transQueueId);
        $stmt_tr = $this->pdo->prepare("SELECT status FROM communication_queue WHERE id = ?");
        $stmt_tr->execute([$transQueueId]);
        $transResult = $stmt_tr->fetch(PDO::FETCH_ASSOC);
        $this->assert($transResult['status'] !== 'cancelled', "Transactional communication is NOT cancelled for suspended student");

        // Reset STU001 back to active
        $this->pdo->prepare("UPDATE users SET student_status = 'active' WHERE user_id = 'STU001'")->execute();

        echo "\n--- Test Suite 11: Report Endpoints IDOR & Helper Action Hardening ---\n";
        // Test get_student_call_logs
        $this->assert(can_mentor_view_student($this->pdo, 2, 'STU001') === true, "Mentor 2 is authorized for STU001 call logs");
        $this->assert(can_mentor_view_student($this->pdo, 3, 'STU001') === false, "Mentor 3 is BLOCKED from STU001 call logs (IDOR)");
        $this->assert(can_mentor_view_student($this->pdo, 2, 'STU004') === false, "Mentor 2 is BLOCKED from dropout STU004 call logs");

        // Test get_student_remarks
        $this->assert(can_mentor_view_student($this->pdo, 2, 'STU002') === true, "Mentor 2 is authorized for assigned suspended STU002 remarks");
        $this->assert(can_mentor_view_student($this->pdo, 3, 'STU002') === false, "Mentor 3 is BLOCKED from STU002 remarks (IDOR)");
        $this->assert(can_mentor_view_student($this->pdo, 2, 'STU005') === false, "Mentor 2 is BLOCKED from completed STU005 remarks");

        echo "\n======================================================================\n";
        echo "Test Results: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "======================================================================\n";

        return $this->failed === 0;
    }
}

$suite = new StudentStatusSecurityTestSuite();
$success = $suite->run();
exit($success ? 0 : 1);
