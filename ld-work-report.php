<?php
require_once 'includes/auth.php';
require_permission('ld-work-report');

if (get_admin_type() === 'intern') {
    http_response_code(403);
    die("Access denied. L&D Work Report is restricted to administrators.");
}

require_once 'config/database.php';

if (!ld_tables_exist($pdo)) {
    $active_page = 'ld-work-report';
    $page_title  = 'L&D Work Report';
    $page_sub    = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>L&D Work Report is not installed yet. Please run the required database migration (<strong>database-update-21.sql</strong>) before using this module.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

require_once 'includes/pdf_invoice.php'; // MiniPDF + helpers

// Set time zone
date_default_timezone_set('Asia/Kolkata');

$tab = $_GET['tab'] ?? 'report';
if (!in_array($tab, ['report', 'payments'])) {
    $tab = 'report';
}

if ($tab === 'payments') {
    if (get_admin_type() === 'intern') {
        http_response_code(403);
        echo "<div class='alert alert-error' style='margin:20px;'><i class='fas fa-triangle-exclamation'></i> Access Denied. Financial reports are restricted to administrators.</div>";
        exit();
    }
}

$success_message = '';
$error_message = '';

if (!empty($_GET['paid'])) {
    $success_message = "Payment logged successfully. Voucher: " . htmlspecialchars(strip_tags($_GET['paid']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_task' || $action === 'delete_task') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please retry.']);
            exit();
        }
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_task') {
            header('Content-Type: application/json');
            if (!is_super_admin()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied. Only Super Admin can edit activity logs.']);
                exit();
            }

            $id = (int)($_POST['task_id'] ?? 0);
            $topics = $_POST['topics'] ?? [];
            $quantities = $_POST['quantities'] ?? [];

            $stmt = $pdo->prepare("SELECT * FROM ld_tasks WHERE id = ? AND status = 'active'");
            $stmt->execute([$id]);
            $task = $stmt->fetch();

            if (!$task) {
                echo json_encode(['success' => false, 'message' => 'Task not found.']);
                exit();
            } elseif (($lock_info = is_ld_task_locked($pdo, $task['admin_id'], date('Y-m-d', strtotime($task['created_at']))))) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied. This activity belongs to a completed payment period.']);
                exit();
            } elseif (empty($topics)) {
                echo json_encode(['success' => false, 'message' => 'Please provide at least one topic.']);
                exit();
            } else {
                $rate = $task['charge_per_quantity_snapshot'] !== null ? (float)$task['charge_per_quantity_snapshot'] : 0.00;
                $qty_label = $task['quantity_label_snapshot'];

                $stmt = $pdo->prepare("SELECT * FROM ld_task_topics WHERE task_id = ?");
                $stmt->execute([$id]);
                $old_topics = $stmt->fetchAll();

                $prev_data = [
                    'id' => $task['id'],
                    'course_name' => $task['course_name'],
                    'mode_name' => $task['mode_name'],
                    'topics' => array_map(function($tp) {
                        return ['topic_name' => $tp['topic_name'], 'quantity' => $tp['quantity'], 'calculated_charge' => $tp['calculated_charge']];
                    }, $old_topics)
                ];

                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("
                        UPDATE ld_tasks
                        SET updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$id]);

                    $stmt = $pdo->prepare("DELETE FROM ld_task_topics WHERE task_id = ?");
                    $stmt->execute([$id]);

                    $stmt_topic = $pdo->prepare("INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge) VALUES (?, ?, ?, ?)");
                    $clean_topics = [];
                    foreach ($topics as $idx => $topic) {
                        $topic_clean = trim($topic);
                        if ($topic_clean !== '') {
                            $qty = isset($quantities[$idx]) && $quantities[$idx] !== '' ? filter_var($quantities[$idx], FILTER_VALIDATE_FLOAT) : null;
                            if ($qty !== null && ($qty === false || $qty < 0)) {
                                throw new Exception("Quantity for each topic must be a valid non-negative number.");
                            }
                            $calculated_charge = $qty !== null ? ($qty * $rate) : 0.00;
                            $stmt_topic->execute([$id, $topic_clean, $qty, $calculated_charge]);
                            $clean_topics[] = [
                                'topic_name' => $topic_clean,
                                'quantity' => $qty,
                                'calculated_charge' => $calculated_charge
                            ];
                        }
                    }

                    $new_data = [
                        'id' => $id,
                        'course_name' => $task['course_name'],
                        'mode_name' => $task['mode_name'],
                        'topics' => $clean_topics
                    ];

                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

                    $lat = $task['latitude'] !== null ? $task['latitude'] : '0.00000000';
                    $lng = $task['longitude'] !== null ? $task['longitude'] : '0.00000000';
                    $maps_url = $task['maps_url'] !== null ? $task['maps_url'] : '';

                    $stmt = $pdo->prepare("
                        INSERT INTO ld_task_audit (task_id, admin_id, admin_username, action, previous_values, new_values, latitude, longitude, maps_url, ip_address, user_agent, created_at)
                        VALUES (?, ?, ?, 'UPDATE', ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $id,
                        (int)$task['admin_id'],
                        $admin_username,
                        json_encode($prev_data, JSON_UNESCAPED_UNICODE),
                        json_encode($new_data, JSON_UNESCAPED_UNICODE),
                        $lat,
                        $lng,
                        $maps_url,
                        $ip,
                        $ua
                    ]);

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Task updated successfully.']);
                    exit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Database error while updating task.']);
                    exit();
                }
            }
        } elseif ($action === 'delete_task') {
            header('Content-Type: application/json');
            if (!is_super_admin()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied. Only Super Admin can delete activity logs.']);
                exit();
            }

            $id = (int)($_POST['task_id'] ?? 0);
            $reason = trim($_POST['delete_reason'] ?? '');
            $confirm_text = trim($_POST['confirm_text'] ?? '');

            if ($confirm_text !== 'DELETE') {
                echo json_encode(['success' => false, 'message' => 'Deletion failed. You must type DELETE to confirm.']);
                exit();
            } else {
                $stmt = $pdo->prepare("SELECT * FROM ld_tasks WHERE id = ? AND status = 'active'");
                $stmt->execute([$id]);
                $task = $stmt->fetch();

                if (!$task) {
                    echo json_encode(['success' => false, 'message' => 'Task not found.']);
                    exit();
                } elseif (($lock_info = is_ld_task_locked($pdo, $task['admin_id'], date('Y-m-d', strtotime($task['created_at']))))) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Access denied. This activity belongs to a completed payment period.']);
                    exit();
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM ld_task_topics WHERE task_id = ?");
                    $stmt->execute([$id]);
                    $old_topics = $stmt->fetchAll();

                    $prev_data = [
                        'id' => $task['id'],
                        'course_name' => $task['course_name'],
                        'mode_name' => $task['mode_name'],
                        'topics' => array_map(function($tp) {
                            return ['topic_name' => $tp['topic_name'], 'quantity' => $tp['quantity'], 'calculated_charge' => $tp['calculated_charge']];
                        }, $old_topics)
                    ];

                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE ld_tasks
                            SET status = 'deleted',
                                deleted_at = NOW(),
                                deleted_by = ?,
                                deleted_reason = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$admin_username, $reason, $id]);

                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

                        $lat = $task['latitude'] !== null ? $task['latitude'] : '0.00000000';
                        $lng = $task['longitude'] !== null ? $task['longitude'] : '0.00000000';
                        $maps_url = $task['maps_url'] !== null ? $task['maps_url'] : '';

                        $stmt = $pdo->prepare("
                            INSERT INTO ld_task_audit (task_id, admin_id, admin_username, action, previous_values, new_values, latitude, longitude, maps_url, ip_address, user_agent, created_at)
                            VALUES (?, ?, ?, 'DELETE', ?, NULL, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $id,
                            (int)$task['admin_id'],
                            $admin_username,
                            json_encode($prev_data, JSON_UNESCAPED_UNICODE),
                            $lat,
                            $lng,
                            $maps_url,
                            $ip,
                            $ua
                        ]);

                        $pdo->commit();
                        echo json_encode(['success' => true, 'message' => 'Task deleted successfully.']);
                        exit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Database error while deleting task.']);
                        exit();
                    }
                }
            }
        }
    }
}

