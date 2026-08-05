<?php
/**
 * PEPP Learning Admin - shared page shell (sidebar + topbar).
 * Usage (after auth.php + database.php):
 *   $active_page = 'dashboard';   // nav key
 *   $page_title  = 'Dashboard';
 *   $page_sub    = 'Overview of your admission system';
 *   include 'includes/admin_nav.php';
 *   ... page content ...
 *   include 'includes/admin_footer.php';
 */
if (!isset($active_page)) $active_page = '';
if (!isset($page_title))  $page_title  = 'PEPP Learning Admin';
if (!isset($page_sub))    $page_sub    = '';

// Live badge counts for the sidebar (cheap, indexed queries)
$nav_pending_approvals = 0;
$nav_due_leads = 0;
try {
    if (function_exists('can_access') && can_access('leads')) {
        $__lc = $pdo->query("SHOW TABLES LIKE 'leads'")->fetchColumn();
        if ($__lc) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE next_followup_date IS NOT NULL AND next_followup_date <= CURDATE() AND status NOT IN ('converted','rejected','not_interested')");
            $nav_due_leads = (int)$stmt->fetchColumn();
        }
    }
} catch (Exception $e) { $nav_due_leads = 0; }

// Marketing unread badges (green=referral, red=coupon)
$nav_mkt = ['referral' => 0, 'coupon' => 0];
if (function_exists('can_access') && can_access('marketing') && file_exists(__DIR__ . '/peppian_notify.php')) {
    require_once __DIR__ . '/peppian_notify.php';
    try { $nav_mkt = marketing_unread_counts($pdo); } catch (Exception $e) {}
}

// Reminders: load helper, send any due emails (once), and collect due/pending for the bell.
$nav_reminders_due = [];
$nav_reminders_pending = [];
if (file_exists(__DIR__ . '/reminders_helper.php')) {
    require_once __DIR__ . '/reminders_helper.php';
    try {
        reminders_send_due_emails($pdo);
        $nav_reminders_due     = reminders_due($pdo, $admin_username);
        $nav_reminders_pending = reminders_for($pdo, $admin_username, ['pending']);
    } catch (Exception $e) { error_log('nav reminders: ' . $e->getMessage()); }
}

// Automatic session reminders (12h / 4h / 10m / start) - runs lazily on page loads.
if (file_exists(__DIR__ . '/session_cron.php')) {
    require_once __DIR__ . '/session_cron.php';
    try {
        if (function_exists('sessions_dispatch_due')) sessions_dispatch_due($pdo);
        if (function_exists('installments_dispatch_reminders')) installments_dispatch_reminders($pdo);
    }
    catch (Exception $e) { error_log('nav session/installment cron: ' . $e->getMessage()); }
}

// Email Campaigns: run due email campaigns and batch delivery queue.
if (file_exists(__DIR__ . '/email_campaigns_helper.php')) {
    require_once __DIR__ . '/email_campaigns_helper.php';
    try {
        email_campaigns_send_due($pdo);
    } catch (Exception $e) {
        error_log('nav email campaigns cron: ' . $e->getMessage());
    }
}
$nav_pending_payments  = 0;
$nav_pending_onboarding = 0;
$nav_due_within_10_days = 0;
$nav_active_forms_count = 0;
$nav_unread_submissions_count = 0;
try {
    $nav_pending_approvals  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    $nav_pending_payments   = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status = 'pending' AND paid_date IS NOT NULL")->fetchColumn();
    $nav_pending_onboarding = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'approved' AND (onboarding_status IS NULL OR onboarding_status <> 'completed')")->fetchColumn();
    $nav_due_within_10_days = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status = 'pending' AND paid_date IS NULL AND rejected_at IS NULL AND due_date <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)")->fetchColumn();
    $nav_active_forms_count = (int)$pdo->query("SELECT COUNT(*) FROM campaign_forms WHERE status = 'published'")->fetchColumn();
    $nav_unread_submissions_count = (int)$pdo->query("SELECT COUNT(*) FROM campaign_form_submissions WHERE is_read = 0 AND is_deleted = 0")->fetchColumn();
} catch (Exception $navEx) { /* sidebar still renders */ }

