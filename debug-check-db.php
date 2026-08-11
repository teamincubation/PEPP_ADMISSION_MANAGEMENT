<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';
try {
    echo "CONVERSATIONS:\n";
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_conversations");
    $stmt->execute();
    print_r($stmt->fetchAll());

    echo "\nMESSAGES:\n";
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_messages");
    $stmt->execute();
    print_r($stmt->fetchAll());
} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage();
}
exit;