if ($tab === 'payments') {
    // 1. AJAX requests handling
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        $action = $_GET['action'];

        if ($action === 'calc_expected') {
            $intern_id = (int)($_GET['intern_id'] ?? 0);
            $start = trim($_GET['start'] ?? '');
            $end = trim($_GET['end'] ?? '');

            if (!$intern_id || !$start || !$end) {
                echo json_encode(['success' => false, 'error' => 'Missing required fields']);
                exit();
            }

            // Fetch intern username to query tasks
            $stmt = $pdo->prepare("
                SELECT username, DATE(created_at) AS joining_date
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
                echo json_encode(['success' => false, 'error' => 'Intern not found']);
                exit();
            }

            // Date validations
            if (!strtotime($start) || !strtotime($end)) {
                echo json_encode(['success' => false, 'error' => 'Invalid date format.']);
                exit();
            }
            if ($start < $intern['joining_date']) {
                echo json_encode(['success' => false, 'error' => 'Start date cannot be earlier than intern joining date (' . $intern['joining_date'] . ')']);
                exit();
            }
            if ($end < $start) {
                echo json_encode(['success' => false, 'error' => 'End date cannot be earlier than start date']);
                exit();
            }
            if ($end > date('Y-m-d')) {
                echo json_encode(['success' => false, 'error' => 'End date cannot be in the future.']);
                exit();
            }

            // Check overlap
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
                $msg = "This payment period overlaps with an existing completed payment period (Voucher: " . $overlap['voucher_no'] . ", " . $ov_start . " – " . $ov_end . "). Please select a non-overlapping period.";
                echo json_encode(['success' => false, 'error' => $msg]);
                exit();
            }

            // Scan for legacy tasks with missing quantities in the period, excluding already paid ones
            $stmt = $pdo->prepare("
                SELECT DISTINCT t.id, DATE(t.created_at) AS task_date, t.mode_name, tp.topic_name
                FROM ld_tasks t
                JOIN ld_task_topics tp ON tp.task_id = t.id
                WHERE t.admin_username = ?
                  AND t.status = 'active'
                  AND DATE(t.created_at) BETWEEN ? AND ?
                  AND (tp.quantity IS NULL OR tp.quantity <= 0)
                  AND NOT EXISTS (
                      SELECT 1 FROM ld_intern_payments p
                      WHERE p.intern_id = ?
                        AND p.status = 'Completed'
                        AND DATE(t.created_at) BETWEEN p.period_start_date AND p.period_end_date
                  )
            ");
            $stmt->execute([$intern['username'], $start, $end, $intern_id]);
            $incomplete_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Query tasks and calculate expected amount based on topic snapshots, excluding already paid ones
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
            $expected = (float)($stmt->fetchColumn() ?? 0.00);

            // Aggregate by mode, excluding already paid ones
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
                        AND p.status = 'Completed'
                        AND DATE(t.created_at) BETWEEN p.period_start_date AND p.period_end_date
                  )
                GROUP BY t.mode_id, t.quantity_label_snapshot
            ");
            $stmt->execute([$intern['username'], $start, $end, $intern_id]);
            $modes = $stmt->fetchAll();

            $work_summary = [];
            foreach ($modes as $m) {
                $mname = !empty($m['mode_title']) ? $m['mode_title'] : (!empty($m['mode_name']) ? $m['mode_name'] : 'Work Mode');
                $qlbl = !empty($m['quantity_label_snapshot']) ? $m['quantity_label_snapshot'] : 'units';
                $work_summary[] = [
                    'mode_name' => $mname,
                    'total_qty' => (float)$m['total_qty'],
                    'quantity_label' => $qlbl
                ];
            }

            echo json_encode([
                'success' => true,
                'expected_amount' => $expected,
                'work_summary' => $work_summary,
                'incomplete_tasks' => $incomplete_tasks
            ]);
            exit();
        }

        if ($action === 'check_overlap') {
            $intern_id = (int)($_GET['intern_id'] ?? 0);
            $start = trim($_GET['start'] ?? '');
            $end = trim($_GET['end'] ?? '');

            if (!$intern_id || !$start || !$end) {
                echo json_encode(['success' => false, 'error' => 'Missing fields']);
                exit();
            }

            // Check overlap
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
                $msg = "This payment period overlaps with an existing completed payment period (Voucher: " . $overlap['voucher_no'] . ", " . $ov_start . " – " . $ov_end . "). Please select a non-overlapping period.";
                echo json_encode([
                    'success' => true,
                    'overlap' => true,
                    'message' => $msg
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'overlap' => false
                ]);
            }
            exit();
        }
    }

    // 2. Handle POST payout logging
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
        if (!csrf_verify()) {
            $error_message = 'Invalid request (CSRF check failed).';
        } else {
            $intern_id = (int)($_POST['intern_id'] ?? 0);
            $start = trim($_POST['period_start'] ?? '');
            $end = trim($_POST['period_end'] ?? '');
            $adj = (float)($_POST['adjustment_amount'] ?? 0.00);
            $acct_id = (int)($_POST['payment_account_id'] ?? 0);
            $paid_date = trim($_POST['paid_date'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');
            $admin_username = $_SESSION['admin_username'] ?? (function_exists('get_admin_username') ? get_admin_username() : (function_exists('get_admin_user') ? get_admin_user() : 'Admin'));

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
                $error_message = 'Intern not found.';
            } elseif (empty($start) || empty($end) || empty($paid_date)) {
                $error_message = 'Start date, end date, and paid date are mandatory.';
            } elseif (!strtotime($start) || !strtotime($end) || !strtotime($paid_date)) {
                $error_message = 'Invalid date format provided.';
            } elseif (!empty($intern['joining_date']) && $start < $intern['joining_date']) {
                $error_message = 'Start date cannot be earlier than intern joining/registration date (' . $intern['joining_date'] . ').';
            } elseif ($end < $start) {
                $error_message = 'End date cannot be earlier than start date.';
            } elseif ($end > date('Y-m-d')) {
                $error_message = 'End date cannot be in the future.';
            } elseif ($acct_id <= 0) {
                $error_message = 'Payment account is required.';
            } else {
                $stmt = $pdo->prepare("SELECT account_name FROM payment_accounts WHERE id = ? AND status = 'active'");
                $stmt->execute([$acct_id]);
                $acct_name = $stmt->fetchColumn();

                if (!$acct_name) {
                    $error_message = 'Invalid or inactive payment account selected.';
                } else {
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
                        $error_message = "This payment period overlaps with an existing completed payment period (Voucher: " . $overlap['voucher_no'] . ", " . $ov_start . " – " . $ov_end . "). Please select a non-overlapping period.";
                    } else {
                        // SERVER-SIDE RE-CALCULATION, excluding already paid tasks
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
                            $error_message = 'Final paid amount cannot be negative.';
                        } else {
                            $screenshot_path = null;
                            if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] !== UPLOAD_ERR_NO_FILE) {
                                if ($_FILES['screenshot']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['screenshot']['error'] === UPLOAD_ERR_FORM_SIZE) {
                                    $error_message = 'Payment screenshot exceeds the maximum allowed file size.';
                                } elseif ($_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
                                    $error_message = 'Payment screenshot upload failed (Error code: ' . (int)$_FILES['screenshot']['error'] . ').';
                                } else {
                                    $file = $_FILES['screenshot'];
                                    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                                    $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];

                                    if (!in_array($file_ext, $allowed_exts)) {
                                        $error_message = 'Only image (JPG, PNG) or PDF screenshots are allowed.';
                                    } elseif ($file['size'] > 5 * 1024 * 1024) {
                                        $error_message = 'Payment screenshot size cannot exceed 5MB.';
                                    } else {
                                        $upload_dir = __DIR__ . '/uploads/ld_payments/';
                                        if (!file_exists($upload_dir)) {
                                            @mkdir($upload_dir, 0777, true);
                                        }
                                        $unique_name = 'ld_pay_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
                                        $target_path = $upload_dir . $unique_name;
                                        if (move_uploaded_file($file['tmp_name'], $target_path)) {
                                            $screenshot_path = 'uploads/ld_payments/' . $unique_name;
                                        } else {
                                            $error_message = 'Failed to move uploaded screenshot file.';
                                        }
                                    }
                                }
                            }

                            if (empty($error_message)) {
                                try {
                                    $pdo->beginTransaction();

                                    // Double-check overlap inside transaction for concurrency safety
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
                                    $voucher_no = 'VOU-LD-' . str_pad($inserted_id, 5, '0', STR_PAD_LEFT);

                                    $stmt = $pdo->prepare("UPDATE ld_intern_payments SET voucher_no = ? WHERE id = ?");
                                    $stmt->execute([$voucher_no, $inserted_id]);

                                    // Record linked transaction in Accounts & Expenses
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

                                    // Aggregate by work mode during the payment period and bulk insert snapshots into ld_intern_payment_items, excluding already paid ones
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
                                        $mtitle = !empty($item['mode_title']) ? $item['mode_title'] : (!empty($item['mode_name']) ? $item['mode_name'] : 'Work Mode');
                                        $qlbl = !empty($item['quantity_label_snapshot']) ? $item['quantity_label_snapshot'] : 'units';
                                        $item_stmt->execute([
                                            $inserted_id,
                                            (int)$item['mode_id'],
                                            $mtitle,
                                            (float)$item['total_qty'],
                                            $qlbl
                                        ]);
                                    }

                                    $audit_details = "Recorded payment {$voucher_no} for intern {$intern['full_name']} ({$start} to {$end}) - Expected: ₹" . number_format($server_expected, 2) . ", Adj: ₹" . number_format($adj, 2) . ", Paid: ₹" . number_format($server_paid_amount, 2);
                                    log_admin_activity($pdo, $admin_username, 'ld_payment_recorded', $audit_details);

                                    $pdo->commit();

                                    if (!headers_sent()) {
                                        header("Location: ld-work-report.php?tab=payments&paid=" . urlencode($voucher_no));
                                        exit();
                                    } else {
                                        $success_message = "Payment logged successfully. Voucher: " . $voucher_no;
                                    }
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
                                    error_log("L&D Intern Payment Recording Error: " . $txEx->getMessage() . " in " . $txEx->getFile() . ":" . $txEx->getLine());
                                    $error_message = "Database error: " . $txEx->getMessage();
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // Load Intern Summary data
    $intern_payouts = [];
    try {
        $all_interns = $pdo->query("
            SELECT id, username, full_name, status, DATE(created_at) AS joining_date
            FROM admins
            WHERE admin_type = 'intern'
               OR (
                   role != 'super_admin'
                   AND permissions != 'ALL'
                   AND (permissions = 'task-tracker' OR permissions LIKE '%,task-tracker' OR permissions LIKE 'task-tracker,%' OR permissions LIKE '%,task-tracker,%')
               )
            ORDER BY full_name ASC
        ")->fetchAll();

        // Pre-fetch expected charges, completed payments, and pending amounts in 3 batch queries
        $batch_expected = [];
        try {
            $stmt_exp = $pdo->query("
                SELECT t.admin_username, SUM(tp.calculated_charge) AS total_charge
                FROM ld_tasks t
                JOIN ld_task_topics tp ON tp.task_id = t.id
                WHERE t.status = 'active'
                GROUP BY t.admin_username
            ");
            while ($row_exp = $stmt_exp->fetch(PDO::FETCH_ASSOC)) {
                $batch_expected[$row_exp['admin_username']] = (float)$row_exp['total_charge'];
            }
        } catch (Throwable $e) {}

        $batch_paid = [];
        try {
            $stmt_paid = $pdo->query("
                SELECT intern_id, SUM(paid_amount) AS total_paid
                FROM ld_intern_payments
                WHERE status = 'Completed'
                GROUP BY intern_id
            ");
            while ($row_paid = $stmt_paid->fetch(PDO::FETCH_ASSOC)) {
                $batch_paid[(int)$row_paid['intern_id']] = (float)$row_paid['total_paid'];
            }
        } catch (Throwable $e) {}

        $batch_pending = [];
        try {
            $stmt_pend = $pdo->query("
                SELECT t.admin_username, SUM(tp.calculated_charge) AS total_pending
                FROM ld_tasks t
                JOIN ld_task_topics tp ON tp.task_id = t.id
                JOIN admins a ON a.username = t.admin_username
                WHERE t.status = 'active'
                  AND tp.quantity IS NOT NULL
                  AND tp.quantity > 0
                  AND NOT EXISTS (
                      SELECT 1 FROM ld_intern_payments p
                      WHERE p.intern_id = a.id
                        AND p.status = 'Completed'
                        AND DATE(t.created_at) BETWEEN p.period_start_date AND p.period_end_date
                  )
                GROUP BY t.admin_username
            ");
            while ($row_pend = $stmt_pend->fetch(PDO::FETCH_ASSOC)) {
                $batch_pending[$row_pend['admin_username']] = (float)$row_pend['total_pending'];
            }
        } catch (Throwable $e) {}

        foreach ($all_interns as $intern) {
            $intern_payouts[] = [
                'id' => $intern['id'],
                'username' => $intern['username'],
                'full_name' => $intern['full_name'],
                'status' => $intern['status'],
                'joining_date' => $intern['joining_date'],
                'expected' => $batch_expected[$intern['username']] ?? 0.00,
                'paid' => $batch_paid[(int)$intern['id']] ?? 0.00,
                'pending' => $batch_pending[$intern['username']] ?? 0.00
            ];
        }
    } catch (Exception $e) {
        error_log("Load intern payouts summary error: " . $e->getMessage());
    }

    // Load Completed Payments data
    $completed_payments = [];
    try {
        $completed_payments = $pdo->query("
            SELECT p.*, a.account_name
            FROM ld_intern_payments p
            LEFT JOIN payment_accounts a ON a.id = p.payment_account_id
            ORDER BY p.paid_date DESC, p.id DESC
        ")->fetchAll();
    } catch (Exception $e) {
        error_log("Load completed payments error: " . $e->getMessage());
    }

    // Load payment accounts
    $payment_accounts = [];
    try {
        $payment_accounts = $pdo->query("
            SELECT id, account_name, account_type
            FROM payment_accounts
            WHERE status = 'active'
            ORDER BY account_name ASC
        ")->fetchAll();
    } catch (Exception $e) {
        error_log("Load payment accounts error: " . $e->getMessage());
    }
}

if ($tab === 'report') {
    // Filter parameters
    $f_staff = trim($_GET['staff'] ?? '');
$f_role = trim($_GET['role'] ?? '');
$f_course = (int)($_GET['course'] ?? 0);
$f_mode = (int)($_GET['mode'] ?? 0);
$f_status = trim($_GET['status'] ?? 'active'); // 'active', 'deleted', 'all'
$f_period = trim($_GET['period'] ?? 'overall'); // 'today', 'weekly', 'monthly', 'overall', 'custom'
$f_from = trim($_GET['from'] ?? '');
$f_to = trim($_GET['to'] ?? '');

// Build where clause
$where = [];
$params = [];

if ($f_staff !== '') {
    $where[] = "t.admin_username = ?";
    $params[] = $f_staff;
}
if ($f_role !== '') {
    $where[] = "t.admin_role = ?";
    $params[] = $f_role;
}
if ($f_course > 0) {
    $where[] = "t.course_id = ?";
    $params[] = $f_course;
}
if ($f_mode > 0) {
    $where[] = "t.mode_id = ?";
    $params[] = $f_mode;
}

// Status filter
if ($f_status === 'active') {
    $where[] = "t.status = 'active'";
} elseif ($f_status === 'deleted') {
    $where[] = "t.status = 'deleted'";
} // 'all' allows both

// Period filter
if ($f_period === 'today') {
    $where[] = "DATE(t.created_at) = CURRENT_DATE()";
} elseif ($f_period === 'weekly') {
    $where[] = "t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($f_period === 'monthly') {
    $where[] = "t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($f_period === 'custom') {
    if ($f_from !== '') {
        $where[] = "t.created_at >= ?";
        $params[] = $f_from . ' 00:00:00';
    }
    if ($f_to !== '') {
        $where[] = "t.created_at <= ?";
        $params[] = $f_to . ' 23:59:59';
    }
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// Fetch all staff members for filters
$staff_list = [];
try {
    $staff_list = $pdo->query("
        SELECT username AS admin_username, full_name AS admin_name
        FROM admins
        WHERE admin_type = 'intern'
           OR (
               role != 'super_admin'
               AND permissions != 'ALL'
               AND (permissions = 'task-tracker' OR permissions LIKE '%,task-tracker' OR permissions LIKE 'task-tracker,%' OR permissions LIKE '%,task-tracker,%')
           )
        ORDER BY full_name ASC
    ")->fetchAll();
} catch (Exception $e) {}

// Fetch courses and modes for filter dropdowns
$courses_filter = [];
try { $courses_filter = $pdo->query("SELECT * FROM ld_work_courses ORDER BY sort_order ASC, course_name ASC")->fetchAll(); } catch (Exception $e) {}
$modes_filter = [];
try { $modes_filter = $pdo->query("SELECT * FROM ld_work_modes ORDER BY sort_order ASC, mode_name ASC")->fetchAll(); } catch (Exception $e) {}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!is_super_admin() && !can_access('ld-work-report')) {
        die('Access denied.');
    }

    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT t.*
            FROM ld_tasks t
            $where_sql
            ORDER BY t.created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if (!empty($rows)) {
            $task_ids = array_map(function($x) { return (int)$x['id']; }, $rows);
            $in_clause = implode(',', $task_ids);
            $topics_rows = $pdo->query("SELECT * FROM ld_task_topics WHERE task_id IN ($in_clause) ORDER BY id ASC")->fetchAll();

            $topics_by_task = [];
            foreach ($topics_rows as $row) {
                $topics_by_task[$row['task_id']][] = $row;
            }
            foreach ($rows as &$t) {
                $t['topics'] = $topics_by_task[$t['id']] ?? [];
            }
            unset($t);
        }
    } catch (Exception $e) {
        die("Export Error: " . $e->getMessage());
    }

    log_admin_activity($pdo, $admin_username, 'data_export', "Exported L&D Work report (" . count($rows) . ' rows)');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ld-work-report-' . date('Y-m-d-Hi') . '.csv"');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM
    fputcsv($out, ['Date', 'Staff', 'Role', 'Course', 'Work Mode', 'Topics Count', 'Total Quantity', 'Total Charge (₹)', 'Topics List', 'IP Address', 'Maps URL', 'Status', 'Deleted By', 'Deleted Reason']);

    foreach ($rows as $r) {
        $topics_clean_parts = [];
        $total_task_qty = 0.00;
        $total_task_charge = 0.00;
        $has_qty_info = false;

        foreach ($r['topics'] as $tp) {
            if ($tp['quantity'] !== null) {
                $has_qty_info = true;
                $total_task_qty += (float)$tp['quantity'];
                $total_task_charge += (float)$tp['calculated_charge'];

                $qty_lbl = $r['quantity_label_snapshot'] ?? 'units';
                $rate_val = $r['charge_per_quantity_snapshot'] !== null ? '₹' . number_format((float)$r['charge_per_quantity_snapshot'], 2) : 'N/A';
                $charge_val = '₹' . number_format((float)$tp['calculated_charge'], 2);

                $topics_clean_parts[] = $tp['topic_name'] . " (" . (float)$tp['quantity'] . " " . $qty_lbl . " @ " . $rate_val . " = " . $charge_val . ")";
            } else {
                $topics_clean_parts[] = $tp['topic_name'] . " (Quantity: Not Added / Historical Rate: Not Available / Charge: Not Calculated)";
            }
        }
        $topics_clean = implode('; ', $topics_clean_parts);

        fputcsv($out, [
            $r['created_at'],
            $r['admin_name'],
            $r['admin_role'],
            $r['course_name'],
            $r['mode_name_snapshot'] ?: $r['mode_name'],
            count($r['topics']),
            $has_qty_info ? $total_task_qty : 'Not Added',
            $has_qty_info ? number_format($total_task_charge, 2) : 'Not Calculated',
            $topics_clean,
            $r['ip_address'],
            $r['maps_url'],
            $r['status'],
            $r['deleted_by'] ?? '',
            $r['deleted_reason'] ?? ''
        ]);
    }
    fclose($out);
    exit();
}

if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    if (!is_super_admin() && !can_access('ld-work-report')) {
        die('Access denied.');
    }

    // Fetch metrics
    $total_tasks = 0;
    $total_topics = 0;
    $active_days = 0;
    $course_breakdown = [];
    $mode_breakdown = [];
    $tasks = [];
    $total_charge_sum = 0.00;

    try {
        // Fetch raw tasks matching filters
        $stmt = $pdo->prepare("
            SELECT t.*
            FROM ld_tasks t
            $where_sql
            ORDER BY t.created_at DESC
        ");
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();

        if (!empty($tasks)) {
            $task_ids = array_map(function($x) { return (int)$x['id']; }, $tasks);
            $in_clause = implode(',', $task_ids);
            $topics_rows = $pdo->query("SELECT * FROM ld_task_topics WHERE task_id IN ($in_clause) ORDER BY id ASC")->fetchAll();

            $topics_by_task = [];
            foreach ($topics_rows as $row) {
                $topics_by_task[$row['task_id']][] = $row;
            }
            foreach ($tasks as &$t) {
                $t['topics'] = $topics_by_task[$t['id']] ?? [];
            }
            unset($t);
        }

        $dates = [];
        foreach ($tasks as $tk) {
            $total_tasks++;
            $cnt = count($tk['topics']);
            $total_topics += $cnt;

            $dates[date('Y-m-d', strtotime($tk['created_at']))] = true;

            if (!isset($course_breakdown[$tk['course_name']])) {
                $course_breakdown[$tk['course_name']] = 0;
            }
            $course_breakdown[$tk['course_name']] += $cnt;

            $mode_title = $tk['mode_name_snapshot'] ?: $tk['mode_name'];
            if (!isset($mode_breakdown[$mode_title])) {
                $mode_breakdown[$mode_title] = 0;
            }
            $mode_breakdown[$mode_title] += $cnt;

            foreach ($tk['topics'] as $tp) {
                $total_charge_sum += (float)$tp['calculated_charge'];
            }
        }
        $active_days = count($dates);
    } catch (Exception $e) {
        die("PDF Stats Error: " . $e->getMessage());
    }

    $pdf = new MiniPDF();
    $L = 50; $R = MiniPDF::W - 50; $W = $R - $L;

    // Check if logo exists
    $logo = __DIR__ . '/pepp-logo.jpg';
    if (file_exists($logo)) {
        $pdf->image($logo, $L, 44, 92, 42);
    } else {
        $pdf->text($L, 44, 18, 'PEPP Learning', true);
    }

    $pdf->text($L, 48, 9, 'L&D Operations Work Report', false, 'R', $W);
    $pdf->text($L, 60, 9, 'Generated: ' . date('d-m-Y h:i A'), false, 'R', $W);
    $pdf->text($L, 95, 14, 'L&D OPERATIONS WORK REPORT', true, 'C', $W);

    $y = 120;
    $pdf->line($L, $y, $R, $y); $y += 12;

    // Summary table
    $pdf->text($L, $y, 10, 'Summary Metrics', true); $y += 16;
    $pdf->text($L, $y, 9, 'Total Task Logs: ' . $total_tasks);
    $pdf->text($L + 120, $y, 9, 'Total Topics: ' . $total_topics);
    $pdf->text($L + 220, $y, 9, 'Active Days: ' . $active_days);
    $pdf->text($L + 300, $y, 9, 'Total Charge: INR ' . number_format($total_charge_sum, 2));
    $y += 14;
    $pdf->text($L, $y, 9, 'Avg Topics/Active Day: ' . ($active_days > 0 ? number_format($total_topics / $active_days, 1) : '0'));
    $y += 16;
    $pdf->line($L, $y, $R, $y); $y += 16;

    // Course breakdown table
    $pdf->text($L, $y, 10, 'Course Breakdown (Topics completed)', true); $y += 14;
    $pdf->line($L, $y, $R, $y); $y += 8;
    foreach ($course_breakdown as $c_name => $c_cnt) {
        if ($y > 760) { $pdf->line($L, $y, $R, $y); $y = 50; }
        $pdf->text($L, $y, 9, $c_name);
        $pdf->text($R - 50, $y, 9, $c_cnt, false, 'R');
        $y += 14;
    }
    $y += 10;
    $pdf->line($L, $y, $R, $y); $y += 16;

    // Mode breakdown table
    $pdf->text($L, $y, 10, 'Work Mode Breakdown (Topics completed)', true); $y += 14;
    $pdf->line($L, $y, $R, $y); $y += 8;
    foreach ($mode_breakdown as $m_name => $m_cnt) {
        if ($y > 760) { $pdf->line($L, $y, $R, $y); $y = 50; }
        $pdf->text($L, $y, 9, $m_name);
        $pdf->text($R - 50, $y, 9, $m_cnt, false, 'R');
        $y += 14;
    }
    $y += 10;
    $pdf->line($L, $y, $R, $y); $y += 20;

    // Daily activity detail list (Up to 15 rows to fit pages)
    $pdf->text($L, $y, 10, 'Recent Activity Logs', true); $y += 14;
    $pdf->line($L, $y, $R, $y); $y += 8;

    $pdf->text($L, $y, 8.5, 'Date/Time', true);
    $pdf->text($L + 80, $y, 8.5, 'Staff', true);
    $pdf->text($L + 180, $y, 8.5, 'Course', true);
    $pdf->text($L + 280, $y, 8.5, 'Mode', true);
    $pdf->text($L + 380, $y, 8.5, 'Topics', true, 'R', 40);
    $pdf->text($R, $y, 8.5, 'Charge (₹)', true, 'R');
    $y += 8; $pdf->line($L, $y, $R, $y); $y += 10;

    $limit = 15;
    $count = 0;
    foreach ($tasks as $tk) {
        if ($count >= $limit) break;
        if ($y > 760) { $pdf->line($L, $y, $R, $y); $y = 50; }

        $task_charge = 0.00;
        $has_incomplete = false;
        foreach ($tk['topics'] as $tp) {
            if ($tp['quantity'] === null) $has_incomplete = true;
            $task_charge += (float)$tp['calculated_charge'];
        }

        $pdf->text($L, $y, 8, date('d-m-y H:i', strtotime($tk['created_at'])));
        $pdf->text($L + 80, $y, 8, substr($tk['admin_name'], 0, 18));
        $pdf->text($L + 180, $y, 8, substr($tk['course_name'], 0, 18));
        $pdf->text($L + 280, $y, 8, substr($tk['mode_name_snapshot'] ?: $tk['mode_name'], 0, 18));
        $pdf->text($L + 380, $y, 8, count($tk['topics']), false, 'R', 40);

        $charge_display = $has_incomplete ? 'Incomplete' : '₹' . number_format($task_charge, 2);
        $pdf->text($R, $y, 8, $charge_display, false, 'R');

        $y += 14;
        $count++;
    }

    $y += 10;
    $pdf->line($L, $y, $R, $y); $y += 12;
    $pdf->text($L, $y, 8, 'PEPP Learning Operations · office@pepplearning.com · Confidential Report', false, 'C', $W);

    $bytes = $pdf->output();
    $fname = 'ld-work-report-' . date('Y-m-d-Hi') . '.pdf';

    log_admin_activity($pdo, $admin_username, 'data_export', "Exported L&D Work PDF report");

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit();
}

// Page query variables
$stats_error = '';
$total_tasks = 0;
$total_topics = 0;
$active_days = 0;
$courses_worked = 0;
$modes_used = 0;
$total_charge_sum = 0.00;

try {
    // 1. Aggregated Metrics
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT t.id) AS total_tasks,
            COUNT(tp.id) AS total_topics,
            COUNT(DISTINCT DATE(t.created_at)) AS active_days,
            COUNT(DISTINCT t.course_id) AS courses_worked,
            COUNT(DISTINCT t.mode_id) AS modes_used,
            SUM(tp.calculated_charge) AS total_charge
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql
    ");
    $stmt->execute($params);
    $totals = $stmt->fetch();

    $total_tasks = $totals['total_tasks'] ?? 0;
    $total_topics = $totals['total_topics'] ?? 0;
    $active_days = $totals['active_days'] ?? 0;
    $courses_worked = $totals['courses_worked'] ?? 0;
    $modes_used = $totals['modes_used'] ?? 0;
    $total_charge_sum = (float)($totals['total_charge'] ?? 0.00);

} catch (Exception $e) {
    error_log("Report stats error: " . $e->getMessage());
    $stats_error = 'Error calculating summary metrics.';
}

// 2. Fetch Detail Records (Paginated)
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;
$detail_rows = [];
$total_rows = 0;

try {
    // Count total rows matching filters for pagination
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM ld_tasks t $where_sql");
    $stmt->execute($params);
    $total_rows = (int)$stmt->fetchColumn();

    // Fetch records
    $stmt = $pdo->prepare("
        SELECT t.*
        FROM ld_tasks t
        $where_sql
        ORDER BY t.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $detail_rows = $stmt->fetchAll();

    if (!empty($detail_rows)) {
        $task_ids = array_map(function($x) { return (int)$x['id']; }, $detail_rows);
        $in_clause = implode(',', $task_ids);
        $topics_rows = $pdo->query("SELECT * FROM ld_task_topics WHERE task_id IN ($in_clause) ORDER BY id ASC")->fetchAll();

        $topics_by_task = [];
        foreach ($topics_rows as $row) {
            $topics_by_task[$row['task_id']][] = $row;
        }

        foreach ($detail_rows as &$t) {
            $t['topics'] = $topics_by_task[$t['id']] ?? [];
        }
        unset($t);
    }
} catch (Exception $e) {
    error_log("Report details error: " . $e->getMessage());
}

// 3. Fetch Chart Data
$chart_daily_labels = [];
$chart_daily_data = [];
$chart_weekly_labels = [];
$chart_weekly_data = [];
$chart_monthly_labels = [];
$chart_monthly_data = [];
$chart_course_labels = [];
$chart_course_data = [];
$chart_mode_labels = [];
$chart_mode_data = [];

try {
    // Daily Productivity (Last 7 Days)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(t.created_at, '%d %b') AS day_label, COUNT(tp.id) AS topic_cnt
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(t.created_at)
        ORDER BY DATE(t.created_at) ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chart_daily_labels[] = $row['day_label'];
        $chart_daily_data[] = (int)$row['topic_cnt'];
    }

    // Weekly Productivity (Last 4 Weeks)
    $stmt = $pdo->prepare("
        SELECT YEARWEEK(t.created_at) AS wk, CONCAT('Wk ', WEEK(t.created_at)) AS wk_label, COUNT(tp.id) AS topic_cnt
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql AND t.created_at >= DATE_SUB(NOW(), INTERVAL 4 WEEK)
        GROUP BY YEARWEEK(t.created_at)
        ORDER BY YEARWEEK(t.created_at) ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chart_weekly_labels[] = $row['wk_label'];
        $chart_weekly_data[] = (int)$row['topic_cnt'];
    }

    // Monthly Productivity (Last 6 Months)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(t.created_at, '%b %y') AS mon_label, COUNT(tp.id) AS topic_cnt
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql AND t.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(t.created_at, '%Y-%m')
        ORDER BY DATE(t.created_at) ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chart_monthly_labels[] = $row['mon_label'];
        $chart_monthly_data[] = (int)$row['topic_cnt'];
    }

    // Course Distribution
    $stmt = $pdo->prepare("
        SELECT t.course_name, COUNT(tp.id) AS topic_cnt
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql
        GROUP BY t.course_id
        ORDER BY topic_cnt DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chart_course_labels[] = $row['course_name'];
        $chart_course_data[] = (int)$row['topic_cnt'];
    }

    // Work Mode Distribution
    $stmt = $pdo->prepare("
        SELECT t.mode_name, COUNT(tp.id) AS topic_cnt
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql
        GROUP BY t.mode_id
        ORDER BY topic_cnt DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chart_mode_labels[] = $row['mode_name'];
        $chart_mode_data[] = (int)$row['topic_cnt'];
    }

} catch (Exception $e) {
    error_log("Chart load error: " . $e->getMessage());
}

}

if ($tab === 'payments') {
    $page_title = 'L&D Intern Payments';
    $page_sub   = 'Process and view payment vouchers for L&D Interns';
} else {
    $page_title = 'L&D Operations Work Report';
    $page_sub   = 'Operational L&D stats, charts and logs';
}
$active_page = 'ld-work-report';
include 'includes/admin_nav.php';
?>

<style>
.report-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}
.report-grid-two {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
@media (max-width: 992px) {
    .report-grid-two {
        grid-template-columns: 1fr;
    }
}
.stats-card-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.stats-card {
    background: var(--card);
    color: var(--card-foreground);
    border: 1px solid var(--border);
    padding: 18px;
    border-radius: 12px;
    box-shadow: 0 4px 14px rgba(22, 78, 99, 0.05);
    transition: transform 0.2s ease;
}
.stats-card:hover {
    transform: translateY(-2px);
}
.stats-card h3 {
    margin: 0;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.8;
}
.stats-card p {
    margin: 8px 0 0 0;
    font-size: 1.8rem;
    font-weight: 700;
}
.timeline-card-mobile {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
}
.desktop-view {
    display: block;
}
.mobile-view {
    display: none;
}
.chart-box {
    background: #ffffff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.03);
}
.filter-bar.report-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: flex-end;
}
.filter-bar.report-filters .field {
    margin: 0;
}

@media (max-width: 768px) {
    .desktop-view {
        display: none;
    }
    .mobile-view {
        display: block;
    }
    .stats-card-list {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .stats-card {
        padding: 12px;
    }
    .stats-card h3 {
        font-size: 0.7rem;
    }
    .stats-card p {
        font-size: 1.4rem;
    }
    .panel-head {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 12px;
    }
    .panel-head .head-right {
        margin-left: 0 !important;
        width: 100%;
        justify-content: flex-start;
    }
    .panel-head h2 {
        font-size: 0.95rem;
    }
    .filter-bar.report-filters {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 12px !important;
    }
    .filter-bar.report-filters .field {
        min-width: 0 !important;
        width: 100% !important;
    }
    .filter-bar.report-filters .custom-period {
        grid-column: span 1 !important;
    }
    .filter-bar.report-filters .filter-actions {
        grid-column: span 2 !important;
        display: flex;
        gap: 8px;
        margin-top: 8px;
    }
    .filter-bar.report-filters .filter-actions button,
    .filter-bar.report-filters .filter-actions a {
        flex: 1;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .filter-bar.report-filters {
        grid-template-columns: 1fr !important;
    }
    .filter-bar.report-filters .filter-actions {
        grid-column: span 1 !important;
    }
}
</style><div class="tabs" style="margin-bottom:18px;">
    <a class="tab <?php echo $tab === 'report' ? 'active' : ''; ?>" href="?tab=report"><i class="fas fa-chart-line"></i> L&D Work Report</a>
    <a class="tab <?php echo $tab === 'payments' ? 'active' : ''; ?>" href="?tab=payments"><i class="fas fa-indian-rupee-sign"></i> L&D Intern Payments</a>
</div>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<?php if ($tab === 'report'): ?>
<div class="report-grid">
    <!-- Filters Panel -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon"><i class="fas fa-filter"></i></span>
            <h2>Filter Work Reports</h2>
        </div>
        <div class="panel-body">
            <form method="GET" class="filter-bar report-filters">
                <div class="field" style="margin: 0; min-width: 140px;">
                    <label>Staff</label>
                    <select name="staff">
                        <option value="">- All Staff -</option>
                        <?php foreach ($staff_list as $st): ?>
                            <option value="<?php echo e($st['admin_username']); ?>" <?php echo $f_staff === $st['admin_username'] ? 'selected' : ''; ?>><?php echo e($st['admin_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" style="margin: 0; min-width: 120px;">
                    <label>Role</label>
                    <select name="role">
                        <option value="">- All Roles -</option>
                        <option value="super_admin" <?php echo $f_role === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                        <option value="admin" <?php echo $f_role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>

                <div class="field" style="margin: 0; min-width: 140px;">
                    <label>Course</label>
                    <select name="course">
                        <option value="0">- All Courses -</option>
                        <?php foreach ($courses_filter as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo $f_course === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['course_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" style="margin: 0; min-width: 140px;">
                    <label>Work Mode</label>
                    <select name="mode">
                        <option value="0">- All Modes -</option>
                        <?php foreach ($modes_filter as $m): ?>
                            <option value="<?php echo (int)$m['id']; ?>" <?php echo $f_mode === (int)$m['id'] ? 'selected' : ''; ?>><?php echo e($m['mode_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" style="margin: 0; min-width: 120px;">
                    <label>Period</label>
                    <select name="period" onchange="togglePeriodFields(this.value)">
                        <option value="overall" <?php echo $f_period === 'overall' ? 'selected' : ''; ?>>Overall</option>
                        <option value="today" <?php echo $f_period === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="weekly" <?php echo $f_period === 'weekly' ? 'selected' : ''; ?>>Weekly (Last 7d)</option>
                        <option value="monthly" <?php echo $f_period === 'monthly' ? 'selected' : ''; ?>>Monthly (Last 30d)</option>
                        <option value="custom" <?php echo $f_period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    </select>
                </div>

                <div class="field custom-period" style="margin: 0; min-width: 130px; display: <?php echo $f_period === 'custom' ? 'block' : 'none'; ?>;">
                    <label>From Date</label>
                    <input type="date" name="from" value="<?php echo e($f_from); ?>">
                </div>

                <div class="field custom-period" style="margin: 0; min-width: 130px; display: <?php echo $f_period === 'custom' ? 'block' : 'none'; ?>;">
                    <label>To Date</label>
                    <input type="date" name="to" value="<?php echo e($f_to); ?>">
                </div>

                <div class="field" style="margin: 0; min-width: 100px;">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?php echo $f_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="deleted" <?php echo $f_status === 'deleted' ? 'selected' : ''; ?>>Deleted</option>
                        <option value="all" <?php echo $f_status === 'all' ? 'selected' : ''; ?>>All (Active + Deleted)</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Filter</button>
                    <a href="ld-work-report.php" class="btn btn-outline" title="Clear Filters"><i class="fas fa-rotate"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Metrics Dashboard -->
    <div class="stats-card-list">
        <div class="stats-card">
            <h3>Task Records</h3>
            <p><?php echo $total_tasks; ?></p>
        </div>
        <div class="stats-card">
            <h3>Completed Topics</h3>
            <p><?php echo $total_topics; ?></p>
        </div>
        <div class="stats-card">
            <h3>Active Days</h3>
            <p><?php echo $active_days; ?></p>
        </div>
        <div class="stats-card">
            <h3>Courses Worked</h3>
            <p><?php echo $courses_worked; ?></p>
        </div>
        <div class="stats-card">
            <h3>Work Modes Used</h3>
            <p><?php echo $modes_used; ?></p>
        </div>
        <div class="stats-card">
            <h3>Avg Topics/Day</h3>
            <p><?php echo $active_days > 0 ? number_format($total_topics / $active_days, 1) : '0.0'; ?></p>
        </div>
        <div class="stats-card" style="background: linear-gradient(135deg, #10b981, #059669); color: #ffffff;">
            <h3 style="color: rgba(255,255,255,0.85); font-weight:600;">Total L&D Charge</h3>
            <p style="margin-top: 4px;">₹<?php echo number_format($total_charge_sum, 2); ?></p>
        </div>
    </div>

    <!-- Charts and Breakdown Visualizations -->
    <div class="report-grid-two">
        <div class="chart-box">
            <h3 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 12px; color: var(--primary);"><i class="fas fa-chart-line"></i> Productivity Over Time</h3>
            <div style="height:250px; position:relative;">
                <canvas id="productivityChart"></canvas>
            </div>
            <div style="display:flex; justify-content:center; gap:16px; margin-top:10px;">
                <label style="font-size:0.8rem; cursor:pointer;"><input type="radio" name="timeframe" value="daily" checked onchange="updateProductivityChart(this.value)"> Daily</label>
                <label style="font-size:0.8rem; cursor:pointer;"><input type="radio" name="timeframe" value="weekly" onchange="updateProductivityChart(this.value)"> Weekly</label>
                <label style="font-size:0.8rem; cursor:pointer;"><input type="radio" name="timeframe" value="monthly" onchange="updateProductivityChart(this.value)"> Monthly</label>
            </div>
        </div>

        <div class="chart-box">
            <h3 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 12px; color: var(--primary);"><i class="fas fa-chart-pie"></i> Distribution Breakdown</h3>
            <div style="height:250px; position:relative;">
                <canvas id="distributionChart"></canvas>
            </div>
            <div style="display:flex; justify-content:center; gap:16px; margin-top:10px;">
                <label style="font-size:0.8rem; cursor:pointer;"><input type="radio" name="distType" value="course" checked onchange="updateDistributionChart(this.value)"> Courses</label>
                <label style="font-size:0.8rem; cursor:pointer;"><input type="radio" name="distType" value="mode" onchange="updateDistributionChart(this.value)"> Work Modes</label>
            </div>
        </div>
    </div>

    <!-- Details List -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-list"></i></span>
            <h2>Activity Logs</h2>
            <div class="head-right" style="display:flex; gap:8px;">
                <!-- Respect Active Filters inside URL -->
                <?php
                $query_str = http_build_query($_GET);
                $csv_url = "ld-work-report.php?export=csv" . ($query_str ? '&' . $query_str : '');
                $pdf_url = "ld-work-report.php?export=pdf" . ($query_str ? '&' . $query_str : '');
                ?>
                <a href="<?php echo $csv_url; ?>" class="btn btn-sm btn-outline"><i class="fas fa-file-excel"></i> Export CSV</a>
                <a href="<?php echo $pdf_url; ?>" class="btn btn-sm btn-outline"><i class="fas fa-file-pdf"></i> Export PDF</a>
            </div>
        </div>
        <div class="panel-body">
            <!-- Desktop Layout -->
            <div class="desktop-view table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Staff Name</th>
                            <th>Course</th>
                            <th>Work Mode</th>
                            <th>Completed Topics (Details)</th>
                            <th>Amount</th>
                            <th>Location</th>
                            <th>Status</th>
                            <?php if (is_super_admin()): ?>
                                <th style="text-align:right;">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detail_rows as $row):
                            $task_amount = 0.00;
                            $has_incomplete = false;
                            foreach ($row['topics'] as $tp) {
                                if ($tp['quantity'] === null) {
                                    $has_incomplete = true;
                                }
                                $task_amount += (float)$tp['calculated_charge'];
                            }
                            $display_mode_name = $row['mode_name_snapshot'] ?: $row['mode_name'];
                        ?>
                            <tr>
                                <td style="white-space:nowrap; font-size:0.8rem;">
                                    <div class="cell-main"><?php echo date('d M Y', strtotime($row['created_at'])); ?></div>
                                    <div class="cell-sub"><?php echo date('h:i A', strtotime($row['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="cell-main"><?php echo e($row['admin_name']); ?></div>
                                    <div class="cell-sub">Role: <?php echo ucfirst(e($row['admin_role'])); ?></div>
                                </td>
                                <td><?php echo e($row['course_name']); ?></td>
                                <td><?php echo e($display_mode_name); ?></td>
                                <td>
                                    <ul style="padding-left: 14px; list-style-type: disc; font-size: 0.8rem; margin: 0;">
                                        <?php foreach ($row['topics'] as $tp): ?>
                                            <li>
                                                <strong><?php echo e($tp['topic_name']); ?></strong>
                                                <?php if ($tp['quantity'] !== null): ?>
                                                    <span style="color:var(--text-muted); font-size:0.75rem;">
                                                        (<?php echo (float)$tp['quantity']; ?> <?php echo e($row['quantity_label_snapshot'] ?? 'units'); ?>
                                                        <?php if ($row['charge_per_quantity_snapshot'] !== null): ?>
                                                            @ ₹<?php echo number_format((float)$row['charge_per_quantity_snapshot'], 2); ?>
                                                        <?php endif; ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color:var(--destructive); font-size:0.75rem;">(Quantity not added)</span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                                <td style="font-weight:700; color:var(--success);">
                                    <?php if ($has_incomplete): ?>
                                        <span class="badge gray" style="font-size:0.7rem; padding:2px 6px;">Incomplete</span>
                                    <?php else: ?>
                                        ₹<?php echo number_format($task_amount, 2); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo e($row['maps_url']); ?>" target="_blank" class="btn btn-sm btn-soft-violet" title="View location"><i class="fas fa-map-location-dot"></i> View Map</a>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'active'): ?>
                                        <span class="badge green">Active</span>
                                    <?php else: ?>
                                        <span class="badge red" title="Deleted Reason: <?php echo e($row['deleted_reason']); ?>">Deleted</span>
                                        <div class="cell-sub" style="font-size:0.72rem; margin-top:2px;">By: <?php echo e($row['deleted_by']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <?php if (is_super_admin()): ?>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <?php
                                        $lock_info = is_ld_task_locked($pdo, $row['admin_id'], date('Y-m-d', strtotime($row['created_at'])));
                                        if ($lock_info):
                                        ?>
                                            <span style="font-weight:700; color:var(--text-muted); font-size:0.85rem; display:inline-flex; align-items:center; gap:4px;">
                                                <i class="fas fa-lock" style="color:var(--success-ink);"></i> Locked (Paid)
                                            </span>
                                        <?php elseif ($row['status'] === 'deleted'): ?>
                                            <span style="color:var(--text-muted); font-size:0.8rem;">No Actions</span>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-soft-amber" onclick='openEditTaskModal(<?php echo json_encode([
                                                "id" => (int)$row["id"],
                                                "date" => date("d M Y, h:i A", strtotime($row["created_at"])),
                                                "staff" => $row["admin_name"],
                                                "course" => $row["course_name"],
                                                "mode" => $display_mode_name,
                                                "qty_label" => $row["quantity_label_snapshot"],
                                                "topics" => array_map(function($tp) {
                                                    return [
                                                        "name" => $tp["topic_name"],
                                                        "qty" => $tp["quantity"] !== null ? (float)$tp["quantity"] : ""
                                                    ];
                                                }, $row["topics"])
                                            ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'>
                                                <i class="fas fa-pen"></i> Edit
                                            </button>
                                            <button class="btn btn-sm btn-soft-red" onclick="openDeleteTaskModal(<?php echo (int)$row['id']; ?>)">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($detail_rows)): ?>
                            <tr><td colspan="<?php echo is_super_admin() ? 9 : 8; ?>"><div class="empty-state" style="padding:24px;"><p>No task entries match the selected filters.</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Layout -->
            <div class="mobile-view">
                <?php foreach ($detail_rows as $row):
                    $task_amount = 0.00;
                    $has_incomplete = false;
                    foreach ($row['topics'] as $tp) {
                        if ($tp['quantity'] === null) {
                            $has_incomplete = true;
                        }
                        $task_amount += (float)$tp['calculated_charge'];
                    }
                    $display_mode_name = $row['mode_name_snapshot'] ?: $row['mode_name'];
                ?>
                    <div class="timeline-card-mobile">
                        <div style="display:flex; justify-content:space-between; align-items:start;">
                            <div>
                                <strong style="font-size:0.9rem; color:var(--primary);"><?php echo e($row['admin_name']); ?></strong>
                                <div style="font-size:0.75rem; color:var(--foreground); opacity:0.85;">Role: <?php echo ucfirst(e($row['admin_role'])); ?></div>
                            </div>
                            <div style="text-align:right;">
                                <?php if ($row['status'] === 'active'): ?>
                                    <span class="badge green">Active</span>
                                <?php else: ?>
                                    <span class="badge red">Deleted</span>
                                <?php endif; ?>
                                <div style="font-weight:700; color:var(--success); font-size:0.85rem; margin-top:4px;">
                                    <?php if ($has_incomplete): ?>
                                        <span class="badge gray" style="font-size:0.65rem; padding:1px 4px;">Incomplete</span>
                                    <?php else: ?>
                                        ₹<?php echo number_format($task_amount, 2); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:8px; font-size:0.82rem;">
                            <div><strong>Course:</strong> <?php echo e($row['course_name']); ?></div>
                            <div><strong>Mode:</strong> <?php echo e($display_mode_name); ?></div>
                        </div>
                        <ul style="margin-top:6px; padding-left:14px; list-style-type:disc; font-size:0.8rem;">
                            <?php foreach ($row['topics'] as $tp): ?>
                                <li>
                                    <?php echo e($tp['topic_name']); ?>
                                    <?php if ($tp['quantity'] !== null): ?>
                                        <span style="color:var(--text-muted); font-size:0.75rem;">
                                            (<?php echo (float)$tp['quantity']; ?> <?php echo e($row['quantity_label_snapshot'] ?? 'units'); ?>
                                            <?php if ($row['charge_per_quantity_snapshot'] !== null): ?>
                                                @ ₹<?php echo number_format((float)$row['charge_per_quantity_snapshot'], 2); ?>
                                            <?php endif; ?>)
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--destructive); font-size:0.75rem;">(Quantity not added)</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div style="margin-top:10px; font-size:0.75rem; border-top:1px solid rgba(22, 78, 99, 0.05); padding-top:8px; display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">
                            <div><i class="fas fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?></div>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <a href="<?php echo e($row['maps_url']); ?>" target="_blank" class="btn btn-sm btn-soft-violet" style="padding:2px 8px; font-size:0.72rem;"><i class="fas fa-map-location-dot"></i> Map</a>
                                <?php if (is_super_admin()): ?>
                                    <?php
                                    $lock_info = is_ld_task_locked($pdo, $row['admin_id'], date('Y-m-d', strtotime($row['created_at'])));
                                    if ($lock_info):
                                    ?>
                                        <span style="font-weight:700; color:var(--text-muted); font-size:0.72rem; display:inline-flex; align-items:center; gap:2px;"><i class="fas fa-lock" style="color:var(--success-ink);"></i> Locked (Paid)</span>
                                    <?php elseif ($row['status'] !== 'deleted'): ?>
                                        <button type="button" class="btn btn-sm btn-soft-amber" style="padding:2px 8px; font-size:0.72rem;" onclick='openEditTaskModal(<?php echo json_encode([
                                            "id" => (int)$row["id"],
                                            "date" => date("d M Y, h:i A", strtotime($row["created_at"])),
                                            "staff" => $row["admin_name"],
                                            "course" => $row["course_name"],
                                            "mode" => $display_mode_name,
                                            "qty_label" => $row["quantity_label_snapshot"],
                                            "topics" => array_map(function($tp) {
                                                return [
                                                    "name" => $tp["topic_name"],
                                                    "qty" => $tp["quantity"] !== null ? (float)$tp["quantity"] : ""
                                                ];
                                            }, $row["topics"])
                                        ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i> Edit</button>
                                        <button type="button" class="btn btn-sm btn-soft-red" style="padding:2px 8px; font-size:0.72rem;" onclick="openDeleteTaskModal(<?php echo (int)$row['id']; ?>)"><i class="fas fa-trash"></i> Delete</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($detail_rows)): ?>
                    <div class="empty-state" style="padding:24px;"><p>No task entries match the selected filters.</p></div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_rows > $limit):
                $total_pages = ceil($total_rows / $limit);
                $q = $_GET;
            ?>
                <div style="display:flex; justify-content:center; gap:8px; margin-top:20px;">
                    <?php if ($page > 1): $q['page'] = $page - 1; ?>
                        <a href="ld-work-report.php?<?php echo http_build_query($q); ?>" class="btn btn-sm btn-outline"><i class="fas fa-chevron-left"></i> Previous</a>
                    <?php endif; ?>
                    <span class="btn btn-sm btn-soft-violet" style="pointer-events:none;">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                    <?php if ($page < $total_pages): $q['page'] = $page + 1; ?>
                        <a href="ld-work-report.php?<?php echo http_build_query($q); ?>" class="btn btn-sm btn-outline">Next <i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; // End of if ($tab === 'report') ?>

<?php if ($tab === 'payments'): ?>
    <!-- Payments Dashboard -->
    <div class="report-grid">
        <!-- Interns Summary Table -->
        <div class="panel">
            <div class="panel-head">
                <span class="head-icon" style="background:var(--success-soft);color:var(--success-ink);"><i class="fas fa-users-viewfinder"></i></span>
                <h2>L&D Interns Payment Summary</h2>
            </div>
            <div class="panel-body">
                <div class="desktop-view table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Intern Name</th>
                                <th>Joining Date</th>
                                <th>Total Expected Charge</th>
                                <th>Total Paid Amount</th>
                                <th>Pending Balance</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($intern_payouts as $ip): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo e($ip['full_name']); ?></strong><br>
                                        <small style="color:var(--text-muted);">@<?php echo e($ip['username']); ?></small>
                                        <?php if ($ip['status'] === 'inactive'): ?>
                                            <span class="badge red" style="font-size:0.65rem; padding:1px 4px; margin-left:4px;">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d-m-Y', strtotime($ip['joining_date'])); ?></td>
                                    <td>₹<?php echo number_format($ip['expected'], 2); ?></td>
                                    <td>₹<?php echo number_format($ip['paid'], 2); ?></td>
                                    <td style="font-weight: 700; color: <?php echo $ip['pending'] > 0 ? 'var(--destructive)' : 'var(--success)'; ?>;">
                                        ₹<?php echo number_format($ip['pending'], 2); ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <button class="btn btn-sm btn-primary" onclick="openPayModal(<?php echo htmlspecialchars(json_encode($ip)); ?>)">
                                            <i class="fas fa-indian-rupee-sign"></i> Pay
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($intern_payouts)): ?>
                                <tr>
                                    <td colspan="6" class="empty-state" style="text-align:center;">No L&D Interns found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Completed Payments Section -->
        <div class="panel">
            <div class="panel-head">
                <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-receipt"></i></span>
                <h2>Completed Payments History</h2>
            </div>
            <div class="panel-body">
                <div class="desktop-view table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Voucher No</th>
                                <th>Intern Name</th>
                                <th>Payment Period</th>
                                <th>Paid Date</th>
                                <th>Paid Amount</th>
                                <th>Payment Account</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completed_payments as $cp): ?>
                                <tr>
                                    <td><strong><?php echo e($cp['voucher_no']); ?></strong></td>
                                    <td><?php echo e($cp['intern_name_snapshot']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($cp['period_start_date'])) . ' to ' . date('d/m/Y', strtotime($cp['period_end_date'])); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($cp['paid_date'])); ?></td>
                                    <td><strong>₹<?php echo number_format($cp['paid_amount'], 2); ?></strong></td>
                                    <td><?php echo e($cp['payment_account_name_snapshot'] ?: 'N/A'); ?></td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <a href="ld-payment-voucher.php?id=<?php echo $cp['id']; ?>" target="_blank" class="btn btn-sm btn-soft-violet">
                                            <i class="fas fa-file-invoice"></i> View Voucher
                                        </a>
                                        <?php if ($cp['screenshot_path']): ?>
                                            <a href="download-ld-payment-proof.php?id=<?php echo (int)$cp['id']; ?>" target="_blank" class="btn btn-sm btn-soft-blue" title="View Screenshot">
                                                <i class="fas fa-image"></i> Screenshot
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($completed_payments)): ?>
                                <tr>
                                    <td colspan="7" class="empty-state" style="text-align:center;">No completed payments recorded.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Pay Modal -->
    <div class="modal-backdrop" id="pay-modal">
        <div class="modal" style="max-width:600px; width: 95%;">
            <div class="modal-head">
                <h3><i class="fas fa-money-bill-transfer" style="color:var(--accent);"></i> Process Intern Payout</h3>
                <button class="modal-close" onclick="closeModal('pay-modal')"><i class="fas fa-xmark"></i></button>
            </div>
            <form id="payout-form" method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="intern_id" id="modal-intern-id">

                <div class="modal-body">
                    <div class="alert alert-info" style="display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-user-check"></i>
                        <div>
                            <strong id="modal-intern-name">Intern</strong>
                            (<span id="modal-intern-username">@username</span>) -
                            Joining: <span id="modal-intern-joining">Joining Date</span>
                        </div>
                    </div>

                    <div id="modal-alert-box" style="margin-bottom:12px;"></div>

                    <div class="form-grid">
                        <div class="field">
                            <label>Period Start Date <span class="req">*</span></label>
                            <input type="date" name="period_start" id="modal-period-start" required onchange="onPeriodDateChange()">
                        </div>
                        <div class="field">
                            <label>Period End Date <span class="req">*</span></label>
                            <input type="date" name="period_end" id="modal-period-end" required onchange="onPeriodDateChange()">
                        </div>
                        <div class="field full" style="margin-top: 5px;">
                            <button type="button" class="btn btn-sm btn-outline" id="btn-calculate" onclick="calculateExpectedAmount()" style="width: 100%; justify-content: center;">
                                <i class="fas fa-calculator"></i> Calculate Expected Amount & Work Details
                            </button>
                        </div>

                        <!-- Work Aggregation Preview -->
                        <div class="field full" id="work-preview-container" style="display:none; background:var(--bg-muted); padding:12px; border-radius:8px; border:1px solid var(--border);">
                            <label style="font-weight: 700; margin-bottom: 6px;"><i class="fas fa-cubes"></i> Aggregated Work Completed</label>
                            <div id="work-preview-list" style="font-size:0.85rem; line-height:1.5;"></div>
                        </div>

                        <div class="field">
                            <label>Expected Amount (₹)</label>
                            <input type="text" id="modal-expected-display" readonly style="background:var(--bg-muted); font-weight:700;" value="₹0.00">
                            <input type="hidden" name="expected_amount" id="modal-expected-val" value="0.00">
                        </div>
                        <div class="field">
                            <label>Adjustment Amount (₹)</label>
                            <input type="number" step="0.01" name="adjustment_amount" id="modal-adjustment" value="0.00" oninput="updateFinalPayout()">
                        </div>
                        <div class="field">
                            <label>Final Paid Amount (₹)</label>
                            <input type="text" id="modal-final-display" readonly style="background:var(--bg-muted); font-weight:700; color:var(--success-ink);" value="₹0.00">
                        </div>
                        <div class="field">
                            <label>Payment Account <span class="req">*</span></label>
                            <select name="payment_account_id" required>
                                <option value="">- Select Account -</option>
                                <?php foreach ($payment_accounts as $pa): ?>
                                    <option value="<?php echo (int)$pa['id']; ?>"><?php echo e($pa['account_name']); ?> (<?php echo e($pa['account_type']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Paid Date <span class="req">*</span></label>
                            <input type="date" name="paid_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="field full">
                            <label>Payment Screenshot (Screenshot/PDF)</label>
                            <input type="file" name="screenshot" accept="image/*,.pdf">
                        </div>

                        <div class="field full">
                            <label>Remarks</label>
                            <textarea name="remarks" id="modal-remarks" rows="2" style="width: 100%; border: 1px solid var(--border); border-radius: 6px; padding: 8px; font-family: inherit; font-size: 0.9rem;" placeholder="Optional transaction notes..."></textarea>
                        </div>

                        <!-- Legacy/Incomplete Tasks Warning -->
                        <div class="field full" id="legacy-warning-container" style="display:none; background:#fee2e2; border:1px solid #fca5a5; padding:12px; border-radius:8px; color:#991b1b;">
                            <label style="font-weight: 700; margin-bottom: 6px;"><i class="fas fa-triangle-exclamation"></i> Tasks with Missing/Invalid Quantities</label>
                            <div id="legacy-warning-list" style="font-size:0.85rem; line-height:1.5;"></div>
                        </div>

                        <!-- Overlap Block Warning (hidden by default) -->
                        <div class="field full" id="overlap-warning-field" style="display:none; background:#fee2e2; border:1px solid #fca5a5; padding:12px; border-radius:8px; color:#991b1b;">
                            <div style="display:flex; align-items:flex-start; gap:8px;">
                                <i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i>
                                <div>
                                    <strong style="display:block; margin-bottom:4px; font-weight:700;">Overlapping Period Blocked</strong>
                                    <span id="overlap-warning-text"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-outline" onclick="closeModal('pay-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-submit-payout"><i class="fas fa-check"></i> Record Payout</button>
                </div>
            </form>
        </div>
    </div>


    <!-- JS for Payout Dashboard -->
    <script>
    var selectedIntern = null;
    var currentExpected = 0.00;



    var selectedIntern = null;

    function openPayModal(intern) {
        selectedIntern = intern;
        document.getElementById('modal-intern-id').value = intern.id;
        document.getElementById('modal-intern-name').textContent = intern.full_name;
        document.getElementById('modal-intern-username').textContent = '@' + intern.username;
        document.getElementById('modal-intern-joining').textContent = intern.joining_date;

        // Reset fields
        document.getElementById('modal-period-start').value = '';
        document.getElementById('modal-period-end').value = '';
        document.getElementById('modal-expected-display').value = '₹0.00';
        document.getElementById('modal-expected-val').value = '0.00';
        document.getElementById('modal-adjustment').value = '0.00';
        document.getElementById('modal-final-display').value = '₹0.00';
        document.getElementById('modal-final-display').style.color = '';
        document.getElementById('modal-remarks').value = '';
        document.getElementById('work-preview-container').style.display = 'none';
        document.getElementById('legacy-warning-container').style.display = 'none';
        document.getElementById('legacy-warning-list').innerHTML = '';
        document.getElementById('overlap-warning-field').style.display = 'none';
        document.getElementById('modal-alert-box').innerHTML = '';
        document.getElementById('btn-submit-payout').disabled = false;

        currentExpected = 0.00;
        openModal('pay-modal');
    }

    function onPeriodDateChange() {
        // Clear previous calculations since dates changed
        document.getElementById('modal-expected-display').value = '₹0.00';
        document.getElementById('modal-expected-val').value = '0.00';
        document.getElementById('modal-final-display').value = '₹0.00';
        document.getElementById('work-preview-container').style.display = 'none';
        document.getElementById('legacy-warning-container').style.display = 'none';
        document.getElementById('legacy-warning-list').innerHTML = '';
        document.getElementById('overlap-warning-field').style.display = 'none';
        document.getElementById('btn-submit-payout').disabled = false;
        currentExpected = 0.00;
    }

    function calculateExpectedAmount() {
        var start = document.getElementById('modal-period-start').value;
        var end = document.getElementById('modal-period-end').value;
        var alertBox = document.getElementById('modal-alert-box');

        alertBox.innerHTML = '';
        document.getElementById('legacy-warning-container').style.display = 'none';
        document.getElementById('legacy-warning-list').innerHTML = '';

        if (!start || !end) {
            alertBox.innerHTML = '<div class="alert alert-error" style="padding: 8px 12px; font-size: 0.8rem;"><i class="fas fa-triangle-exclamation"></i> Please select both Period Start and End dates.</div>';
            return;
        }

        if (start < selectedIntern.joining_date) {
            alertBox.innerHTML = '<div class="alert alert-error" style="padding: 8px 12px; font-size: 0.8rem;"><i class="fas fa-triangle-exclamation"></i> Period Start date cannot be before intern registration date (' + selectedIntern.joining_date + ').</div>';
            return;
        }

        if (end < start) {
            alertBox.innerHTML = '<div class="alert alert-error" style="padding: 8px 12px; font-size: 0.8rem;"><i class="fas fa-triangle-exclamation"></i> End date cannot be before start date.</div>';
            return;
        }

        var today = new Date().toISOString().split('T')[0];
        if (end > today) {
            alertBox.innerHTML = '<div class="alert alert-error" style="padding: 8px 12px; font-size: 0.8rem;"><i class="fas fa-triangle-exclamation"></i> End date cannot be in the future.</div>';
            return;
        }

        // Fetch calculations
        var btn = document.getElementById('btn-calculate');
        var oldText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Calculating...';
        btn.disabled = true;

        var url = 'ld-work-report.php?tab=payments&action=calc_expected&intern_id=' + selectedIntern.id + '&start=' + start + '&end=' + end;
        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                btn.innerHTML = oldText;
                btn.disabled = false;

                if (data.success) {
                    currentExpected = parseFloat(data.expected_amount) || 0.00;
                    document.getElementById('modal-expected-display').value = '₹' + currentExpected.toFixed(2);
                    document.getElementById('modal-expected-val').value = currentExpected.toFixed(2);

                    updateFinalPayout();

                    // Render work preview aggregation
                    var previewList = document.getElementById('work-preview-list');
                    previewList.innerHTML = '';
                    if (data.work_summary && data.work_summary.length > 0) {
                        var html = '<ul style="margin: 0; padding-left: 16px; list-style-type: square;">';
                        data.work_summary.forEach(function(item) {
                            html += '<li>' + item.mode_name + ' — <strong>' + item.total_qty + ' ' + item.quantity_label + '</strong></li>';
                        });
                        html += '</ul>';
                        previewList.innerHTML = html;
                        document.getElementById('work-preview-container').style.display = 'block';
                    } else {
                        previewList.innerHTML = '<div style="color:var(--text-muted);"><i class="fas fa-info-circle"></i> No active work logs found for this period. (Expected Charge: ₹0.00)</div>';
                        document.getElementById('work-preview-container').style.display = 'block';
                        alertBox.innerHTML = '<div class="alert alert-warn" style="padding: 8px 12px; font-size: 0.8rem;"><i class="fas fa-triangle-exclamation"></i> Warning: No active tasks logged for this intern in the selected period.</div>';
                    }

                    // Render legacy warnings
                    if (data.incomplete_tasks && data.incomplete_tasks.length > 0) {
                        var legacyList = document.getElementById('legacy-warning-list');
                        var legacyHtml = '<ul style="margin: 0; padding-left: 16px; color:#991b1b;">';
                        data.incomplete_tasks.forEach(function(task) {
                            legacyHtml += '<li>Task #' + task.id + ' (' + task.task_date + ') — <strong>' + task.mode_name + '</strong> (Missing quantity for topic: "<em>' + task.topic_name + '</em>")</li>';
                        });
                        legacyHtml += '</ul><div style="margin-top:8px; font-weight:600;"><i class="fas fa-circle-info"></i> These tasks are excluded from calculation. Please ask the intern to complete them.</div>';
                        legacyList.innerHTML = legacyHtml;
                        document.getElementById('legacy-warning-container').style.display = 'block';
                    }

                    // Check for overlap after successful calculation
                    checkOverlap(start, end);
                } else {
                    alertBox.innerHTML = '<div class="alert alert-error" style="padding: 8px 12px; font-size: 0.8rem;"><i class="fas fa-triangle-exclamation"></i> ' + (data.error || 'Failed to calculate.') + '</div>';
                }
            })
            .catch(function(err) {
                btn.innerHTML = oldText;
                btn.disabled = false;
                alertBox.innerHTML = '<div class="alert alert-error" style="padding: 8px 12px; font-size: 0.8rem;"><i class="fas fa-triangle-exclamation"></i> Server connection failed.</div>';
            });
    }

    function checkOverlap(start, end) {
        var url = 'ld-work-report.php?tab=payments&action=check_overlap&intern_id=' + selectedIntern.id + '&start=' + start + '&end=' + end;
        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success && data.overlap) {
                    document.getElementById('overlap-warning-text').textContent = data.message;
                    document.getElementById('overlap-warning-field').style.display = 'block';
                    document.getElementById('btn-submit-payout').disabled = true;
                } else {
                    document.getElementById('overlap-warning-field').style.display = 'none';
                    document.getElementById('btn-submit-payout').disabled = false;
                }
            })
            .catch(function(err) {
                console.error("Failed to check overlap:", err);
            });
    }

    function updateFinalPayout() {
        var adj = parseFloat(document.getElementById('modal-adjustment').value) || 0.00;
        var finalPay = currentExpected + adj;

        var displayEl = document.getElementById('modal-final-display');
        displayEl.value = '₹' + finalPay.toFixed(2);

        if (finalPay < 0) {
            displayEl.style.color = 'var(--destructive)';
        } else {
            displayEl.style.color = 'var(--success-ink)';
        }
    }

    document.getElementById('payout-form').addEventListener('submit', function(e) {
        var adj = parseFloat(document.getElementById('modal-adjustment').value) || 0.00;
        var finalPay = currentExpected + adj;
        var alertBox = document.getElementById('modal-alert-box');

        alertBox.innerHTML = '';

        if (finalPay < 0) {
            e.preventDefault();
            alertBox.innerHTML = '<div class="alert alert-error" style="padding: 8px 12px; font-size: 0.8rem;"><i class="fas fa-triangle-exclamation"></i> Final Payout amount cannot be negative. Please adjust.</div>';
            return;
        }

        var isOverlapFieldVisible = document.getElementById('overlap-warning-field').style.display !== 'none';
        if (isOverlapFieldVisible) {
            e.preventDefault();
            alertBox.innerHTML = '<div class="alert alert-error" style="padding: 8px 12px; font-size: 0.8rem;"><i class="fas fa-triangle-exclamation"></i> Cannot record payout: Selected period overlaps with an existing completed payment.</div>';
            return;
        }

        // Prevent accidental double-submission
        var submitBtn = document.getElementById('btn-submit-payout');
        if (submitBtn && !submitBtn.disabled) {
            setTimeout(function() {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recording...';
            }, 0);
        }
    });
    </script>
<?php endif; // End of if ($tab === 'payments') ?>

<?php if ($tab === 'report'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function togglePeriodFields(val) {
    var els = document.querySelectorAll('.custom-period');
    els.forEach(function(el) {
        el.style.display = (val === 'custom') ? 'block' : 'none';
    });
}

// Chart.js Configuration
var productivityChart = null;
var distributionChart = null;

// Productivity Chart Datasets
var dailyLabels = <?php echo json_encode($chart_daily_labels); ?>;
var dailyData = <?php echo json_encode($chart_daily_data); ?>;
var weeklyLabels = <?php echo json_encode($chart_weekly_labels); ?>;
var weeklyData = <?php echo json_encode($chart_weekly_data); ?>;
var monthlyLabels = <?php echo json_encode($chart_monthly_labels); ?>;
var monthlyData = <?php echo json_encode($chart_monthly_data); ?>;

// Distribution Datasets
var courseLabels = <?php echo json_encode($chart_course_labels); ?>;
var courseData = <?php echo json_encode($chart_course_data); ?>;
var modeLabels = <?php echo json_encode($chart_mode_labels); ?>;
var modeData = <?php echo json_encode($chart_mode_data); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // 1. Initialize Productivity Chart
    var ctxProd = document.getElementById('productivityChart').getContext('2d');
    productivityChart = new Chart(ctxProd, {
        type: 'line',
        data: {
            labels: dailyLabels,
            datasets: [{
                label: 'Topics Completed',
                data: dailyData,
                borderColor: '#164e63',
                backgroundColor: 'rgba(22, 78, 99, 0.05)',
                borderWidth: 2,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // 2. Initialize Distribution Chart
    var ctxDist = document.getElementById('distributionChart').getContext('2d');
    distributionChart = new Chart(ctxDist, {
        type: 'doughnut',
        data: {
            labels: courseLabels,
            datasets: [{
                data: courseData,
                backgroundColor: [
                    '#164e63', '#0891b2', '#06b6d4', '#22d3ee', '#67e8f9',
                    '#6366f1', '#818cf8', '#a5b4fc', '#c7d2fe', '#e0e7ff'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 10 } } }
            }
        }
    });
});

function updateProductivityChart(type) {
    if (!productivityChart) return;
    if (type === 'daily') {
        productivityChart.data.labels = dailyLabels;
        productivityChart.data.datasets[0].data = dailyData;
    } else if (type === 'weekly') {
        productivityChart.data.labels = weeklyLabels;
        productivityChart.data.datasets[0].data = weeklyData;
    } else if (type === 'monthly') {
        productivityChart.data.labels = monthlyLabels;
        productivityChart.data.datasets[0].data = monthlyData;
    }
    productivityChart.update();
}

function updateDistributionChart(type) {
    if (!distributionChart) return;
    if (type === 'course') {
        distributionChart.data.labels = courseLabels;
        distributionChart.data.datasets[0].data = courseData;
    } else if (type === 'mode') {
        distributionChart.data.labels = modeLabels;
        distributionChart.data.datasets[0].data = modeData;
    }
    distributionChart.update();
}
</script>
<?php endif; // End of if ($tab === 'report') ?>

    <!-- Edit Task Modal -->
    <div class="modal-backdrop" id="edit-task-modal">
        <div class="modal" style="max-width:550px; width: 95%;">
            <div class="modal-head">
                <h3><i class="fas fa-pen-to-square" style="color:var(--accent);"></i> Edit L&D Task</h3>
                <button class="modal-close" onclick="closeModal('edit-task-modal')"><i class="fas fa-xmark"></i></button>
            </div>
            <form id="edit-task-form" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_task">
                <input type="hidden" name="task_id" id="edit-task-id">
                <input type="hidden" id="edit-quantity-label" value="">
                <div class="modal-body">
                    <div id="edit-modal-alert-container" style="display:none; margin-bottom:12px;"></div>
                    <div style="background:var(--bg-muted); padding:12px; border-radius:8px; margin-bottom:15px; border:1px solid var(--border); font-size:0.9rem;">
                        <div style="margin-bottom:6px;"><strong>Date:</strong> <span id="edit-task-date-display" style="color:var(--text-muted); font-weight:500;"></span></div>
                        <div style="margin-bottom:6px;"><strong>Staff:</strong> <span id="edit-task-staff-display" style="color:var(--text-muted); font-weight:500;"></span></div>
                        <div style="margin-bottom:6px;"><strong>Course:</strong> <span id="edit-task-course-display" style="color:var(--text-muted); font-weight:500;"></span></div>
                        <div><strong>Work Mode:</strong> <span id="edit-task-mode-display" style="color:var(--text-muted); font-weight:500;"></span></div>
                    </div>
                    <div class="field">
                        <label>Topics Completed <span class="req">*</span></label>
                        <div id="edit-topics-container">
                            <!-- Dynamic rows -->
                        </div>
                        <button type="button" class="btn btn-sm btn-outline" style="margin-top:8px;" onclick="addEditTopicRow('', '')"><i class="fas fa-plus"></i> Add Topic</button>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-outline" onclick="closeModal('edit-task-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Task Modal -->
    <div class="modal-backdrop" id="delete-task-modal">
        <div class="modal" style="max-width:450px; width: 95%;">
            <div class="modal-head">
                <h3><i class="fas fa-trash-can" style="color:var(--destructive);"></i> Delete Activity Log</h3>
                <button class="modal-close" onclick="closeModal('delete-task-modal')"><i class="fas fa-xmark"></i></button>
            </div>
            <form id="delete-task-form" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete_task">
                <input type="hidden" name="task_id" id="delete-task-id">
                <div class="modal-body">
                    <div id="delete-modal-alert-container" style="display:none; margin-bottom:12px;"></div>
                    <p style="font-size:0.92rem; margin-bottom:14px; color:#b91c1c; font-weight: 600;">
                        WARNING: This will permanently delete this activity log and all associated work details. This action cannot be undone.
                    </p>
                    <div class="field" style="margin-bottom:12px;">
                        <label>Reason for Deletion <span class="req">*</span></label>
                        <input type="text" name="delete_reason" required placeholder="e.g. Logged incorrect quantity" style="width: 100%;">
                    </div>
                    <div class="field" style="margin-bottom:12px;">
                        <label>Type DELETE to confirm <span class="req">*</span></label>
                        <input type="text" name="confirm_text" id="delete-confirm-input" autocomplete="off" oninput="onDeleteConfirmInput(this)" placeholder="Type DELETE" style="width: 100%;">
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-outline" onclick="closeModal('delete-task-modal')">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-danger" id="btn-submit-delete" disabled><i class="fas fa-trash"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JS for Edit/Delete Controls -->
    <script>
    function onDeleteConfirmInput(el) {
        var btn = document.getElementById('btn-submit-delete');
        if (el.value === 'DELETE') {
            btn.disabled = false;
        } else {
            btn.disabled = true;
        }
    }

    function openDeleteTaskModal(taskId) {
        document.getElementById('delete-task-id').value = taskId;
        var confirmInput = document.getElementById('delete-confirm-input');
        if (confirmInput) {
            confirmInput.value = '';
        }
        var btn = document.getElementById('btn-submit-delete');
        if (btn) {
            btn.disabled = true;
        }

        var alertContainer = document.getElementById('delete-modal-alert-container');
        if (alertContainer) {
            alertContainer.style.display = 'none';
            alertContainer.innerHTML = '';
        }

        openModal('delete-task-modal');
    }

    function openEditTaskModal(data) {
        document.getElementById('edit-task-id').value = data.id;
        document.getElementById('edit-task-date-display').textContent = data.date;
        document.getElementById('edit-task-staff-display').textContent = data.staff;
        document.getElementById('edit-task-course-display').textContent = data.course;
        document.getElementById('edit-task-mode-display').textContent = data.mode;
        document.getElementById('edit-quantity-label').value = data.qty_label || '';

        var container = document.getElementById('edit-topics-container');
        container.innerHTML = '';

        data.topics.forEach(function(tp) {
            addEditTopicRow(tp.name, tp.qty);
        });

        if (data.topics.length === 0) {
            addEditTopicRow('', '');
        }

        refreshEditQuantities();

        var alertContainer = document.getElementById('edit-modal-alert-container');
        if (alertContainer) {
            alertContainer.style.display = 'none';
            alertContainer.innerHTML = '';
        }

        openModal('edit-task-modal');
    }

    function addEditTopicRow(name, qty) {
        var container = document.getElementById('edit-topics-container');
        var row = document.createElement('div');
        row.className = 'topic-input-row';
        row.style.display = 'flex';
        row.style.gap = '10px';
        row.style.marginBottom = '8px';
        row.style.alignItems = 'center';

        var input = document.createElement('input');
        input.type = 'text';
        input.name = 'topics[]';
        input.className = 'topic-input';
        input.value = name;
        input.required = true;
        input.style.flex = '2';

        var qtyContainer = document.createElement('div');
        qtyContainer.className = 'qty-container';
        qtyContainer.style.display = 'flex';
        qtyContainer.style.alignItems = 'center';
        qtyContainer.style.gap = '6px';
        qtyContainer.style.flex = '1';

        var qtyInput = document.createElement('input');
        qtyInput.type = 'number';
        qtyInput.step = 'any';
        qtyInput.name = 'quantities[]';
        qtyInput.className = 'qty-input';
        qtyInput.value = (qty !== '' && qty !== null && qty !== undefined) ? qty : '1';
        qtyInput.placeholder = 'Qty';
        qtyInput.style.width = '100px';

        var qtyLabel = document.createElement('span');
        qtyLabel.className = 'qty-label';
        qtyLabel.style.fontSize = '0.85rem';
        qtyLabel.style.fontWeight = '600';
        qtyLabel.style.color = 'var(--text-muted)';
        qtyLabel.textContent = 'units';

        qtyContainer.appendChild(qtyInput);
        qtyContainer.appendChild(qtyLabel);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-soft-red';
        btn.style.padding = '10px 12px';
        btn.innerHTML = '<i class="fas fa-trash"></i>';
        btn.onclick = function() {
            if (container.querySelectorAll('.topic-input-row').length > 1) {
                row.remove();
            } else {
                alert('At least one topic is required.');
            }
        };

        row.appendChild(input);
        row.appendChild(qtyContainer);
        row.appendChild(btn);
        container.appendChild(row);
    }

    function refreshEditQuantities() {
        var label = document.getElementById('edit-quantity-label').value || '';
        var displayLabel = (label && label.trim() !== '') ? label : 'units';
        var container = document.getElementById('edit-topics-container');
        var rows = container.querySelectorAll('.topic-input-row');
        rows.forEach(function(row) {
            var qtyInput = row.querySelector('.qty-input');
            var qtyLabel = row.querySelector('.qty-label');
            qtyInput.style.display = 'inline-block';
            qtyInput.required = true;
            qtyLabel.textContent = displayLabel;
            qtyLabel.style.display = 'inline-block';
        });
    }

    // AJAX Form submissions handlers
    document.getElementById('edit-task-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var alertContainer = document.getElementById('edit-modal-alert-container');
        alertContainer.style.display = 'none';
        alertContainer.innerHTML = '';

        var submitBtn = form.querySelector('button[type="submit"]');
        var oldBtnHtml = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        var formData = new FormData(form);

        fetch('ld-work-report.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = oldBtnHtml;
            if (data.success) {
                window.location.reload();
            } else {
                alertContainer.className = 'alert alert-error';
                alertContainer.style.padding = '8px 12px';
                alertContainer.style.fontSize = '0.8rem';
                alertContainer.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' + (data.message || 'Unable to save changes. Please correct the error and try again.');
                alertContainer.style.display = 'block';
            }
        })
        .catch(function(err) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = oldBtnHtml;
            alertContainer.className = 'alert alert-error';
            alertContainer.style.padding = '8px 12px';
            alertContainer.style.fontSize = '0.8rem';
            alertContainer.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Connection/Server error occurred. Please try again.';
            alertContainer.style.display = 'block';
        });
    });

    document.getElementById('delete-task-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var alertContainer = document.getElementById('delete-modal-alert-container');
        alertContainer.style.display = 'none';
        alertContainer.innerHTML = '';

        var submitBtn = document.getElementById('btn-submit-delete');
        var oldBtnHtml = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

        var formData = new FormData(form);

        fetch('ld-work-report.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = oldBtnHtml;
            if (data.success) {
                window.location.reload();
            } else {
                alertContainer.className = 'alert alert-error';
                alertContainer.style.padding = '8px 12px';
                alertContainer.style.fontSize = '0.8rem';
                alertContainer.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' + (data.message || 'Failed to delete task.');
                alertContainer.style.display = 'block';
            }
        })
        .catch(function(err) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = oldBtnHtml;
            alertContainer.className = 'alert alert-error';
            alertContainer.style.padding = '8px 12px';
            alertContainer.style.fontSize = '0.8rem';
            alertContainer.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Connection/Server error occurred. Please try again.';
            alertContainer.style.display = 'block';
        });
    });
    </script>

<?php include 'includes/admin_footer.php'; ?>
