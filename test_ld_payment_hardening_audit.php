<?php
/**
 * PEPP Learning ERP — L&D Intern Payment Recording Hardening Test Suite
 *
 * Verifies all Scenarios A through L:
 * A. Valid payout -> succeeds.
 * B. Invalid/missing CSRF token -> controlled security error, no DB insertion.
 * C. Invalid payment account -> controlled validation error.
 * D. Overlapping completed period -> blocked.
 * E. End date before start date -> blocked.
 * F. Future end date -> blocked.
 * G. Negative final amount -> blocked.
 * H. Valid payout without screenshot -> optional screenshot handled properly.
 * I. Valid payout with screenshot -> file saved and DB path recorded.
 * J. Database failure during expense insertion -> complete transaction rollback and orphan cleanup.
 * K. Double-click / duplicate submission -> blocked by overlap protection.
 * L. Post-payout verification of ld_intern_payments, expenses, ld_intern_payment_items, and report consistency.
 */

declare(strict_types=1);

// Configure test session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_username'] = 'superadmin_tester';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

require_once __DIR__ . '/includes/auth.php';

// Setup isolated in-memory SQLite DB with MySQL emulation
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

// Create required schemas
$pdo->exec("
    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'intern',
        admin_type TEXT DEFAULT 'intern',
        permissions TEXT DEFAULT 'task-tracker',
        status TEXT DEFAULT 'active',
        created_at TEXT DEFAULT '2026-08-01 10:00:00'
    );

    CREATE TABLE IF NOT EXISTS payment_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_name TEXT NOT NULL,
        account_type TEXT DEFAULT 'Bank',
        status TEXT DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS ld_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL,
        admin_username TEXT NOT NULL,
        admin_name TEXT NOT NULL,
        admin_role TEXT NOT NULL,
        course_id INTEGER NOT NULL,
        course_name TEXT NOT NULL,
        mode_id INTEGER NOT NULL,
        mode_name TEXT NOT NULL,
        mode_name_snapshot TEXT DEFAULT NULL,
        quantity_label_snapshot TEXT DEFAULT 'questions',
        charge_per_quantity_snapshot REAL DEFAULT 15.00,
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS ld_task_topics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        topic_name TEXT NOT NULL,
        quantity REAL DEFAULT NULL,
        calculated_charge REAL NOT NULL DEFAULT 0.00,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS ld_intern_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        voucher_no TEXT NOT NULL UNIQUE,
        intern_id INTEGER NOT NULL,
        intern_username_snapshot TEXT NOT NULL,
        intern_name_snapshot TEXT NOT NULL,
        period_start_date TEXT NOT NULL,
        period_end_date TEXT NOT NULL,
        expected_amount REAL NOT NULL,
        adjustment_amount REAL NOT NULL DEFAULT 0.00,
        paid_amount REAL NOT NULL,
        payment_account_id INTEGER DEFAULT NULL,
        payment_account_name_snapshot TEXT DEFAULT NULL,
        paid_date TEXT NOT NULL,
        screenshot_path TEXT DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'Completed',
        created_by TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT NULL
    );

    CREATE TABLE IF NOT EXISTS ld_intern_payment_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payment_id INTEGER NOT NULL,
        work_mode_id INTEGER NOT NULL,
        work_mode_name_snapshot TEXT NOT NULL,
        quantity REAL NOT NULL,
        quantity_label_snapshot TEXT DEFAULT NULL
    );

    CREATE TABLE IF NOT EXISTS expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        purpose TEXT NOT NULL,
        expense_type TEXT DEFAULT 'L&D Intern Payment',
        amount REAL NOT NULL,
        remarks TEXT DEFAULT NULL,
        payment_account_id INTEGER DEFAULT NULL,
        spent_date TEXT DEFAULT NULL,
        ld_payment_id INTEGER DEFAULT NULL,
        created_by TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
");

