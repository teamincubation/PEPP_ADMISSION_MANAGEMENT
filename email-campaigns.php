<?php
require_once 'includes/auth.php';
require_permission('marketing');
require_once 'includes/email_campaigns_helper.php';

// Auto-run self-healing table check
check_and_create_email_campaign_tables($pdo);

// Handle CSV Report Download BEFORE layout output
if (isset($_GET['action']) && $_GET['action'] === 'download_report') {
    $campaign_id = (int)($_GET['campaign_id'] ?? 0);
    try {
        $stmt_camp = $pdo->prepare("SELECT * FROM email_campaigns WHERE id = ?");
        $stmt_camp->execute([$campaign_id]);
        $campaign = $stmt_camp->fetch(PDO::FETCH_ASSOC);
        
        if (!$campaign) {
            die('Campaign not found.');
        }
        
        $stmt_queue = $pdo->prepare("SELECT * FROM email_queue WHERE campaign_id = ? ORDER BY id ASC");
        $stmt_queue->execute([$campaign_id]);
        $queue_items = $stmt_queue->fetchAll(PDO::FETCH_ASSOC);
        
        $filename = 'campaign_report_' . $campaign_id . '_' . date('Ymd_His') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fputs($output, "\xEF\xBB\xBF"); // BOM for Excel
        
        fputcsv($output, ['Sl. No.', 'Recipient Name', 'Recipient Email', 'Student ID', 'Subject', 'Status', 'Sent At', 'Error Message']);
        
        $sl = 1;
        foreach ($queue_items as $item) {
            fputcsv($output, [
                $sl++,
                $item['recipient_name'],
                $item['recipient_email'],
                $item['student_id'],
                $item['subject'],
                ucfirst($item['status']),
                $item['sent_at'] ? date('d M Y, h:i A', strtotime($item['sent_at'])) : 'N/A',
                $item['error_message'] ?: 'None'
            ]);
        }
        
        fclose($output);
        exit();
    } catch (Exception $e) {
        die('Error generating report: ' . $e->getMessage());
    }
}

