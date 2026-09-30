<?php
require_once 'includes/auth.php';
require_permission('task-tracker');
require_once 'config/database.php';

if (!ld_tables_exist($pdo)) {
    $active_page = 'task-tracker';
    $page_title  = 'Intern Task Tracker';
    $page_sub    = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>Intern Task Tracker is not installed yet. Please run the required database migration (<strong>database-update-21.sql</strong>) before using this module.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

$success_message = '';
$error_message = '';

// Get logged-in user profile details
$stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
$stmt->execute([$admin_username]);
$me = $stmt->fetch();
if (!$me) {
    $me = [
        'id' => 0,
        'username' => $admin_username,
        'full_name' => $admin_username,
        'role' => $_SESSION['admin_role'] ?? 'admin'
    ];
}
$is_intern = is_ld_intern_user();
$can_view_financials = is_super_admin() || (!$is_intern && can_access('ld-work-report'));


// Audit logger helper
function log_ld_audit($pdo, $taskId, $adminId, $username, $action, $prev, $new, $lat, $lon, $mapsUrl) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $pdo->prepare("
        INSERT INTO ld_task_audit (task_id, admin_id, admin_username, action, previous_values, new_values, latitude, longitude, maps_url, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $taskId,
        $adminId,
        $username,
        $action,
        $prev ? json_encode($prev, JSON_UNESCAPED_UNICODE) : null,
        $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
        $lat,
        $lon,
        $mapsUrl,
        $ip,
        $ua
    ]);
}

require_once 'includes/ld_quality_assessment_helper.php';

// Handle QA Template Downloads
if (isset($_GET['action']) && $_GET['action'] === 'download_qa_template') {
    $fmt = strtolower($_GET['format'] ?? 'xlsx');
    if ($fmt === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Lecture_Quality_Assessment_Template.csv"');
        echo generate_ld_qa_csv_template();
        exit();
    } else {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Lecture_Quality_Assessment_Template.xlsx"');
        echo generate_ld_qa_xlsx_template();
        exit();
    }
}

