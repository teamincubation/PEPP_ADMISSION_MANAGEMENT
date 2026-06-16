<?php
function renderHeader($page_title = "PEPP Learning Admin", $current_page = "") {
    $admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Work+Sans:wght@400;500;600;700&family=Open+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="styles/globals.css" rel="stylesheet">
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar Navigation -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <img src="assets/img/pepp-logo-icon.png" alt="PEPP Logo" class="logo-img">
                    <span>PEPP Learning</span>
                </div>
            </div>
            
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="studentonboarding.php" class="menu-item <?php echo $current_page === 'students' ? 'active' : ''; ?>">
                    <i class="fas fa-user-plus"></i>
                    <span>Student Onboarding</span>
                </a>
                
                <a href="student-approval.php" class="menu-item <?php echo $current_page === 'approval' ? 'active' : ''; ?>">
                    <i class="fas fa-user-check"></i>
                    <span>Student Approval</span>
                </a>
                
                <a href="course-management.php" class="menu-item <?php echo $current_page === 'courses' ? 'active' : ''; ?>">
                    <i class="fas fa-book"></i>
                    <span>Course Management</span>
                </a>
                
                <a href="phpinstalmentpaymentupdate.php" class="menu-item <?php echo $current_page === 'payments' ? 'active' : ''; ?>">
                    <i class="fas fa-credit-card"></i>
                    <span>Installment Payments</span>
                </a>
                
                <a href="settings.php" class="menu-item <?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </div>
            
            <div class="sidebar-footer">
                <div class="admin-info">
                    <i class="fas fa-user-shield"></i>
                    <span><?php echo htmlspecialchars($admin_username); ?></span>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
        
        <!-- Main Content Area -->
        <main class="main-content">
            <header class="top-header">
                <div class="header-left">
                    <button class="sidebar-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="page-title"><?php echo htmlspecialchars($page_title); ?></h1>
                </div>
                
                <div class="header-right">
                    <div class="header-actions">
                        <button class="notification-btn">
                            <i class="fas fa-bell"></i>
                            <span class="notification-badge">3</span>
                        </button>
                        
                        <div class="admin-profile">
                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($admin_username); ?>&background=164e63&color=ffffff" alt="Admin" class="profile-avatar">
                            <span class="profile-name"><?php echo htmlspecialchars($admin_username); ?></span>
                        </div>
                    </div>
                </div>
            </header>
            
            <div class="content-wrapper">
<?php
}
?>
