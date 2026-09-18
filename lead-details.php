<?php
require_once 'includes/auth.php';
require_permission('leads');
require_once 'includes/lead_helper.php';

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

ensure_lead_converted_by_column($pdo);

$all_admins = [];
$all_admin_names = [];
if (admins_table_exists($pdo)) {
    try {
        $all_admins = $pdo->query("SELECT username, full_name FROM admins WHERE username IS NOT NULL AND TRIM(username) <> '' ORDER BY full_name ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all_admins as $adm) {
            $all_admin_names[$adm['username']] = !empty($adm['full_name']) ? $adm['full_name'] : $adm['username'];
        }
    } catch (Exception $e) {}
}

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
                    $whatsapp_number = trim($_POST['whatsapp_number'] ?? $lead['whatsapp_number']);
                    if (is_credential_restricted('leads')) {
                        if (strpos($whatsapp_number, '*') !== false || preg_match('/^[x\s@.]+$/i', $whatsapp_number) || strpos($whatsapp_number, '<span') !== false) {
                            $whatsapp_number = $lead['whatsapp_number'];
                        }
                    }
                    $course = trim($_POST['interested_course'] ?? $lead['interested_course']);
                    $inst = trim($_POST['last_institute'] ?? $lead['last_institute']);
                    $lcourse = trim($_POST['last_course'] ?? $lead['last_course']);
                    $fy = in_array($_POST['is_fyugp'] ?? '', ['yes', 'no'], true) ? $_POST['is_fyugp'] : null;
                    $yr = in_array($_POST['year_of_study'] ?? '', $YEARS, true) ? $_POST['year_of_study'] : null;
                    $assigned = (is_super_admin() && !empty($_POST['assigned_to'])) ? $_POST['assigned_to'] : $lead['assigned_to'];

                    $is_opted_out = isset($_POST['is_opted_out']) ? 1 : 0;
                    $inc = $is_followup ? 1 : 0;
                    $stmt = $pdo->prepare("
                        UPDATE leads SET name=?, whatsapp_number=?, interested_course=?, last_institute=?, last_course=?, is_fyugp=?, year_of_study=?,
                            status=?, is_opted_out=?, next_followup_date=?, assigned_to=?, followup_count = followup_count + ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $whatsapp_number, $course, $inst, $lcourse, $fy, $yr, $new_status, $is_opted_out,
                        $closed ? ($followup ?: null) : $followup, $assigned, $inc, $lead_id]);

                    if ($is_opted_out != $lead['is_opted_out']) {
                        $logMsg = $is_opted_out ? "WhatsApp Marketing: Lead opted out." : "WhatsApp Marketing: Lead opted back in.";
                        lead_log($pdo, $lead_id, 'details_change', $logMsg, null, null, null, $admin_username);
                    }

                    // Timeline entries
                    if ($whatsapp_number !== $lead['whatsapp_number']) {
                        lead_log($pdo, $lead_id, 'details_change', "WhatsApp number updated: {$lead['whatsapp_number']} → {$whatsapp_number}", null, null, null, $admin_username);
                    }
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
                $conv_by_input = trim($_POST['converted_by'] ?? '');

                // Check if matched student is alumni referral
                $is_alumni = false;
                if ($uid) {
                    $sRow = null;
                    try {
                        $sStmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
                        $sStmt->execute([$uid]);
                        $sRow = $sStmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {}
                    $is_alumni = is_alumni_referral_student($pdo, $uid, $sRow ?: []);
                }

                $resolved_by = resolveLeadConversionAttribution($pdo, $is_alumni, $conv_by_input, false);
                if (!$resolved_by) {
                    $resolved_by = resolveLeadConversionAttribution($pdo, $is_alumni, $admin_username, false);
                }

                $pdo->prepare("UPDATE leads SET status = 'converted', converted_user_id = ?, converted_by = ?, next_followup_date = NULL, updated_at = NOW() WHERE id = ?")
                    ->execute([$uid ?: null, $resolved_by, $lead_id]);

                $updater_mobile = get_admin_mobile($pdo, $admin_username);
                $phoneInfo = $updater_mobile ? " (Mobile: {$updater_mobile})" : "";

                lead_log($pdo, $lead_id, 'status_change', $uid ? "Converted - linked to student {$uid} (Attributed to: {$resolved_by}) by {$admin_username}{$phoneInfo}" : "Marked as converted (Attributed to: {$resolved_by}) by {$admin_username}{$phoneInfo}", $lead['status'], 'converted', null, $admin_username);
                log_admin_activity($pdo, $admin_username, 'lead_converted', "Lead #{$lead_id} converted (Attributed to: {$resolved_by}) by {$admin_username}{$phoneInfo}" . ($uid ? " → {$uid}" : ''));
                $success_message = 'Lead marked as converted.';
                $lead = load_lead($pdo, $lead_id);
            } elseif ($action === 'change_converted_by') {
                if (!is_super_admin()) {
                    $error_message = 'Only the Super Admin can change conversion attribution.';
                } else {
                    $new_converted_by = trim($_POST['converted_by'] ?? '');
                    $current_converted_by = $lead['converted_by'] ?? '';
                    if ($current_converted_by === 'alumni_referral') {
                        $error_message = 'Alumni referral conversions are permanently locked and cannot be changed.';
                    } else {
                        $resolved = resolveLeadConversionAttribution($pdo, false, $new_converted_by, false);
                        if (!$resolved) {
                            $error_message = 'Invalid conversion attribution selected.';
                        } else {
                            $stmt = $pdo->prepare("UPDATE leads SET converted_by = ?, updated_at = NOW() WHERE id = ?");
                            $stmt->execute([$resolved, $lead_id]);

                            $updater_mobile = get_admin_mobile($pdo, $admin_username);
                            $phoneInfo = $updater_mobile ? " (Mobile: {$updater_mobile})" : "";
                            $oldLabel = $current_converted_by ?: 'none';
                            $newLabel = $resolved;

                            $logMsg = "Converted By changed from {$oldLabel} to {$newLabel} by {$admin_username}{$phoneInfo}";
                            lead_log($pdo, $lead_id, 'converted_by_changed', $logMsg, null, null, null, $admin_username);
                            log_admin_activity($pdo, $admin_username, 'lead_converted_by_updated', "Lead #{$lead_id} attribution updated: {$oldLabel} -> {$newLabel}{$phoneInfo}");

                            $success_message = "Conversion attribution updated to '{$newLabel}'.";
                            $lead = load_lead($pdo, $lead_id);
                        }
                    }
                }
            } elseif ($action === 'quick_update_followup') {
                // Non-super admins may only edit leads assigned to them or __ALL__ (Correction 7)
                if (!is_super_admin() && $lead['assigned_to'] !== $admin_username && $lead['assigned_to'] !== '__ALL__') {
                    $error_message = 'You do not have permission to update this lead.';
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                        header('Content-Type: application/json; charset=UTF-8');
                        http_response_code(403);
                        echo json_encode(['success' => false, 'error' => $error_message]);
                        exit();
                    }
                } else {
                    $raw_followup = isset($_POST['next_followup_date']) ? trim((string)$_POST['next_followup_date']) : '';
                    $parsed_followup = parse_lead_followup_date($raw_followup);
                    $is_closed = in_array($lead['status'], $CLOSED, true);

                    if ($raw_followup !== '' && $parsed_followup === false) {
                        $error_message = 'Invalid follow-up date format. Please use a valid calendar date.';
                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                            header('Content-Type: application/json; charset=UTF-8');
                            http_response_code(422);
                            echo json_encode(['success' => false, 'error' => $error_message]);
                            exit();
                        }
                    } elseif (!$is_closed && empty($parsed_followup)) {
                        $error_message = 'A next follow-up date is required until the lead is converted or rejected.';
                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                            header('Content-Type: application/json; charset=UTF-8');
                            http_response_code(422);
                            echo json_encode(['success' => false, 'error' => $error_message]);
                            exit();
                        }
                    } else {
                        $old_date = $lead['next_followup_date'];
                        $new_date = $parsed_followup;

                        if ($new_date !== $old_date) {
                            $pdo->prepare("UPDATE leads SET next_followup_date = ?, updated_at = NOW(), last_activity_at = NOW() WHERE id = ?")
                                ->execute([$new_date ?: null, $lead_id]);

                            $old_label = $old_date ? date('d M Y', strtotime($old_date)) : 'None';
                            $new_label = $new_date ? date('d M Y', strtotime($new_date)) : 'None';
                            $remark = "Follow-up date changed: {$old_label} → {$new_label}";

                            lead_log($pdo, $lead_id, 'followup', $remark, null, null, $new_date ?: null, $admin_username);
                            log_admin_activity($pdo, $admin_username, 'lead_followup_updated', "Lead #{$lead_id} follow-up date changed: {$old_label} → {$new_label}");
                        }

                        $lead = load_lead($pdo, $lead_id);
                        $overdue = $lead['next_followup_date'] && $lead['next_followup_date'] < date('Y-m-d') && !$is_closed;

                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                            $badge_html = '-';
                            if ($is_closed) {
                                $badge_html = '-';
                            } elseif ($lead['next_followup_date']) {
                                $badge_color = $overdue ? 'red' : 'amber';
                                $badge_text = date('d M Y', strtotime($lead['next_followup_date'])) . ($overdue ? ' · overdue' : '');
                                $badge_html = '<span class="badge ' . $badge_color . '">' . htmlspecialchars($badge_text) . '</span>';
                            }
                            header('Content-Type: application/json; charset=UTF-8');
                            echo json_encode([
                                'success' => true,
                                'message' => 'Next follow-up date updated.',
                                'lead_id' => $lead_id,
                                'next_followup_date' => $lead['next_followup_date'],
                                'formatted_date' => $lead['next_followup_date'] ? date('d M Y', strtotime($lead['next_followup_date'])) : '-',
                                'is_overdue' => $overdue,
                                'badge_html' => $badge_html
                            ]);
                            exit();
                        }
                        $success_message = 'Next follow-up date updated.';
                    }
                }
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
$page_sub    = format_credential_text($lead['whatsapp_number'], 'phone', 'leads');
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
                    <a class="btn btn-sm btn-outline" href="tel:<?php echo preg_replace('/\D/', '', $lead['whatsapp_number']); ?>"><i class="fas fa-phone"></i> Call</a>
                    <a class="btn btn-sm btn-whatsapp" href="<?php echo e(wa_link($lead['whatsapp_number'])); ?>" target="_blank"><i class="fab fa-whatsapp"></i> Chat</a>
                </div>
            </div>
            <div class="panel-body">
                <div class="detail-list" style="margin-bottom:14px;">
                    <div class="detail-row"><div class="dl">WhatsApp</div><div class="dv"><?php echo format_credential($lead['whatsapp_number'], 'phone', 'leads'); ?></div></div>
                    <div class="detail-row"><div class="dl">Follow-ups done</div><div class="dv"><?php echo (int)$lead['followup_count']; ?></div></div>
                    <div class="detail-row"><div class="dl">Next follow-up</div><div class="dv" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span id="top-fu-display">
                            <?php if ($is_closed): ?>-
                            <?php elseif ($lead['next_followup_date']): ?>
                                <span class="badge <?php echo $overdue ? 'red' : 'amber'; ?>"><?php echo date('d M Y', strtotime($lead['next_followup_date'])); ?><?php echo $overdue ? ' · overdue' : ''; ?></span>
                            <?php else: ?>-<?php endif; ?>
                        </span>
                        <?php if (!$is_closed): ?>
                            <button type="button" class="btn btn-sm btn-outline" onclick="openQuickFollowupModal()" title="Edit next follow-up date" style="padding:2px 8px; font-size:0.75rem; border-radius:6px; display:inline-flex; align-items:center; gap:4px;">
                                <i class="fas fa-pen"></i> Edit
                            </button>
                        <?php endif; ?>
                    </div></div>
                    <div class="detail-row"><div class="dl">Assigned to</div><div class="dv"><?php echo $lead['assigned_to'] === '__ALL__' ? 'All Admins' : e($lead['assigned_to'] ?: '-'); ?></div></div>
                    <div class="detail-row"><div class="dl">Source</div><div class="dv"><?php echo e(ucfirst($lead['source'])); ?></div></div>
                    <div class="detail-row"><div class="dl">Created</div><div class="dv"><?php echo date('d M Y, h:i A', strtotime($lead['created_at'])); ?> by <?php echo e($lead['created_by'] ?: '-'); ?></div></div>
                    <?php if ($st === 'converted'):
                        $cbVal = $lead['converted_by'] ?? '';
                        $isAlum = ($cbVal === 'alumni_referral');
                    ?>
                    <div class="detail-row">
                        <div class="dl">Converted By</div>
                        <div class="dv" style="display:flex; align-items:center; gap:8px;">
                            <?php if ($isAlum): ?>
                                <span class="badge" style="background:#f3e8ff; color:#6b21a8; font-weight:700; border:1px solid #d8b4fe; padding:3px 8px;">
                                    <i class="fas fa-lock" style="font-size:0.75rem; margin-right:4px;"></i> Alumni Referral
                                </span>
                            <?php elseif ($cbVal === 'auto_converted'): ?>
                                <span class="badge" style="background:#f8fafc; color:#64748b; font-weight:600; border:1px solid #cbd5e1; padding:3px 8px;">
                                    Auto Converted
                                </span>
                            <?php else:
                                $aName = $all_admin_names[$cbVal] ?? null;
                                $aLabel = $aName ? "{$aName} ({$cbVal})" : ($cbVal ?: 'Unassigned');
                            ?>
                                <span class="badge" style="background:#ecfdf5; color:#065f46; font-weight:600; border:1px solid #a7f3d0; padding:3px 8px;">
                                    <?php echo e($aLabel); ?>
                                </span>
                            <?php endif; ?>

                            <?php if (is_super_admin() && !$isAlum): ?>
                                <button type="button" class="btn btn-sm btn-outline" onclick="openEditConvertedByModal()" style="padding:2px 8px; font-size:0.75rem;">
                                    <i class="fas fa-pen"></i> Edit
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($lead['converted_user_id']): ?>
                    <div class="detail-row"><div class="dl">Student</div><div class="dv"><a href="student-details.php?user_id=<?php echo urlencode($lead['converted_user_id']); ?>"><?php echo e($lead['converted_user_id']); ?></a></div></div>
                    <?php endif; ?>
                </div>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_lead">
                    <div class="form-grid">
                        <div class="field"><label>Name</label><input type="text" name="name" value="<?php echo e($lead['name']); ?>"></div>
                        <div class="field"><label>WhatsApp Number <span class="req">*</span></label><input type="text" name="whatsapp_number" value="<?php echo htmlspecialchars(format_credential_text($lead['whatsapp_number'], 'phone', 'leads'), ENT_QUOTES, 'UTF-8'); ?>" required></div>
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
                        <div class="field full" style="border-top:1px dashed #e2e8f0; padding-top:10px; margin-top:10px;">
                            <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;text-transform:none;color:#ef4444;letter-spacing:0;cursor:pointer;">
                                <input type="checkbox" name="is_opted_out" value="1" <?php echo !empty($lead['is_opted_out']) ? 'checked' : ''; ?> style="width:16px;height:16px;accent-color:#ef4444;"> Opt-out from WhatsApp marketing campaigns
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
                    <div class="field" style="margin-top:10px; margin-bottom:12px;">
                        <label>Converted By Attribution <span class="req">*</span></label>
                        <select name="converted_by" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.85rem;">
                            <option value="<?php echo e($admin_username); ?>" selected><?php echo e($all_admin_names[$admin_username] ?? $admin_username); ?> (Me)</option>
                            <option value="auto_converted">Auto Converted</option>
                            <?php foreach ($all_admins as $adm): if ($adm['username'] === $admin_username) continue; ?>
                                <option value="<?php echo e($adm['username']); ?>">
                                    <?php echo e(!empty($adm['full_name']) ? $adm['full_name'] . ' (' . $adm['username'] . ')' : $adm['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                    elseif ($t['activity_type'] === 'converted_by_changed') { $icon = 'fa-user-gear'; $color = 'var(--accent)'; }
                    elseif ($t['activity_type'] === 'contact_called') { $icon = 'fa-phone-volume'; $color = 'var(--green-ink)'; }
                    elseif ($t['activity_type'] === 'contact_texted') { $icon = 'fa-comment-dots'; $color = 'var(--blue-ink)'; }
                ?>
                <div class="tl-item">
                    <div class="tl-dot" style="color:<?php echo $color; ?>;"><i class="fas <?php echo $icon; ?>"></i></div>
                    <div class="tl-body">
                        <?php if ($t['activity_type'] === 'contact_called' || $t['activity_type'] === 'contact_texted'): ?>
                            <div class="tl-title" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                <span><?php echo $t['activity_type'] === 'contact_called' ? '<i class="fas fa-phone-volume" style="color:#16a34a;"></i> Called' : '<i class="fas fa-comment-dots" style="color:#2563eb;"></i> Texted'; ?></span>
                                <?php if ($t['old_status'] && $t['new_status']): ?>
                                    <span style="font-weight:400; color:var(--text-muted); font-size:0.8rem;">&middot; Status:</span>
                                    <span class="badge <?php echo $LEAD_STATUSES[$t['old_status']][1] ?? 'gray'; ?>"><?php echo $LEAD_STATUSES[$t['old_status']][0] ?? $t['old_status']; ?></span> &rarr;
                                    <span class="badge <?php echo $LEAD_STATUSES[$t['new_status']][1] ?? 'gray'; ?>"><?php echo $LEAD_STATUSES[$t['new_status']][0] ?? $t['new_status']; ?></span>
                                <?php elseif ($t['new_status']): ?>
                                    <span style="font-weight:400; color:var(--text-muted); font-size:0.8rem;">&middot; Status:</span>
                                    <span class="badge <?php echo $LEAD_STATUSES[$t['new_status']][1] ?? 'gray'; ?>"><?php echo $LEAD_STATUSES[$t['new_status']][0] ?? $t['new_status']; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($t['old_status'] || $t['new_status']): ?>
                            <div class="tl-title">
                                <?php if ($t['old_status']): ?><span class="badge <?php echo $LEAD_STATUSES[$t['old_status']][1] ?? 'gray'; ?>"><?php echo $LEAD_STATUSES[$t['old_status']][0] ?? $t['old_status']; ?></span> &rarr; <?php endif; ?>
                                <span class="badge <?php echo $LEAD_STATUSES[$t['new_status']][1] ?? 'gray'; ?>"><?php echo $LEAD_STATUSES[$t['new_status']][0] ?? $t['new_status']; ?></span>
                            </div>
                        <?php elseif ($t['activity_type'] === 'followup'): ?>
                            <div class="tl-title"><?php echo ($t['remark'] && stripos($t['remark'], 'Follow-up date') !== false) ? 'Follow-up date changed' : 'Follow-up done'; ?></div>
                        <?php elseif ($t['activity_type'] === 'created'): ?>
                            <div class="tl-title">Lead created</div>
                        <?php elseif ($t['activity_type'] === 'reassigned'): ?>
                            <div class="tl-title">Reassigned</div>
                        <?php elseif ($t['activity_type'] === 'converted_by_changed'): ?>
                            <div class="tl-title">Attribution Changed</div>
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

<?php if (is_super_admin()): ?>
<!-- ── SUPER ADMIN EDIT CONVERTED BY MODAL ── -->
<div id="edit-converted-by-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(2px);">
    <div style="background:#fff; border-radius:16px; width:100%; max-width:440px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); margin:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
            <h4 style="margin:0; font-size:1rem; font-weight:700; color:#1e293b;">
                <i class="fas fa-pen" style="color:var(--accent); margin-right:6px;"></i> Edit Converted By Attribution
            </h4>
            <button type="button" onclick="closeEditConvertedByModal()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="change_converted_by">

            <div style="margin-bottom:14px; font-size:0.85rem; color:#475569; background:#f8fafc; padding:8px 12px; border-radius:8px; border:1px solid #e2e8f0;">
                Lead: <strong style="color:#1e293b;"><?php echo e($lead['name'] ?: 'Lead #' . $lead['id']); ?></strong>
            </div>

            <div class="field" style="margin-bottom:16px;">
                <label style="font-weight:700; color:#1e293b;">Attributed To <span class="req">*</span></label>
                <select name="converted_by" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.88rem;">
                    <option value="auto_converted" <?php echo ($lead['converted_by'] ?? '') === 'auto_converted' ? 'selected' : ''; ?>>Auto Converted</option>
                    <?php foreach ($all_admins as $adm): ?>
                        <option value="<?php echo e($adm['username']); ?>" <?php echo ($lead['converted_by'] ?? '') === $adm['username'] ? 'selected' : ''; ?>>
                            <?php echo e(!empty($adm['full_name']) ? $adm['full_name'] . ' (' . $adm['username'] . ')' : $adm['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint" style="font-size:0.75rem; color:#64748b; margin-top:6px; display:block;">
                    Super Admin modification will be permanently logged with your name and mobile number.
                </span>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="closeEditConvertedByModal()" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Update Attribution</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_closed): ?>
<!-- ── QUICK EDIT NEXT FOLLOW-UP MODAL ── -->
<div id="quick-followup-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(2px);">
    <div style="background:#fff; border-radius:16px; width:100%; max-width:380px; padding:20px 24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); margin:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <h4 style="margin:0; font-size:0.95rem; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:6px;">
                <i class="fas fa-calendar-alt" style="color:var(--accent);"></i> Next Follow-up Date
            </h4>
            <button type="button" onclick="closeQuickFollowupModal()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#94a3b8; line-height:1;">&times;</button>
        </div>
        <form id="quick-followup-form" method="POST" onsubmit="submitQuickFollowup(event)" style="margin:0;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="quick_update_followup">
            <div style="margin-bottom:14px;">
                <label style="font-size:0.82rem; font-weight:700; color:#334155; margin-bottom:6px; display:block;">Follow-up Date <span class="req">*</span></label>
                <input type="date" name="next_followup_date" id="quick-fu-input" value="<?php echo e($lead['next_followup_date']); ?>" required style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.88rem;">
                <div id="quick-fu-error" style="display:none; font-size:0.78rem; color:#ef4444; margin-top:5px;"></div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="closeQuickFollowupModal()" class="btn btn-sm btn-outline">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary" id="quick-fu-submit-btn"><i class="fas fa-floppy-disk"></i> Save</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

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
ob_start();
?>
<script>
function toggleFU() {
    var s = document.getElementById('status-sel').value;
    var closed = ['converted','rejected','not_interested'].indexOf(s) !== -1;
    document.getElementById('fu-date').required = !closed;
    var r = document.getElementById('fu-req'); if (r) r.style.display = closed ? 'none' : 'inline';
}
toggleFU();

function openEditConvertedByModal() {
    var modal = document.getElementById('edit-converted-by-modal');
    if (modal) modal.style.display = 'flex';
}

function closeEditConvertedByModal() {
    var modal = document.getElementById('edit-converted-by-modal');
    if (modal) modal.style.display = 'none';
}

function openQuickFollowupModal() {
    var modal = document.getElementById('quick-followup-modal');
    var input = document.getElementById('quick-fu-input');
    var lowerInput = document.getElementById('fu-date');
    if (input && lowerInput) {
        input.value = lowerInput.value;
    }
    var err = document.getElementById('quick-fu-error');
    if (err) { err.style.display = 'none'; err.textContent = ''; }
    if (modal) modal.style.display = 'flex';
}

function closeQuickFollowupModal() {
    var modal = document.getElementById('quick-followup-modal');
    if (modal) modal.style.display = 'none';
}

function submitQuickFollowup(e) {
    e.preventDefault();
    var form = document.getElementById('quick-followup-form');
    var input = document.getElementById('quick-fu-input');
    var err = document.getElementById('quick-fu-error');
    var submitBtn = document.getElementById('quick-fu-submit-btn');

    if (!input || !input.value) {
        if (err) { err.textContent = 'Please select a date.'; err.style.display = 'block'; }
        return;
    }

    var fd = new FormData(form);
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }

    fetch('lead-details.php?id=<?php echo (int)$lead_id; ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save'; }
        if (data.success) {
            var topDisplay = document.getElementById('top-fu-display');
            if (topDisplay && data.badge_html) {
                topDisplay.innerHTML = data.badge_html;
            }
            var lowerInput = document.getElementById('fu-date');
            if (lowerInput && data.next_followup_date) {
                lowerInput.value = data.next_followup_date;
            }
            closeQuickFollowupModal();
        } else {
            if (err) {
                err.textContent = data.error || 'Failed to update follow-up date.';
                err.style.display = 'block';
            }
        }
    })
    .catch(function(error) {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save'; }
        form.submit();
    });
}
</script>
<?php
$extra_scripts = ob_get_clean();
include 'includes/admin_footer.php';
?>