// Handle Secure Download of Original QA Spreadsheet
if (isset($_GET['action']) && $_GET['action'] === 'download_qa_file') {
    $repId = (int)($_GET['report_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_reports WHERE id = ?");
    $stmt->execute([$repId]);
    $rep = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rep) {
        die('Assessment report not found.');
    }
    if (!is_super_admin() && $rep['admin_username'] !== $admin_username && !can_access('ld-work-report')) {
        die('Access denied.');
    }

    $baseUploadDir = realpath(dirname(__DIR__) . '/uploads/ld_quality_assessments');
    $realFullPath = realpath(dirname(__DIR__) . '/' . $rep['stored_path']);
    if (!$realFullPath || !$baseUploadDir || strpos($realFullPath, $baseUploadDir) !== 0 || !file_exists($realFullPath)) {
        die('Stored file not found on server.');
    }

    $ctype = $rep['file_type'] === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv';
    header('Content-Type: ' . $ctype);
    header('Content-Disposition: attachment; filename="' . basename($rep['original_filename']) . '"');
    header('Content-Length: ' . filesize($realFullPath));
    readfile($realFullPath);
    exit();
}

// Handle AJAX Fetch of QA Items for View Modal
if (isset($_GET['action']) && $_GET['action'] === 'get_qa_items') {
    header('Content-Type: application/json');
    $repId = (int)($_GET['report_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_reports WHERE id = ?");
    $stmt->execute([$repId]);
    $rep = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rep || (!is_super_admin() && $rep['admin_username'] !== $admin_username && !can_access('ld-work-report'))) {
        echo json_encode(['success' => false, 'error' => 'Access denied or report not found']);
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_items WHERE report_id = ? ORDER BY source_row_number ASC");
    $stmt->execute([$repId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_ai_reports WHERE report_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$repId]);
    $aiRep = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$can_view_financials) {
        unset($rep['hourly_rate_snapshot']);
        unset($rep['calculated_charge']);
    }

    echo json_encode([
        'success' => true,
        'report' => $rep,
        'items' => $items,
        'can_view_financials' => $can_view_financials,
        'ai_report' => $aiRep ? [
            'overall_grade' => $aiRep['overall_grade'],
            'summary' => $aiRep['summary'],
            'aspect_analysis' => json_decode($aiRep['aspect_analysis_json'] ?? '[]', true),
            'recommendations' => json_decode($aiRep['recommendations_json'] ?? '[]', true),
            'reverification_items' => json_decode($aiRep['reverification_items_json'] ?? '[]', true)
        ] : null
    ]);
    exit();
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'validate_qa_upload') {
            $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
            $course_id = (int)($_POST['course_id'] ?? 0);
            $mode_id = (int)($_POST['mode_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT course_name FROM ld_work_courses WHERE id = ? AND status = 'active'");
            $stmt->execute([$course_id]);
            $course_name = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT mode_name, charge_per_quantity FROM ld_work_modes WHERE id = ? AND status = 'active'");
            $stmt->execute([$mode_id]);
            $mode_row = $stmt->fetch();
            $hourly_rate = $mode_row ? (float)$mode_row['charge_per_quantity'] : 0.00;

            if (!$course_name || !$mode_row) {
                $resp = ['success' => false, 'error' => 'Please select a Course and Quality Assessment Work Mode before uploading.'];
                if ($isAjax) { echo json_encode($resp); exit(); }
                $error_message = $resp['error'];
            } elseif ($hourly_rate <= 0.00) {
                // Strict business rule: Unconfigured rate blocks upload/submission
                $resp = [
                    'success' => false,
                    'error' => 'Please configure the hourly assessment charge for Lectures: Quality Assessment before submitting this task.'
                ];
                if ($isAjax) { echo json_encode($resp); exit(); }
                $error_message = $resp['error'];
            } elseif (empty($_FILES['qa_file']['tmp_name']) || !is_uploaded_file($_FILES['qa_file']['tmp_name'])) {
                $resp = ['success' => false, 'error' => 'Please select a valid spreadsheet file (.xlsx or .csv) to upload.'];
                if ($isAjax) { echo json_encode($resp); exit(); }
                $error_message = $resp['error'];
            } else {
                $origName = $_FILES['qa_file']['name'];
                $tmpUpload = $_FILES['qa_file']['tmp_name'];

                $tempCacheDir = sys_get_temp_dir() . '/pepp_qa_uploads';
                if (!is_dir($tempCacheDir)) @mkdir($tempCacheDir, 0777, true);
                $tempToken = bin2hex(random_bytes(16));
                $ext = pathinfo($origName, PATHINFO_EXTENSION);
                $cachedFilePath = $tempCacheDir . '/' . $tempToken . '.' . $ext;
                move_uploaded_file($tmpUpload, $cachedFilePath);

                $valRes = validate_ld_qa_file($cachedFilePath, $origName, $course_name, $hourly_rate, $pdo, $course_id);

                $_SESSION['qa_upload_' . $tempToken] = [
                    'file_path' => $cachedFilePath,
                    'original_filename' => $origName,
                    'course_id' => $course_id,
                    'course_name' => $course_name,
                    'hourly_rate' => $hourly_rate,
                    'val_res' => $valRes
                ];

                if ($isAjax) {
                    $clientStats = $valRes['stats'] ?? null;
                    if ($clientStats && !$can_view_financials) {
                        unset($clientStats['hourly_rate']);
                        unset($clientStats['calculated_charge']);
                    }

                    $respData = [
                        'success' => true,
                        'valid' => $valRes['valid'],
                        'errors' => $valRes['errors'],
                        'errors_by_category' => $valRes['errors_by_category'] ?? null,
                        'warnings' => $valRes['warnings'],
                        'stats' => $clientStats,
                        'temp_token' => $tempToken,
                        'can_view_financials' => $can_view_financials
                    ];
                    if ($can_view_financials) {
                        $respData['hourly_rate'] = $hourly_rate;
                    }
                    echo json_encode($respData);
                    exit();
                }
            }
        } elseif ($action === 'submit_qa_assessment') {
            $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
            $tempToken = trim($_POST['temp_token'] ?? '');
            $cached = $_SESSION['qa_upload_' . $tempToken] ?? null;

            // Server-side authoritative rate check
            $mode = get_ld_quality_assessment_mode($pdo);
            $dbRate = $mode ? (float)$mode['charge_per_quantity'] : 0.00;

            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

            if ($dbRate <= 0.00) {
                $resp = [
                    'success' => false,
                    'error' => 'Please configure the hourly assessment charge for Lectures: Quality Assessment before submitting this task.'
                ];
                if ($isAjax) { echo json_encode($resp); exit(); }
                $error_message = $resp['error'];
            } elseif ($lat === false || $lon === false || $lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                $resp = ['success' => false, 'error' => "Location access is required to record this activity."];
                if ($isAjax) { echo json_encode($resp); exit(); }
                $error_message = $resp['error'];
            } elseif (!$cached || !file_exists($cached['file_path'])) {
                $resp = ['success' => false, 'error' => "Uploaded assessment session expired. Please re-upload your spreadsheet."];
                if ($isAjax) { echo json_encode($resp); exit(); }
                $error_message = $resp['error'];
            } elseif (!$cached['val_res']['valid']) {
                $resp = ['success' => false, 'error' => "Spreadsheet has unresolved blocking validation errors. Submission blocked."];
                if ($isAjax) { echo json_encode($resp); exit(); }
                $error_message = $resp['error'];
            } else {
                $mapsUrl = "https://www.google.com/maps?q=" . $lat . "," . $lon;
                try {
                    $outcome = store_ld_quality_assessment(
                        $pdo,
                        $cached['val_res'],
                        $cached['file_path'],
                        $cached['original_filename'],
                        $me,
                        (int)$cached['course_id'],
                        $dbRate,
                        $lat,
                        $lon,
                        $mapsUrl
                    );

                    @unlink($cached['file_path']);
                    unset($_SESSION['qa_upload_' . $tempToken]);

                    $aiStatus = $outcome['ai_status'] ?? 'pending';
                    $aiMsg = ($aiStatus === 'completed') ? 'Assessment submitted successfully. AI analysis completed.' : 'Assessment submitted successfully. AI analysis could not be completed.';
                    if ($can_view_financials) {
                        $msg = $aiMsg . " (" . $outcome['report_reference'] . ", " .
                            $outcome['stats']['total_lectures'] . " lectures recorded, " . number_format((float)$outcome['stats']['total_assessment_hours'], 2) . " Hours, ₹" . number_format((float)$outcome['stats']['calculated_charge'], 2) . ").";
                    } else {
                        $msg = $aiMsg . " (" . $outcome['report_reference'] . ", " .
                            $outcome['stats']['total_lectures'] . " lectures recorded, " . number_format((float)$outcome['stats']['total_assessment_hours'], 2) . " Hours).";
                    }

                    if ($isAjax) {
                        echo json_encode([
                            'success' => true,
                            'message' => $msg,
                            'report_id' => $outcome['report_id'],
                            'report_reference' => $outcome['report_reference']
                        ]);
                        exit();
                    }
                    $success_message = $msg;
                } catch (Exception $e) {
                    error_log("submit_qa_assessment error: " . $e->getMessage());
                    $resp = ['success' => false, 'error' => $e->getMessage() ?: "Failed to record assessment report."];
                    if ($isAjax) { echo json_encode($resp); exit(); }
                    $error_message = $resp['error'];
                }
            }
        } elseif ($action === 'replace_qa_assessment') {
            $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
            $taskId = (int)($_POST['task_id'] ?? 0);
            $tempToken = trim($_POST['temp_token'] ?? '');
            $cached = $_SESSION['qa_upload_' . $tempToken] ?? null;

            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

            if ($taskId <= 0) {
                $resp = ['success' => false, 'error' => "Invalid task ID for replacement."];
                if ($isAjax) { echo json_encode($resp); exit(); }
                $error_message = $resp['error'];
            } elseif (!$cached || !file_exists($cached['file_path'])) {
                $resp = ['success' => false, 'error' => "Uploaded assessment session expired. Please re-upload your spreadsheet."];
                if ($isAjax) { echo json_encode($resp); exit(); }
                $error_message = $resp['error'];
            } elseif (!$cached['val_res']['valid']) {
                $resp = ['success' => false, 'error' => "Spreadsheet has unresolved blocking validation errors. Replacement blocked."];
                if ($isAjax) { echo json_encode($resp); exit(); }
                $error_message = $resp['error'];
            } else {
                $mapsUrl = ($lat !== false && $lon !== false && $lat !== null && $lon !== null) ? "https://www.google.com/maps?q=" . $lat . "," . $lon : null;
                try {
                    $outcome = replace_ld_quality_assessment(
                        $pdo,
                        $taskId,
                        $cached['val_res'],
                        $cached['file_path'],
                        $cached['original_filename'],
                        $me,
                        $lat ?: null,
                        $lon ?: null,
                        $mapsUrl
                    );

                    @unlink($cached['file_path']);
                    unset($_SESSION['qa_upload_' . $tempToken]);

                    $aiStatus = $outcome['ai_status'] ?? 'pending';
                    $aiMsg = ($aiStatus === 'completed') ? 'Assessment submitted successfully. AI analysis completed.' : 'Assessment submitted successfully. AI analysis could not be completed.';
                    $msg = $aiMsg . " Version " . $outcome['version'] . " (" . $outcome['report_reference'] . ") is now active.";

                    if ($isAjax) {
                        echo json_encode([
                            'success' => true,
                            'message' => $msg,
                            'report_id' => $outcome['report_id'],
                            'task_id' => $outcome['task_id'],
                            'version' => $outcome['version'],
                            'report_reference' => $outcome['report_reference']
                        ]);
                        exit();
                    }
                    $success_message = $msg;
                } catch (Exception $e) {
                    error_log("replace_qa_assessment error: " . $e->getMessage());
                    $resp = ['success' => false, 'error' => $e->getMessage() ?: "Failed to replace assessment report."];
                    if ($isAjax) { echo json_encode($resp); exit(); }
                    $error_message = $resp['error'];
                }
            }
        } elseif ($action === 'create_task') {
            $course_id = (int)($_POST['course_id'] ?? 0);
            $mode_id = (int)($_POST['mode_id'] ?? 0);
            $topics = $_POST['topics'] ?? [];
            $quantities = $_POST['quantities'] ?? [];

            // Check location coordinates
            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

            // Validate location
            if ($lat === false || $lon === false || $lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                $error_message = "Location access is required to record this activity.";
            } elseif ($course_id <= 0 || $mode_id <= 0 || empty($topics)) {
                $error_message = "Please select a Course, Work Mode, and provide at least one topic.";
            } else {
                $mapsUrl = "https://www.google.com/maps?q=" . $lat . "," . $lon;

                // Fetch course name
                $stmt = $pdo->prepare("SELECT course_name FROM ld_work_courses WHERE id = ? AND status = 'active'");
                $stmt->execute([$course_id]);
                $course_name = $stmt->fetchColumn();

                // Fetch mode name and details
                $stmt = $pdo->prepare("SELECT mode_name, quantity_label, charge_per_quantity FROM ld_work_modes WHERE id = ? AND status = 'active'");
                $stmt->execute([$mode_id]);
                $mode_row = $stmt->fetch();
                $mode_name = $mode_row ? $mode_row['mode_name'] : '';
                $qty_label_snapshot = $mode_row ? $mode_row['quantity_label'] : null;
                $charge_snapshot = $mode_row ? (float)$mode_row['charge_per_quantity'] : 0.00;

                if (!$course_name || !$mode_name) {
                    $error_message = "Invalid Course or Work Mode selection.";
                } elseif (is_ld_quality_assessment_mode($mode_name)) {
                    $error_message = "Lecture Video Quality Assessment tasks must be submitted via the dedicated spreadsheet upload panel.";
                } else {
                    try {
                        $validated_topics = [];
                        $has_at_least_one_valid_topic = false;
                        foreach ($topics as $idx => $topic) {
                            $topic_clean = trim($topic);
                            if ($topic_clean !== '') {
                                $has_at_least_one_valid_topic = true;
                                if (!isset($quantities[$idx]) || trim($quantities[$idx]) === '') {
                                    throw new Exception("Quantity is required for every topic.");
                                }
                                $raw_qty = trim($quantities[$idx]);
                                if (!is_numeric($raw_qty)) {
                                    throw new Exception("Quantity must be a positive whole number.");
                                }
                                $qty = filter_var($raw_qty, FILTER_VALIDATE_INT);
                                if ($qty === false || $qty <= 0) {
                                    throw new Exception("Quantity must be a positive whole number.");
                                }

                                $validated_topics[] = [
                                    'topic_name' => $topic_clean,
                                    'quantity' => $qty
                                ];
                            }
                        }

                        if (!$has_at_least_one_valid_topic) {
                            throw new Exception("Please select a Course, Work Mode, and provide at least one topic.");
                        }

                        $pdo->beginTransaction();

                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

                        $stmt = $pdo->prepare("
                            INSERT INTO ld_tasks (admin_id, admin_username, admin_name, admin_role, course_id, course_name, mode_id, mode_name, latitude, longitude, maps_url, ip_address, user_agent, status, created_at, quantity_label_snapshot, charge_per_quantity_snapshot, mode_name_snapshot)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), ?, ?, ?)
                        ");
                        $stmt->execute([
                            $me['id'],
                            $admin_username,
                            $me['full_name'],
                            $me['role'],
                            $course_id,
                            $course_name,
                            $mode_id,
                            $mode_name,
                            $lat,
                            $lon,
                            $mapsUrl,
                            $ip,
                            $ua,
                            $qty_label_snapshot,
                            $charge_snapshot,
                            $mode_name
                        ]);
                        $task_id = $pdo->lastInsertId();

                        // Insert topics
                        $stmt_topic = $pdo->prepare("INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge) VALUES (?, ?, ?, ?)");
                        $clean_topics = [];
                        foreach ($validated_topics as $v_topic) {
                            $qty = $v_topic['quantity'];
                            $calculated_charge = $qty !== null ? ($qty * $charge_snapshot) : 0.00;
                            $stmt_topic->execute([$task_id, $v_topic['topic_name'], $qty, $calculated_charge]);
                            $clean_topics[] = [
                                'topic_name' => $v_topic['topic_name'],
                                'quantity' => $qty,
                                'calculated_charge' => $calculated_charge
                            ];
                        }

                        // Log Audit CREATE
                        $new_data = [
                            'id' => $task_id,
                            'course_name' => $course_name,
                            'mode_name' => $mode_name,
                            'topics' => $clean_topics
                        ];
                        log_ld_audit($pdo, $task_id, $me['id'], $admin_username, 'CREATE', null, $new_data, $lat, $lon, $mapsUrl);

                        $pdo->commit();
                        $success_message = "Task created successfully.";
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("Create L&D Task: " . $e->getMessage());
                        $error_message = $e->getMessage() ?: "Database error while creating task.";
                    }
                }
            }
        } elseif ($action === 'update_task') {
            $id = (int)($_POST['task_id'] ?? 0);
            $course_id = (int)($_POST['course_id'] ?? 0);
            $mode_id = (int)($_POST['mode_id'] ?? 0);
            $topics = $_POST['topics'] ?? [];
            $quantities = $_POST['quantities'] ?? [];

            // Check location coordinates
            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

            if ($lat === false || $lon === false || $lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                $error_message = "Location access is required to record this activity.";
            } else {
                $mapsUrl = "https://www.google.com/maps?q=" . $lat . "," . $lon;

                $stmt = $pdo->prepare("SELECT * FROM ld_tasks WHERE id = ? AND status = 'active'");
                $stmt->execute([$id]);
                $task = $stmt->fetch();

                if (!$task) {
                    $error_message = "Task not found.";
                } elseif (is_ld_quality_assessment_mode($task['mode_name']) || is_ld_quality_assessment_mode($task['mode_name_snapshot'])) {
                    $error_message = "Lecture Video Quality Assessment tasks cannot be edited via standard topic editing. Use re-upload or administrative review.";
                } elseif (($lock_info = is_ld_task_locked($pdo, $task['admin_id'], date('Y-m-d', strtotime($task['created_at']))))) {
                    $error_message = "This task belongs to a completed payment period (" . date('d M Y', strtotime($lock_info['period_start_date'])) . " – " . date('d M Y', strtotime($lock_info['period_end_date'])) . ") and can no longer be edited.";
                } elseif (!is_super_admin() && $task['admin_username'] !== $admin_username) {
                    $error_message = "You are not authorized to update this task.";
                } elseif ($course_id <= 0 || $mode_id <= 0 || empty($topics)) {
                    $error_message = "Please select a Course, Work Mode, and provide at least one topic.";
                } else {
                    // Fetch course name
                    $stmt = $pdo->prepare("SELECT course_name FROM ld_work_courses WHERE id = ? AND status = 'active'");
                    $stmt->execute([$course_id]);
                    $course_name = $stmt->fetchColumn();

                    // Fetch mode details
                    $stmt = $pdo->prepare("SELECT mode_name, quantity_label, charge_per_quantity FROM ld_work_modes WHERE id = ? AND status = 'active'");
                    $stmt->execute([$mode_id]);
                    $mode_row = $stmt->fetch();
                    $mode_name = $mode_row ? $mode_row['mode_name'] : '';

                    if (!$course_name || !$mode_name) {
                        $error_message = "Invalid Course or Work Mode selection.";
                    } else {
                        // Determine snapshot rate and label
                        $rate = 0.00;
                        $qty_label = '';

                        if ($mode_id === (int)$task['mode_id']) {
                            // Mode is unchanged
                            if ($task['charge_per_quantity_snapshot'] !== null && $task['quantity_label_snapshot'] !== null) {
                                $rate = (float)$task['charge_per_quantity_snapshot'];
                                $qty_label = $task['quantity_label_snapshot'];
                            } else {
                                // Fallback to current configuration (legacy task)
                                $rate = $mode_row ? (float)$mode_row['charge_per_quantity'] : 0.00;
                                $qty_label = $mode_row ? $mode_row['quantity_label'] : null;
                            }
                        } else {
                            // Mode is changed: fetch new current configuration
                            $rate = $mode_row ? (float)$mode_row['charge_per_quantity'] : 0.00;
                            $qty_label = $mode_row ? $mode_row['quantity_label'] : null;
                        }

                        // Fetch old topics
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
                                SET course_id = ?, course_name = ?, mode_id = ?, mode_name = ?, updated_at = NOW(),
                                    quantity_label_snapshot = ?, charge_per_quantity_snapshot = ?, mode_name_snapshot = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$course_id, $course_name, $mode_id, $mode_name, $qty_label, $rate, $mode_name, $id]);

                            // Replace topics
                            $stmt = $pdo->prepare("DELETE FROM ld_task_topics WHERE task_id = ?");
                            $stmt->execute([$id]);

                            $stmt_topic = $pdo->prepare("INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge) VALUES (?, ?, ?, ?)");
                            $clean_topics = [];
                            foreach ($topics as $idx => $topic) {
                                $topic_clean = trim($topic);
                                if ($topic_clean !== '') {
                                    if (!isset($quantities[$idx]) || trim($quantities[$idx]) === '') {
                                        throw new Exception("Quantity is required for every topic.");
                                    }
                                    $raw_qty = trim($quantities[$idx]);
                                    if (!is_numeric($raw_qty)) {
                                        throw new Exception("Quantity must be a positive whole number.");
                                    }
                                    $qty = filter_var($raw_qty, FILTER_VALIDATE_INT);
                                    if ($qty === false || $qty <= 0) {
                                        throw new Exception("Quantity must be a positive whole number.");
                                    }

                                    $calculated_charge = $qty * $rate;
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
                                'course_name' => $course_name,
                                'mode_name' => $mode_name,
                                'topics' => $clean_topics
                            ];

                            log_ld_audit($pdo, $id, $me['id'], $admin_username, 'UPDATE', $prev_data, $new_data, $lat, $lon, $mapsUrl);

                            $pdo->commit();
                            $success_message = "Task updated successfully.";
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            error_log("Update L&D Task: " . $e->getMessage());
                            $error_message = $e->getMessage() ?: "Database error while updating task.";
                        }
                    }
                }
            }
        } elseif ($action === 'delete_task') {
            $id = (int)($_POST['task_id'] ?? 0);
            $reason = trim($_POST['delete_reason'] ?? '');

            // Check location coordinates
            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

            if ($lat === false || $lon === false || $lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                $error_message = "Location access is required to record this activity.";
            } else {
                $mapsUrl = "https://www.google.com/maps?q=" . $lat . "," . $lon;

                $stmt = $pdo->prepare("SELECT * FROM ld_tasks WHERE id = ? AND status = 'active'");
                $stmt->execute([$id]);
                $task = $stmt->fetch();

                if (!$task) {
                    $error_message = "Task not found.";
                } elseif (($lock_info = is_ld_task_locked($pdo, $task['admin_id'], date('Y-m-d', strtotime($task['created_at']))))) {
                    $error_message = "This task belongs to a completed payment period (" . date('d M Y', strtotime($lock_info['period_start_date'])) . " – " . date('d M Y', strtotime($lock_info['period_end_date'])) . ") and can no longer be deleted.";
                } elseif (!is_super_admin() && $task['admin_username'] !== $admin_username) {
                    $error_message = "You are not authorized to delete this task.";
                } else {
                    // Fetch topics
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
                                deleted_reason = ?,
                                deleted_latitude = ?,
                                deleted_longitude = ?,
                                deleted_maps_url = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$admin_username, $reason, $lat, $lon, $mapsUrl, $id]);

                        log_ld_audit($pdo, $id, $me['id'], $admin_username, 'DELETE', $prev_data, null, $lat, $lon, $mapsUrl);

                        $pdo->commit();
                        $success_message = "Task deleted successfully.";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        error_log("Delete L&D Task: " . $e->getMessage());
                        $error_message = "Database error while deleting task.";
                    }
                }
            }
        }
    }
}

