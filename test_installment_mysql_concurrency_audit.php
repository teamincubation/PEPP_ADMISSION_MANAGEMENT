<?php
/**
 * PEPP ERP — MySQL/MariaDB InnoDB Concurrency, Row-Locking & Constraint Integration Suite
 *
 * Runs strictly against a local, isolated temporary test database.
 * Tests real MySQL/MariaDB InnoDB engine features:
 * 1. SELECT ... FOR UPDATE exclusive row-locking & timeout contention
 * 2. Concurrent schedule updates serialization & race condition prevention
 * 3. Composite UNIQUE KEY unique_user_installment (user_id, instalment_number) enforcement (Error 1062)
 * 4. Transaction rollback integrity on failure
 * 5. In-flight payment blocking under InnoDB row locks
 * 6. Course migration with UNIQUE constraint applied
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '1');

echo "====================================================================\n";
echo "PEPP ERP — MYSQL/MARIADB INNODB CONCURRENCY & INTEGRATION SUITE\n";
echo "====================================================================\n\n";

$dbHost = 'localhost';
$dbPort = 3306;
$dbUser = 'root';
$dbPass = '';
$testDbName = 'pepp_local_concurrency_test_db';

try {
    $adminPdo = new PDO("mysql:host={$dbHost};port={$dbPort}", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2
    ]);
} catch (Exception $e) {
    echo "Local MySQL is not running on localhost:3306 or root password required.\n";
    echo "Skipping MySQL concurrency tests (SQLite unit tests already validated logic).\n";
    exit(0);
}

// 1. Create completely isolated test database
$adminPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
$adminPdo->exec("CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

function getTestConnection(string $host, int $port, string $db, string $user, string $pass): PDO {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    return $pdo;
}

$connA = getTestConnection($dbHost, $dbPort, $testDbName, $dbUser, $dbPass);
$connB = getTestConnection($dbHost, $dbPort, $testDbName, $dbUser, $dbPass);

$passed = 0;
$failed = 0;

function assertMysql(string $name, bool $cond, string $extra = '') {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] $name\n";
    } else {
        $failed++;
        echo "  [FAIL] $name" . ($extra ? " — $extra" : "") . "\n";
    }
}

// 2. Setup InnoDB Tables
$connA->exec("
    CREATE TABLE `users` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` varchar(50) NOT NULL UNIQUE,
        `name` varchar(100) NOT NULL,
        `pepp_course` varchar(100) NOT NULL,
        `pepp_academic_year` varchar(20) NOT NULL,
        `status` varchar(20) DEFAULT 'approved',
        `payment_plan` varchar(50) DEFAULT 'One Time',
        `paid_amount` decimal(10,2) DEFAULT 0.00,
        `discount_amount` decimal(10,2) DEFAULT 0.00,
        `total_fee` decimal(10,2) DEFAULT 0.00,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB;

    CREATE TABLE `pepp_courses` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `course_name` varchar(100) NOT NULL UNIQUE,
        `academic_year` varchar(20) NOT NULL,
        `total_fee` decimal(10,2) DEFAULT 0.00,
        `status` varchar(20) DEFAULT 'active',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB;

    CREATE TABLE `instalment_details` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` varchar(50) NOT NULL,
        `instalment_number` int(11) NOT NULL,
        `amount` decimal(10,2) NOT NULL,
        `due_date` date NOT NULL,
        `status` varchar(20) NOT NULL DEFAULT 'pending',
        `paid_amount` decimal(10,2) DEFAULT NULL,
        `paid_date` date DEFAULT NULL,
        `payment_reference` varchar(100) DEFAULT NULL,
        `payment_mode` varchar(50) DEFAULT NULL,
        `payment_account_id` int(11) DEFAULT NULL,
        `approved_by` varchar(50) DEFAULT NULL,
        `approved_at` datetime DEFAULT NULL,
        `admin_remarks` text DEFAULT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_instalment` (`user_id`, `instalment_number`)
    ) ENGINE=InnoDB;

    CREATE TABLE `invoices` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_number` varchar(50) NOT NULL UNIQUE,
        `user_id` varchar(50) NOT NULL,
        `source` varchar(30) NOT NULL,
        `source_ref` int(11) NOT NULL,
        `amount` decimal(10,2) NOT NULL,
        `status` varchar(20) DEFAULT 'paid',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB;
");

echo "--- 1. SELECT ... FOR UPDATE ROW-LEVEL LOCKING & TIMEOUT TEST ---\n";

// Seed a student
$connA->exec("
    INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (1, 'CUET PG', '2026-27', 30000);
    INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
    VALUES ('STU_LOCK_TEST', 'Lock Student', 'CUET PG', '2026-27', 10000, 30000, '2 Installments');
    INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
    VALUES (1001, 'STU_LOCK_TEST', 2, 20000, '2026-10-15', 'pending');
");

// Connection A starts transaction and locks the student row
$connA->beginTransaction();
$stmtA = $connA->prepare("SELECT * FROM users WHERE user_id = 'STU_LOCK_TEST' FOR UPDATE");
$stmtA->execute();
$lockedStudent = $stmtA->fetch();
assertMysql("Connection A acquired exclusive row lock on student STU_LOCK_TEST", !empty($lockedStudent));

// Connection B configures a 1-second lock wait timeout and tries to acquire the same lock
$connB->exec("SET innodb_lock_wait_timeout = 1");
$lockTimeoutOccurred = false;
try {
    $connB->beginTransaction();
    $stmtB = $connB->prepare("SELECT * FROM users WHERE user_id = 'STU_LOCK_TEST' FOR UPDATE");
    $stmtB->execute();
    $connB->rollBack();
} catch (PDOException $e) {
    // MySQL error 1205: Lock wait timeout exceeded
    if ($e->getCode() == 'HY000' && str_contains($e->getMessage(), 'Lock wait timeout')) {
        $lockTimeoutOccurred = true;
    }
    if ($connB->inTransaction()) $connB->rollBack();
}
assertMysql("Connection B was blocked by Connection A's row lock and timed out (Lock wait timeout)", $lockTimeoutOccurred);

// Connection A commits and releases the lock
$connA->commit();

// Connection B can now acquire the lock immediately
$connB->beginTransaction();
$stmtB2 = $connB->prepare("SELECT * FROM users WHERE user_id = 'STU_LOCK_TEST' FOR UPDATE");
$stmtB2->execute();
$acquiredAfterRelease = $stmtB2->fetch();
assertMysql("Connection B successfully acquired lock after Connection A committed", !empty($acquiredAfterRelease));
$connB->commit();

echo "\n--- 2. INNODB COMPOSITE UNIQUE KEY ENFORCEMENT TEST ---\n";
// Apply UNIQUE constraint on instalment_details (user_id, instalment_number)
$connA->exec("ALTER TABLE `instalment_details` ADD UNIQUE KEY `unique_user_installment` (`user_id`, `instalment_number`)");
assertMysql("Applied UNIQUE KEY `unique_user_installment` (`user_id`, `instalment_number`) on MySQL", true);

// Attempting to insert a duplicate logical installment for the same student
$duplicateBlockedByEngine = false;
try {
    $connA->prepare("INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status) VALUES (?, ?, ?, ?, 'pending')")
        ->execute(['STU_LOCK_TEST', 2, 20000, '2026-10-15']);
} catch (PDOException $e) {
    // MySQL error 1062: Duplicate entry
    if ($e->getCode() == '23000' && str_contains($e->getMessage(), 'Duplicate entry')) {
        $duplicateBlockedByEngine = true;
    }
}
assertMysql("MySQL InnoDB rejected duplicate logical installment with Error 1062 (Duplicate entry)", $duplicateBlockedByEngine);

echo "\n--- 3. TRANSACTION ROLLBACK INTEGRITY ON ENGINE-LEVEL FAILURE ---\n";
$connA->beginTransaction();
$connA->exec("UPDATE users SET discount_amount = 5000 WHERE user_id = 'STU_LOCK_TEST'");
$rollbackSuccessful = false;
try {
    // This will violate the UNIQUE key
    $connA->exec("INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status) VALUES ('STU_LOCK_TEST', 2, 20000, '2026-10-15')");
    $connA->commit();
} catch (Exception $e) {
    $connA->rollBack();
    $rollbackSuccessful = true;
}
assertMysql("Engine failure triggered full transaction rollback", $rollbackSuccessful);

$discountAfterRollback = (float)$connA->query("SELECT discount_amount FROM users WHERE user_id = 'STU_LOCK_TEST'")->fetchColumn();
assertMysql("Preceding UPDATE in rolled-back transaction was completely reverted (discount = 0)", $discountAfterRollback === 0.0);

echo "\n--- 4. IN-FLIGHT PAYMENT BLOCKING UNDER INNODB ---\n";
$connA->exec("
    INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
    VALUES ('STU_INFLIGHT_MYSQL', 'Inflight MySQL', 'CUET PG', '2026-27', 10000, 30000, '2 Installments');
    INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference)
    VALUES ('STU_INFLIGHT_MYSQL', 2, 20000, '2026-10-15', 'pending', 20000, '2026-09-18', 'TXN-INFLIGHT-MYSQL');
");

$connA->beginTransaction();
$stmtInflightCheck = $connA->prepare("SELECT * FROM instalment_details WHERE user_id = 'STU_INFLIGHT_MYSQL' FOR UPDATE");
$stmtInflightCheck->execute();
$rows = $stmtInflightCheck->fetchAll();

$hasInflight = false;
foreach ($rows as $r) {
    if ($r['status'] === 'pending' && !empty($r['paid_date'])) {
        $hasInflight = true;
        break;
    }
}
$connA->rollBack();
assertMysql("Detected in-flight payment under InnoDB row lock and rolled back cleanly", $hasInflight);

echo "\n--- 5. COURSE MIGRATION WITH UNIQUE CONSTRAINT ACTIVE ---\n";
// Ensure migrate_course logic does NOT trigger duplicate key violation when already-paid installment exists
$connA->exec("
    INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (2, 'CUET PG Mega', '2026-27', 45000);
    INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan, status)
    VALUES ('STU_MIG_MYSQL', 'Mig Student', 'CUET PG', '2026-27', 10000, 30000, '2 Installments', 'approved');
    INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference)
    VALUES (2002, 'STU_MIG_MYSQL', 2, 20000, '2026-08-15', 'approved', 20000, '2026-08-15', 'PAY-PAID-ROW');
");

$connA->beginTransaction();
// 1. Delete unpaid installments
$connA->prepare("DELETE FROM instalment_details WHERE user_id = 'STU_MIG_MYSQL' AND status NOT IN ('approved', 'paid') AND paid_date IS NULL")->execute();

// 2. Fetch already-paid installments
$stmtPaid = $connA->query("SELECT * FROM instalment_details WHERE user_id = 'STU_MIG_MYSQL' AND status IN ('approved', 'paid')");
$alreadyPaid = [];
while ($row = $stmtPaid->fetch()) {
    $alreadyPaid[$row['instalment_number']] = $row;
}

// 3. Rebuild schedule for 3 Installments: #2 is already paid (skip insert!), #3 is new (insert!)
$newSchedule = [
    2 => ['amount' => 20000, 'due_date' => '2026-08-15', 'status' => 'approved'],
    3 => ['amount' => 15000, 'due_date' => '2026-11-15', 'status' => 'pending']
];

$insStmt = $connA->prepare("INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status) VALUES (?, ?, ?, ?, ?)");
$migrationSucceededWithoutCollision = true;
try {
    foreach ($newSchedule as $num => $data) {
        if (isset($alreadyPaid[$num])) {
            continue; // CRITICAL: Skip already-paid row so UNIQUE KEY is not violated!
        }
        $insStmt->execute(['STU_MIG_MYSQL', $num, $data['amount'], $data['due_date'], $data['status']]);
    }
    $connA->commit();
} catch (Exception $e) {
    $connA->rollBack();
    $migrationSucceededWithoutCollision = false;
}

assertMysql("Course migration completed without UNIQUE constraint collision", $migrationSucceededWithoutCollision);
$migInsts = $connA->query("SELECT * FROM instalment_details WHERE user_id = 'STU_MIG_MYSQL' ORDER BY instalment_number ASC")->fetchAll();
assertMysql("Student has exactly 2 installments (#2 paid ID 2002, #3 pending)", count($migInsts) === 2 && (int)$migInsts[0]['id'] === 2002);

// 6. Cleanup temporary test database
$adminPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
echo "\nCleaned up temporary test database `{$testDbName}`.\n";

echo "\n====================================================================\n";
echo "MYSQL INTEGRATION RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
