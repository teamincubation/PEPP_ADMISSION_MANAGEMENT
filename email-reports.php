<?php
/**
 * PEPP Learning ERP - Enterprise Email Dispatch Reports & Analytics.
 * Restricted exclusively to Super Administrators.
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/email_campaigns_helper.php';

// Strict Super Admin Access Enforcement
if (!is_super_admin()) {
    http_response_code(403);
    die("<div style='color:#ef4444; font-family:sans-serif; text-align:center; margin-top:50px; padding:20px; border:1px solid #fca5a5; background:#fef2f2; border-radius:12px; max-width:500px; margin-left:auto; margin-right:auto;'><h3>Access Denied</h3><p>Email Dispatch Reports are restricted exclusively to Super Administrators.</p><a href='dashboard.php' style='display:inline-block; margin-top:10px; color:#7c3aed; font-weight:600;'>Return to Dashboard</a></div>");
}

check_and_create_email_campaign_tables($pdo);

$active_page = 'email-reports';
$page_title  = 'Email Dispatch Reports';
$page_sub    = 'Comprehensive enterprise logs, delivery analytics, and dispatch tracking for all system emails';

$success_message = '';
$error_message   = '';

// ── RETRY / RESEND ACTION ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry_email') {
    if (!csrf_verify()) {
        $error_message = 'CSRF token verification failed.';
    } else {
        $log_id = (int)($_POST['log_id'] ?? 0);
        $source_module = trim($_POST['source_module'] ?? '');
        
        if ($source_module === 'email_campaigns' && $log_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM email_queue WHERE id = ?");
            $stmt->execute([$log_id]);
            $item = $stmt->fetch();
            if ($item) {
                $html_body = build_campaign_email_html($item['body']);
                $sent = send_custom_email($item['recipient_email'], $item['subject'], $html_body);
                if ($sent) {
                    $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), error_message = NULL WHERE id = ?")->execute([$log_id]);
                    log_admin_activity($pdo, $admin_username, 'email_resent', "Resent campaign email #{$log_id} to {$item['recipient_email']}");
                    $success_message = "Email dispatch re-sent successfully to {$item['recipient_email']}.";
                } else {
                    $pdo->prepare("UPDATE email_queue SET status = 'failed', error_message = 'Manual resend failed via mailer' WHERE id = ?")->execute([$log_id]);
                    $error_message = "Failed to resend email to {$item['recipient_email']}.";
                }
            }
        } elseif ($source_module === 'communication_engine' && $log_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
            $stmt->execute([$log_id]);
            $item = $stmt->fetch();
            if ($item) {
                require_once 'includes/mailer.php';
                $sent = pepp_mail($item['recipient'], $item['subject'] ?: 'PEPP Learning Notification', $item['body_html'] ?: nl2br(htmlspecialchars($item['body_text'])), $item['body_text'] ?: strip_tags($item['body_html']));
                if ($sent) {
                    $pdo->prepare("UPDATE communication_queue SET status = 'sent', updated_at = NOW(), error_message = NULL WHERE id = ?")->execute([$log_id]);
                    log_admin_activity($pdo, $admin_username, 'email_resent', "Resent communication email #{$log_id} to {$item['recipient']}");
                    $success_message = "Communication email re-sent successfully to {$item['recipient']}.";
                } else {
                    $pdo->prepare("UPDATE communication_queue SET status = 'failed', error_message = 'Manual resend failed' WHERE id = ?")->execute([$log_id]);
                    $error_message = "Failed to resend email to {$item['recipient']}.";
                }
            }
        }
    }
}

// ── FILTER PARAMETERS ──
$filter_module = trim($_GET['module'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_admin  = trim($_GET['admin'] ?? '');
$filter_search = trim($_GET['search'] ?? '');
$date_range    = trim($_GET['date_range'] ?? '');
$start_date    = trim($_GET['start_date'] ?? '');
$end_date      = trim($_GET['end_date'] ?? '');

// Date Range Shortcut Resolver
if ($date_range === 'today') {
    $start_date = date('Y-m-d');
    $end_date   = date('Y-m-d');
} elseif ($date_range === '7days') {
    $start_date = date('Y-m-d', strtotime('-6 days'));
    $end_date   = date('Y-m-d');
} elseif ($date_range === '30days') {
    $start_date = date('Y-m-d', strtotime('-29 days'));
    $end_date   = date('Y-m-d');
} elseif ($date_range === 'this_month') {
    $start_date = date('Y-m-01');
    $end_date   = date('Y-m-t');
}

// ── UNIFIED QUERY BUILDING ──
$sub_queries = [];

// 1. Marketing Email Campaigns Queue
$sub_queries[] = "
    SELECT 
        eq.id as unique_id,
        'email_campaigns' as module_type,
        'Bulk Email Campaign' as module_label,
        COALESCE(ec.subject, 'Marketing Campaign') as campaign_title,
        eq.recipient_email,
        eq.recipient_name,
        eq.subject,
        eq.body as body_preview,
        eq.status,
        eq.error_message,
        COALESCE(eq.sent_at, eq.created_at) as dispatched_at,
        eq.created_at,
        COALESCE(ec.created_by, 'System') as admin_username
    FROM email_queue eq
    LEFT JOIN email_campaigns ec ON eq.campaign_id = ec.id
";

// 2. Communication Engine Queue (channel = 'email')
$has_comm_table = false;
try {
    $has_comm_table = (bool)$pdo->query("SHOW TABLES LIKE 'communication_queue'")->fetchColumn();
} catch (Throwable $e) {}

if ($has_comm_table) {
    $sub_queries[] = "
        SELECT 
            cq.id as unique_id,
            'communication_engine' as module_type,
            'Communication Engine' as module_label,
            COALESCE(cq.template_name, 'Direct Dispatch') as campaign_title,
            cq.recipient as recipient_email,
            cq.recipient_name,
            COALESCE(cq.subject, 'Notification') as subject,
            COALESCE(cq.body_html, cq.body_text) as body_preview,
            cq.status,
            cq.error_message,
            COALESCE(cq.updated_at, cq.created_at) as dispatched_at,
            cq.created_at,
            COALESCE(cq.sent_by, 'System') as admin_username
        FROM communication_queue cq
        WHERE cq.channel = 'email'
    ";
}

// 3. Invoice Email Dispatches
$has_inv_col = false;
try {
    $has_inv_table = (bool)$pdo->query("SHOW TABLES LIKE 'invoices'")->fetchColumn();
    if ($has_inv_table) {
        $has_inv_col = (bool)$pdo->query("SHOW COLUMNS FROM invoices LIKE 'email_status'")->fetch();
    }
} catch (Throwable $e) {}
if ($has_inv_col) {
    $sub_queries[] = "
        SELECT 
            inv.id as unique_id,
            'invoices' as module_type,
            'Invoices & Billing' as module_label,
            CONCAT('Invoice #', COALESCE(inv.invoice_no, inv.id)) as campaign_title,
            inv.email as recipient_email,
            inv.student_name as recipient_name,
            CONCAT('Invoice #', COALESCE(inv.invoice_no, inv.id), ' - PEPP Learning') as subject,
            CONCAT('Invoice notification dispatched for ', inv.student_name) as body_preview,
            CASE WHEN inv.email_status = 'sent' THEN 'sent' WHEN inv.email_status = 'failed' THEN 'failed' ELSE 'pending' END as status,
            NULL as error_message,
            COALESCE(inv.paid_date, inv.created_at) as dispatched_at,
            inv.created_at,
            COALESCE(inv.generated_by, 'System') as admin_username
        FROM invoices inv
        WHERE inv.email_status IS NOT NULL AND inv.email_status != ''
    ";
}

$unified_sql = "SELECT * FROM (" . implode(" UNION ALL ", $sub_queries) . ") AS aggregated_emails WHERE 1=1";
$params = [];

if ($filter_module !== '') {
    $unified_sql .= " AND module_type = ?";
    $params[] = $filter_module;
}

if ($filter_status !== '') {
    if ($filter_status === 'sent') {
        $unified_sql .= " AND status IN ('sent', 'delivered', 'read')";
    } else {
        $unified_sql .= " AND status = ?";
        $params[] = $filter_status;
    }
}

if ($filter_admin !== '') {
    $unified_sql .= " AND admin_username = ?";
    $params[] = $filter_admin;
}

if ($start_date !== '') {
    $unified_sql .= " AND DATE(dispatched_at) >= ?";
    $params[] = $start_date;
}

if ($end_date !== '') {
    $unified_sql .= " AND DATE(dispatched_at) <= ?";
    $params[] = $end_date;
}

if ($filter_search !== '') {
    $unified_sql .= " AND (recipient_email LIKE ? OR recipient_name LIKE ? OR subject LIKE ? OR campaign_title LIKE ?)";
    $term = '%' . $filter_search . '%';
    $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
}

// Handle CSV Export Request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_sql = $unified_sql . " ORDER BY dispatched_at DESC";
    $stmt_exp = $pdo->prepare($export_sql);
    $stmt_exp->execute($params);
    $rows_exp = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="email_dispatches_report_' . date('Y-m-d_H-i') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Module', 'Campaign / Purpose', 'Recipient Name', 'Recipient Email', 'Subject', 'Status', 'Dispatched At', 'Admin', 'Error Message']);
    foreach ($rows_exp as $r) {
        fputcsv($out, [
            $r['unique_id'],
            $r['module_label'],
            $r['campaign_title'],
            $r['recipient_name'] ?: 'N/A',
            $r['recipient_email'],
            $r['subject'],
            strtoupper($r['status']),
            $r['dispatched_at'],
            $r['admin_username'],
            $r['error_message'] ?: ''
        ]);
    }
    fclose($out);
    exit();
}

// KPI Statistics Metrics
$total_all = 0; $sent_count = 0; $failed_count = 0; $pending_count = 0;
try {
    $stmt_kpi = $pdo->prepare($unified_sql);
    $stmt_kpi->execute($params);
    $all_records = $stmt_kpi->fetchAll(PDO::FETCH_ASSOC);
    $total_all = count($all_records);
    foreach ($all_records as $rec) {
        $st = strtolower($rec['status']);
        if (in_array($st, ['sent', 'delivered', 'read'])) {
            $sent_count++;
        } elseif (in_array($st, ['failed', 'cancelled'])) {
            $failed_count++;
        } else {
            $pending_count++;
        }
    }
} catch (Exception $ex) {}

// Fetch distinct admins for dropdown
$admins_list = [];
try {
    $admins_list = $pdo->query("SELECT DISTINCT username FROM admins ORDER BY username ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {}

// Pagination Setup
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$total_pages = max(1, ceil($total_all / $per_page));
$offset = ($page - 1) * $per_page;

$final_sql = $unified_sql . " ORDER BY dispatched_at DESC LIMIT $per_page OFFSET $offset";
$stmt_page = $pdo->prepare($final_sql);
$stmt_page->execute($params);
$reports = $stmt_page->fetchAll(PDO::FETCH_ASSOC);

include 'includes/admin_nav.php';
?>

<div class="container-fluid" style="padding:24px; max-width:1400px; margin:0 auto;">
    
    <!-- Top Header Banner -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
        <div>
            <h1 style="font-size:1.75rem; font-weight:700; color:var(--text, #0f172a); margin:0 0 4px 0; display:flex; align-items:center; gap:10px;">
                <span style="background:linear-gradient(135deg, #7c3aed, #4f46e5); color:#fff; width:42px; height:42px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; font-size:1.2rem; box-shadow:0 4px 12px rgba(124,58,237,0.25);">
                    <i class="fas fa-envelope-open-text"></i>
                </span>
                Email Dispatch Reports
            </h1>
            <p style="color:var(--text-muted, #64748b); margin:0; font-size:0.9rem;">
                Unified enterprise audit log and delivery metrics for all outgoing system emails
            </p>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline" style="background:#fff; border:1px solid var(--border, #cbd5e1); color:var(--text, #334155); border-radius:10px; padding:10px 18px; font-weight:600; font-size:0.88rem; display:inline-flex; align-items:center; gap:8px; text-decoration:none; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <i class="fas fa-file-csv" style="color:#10b981; font-size:1rem;"></i> Export Report (CSV)
            </a>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:14px 18px; border-radius:12px; margin-bottom:20px; display:flex; align-items:center; gap:10px;">
            <i class="fas fa-circle-check" style="font-size:1.1rem;"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-danger" style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:14px 18px; border-radius:12px; margin-bottom:20px; display:flex; align-items:center; gap:10px;">
            <i class="fas fa-triangle-exclamation" style="font-size:1.1rem;"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- KPI Metric Cards Banner -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:18px; margin-bottom:24px;">
        <div style="background:var(--card, #fff); border:1px solid var(--border, #e2e8f0); border-radius:16px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <span style="font-size:0.85rem; font-weight:600; color:var(--text-muted, #64748b); text-transform:uppercase; letter-spacing:0.5px;">Total Dispatches</span>
                <span style="background:#f1f5f9; color:#475569; width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-paper-plane"></i></span>
            </div>
            <div style="font-size:1.8rem; font-weight:800; color:var(--text, #0f172a);"><?php echo number_format($total_all); ?></div>
            <div style="font-size:0.8rem; color:#64748b; margin-top:4px;">Across all modules & campaigns</div>
        </div>

        <div style="background:var(--card, #fff); border:1px solid var(--border, #e2e8f0); border-radius:16px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <span style="font-size:0.85rem; font-weight:600; color:#166534; text-transform:uppercase; letter-spacing:0.5px;">Delivered / Sent</span>
                <span style="background:#dcfce7; color:#15803d; width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-circle-check"></i></span>
            </div>
            <div style="font-size:1.8rem; font-weight:800; color:#15803d;"><?php echo number_format($sent_count); ?></div>
            <div style="font-size:0.8rem; color:#166534; margin-top:4px;">
                <?php echo $total_all > 0 ? number_format(($sent_count / $total_all) * 100, 1) . '% delivery rate' : '0% delivery rate'; ?>
            </div>
        </div>

        <div style="background:var(--card, #fff); border:1px solid var(--border, #e2e8f0); border-radius:16px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <span style="font-size:0.85rem; font-weight:600; color:#991b1b; text-transform:uppercase; letter-spacing:0.5px;">Failed / Bounced</span>
                <span style="background:#fee2e2; color:#b91c1c; width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-circle-xmark"></i></span>
            </div>
            <div style="font-size:1.8rem; font-weight:800; color:#b91c1c;"><?php echo number_format($failed_count); ?></div>
            <div style="font-size:0.8rem; color:#991b1b; margin-top:4px;">Requires verification or retry</div>
        </div>

        <div style="background:var(--card, #fff); border:1px solid var(--border, #e2e8f0); border-radius:16px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <span style="font-size:0.85rem; font-weight:600; color:#92400e; text-transform:uppercase; letter-spacing:0.5px;">Pending / Queued</span>
                <span style="background:#fef3c7; color:#b45309; width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-hourglass-half"></i></span>
            </div>
            <div style="font-size:1.8rem; font-weight:800; color:#b45309;"><?php echo number_format($pending_count); ?></div>
            <div style="font-size:0.8rem; color:#92400e; margin-top:4px;">Waiting in dispatch queue</div>
        </div>
    </div>

    <!-- Filter Toolbar Form -->
    <div style="background:var(--card, #fff); border:1px solid var(--border, #e2e8f0); border-radius:16px; padding:20px; margin-bottom:24px; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
        <form method="GET" style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
            
            <div style="flex:1; min-width:220px;">
                <label style="font-size:0.82rem; font-weight:600; color:var(--text-muted, #64748b); margin-bottom:6px; display:block;">Search Keywords</label>
                <div style="position:relative;">
                    <i class="fas fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8;"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Search email, name, subject..." class="form-control" style="padding-left:36px; height:42px; border-radius:10px; border:1px solid var(--border, #cbd5e1); width:100%;">
                </div>
            </div>

            <div style="min-width:160px;">
                <label style="font-size:0.82rem; font-weight:600; color:var(--text-muted, #64748b); margin-bottom:6px; display:block;">Module / Source</label>
                <select name="module" class="form-select" style="height:42px; border-radius:10px; border:1px solid var(--border, #cbd5e1); width:100%;">
                    <option value="">All Modules</option>
                    <option value="email_campaigns" <?php echo $filter_module === 'email_campaigns' ? 'selected' : ''; ?>>Bulk Email Campaigns</option>
                    <option value="communication_engine" <?php echo $filter_module === 'communication_engine' ? 'selected' : ''; ?>>Communication Engine</option>
                    <option value="invoices" <?php echo $filter_module === 'invoices' ? 'selected' : ''; ?>>Invoices & Receipts</option>
                </select>
            </div>

            <div style="min-width:150px;">
                <label style="font-size:0.82rem; font-weight:600; color:var(--text-muted, #64748b); margin-bottom:6px; display:block;">Delivery Status</label>
                <select name="status" class="form-select" style="height:42px; border-radius:10px; border:1px solid var(--border, #cbd5e1); width:100%;">
                    <option value="">All Statuses</option>
                    <option value="sent" <?php echo $filter_status === 'sent' ? 'selected' : ''; ?>>Sent / Delivered</option>
                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending / Queued</option>
                    <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed / Bounced</option>
                </select>
            </div>

            <div style="min-width:150px;">
                <label style="font-size:0.82rem; font-weight:600; color:var(--text-muted, #64748b); margin-bottom:6px; display:block;">Admin</label>
                <select name="admin" class="form-select" style="height:42px; border-radius:10px; border:1px solid var(--border, #cbd5e1); width:100%;">
                    <option value="">All Admins</option>
                    <?php foreach ($admins_list as $adm): ?>
                        <option value="<?php echo htmlspecialchars($adm); ?>" <?php echo $filter_admin === $adm ? 'selected' : ''; ?>><?php echo htmlspecialchars($adm); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="min-width:140px;">
                <label style="font-size:0.82rem; font-weight:600; color:var(--text-muted, #64748b); margin-bottom:6px; display:block;">Date Shortcut</label>
                <select name="date_range" class="form-select" style="height:42px; border-radius:10px; border:1px solid var(--border, #cbd5e1); width:100%;" onchange="this.form.submit()">
                    <option value="">Custom Range</option>
                    <option value="today" <?php echo $date_range === 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="7days" <?php echo $date_range === '7days' ? 'selected' : ''; ?>>Last 7 Days</option>
                    <option value="30days" <?php echo $date_range === '30days' ? 'selected' : ''; ?>>Last 30 Days</option>
                    <option value="this_month" <?php echo $date_range === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                </select>
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary" style="height:42px; border-radius:10px; padding:0 20px; font-weight:600; background:linear-gradient(135deg, #7c3aed, #6d28d9); border:none; color:#fff;">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <a href="email-reports.php" class="btn btn-outline" style="height:42px; border-radius:10px; padding:0 14px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; background:#f8fafc; border:1px solid #cbd5e1; color:#64748b;" title="Reset Filters">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Data Table Card -->
    <div style="background:var(--card, #fff); border:1px solid var(--border, #e2e8f0); border-radius:16px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
        <div style="overflow-x:auto;">
            <table class="table" style="width:100%; border-collapse:collapse; text-align:left; font-size:0.88rem;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:1px solid var(--border, #e2e8f0); color:var(--text-muted, #64748b); font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.5px;">
                        <th style="padding:16px 20px;">Module & Campaign</th>
                        <th style="padding:16px 20px;">Recipient</th>
                        <th style="padding:16px 20px;">Subject</th>
                        <th style="padding:16px 20px;">Status</th>
                        <th style="padding:16px 20px;">Dispatched At</th>
                        <th style="padding:16px 20px;">Admin</th>
                        <th style="padding:16px 20px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:48px 20px; color:#94a3b8;">
                                <i class="fas fa-envelope-open" style="font-size:2.5rem; margin-bottom:12px; opacity:0.4; display:block;"></i>
                                <div style="font-weight:600; font-size:1rem; color:#475569;">No email dispatches found</div>
                                <div style="font-size:0.84rem; margin-top:4px;">Try adjusting your filter criteria or date range</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reports as $r): ?>
                            <?php 
                            $status_st = strtolower($r['status']);
                            $badge_class = 'background:#f1f5f9; color:#475569;';
                            $status_icon = 'fa-clock';
                            $status_text = ucfirst($status_st);
                            
                            if (in_array($status_st, ['sent', 'delivered', 'read'])) {
                                $badge_class = 'background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;';
                                $status_icon = 'fa-circle-check';
                                $status_text = 'Delivered';
                            } elseif (in_array($status_st, ['failed', 'cancelled'])) {
                                $badge_class = 'background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;';
                                $status_icon = 'fa-circle-xmark';
                                $status_text = 'Failed';
                            } else {
                                $badge_class = 'background:#fef3c7; color:#b45309; border:1px solid #fde68a;';
                                $status_icon = 'fa-hourglass-half';
                                $status_text = 'Queued';
                            }

                            // Module icon badge
                            $mod_bg = '#f3e8ff'; $mod_fg = '#7c3aed'; $mod_icon = 'fa-bullhorn';
                            if ($r['module_type'] === 'communication_engine') {
                                $mod_bg = '#e0f2fe'; $mod_fg = '#0284c7'; $mod_icon = 'fa-network-wired';
                            } elseif ($r['module_type'] === 'invoices') {
                                $mod_bg = '#dcfce7'; $mod_fg = '#16a34a'; $mod_icon = 'fa-file-invoice-dollar';
                            }
                            ?>
                            <tr style="border-bottom:1px solid var(--border, #f1f5f9); transition:background .15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <td style="padding:16px 20px;">
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <span style="background:<?php echo $mod_bg; ?>; color:<?php echo $mod_fg; ?>; width:32px; height:32px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; font-size:0.85rem; flex-shrink:0;">
                                            <i class="fas <?php echo $mod_icon; ?>"></i>
                                        </span>
                                        <div>
                                            <div style="font-weight:600; color:var(--text, #0f172a);"><?php echo htmlspecialchars($r['module_label']); ?></div>
                                            <div style="font-size:0.78rem; color:var(--text-muted, #64748b);"><?php echo htmlspecialchars($r['campaign_title']); ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td style="padding:16px 20px;">
                                    <div style="font-weight:600; color:var(--text, #1e293b);"><?php echo htmlspecialchars($r['recipient_name'] ?: 'Recipient'); ?></div>
                                    <div style="font-size:0.8rem; color:#64748b; font-family:monospace;"><?php echo htmlspecialchars($r['recipient_email']); ?></div>
                                </td>

                                <td style="padding:16px 20px; max-width:280px;">
                                    <div style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-weight:500;" title="<?php echo htmlspecialchars($r['subject']); ?>">
                                        <?php echo htmlspecialchars($r['subject']); ?>
                                    </div>
                                </td>

                                <td style="padding:16px 20px;">
                                    <span style="display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:20px; font-size:0.78rem; font-weight:600; <?php echo $badge_class; ?>">
                                        <i class="fas <?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                                    </span>
                                </td>

                                <td style="padding:16px 20px; color:#475569; font-size:0.82rem;">
                                    <?php echo date('M d, Y', strtotime($r['dispatched_at'])); ?><br>
                                    <span style="font-size:0.75rem; color:#94a3b8;"><?php echo date('h:i A', strtotime($r['dispatched_at'])); ?></span>
                                </td>

                                <td style="padding:16px 20px;">
                                    <span style="background:#f1f5f9; color:#334155; padding:4px 10px; border-radius:6px; font-size:0.78rem; font-weight:600;">
                                        <i class="fas fa-user-shield" style="font-size:0.75rem; color:#64748b;"></i> <?php echo htmlspecialchars($r['admin_username']); ?>
                                    </span>
                                </td>

                                <td style="padding:16px 20px; text-align:right;">
                                    <button type="button" class="btn btn-sm btn-outline" style="border-radius:8px; font-size:0.8rem; padding:6px 12px; background:#fff; border:1px solid #cbd5e1;" onclick='showEmailDetails(<?php echo json_encode($r); ?>)'>
                                        <i class="fas fa-eye"></i> Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <?php if ($total_pages > 1): ?>
            <div style="padding:16px 20px; background:#f8fafc; border-top:1px solid var(--border, #e2e8f0); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div style="font-size:0.84rem; color:#64748b;">
                    Showing <strong><?php echo min($total_all, $offset + 1); ?></strong> to <strong><?php echo min($total_all, $offset + count($reports)); ?></strong> of <strong><?php echo $total_all; ?></strong> dispatches
                </div>
                <div style="display:flex; gap:6px;">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>" style="padding:6px 12px; border-radius:8px; font-size:0.84rem; font-weight:600; text-decoration:none; <?php echo $p === $page ? 'background:#7c3aed; color:#fff;' : 'background:#fff; border:1px solid #cbd5e1; color:#334155;'; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Email Details Modal -->
<div id="email-details-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:20px; max-width:650px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 50px rgba(0,0,0,0.2); animation:modalSlideUp .25s ease-out;">
        <div style="padding:20px 24px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border-top-left-radius:20px; border-top-right-radius:20px;">
            <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-envelope-open-text" style="color:#7c3aed;"></i> Email Dispatch Audit Details
            </h3>
            <button type="button" onclick="closeModal('email-details-modal')" style="background:none; border:none; font-size:1.2rem; color:#64748b; cursor:pointer;"><i class="fas fa-xmark"></i></button>
        </div>

        <div style="padding:24px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:20px; background:#f8fafc; padding:16px; border-radius:12px; border:1px solid #e2e8f0;">
                <div>
                    <span style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Module & Source</span>
                    <div id="md-module" style="font-weight:700; color:#0f172a; font-size:0.95rem; margin-top:2px;"></div>
                </div>
                <div>
                    <span style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Campaign / Purpose</span>
                    <div id="md-campaign" style="font-weight:600; color:#334155; font-size:0.9rem; margin-top:2px;"></div>
                </div>
                <div>
                    <span style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Recipient</span>
                    <div id="md-recipient" style="font-weight:600; color:#0f172a; font-size:0.9rem; margin-top:2px;"></div>
                </div>
                <div>
                    <span style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Dispatched By</span>
                    <div id="md-admin" style="font-weight:600; color:#0f172a; font-size:0.9rem; margin-top:2px;"></div>
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <label style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase; display:block; margin-bottom:4px;">Subject</label>
                <div id="md-subject" style="font-weight:600; color:#0f172a; font-size:0.95rem; padding:10px 14px; background:#fff; border:1px solid #cbd5e1; border-radius:8px;"></div>
            </div>

            <div style="margin-bottom:16px;" id="md-error-box">
                <label style="font-size:0.75rem; color:#b91c1c; font-weight:600; text-transform:uppercase; display:block; margin-bottom:4px;">Error Trace</label>
                <div id="md-error" style="color:#b91c1c; font-size:0.84rem; padding:10px 14px; background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; font-family:monospace;"></div>
            </div>

            <div>
                <label style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase; display:block; margin-bottom:4px;">Email Body Content Preview</label>
                <div id="md-body" style="padding:14px; background:#fff; border:1px solid #cbd5e1; border-radius:10px; max-height:220px; overflow-y:auto; font-size:0.85rem; color:#334155; line-height:1.5;"></div>
            </div>
        </div>

        <div style="padding:16px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; border-bottom-left-radius:20px; border-bottom-right-radius:20px;">
            <button type="button" onclick="closeModal('email-details-modal')" class="btn btn-outline" style="border-radius:10px; padding:8px 18px; background:#fff; border:1px solid #cbd5e1;">Close</button>
            <form method="POST" id="resend-form" style="margin:0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="retry_email">
                <input type="hidden" name="log_id" id="md-resend-id">
                <input type="hidden" name="source_module" id="md-resend-module">
                <button type="submit" class="btn btn-primary" style="background:#7c3aed; border:none; color:#fff; border-radius:10px; padding:8px 20px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-paper-plane"></i> Re-send Email Now
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function showEmailDetails(record) {
    document.getElementById('md-module').innerText = record.module_label;
    document.getElementById('md-campaign').innerText = record.campaign_title;
    document.getElementById('md-recipient').innerText = (record.recipient_name ? record.recipient_name + ' (' + record.recipient_email + ')' : record.recipient_email);
    document.getElementById('md-admin').innerText = record.admin_username;
    document.getElementById('md-subject').innerText = record.subject;
    document.getElementById('md-body').innerHTML = record.body_preview || '<em>No preview content available</em>';
    
    var errBox = document.getElementById('md-error-box');
    if (record.error_message) {
        errBox.style.display = 'block';
        document.getElementById('md-error').innerText = record.error_message;
    } else {
        errBox.style.display = 'none';
    }
    
    document.getElementById('md-resend-id').value = record.unique_id;
    document.getElementById('md-resend-module').value = record.module_type;
    
    openModal('email-details-modal');
}

function openModal(id) {
    var m = document.getElementById(id);
    if (m) m.style.display = 'flex';
}
function closeModal(id) {
    var m = document.getElementById(id);
    if (m) m.style.display = 'none';
}
</script>

<?php include 'includes/admin_footer.php'; ?>
