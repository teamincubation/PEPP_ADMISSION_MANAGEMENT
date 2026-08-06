<?php
require_once 'includes/auth.php';
require_permission('marketing');
require_once 'includes/email_campaigns_helper.php';

// Auto-run self-healing table check
check_and_create_email_campaign_tables($pdo);

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'CSRF validation failed. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_campaign') {
            $subject = trim($_POST['subject'] ?? '');
            $body = trim($_POST['body'] ?? '');
            $target_courses = $_POST['scope_course'] ?? [];
            $target_forms = $_POST['scope_form'] ?? [];
            $send_option = $_POST['send_option'] ?? 'instant';
            $scheduled_at = $_POST['scheduled_at'] ?? '';
            
            if (is_array($target_courses)) {
                $target_courses = array_filter(array_map('trim', $target_courses));
            } else {
                $target_courses = [];
            }
            
            if (is_array($target_forms)) {
                $target_forms = array_filter(array_map('intval', $target_forms));
            } else {
                $target_forms = [];
            }
            
            if ($subject === '' || $body === '' || (empty($target_courses) && empty($target_forms))) {
                $error_message = 'Subject, Body, and at least one Target Course or Target Form are required.';
            } else {
                $courses_str = implode(',', $target_courses);
                $forms_str = implode(',', $target_forms);
                $status = 'scheduled';
                $sched_time = null;
                
                if ($send_option === 'scheduled' && !empty($scheduled_at)) {
                    $ts = strtotime($scheduled_at);
                    if ($ts && $ts > time()) {
                        $sched_time = date('Y-m-d H:i:s', $ts);
                    } else {
                        $error_message = 'Scheduled time must be in the future.';
                    }
                }
                
                if ($error_message === '') {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO email_campaigns (subject, body, target_courses, target_forms, scheduled_at, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$subject, $body, $courses_str, $forms_str, $sched_time, $status, $admin_username]);
                        $campaign_id = $pdo->lastInsertId();
                        
                        log_admin_activity($pdo, $admin_username, 'email_campaign_created', "Created email campaign \"{$subject}\"");
                        
                        if ($send_option === 'instant') {
                            // Queue and send first batch immediately
                            email_campaigns_send_due($pdo);
                            $success_message = 'Email campaign created and queued for sending.';
                        } else {
                            $success_message = 'Email campaign successfully scheduled.';
                        }
                    } catch (Exception $e) {
                        $error_message = 'Failed to create campaign: ' . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'cancel_campaign') {
            $campaign_id = (int)($_POST['campaign_id'] ?? 0);
            try {
                $stmt = $pdo->prepare("SELECT * FROM email_campaigns WHERE id = ?");
                $stmt->execute([$campaign_id]);
                $camp = $stmt->fetch();
                if ($camp && $camp['status'] === 'scheduled') {
                    $pdo->prepare("UPDATE email_campaigns SET status = 'cancelled' WHERE id = ?")->execute([$campaign_id]);
                    log_admin_activity($pdo, $admin_username, 'email_campaign_cancelled', "Cancelled scheduled email campaign #{$campaign_id}");
                    $success_message = 'Campaign successfully cancelled.';
                } else {
                    $error_message = 'Campaign cannot be cancelled (it may have already started or been sent).';
                }
            } catch (Exception $e) {
                $error_message = 'Failed to cancel campaign: ' . $e->getMessage();
            }
        }
    }
}

