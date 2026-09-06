<?php
/**
 * PEPP ERP — Assessment Results & Score Management
 * 
 * Upload CSV/XLSX test results, validate, preview, publish, and manage
 * assessment scores for Study Plan activities.
 * 
 * SAFETY: This module NEVER touches study_plan_analytics.
 * All data is stored in assessment_result_batches + assessment_results.
 */
require_once 'includes/auth.php';
require_once 'includes/assessment_rank_helper.php';

// Safe AJAX action check for card designer tools
$ajax_action = $_GET['action'] ?? $_POST['action'] ?? '';
$is_cards_lookup = in_array($ajax_action, ['get_published_tests_by_year', 'get_course_participation_summary', 'get_merged_results'], true);

if ($is_cards_lookup) {
    if (!can_access('studyplans') && !can_access('cards') && !can_access('card-templates')) {
        require_permission('studyplans');
    }
} else {
    require_permission('studyplans');
}

$active_page = 'assessment-results';
$page_title  = 'Mega Test Results';
$page_sub    = 'Upload, validate, and publish student mega test results';

if (!function_exists('ar_esc')) {
    function ar_esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

function parse_time_spent_seconds($str) {
    if (empty($str) || strtolower(trim($str)) === 'na') return null;
    $s = trim($str); $total = 0;
    if (preg_match('/(\d+)\s*hr/i', $s, $m)) $total += (int)$m[1] * 3600;
    if (preg_match('/(\d+)\s*min/i', $s, $m)) $total += (int)$m[1] * 60;
    if (preg_match('/(\d+)\s*sec/i', $s, $m)) $total += (int)$m[1];
    return $total > 0 ? $total : (is_numeric($s) ? (int)$s : 0);
}

function parse_accuracy_numeric($str) {
    if (empty($str) || strtolower(trim($str)) === 'na') return null;
    $s = trim(str_replace('%', '', $str));
    return is_numeric($s) ? round((float)$s, 4) : null;
}

function safe_int($v) {
    if ($v === null || $v === '' || strtolower(trim((string)$v)) === 'na') return null;
    return is_numeric($v) ? (int)$v : null;
}

function safe_decimal($v) {
    if ($v === null || $v === '' || strtolower(trim((string)$v)) === 'na') return null;
    return is_numeric($v) ? round((float)$v, 2) : null;
}

function compute_competition_ranks(array $results) {
    $rankable = [];
    foreach ($results as $r) {
        if ($r['attendance_status'] === 'attended' && $r['score'] !== null) {
            $rankable[] = $r;
        }
    }
    usort($rankable, function($a, $b) { return ($b['score'] ?? 0) <=> ($a['score'] ?? 0); });
    $ranks = []; $prev_score = null; $rank = 0; $count = 0;
    foreach ($rankable as $r) {
        $count++;
        if ($r['score'] !== $prev_score) { $rank = $count; }
        $ranks[$r['student_email']] = $rank;
        $prev_score = $r['score'];
    }
    return $ranks;
}

function normalize_header($h) {
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    return strtolower(trim(preg_replace('/\s+/', ' ', $h)));
}

$REQUIRED_COLUMNS = [
    'learner details', 'name', 'mobile', 'attempt', 'status', 'evaluation',
    'submitted on', 'answered', 'score', 'total score', 'accuracy',
    'avg .q/hr', 'correct', 'wrong', 'skipped', 'time spent', 'export'
];

// AJAX ACTION HANDLERS
if (isset($_GET['action']) || (isset($_POST['action']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH']))) {
    header('Content-Type: application/json');
    $ajax_action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($ajax_action === 'get_courses') {
        $year = trim($_GET['year'] ?? '');
        if (empty($year)) { echo json_encode([]); exit; }
        try {
            $stmt = $pdo->prepare("SELECT id, course_name, course_code FROM pepp_courses WHERE academic_year = ? AND status = 'active' ORDER BY course_name ASC");
            $stmt->execute([$year]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { echo json_encode([]); }
        exit;
    }

    if ($ajax_action === 'get_study_plans') {
        $year = trim($_GET['year'] ?? '');
        if (empty($year)) { echo json_encode([]); exit; }
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT sp.id, sp.title, sp.start_date, sp.end_date, sp.status, sp.plan_type
                FROM study_plans sp
                JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
                WHERE sp.academic_year = ?
                  AND sp.status IN ('published','draft')
                  AND sp.is_deleted = 0
                  AND sa.is_deleted = 0
                ORDER BY sp.title ASC
            ");
            $stmt->execute([$year]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { echo json_encode([]); }
        exit;
    }

    if ($ajax_action === 'get_plan_info') {
        $plan_id = (int)($_GET['plan_id'] ?? 0);
        $year = trim($_GET['year'] ?? '');
        if ($plan_id <= 0 || empty($year)) { echo json_encode(['success' => false]); exit; }
        try {
            $stmt_assign = $pdo->prepare("SELECT assignment_type, assigned_value FROM study_plan_assignments WHERE study_plan_id = ? AND is_deleted = 0");
            $stmt_assign->execute([$plan_id]);
            $assignments = $stmt_assign->fetchAll(PDO::FETCH_ASSOC);

            $is_all = false;
            $assigned_names = [];
            foreach ($assignments as $asg) {
                if ($asg['assignment_type'] === 'all') {
                    $is_all = true;
                    break;
                } elseif ($asg['assignment_type'] === 'course') {
                    $assigned_names[] = $asg['assigned_value'];
                }
            }

            if ($is_all) {
                $stmt_courses = $pdo->prepare("SELECT id, course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active' ORDER BY course_name ASC");
                $stmt_courses->execute([$year]);
            } else {
                if (empty($assigned_names)) {
                    $stmt_courses = null;
                } else {
                    $placeholders = implode(',', array_fill(0, count($assigned_names), '?'));
                    $stmt_courses = $pdo->prepare("SELECT id, course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active' AND course_name IN ($placeholders) ORDER BY course_name ASC");
                    $stmt_courses->execute(array_merge([$year], $assigned_names));
                }
            }
            $courses = $stmt_courses ? $stmt_courses->fetchAll(PDO::FETCH_ASSOC) : [];
            $course_names = array_column($courses, 'course_name');

            $student_count = 0;
            if (!empty($course_names)) {
                $placeholders_courses = implode(',', array_fill(0, count($course_names), '?'));
                $stmt_count = $pdo->prepare("
                    SELECT COUNT(*) FROM users
                    WHERE status = 'approved'
                      AND student_status IN ('active','completed')
                      AND pepp_academic_year = ?
                      AND TRIM(pepp_course) IN ($placeholders_courses)
                ");
                $stmt_count->execute(array_merge([$year], $course_names));
                $student_count = (int)$stmt_count->fetchColumn();
            }

            echo json_encode([
                'success' => true,
                'courses_count' => count($courses),
                'students_count' => $student_count,
                'courses' => $courses
            ]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'get_tests') {
        $plan_id = (int)($_GET['plan_id'] ?? 0);
        $year = trim($_GET['year'] ?? '');
        if ($plan_id <= 0) { echo json_encode([]); exit; }
        $test_types = ['Attend Mock Test','Attend Mega Test','Attend Weekly Test','Practice Test','Previous Year Questions','Daily Quiz','Self-Assessment'];
        try {
            $custom_stmt = $pdo->query("SELECT name FROM study_plan_custom_types ORDER BY name ASC");
            $custom_types = $custom_stmt ? $custom_stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $all_test_types = array_unique(array_merge($test_types, $custom_types));
            $placeholders = implode(',', array_fill(0, count($all_test_types), '?'));
            $stmt = $pdo->prepare("
                SELECT id, activity_title, activity_type, activity_date, chapter,
                       COALESCE(NULLIF(topic, ''), NULLIF(subject, ''), '') as topic, day_number
                FROM study_plan_activities WHERE study_plan_id = ? AND activity_type IN ($placeholders) AND is_deleted = 0
                ORDER BY activity_date ASC, sort_order ASC, day_number ASC
            ");
            $stmt->execute(array_merge([$plan_id], array_values($all_test_types)));
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($activities as &$act) {
                $raw_top = trim((string)($act['topic'] ?? ''));
                $act['topic'] = $raw_top;

                $bs = $pdo->prepare("SELECT id, version, status, published_at, published_by FROM assessment_result_batches WHERE activity_id = ? AND study_plan_id = ? AND status = 'published' LIMIT 1");
                $bs->execute([$act['id'], $plan_id]);
                $batch = $bs->fetch(PDO::FETCH_ASSOC);

                $act['has_published_result'] = $batch ? true : false;
                $act['published_version'] = $batch ? (int)$batch['version'] : 0;
                $act['published_batch_id'] = $batch ? (int)$batch['id'] : 0;
            }
            unset($act);
            echo json_encode($activities);
        } catch (Exception $e) { echo json_encode([]); }
        exit;
    }

    if ($ajax_action === 'get_published_tests_by_year') {
        $year = trim($_GET['year'] ?? '');
        if (empty($year)) { echo json_encode([]); exit; }
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    arb.study_plan_id,
                    arb.activity_id,
                    arb.activity_title_snapshot AS activity_title,
                    arb.activity_type_snapshot AS activity_type,
                    COALESCE(spa.activity_date, arb.activity_date_snapshot) AS activity_date,
                    COALESCE(spa.chapter, arb.chapter_snapshot) AS chapter,
                    sp.title AS plan_title,
                    spa.day_number
                FROM assessment_result_batches arb
                LEFT JOIN study_plans sp ON arb.study_plan_id = sp.id
                LEFT JOIN study_plan_activities spa ON arb.activity_id = spa.id
                WHERE arb.academic_year = ? AND arb.status = 'published'
                ORDER BY COALESCE(spa.activity_date, arb.activity_date_snapshot) DESC, arb.activity_id DESC
            ");
            $stmt->execute([$year]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { echo json_encode([]); }
        exit;
    }

    if ($ajax_action === 'get_course_participation_summary') {
        $year = trim($_GET['year'] ?? '');
        $plan_id = (int)($_GET['plan_id'] ?? 0);
        $activity_id = (int)($_GET['activity_id'] ?? 0);

        if (empty($year) || $plan_id <= 0 || $activity_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
            exit;
        }

        try {
            // Find study plan details
            $stmt_plan = $pdo->prepare("SELECT title FROM study_plans WHERE id = ?");
            $stmt_plan->execute([$plan_id]);
            $plan_title = $stmt_plan->fetchColumn();
            if (!$plan_title) {
                $plan_title = 'Study Plan #' . $plan_id;
            }

            // Find activity details
            $stmt_act = $pdo->prepare("SELECT activity_title, activity_type, activity_date, chapter FROM study_plan_activities WHERE id = ?");
            $stmt_act->execute([$activity_id]);
            $activity = $stmt_act->fetch(PDO::FETCH_ASSOC);

            if (!$activity) {
                // Fallback: load snapshot from assessment_result_batches
                $stmt_snap = $pdo->prepare("
                    SELECT
                        activity_title_snapshot AS activity_title,
                        activity_type_snapshot AS activity_type,
                        activity_date_snapshot AS activity_date,
                        chapter_snapshot AS chapter
                    FROM assessment_result_batches
                    WHERE academic_year = ? AND study_plan_id = ? AND activity_id = ? AND status = 'published'
                    LIMIT 1
                ");
                $stmt_snap->execute([$year, $plan_id, $activity_id]);
                $activity = $stmt_snap->fetch(PDO::FETCH_ASSOC);
            }

            if (!$activity) {
                // Hard fallback: query by activity_id snapshots from any batch
                $stmt_snap2 = $pdo->prepare("
                    SELECT
                        activity_title_snapshot AS activity_title,
                        activity_type_snapshot AS activity_type,
                        activity_date_snapshot AS activity_date,
                        chapter_snapshot AS chapter
                    FROM assessment_result_batches
                    WHERE activity_id = ? AND status = 'published'
                    LIMIT 1
                ");
                $stmt_snap2->execute([$activity_id]);
                $activity = $stmt_snap2->fetch(PDO::FETCH_ASSOC);
            }

            if (!$activity) {
                // Final placeholder fallback
                $activity = [
                    'activity_title' => 'Logical Test #' . $activity_id,
                    'activity_type' => 'Mock Test',
                    'activity_date' => null,
                    'chapter' => null
                ];
            }

            // Find all assigned courses
            $stmt_assign = $pdo->prepare("SELECT assignment_type, assigned_value FROM study_plan_assignments WHERE study_plan_id = ?");
            $stmt_assign->execute([$plan_id]);
            $assignments = $stmt_assign->fetchAll(PDO::FETCH_ASSOC);

            $is_all = false;
            $assigned_names = [];
            foreach ($assignments as $asg) {
                if ($asg['assignment_type'] === 'all') {
                    $is_all = true;
                    break;
                } elseif ($asg['assignment_type'] === 'course') {
                    $assigned_names[] = $asg['assigned_value'];
                }
            }

            if ($is_all) {
                $stmt_courses = $pdo->prepare("SELECT id, course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active' ORDER BY course_name ASC");
                $stmt_courses->execute([$year]);
            } else {
                if (empty($assigned_names)) {
                    $stmt_courses = null;
                } else {
                    $placeholders = implode(',', array_fill(0, count($assigned_names), '?'));
                    $stmt_courses = $pdo->prepare("SELECT id, course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active' AND course_name IN ($placeholders) ORDER BY course_name ASC");
                    $stmt_courses->execute(array_merge([$year], $assigned_names));
                }
            }

            $courses = $stmt_courses ? $stmt_courses->fetchAll(PDO::FETCH_ASSOC) : [];
            $summary = [];

            foreach ($courses as $c) {
                // 1. Total Students enrolled in this course
                $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE status = 'approved' AND student_status IN ('active','completed') AND LOWER(TRIM(pepp_course)) = LOWER(TRIM(?)) AND pepp_academic_year = ?");
                $stmt_count->execute([$c['course_name'], $year]);
                $total_students = (int)$stmt_count->fetchColumn();

                // 2. Resolve result batches (new study-plan scoped or legacy course-scoped)
                $stmt_batches = $pdo->prepare("
                    SELECT id FROM assessment_result_batches
                    WHERE activity_id = ?
                      AND (study_plan_id = ? OR course_id = ? OR LOWER(TRIM(course_name)) = LOWER(TRIM(?)))
                      AND status = 'published'
                ");
                $stmt_batches->execute([$activity_id, $plan_id, $c['id'], $c['course_name']]);
                $batch_ids = $stmt_batches->fetchAll(PDO::FETCH_COLUMN);

                $attended = 0;
                $result_available = 'No';

                if (!empty($batch_ids)) {
                    $result_available = 'Yes';
                    $placeholders_batches = implode(',', array_fill(0, count($batch_ids), '?'));
                    // Count unique students enrolled in this course who attended
                    $stmt_att = $pdo->prepare("
                        SELECT COUNT(DISTINCT COALESCE(NULLIF(u.user_id, ''), ar.student_email))
                        FROM assessment_results ar
                        JOIN users u ON (ar.user_id = u.user_id OR LOWER(ar.student_email) = LOWER(u.email))
                        WHERE ar.batch_id IN ($placeholders_batches)
                          AND ar.attendance_status = 'attended'
                          AND u.status = 'approved'
                          AND u.student_status IN ('active','completed')
                          AND LOWER(TRIM(u.pepp_course)) = LOWER(TRIM(?))
                          AND u.pepp_academic_year = ?
                    ");
                    $stmt_att->execute(array_merge($batch_ids, [$c['course_name'], $year]));
                    $attended = (int)$stmt_att->fetchColumn();
                }

                $unattended = max(0, $total_students - $attended);

                $summary[] = [
                    'course_id' => $c['id'],
                    'course_name' => $c['course_name'],
                    'total_students' => $total_students,
                    'attended' => $attended,
                    'unattended' => $unattended,
                    'result_available' => $result_available
                ];
            }

            echo json_encode([
                'success' => true,
                'plan_title' => $plan_title,
                'activity_title' => $activity['activity_title'],
                'activity_type' => $activity['activity_type'],
                'activity_date' => $activity['activity_date'],
                'chapter' => $activity['chapter'],
                'courses' => $summary
            ]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($ajax_action === 'get_merged_results') {
        $year = trim($_GET['year'] ?? '');
        $plan_id = (int)($_GET['plan_id'] ?? 0);
        $activity_id = (int)($_GET['activity_id'] ?? 0);

        if (empty($year) || $plan_id <= 0 || $activity_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
            exit;
        }

        try {
            // Find all active published batches for the activity
            $stmt_batches = $pdo->prepare("
                SELECT id FROM assessment_result_batches
                WHERE activity_id = ?
                  AND study_plan_id = ?
                  AND academic_year = ?
                  AND status = 'published'
            ");
            $stmt_batches->execute([$activity_id, $plan_id, $year]);
            $batch_ids = $stmt_batches->fetchAll(PDO::FETCH_COLUMN);

            if (empty($batch_ids)) {
                echo json_encode(['success' => true, 'results' => []]);
                exit;
            }

            $canonical_res = AssessmentRankHelper::getCanonicalTestResults($pdo, $batch_ids, $year, __DIR__);
            $ranking_list = $canonical_res['ranked_list'];

            echo json_encode(['success' => true, 'results' => $ranking_list]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Upload and validate CSV
    if ($ajax_action === 'upload_validate') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please reload and try again.']);
            exit;
        }
        $activity_id = (int)($_POST['activity_id'] ?? 0);
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        $year = trim($_POST['academic_year'] ?? '');
        if ($activity_id <= 0 || $plan_id <= 0 || empty($year)) {
            echo json_encode(['success' => false, 'message' => 'Missing required selection parameters.']);
            exit;
        }

        // Server-side duplicate upload prevention check
        $bs = $pdo->prepare("SELECT id, version, status FROM assessment_result_batches WHERE activity_id = ? AND study_plan_id = ? AND status = 'published' LIMIT 1");
        $bs->execute([$activity_id, $plan_id]);
        $existing_batch = $bs->fetch(PDO::FETCH_ASSOC);

        if ($existing_batch) {
            echo json_encode(['success' => false, 'message' => 'Results have already been uploaded and published for this test. Please delete the existing results first.']);
            exit;
        }
        if (!isset($_FILES['result_file']) || $_FILES['result_file']['error'] !== UPLOAD_ERR_OK) {
            $maxSize = ini_get('upload_max_filesize') ?: '2M';
            $uerr = [UPLOAD_ERR_INI_SIZE=>'File exceeds the maximum allowed upload size ('.$maxSize.'). Please reduce the file size or split into smaller files.',UPLOAD_ERR_FORM_SIZE=>'File exceeds the maximum allowed upload size.',UPLOAD_ERR_PARTIAL=>'File was only partially uploaded. Please try again.',UPLOAD_ERR_NO_FILE=>'No file was uploaded.'];
            $ec = $_FILES['result_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            echo json_encode(['success' => false, 'message' => $uerr[$ec] ?? 'File upload failed. Please try again.']);
            exit;
        }
        $file = $_FILES['result_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv'])) {
            echo json_encode(['success' => false, 'message' => 'Only .csv files are accepted server-side. For .xlsx files, use the built-in XLSX-to-CSV converter on this page.']);
            exit;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, ['text/csv','text/plain','application/csv','application/vnd.ms-excel','application/octet-stream'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type detected: ' . ar_esc($mime)]);
            exit;
        }
        $content = file_get_contents($file['tmp_name']);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            echo json_encode(['success' => false, 'message' => 'File must have a header row and at least one data row.']);
            exit;
        }
        $header_line = array_shift($lines);
        $headers = str_getcsv($header_line);
        $normalized_headers = array_map('normalize_header', $headers);
        $missing = []; $col_map = [];
        foreach ($REQUIRED_COLUMNS as $req) {
            $idx = array_search($req, $normalized_headers);
            if ($idx === false) { $missing[] = $req; } else { $col_map[$req] = $idx; }
        }
        if (!empty($missing)) {
            echo json_encode(['success' => false, 'message' => 'Missing required columns: ' . implode(', ', $missing)]);
            exit;
        }
        $extra_cols = [];
        foreach ($normalized_headers as $i => $nh) {
            if (!in_array($nh, $REQUIRED_COLUMNS)) { $extra_cols[] = $headers[$i]; }
        }
        if (!empty($extra_cols)) {
            echo json_encode(['success' => false, 'message' => 'Unexpected extra columns found: ' . implode(', ', $extra_cols) . '. The file must contain exactly the 17 required columns.']);
            exit;
        }

        $stmt_act = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ? AND study_plan_id = ? AND is_deleted = 0");
        $stmt_act->execute([$activity_id, $plan_id]);
        $activity = $stmt_act->fetch(PDO::FETCH_ASSOC);
        if (!$activity) { echo json_encode(['success' => false, 'message' => 'Activity not found in the selected Study Plan.']); exit; }

        // Find all assigned courses to this Study Plan
        $stmt_assign = $pdo->prepare("SELECT assignment_type, assigned_value FROM study_plan_assignments WHERE study_plan_id = ? AND is_deleted = 0");
        $stmt_assign->execute([$plan_id]);
        $assignments = $stmt_assign->fetchAll(PDO::FETCH_ASSOC);

        $is_all = false;
        $assigned_names = [];
        foreach ($assignments as $asg) {
            if ($asg['assignment_type'] === 'all') {
                $is_all = true;
                break;
            } elseif ($asg['assignment_type'] === 'course') {
                $assigned_names[] = $asg['assigned_value'];
            }
        }

        if ($is_all) {
            $stmt_courses = $pdo->prepare("SELECT id, course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active' ORDER BY course_name ASC");
            $stmt_courses->execute([$year]);
        } else {
            if (empty($assigned_names)) {
                $stmt_courses = null;
            } else {
                $placeholders = implode(',', array_fill(0, count($assigned_names), '?'));
                $stmt_courses = $pdo->prepare("SELECT id, course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active' AND course_name IN ($placeholders) ORDER BY course_name ASC");
                $stmt_courses->execute(array_merge([$year], $assigned_names));
            }
        }
        $courses = $stmt_courses ? $stmt_courses->fetchAll(PDO::FETCH_ASSOC) : [];
        $course_names = array_column($courses, 'course_name');

        $eligible_students = [];
        if (!empty($course_names)) {
            $placeholders_courses = implode(',', array_fill(0, count($course_names), '?'));
            $stmt_elig = $pdo->prepare("
                SELECT user_id, LOWER(TRIM(email)) as email, name, pepp_course, pepp_academic_year
                FROM users
                WHERE status = 'approved'
                  AND student_status IN ('active','completed')
                  AND pepp_academic_year = ?
                  AND TRIM(pepp_course) IN ($placeholders_courses)
            ");
            $stmt_elig->execute(array_merge([$year], $course_names));
            while ($row = $stmt_elig->fetch(PDO::FETCH_ASSOC)) {
                $eligible_students[strtolower(trim($row['email']))] = $row;
            }
        }

        // Parse data rows
        $parsed_rows = [];
        $excluded_emails = [];
        $invalid_emails = [];
        $email_set = [];
        $row_num = 1;
        foreach ($lines as $line) {
            $row_num++;
            $cols = str_getcsv($line);
            while (count($cols) < count($headers)) $cols[] = '';
            $learner = trim($cols[$col_map['learner details']] ?? '');
            $email = strtolower(trim($learner));
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid_emails[] = ['row' => $row_num, 'value' => $learner];
                continue;
            }
            $src_name = trim($cols[$col_map['name']] ?? '');
            // Check if the student belongs to any assigned courses
            if (!isset($eligible_students[$email])) {
                $excluded_emails[] = ['email' => $email, 'name' => $src_name];
                continue;
            }
            // Check if student email is duplicate in the matched roster subset
            if (isset($email_set[$email])) {
                if (!is_array($email_set[$email])) {
                    $email_set[$email] = [$email_set[$email]];
                }
                $email_set[$email][] = $row_num;
                continue;
            }
            $email_set[$email] = $row_num;
            
            $src_status = trim($cols[$col_map['status']] ?? '');
            $src_evaluation = trim($cols[$col_map['evaluation']] ?? '');
            $s_lower = strtolower($src_status); $e_lower = strtolower($src_evaluation);
            if ($s_lower === 'submitted' && $e_lower === 'completed') { $attendance = 'attended'; }
            elseif ($s_lower === 'in progress' || $e_lower === 'unassigned') { $attendance = 'in_progress'; }
            else { $attendance = 'review_required'; }
            $parsed_rows[] = [
                'student_email'=>$email, 'user_id'=>$eligible_students[$email]['user_id'],
                'matched'=>true, 'attendance_status'=>$attendance,
                'src_learner_details'=>$learner, 'src_name'=>$src_name,
                'src_mobile'=>trim($cols[$col_map['mobile']] ?? ''),
                'src_attempt'=>trim($cols[$col_map['attempt']] ?? ''),
                'src_status'=>$src_status, 'src_evaluation'=>$src_evaluation,
                'src_submitted_on'=>trim($cols[$col_map['submitted on']] ?? ''),
                'src_answered'=>trim($cols[$col_map['answered']] ?? ''),
                'score'=>safe_decimal(trim($cols[$col_map['score']] ?? '')),
                'total_score'=>safe_decimal(trim($cols[$col_map['total score']] ?? '')),
                'src_accuracy'=>trim($cols[$col_map['accuracy']] ?? ''),
                'accuracy_numeric'=>parse_accuracy_numeric(trim($cols[$col_map['accuracy']] ?? '')),
                'src_avg_q_per_hr'=>trim($cols[$col_map['avg .q/hr']] ?? ''),
                'avg_q_per_hr_numeric'=>safe_int(trim($cols[$col_map['avg .q/hr']] ?? '')),
                'correct'=>safe_int(trim($cols[$col_map['correct']] ?? '')),
                'wrong'=>safe_int(trim($cols[$col_map['wrong']] ?? '')),
                'skipped'=>safe_int(trim($cols[$col_map['skipped']] ?? '')),
                'src_time_spent'=>trim($cols[$col_map['time spent']] ?? ''),
                'time_spent_seconds'=>parse_time_spent_seconds(trim($cols[$col_map['time spent']] ?? '')),
                'src_export'=>trim($cols[$col_map['export']] ?? ''),
            ];
        }
        $duplicate_emails = [];
        foreach ($email_set as $eml => $rows) {
            if (is_array($rows)) {
                $duplicate_emails[] = ['email' => $eml, 'rows' => $rows];
            }
        }
        if (!empty($duplicate_emails)) {
            $dm = [];
            foreach ($duplicate_emails as $d) { $dm[] = $d['email'] . ' (rows ' . implode(', ', $d['rows']) . ')'; }
            echo json_encode(['success'=>false, 'message'=>'Duplicate student emails detected in the uploaded file: '.implode('; ', $dm)]);
            exit;
        }
        $csv_emails = array_column($parsed_rows, 'student_email');
        $not_attended = [];
        foreach ($eligible_students as $eml => $stu) {
            if (!in_array($eml, $csv_emails)) {
                $not_attended[] = [
                    'student_email'=>$eml, 'user_id'=>$stu['user_id'], 'matched'=>true,
                    'attendance_status'=>'not_attended',
                    'src_learner_details'=>null, 'src_name'=>$stu['name'],
                    'src_mobile'=>null,'src_attempt'=>null,'src_status'=>null,'src_evaluation'=>null,
                    'src_submitted_on'=>null,'src_answered'=>null,
                    'score'=>null,'total_score'=>null,'src_accuracy'=>null,'accuracy_numeric'=>null,
                    'src_avg_q_per_hr'=>null,'avg_q_per_hr_numeric'=>null,
                    'correct'=>null,'wrong'=>null,'skipped'=>null,
                    'src_time_spent'=>null,'time_spent_seconds'=>null,'src_export'=>null,
                ];
            }
        }
        $all_results = array_merge($parsed_rows, $not_attended);
        $total_csv_rows = count($lines);
        $attended_count = count(array_filter($parsed_rows, fn($r) => $r['attendance_status'] === 'attended'));
        $in_progress_count = count(array_filter($parsed_rows, fn($r) => $r['attendance_status'] === 'in_progress'));
        $review_required_count = count(array_filter($parsed_rows, fn($r) => $r['attendance_status'] === 'review_required'));
        $unmatched_count = count($excluded_emails);
        $matched_count = count($parsed_rows);
        $att_scores = array_filter(array_map(fn($r)=>$r['score'], array_filter($all_results, fn($r)=>$r['attendance_status']==='attended' && $r['score']!==null)));
        $highest = !empty($att_scores) ? max($att_scores) : null;
        $lowest = !empty($att_scores) ? min($att_scores) : null;
        $avg = !empty($att_scores) ? round(array_sum($att_scores)/count($att_scores),2) : null;

        $_SESSION['ar_preview'] = [
            'activity_id'=>$activity_id, 'plan_id'=>$plan_id, 'course_id'=>0,
            'course_name'=>'All Courses', 'academic_year'=>$year, 'activity'=>$activity,
            'results'=>$all_results, 'filename'=>$file['name'],
            'stats'=>['total_rows'=>$total_csv_rows,'attended'=>$attended_count,
                'in_progress'=>$in_progress_count,'not_attended'=>count($not_attended),
                'review_required'=>$review_required_count,'unmatched'=>$unmatched_count,
                'matched'=>$matched_count,'invalid_emails'=>$invalid_emails,
                'highest'=>$highest,'lowest'=>$lowest,'average'=>$avg,
                'ranked_students'=>count($att_scores)],
            'existing_batch'=>$existing_batch,
        ];
        $ranks = compute_competition_ranks($all_results);
        $preview_table = [];
        foreach ($all_results as $r) {
            $r['rank'] = $ranks[$r['student_email']] ?? null;
            $r['student_name'] = $r['src_name'] ?: ($eligible_students[$r['student_email']]['name'] ?? "\xe2\x80\x94");
            $preview_table[] = $r;
        }
        usort($preview_table, function($a, $b) {
            $order = ['attended'=>0,'in_progress'=>1,'review_required'=>2,'not_attended'=>3];
            $oa=$order[$a['attendance_status']]??9; $ob=$order[$b['attendance_status']]??9;
            if ($oa!==$ob) return $oa<=>$ob;
            if ($a['rank']!==null && $b['rank']!==null) return $a['rank']<=>$b['rank'];
            if ($a['rank']!==null) return -1; if ($b['rank']!==null) return 1;
            return ($a['student_name']??'')<=>($b['student_name']??'');
        });
        echo json_encode([
            'success'=>true, 'stats'=>$_SESSION['ar_preview']['stats'],
            'preview'=>$preview_table, 'existing_batch'=>$existing_batch,
            'activity_title'=>$activity['activity_title'],
            'activity_type'=>$activity['activity_type'],
            'activity_date'=>$activity['activity_date'],
            'chapter'=>$activity['chapter'] ?? '',
            'excluded_emails'=>$excluded_emails,
        ]);
        exit;
    }

    // Publish results
    if ($ajax_action === 'publish_results') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['success'=>false,'message'=>'Security token mismatch.']); exit;
        }
        if (empty($_SESSION['ar_preview'])) {
            echo json_encode(['success'=>false,'message'=>'No preview data found. Please upload and validate first.']); exit;
        }
        $preview = $_SESSION['ar_preview'];
        $replace_reason = trim($_POST['replace_reason'] ?? '');
        $is_replacement = !empty($preview['existing_batch']);
        if ($is_replacement && empty($replace_reason)) {
            echo json_encode(['success'=>false,'message'=>'Replacement reason is required when replacing existing results.']); exit;
        }
        try {
            $pdo->beginTransaction();
            $new_version = 1;
            
            // ── Concurrency protection for ALL publications (first and replacement) ──
            // Use SELECT FOR UPDATE to lock any existing published batch for this activity.
            $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $sql_lock = "SELECT id, status, version FROM assessment_result_batches WHERE activity_id = ? AND study_plan_id = ? AND status = 'published'";
            if ($db_driver === 'mysql') {
                $sql_lock .= " FOR UPDATE";
            }
            $concurrent_check = $pdo->prepare($sql_lock);
            $concurrent_check->execute([$preview['activity_id'], $preview['plan_id']]);
            $existing_published = $concurrent_check->fetch(PDO::FETCH_ASSOC);
            
            if ($is_replacement) {
                $old_batch_id = (int)$preview['existing_batch']['id'];
                if (!$existing_published || (int)$existing_published['id'] !== $old_batch_id) {
                    $pdo->rollBack();
                    echo json_encode(['success'=>false,'message'=>'The previous batch was modified concurrently by another admin. Please refresh and try again.']); exit;
                }
                $new_version = $existing_published['version'] + 1;
            } elseif ($existing_published) {
                // Another admin published between our upload_validate and this publish call
                $pdo->rollBack();
                echo json_encode(['success'=>false,'message'=>'Another administrator published results for this activity (Version '.$existing_published['version'].') while you were preparing yours. Please refresh and re-upload if you need to replace.']); exit;
            }
            $batch_stmt = $pdo->prepare("INSERT INTO assessment_result_batches (activity_id,study_plan_id,academic_year,course_id,course_name,activity_title_snapshot,activity_type_snapshot,activity_date_snapshot,chapter_snapshot,version,status,source_filename,total_rows,matched_students,unmatched_emails,attended_count,not_attended_count,in_progress_count,review_required_count,uploaded_by,published_by,published_at,created_at) VALUES (?,?,?,0,'All Courses',?,?,?,?,?,'published',?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
            $batch_stmt->execute([
                $preview['activity_id'],$preview['plan_id'],$preview['academic_year'],
                $preview['activity']['activity_title'],$preview['activity']['activity_type'],
                $preview['activity']['activity_date']?:null,$preview['activity']['chapter']??null,
                $new_version,$preview['filename'],
                $preview['stats']['total_rows'],$preview['stats']['matched'],
                $preview['stats']['unmatched'],$preview['stats']['attended'],
                $preview['stats']['not_attended'],$preview['stats']['in_progress'],
                $preview['stats']['review_required'],
                $admin_username,$admin_username
            ]);
            $new_batch_id = $pdo->lastInsertId();
            if ($is_replacement) {
                $upd_stmt = $pdo->prepare("UPDATE assessment_result_batches SET status='replaced',replaced_by_batch_id=?,replace_reason=?,updated_at=NOW() WHERE id=?");
                $upd_stmt->execute([$new_batch_id,$replace_reason,$old_batch_id]);
            }
            $ins_stmt = $pdo->prepare("INSERT INTO assessment_results (batch_id,student_email,user_id,attendance_status,src_learner_details,src_name,src_mobile,src_attempt,src_status,src_evaluation,src_submitted_on,src_answered,score,total_score,src_accuracy,accuracy_numeric,src_avg_q_per_hr,avg_q_per_hr_numeric,correct,wrong,skipped,src_time_spent,time_spent_seconds,src_export) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($preview['results'] as $r) {
                $ins_stmt->execute([
                    $new_batch_id,$r['student_email'],$r['user_id'],$r['attendance_status'],
                    $r['src_learner_details'],$r['src_name'],$r['src_mobile'],$r['src_attempt'],
                    $r['src_status'],$r['src_evaluation'],$r['src_submitted_on'],$r['src_answered'],
                    $r['score'],$r['total_score'],$r['src_accuracy'],$r['accuracy_numeric'],
                    $r['src_avg_q_per_hr'],$r['avg_q_per_hr_numeric'],
                    $r['correct'],$r['wrong'],$r['skipped'],
                    $r['src_time_spent'],$r['time_spent_seconds'],$r['src_export'],
                ]);
            }
            $atype = $is_replacement ? 'assessment_result_replaced' : 'assessment_result_published';
            $details = ($is_replacement?"Replaced":"Published")." assessment results for '{$preview['activity']['activity_title']}' (Activity #{$preview['activity_id']}, Plan #{$preview['plan_id']}, Course: All Courses, Year: {$preview['academic_year']}, Version: {$new_version}, Batch #{$new_batch_id}, Attended: {$preview['stats']['attended']}, Not Attended: {$preview['stats']['not_attended']})";
            if ($is_replacement) $details .= ". Reason: {$replace_reason}";
            log_admin_activity($pdo, $admin_username, $atype, $details);
            $pdo->commit();
            unset($_SESSION['ar_preview']);
            echo json_encode(['success'=>true,'message'=>($is_replacement?'Results replaced':'Results published')." successfully (Version {$new_version}).",'batch_id'=>$new_batch_id,'version'=>$new_version]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Assessment result publish error: ".$e->getMessage());
            echo json_encode(['success'=>false,'message'=>'Failed to publish. Previous version remains active. Error: '.$e->getMessage()]);
        }
        exit;
    }

    // Get batch details
    if ($ajax_action === 'get_batch_details') {
        $batch_id = (int)($_GET['batch_id'] ?? 0);
        if ($batch_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid batch ID.']); exit; }
        try {
            $bs = $pdo->prepare("SELECT * FROM assessment_result_batches WHERE id = ?");
            $bs->execute([$batch_id]);
            $batch = $bs->fetch(PDO::FETCH_ASSOC);
            if (!$batch) { echo json_encode(['success'=>false,'message'=>'Batch not found.']); exit; }
            $rs = $pdo->prepare("SELECT * FROM assessment_results WHERE batch_id = ? ORDER BY score DESC, student_email ASC");
            $rs->execute([$batch_id]);
            $results = $rs->fetchAll(PDO::FETCH_ASSOC);
            $ranks = compute_competition_ranks($results);
            foreach ($results as &$r) {
                $r['rank'] = $ranks[$r['student_email']] ?? null;
                $r['display_name'] = $r['src_name'] ?: "\xe2\x80\x94";
                $r['percentage'] = ($r['score']!==null && $r['total_score']!==null && $r['total_score']>0) ? round(($r['score']/$r['total_score'])*100,2) : null;
            }
            unset($r);
            usort($results, function($a,$b) {
                $order = ['attended'=>0,'in_progress'=>1,'review_required'=>2,'not_attended'=>3];
                $oa=$order[$a['attendance_status']]??9; $ob=$order[$b['attendance_status']]??9;
                if ($oa!==$ob) return $oa<=>$ob;
                if ($a['rank']!==null && $b['rank']!==null) return $a['rank']<=>$b['rank'];
                if ($a['rank']!==null) return -1; if ($b['rank']!==null) return 1;
                return 0;
            });
            $as2 = array_filter(array_map(fn($r)=>$r['score'], array_filter($results, fn($r)=>$r['attendance_status']==='attended' && $r['score']!==null)));
            $aa2 = array_filter(array_map(fn($r)=>$r['accuracy_numeric'], array_filter($results, fn($r)=>$r['attendance_status']==='attended' && $r['accuracy_numeric']!==null)));
            $at2 = array_filter(array_map(fn($r)=>$r['time_spent_seconds'], array_filter($results, fn($r)=>$r['attendance_status']==='attended' && $r['time_spent_seconds']!==null && $r['time_spent_seconds']>0)));
            echo json_encode(['success'=>true,'batch'=>$batch,'results'=>$results,'stats'=>[
                'attended'=>count(array_filter($results, fn($r)=>$r['attendance_status']==='attended')),
                'not_attended'=>count(array_filter($results, fn($r)=>$r['attendance_status']==='not_attended')),
                'in_progress'=>count(array_filter($results, fn($r)=>$r['attendance_status']==='in_progress')),
                'review_required'=>count(array_filter($results, fn($r)=>$r['attendance_status']==='review_required')),
                'highest'=>!empty($as2)?max($as2):null,'lowest'=>!empty($as2)?min($as2):null,
                'average'=>!empty($as2)?round(array_sum($as2)/count($as2),2):null,
                'avg_accuracy'=>!empty($aa2)?round(array_sum($aa2)/count($aa2),2):null,
                'avg_time_seconds'=>!empty($at2)?round(array_sum($at2)/count($at2)):null,
            ]]);
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Error loading batch.']); }
        exit;
    }

    // Export results CSV
    if ($ajax_action === 'export_results') {
        $batch_id = (int)($_GET['batch_id'] ?? 0);
        if ($batch_id <= 0) { header('Content-Type: text/plain'); echo 'Invalid batch.'; exit; }
        try {
            $bs = $pdo->prepare("SELECT * FROM assessment_result_batches WHERE id = ?");
            $bs->execute([$batch_id]);
            $batch = $bs->fetch(PDO::FETCH_ASSOC);
            if (!$batch) { echo 'Batch not found.'; exit; }
            $rs = $pdo->prepare("SELECT * FROM assessment_results WHERE batch_id = ? ORDER BY score DESC");
            $rs->execute([$batch_id]);
            $results = $rs->fetchAll(PDO::FETCH_ASSOC);
            $ranks = compute_competition_ranks($results);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.preg_replace('/[^a-zA-Z0-9_-]/','_',$batch['activity_title_snapshot']).'_Results_v'.$batch['version'].'.csv"');
            $out = fopen('php://output','w');
            fputcsv($out,['Rank','Student Name','Email','Score','Total Score','Percentage','Accuracy','Answered','Correct','Wrong','Skipped','Time Spent','Avg Q/hr','Attendance Status']);
            foreach ($results as $r) {
                $rnk = $ranks[$r['student_email']] ?? "\xe2\x80\x94";
                $pct = ($r['score']!==null&&$r['total_score']!==null&&$r['total_score']>0)?round(($r['score']/$r['total_score'])*100,2).'%':"\xe2\x80\x94";
                fputcsv($out,[$rnk,$r['src_name']?:"\xe2\x80\x94",$r['student_email'],$r['score']??"\xe2\x80\x94",$r['total_score']??"\xe2\x80\x94",$pct,$r['src_accuracy']??"\xe2\x80\x94",$r['src_answered']??"\xe2\x80\x94",$r['correct']??"\xe2\x80\x94",$r['wrong']??"\xe2\x80\x94",$r['skipped']??"\xe2\x80\x94",$r['src_time_spent']??"\xe2\x80\x94",$r['src_avg_q_per_hr']??"\xe2\x80\x94",ucfirst(str_replace('_',' ',$r['attendance_status']))]);
            }
            fclose($out);
            log_admin_activity($pdo,$admin_username,'assessment_result_exported',"Exported results for batch #{$batch_id}");
        } catch (Exception $e) { echo 'Export error.'; }
        exit;
    }

    // Get all batches for management table
    if ($ajax_action === 'get_all_batches') {
        $year = trim($_GET['year'] ?? ''); $cid = (int)($_GET['course_id'] ?? 0);
        $w = ['1=1']; $p = [];
        if (!empty($year)) { $w[] = "arb.academic_year = ?"; $p[] = $year; }
        if ($cid > 0) {
            $stmt_cn = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE id = ?");
            $stmt_cn->execute([$cid]);
            $course_name = $stmt_cn->fetchColumn();
            if ($course_name) {
                $w[] = "(arb.course_id = ? OR (arb.course_id = 0 AND arb.study_plan_id IN (
                    SELECT study_plan_id FROM study_plan_assignments
                    WHERE (assignment_type = 'all' OR (assignment_type = 'course' AND LOWER(TRIM(assigned_value)) = LOWER(TRIM(?))))
                      AND is_deleted = 0
                )))";
                $p[] = $cid;
                $p[] = $course_name;
            } else {
                $w[] = "arb.course_id = ?";
                $p[] = $cid;
            }
        }
        try {
            $stmt = $pdo->prepare("SELECT arb.*, sp.title as plan_title FROM assessment_result_batches arb LEFT JOIN study_plans sp ON arb.study_plan_id = sp.id WHERE ".implode(' AND ',$w)." ORDER BY arb.created_at DESC");
            $stmt->execute($p);
            echo json_encode(['success'=>true,'batches'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) { echo json_encode(['success'=>true,'batches'=>[]]); }
        exit;
    }

    // Get student assessment results
    if ($ajax_action === 'get_student_results') {
        $email = strtolower(trim($_GET['email'] ?? ''));
        $student_id = trim($_GET['student_id'] ?? $_GET['user_id'] ?? '');
        if (empty($email) && empty($student_id)) { echo json_encode([]); exit; }

        if (!empty($student_id)) {
            try {
                $stmt_resolve = $pdo->prepare("SELECT email FROM users WHERE user_id = ? LIMIT 1");
                $stmt_resolve->execute([$student_id]);
                $resolved_email = $stmt_resolve->fetchColumn();
                if ($resolved_email) {
                    $email = strtolower(trim($resolved_email));
                }
            } catch (Exception $e) {}
        }

        try {
            $stmt = $pdo->prepare("
                SELECT ar.*, arb.activity_title_snapshot, arb.activity_type_snapshot, arb.activity_date_snapshot, arb.chapter_snapshot, arb.course_name, arb.academic_year, arb.version, arb.status as batch_status
                FROM assessment_results ar
                JOIN assessment_result_batches arb ON ar.batch_id = arb.id
                JOIN users u ON LOWER(ar.student_email) = LOWER(u.email)
                WHERE ar.student_email = ?
                  AND arb.status = 'published'
                  AND (
                      arb.course_id = 0
                      OR LOWER(TRIM(arb.course_name)) = LOWER(TRIM(u.pepp_course))
                      OR arb.study_plan_id IN (
                          SELECT study_plan_id FROM study_plan_assignments
                          WHERE (assignment_type = 'all' OR (assignment_type = 'course' AND LOWER(TRIM(assigned_value)) = LOWER(TRIM(u.pepp_course))))
                            AND is_deleted = 0
                      )
                  )
                ORDER BY arb.activity_date_snapshot DESC
            ");
            $stmt->execute([$email]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($results as &$r) {
                $br = $pdo->prepare("SELECT student_email, score, attendance_status FROM assessment_results WHERE batch_id = ?");
                $br->execute([$r['batch_id']]);
                $ranks = compute_competition_ranks($br->fetchAll(PDO::FETCH_ASSOC));
                $r['rank'] = $ranks[$r['student_email']] ?? null;
                $r['total_ranked'] = count($ranks);
                if ($r['score']!==null && $r['total_score']!==null && $r['total_score']>0) { $r['percentage'] = round(($r['score']/$r['total_score'])*100,2); }

                if (is_credential_restricted('students')) {
                    $r['student_email'] = format_credential_text($r['student_email'], 'email', 'students');
                }
            }
            unset($r);
            echo json_encode($results);
        } catch (Exception $e) { echo json_encode([]); }
        exit;
    }

    // Delete result batch (Super Admin only)
    if ($ajax_action === 'delete_batch') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
            exit;
        }
        if (!is_super_admin()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied. Only Super Administrators can delete assessment results.']);
            exit;
        }
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        if ($batch_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid batch ID.']);
            exit;
        }
        try {
            $pdo->beginTransaction();
            
            // Get batch details for activity log
            $bs = $pdo->prepare("SELECT * FROM assessment_result_batches WHERE id = ?");
            $bs->execute([$batch_id]);
            $batch = $bs->fetch(PDO::FETCH_ASSOC);
            if (!$batch) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Batch not found.']);
                exit;
            }
            
            // Delete result rows
            $del_res = $pdo->prepare("DELETE FROM assessment_results WHERE batch_id = ?");
            $del_res->execute([$batch_id]);
            
            // Delete batch row
            $del_batch = $pdo->prepare("DELETE FROM assessment_result_batches WHERE id = ?");
            $del_batch->execute([$batch_id]);
            
            log_admin_activity($pdo, $admin_username, 'assessment_result_deleted', "Deleted assessment result batch #{$batch_id} ('{$batch['activity_title_snapshot']}', Course: '{$batch['course_name']}', Year: '{$batch['academic_year']}', Version: {$batch['version']})");
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Assessment results deleted successfully.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to delete results: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit;
}

$academic_years = [];
try { $academic_years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}

include 'includes/admin_nav.php';
?>
<!-- ─── PAGE STYLES ─────────────────────────────────────────────────── -->
<style>
.ar-wizard{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:24px}
.ar-wizard h3{font-family:'Space Grotesk',sans-serif;font-size:1.1rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.ar-steps{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.ar-step{display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted-foreground);font-weight:500}
.ar-step.active{color:var(--accent);font-weight:700}
.ar-step.done{color:var(--success)}
.ar-step .num{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;background:var(--muted);color:var(--muted-foreground)}
.ar-step.active .num{background:var(--accent);color:#fff}
.ar-step.done .num{background:var(--success);color:#fff}
.ar-step-arrow{color:var(--border);font-size:.8rem}
.ar-selectors{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:20px}
.ar-selectors .field{display:flex;flex-direction:column;gap:4px}
.ar-selectors label{font-size:.78rem;font-weight:600;color:var(--foreground)}
.ar-selectors select{padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius);font-size:.85rem;background:#fff;color:var(--foreground)}
.ar-test-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;margin-bottom:20px}
.ar-test-card{border:1px solid var(--border);border-radius:var(--radius);padding:14px;cursor:pointer;transition:all .2s;background:#fff}
.ar-test-card:hover{border-color:var(--accent);box-shadow:0 2px 8px rgba(139,92,246,.1)}
.ar-test-card.selected{border-color:var(--accent);background:rgba(139,92,246,.04);box-shadow:0 0 0 2px rgba(139,92,246,.2)}
.ar-test-card.has-results{border-color:#16a34a;background:rgba(34,197,94,0.04)}
.ar-test-card.has-results:hover{border-color:#15803d;box-shadow:0 2px 8px rgba(34,197,94,0.12)}
.ar-test-card.has-results.selected{border-color:#16a34a;background:rgba(34,197,94,0.08);box-shadow:0 0 0 2px rgba(34,197,94,0.15)}
.ar-test-card .test-title{font-weight:700;font-size:.88rem;margin-bottom:6px}
.ar-test-card .test-meta{display:flex;flex-wrap:wrap;gap:8px;font-size:.75rem;color:var(--muted-foreground)}
.ar-test-card .test-meta span{display:flex;align-items:center;gap:4px}
.ar-test-badge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:20px}
.ar-test-badge.published{background:rgba(34,197,94,.1);color:#16a34a}
.ar-test-badge.not-uploaded{background:var(--muted);color:var(--muted-foreground)}
.ar-upload-zone{border:2px dashed var(--border);border-radius:var(--radius);padding:40px;text-align:center;transition:all .2s;cursor:pointer;background:rgba(139,92,246,.02)}
.ar-upload-zone:hover,.ar-upload-zone.drag-over{border-color:var(--accent);background:rgba(139,92,246,.06)}
.ar-upload-zone i{font-size:2rem;color:var(--accent);margin-bottom:8px}
.ar-upload-zone p{color:var(--muted-foreground);font-size:.85rem;margin-top:4px}
.ar-upload-zone .file-types{font-size:.75rem;color:var(--muted-foreground);margin-top:8px}
.ar-preview-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.ar-stat-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:12px;text-align:center}
.ar-stat-card .val{font-size:1.4rem;font-weight:700;font-family:'Space Grotesk',sans-serif}
.ar-stat-card .lbl{font-size:.72rem;color:var(--muted-foreground);font-weight:500;margin-top:2px}
.ar-stat-card.green .val{color:#16a34a}
.ar-stat-card.orange .val{color:#f59e0b}
.ar-stat-card.red .val{color:#ef4444}
.ar-stat-card.blue .val{color:#3b82f6}
.ar-stat-card.purple .val{color:var(--accent)}
.ar-table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px}
.ar-table{width:100%;border-collapse:collapse;font-size:.8rem}
.ar-table th{background:var(--muted);font-weight:600;text-align:left;padding:10px 12px;white-space:nowrap;position:sticky;top:0;z-index:1}
.ar-table td{padding:8px 12px;border-top:1px solid var(--border);vertical-align:middle}
.ar-table tr:hover td{background:rgba(139,92,246,.03)}
.ar-status{display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:20px}
.ar-status.attended{background:rgba(34,197,94,.1);color:#16a34a}
.ar-status.not-attended{background:rgba(239,68,68,.08);color:#dc2626}
.ar-status.in-progress{background:rgba(245,158,11,.1);color:#d97706}
.ar-status.review-required{background:rgba(139,92,246,.1);color:var(--accent)}
.ar-rank{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:.9rem}
.ar-rank.top3{color:var(--accent)}
.ar-actions{display:flex;gap:8px;flex-wrap:wrap}
.ar-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--radius);font-size:.82rem;font-weight:600;border:none;cursor:pointer;transition:all .15s}
.ar-btn.primary{background:var(--accent);color:#fff}
.ar-btn.primary:hover{background:#7c3aed;transform:translateY(-1px)}
.ar-btn.secondary{background:var(--muted);color:var(--foreground)}
.ar-btn.secondary:hover{background:var(--border)}
.ar-btn.danger{background:rgba(239,68,68,.1);color:#dc2626}
.ar-btn.danger:hover{background:rgba(239,68,68,.2)}
.ar-btn.success{background:rgba(34,197,94,.1);color:#16a34a}
.ar-btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important}
.ar-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center;padding:20px}
.ar-modal-backdrop.show{display:flex}
.ar-modal{background:#fff;border-radius:12px;max-width:95vw;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,.2)}
.ar-modal.large{width:1200px}
.ar-modal.medium{width:700px}
.ar-modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.ar-modal-head h3{font-family:'Space Grotesk',sans-serif;font-size:1rem;font-weight:700}
.ar-modal-body{padding:20px;overflow-y:auto;flex:1}
.ar-modal-close{background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--muted-foreground);padding:4px}
.ar-toast{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:5000;padding:11px 20px;border-radius:50px;font-weight:600;font-size:.85rem;box-shadow:0 8px 28px rgba(0,0,0,.25);display:none}
.ar-toast.success{background:#16a34a;color:#fff}
.ar-toast.error{background:#dc2626;color:#fff}
.ar-loading{display:inline-flex;align-items:center;gap:8px;color:var(--muted-foreground);font-size:.85rem}
.ar-loading .spinner{width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:ar-spin .6s linear infinite}
@keyframes ar-spin{to{transform:rotate(360deg)}}
.ar-mgmt-section{margin-top:32px}
.ar-mgmt-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px}
.ar-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.ar-filters select,.ar-filters input{padding:6px 10px;border:1px solid var(--border);border-radius:var(--radius);font-size:.8rem;background:#fff}
.ar-empty{text-align:center;padding:40px;color:var(--muted-foreground)}
.ar-empty i{font-size:2rem;margin-bottom:8px;display:block;opacity:.5}
#ar-replace-modal textarea{width:100%;min-height:80px;border:1px solid var(--border);border-radius:var(--radius);padding:10px;font-size:.85rem;margin-top:8px;resize:vertical}
.ar-xlsx-notice{background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.15);border-radius:var(--radius);padding:12px 16px;font-size:.8rem;color:#1e40af;margin-bottom:12px;display:flex;align-items:center;gap:8px}
@media(max-width:768px){
    .ar-selectors{grid-template-columns:1fr}
    .ar-test-grid{grid-template-columns:1fr}
    .ar-preview-stats{grid-template-columns:repeat(2,1fr)}
    .ar-steps{display:none}
    .ar-modal.large{width:100%}
}
</style>

<!-- ─── PAGE CONTENT ────────────────────────────────────────────────── -->
<div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <h1><i class="fas fa-chart-column" style="color:var(--accent)"></i> <?php echo $page_title; ?></h1>
        <p class="page-subtitle"><?php echo $page_sub; ?></p>
    </div>
    <?php if (can_access('cards')): ?>
        <div>
            <a href="cards.php?tab=test_results" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none; background:var(--accent); color:#fff; padding:10px 18px; border-radius:8px; font-weight:600; font-size:0.875rem; box-shadow:0 4px 12px rgba(139,92,246,0.25); transition:all 0.2s ease;">
                <i class="fas fa-id-card"></i>
                <span>Generate Result Cards</span>
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Upload Wizard -->
<div class="ar-wizard" id="ar-wizard">
    <h3><i class="fas fa-cloud-arrow-up"></i> Upload Mega Test Results</h3>
    <div class="ar-steps" id="ar-steps">
        <div class="ar-step active" data-step="1"><span class="num">1</span> Academic Year</div>
        <span class="ar-step-arrow"><i class="fas fa-chevron-right"></i></span>
        <div class="ar-step" data-step="2"><span class="num">2</span> Study Plan</div>
        <span class="ar-step-arrow"><i class="fas fa-chevron-right"></i></span>
        <div class="ar-step" data-step="3"><span class="num">3</span> Test</div>
        <span class="ar-step-arrow"><i class="fas fa-chevron-right"></i></span>
        <div class="ar-step" data-step="4"><span class="num">4</span> Upload</div>
    </div>

    <div class="ar-selectors">
        <div class="field">
            <label>Academic Year</label>
            <select id="ar-year" onchange="arSelectYear(this.value)">
                <option value="">— Select Year —</option>
                <?php foreach ($academic_years as $y): ?>
                    <option value="<?php echo ar_esc($y); ?>"><?php echo ar_esc($y); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Study Plan</label>
            <select id="ar-plan" onchange="arSelectPlan(this.value)" disabled>
                <option value="">— Select Plan —</option>
            </select>
            <div id="ar-plan-info" style="display:none; margin-top: 10px; font-size: 0.82rem; color: var(--accent); font-weight: 600;"></div>
        </div>
    </div>

    <!-- Test Selection Grid -->
    <div id="ar-tests-container" style="display:none">
        <label style="font-size:.82rem;font-weight:700;margin-bottom:8px;display:block">Select Mega Test Activity</label>
        <div class="ar-test-grid" id="ar-tests-grid"></div>
    </div>

    <!-- Upload Zone -->
    <div id="ar-upload-container" style="display:none">
        <div id="ar-upload-warning" style="display:none; background:rgba(239,68,68,0.05); border:1px solid rgba(239,68,68,0.2); border-radius:8px; padding:20px; text-align:center; margin-bottom:16px;">
            <i class="fas fa-circle-exclamation" style="font-size:2rem; color:#dc2626; margin-bottom:8px; display:block;"></i>
            <h4 style="font-weight:700; color:#b91c1c; margin-bottom:6px; font-family:'Space Grotesk',sans-serif;">Results Already Uploaded</h4>
            <p style="font-size:0.85rem; color:#7f1d1d; margin-bottom:12px;">This test already has published results. You cannot upload a new result file until the previous data is deleted.</p>
            <div id="ar-warning-delete-btn-container"></div>
        </div>
        <div id="ar-upload-fields">
            <div class="ar-xlsx-notice">
                <i class="fas fa-info-circle"></i>
                <span><strong>.XLSX support:</strong> If your file is .xlsx, it will be automatically converted to CSV in your browser before uploading. Both .csv and .xlsx are supported.</span>
            </div>
            <div class="ar-upload-zone" id="ar-drop-zone" onclick="document.getElementById('ar-file-input').click()" ondragover="event.preventDefault();this.classList.add('drag-over')" ondragleave="this.classList.remove('drag-over')" ondrop="arHandleDrop(event)">
                <i class="fas fa-cloud-arrow-up"></i>
                <p><strong>Click to upload</strong> or drag & drop your result file here</p>
                <p class="file-types">Supported: .csv, .xlsx</p>
                <input type="file" id="ar-file-input" accept=".csv,.xlsx" style="display:none" onchange="arHandleFile(this.files[0])">
            </div>
        </div>
        <div id="ar-upload-progress" style="display:none;margin-top:12px">
            <div class="ar-loading"><div class="spinner"></div> <span id="ar-upload-status">Processing file...</span></div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="ar-modal-backdrop" id="ar-preview-modal">
    <div class="ar-modal large">
        <div class="ar-modal-head">
            <h3><i class="fas fa-magnifying-glass-chart" style="color:var(--accent)"></i> Result Preview</h3>
            <button class="ar-modal-close" onclick="arCloseModal('ar-preview-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="ar-modal-body" id="ar-preview-body">
            <!-- Dynamic content -->
        </div>
    </div>
</div>

<!-- Detail View Modal -->
<div class="ar-modal-backdrop" id="ar-detail-modal">
    <div class="ar-modal large">
        <div class="ar-modal-head">
            <h3><i class="fas fa-chart-bar" style="color:var(--accent)"></i> <span id="ar-detail-title">Mega Test Result Details</span></h3>
            <button class="ar-modal-close" onclick="arCloseModal('ar-detail-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="ar-modal-body" id="ar-detail-body">
            <!-- Dynamic content -->
        </div>
    </div>
</div>

<!-- Replace Confirmation Modal -->
<div class="ar-modal-backdrop" id="ar-replace-modal">
    <div class="ar-modal medium">
        <div class="ar-modal-head">
            <h3><i class="fas fa-triangle-exclamation" style="color:#f59e0b"></i> Replace Existing Results</h3>
            <button class="ar-modal-close" onclick="arCloseModal('ar-replace-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="ar-modal-body">
            <p style="margin-bottom:12px;color:var(--foreground);font-size:.88rem">
                <strong>This test already has published results.</strong> Publishing new results will replace the existing version. The previous version will be preserved in the history.
            </p>
            <label style="font-size:.82rem;font-weight:600">Replacement Reason <span style="color:#ef4444">*</span></label>
            <textarea id="ar-replace-reason" placeholder="Explain why the previous results are being replaced (e.g., corrected answer key, additional students)"></textarea>
            <div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end">
                <button class="ar-btn secondary" onclick="arCloseModal('ar-replace-modal')">Cancel</button>
                <button class="ar-btn danger" id="ar-confirm-replace-btn" onclick="arPublishResults(true)"><i class="fas fa-rotate"></i> Replace & Publish</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="ar-toast" id="ar-toast"></div>

<!-- Result Management Section -->
<div class="ar-mgmt-section">
    <div class="ar-mgmt-header">
        <h3 style="font-family:'Space Grotesk',sans-serif;font-weight:700;display:flex;align-items:center;gap:8px">
            <i class="fas fa-table-list" style="color:var(--accent)"></i> Published Mega Test Results
        </h3>
        <div class="ar-filters">
            <select id="ar-mgmt-year" onchange="arLoadBatches()">
                <option value="">All Years</option>
                <?php foreach ($academic_years as $y): ?>
                    <option value="<?php echo ar_esc($y); ?>"><?php echo ar_esc($y); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="ar-mgmt-course" onchange="arLoadBatches()">
                <option value="">All Courses</option>
            </select>
        </div>
    </div>
    <div id="ar-mgmt-table-wrap">
        <div class="ar-empty"><i class="fas fa-inbox"></i><p>Select filters or upload results to see published mega tests here.</p></div>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>

<!-- SheetJS for XLSX support (same CDN already used by student-study-reports.php) -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
const CSRF = '<?php echo csrf_token(); ?>';
const isSuperAdmin = <?php echo is_super_admin() ? 'true' : 'false'; ?>;
let arSelectedYear = '', arSelectedCourseId = 0, arSelectedCourseName = '', arSelectedPlanId = 0, arSelectedActivityId = 0;
let arPreviewData = null;

// ── Toast ──
function arToast(msg, type) {
    const t = document.getElementById('ar-toast');
    t.textContent = msg; t.className = 'ar-toast ' + type; t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 4000);
}

// ── Modal ──
function arOpenModal(id) { document.getElementById(id).classList.add('show'); }
function arCloseModal(id) { document.getElementById(id).classList.remove('show'); }

// ── Step Tracker ──
function arUpdateSteps(step) {
    document.querySelectorAll('.ar-step').forEach(s => {
        const sn = parseInt(s.dataset.step);
        s.classList.remove('active','done');
        if (sn < step) s.classList.add('done');
        else if (sn === step) s.classList.add('active');
    });
}

// ── Step 1: Academic Year ──
function arSelectYear(year) {
    arSelectedYear = year; arSelectedPlanId = 0; arSelectedActivityId = 0;
    const ps = document.getElementById('ar-plan');
    ps.innerHTML = '<option value="">— Select Plan —</option>'; ps.disabled = true;
    document.getElementById('ar-tests-container').style.display = 'none';
    document.getElementById('ar-upload-container').style.display = 'none';
    const infoDiv = document.getElementById('ar-plan-info');
    if (infoDiv) infoDiv.style.display = 'none';
    arUpdateSteps(year ? 2 : 1);
    if (!year) return;
    fetch('assessment-results.php?action=get_study_plans&year=' + encodeURIComponent(year))
        .then(r => r.json()).then(plans => {
            ps.innerHTML = '<option value="">— Select Plan —</option>';
            plans.forEach(p => {
                const badge = p.status === 'published' ? ' [Published]' : ' [Draft]';
                ps.innerHTML += '<option value="'+p.id+'">'+escH(p.title)+badge+'</option>';
            });
            ps.disabled = false;
        });
    // Also load management courses
    fetch('assessment-results.php?action=get_courses&year=' + encodeURIComponent(year))
        .then(r => r.json()).then(courses => {
            const mc = document.getElementById('ar-mgmt-course');
            mc.innerHTML = '<option value="">All Courses</option>';
            courses.forEach(c => { mc.innerHTML += '<option value="'+c.id+'">'+escH(c.course_name)+'</option>'; });
        });
}

// ── Step 2: Study Plan ──
function arSelectPlan(planId) {
    arSelectedPlanId = parseInt(planId) || 0; arSelectedActivityId = 0;
    document.getElementById('ar-upload-container').style.display = 'none';
    const tc = document.getElementById('ar-tests-container');
    const tg = document.getElementById('ar-tests-grid');
    const infoDiv = document.getElementById('ar-plan-info');
    if (!arSelectedPlanId) {
        tc.style.display = 'none';
        if (infoDiv) infoDiv.style.display = 'none';
        arUpdateSteps(2);
        return;
    }
    arUpdateSteps(3);
    tc.style.display = 'block';
    tg.innerHTML = '<div class="ar-loading"><div class="spinner"></div> Loading tests...</div>';

    if (infoDiv) {
        infoDiv.style.display = 'block';
        infoDiv.innerHTML = '<div class="ar-loading"><div class="spinner"></div> Loading assigned courses info...</div>';
        fetch('assessment-results.php?action=get_plan_info&plan_id=' + arSelectedPlanId + '&year=' + encodeURIComponent(arSelectedYear))
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    infoDiv.innerHTML = `<i class="fas fa-info-circle"></i> ${data.courses_count} Course(s) • ${data.students_count} Student(s) assigned to this Study Plan`;
                } else {
                    infoDiv.style.display = 'none';
                }
            });
    }

    fetch('assessment-results.php?action=get_tests&plan_id='+arSelectedPlanId+'&year='+encodeURIComponent(arSelectedYear))
        .then(r => r.json()).then(tests => {
            if (!tests.length) { tg.innerHTML = '<div class="ar-empty"><i class="fas fa-flask-vial"></i><p>No test/assessment activities found in this Study Plan.</p></div>'; return; }
            tg.innerHTML = '';
            tests.forEach(t => {
                const d = document.createElement('div');
                d.className = 'ar-test-card'; d.dataset.activityId = t.id;
                if (t.has_published_result) {
                    d.classList.add('has-results');
                }
                d.onclick = () => arSelectTest(t);
                let meta = '<span><i class="fas fa-tag"></i> '+escH(t.activity_type)+'</span>';
                if (t.chapter) meta += '<span><i class="fas fa-book"></i> Chapter: '+escH(t.chapter)+'</span>';
                if (t.topic) meta += '<span><i class="fas fa-bookmark"></i> Topic: '+escH(t.topic)+'</span>';
                if (t.activity_date) meta += '<span><i class="fas fa-calendar"></i> Activity Date: '+formatDate(t.activity_date)+'</span>';
                let badge = t.has_published_result
                    ? '<span class="ar-test-badge published"><i class="fas fa-check-circle"></i> Result Published (v'+t.published_version+')</span>'
                    : '<span class="ar-test-badge not-uploaded"><i class="fas fa-circle-minus"></i> Not Uploaded</span>';
                d.innerHTML = '<div class="test-title">'+escH(t.activity_title)+'</div><div class="test-meta">'+meta+'</div><div style="margin-top:8px">'+badge+'</div>';
                tg.appendChild(d);
            });
        });
}

// ── Step 3: Test ──
function arSelectTest(test) {
    arSelectedActivityId = test.id;
    document.querySelectorAll('.ar-test-card').forEach(c => c.classList.remove('selected'));
    document.querySelector('.ar-test-card[data-activity-id="'+test.id+'"]').classList.add('selected');
    document.getElementById('ar-upload-container').style.display = 'block';

    const warning = document.getElementById('ar-upload-warning');
    const fields = document.getElementById('ar-upload-fields');
    if (test.has_published_result) {
        warning.style.display = 'block';
        fields.style.display = 'none';
        const warningDeleteBtnContainer = document.getElementById('ar-warning-delete-btn-container');
        if (isSuperAdmin && test.published_batch_id) {
            warningDeleteBtnContainer.innerHTML = '<button class="ar-btn danger" onclick="arDeleteBatchDirect(' + test.published_batch_id + ')"><i class="fas fa-trash"></i> Delete Existing Results & Re-upload</button>';
        } else {
            warningDeleteBtnContainer.innerHTML = '';
        }
    } else {
        warning.style.display = 'none';
        fields.style.display = 'block';
    }

    arUpdateSteps(4);
}

function arDeleteBatchDirect(batchId) {
    if (!confirm('Are you sure you want to delete the existing assessment results for this test? This action cannot be undone.')) {
        return;
    }
    const fd = new FormData();
    fd.append('batch_id', batchId);
    fd.append('csrf_token', CSRF);
    fd.append('action', 'delete_batch');

    fetch('assessment-results.php?action=delete_batch', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r => r.json()).then(data => {
            if (data.success) {
                arToast(data.message, 'success');
                // Reload the tests grid to refresh statuses and unlock uploads
                arSelectPlan(arSelectedPlanId);
            } else {
                arToast(data.message, 'error');
            }
        }).catch(err => {
            arToast('Failed to delete results: ' + err.message, 'error');
        });
}

// ── Step 5: File Upload ──
function arHandleDrop(e) {
    e.preventDefault(); e.currentTarget.classList.remove('drag-over');
    if (e.dataTransfer.files.length) arHandleFile(e.dataTransfer.files[0]);
}

function arHandleFile(file) {
    if (!file) return;
    if (!arSelectedActivityId) { arToast('Please select a test first.','error'); return; }
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['csv','xlsx'].includes(ext)) { arToast('Only .csv and .xlsx files are supported.','error'); return; }

    document.getElementById('ar-upload-progress').style.display = 'block';
    document.getElementById('ar-upload-status').textContent = 'Processing file...';

    if (ext === 'xlsx') {
        // Convert XLSX to CSV client-side using SheetJS
        document.getElementById('ar-upload-status').textContent = 'Converting XLSX to CSV...';
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const wb = XLSX.read(data, {type:'array', raw:true});
                const ws = wb.Sheets[wb.SheetNames[0]];
                const csvContent = XLSX.utils.sheet_to_csv(ws);
                const blob = new Blob([csvContent], {type:'text/csv'});
                const csvFile = new File([blob], file.name.replace(/\.xlsx$/i, '.csv'), {type:'text/csv'});
                arUploadCSV(csvFile);
            } catch(err) {
                document.getElementById('ar-upload-progress').style.display = 'none';
                arToast('Failed to read XLSX file: ' + err.message, 'error');
            }
        };
        reader.readAsArrayBuffer(file);
    } else {
        arUploadCSV(file);
    }
}

function arUploadCSV(file) {
    document.getElementById('ar-upload-status').textContent = 'Uploading and validating...';
    const fd = new FormData();
    fd.append('result_file', file);
    fd.append('activity_id', arSelectedActivityId);
    fd.append('plan_id', arSelectedPlanId);
    fd.append('course_id', 0);
    fd.append('academic_year', arSelectedYear);
    fd.append('csrf_token', CSRF);
    fd.append('action', 'upload_validate');

    fetch('assessment-results.php?action=upload_validate', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r => r.json()).then(data => {
            document.getElementById('ar-upload-progress').style.display = 'none';
            if (!data.success) { arToast(data.message, 'error'); return; }
            arPreviewData = data;
            arShowPreview(data);
        }).catch(err => {
            document.getElementById('ar-upload-progress').style.display = 'none';
            arToast('Upload failed: ' + err.message, 'error');
        });
}

// ── Preview Rendering ──
function arShowPreview(data) {
    const s = data.stats; const eb = data.existing_batch;
    let h = '<div style="margin-bottom:16px"><strong>'+escH(data.activity_title)+'</strong>';
    h += ' <span style="color:var(--muted-foreground);font-size:.82rem">['+escH(data.activity_type)+']</span>';
    if (data.chapter) h += ' <span style="color:var(--muted-foreground);font-size:.82rem">| Chapter: '+escH(data.chapter)+'</span>';
    if (data.activity_date) h += ' <span style="color:var(--muted-foreground);font-size:.82rem">| '+formatDate(data.activity_date)+'</span>';
    h += '</div>';
    if (eb) {
        h += '<div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.84rem"><i class="fas fa-triangle-exclamation" style="color:#f59e0b"></i> <strong>This test already has published results (Version '+eb.version+').</strong> Publishing will replace the existing version.</div>';
    }
    h += '<div class="ar-preview-stats">';
    h += arStatCard(s.total_rows, 'CSV Rows', 'blue');
    h += arStatCard(s.matched + s.not_attended, 'Course Students', 'purple');
    h += arStatCard(s.attended, 'Attended', 'green');
    h += arStatCard(s.in_progress, 'In Progress', 'orange');
    h += arStatCard(s.not_attended, 'Not Attended', 'red');
    if (s.review_required > 0) h += arStatCard(s.review_required, 'Review Required', 'purple');
    h += arStatCard(s.unmatched, 'Excluded Emails', s.unmatched > 0 ? 'orange' : 'blue');
    h += arStatCard(s.ranked_students, 'Ranked Students', 'purple');
    if (s.highest !== null) h += arStatCard(s.highest, 'Highest Score', 'green');
    if (s.lowest !== null) h += arStatCard(s.lowest, 'Lowest Score', 'orange');
    if (s.average !== null) h += arStatCard(s.average, 'Average Score', 'blue');
    h += '</div>';
    if (s.invalid_emails && s.invalid_emails.length > 0) {
        h += '<div style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.82rem"><strong><i class="fas fa-circle-exclamation" style="color:#ef4444"></i> Invalid emails skipped:</strong><ul style="margin:6px 0 0 16px">';
        s.invalid_emails.forEach(ie => { h += '<li>Row '+ie.row+': "'+escH(ie.value)+'"</li>'; });
        h += '</ul></div>';
    }
    if (data.excluded_emails && data.excluded_emails.length > 0) {
        h += '<details style="background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.82rem">';
        h += '<summary style="cursor:pointer;font-weight:700;"><i class="fas fa-triangle-exclamation" style="color:#f59e0b"></i> Excluded Emails — Other Course / Not Enrolled ('+data.excluded_emails.length+')</summary>';
        h += '<ul style="margin:8px 0 0 16px;max-height:150px;overflow-y:auto;padding-left:12px;">';
        data.excluded_emails.forEach(x => {
            h += '<li>' + escH(x.email) + (x.name ? ' ('+escH(x.name)+')' : '') + '</li>';
        });
        h += '</ul></details>';
    }
    // Preview table
    h += '<div class="ar-table-wrap" style="max-height:400px;overflow-y:auto"><table class="ar-table"><thead><tr>';
    h += '<th>Rank</th><th>Student</th><th>Email</th><th>Score</th><th>Total</th><th>Accuracy</th><th>Correct</th><th>Wrong</th><th>Skipped</th><th>Time</th><th>Status</th><th>Matched</th>';
    h += '</tr></thead><tbody>';
    data.preview.forEach(r => {
        const rnk = r.rank !== null ? '<span class="ar-rank'+(r.rank<=3?' top3':'')+'">'+r.rank+'</span>' : '<span style="color:var(--muted-foreground)">—</span>';
        const sc = r.score !== null ? r.score : '—';
        const ts = r.total_score !== null ? r.total_score : '—';
        const st = arStatusBadge(r.attendance_status);
        const matched = r.matched ? '<i class="fas fa-check-circle" style="color:#16a34a"></i>' : '<i class="fas fa-xmark" style="color:#ef4444"></i>';
        h += '<tr><td>'+rnk+'</td><td>'+escH(r.student_name||'')+'</td><td style="font-size:.78rem">'+escH(r.student_email)+'</td>';
        h += '<td>'+sc+'</td><td>'+ts+'</td><td>'+escH(r.src_accuracy||'—')+'</td>';
        h += '<td>'+(r.correct!==null?r.correct:'—')+'</td><td>'+(r.wrong!==null?r.wrong:'—')+'</td><td>'+(r.skipped!==null?r.skipped:'—')+'</td>';
        h += '<td>'+escH(r.src_time_spent||'—')+'</td><td>'+st+'</td><td>'+matched+'</td></tr>';
    });
    h += '</tbody></table></div>';
    h += '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">';
    h += '<button class="ar-btn secondary" onclick="arCloseModal(\'ar-preview-modal\')"><i class="fas fa-xmark"></i> Cancel</button>';
    if (eb) {
        h += '<button class="ar-btn danger" onclick="arCloseModal(\'ar-preview-modal\');arOpenModal(\'ar-replace-modal\')"><i class="fas fa-rotate"></i> Replace & Publish</button>';
    } else {
        h += '<button class="ar-btn primary" onclick="arPublishResults(false)"><i class="fas fa-paper-plane"></i> Publish Results</button>';
    }
    h += '</div>';
    document.getElementById('ar-preview-body').innerHTML = h;
    arOpenModal('ar-preview-modal');
}

function arStatCard(val, label, color) {
    return '<div class="ar-stat-card '+color+'"><div class="val">'+(val!==null?val:'—')+'</div><div class="lbl">'+label+'</div></div>';
}

function arStatusBadge(status) {
    const map = {
        'attended':'<span class="ar-status attended"><i class="fas fa-check"></i> Attended</span>',
        'not_attended':'<span class="ar-status not-attended"><i class="fas fa-minus-circle"></i> Not Attended</span>',
        'in_progress':'<span class="ar-status in-progress"><i class="fas fa-clock"></i> In Progress</span>',
        'review_required':'<span class="ar-status review-required"><i class="fas fa-flag"></i> Review Required</span>'
    };
    return map[status] || status;
}

// ── Publish ──
function arPublishResults(isReplace) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'publish_results');
    if (isReplace) {
        const reason = document.getElementById('ar-replace-reason').value.trim();
        if (!reason) { arToast('Replacement reason is required.', 'error'); return; }
        fd.append('replace_reason', reason);
    }
    const btn = isReplace ? document.getElementById('ar-confirm-replace-btn') : document.querySelector('#ar-preview-body .ar-btn.primary');
    if (btn) { btn.disabled = true; btn.innerHTML = '<div class="spinner" style="width:14px;height:14px;border-width:2px"></div> Publishing...'; }

    fetch('assessment-results.php?action=publish_results', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r => r.json()).then(data => {
            arCloseModal('ar-preview-modal'); arCloseModal('ar-replace-modal');
            if (data.success) {
                arToast(data.message, 'success');
                // Reset file input
                document.getElementById('ar-file-input').value = '';
                // Reload tests
                if (arSelectedPlanId) arSelectPlan(arSelectedPlanId);
                arLoadBatches();
            } else {
                arToast(data.message, 'error');
            }
            if (btn) { btn.disabled = false; }
        }).catch(err => {
            arToast('Publish failed: '+err.message, 'error');
            if (btn) { btn.disabled = false; }
        });
}

// ── Load Management Table ──
function arLoadBatches() {
    const year = document.getElementById('ar-mgmt-year').value;
    const cid = document.getElementById('ar-mgmt-course').value;
    const wrap = document.getElementById('ar-mgmt-table-wrap');
    wrap.innerHTML = '<div class="ar-loading" style="padding:20px"><div class="spinner"></div> Loading...</div>';

    fetch('assessment-results.php?action=get_all_batches&year='+encodeURIComponent(year)+'&course_id='+(cid||0))
        .then(r => r.json()).then(data => {
            if (!data.batches || !data.batches.length) {
                wrap.innerHTML = '<div class="ar-empty"><i class="fas fa-inbox"></i><p>No assessment results found for the selected filters.</p></div>';
                return;
            }
            let h = '<div class="ar-table-wrap"><table class="ar-table"><thead><tr>';
            h += '<th>Test</th><th>Type</th><th>Chapter</th><th>Activity Date</th><th>Course</th><th>Year</th>';
            h += '<th>Status</th><th>Version</th><th>Attended</th><th>Not Att.</th><th>Avg Score</th><th>Published</th><th>By</th><th>Actions</th>';
            h += '</tr></thead><tbody>';
            data.batches.forEach(b => {
                const statusCls = b.status === 'published' ? 'attended' : (b.status === 'replaced' ? 'in-progress' : 'not-attended');
                const avgScore = (b.attended_count > 0) ? '—' : '—'; // Will be computed on detail view
                h += '<tr>';
                h += '<td><strong>'+escH(b.activity_title_snapshot)+'</strong></td>';
                h += '<td>'+escH(b.activity_type_snapshot)+'</td>';
                h += '<td>'+(b.chapter_snapshot?escH(b.chapter_snapshot):'—')+'</td>';
                h += '<td>'+(b.activity_date_snapshot?formatDate(b.activity_date_snapshot):'—')+'</td>';
                h += '<td>'+escH(b.course_name)+'</td>';
                h += '<td>'+escH(b.academic_year)+'</td>';
                h += '<td><span class="ar-status '+statusCls+'">'+ucFirst(b.status)+'</span></td>';
                h += '<td>v'+b.version+'</td>';
                h += '<td>'+b.attended_count+'</td>';
                h += '<td>'+b.not_attended_count+'</td>';
                h += '<td>—</td>';
                h += '<td>'+(b.published_at?formatDateTime(b.published_at):'—')+'</td>';
                h += '<td>'+escH(b.published_by||'—')+'</td>';
                h += '<td><div class="ar-actions">';
                h += '<button class="ar-btn secondary" style="padding:4px 10px;font-size:.75rem" onclick="arViewBatch('+b.id+')"><i class="fas fa-eye"></i> View</button>';
                h += '<a class="ar-btn secondary" style="padding:4px 10px;font-size:.75rem;text-decoration:none" href="assessment-results.php?action=export_results&batch_id='+b.id+'"><i class="fas fa-download"></i> Export</a>';
                if (isSuperAdmin) {
                    h += '<button class="ar-btn danger" style="padding:4px 10px;font-size:.75rem" onclick="arDeleteBatch('+b.id+')"><i class="fas fa-trash"></i> Delete</button>';
                }
                h += '</div></td></tr>';
            });
            h += '</tbody></table></div>';
            wrap.innerHTML = h;
        });
}

function arDeleteBatch(batchId) {
    if (!confirm('Are you absolutely sure you want to delete this assessment result batch? This will permanently delete all student scores and rankings associated with this batch. This action cannot be undone.')) {
        return;
    }
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'delete_batch');
    fd.append('batch_id', batchId);

    fetch('assessment-results.php?action=delete_batch', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            arToast(data.message, 'success');
            arLoadBatches();
        } else {
            arToast(data.message, 'error');
        }
    })
    .catch(err => {
        arToast('Delete failed: ' + err.message, 'error');
    });
}

// ── View Batch Detail ──
function arViewBatch(batchId) {
    const body = document.getElementById('ar-detail-body');
    body.innerHTML = '<div class="ar-loading" style="padding:20px"><div class="spinner"></div> Loading results...</div>';
    arOpenModal('ar-detail-modal');

    fetch('assessment-results.php?action=get_batch_details&batch_id='+batchId)
        .then(r => r.json()).then(data => {
            if (!data.success) { body.innerHTML = '<p style="color:#ef4444">'+escH(data.message)+'</p>'; return; }
            const b = data.batch; const st = data.stats;
            document.getElementById('ar-detail-title').textContent = b.activity_title_snapshot + ' — Results (v' + b.version + ')';

            let h = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:16px;font-size:.82rem">';
            h += '<div><strong>Test Type:</strong> '+escH(b.activity_type_snapshot)+'</div>';
            if (b.chapter_snapshot) h += '<div><strong>Chapter:</strong> '+escH(b.chapter_snapshot)+'</div>';
            if (b.activity_date_snapshot) h += '<div><strong>Activity Date:</strong> '+formatDate(b.activity_date_snapshot)+'</div>';
            h += '<div><strong>Course:</strong> '+escH(b.course_name)+'</div>';
            h += '<div><strong>Academic Year:</strong> '+escH(b.academic_year)+'</div>';
            h += '<div><strong>Version:</strong> '+b.version+'</div>';
            h += '<div><strong>Published:</strong> '+(b.published_at?formatDateTime(b.published_at):'—')+'</div>';
            h += '<div><strong>Published By:</strong> '+escH(b.published_by||'—')+'</div>';
            h += '</div>';

            h += '<div class="ar-preview-stats">';
            h += arStatCard(st.attended, 'Attended', 'green');
            h += arStatCard(st.not_attended, 'Not Attended', 'red');
            h += arStatCard(st.in_progress, 'In Progress', 'orange');
            if (st.review_required > 0) h += arStatCard(st.review_required, 'Review Required', 'purple');
            h += arStatCard(st.highest, 'Highest', 'green');
            h += arStatCard(st.lowest, 'Lowest', 'orange');
            h += arStatCard(st.average, 'Average', 'blue');
            if (st.avg_accuracy !== null) h += arStatCard(st.avg_accuracy+'%', 'Avg Accuracy', 'purple');
            if (st.avg_time_seconds !== null) h += arStatCard(formatSeconds(st.avg_time_seconds), 'Avg Time', 'blue');
            h += '</div>';

            h += '<div class="ar-table-wrap" style="max-height:500px;overflow-y:auto"><table class="ar-table"><thead><tr>';
            h += '<th>Rank</th><th>Student</th><th>Email</th><th>Score</th><th>Total</th><th>%</th><th>Accuracy</th><th>Answered</th><th>Correct</th><th>Wrong</th><th>Skipped</th><th>Time</th><th>Avg Q/hr</th><th>Status</th>';
            h += '</tr></thead><tbody>';
            data.results.forEach(r => {
                const rnk = r.rank!==null ? '<span class="ar-rank'+(r.rank<=3?' top3':'')+'">'+r.rank+'</span>' : '<span style="color:var(--muted-foreground)">—</span>';
                const pct = r.percentage!==null ? r.percentage+'%' : '—';
                h += '<tr><td>'+rnk+'</td><td>'+escH(r.display_name)+'</td><td style="font-size:.78rem">'+escH(r.student_email)+'</td>';
                h += '<td>'+(r.score!==null?r.score:'—')+'</td><td>'+(r.total_score!==null?r.total_score:'—')+'</td><td>'+pct+'</td>';
                h += '<td>'+escH(r.src_accuracy||'—')+'</td><td>'+escH(r.src_answered||'—')+'</td>';
                h += '<td>'+(r.correct!==null?r.correct:'—')+'</td><td>'+(r.wrong!==null?r.wrong:'—')+'</td><td>'+(r.skipped!==null?r.skipped:'—')+'</td>';
                h += '<td>'+escH(r.src_time_spent||'—')+'</td><td>'+escH(r.src_avg_q_per_hr||'—')+'</td>';
                h += '<td>'+arStatusBadge(r.attendance_status)+'</td></tr>';
            });
            h += '</tbody></table></div>';
            h += '<div style="margin-top:12px;text-align:right"><a class="ar-btn primary" href="assessment-results.php?action=export_results&batch_id='+batchId+'" style="text-decoration:none"><i class="fas fa-download"></i> Export CSV</a></div>';
            body.innerHTML = h;
        });
}

// ── Utility Functions ──
function escH(s) { if (!s) return ''; const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function ucFirst(s) { return s ? s.charAt(0).toUpperCase()+s.slice(1) : ''; }
function formatDate(d) { if (!d) return '—'; const dt=new Date(d); return dt.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}); }
function formatDateTime(d) { if (!d) return '—'; const dt=new Date(d); return dt.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'})+' '+dt.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'}); }
function formatSeconds(s) { if (!s) return '—'; const m=Math.floor(s/60); const sec=s%60; return m+'m '+sec+'s'; }

// ── Init ──
document.addEventListener('DOMContentLoaded', function() { arLoadBatches(); });
</script>