// Fetch Active Settings Lists for Dropdowns
$active_courses = $pdo->query("SELECT * FROM ld_work_courses WHERE status = 'active' ORDER BY sort_order ASC, course_name ASC")->fetchAll();
$active_modes = $pdo->query("SELECT * FROM ld_work_modes WHERE status = 'active' ORDER BY sort_order ASC, mode_name ASC")->fetchAll();

// Fetch Logged-in User's Active Tasks with Topics
$my_tasks = [];
try {
    $charge_field_sql = !$can_view_financials ? "NULL AS charge_per_quantity_snapshot" : "t.charge_per_quantity_snapshot";
    $stmt = $pdo->prepare("
        SELECT t.id, t.admin_id, t.admin_username, t.admin_name, t.admin_role, t.course_id, t.course_name, t.mode_id, t.mode_name, t.latitude, t.longitude, t.maps_url, t.ip_address, t.user_agent, t.status, t.created_at, t.updated_at, t.quantity_label_snapshot, t.mode_name_snapshot, $charge_field_sql
        FROM ld_tasks t
        WHERE t.admin_username = ? AND t.status = 'active'
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$admin_username]);
    $my_tasks = $stmt->fetchAll();

    if (!empty($my_tasks)) {
        $task_ids = array_map(function($x) { return (int)$x['id']; }, $my_tasks);
        $in_clause = implode(',', $task_ids);
        $calc_charge_field_sql = !$can_view_financials ? "NULL AS calculated_charge" : "calculated_charge";
        $topics_rows = $pdo->query("SELECT id, task_id, topic_name, quantity, $calc_charge_field_sql FROM ld_task_topics WHERE task_id IN ($in_clause) ORDER BY id ASC")->fetchAll();

        $topics_by_task = [];
        foreach ($topics_rows as $row) {
            $topics_by_task[$row['task_id']][] = $row;
        }

        $qa_reports_by_task = [];
        try {
            $hasQaReportsTable = (bool)$pdo->query("SELECT 1 FROM ld_quality_assessment_reports LIMIT 1");
            if ($hasQaReportsTable) {
                $qa_rows = $pdo->query("SELECT * FROM ld_quality_assessment_reports WHERE task_id IN ($in_clause) AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($qa_rows)) {
                    // Fallback for legacy rows created before versioning
                    $qa_rows = $pdo->query("SELECT * FROM ld_quality_assessment_reports WHERE task_id IN ($in_clause) ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
                }
                foreach ($qa_rows as $qar) {
                    if (!$can_view_financials) {
                        unset($qar['hourly_rate_snapshot']);
                        unset($qar['calculated_charge']);
                    }
                    if (!isset($qa_reports_by_task[$qar['task_id']])) {
                        $qa_reports_by_task[$qar['task_id']] = $qar;
                    }
                }
            }
        } catch (Exception $e) {}

        foreach ($my_tasks as &$t) {
            $t['topics'] = $topics_by_task[$t['id']] ?? [];
            $t['qa_report'] = $qa_reports_by_task[$t['id']] ?? null;
        }
        unset($t);
    }
} catch (Exception $e) {
    error_log("My Tasks Load: " . $e->getMessage());
}

// Count L&D tasks with missing quantity details
$incomplete_qty_count = 0;
try {
    $stmt_check = $pdo->prepare("
        SELECT COUNT(DISTINCT t.id)
        FROM ld_tasks t
        JOIN ld_task_topics tp ON tp.task_id = t.id
        WHERE t.admin_username = ? AND t.status = 'active' AND tp.quantity IS NULL
    ");
    $stmt_check->execute([$admin_username]);
    $incomplete_qty_count = (int)$stmt_check->fetchColumn();
} catch (Exception $e) {
    error_log("Incomplete Qty count load: " . $e->getMessage());
}

$active_page = 'task-tracker';
$page_title  = 'Intern Task Tracker';
$page_sub    = 'Daily task logging and local work timeline';
include 'includes/admin_nav.php';
?>

<?php
$modes_json = [];
foreach ($active_modes as $m) {
    $is_qa = is_ld_quality_assessment_mode($m['mode_name']) || (($m['mode_key'] ?? '') === 'lecture_quality_assessment');
    $is_rate_configured = ($m['charge_per_quantity'] !== null && (float)$m['charge_per_quantity'] > 0.0);
    $modes_json[$m['id']] = [
        'name' => $m['mode_name'],
        'qty_label' => $m['quantity_label'] ?? '',
        'is_charging' => ($m['charge_per_quantity'] !== null),
        'is_qa' => $is_qa,
        'rate_configured' => $is_rate_configured,
        'charge_per_quantity' => $can_view_financials && $m['charge_per_quantity'] !== null ? (float)$m['charge_per_quantity'] : null
    ];
}
?>
<script>
var workModesConfig = <?php echo json_encode($modes_json); ?>;
</script>

<style>
/* Custom local styles to make the module look extremely premium and responsive */
.ld-layout {
    display: grid;
    grid-template-columns: 1.2fr 1.8fr;
    gap: 20px;
    align-items: start;
}
@media (max-width: 992px) {
    .ld-layout {
        grid-template-columns: 1fr;
    }
}
.topic-input-row {
    display: flex;
    gap: 10px;
    margin-bottom: 8px;
    align-items: center;
}
.topic-input-row .topic-input {
    flex: 1 1 auto;
    min-width: 140px;
}
.topic-input-row .qty-container {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex: 0 0 auto;
}
.topic-input-row .qty-input {
    width: 90px !important;
    min-width: 80px;
    max-width: 100px;
    flex: 0 0 90px;
    box-sizing: border-box;
    text-align: center;
    padding: 9px 8px;
}
.topic-input-row .qty-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-muted);
    white-space: nowrap;
}
@media (max-width: 576px) {
    .topic-input-row {
        flex-wrap: wrap;
    }
    .topic-input-row .topic-input {
        width: 100%;
        flex: 1 1 100%;
    }
    .topic-input-row .qty-container {
        flex: 1;
    }
}
.timeline-list {
    position: relative;
    border-left: 2px solid var(--primary);
    padding-left: 20px;
    margin-left: 10px;
    margin-top: 15px;
}
.timeline-card {
    position: relative;
    background: var(--card);
    color: var(--card-foreground);
    padding: 16px;
    border-radius: 12px;
    border: 1px solid var(--border);
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(22, 78, 99, 0.05);
}
.timeline-card::before {
    content: '';
    position: absolute;
    left: -27px;
    top: 20px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--primary);
    border: 2px solid #ffffff;
}
.timeline-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}
.timeline-card-title {
    font-size: 0.95rem;
    font-weight: 600;
    margin: 0;
    color: var(--primary);
}
.timeline-meta {
    font-size: 0.78rem;
    color: var(--foreground);
    opacity: 0.85;
    margin-top: 4px;
}
.timeline-topics {
    margin-top: 10px;
    padding-left: 15px;
    list-style-type: disc;
    font-size: 0.85rem;
}
.timeline-topics li {
    margin-bottom: 4px;
}
.timeline-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 12px;
    border-top: 1px solid rgba(22, 78, 99, 0.1);
    padding-top: 10px;
}
.geo-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8rem;
    color: var(--success);
    font-weight: 600;
    background: rgba(16, 185, 129, 0.1);
    padding: 4px 10px;
    border-radius: 30px;
    margin-top: 6px;
}
.geo-indicator.denied {
    color: var(--destructive);
    background: rgba(220, 38, 38, 0.1);
}
.timeline-card-details {
    display: none;
    margin-top: 10px;
    border-top: 1px dashed rgba(22, 78, 99, 0.15);
    padding-top: 10px;
}
.timeline-card-details.expanded {
    display: block;
}
.details-toggle-btn {
    background: none;
    border: none;
    color: var(--primary);
    font-weight: 600;
    font-size: 0.82rem;
    cursor: pointer;
    padding: 6px 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    outline: none;
}
.details-toggle-btn:hover {
    color: var(--primary-hover, #0e7490);
    text-decoration: underline;
}
</style>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message): ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<?php if ($incomplete_qty_count > 0): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-player/2.0.4/lottie-player.js"></script>
<div class="alert alert-info" id="motivation-box" style="background: linear-gradient(135deg, #f5f3ff, #ede9fe); border: 1px solid #ddd6fe; border-radius: 16px; padding: 20px; margin-bottom: 24px; position: relative; box-shadow: 0 4px 20px rgba(124, 58, 237, 0.05); display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
    <div style="background: #ffffff; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(124, 58, 237, 0.1); width: 56px; height: 56px; flex-shrink: 0; overflow: hidden; position: relative;">
        <!-- Lottie player loading local asset -->
        <lottie-player id="motivation-lottie" src="assets/img/tick.json" background="transparent" speed="1" style="width: 48px; height: 48px; position: relative; z-index: 2;" loop autoplay></lottie-player>
        <!-- Clean static icon fallback in case Lottie fails to load or render -->
        <i class="fas fa-award" id="motivation-fallback-icon" style="color:#7c3aed; font-size: 1.5rem; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 1;"></i>
    </div>
    <div style="flex: 1; min-width: 250px;">
        <h3 style="margin: 0 0 4px 0; font-size: 1.05rem; font-weight: 700; color: #5b21b6;">Make Your Work Count! ✨</h3>
        <p style="margin: 0; font-size: 0.88rem; color: #6d28d9; line-height: 1.4;">
            Some of your previously completed tasks are missing quantity details. Add them now so your work can be properly measured and included in your payment calculation.
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-shrink:0;">
        <button type="button" class="btn btn-primary" onclick="scrollToIncompleteTasks()" style="background:#7c3aed; border-color:#7c3aed; white-space:nowrap;">
            <i class="fas fa-pencil"></i> Update My Tasks
        </button>
        <button type="button" class="btn btn-outline" onclick="closeMotivationBox()" style="padding: 10px; border-color:#c084fc; color:#7c3aed;" title="Dismiss">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var player = document.getElementById("motivation-lottie");
    var fallback = document.getElementById("motivation-fallback-icon");
    if (player) {
        player.addEventListener("ready", function() {
            if (fallback) fallback.style.display = "none";
        });
        player.addEventListener("error", function() {
            if (fallback) fallback.style.display = "block";
            player.style.display = "none";
        });
    }
});
</script>

<script>
function scrollToIncompleteTasks() {
    var card = document.querySelector('.timeline-card.has-incomplete-qty');
    if (card) {
        card.scrollIntoView({ behavior: 'smooth' });
        var editBtn = card.querySelector('.btn-soft-amber');
        if (editBtn) {
            editBtn.focus();
            card.style.outline = '3px solid #7c3aed';
            setTimeout(function() {
                card.style.outline = 'none';
            }, 3000);
        }
    } else {
        var timeline = document.querySelector('.timeline-list');
        if (timeline) {
            timeline.scrollIntoView({ behavior: 'smooth' });
        }
    }
}
function closeMotivationBox() {
    var box = document.getElementById('motivation-box');
    if (box) {
        box.style.display = 'none';
        sessionStorage.setItem('dismiss_ld_motivation', '1');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    if (sessionStorage.getItem('dismiss_ld_motivation') === '1') {
        var box = document.getElementById('motivation-box');
        if (box) box.style.display = 'none';
    }
});
</script>
<?php endif; ?>

