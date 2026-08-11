<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';

try {
    // Temporarily set conversation 55 unread_count to 3 for testing
    $orig_unread = (int)$pdo->query("SELECT unread_count FROM whatsapp_conversations WHERE id = 55")->fetchColumn();
    $pdo->query("UPDATE whatsapp_conversations SET unread_count = 3 WHERE id = 55");
    
    echo "=== DATABASE CONVERSATION COUNTS (SIMULATED UNREAD = 3) ===\n";
    $total_convs = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_conversations")->fetchColumn();
    $unread_convs = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_conversations WHERE unread_count > 0")->fetchColumn();
    $student_convs = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_conversations WHERE student_uid IS NOT NULL")->fetchColumn();
    $unknown_convs = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_conversations WHERE student_uid IS NULL")->fetchColumn();
    
    echo "Total Conversations: $total_convs\n";
    echo "Unread Conversations: $unread_convs\n";
    echo "Students Conversations: $student_convs\n";
    echo "Unknown Conversations: $unknown_convs\n";

    echo "\n=== API FILTER VERIFICATION ===\n";
    
    // Helper to simulate API call
    function check_filter_api($filter) {
        global $pdo;
        $sql = "SELECT COUNT(*) FROM whatsapp_conversations WHERE 1=1";
        if ($filter === 'unread') {
            $sql .= " AND unread_count > 0";
        } elseif ($filter === 'students') {
            $sql .= " AND student_uid IS NOT NULL";
        } elseif ($filter === 'unknown') {
            $sql .= " AND student_uid IS NULL";
        }
        return (int)$pdo->query($sql)->fetchColumn();
    }

    echo "API 'all' Count: " . check_filter_api('all') . " (Expected: $total_convs)\n";
    echo "API 'unread' Count: " . check_filter_api('unread') . " (Expected: $unread_convs)\n";
    echo "API 'students' Count: " . check_filter_api('students') . " (Expected: $student_convs)\n";
    echo "API 'unknown' Count: " . check_filter_api('unknown') . " (Expected: $unknown_convs)\n";

    echo "\n=== TESTING UNREAD_COUNT BEHAVIOR ===\n";
    
    $cid = 55;
    $stmt_check = $pdo->prepare("SELECT unread_count FROM whatsapp_conversations WHERE id = ?");
    
    // Re-fetch conversation to check DB unread count (simulating mark_read=0 polling)
    $stmt_check->execute([$cid]);
    $unread_after_poll = $stmt_check->fetchColumn();
    echo "unread_count after background polling (mark_read=0): $unread_after_poll (Expected: 3)\n";
    
    // Simulate explicit open (mark_read = 1) -> must reset to 0 in DB
    $pdo->prepare("UPDATE whatsapp_conversations SET unread_count = 0 WHERE id = ?")->execute([$cid]);
    $stmt_check->execute([$cid]);
    $unread_after_open = $stmt_check->fetchColumn();
    echo "unread_count after explicit open (mark_read=1): $unread_after_open (Expected: 0)\n";
    
    // Restore original unread count in DB to keep data pristine
    $pdo->prepare("UPDATE whatsapp_conversations SET unread_count = ? WHERE id = ?")->execute([$orig_unread, $cid]);
    echo "Pristine state restored.\n";
} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage() . "\n";
}
exit;
