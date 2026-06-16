<?php
require_once 'includes/auth.php';
require_super_admin();

/* Activity Log - Super Admin only.
   Merges three sources into one filterable timeline:
   • admin_activity_log     : logins / logouts / auto-logouts / admin events
   • track_records          : every action performed on a student
   • whatsapp_notifications : messages sent to students
   Each source is queried INDEPENDENTLY and merged in PHP, so a missing or
   differently-shaped table never blanks the whole page. Supports per-row
   delete and "clear filtered" delete, plus Excel export. */

$f_admin = trim($_GET['admin'] ?? '');
$f_type  = trim($_GET['type'] ?? '');
$f_from  = trim($_GET['from'] ?? '');
$f_to    = trim($_GET['to'] ?? '');
$f_q     = trim($_GET['q'] ?? '');

if ($f_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = '';
if ($f_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = '';

$success_message = '';
$error_message   = '';

function table_exists($pdo, $t) {
    try { return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn(); }
    catch (Exception $e) { return false; }
}

/**
 * Collect activity rows from all three sources, merged & sorted in PHP.
 * Each row: source, source_id, at_time, admin_name, act, details, ip, loc, student.
 */
function collect_activity($pdo, $f_admin, $f_type, $f_from, $f_to, $f_q, $limit = 500) {
    $all = [];
    $like = $f_q !== '' ? '%' . $f_q . '%' : null;

    if (($f_type === '' || in_array($f_type, ['session', 'admin_event'], true)) && table_exists($pdo, 'admin_activity_log')) {
        try {
            $w = ['1=1']; $p = [];
            if ($f_type === 'session')     $w[] = "action_type IN ('login','logout','auto_logout','forced_logout')";
            if ($f_type === 'admin_event') $w[] = "action_type NOT IN ('login','logout','auto_logout','forced_logout')";
            if ($f_admin !== '') { $w[] = "admin_username = ?"; $p[] = $f_admin; }
            if ($f_from  !== '') { $w[] = "created_at >= ?";    $p[] = $f_from . ' 00:00:00'; }
            if ($f_to    !== '') { $w[] = "created_at <= ?";    $p[] = $f_to . ' 23:59:59'; }
            if ($like)           { $w[] = "(details LIKE ? OR action_type LIKE ?)"; $p[] = $like; $p[] = $like; }
            $stmt = $pdo->prepare("SELECT id, created_at, admin_username, action_type, details, ip_address, location
                                   FROM admin_activity_log WHERE " . implode(' AND ', $w) . " ORDER BY created_at DESC LIMIT $limit");
            $stmt->execute($p);
            foreach ($stmt->fetchAll() as $r) {
                $all[] = ['source' => 'admin_activity_log', 'source_id' => (int)$r['id'], 'at_time' => $r['created_at'],
                          'admin_name' => $r['admin_username'], 'act' => $r['action_type'], 'details' => $r['details'],
                          'ip' => $r['ip_address'], 'loc' => $r['location'], 'student' => null];
            }
        } catch (Exception $e) { error_log('activity admin_log: ' . $e->getMessage()); }
    }

    if (($f_type === '' || $f_type === 'student_action') && table_exists($pdo, 'track_records')) {
        try {
            $w = ['1=1']; $p = [];
            if ($f_admin !== '') { $w[] = "performed_by = ?"; $p[] = $f_admin; }
            if ($f_from  !== '') { $w[] = "performed_at >= ?"; $p[] = $f_from . ' 00:00:00'; }
            if ($f_to    !== '') { $w[] = "performed_at <= ?"; $p[] = $f_to . ' 23:59:59'; }
            if ($like)           { $w[] = "(action_details LIKE ? OR action_type LIKE ? OR user_id LIKE ?)"; $p[] = $like; $p[] = $like; $p[] = $like; }
            $stmt = $pdo->prepare("SELECT id, performed_at, performed_by, action_type, action_details, user_id
                                   FROM track_records WHERE " . implode(' AND ', $w) . " ORDER BY performed_at DESC LIMIT $limit");
            $stmt->execute($p);
            foreach ($stmt->fetchAll() as $r) {
                $all[] = ['source' => 'track_records', 'source_id' => (int)$r['id'], 'at_time' => $r['performed_at'],
                          'admin_name' => $r['performed_by'], 'act' => $r['action_type'], 'details' => $r['action_details'],
                          'ip' => null, 'loc' => null, 'student' => $r['user_id']];
            }
        } catch (Exception $e) { error_log('activity track: ' . $e->getMessage()); }
    }

    if (($f_type === '' || $f_type === 'whatsapp') && table_exists($pdo, 'whatsapp_notifications')) {
        try {
            $w = ['1=1']; $p = [];
            if ($f_admin !== '') { $w[] = "sent_by = ?"; $p[] = $f_admin; }
            if ($f_from  !== '') { $w[] = "created_at >= ?"; $p[] = $f_from . ' 00:00:00'; }
            if ($f_to    !== '') { $w[] = "created_at <= ?"; $p[] = $f_to . ' 23:59:59'; }
            if ($like)           { $w[] = "(message LIKE ? OR student_name LIKE ? OR phone LIKE ?)"; $p[] = $like; $p[] = $like; $p[] = $like; }
            $stmt = $pdo->prepare("SELECT id, created_at, sent_by, student_name, phone, message
                                   FROM whatsapp_notifications WHERE " . implode(' AND ', $w) . " ORDER BY created_at DESC LIMIT $limit");
            $stmt->execute($p);
            foreach ($stmt->fetchAll() as $r) {
                $detail = 'To ' . ($r['student_name'] ?: $r['phone']) . ': ' . mb_substr((string)$r['message'], 0, 160);
                $all[] = ['source' => 'whatsapp_notifications', 'source_id' => (int)$r['id'], 'at_time' => $r['created_at'],
                          'admin_name' => $r['sent_by'], 'act' => 'whatsapp_message', 'details' => $detail,
                          'ip' => null, 'loc' => null, 'student' => null];
            }
        } catch (Exception $e) { error_log('activity whatsapp: ' . $e->getMessage()); }
    }

    usort($all, function ($a, $b) { return strtotime($b['at_time']) <=> strtotime($a['at_time']); });
    return array_slice($all, 0, $limit);
}

/* ── POST: delete actions ───────────────────────────────────────── */
$valid_sources = ['admin_activity_log', 'track_records', 'whatsapp_notifications'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'delete_one') {
                $src = $_POST['source'] ?? '';
                $sid = (int)($_POST['source_id'] ?? 0);
                if (in_array($src, $valid_sources, true) && $sid > 0) {
                    $pdo->prepare("DELETE FROM `$src` WHERE id = ?")->execute([$sid]);
                    log_admin_activity($pdo, $admin_username, 'activity_deleted', "Deleted one activity record from {$src} (#{$sid})");
                    $success_message = 'Activity record deleted.';
                } else {
                    $error_message = 'Invalid record.';
                }
            } elseif ($action === 'clear_filtered') {
                $rows = collect_activity($pdo, $f_admin, $f_type, $f_from, $f_to, $f_q, 100000);
                $byTable = ['admin_activity_log' => [], 'track_records' => [], 'whatsapp_notifications' => []];
                foreach ($rows as $r) { $byTable[$r['source']][] = (int)$r['source_id']; }
                $deleted = 0;
                foreach ($byTable as $tbl => $ids) {
                    if (empty($ids)) continue;
                    $ids = array_values(array_unique(array_filter($ids)));
                    foreach (array_chunk($ids, 500) as $chunk) {
                        $ph = implode(',', array_fill(0, count($chunk), '?'));
                        $stmt = $pdo->prepare("DELETE FROM `$tbl` WHERE id IN ($ph)");
                        $stmt->execute($chunk);
                        $deleted += $stmt->rowCount();
                    }
                }
                log_admin_activity($pdo, $admin_username, 'activity_cleared',
                    "Cleared {$deleted} activity record(s) matching filter" .
                    ($f_admin || $f_type || $f_from || $f_to || $f_q ? '' : ' (ALL records)'));
                $success_message = "Deleted {$deleted} activity record(s).";
            }
        } catch (Exception $e) {
            error_log('Activity delete: ' . $e->getMessage());
            $error_message = 'Database error while deleting records.';
        }
    }
}

/* ── EXPORT (before any output) ─────────────────────────────────── */
if (isset($_GET['export'])) {
    $rows = collect_activity($pdo, $f_admin, $f_type, $f_from, $f_to, $f_q, 20000);
    log_admin_activity($pdo, $admin_username, 'data_export',
        'Exported admin activity (' . count($rows) . ' rows' . ($f_admin ? ", admin={$f_admin}" : '') . ')');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="admin-activity-' . date('Y-m-d-Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date & Time', 'Admin', 'Action', 'Details', 'Student ID', 'IP Address', 'Location']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['at_time'], $r['admin_name'], $r['act'], $r['details'], $r['student'], $r['ip'], $r['loc']]);
    }
    fclose($out);
    exit();
}

/* ── PAGE DATA ──────────────────────────────────────────────────── */
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 40;

$all_rows = collect_activity($pdo, $f_admin, $f_type, $f_from, $f_to, $f_q, 5000);
$total    = count($all_rows);
$total_pages = max(1, (int)ceil($total / $per_page));
$page     = min($page, $total_pages);
$rows     = array_slice($all_rows, ($page - 1) * $per_page, $per_page);

$admin_list = [];
try {
    $set = [];
    if (table_exists($pdo, 'admin_activity_log')) {
        foreach ($pdo->query("SELECT DISTINCT admin_username FROM admin_activity_log WHERE admin_username IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $a) $set[$a] = true;
    }
    if (table_exists($pdo, 'track_records')) {
        foreach ($pdo->query("SELECT DISTINCT performed_by FROM track_records WHERE performed_by IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $a) $set[$a] = true;
    }
    if (table_exists($pdo, 'whatsapp_notifications')) {
        foreach ($pdo->query("SELECT DISTINCT sent_by FROM whatsapp_notifications WHERE sent_by IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $a) $set[$a] = true;
    }
    $admin_list = array_keys($set);
    sort($admin_list);
} catch (Exception $e) { error_log('activity admin list: ' . $e->getMessage()); }

$has_filter = ($f_admin || $f_type || $f_from || $f_to || $f_q);

function aqs($overrides = []) {
    $q = array_merge($_GET, $overrides);
    unset($q['logout'], $q['export']);
    return '?' . http_build_query($q);
}
function act_badge($act) {
    if ($act === 'login') return ['green', 'fa-right-to-bracket', 'Login'];
    if ($act === 'logout') return ['gray', 'fa-right-from-bracket', 'Logout'];
    if (in_array($act, ['auto_logout', 'forced_logout'], true)) return ['amber', 'fa-clock', $act === 'auto_logout' ? 'Auto-logout' : 'Forced logout'];
    if ($act === 'whatsapp_message') return ['teal', 'fa-comment', 'WhatsApp'];
    if (strpos($act, 'admin_') === 0 || strpos($act, 'lead_') === 0 || strpos($act, 'invoice') === 0 ||
        in_array($act, ['permissions_changed', 'password_reset', 'password_changed', 'data_export', 'activity_deleted', 'activity_cleared', 'student_reverted'], true))
        return ['violet', 'fa-user-shield', ucwords(str_replace('_', ' ', $act))];
    return ['blue', 'fa-pen', ucwords(str_replace('_', ' ', $act))];
}

$active_page = 'admin-activity';
$page_title  = 'Activity Log';
$page_sub    = 'Every admin action, login & message - Super Admin only';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="panel">
    <div class="panel-body">
        <form method="GET" class="filter-bar">
            <div class="field">
                <label>Admin</label>
                <select name="admin">
                    <option value="">All admins</option>
                    <?php foreach ($admin_list as $a): ?>
                        <option value="<?php echo e($a); ?>" <?php echo $f_admin === $a ? 'selected' : ''; ?>><?php echo e($a); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Type</label>
                <select name="type">
                    <option value="">All activity</option>
                    <option value="session"        <?php echo $f_type === 'session' ? 'selected' : ''; ?>>Logins / Logouts</option>
                    <option value="student_action" <?php echo $f_type === 'student_action' ? 'selected' : ''; ?>>Student actions</option>
                    <option value="whatsapp"       <?php echo $f_type === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp messages</option>
                    <option value="admin_event"    <?php echo $f_type === 'admin_event' ? 'selected' : ''; ?>>Admin / system events</option>
                </select>
            </div>
            <div class="field"><label>From</label><input type="date" name="from" value="<?php echo e($f_from); ?>"></div>
            <div class="field"><label>To</label><input type="date" name="to" value="<?php echo e($f_to); ?>"></div>
            <div class="field grow-2"><label>Search</label><input type="text" name="q" value="<?php echo e($f_q); ?>" placeholder="Details, action or student ID"></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="admin-activity.php" class="btn btn-outline">Reset</a>
            <a href="<?php echo e(aqs(['export' => 1])); ?>" class="btn btn-soft-green"><i class="fas fa-file-excel"></i> Export Excel</a>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--card);color:var(--secondary);"><i class="fas fa-clock-rotate-left"></i></span>
        <h2>Activity (<?php echo number_format($total); ?><?php echo $total >= 5000 ? '+' : ''; ?> records)</h2>
        <div class="head-right">
            <?php if ($total > 0): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirmClear();">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="clear_filtered">
                <button type="submit" class="btn btn-sm btn-soft-red">
                    <i class="fas fa-trash"></i> <?php echo $has_filter ? 'Delete Filtered' : 'Clear All'; ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($rows)): ?>
            <div class="empty-state"><i class="fas fa-clock-rotate-left"></i>
                <p><?php echo $has_filter ? 'No activity matches these filters.' : 'No activity recorded yet. Logins, student actions and messages will appear here.'; ?></p>
            </div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Date &amp; Time</th><th>Admin</th><th>Action</th><th>Details</th><th>IP / Location</th><th style="text-align:right;">Delete</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): [$b, $icon, $label] = act_badge($r['act']); ?>
                <tr>
                    <td class="cell-sub" style="white-space:nowrap;"><?php echo date('d M Y, h:i:s A', strtotime($r['at_time'])); ?></td>
                    <td class="cell-main"><?php echo e($r['admin_name'] ?: '-'); ?></td>
                    <td><span class="badge <?php echo $b; ?>"><i class="fas <?php echo $icon; ?>"></i> <?php echo e($label); ?></span></td>
                    <td class="cell-sub" style="max-width:420px;">
                        <?php echo e($r['details'] ?: '-'); ?>
                        <?php if ($r['student']): ?>
                            · <a href="student-details.php?user_id=<?php echo urlencode($r['student']); ?>"><?php echo e($r['student']); ?></a>
                        <?php endif; ?>
                    </td>
                    <td class="cell-sub">
                        <?php if ($r['ip']): ?>
                            <?php echo e($r['ip']); ?><br><span style="font-size:.7rem;"><?php echo e($r['loc'] ?: ''); ?></span>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this activity record?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_one">
                            <input type="hidden" name="source" value="<?php echo e($r['source']); ?>">
                            <input type="hidden" name="source_id" value="<?php echo (int)$r['source_id']; ?>">
                            <button type="submit" class="btn btn-sm btn-soft-red" title="Delete this record"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a class="page-link" href="<?php echo e(aqs(['page' => $page - 1])); ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
            <?php for ($p = max(1, $page - 3); $p <= min($total_pages, $page + 3); $p++): ?>
                <a class="page-link <?php echo $p === $page ? 'active' : ''; ?>" href="<?php echo e(aqs(['page' => $p])); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?><a class="page-link" href="<?php echo e(aqs(['page' => $page + 1])); ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
$extra_scripts = "<script>
function confirmClear() {
    " . ($has_filter
        ? "return confirm('Delete ALL " . (int)$total . " activity records matching the current filter? This cannot be undone.');"
        : "return confirm('Delete the ENTIRE activity log (" . (int)$total . " records)? This cannot be undone.');") . "
}
</script>";
include 'includes/admin_footer.php';
?>