<div class="ld-layout">
    <!-- 1. Task Entry Form Panel -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon"><i class="fas fa-list-check"></i></span>
            <h2>Log L&D Activity</h2>
        </div>
        <div class="panel-body">
            <form id="task-form" method="POST" onsubmit="return validateAndSubmit(event)">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" id="form-action" value="create_task">
                <input type="hidden" name="task_id" id="form-task-id" value="">
                <input type="hidden" name="latitude" id="form-latitude" value="">
                <input type="hidden" name="longitude" id="form-longitude" value="">

                <div class="form-grid" style="grid-template-columns: 1fr;">
                    <div class="field">
                        <label>L&D Work Course <span class="req">*</span></label>
                        <select name="course_id" id="form-course" required>
                            <option value="">- Select Course -</option>
                            <?php foreach ($active_courses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['course_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Work Mode <span class="req">*</span></label>
                        <select name="mode_id" id="form-mode" required onchange="handleModeChange()">
                            <option value="">- Select Work Mode -</option>
                            <?php foreach ($active_modes as $m): 
                                $is_m_qa = is_ld_quality_assessment_mode($m['mode_name']) || (($m['mode_key'] ?? '') === 'lecture_quality_assessment');
                            ?>
                                <option value="<?php echo (int)$m['id']; ?>" data-is-qa="<?php echo $is_m_qa ? '1' : '0'; ?>"><?php echo e($m['mode_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Normal Topics Container (Hidden when QA mode is selected) -->
                    <div id="normal-topics-container" class="field">
                        <label>Topics Completed <span class="req">*</span></label>
                        <div id="topics-container">
                            <div class="topic-input-row">
                                <input type="text" name="topics[]" class="topic-input" placeholder="e.g. Personality theories" required>
                                <div class="qty-container">
                                    <input type="number" min="1" step="1" required name="quantities[]" class="qty-input" placeholder="Qty *">
                                    <span class="qty-label">Qty *</span>
                                </div>
                                <button type="button" class="btn btn-sm btn-soft-red" style="opacity:0; pointer-events:none; padding:10px 12px;"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline" style="margin-top:8px;" onclick="addTopicRow()"><i class="fas fa-plus"></i> Add Topic</button>
                    </div>

                    <!-- Quality Assessment Upload Panel (Displayed only when QA mode is selected) -->
                    <div id="qa-upload-panel" class="field" style="display:none; background: linear-gradient(135deg, rgba(14, 165, 233, 0.05), rgba(99, 102, 241, 0.05)); border: 1px solid rgba(14, 165, 233, 0.2); border-radius: 12px; padding: 18px; margin-top: 10px;">
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                            <span style="background:var(--primary); color:#fff; width:34px; height:34px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; font-size:1.1rem;"><i class="fas fa-video"></i></span>
                            <div>
                                <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--foreground);">Lecture Video Quality Assessment</h3>
                                <p style="margin:2px 0 0 0; font-size:0.8rem; color:var(--text-muted);">
                                    Upload your assessment spreadsheet (.xlsx or .csv). Download the official template below.
                                </p>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
                            <a href="task-tracker.php?action=download_qa_template&format=xlsx" class="btn btn-sm btn-outline" style="background:#fff;">
                                <i class="fas fa-file-excel" style="color:#107c41;"></i> Download Excel Template
                            </a>
                            <a href="task-tracker.php?action=download_qa_template&format=csv" class="btn btn-sm btn-outline" style="background:#fff;">
                                <i class="fas fa-file-csv" style="color:#0284c7;"></i> Download CSV Template
                            </a>
                        </div>

                        <div class="field" style="margin-bottom:10px;">
                            <label style="font-weight:600; font-size:0.85rem;">Select Completed Spreadsheet (.xlsx / .csv) <span class="req">*</span></label>
                            <input type="file" id="qa-file-input" accept=".xlsx,.csv" style="background:#fff; border: 1px dashed var(--border); border-radius: 8px; padding: 10px; width: 100%; box-sizing: border-box;">
                            <small style="color:var(--text-muted); display:block; margin-top:4px;">Supported formats: .xlsx, .csv. Maximum file size: 10 MB. Corrupted spreadsheets and macro-enabled files are rejected.</small>
                        </div>

                        <button type="button" id="btn-validate-qa" class="btn btn-primary" onclick="validateQaUpload()" style="margin-top:4px;">
                            <i class="fas fa-magnifying-glass-chart"></i> Upload &amp; Validate Spreadsheet
                        </button>

                        <!-- Dynamic Validation Result Container -->
                        <div id="qa-validation-result" style="margin-top:16px; display:none;"></div>
                    </div>
                </div>

                <div id="geo-status-box">
                    <span id="geo-text" class="geo-indicator"><i class="fas fa-location-dot"></i> Fetching location coordinates...</span>
                </div>

                <div id="normal-submit-container" style="display:flex; justify-content:space-between; align-items:center; margin-top:20px;">
                    <button type="button" id="btn-cancel-edit" class="btn btn-outline" style="display:none;" onclick="cancelEditMode()">Cancel Edit</button>
                    <button type="submit" id="btn-submit" class="btn btn-primary" style="margin-left:auto;"><i class="fas fa-floppy-disk"></i> Log Task</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. My Work Report timeline View -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-clock-rotate-left"></i></span>
            <h2>My Work Report</h2>
        </div>
        <div class="panel-body">
            <?php if (empty($my_tasks)): ?>
                <div class="empty-state" style="padding:40px;">
                    <i class="fas fa-folder-open"></i>
                    <p>You have not logged any tasks yet today.</p>
                </div>
            <?php else: ?>
                <div class="timeline-list">
                    <?php
                    $current_date = '';
                    foreach ($my_tasks as $t):
                        $task_date = date('d M Y', strtotime($t['created_at']));
                        if ($task_date !== $current_date) {
                            $current_date = $task_date;
                            echo "<div style='font-weight:700; font-size:0.9rem; color:var(--primary); margin-top:14px; margin-bottom:8px;'><i class='fas fa-calendar-day'></i> {$current_date}</div>";
                        }

                        $has_incomplete = false;
                        $total_task_charge = 0.00;
                        foreach ($t['topics'] as $tp) {
                            if ($tp['quantity'] === null) {
                                $has_incomplete = true;
                            }
                            $total_task_charge += (float)$tp['calculated_charge'];
                        }
                        $mode_title = $t['mode_name_snapshot'] ?: $t['mode_name'];
                    ?>
                        <?php if (!empty($t['qa_report'])): 
                            $rep = $t['qa_report'];
                        ?>
                            <div class="timeline-card" id="task-card-<?php echo (int)$t['id']; ?>" style="border-left: 4px solid var(--primary);">
                                <div class="timeline-card-header">
                                    <div>
                                        <span class="badge blue" style="margin-bottom:6px; display:inline-block;"><i class="fas fa-video"></i> Quality Assessment</span>
                                        <h3 class="timeline-card-title"><?php echo e($mode_title); ?></h3>
                                        <div class="timeline-meta"><i class="fas fa-book"></i> Course: <?php echo e($t['course_name']); ?></div>
                                        <div class="timeline-meta"><i class="fas fa-file-invoice"></i> Ref: <strong><?php echo e($rep['report_reference']); ?></strong> <span class="badge blue" style="font-size:0.68rem; padding:1px 5px;">v<?php echo (int)($rep['version'] ?? 1); ?></span></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <span class="badge blue"><?php echo (int)$rep['row_count']; ?> lectures</span>
                                        <div style="font-size:0.85rem; font-weight:700; color:var(--primary); margin-top:4px;">
                                            <?php echo number_format((float)$rep['total_assessment_hours'], 2); ?> Hours
                                        </div>
                                        <?php if ($can_view_financials && $t['charge_per_quantity_snapshot'] !== null && isset($rep['calculated_charge'])): ?>
                                            <div style="font-size:0.85rem; font-weight:700; color:var(--success); margin-top:2px;">₹<?php echo number_format((float)$rep['calculated_charge'], 2); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; align-items:center;">
                                    <?php if ($rep['ai_status'] === 'completed'): 
                                        $aiGrade = $rep['ai_overall_grade'] ?: 'Good';
                                        $aiClass = strtolower($aiGrade) === 'good' ? 'green' : (strtolower($aiGrade) === 'okay' ? 'amber' : 'red');
                                    ?>
                                        <span class="badge <?php echo $aiClass; ?>" title="AI Overall Quality Analysis"><i class="fas fa-brain"></i> AI: <?php echo e(ucwords($aiGrade)); ?></span>
                                    <?php elseif ($rep['ai_status'] === 'pending_config'): ?>
                                        <span class="badge yellow" title="AI Provider pending configuration"><i class="fas fa-brain"></i> AI: Pending Config</span>
                                    <?php elseif ($rep['ai_status'] === 'failed'): ?>
                                        <span class="badge red" title="AI analysis failed"><i class="fas fa-triangle-exclamation"></i> AI: Failed</span>
                                    <?php else: ?>
                                        <span class="badge blue"><i class="fas fa-spinner fa-spin"></i> AI: In Progress</span>
                                    <?php endif; ?>

                                    <?php 
                                    $rev = $rep['admin_review_status'] ?? 'pending';
                                    if ($rev === 'verified'): ?>
                                        <span class="badge green"><i class="fas fa-circle-check"></i> Admin: Verified</span>
                                    <?php elseif ($rev === 'needs_action'): ?>
                                        <span class="badge amber"><i class="fas fa-triangle-exclamation"></i> Admin: Needs Action</span>
                                    <?php elseif ($rev === 'needs_reassessment'): ?>
                                        <span class="badge red"><i class="fas fa-rotate"></i> Admin: Needs Reassessment</span>
                                    <?php elseif ($rev === 'rejected'): ?>
                                        <span class="badge red"><i class="fas fa-ban"></i> Admin: Rejected</span>
                                    <?php else: ?>
                                        <span class="badge gray"><i class="fas fa-clock"></i> Admin: Pending Review</span>
                                    <?php endif; ?>
                                </div>

                                <div class="timeline-meta" style="margin-top:10px; border-top:1px solid rgba(22, 78, 99, 0.05); padding-top:8px;">
                                    <div><strong>Created:</strong> <?php echo date('d M Y, h:i A', strtotime($t['created_at'])); ?></div>
                                    <div><strong>Original File:</strong> <?php echo e($rep['original_filename']); ?> (<?php echo strtoupper(e($rep['file_type'])); ?>, <?php echo round($rep['file_size']/1024, 1); ?> KB)</div>
                                </div>

                                <div class="timeline-actions" style="margin-top:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-sm btn-outline" onclick="openViewQaModal(<?php echo (int)$rep['id']; ?>)">
                                        <i class="fas fa-eye"></i> View Assessment
                                    </button>
                                    <a href="task-tracker.php?action=download_qa_file&report_id=<?php echo (int)$rep['id']; ?>" class="btn btn-sm btn-soft-violet" title="Download original file">
                                        <i class="fas fa-download"></i> Original File
                                    </a>

                                    <?php
                                    $lock_info = is_ld_task_locked($pdo, $t['admin_id'], date('Y-m-d', strtotime($t['created_at'])));
                                    if ($lock_info):
                                    ?>
                                        <span style="font-weight:700; color:var(--text-muted); font-size:0.85rem; display:inline-flex; align-items:center; gap:6px;">
                                            <i class="fas fa-lock" style="color:var(--success-ink);"></i> Paid &amp; Locked
                                            <small style="font-weight:500;">(<?php echo date('d M Y', strtotime($lock_info['period_start_date'])) . ' – ' . date('d M Y', strtotime($lock_info['period_end_date'])); ?>)</small>
                                        </span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-soft-amber" onclick='openReplaceQaModal(<?php echo (int)$t["id"]; ?>, <?php echo json_encode($t["course_name"]); ?>, <?php echo (int)$t["course_id"]; ?>, <?php echo json_encode($rep["report_reference"]); ?>, <?php echo (int)($rep["version"] ?? 1); ?>)'>
                                            <i class="fas fa-rotate"></i> Replace Report
                                        </button>
                                        <button type="button" class="btn btn-sm btn-soft-red" onclick="openDeleteModal(<?php echo (int)$t['id']; ?>)">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="timeline-card <?php echo $has_incomplete ? 'has-incomplete-qty' : ''; ?>" id="task-card-<?php echo (int)$t['id']; ?>" <?php echo $has_incomplete ? 'style="border: 1px dashed var(--warning);"' : ''; ?>>
                                <?php if ($has_incomplete): ?>
                                    <div style="font-size:0.75rem; color:var(--warning-ink); background:var(--warning-soft); padding:4px 8px; border-radius:6px; margin-bottom:8px; display:inline-block; font-weight:600;">
                                        <i class="fas fa-circle-exclamation"></i> Missing Quantity data - Click edit to update
                                    </div>
                                <?php endif; ?>

                                <div class="timeline-card-header">
                                    <div>
                                        <h3 class="timeline-card-title"><?php echo e($mode_title); ?></h3>
                                        <div class="timeline-meta"><i class="fas fa-book"></i> Course: <?php echo e($t['course_name']); ?></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <span class="badge blue"><?php echo count($t['topics']); ?> topics</span>
                                        <?php if ($can_view_financials && !$has_incomplete && $t['charge_per_quantity_snapshot'] !== null): ?>
                                            <div style="font-size:0.8rem; font-weight:700; color:var(--success); margin-top:4px;">₹<?php echo number_format($total_task_charge, 2); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="timeline-meta" style="margin-top:10px; border-top:1px solid rgba(22, 78, 99, 0.05); padding-top:8px;">
                                    <div><strong>Created:</strong> <?php echo date('d M Y, h:i A', strtotime($t['created_at'])); ?></div>
                                </div>

                                <button type="button" class="details-toggle-btn" id="toggle-btn-<?php echo (int)$t['id']; ?>" aria-expanded="false" onclick="toggleDetails(<?php echo (int)$t['id']; ?>)" aria-controls="details-<?php echo (int)$t['id']; ?>">
                                    <i class="fas fa-chevron-down"></i> View Details
                                </button>

                                <div class="timeline-card-details" id="details-<?php echo (int)$t['id']; ?>" role="region" aria-labelledby="toggle-btn-<?php echo (int)$t['id']; ?>">
                                    <div style="font-weight:600; font-size:0.8rem; color:var(--foreground); opacity:0.9; margin-bottom:6px;">Completed Topics</div>
                                    <ul class="timeline-topics" style="margin-top:0; padding-left:15px;">
                                        <?php foreach ($t['topics'] as $tp): ?>
                                            <li>
                                                <?php echo e($tp['topic_name']); ?>
                                                <?php if ($tp['quantity'] !== null): ?>
                                                    <span style="font-weight:600; color:var(--text-muted);">
                                                        (<?php echo (float)$tp['quantity']; ?> <?php echo e($t['quantity_label_snapshot'] ?? 'units'); ?><?php if ($can_view_financials && $t['charge_per_quantity_snapshot'] !== null && isset($tp['calculated_charge'])): ?> @ ₹<?php echo number_format((float)$t['charge_per_quantity_snapshot'], 2); ?>/unit = ₹<?php echo number_format((float)$tp['calculated_charge'], 2); ?><?php endif; ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span style="font-weight:600; color:var(--destructive);">
                                                        (Quantity not added)
                                                    </span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>

                                <div class="timeline-actions">
                                    <?php
                                    $lock_info = is_ld_task_locked($pdo, $t['admin_id'], date('Y-m-d', strtotime($t['created_at'])));
                                    if ($lock_info):
                                    ?>
                                        <span style="font-weight:700; color:var(--text-muted); font-size:0.85rem; display:inline-flex; align-items:center; gap:6px;">
                                            <i class="fas fa-lock" style="color:var(--success-ink);"></i> Paid &amp; Locked
                                            <small style="font-weight:500;">(<?php echo date('d M Y', strtotime($lock_info['period_start_date'])) . ' – ' . date('d M Y', strtotime($lock_info['period_end_date'])); ?>)</small>
                                        </span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-soft-amber" onclick='enterEditMode(<?php echo json_encode([
                                            "id" => (int)$t["id"],
                                            "course_id" => (int)$t["course_id"],
                                            "mode_id" => (int)$t["mode_id"],
                                            "topics" => array_map(function($tp) {
                                                return [
                                                    "name" => $tp["topic_name"],
                                                    "qty" => $tp["quantity"] !== null ? (float)$tp["quantity"] : ""
                                                ];
                                            }, $t["topics"])
                                        ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i> Edit</button>
                                        <button type="button" class="btn btn-sm btn-soft-red" onclick="openDeleteModal(<?php echo (int)$t['id']; ?>)"><i class="fas fa-trash"></i> Delete</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── DELETE REASON MODAL ── -->
<div class="modal-backdrop" id="delete-task-modal">
    <div class="modal" style="max-width:450px;">
        <div class="modal-head">
            <h3>Delete L&D Task Log</h3>
            <button class="modal-close" onclick="closeModal('delete-task-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" id="delete-form" onsubmit="return validateDelete(event)">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete_task">
            <input type="hidden" name="task_id" id="delete-task-id">
            <input type="hidden" name="latitude" id="delete-latitude">
            <input type="hidden" name="longitude" id="delete-longitude">
            <div class="modal-body">
                <div class="field">
                    <label>Reason for deletion <span class="req">*</span></label>
                    <textarea name="delete_reason" rows="3" placeholder="e.g. Duplicate logging / incorrect course selection" required></textarea>
                </div>
                <div id="delete-geo-box" style="margin-top:10px;">
                    <span id="delete-geo-text" class="geo-indicator"><i class="fas fa-location-dot"></i> Accessing location coordinates...</span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('delete-task-modal')">Cancel</button>
                <button type="submit" id="btn-confirm-delete" class="btn btn-danger">Confirm Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- ── VIEW QUALITY ASSESSMENT MODAL ── -->
<div class="modal-backdrop" id="view-qa-modal">
    <div class="modal" style="max-width:960px; width:95%;">
        <div class="modal-head">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="background:var(--primary); color:#fff; width:28px; height:28px; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; font-size:0.9rem;"><i class="fas fa-video"></i></span>
                <h3 style="margin:0;">Lecture Quality Assessment Report</h3>
            </div>
            <button class="modal-close" onclick="closeModal('view-qa-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="view-qa-modal-content" style="max-height:75vh; overflow-y:auto; padding:18px;">
            <!-- Loaded dynamically via AJAX -->
        </div>
        <div class="modal-foot" style="justify-content:flex-end;">
            <button type="button" class="btn btn-outline" onclick="closeModal('view-qa-modal')">Close</button>
        </div>
    </div>
</div>

<!-- ── REPLACE QUALITY ASSESSMENT REPORT MODAL ── -->
<div class="modal-backdrop" id="replace-qa-modal">
    <div class="modal" style="max-width:680px; width:95%;">
        <div class="modal-head">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="background:var(--warning); color:#fff; width:28px; height:28px; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; font-size:0.9rem;"><i class="fas fa-rotate"></i></span>
                <h3 style="margin:0;">Replace Assessment Report</h3>
            </div>
            <button class="modal-close" onclick="closeModal('replace-qa-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="padding:18px;">
            <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px; margin-bottom:14px; font-size:0.85rem; color:#1e40af; line-height:1.5;">
                <i class="fas fa-circle-info"></i> <strong>Audit Preservation:</strong> Replacing this report does not destroy historical evidence. The current version will be archived (<span style="font-family:monospace;">is_active = 0</span>), its original file and AI results preserved, and a new version will be created.
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px; background:#f8fafc; padding:10px; border-radius:8px; border:1px solid var(--border); font-size:0.85rem;">
                <div><span style="color:var(--text-muted); font-size:0.75rem; display:block;">Course</span><strong id="replace-qa-course-name"></strong></div>
                <div><span style="color:var(--text-muted); font-size:0.75rem; display:block;">Current Reference</span><strong id="replace-qa-current-ref"></strong> (<span id="replace-qa-current-ver"></span>)</div>
            </div>

            <input type="hidden" id="replace-qa-task-id" value="">
            <input type="hidden" id="replace-qa-course-id" value="">

            <div class="field" style="margin-bottom:12px;">
                <label style="font-weight:600; font-size:0.85rem;">Select Replacement Spreadsheet (.xlsx / .csv) <span class="req">*</span></label>
                <input type="file" id="replace-qa-file-input" accept=".xlsx,.csv" style="background:#fff; border: 1px dashed var(--border); border-radius: 8px; padding: 10px; width: 100%; box-sizing: border-box;">
                <small style="color:var(--text-muted); display:block; margin-top:4px;">Upload the corrected spreadsheet. Validation rules apply identically.</small>
            </div>

            <button type="button" id="btn-validate-replace-qa" class="btn btn-primary" onclick="validateReplaceQaUpload()" style="width:100%;">
                <i class="fas fa-magnifying-glass-chart"></i> Validate Replacement Spreadsheet
            </button>

            <div id="replace-qa-validation-result" style="margin-top:14px; display:none;"></div>
        </div>
        <div class="modal-foot" style="justify-content:flex-end;">
            <button type="button" class="btn btn-outline" onclick="closeModal('replace-qa-modal')">Close</button>
        </div>
    </div>
</div>

<script>
// Geolocation cache
var clientLatitude = null;
var clientLongitude = null;
var geoErrorMsg = null;

function requestLocation(callback) {
    if (!navigator.geolocation) {
        geoErrorMsg = "Geolocation is not supported by your browser.";
        updateGeoUI();
        if (callback) callback(false);
        return;
    }

    navigator.geolocation.getCurrentPosition(function(pos) {
        clientLatitude = pos.coords.latitude;
        clientLongitude = pos.coords.longitude;
        geoErrorMsg = null;
        updateGeoUI();
        if (callback) callback(true);
    }, function(err) {
        // Fallback to low accuracy network-based location
        navigator.geolocation.getCurrentPosition(function(pos2) {
            clientLatitude = pos2.coords.latitude;
            clientLongitude = pos2.coords.longitude;
            geoErrorMsg = null;
            updateGeoUI();
            if (callback) callback(true);
        }, function(err2) {
            clientLatitude = null;
            clientLongitude = null;
            geoErrorMsg = "Location access is required to record this activity. Please allow site location permissions in your browser.";
            updateGeoUI();
            if (callback) callback(false);
        }, {
            enableHighAccuracy: false,
            timeout: 10000,
            maximumAge: 60000
        });
    }, {
        enableHighAccuracy: true,
        timeout: 5000,
        maximumAge: 0
    });
}

function updateGeoUI() {
    var textEl = document.getElementById('geo-text');
    var delTextEl = document.getElementById('delete-geo-text');
    var btnSubmit = document.getElementById('btn-submit');
    var btnDelete = document.getElementById('btn-confirm-delete');

    if (clientLatitude !== null && clientLongitude !== null) {
        var successHTML = '<i class="fas fa-circle-check"></i> Location captured successfully';
        textEl.className = 'geo-indicator';
        textEl.innerHTML = successHTML;
        if (delTextEl) {
            delTextEl.className = 'geo-indicator';
            delTextEl.innerHTML = successHTML;
        }
        btnSubmit.disabled = false;
        if (btnDelete) btnDelete.disabled = false;
    } else {
        var errHTML = '<i class="fas fa-triangle-exclamation"></i> ' + (geoErrorMsg || "Accessing location coordinates...");
        textEl.className = 'geo-indicator denied';
        textEl.innerHTML = errHTML;
        if (delTextEl) {
            delTextEl.className = 'geo-indicator denied';
            delTextEl.innerHTML = errHTML;
        }
        // Keep buttons disabled until location is confirmed
        btnSubmit.disabled = true;
        if (btnDelete) btnDelete.disabled = true;
    }
}

// Request location on load
document.addEventListener('DOMContentLoaded', function() {
    requestLocation();
    var modeSelect = document.getElementById('form-mode');
    if (modeSelect) {
        modeSelect.addEventListener('change', function() {
            updateAllQtyLabels();
        });
        // Run immediately to set initial required attributes based on selected mode
        updateAllQtyLabels();
    }
});

function getSelectedModeQtyLabel() {
    var modeSelect = document.getElementById('form-mode');
    var modeId = modeSelect ? modeSelect.value : '';
    if (modeId && workModesConfig[modeId]) {
        return workModesConfig[modeId].qty_label || 'Qty';
    }
    return 'Qty';
}

function isQtyRequired() {
    var action = document.getElementById('form-action').value;
    if (action !== 'create_task') {
        return false;
    }
    var modeSelect = document.getElementById('form-mode');
    var modeId = modeSelect ? modeSelect.value : '';
    if (modeId && workModesConfig[modeId]) {
        var cfg = workModesConfig[modeId];
        return (cfg.qty_label !== undefined && cfg.qty_label !== null && cfg.qty_label.trim() !== '' && cfg.is_charging);
    }
    return false;
}

function updateAllQtyLabels() {
    var labelText = getSelectedModeQtyLabel();
    var displayLabel = labelText ? (labelText + ' *') : 'Qty *';

    var labels = document.querySelectorAll('.qty-label');
    labels.forEach(function(lbl) {
        lbl.textContent = displayLabel;
    });

    var inputs = document.querySelectorAll('.qty-input');
    inputs.forEach(function(inp) {
        inp.placeholder = displayLabel;
        inp.required = true;
        inp.min = "1";
        inp.step = "1";
        inp.classList.add('qty-required');
    });
}

// Dynamic Topics Inputs
function addTopicRow(val = '', qty = '') {
    var container = document.getElementById('topics-container');
    var row = document.createElement('div');
    row.className = 'topic-input-row';

    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'topics[]';
    input.className = 'topic-input';
    input.placeholder = 'Next topic completed';
    input.value = val;
    input.required = true;

    var qtyContainer = document.createElement('div');
    qtyContainer.className = 'qty-container';

    var qtyLabelText = getSelectedModeQtyLabel();
    var displayLabel = qtyLabelText ? (qtyLabelText + ' *') : 'Qty *';

    var qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.min = '1';
    qtyInput.step = '1';
    qtyInput.name = 'quantities[]';
    qtyInput.className = 'qty-input';
    qtyInput.placeholder = displayLabel;
    qtyInput.value = (qty !== '' && qty !== null && qty !== undefined) ? qty : '1';
    qtyInput.required = true;
    qtyInput.classList.add('qty-required');

    var qtyLabel = document.createElement('span');
    qtyLabel.className = 'qty-label';
    qtyLabel.textContent = displayLabel;

    qtyContainer.appendChild(qtyInput);
    qtyContainer.appendChild(qtyLabel);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-soft-red';
    btn.innerHTML = '<i class="fas fa-trash"></i>';
    btn.style.padding = '10px 12px';
    btn.onclick = function() {
        row.remove();
    };

    row.appendChild(input);
    row.appendChild(qtyContainer);
    row.appendChild(btn);
    container.appendChild(row);
}

function enterEditMode(data) {
    document.getElementById('form-action').value = 'update_task';
    document.getElementById('form-task-id').value = data.id;
    document.getElementById('form-course').value = data.course_id;
    document.getElementById('form-mode').value = data.mode_id;

    // Clear topics container
    var container = document.getElementById('topics-container');
    container.innerHTML = '';

    // Fill topics
    data.topics.forEach(function(tp, idx) {
        if (idx === 0) {
            var row = document.createElement('div');
            row.className = 'topic-input-row';

            var input = document.createElement('input');
            input.type = 'text';
            input.name = 'topics[]';
            input.className = 'topic-input';
            input.value = tp.name;
            input.required = true;

            var qtyContainer = document.createElement('div');
            qtyContainer.className = 'qty-container';

            var qtyLabelText = getSelectedModeQtyLabel();
            var displayLabel = qtyLabelText ? (qtyLabelText + ' *') : 'Qty *';

            var qtyInput = document.createElement('input');
            qtyInput.type = 'number';
            qtyInput.min = '1';
            qtyInput.step = '1';
            qtyInput.name = 'quantities[]';
            qtyInput.className = 'qty-input';
            qtyInput.placeholder = displayLabel;
            qtyInput.value = tp.qty;
            qtyInput.required = true;
            qtyInput.classList.add('qty-required');

            var qtyLabel = document.createElement('span');
            qtyLabel.className = 'qty-label';
            qtyLabel.textContent = displayLabel;

            qtyContainer.appendChild(qtyInput);
            qtyContainer.appendChild(qtyLabel);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-soft-red';
            btn.style.opacity = '0';
            btn.style.pointerEvents = 'none';
            btn.innerHTML = '<i class="fas fa-trash"></i>';
            btn.style.padding = '10px 12px';

            row.appendChild(input);
            row.appendChild(qtyContainer);
            row.appendChild(btn);
            container.appendChild(row);
        } else {
            addTopicRow(tp.name, tp.qty);
        }
    });

    updateAllQtyLabels();

    document.getElementById('btn-cancel-edit').style.display = 'inline-block';
    document.getElementById('btn-submit').innerHTML = '<i class="fas fa-floppy-disk"></i> Update Task';

    // Focus course
    document.getElementById('form-course').focus();

    // Scroll to form
    document.getElementById('task-form').scrollIntoView({ behavior: 'smooth' });
}

function cancelEditMode() {
    document.getElementById('form-action').value = 'create_task';
    document.getElementById('form-task-id').value = '';
    document.getElementById('form-course').value = '';
    document.getElementById('form-mode').value = '';

    var container = document.getElementById('topics-container');
    container.innerHTML = '';

    var row = document.createElement('div');
    row.className = 'topic-input-row';

    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'topics[]';
    input.className = 'topic-input';
    input.placeholder = 'e.g. Personality theories';
    input.required = true;

    var qtyContainer = document.createElement('div');
    qtyContainer.className = 'qty-container';

    var qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.min = '1';
    qtyInput.step = '1';
    qtyInput.name = 'quantities[]';
    qtyInput.className = 'qty-input';
    qtyInput.placeholder = 'Qty *';
    qtyInput.required = true;
    qtyInput.classList.add('qty-required');

    var qtyLabel = document.createElement('span');
    qtyLabel.className = 'qty-label';
    qtyLabel.textContent = 'Qty *';

    qtyContainer.appendChild(qtyInput);
    qtyContainer.appendChild(qtyLabel);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-soft-red';
    btn.style.opacity = '0';
    btn.style.pointerEvents = 'none';
    btn.innerHTML = '<i class="fas fa-trash"></i>';
    btn.style.padding = '10px 12px';

    row.appendChild(input);
    row.appendChild(qtyContainer);
    row.appendChild(btn);
    container.appendChild(row);

    document.getElementById('btn-cancel-edit').style.display = 'none';
    document.getElementById('btn-submit').innerHTML = '<i class="fas fa-floppy-disk"></i> Log Task';

    updateAllQtyLabels();
}

function validateAndSubmit(e) {
    e.preventDefault();

    // Reset previous validation highlights
    var inputs = document.querySelectorAll('.qty-input');
    inputs.forEach(function(inp) {
        inp.style.border = '';
    });

    var hasInvalidQty = false;
    var firstInvalidInp = null;

    inputs.forEach(function(inp) {
        var row = inp.closest('.topic-input-row');
        if (row) {
            var topicInput = row.querySelector('.topic-input');
            if (topicInput && topicInput.value.trim() !== '') {
                var val = inp.value.trim();
                if (val === '') {
                    inp.style.border = '2px solid #ef4444';
                    hasInvalidQty = true;
                    if (!firstInvalidInp) firstInvalidInp = inp;
                } else {
                    var isInt = /^[1-9]\d*$/.test(val);
                    if (!isInt) {
                        inp.style.border = '2px solid #ef4444';
                        hasInvalidQty = true;
                        if (!firstInvalidInp) firstInvalidInp = inp;
                    }
                }
            }
        }
    });

    if (hasInvalidQty) {
        alert("Quantity is required for every topic.");
        if (firstInvalidInp) {
            firstInvalidInp.focus();
        }
        return false;
    }

    requestLocation(function(success) {
        if (!success) {
            alert("Location access is required to record this activity. Submission blocked.");
            return false;
        }

        document.getElementById('form-latitude').value = clientLatitude;
        document.getElementById('form-longitude').value = clientLongitude;

        document.getElementById('task-form').submit();
    });
}

function openDeleteModal(id) {
    document.getElementById('delete-task-id').value = id;
    openModal('delete-task-modal');
    requestLocation();
}

function validateDelete(e) {
    e.preventDefault();

    requestLocation(function(success) {
        if (!success) {
            alert("Location access is required to record this activity. Deletion blocked.");
            return false;
        }

        document.getElementById('delete-latitude').value = clientLatitude;
        document.getElementById('delete-longitude').value = clientLongitude;

        document.getElementById('delete-form').submit();
    });
}

function toggleDetails(taskId) {
    var detailsEl = document.getElementById('details-' + taskId);
    var btnEl = document.getElementById('toggle-btn-' + taskId);

    if (detailsEl.classList.contains('expanded')) {
        detailsEl.classList.remove('expanded');
        btnEl.setAttribute('aria-expanded', 'false');
        btnEl.innerHTML = '<i class="fas fa-chevron-down"></i> View Details';
    } else {
        detailsEl.classList.add('expanded');
        btnEl.setAttribute('aria-expanded', 'true');
        btnEl.innerHTML = '<i class="fas fa-chevron-up"></i> Hide Details';
    }
}

// ── Quality Assessment UI Handlers ──
function handleModeChange() {
    var modeSelect = document.getElementById('form-mode');
    var selectedOpt = modeSelect.options[modeSelect.selectedIndex];
    var isQa = selectedOpt && selectedOpt.getAttribute('data-is-qa') === '1';
    var modeId = modeSelect.value;
    var modeCfg = (modeId && workModesConfig[modeId]) ? workModesConfig[modeId] : null;

    var normalTopics = document.getElementById('normal-topics-container');
    var qaPanel = document.getElementById('qa-upload-panel');
    var normalSubmit = document.getElementById('normal-submit-container');

    if (isQa) {
        if (normalTopics) normalTopics.style.display = 'none';
        if (qaPanel) qaPanel.style.display = 'block';
        if (normalSubmit) normalSubmit.style.display = 'none';

        // Check if hourly rate is configured
        var rateWarningEl = document.getElementById('qa-rate-unconfigured-alert');
        var fileInp = document.getElementById('qa-file-input');
        var valBtn = document.getElementById('btn-validate-qa');

        if (modeCfg && !modeCfg.rate_configured) {
            if (!rateWarningEl) {
                rateWarningEl = document.createElement('div');
                rateWarningEl.id = 'qa-rate-unconfigured-alert';
                rateWarningEl.className = 'alert alert-error';
                rateWarningEl.style.marginBottom = '14px';
                rateWarningEl.innerHTML = '<i class="fas fa-triangle-exclamation"></i> <strong>Configuration Required:</strong> Please configure the hourly assessment charge for Lectures: Quality Assessment before submitting this task.';
                qaPanel.insertBefore(rateWarningEl, qaPanel.firstChild);
            }
            if (fileInp) fileInp.disabled = true;
            if (valBtn) valBtn.disabled = true;
        } else {
            if (rateWarningEl) rateWarningEl.remove();
            if (fileInp) fileInp.disabled = false;
            if (valBtn) valBtn.disabled = false;
        }

        // Remove required attribute from normal topic inputs so form doesn't block
        document.querySelectorAll('#topics-container input').forEach(function(inp) {
            inp.required = false;
        });
    } else {
        if (normalTopics) normalTopics.style.display = 'block';
        if (qaPanel) qaPanel.style.display = 'none';
        if (normalSubmit) normalSubmit.style.display = 'flex';

        document.querySelectorAll('#topics-container input').forEach(function(inp) {
            inp.required = true;
        });
        updateAllQtyLabels();
    }
}

function validateQaUpload() {
    var courseId = document.getElementById('form-course').value;
    var modeId = document.getElementById('form-mode').value;
    var fileInput = document.getElementById('qa-file-input');
    var resDiv = document.getElementById('qa-validation-result');

    if (!courseId) {
        alert('Please select an L&D Work Course first.');
        document.getElementById('form-course').focus();
        return;
    }
    if (!modeId) {
        alert('Please select the Quality Assessment Work Mode.');
        document.getElementById('form-mode').focus();
        return;
    }
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Please select an Excel (.xlsx) or CSV file to upload.');
        fileInput.focus();
        return;
    }

    var file = fileInput.files[0];
    if (file.size > 10 * 1024 * 1024) {
        alert('File exceeds maximum allowed size of 10 MB.');
        return;
    }

    var formData = new FormData();
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    formData.append('action', 'validate_qa_upload');
    formData.append('ajax', '1');
    formData.append('course_id', courseId);
    formData.append('mode_id', modeId);
    formData.append('qa_file', file);

    var btn = document.getElementById('btn-validate-qa');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Parsing and Validating Spreadsheet...';

    resDiv.style.display = 'block';
    resDiv.innerHTML = '<div style="padding:15px; background:#f8fafc; border-radius:8px; border:1px solid #cbd5e1; text-align:center;"><i class="fas fa-spinner fa-spin"></i> Analyzing spreadsheet structure, columns, and quality rows...</div>';

    fetch('task-tracker.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magnifying-glass-chart"></i> Upload &amp; Validate Spreadsheet';

        if (!data.success) {
            var errHtml = '<div class="alert alert-error" style="margin:0;"><div style="font-weight:700; margin-bottom:6px;"><i class="fas fa-circle-xmark"></i> VALIDATION FAILED</div><p style="margin:0 0 8px 0;">' + (data.error || 'Validation failed.') + '</p>';
            if (data.errors && data.errors.length > 0) {
                errHtml += '<ul style="margin:0; padding-left:18px; font-size:0.85rem;">';
                data.errors.forEach(function(e) { errHtml += '<li>' + e + '</li>'; });
                errHtml += '</ul>';
            }
            errHtml += '</div>';
            resDiv.innerHTML = errHtml;
            return;
        }

        var val = data.val_res || data;
        if (!val.valid) {
            var errHtml = '<div class="alert alert-error" style="margin:0;"><div style="font-weight:700; font-size:1rem; margin-bottom:6px;"><i class="fas fa-circle-xmark"></i> VALIDATION FAILED</div>';
            var totalErrorsCount = (val.errors ? val.errors.length : 0);
            var totalWarnCount = (val.warnings ? val.warnings.length : 0);
            errHtml += '<p style="margin:0 0 10px 0; font-weight:600;">' + totalErrorsCount + ' blocking error(s) &bull; ' + totalWarnCount + ' warning(s)</p>';

            var cats = val.errors_by_category || {};
            
            // 1. File Structure Errors
            if (cats.file_structure && cats.file_structure.length > 0) {
                errHtml += '<div style="background:#fee2e2; border:1px solid #fca5a5; border-radius:6px; padding:10px; margin-bottom:8px;">';
                errHtml += '<strong style="color:#991b1b; font-size:0.85rem;"><i class="fas fa-file-excel"></i> File Structure Errors (' + cats.file_structure.length + '):</strong>';
                errHtml += '<ul style="margin:4px 0 0 0; padding-left:18px; font-size:0.82rem; color:#991b1b;">';
                cats.file_structure.forEach(function(e) { errHtml += '<li>' + e + '</li>'; });
                errHtml += '</ul></div>';
            }

            // 2. Data Errors
            if (cats.data_errors && cats.data_errors.length > 0) {
                errHtml += '<div style="background:#fee2e2; border:1px solid #fca5a5; border-radius:6px; padding:10px; margin-bottom:8px;">';
                errHtml += '<strong style="color:#991b1b; font-size:0.85rem;"><i class="fas fa-table"></i> Data &amp; Field Errors (' + cats.data_errors.length + '):</strong>';
                errHtml += '<ul style="margin:4px 0 0 0; padding-left:18px; font-size:0.82rem; color:#991b1b;">';
                cats.data_errors.forEach(function(e) { errHtml += '<li>' + e + '</li>'; });
                errHtml += '</ul></div>';
            }

            // 3. Duplicate Errors
            if (cats.duplicate_errors && cats.duplicate_errors.length > 0) {
                errHtml += '<div style="background:#fee2e2; border:1px solid #fca5a5; border-radius:6px; padding:10px; margin-bottom:8px;">';
                errHtml += '<strong style="color:#991b1b; font-size:0.85rem;"><i class="fas fa-copy"></i> Duplicate Lecture Errors (' + cats.duplicate_errors.length + '):</strong>';
                errHtml += '<ul style="margin:4px 0 0 0; padding-left:18px; font-size:0.82rem; color:#991b1b;">';
                cats.duplicate_errors.forEach(function(e) { errHtml += '<li>' + e + '</li>'; });
                errHtml += '</ul></div>';
            }
            // Fallback for general uncategorized errors
            if ((!cats.file_structure || cats.file_structure.length === 0) &&
                (!cats.data_errors || cats.data_errors.length === 0) &&
                (!cats.duplicate_errors || cats.duplicate_errors.length === 0) &&
                val.errors && val.errors.length > 0) {
                errHtml += '<ul style="margin:4px 0 8px 0; padding-left:18px; font-size:0.85rem; color:#991b1b;">';
                val.errors.forEach(function(e) { errHtml += '<li>' + e + '</li>'; });
                errHtml += '</ul>';
            }

            // Warnings
            if (val.warnings && val.warnings.length > 0) {
                errHtml += '<div style="background:#fefce8; border:1px solid #fef08a; border-radius:6px; padding:10px; margin-top:8px;">';
                errHtml += '<strong style="color:#854d0e; font-size:0.85rem;"><i class="fas fa-triangle-exclamation"></i> Non-Blocking Warnings (' + val.warnings.length + '):</strong>';
                errHtml += '<ul style="margin:4px 0 0 0; padding-left:18px; font-size:0.82rem; color:#854d0e;">';
                val.warnings.forEach(function(w) { errHtml += '<li>' + w + '</li>'; });
                errHtml += '</ul></div>';
            }

            errHtml += '<div style="margin-top:12px; font-size:0.82rem; color:var(--text-muted);"><i class="fas fa-circle-info"></i> Please correct the highlighted errors in your spreadsheet and re-upload.</div></div>';
            resDiv.innerHTML = errHtml;
            return;
        }

        // Passed!
        var stats = val.stats;
        var succHtml = '<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:16px;">';
        succHtml += '<div style="font-weight:700; color:#166534; font-size:1.05rem; display:flex; align-items:center; gap:8px; margin-bottom:12px;"><i class="fas fa-circle-check" style="font-size:1.2rem;"></i> VALIDATION PASSED</div>';

        succHtml += '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:10px; margin-bottom:14px;">';
        succHtml += '<div style="background:#fff; border:1px solid #dcfce7; border-radius:8px; padding:10px; text-align:center;"><div style="font-size:0.75rem; color:#15803d; font-weight:600;">Lectures Assessed</div><div style="font-size:1.25rem; font-weight:800; color:#166534;">' + stats.total_lectures + '</div></div>';
        succHtml += '<div style="background:#fff; border:1px solid #dcfce7; border-radius:8px; padding:10px; text-align:center;"><div style="font-size:0.75rem; color:#15803d; font-weight:600;">Total Assessment Time</div><div style="font-size:1.25rem; font-weight:800; color:#166534;">' + stats.total_assessment_minutes + ' min</div></div>';
        succHtml += '<div style="background:#fff; border:1px solid #dcfce7; border-radius:8px; padding:10px; text-align:center;"><div style="font-size:0.75rem; color:#15803d; font-weight:600;">Assessment Hours</div><div style="font-size:1.25rem; font-weight:800; color:#166534;">' + stats.total_assessment_hours.toFixed(2) + ' Hrs</div></div>';
        if (data.can_view_financials && data.hourly_rate !== undefined && stats.calculated_charge !== undefined) {
            succHtml += '<div style="background:#fff; border:1px solid #dcfce7; border-radius:8px; padding:10px; text-align:center;"><div style="font-size:0.75rem; color:#15803d; font-weight:600;">Hourly Rate</div><div style="font-size:1.25rem; font-weight:800; color:#166534;">₹' + Number(data.hourly_rate).toFixed(2) + '</div></div>';
            succHtml += '<div style="background:#fff; border:1px solid #dcfce7; border-radius:8px; padding:10px; text-align:center;"><div style="font-size:0.75rem; color:#15803d; font-weight:600;">Calculated Charge</div><div style="font-size:1.25rem; font-weight:800; color:#166534;">₹' + Number(stats.calculated_charge).toFixed(2) + '</div></div>';
        }
        succHtml += '</div>';

        if (val.warnings && val.warnings.length > 0) {
            succHtml += '<div style="background:#fefce8; border:1px solid #fef08a; border-radius:8px; padding:10px; margin-bottom:12px;">';
            succHtml += '<strong style="color:#854d0e; font-size:0.85rem;"><i class="fas fa-triangle-exclamation"></i> Warnings (' + val.warnings.length + '):</strong>';
            succHtml += '<ul style="margin:4px 0 0 0; padding-left:18px; font-size:0.82rem; color:#854d0e;">';
            val.warnings.forEach(function(w) { succHtml += '<li>' + w + '</li>'; });
            succHtml += '</ul></div>';
        }

        succHtml += '<button type="button" class="btn btn-success" style="width:100%; padding:12px; font-weight:700; font-size:0.95rem;" onclick="confirmSubmitQa(\'' + data.temp_token + '\')">';
        succHtml += '<i class="fas fa-circle-check"></i> Confirm &amp; Submit Assessment Report';
        succHtml += '</button>';
        succHtml += '</div>';

        resDiv.innerHTML = succHtml;
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magnifying-glass-chart"></i> Upload &amp; Validate Spreadsheet';
        resDiv.innerHTML = '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> Network or server error during validation. Please try again.</div>';
    });
}

function confirmSubmitQa(tempToken) {
    requestLocation(function(success) {
        if (!success) {
            alert('Location access is required to record this activity. Submission blocked.');
            return;
        }

        var formData = new FormData();
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        formData.append('action', 'submit_qa_assessment');
        formData.append('ajax', '1');
        formData.append('temp_token', tempToken);
        formData.append('latitude', clientLatitude);
        formData.append('longitude', clientLongitude);

        var resDiv = document.getElementById('qa-validation-result');
        resDiv.innerHTML = '<div style="padding:15px; background:#f8fafc; border-radius:8px; border:1px solid #cbd5e1; text-align:center;"><i class="fas fa-spinner fa-spin"></i> Finalizing transaction and creating task records...</div>';

        fetch('task-tracker.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                resDiv.innerHTML = '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> ' + (data.error || 'Submission failed.') + '</div>';
            }
        })
        .catch(function(err) {
            resDiv.innerHTML = '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> Network error during submission.</div>';
        });
    });
}

