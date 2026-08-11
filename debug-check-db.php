<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';
try {
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'admins'");
    $exists = $stmt->fetchColumn();
    if ($exists) {
        echo "TEST STUDENT RECORDS:\n";
        $stmt = $pdo->prepare("SELECT id, user_id, name, whatsapp_country_code, whatsapp_number, status FROM users WHERE user_id = 'PEPP2026INBOX'");
        $stmt->execute();
        print_r($stmt->fetchAll());
    } else {
        echo "ADMINS TABLE DOES NOT EXIST.\n";
    }
} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage() . "\n" . $t->getFile() . ":" . $t->getLine() . "\n";
}
exit;
