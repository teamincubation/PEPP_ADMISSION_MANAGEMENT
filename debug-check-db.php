<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';
try {
    // Restore test student to clean formatting
    $pdo->prepare("UPDATE users SET whatsapp_country_code = '+91', whatsapp_number = '6282563209' WHERE user_id = 'PEPP2026INBOX'")->execute();
    
    echo "TEST STUDENT STATUS AFTER RESTORE:\n";
    $stmt = $pdo->prepare("SELECT id, user_id, whatsapp_country_code, whatsapp_number, status FROM users WHERE user_id = 'PEPP2026INBOX'");
    $stmt->execute();
    print_r($stmt->fetchAll());
} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage() . "\n";
}
exit;
