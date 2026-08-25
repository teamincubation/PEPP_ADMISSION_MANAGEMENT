<?php
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
require_once 'config/database.php';
global $pdo;

echo "=== Table Verification ===\n";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM test_result_cards")->fetchColumn();
    echo "test_result_cards count: " . $count . "\n";
    
    $title = $pdo->query("SELECT title FROM card_templates WHERE title = 'Mega Test Result Template'")->fetchColumn();
    echo "Mega Test Result Template exists: " . ($title ? "YES ({$title})" : "NO") . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
