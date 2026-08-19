<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('whatsapp');
if (file_exists(__DIR__ . '/includes/template_helper.php')) {
    require_once __DIR__ . '/includes/template_helper.php';
}
if (!function_exists('fill_student_template')) {
    function fill_student_template($pdo, $template, $student) { return $template; } // helper file missing - send as typed
}

/* Direct WhatsApp messaging - same simple approach as the onboarding page:
   type (or prefill) a message, one click opens WhatsApp with it ready to
   send, and the message is logged to whatsapp_notifications.
   The old version only "logged" the message and never actually opened
   WhatsApp; it also depended on session hand-offs from payment-review.php.
   Prefill now works with plain GET parameters: ?phone=...&name=...&message=...
   (links from payment-review.php and elsewhere). */

$success_message = '';
$error_message   = '';
$whatsapp_url    = '';

// Prefill: GET params take priority; legacy session hand-off still honoured
$prefill_phone   = trim($_GET['phone'] ?? '');
$prefill_name    = trim($_GET['name'] ?? '');
$prefill_message = trim($_GET['message'] ?? '');
if (!$prefill_phone && !empty($_SESSION['whatsapp_notification'])) {
    $legacy = $_SESSION['whatsapp_notification'];
    $prefill_phone   = $legacy['phone'] ?? '';
    $prefill_name    = $legacy['student_name'] ?? '';
    $prefill_message = $legacy['message'] ?? '';
    unset($_SESSION['whatsapp_notification']);
}

