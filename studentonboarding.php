<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('onboarding');

/* Onboarding queue: approved students who haven't completed onboarding.
   Data arrives here automatically once a registration is approved in
   student-approval.php. */

// ── Template variables auto-fetched from the database ────────────
if (file_exists(__DIR__ . '/includes/template_helper.php')) {
    require_once __DIR__ . '/includes/template_helper.php';
}
if (!function_exists('fill_student_template')) {
    function fill_student_template($pdo, $template, $student) { return $template; }
}

// ── AJAX: complete onboarding / fetch message link ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch. Refresh and try again.']);
        exit;
    }

    $action  = $_POST['action'] ?? '';
    $user_id = trim($_POST['user_id'] ?? '');

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND status = 'approved'");
        $stmt->execute([$user_id]);
        $student = $stmt->fetch();
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Approved student not found.']);
            exit;
        }

        if ($action === 'complete_onboarding') {
            $app  = ($_POST['app_access_provided'] ?? '') === 'Yes' ? 'Yes' : 'No';
            $sav  = ($_POST['saved_to_contacts'] ?? '') === 'Yes' ? 'Yes' : 'No';
            $wa   = ($_POST['added_whatsapp_groups'] ?? '') === 'Yes' ? 'Yes' : 'No';
            $sem  = ($_POST['semester_guide_provided'] ?? '') === 'Yes' ? 'Yes' : 'No';

            $pdo->beginTransaction();

            // Upsert the onboarding record
            $stmt = $pdo->prepare("SELECT id FROM student_onboarding WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("
                    UPDATE student_onboarding SET
                        app_access_provided = ?, saved_to_contacts = ?, added_whatsapp_groups = ?,
                        semester_guide_provided = ?, onboarded_by = ?, onboarded_at = NOW(), updated_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$app, $sav, $wa, $sem, $admin_username, $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO student_onboarding
                        (user_id, app_access_provided, saved_to_contacts, added_whatsapp_groups, semester_guide_provided, onboarded_by, onboarded_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ");
                $stmt->execute([$user_id, $app, $sav, $wa, $sem, $admin_username]);
            }

            // Mark complete on the user + reflect app access
            $stmt = $pdo->prepare("UPDATE users SET onboarding_status = 'completed', course_access_provided = ? WHERE user_id = ?");
            $stmt->execute([$app === 'Yes' ? 'yes' : 'no', $user_id]);

            // Referral: credit earnings now that onboarding is complete
            if (file_exists(__DIR__ . '/includes/referral_helper.php')) {
                require_once __DIR__ . '/includes/referral_helper.php';
                try { credit_referral_for_user($pdo, $user_id); } catch (Exception $e) { error_log('ref credit (onboarding): ' . $e->getMessage()); }
            }

            $pdo->commit();

            status_log($pdo, $user_id, 'pending', 'onboarded', 'Onboarding checklist completed', $admin_username);
            track_record($pdo, $user_id, 'onboarding_completed',
                "App access: $app, Contacts: $sav, WhatsApp groups: $wa, Semester guide: $sem", $admin_username);

            echo json_encode(['success' => true, 'message' => 'Onboarding completed for ' . $student['name'] . '.']);
            exit;
        }

        if ($action === 'get_message') {
            // Builds a wa.me link from a stored template
            $type = $_POST['type'] ?? '';
            $allowed = [
                'welcome'      => 'onboarding_wp_message',
                'confirmation' => 'approval_confirmation_message',
                'app_access'   => 'approval_app_access_message',
            ];
            if (!isset($allowed[$type])) {
                echo json_encode(['success' => false, 'message' => 'Unknown message type.']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = ?");
            $stmt->execute([$allowed[$type]]);
            $template = $stmt->fetchColumn();
            if (!$template) {
                echo json_encode(['success' => false, 'message' => 'Template not configured. Set it in Settings.']);
                exit;
            }
            $msg   = fill_student_template($pdo, $template, $student);
            $phone = preg_replace('/\D/', '', $student['whatsapp_country_code'] . $student['whatsapp_number']);

            // Queue onboarding notification via Centralized Communication Engine
            try {
                require_once 'includes/communication/CommunicationEngine.php';
                $engine = CommunicationEngine::getInstance($pdo);
                
                $context = [
                    'student_uid' => $student['user_id'],
                    'student_name' => $student['name'] ?? '',
                    'application_id' => $student['user_id'],
                    'course_name' => $student['pepp_course'] ?? ''
                ];
                
                $qId = $engine->sendEventNotification('student_registration', $phone, $context, $admin_username);
                if (!$qId) {
                    // Fallback to manual text message
                    $engine->queueMessage(
                        'whatsapp',
                        $phone,
                        $student['name'],
                        'Student Onboarding: ' . ucfirst($type),
                        $msg,
                        $msg,
                        [],
                        [],
                        $admin_username,
                        null,
                        $student['user_id']
                    );
                }
            } catch (Exception $ex) { error_log('wa log: ' . $ex->getMessage()); }

            echo json_encode([
                'success' => true,
                'url' => 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg)
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Onboarding action: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error while processing the request.']);
        exit;
    }
}

// ── PAGE DATA ────────────────────────────────────────────────────
$students = [];
$load_error = '';
try {
    $students = $pdo->query("
        SELECT u.user_id, u.name, u.email, u.whatsapp_country_code, u.whatsapp_number,
               u.pepp_course, u.pepp_academic_year, u.approval_date, u.course_duration_date, u.paid_amount, u.user_photo,
               so.app_access_provided, so.saved_to_contacts, so.added_whatsapp_groups, so.semester_guide_provided
        FROM users u
        LEFT JOIN student_onboarding so ON so.user_id = u.user_id
        WHERE u.status = 'approved'
          AND (u.onboarding_status IS NULL OR u.onboarding_status <> 'completed')
        ORDER BY u.approval_date DESC, u.created_at DESC
    ")->fetchAll();
} catch (Exception $e) {
    error_log('Onboarding list: ' . $e->getMessage());
    $load_error = 'Could not load the onboarding queue.';
}

$completed_count = 0;
try {
    $completed_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'approved' AND onboarding_status = 'completed'")->fetchColumn();
} catch (Exception $e) {}

$active_page = 'onboarding';
$page_title  = 'Student Onboarding';
$page_sub    = 'Welcome newly approved students and complete the checklist';
include 'includes/admin_nav.php';
?>

<?php if ($load_error): ?>
    <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($load_error); ?></span></div>
<?php endif; ?>
<div id="flash" style="display:none;"></div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Awaiting Onboarding</span><span class="stat-icon teal"><i class="fas fa-handshake"></i></span></div>
        <div class="stat-value"><?php echo count($students); ?></div>
        <div class="stat-hint">Approved, not yet onboarded</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Onboarded</span><span class="stat-icon green"><i class="fas fa-circle-check"></i></span></div>
        <div class="stat-value"><?php echo $completed_count; ?></div>
        <div class="stat-hint">Checklist completed</div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--teal-soft);color:var(--teal-ink);"><i class="fas fa-clipboard-check"></i></span>
        <h2>Onboarding Queue</h2>
        <div class="head-right"><span class="badge gray">Templates editable in <a href="settings.php">Settings</a></span></div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($students)): ?>
            <div class="empty-state"><i class="fas fa-mug-hot"></i><p>Nobody is waiting - every approved student is onboarded.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr>
                <th>Student</th><th>Course</th><th>Approved</th><th>WhatsApp Messages</th><th style="text-align:right;">Onboard</th>
            </tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <?php
                            $photo = $s['user_photo'] ?: 'assets/img/default-avatar.svg';
                            ?>
                            <img src="<?php echo e($photo); ?>" onerror="this.src='assets/img/default-avatar.svg'; this.onerror=null;" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:1px solid var(--border);" alt="Avatar">
                            <div>
                                <div class="cell-main"><?php echo e($s['name']); ?></div>
                                <div class="cell-sub"><?php echo format_credential($s['email'], 'email'); ?> &middot; <?php echo e($s['whatsapp_country_code']); ?> <?php echo format_credential($s['whatsapp_number'], 'phone'); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:.82rem;font-weight:600;"><?php echo e($s['pepp_course']); ?></div>
                        <div class="cell-sub"><?php echo e($s['pepp_academic_year']); ?> &middot; Access End: <?php echo $s['course_duration_date'] ? date('d M Y', strtotime($s['course_duration_date'])) : 'Not Set'; ?> &middot; Reg: ₹<?php echo number_format((float)$s['paid_amount'], 2); ?></div>
                    </td>
                    <td class="cell-sub"><?php echo $s['approval_date'] ? date('d M Y', strtotime($s['approval_date'])) : '-'; ?></td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm btn-whatsapp" onclick="sendMessage('<?php echo e($s['user_id']); ?>', 'welcome')"><i class="fab fa-whatsapp"></i> Welcome</button>
                        <button class="btn btn-sm btn-soft-green" onclick="sendMessage('<?php echo e($s['user_id']); ?>', 'confirmation')"><i class="fas fa-circle-check"></i> Confirmation</button>
                        <button class="btn btn-sm btn-soft-blue" onclick="sendMessage('<?php echo e($s['user_id']); ?>', 'app_access')"><i class="fas fa-mobile-screen"></i> App Access</button>
                    </td>
                    <td style="text-align:right;">
                        <button class="btn btn-sm btn-primary" onclick='openOnboardModal(<?php echo json_encode([
                            "user_id" => $s["user_id"], "name" => $s["name"],
                            "app" => $s["app_access_provided"] ?? "No",
                            "contacts" => $s["saved_to_contacts"] ?? "No",
                            "groups" => $s["added_whatsapp_groups"] ?? "No",
                            "guide" => $s["semester_guide_provided"] ?? "No",
                        ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fas fa-list-check"></i> Checklist</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── CHECKLIST MODAL ── -->
<div class="modal-backdrop" id="onboard-modal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-head">
            <h3><i class="fas fa-list-check" style="color:var(--teal);"></i> Onboarding Checklist</h3>
            <button class="modal-close" onclick="closeModal('onboard-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form id="onboard-form" onsubmit="return submitOnboarding(event)">
            <div class="modal-body">
                <input type="hidden" name="user_id" id="ob-user-id">
                <p id="ob-name" style="font-weight:700; margin-bottom:14px;"></p>
                <?php
                $checks = [
                    'app_access_provided'    => ['App access provided', 'fa-mobile-screen'],
                    'saved_to_contacts'      => ['Saved to contacts', 'fa-address-book'],
                    'added_whatsapp_groups'  => ['Added to WhatsApp groups', 'fa-users'],
                    'semester_guide_provided'=> ['Semester guide shared', 'fa-book'],
                ];
                foreach ($checks as $key => [$label, $icon]): ?>
                <label style="display:flex; align-items:center; gap:12px; padding:11px 14px; border:1.5px solid var(--border); border-radius:11px; margin-bottom:9px; cursor:pointer; font-weight:600; font-size:.875rem;">
                    <input type="checkbox" name="<?php echo $key; ?>" value="Yes" id="ob-<?php echo $key; ?>" style="width:17px;height:17px;accent-color:var(--accent);">
                    <i class="fas <?php echo $icon; ?>" style="color:var(--secondary); width:18px;"></i>
                    <?php echo $label; ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('onboard-modal')">Cancel</button>
                <button type="submit" class="btn btn-success" id="ob-submit"><i class="fas fa-check"></i> Mark Onboarded</button>
            </div>
        </form>
    </div>
</div>

<?php
$extra_scripts = "<script>
const CSRF = " . json_encode(csrf_token()) . ";

function flash(msg, ok) {
    const f = document.getElementById('flash');
    f.style.display = 'block';
    f.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
    f.innerHTML = '<i class=\"fas fa-' + (ok ? 'circle-check' : 'triangle-exclamation') + '\"></i><span>' + msg + '</span>';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function post(data) {
    data.csrf_token = CSRF;
    return fetch('studentonboarding.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data)
    }).then(r => r.json());
}
function sendMessage(userId, type) {
    post({ action: 'get_message', user_id: userId, type: type }).then(res => {
        if (res.success) { window.open(res.url, '_blank'); }
        else { flash(res.message || 'Could not build the message.', false); }
    }).catch(() => flash('Network error.', false));
}
function openOnboardModal(s) {
    document.getElementById('ob-user-id').value = s.user_id;
    document.getElementById('ob-name').textContent = s.name + ' (' + s.user_id + ')';
    document.getElementById('ob-app_access_provided').checked     = s.app === 'Yes';
    document.getElementById('ob-saved_to_contacts').checked       = s.contacts === 'Yes';
    document.getElementById('ob-added_whatsapp_groups').checked   = s.groups === 'Yes';
    document.getElementById('ob-semester_guide_provided').checked = s.guide === 'Yes';
    openModal('onboard-modal');
}
function submitOnboarding(ev) {
    ev.preventDefault();
    const btn = document.getElementById('ob-submit');
    btn.disabled = true;
    const data = { action: 'complete_onboarding' };
    new FormData(document.getElementById('onboard-form')).forEach((v, k) => data[k] = v);
    post(data).then(res => {
        flash(res.message, res.success);
        if (res.success) { closeModal('onboard-modal'); setTimeout(() => location.reload(), 900); }
    }).catch(() => flash('Network error.', false))
      .finally(() => btn.disabled = false);
    return false;
}
</script>";
include 'includes/admin_footer.php';
?>
