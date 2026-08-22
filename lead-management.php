<?php
require_once 'includes/auth.php';
require_permission('leads');
require_once 'includes/template_helper.php';

/* Lead Management (CRM).
   Capture and work prospective students before they register. Super Admin and
   any admin granted the 'leads' page can add individual leads or bulk-import
   from Excel/CSV, then track each lead through a standard pipeline with
   remarks, follow-up dates and a full activity timeline.

   Pipeline:  new → contacted → follow_up → interested → converted
                                                       ↘ not_interested / rejected
   next_followup_date is required until a lead is converted/rejected. */

$LEAD_STATUSES = [
    'new'            => ['New',            'blue'],
    'contacted'      => ['Contacted',      'violet'],
    'follow_up'      => ['Follow-up',      'amber'],
    'interested'     => ['Interested',     'teal'],
    'not_interested' => ['Not Interested', 'gray'],
    'converted'      => ['Converted',      'green'],
    'rejected'       => ['Rejected',       'red'],
];
$YEARS = ['First Year', 'Second Year', 'Third Year', 'Fourth Year', 'Completed'];
$CLOSED = ['converted', 'rejected', 'not_interested'];

$success_message = '';
$error_message   = '';

if (!function_exists('leads_table_exists')) {
    function leads_table_exists($pdo) {
        static $e = null;
        if ($e === null) { try { $e = (bool)$pdo->query("SHOW TABLES LIKE 'leads'")->fetchColumn(); } catch (Exception $ex) { $e = false; } }
        return $e;
    }
}
if (!leads_table_exists($pdo)) {
    $active_page = 'leads'; $page_title = 'Lead Management'; $page_sub = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>The Lead Management system is not installed yet. Run <strong>database-update-4.sql</strong> once in phpMyAdmin, then reload.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

/** Log a lead activity row. */
function lead_log($pdo, $lead_id, $type, $remark, $old, $new, $followup, $admin) {
    try {
        $stmt = $pdo->prepare("INSERT INTO lead_activity (lead_id, activity_type, remark, old_status, new_status, followup_date, performed_by, performed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$lead_id, $type, $remark, $old, $new, $followup ?: null, $admin]);
        $pdo->prepare("UPDATE leads SET last_activity_at = NOW() WHERE id = ?")->execute([$lead_id]);
    } catch (Exception $e) { error_log('lead_log: ' . $e->getMessage()); }
}
function clean_wa($n) {
    $n = preg_replace('/\D/', '', (string)$n);
    if (strlen($n) === 10) $n = '91' . $n;     // default India
    return $n;
}

/* ── POST actions ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_lead') {
                $wa = clean_wa($_POST['whatsapp_number'] ?? '');
                $status = in_array($_POST['status'] ?? '', array_keys($LEAD_STATUSES), true) ? $_POST['status'] : 'new';
                $followup = $_POST['next_followup_date'] ?? '';
                $course_name = trim($_POST['interested_course'] ?? '');
                
                if (strlen($wa) < 11) {
                    $error_message = 'A valid WhatsApp number is required.';
                } elseif (!in_array($status, $CLOSED, true) && $followup === '') {
                    $error_message = 'A next follow-up date is required until the lead is converted or rejected.';
                } else {
                    $pdo->beginTransaction();
                    $lockAcquired = acquireLeadLock($pdo, $wa, $course_name);
                    try {
                        // Check duplicate lead
                        $dupRes = checkLeadDuplicate($pdo, $wa, $course_name, null, true);
                        if ($dupRes['count'] > 0) {
                            $existingLead = $dupRes['matches'][0];
                            $error_message = "Lead already exists for this contact number for this course (Lead ID: #{$existingLead['id']}, Name: " . htmlspecialchars($existingLead['name'] ?? 'Unknown') . ", Status: " . htmlspecialchars($LEAD_STATUSES[$existingLead['status']][0] ?? $existingLead['status']) . ").";
                            $pdo->rollBack();
                        } else {
                            $assigned = is_super_admin() ? (trim($_POST['assigned_to'] ?? '') ?: '__ALL__') : '__ALL__';
                            $stmt = $pdo->prepare("
                                INSERT INTO leads (whatsapp_number, name, interested_course, last_institute, last_course,
                                    is_fyugp, year_of_study, status, next_followup_date, assigned_to, source, created_by, created_at, last_activity_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, NOW(), NOW())
                            ");
                            $stmt->execute([
                                normalizeLeadPhone($wa), trim($_POST['name'] ?? ''), $course_name,
                                trim($_POST['last_institute'] ?? ''), trim($_POST['last_course'] ?? ''),
                                in_array($_POST['is_fyugp'] ?? '', ['yes', 'no'], true) ? $_POST['is_fyugp'] : null,
                                in_array($_POST['year_of_study'] ?? '', $YEARS, true) ? $_POST['year_of_study'] : null,
                                $status, in_array($status, $CLOSED, true) ? ($followup ?: null) : $followup,
                                $assigned, $admin_username
                            ]);
                            $lead_id = (int)$pdo->lastInsertId();
                            lead_log($pdo, $lead_id, 'created', trim($_POST['remarks'] ?? '') ?: 'Lead created', null, $status, $followup, $admin_username);
                            log_admin_activity($pdo, $admin_username, 'lead_created', "Lead added: {$wa}" . (trim($_POST['name'] ?? '') ? ' (' . trim($_POST['name']) . ')' : ''));
                            $pdo->commit();
                            $success_message = 'Lead added.';
                        }
                    } catch (Exception $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $ex;
                    } finally {
                        releaseLeadLock($pdo, $wa, $course_name);
                    }
                }
            } elseif ($action === 'bulk_import') {
                if (!isset($_FILES['lead_file']) || $_FILES['lead_file']['error'] !== UPLOAD_ERR_OK) {
                    $error_message = 'Please choose a CSV or Excel file to import.';
                } else {
                    $rows = parse_lead_file($_FILES['lead_file']['tmp_name'], $_FILES['lead_file']['name']);
                    if ($rows === null) {
                        $error_message = 'Could not read the file. Please upload a .csv file (Excel: Save As → CSV).';
                    } else {
                        $added = 0; 
                        $skipped_invalid = 0;
                        $skipped_db_dup = 0;
                        $skipped_file_dup = 0;
                        $duplicate_logs = [];
                        $processed_in_sheet = [];
                        
                        $assigned_default = is_super_admin() ? (trim($_POST['bulk_assigned_to'] ?? '') ?: '__ALL__') : '__ALL__';
                        
                        $pdo->beginTransaction();
                        try {
                            foreach ($rows as $idx => $r) {
                                $rowNum = $idx + 2; // Row 1 is header
                                $wa = clean_wa($r['whatsapp_number'] ?? '');
                                $course_name = trim($r['interested_course'] ?? '');
                                
                                if (strlen($wa) < 11) { 
                                    $skipped_invalid++; 
                                    continue; 
                                }
                                
                                $normPhone = normalizeLeadPhone($wa);
                                $normCourse = normalizeLeadCourse($course_name);
                                $sheetKey = $normPhone . '||' . $normCourse;
                                
                                // Check duplicate in sheet
                                if (isset($processed_in_sheet[$sheetKey])) {
                                    $skipped_file_dup++;
                                    $duplicate_logs[] = "Row {$rowNum}: Phone '{$wa}', Course '{$course_name}' - Duplicate within import file.";
                                    continue;
                                }
                                
                                // Acquire named lock for database-level check
                                acquireLeadLock($pdo, $wa, $course_name);
                                try {
                                    $dupRes = checkLeadDuplicate($pdo, $wa, $course_name, null, true);
                                    if ($dupRes['count'] > 0) {
                                        $existingLead = $dupRes['matches'][0];
                                        $skipped_db_dup++;
                                        $duplicate_logs[] = "Row {$rowNum}: Phone '{$wa}', Course '{$course_name}' - Duplicate (existing Lead #{$existingLead['id']} found).";
                                        continue;
                                    }
                                    
                                    // Mark as processed in this session
                                    $processed_in_sheet[$sheetKey] = true;
                                    
                                    $yr = in_array($r['year_of_study'] ?? '', $YEARS, true) ? $r['year_of_study'] : null;
                                    $fy = in_array(strtolower($r['is_fyugp'] ?? ''), ['yes', 'no'], true) ? strtolower($r['is_fyugp']) : null;
                                    $fu = (!empty($r['next_followup_date']) && strtotime($r['next_followup_date'])) ? date('Y-m-d', strtotime($r['next_followup_date'])) : date('Y-m-d', strtotime('+2 days'));
                                    
                                    $stmt = $pdo->prepare("
                                        INSERT INTO leads (whatsapp_number, name, interested_course, last_institute, last_course,
                                            is_fyugp, year_of_study, status, next_followup_date, assigned_to, source, created_by, created_at, last_activity_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, 'import', ?, NOW(), NOW())
                                    ");
                                    $stmt->execute([
                                        $normPhone, trim($r['name'] ?? ''), $course_name,
                                        trim($r['last_institute'] ?? ''), trim($r['last_course'] ?? ''),
                                        $fy, $yr, $fu, $assigned_default, $admin_username
                                    ]);
                                    $lead_id = (int)$pdo->lastInsertId();
                                    lead_log($pdo, $lead_id, 'created', trim($r['remarks'] ?? '') ?: 'Imported from file', null, 'new', $fu, $admin_username);
                                    $added++;
                                } finally {
                                    releaseLeadLock($pdo, $wa, $course_name);
                                }
                            }
                            $pdo->commit();
                        } catch (Exception $ex) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw $ex;
                        }
                        
                        log_admin_activity($pdo, $admin_username, 'leads_imported', "Bulk import: {$added} added, {$skipped_invalid} invalid, {$skipped_db_dup} db duplicates, {$skipped_file_dup} file duplicates");
                        
                        $success_message = "<strong>Import Completed:</strong><br>";
                        $success_message .= "Successfully Imported: {$added}<br>";
                        $success_message .= "Duplicates in Database (Skipped): {$skipped_db_dup}<br>";
                        $success_message .= "Duplicates in Upload File (Skipped): {$skipped_file_dup}<br>";
                        $success_message .= "Invalid Suffix/Missing Phone (Skipped): {$skipped_invalid}<br>";
                        
                        if (!empty($duplicate_logs)) {
                            $success_message .= "<div style='margin-top:10px; max-height:150px; overflow-y:auto; font-size:0.75rem; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:8px; text-align:left;'>";
                            $success_message .= "<strong>Skipped rows details:</strong><br>";
                            foreach ($duplicate_logs as $log) {
                                $success_message .= htmlspecialchars($log) . "<br>";
                            }
                            $success_message .= "</div>";
                        }
                    }
                }
            } elseif ($action === 'mark_converted') {
                $lead_id = (int)($_POST['lead_id'] ?? 0);
                $student_user_id = trim($_POST['student_user_id'] ?? '');
                
                $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ? FOR UPDATE");
                $stmt->execute([$lead_id]);
                $lead = $stmt->fetch();
                
                if (!$lead) {
                    $error_message = 'Lead not found.';
                } elseif ($lead['status'] === 'converted') {
                    $error_message = 'This lead is already converted.';
                } else {
                    $pdo->beginTransaction();
                    $lockAcquired = acquireLeadLock($pdo, $lead['whatsapp_number'], $lead['interested_course']);
                    try {
                        $normPhone = normalizeLeadPhone($lead['whatsapp_number']);
                        $normCourse = normalizeLeadCourse($lead['interested_course']);
                        
                        // Query matching student/admission records
                        $stmtStud = $pdo->prepare("
                            SELECT * FROM users 
                            WHERE user_id = ?
                              AND status = 'approved'
                            FOR UPDATE
                        ");
                        $stmtStud->execute([$student_user_id]);
                        $student = $stmtStud->fetch();
                        
                        if ($student) {
                            $studPhone = normalizeLeadPhone($student['whatsapp_country_code'] . $student['whatsapp_number']);
                            $studCourse = normalizeLeadCourse($student['pepp_course']);
                            
                            if ($studPhone === $normPhone && $studCourse === $normCourse) {
                                $success = convertLeadFromApprovedAdmission($pdo, $lead['id'], $student['user_id'], $admin_username);
                                if ($success) {
                                    $pdo->commit();
                                    $success_message = "Lead #{$lead['id']} successfully marked as converted for Student #{$student['user_id']}.";
                                } else {
                                    $pdo->rollBack();
                                    $error_message = "Failed to convert lead.";
                                }
                            } else {
                                $pdo->rollBack();
                                if ($studPhone !== $normPhone) {
                                    $error_message = "Reconciliation error: Contact numbers do not match.";
                                } else {
                                    $error_message = "This contact has joined another PEPP course (" . htmlspecialchars($student['pepp_course']) . "), but not the course associated with this lead.";
                                }
                            }
                        } else {
                            $pdo->rollBack();
                            $error_message = "No approved admission found for Student ID {$student_user_id}.";
                        }
                    } catch (Exception $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $ex;
                    } finally {
                        releaseLeadLock($pdo, $lead['whatsapp_number'], $lead['interested_course']);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Lead action: ' . $e->getMessage());
            $error_message = 'Database error while saving the lead.';
        }
    }
}

/** Parse an uploaded CSV (Excel saved as CSV). Returns array of assoc rows or null.
    Recognised headers (case/space-insensitive): whatsapp/whatsapp_number/phone,
    name, interested_course/course, last_institute/institute, last_course,
    is_fyugp/fyugp, year_of_study/year, next_followup_date/followup, remarks. */
function parse_lead_file($tmp, $orig) {
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'], true)) {
        // .xlsx is a zip; without a spreadsheet library we ask for CSV.
        if (in_array($ext, ['xlsx', 'xls'], true)) return null;
    }
    if (($h = fopen($tmp, 'r')) === false) return null;
    $headers = fgetcsv($h);
    if (!$headers) { fclose($h); return null; }
    $norm = function ($s) { return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$s))); };
    $alias = [
        'whatsapp' => 'whatsapp_number', 'whatsappnumber' => 'whatsapp_number', 'phone' => 'whatsapp_number', 'mobile' => 'whatsapp_number', 'number' => 'whatsapp_number',
        'name' => 'name', 'fullname' => 'name',
        'interestedcourse' => 'interested_course', 'course' => 'interested_course', 'peppcourse' => 'interested_course',
        'lastinstitute' => 'last_institute', 'institute' => 'last_institute', 'college' => 'last_institute', 'school' => 'last_institute',
        'lastcourse' => 'last_course', 'studiedcourse' => 'last_course',
        'isfyugp' => 'is_fyugp', 'fyugp' => 'is_fyugp',
        'yearofstudy' => 'year_of_study', 'year' => 'year_of_study',
        'nextfollowupdate' => 'next_followup_date', 'followup' => 'next_followup_date', 'followupdate' => 'next_followup_date', 'nextfollowup' => 'next_followup_date',
        'remarks' => 'remarks', 'remark' => 'remarks', 'note' => 'remarks', 'notes' => 'remarks',
    ];
    $cols = [];
    foreach ($headers as $i => $hdr) {
        $key = $alias[$norm($hdr)] ?? null;
        if ($key) $cols[$i] = $key;
    }
    if (!in_array('whatsapp_number', $cols, true)) { fclose($h); return null; }
    $rows = [];
    while (($line = fgetcsv($h)) !== false) {
        if (count(array_filter($line, function ($v) { return trim((string)$v) !== ''; })) === 0) continue;
        $row = [];
        foreach ($cols as $i => $key) $row[$key] = $line[$i] ?? '';
        $rows[] = $row;
    }
    fclose($h);
    return $rows;
}

