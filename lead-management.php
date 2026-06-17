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
                if (strlen($wa) < 11) {
                    $error_message = 'A valid WhatsApp number is required.';
                } elseif (!in_array($status, $CLOSED, true) && $followup === '') {
                    $error_message = 'A next follow-up date is required until the lead is converted or rejected.';
                } else {
                    $assigned = is_super_admin() ? (trim($_POST['assigned_to'] ?? '') ?: '__ALL__') : '__ALL__';
                    $stmt = $pdo->prepare("
                        INSERT INTO leads (whatsapp_number, name, interested_course, last_institute, last_course,
                            is_fyugp, year_of_study, status, next_followup_date, assigned_to, source, created_by, created_at, last_activity_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $wa, trim($_POST['name'] ?? ''), trim($_POST['interested_course'] ?? ''),
                        trim($_POST['last_institute'] ?? ''), trim($_POST['last_course'] ?? ''),
                        in_array($_POST['is_fyugp'] ?? '', ['yes', 'no'], true) ? $_POST['is_fyugp'] : null,
                        in_array($_POST['year_of_study'] ?? '', $YEARS, true) ? $_POST['year_of_study'] : null,
                        $status, in_array($status, $CLOSED, true) ? ($followup ?: null) : $followup,
                        $assigned, $admin_username
                    ]);
                    $lead_id = (int)$pdo->lastInsertId();
                    lead_log($pdo, $lead_id, 'created', trim($_POST['remarks'] ?? '') ?: 'Lead created', null, $status, $followup, $admin_username);
                    log_admin_activity($pdo, $admin_username, 'lead_created', "Lead added: {$wa}" . (trim($_POST['name'] ?? '') ? ' (' . trim($_POST['name']) . ')' : ''));
                    $success_message = 'Lead added.';
                }
            } elseif ($action === 'bulk_import') {
                if (!isset($_FILES['lead_file']) || $_FILES['lead_file']['error'] !== UPLOAD_ERR_OK) {
                    $error_message = 'Please choose a CSV or Excel file to import.';
                } else {
                    $rows = parse_lead_file($_FILES['lead_file']['tmp_name'], $_FILES['lead_file']['name']);
                    if ($rows === null) {
                        $error_message = 'Could not read the file. Please upload a .csv file (Excel: Save As → CSV).';
                    } else {
                        $added = 0; $skipped = 0;
                        $assigned_default = is_super_admin() ? (trim($_POST['bulk_assigned_to'] ?? '') ?: '__ALL__') : '__ALL__';
                        foreach ($rows as $r) {
                            $wa = clean_wa($r['whatsapp_number'] ?? '');
                            if (strlen($wa) < 11) { $skipped++; continue; }
                            $yr = in_array($r['year_of_study'] ?? '', $YEARS, true) ? $r['year_of_study'] : null;
                            $fy = in_array(strtolower($r['is_fyugp'] ?? ''), ['yes', 'no'], true) ? strtolower($r['is_fyugp']) : null;
                            $fu = (!empty($r['next_followup_date']) && strtotime($r['next_followup_date'])) ? date('Y-m-d', strtotime($r['next_followup_date'])) : date('Y-m-d', strtotime('+2 days'));
                            $stmt = $pdo->prepare("
                                INSERT INTO leads (whatsapp_number, name, interested_course, last_institute, last_course,
                                    is_fyugp, year_of_study, status, next_followup_date, assigned_to, source, created_by, created_at, last_activity_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, 'import', ?, NOW(), NOW())
                            ");
                            $stmt->execute([
                                $wa, trim($r['name'] ?? ''), trim($r['interested_course'] ?? ''),
                                trim($r['last_institute'] ?? ''), trim($r['last_course'] ?? ''),
                                $fy, $yr, $fu, $assigned_default, $admin_username
                            ]);
                            $lead_id = (int)$pdo->lastInsertId();
                            lead_log($pdo, $lead_id, 'created', trim($r['remarks'] ?? '') ?: 'Imported from file', null, 'new', $fu, $admin_username);
                            $added++;
                        }
                        log_admin_activity($pdo, $admin_username, 'leads_imported', "Bulk import: {$added} added, {$skipped} skipped");
                        $success_message = "Import complete: {$added} lead(s) added" . ($skipped ? ", {$skipped} skipped (missing/invalid WhatsApp number)" : '') . '.';
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
            <?php foreach ($today_leads as $l): $overdue = $l['next_followup_date'] < date('Y-m-d'); ?>
                <tr<?php echo $overdue ? ' style="background:#fff7f7;"' : ''; ?>>
                    <td>
                        <div class="cell-main"><?php echo e($l['name'] ?: 'Unknown'); ?></div>
                        <div class="cell-sub"><?php echo e($l['whatsapp_number']); ?> · <?php echo (int)$l['followup_count']; ?> follow-up(s)</div>
                    </td>
                    <td class="cell-sub"><?php echo e($l['interested_course'] ?: '-'); ?></td>
                    <td><span class="badge <?php echo $LEAD_STATUSES[$l['status']][1]; ?>"><?php echo $LEAD_STATUSES[$l['status']][0]; ?></span></td>
                    <td><span class="badge <?php echo $overdue ? 'red' : 'amber'; ?>"><?php echo date('d M', strtotime($l['next_followup_date'])); ?><?php echo $overdue ? ' · overdue' : ' · today'; ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
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
            ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($l['name'] ?: 'Unknown'); ?></div>
                        <div class="cell-sub"><?php echo e($l['whatsapp_number']); ?> · <?php echo (int)$l['followup_count']; ?> follow-up(s)</div>
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
$extra_scripts = "<script>
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
