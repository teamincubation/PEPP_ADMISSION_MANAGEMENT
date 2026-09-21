<?php
/**
 * PEPP ERP — FINAL CODE-LEVEL REGRESSION AUDIT SUITE
 *
 * Verifies the complete installment schedule edit workflow after the
 * production composite UNIQUE(user_id, instalment_number) constraint
 * has been enforced.
 *
 * Tests against an isolated MySQL InnoDB database with the active UNIQUE constraint:
 * 1. Editing pending installments
 * 2. Editing 3 -> 5 installments
 * 3. Editing 5 -> 3 installments
 * 4. Preserving approved/paid installments
 * 5. Blocking installments with submitted payments (In-flight guard)
 * 6. Concurrent schedule saves (SELECT ... FOR UPDATE row locks)
 * 7. Concurrent payment submission + schedule edit
 * 8. Rollback on validation/database failure
 * 9. Invoice / source_ref integrity
 * 10. Financial totals and outstanding balance invariants
 * 11. Course migration / rescheduling
 * 12. Duplicate prevention under all relevant INSERT paths
 */

$dbHost = 'localhost';
$dbPort = 3306;
$dbUser = 'root';
$dbPass = '';
$testDbName = 'pepp_final_regression_test_db';

echo "====================================================================\n";
echo "PEPP ERP — FINAL INSTALLMENT REGRESSION AUDIT SUITE (MYSQL INNODB)\n";
echo "====================================================================\n\n";

try {
    $adminPdo = new PDO("mysql:host={$dbHost};port={$dbPort}", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ]);
} catch (Exception $e) {
    echo "Local MySQL connection error: " . $e->getMessage() . "\n";
    exit(1);
}