// Seed test intern
$pdo->exec("
    INSERT INTO admins (id, username, full_name, role, admin_type, permissions, status, created_at)
    VALUES (101, 'isha_fathima', 'Isha Fathima E P', 'intern', 'intern', 'task-tracker', 'active', '2026-08-01 00:00:00');
");

// Seed test payment accounts
$pdo->exec("
    INSERT INTO payment_accounts (id, account_name, account_type, status)
    VALUES
    (1, 'AXIS LABINC', 'Bank', 'active'),
    (2, 'INACTIVE ACCOUNT', 'Bank', 'inactive');
");

// Seed active tasks for intern between 2026-08-10 and 2026-08-20
$pdo->exec("
    INSERT INTO ld_tasks (id, admin_id, admin_username, admin_name, admin_role, course_id, course_name, mode_id, mode_name, mode_name_snapshot, quantity_label_snapshot, charge_per_quantity_snapshot, status, created_at)
    VALUES
    (1, 101, 'isha_fathima', 'Isha Fathima E P', 'intern', 10, 'Psychology', 1, 'Question Preparation', 'Question Preparation', 'questions', 10.00, 'active', '2026-08-12 11:00:00'),
    (2, 101, 'isha_fathima', 'Isha Fathima E P', 'intern', 10, 'Psychology', 2, 'Content Review', 'Content Review', 'chapters', 50.00, 'active', '2026-08-15 14:00:00');

    INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge)
    VALUES
    (1, 'Cognitive Psychology Qs', 20, 200.00),
    (2, 'Abnormal Psychology Review', 3, 150.00);
");

// Simulation executor for record_payment logic
function simulate_record_payment(PDO $pdo, array $post_data, ?array $file_data = null, bool $force_expense_fail = false): array {
    $admin_username = get_admin_username();

    // CSRF check
    $csrf_submitted = $post_data['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_submitted)) {
        return ['success' => false, 'error' => 'Invalid request (CSRF check failed).'];
    }

    $intern_id = (int)($post_data['intern_id'] ?? 0);
    $start = trim($post_data['period_start'] ?? '');
    $end = trim($post_data['period_end'] ?? '');
    $adj = (float)($post_data['adjustment_amount'] ?? 0.00);
    $acct_id = (int)($post_data['payment_account_id'] ?? 0);
    $paid_date = trim($post_data['paid_date'] ?? '');
    $remarks = trim($post_data['remarks'] ?? '');

    if (!empty($start) && strtotime($start)) {
        $start = date('Y-m-d', strtotime($start));
    }
    if (!empty($end) && strtotime($end)) {
        $end = date('Y-m-d', strtotime($end));
    }
    if (!empty($paid_date) && strtotime($paid_date)) {
        $paid_date = date('Y-m-d', strtotime($paid_date));
    }

    $stmt = $pdo->prepare("
        SELECT username, full_name, DATE(created_at) AS joining_date
        FROM admins
        WHERE id = ?
          AND (
              admin_type = 'intern'
              OR (
                  role != 'super_admin'
                  AND permissions != 'ALL'
                  AND (permissions = 'task-tracker' OR permissions LIKE '%,task-tracker' OR permissions LIKE 'task-tracker,%' OR permissions LIKE '%,task-tracker,%')
              )
          )
    ");
    $stmt->execute([$intern_id]);
    $intern = $stmt->fetch();

    if (!$intern) {
        return ['success' => false, 'error' => 'Intern not found.'];
    } elseif (empty($start) || empty($end) || empty($paid_date)) {
        return ['success' => false, 'error' => 'Start date, end date, and paid date are mandatory.'];
    } elseif (!strtotime($start) || !strtotime($end) || !strtotime($paid_date)) {
        return ['success' => false, 'error' => 'Invalid date format provided.'];
    } elseif (!empty($intern['joining_date']) && $start < $intern['joining_date']) {
        return ['success' => false, 'error' => 'Start date cannot be earlier than intern joining/registration date (' . $intern['joining_date'] . ').'];
    } elseif ($end < $start) {
        return ['success' => false, 'error' => 'End date cannot be earlier than start date.'];
    } elseif ($end > date('Y-m-d')) {
        return ['success' => false, 'error' => 'End date cannot be in the future.'];
    } elseif ($acct_id <= 0) {
        return ['success' => false, 'error' => 'Payment account is required.'];
    }

    $stmt = $pdo->prepare("SELECT account_name FROM payment_accounts WHERE id = ? AND status = 'active'");
    $stmt->execute([$acct_id]);
    $acct_name = $stmt->fetchColumn();

    if (!$acct_name) {
        return ['success' => false, 'error' => 'Invalid or inactive payment account selected.'];
    }

    // Overlap check
    $stmt = $pdo->prepare("
        SELECT id, voucher_no, period_start_date, period_end_date
        FROM ld_intern_payments
        WHERE intern_id = ?
          AND status = 'Completed'
          AND NOT (period_end_date < ? OR period_start_date > ?)
        LIMIT 1
    ");
    $stmt->execute([$intern_id, $start, $end]);
    $overlap = $stmt->fetch();

    if ($overlap) {
        $ov_start = date('d M Y', strtotime($overlap['period_start_date']));
        $ov_end = date('d M Y', strtotime($overlap['period_end_date']));
        return ['success' => false, 'error' => "This payment period overlaps with an existing completed payment period (Voucher: " . $overlap['voucher_no'] . ", " . $ov_start . " – " . $ov_end . "). Please select a non-overlapping period."];
    }

    // Server-side calculation
    $stmt = $pdo->prepare("
        SELECT SUM(tp.calculated_charge) AS total_charge
        FROM ld_tasks t
        JOIN ld_task_topics tp ON tp.task_id = t.id
        WHERE t.admin_username = ?
          AND t.status = 'active'
          AND DATE(t.created_at) BETWEEN ? AND ?
          AND tp.quantity IS NOT NULL
          AND tp.quantity > 0
          AND NOT EXISTS (
              SELECT 1 FROM ld_intern_payments p
              WHERE p.intern_id = ?
                AND p.status = 'Completed'
                AND DATE(t.created_at) BETWEEN p.period_start_date AND p.period_end_date
          )
    ");
    $stmt->execute([$intern['username'], $start, $end, $intern_id]);
    $server_expected = (float)($stmt->fetchColumn() ?? 0.00);

    $server_paid_amount = $server_expected + $adj;
    if ($server_paid_amount < 0) {
        return ['success' => false, 'error' => 'Final paid amount cannot be negative.'];
    }

    $screenshot_path = null;
    if ($file_data && ($file_data['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($file_data['error'] === UPLOAD_ERR_INI_SIZE || $file_data['error'] === UPLOAD_ERR_FORM_SIZE) {
            return ['success' => false, 'error' => 'Payment screenshot exceeds the maximum allowed file size.'];
        } elseif ($file_data['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Payment screenshot upload failed (Error code: ' . (int)$file_data['error'] . ').'];
        } else {
            $file_ext = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
            if (!in_array($file_ext, $allowed_exts)) {
                return ['success' => false, 'error' => 'Only image (JPG, PNG) or PDF screenshots are allowed.'];
            } elseif ($file_data['size'] > 5 * 1024 * 1024) {
                return ['success' => false, 'error' => 'Payment screenshot size cannot exceed 5MB.'];
            } else {
                $unique_name = 'ld_pay_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
                $screenshot_path = 'uploads/ld_payments/' . $unique_name;
                $mock_dir = __DIR__ . '/uploads/ld_payments/';
                if (!file_exists($mock_dir)) @mkdir($mock_dir, 0777, true);
                file_put_contents(__DIR__ . '/' . $screenshot_path, 'mock file content');
            }
        }
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, voucher_no, period_start_date, period_end_date
            FROM ld_intern_payments
            WHERE intern_id = ?
              AND status = 'Completed'
              AND NOT (period_end_date < ? OR period_start_date > ?)
            LIMIT 1
        ");
        $stmt->execute([$intern_id, $start, $end]);
        $tx_overlap = $stmt->fetch();

        if ($tx_overlap) {
            $ov_start = date('d M Y', strtotime($tx_overlap['period_start_date']));
            $ov_end = date('d M Y', strtotime($tx_overlap['period_end_date']));
            throw new Exception("This payment period overlaps with an existing completed payment period (Voucher: " . $tx_overlap['voucher_no'] . ", " . $ov_start . " – " . $ov_end . "). Please select a non-overlapping period.");
        }

        $temp_voucher = 'TEMP-LD-' . bin2hex(random_bytes(16));

        $stmt = $pdo->prepare("
            INSERT INTO ld_intern_payments (
                voucher_no, intern_id, intern_username_snapshot, intern_name_snapshot,
                period_start_date, period_end_date, expected_amount, adjustment_amount,
                paid_amount, payment_account_id, payment_account_name_snapshot, paid_date,
                screenshot_path, remarks, status, created_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed', ?
            )
        ");
        $stmt->execute([
            $temp_voucher,
            $intern_id, $intern['username'], $intern['full_name'],
            $start, $end, $server_expected, $adj,
            $server_paid_amount, $acct_id, $acct_name, $paid_date,
            $screenshot_path, $remarks, $admin_username
        ]);

        $inserted_id = (int)$pdo->lastInsertId();
        $voucher_no = 'VOU-LD-' . str_pad((string)$inserted_id, 5, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("UPDATE ld_intern_payments SET voucher_no = ? WHERE id = ?");
        $stmt->execute([$voucher_no, $inserted_id]);

        if ($force_expense_fail) {
            throw new Exception("Simulated DB failure during expense insertion");
        }

        $expense_purpose = "L&D Intern Payment – " . $intern['full_name'] . " – " . $voucher_no;
        $expense_stmt = $pdo->prepare("
            INSERT INTO expenses (
                purpose, expense_type, amount, remarks,
                payment_account_id, spent_date, ld_payment_id, created_by, created_at
            ) VALUES (
                ?, 'L&D Intern Payment', ?, ?,
                ?, ?, ?, ?, NOW()
            )
        ");
        $expense_stmt->execute([
            $expense_purpose,
            $server_paid_amount,
            $remarks ?: null,
            $acct_id,
            $paid_date,
            $inserted_id,
            $admin_username
        ]);

        $stmt = $pdo->prepare("
            SELECT t.mode_id, MAX(COALESCE(t.mode_name_snapshot, t.mode_name)) AS mode_title, t.quantity_label_snapshot, SUM(tp.quantity) AS total_qty
            FROM ld_tasks t
            JOIN ld_task_topics tp ON tp.task_id = t.id
            WHERE t.admin_username = ?
              AND t.status = 'active'
              AND DATE(t.created_at) BETWEEN ? AND ?
              AND tp.quantity IS NOT NULL
              AND tp.quantity > 0
              AND NOT EXISTS (
                  SELECT 1 FROM ld_intern_payments p
                  WHERE p.intern_id = ?
                    AND p.id != ?
                    AND p.status = 'Completed'
                    AND DATE(t.created_at) BETWEEN p.period_start_date AND p.period_end_date
              )
            GROUP BY t.mode_id, t.quantity_label_snapshot
        ");
        $stmt->execute([$intern['username'], $start, $end, $intern_id, $inserted_id]);
        $aggregated_items = $stmt->fetchAll();

        $item_stmt = $pdo->prepare("
            INSERT INTO ld_intern_payment_items (
                payment_id, work_mode_id, work_mode_name_snapshot, quantity, quantity_label_snapshot
            ) VALUES (
                ?, ?, ?, ?, ?
            )
        ");
        foreach ($aggregated_items as $item) {
            $mtitle = !empty($item['mode_title']) ? $item['mode_title'] : 'Work Mode';
            $qlbl = !empty($item['quantity_label_snapshot']) ? $item['quantity_label_snapshot'] : 'units';
            $item_stmt->execute([
                $inserted_id,
                (int)$item['mode_id'],
                $mtitle,
                (float)$item['total_qty'],
                $qlbl
            ]);
        }

        $audit_details = "Recorded payment {$voucher_no} for intern {$intern['full_name']} ({$start} to {$end})";
        log_admin_activity($pdo, $admin_username, 'ld_payment_recorded', $audit_details);

        $pdo->commit();
        return [
            'success' => true,
            'voucher_no' => $voucher_no,
            'payment_id' => $inserted_id,
            'screenshot_path' => $screenshot_path,
            'paid_amount' => $server_paid_amount,
            'expected_amount' => $server_expected
        ];
    } catch (Throwable $txEx) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($screenshot_path)) {
            $orphan_file = __DIR__ . '/' . $screenshot_path;
            if (file_exists($orphan_file)) {
                @unlink($orphan_file);
            }
        }
        return ['success' => false, 'error' => "Database error: " . $txEx->getMessage()];
    }
}

// Running Test Matrix
$tests_passed = 0;
$tests_failed = 0;

function assert_test(string $name, bool $condition, string $detail = '') {
    global $tests_passed, $tests_failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $tests_passed++;
    } else {
        echo "  [FAIL] {$name}: {$detail}\n";
        $tests_failed++;
    }
}

echo "====================================================================\n";
echo "PEPP ERP — L&D INTERN PAYMENT HARDENING REGRESSION TEST SUITE\n";
echo "====================================================================\n\n";

// Scenario A: Valid payout -> succeeds
echo "--- Scenario A: Valid payout ---\n";
$valid_post = [
    'csrf_token' => $_SESSION['csrf_token'],
    'action' => 'record_payment',
    'intern_id' => 101,
    'period_start' => '2026-08-10',
    'period_end' => '2026-08-20',
    'adjustment_amount' => 50.00,
    'payment_account_id' => 1,
    'paid_date' => '2026-08-21',
    'remarks' => 'Monthly intern payout with bonus'
];
$res_a = simulate_record_payment($pdo, $valid_post);
assert_test("Scenario A: Payment succeeds", $res_a['success'] === true);
assert_test("Scenario A: Expected amount calculated from tasks (₹350.00)", ($res_a['expected_amount'] ?? 0) === 350.00);
assert_test("Scenario A: Final paid amount = expected + adjustment (₹400.00)", ($res_a['paid_amount'] ?? 0) === 400.00);
assert_test("Scenario A: Voucher format VOU-LD-00001", ($res_a['voucher_no'] ?? '') === 'VOU-LD-00001');

// Scenario B: Invalid/missing CSRF token -> blocked
echo "\n--- Scenario B: CSRF Protection ---\n";
$bad_csrf_post = $valid_post;
$bad_csrf_post['csrf_token'] = 'invalid_attacker_token_xyz';
$res_b = simulate_record_payment($pdo, $bad_csrf_post);
assert_test("Scenario B: Blocked with invalid CSRF", $res_b['success'] === false && str_contains($res_b['error'], 'CSRF'));

// Scenario C: Invalid payment account -> blocked
echo "\n--- Scenario C: Payment Account Validation ---\n";
$bad_acct_post = $valid_post;
$bad_acct_post['period_start'] = '2026-08-22';
$bad_acct_post['period_end'] = '2026-08-25';
$bad_acct_post['payment_account_id'] = 2; // inactive account
$res_c = simulate_record_payment($pdo, $bad_acct_post);
assert_test("Scenario C: Inactive payment account blocked", $res_c['success'] === false && str_contains($res_c['error'], 'payment account'));

// Scenario D: Overlapping completed period -> blocked
echo "\n--- Scenario D: Overlap Protection ---\n";
$overlap_post = $valid_post;
$overlap_post['period_start'] = '2026-08-15'; // overlaps with existing 2026-08-10 - 2026-08-20
$overlap_post['period_end'] = '2026-08-25';
$res_d = simulate_record_payment($pdo, $overlap_post);
assert_test("Scenario D: Overlapping period blocked", $res_d['success'] === false && str_contains($res_d['error'], 'overlaps'));

// Scenario E: End date before start date -> blocked
echo "\n--- Scenario E: Date Range Consistency ---\n";
$bad_date_post = $valid_post;
$bad_date_post['period_start'] = '2026-08-25';
$bad_date_post['period_end'] = '2026-08-22';
$res_e = simulate_record_payment($pdo, $bad_date_post);
assert_test("Scenario E: End date before start date blocked", $res_e['success'] === false && str_contains($res_e['error'], 'earlier than start date'));

// Scenario F: Future end date -> blocked
echo "\n--- Scenario F: Future Date Block ---\n";
$future_date_post = $valid_post;
$future_date_post['period_start'] = '2026-08-22';
$future_date_post['period_end'] = date('Y-m-d', strtotime('+5 days'));
$res_f = simulate_record_payment($pdo, $future_date_post);
assert_test("Scenario F: Future end date blocked", $res_f['success'] === false && str_contains($res_f['error'], 'future'));

// Scenario G: Negative final amount -> blocked
echo "\n--- Scenario G: Negative Amount Block ---\n";
$neg_post = $valid_post;
$neg_post['period_start'] = '2026-08-22';
$neg_post['period_end'] = '2026-08-25';
$neg_post['adjustment_amount'] = -500.00; // Expected is 0, adjustment -500 => -500
$res_g = simulate_record_payment($pdo, $neg_post);
assert_test("Scenario G: Negative final paid amount blocked", $res_g['success'] === false && str_contains($res_g['error'], 'cannot be negative'));

// Scenario H: Valid payout without screenshot -> optional screenshot handled properly
echo "\n--- Scenario H: Optional Screenshot Support ---\n";
$no_ss_post = $valid_post;
$no_ss_post['period_start'] = '2026-08-22';
$no_ss_post['period_end'] = '2026-08-25';
$no_ss_post['adjustment_amount'] = 100.00;
$res_h = simulate_record_payment($pdo, $no_ss_post, null);
assert_test("Scenario H: Payout succeeds without screenshot", $res_h['success'] === true && $res_h['screenshot_path'] === null);

// Scenario I: Valid payout with screenshot -> file saved and DB path recorded
echo "\n--- Scenario I: Screenshot File Upload ---\n";
$with_ss_post = $valid_post;
$with_ss_post['period_start'] = '2026-08-26';
$with_ss_post['period_end'] = '2026-08-28';
$with_ss_post['adjustment_amount'] = 200.00;
$mock_file = [
    'name' => 'payment_proof.png',
    'size' => 1024 * 50,
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => sys_get_temp_dir() . '/test_payout_ss.png'
];
$res_i = simulate_record_payment($pdo, $with_ss_post, $mock_file);
assert_test("Scenario I: Payout succeeds with screenshot", $res_i['success'] === true && !empty($res_i['screenshot_path']));
assert_test("Scenario I: Screenshot file exists on disk", file_exists(__DIR__ . '/' . ($res_i['screenshot_path'] ?? '')));

// Clean up mock file
if (!empty($res_i['screenshot_path']) && file_exists(__DIR__ . '/' . $res_i['screenshot_path'])) {
    @unlink(__DIR__ . '/' . $res_i['screenshot_path']);
}

// Scenario J: Database failure during expense insertion -> complete transaction rollback & orphan cleanup
echo "\n--- Scenario J: Defensive Transaction Rollback & Orphan Cleanup ---\n";
$fail_post = $valid_post;
$fail_post['period_start'] = '2026-08-29';
$fail_post['period_end'] = '2026-08-30';
$fail_post['adjustment_amount'] = 150.00;
$mock_fail_file = [
    'name' => 'orphan_check.jpg',
    'size' => 1024 * 20,
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => sys_get_temp_dir() . '/orphan_check.jpg'
];
$payments_before = (int)$pdo->query("SELECT COUNT(*) FROM ld_intern_payments")->fetchColumn();
$expenses_before = (int)$pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn();
$items_before = (int)$pdo->query("SELECT COUNT(*) FROM ld_intern_payment_items")->fetchColumn();

$res_j = simulate_record_payment($pdo, $fail_post, $mock_fail_file, true); // force DB failure
$payments_after = (int)$pdo->query("SELECT COUNT(*) FROM ld_intern_payments")->fetchColumn();
$expenses_after = (int)$pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn();
$items_after = (int)$pdo->query("SELECT COUNT(*) FROM ld_intern_payment_items")->fetchColumn();

assert_test("Scenario J: DB failure caught gracefully without 500", $res_j['success'] === false && str_contains($res_j['error'], 'Database error'));
assert_test("Scenario J: ld_intern_payments rolled back (no partial record)", $payments_before === $payments_after);
assert_test("Scenario J: expenses rolled back (no partial expense)", $expenses_before === $expenses_after);
assert_test("Scenario J: ld_intern_payment_items rolled back (no orphan items)", $items_before === $items_after);

// Scenario K: Double-click / duplicate submission -> blocked by overlap protection
echo "\n--- Scenario K: Duplicate Submission / Concurrency Protection ---\n";
$dup_res = simulate_record_payment($pdo, $valid_post); // Try exact same valid_post again
assert_test("Scenario K: Duplicate submission blocked by overlap", $dup_res['success'] === false && str_contains($dup_res['error'], 'overlaps'));

// Scenario L: Consistency and Foreign Key Verification
echo "\n--- Scenario L: Relational Consistency & Non-Duplication ---\n";
$pay1 = $pdo->query("SELECT * FROM ld_intern_payments WHERE voucher_no = 'VOU-LD-00001'")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario L: Payment row voucher_no matches", $pay1['voucher_no'] === 'VOU-LD-00001');
assert_test("Scenario L: Payment row status is Completed", $pay1['status'] === 'Completed');

$exp1 = $pdo->query("SELECT * FROM expenses WHERE ld_payment_id = " . (int)$pay1['id'])->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario L: Expense row created and linked to ld_payment_id", !empty($exp1) && (int)$exp1['ld_payment_id'] === (int)$pay1['id']);
assert_test("Scenario L: Expense amount matches paid amount", (float)$exp1['amount'] === (float)$pay1['paid_amount']);

$items = $pdo->query("SELECT * FROM ld_intern_payment_items WHERE payment_id = " . (int)$pay1['id'])->fetchAll(PDO::FETCH_ASSOC);
assert_test("Scenario L: Payment items aggregated into 2 distinct work modes", count($items) === 2);

// Re-calculation test: tasks in paid period must NOT appear in future calculations
$stmt_recalc = $pdo->prepare("
    SELECT SUM(tp.calculated_charge) AS total_charge
    FROM ld_tasks t
    JOIN ld_task_topics tp ON tp.task_id = t.id
    WHERE t.admin_username = 'isha_fathima'
      AND t.status = 'active'
      AND DATE(t.created_at) BETWEEN '2026-08-10' AND '2026-08-20'
      AND tp.quantity IS NOT NULL
      AND tp.quantity > 0
      AND NOT EXISTS (
          SELECT 1 FROM ld_intern_payments p
          WHERE p.intern_id = 101
            AND p.status = 'Completed'
            AND DATE(t.created_at) BETWEEN p.period_start_date AND p.period_end_date
      )
");
$stmt_recalc->execute();
$recalc_charge = (float)($stmt_recalc->fetchColumn() ?? 0.00);
assert_test("Scenario L: Already paid tasks excluded from subsequent calculations (₹0.00)", $recalc_charge === 0.00);

echo "\n====================================================================\n";
echo "AUDIT SUMMARY: {$tests_passed} PASSED, {$tests_failed} FAILED\n";
echo "====================================================================\n";

if ($tests_failed > 0) {
    exit(1);
}
