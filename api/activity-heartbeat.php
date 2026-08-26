<?php
/**
 * PEPP ERP - Centralized Heartbeat AJAX Receiver
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security: Derive credentials and session status strictly from the server-side session
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || empty($_SESSION['admin_username'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_logger.php';

// Validate session_ref matches
$session_ref = $_SESSION['session_ref'] ?? null;
if (!$session_ref) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid session reference']);
    exit();
}

// Get JSON raw body or POST input parameters
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$page = trim($input['page'] ?? '');
$module = trim($input['module'] ?? '');
$section = trim($input['section'] ?? '');
$is_idle = isset($input['is_idle']) ? (int)$input['is_idle'] : 0;

// Geolocation coordinate parsing and cookie caching
if (isset($input['latitude']) && is_numeric($input['latitude'])) {
    setcookie('pepp_lat', $input['latitude'], time() + 86400 * 30, '/');
    $_COOKIE['pepp_lat'] = $input['latitude'];
}
if (isset($input['longitude']) && is_numeric($input['longitude'])) {
    setcookie('pepp_lng', $input['longitude'], time() + 86400 * 30, '/');
    $_COOKIE['pepp_lng'] = $input['longitude'];
}

if ($page) {
    $page = basename($page);
} else {
    $page = 'unknown';
}

// Update admin presence
update_presence_state($pdo, $page, $module, $section, $is_idle);

// Record heartbeat in activity log (suppressing duplicates internally)
log_heartbeat($pdo, $page, $module, $section, $is_idle);

// Return JSON success response
header('Content-Type: application/json');
echo json_encode(['success' => true]);