/* ── Filters ────────────────────────────────────────────────────── */
$f_status   = trim($_GET['status'] ?? '');
$f_assigned = trim($_GET['assigned'] ?? '');
$f_course   = trim($_GET['course'] ?? '');
$f_due      = trim($_GET['due'] ?? '');           // today | overdue | upcoming
$f_q        = trim($_GET['q'] ?? '');

$where = ['1=1']; $params = [];
// Non-super admins see leads assigned to them OR to all admins
if (!is_super_admin()) { $where[] = "(l.assigned_to = ? OR l.assigned_to = '__ALL__')"; $params[] = $admin_username; }
if (isset($LEAD_STATUSES[$f_status])) { $where[] = "l.status = ?"; $params[] = $f_status; }
if ($f_assigned !== '' && is_super_admin()) { $where[] = "l.assigned_to = ?"; $params[] = $f_assigned; }
if ($f_course !== '') { $where[] = "l.interested_course = ?"; $params[] = $f_course; }
if ($f_due === 'today')    { $where[] = "l.next_followup_date = CURDATE() AND l.status NOT IN ('converted','rejected','not_interested')"; }
if ($f_due === 'overdue')  { $where[] = "l.next_followup_date < CURDATE() AND l.status NOT IN ('converted','rejected','not_interested')"; }
if ($f_due === 'upcoming') { $where[] = "l.next_followup_date > CURDATE() AND l.status NOT IN ('converted','rejected','not_interested')"; }
if ($f_q !== '') {
    $where[] = "(l.whatsapp_number LIKE ? OR l.name LIKE ? OR l.interested_course LIKE ? OR l.last_institute LIKE ?)";
    $like = "%{$f_q}%"; array_push($params, $like, $like, $like, $like);
}
$where_sql = implode(' AND ', $where);

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$total = 0; $leads = [];
$stats = ['total' => 0, 'due_today' => 0, 'overdue' => 0, 'converted' => 0];
$today_leads = [];
$admin_list = []; $course_list = [];

