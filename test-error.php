<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
try {
    require_once 'includes/auth.php';
    require_once 'config/database.php';
    
    // Mock rendering variables
    $active_page = 'dashboard';
    $page_title = 'Test';
    $page_sub = 'Test Sub';
    
    include 'includes/admin_nav.php';
    echo "<h1>NO ERRORS!</h1>";
} catch (Throwable $t) {
    echo "<h1>ERROR:</h1>";
    echo "<pre>" . $t->getMessage() . "\n" . $t->getFile() . ":" . $t->getLine() . "\n" . $t->getTraceAsString() . "</pre>";
}
