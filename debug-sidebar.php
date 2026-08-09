<?php
require_once 'config/database.php';
$stmt = $pdo->query("SELECT setting_value FROM admin_settings WHERE setting_name = 'sidebar_menu_config'");
$val = $stmt->fetchColumn();
echo "<pre>SIDEBAR MENU CONFIG:\n";
echo htmlspecialchars($val) . "\n</pre>";