try {
    // Stats (respect non-super scoping)
    $scope = is_super_admin() ? '1=1' : '(assigned_to = ' . $pdo->quote($admin_username) . " OR assigned_to = '__ALL__')";
    $stats['total']     = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE $scope")->fetchColumn();
    $stats['due_today'] = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE $scope AND next_followup_date = CURDATE() AND status NOT IN ('converted','rejected','not_interested')")->fetchColumn();
    $stats['overdue']   = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE $scope AND next_followup_date < CURDATE() AND status NOT IN ('converted','rejected','not_interested')")->fetchColumn();
    $stats['converted'] = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE $scope AND status = 'converted'")->fetchColumn();

    // Today + overdue list for the action panel
    $stmt = $pdo->prepare("SELECT * FROM leads WHERE $scope AND next_followup_date <= CURDATE() AND status NOT IN ('converted','rejected','not_interested') ORDER BY next_followup_date ASC, last_activity_at ASC LIMIT 50");
    $stmt->execute();
    $today_leads = $stmt->fetchAll();

    // Filtered list
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $where_sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $offset = ($page - 1) * $per_page;
    $stmt = $pdo->prepare("SELECT l.* FROM leads l WHERE $where_sql ORDER BY
        CASE WHEN l.next_followup_date <= CURDATE() AND l.status NOT IN ('converted','rejected','not_interested') THEN 0 ELSE 1 END,
        l.next_followup_date ASC, l.created_at DESC LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $leads = $stmt->fetchAll();

    // Preload matching approved/pending admission records in a single database query to avoid N+1 query loops
    $preloadedAdmissions = [];
    $phonesToSearch = [];
    foreach (array_merge($leads, $today_leads) as $l) {
        $cleaned = preg_replace('/\D/', '', $l['whatsapp_number']);
        if (strlen($cleaned) >= 10) {
            $phonesToSearch[] = $cleaned;
            $phonesToSearch[] = substr($cleaned, -10);
        }
    }
    if (!empty($phonesToSearch)) {
        $phonesToSearch = array_unique($phonesToSearch);
        $placeholders = implode(',', array_fill(0, count($phonesToSearch), '?'));
        
        $stmtPreload = $pdo->prepare("
            SELECT id, user_id, name, whatsapp_number, whatsapp_country_code, pepp_course, status, approval_date 
            FROM users 
            WHERE (whatsapp_number IN ({$placeholders}) OR CONCAT(whatsapp_country_code, whatsapp_number) IN ({$placeholders}))
        ");
        $stmtPreload->execute(array_merge($phonesToSearch, $phonesToSearch));
        $admissions = $stmtPreload->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($admissions as $adm) {
            $normPhone = normalizeLeadPhone($adm['whatsapp_country_code'] . $adm['whatsapp_number']);
            $preloadedAdmissions[$normPhone][] = $adm;
        }
    }

    if (is_super_admin()) {
        $admin_list = $pdo->query("SELECT DISTINCT assigned_to FROM leads WHERE assigned_to IS NOT NULL AND assigned_to <> '' ORDER BY assigned_to")->fetchAll(PDO::FETCH_COLUMN);
    }
    $course_list = $pdo->query("SELECT DISTINCT interested_course FROM leads WHERE interested_course IS NOT NULL AND interested_course <> '' ORDER BY interested_course")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('Lead list: ' . $e->getMessage());
    $error_message = $error_message ?: 'Could not load leads.';
}

// Admins that leads can be assigned to (super admin only)
$assignable = [];
if (is_super_admin() && admins_table_exists($pdo)) {
    try { $assignable = $pdo->query("SELECT username FROM admins WHERE status = 'active' ORDER BY role = 'super_admin' DESC, username")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Exception $e) {}
}
// PEPP courses for the dropdown
$pepp_courses = [];
try { $pepp_courses = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

$total_pages = max(1, (int)ceil($total / $per_page));
function lqs($overrides = []) {
    $q = array_merge($_GET, $overrides);
    unset($q['logout']);
    return '?' . http_build_query($q);
}
function wa_link($num, $text = '') {
    return 'https://wa.me/' . preg_replace('/\D/', '', $num) . ($text ? '?text=' . rawurlencode($text) : '');
}

$active_page = 'leads';
$page_title  = 'Lead Management';
$page_sub    = 'Track and convert prospective students';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<!-- ── STATS ── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Total Leads</span><span class="stat-icon violet"><i class="fas fa-user-tag"></i></span></div>
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-hint"><?php echo is_super_admin() ? 'All leads' : 'Assigned to you'; ?></div>
    </div>
    <a href="<?php echo e(lqs(['due' => 'today', 'status' => '', 'page' => 1])); ?>" class="stat-card" style="text-decoration:none;">
        <div class="stat-top"><span class="stat-label">Due Today</span><span class="stat-icon amber"><i class="fas fa-calendar-day"></i></span></div>
        <div class="stat-value"><?php echo number_format($stats['due_today']); ?></div>
        <div class="stat-hint">Follow-ups for <?php echo date('d M Y'); ?></div>
    </a>
    <a href="<?php echo e(lqs(['due' => 'overdue', 'status' => '', 'page' => 1])); ?>" class="stat-card" style="text-decoration:none;">
        <div class="stat-top"><span class="stat-label">Overdue</span><span class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></span></div>
        <div class="stat-value"><?php echo number_format($stats['overdue']); ?></div>
        <div class="stat-hint">Past follow-up date</div>
    </a>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Converted</span><span class="stat-icon green"><i class="fas fa-circle-check"></i></span></div>
        <div class="stat-value"><?php echo number_format($stats['converted']); ?></div>
        <div class="stat-hint">Became students</div>
    </div>
</div>

<!-- ── TODAY'S / OVERDUE FOLLOW-UPS ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-bell"></i></span>
        <h2>Follow-ups Needed <?php echo count($today_leads) ? '(' . count($today_leads) . ')' : ''; ?></h2>
        <div class="head-right">
            <a href="communication-campaigns.php?target=leads" class="btn btn-sm btn-success" style="border-radius:6px; font-weight:700;"><i class="fas fa-bullhorn"></i> Create WhatsApp Campaign</a>
            <button class="btn btn-sm btn-primary" onclick="openModal('add-lead-modal')"><i class="fas fa-plus"></i> Add Lead</button>
            <button class="btn btn-sm btn-outline" onclick="openModal('import-modal')"><i class="fas fa-file-import"></i> Bulk Import</button>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($today_leads)): ?>
            <div class="empty-state"><i class="fas fa-mug-hot"></i><p>No follow-ups due today or overdue. You're all caught up!</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Lead</th><th>Interested In</th><th>Status</th><th>Follow-up</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($today_leads as $l): 
                $overdue = $l['next_followup_date'] < date('Y-m-d');
                $normPhone = normalizeLeadPhone($l['whatsapp_number']);
                $matches = $preloadedAdmissions[$normPhone] ?? [];
                $joinedCount = 0;
                $appliedCount = 0;
                $sameCourseApprovedStudent = null;
                $eligibleForManualConversion = false;
                $normLeadCourse = normalizeLeadCourse($l['interested_course']);

                foreach ($matches as $m) {
                    if ($m['status'] === 'approved') {
                        $joinedCount++;
                        if (normalizeLeadCourse($m['pepp_course']) === $normLeadCourse) {
                            $sameCourseApprovedStudent = $m;
                        }
                    } elseif ($m['status'] === 'pending') {
                        $appliedCount++;
                    }
                }
                if ($sameCourseApprovedStudent && $l['status'] !== 'converted') {
                    $eligibleForManualConversion = true;
                }
                
                $matchedDetails = [];
                foreach ($matches as $m) {
                    $matchedDetails[] = [
                        'course' => $m['pepp_course'],
                        'student_name' => $m['name'],
                        'status' => ucfirst($m['status']),
                        'date' => $m['approval_date'] ? date('d M Y', strtotime($m['approval_date'])) : 'N/A',
                        'student_id' => $m['user_id'],
                        'is_same_course' => (normalizeLeadCourse($m['pepp_course']) === normalizeLeadCourse($l['interested_course'])),
                        'lead_status' => $l['status']
                    ];
                }
                $detailsJson = htmlspecialchars(json_encode($matchedDetails));
            ?>
                <tr<?php echo $overdue ? ' style="background:#fff7f7;"' : ''; ?>>
                    <td>
                        <div class="cell-main"><?php echo e($l['name'] ?: 'Unknown'); ?></div>
                        <div class="cell-sub">
                            <?php echo format_credential($l['whatsapp_number'], 'phone', 'leads'); ?> · <?php echo (int)$l['followup_count']; ?> follow-up(s)
                            <?php if (!empty($matches)): ?>
                                <div style="margin-top:4px;">
                                    <button type="button" class="joined-courses-btn" data-details="<?php echo $detailsJson; ?>" onclick="showJoinedCoursesModal(this)" style="padding:1px 6px; border-radius:4px; font-size:0.62rem; line-height:1.2; font-weight:700; cursor:pointer; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46;">
                                        <?php if ($joinedCount > 0): ?>
                                            🟢 Joined: <?php echo $joinedCount; ?>
                                        <?php endif; ?>
                                        <?php if ($appliedCount > 0): ?>
                                            <?php echo $joinedCount > 0 ? ' | ' : ''; ?>🟡 Applied: <?php echo $appliedCount; ?>
                                        <?php endif; ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="cell-sub"><?php echo e($l['interested_course'] ?: '-'); ?></td>
                    <td><span class="badge <?php echo $LEAD_STATUSES[$l['status']][1]; ?>"><?php echo $LEAD_STATUSES[$l['status']][0]; ?></span></td>
                    <td><span class="badge <?php echo $overdue ? 'red' : 'amber'; ?>"><?php echo date('d M', strtotime($l['next_followup_date'])); ?><?php echo $overdue ? ' · overdue' : ' · today'; ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <?php if ($eligibleForManualConversion): ?>
                            <button type="button" class="btn btn-sm btn-success" 
                                    data-lead-id="<?php echo $l['id']; ?>" 
                                    data-lead-name="<?php echo htmlspecialchars($l['name']); ?>" 
                                    data-lead-course="<?php echo htmlspecialchars($l['interested_course']); ?>" 
                                    data-lead-phone="<?php echo htmlspecialchars($l['whatsapp_number']); ?>" 
                                    data-student-id="<?php echo htmlspecialchars($sameCourseApprovedStudent['user_id']); ?>" 
                                    data-student-name="<?php echo htmlspecialchars($sameCourseApprovedStudent['name']); ?>" 
                                    data-student-course="<?php echo htmlspecialchars($sameCourseApprovedStudent['pepp_course']); ?>" 
                                    data-student-date="<?php echo $sameCourseApprovedStudent['approval_date'] ? date('d M Y', strtotime($sameCourseApprovedStudent['approval_date'])) : 'N/A'; ?>" 
                                    onclick="confirmManualConversion(this)" 
                                    title="Mark as Converted" 
                                    style="border-radius:6px; padding:2px 8px; font-size:0.7rem; font-weight:700; margin-right:4px;">
                                <i class="fas fa-check-circle"></i> Mark Converted
                            </button>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline" href="tel:<?php echo preg_replace('/\D/', '', $l['whatsapp_number']); ?>" title="Call"><i class="fas fa-phone"></i></a>
                        <a class="btn btn-sm btn-whatsapp" href="<?php echo e(wa_link($l['whatsapp_number'])); ?>" target="_blank" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                        <a class="btn btn-sm btn-primary" href="lead-details.php?id=<?php echo (int)$l['id']; ?>"><i class="fas fa-pen"></i> Update</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── FILTERS ── -->
<div class="panel">
    <div class="panel-body">
        <form method="GET" class="filter-bar">
            <div class="field"><label>Status</label>
                <select name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($LEAD_STATUSES as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $f_status === $k ? 'selected' : ''; ?>><?php echo $v[0]; ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Follow-up</label>
                <select name="due">
                    <option value="">Any</option>
                    <option value="today"    <?php echo $f_due === 'today' ? 'selected' : ''; ?>>Due today</option>
                    <option value="overdue"  <?php echo $f_due === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                    <option value="upcoming" <?php echo $f_due === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                </select>
            </div>
            <?php if (is_super_admin() && $admin_list): ?>
            <div class="field"><label>Assigned to</label>
                <select name="assigned">
                    <option value="">All admins</option>
                    <?php foreach ($admin_list as $a): ?><option value="<?php echo e($a); ?>" <?php echo $f_assigned === $a ? 'selected' : ''; ?>><?php echo e($a); ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($course_list): ?>
            <div class="field"><label>Course</label>
                <select name="course">
                    <option value="">All courses</option>
                    <?php foreach ($course_list as $c): ?><option value="<?php echo e($c); ?>" <?php echo $f_course === $c ? 'selected' : ''; ?>><?php echo e($c); ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="field grow-2"><label>Search</label><input type="text" name="q" value="<?php echo e($f_q); ?>" placeholder="Name, WhatsApp, course or institute"></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="lead-management.php" class="btn btn-outline">Reset</a>
        </form>
    </div>
</div>

<!-- ── ALL LEADS ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--accent-soft);color:var(--accent-dark);"><i class="fas fa-list"></i></span>
        <h2>Leads (<?php echo number_format($total); ?>)</h2>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($leads)): ?>
            <div class="empty-state"><i class="fas fa-user-tag"></i><p>No leads match these filters. Add your first lead or import a list.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Lead</th><th>Interested In</th><th>Education</th><th>Status</th><th>Next Follow-up</th><th>Assigned</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($leads as $l):
                $overdue = $l['next_followup_date'] && $l['next_followup_date'] < date('Y-m-d') && !in_array($l['status'], $CLOSED, true);
                $istoday = $l['next_followup_date'] === date('Y-m-d') && !in_array($l['status'], $CLOSED, true);
                $normPhone = normalizeLeadPhone($l['whatsapp_number']);
                $matches = $preloadedAdmissions[$normPhone] ?? [];
                $joinedCount = 0;
                $appliedCount = 0;
                $sameCourseApprovedStudent = null;
                $eligibleForManualConversion = false;
                $normLeadCourse = normalizeLeadCourse($l['interested_course']);

                foreach ($matches as $m) {
                    if ($m['status'] === 'approved') {
                        $joinedCount++;
                        if (normalizeLeadCourse($m['pepp_course']) === $normLeadCourse) {
                            $sameCourseApprovedStudent = $m;
                        }
                    } elseif ($m['status'] === 'pending') {
                        $appliedCount++;
                    }
                }
                if ($sameCourseApprovedStudent && $l['status'] !== 'converted') {
                    $eligibleForManualConversion = true;
                }
                
                $matchedDetails = [];
                foreach ($matches as $m) {
                    $matchedDetails[] = [
                        'course' => $m['pepp_course'],
                        'student_name' => $m['name'],
                        'status' => ucfirst($m['status']),
                        'date' => $m['approval_date'] ? date('d M Y', strtotime($m['approval_date'])) : 'N/A',
                        'student_id' => $m['user_id'],
                        'is_same_course' => (normalizeLeadCourse($m['pepp_course']) === normalizeLeadCourse($l['interested_course'])),
                        'lead_status' => $l['status']
                    ];
                }
                $detailsJson = htmlspecialchars(json_encode($matchedDetails));
            ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($l['name'] ?: 'Unknown'); ?></div>
                        <div class="cell-sub">
                            <?php echo format_credential($l['whatsapp_number'], 'phone', 'leads'); ?> · <?php echo (int)$l['followup_count']; ?> follow-up(s)
                            <?php if (!empty($matches)): ?>
                                <div style="margin-top:4px;">
                                    <button type="button" class="joined-courses-btn" data-details="<?php echo $detailsJson; ?>" onclick="showJoinedCoursesModal(this)" style="padding:1px 6px; border-radius:4px; font-size:0.62rem; line-height:1.2; font-weight:700; cursor:pointer; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46;">
                                        <?php if ($joinedCount > 0): ?>
                                            🟢 Joined: <?php echo $joinedCount; ?>
                                        <?php endif; ?>
                                        <?php if ($appliedCount > 0): ?>
                                            <?php echo $joinedCount > 0 ? ' | ' : ''; ?>🟡 Applied: <?php echo $appliedCount; ?>
                                        <?php endif; ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="cell-sub"><?php echo e($l['interested_course'] ?: '-'); ?></td>
                    <td class="cell-sub">
                        <?php echo e($l['last_institute'] ?: '-'); ?>
                        <?php if ($l['year_of_study']): ?><div><span class="badge gray" style="font-size:.62rem;"><?php echo e($l['year_of_study']); ?><?php echo $l['is_fyugp'] === 'yes' ? ' · FYUGP' : ''; ?></span></div><?php endif; ?>
                    </td>
                    <td><span class="badge <?php echo $LEAD_STATUSES[$l['status']][1]; ?>"><?php echo $LEAD_STATUSES[$l['status']][0]; ?></span></td>
                    <td>
                        <?php if (in_array($l['status'], $CLOSED, true)): ?>
                            <span class="cell-sub">-</span>
                        <?php elseif ($l['next_followup_date']): ?>
                            <span class="badge <?php echo $overdue ? 'red' : ($istoday ? 'amber' : 'gray'); ?>"><?php echo date('d M Y', strtotime($l['next_followup_date'])); ?></span>
                        <?php else: ?><span class="cell-sub">-</span><?php endif; ?>
                    </td>
                    <td class="cell-sub"><?php echo $l['assigned_to'] === '__ALL__' ? '<span class="badge violet">All Admins</span>' : e($l['assigned_to'] ?: '-'); ?></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <?php if ($eligibleForManualConversion): ?>
                            <button type="button" class="btn btn-sm btn-success" 
                                    data-lead-id="<?php echo $l['id']; ?>" 
                                    data-lead-name="<?php echo htmlspecialchars($l['name']); ?>" 
                                    data-lead-course="<?php echo htmlspecialchars($l['interested_course']); ?>" 
                                    data-lead-phone="<?php echo htmlspecialchars($l['whatsapp_number']); ?>" 
                                    data-student-id="<?php echo htmlspecialchars($sameCourseApprovedStudent['user_id']); ?>" 
                                    data-student-name="<?php echo htmlspecialchars($sameCourseApprovedStudent['name']); ?>" 
                                    data-student-course="<?php echo htmlspecialchars($sameCourseApprovedStudent['pepp_course']); ?>" 
                                    data-student-date="<?php echo $sameCourseApprovedStudent['approval_date'] ? date('d M Y', strtotime($sameCourseApprovedStudent['approval_date'])) : 'N/A'; ?>" 
                                    onclick="confirmManualConversion(this)" 
                                    title="Mark as Converted" 
                                    style="border-radius:6px; padding:2px 8px; font-size:0.7rem; font-weight:700; margin-right:4px;">
                                <i class="fas fa-check-circle"></i> Mark Converted
                            </button>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline" href="tel:<?php echo preg_replace('/\D/', '', $l['whatsapp_number']); ?>" title="Call"><i class="fas fa-phone"></i></a>
                        <a class="btn btn-sm btn-whatsapp" href="<?php echo e(wa_link($l['whatsapp_number'])); ?>" target="_blank" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                        <a class="btn btn-sm btn-primary" href="lead-details.php?id=<?php echo (int)$l['id']; ?>" title="Open lead"><i class="fas fa-arrow-right"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a class="page-link" href="<?php echo e(lqs(['page' => $page - 1])); ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
            <?php for ($p = max(1, $page - 3); $p <= min($total_pages, $page + 3); $p++): ?>
                <a class="page-link <?php echo $p === $page ? 'active' : ''; ?>" href="<?php echo e(lqs(['page' => $p])); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?><a class="page-link" href="<?php echo e(lqs(['page' => $page + 1])); ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── ADD LEAD MODAL ── -->
<div class="modal-backdrop" id="add-lead-modal">
    <div class="modal" style="max-width:620px;">
        <div class="modal-head"><h3><i class="fas fa-user-plus" style="color:var(--accent);"></i> Add Lead</h3><button class="modal-close" onclick="closeModal('add-lead-modal')"><i class="fas fa-xmark"></i></button></div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_lead">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field"><label>WhatsApp Number <span class="req">*</span></label>
                        <input type="text" name="whatsapp_number" required placeholder="10-digit or with country code"></div>
                    <div class="field"><label>Name</label><input type="text" name="name" placeholder="Lead name"></div>
                    <div class="field"><label>Interested PEPP Course</label>
                        <input type="text" name="interested_course" list="course-options" placeholder="Course of interest">
                        <datalist id="course-options"><?php foreach ($pepp_courses as $c): ?><option value="<?php echo e($c); ?>"><?php endforeach; ?></datalist>
                    </div>
                    <div class="field"><label>Last Studied Institute</label><input type="text" name="last_institute"></div>
                    <div class="field"><label>Last Studied Course</label><input type="text" name="last_course"></div>
                    <div class="field"><label>FYUGP Student?</label>
                        <select name="is_fyugp"><option value="">-</option><option value="yes">Yes</option><option value="no">No</option></select></div>
                    <div class="field"><label>Year of Study</label>
                        <select name="year_of_study"><option value="">-</option><?php foreach ($YEARS as $y): ?><option value="<?php echo $y; ?>"><?php echo $y; ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Lead Status</label>
                        <select name="status" id="add-status" onchange="toggleFollowupReq('add')">
                            <?php foreach ($LEAD_STATUSES as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo $v[0]; ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field"><label>Next Follow-up Date <span class="req" id="add-fu-req">*</span></label>
                        <input type="date" name="next_followup_date" id="add-followup" value="<?php echo date('Y-m-d', strtotime('+2 days')); ?>"></div>
                    <?php if (is_super_admin() && $assignable): ?>
                    <div class="field"><label>Assign To</label>
                        <select name="assigned_to">
                            <option value="__ALL__" selected>All Admins</option>
                            <?php foreach ($assignable as $a): ?><option value="<?php echo e($a); ?>"><?php echo e($a); ?></option><?php endforeach; ?>
                        </select></div>
                    <?php endif; ?>
                    <div class="field full"><label>Remarks</label><textarea name="remarks" rows="2" placeholder="First note about this lead"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('add-lead-modal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Lead</button></div>
        </form>
    </div>
</div>

<!-- ── IMPORT MODAL ── -->
<div class="modal-backdrop" id="import-modal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-head"><h3><i class="fas fa-file-import" style="color:var(--accent);"></i> Bulk Import Leads</h3><button class="modal-close" onclick="closeModal('import-modal')"><i class="fas fa-xmark"></i></button></div>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_import">
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-circle-info"></i>
                    <span>Upload a <strong>CSV file</strong> (in Excel: <em>Save As → CSV</em>). The first row must be column headers. Recognised columns:
                    <code>whatsapp_number</code> (required), <code>name</code>, <code>interested_course</code>, <code>last_institute</code>,
                    <code>last_course</code>, <code>is_fyugp</code> (yes/no), <code>year_of_study</code>, <code>next_followup_date</code>, <code>remarks</code>.
                    Imported leads start as <strong>New</strong>; rows without a valid WhatsApp number are skipped.</span>
                </div>
                <div class="field"><label>CSV file <span class="req">*</span></label>
                    <input type="file" name="lead_file" accept=".csv,.txt" required></div>
                <?php if (is_super_admin() && $assignable): ?>
                <div class="field"><label>Assign all imported leads to</label>
                    <select name="bulk_assigned_to">
                        <option value="__ALL__" selected>All Admins</option>
                        <?php foreach ($assignable as $a): ?><option value="<?php echo e($a); ?>"><?php echo e($a); ?></option><?php endforeach; ?>
                    </select></div>
                <?php endif; ?>
                <a href="lead-sample.csv" download style="font-size:.78rem;font-weight:600;color:var(--accent);"><i class="fas fa-download"></i> Download a sample CSV</a>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('import-modal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-file-import"></i> Import Leads</button></div>
        </form>
    </div>
</div>

<?php
$extra_scripts = "
<!-- Hidden POST Form for Manual Conversion -->
<form id='manual-conversion-form' method='POST' action='lead-management.php' style='display:none;'>
    " . csrf_field() . "
    <input type='hidden' name='action' value='mark_converted'>
    <input type='hidden' name='lead_id' id='post-convert-lead-id'>
    <input type='hidden' name='student_user_id' id='post-convert-student-id'>
</form>

<!-- Joined Courses Modal -->
<div id='joined-courses-modal' style='display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;'>
    <div style='background:#fff; border-radius:16px; width:100%; max-width:400px; padding:20px; box-shadow:0 10px 25px rgba(0,0,0,0.1);'>
        <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;'>
            <h4 style='margin:0; font-size:0.95rem; font-weight:700; color:#1e293b;'><i class='fas fa-user-graduate' style='color:#10b981; margin-right:6px;'></i> Joined PEPP Courses</h4>
            <button onclick=\"document.getElementById('joined-courses-modal').style.display='none'\" style='background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8;'>&times;</button>
        </div>
        <div id='joined-courses-modal-list' style='max-height:300px; overflow-y:auto; margin-bottom:16px; text-align:left;'></div>
        <div style='text-align:right;'>
            <button onclick=\"document.getElementById('joined-courses-modal').style.display='none'\" class='btn btn-secondary' style='border-radius:8px; font-size:0.8rem; padding:6px 14px;'>Close</button>
        </div>
    </div>
</div>

<!-- Manual Conversion Confirmation Modal -->
<div id='confirm-conversion-modal' style='display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;'>
    <div style='background:#fff; border-radius:16px; width:100%; max-width:420px; padding:20px; box-shadow:0 10px 25px rgba(0,0,0,0.1); text-align:left;'>
        <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;'>
            <h4 style='margin:0; font-size:0.95rem; font-weight:700; color:#1e293b;'><i class='fas fa-check-circle' style='color:#10b981; margin-right:6px;'></i> Confirm Conversion</h4>
            <button onclick=\"document.getElementById('confirm-conversion-modal').style.display='none'\" style='background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8;'>&times;</button>
        </div>
        <p style='font-size:0.8rem; color:#64748b; margin-bottom:14px;'>Mark this lead as Converted?</p>
        
        <div style='background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; margin-bottom:16px; font-size:0.75rem; color:#475569;'>
            <strong>Lead Record:</strong><br>
            Name: <span id='modal-lead-name'></span><br>
            Course: <span id='modal-lead-course'></span><br>
            Phone: <span id='modal-lead-phone'></span>
            
            <hr style='border:0; border-top:1px solid #e2e8f0; margin:10px 0;'>
            
            <strong>Matched Admission:</strong><br>
            Student: <span id='modal-student-name'></span> (<span id='modal-student-id'></span>)<br>
            Course: <span id='modal-student-course'></span><br>
            Status: <span style='color:#10b981; font-weight:700;'>Approved</span> on <span id='modal-student-date'></span>
        </div>
        
        <div style='display:flex; justify-content:end; gap:8px;'>
            <button onclick=\"document.getElementById('confirm-conversion-modal').style.display='none'\" class='btn btn-outline' style='border-radius:8px; font-size:0.8rem; padding:6px 14px;'>Cancel</button>
            <button onclick='submitManualConversion()' class='btn btn-success' style='border-radius:8px; font-size:0.8rem; padding:6px 14px; font-weight:700;'>Yes, Mark Converted</button>
        </div>
    </div>
</div>

<script>
var selectedLeadId = null;
var selectedStudentId = null;

function confirmManualConversion(btn) {
    selectedLeadId = btn.getAttribute('data-lead-id');
    selectedStudentId = btn.getAttribute('data-student-id');
    
    document.getElementById('modal-lead-name').textContent = btn.getAttribute('data-lead-name');
    document.getElementById('modal-lead-course').textContent = btn.getAttribute('data-lead-course');
    document.getElementById('modal-lead-phone').textContent = btn.getAttribute('data-lead-phone');
    
    document.getElementById('modal-student-name').textContent = btn.getAttribute('data-student-name');
    document.getElementById('modal-student-id').textContent = selectedStudentId;
    document.getElementById('modal-student-course').textContent = btn.getAttribute('data-student-course');
    document.getElementById('modal-student-date').textContent = btn.getAttribute('data-student-date');
    
    document.getElementById('confirm-conversion-modal').style.display = 'flex';
}

function submitManualConversion() {
    document.getElementById('post-convert-lead-id').value = selectedLeadId;
    document.getElementById('post-convert-student-id').value = selectedStudentId;
    document.getElementById('manual-conversion-form').submit();
}

function showJoinedCoursesModal(btn) {
    var details = JSON.parse(btn.getAttribute('data-details'));
    var listHtml = '<div style=\"display:flex; flex-direction:column; gap:12px;\">';
    
    details.forEach(function(item) {
        var statusColor = '#94a3b8'; // Default grey
        var indicator = '○';
        if (item.status === 'Approved') {
            statusColor = '#10b981'; // Green
            indicator = '✓';
        } else if (item.status === 'Pending') {
            statusColor = '#f59e0b'; // Amber
            indicator = '⚡';
        } else if (item.status === 'Rejected') {
            statusColor = '#ef4444'; // Red
            indicator = '✗';
        }
        
        var courseTypeBadge = '';
        if (item.is_same_course) {
            courseTypeBadge = ' <span style=\"background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:2px 6px; border-radius:4px; font-size:0.65rem; font-weight:700;\">Same Course</span>';
        } else {
            courseTypeBadge = ' <span style=\"background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; padding:2px 6px; border-radius:4px; font-size:0.65rem; font-weight:700;\">Other Course</span>';
        }
        
        listHtml += '<div style=\"background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px;\">';
        listHtml += '  <div style=\"display:flex; justify-content:space-between; align-items:center; font-weight:700; color:#1e293b; font-size:0.82rem; margin-bottom:4px;\">';
        listHtml += '    <span>' + indicator + ' ' + escapeHtml(item.course) + '</span>';
        listHtml += '    ' + courseTypeBadge;
        listHtml += '  </div>';
        listHtml += '  <div style=\"font-size:0.75rem; color:#64748b; margin-top:4px;\">';
        listHtml += '    Name: ' + escapeHtml(item.student_name) + ' (' + escapeHtml(item.student_id) + ')<br>';
        listHtml += '    Status: <span style=\"font-weight:700; color:' + statusColor + ';\">' + escapeHtml(item.status) + '</span>';
        if (item.status === 'Approved') {
            listHtml += ' • Approved on ' + escapeHtml(item.date);
        }
        listHtml += '    <br>Current Lead Status: <span style=\"font-weight:700; color:#475569;\">' + escapeHtml(item.lead_status) + '</span>';
        listHtml += '  </div>';
        listHtml += '</div>';
    });
    listHtml += '</div>';
    
    document.getElementById('joined-courses-modal-list').innerHTML = listHtml;
    document.getElementById('joined-courses-modal').style.display = 'flex';
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function toggleFollowupReq(prefix) {
    var status = document.getElementById(prefix + '-status').value;
    var closed = ['converted','rejected','not_interested'].indexOf(status) !== -1;
    var fu = document.getElementById(prefix + '-followup');
    var req = document.getElementById(prefix + '-fu-req');
    if (fu) fu.required = !closed;
    if (req) req.style.display = closed ? 'none' : 'inline';
}
</script>";
include 'includes/admin_footer.php';
?>
