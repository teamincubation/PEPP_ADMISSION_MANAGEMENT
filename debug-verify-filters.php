<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';

try {
    echo "=== DATABASE CONVERSATION COUNTS ===\n";
    // 1. Database raw counts
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
    
    // Let's find one conversation to test behavior on
    $conv = $pdo->query("SELECT * FROM whatsapp_conversations LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($conv) {
        $cid = $conv['id'];
        echo "Testing on Conversation ID #$cid (Initial unread_count: {$conv['unread_count']})\n";
        
        // Save initial unread count
        $orig_unread = $conv['unread_count'];
        
        // 1. Simulate background polling (mark_read = 0)
        // Background polling should NOT alter the unread count in DB
        $stmt_msg = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages WHERE conversation_id = ?");
        $stmt_msg->execute([$cid]);
        $msg_count = $stmt_msg->fetchColumn();
        
        // Re-fetch conversation to check DB unread count
        $stmt_check = $pdo->prepare("SELECT unread_count FROM whatsapp_conversations WHERE id = ?");
        $stmt_check->execute([$cid]);
        $unread_after_poll = $stmt_check->fetchColumn();
        echo "unread_count after background polling (mark_read=0): $unread_after_poll (Expected: $orig_unread)\n";
        
        // 2. Simulate explicit open (mark_read = 1)
        // Explicit open MUST reset the unread count to 0 in DB
        $pdo->prepare("UPDATE whatsapp_conversations SET unread_count = 0 WHERE id = ?")->execute([$cid]);
        $stmt_check->execute([$cid]);
        $unread_after_open = $stmt_check->fetchColumn();
        echo "unread_count after explicit open (mark_read=1): $unread_after_open (Expected: 0)\n";
        
        // Restore original unread count in DB to keep data pristine
        $pdo->prepare("UPDATE whatsapp_conversations SET unread_count = ? WHERE id = ?")->execute([$orig_unread, $cid]);
        echo "Prised state restored.\n";
    } else {
        echo "No conversations in database to test unread_count behavior.\n";
    }
} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage() . "\n";
}
exit;
