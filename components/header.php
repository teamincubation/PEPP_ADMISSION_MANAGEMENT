<?php
// Header component for PEPP Learning Admin Dashboard
// This component provides a consistent header across all admin pages

// Get admin username from session
$admin_username = $_SESSION['admin_username'] ?? 'Admin';

// Define page titles based on current page
$page_titles = [
    'dashboard.php' => ['title' => 'Dashboard Overview', 'subtitle' => 'Welcome back'],
    'student-approval.php' => ['title' => 'Student Approval', 'subtitle' => 'Review and approve student applications'],
    'studentonboarding.php' => ['title' => 'Student Onboarding', 'subtitle' => 'Manage new student registrations'],
    'studentpage.php' => ['title' => 'Students', 'subtitle' => 'Manage all registered students'],
    'phpinstalmentpaymentupdate.php' => ['title' => 'Installment Payments', 'subtitle' => 'Track and update payment records'],
    'student-reports.php' => ['title' => 'Student Reports', 'subtitle' => 'View detailed student analytics'],
    'course-management.php' => ['title' => 'Course Management', 'subtitle' => 'Manage courses and curriculum'],
    'admin-settings.php' => ['title' => 'Admin Settings', 'subtitle' => 'Configure system settings'],
    'invoices.php' => ['title' => 'Invoices', 'subtitle' => 'Manage billing and invoices'],
    'mentor-management.php' => ['title' => 'Mentor Management', 'subtitle' => 'Manage mentors and assignments'],
    'peppkit-tracking.php' => ['title' => 'PEPPKIT Tracking', 'subtitle' => 'Track kit deliveries and status'],
    'leads.php' => ['title' => 'Leads', 'subtitle' => 'Manage potential student leads'],
    'settings.php' => ['title' => 'Settings', 'subtitle' => 'System configuration and preferences'],
    'export-data.php' => ['title' => 'Export Data', 'subtitle' => 'Download and export system data']
];

$current_page = basename($_SERVER['PHP_SELF']);
$page_info = $page_titles[$current_page] ?? ['title' => 'Admin Panel', 'subtitle' => 'Manage your system'];
?>

<!-- Header -->
<div class="header">
    <div class="header-left">
        <h1><?php echo $page_info['title']; ?></h1>
        <p class="header-subtitle"><?php echo $page_info['subtitle']; ?>, <?php echo htmlspecialchars($admin_username); ?>!</p>
    </div>
    <div class="header-right">
        <div class="user-info">
            <div class="user-avatar">
                <i class="fas fa-user"></i>
            </div>
            <div class="user-details">
                <span class="user-name"><?php echo htmlspecialchars($admin_username); ?></span>
                <span class="user-role">Administrator</span>
            </div>
        </div>
    </div>
</div>