/* ── POST: log + build wa.me link ───────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $phone   = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        $name    = trim($_POST['student_name'] ?? '');
        $message = trim($_POST['message'] ?? '');
        // Auto-fetch ALL {variables} from the database. Works via the picked
        // student id, and falls back to matching the WhatsApp number, so it
        // fills even when the phone was typed manually.
        if (strpos($message, '{') !== false) {
            try {
                $fill_uid = trim($_POST['picked_user_id'] ?? '');
                if ($fill_uid === '' && $phone !== '') {
                    $last10 = substr($phone, -10);
                    $stmt = $pdo->prepare("
                        SELECT user_id FROM users
                        WHERE RIGHT(REPLACE(REPLACE(whatsapp_number, ' ', ''), '-', ''), 10) = ?
                        ORDER BY (status = 'approved') DESC, created_at DESC LIMIT 1
                    ");
                    $stmt->execute([$last10]);
                    $fill_uid = (string)$stmt->fetchColumn();
                }
                if ($fill_uid !== '') {
                    $message = fill_student_template($pdo, $message, $fill_uid);
                }
            } catch (Exception $e) { error_log('WA template fill: ' . $e->getMessage()); }
        }

        if (strlen($phone) < 10) {
            $error_message = 'Please enter a valid phone number (with country code, e.g. 91XXXXXXXXXX).';
        } elseif ($message === '') {
            $error_message = 'Please write the message.';
        } else {
            // 10-digit Indian numbers → add 91 automatically
            if (strlen($phone) === 10) $phone = '91' . $phone;
            
            $dispatchMode = $_POST['dispatch_mode'] ?? 'meta_api';
            $uid = trim($_POST['picked_user_id'] ?? '');

            try {
                require_once 'includes/communication/CommunicationEngine.php';
                $engine = CommunicationEngine::getInstance($pdo);
                
                if ($dispatchMode === 'meta_api') {
                    // Queue for Meta API dispatch
                    $queueId = $engine->queueMessage(
                        'whatsapp',
                        $phone,
                        $name ?: null,
                        'Manual Admin Message',
                        $message,
                        $message,
                        [],
                        [],
                        $admin_username,
                        null,
                        $uid ?: null
                    );
                    $success_message = 'Message enqueued successfully for automated dispatch via Meta Cloud API!';
                } else {
                    // Legacy manual mode: log and build wa.me link
                    $lat = isset($_COOKIE['pepp_lat']) && is_numeric($_COOKIE['pepp_lat']) ? (float)$_COOKIE['pepp_lat'] : null;
                    $lng = isset($_COOKIE['pepp_lng']) && is_numeric($_COOKIE['pepp_lng']) ? (float)$_COOKIE['pepp_lng'] : null;
                    $meta = $_COOKIE['pepp_meta'] ?? null;
                    $stmt = $pdo->prepare("INSERT INTO whatsapp_notifications (phone, message, student_name, sent_by, status, latitude, longitude, metadata) VALUES (?, ?, ?, ?, 'sent', ?, ?, ?)");
                    $stmt->execute([substr($phone, -15), $message, $name ?: null, $admin_username, $lat, $lng, $meta]);
                    $whatsapp_url = 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);
                    $success_message = 'Message logged - WhatsApp is opening in a new tab.' . ($name ? " (to {$name})" : '');
                }
            } catch (Exception $e) {
                error_log('wa log: ' . $e->getMessage());
                $error_message = 'Failed to send message: ' . $e->getMessage();
            }
            // keep values so the admin can resend / adjust
            $prefill_phone = $phone; $prefill_name = $name; $prefill_message = $message;
        }
    }
}

/* ── Quick-pick students + templates + recent log ───────────────── */
try {
    $students = $pdo->query("
        SELECT user_id, name, whatsapp_country_code, whatsapp_number, pepp_course
        FROM users WHERE status = 'approved'
        ORDER BY created_at DESC LIMIT 300
    ")->fetchAll();
} catch (Exception $e) { $students = []; }

try {
    $templates = [];
    foreach ($pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE '%message%'")->fetchAll() as $row) {
        $templates[$row['setting_name']] = $row['setting_value'];
    }
} catch (Exception $e) { $templates = []; }

try {
    $recent = $pdo->query("SELECT * FROM whatsapp_notifications ORDER BY created_at DESC LIMIT 20")->fetchAll();
} catch (Exception $e) { $recent = []; }

$template_labels = [
    'onboarding_wp_message'         => 'Welcome / Onboarding',
    'approval_confirmation_message' => 'Approval confirmation',
    'approval_app_access_message'   => 'App access',
    'user_rejection_wp_message'     => 'Application rejected',
    'reg_entry_cancelling_message'  => 'Registration cancelled',
    'installment_reminder_message'  => 'Installment Payment Reminder',
];

$active_page = 'whatsapp';
$page_title  = 'Manual WP Log';
$page_sub    = 'Send a direct message and keep the log';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?>
    <div class="alert alert-success"><i class="fas fa-circle-check"></i>
        <span><?php echo e($success_message); ?>
        <?php if ($whatsapp_url): ?>
            <a class="btn btn-sm btn-whatsapp" href="<?php echo e($whatsapp_url); ?>" target="_blank" style="margin-left:8px;"><i class="fab fa-whatsapp"></i> Open WhatsApp again</a>
        <?php endif; ?>
        </span>
    </div>
<?php endif; ?>
<?php if ($error_message): ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<!-- ── COMPOSE ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:#dcfce7;color:#15803d;"><i class="fab fa-whatsapp"></i></span>
        <h2>Compose Message</h2>
        <div class="head-right"><span class="badge gray">Templates editable in <a href="settings.php">Settings</a></span></div>
    </div>
    <div class="panel-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-grid">
                <div class="field full">
                    <label>Quick-pick student (optional)</label>
                    <select id="student-pick" onchange="pickStudent(this)">
                        <option value="">- Type details manually or pick a student -</option>
                        <?php foreach ($students as $s):
                            $p = preg_replace('/\D/', '', $s['whatsapp_country_code'] . $s['whatsapp_number']); ?>
                            <option value="<?php echo e($p); ?>" data-name="<?php echo e($s['name']); ?>" data-course="<?php echo e($s['pepp_course']); ?>" data-uid="<?php echo e($s['user_id']); ?>">
                                <?php echo e($s['name']); ?> - <?php echo e($s['user_id']); ?> (<?php echo e($s['pepp_course']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="picked_user_id" id="wa-uid" value="">
                <div class="field">
                    <label>Phone (with country code) <span class="req">*</span></label>
                    <input type="text" name="phone" id="wa-phone" value="<?php echo e($prefill_phone); ?>" placeholder="91XXXXXXXXXX" required>
                    <div class="help">10-digit numbers get +91 automatically</div>
                </div>
                <div class="field">
                    <label>Student name</label>
                    <input type="text" name="student_name" id="wa-name" value="<?php echo e($prefill_name); ?>" placeholder="For the log">
                </div>
                <div class="field">
                    <label>Dispatch Mode</label>
                    <select name="dispatch_mode" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                        <option value="meta_api" selected>Queue via Meta API (Automated)</option>
                        <option value="wa_web">Open WhatsApp Web (Manual)</option>
                    </select>
                </div>
                <div class="field full">
                    <label>Template (optional)</label>
                    <select id="template-pick" onchange="pickTemplate(this)">
                        <option value="">- Start from a template -</option>
                        <?php foreach ($template_labels as $key => $label): if (!empty($templates[$key])): ?>
                            <option value="<?php echo e($templates[$key]); ?>"><?php echo e($label); ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <div class="field full">
                    <label>Message <span class="req">*</span></label>
                    <textarea name="message" id="wa-message" rows="5" required placeholder="Type the message…"><?php echo e($prefill_message); ?></textarea>
                    <div class="help">All {variables} ({name}, {PEPP course}, {access_end}, {balance}, {collected}…) are auto-fetched from the database when a student is picked - see Settings for the full list</div>
                </div>
            </div>
             <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                 <button type="submit" class="btn btn-whatsapp"><i class="fab fa-whatsapp"></i> Send / Queue Message</button>
             </div>
        </form>
    </div>
</div>

<!-- ── RECENT LOG ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--card);color:var(--secondary);"><i class="fas fa-clock-rotate-left"></i></span><h2>Recent Messages</h2></div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($recent)): ?>
            <div class="empty-state"><i class="fab fa-whatsapp"></i><p>No messages logged yet.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>When</th><th>Student</th><th>Phone</th><th>Message</th><th>By</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recent as $n): ?>
                <tr>
                    <td class="cell-sub" style="white-space:nowrap;"><?php echo date('d M, h:i A', strtotime($n['created_at'])); ?></td>
                    <td class="cell-main"><?php echo e($n['student_name'] ?: '-'); ?></td>
                    <td class="cell-sub"><?php echo e($n['phone']); ?></td>
                    <td class="cell-sub" style="max-width:380px;"><?php echo e(mb_strimwidth($n['message'], 0, 110, '…')); ?></td>
                    <td class="cell-sub"><?php echo e($n['sent_by']); ?></td>
                    <td>
                        <?php if (is_credential_restricted('students') && !can_admin_whatsapp_chat()): ?>
                            <a class="btn btn-sm btn-whatsapp" href="javascript:void(0)" onclick="alert('Access to student WhatsApp chat is restricted.')" style="opacity:0.6; cursor:not-allowed;" title="WhatsApp chat denied"><i class="fab fa-whatsapp"></i></a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-whatsapp" target="_blank" href="https://wa.me/<?php echo e(preg_replace('/\D/', '', $n['phone'])); ?>?text=<?php echo rawurlencode($n['message']); ?>" title="Resend"><i class="fab fa-whatsapp"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php
$extra_scripts = "<script>
let picked = null;
function pickStudent(sel) {
    const o = sel.options[sel.selectedIndex];
    if (!o.value) { picked = null; document.getElementById('wa-uid').value = ''; return; }
    picked = { name: o.dataset.name, course: o.dataset.course, uid: o.dataset.uid };
    document.getElementById('wa-uid').value = o.dataset.uid;
    document.getElementById('wa-phone').value = o.value;
    document.getElementById('wa-name').value = o.dataset.name;
    fillPlaceholders();
}
function pickTemplate(sel) {
    if (!sel.value) return;
    document.getElementById('wa-message').value = sel.value;
    fillPlaceholders();
    sel.selectedIndex = 0;
}
function fillPlaceholders() {
    if (!picked) return;
    const box = document.getElementById('wa-message');
    box.value = box.value
        .split('{name}').join(picked.name)
        .split('{PEPP course}').join(picked.course)
        .split('{pepp_course}').join(picked.course)
        .split('{user_id}').join(picked.uid);
}
" . ($whatsapp_url ? "window.open(" . json_encode($whatsapp_url) . ", '_blank');" : "") . "
</script>";
include 'includes/admin_footer.php';
?>
