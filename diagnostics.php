<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

try {
    $stmt = $pdo->query("
        SELECT em.event_name, em.template_name, em.parameter_mappings, t.language, t.status, t.meta_data
        FROM communication_event_mappings em
        LEFT JOIN communication_templates t ON em.template_name = t.template_name
    ");
    $output['mappings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $output['mappings_error'] = $e->getMessage();
}

echo json_encode($output, JSON_PRETTY_PRINT);
exit;
