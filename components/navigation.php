<?php
// Navigation component for PEPP Learning Admin Dashboard
// This component provides a consistent sidebar navigation across all admin pages

// Get current page for active state management
$current_page = basename($_SERVER['PHP_SELF']);

// Define navigation items with their respective pages and icons
$nav_items = [
    [
        'href' => 'dashboard.php',
        'icon' => 'fas fa-tachometer-alt',
        'label' => 'Dashboard',
        'page' => 'dashboard.php'
    ],
    [
        'href' => 'student-approval.php',
        'icon' => 'fas fa-user-check',
        'label' => 'Student Approval',
        'page' => 'student-approval.php'
    ],
    [
        'href' => 'studentonboarding.php',
        'icon' => 'fas fa-user-plus',
        'label' => 'Student Onboarding',
        'page' => 'studentonboarding.php'
    ],
    [
        'href' => 'studentpage.php',
        'icon' => 'fas fa-users',
        'label' => 'Students',
        'page' => 'studentpage.php'
    ],
    [
        'href' => 'add-student.php',
        'icon' => 'fas fa-user-plus',
        'label' => 'Add Student',
        'page' => 'add-student.php'
    ],
    [
        'href' => 'phpinstalmentpaymentupdate.php',
        'icon' => 'fas fa-credit-card',
        'label' => 'Installment Payments',
        'page' => 'phpinstalmentpaymentupdate.php'
    ],
    [
        'href' => 'student-reports.php',
        'icon' => 'fas fa-chart-bar',
        'label' => 'Student Reports',
        'page' => 'student-reports.php'
    ],
    [
        'href' => 'course-management.php',
        'icon' => 'fas fa-book',
        'label' => 'Course Management',
        'page' => 'course-management.php'
    ],
    [
        'href' => 'admin-settings.php',
        'icon' => 'fas fa-cog',
        'label' => 'Admin Settings',
        'page' => 'admin-settings.php'
    ],
    [
        'href' => 'invoices.php',
        'icon' => 'fas fa-file-invoice',
        'label' => 'Invoices',
        'page' => 'invoices.php'
    ],
    [
        'href' => 'mentor-management.php',
        'icon' => 'fas fa-chalkboard-teacher',
        'label' => 'Mentor Management',
        'page' => 'mentor-management.php'
    ],
    [
        'href' => 'peppkit-tracking.php',
        'icon' => 'fas fa-box',
        'label' => 'PEPPKIT Tracking',
        'page' => 'peppkit-tracking.php'
    ],
    [
        'href' => 'leads.php',
        'icon' => 'fas fa-bullseye',
        'label' => 'Leads',
        'page' => 'leads.php'
    ],
    [
        'href' => 'settings.php',
        'icon' => 'fas fa-sliders-h',
        'label' => 'Settings',
        'page' => 'settings.php'
    ],
    [
        'href' => 'export-data.php',
        'icon' => 'fas fa-download',
        'label' => 'Export Data',
        'page' => 'export-data.php'
    ]
];

// Get admin username from session
$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>

<!-- Sidebar Navigation -->
<nav class="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon">
                <img src="assets/img/pepp-logo-icon.png" alt="PEPP Logo">
            </div>
            <h2>PEPP Learning</h2>
        </div>
    </div>
    
    <ul class="nav-menu">
        <?php foreach ($nav_items as $item): ?>
            <li class="nav-item">
                <a href="<?php echo $item['href']; ?>" 
                   class="nav-link <?php echo ($current_page === $item['page']) ? 'active' : ''; ?>">
                    <i class="<?php echo $item['icon']; ?> nav-icon"></i>
                    <span><?php echo $item['label']; ?></span>
                </a>
            </li>
        <?php endforeach; ?>
        
        <!-- Logout Link -->
        <li class="nav-item">
            <a href="?logout=1" class="nav-link logout-link" 
               onclick="return confirm('Are you sure you want to logout?')">
                <i class="fas fa-sign-out-alt nav-icon"></i>
                <span>Logout</span>
            </a>
        </li>
    </ul>
</nav>
