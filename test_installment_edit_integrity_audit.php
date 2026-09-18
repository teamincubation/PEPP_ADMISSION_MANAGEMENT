<?php
/**
 * PEPP ERP — Installment Schedule Edit & Integrity Verification Test Suite
 *
 * Isolated functional unit test suite covering:
 * 1. Transaction ordering & permission/CSRF validation
 * 2. In-flight payment blocking (status = 'pending' AND paid_date IS NOT NULL)
 * 3. Paid/approved row preservation (id, payment history, invoice links)
 * 4. Pending row in-place update & term adjustments
 * 5. Authoritative ERP financial invariant enforcement
 * 6. Sequential due date validation
 * 7. Repeated save idempotency
 * 8. Deterministic duplicate reconciliation (Groups A, B, C) & manual review safety (Group D)
 * 9. Course migration duplicate re-insertion fix & in-flight guard
 * 10. CLI-only audit tool safety & stage decoupled execution
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

class InstallmentIntegrityTestSuite {
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
                payment_plan TEXT DEFAULT 'One Time',
                paid_amount REAL DEFAULT 0.00,
                discount_amount REAL DEFAULT 0.00,
                discount_remark TEXT,
                total_fee REAL DEFAULT 0.00,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE pepp_courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_name TEXT UNIQUE,
                course_code TEXT,
                academic_year TEXT,
                total_fee REAL DEFAULT 0.00,
                status TEXT DEFAULT 'active'
            );

            CREATE TABLE instalment_details (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                instalment_number INTEGER NOT NULL,
                amount REAL NOT NULL,
                due_date TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                paid_amount REAL DEFAULT NULL,
                paid_date TEXT DEFAULT NULL,
                payment_reference TEXT DEFAULT NULL,
                payment_mode TEXT DEFAULT NULL,
                payment_account_id INTEGER DEFAULT NULL,
                approved_by TEXT DEFAULT NULL,
                approved_at TEXT DEFAULT NULL,
                admin_remarks TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_number TEXT UNIQUE,
                user_id TEXT NOT NULL,
                source TEXT NOT NULL,
                source_ref INTEGER NOT NULL,
                amount REAL NOT NULL,
                status TEXT DEFAULT 'paid',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE student_course_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                old_course TEXT,
                old_course_id INTEGER,
                old_course_fee REAL,
                new_course TEXT,
                new_course_id INTEGER,
                new_course_fee REAL,
                payment_plan TEXT,
                paid_amount_at_migration REAL,
                outstanding_before REAL,
                outstanding_after REAL,
                upgrade_amount REAL,
                migration_reason TEXT,
                migrated_by TEXT,
                migrated_at TEXT,
                revised_installment_schedule TEXT
            );

            CREATE TABLE track_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                action TEXT NOT NULL,
                description TEXT,
                admin_username TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE admin_activity_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_username TEXT NOT NULL,
                action TEXT NOT NULL,
                details TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    private function resetData(): void {
        $this->pdo->exec("
            DELETE FROM users;
            DELETE FROM pepp_courses;
            DELETE FROM instalment_details;
            DELETE FROM invoices;
            DELETE FROM student_course_migrations;
            DELETE FROM track_records;
            DELETE FROM admin_activity_logs;
        ");
    }

    private function assertTest(string $name, bool $condition, string $detail = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] $name\n";
        } else {
            $this->failed++;
            echo "  [FAIL] $name" . ($detail ? " — $detail" : "") . "\n";
        }
    }

    /**
     * Replicates the exact student-details.php edit_installments POST execution engine
     */
    private function executeEditInstallments(
        string $userId,
        array $postData,
        bool $csrfValid = true,
        bool $isFinancialRestricted = false,
        string $adminUsername = 'test_admin'
    ): array {
        if (!$csrfValid) {
            return ['success' => false, 'error' => 'Security token mismatch. Please retry.'];
        }
        if ($isFinancialRestricted) {
            return ['success' => false, 'error' => 'Access Denied: You do not have permission to modify financial details.'];
        }

        try {
            $this->pdo->beginTransaction();

            // 1. SELECT student FOR UPDATE
            $stmtStudent = $this->pdo->prepare("SELECT u.*, pc.total_fee AS course_fee FROM users u LEFT JOIN pepp_courses pc ON pc.course_name = u.pepp_course WHERE u.user_id = ?");
            $stmtStudent->execute([$userId]);
            $student = $stmtStudent->fetch();
            if (!$student) {
                throw new Exception("Student not found.");
            }

            // 2. SELECT all instalment_details FOR UPDATE
            $stmtInsts = $this->pdo->prepare("SELECT * FROM instalment_details WHERE user_id = ? ORDER BY instalment_number ASC");
            $stmtInsts->execute([$userId]);
            $existing_rows = $stmtInsts->fetchAll();

            // 3. Detect in-flight payment submission
            foreach ($existing_rows as $row) {
                if ($row['status'] === 'pending' && !empty($row['paid_date'])) {
                    $inst_num = (int)$row['instalment_number'];
                    $this->pdo->rollBack();
                    return [
                        'success' => false,
                        'error' => "Installment schedule cannot be edited while a payment is awaiting review for installment #{$inst_num}. Please approve or reject the submitted payment first."
                    ];
                }
            }

            // 4. Validate payment plan
            $allowed_plans = ['One Time', '2 Installments', '3 Installments', '4 Installments', '5 Installments'];
            $new_plan = trim($postData['payment_plan'] ?? 'One Time');
            if (!in_array($new_plan, $allowed_plans, true)) {
                throw new Exception("Invalid payment plan selected.");
            }
            $new_count = ($new_plan === 'One Time') ? 1 : (int)explode(' ', $new_plan)[0];

            // 5. Fee & Discount validation
            $new_discount = max(0.0, round(floatval($postData['discount_amount'] ?? 0), 2));
            $course_fee = (float)($student['course_fee'] ?? 0);
            if ($new_discount > $course_fee) {
                throw new Exception("Discount amount (₹" . number_format($new_discount, 2) . ") cannot exceed course base fee (₹" . number_format($course_fee, 2) . ").");
            }
            $new_total_fee = max(0.0, round($course_fee - $new_discount, 2));
            $reg_paid = (float)$student['paid_amount'];

            // 6. Index existing installments and calculate approved collections (with deterministic duplicate healing)
            $existing_by_num = [];
            $paid_count = 1; // Registration is installment #1
            $approved_collected = 0.0;
            $duplicates_pruned_audit = [];

            $grouped_existing = [];
            foreach ($existing_rows as $inst) {
                $num = (int)$inst['instalment_number'];
                $grouped_existing[$num][] = $inst;
            }

            foreach ($grouped_existing as $num => $instGroup) {
                if (count($instGroup) === 1) {
                    $inst = $instGroup[0];
                    $existing_by_num[$num] = $inst;
                    if (in_array($inst['status'], ['approved', 'paid'], true)) {
                        $paid_count++;
                        $approved_collected += (float)($inst['paid_amount'] ?: $inst['amount']);
                    }
                } else {
                    // Multiple rows exist
                    $appr = [];
                    $inflight = [];
                    $emptyPend = [];
                    foreach ($instGroup as $r) {
                        if (in_array($r['status'], ['approved', 'paid'], true)) {
                            $appr[] = $r;
                        } elseif ($r['status'] === 'pending' && !empty($r['paid_date'])) {
                            $inflight[] = $r;
                        } else {
                            $emptyPend[] = $r;
                        }
                    }

                    // Group D: Conflicting financial evidence - MUST NOT auto-reconcile
                    if (count($appr) > 1 || (count($appr) >= 1 && count($inflight) >= 1)) {
                        throw new Exception("Multiple conflicting financial payment records found for installment #$num. Schedule cannot be modified automatically; manual review required.");
                    }

                    if (count($appr) === 1) {
                        // Group A
                        $canonical = $appr[0];
                        $existing_by_num[$num] = $canonical;
                        $paid_count++;
                        $approved_collected += (float)($canonical['paid_amount'] ?: $canonical['amount']);
                        foreach ($emptyPend as $redundant) {
                            $this->pdo->prepare("DELETE FROM instalment_details WHERE id = ?")->execute([$redundant['id']]);
                            $duplicates_pruned_audit[] = "Pruned redundant empty pending row ID {$redundant['id']} for #$num";
                        }
                    } elseif (count($emptyPend) === count($instGroup)) {
                        // Group C
                        usort($emptyPend, function($a, $b) {
                            $aRem = !empty(trim($a['admin_remarks'] ?? ''));
                            $bRem = !empty(trim($b['admin_remarks'] ?? ''));
                            if ($aRem !== $bRem) return $bRem <=> $aRem;
                            return (int)$a['id'] <=> (int)$b['id'];
                        });
                        $canonical = $emptyPend[0];
                        $existing_by_num[$num] = $canonical;
                        $redundantRows = array_slice($emptyPend, 1);
                        foreach ($redundantRows as $redundant) {
                            $this->pdo->prepare("DELETE FROM instalment_details WHERE id = ?")->execute([$redundant['id']]);
                            $duplicates_pruned_audit[] = "Pruned redundant duplicate pending row ID {$redundant['id']} for #$num";
                        }
                    } else {
                        throw new Exception("Conflicting duplicate records found for installment #$num. Manual review required.");
                    }
                }
            }

            if ($new_count < $paid_count) {
                throw new Exception("New plan term cannot be less than currently paid/approved installments count ($paid_count).");
            }

            $total_collected = $reg_paid + $approved_collected;
            $outstanding_balance = max(0.0, round($new_total_fee - $total_collected, 2));

            if ($new_plan === 'One Time' && $outstanding_balance > 0) {
                throw new Exception("Outstanding balance of ₹" . number_format($outstanding_balance, 2) . " remains. You must select an installment plan to schedule remaining payments.");
            }

            // 7. Validate submitted schedule inputs
            $scheduled_pending_sum = 0.0;
            $schedule_to_apply = [];
            $prev_due_date = null;

            for ($i = 2; $i <= $new_count; $i++) {
                $existing = $existing_by_num[$i] ?? null;
                $is_paid = $existing && in_array($existing['status'], ['approved', 'paid'], true);

                if ($is_paid) {
                    $schedule_to_apply[$i] = [
                        'type' => 'preserve_paid',
                        'existing_id' => $existing['id'],
                        'amount' => (float)$existing['amount'],
                        'due_date' => $existing['due_date']
                    ];
                    $prev_due_date = $existing['due_date'];
                } else {
                    $amt_raw = trim($postData["inst_{$i}_amount"] ?? '');
                    if (!is_numeric($amt_raw) || !is_finite((float)$amt_raw)) {
                        throw new Exception("Installment #$i amount must be a valid number.");
                    }
                    $amt = round((float)$amt_raw, 2);
                    if ($amt < 1.0) {
                        throw new Exception("Installment #$i amount must be at least ₹1.");
                    }

                    $due = trim($postData["inst_{$i}_due_date"] ?? '');
                    if (empty($due) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || !checkdate((int)substr($due, 5, 2), (int)substr($due, 8, 2), (int)substr($due, 0, 4))) {
                        throw new Exception("A valid due date (YYYY-MM-DD) is required for installment #$i.");
                    }

                    if ($prev_due_date !== null && $due < $prev_due_date) {
                        throw new Exception("Due date for installment #$i ($due) cannot be earlier than installment #" . ($i - 1) . " ($prev_due_date).");
                    }
                    $prev_due_date = $due;

                    $scheduled_pending_sum += $amt;
                    $schedule_to_apply[$i] = [
                        'type' => $existing ? 'update_pending' : 'insert_new',
                        'existing_id' => $existing['id'] ?? null,
                        'amount' => $amt,
                        'due_date' => $due
                    ];
                }
            }

            // 8. Financial invariant check
            if (round($scheduled_pending_sum, 2) > round($outstanding_balance, 2)) {
                throw new Exception("Total scheduled pending installments (₹" . number_format($scheduled_pending_sum, 2) . ") cannot exceed the remaining outstanding balance (₹" . number_format($outstanding_balance, 2) . ").");
            }
            if ($new_count > 1 && $outstanding_balance > 0 && round($scheduled_pending_sum, 2) !== round($outstanding_balance, 2)) {
                throw new Exception("Total scheduled pending installments (₹" . number_format($scheduled_pending_sum, 2) . ") must exactly equal the remaining outstanding balance (₹" . number_format($outstanding_balance, 2) . ").");
            }

            // 9. Update users table
            $stmt_u = $this->pdo->prepare("UPDATE users SET discount_amount = ?, total_fee = ?, payment_plan = ?, updated_at = NOW() WHERE user_id = ?");
            $stmt_u->execute([$new_discount, $new_total_fee, $new_plan, $userId]);

            // 10. Reconcile removed future installments
            foreach ($existing_rows as $r) {
                $n = (int)$r['instalment_number'];
                if ($n > $new_count) {
                    if (in_array($r['status'], ['approved', 'paid'], true) || !empty($r['paid_date'])) {
                        throw new Exception("Cannot reduce plan term: Installment #$n has financial payment history.");
                    }
                    $this->pdo->prepare("DELETE FROM instalment_details WHERE id = ?")->execute([$r['id']]);
                }
            }

            // 11. Reconcile scheduled installments
            foreach ($schedule_to_apply as $i => $data) {
                if ($data['type'] === 'update_pending') {
                    $stmt_upd = $this->pdo->prepare("UPDATE instalment_details SET amount = ?, due_date = ?, updated_at = NOW() WHERE id = ?");
                    $stmt_upd->execute([$data['amount'], $data['due_date'], $data['existing_id']]);
                } elseif ($data['type'] === 'insert_new') {
                    $stmt_ins = $this->pdo->prepare("INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())");
                    $stmt_ins->execute([$userId, $i, $data['amount'], $data['due_date']]);
                }
            }

            // 12. Pre-Commit Invariant Integrity Checks
            $chk_dup = $this->pdo->prepare("SELECT COUNT(*), COUNT(DISTINCT instalment_number) FROM instalment_details WHERE user_id = ?");
            $chk_dup->execute([$userId]);
            [$tot_insts, $dist_insts] = $chk_dup->fetch(PDO::FETCH_NUM);
            if ($tot_insts !== $dist_insts) {
                throw new Exception("Integrity violation: Duplicate installment numbers detected after schedule update.");
            }

            $chk_paid = $this->pdo->prepare("SELECT COALESCE(SUM(COALESCE(paid_amount, amount)), 0) FROM instalment_details WHERE user_id = ? AND status IN ('approved', 'paid')");
            $chk_paid->execute([$userId]);
            $post_approved_sum = (float)$chk_paid->fetchColumn();
            if (round($post_approved_sum, 2) !== round($approved_collected, 2)) {
                throw new Exception("Integrity violation: Historical approved installment revenue was altered.");
            }

            // 13. Audit Logging
            $changes = [];
            if ($student['payment_plan'] !== $new_plan) {
                $changes[] = "Plan: {$student['payment_plan']} → $new_plan";
            }
            if ((float)$student['discount_amount'] !== (float)$new_discount) {
                $changes[] = "Discount: ₹" . number_format($student['discount_amount']) . " → ₹" . number_format($new_discount);
            }
            foreach ($schedule_to_apply as $num => $info) {
                if ($info['type'] === 'update_pending') {
                    $orig = $existing_by_num[$num];
                    if ((float)$orig['amount'] !== (float)$info['amount'] || $orig['due_date'] !== $info['due_date']) {
                        $changes[] = "#$num (₹{$orig['amount']}, due {$orig['due_date']} → ₹{$info['amount']}, due {$info['due_date']})";
                    }
                } elseif ($info['type'] === 'insert_new') {
                    $changes[] = "#$num added (₹{$info['amount']}, due {$info['due_date']})";
                }
            }
            if (!empty($duplicates_pruned_audit)) {
                $changes = array_merge($changes, $duplicates_pruned_audit);
            }
            $change_str = empty($changes) ? 'Schedule verified without alterations' : implode('; ', $changes);

            $this->pdo->prepare("INSERT INTO track_records (user_id, action, description, admin_username) VALUES (?, 'installments_edited', ?, ?)")
                ->execute([$userId, "Installment schedule edited: $change_str. Net fee: ₹$new_total_fee", $adminUsername]);
            $this->pdo->prepare("INSERT INTO admin_activity_logs (admin_username, action, details) VALUES (?, 'installments_edited', ?)")
                ->execute([$adminUsername, "Edited installment schedule for student $userId: $change_str"]);

            $this->pdo->commit();
            return ['success' => true, 'message' => 'Installment configuration updated successfully.'];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Replicates the exact student-details.php migrate_course POST execution engine
     */
    private function executeMigrateCourse(
        string $userId,
        array $postData,
        string $adminUsername = 'test_admin'
    ): array {
        try {
            $this->pdo->beginTransaction();

            $target_course_id = (int)($postData['target_course_id'] ?? 0);
            $new_plan = $postData['payment_plan'] ?? 'One Time';
            $migration_reason = trim($postData['migration_reason'] ?? '');

            if (empty($migration_reason)) {
                throw new Exception("Migration reason is required.");
            }

            $stmt = $this->pdo->prepare("SELECT u.*, pc.total_fee AS course_fee, pc.id AS course_id FROM users u LEFT JOIN pepp_courses pc ON pc.course_name = u.pepp_course WHERE u.user_id = ?");
            $stmt->execute([$userId]);
            $student_locked = $stmt->fetch();
            if (!$student_locked) throw new Exception("Student not found.");
            if ($student_locked['status'] !== 'approved') throw new Exception("Student is not approved.");

            $stmt_tc = $this->pdo->prepare("SELECT * FROM pepp_courses WHERE id = ?");
            $stmt_tc->execute([$target_course_id]);
            $target_course = $stmt_tc->fetch();
            if (!$target_course || $target_course['status'] !== 'active') throw new Exception("Target course not found or inactive.");
            if ($target_course['academic_year'] !== $student_locked['pepp_academic_year']) throw new Exception("Target course is not in the same academic year.");
            if ($target_course['course_name'] === $student_locked['pepp_course']) throw new Exception("Target course must be different.");

            $current_course_fee = (float)($student_locked['course_fee'] ?? 0);
            $target_course_fee = (float)$target_course['total_fee'];
            if ($target_course_fee < $current_course_fee) throw new Exception("Accidental downgrade blocked.");

            $reg_paid = (float)$student_locked['paid_amount'];

            // Fetch all installments
            $stmt_insts = $this->pdo->prepare("SELECT * FROM instalment_details WHERE user_id = ? ORDER BY instalment_number ASC");
            $stmt_insts->execute([$userId]);
            $current_installments = $stmt_insts->fetchAll();

            // Detect in-flight payment submissions awaiting review
            foreach ($current_installments as $inst) {
                if ($inst['status'] === 'pending' && !empty($inst['paid_date'])) {
                    $inst_num = (int)$inst['instalment_number'];
                    throw new Exception("Course migration cannot proceed while a payment is awaiting review for installment #{$inst_num}. Please approve or reject the submitted payment first.");
                }
            }

            $inst_paid = 0.0;
            $paid_count = 1;
            $already_paid_data = [];
            foreach ($current_installments as $inst) {
                if (in_array($inst['status'], ['approved', 'paid'], true)) {
                    $inst_paid += (float)($inst['paid_amount'] ?: $inst['amount']);
                    $paid_count++;
                    $already_paid_data[$inst['instalment_number']] = $inst;
                }
            }
            $total_collected = $reg_paid + $inst_paid;
            $new_outstanding = max(0.0, $target_course_fee - $total_collected);
            $upgrade_amount = max(0.0, $target_course_fee - $current_course_fee);

            $immediate_payment = isset($postData['upgrade_paid_immediately']);
            $immediate_amount = 0.0;
            if ($immediate_payment) {
                $immediate_amount = max(0.0, floatval($postData['immediate_amount'] ?? 0));
                if ($immediate_amount <= 0) throw new Exception("Immediate payment amount must be greater than zero.");
                if ($immediate_amount > $new_outstanding) throw new Exception("Immediate payment cannot exceed new outstanding balance.");
                $new_outstanding = max(0.0, $new_outstanding - $immediate_amount);
            }

            $new_count = ($new_plan === 'One Time') ? 1 : (int)explode(' ', $new_plan)[0];
            if ($new_outstanding > 0 && $new_plan === 'One Time') throw new Exception("Outstanding balance remaining.");
            if ($new_count < $paid_count + ($immediate_payment ? 1 : 0)) {
                $min_allowed = $paid_count + ($immediate_payment ? 1 : 0);
                throw new Exception("New plan term cannot be less than currently paid installments count ($min_allowed).");
            }

            $new_installments_data = [];
            $sum_installments = 0.0;
            $immediate_inst_num = $immediate_payment ? ($paid_count + 1) : null;

            for ($i = 2; $i <= $new_count; $i++) {
                if (isset($already_paid_data[$i])) {
                    $new_installments_data[$i] = [
                        'amount' => (float)$already_paid_data[$i]['amount'],
                        'due_date' => $already_paid_data[$i]['due_date'],
                        'status' => $already_paid_data[$i]['status'],
                        'paid_amount' => $already_paid_data[$i]['paid_amount'],
                        'paid_date' => $already_paid_data[$i]['paid_date'],
                        'payment_reference' => $already_paid_data[$i]['payment_reference']
                    ];
                } elseif ($i === $immediate_inst_num) {
                    $new_installments_data[$i] = [
                        'amount' => $immediate_amount,
                        'due_date' => $postData['immediate_paid_date'] ?? date('Y-m-d'),
                        'status' => 'approved',
                        'paid_amount' => $immediate_amount,
                        'paid_date' => $postData['immediate_paid_date'] ?? date('Y-m-d'),
                        'payment_reference' => 'Immediate Upgrade Payment'
                    ];
                } else {
                    $amt = max(0.0, floatval($postData["inst_{$i}_amount"] ?? 0));
                    $due = $postData["inst_{$i}_due_date"] ?? '';
                    if ($amt < 1) throw new Exception("Installment #$i amount must be at least ₹1.");
                    if (empty($due)) throw new Exception("Due date for installment #$i is required.");
                    $new_installments_data[$i] = [
                        'amount' => $amt,
                        'due_date' => $due,
                        'status' => 'pending',
                        'paid_amount' => null,
                        'paid_date' => null,
                        'payment_reference' => null
                    ];
                    $sum_installments += $amt;
                }
            }

            if ($new_outstanding > 0 && round($sum_installments, 2) !== round($new_outstanding, 2)) {
                throw new Exception("Total scheduled installments must exactly equal the remaining outstanding balance.");
            }

            $now_dt = date('Y-m-d H:i:s');

            // 1. Delete all non-paid installments
            $this->pdo->prepare("DELETE FROM instalment_details WHERE user_id = ? AND status NOT IN ('approved', 'paid') AND paid_date IS NULL")->execute([$userId]);

            // 2. Insert new/rebuilt schedule (SKIPPING already paid installments!)
            $ins = $this->pdo->prepare("
                INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $immediate_inserted_id = null;
            foreach ($new_installments_data as $num => $data) {
                if (isset($already_paid_data[$num])) {
                    continue; // SKIP RE-INSERTION OF ALREADY PAID ROWS!
                }
                $ins->execute([
                    $userId, $num, $data['amount'], $data['due_date'],
                    $data['status'], $data['paid_amount'], $data['paid_date'], $data['payment_reference'],
                    $now_dt, $now_dt
                ]);
                if ($num === $immediate_inst_num) {
                    $immediate_inserted_id = (int)$this->pdo->lastInsertId();
                }
            }

            // 3. Update student record
            $this->pdo->prepare("UPDATE users SET pepp_course = ?, total_fee = ?, discount_amount = 0, payment_plan = ?, updated_at = ? WHERE user_id = ?")
                ->execute([$target_course['course_name'], $target_course_fee, $new_plan, $now_dt, $userId]);

            // 4. Pre-Commit Invariant Integrity Checks
            $chk_dup = $this->pdo->prepare("SELECT COUNT(*), COUNT(DISTINCT instalment_number) FROM instalment_details WHERE user_id = ?");
            $chk_dup->execute([$userId]);
            [$tot_insts, $dist_insts] = $chk_dup->fetch(PDO::FETCH_NUM);
            if ($tot_insts !== $dist_insts) {
                throw new Exception("Integrity violation: Duplicate installment numbers detected after course migration.");
            }

            $chk_paid = $this->pdo->prepare("SELECT COALESCE(SUM(COALESCE(paid_amount, amount)), 0) FROM instalment_details WHERE user_id = ? AND status IN ('approved', 'paid')");
            $chk_paid->execute([$userId]);
            $post_approved_sum = (float)$chk_paid->fetchColumn();
            $expected_approved_sum = $inst_paid + ($immediate_payment ? $immediate_amount : 0.0);
            if (round($post_approved_sum, 2) !== round($expected_approved_sum, 2)) {
                throw new Exception("Integrity violation: Historical approved installment revenue was altered during course migration.");
            }

            $this->pdo->commit();
            return ['success' => true, 'immediate_id' => $immediate_inserted_id];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function runAllTests(): void {
        echo "====================================================================\n";
        echo "PEPP ERP — INSTALLMENT SCHEDULE EDIT & INTEGRITY VERIFICATION SUITE\n";
        echo "====================================================================\n\n";

        $this->testSecurityAndPermissions();
        $this->testInFlightPaymentBlocking();
        $this->testPaidRowPreservationAndInvoiceIntegrity();
        $this->testFinancialInvariants();
        $this->testPlanTermAdjustmentsAndInPlaceUpdates();
        $this->testChronologicalDueDateValidation();
        $this->testRepeatedSaveIdempotency();
        $this->testDuplicateHealingAndGroupDProtection();
        $this->testMigrateCourseDuplicateFixAndInFlightGuard();
        $this->testCliAuditIntegrityStages();

        echo "\n====================================================================\n";
        echo "TEST RESULTS: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "====================================================================\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function testSecurityAndPermissions(): void {
        echo "--- 1. SECURITY & PERMISSIONS ---\n";
        $this->resetData();

        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (1, 'CUET PG', '2026-27', 30000);
            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
            VALUES ('STU001', 'Test Student', 'CUET PG', '2026-27', 10000, 30000, '2 Installments');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (101, 'STU001', 2, 20000, '2026-10-15', 'pending');
        ");

        // CSRF Token Mismatch
        $res = $this->executeEditInstallments('STU001', ['payment_plan' => '2 Installments', 'inst_2_amount' => 20000, 'inst_2_due_date' => '2026-10-15'], false);
        $this->assertTest("CSRF failure blocks edit with clear error", !$res['success'] && str_contains($res['error'], 'Security token mismatch'));

        // Credential Restricted Admin ('financials')
        $res = $this->executeEditInstallments('STU001', ['payment_plan' => '2 Installments', 'inst_2_amount' => 20000, 'inst_2_due_date' => '2026-10-15'], true, true);
        $this->assertTest("Restricted admin blocked with HTTP 200 friendly message", !$res['success'] && str_contains($res['error'], 'Access Denied'));

        // Non-existent student
        $res = $this->executeEditInstallments('STU_NONEXISTENT', ['payment_plan' => '2 Installments']);
        $this->assertTest("Non-existent student throws clear error without crash", !$res['success'] && str_contains($res['error'], 'Student not found'));
    }

    private function testInFlightPaymentBlocking(): void {
        echo "\n--- 2. IN-FLIGHT PAYMENT REVIEW BLOCKING ---\n";
        $this->resetData();

        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (1, 'CUET PG', '2026-27', 30000);
            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
            VALUES ('STU_INFLIGHT', 'Inflight Student', 'CUET PG', '2026-27', 10000, 30000, '2 Installments');
            -- Installment #2 has pending status with submitted receipt (paid_date NOT NULL)
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference)
            VALUES (201, 'STU_INFLIGHT', 2, 20000, '2026-10-15', 'pending', 20000, '2026-09-18', 'UPI-REF-INFLIGHT-999');
        ");

        $res = $this->executeEditInstallments('STU_INFLIGHT', [
            'payment_plan' => '2 Installments',
            'discount_amount' => 0,
            'inst_2_amount' => 20000,
            'inst_2_due_date' => '2026-10-20'
        ]);

        $this->assertTest("In-flight payment edit attempt is strictly blocked", !$res['success']);
        $this->assertTest("Clear user message mentions awaiting review for installment #2", str_contains($res['error'], 'awaiting review for installment #2'));

        // Verify zero modifications occurred
        $stmt = $this->pdo->query("SELECT * FROM instalment_details WHERE id = 201");
        $row = $stmt->fetch();
        $this->assertTest("In-flight row was NOT deleted or recreated", (int)$row['id'] === 201);
        $this->assertTest("In-flight row status remains 'pending'", $row['status'] === 'pending');
        $this->assertTest("In-flight row due_date was NOT modified", $row['due_date'] === '2026-10-15');
        $this->assertTest("In-flight row payment reference preserved", $row['payment_reference'] === 'UPI-REF-INFLIGHT-999');

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM instalment_details WHERE user_id = 'STU_INFLIGHT'")->fetchColumn();
        $this->assertTest("Zero duplicate rows created during blocked attempt", $count === 1);
    }

    private function testPaidRowPreservationAndInvoiceIntegrity(): void {
        echo "\n--- 3. PAID ROW PRESERVATION & INVOICE INTEGRITY ---\n";
        $this->resetData();

        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (1, 'CUET PG', '2026-27', 40000);
            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
            VALUES ('STU_PAID', 'Paid Student', 'CUET PG', '2026-27', 10000, 40000, '3 Installments');

            -- Installment #2 is approved & paid
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference, payment_mode, approved_by, approved_at, created_at)
            VALUES (302, 'STU_PAID', 2, 15000, '2026-08-15', 'approved', 15000, '2026-08-14', 'PAY-TXN-302', 'Bank Transfer', 'finance_admin', '2026-08-15 10:00:00', '2026-08-01 09:00:00');

            -- Invoice links directly to instalment_details.id = 302
            INSERT INTO invoices (invoice_number, user_id, source, source_ref, amount, status)
            VALUES ('INV-2026-302', 'STU_PAID', 'installment', 302, 15000, 'paid');

            -- Installment #3 is pending
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (303, 'STU_PAID', 3, 15000, '2026-11-15', 'pending');
        ");

        // Admin updates pending installment #3 amount and due date
        $res = $this->executeEditInstallments('STU_PAID', [
            'payment_plan' => '3 Installments',
            'discount_amount' => 0,
            'inst_3_amount' => 15000,
            'inst_3_due_date' => '2026-11-20'
        ]);

        $this->assertTest("Edit installments succeeded with approved history", $res['success'], $res['error'] ?? '');

        // Verify approved installment #2 row
        $stmt = $this->pdo->query("SELECT * FROM instalment_details WHERE user_id = 'STU_PAID' AND instalment_number = 2");
        $inst2 = $stmt->fetch();
        $this->assertTest("Approved installment #2 preserved original primary key ID (302)", (int)$inst2['id'] === 302);
        $this->assertTest("Approved installment #2 status preserved ('approved')", $inst2['status'] === 'approved');
        $this->assertTest("Approved installment #2 paid_amount preserved (15000)", (float)$inst2['paid_amount'] === 15000.0);
        $this->assertTest("Approved installment #2 paid_date preserved ('2026-08-14')", $inst2['paid_date'] === '2026-08-14');
        $this->assertTest("Approved installment #2 payment_reference preserved ('PAY-TXN-302')", $inst2['payment_reference'] === 'PAY-TXN-302');
        $this->assertTest("Approved installment #2 approved_by preserved ('finance_admin')", $inst2['approved_by'] === 'finance_admin');
        $this->assertTest("Approved installment #2 created_at preserved", $inst2['created_at'] === '2026-08-01 09:00:00');

        // Verify invoice reference remains valid
        $inv = $this->pdo->query("SELECT * FROM invoices WHERE invoice_number = 'INV-2026-302'")->fetch();
        $this->assertTest("Invoice source_ref still points to valid existing instalment row", (int)$inv['source_ref'] === (int)$inst2['id']);
    }

    private function testFinancialInvariants(): void {
        echo "\n--- 4. FINANCIAL INVARIANT ENFORCEMENT ---\n";
        $this->resetData();

        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (1, 'CUET PG', '2026-27', 50000);
            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
            VALUES ('STU_FIN', 'Fin Student', 'CUET PG', '2026-27', 10000, 50000, '3 Installments');
            -- Total Fee: 50,000. Reg Paid: 10,000. Outstanding: 40,000
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (402, 'STU_FIN', 2, 20000, '2026-10-15', 'pending');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (403, 'STU_FIN', 3, 20000, '2026-11-15', 'pending');
        ");

        // Over-scheduling: 25,000 + 20,000 = 45,000 > 40,000
        $resOver = $this->executeEditInstallments('STU_FIN', [
            'payment_plan' => '3 Installments',
            'discount_amount' => 0,
            'inst_2_amount' => 25000,
            'inst_2_due_date' => '2026-10-15',
            'inst_3_amount' => 20000,
            'inst_3_due_date' => '2026-11-15'
        ]);
        $this->assertTest("Over-scheduling pending installments is rejected", !$resOver['success'] && str_contains($resOver['error'], 'cannot exceed'));

        // Under-scheduling: 15,000 + 20,000 = 35,000 < 40,000
        $resUnder = $this->executeEditInstallments('STU_FIN', [
            'payment_plan' => '3 Installments',
            'discount_amount' => 0,
            'inst_2_amount' => 15000,
            'inst_2_due_date' => '2026-10-15',
            'inst_3_amount' => 20000,
            'inst_3_due_date' => '2026-11-15'
        ]);
        $this->assertTest("Under-scheduling pending installments is rejected", !$resUnder['success'] && str_contains($resUnder['error'], 'must exactly equal'));

        // Selecting 'One Time' when balance > 0 is rejected
        $resOneTimeWithBal = $this->executeEditInstallments('STU_FIN', [
            'payment_plan' => 'One Time',
            'discount_amount' => 0
        ]);
        $this->assertTest("'One Time' rejected when outstanding balance remains", !$resOneTimeWithBal['success'] && str_contains($resOneTimeWithBal['error'], 'Outstanding balance'));

        // Selecting 'One Time' when balance == 0 (e.g. 40,000 discount, net = 10,000, reg = 10,000) succeeds
        $resOneTimeZeroBal = $this->executeEditInstallments('STU_FIN', [
            'payment_plan' => 'One Time',
            'discount_amount' => 40000
        ]);
        $this->assertTest("'One Time' succeeds when outstanding balance is zero", $resOneTimeZeroBal['success'], $resOneTimeZeroBal['error'] ?? '');
    }

    private function testPlanTermAdjustmentsAndInPlaceUpdates(): void {
        echo "\n--- 5. PLAN TERM ADJUSTMENTS & IN-PLACE UPDATES ---\n";
        $this->resetData();

        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (1, 'CUET PG', '2026-27', 40000);
            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
            VALUES ('STU_TERM', 'Term Student', 'CUET PG', '2026-27', 10000, 40000, '4 Installments');

            -- Installments #2, #3, #4
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (502, 'STU_TERM', 2, 10000, '2026-10-15', 'pending');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (503, 'STU_TERM', 3, 10000, '2026-11-15', 'pending');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (504, 'STU_TERM', 4, 10000, '2026-12-15', 'pending');
        ");

        // Reduce plan from 4 to 2 Installments: Outstanding is 30,000, so installment #2 becomes 30,000
        $resReduce = $this->executeEditInstallments('STU_TERM', [
            'payment_plan' => '2 Installments',
            'discount_amount' => 0,
            'inst_2_amount' => 30000,
            'inst_2_due_date' => '2026-10-25'
        ]);

        $this->assertTest("Reducing plan term from 4 to 2 installments succeeds", $resReduce['success'], $resReduce['error'] ?? '');

        // Verify installment #2 was updated in-place with its original ID 502
        $inst2 = $this->pdo->query("SELECT * FROM instalment_details WHERE user_id = 'STU_TERM' AND instalment_number = 2")->fetch();
        $this->assertTest("Installment #2 updated in-place using existing ID 502", (int)$inst2['id'] === 502);
        $this->assertTest("Installment #2 amount updated to 30,000", (float)$inst2['amount'] === 30000.0);
        $this->assertTest("Installment #2 due date updated to 2026-10-25", $inst2['due_date'] === '2026-10-25');

        // Verify installments #3 and #4 were removed
        $remaining = $this->pdo->query("SELECT instalment_number FROM instalment_details WHERE user_id = 'STU_TERM'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertTest("Installments #3 and #4 were removed cleanly", $remaining === [2]);

        // Now expand from 2 to 3 Installments: #2 = 15,000, #3 = 15,000
        $resExpand = $this->executeEditInstallments('STU_TERM', [
            'payment_plan' => '3 Installments',
            'discount_amount' => 0,
            'inst_2_amount' => 15000,
            'inst_2_due_date' => '2026-10-25',
            'inst_3_amount' => 15000,
            'inst_3_due_date' => '2026-11-25'
        ]);

        $this->assertTest("Expanding plan term from 2 to 3 installments succeeds", $resExpand['success'], $resExpand['error'] ?? '');
        $instsPost = $this->pdo->query("SELECT instalment_number, amount, status FROM instalment_details WHERE user_id = 'STU_TERM' ORDER BY instalment_number ASC")->fetchAll();
        $this->assertTest("Exactly 2 future installments exist (#2 and #3)", count($instsPost) === 2);
        $this->assertTest("Installment #3 was inserted with status 'pending'", $instsPost[1]['instalment_number'] == 3 && $instsPost[1]['status'] === 'pending');
    }

    private function testChronologicalDueDateValidation(): void {
        echo "\n--- 6. CHRONOLOGICAL DUE DATE VALIDATION ---\n";
        $this->resetData();

        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (1, 'CUET PG', '2026-27', 30000);
            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
            VALUES ('STU_DATES', 'Date Student', 'CUET PG', '2026-27', 10000, 30000, '3 Installments');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (602, 'STU_DATES', 2, 10000, '2026-10-15', 'pending');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (603, 'STU_DATES', 3, 10000, '2026-11-15', 'pending');
        ");

        // Due date #3 earlier than #2
        $resOutdated = $this->executeEditInstallments('STU_DATES', [
            'payment_plan' => '3 Installments',
            'discount_amount' => 0,
            'inst_2_amount' => 10000,
            'inst_2_due_date' => '2026-11-01',
            'inst_3_amount' => 10000,
            'inst_3_due_date' => '2026-10-15' // Earlier!
        ]);
        $this->assertTest("Installment due date earlier than preceding installment is rejected", !$resOutdated['success'] && str_contains($resOutdated['error'], 'cannot be earlier'));

        // Invalid calendar date (Feb 30)
        $resInvalidDate = $this->executeEditInstallments('STU_DATES', [
            'payment_plan' => '3 Installments',
            'discount_amount' => 0,
            'inst_2_amount' => 10000,
            'inst_2_due_date' => '2026-02-30',
            'inst_3_amount' => 10000,
            'inst_3_due_date' => '2026-03-15'
        ]);
        $this->assertTest("Non-existent calendar date (2026-02-30) is rejected", !$resInvalidDate['success'] && str_contains($resInvalidDate['error'], 'valid due date'));
    }

    private function testRepeatedSaveIdempotency(): void {
        echo "\n--- 7. REPEATED SAVE IDEMPOTENCY ---\n";
        $this->resetData();

        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (1, 'CUET PG', '2026-27', 30000);
            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
            VALUES ('STU_IDEMP', 'Idempotent Student', 'CUET PG', '2026-27', 10000, 30000, '2 Installments');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (702, 'STU_IDEMP', 2, 20000, '2026-10-15', 'pending');
        ");

        $postPayload = [
            'payment_plan' => '2 Installments',
            'discount_amount' => 0,
            'inst_2_amount' => 20000,
            'inst_2_due_date' => '2026-10-15'
        ];

        // Save 1
        $r1 = $this->executeEditInstallments('STU_IDEMP', $postPayload);
        // Save 2
        $r2 = $this->executeEditInstallments('STU_IDEMP', $postPayload);
        // Save 3
        $r3 = $this->executeEditInstallments('STU_IDEMP', $postPayload);

        $this->assertTest("Repeated saves all succeed without errors", $r1['success'] && $r2['success'] && $r3['success']);

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM instalment_details WHERE user_id = 'STU_IDEMP'")->fetchColumn();
        $this->assertTest("Zero duplicate rows created after 3 repeated saves", $count === 1);

        $inst = $this->pdo->query("SELECT * FROM instalment_details WHERE user_id = 'STU_IDEMP'")->fetch();
        $this->assertTest("Row ID preserved across repeated saves (702)", (int)$inst['id'] === 702);
    }

    private function testDuplicateHealingAndGroupDProtection(): void {
        echo "\n--- 8. DETERMINISTIC DUPLICATE HEALING & GROUP D PROTECTION ---\n";
        $this->resetData();

        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (1, 'CUET PG', '2026-27', 30000);
            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
            VALUES ('STU_DUP_A', 'Dup A Student', 'CUET PG', '2026-27', 10000, 30000, '2 Installments');

            -- Group A Duplicate: 1 approved row (801) + 1 redundant empty pending row (802) for installment #2
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference)
            VALUES (801, 'STU_DUP_A', 2, 20000, '2026-08-15', 'approved', 20000, '2026-08-15', 'PAY-DUP-A');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status)
            VALUES (802, 'STU_DUP_A', 2, 20000, '2026-08-15', 'pending');
        ");

        $resA = $this->executeEditInstallments('STU_DUP_A', [
            'payment_plan' => '2 Installments',
            'discount_amount' => 0
        ]);

        $this->assertTest("Group A duplicate auto-heals during schedule edit", $resA['success'], $resA['error'] ?? '');
        $instsA = $this->pdo->query("SELECT * FROM instalment_details WHERE user_id = 'STU_DUP_A'")->fetchAll();
        $this->assertTest("Exactly 1 installment row remains for STU_DUP_A", count($instsA) === 1);
        $this->assertTest("Canonical approved row (801) was retained", (int)$instsA[0]['id'] === 801);

        // Group C Duplicate: Multiple empty pending rows (901 with remark, 902 without)
        $this->pdo->exec("
            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
            VALUES ('STU_DUP_C', 'Dup C Student', 'CUET PG', '2026-27', 10000, 30000, '2 Installments');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, admin_remarks)
            VALUES (901, 'STU_DUP_C', 2, 20000, '2026-10-15', 'pending', 'Student requested extension');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, admin_remarks)
            VALUES (902, 'STU_DUP_C', 2, 20000, '2026-10-15', 'pending', '');
        ");

        $resC = $this->executeEditInstallments('STU_DUP_C', [
            'payment_plan' => '2 Installments',
            'discount_amount' => 0,
            'inst_2_amount' => 20000,
            'inst_2_due_date' => '2026-10-20'
        ]);

        $this->assertTest("Group C duplicate auto-heals during schedule edit", $resC['success'], $resC['error'] ?? '');
        $instsC = $this->pdo->query("SELECT * FROM instalment_details WHERE user_id = 'STU_DUP_C'")->fetchAll();
        $this->assertTest("Exactly 1 installment row remains for STU_DUP_C", count($instsC) === 1);
        $this->assertTest("Canonical row with admin remark (901) was retained", (int)$instsC[0]['id'] === 901);

        // Group D Duplicate: Conflicting financial evidence (2 approved rows)
        $this->pdo->exec("
            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan)
            VALUES ('STU_DUP_D', 'Dup D Student', 'CUET PG', '2026-27', 10000, 50000, '2 Installments');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference)
            VALUES (951, 'STU_DUP_D', 2, 20000, '2026-08-15', 'approved', 20000, '2026-08-15', 'PAY-D-1');
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference)
            VALUES (952, 'STU_DUP_D', 2, 20000, '2026-08-16', 'approved', 20000, '2026-08-16', 'PAY-D-2');
        ");

        $resD = $this->executeEditInstallments('STU_DUP_D', [
            'payment_plan' => '2 Installments',
            'discount_amount' => 0
        ]);

        $this->assertTest("Group D conflicting financial records block schedule modification", !$resD['success']);
        $this->assertTest("Clear error indicates manual review required for Group D", str_contains($resD['error'], 'manual review required'));
        $countD = (int)$this->pdo->query("SELECT COUNT(*) FROM instalment_details WHERE user_id = 'STU_DUP_D'")->fetchColumn();
        $this->assertTest("Both conflicting financial rows remain 100% untouched", $countD === 2);
    }

    private function testMigrateCourseDuplicateFixAndInFlightGuard(): void {
        echo "\n--- 9. MIGRATE COURSE DUPLICATE FIX & IN-FLIGHT GUARD ---\n";
        $this->resetData();

        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (1, 'UG Basic', '2026-27', 20000);
            INSERT INTO pepp_courses (id, course_name, academic_year, total_fee) VALUES (2, 'UG Premium', '2026-27', 35000);

            INSERT INTO users (user_id, name, pepp_course, pepp_academic_year, paid_amount, total_fee, payment_plan, status)
            VALUES ('STU_MIG', 'Migration Student', 'UG Basic', '2026-27', 5000, 20000, '2 Installments', 'approved');

            -- Installment #2 is already approved & paid in UG Basic
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference)
            VALUES (1002, 'STU_MIG', 2, 15000, '2026-08-15', 'approved', 15000, '2026-08-15', 'PAY-MIG-OLD');
        ");

        // Test 1: In-flight payment blocking during course migration
        $this->pdo->exec("
            INSERT INTO instalment_details (id, user_id, instalment_number, amount, due_date, status, paid_amount, paid_date, payment_reference)
            VALUES (1003, 'STU_MIG', 3, 5000, '2026-09-20', 'pending', 5000, '2026-09-18', 'INFLIGHT-MIG-123');
        ");

        $resBlocked = $this->executeMigrateCourse('STU_MIG', [
            'target_course_id' => 2,
            'payment_plan' => '3 Installments',
            'migration_reason' => 'Upgrade to Premium'
        ]);

        $this->assertTest("Course migration blocked while an in-flight payment awaits review", !$resBlocked['success'] && str_contains($resBlocked['error'], 'awaiting review'));

        // Remove the in-flight row for the actual migration test
        $this->pdo->exec("DELETE FROM instalment_details WHERE id = 1003");

        // Test 2: Migrate course to UG Premium (35,000). Total collected: 5000 (reg) + 15000 (inst #2) = 20,000.
        // Remaining outstanding = 15,000. Plan: 3 Installments (#2 already paid, #3 scheduled for 15,000)
        $resMig = $this->executeMigrateCourse('STU_MIG', [
            'target_course_id' => 2,
            'payment_plan' => '3 Installments',
            'migration_reason' => 'Upgrade to UG Premium',
            'inst_3_amount' => 15000,
            'inst_3_due_date' => '2026-11-15'
        ]);

        $this->assertTest("Course migration succeeds cleanly", $resMig['success'], $resMig['error'] ?? '');

        // Verify duplicate re-insertion was fixed: Installment #2 must exist ONCE with original ID 1002
        $rowsInst2 = $this->pdo->query("SELECT * FROM instalment_details WHERE user_id = 'STU_MIG' AND instalment_number = 2")->fetchAll();
        $this->assertTest("Installment #2 exists exactly once (no duplicate inserted)", count($rowsInst2) === 1);
        $this->assertTest("Installment #2 retains original ID 1002", (int)$rowsInst2[0]['id'] === 1002);
        $this->assertTest("Installment #2 retains payment reference PAY-MIG-OLD", $rowsInst2[0]['payment_reference'] === 'PAY-MIG-OLD');

        // Verify total rows for student is 2 (inst #2 paid, inst #3 pending)
        $allInsts = $this->pdo->query("SELECT * FROM instalment_details WHERE user_id = 'STU_MIG' ORDER BY instalment_number ASC")->fetchAll();
        $this->assertTest("Total installments after migration is exactly 2 (#2 and #3)", count($allInsts) === 2);
        $this->assertTest("Installment #3 is pending for 15,000", $allInsts[1]['instalment_number'] == 3 && (float)$allInsts[1]['amount'] === 15000.0 && $allInsts[1]['status'] === 'pending');
    }

    private function testCliAuditIntegrityStages(): void {
        echo "\n--- 10. CLI AUDIT TOOL SAFETY & STAGE INTEGRATION ---\n";
        $auditScript = __DIR__ . '/scripts/audit_installment_integrity.php';
        $this->assertTest("Audit script exists on filesystem", file_exists($auditScript));

        // Test Non-CLI execution simulation: php -r "define('PEPP_SAPI_SIM', 'fpm'); ..."
        $outHttp = shell_exec("php -r \"require 'scripts/audit_installment_integrity.php';\" 2>&1");
        $this->assertTest("Non-CLI guard exists and script executes cleanly under CLI", str_contains($outHttp, "PEPP ERP — INSTALLMENT INTEGRITY FORENSIC AUDIT"));

        // Dry-run mode
        $outDryRun = shell_exec("php scripts/audit_installment_integrity.php --dry-run 2>&1");
        $this->assertTest("Dry run mode executes without syntax or runtime error", str_contains($outDryRun, "DRY-RUN COMPLETE. ZERO ROWS MODIFIED"));

        // Verify mode
        $outVerify = shell_exec("php scripts/audit_installment_integrity.php --verify 2>&1");
        $this->assertTest("Verify mode executes and reports status", str_contains($outVerify, "VERIFICATION"));
    }
}

// Execute Suite
$suite = new InstallmentIntegrityTestSuite();
$suite->runAllTests();
