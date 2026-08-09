<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username']  = 'superadmin';
$_SESSION['admin_role']      = 'super_admin';

ob_start();
include 'email-reports.php';
$html = ob_get_clean();

echo "Rendered HTML length: " . strlen($html) . "\n";
if (strpos($html, 'Email Dispatch Reports') !== false) {
    echo "SUCCESS: Email Dispatch Reports page rendered perfectly!\n";
} else {
    echo "ERROR: Title not found in rendered HTML.\n";
}
