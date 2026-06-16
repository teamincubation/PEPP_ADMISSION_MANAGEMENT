<?php
require_once 'includes/auth.php';
require_permission('leads');

/* Lead detail - full history of one lead with every remark, follow-up and
   status change (who did it and when), plus the update form. */

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

if (!function_exists('lead_log')) {
    function lead_log($pdo, $lead_id, $type, $remark, $old, $new, $followup, $admin) {
        try {
            $stmt = $pdo->prepare("INSERT INTO lead_activity (lead_id, activity_type, remark, old_status, new_status, followup_date, performed_by, performed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$lead_id, $type, $remark, $old, $new, $followup ?: null, $admin]);
            $pdo->prepare("UPDATE leads SET last_activity_at = NOW() WHERE id = ?")->execute([$lead_id]);
        } catch (Exception $e) { error_log('lead_log: ' . $e->getMessage()); }
    }
}

$lead_id = (int)($_GET['id'] ?? 0);
if (!$lead_id) { header('Location: lead-management.php'); exit(); }

$success_message = ''; $error_message = '';

/* Load lead */
function load_lead($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}
$lead = load_lead($pdo, $lead_id);
if (!$lead) { header('Location: lead-management.php'); exit(); }

// Non-super admins may only open leads assigned to them
if (!is_super_admin() && $lead['assigned_to'] !== $admin_username && $lead['assigned_to'] !== '__ALL__') {
    require_super_admin(); // shows the restricted page
    exit();
}

/* ── POST: update ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'update_lead') {
                $new_status = in_array($_POST['status'] ?? '', array_keys($LEAD_STATUSES), true) ? $_POST['status'] : $lead['status'];
                $remark     = trim($_POST['remark'] ?? '');
                $followup   = $_POST['next_followup_date'] ?? '';
                $is_followup = isset($_POST['log_followup']);
                $closed = in_array($new_status, $CLOSED, true);

                if (!$closed && $followup === '') {
                    $error_message = 'A next follow-up date is required until the lead is converted or rejected.';
                } else {
                    // Editable profile fields
                    $name = trim($_POST['name'] ?? $lead['name']);
                    $course = trim($_POST['interested_course'] ?? $lead['interested_course']);
                    $inst = trim($_POST['last_institute'] ?? $lead['last_institute']);
                    $lcourse = trim($_POST['last_course'] ?? $lead['last_course']);
                    $fy = in_array($_POST['is_fyugp'] ?? '', ['yes', 'no'], true) ? $_POST['is_fyugp'] : null;
                    $yr = in_array($_POST['year_of_study'] ?? '', $YEARS, true) ? $_POST['year_of_study'] : null;
                    $assigned = (is_super_admin() && !empty($_POST['assigned_to'])) ? $_POST['assigned_to'] : $lead['assigned_to'];

                    $inc = $is_followup ? 1 : 0;
                    $stmt = $pdo->prepare("
                        UPDATE leads SET name=?, interested_course=?, last_institute=?, last_course=?, is_fyugp=?, year_of_study=?,
                            status=?, next_followup_date=?, assigned_to=?, followup_count = followup_count + ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $course, $inst, $lcourse, $fy, $yr, $new_status,
                        $closed ? ($followup ?: null) : $followup, $assigned, $inc, $lead_id]);

                    // Timeline entries
                    if ($new_status !== $lead['status']) {
                        lead_log($pdo, $lead_id, 'status_change', $remark ?: null, $lead['status'], $new_status, $followup, $admin_username);
                    }
                    if ($is_followup) {
                        lead_log($pdo, $lead_id, 'followup', $remark ?: 'Follow-up done', null, null, $followup, $admin_username);
                    } elseif ($remark !== '' && $new_status === $lead['status']) {
                        lead_log($pdo, $lead_id, 'remark', $remark, null, null, $followup, $admin_username);
                    }
                    if ($assigned !== $lead['assigned_to']) {
                        lead_log($pdo, $lead_id, 'reassigned', 'Reassigned to ' . $assigned, null, null, null, $admin_username);
                    }

                    log_admin_activity($pdo, $admin_username, 'lead_updated',
                        "Lead #{$lead_id} ({$lead['whatsapp_number']}) → " . $LEAD_STATUSES[$new_status][0] . ($is_followup ? ' [follow-up logged]' : ''));
                    $success_message = 'Lead updated.';
                    $lead = load_lead($pdo, $lead_id);
                }
            } elseif ($action === 'convert_lead') {
                // Link this lead to an existing student record (optional user_id)
                $uid = trim($_POST['converted_user_id'] ?? '');
                $pdo->prepare("UPDATE leads SET status = 'converted', converted_user_id = ?, next_followup_date = NULL, updated_at = NOW() WHERE id = ?")
                    ->execute([$uid ?: null, $lead_id]);
                lead_log($pdo, $lead_id, 'status_change', $uid ? "Converted - linked to student {$uid}" : 'Marked as converted', $lead['status'], 'converted', null, $admin_username);
                log_admin_activity($pdo, $admin_username, 'lead_converted', "Lead #{$lead_id} converted" . ($uid ? " → {$uid}" : ''));
                $success_message = 'Lead marked as converted.';
                $lead = load_lead($pdo, $lead_id);
            } elseif ($action === 'delete_lead') {
                if (!is_super_admin()) {
                    $error_message = 'Only the Super Admin can delete a lead.';
                } else {
                    $pdo->prepare("DELETE FROM lead_activity WHERE lead_id = ?")->execute([$lead_id]);
                    $pdo->prepare("DELETE FROM leads WHERE id = ?")->execute([$lead_id]);
                    log_admin_activity($pdo, $admin_username, 'lead_deleted', "Deleted lead #{$lead_id} ({$lead['whatsapp_number']})");
                    header('Location: lead-management.php?deleted=1');
                    exit();
                }
            }
        } catch (Exception $e) {
            error_log('Lead update: ' . $e->getMessage());
            $error_message = 'Database error while updating the lead.';
        }
    }
}

/* Timeline + assignable admins + student match */
$timeline = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM lead_activity WHERE lead_id = ? ORDER BY performed_at DESC, id DESC");
    $stmt->execute([$lead_id]);
    $timeline = $stmt->fetchAll();
} catch (Exception $e) {}

