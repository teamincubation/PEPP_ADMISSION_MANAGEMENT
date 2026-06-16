<?php
// Layout template for PEPP Learning Admin Dashboard
// This template provides the basic structure that all admin pages should follow

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Function to render the complete page layout
function render_admin_page($page_content, $page_title = 'PEPP Learning - Admin', $additional_css = '', $additional_js = '') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($page_title); ?></title>
        
        <!-- Fonts -->
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        
        <!-- Main stylesheet -->
        <link href="assets/css/pepp-admin-styles.css" rel="stylesheet">
        
        <!-- Additional CSS -->
        <?php if ($additional_css): ?>
            <style><?php echo $additional_css; ?></style>
        <?php endif; ?>
    </head>
    <body>
        <div class="dashboard-container">
            <?php include 'components/navigation.php'; ?>
            
            <main class="main-content">
                <?php include 'components/header.php'; ?>
                
                <div class="content-area">
                    <?php echo $page_content; ?>
                </div>
            </main>
        </div>

        <!-- Additional JavaScript -->
        <?php if ($additional_js): ?>
            <script><?php echo $additional_js; ?></script>
        <?php endif; ?>
        
        <!-- Base JavaScript for navigation -->
        <script>
            // Add active state management for navigation
            document.addEventListener('DOMContentLoaded', function() {
                const navLinks = document.querySelectorAll('.nav-link');
                
                navLinks.forEach(link => {
                    link.addEventListener('click', function(e) {
                        // Don't prevent default for logout link or actual page links
                        if (this.getAttribute('href') === '?logout=1' || 
                            this.getAttribute('href').endsWith('.php')) {
                            return;
                        }
                    });
                });
                
                // Add loading states for forms
                const forms = document.querySelectorAll('form');
                forms.forEach(form => {
                    form.addEventListener('submit', function() {
                        const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
                        if (submitBtn) {
                            submitBtn.classList.add('loading');
                            submitBtn.disabled = true;
                        }
                    });
                });
            });
        </script>
    </body>
    </html>
    <?php
}
?>
