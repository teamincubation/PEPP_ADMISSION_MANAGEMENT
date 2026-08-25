<?php
/**
 * Backend Functional Unit Tests for Inbox Failed Conversation Badge.
 * Verifies that the SQL subquery correctly resolves the status and direction of the latest message.
 */

// Enable SQLite Memory Database Testing Mode
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

require_once dirname(__DIR__) . '/config/database.php';

function assert_test($label, $assertion) {
    if ($assertion) {
        echo "✅ PASS: {$label}\n";
    } else {
        echo "❌ FAIL: {$label}\n";
        exit(1);
    }
}

global $pdo;

echo "=== Running Inbox Failed Badge SQL Tests ===\n";

try {
    // 1. Setup Mock Tables & Data
    // We already have tables created in memory mode:
    // whatsapp_conversations: id, wa_phone_number, contact_name, last_message_text, last_message_at
    // whatsapp_messages: id, conversation_id, direction, status, message_text, created_at
    
    // Let's create the whatsapp_conversations table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            wa_phone_number TEXT,
            contact_name TEXT,
            last_message_text TEXT,
            last_message_at TEXT
        );
        CREATE TABLE IF NOT EXISTS whatsapp_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER,
            direction TEXT,
            status TEXT,
            message_text TEXT,
            created_at TEXT
        );
    ");

    // Insert 4 mock conversations
    $pdo->exec("
        INSERT INTO whatsapp_conversations (id, wa_phone_number, contact_name, last_message_text, last_message_at)
        VALUES 
        (1, '910000000001', 'Student A', 'Template successful', '2026-08-23 18:00:00'),
        (2, '910000000002', 'Student B', 'Failed templates', '2026-08-23 18:05:00'),
        (3, '910000000003', 'Student C', 'Delivered text', '2026-08-23 18:10:00'),
        (4, '910000000004', 'Student D', 'Inbound answer', '2026-08-23 18:15:00')
    ");

    // Insert messages for Conversation 1: Latest outbound is successful
    $pdo->exec("
        INSERT INTO whatsapp_messages (conversation_id, direction, status, message_text, created_at)
        VALUES (1, 'outbound', 'delivered', 'Hello A', '2026-08-23 18:00:00')
    ");

    // Insert messages for Conversation 2: Latest outbound is failed
    $pdo->exec("
        INSERT INTO whatsapp_messages (conversation_id, direction, status, message_text, created_at)
        VALUES (2, 'outbound', 'failed', 'Hello B', '2026-08-23 18:05:00')
    ");

    // Insert messages for Conversation 3: Older failed, newer successful outbound
    $pdo->exec("
        INSERT INTO whatsapp_messages (conversation_id, direction, status, message_text, created_at)
        VALUES 
        (3, 'outbound', 'failed', 'Old attempt', '2026-08-23 18:00:00'),
        (3, 'outbound', 'delivered', 'New attempt', '2026-08-23 18:10:00')
    ");

    // Insert messages for Conversation 4: Older failed, latest message is inbound
    $pdo->exec("
        INSERT INTO whatsapp_messages (conversation_id, direction, status, message_text, created_at)
        VALUES 
        (4, 'outbound', 'failed', 'Old attempt', '2026-08-23 18:00:00'),
        (4, 'inbound', 'received', 'Hi Admin', '2026-08-23 18:15:00')
    ");

    // Execute the new query from fetch-conversations.php
    $sql = "SELECT wc.*,
                   (
                       SELECT direction 
                       FROM whatsapp_messages 
                       WHERE conversation_id = wc.id 
                       ORDER BY created_at DESC, id DESC 
                       LIMIT 1
                   ) AS latest_message_direction,
                   (
                       SELECT status 
                       FROM whatsapp_messages 
                       WHERE conversation_id = wc.id 
                       ORDER BY created_at DESC, id DESC 
                       LIMIT 1
                   ) AS latest_message_status
            FROM whatsapp_conversations wc
            ORDER BY last_message_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    // TEST A: Conversation 1
    assert_test("Conversation 1 has outbound direction", $rows[1]['latest_message_direction'] === 'outbound');
    assert_test("Conversation 1 is delivered", $rows[1]['latest_message_status'] === 'delivered');
    assert_test("Conversation 1 is NOT classified as failed", 
        !($rows[1]['latest_message_direction'] === 'outbound' && $rows[1]['latest_message_status'] === 'failed'));

    // TEST B: Conversation 2
    assert_test("Conversation 2 has outbound direction", $rows[2]['latest_message_direction'] === 'outbound');
    assert_test("Conversation 2 is failed", $rows[2]['latest_message_status'] === 'failed');
    assert_test("Conversation 2 IS classified as failed", 
        ($rows[2]['latest_message_direction'] === 'outbound' && $rows[2]['latest_message_status'] === 'failed'));

    // TEST C: Conversation 3
    assert_test("Conversation 3 has outbound direction", $rows[3]['latest_message_direction'] === 'outbound');
    assert_test("Conversation 3 has latest message status 'delivered' (newer success)", $rows[3]['latest_message_status'] === 'delivered');
    assert_test("Conversation 3 is NOT classified as failed", 
        !($rows[3]['latest_message_direction'] === 'outbound' && $rows[3]['latest_message_status'] === 'failed'));

    // TEST D: Conversation 4
    assert_test("Conversation 4 has inbound direction (latest message)", $rows[4]['latest_message_direction'] === 'inbound');
    assert_test("Conversation 4 has latest status 'received'", $rows[4]['latest_message_status'] === 'received');
    assert_test("Conversation 4 is NOT classified as failed (inbound override)", 
        !($rows[4]['latest_message_direction'] === 'outbound' && $rows[4]['latest_message_status'] === 'failed'));

    echo "\n=== All SQL Query logic tests passed successfully! ===\n";

} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