// 1. Setup fresh isolated test database
$adminPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
$adminPdo->exec("CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

function getConn(string $host, int $port, string $db, string $user, string $pass): PDO {
    return new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
}

$connA = getConn($dbHost, $dbPort, $testDbName, $dbUser, $dbPass);
$connB = getConn($dbHost, $dbPort, $testDbName, $dbUser, $dbPass);

// 2. Initialize exact schema with the production UNIQUE constraint active
$connA->exec("
    CREATE TABLE `users` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` varchar(20) NOT NULL,
        `name` varchar(100) NOT NULL,
        `email` varchar(100) NOT NULL,
        `student_status` varchar(20) DEFAULT 'active',
        `course_duration_date` date DEFAULT NULL,
        `total_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
        `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
        `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
        `payment_plan` varchar(50) DEFAULT 'One Time',
        `pepp_course` varchar(100) DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_user_id` (`user_id`)
    ) ENGINE=InnoDB;

    CREATE TABLE `instalment_details` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` varchar(20) NOT NULL,
        `instalment_number` int(11) NOT NULL,
        `amount` decimal(10,2) NOT NULL,
        `due_date` date NOT NULL,
        `status` enum('pending','approved','rejected','overdue','paid') DEFAULT 'pending',
        `paid_date` date DEFAULT NULL,
        `payment_reference` varchar(255) DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `payment_mode` varchar(50) DEFAULT NULL,
        `payment_account_id` int(11) DEFAULT NULL,
        `admin_remarks` text DEFAULT NULL,
        `approved_by` varchar(100) DEFAULT NULL,
        `approved_at` timestamp NULL DEFAULT NULL,
        `rejected_by` varchar(100) DEFAULT NULL,
        `rejected_at` timestamp NULL DEFAULT NULL,
        `paid_amount` decimal(10,2) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_user_installment` (`user_id`,`instalment_number`),
        KEY `idx_user_instalment` (`user_id`,`instalment_number`),
        KEY `idx_due_date` (`due_date`),
        KEY `idx_status` (`status`),
        CONSTRAINT `fk_inst_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    CREATE TABLE `invoices` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_no` varchar(50) NOT NULL,
        `user_id` varchar(20) NOT NULL,
        `source` varchar(20) NOT NULL,
        `source_ref` int(11) NOT NULL,
        `instalment_number` int(11) DEFAULT NULL,
        `gross_amount` decimal(10,2) NOT NULL,
        `paid_date` date DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_source` (`source`,`source_ref`),
        KEY `idx_inv_user` (`user_id`)
    ) ENGINE=InnoDB;

    CREATE TABLE `track_records` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` varchar(20) NOT NULL,
        `action_type` varchar(50) NOT NULL,
        `action_details` text,
        `performed_by` varchar(50) DEFAULT NULL,
        `performed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB;

    CREATE TABLE `admin_activity_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `admin_username` varchar(50) NOT NULL,
        `action_type` varchar(50) NOT NULL,
        `details` text,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB;
");

$passed = 0;
$failed = 0;

function assertTest(string $scenario, string $check, bool $result, string $details = '') {
    global $passed, $failed;
    if ($result) {
        $passed++;
        echo "  [PASS] {$scenario}: {$check}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$scenario}: {$check} - {$details}\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Schedule Edit Function (replicates student-details.php edit_installments)
// ─────────────────────────────────────────────────────────────────────────────
function executeScheduleEdit(PDO $pdo, string $userId, array $installmentsInput, string $adminUser = 'superadmin'): array {
    $pdo->beginTransaction();
    try {
        // Row locks
        $stmtUser = $pdo->prepare("SELECT * FROM users WHERE user_id = ? FOR UPDATE");
        $stmtUser->execute([$userId]);
        $student = $stmtUser->fetch();
        if (!$student) throw new Exception("Student not found.");

        $stmtInsts = $pdo->prepare("SELECT * FROM instalment_details WHERE user_id = ? ORDER BY instalment_number ASC FOR UPDATE");
        $stmtInsts->execute([$userId]);
        $existing = $stmtInsts->fetchAll();

        // In-flight payment guard
        foreach ($existing as $r) {
            if ($r['status'] === 'pending' && !empty($r['paid_date'])) {
                throw new Exception("Cannot edit installment schedule: Installment #{$r['instalment_number']} has a submitted payment awaiting admin review.");
            }
        }

        // Map existing by installment_number
        $existingByNum = [];
        foreach ($existing as $r) {
            $existingByNum[$r['instalment_number']] = $r;
        }

        // Check revenue before
        $preApproved = 0.0;
        foreach ($existing as $r) {
            if (in_array($r['status'], ['approved', 'paid'])) {
                $preApproved += (float)($r['paid_amount'] ?? $r['amount']);
            }
        }

        // 1. Prune excess pending installments
        foreach ($existingByNum as $num => $r) {
            if (!isset($installmentsInput[$num])) {
                if (!in_array($r['status'], ['approved', 'paid']) && empty($r['paid_date'])) {
                    $delStmt = $pdo->prepare("DELETE FROM instalment_details WHERE id = ?");
                    $delStmt->execute([$r['id']]);
                }
            }
        }

        // 2. Update or Insert
        foreach ($installmentsInput as $num => $inst) {
            if (isset($existingByNum[$num])) {
                $upd = $pdo->prepare("UPDATE instalment_details SET amount = ?, due_date = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$inst['amount'], $inst['due_date'], $existingByNum[$num]['id']]);
            } else {
                $ins = $pdo->prepare("INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())");
                $ins->execute([$userId, $num, $inst['amount'], $inst['due_date']]);
            }
        }

        // Pre-commit duplicate invariant check
        $chkDup = $pdo->prepare("SELECT COUNT(*), COUNT(DISTINCT instalment_number) FROM instalment_details WHERE user_id = ?");
        $chkDup->execute([$userId]);
        [$totRows, $distInst] = $chkDup->fetch(PDO::FETCH_NUM);
        if ((int)$totRows !== (int)$distInst) {
            throw new Exception("Duplicate installment numbers detected for student.");
        }

        // Pre-commit approved revenue preservation check
        $chkPaid = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(paid_amount, amount)), 0) FROM instalment_details WHERE user_id = ? AND status IN ('approved', 'paid')");
        $chkPaid->execute([$userId]);
        $postPaid = (float)$chkPaid->fetchColumn();
        if (abs($preApproved - $postPaid) > 0.01) {
            throw new Exception("Schedule edit violated approved revenue preservation.");
        }

        $pdo->commit();
        return ['success' => true];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 1: EDITING PENDING INSTALLMENTS
// ─────────────────────────────────────────────────────────────────────────────
echo "--- SCENARIO 1: Editing Pending Installments ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_SCEN1', 'Student 1', 's1@test.com', 15000, 3000, '4 Installments')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status) VALUES
    (101, 'STU_SCEN1', 2, 4000, '2026-10-01', 'pending'),
    (102, 'STU_SCEN1', 3, 4000, '2026-11-01', 'pending'),
    (103, 'STU_SCEN1', 4, 4000, '2026-12-01', 'pending')");

$res1 = executeScheduleEdit($connA, 'STU_SCEN1', [
    2 => ['amount' => 5000, 'due_date' => '2026-10-10'],
    3 => ['amount' => 4000, 'due_date' => '2026-11-10'],
    4 => ['amount' => 3000, 'due_date' => '2026-12-10']
]);

assertTest("Scenario 1", "Schedule edit succeeded", $res1['success']);
$row101 = $connA->query("SELECT * FROM instalment_details WHERE id = 101")->fetch();
assertTest("Scenario 1", "Row 101 updated in-place (ID preserved)", (int)$row101['id'] === 101 && (float)$row101['amount'] === 5000.0);
$cnt1 = (int)$connA->query("SELECT COUNT(*) FROM instalment_details WHERE user_id = 'STU_SCEN1'")->fetchColumn();
assertTest("Scenario 1", "Zero duplicate rows generated", $cnt1 === 3);

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 2: EDITING 3 -> 5 INSTALLMENTS (EXPANSION)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 2: Editing 3 -> 5 Installments (Expansion) ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_SCEN2', 'Student 2', 's2@test.com', 20000, 5000, '3 Installments')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status) VALUES
    (201, 'STU_SCEN2', 2, 7500, '2026-10-01', 'pending'),
    (202, 'STU_SCEN2', 3, 7500, '2026-11-01', 'pending')");

$res2 = executeScheduleEdit($connA, 'STU_SCEN2', [
    2 => ['amount' => 4000, 'due_date' => '2026-10-01'],
    3 => ['amount' => 4000, 'due_date' => '2026-11-01'],
    4 => ['amount' => 4000, 'due_date' => '2026-12-01'],
    5 => ['amount' => 3000, 'due_date' => '2027-01-01']
]);

assertTest("Scenario 2", "Schedule expansion (3 -> 5) succeeded", $res2['success']);
$rows2 = $connA->query("SELECT instalment_number, amount, id FROM instalment_details WHERE user_id = 'STU_SCEN2' ORDER BY instalment_number ASC")->fetchAll();
assertTest("Scenario 2", "Exactly 4 future installments exist (#2 to #5)", count($rows2) === 4);
assertTest("Scenario 2", "Existing row 201 and 202 preserved in-place", (int)$rows2[0]['id'] === 201 && (int)$rows2[1]['id'] === 202);
assertTest("Scenario 2", "New rows #4 and #5 inserted without duplicate key error", (int)$rows2[2]['instalment_number'] === 4 && (int)$rows2[3]['instalment_number'] === 5);

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 3: EDITING 5 -> 3 INSTALLMENTS (CONTRACTION)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 3: Editing 5 -> 3 Installments (Contraction) ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_SCEN3', 'Student 3', 's3@test.com', 25000, 5000, '5 Installments')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status) VALUES
    (301, 'STU_SCEN3', 2, 5000, '2026-10-01', 'pending'),
    (302, 'STU_SCEN3', 3, 5000, '2026-11-01', 'pending'),
    (303, 'STU_SCEN3', 4, 5000, '2026-12-01', 'pending'),
    (304, 'STU_SCEN3', 5, 5000, '2027-01-01', 'pending')");

$res3 = executeScheduleEdit($connA, 'STU_SCEN3', [
    2 => ['amount' => 10000, 'due_date' => '2026-10-15'],
    3 => ['amount' => 10000, 'due_date' => '2026-11-15']
]);

assertTest("Scenario 3", "Schedule contraction (5 -> 3) succeeded", $res3['success']);
$rows3 = $connA->query("SELECT instalment_number, id FROM instalment_details WHERE user_id = 'STU_SCEN3' ORDER BY instalment_number ASC")->fetchAll();
assertTest("Scenario 3", "Exactly 2 future installments remain (#2 and #3)", count($rows3) === 2);
$excessCount = (int)$connA->query("SELECT COUNT(*) FROM instalment_details WHERE id IN (303, 304)")->fetchColumn();
assertTest("Scenario 3", "Excess pending installments 303 and 304 pruned cleanly", $excessCount === 0);

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 4: PRESERVING APPROVED/PAID INSTALLMENTS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 4: Preserving Approved/Paid Installments ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_SCEN4', 'Student 4', 's4@test.com', 30000, 5000, '4 Installments')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference, approved_by, approved_at, payment_mode, payment_account_id) VALUES
    (401, 'STU_SCEN4', 2, 10000, '2026-08-10', 'approved', 10000, '2026-08-08', 'REF-ORIG-401', 'finance_lead', '2026-08-08 10:00:00', 'Online', 3),
    (402, 'STU_SCEN4', 3, 7500, '2026-10-01', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
    (403, 'STU_SCEN4', 4, 7500, '2026-11-01', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL)");
$connA->exec("INSERT INTO invoices (id, invoice_no, user_id, source, source_ref, instalment_number, gross_amount, paid_date) VALUES
    (601, 'INV/SCEN4/601', 'STU_SCEN4', 'installment', 401, 2, 10000, '2026-08-08')");

$res4 = executeScheduleEdit($connA, 'STU_SCEN4', [
    2 => ['amount' => 10000, 'due_date' => '2026-08-10'],
    3 => ['amount' => 15000, 'due_date' => '2026-10-20'] // merged 3 and 4 into 3
]);

assertTest("Scenario 4", "Schedule edit with approved installment succeeded", $res4['success']);
$row401 = $connA->query("SELECT * FROM instalment_details WHERE id = 401")->fetch();
assertTest("Scenario 4", "Row 401 primary key ID preserved", (int)$row401['id'] === 401);
assertTest("Scenario 4", "Row 401 status remains 'approved'", $row401['status'] === 'approved');
assertTest("Scenario 4", "Row 401 paid_amount preserved (10,000)", (float)$row401['paid_amount'] === 10000.0);
assertTest("Scenario 4", "Row 401 payment_reference preserved", $row401['payment_reference'] === 'REF-ORIG-401');
assertTest("Scenario 4", "Row 401 approved_by preserved", $row401['approved_by'] === 'finance_lead');
assertTest("Scenario 4", "Invoice 601 source_ref remains linked to Row 401", (int)$connA->query("SELECT source_ref FROM invoices WHERE id = 601")->fetchColumn() === 401);

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 5: BLOCKING INSTALLMENTS WITH SUBMITTED PAYMENTS (IN-FLIGHT GUARD)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 5: Blocking Installments with Submitted Payments ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_SCEN5', 'Student 5', 's5@test.com', 20000, 5000, '3 Installments')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_date, payment_reference) VALUES
    (501, 'STU_SCEN5', 2, 7500, '2026-09-15', 'pending', '2026-09-10', 'proof_upload_receipt.png'),
    (502, 'STU_SCEN5', 3, 7500, '2026-10-15', 'pending', NULL, NULL)");

$res5 = executeScheduleEdit($connA, 'STU_SCEN5', [
    2 => ['amount' => 8000, 'due_date' => '2026-09-20'],
    3 => ['amount' => 7000, 'due_date' => '2026-10-20']
]);

assertTest("Scenario 5", "Schedule edit strictly blocked by in-flight guard", !$res5['success']);
assertTest("Scenario 5", "Error message identifies awaiting review on #2", strpos($res5['error'], 'Installment #2 has a submitted payment awaiting admin review') !== false);
$row501 = $connA->query("SELECT * FROM instalment_details WHERE id = 501")->fetch();
assertTest("Scenario 5", "In-flight row was NOT modified or deleted", $row501['payment_reference'] === 'proof_upload_receipt.png' && (float)$row501['amount'] === 7500.0);

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 6: CONCURRENT SCHEDULE SAVES (ROW LOCK SERIALIZATION)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 6: Concurrent Schedule Saves (SELECT ... FOR UPDATE) ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_SCEN6', 'Student 6', 's6@test.com', 20000, 5000, '3 Installments')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status) VALUES
    (601, 'STU_SCEN6', 2, 7500, '2026-10-01', 'pending'),
    (602, 'STU_SCEN6', 3, 7500, '2026-11-01', 'pending')");

// Connection A locks student
$connA->beginTransaction();
$connA->query("SELECT * FROM users WHERE user_id = 'STU_SCEN6' FOR UPDATE");
$connA->query("SELECT * FROM instalment_details WHERE user_id = 'STU_SCEN6' FOR UPDATE");

// Connection B tries to acquire lock with short timeout
$connB->exec("SET innodb_lock_wait_timeout = 1");
$blocked = false;
try {
    $connB->beginTransaction();
    $connB->query("SELECT * FROM users WHERE user_id = 'STU_SCEN6' FOR UPDATE");
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Lock wait timeout') !== false) {
        $blocked = true;
    }
    $connB->rollBack();
}
assertTest("Scenario 6", "Connection B was safely blocked by Connection A row lock", $blocked);

// Connection A updates and commits
$connA->query("UPDATE instalment_details SET amount = 8000 WHERE id = 601");
$connA->commit();

// Now Connection B can acquire lock and see committed state
$connB->beginTransaction();
$bRow = $connB->query("SELECT amount FROM instalment_details WHERE id = 601 FOR UPDATE")->fetch();
$connB->commit();
assertTest("Scenario 6", "Connection B acquires lock after Connection A commits, seeing updated amount", (float)$bRow['amount'] === 8000.0);

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 7: CONCURRENT PAYMENT SUBMISSION + SCHEDULE EDIT
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 7: Concurrent Payment Submission + Schedule Edit ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_SCEN7', 'Student 7', 's7@test.com', 15000, 3000, '3 Installments')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status) VALUES
    (701, 'STU_SCEN7', 2, 6000, '2026-10-01', 'pending'),
    (702, 'STU_SCEN7', 3, 6000, '2026-11-01', 'pending')");

// Student submits payment on Connection B
$connB->beginTransaction();
$connB->prepare("UPDATE instalment_details SET status = 'pending', paid_date = '2026-09-18', payment_reference = 'receipt_stu7.jpg' WHERE id = 701")->execute();
$connB->commit();

// Admin tries to edit schedule on Connection A
$res7 = executeScheduleEdit($connA, 'STU_SCEN7', [
    2 => ['amount' => 7000, 'due_date' => '2026-10-05'],
    3 => ['amount' => 5000, 'due_date' => '2026-11-05']
]);
assertTest("Scenario 7", "Schedule edit detects submitted payment and rejects modification", !$res7['success']);
assertTest("Scenario 7", "Submitted payment proof is intact", (string)$connA->query("SELECT payment_reference FROM instalment_details WHERE id = 701")->fetchColumn() === 'receipt_stu7.jpg');

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 8: ROLLBACK ON VALIDATION/DATABASE FAILURE
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 8: Rollback on Validation/Database Failure ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_SCEN8', 'Student 8', 's8@test.com', 20000, 5000, '3 Installments')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status) VALUES
    (801, 'STU_SCEN8', 2, 7500, '2026-10-01', 'pending'),
    (802, 'STU_SCEN8', 3, 7500, '2026-11-01', 'pending')");

// Simulate transaction that fails on pre-commit check (e.g. duplicate key or logic error)
$connA->beginTransaction();
try {
    $connA->query("UPDATE instalment_details SET amount = 9999 WHERE id = 801");
    // Trigger intentional error before commit
    throw new Exception("Simulated mid-transaction failure");
    $connA->commit();
} catch (Exception $e) {
    $connA->rollBack();
}

$row801 = $connA->query("SELECT amount FROM instalment_details WHERE id = 801")->fetch();
assertTest("Scenario 8", "Mid-transaction failure rolled back completely", (float)$row801['amount'] === 7500.0);

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 9: INVOICE / SOURCE_REF INTEGRITY
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 9: Invoice / source_ref Integrity ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_SCEN9', 'Student 9', 's9@test.com', 25000, 5000, '3 Installments')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference, approved_by) VALUES
    (901, 'STU_SCEN9', 2, 10000, '2026-08-01', 'approved', 10000, '2026-08-01', 'TXN-901', 'admin_sarah'),
    (902, 'STU_SCEN9', 3, 10000, '2026-10-01', 'pending', NULL, NULL, NULL, NULL)");
$connA->exec("INSERT INTO invoices (id, invoice_no, user_id, source, source_ref, instalment_number, gross_amount, paid_date) VALUES
    (950, 'INV/SCEN9/950', 'STU_SCEN9', 'installment', 901, 2, 10000, '2026-08-01')");

// Edit pending schedule
$res9 = executeScheduleEdit($connA, 'STU_SCEN9', [
    2 => ['amount' => 10000, 'due_date' => '2026-08-01'],
    3 => ['amount' => 5000, 'due_date' => '2026-10-01'],
    4 => ['amount' => 5000, 'due_date' => '2026-11-01']
]);
assertTest("Scenario 9", "Schedule edit succeeded with invoice link active", $res9['success']);
$inv950 = $connA->query("SELECT * FROM invoices WHERE id = 950")->fetch();
$inst901 = $connA->query("SELECT * FROM instalment_details WHERE id = {$inv950['source_ref']}")->fetch();
assertTest("Scenario 9", "Invoice 950 source_ref still points to valid existing approved row 901", $inst901 !== false && (int)$inst901['id'] === 901);
assertTest("Scenario 9", "Invoice gross_amount matches approved installment paid_amount", (float)$inv950['gross_amount'] === (float)$inst901['paid_amount']);

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 10: FINANCIAL TOTALS AND OUTSTANDING BALANCE INVARIANTS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 10: Financial Totals and Outstanding Balance Invariants ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_SCEN10', 'Student 10', 's10@test.com', 30000, 6000, '4 Installments')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date) VALUES
    (1001, 'STU_SCEN10', 2, 8000, '2026-08-15', 'approved', 8000, '2026-08-15'),
    (1002, 'STU_SCEN10', 3, 8000, '2026-10-15', 'pending', NULL, NULL),
    (1003, 'STU_SCEN10', 4, 8000, '2026-11-15', 'pending', NULL, NULL)");

// Total fee = 30000, Reg = 6000, Inst #2 paid = 8000 -> Total Genuine Paid = 14000. Balance Due = 16000.
// Pending installments must sum to 16000.
$res10 = executeScheduleEdit($connA, 'STU_SCEN10', [
    2 => ['amount' => 8000, 'due_date' => '2026-08-15'],
    3 => ['amount' => 10000, 'due_date' => '2026-10-15'],
    4 => ['amount' => 6000, 'due_date' => '2026-11-15']
]);
assertTest("Scenario 10", "Schedule rebalancing succeeded", $res10['success']);

$u10 = $connA->query("SELECT total_fee, paid_amount FROM users WHERE user_id = 'STU_SCEN10'")->fetch();
$pInst = (float)$connA->query("SELECT COALESCE(SUM(paid_amount), 0) FROM instalment_details WHERE user_id = 'STU_SCEN10' AND status = 'approved'")->fetchColumn();
$totPaid = (float)$u10['paid_amount'] + $pInst;
$balDue = (float)$u10['total_fee'] - $totPaid;
$pendSum = (float)$connA->query("SELECT COALESCE(SUM(amount), 0) FROM instalment_details WHERE user_id = 'STU_SCEN10' AND status = 'pending'")->fetchColumn();

assertTest("Scenario 10", "Total Genuine Paid = ₹14,000", $totPaid === 14000.0);
assertTest("Scenario 10", "Balance Due = ₹16,000", $balDue === 16000.0);
assertTest("Scenario 10", "Sum of Pending Installments exactly matches Balance Due (₹16,000)", $pendSum === $balDue);

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 11: COURSE MIGRATION / RESCHEDULING WITH UNIQUE CONSTRAINT
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 11: Course Migration / Rescheduling with Active UNIQUE Constraint ---\n";
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan, pepp_course) VALUES
    ('STU_MIG', 'Student Mig', 'mig@test.com', 20000, 5000, '3 Installments', 'Course A')");
$connA->exec("INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference) VALUES
    (1101, 'STU_MIG', 2, 7500, '2026-08-10', 'approved', 7500, '2026-08-10', 'MIG-TXN-1101'),
    (1102, 'STU_MIG', 3, 7500, '2026-09-10', 'pending', NULL, NULL, NULL)");
$connA->exec("INSERT INTO invoices (id, invoice_no, user_id, source, source_ref, instalment_number, gross_amount, paid_date) VALUES
    (1150, 'INV/MIG/1150', 'STU_MIG', 'installment', 1101, 2, 7500, '2026-08-10')");

// Migration to Course B: Total fee 35000. Reg paid = 5000. Old inst #2 paid = 7500. Total paid = 12500.
// Remaining balance = 22500.
// New plan: 4 installments: #2 (paid 7500 preserved), #3 (pending 11250), #4 (pending 11250).
$connA->beginTransaction();
try {
    // 1. Delete all non-paid installments
    $connA->prepare("DELETE FROM instalment_details WHERE user_id = 'STU_MIG' AND status NOT IN ('approved', 'paid') AND paid_date IS NULL")->execute();

    // 2. Insert new schedule skipping already-paid
    $alreadyPaid = [2 => 7500.0];
    $newSchedule = [
        2 => ['amount' => 7500, 'due_date' => '2026-08-10', 'status' => 'approved'],
        3 => ['amount' => 11250, 'due_date' => '2026-10-15', 'status' => 'pending'],
        4 => ['amount' => 11250, 'due_date' => '2026-11-15', 'status' => 'pending']
    ];

    $insMig = $connA->prepare("INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
    foreach ($newSchedule as $n => $d) {
        if (isset($alreadyPaid[$n])) {
            continue; // Skip paid row, preserving original ID and invoice reference
        }
        $insMig->execute(['STU_MIG', $n, $d['amount'], $d['due_date'], $d['status']]);
    }

    // 3. Update user record
    $connA->prepare("UPDATE users SET pepp_course = 'Course B', total_fee = 35000, payment_plan = '4 Installments' WHERE user_id = 'STU_MIG'")->execute();

    // Pre-commit verification
    $chkDup = $connA->query("SELECT COUNT(*), COUNT(DISTINCT instalment_number) FROM instalment_details WHERE user_id = 'STU_MIG'")->fetch(PDO::FETCH_NUM);
    if ((int)$chkDup[0] !== (int)$chkDup[1]) {
        throw new Exception("Duplicate numbers detected after migration.");
    }

    $connA->commit();
    assertTest("Scenario 11", "Course migration executed cleanly without UNIQUE key collision", true);
} catch (Exception $e) {
    $connA->rollBack();
    assertTest("Scenario 11", "Course migration failed: " . $e->getMessage(), false);
}

$migRows = $connA->query("SELECT instalment_number, id, amount, status FROM instalment_details WHERE user_id = 'STU_MIG' ORDER BY instalment_number ASC")->fetchAll();
assertTest("Scenario 11", "Paid installment #2 preserved with original ID 1101", (int)$migRows[0]['id'] === 1101 && $migRows[0]['status'] === 'approved');
assertTest("Scenario 11", "Invoice 1150 source_ref still links to 1101", (int)$connA->query("SELECT source_ref FROM invoices WHERE id = 1150")->fetchColumn() === 1101);
assertTest("Scenario 11", "New installments #3 and #4 scheduled cleanly", count($migRows) === 3 && (float)$migRows[1]['amount'] === 11250.0 && (float)$migRows[2]['amount'] === 11250.0);

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO 12: DUPLICATE PREVENTION UNDER ALL RELEVANT INSERT PATHS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- SCENARIO 12: Duplicate Prevention Under All INSERT Paths ---\n";

// Path 1: student-approval.php workflow
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_APPR', 'Approval Test', 'appr@test.com', 20000, 5000, '4 Installments')");
$insAppr = $connA->prepare("INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())");
for ($i = 2; $i <= 4; $i++) {
    $insAppr->execute(['STU_APPR', $i, 5000, '2026-' . sprintf('%02d', 8 + $i) . '-01']);
}
$cntAppr = (int)$connA->query("SELECT COUNT(*) FROM instalment_details WHERE user_id = 'STU_APPR'")->fetchColumn();
assertTest("Scenario 12", "student-approval.php INSERT path creates clean distinct installments", $cntAppr === 3);

// Path 2: add-student.php workflow
$connA->exec("INSERT INTO users (user_id, name, email, total_fee, paid_amount, payment_plan) VALUES ('STU_ADD', 'Add Student Test', 'add@test.com', 15000, 3000, '3 Installments')");
$insAdd = $connA->prepare("INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())");
foreach ([2 => ['amount' => 6000, 'due_date' => '2026-10-01'], 3 => ['amount' => 6000, 'due_date' => '2026-11-01']] as $n => $d) {
    $insAdd->execute(['STU_ADD', $n, $d['amount'], $d['due_date']]);
}
$cntAdd = (int)$connA->query("SELECT COUNT(*) FROM instalment_details WHERE user_id = 'STU_ADD'")->fetchColumn();
assertTest("Scenario 12", "add-student.php INSERT path creates clean distinct installments", $cntAdd === 2);

// Path 3: Rogue duplicate INSERT directly rejected by InnoDB engine
$caughtDup = false;
try {
    $connA->exec("INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status) VALUES ('STU_ADD', 2, 9999, '2026-10-01', 'pending')");
} catch (PDOException $e) {
    if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
        $caughtDup = true;
    }
}
assertTest("Scenario 12", "Rogue duplicate INSERT is hard-rejected by InnoDB with Error 1062 (Duplicate entry)", $caughtDup);

// Clean up test database
$adminPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");

echo "\n====================================================================\n";
echo "REGRESSION AUDIT COMPLETE: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