// Handle AJAX Targets Resolution BEFORE layout output
if (isset($_GET['action']) && $_GET['action'] === 'resolve_targets') {
    header('Content-Type: application/json');
    $target_courses = $_POST['scope_course'] ?? [];
    $target_forms = $_POST['scope_form'] ?? [];
    $target_lists = $_POST['scope_list'] ?? [];
    $raw_subject = $_POST['subject'] ?? '';
    $raw_body = $_POST['body'] ?? '';
    
    // Resolve courses
    $students = [];
    if (!empty($target_courses) && is_array($target_courses)) {
        $placeholders = implode(',', array_fill(0, count($target_courses), '?'));
        $u_stmt = $pdo->prepare("SELECT name, email, pepp_course FROM users WHERE status = 'approved' AND pepp_course IN ($placeholders)");
        $u_stmt->execute($target_courses);
        $students = $u_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Resolve forms
    $form_users = [];
    if (!empty($target_forms) && is_array($target_forms)) {
        $placeholders_f = implode(',', array_fill(0, count($target_forms), '?'));
        $f_stmt = $pdo->prepare("
            SELECT s.id as submission_id, s.respondent_identifier, s.form_id, f.title as form_title
            FROM campaign_form_submissions s
            JOIN campaign_forms f ON s.form_id = f.id
            WHERE s.form_id IN ($placeholders_f) AND s.is_deleted = 0
        ");
        $f_stmt->execute($target_forms);
        $submissions = $f_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($submissions as $sub) {
            $email = $sub['respondent_identifier'];
            
            $stmt_name = $pdo->prepare("
                SELECT a.answer_text 
                FROM campaign_form_answers a
                JOIN campaign_form_fields f ON a.field_id = f.id
                WHERE a.submission_id = ? AND (f.label LIKE '%name%' OR f.field_name LIKE '%name%')
                ORDER BY f.sort_order ASC
                LIMIT 1
            ");
            $stmt_name->execute([$sub['submission_id']]);
            $name = $stmt_name->fetchColumn();
            
            if (empty($email) || strpos($email, '@') === false) {
                $stmt_email = $pdo->prepare("
                    SELECT a.answer_text 
                    FROM campaign_form_answers a
                    JOIN campaign_form_fields f ON a.field_id = f.id
                    WHERE a.submission_id = ? AND (f.type = 'email' OR f.label LIKE '%email%' OR f.field_name LIKE '%email%')
                    ORDER BY f.sort_order ASC
                    LIMIT 1
                ");
                $stmt_email->execute([$sub['submission_id']]);
                $ans_email = $stmt_email->fetchColumn();
                if ($ans_email) {
                    $email = $ans_email;
                }
            }
            
            if (!empty($email) && strpos($email, '@') !== false) {
                $form_users[] = [
                    'name' => $name ?: 'Form Registrant',
                    'email' => $email,
                    'pepp_course' => $sub['form_title']
                ];
            }
        }
    }
    
    // Resolve lists
    $list_users = [];
    if (!empty($target_lists) && is_array($target_lists)) {
        $placeholders_l = implode(',', array_fill(0, count($target_lists), '?'));
        $l_stmt = $pdo->prepare("
            SELECT le.name, le.email, l.label as list_title
            FROM email_campaign_list_emails le
            JOIN email_campaign_lists l ON le.list_id = l.id
            WHERE le.list_id IN ($placeholders_l)
        ");
        $l_stmt->execute($target_lists);
        $list_emails = $l_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($list_emails as $le) {
            if (!empty($le['email']) && strpos($le['email'], '@') !== false) {
                $list_users[] = [
                    'name' => $le['name'] ?: 'Recipient',
                    'email' => $le['email'],
                    'pepp_course' => $le['list_title']
                ];
            }
        }
    }
    
    // Merge uniquely by email address
    $recipients = [];
    $seen_emails = [];
    foreach ($students as $s) {
        if (empty($s['email'])) continue;
        $lowercase_email = strtolower(trim($s['email']));
        if (!isset($seen_emails[$lowercase_email])) {
            $seen_emails[$lowercase_email] = true;
            $recipients[] = [
                'name' => $s['name'],
                'email' => $s['email'],
                'source' => $s['pepp_course']
            ];
        }
    }
    foreach ($form_users as $fu) {
        $lowercase_email = strtolower(trim($fu['email']));
        if (!isset($seen_emails[$lowercase_email])) {
            $seen_emails[$lowercase_email] = true;
            $recipients[] = [
                'name' => $fu['name'],
                'email' => $fu['email'],
                'source' => $fu['pepp_course']
            ];
        }
    }
    foreach ($list_users as $lu) {
        $lowercase_email = strtolower(trim($lu['email']));
        if (!isset($seen_emails[$lowercase_email])) {
            $seen_emails[$lowercase_email] = true;
            $recipients[] = [
                'name' => $lu['name'],
                'email' => $lu['email'],
                'source' => $lu['pepp_course']
            ];
        }
    }
    
    // Render sample subject and body
    $sample_subject = $raw_subject;
    $sample_body = $raw_body;
    if (!empty($recipients)) {
        $first = $recipients[0];
        $search = ['{name}', '{email}', '{course}', '{user_id}'];
        $replace = [$first['name'], $first['email'], $first['source'], 'sample_user_id'];
        $sample_subject = str_replace($search, $replace, $raw_subject);
        $sample_body = str_replace($search, $replace, $raw_body);
    }
    $preview_html = build_campaign_email_html($sample_body);
    
    echo json_encode([
        'recipients' => $recipients,
        'sample_subject' => htmlspecialchars($sample_subject),
        'preview_html' => $preview_html
    ]);
    exit();
}

// Handle AJAX Queue Processing
if (isset($_GET['action']) && $_GET['action'] === 'process_queue') {
    header('Content-Type: application/json');
    
    // Process a batch of 5 pending queue items
    $stmt = $pdo->prepare("SELECT * FROM email_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 5");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $processed = 0;
    $sent = 0;
    $failed = 0;
    
    foreach ($items as $item) {
        $html = build_campaign_email_html($item['body']);
        
        // Add a small delay (250ms) between SMTP sends to prevent server blockages
        usleep(250000);
        
        $ok = send_custom_email($item['recipient_email'], $item['subject'], $html);
        if ($ok) {
            $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$item['id']]);
            $sent++;
        } else {
            $pdo->prepare("UPDATE email_queue SET status = 'failed', error_message = 'Failed to deliver via mail()' WHERE id = ?")->execute([$item['id']]);
            $failed++;
        }
        
        // Update campaign status if no more pending items exist
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM email_queue WHERE campaign_id = ? AND status = 'pending'");
        $stmt_check->execute([$item['campaign_id']]);
        $pending_left = (int)$stmt_check->fetchColumn();
        if ($pending_left === 0) {
            $pdo->prepare("UPDATE email_campaigns SET status = 'sent' WHERE id = ?")->execute([$item['campaign_id']]);
        }
        
        $processed++;
    }
    
    // Fetch updated status of recent campaigns
    $campaigns_status = [];
    try {
        $recent = $pdo->query("SELECT id FROM email_campaigns ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($recent as $camp_id) {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE campaign_id = $camp_id")->fetchColumn();
            $s_count = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE campaign_id = $camp_id AND status = 'sent'")->fetchColumn();
            $f_count = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE campaign_id = $camp_id AND status = 'failed'")->fetchColumn();
            $p_count = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE campaign_id = $camp_id AND status = 'pending'")->fetchColumn();
            
            $campaigns_status[$camp_id] = [
                'total' => $total,
                'sent' => $s_count,
                'failed' => $f_count,
                'pending' => $p_count
            ];
        }
    } catch (Exception $e) {}
    
    echo json_encode([
        'processed' => $processed,
        'sent_batch' => $sent,
        'failed_batch' => $failed,
        'campaigns' => $campaigns_status
    ]);
    exit();
}

// Handle AJAX Campaign Status Poll
if (isset($_GET['action']) && $_GET['action'] === 'get_campaign_stats') {
    header('Content-Type: application/json');
    $recent_campaigns = [];
    try {
        $stmt = $pdo->query("
            SELECT 
                id, status,
                (SELECT COUNT(*) FROM email_queue WHERE campaign_id = ec.id) as total_queued,
                (SELECT COUNT(*) FROM email_queue WHERE campaign_id = ec.id AND status = 'sent') as sent_count,
                (SELECT COUNT(*) FROM email_queue WHERE campaign_id = ec.id AND status = 'pending') as pending_count,
                (SELECT COUNT(*) FROM email_queue WHERE campaign_id = ec.id AND status = 'failed') as failed_count
            FROM email_campaigns ec
            ORDER BY id DESC LIMIT 20
        ");
        $recent_campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    
    echo json_encode(['campaigns' => $recent_campaigns]);
    exit();
}

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
            $target_lists = $_POST['scope_list'] ?? [];
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
            
            if (is_array($target_lists)) {
                $target_lists = array_filter(array_map('intval', $target_lists));
            } else {
                $target_lists = [];
            }
            
            if ($subject === '' || $body === '' || (empty($target_courses) && empty($target_forms) && empty($target_lists))) {
                $error_message = 'Subject, Body, and at least one Target Course, Form, or Custom List are required.';
            } else {
                $courses_str = implode(',', $target_courses);
                $forms_str = implode(',', $target_forms);
                $lists_str = implode(',', $target_lists);
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
                        $stmt = $pdo->prepare("INSERT INTO email_campaigns (subject, body, target_courses, target_forms, target_lists, scheduled_at, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$subject, $body, $courses_str, $forms_str, $lists_str, $sched_time, $status, $admin_username]);
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
        } elseif ($action === 'import_list') {
            $label = trim($_POST['list_label'] ?? '');
            if ($label === '' || empty($_FILES['list_file']['tmp_name'])) {
                $error_message = 'List label and a valid CSV/Excel file are required.';
            } else {
                try {
                    $file_path = $_FILES['list_file']['tmp_name'];
                    $handle = fopen($file_path, 'r');
                    $emails = [];
                    if ($handle !== false) {
                        $headers = fgetcsv($handle);
                        $name_idx = -1;
                        $email_idx = -1;
                        
                        if ($headers) {
                            foreach ($headers as $idx => $header) {
                                $header_clean = strtolower(trim($header));
                                if (strpos($header_clean, 'email') !== false || strpos($header_clean, 'mail') !== false) {
                                    $email_idx = $idx;
                                } elseif (strpos($header_clean, 'name') !== false) {
                                    $name_idx = $idx;
                                }
                            }
                        }
                        
                        if ($email_idx === -1) {
                            if ($headers && count($headers) === 1) {
                                $email_idx = 0;
                            } else {
                                $name_idx = 0;
                                $email_idx = 1;
                            }
                        }
                        
                        if ($email_idx !== -1 && $headers && count($headers) > 0) {
                            $first_email = trim($headers[$email_idx]);
                            if (strpos($first_email, '@') !== false) {
                                $first_name = $name_idx !== -1 ? trim($headers[$name_idx]) : '';
                                $emails[] = ['name' => $first_name, 'email' => $first_email];
                            }
                        }
                        
                        while (($row = fgetcsv($handle)) !== false) {
                            if (count($row) <= max($name_idx, $email_idx)) continue;
                            $email = trim($row[$email_idx] ?? '');
                            if (strpos($email, '@') !== false) {
                                $name = $name_idx !== -1 ? trim($row[$name_idx] ?? '') : '';
                                $emails[] = ['name' => $name, 'email' => $email];
                            }
                        }
                        fclose($handle);
                    }
                    
                    if (empty($emails)) {
                        $error_message = 'No valid email addresses found in the file.';
                    } else {
                        $pdo->beginTransaction();
                        $stmt_list = $pdo->prepare("INSERT INTO email_campaign_lists (label, created_at) VALUES (?, NOW())");
                        $stmt_list->execute([$label]);
                        $list_id = $pdo->lastInsertId();
                        
                        $stmt_email = $pdo->prepare("INSERT INTO email_campaign_list_emails (list_id, name, email) VALUES (?, ?, ?)");
                        foreach ($emails as $item) {
                            $stmt_email->execute([$list_id, $item['name'], $item['email']]);
                        }
                        $pdo->commit();
                        $success_message = 'Successfully imported custom list "' . htmlspecialchars($label) . '" with ' . count($emails) . ' recipients.';
                        log_admin_activity($pdo, $admin_username, 'email_list_imported', "Imported custom email list \"{$label}\" with " . count($emails) . " emails");
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error_message = 'Failed to import list: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete_list') {
            $list_id = (int)($_POST['list_id'] ?? 0);
            try {
                $pdo->prepare("DELETE FROM email_campaign_lists WHERE id = ?")->execute([$list_id]);
                $pdo->prepare("DELETE FROM email_campaign_list_emails WHERE list_id = ?")->execute([$list_id]);
                $success_message = 'Custom list deleted successfully.';
                log_admin_activity($pdo, $admin_username, 'email_list_deleted', "Deleted custom email list #{$list_id}");
            } catch (Exception $e) {
                $error_message = 'Failed to delete list: ' . $e->getMessage();
            }
        } elseif ($action === 'save_template') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            $template_name = trim($_POST['template_name'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $body = trim($_POST['body'] ?? '');
            
            if ($subject === '' || $body === '') {
                $error_message = 'Subject and Email Body are required to save a template.';
            } else {
                try {
                    if ($template_id > 0) {
                        // Update existing template
                        $stmt = $pdo->prepare("UPDATE email_campaign_templates SET subject = ?, body = ? WHERE id = ?");
                        $stmt->execute([$subject, $body, $template_id]);
                        $success_message = 'Template updated successfully.';
                        log_admin_activity($pdo, $admin_username, 'email_template_updated', "Updated email template #{$template_id}");
                    } else {
                        // Create new template
                        if ($template_name === '') {
                            $template_name = 'Template ' . date('Y-m-d H:i');
                        }
                        $stmt = $pdo->prepare("INSERT INTO email_campaign_templates (template_name, subject, body, created_at) VALUES (?, ?, ?, NOW())");
                        $stmt->execute([$template_name, $subject, $body]);
                        $success_message = 'Template "' . htmlspecialchars($template_name) . '" saved successfully.';
                        log_admin_activity($pdo, $admin_username, 'email_template_created', "Created email template \"{$template_name}\"");
                    }
                } catch (Exception $e) {
                    $error_message = 'Failed to save template: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete_template') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            try {
                $pdo->prepare("DELETE FROM email_campaign_templates WHERE id = ?")->execute([$template_id]);
                $success_message = 'Template deleted successfully.';
                log_admin_activity($pdo, $admin_username, 'email_template_deleted', "Deleted email template #{$template_id}");
            } catch (Exception $e) {
                $error_message = 'Failed to delete template: ' . $e->getMessage();
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
        WHERE ff.is_deleted = 0 
          AND (ff.type = 'email' OR ff.field_name LIKE '%email%' OR ff.label LIKE '%email%')
        ORDER BY f.title
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch active custom lists for targeting
$custom_lists = [];
try {
    $custom_lists = $pdo->query("SELECT id, label, (SELECT COUNT(*) FROM email_campaign_list_emails WHERE list_id = l.id) as emails_count FROM email_campaign_lists l ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch saved email templates
$templates = [];
try {
    $templates = $pdo->query("SELECT * FROM email_campaign_templates ORDER BY template_name ASC")->fetchAll(PDO::FETCH_ASSOC);
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
.ql-editor {
    min-height: 200px;
}
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.6);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    backdrop-filter: blur(4px);
}
</style>

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <h1 style="margin:0;"><i class="fas fa-envelope-open-text" style="color:var(--accent); margin-right:8px;"></i> Email Campaigns</h1>
        <p class="subtitle" style="margin:4px 0 0 0; color:var(--text-muted);">Send and schedule custom emails to students targeted by courses, forms, or custom Excel lists</p>
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
                
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:16px;">
                    <div class="field">
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Target Courses</label>
                        <div style="background:var(--bg-card); border:1px solid var(--border); border-radius:8px; padding:12px; height:120px; overflow-y:auto;">
                            <?php if (empty($courses)): ?>
                                <p style="margin:0; color:var(--text-muted); font-size:0.9rem;">No courses available.</p>
                            <?php else: ?>
                                <?php foreach ($courses as $c): ?>
                                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.9rem; cursor:pointer;">
                                        <input type="checkbox" class="target-checkbox" name="scope_course[]" value="<?php echo htmlspecialchars($c); ?>" style="width:auto;" onchange="updatePreviewButtonVisibility()">
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
                                        <input type="checkbox" class="target-checkbox" name="scope_form[]" value="<?php echo (int)$f['id']; ?>" style="width:auto;" onchange="updatePreviewButtonVisibility()">
                                        <?php echo htmlspecialchars($f['title']); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Target Custom Lists</label>
                        <div style="background:var(--bg-card); border:1px solid var(--border); border-radius:8px; padding:12px; height:120px; overflow-y:auto;">
                            <?php if (empty($custom_lists)): ?>
                                <p style="margin:0; color:var(--text-muted); font-size:0.9rem;">No custom lists available.</p>
                            <?php else: ?>
                                <?php foreach ($custom_lists as $l): ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; font-size:0.9rem;">
                                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0; flex:1;">
                                            <input type="checkbox" class="target-checkbox" name="scope_list[]" value="<?php echo (int)$l['id']; ?>" style="width:auto;" onchange="updatePreviewButtonVisibility()">
                                            <?php echo htmlspecialchars($l['label']); ?> (<?php echo $l['emails_count']; ?>)
                                        </label>
                                        <button type="button" class="btn btn-sm btn-soft-red" style="padding:2px 6px; font-size:0.75rem; border:none; margin-left:4px;" onclick="deleteCustomList(<?php echo $l['id']; ?>, '<?php echo addslashes($l['label']); ?>')"><i class="fas fa-trash-can"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Email Template Manager -->
                <div style="background:var(--bg-card); border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:16px;">
                    <label style="font-weight:700; display:block; margin-bottom:6px; font-size:0.85rem; color:var(--text-muted);">
                        <i class="fas fa-paste" style="margin-right:4px;"></i> Email Template Manager
                    </label>
                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <select id="template-select" style="flex:1; min-width:200px; padding:8px; border-radius:6px; border:1px solid var(--border);" onchange="loadTemplate()">
                            <option value="">-- Select template to load --</option>
                            <?php foreach ($templates as $tmpl): ?>
                                <option value="<?php echo $tmpl['id']; ?>" data-subject="<?php echo htmlspecialchars($tmpl['subject']); ?>" data-body="<?php echo htmlspecialchars($tmpl['body']); ?>">
                                    <?php echo htmlspecialchars($tmpl['template_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline" style="padding: 6px 12px; font-size: 0.8rem;" onclick="saveAsNewTemplate()"><i class="fas fa-plus"></i> Save New</button>
                        <button type="button" class="btn btn-sm btn-outline" style="padding: 6px 12px; font-size: 0.8rem;" id="update-tmpl-btn" disabled onclick="updateSelectedTemplate()"><i class="fas fa-floppy-disk"></i> Update</button>
                        <button type="button" class="btn btn-sm btn-soft-red" style="padding: 6px 12px; font-size: 0.8rem;" id="delete-tmpl-btn" disabled onclick="deleteSelectedTemplate()"><i class="fas fa-trash-can"></i> Delete</button>
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

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" class="btn btn-secondary" id="preview-btn" style="padding:10px 24px; display:none; align-items:center; gap:6px;" onclick="openPreviewModal()"><i class="fas fa-eye"></i> Preview</button>
                    <button type="submit" class="btn btn-primary" style="padding:10px 24px;"><i class="fas fa-paper-plane" style="margin-right:6px;"></i> Dispatch Campaign</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Right-Hand Side Column -->
    <div style="display:flex; flex-direction:column; gap:20px;">
        
        <!-- Import Excel/CSV List Card -->
        <div class="panel">
            <div class="panel-head">
                <span class="head-icon"><i class="fas fa-file-import"></i></span>
                <h2>Import Custom Recipient List</h2>
            </div>
            <div class="panel-body">
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="import_list">
                    <div class="field" style="margin-bottom:12px;">
                        <label style="font-weight:600; display:block; margin-bottom:4px;">List Label Name <span class="req">*</span></label>
                        <input type="text" name="list_label" placeholder="e.g. Special Leads Aug 2026" required style="width:100%; padding:10px; border-radius:6px; border:1px solid var(--border);">
                    </div>
                    <div class="field" style="margin-bottom:12px;">
                        <label style="font-weight:600; display:block; margin-bottom:4px;">Select CSV/Excel File (Columns: Name, Email) <span class="req">*</span></label>
                        <input type="file" name="list_file" accept=".csv" required style="width:100%; padding:8px; border-radius:6px; border:1px solid var(--border); background:#fff;">
                        <p style="font-size:0.75rem; color:var(--text-muted); margin:4px 0 0 0;"><i class="fas fa-circle-info"></i> Excel lists must be saved as CSV (.csv format) with columns for Name and Email before uploading.</p>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="width:100%; padding:10px; font-weight:700;"><i class="fas fa-upload" style="margin-right:6px;"></i> Import &amp; Save List</button>
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
                                <tr style="border-bottom:1px solid var(--border);" id="campaign-row-<?php echo $camp['id']; ?>">
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
                                            if (!empty($camp['target_courses'])) {
                                                $t_courses = array_filter(explode(',', $camp['target_courses']));
                                                foreach ($t_courses as $tc) {
                                                    echo '<span class="badge blue" style="font-size:0.7rem; margin:1px; display:inline-block;">' . htmlspecialchars($tc) . '</span> ';
                                                }
                                            }
                                            if (!empty($camp['target_forms'])) {
                                                $t_forms = array_filter(explode(',', $camp['target_forms']));
                                                foreach ($t_forms as $tf) {
                                                    echo '<span class="badge green" style="font-size:0.7rem; margin:1px; display:inline-block;">Form #' . htmlspecialchars($tf) . '</span> ';
                                                }
                                            }
                                            if (!empty($camp['target_lists'])) {
                                                $t_lists = array_filter(explode(',', $camp['target_lists']));
                                                foreach ($t_lists as $tl) {
                                                    echo '<span class="badge purple" style="font-size:0.7rem; margin:1px; display:inline-block; background:rgba(168,85,247,0.15); color:#a855f7; border:1px solid rgba(168,85,247,0.3);">List #' . htmlspecialchars($tl) . '</span> ';
                                                }
                                            }
                                            ?>
                                        </div>
                                    </td>
                                <td style="padding:12px 16px; text-align:center; vertical-align:top;" class="campaign-status-cell" data-campaign-id="<?php echo $camp['id']; ?>">
                                    <?php if ($camp['status'] === 'scheduled'): ?>
                                        <span class="badge amber"><i class="fas fa-clock"></i> Scheduled</span>
                                        <?php if (!empty($camp['scheduled_at'])): ?>
                                            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                                                <?php echo date('d M Y, h:i A', strtotime($camp['scheduled_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($camp['status'] === 'sending' || $camp['pending_count'] > 0): ?>
                                        <span class="badge blue" style="background:#2563eb; color:#fff;"><i class="fas fa-spinner fa-spin"></i> Sending</span>
                                    <?php elseif ($camp['status'] === 'sent'): ?>
                                        <span class="badge green"><i class="fas fa-circle-check"></i> Sent</span>
                                    <?php else: ?>
                                        <span class="badge gray"><i class="fas fa-ban"></i> Cancelled</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 16px; text-align:right; vertical-align:top; font-size:0.85rem; line-height:1.4;" class="campaign-stats-cell" data-campaign-id="<?php echo $camp['id']; ?>" data-total="<?php echo $camp['total_queued']; ?>" data-sent="<?php echo $camp['sent_count']; ?>" data-pending="<?php echo $camp['pending_count']; ?>" data-failed="<?php echo $camp['failed_count']; ?>">
                                    <?php if ($camp['total_queued'] > 0): ?>
                                        <span style="font-weight:600;"><span class="stat-total"><?php echo $camp['total_queued']; ?></span> Total</span><br>
                                        <span style="color:#16a34a;"><span class="stat-sent"><?php echo $camp['sent_count']; ?></span> Sent</span><br>
                                        <?php if ($camp['pending_count'] > 0): ?>
                                            <span style="color:#eab308;"><span class="stat-pending"><?php echo $camp['pending_count']; ?></span> Pending</span><br>
                                            <?php 
                                            $pct = round(($camp['sent_count'] / $camp['total_queued']) * 100); 
                                            ?>
                                            <div class="progress-bar-container" style="width: 100px; height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; margin-top: 6px; margin-left: auto;">
                                                <div class="progress-bar-fill" style="width: <?php echo $pct; ?>%; height: 100%; background: var(--accent); transition: width 0.3s;"></div>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--accent); font-weight: 600; margin-top: 2px;">
                                                <i class="fas fa-spinner fa-spin" style="margin-right: 2px;"></i> Sending <span class="stat-pct"><?php echo $pct; ?></span>%
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($camp['failed_count'] > 0): ?>
                                            <span style="color:#ef4444; font-weight:600;"><span class="stat-failed"><?php echo $camp['failed_count']; ?></span> Failed</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="cell-sub">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 16px; text-align:right; vertical-align:middle;">
                                    <div style="display:inline-flex; gap:6px; justify-content:flex-end; align-items:center;">
                                        <a href="email-campaigns.php?action=download_report&campaign_id=<?php echo $camp['id']; ?>" class="btn btn-sm btn-outline" title="Download CSV Report" style="padding: 4px 8px; font-size: 0.8rem; height: 28px; line-height: 1.2; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fas fa-download"></i> Report
                                        </a>
                                        <?php if ($camp['status'] === 'scheduled'): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this scheduled campaign?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="cancel_campaign">
                                                <input type="hidden" name="campaign_id" value="<?php echo $camp['id']; ?>">
                                                <button class="btn btn-sm btn-soft-red" type="submit" style="padding: 4px 8px; font-size: 0.8rem; height: 28px; line-height: 1.2; display: inline-flex; align-items: center; gap: 4px;"><i class="fas fa-xmark"></i> Cancel</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
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
    var listsChecked = document.querySelectorAll('input[name="scope_list[]"]:checked').length;
    if (coursesChecked === 0 && formsChecked === 0 && listsChecked === 0) {
        alert('Please select at least one Target Course, Target Form, or Target Custom List.');
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

// Collapsible Preview Trigger Buttons Logic
function updatePreviewButtonVisibility() {
    var checked = document.querySelectorAll('.target-checkbox:checked').length;
    var previewBtn = document.getElementById('preview-btn');
    if (checked > 0) {
        previewBtn.style.display = 'inline-flex';
    } else {
        previewBtn.style.display = 'none';
    }
}

function openPreviewModal() {
    var formElement = document.getElementById('campaign-form');
    var formData = new FormData(formElement);
    if (typeof quill !== 'undefined') {
        formData.set('body', quill.root.innerHTML);
    }
    
    document.getElementById('target-list-container').innerHTML = '<div style="text-align:center; padding:40px 10px; color:var(--text-muted); font-size:0.95rem;"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem; margin-bottom:8px; display:block; color:var(--accent);"></i> Resolving target emails...</div>';
    document.getElementById('preview-subject-text').innerText = 'Loading...';
    document.getElementById('preview-body-iframe-container').innerHTML = '';
    document.getElementById('preview-modal').style.display = 'flex';
    
    fetch('email-campaigns.php?action=resolve_targets', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById('target-count').innerText = data.recipients.length;
        
        var listHtml = '';
        if (data.recipients.length === 0) {
            listHtml = '<div style="text-align:center; color:var(--text-muted); padding:30px 10px; font-size:0.85rem;">No target recipients found. Check your filters.</div>';
        } else {
            data.recipients.forEach(r => {
                var escName = (r.name || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                var escEmail = (r.email || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                var escSource = (r.source || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                listHtml += '<div style="background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:10px; display:flex; flex-direction:column; gap:2px; font-size:0.82rem;">' +
                    '<div style="font-weight:700; color:var(--text-main); text-overflow:ellipsis; overflow:hidden; white-space:nowrap;">' + (escName || 'Recipient') + '</div>' +
                    '<div style="color:var(--secondary); text-overflow:ellipsis; overflow:hidden; white-space:nowrap;">' + escEmail + '</div>' +
                    '<div style="font-size:0.7rem; color:var(--accent); font-weight:600; text-transform:uppercase; margin-top:2px;">' + escSource + '</div>' +
                '</div>';
            });
        }
        document.getElementById('target-list-container').innerHTML = listHtml;
        
        document.getElementById('preview-subject-text').innerText = data.sample_subject || '(No Subject)';
        
        var iframe = document.createElement('iframe');
        iframe.style.width = '100%';
        iframe.style.height = '500px';
        iframe.style.border = 'none';
        iframe.style.borderRadius = '8px';
        iframe.style.boxShadow = '0 4px 20px rgba(0,0,0,0.1)';
        document.getElementById('preview-body-iframe-container').appendChild(iframe);
        
        var doc = iframe.contentWindow || iframe.contentDocument.document || iframe.contentDocument;
        doc.document.open();
        doc.document.write(data.preview_html);
        doc.document.close();
    })
    .catch(err => {
        document.getElementById('target-list-container').innerHTML = '<div style="text-align:center; color:#ef4444; padding:20px; font-size:0.85rem;">Error loading preview.</div>';
    });
}

function closePreviewModal() {
    document.getElementById('preview-modal').style.display = 'none';
}

function deleteCustomList(listId, listLabel) {
    if (confirm('Are you sure you want to delete the custom list "' + listLabel + '"? This will remove all associated target email addresses.')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = document.querySelector('input[name="csrf_token"]').value;
        form.appendChild(csrfInput);
        
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_list';
        form.appendChild(actionInput);
        
        var idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'list_id';
        idInput.value = listId;
        form.appendChild(idInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Email Template Manager Javascript Handlers
function loadTemplate() {
    var select = document.getElementById('template-select');
    var opt = select.options[select.selectedIndex];
    var updateBtn = document.getElementById('update-tmpl-btn');
    var deleteBtn = document.getElementById('delete-tmpl-btn');
    
    if (opt && opt.value) {
        var subject = opt.getAttribute('data-subject');
        var body = opt.getAttribute('data-body');
        
        document.getElementById('camp-subject').value = subject;
        if (typeof quill !== 'undefined') {
            quill.root.innerHTML = body;
        }
        
        updateBtn.disabled = false;
        deleteBtn.disabled = false;
    } else {
        updateBtn.disabled = true;
        deleteBtn.disabled = true;
    }
}

function saveAsNewTemplate() {
    var subject = document.getElementById('camp-subject').value.trim();
    var body = typeof quill !== 'undefined' ? quill.root.innerHTML.trim() : '';
    var rawText = typeof quill !== 'undefined' ? quill.getText().trim() : '';
    
    if (subject === '' || rawText === '') {
        alert('Subject and Email Body Content are required to save a template.');
        return;
    }
    
    var name = prompt('Enter a unique name for this email template:');
    if (name === null) return; // cancelled
    name = name.trim();
    if (name === '') {
        alert('Template name cannot be empty.');
        return;
    }
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    
    var inputs = [
        { name: 'csrf_token', value: document.querySelector('input[name="csrf_token"]').value },
        { name: 'action', value: 'save_template' },
        { name: 'template_name', value: name },
        { name: 'subject', value: subject },
        { name: 'body', value: body }
    ];
    
    inputs.forEach(inp => {
        var el = document.createElement('input');
        el.type = 'hidden';
        el.name = inp.name;
        el.value = inp.value;
        form.appendChild(el);
    });
    
    document.body.appendChild(form);
    form.submit();
}

function updateSelectedTemplate() {
    var select = document.getElementById('template-select');
    var templateId = select.value;
    var opt = select.options[select.selectedIndex];
    if (!templateId) return;
    
    var subject = document.getElementById('camp-subject').value.trim();
    var body = typeof quill !== 'undefined' ? quill.root.innerHTML.trim() : '';
    var rawText = typeof quill !== 'undefined' ? quill.getText().trim() : '';
    
    if (subject === '' || rawText === '') {
        alert('Subject and Email Body Content are required to update a template.');
        return;
    }
    
    if (!confirm('Are you sure you want to update the template "' + opt.text.trim() + '" with the current Subject and Body?')) {
        return;
    }
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    
    var inputs = [
        { name: 'csrf_token', value: document.querySelector('input[name="csrf_token"]').value },
        { name: 'action', value: 'save_template' },
        { name: 'template_id', value: templateId },
        { name: 'subject', value: subject },
        { name: 'body', value: body }
    ];
    
    inputs.forEach(inp => {
        var el = document.createElement('input');
        el.type = 'hidden';
        el.name = inp.name;
        el.value = inp.value;
        form.appendChild(el);
    });
    
    document.body.appendChild(form);
    form.submit();
}

function deleteSelectedTemplate() {
    var select = document.getElementById('template-select');
    var templateId = select.value;
    var opt = select.options[select.selectedIndex];
    if (!templateId) return;
    
    if (!confirm('Are you sure you want to delete the email template "' + opt.text.trim() + '"? This action cannot be undone.')) {
        return;
    }
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    
    var inputs = [
        { name: 'csrf_token', value: document.querySelector('input[name="csrf_token"]').value },
        { name: 'action', value: 'delete_template' },
        { name: 'template_id', value: templateId }
    ];
    
    inputs.forEach(inp => {
        var el = document.createElement('input');
        el.type = 'hidden';
        el.name = inp.name;
        el.value = inp.value;
        form.appendChild(el);
    });
    
    
    document.body.appendChild(form);
    form.submit();
}

// Live Queue processing and Stats polling auto-loader routines
function updateCampaignStatsUI(campId, total, sent, pending, failed) {
    var cell = document.querySelector('.campaign-stats-cell[data-campaign-id="' + campId + '"]');
    if (!cell) return;
    
    cell.setAttribute('data-total', total);
    cell.setAttribute('data-sent', sent);
    cell.setAttribute('data-pending', pending);
    cell.setAttribute('data-failed', failed);
    
    if (total > 0) {
        var html = '<span style="font-weight:600;"><span class="stat-total">' + total + '</span> Total</span><br>' +
                   '<span style="color:#16a34a;"><span class="stat-sent">' + sent + '</span> Sent</span><br>';
                   
        if (pending > 0) {
            html += '<span style="color:#eab308;"><span class="stat-pending">' + pending + '</span> Pending</span><br>';
            var pct = Math.round((sent / total) * 100);
            html += '<div class="progress-bar-container" style="width: 100px; height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; margin-top: 6px; margin-left: auto;">' +
                        '<div class="progress-bar-fill" style="width: ' + pct + '%; height: 100%; background: var(--accent); transition: width 0.3s;"></div>' +
                    '</div>' +
                    '<div style="font-size: 0.75rem; color: var(--accent); font-weight: 600; margin-top: 2px;">' +
                        '<i class="fas fa-spinner fa-spin" style="margin-right: 2px;"></i> Sending <span class="stat-pct">' + pct + '</span>%' +
                    '</div>';
        }
        
        if (failed > 0) {
            html += '<span style="color:#ef4444; font-weight:600;"><span class="stat-failed">' + failed + '</span> Failed</span>';
        }
        cell.innerHTML = html;
    } else {
        cell.innerHTML = '<span class="cell-sub">-</span>';
    }
}

var processingQueue = false;

function runQueueAutoLoader() {
    var hasPending = false;
    document.querySelectorAll('.campaign-stats-cell').forEach(cell => {
        var pending = parseInt(cell.getAttribute('data-pending') || '0', 10);
        if (pending > 0) {
            hasPending = true;
        }
    });
    
    if (hasPending) {
        if (processingQueue) return;
        processingQueue = true;
        
        fetch('email-campaigns.php?action=process_queue')
        .then(res => res.json())
        .then(data => {
            processingQueue = false;
            if (data.campaigns) {
                Object.keys(data.campaigns).forEach(campId => {
                    var c = data.campaigns[campId];
                    updateCampaignStatsUI(campId, c.total, c.sent, c.pending, c.failed);
                    
                    var statusCell = document.querySelector('.campaign-status-cell[data-campaign-id="' + campId + '"]');
                    if (statusCell) {
                        if (c.pending === 0) {
                            statusCell.innerHTML = '<span class="badge green"><i class="fas fa-circle-check"></i> Sent</span>';
                        } else {
                            if (!statusCell.innerHTML.includes('Sending')) {
                                statusCell.innerHTML = '<span class="badge blue" style="background:#2563eb; color:#fff;"><i class="fas fa-spinner fa-spin"></i> Sending</span>';
                            }
                        }
                    }
                });
            }
            setTimeout(runQueueAutoLoader, 500);
        })
        .catch(err => {
            processingQueue = false;
            setTimeout(runQueueAutoLoader, 3000);
        });
    } else {
        setTimeout(pollCampaignStats, 5000);
    }
}

function pollCampaignStats() {
    fetch('email-campaigns.php?action=get_campaign_stats')
    .then(res => res.json())
    .then(data => {
        var hasNewPending = false;
        if (data.campaigns) {
            data.campaigns.forEach(c => {
                updateCampaignStatsUI(c.id, c.total_queued, c.sent_count, c.pending_count, c.failed_count);
                
                var statusCell = document.querySelector('.campaign-status-cell[data-campaign-id="' + c.id + '"]');
                if (statusCell) {
                    if (c.pending_count > 0) {
                        hasNewPending = true;
                        statusCell.innerHTML = '<span class="badge blue" style="background:#2563eb; color:#fff;"><i class="fas fa-spinner fa-spin"></i> Sending</span>';
                    } else if (c.status === 'scheduled') {
                        statusCell.innerHTML = '<span class="badge amber"><i class="fas fa-clock"></i> Scheduled</span>';
                    } else if (c.status === 'sent') {
                        statusCell.innerHTML = '<span class="badge green"><i class="fas fa-circle-check"></i> Sent</span>';
                    } else if (c.status === 'sending') {
                        statusCell.innerHTML = '<span class="badge blue" style="background:#2563eb; color:#fff;"><i class="fas fa-spinner fa-spin"></i> Sending</span>';
                    } else {
                        statusCell.innerHTML = '<span class="badge gray"><i class="fas fa-ban"></i> Cancelled</span>';
                    }
                }
            });
        }
        if (hasNewPending) {
            runQueueAutoLoader();
        } else {
            setTimeout(pollCampaignStats, 5000);
        }
    })
    .catch(err => {
        setTimeout(pollCampaignStats, 8000);
    });
}

window.addEventListener('DOMContentLoaded', function() {
    runQueueAutoLoader();
});
</script>

<!-- Campaign Preview Modal -->
<div class="modal-overlay" id="preview-modal" style="display:none; align-items:center; justify-content:center;">
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:18px; width:100%; max-width:980px; height:85vh; display:flex; flex-direction:column; box-shadow:0 20px 50px rgba(0,0,0,0.3); overflow:hidden;">
        <div style="padding:16px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:var(--card);">
            <h3 style="margin:0; font-size:1.15rem; font-weight:800; color:var(--text-main);"><i class="fas fa-magnifying-glass-chart" style="color:var(--accent); margin-right:8px;"></i> Campaign Preview &amp; Target Verification</h3>
            <button type="button" class="btn btn-sm btn-secondary" onclick="closePreviewModal()" style="padding:4px 10px; font-size:0.8rem;"><i class="fas fa-xmark"></i> Close</button>
        </div>
        <div style="flex:1; display:grid; grid-template-columns:300px 1fr; overflow:hidden;">
            <!-- Left Side: Target Verification List -->
            <div style="border-right:1px solid var(--border); display:flex; flex-direction:column; overflow:hidden; background:var(--input-bg);">
                <div style="padding:12px 16px; border-bottom:1px solid var(--border); background:var(--card); font-weight:700; font-size:0.85rem; color:var(--text-muted); display:flex; justify-content:space-between; align-items:center;">
                    <span>Verified Targets</span>
                    <span id="target-count" class="badge blue" style="font-size:0.75rem;">0</span>
                </div>
                <div id="target-list-container" style="flex:1; overflow-y:auto; padding:12px; display:flex; flex-direction:column; gap:8px;">
                    <!-- Recipients list dynamically inserted here -->
                </div>
            </div>
            <!-- Right Side: Email Sample Preview -->
            <div style="display:flex; flex-direction:column; overflow:hidden;">
                <div style="padding:12px 20px; border-bottom:1px solid var(--border); background:var(--card); display:flex; flex-direction:column; gap:4px;">
                    <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">Sample Subject Preview:</div>
                    <div id="preview-subject-text" style="font-weight:700; color:var(--text-main); font-size:0.95rem;">-</div>
                </div>
                <div style="flex:1; overflow-y:auto; padding:20px; background:#f5f5f4; display:flex; justify-content:center;">
                    <div style="width:100%; max-width:580px;" id="preview-body-iframe-container">
                        <!-- Rendered template body dynamically inserted here inside an iframe -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'admin_footer.php'; ?>