$assignable = [];
if (is_super_admin() && admins_table_exists($pdo)) {
    try { $assignable = $pdo->query("SELECT username FROM admins WHERE status = 'active' ORDER BY role='super_admin' DESC, username")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
}
$pepp_courses = [];
try { $pepp_courses = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

// Try to match this WhatsApp number to an existing student (for conversion)
$matched_student = null;
try {
    $last10 = substr(preg_replace('/\D/', '', $lead['whatsapp_number']), -10);
    $stmt = $pdo->prepare("SELECT user_id, name, status FROM users WHERE RIGHT(REPLACE(REPLACE(whatsapp_number,' ',''),'-',''),10) = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$last10]);
    $matched_student = $stmt->fetch();
} catch (Exception $e) {}

function wa_link($num, $text = '') { return 'https://wa.me/' . preg_replace('/\D/', '', $num) . ($text ? '?text=' . rawurlencode($text) : ''); }
$st = $lead['status'];
$is_closed = in_array($st, $CLOSED, true);
$overdue = $lead['next_followup_date'] && $lead['next_followup_date'] < date('Y-m-d') && !$is_closed;

$active_page = 'leads';
$page_title  = $lead['name'] ?: 'Lead';
$page_sub    = $lead['whatsapp_number'];
include 'includes/admin_nav.php';
?>

<div style="margin-bottom:16px;"><a href="lead-management.php" class="btn btn-sm btn-outline"><i class="fas fa-arrow-left"></i> Back to Leads</a></div>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start;" class="lead-grid">

    <!-- ── LEFT: profile + update ── -->
    <div>
        <div class="panel">
            <div class="panel-head">
                <span class="head-icon" style="background:var(--accent-soft);color:var(--accent-dark);"><i class="fas fa-user-tag"></i></span>
                <h2>Lead Details</h2>
                <div class="head-right">
                    <span class="badge <?php echo $LEAD_STATUSES[$st][1]; ?>"><?php echo $LEAD_STATUSES[$st][0]; ?></span>
                    <a class="btn btn-sm btn-whatsapp" href="<?php echo e(wa_link($lead['whatsapp_number'])); ?>" target="_blank"><i class="fab fa-whatsapp"></i> Chat</a>
                </div>
            </div>
            <div class="panel-body">
                <div class="detail-list" style="margin-bottom:14px;">
                    <div class="detail-row"><div class="dl">WhatsApp</div><div class="dv"><?php echo e($lead['whatsapp_number']); ?></div></div>
                    <div class="detail-row"><div class="dl">Follow-ups done</div><div class="dv"><?php echo (int)$lead['followup_count']; ?></div></div>
                    <div class="detail-row"><div class="dl">Next follow-up</div><div class="dv">
                        <?php if ($is_closed): ?>-
                        <?php elseif ($lead['next_followup_date']): ?>
                            <span class="badge <?php echo $overdue ? 'red' : 'amber'; ?>"><?php echo date('d M Y', strtotime($lead['next_followup_date'])); ?><?php echo $overdue ? ' · overdue' : ''; ?></span>
                        <?php else: ?>-<?php endif; ?>
                    </div></div>
                    <div class="detail-row"><div class="dl">Assigned to</div><div class="dv"><?php echo $lead['assigned_to'] === '__ALL__' ? 'All Admins' : e($lead['assigned_to'] ?: '-'); ?></div></div>
                    <div class="detail-row"><div class="dl">Source</div><div class="dv"><?php echo e(ucfirst($lead['source'])); ?></div></div>
                    <div class="detail-row"><div class="dl">Created</div><div class="dv"><?php echo date('d M Y, h:i A', strtotime($lead['created_at'])); ?> by <?php echo e($lead['created_by'] ?: '-'); ?></div></div>
                    <?php if ($lead['converted_user_id']): ?>
                    <div class="detail-row"><div class="dl">Student</div><div class="dv"><a href="student-details.php?user_id=<?php echo urlencode($lead['converted_user_id']); ?>"><?php echo e($lead['converted_user_id']); ?></a></div></div>
                    <?php endif; ?>
                </div>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_lead">
                    <div class="form-grid">
                        <div class="field"><label>Name</label><input type="text" name="name" value="<?php echo e($lead['name']); ?>"></div>
                        <div class="field"><label>Interested PEPP Course</label>
                            <select name="interested_course">
                                <option value="">Select a course...</option>
                                <?php
                                $cur = $lead['interested_course'];
                                $found = false;
                                foreach ($pepp_courses as $c): if ($c === $cur) $found = true; ?>
                                    <option value="<?php echo e($c); ?>" <?php echo $c === $cur ? 'selected' : ''; ?>><?php echo e($c); ?></option>
                                <?php endforeach; ?>
                                <?php if (!$found && $cur !== ''): ?><option value="<?php echo e($cur); ?>" selected><?php echo e($cur); ?> (current)</option><?php endif; ?>
                            </select></div>
                        <div class="field"><label>Last Studied Institute</label><input type="text" name="last_institute" value="<?php echo e($lead['last_institute']); ?>"></div>
                        <div class="field"><label>Last Studied Course</label><input type="text" name="last_course" value="<?php echo e($lead['last_course']); ?>"></div>
                        <div class="field"><label>FYUGP Student?</label>
                            <select name="is_fyugp"><option value="">-</option>
                                <option value="yes" <?php echo $lead['is_fyugp'] === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                <option value="no"  <?php echo $lead['is_fyugp'] === 'no' ? 'selected' : ''; ?>>No</option></select></div>
                        <div class="field"><label>Year of Study</label>
                            <select name="year_of_study"><option value="">-</option>
                                <?php foreach ($YEARS as $y): ?><option value="<?php echo $y; ?>" <?php echo $lead['year_of_study'] === $y ? 'selected' : ''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label>Lead Status</label>
                            <select name="status" id="status-sel" onchange="toggleFU()">
                                <?php foreach ($LEAD_STATUSES as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $st === $k ? 'selected' : ''; ?>><?php echo $v[0]; ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label>Next Follow-up Date <span class="req" id="fu-req"<?php echo $is_closed ? ' style="display:none;"' : ''; ?>>*</span></label>
                            <input type="date" name="next_followup_date" id="fu-date" value="<?php echo e($lead['next_followup_date']); ?>"></div>
                        <?php if (is_super_admin() && $assignable): ?>
                        <div class="field"><label>Assigned To</label>
                            <select name="assigned_to">
                                <option value="__ALL__" <?php echo $lead['assigned_to'] === '__ALL__' ? 'selected' : ''; ?>>All Admins</option>
                                <?php foreach ($assignable as $a): ?><option value="<?php echo e($a); ?>" <?php echo $lead['assigned_to'] === $a ? 'selected' : ''; ?>><?php echo e($a); ?></option><?php endforeach; ?>
                            </select></div>
                        <?php endif; ?>
                        <div class="field full"><label>Add a remark</label><textarea name="remark" rows="2" placeholder="What happened in this interaction?"></textarea></div>
                        <div class="field full">
                            <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;text-transform:none;letter-spacing:0;cursor:pointer;">
                                <input type="checkbox" name="log_followup" value="1" style="width:16px;height:16px;accent-color:var(--accent);"> Count this as a completed follow-up (+1)
                            </label>
                        </div>
                    </div>
                    <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Update</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Convert / link to student -->
        <?php if ($st !== 'converted'): ?>
        <div class="panel">
            <div class="panel-head"><span class="head-icon green"><i class="fas fa-circle-check"></i></span><h2>Convert Lead</h2></div>
            <div class="panel-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="convert_lead">
                    <?php if ($matched_student): ?>
                        <div class="alert alert-info"><i class="fas fa-circle-info"></i><span>This WhatsApp number matches student <strong><?php echo e($matched_student['name']); ?></strong> (<?php echo e($matched_student['user_id']); ?>, <?php echo e($matched_student['status']); ?>).</span></div>
                        <input type="hidden" name="converted_user_id" value="<?php echo e($matched_student['user_id']); ?>">
                    <?php else: ?>
                        <div class="field"><label>Student ID (optional)</label><input type="text" name="converted_user_id" placeholder="e.g. PEPP20260042 - leave blank if not registered yet"></div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-soft-green" onclick="return confirm('Mark this lead as converted?');"><i class="fas fa-circle-check"></i> Mark as Converted</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (is_super_admin()): ?>
        <div class="panel" style="border-color:#fecaca;">
            <div class="panel-body" style="display:flex; gap:14px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
                <div class="cell-sub">Delete this lead and its entire history (Super Admin).</div>
                <form method="POST" onsubmit="return confirm('Delete this lead permanently?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_lead">
                    <button type="submit" class="btn btn-soft-red btn-sm"><i class="fas fa-trash"></i> Delete Lead</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── RIGHT: timeline ── -->
    <div class="panel">
        <div class="panel-head"><span class="head-icon" style="background:var(--card);color:var(--secondary);"><i class="fas fa-timeline"></i></span><h2>Activity &amp; Remarks (<?php echo count($timeline); ?>)</h2></div>
        <div class="panel-body">
            <?php if (empty($timeline)): ?>
                <div class="empty-state"><i class="fas fa-comment-dots"></i><p>No activity yet.</p></div>
            <?php else: ?>
            <div class="lead-timeline">
                <?php foreach ($timeline as $t):
                    $icon = 'fa-comment'; $color = 'var(--secondary)';
                    if ($t['activity_type'] === 'status_change') { $icon = 'fa-flag'; $color = 'var(--accent)'; }
                    elseif ($t['activity_type'] === 'followup') { $icon = 'fa-phone'; $color = 'var(--amber-ink)'; }
                    elseif ($t['activity_type'] === 'created') { $icon = 'fa-plus'; $color = 'var(--green-ink)'; }
                    elseif ($t['activity_type'] === 'reassigned') { $icon = 'fa-user-pen'; $color = 'var(--blue-ink)'; }
                ?>
                <div class="tl-item">
                    <div class="tl-dot" style="color:<?php echo $color; ?>;"><i class="fas <?php echo $icon; ?>"></i></div>
                    <div class="tl-body">
                        <?php if ($t['old_status'] || $t['new_status']): ?>
                            <div class="tl-title">
                                <?php if ($t['old_status']): ?><span class="badge <?php echo $LEAD_STATUSES[$t['old_status']][1] ?? 'gray'; ?>"><?php echo $LEAD_STATUSES[$t['old_status']][0] ?? $t['old_status']; ?></span> &rarr; <?php endif; ?>
                                <span class="badge <?php echo $LEAD_STATUSES[$t['new_status']][1] ?? 'gray'; ?>"><?php echo $LEAD_STATUSES[$t['new_status']][0] ?? $t['new_status']; ?></span>
                            </div>
                        <?php elseif ($t['activity_type'] === 'followup'): ?>
                            <div class="tl-title">Follow-up done</div>
                        <?php elseif ($t['activity_type'] === 'created'): ?>
                            <div class="tl-title">Lead created</div>
                        <?php elseif ($t['activity_type'] === 'reassigned'): ?>
                            <div class="tl-title">Reassigned</div>
                        <?php endif; ?>
                        <?php if ($t['remark']): ?><div class="tl-remark"><?php echo nl2br(e($t['remark'])); ?></div><?php endif; ?>
                        <div class="tl-meta">
                            <i class="fas fa-user"></i> <?php echo e($t['performed_by'] ?: '-'); ?>
                            · <i class="fas fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($t['performed_at'])); ?>
                            <?php if ($t['followup_date']): ?> · next: <?php echo date('d M Y', strtotime($t['followup_date'])); ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.lead-timeline { position: relative; }
.tl-item { display: flex; gap: 12px; padding-bottom: 16px; position: relative; }
.tl-item:not(:last-child)::before { content: ''; position: absolute; left: 13px; top: 28px; bottom: 0; width: 2px; background: var(--border); }
.tl-dot { width: 28px; height: 28px; border-radius: 50%; background: var(--card); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: .72rem; flex-shrink: 0; z-index: 1; }
.tl-body { flex: 1; }
.tl-title { font-size: .82rem; font-weight: 700; margin-bottom: 3px; }
.tl-remark { font-size: .84rem; color: var(--foreground); background: var(--card); border-radius: 8px; padding: 7px 11px; margin: 4px 0; line-height: 1.5; }
.tl-meta { font-size: .72rem; color: var(--muted-foreground); }
.tl-meta i { margin-right: 2px; }
@media (max-width: 900px) { .lead-grid { grid-template-columns: 1fr !important; } }
</style>

<?php
$extra_scripts = "<script>
function toggleFU() {
    var s = document.getElementById('status-sel').value;
    var closed = ['converted','rejected','not_interested'].indexOf(s) !== -1;
    document.getElementById('fu-date').required = !closed;
    var r = document.getElementById('fu-req'); if (r) r.style.display = closed ? 'none' : 'inline';
}
toggleFU();
</script>";
include 'includes/admin_footer.php';
?>