// Fetch active courses for targeting
$courses = [];
try {
    $courses = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Fetch active forms for targeting that include an email address field
$active_forms = [];
try {
    $active_forms = $pdo->query("
        SELECT DISTINCT f.id, f.title 
        FROM campaign_forms f
        JOIN campaign_form_fields ff ON f.id = ff.form_id
        WHERE f.is_deleted = 0 
          AND (ff.type = 'email' OR ff.field_name LIKE '%email%' OR ff.label LIKE '%email%')
        ORDER BY f.title
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch campaign history with queue statistics
$campaigns = [];
try {
    $campaigns = $pdo->query("
        SELECT 
            ec.*,
            (SELECT COUNT(*) FROM email_queue WHERE campaign_id = ec.id) as total_queued,
            (SELECT COUNT(*) FROM email_queue WHERE campaign_id = ec.id AND status = 'sent') as sent_count,
            (SELECT COUNT(*) FROM email_queue WHERE campaign_id = ec.id AND status = 'pending') as pending_count,
            (SELECT COUNT(*) FROM email_queue WHERE campaign_id = ec.id AND status = 'failed') as failed_count
        FROM email_campaigns ec
        ORDER BY ec.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$active_page = 'email-campaigns';
$page_title = 'Email Campaigns';
include 'includes/admin_nav.php';
?>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<style>
.ql-toolbar.ql-snow {
    border-color: var(--border) !important;
    background: #f8fafc;
    border-top-left-radius: 6px;
    border-top-right-radius: 6px;
}
.ql-container.ql-snow {
    border-color: var(--border) !important;
    border-bottom-left-radius: 6px;
    border-bottom-right-radius: 6px;
    font-family: inherit;
    font-size: 0.95rem;
}
.ql-editor {
    min-height: 200px;
}
</style>

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <h1 style="margin:0;"><i class="fas fa-envelope-open-text" style="color:var(--accent); margin-right:8px;"></i> Email Campaigns</h1>
        <p class="subtitle" style="margin:4px 0 0 0; color:var(--text-muted);">Send and schedule custom emails to students targeted by courses</p>
    </div>
</div>

<?php if (!empty($success_message)): ?>
    <div class="alert alert-success" style="margin-bottom:20px;"><i class="fas fa-circle-check"></i> <span><?php echo htmlspecialchars($success_message); ?></span></div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger" style="margin-bottom:20px;"><i class="fas fa-circle-xmark"></i> <span><?php echo htmlspecialchars($error_message); ?></span></div>
<?php endif; ?>

<div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; align-items:start;">
    
    <!-- Compose Email Campaign Card -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon"><i class="fas fa-pen-fancy"></i></span>
            <h2>Compose Campaign</h2>
        </div>
        <div class="panel-body">
            <form method="POST" id="campaign-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create_campaign">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                    <div class="field">
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Target Courses</label>
                        <div style="background:var(--bg-card); border:1px solid var(--border); border-radius:8px; padding:12px; height:120px; overflow-y:auto;">
                            <?php if (empty($courses)): ?>
                                <p style="margin:0; color:var(--text-muted); font-size:0.9rem;">No courses available.</p>
                            <?php else: ?>
                                <?php foreach ($courses as $c): ?>
                                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.9rem; cursor:pointer;">
                                        <input type="checkbox" name="scope_course[]" value="<?php echo htmlspecialchars($c); ?>" style="width:auto;">
                                        <?php echo htmlspecialchars($c); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Target Forms</label>
                        <div style="background:var(--bg-card); border:1px solid var(--border); border-radius:8px; padding:12px; height:120px; overflow-y:auto;">
                            <?php if (empty($active_forms)): ?>
                                <p style="margin:0; color:var(--text-muted); font-size:0.9rem;">No active forms available.</p>
                            <?php else: ?>
                                <?php foreach ($active_forms as $f): ?>
                                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.9rem; cursor:pointer;">
                                        <input type="checkbox" name="scope_form[]" value="<?php echo (int)$f['id']; ?>" style="width:auto;">
                                        <?php echo htmlspecialchars($f['title']); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="field" style="margin-bottom:16px;">
                    <label style="font-weight:600; display:block; margin-bottom:4px;">Subject <span class="req">*</span></label>
                    <input type="text" name="subject" id="camp-subject" placeholder="e.g. Important update regarding {course}" required style="width:100%; padding:10px; border-radius:6px; border:1px solid var(--border);">
                    <div style="margin-top:6px; display:flex; gap:6px; align-items:center;">
                        <span style="font-size:0.8rem; color:var(--text-muted);">Insert tokens:</span>
                        <button type="button" class="btn btn-sm btn-outline" style="padding: 2px 6px; font-size:0.75rem;" onclick="insertPlaceholder('camp-subject', '{name}')">{name}</button>
                        <button type="button" class="btn btn-sm btn-outline" style="padding: 2px 6px; font-size:0.75rem;" onclick="insertPlaceholder('camp-subject', '{course}')">{course}</button>
                        <button type="button" class="btn btn-sm btn-outline" style="padding: 2px 6px; font-size:0.75rem;" onclick="insertPlaceholder('camp-subject', '{user_id}')">{user_id}</button>
                    </div>
                </div>

                <div class="field" style="margin-bottom:16px;">
                    <label style="font-weight:600; display:block; margin-bottom:4px;">Email Body Content <span class="req">*</span></label>
                    <div id="editor-container" style="height: 250px; background:#fff; border-radius:6px; border:1px solid var(--border); overflow: hidden;"></div>
                    <input type="hidden" name="body" id="body-input">
                    <div style="margin-top:6px; display:flex; gap:6px; align-items:center; margin-bottom:12px;">
                        <span style="font-size:0.8rem; color:var(--text-muted);">Insert tokens:</span>
                        <button type="button" class="btn btn-sm btn-outline" style="padding: 2px 6px; font-size:0.75rem;" onclick="insertQuillPlaceholder('{name}')">{name}</button>
                        <button type="button" class="btn btn-sm btn-outline" style="padding: 2px 6px; font-size:0.75rem;" onclick="insertQuillPlaceholder('{email}')">{email}</button>
                        <button type="button" class="btn btn-sm btn-outline" style="padding: 2px 6px; font-size:0.75rem;" onclick="insertQuillPlaceholder('{course}')">{course}</button>
                        <button type="button" class="btn btn-sm btn-outline" style="padding: 2px 6px; font-size:0.75rem;" onclick="insertQuillPlaceholder('{user_id}')">{user_id}</button>
                    </div>
                    <p style="font-size:0.8rem; color:var(--text-muted); margin:0; line-height:1.4;">
                        <i class="fas fa-circle-info"></i> Use the formatting toolbar to customize fonts, bold text, links, colors, and lists. Tokens are replaced per recipient automatically.
                    </p>
                </div>

                <div class="field" style="margin-bottom:20px; border-top:1px dashed var(--border); padding-top:12px;">
                    <label style="font-weight:600; display:block; margin-bottom:8px;">Send Settings</label>
                    <div style="display:flex; gap:16px; margin-bottom:12px;">
                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.9rem; cursor:pointer;">
                            <input type="radio" name="send_option" value="instant" checked onclick="toggleSched(false)" style="width:auto;"> Send Instantly
                        </label>
                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.9rem; cursor:pointer;">
                            <input type="radio" name="send_option" value="scheduled" onclick="toggleSched(true)" style="width:auto;"> Schedule for Later
                        </label>
                    </div>
                    
                    <div id="schedule-time-container" style="display:none; transition: all 0.2s ease;">
                        <label style="font-size:0.85rem; display:block; margin-bottom:4px;">Scheduled Sending Time <span class="req">*</span></label>
                        <input type="datetime-local" name="scheduled_at" id="sched-time" style="width:100%; max-width:260px; padding:8px; border-radius:6px; border:1px solid var(--border);">
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary" style="padding:10px 24px;"><i class="fas fa-paper-plane" style="margin-right:6px;"></i> Dispatch Campaign</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Campaigns Queue & Logs Card -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon"><i class="fas fa-history"></i></span>
            <h2>Recent Campaigns</h2>
        </div>
        <div class="panel-body" style="padding:0; overflow-x:auto;">
            <table class="data-table" style="width:100%; border-collapse:collapse; margin:0;">
                <thead>
                    <tr>
                        <th style="padding:12px 16px;">Campaign Info</th>
                        <th style="padding:12px 16px;">Target</th>
                        <th style="padding:12px 16px; text-align:center;">Status</th>
                        <th style="padding:12px 16px; text-align:right;">Stats</th>
                        <th style="padding:12px 16px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($campaigns)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:32px 16px; color:var(--text-muted);">
                                <i class="fas fa-inbox" style="font-size:2rem; margin-bottom:12px; display:block;"></i>
                                No email campaigns dispatched yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($campaigns as $camp): ?>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td style="padding:12px 16px;">
                                    <div class="cell-main" style="font-weight:600;"><?php echo htmlspecialchars($camp['subject']); ?></div>
                                    <div class="cell-sub" style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">
                                        Created by: <?php echo htmlspecialchars($camp['created_by']); ?>
                                        <br>
                                        Date: <?php echo date('d M Y, h:i A', strtotime($camp['created_at'])); ?>
                                    </div>
                                </td>
                                <td style="padding:12px 16px; vertical-align:top;">
                                    <div class="cell-sub" style="font-size:0.8rem; max-width:180px; word-break:break-all;">
                                        <?php 
                                        $t_courses = explode(',', $camp['target_courses']);
                                        foreach ($t_courses as $tc) {
                                            echo '<span class="badge blue" style="font-size:0.7rem; margin:1px; display:inline-block;">' . htmlspecialchars($tc) . '</span> ';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td style="padding:12px 16px; text-align:center; vertical-align:top;">
                                    <?php if ($camp['status'] === 'scheduled'): ?>
                                        <span class="badge amber"><i class="fas fa-clock"></i> Scheduled</span>
                                        <?php if (!empty($camp['scheduled_at'])): ?>
                                            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                                                <?php echo date('d M Y, h:i A', strtotime($camp['scheduled_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($camp['status'] === 'sending'): ?>
                                        <span class="badge blue" style="background:#2563eb; color:#fff;"><i class="fas fa-spinner fa-spin"></i> Sending</span>
                                    <?php elseif ($camp['status'] === 'sent'): ?>
                                        <span class="badge green"><i class="fas fa-circle-check"></i> Sent</span>
                                    <?php else: ?>
                                        <span class="badge gray"><i class="fas fa-ban"></i> Cancelled</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 16px; text-align:right; vertical-align:top; font-size:0.85rem; line-height:1.4;">
                                    <?php if ($camp['total_queued'] > 0): ?>
                                        <span style="font-weight:600;"><?php echo $camp['total_queued']; ?> Total</span><br>
                                        <span style="color:#16a34a;"><?php echo $camp['sent_count']; ?> Sent</span><br>
                                        <?php if ($camp['pending_count'] > 0): ?>
                                            <span style="color:#eab308;"><?php echo $camp['pending_count']; ?> Pending</span><br>
                                        <?php endif; ?>
                                        <?php if ($camp['failed_count'] > 0): ?>
                                            <span style="color:#ef4444; font-weight:600;"><?php echo $camp['failed_count']; ?> Failed</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="cell-sub">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 16px; text-align:right; vertical-align:middle;">
                                    <?php if ($camp['status'] === 'scheduled'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this scheduled campaign?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="cancel_campaign">
                                            <input type="hidden" name="campaign_id" value="<?php echo $camp['id']; ?>">
                                            <button class="btn btn-sm btn-soft-red" type="submit"><i class="fas fa-xmark"></i> Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="cell-sub">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
var quill = new Quill('#editor-container', {
    theme: 'snow',
    modules: {
        toolbar: [
            [{ 'header': [1, 2, 3, false] }],
            ['bold', 'italic', 'underline', 'strike'],
            [{ 'color': [] }, { 'background': [] }],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            ['link', 'clean']
        ]
    }
});

// Submit form bindings
document.getElementById('campaign-form').addEventListener('submit', function(e) {
    var bodyContent = quill.root.innerHTML;
    // Check if empty
    if (quill.getText().trim() === '') {
        alert('Email Body Content is required.');
        e.preventDefault();
        return false;
    }
    
    // Check that at least one checkbox is checked
    var coursesChecked = document.querySelectorAll('input[name="scope_course[]"]:checked').length;
    var formsChecked = document.querySelectorAll('input[name="scope_form[]"]:checked').length;
    if (coursesChecked === 0 && formsChecked === 0) {
        alert('Please select at least one Target Course or Target Form.');
        e.preventDefault();
        return false;
    }
    
    document.getElementById('body-input').value = bodyContent;
});

function insertQuillPlaceholder(token) {
    var range = quill.getSelection(true);
    quill.insertText(range.index, token);
    quill.setSelection(range.index + token.length);
}

function insertPlaceholder(fieldId, token) {
    var field = document.getElementById(fieldId);
    if (!field) return;
    
    var start = field.selectionStart;
    var end = field.selectionEnd;
    var text = field.value;
    
    field.value = text.substring(0, start) + token + text.substring(end);
    field.focus();
    field.selectionStart = field.selectionEnd = start + token.length;
}

function toggleSched(show) {
    var container = document.getElementById('schedule-time-container');
    var input = document.getElementById('sched-time');
    if (show) {
        container.style.display = 'block';
        input.setAttribute('required', 'required');
    } else {
        container.style.display = 'none';
        input.removeAttribute('required');
        input.value = '';
    }
}
</script>

<?php include 'admin_footer.php'; ?>
