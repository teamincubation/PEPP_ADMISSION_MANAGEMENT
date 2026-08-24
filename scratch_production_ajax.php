<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_role'] = 'super_admin';
$_SESSION['admin_username'] = 'superadmin';

$_GET['action'] = 'get_published_tests_by_year';
$_GET['year'] = '2026-27';

require_once __DIR__ . '/assessment-results.php';
