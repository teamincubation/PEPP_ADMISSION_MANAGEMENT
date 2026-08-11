<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';
try {
    // Restore test student to designated test customer formatting (9567276458)
    $pdo->prepare("UPDATE users SET whatsapp_country_code = '+91', whatsapp_number = '9567276458' WHERE user_id = 'PEPP2026INBOX'")->execute();
    
    echo "=== REAL MESSAGE CONVERSATION SEARCH ===\n";
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_conversations WHERE wa_phone_number LIKE '%9567276458%'");
    $stmt->execute();
    $convs = $stmt->fetchAll();
    print_r($convs);

    echo "\n=== REAL MESSAGES SEARCH ===\n";
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE message_text LIKE '%PEPP ERP Inbox E2E test%' OR conversation_id IN (SELECT id FROM whatsapp_conversations WHERE wa_phone_number LIKE '%9567276458%')");
    $stmt->execute();
    $msgs = $stmt->fetchAll();
    print_r($msgs);

    echo "\n=== WEBHOOK DEBUG LOG ===\n";
    if (file_exists('webhook-debug.log')) {
        echo file_get_contents('webhook-debug.log');
    } else {
        echo "webhook-debug.log does not exist.\n";
    }
} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage() . "\n";
}
exit;
