<?php
/**
 * PEPP Learning Admin — shared page shell (sidebar + topbar).
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
$nav_pending_payments  = 0;
$nav_pending_onboarding = 0;
try {
    $nav_pending_approvals  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    $nav_pending_payments   = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status = 'pending' AND paid_date IS NOT NULL")->fetchColumn();
    $nav_pending_onboarding = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'approved' AND (onboarding_status IS NULL OR onboarding_status <> 'completed')")->fetchColumn();
} catch (Exception $navEx) { /* sidebar still renders */ }

function nav_active($key, $active) { return $key === $active ? 'active' : ''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?> — PEPP Learning Admin</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/admin-theme.css" rel="stylesheet">
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

        <?php if (can_access('students') || can_access('onboarding')): ?>
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
        </div>
        <?php endif; ?>

        <?php if (can_access('installments') || can_access('whatsapp') || can_access('invoices')): ?>
        <div class="nav-section">
            <div class="nav-section-label">Payments</div>
            <?php if (can_access('installments')): ?>
            <a class="nav-item <?php echo nav_active('installments', $active_page); ?>" href="phpinstalmentpaymentupdate.php">
                <i class="fas fa-money-bill-wave"></i> Installments
                <?php if ($nav_pending_payments > 0): ?><span class="nav-badge"><?php echo $nav_pending_payments; ?></span><?php endif; ?>
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

        <?php if (can_access('courses')): ?>
        <div class="nav-section">
            <div class="nav-section-label">Academics</div>
            <a class="nav-item <?php echo nav_active('courses', $active_page); ?>" href="course-management.php">
                <i class="fas fa-book-open"></i> Courses
            </a>
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
