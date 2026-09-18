<?php
/**
 * PEPP Learning — Student Course Access & Status Hardening Test Suite
 *
 * Comprehensive automated regression testing for:
 * 1. is_student_access_valid() logic & boundary conditions
 * 2. Automatic student reactivation on payment approval (payment-review.php)
 * 3. Automatic student reactivation on course access extension (studentpage.php)
 * 4. Magic Status-Recovery Tool query consistency, revalidation & bulk execution
 * 5. Communication Engine suspended status exemption for the 5 canonical events
 * 6. Conservative subject-based fallback safety
 * 7. Deduplication & idempotency of course_access_suspended
 * 8. Status audit trail & reason distinction
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

class StudentAccessReactivationTestSuite {
    private PDO $pdo;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Register SQLite UDFs simulating MySQL functions
        $this->pdo->sqliteCreateFunction('NOW', function() {
            return date('Y-m-d H:i:s');
        }, 0);
        $this->pdo->sqliteCreateFunction('CURDATE', function() {
            return date('Y-m-d');
        }, 0);
        $this->pdo->sqliteCreateFunction('DATEDIFF', function($d1, $d2) {
            if (!$d1 || !$d2) return null;
            $t1 = strtotime($d1);
            $t2 = strtotime($d2);
            return (int)round(($t1 - $t2) / 86400);
        }, 2);

        $this->setupSchema();
    }

    private function setupSchema(): void {
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT UNIQUE,
                name TEXT,
                email TEXT,
                whatsapp_country_code TEXT DEFAULT '91',
                whatsapp_number TEXT,
                pepp_course TEXT,
                pepp_academic_year TEXT,
                status TEXT DEFAULT 'approved',
                student_status TEXT DEFAULT 'active',
                course_status TEXT DEFAULT 'active',
                course_duration_date TEXT,
                course_access_provided TEXT DEFAULT 'yes',
                paid_amount REAL DEFAULT 0,
                payment_plan TEXT,
                joined_date TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE instalment_details (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                instalment_number INTEGER,
                amount REAL,
                due_date TEXT,
                status TEXT DEFAULT 'pending',
                paid_date TEXT,
                payment_reference TEXT,
                payment_screenshot TEXT,
                payment_mode TEXT,
                payment_account_id INTEGER,
                admin_remarks TEXT,
                approved_by TEXT,
                approved_at TEXT,
                rejected_by TEXT,
                rejected_at TEXT,
                paid_amount REAL,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE student_status_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                old_status TEXT,
                new_status TEXT,
                reason TEXT,
                changed_by TEXT,
                changed_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE track_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                action_type TEXT,
                action_details TEXT,
                performed_by TEXT,
                latitude REAL,
                longitude REAL,
                metadata TEXT,
                performed_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE admin_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_username TEXT,
                action TEXT,
                details TEXT,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE admin_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                setting_name TEXT UNIQUE,
                setting_value TEXT,
                updated_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE communication_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT DEFAULT 'email',
                recipient TEXT,
                recipient_name TEXT,
                student_uid TEXT,
                invoice_id INTEGER,
                subject TEXT,
                body_html TEXT,
                body_text TEXT,
                template_name TEXT,
                template_data TEXT,
                attachments TEXT,
                sender_email TEXT,
                sender_name TEXT,
                event_name TEXT,
                status TEXT DEFAULT 'pending',
                attempts INTEGER DEFAULT 0,
                retry_count INTEGER DEFAULT 0,
                last_retry_at TEXT,
                worker_started_at TEXT,
                next_attempt_at TEXT,
                dispatched_at TEXT,
                error_message TEXT,
                meta_data TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE communication_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                status TEXT DEFAULT 'active',
                target_audience TEXT DEFAULT 'students'
            );

            CREATE TABLE communication_campaign_recipients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER,
                queue_id INTEGER,
                lead_id INTEGER,
                status TEXT DEFAULT 'pending',
                error_message TEXT
            );

            CREATE TABLE installment_reminders_sent (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                installment_id INTEGER,
                window_key TEXT,
                sent_at TEXT DEFAULT (datetime('now'))
            );
        ");
    }

    private function assert(bool $condition, string $testName, string $details = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$testName}" . ($details ? " - {$details}" : "") . "\n";
        }
    }

    public function run(): bool {
        echo "======================================================================\n";
        echo "STUDENT ACCESS, STATUS REACTIVATION & EMAIL EXEMPTION AUDIT\n";
        echo "======================================================================\n\n";

        require_once __DIR__ . '/includes/student_access_helper.php';
        require_once __DIR__ . '/includes/auth.php';
        require_once __DIR__ . '/includes/communication/CommunicationEngine.php';

        $this->testAccessValidityRules();
        $this->testPaymentApprovalReactivation();
        $this->testAccessExtensionReactivation();
        $this->testMagicStatusRecoveryTool();
        $this->testSuspendedEmailExemptions();
        $this->testSubjectFallbackSafety();
        $this->testDeduplicationAndIdempotency();

        echo "\n======================================================================\n";
        echo "Test Results: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "======================================================================\n";

        return $this->failed === 0;
    }

    private function testAccessValidityRules(): void {
        echo "--- Suite 1: Canonical Access Validity (is_student_access_valid) ---\n";

        $today = date('Y-m-d');
        $future = date('Y-m-d', strtotime('+30 days'));
        $past = date('Y-m-d', strtotime('-5 days'));

        $this->assert(is_student_access_valid(null) === false, "NULL access date is INVALID");
        $this->assert(is_student_access_valid('') === false, "Empty string access date is INVALID");
        $this->assert(is_student_access_valid('   ') === false, "Whitespace string access date is INVALID");
        $this->assert(is_student_access_valid('0000-00-00') === false, "'0000-00-00' access date is INVALID");
        $this->assert(is_student_access_valid('invalid-date') === false, "Unparseable date is INVALID");
        $this->assert(is_student_access_valid($past) === false, "Past date is EXPIRED/INVALID");
        $this->assert(is_student_access_valid($today) === true, "Today's date is VALID");
        $this->assert(is_student_access_valid($future) === true, "Future date is VALID");
    }

    private function testPaymentApprovalReactivation(): void {
        echo "\n--- Suite 2: Payment Approval Reactivation (payment-review.php) ---\n";

        // Setup students
        $today = date('Y-m-d');
        $future = date('Y-m-d', strtotime('+60 days'));
        $past = date('Y-m-d', strtotime('-10 days'));

        // Student S1: suspended, access extended to future upon payment approval
        $this->pdo->prepare("
            INSERT INTO users (user_id, name, email, student_status, course_status, course_duration_date, status)
            VALUES ('S1', 'Student One', 's1@example.com', 'suspended', 'suspended', ?, 'approved')
        ")->execute([$future]);

        // Student S2: suspended, access date remains in past
        $this->pdo->prepare("
            INSERT INTO users (user_id, name, email, student_status, course_status, course_duration_date, status)
            VALUES ('S2', 'Student Two', 's2@example.com', 'suspended', 'suspended', ?, 'approved')
        ")->execute([$past]);

        // Student S3: already active
        $this->pdo->prepare("
            INSERT INTO users (user_id, name, email, student_status, course_status, course_duration_date, status)
            VALUES ('S3', 'Student Three', 's3@example.com', 'active', 'active', ?, 'approved')
        ")->execute([$future]);

        // Student S4: dropped out (should NOT auto-reactivate)
        $this->pdo->prepare("
            INSERT INTO users (user_id, name, email, student_status, course_status, course_duration_date, status)
            VALUES ('S4', 'Student Four', 's4@example.com', 'dropout', 'suspended', ?, 'approved')
        ")->execute([$future]);

        // S1 Reactivation
        $res1 = reactivate_student_if_access_valid($this->pdo, 'S1', 'admin_tester', 'Access valid after installment payment approval');
        $this->assert($res1 === true, "Suspended student S1 with future access is REACTIVATED");

        $s1 = $this->pdo->query("SELECT student_status, course_status FROM users WHERE user_id = 'S1'")->fetch();
        $this->assert($s1['student_status'] === 'active', "S1 student_status is now active");
        $this->assert($s1['course_status'] === 'active', "S1 course_status is now active");

        // Verify S1 audit logging
        $s1_log = $this->pdo->query("SELECT old_status, new_status, reason FROM student_status_log WHERE user_id = 'S1'")->fetch();
        $this->assert($s1_log !== false && $s1_log['old_status'] === 'suspended' && $s1_log['new_status'] === 'active', "Status log suspended -> active created for S1");
        $this->assert(strpos($s1_log['reason'], 'Access valid after installment payment approval') !== false, "Status log contains exact approval reason");

        // S2 Past Access Date (Must NOT reactivate)
        $res2 = reactivate_student_if_access_valid($this->pdo, 'S2', 'admin_tester', 'Access valid after installment payment approval');
        $this->assert($res2 === false, "Suspended student S2 with past access is NOT reactivated");
        $s2 = $this->pdo->query("SELECT student_status FROM users WHERE user_id = 'S2'")->fetch();
        $this->assert($s2['student_status'] === 'suspended', "S2 remains suspended");

        // S3 Active Student (Must NOT write redundant status transition)
        $res3 = reactivate_student_if_access_valid($this->pdo, 'S3', 'admin_tester', 'Access valid after installment payment approval');
        $this->assert($res3 === false, "Already active student S3 is untouched (returns false)");

        // S4 Dropout Student (Must NOT reactivate)
        $res4 = reactivate_student_if_access_valid($this->pdo, 'S4', 'admin_tester', 'Access valid after installment payment approval');
        $this->assert($res4 === false, "Dropout student S4 is untouched (returns false)");
        $s4 = $this->pdo->query("SELECT student_status FROM users WHERE user_id = 'S4'")->fetch();
        $this->assert($s4['student_status'] === 'dropout', "S4 remains dropout");
    }

    private function testAccessExtensionReactivation(): void {
        echo "\n--- Suite 3: Course Access Extension Reactivation (studentpage.php) ---\n";

        $future = date('Y-m-d', strtotime('+45 days'));
        $past = date('Y-m-d', strtotime('-2 days'));

        // Suspended student S5
        $this->pdo->prepare("
            INSERT INTO users (user_id, name, email, student_status, course_status, course_duration_date, status)
            VALUES ('S5', 'Student Five', 's5@example.com', 'suspended', 'suspended', ?, 'approved')
        ")->execute([$past]);

        // Admin extends S5 to past date -> remains suspended
        $this->pdo->beginTransaction();
        $this->pdo->prepare("UPDATE users SET course_duration_date = ? WHERE user_id = 'S5'")->execute([$past]);
        $reactivatedPast = reactivate_student_if_access_valid($this->pdo, 'S5', 'admin_tester', 'Course access extended to valid date');
        $this->pdo->commit();
        $this->assert($reactivatedPast === false, "Extension to past date leaves S5 suspended");

        // Admin extends S5 to future date -> reactivated to active
        $this->pdo->beginTransaction();
        $this->pdo->prepare("UPDATE users SET course_duration_date = ? WHERE user_id = 'S5'")->execute([$future]);
        $reactivatedFuture = reactivate_student_if_access_valid($this->pdo, 'S5', 'admin_tester', 'Course access extended to valid date');
        $this->pdo->commit();
        $this->assert($reactivatedFuture === true, "Extension to future date reactivates S5 to active");

        $s5 = $this->pdo->query("SELECT student_status, course_status FROM users WHERE user_id = 'S5'")->fetch();
        $this->assert($s5['student_status'] === 'active', "S5 status is active");
        $this->assert($s5['course_status'] === 'active', "S5 course_status is active");

        // Verify audit log
        $s5_log = $this->pdo->query("SELECT old_status, new_status, reason FROM student_status_log WHERE user_id = 'S5' AND reason = 'Course access extended to valid date'")->fetch();
        $this->assert($s5_log !== false && $s5_log['new_status'] === 'active', "Status log records course access extension reactivation");
    }

    private function testMagicStatusRecoveryTool(): void {
        echo "\n--- Suite 4: Magic Status-Recovery Tool ---\n";

        $today = date('Y-m-d');
        $future = date('Y-m-d', strtotime('+30 days'));
        $past = date('Y-m-d', strtotime('-15 days'));

        // Insert set of students:
        // M1: approved, suspended, future access -> ELIGIBLE
        // M2: approved, suspended, today's access -> ELIGIBLE
        // M3: approved, suspended, past access -> NOT eligible
        // M4: approved, suspended, NULL access -> NOT eligible
        // M5: approved, suspended, empty access -> NOT eligible
        // M6: approved, suspended, '0000-00-00' access -> NOT eligible
        // M7: pending approval, suspended, future access -> NOT eligible (status != 'approved')
        // M8: approved, active, future access -> NOT eligible (already active)
        $this->pdo->prepare("INSERT INTO users (user_id, name, student_status, course_duration_date, status) VALUES ('M1', 'Magic 1', 'suspended', ?, 'approved')")->execute([$future]);
        $this->pdo->prepare("INSERT INTO users (user_id, name, student_status, course_duration_date, status) VALUES ('M2', 'Magic 2', 'suspended', ?, 'approved')")->execute([$today]);
        $this->pdo->prepare("INSERT INTO users (user_id, name, student_status, course_duration_date, status) VALUES ('M3', 'Magic 3', 'suspended', ?, 'approved')")->execute([$past]);
        $this->pdo->prepare("INSERT INTO users (user_id, name, student_status, course_duration_date, status) VALUES ('M4', 'Magic 4', 'suspended', NULL, 'approved')")->execute();
        $this->pdo->prepare("INSERT INTO users (user_id, name, student_status, course_duration_date, status) VALUES ('M5', 'Magic 5', 'suspended', '', 'approved')")->execute();
        $this->pdo->prepare("INSERT INTO users (user_id, name, student_status, course_duration_date, status) VALUES ('M6', 'Magic 6', 'suspended', '0000-00-00', 'approved')")->execute();
        $this->pdo->prepare("INSERT INTO users (user_id, name, student_status, course_duration_date, status) VALUES ('M7', 'Magic 7', 'suspended', ?, 'pending')")->execute([$future]);
        $this->pdo->prepare("INSERT INTO users (user_id, name, student_status, course_duration_date, status) VALUES ('M8', 'Magic 8', 'active', ?, 'approved')")->execute([$future]);

        // Check count
        $count = count_eligible_magic_reactivation_students($this->pdo);
        $this->assert($count === 2, "Eligible count strictly equals 2 (M1 and M2 only)");

        $eligible = get_eligible_magic_reactivation_students($this->pdo);
        $uids = array_column($eligible, 'user_id');
        sort($uids);
        $this->assert($uids === ['M1', 'M2'], "Eligible list contains exactly M1 and M2");

        // Execute bulk reactivation
        $res = bulk_reactivate_magic_students($this->pdo, 'admin_magic');
        $this->assert($res['reactivated'] === 2, "Bulk reactivation restored 2 students");

        // Re-check count after reactivation
        $countAfter = count_eligible_magic_reactivation_students($this->pdo);
        $this->assert($countAfter === 0, "Eligible count after reactivation drops to 0 (button disappears)");

        // Verify M1 and M2 status
        $m1 = $this->pdo->query("SELECT student_status, course_status FROM users WHERE user_id = 'M1'")->fetch();
        $m2 = $this->pdo->query("SELECT student_status, course_status FROM users WHERE user_id = 'M2'")->fetch();
        $this->assert($m1['student_status'] === 'active' && $m2['student_status'] === 'active', "M1 and M2 both active");

        // Verify M3, M4, M5, M6 remain suspended
        $m3 = $this->pdo->query("SELECT student_status FROM users WHERE user_id = 'M3'")->fetch();
        $m4 = $this->pdo->query("SELECT student_status FROM users WHERE user_id = 'M4'")->fetch();
        $this->assert($m3['student_status'] === 'suspended', "M3 with expired access remains suspended");
        $this->assert($m4['student_status'] === 'suspended', "M4 with NULL access remains suspended");

        // Verify Magic audit logs
        $magic_log = $this->pdo->query("SELECT reason FROM student_status_log WHERE user_id = 'M1' AND reason LIKE 'Magic reactivation%'")->fetch();
        $this->assert($magic_log !== false, "Magic reactivation reason logged in student_status_log");
    }

    private function testSuspendedEmailExemptions(): void {
        echo "\n--- Suite 5: Suspended Student Email Exemption (CommunicationEngine) ---\n";

        // Setup suspended student E1
        $this->pdo->prepare("
            INSERT INTO users (user_id, name, email, student_status, course_status, status)
            VALUES ('E1', 'Email Student 1', 'e1@example.com', 'suspended', 'suspended', 'approved')
        ")->execute();

        $engine = CommunicationEngine::getInstance($this->pdo);

        // Test the 5 required event categories + aliases
        $exemptEvents = [
            'course_access_suspended',
            'installment_overdue',
            'installment_payment_confirmed',
            'payment_confirmation',
            'payment_approved',
            'invoice_email',
            'payment_receipt_received',
            'payment_receipt',
            'installment_payment_due',
            'installment_reminder'
        ];

        foreach ($exemptEvents as $event) {
            $this->pdo->prepare("
                INSERT INTO communication_queue (channel, recipient, student_uid, event_name, status, next_attempt_at, created_at)
                VALUES ('email', 'e1@example.com', 'E1', ?, 'pending', NOW(), NOW())
            ")->execute([$event]);
            $qId = (int)$this->pdo->lastInsertId();

            $engine->processQueueItem($qId);

            $status = $this->pdo->query("SELECT status, error_message FROM communication_queue WHERE id = {$qId}")->fetch();
            $this->assert(
                $status['status'] !== 'cancelled',
                "Exempt event '{$event}' is NOT cancelled for suspended student",
                "Got status: {$status['status']}, error: {$status['error_message']}"
            );
        }

        // Test NON-exempt events for suspended student (MUST BE CANCELLED)
        $nonExemptEvents = [
            'live_session_reminder',
            'weekly_newsletter',
            'session_scheduled',
            'course_migration_completed',
            'unknown_event_random'
        ];

        foreach ($nonExemptEvents as $event) {
            $this->pdo->prepare("
                INSERT INTO communication_queue (channel, recipient, student_uid, event_name, status, next_attempt_at, created_at)
                VALUES ('email', 'e1@example.com', 'E1', ?, 'pending', NOW(), NOW())
            ")->execute([$event]);
            $qId = (int)$this->pdo->lastInsertId();

            $engine->processQueueItem($qId);

            $status = $this->pdo->query("SELECT status, error_message FROM communication_queue WHERE id = {$qId}")->fetch();
            $this->assert(
                $status['status'] === 'cancelled',
                "Non-exempt event '{$event}' is CANCELLED for suspended student",
                "Status: {$status['status']}"
            );
            $this->assert(
                strpos($status['error_message'], "student status is 'suspended'") !== false,
                "Non-exempt cancellation message notes suspended status"
            );
        }
    }

    private function testSubjectFallbackSafety(): void {
        echo "\n--- Suite 6: Conservative Subject-Based Fallback Safety ---\n";

        $engine = CommunicationEngine::getInstance($this->pdo);

        // 1. Exact valid subjects with NULL/empty event_name
        $validSubjects = [
            "PEPP Learning - Course Access Suspended (Overdue Installment)",
            "Installment Payment Confirmed - Installment #2",
            "Payment Confirmed - Invoice INV/0926/101 | PEPP Learning",
            "Payment Receipt Received - Installment #1",
            "Upcoming Installment Payment Reminder (Due in 10 Days)",
            "PEPP Learning - Installment Payment Due in 3 Days",
            "PEPP Learning - Installment Payment Due Today"
        ];

        foreach ($validSubjects as $subj) {
            $this->pdo->prepare("
                INSERT INTO communication_queue (channel, recipient, student_uid, event_name, subject, status, next_attempt_at, created_at)
                VALUES ('email', 'e1@example.com', 'E1', NULL, ?, 'pending', NOW(), NOW())
            ")->execute([$subj]);
            $qId = (int)$this->pdo->lastInsertId();

            $engine->processQueueItem($qId);

            $status = $this->pdo->query("SELECT status, error_message FROM communication_queue WHERE id = {$qId}")->fetch();
            $this->assert(
                $status['status'] !== 'cancelled',
                "Conservative subject fallback MATCHES: '{$subj}'",
                "Got status: {$status['status']}, error: {$status['error_message']}"
            );
        }

        // 2. Ambiguous/generic subjects with words like "Payment", "Invoice", "Due" (MUST NOT BYPASS GUARD)
        $ambiguousSubjects = [
            "General payment instructions",
            "Invoice format inquiry",
            "Due to weather, class cancelled",
            "Admin approved your leave request",
            "Payment gateway scheduled maintenance",
            "Monthly fee structure details"
        ];

        foreach ($ambiguousSubjects as $subj) {
            $this->pdo->prepare("
                INSERT INTO communication_queue (channel, recipient, student_uid, event_name, subject, status, next_attempt_at, created_at)
                VALUES ('email', 'e1@example.com', 'E1', NULL, ?, 'pending', NOW(), NOW())
            ")->execute([$subj]);
            $qId = (int)$this->pdo->lastInsertId();

            $engine->processQueueItem($qId);

            $status = $this->pdo->query("SELECT status, error_message FROM communication_queue WHERE id = {$qId}")->fetch();
            $this->assert(
                $status['status'] === 'cancelled',
                "Ambiguous subject FAILS SAFE (cancelled): '{$subj}'",
                "Got status: {$status['status']}"
            );
        }
    }

    private function testDeduplicationAndIdempotency(): void {
        echo "\n--- Suite 7: Suspension Email Idempotency & Deduplication ---\n";

        // Test installment_reminders_sent table logic used by session_cron.php
        $installmentId = 999;
        $window = 'overdue';

        // Check if sent
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM installment_reminders_sent WHERE installment_id = ? AND window_key = ?");
        $stmt->execute([$installmentId, $window]);
        $this->assert((int)$stmt->fetchColumn() === 0, "Initially reminder not sent");

        // Record first send
        $this->pdo->prepare("INSERT INTO installment_reminders_sent (installment_id, window_key, sent_at) VALUES (?, ?, NOW())")->execute([$installmentId, $window]);

        // Check again
        $stmt->execute([$installmentId, $window]);
        $this->assert((int)$stmt->fetchColumn() === 1, "Recorded first send in installment_reminders_sent");

        // Next cron run checks sent cache:
        $stmtAll = $this->pdo->query("SELECT installment_id, window_key FROM installment_reminders_sent");
        $sentCache = [];
        foreach ($stmtAll->fetchAll() as $s) {
            $sentCache[$s['installment_id'] . ':' . $s['window_key']] = true;
        }

        $this->assert(isset($sentCache[$installmentId . ':overdue']) === true, "Deduplication cache recognizes overdue reminder already sent");
    }
}

$suite = new StudentAccessReactivationTestSuite();
$success = $suite->run();
exit($success ? 0 : 1);
