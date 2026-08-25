<?php
// Force MySQL connection by not setting SERVER_NAME to localhost
$_SERVER['SERVER_NAME'] = 'pepplearning.in';
require_once 'config/database.php';

try {
    $stmt = $pdo->prepare("SELECT * FROM test_result_cards WHERE id = ?");
    $stmt->execute([9]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        echo "--- TEST RESULT CARD ID 9 FOUND ---\n";
        foreach ($row as $col => $val) {
            if ($col === 'design_config' || $col === 'student_rank_mappings') {
                echo "$col: $val\n\n";
            } else {
                echo "$col: $val\n";
            }
        }
    } else {
        echo "Card ID 9 not found in MySQL.\n";
    }
} catch (Exception $e) {
    echo "MySQL connection/query failed: " . $e->getMessage() . "\n";
}
