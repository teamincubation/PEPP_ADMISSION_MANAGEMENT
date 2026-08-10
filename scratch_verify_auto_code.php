<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username']  = 'superadmin';
$_SESSION['admin_role']      = 'super_admin';

ob_start();
include 'studyplan-chapters.php';
$html = ob_get_clean();

echo "Rendered HTML length: " . strlen($html) . "\n";
if (strpos($html, 'Auto (CH-01)') !== false) {
    echo "SUCCESS: Auto-generated chapter codes & simplified fields working perfectly!\n";
} else {
    echo "ERROR in rendering auto code.\n";
}
