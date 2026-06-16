<?php
// Example page showing how to use the new layout system
// This demonstrates how to convert existing pages to use the consistent design

require_once 'components/layout-template.php';

// Your existing page logic here
require_once 'config/database.php';

// Example: Get some data for the page
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $user_count = $stmt->fetchColumn();
} catch (Exception $e) {
    $user_count = 0;
}

// Define the page content
ob_start();
?>

<!-- Your page content goes here -->
<div class="card">
    <div class="card-header">
        <h3>Example Page</h3>
        <p>This shows how to use the new consistent layout system</p>
    </div>
    
    <div class="grid grid-cols-2">
        <div class="card">
            <h4 class="mb-4 font-semibold">Sample Statistics</h4>
            <p class="text-2xl font-bold text-primary"><?php echo number_format($user_count); ?></p>
            <p class="text-sm text-secondary">Total Users</p>
        </div>
        
        <div class="card">
            <h4 class="mb-4 font-semibold">Quick Actions</h4>
            <div class="grid grid-cols-1 gap-3">
                <a href="#" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Add New Item
                </a>
                <a href="#" class="btn btn-outline">
                    <i class="fas fa-download"></i>
                    Export Data
                </a>
            </div>
        </div>
    </div>
    
    <!-- Example table -->
    <div class="table-container mt-6">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>Sample Item</td>
                    <td><span class="status-badge status-active">Active</span></td>
                    <td>
                        <a href="#" class="btn btn-sm btn-info">Edit</a>
                        <a href="#" class="btn btn-sm btn-danger">Delete</a>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php
$page_content = ob_get_clean();

// Additional CSS for this specific page (optional)
$additional_css = '
    .custom-style {
        background: var(--accent);
        color: white;
    }
';

// Additional JavaScript for this specific page (optional)
$additional_js = '
    console.log("Example page loaded");
';

// Render the complete page
render_admin_page($page_content, 'Example Page - PEPP Learning', $additional_css, $additional_js);
?>