// ── Report Replacement Handlers ──
function openReplaceQaModal(taskId, courseName, courseId, currentRef, currentVersion) {
    document.getElementById('replace-qa-task-id').value = taskId;
    document.getElementById('replace-qa-course-id').value = courseId;
    document.getElementById('replace-qa-course-name').textContent = courseName;
    document.getElementById('replace-qa-current-ref').textContent = currentRef;
    document.getElementById('replace-qa-current-ver').textContent = 'Version ' + currentVersion;
    document.getElementById('replace-qa-file-input').value = '';
    var resDiv = document.getElementById('replace-qa-validation-result');
    resDiv.style.display = 'none';
    resDiv.innerHTML = '';
    openModal('replace-qa-modal');
}

function validateReplaceQaUpload() {
    var taskId = document.getElementById('replace-qa-task-id').value;
    var courseId = document.getElementById('replace-qa-course-id').value;
    var modeSelect = document.getElementById('form-mode');
    var qaModeId = '';
    for (var i = 0; i < modeSelect.options.length; i++) {
        if (modeSelect.options[i].getAttribute('data-is-qa') === '1') {
            qaModeId = modeSelect.options[i].value;
            break;
        }
    }
    var fileInput = document.getElementById('replace-qa-file-input');
    var resDiv = document.getElementById('replace-qa-validation-result');

    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Please select a spreadsheet file (.xlsx or .csv) to upload.');
        fileInput.focus();
        return;
    }

    var file = fileInput.files[0];
    if (file.size > 10 * 1024 * 1024) {
        alert('File exceeds maximum allowed size of 10 MB.');
        return;
    }

    var formData = new FormData();
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    formData.append('action', 'validate_qa_upload');
    formData.append('ajax', '1');
    formData.append('course_id', courseId);
    formData.append('mode_id', qaModeId);
    formData.append('qa_file', file);

    var btn = document.getElementById('btn-validate-replace-qa');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validating Replacement...';

    resDiv.style.display = 'block';
    resDiv.innerHTML = '<div style="padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #cbd5e1; text-align:center;"><i class="fas fa-spinner fa-spin"></i> Validating spreadsheet...</div>';

    fetch('task-tracker.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magnifying-glass-chart"></i> Validate Replacement Spreadsheet';

        if (!data.success) {
            resDiv.innerHTML = '<div class="alert alert-error"><i class="fas fa-circle-xmark"></i> ' + (data.error || 'Validation failed.') + '</div>';
            return;
        }

        var val = data.val_res || data;
        if (!val.valid) {
            var errHtml = '<div class="alert alert-error" style="margin:0;"><div style="font-weight:700; margin-bottom:6px;"><i class="fas fa-circle-xmark"></i> VALIDATION FAILED</div>';
            var cats = val.errors_by_category || {};
            if (val.errors && val.errors.length > 0) {
                errHtml += '<ul style="margin:4px 0; padding-left:18px; font-size:0.85rem;">';
                val.errors.forEach(function(e) { errHtml += '<li>' + e + '</li>'; });
                errHtml += '</ul>';
            }
            errHtml += '</div>';
            resDiv.innerHTML = errHtml;
            return;
        }

        // Passed
        var stats = val.stats;
        var succHtml = '<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:14px;">';
        var passDetails = stats.total_assessment_hours.toFixed(2) + ' Hrs';
        if (data.can_view_financials && stats.calculated_charge !== undefined) {
            passDetails += ', ₹' + Number(stats.calculated_charge).toFixed(2);
        }
        succHtml += '<div style="font-weight:700; color:#166534; margin-bottom:8px;"><i class="fas fa-circle-check"></i> Validation Passed: ' + stats.total_lectures + ' lectures (' + passDetails + ')</div>';
        succHtml += '<button type="button" class="btn btn-warning" style="width:100%; padding:10px; font-weight:700;" onclick="confirmReplaceQa(\'' + data.temp_token + '\', ' + taskId + ')">';
        succHtml += '<i class="fas fa-rotate"></i> Confirm &amp; Save Replacement Report';
        succHtml += '</button></div>';
        resDiv.innerHTML = succHtml;
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magnifying-glass-chart"></i> Validate Replacement Spreadsheet';
        resDiv.innerHTML = '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> Network error during validation.</div>';
    });
}

