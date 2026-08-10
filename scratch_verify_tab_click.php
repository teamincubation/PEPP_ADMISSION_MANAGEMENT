<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username']  = 'superadmin';
$_SESSION['admin_role']      = 'super_admin';

ob_start();
include 'studyplan-chapters.php';
$html = ob_get_clean();

echo "Rendered HTML length: " . strlen($html) . "\n";
if (strpos($html, 'switchEntryTab') !== false && strpos($html, 'addChapterRow') !== false) {
    echo "SUCCESS: JS tab switcher and add row script parsed cleanly!\n";
} else {
    echo "ERROR in script.\n";
}
