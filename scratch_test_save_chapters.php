<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username']  = 'superadmin';
$_SESSION['admin_role']      = 'super_admin';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'save_chapters';
$_POST['csrf_token'] = $_SESSION['csrf_token'];
$_POST['academic_year'] = '2026-27';
$_POST['target_courses'] = [1];
$_POST['entry_mode'] = 'manual';
$_POST['chap_name'] = ['Test Chapter Automated Check'];

ob_start();
include 'studyplan-chapters.php';
$html = ob_get_clean();

echo "Rendered HTML length: " . strlen($html) . "\n";
if (strpos($html, 'Successfully pre-set') !== false || strpos($html, 'alert-success') !== false) {
    echo "SUCCESS: Chapters saved successfully without any CSRF error!\n";
} else {
    echo "Output check.\n";
}
