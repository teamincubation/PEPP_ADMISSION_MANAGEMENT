<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security: restrict to logged-in admin or private secure passkey
$authorized = false;
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $authorized = true;
} elseif (($_GET['passkey'] ?? '') === 'pepp_secure_audit_passkey_2026_xyz') {
    $authorized = true;
}

if (!$authorized) {
    http_response_code(403);
    die("Access Denied.");
}

header('Content-Type: application/json');
require_once 'config/database.php';

$output = [];

// 1. Fetch queue items 160-168
try {
    $stmt = $pdo->prepare("SELECT * FROM communication_queue WHERE id BETWEEN 160 AND 168 ORDER BY id ASC");
    $stmt->execute();
    $output['queue_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $output['queue_items_error'] = $e->getMessage();
}

// 2. Fetch whatsapp settings
try {
    $stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
    $output['settings'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $output['settings_error'] = $e->getMessage();
}

// 3. Fetch latest mode audit log
try {
    $stmt = $pdo->query("SELECT * FROM whatsapp_mode_audit ORDER BY id DESC LIMIT 5");
    $output['mode_audit'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $output['mode_audit_error'] = $e->getMessage();
}

// 4. Fetch mapped template for payment_receipt
try {
    $stmt = $pdo->prepare("
        SELECT em.event_name, em.template_name, t.meta_data, t.status, t.language, t.channel
        FROM communication_event_mappings em
        LEFT JOIN communication_templates t ON em.template_name = t.template_name
        WHERE em.event_name = 'payment_receipt'
        LIMIT 1
    ");
    $stmt->execute();
    $output['payment_receipt_template'] = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $output['payment_receipt_template_error'] = $e->getMessage();
}

// 5. Check if any duplicate notifications exist for these IDs
try {
    $stmt = $pdo->query("
        SELECT student_uid, event_name, template_name, status, COUNT(*) as cnt 
        FROM communication_queue 
        WHERE status IN ('sent', 'delivered', 'read') 
        GROUP BY student_uid, event_name, template_name, status
    ");
    $output['active_message_counts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $output['active_message_counts_error'] = $e->getMessage();
}

echo json_encode($output, JSON_PRETTY_PRINT);
exit;