function nav_active($key, $active) { return $key === $active ? 'active' : ''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?> - PEPP Learning Admin</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/admin-theme.css" rel="stylesheet">
    <script>
        (function() {
            var theme = localStorage.getItem('admin-theme') || 'light';
            if (theme !== 'light') {
                document.documentElement.classList.add('theme-' + theme);
            }
        })();
    </script>
    <?php if (!empty($extra_head)) echo $extra_head; ?>
</head>
<body>
<div class="admin-shell">

    <div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar(false)"></div>

    <!-- ── SIDEBAR ── -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="logo.png" alt="PEPP Learning">
            <div>
                <div class="brand-name">PEPP Learning</div>
                <div class="brand-sub">Admin Console</div>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-label">Overview</div>
            <?php if (can_access('dashboard')): ?>
            <a class="nav-item <?php echo nav_active('dashboard', $active_page); ?>" href="dashboard.php">
                <i class="fas fa-gauge-high"></i> Dashboard
            </a>
            <?php endif; ?>
        </div>

        <?php if (can_access('approvals') || can_access('add-student')): ?>
        <div class="nav-section">
            <div class="nav-section-label">Registrations</div>
            <?php if (can_access('approvals')): ?>
            <a class="nav-item <?php echo nav_active('approvals', $active_page); ?>" href="student-approval.php">
                <i class="fas fa-user-check"></i> Approvals
                <?php if ($nav_pending_approvals > 0): ?><span class="nav-badge"><?php echo $nav_pending_approvals; ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (can_access('add-student')): ?>
            <a class="nav-item <?php echo nav_active('add-student', $active_page); ?>" href="add-student.php">
                <i class="fas fa-user-plus"></i> Add Student
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (can_access('students') || can_access('onboarding') || can_access('sessions')): ?>
        <div class="nav-section">
            <div class="nav-section-label">Students</div>
            <?php if (can_access('students')): ?>
            <a class="nav-item <?php echo nav_active('students', $active_page); ?>" href="studentpage.php">
                <i class="fas fa-users"></i> All Students
            </a>
            <?php endif; ?>
            <?php if (can_access('onboarding')): ?>
            <a class="nav-item <?php echo nav_active('onboarding', $active_page); ?>" href="studentonboarding.php">
                <i class="fas fa-handshake"></i> Onboarding
                <?php if ($nav_pending_onboarding > 0): ?><span class="nav-badge"><?php echo $nav_pending_onboarding; ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (can_access('sessions')): ?>
            <a class="nav-item <?php echo nav_active('sessions', $active_page); ?>" href="sessions.php">
                <i class="fas fa-video"></i> Sessions
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (can_access('leads') || can_access('accounts') || can_access('marketing') || can_access('alumni')): ?>
        <div class="nav-section">
            <div class="nav-section-label">CRM</div>
            <?php if (can_access('leads')): ?>
            <a class="nav-item <?php echo nav_active('leads', $active_page); ?>" href="lead-management.php">
                <i class="fas fa-user-tag"></i> Lead Management
                <?php if (!empty($nav_due_leads) && $nav_due_leads > 0): ?><span class="nav-badge"><?php echo $nav_due_leads; ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (can_access('marketing')): ?>
            <a class="nav-item <?php echo nav_active('marketing', $active_page); ?>" href="marketing.php">
                <i class="fas fa-bullhorn"></i> Marketing
                <span style="margin-left:auto; display:inline-flex; gap:4px;">
                    <?php if (!empty($nav_mkt['referral'])): ?><span class="nav-badge" style="background:#16a34a; color:#fff;" title="New referral updates"><?php echo (int)$nav_mkt['referral']; ?></span><?php endif; ?>
                    <?php if (!empty($nav_mkt['coupon'])): ?><span class="nav-badge" style="background:#dc2626; color:#fff;" title="New coupon updates"><?php echo (int)$nav_mkt['coupon']; ?></span><?php endif; ?>
                </span>
            </a>
            <?php endif; ?>
            <?php if (can_access('marketing')): ?>
            <a class="nav-item <?php echo nav_active('email-campaigns', $active_page); ?>" href="email-campaigns.php">
                <i class="fas fa-envelope"></i> Email Campaigns
            </a>
            <?php endif; ?>
            <?php if (can_access('alumni')): ?>
            <a class="nav-item <?php echo nav_active('alumni', $active_page); ?>" href="alumni-database.php">
                <i class="fas fa-user-graduate"></i> Alumni Database
            </a>
            <?php endif; ?>
            <?php if (can_access('peppkit')): ?>
            <a class="nav-item <?php echo nav_active('peppkit', $active_page); ?>" href="peppkit-report.php">
                <i class="fas fa-box-open"></i> PEPPKIT Report
            </a>
            <?php endif; ?>
            <?php if (can_access('cards')): ?>
            <a class="nav-item <?php echo nav_active('cards', $active_page); ?>" href="cards.php">
                <i class="fas fa-id-card"></i> Generate Custom Cards
            </a>
            <?php endif; ?>
            <?php if (can_access('accounts')): ?>
            <a class="nav-item <?php echo nav_active('accounts', $active_page); ?>" href="accounts.php">
                <i class="fas fa-wallet"></i> Accounts &amp; Expenses
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (can_access('campaigns')): ?>
        <div class="nav-section">
            <div class="nav-section-label">Campaigns</div>
            <a class="nav-item <?php echo nav_active('campaigns', $active_page); ?>" href="campaign-forms.php">
                <i class="fab fa-wpforms"></i> Custom Forms
                <span style="margin-left:auto; display:inline-flex; gap:4px; align-items:center;">
                    <?php if ($nav_active_forms_count > 0): ?>
                        <span class="nav-badge" style="background:rgba(34, 197, 94, 0.15); color:#22c55e; border:1px solid rgba(34, 197, 94, 0.3); padding:2px 6px; border-radius:6px; font-size:0.7rem; font-weight:700;" title="Active campaign forms"><?php echo $nav_active_forms_count; ?> Active</span>
                    <?php endif; ?>
                    <?php if ($nav_unread_submissions_count > 0): ?>
                        <span class="nav-badge" style="background:rgba(59, 130, 246, 0.15); color:#3b82f6; border:1px solid rgba(59, 130, 246, 0.3); padding:2px 6px; border-radius:6px; font-size:0.7rem; font-weight:700;" title="New unread registrations"><?php echo $nav_unread_submissions_count; ?> New</span>
                    <?php endif; ?>
                </span>
            </a>
        </div>
        <?php endif; ?>

        <?php if (can_access('installments') || can_access('whatsapp') || can_access('invoices')): ?>
        <div class="nav-section">
            <div class="nav-section-label">Payments</div>
            <?php if (can_access('installments')): ?>
            <a class="nav-item <?php echo nav_active('installments', $active_page); ?>" href="phpinstalmentpaymentupdate.php">
                <i class="fas fa-money-bill-wave"></i> Installments
                <span style="margin-left:auto; display:inline-flex; gap:4px; align-items:center;">
                    <?php if ($nav_pending_payments > 0): ?>
                        <span class="nav-badge" style="background:#f59e0b; color:#fff;" title="Pending review"><?php echo $nav_pending_payments; ?></span>
                    <?php endif; ?>
                    <?php if ($nav_due_within_10_days > 0): ?>
                        <span class="nav-badge" style="background:#ef4444; color:#fff;" title="Due within 10 days"><?php echo $nav_due_within_10_days; ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <?php endif; ?>
            <?php if (can_access('invoices')): ?>
            <a class="nav-item <?php echo nav_active('invoices', $active_page); ?>" href="invoices.php">
                <i class="fas fa-file-invoice"></i> Invoices
            </a>
            <?php endif; ?>
            <?php if (can_access('whatsapp')): ?>
            <a class="nav-item <?php echo nav_active('whatsapp', $active_page); ?>" href="whatsapp-notification.php">
                <i class="fab fa-whatsapp"></i> WhatsApp Messages
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (can_access('courses') || can_access('faculties') || can_access('studyplans')): ?>
        <div class="nav-section">
            <div class="nav-section-label">Academics</div>
            <?php if (can_access('courses')): ?>
            <a class="nav-item <?php echo nav_active('courses', $active_page); ?>" href="course-management.php">
                <i class="fas fa-book-open"></i> Courses
            </a>
            <?php endif; ?>
            <?php if (can_access('faculties')): ?>
            <a class="nav-item <?php echo nav_active('faculties', $active_page); ?>" href="faculties.php">
                <i class="fas fa-chalkboard-user"></i> Faculties
            </a>
            <?php endif; ?>
            <?php if (can_access('studyplans')): ?>
            <a class="nav-item <?php echo nav_active('studyplans', $active_page); ?>" href="studyplans.php">
                <i class="fas fa-calendar-days"></i> Study Plans
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>


        <div class="nav-section">
            <div class="nav-section-label">System</div>
            <?php if (can_access('settings')): ?>
            <a class="nav-item <?php echo nav_active('settings', $active_page); ?>" href="settings.php">
                <i class="fas fa-gear"></i> Settings
            </a>
            <?php endif; ?>
            <?php if (is_super_admin()): ?>
            <a class="nav-item <?php echo nav_active('admin-management', $active_page); ?>" href="admin-management.php">
                <i class="fas fa-user-shield"></i> Admin Management
            </a>
            <a class="nav-item <?php echo nav_active('admin-activity', $active_page); ?>" href="admin-activity.php">
                <i class="fas fa-clock-rotate-left"></i> Activity Log
            </a>
            <a class="nav-item <?php echo nav_active('reports', $active_page); ?>" href="reports.php">
                <i class="fas fa-chart-pie"></i> Reports &amp; Export
            </a>
            <?php endif; ?>
            <a class="nav-item" href="register.php" target="_blank">
                <i class="fas fa-arrow-up-right-from-square"></i> Registration Form
            </a>
        </div>

        <div class="sidebar-footer">
            <a class="nav-item" href="?logout=1" onclick="return confirm('Log out of the admin console?');">
                <i class="fas fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </aside>

    <!-- ── MAIN ── -->
    <div class="main-area">
        <header class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle menu"><i class="fas fa-bars"></i></button>
            <div>
                <h1><?php echo e($page_title); ?></h1>
                <?php if ($page_sub): ?><div class="page-sub"><?php echo e($page_sub); ?></div><?php endif; ?>
            </div>
            <div class="topbar-right">
                <button type="button" class="reminder-bell" id="theme-toggle-btn" style="margin-right:4px;" title="Switch Theme" aria-label="Switch Theme">
                    <i class="fas fa-sun" id="theme-toggle-icon"></i>
                </button>
                <button type="button" class="reminder-bell <?php echo !empty($nav_reminders_due) ? 'has-due' : ''; ?>" onclick="openModal('reminders-modal')" title="Reminders" aria-label="Reminders">
                    <i class="fas fa-bell"></i>
                    <?php if (!empty($nav_reminders_pending)): ?><span class="reminder-count"><?php echo count($nav_reminders_pending); ?></span><?php endif; ?>
                </button>
                <div class="admin-chip">
                    <span class="avatar"><?php echo strtoupper(substr($admin_username, 0, 1)); ?></span>
                    <span><?php echo e($admin_username); ?>
                        <span style="display:block;font-size:.62rem;color:var(--muted-foreground);font-weight:600;line-height:1.1;">
                            <?php echo is_super_admin() ? 'Super Admin' : 'Admin'; ?>
                        </span>
                    </span>
                </div>
            </div>
        </header>

        <main class="content">
