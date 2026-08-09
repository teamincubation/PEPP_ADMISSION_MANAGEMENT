<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username']  = 'superadmin';
$_SESSION['admin_role']      = 'super_admin';

try {
    ob_start();
    include 'email-reports.php';
    $html = ob_get_clean();

    echo "Rendered HTML length: " . strlen($html) . "\n";
    if (strpos($html, 'Email Dispatch Reports') !== false) {
        echo "SUCCESS: Email Dispatch Reports page rendered perfectly!\n";
    } else {
        echo "ERROR: Title not found in rendered HTML.\n";
    }
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    echo "CAUGHT ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