function confirmReplaceQa(tempToken, taskId) {
    if (!confirm('Are you sure you want to replace this assessment report? The previous version will be safely archived.')) {
        return;
    }

    requestLocation(function(success) {
        var formData = new FormData();
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        formData.append('action', 'replace_qa_assessment');
        formData.append('ajax', '1');
        formData.append('task_id', taskId);
        formData.append('temp_token', tempToken);
        if (clientLatitude !== null && clientLongitude !== null) {
            formData.append('latitude', clientLatitude);
            formData.append('longitude', clientLongitude);
        }

        var resDiv = document.getElementById('replace-qa-validation-result');
        resDiv.innerHTML = '<div style="padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #cbd5e1; text-align:center;"><i class="fas fa-spinner fa-spin"></i> Archiving prior version and saving replacement report...</div>';

        fetch('task-tracker.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                alert(data.message || 'Report replaced successfully.');
                window.location.reload();
            } else {
                resDiv.innerHTML = '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> ' + (data.error || 'Replacement failed.') + '</div>';
            }
        })
        .catch(function(err) {
            resDiv.innerHTML = '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> Network error during replacement.</div>';
        });
    });
}

function openViewQaModal(reportId) {
    openModal('view-qa-modal');
    var bodyEl = document.getElementById('view-qa-modal-content');
    bodyEl.innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:1.8rem; color:var(--primary);"></i><p style="margin-top:10px;">Loading assessment data...</p></div>';

    fetch('task-tracker.php?action=get_qa_items&report_id=' + reportId)
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) {
            bodyEl.innerHTML = '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> ' + (res.error || 'Failed to load report') + '</div>';
            return;
        }

        var rep = res.report;
        var items = res.items || [];
        var ai = res.ai_report;

        var html = '';
        // Context banner
        html += '<div style="font-size:0.78rem; color:#475569; margin-bottom:12px; background:#f1f5f9; padding:8px 12px; border-radius:6px; border:1px solid #e2e8f0;">';
        html += '<i class="fas fa-circle-info" style="color:var(--primary);"></i> Assessment submitted by L&amp;D Intern for the selected course <strong>' + (rep.course_name_snapshot || '') + '</strong>. Original File: <em>' + (rep.original_filename || '') + '</em>';
        html += '</div>';

        // Statistics strip
        html += '<div style="margin-bottom:8px; font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;"><i class="fas fa-calculator"></i> System-Generated Assessment Statistics (Authoritative Spreadsheet Metrics)</div>';
        html += '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:10px; margin-bottom:16px; background:#f8fafc; padding:12px; border-radius:10px; border:1px solid var(--border);">';
        html += '<div><span style="font-size:0.75rem; color:var(--text-muted); display:block;">Reference</span><strong>' + (rep.report_reference || '') + '</strong> <span class="badge blue" style="font-size:0.65rem; padding:1px 4px;">v' + (rep.version || 1) + '</span></div>';
        html += '<div><span style="font-size:0.75rem; color:var(--text-muted); display:block;">Course</span><strong>' + (rep.course_name_snapshot || '') + '</strong></div>';
        html += '<div><span style="font-size:0.75rem; color:var(--text-muted); display:block;">Lectures</span><strong>' + rep.row_count + '</strong></div>';
        html += '<div><span style="font-size:0.75rem; color:var(--text-muted); display:block;">Assessment Time</span><strong>' + (parseFloat(rep.total_assessment_hours).toFixed(2)) + ' Hours</strong></div>';
        if (rep.hourly_rate_snapshot !== undefined && rep.hourly_rate_snapshot !== null) {
            html += '<div><span style="font-size:0.75rem; color:var(--text-muted); display:block;">Hourly Rate</span><strong>₹' + (parseFloat(rep.hourly_rate_snapshot).toFixed(2)) + '</strong></div>';
        }
        if (rep.calculated_charge !== undefined && rep.calculated_charge !== null) {
            html += '<div><span style="font-size:0.75rem; color:var(--text-muted); display:block;">Charge</span><strong style="color:var(--success);">₹' + (parseFloat(rep.calculated_charge).toFixed(2)) + '</strong></div>';
        }
        html += '</div>';

        // Tabs
        html += '<div style="display:flex; gap:10px; border-bottom:2px solid var(--border); margin-bottom:14px;">';
        html += '<button type="button" class="btn btn-sm btn-primary" id="tab-btn-items" onclick="switchQaTab(\'items\')"><i class="fas fa-table-list"></i> Assessed Lectures (' + items.length + ')</button>';
        html += '<button type="button" class="btn btn-sm btn-outline" id="tab-btn-ai" onclick="switchQaTab(\'ai\')"><i class="fas fa-brain"></i> AI Quality Insights</button>';
        html += '</div>';

        // Tab Content 1: Items table
        html += '<div id="qa-tab-items" style="display:block; max-height:420px; overflow-y:auto; overflow-x:auto;">';
        html += '<table class="table" style="font-size:0.8rem; width:100%;">';
        html += '<thead><tr><th>#</th><th>Chapter</th><th>Lecture Title</th><th>Lang</th><th>Faculty</th><th>Dur.</th><th>Content</th><th>Video</th><th>Audio</th><th>Slide</th><th>Assessed</th></tr></thead><tbody>';

        var gradeBadge = function(g, r) {
            var cls = g == 1 ? 'green' : (g == 2 ? 'amber' : 'red');
            var txt = g == 1 ? '1 - Good' : (g == 2 ? '2 - Okay' : '3 - Improve');
            var out = '<span class="badge ' + cls + '" style="font-size:0.7rem; padding:2px 5px;">' + txt + '</span>';
            if (r) {
                out += '<div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px; max-width:140px; word-break:break-word;">' + r + '</div>';
            }
            return out;
        };

        items.forEach(function(it) {
            html += '<tr>';
            html += '<td>' + it.source_row_number + '</td>';
            html += '<td><strong>' + it.chapter + '</strong></td>';
            html += '<td>' + it.lecture_title + '</td>';
            html += '<td><span class="badge blue">' + it.language + '</span></td>';
            html += '<td>' + it.faculty_name + '</td>';
            html += '<td>' + it.lecture_duration_minutes + 'm</td>';
            html += '<td>' + gradeBadge(it.content_grade, it.content_remark) + '</td>';
            html += '<td>' + gradeBadge(it.video_grade, it.video_remark) + '</td>';
            html += '<td>' + gradeBadge(it.audio_grade, it.audio_remark) + '</td>';
            html += '<td>' + gradeBadge(it.slide_grade, it.slide_remark) + '</td>';
            html += '<td><strong>' + it.assessment_minutes + 'm</strong></td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';

        // Tab Content 2: AI Insights
        html += '<div id="qa-tab-ai" style="display:none; max-height:420px; overflow-y:auto;">';
        if (ai && rep.ai_status === 'completed') {
            html += '<div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px; margin-bottom:12px;">';
            html += '<div style="font-weight:700; color:#1e40af; margin-bottom:4px;"><i class="fas fa-brain"></i> Executive Summary &bull; Overall: ' + (ai.overall_grade || 'Completed') + '</div>';
            html += '<p style="margin:0; font-size:0.85rem; color:#1e3a8a; line-height:1.5;">' + (ai.summary || 'Summary not available.') + '</p>';
            html += '</div>';

            if (ai.recommendations && ai.recommendations.length > 0) {
                html += '<div style="margin-bottom:12px;"><strong>Key Recommendations:</strong><ul style="margin:4px 0 0 0; padding-left:18px; font-size:0.85rem;">';
                ai.recommendations.forEach(function(rc) { html += '<li>' + rc + '</li>'; });
                html += '</ul></div>';
            }

            if (ai.reverification_items && ai.reverification_items.length > 0) {
                html += '<div style="margin-bottom:12px; background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:10px;">';
                html += '<strong style="color:#9a3412; font-size:0.85rem;"><i class="fas fa-magnifying-glass"></i> Lectures Recommended for Manual Reverification:</strong>';
                html += '<ul style="margin:4px 0 0 0; padding-left:18px; font-size:0.82rem; color:#9a3412;">';
                ai.reverification_items.forEach(function(rv) { 
                    html += '<li>Row ' + rv.row + ' (' + (rv.title || '') + '): ' + (rv.reason || '') + '</li>'; 
                });
                html += '</ul></div>';
            }
        } else {
            var aiMsg = 'AI report not available yet.';
            if (rep.ai_status === 'failed') {
                aiMsg = 'AI analysis could not be completed for this report (AI status: Failed). The intern task and financial totals remain valid and intact.';
            } else if (rep.ai_status === 'pending_config') {
                aiMsg = 'AI report not available yet (AI provider credentials are not yet configured on this server).';
            } else {
                aiMsg = 'AI report not available yet (AI analysis is currently queued or in progress).';
            }
            html += '<div class="alert alert-info"><i class="fas fa-info-circle"></i> ' + aiMsg + '</div>';
        }
        html += '</div>';

        bodyEl.innerHTML = html;
    })
    .catch(function(err) {
        bodyEl.innerHTML = '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> Error loading assessment details.</div>';
    });
}

function switchQaTab(tab) {
    var tabItems = document.getElementById('qa-tab-items');
    var tabAi = document.getElementById('qa-tab-ai');
    var btnItems = document.getElementById('tab-btn-items');
    var btnAi = document.getElementById('tab-btn-ai');

    if (tab === 'items') {
        if (tabItems) tabItems.style.display = 'block';
        if (tabAi) tabAi.style.display = 'none';
        if (btnItems) { btnItems.className = 'btn btn-sm btn-primary'; }
        if (btnAi) { btnAi.className = 'btn btn-sm btn-outline'; }
    } else {
        if (tabItems) tabItems.style.display = 'none';
        if (tabAi) tabAi.style.display = 'block';
        if (btnItems) { btnItems.className = 'btn btn-sm btn-outline'; }
        if (btnAi) { btnAi.className = 'btn btn-sm btn-primary'; }
    }
}
</script>

<?php include 'includes/admin_footer.php'; ?>
