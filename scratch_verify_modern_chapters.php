<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username']  = 'superadmin';
$_SESSION['admin_role']      = 'super_admin';

ob_start();
include 'studyplan-chapters.php';
$html = ob_get_clean();

echo "Rendered HTML length: " . strlen($html) . "\n";
if (strpos($html, 'modern-input') !== false && strpos($html, 'course-card-grid') !== false) {
    echo "SUCCESS: studyplan-chapters.php modern UI rendered perfectly!\n";
} else {
    echo "ERROR in modern UI rendering.\n";
}
