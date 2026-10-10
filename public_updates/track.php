<?php
/**
 * PEPP Updates Public Portal — Interaction & Location Telemetry Endpoint
 * Handles beacon/AJAX telemetry for privacy-conscious click and location tracking.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
if ($action === '') {
    echo json_encode(['ok' => false, 'error' => 'Action required']);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    echo json_encode(['ok' => false, 'error' => 'Database connection unavailable']);
    exit;
}

$sessionId = $_COOKIE['pepp_session_id'] ?? null;
if (!$sessionId || !preg_match('/^[a-f0-9]{32,64}$/i', $sessionId)) {
    $sessionId = bin2hex(random_bytes(16));
    if (!headers_sent()) {
        setcookie('pepp_session_id', $sessionId, time() + 86400 * 30, '/', '', false, true);
    }
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$today = date('Y-m-d');
$salt = 'pepp_public_visit_salt_' . date('Y-m');
$ipHash = hash('sha256', $ip . '_' . $today . '_' . $salt);
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

try {
    if ($action === 'location') {
        $status = trim($_POST['status'] ?? $_GET['status'] ?? 'prompt');
        $lat = !empty($_POST['lat']) ? (float)$_POST['lat'] : (!empty($_GET['lat']) ? (float)$_GET['lat'] : null);
        $lng = !empty($_POST['lng']) ? (float)$_POST['lng'] : (!empty($_GET['lng']) ? (float)$_GET['lng'] : null);
        $accuracy = !empty($_POST['accuracy']) ? (float)$_POST['accuracy'] : (!empty($_GET['accuracy']) ? (float)$_GET['accuracy'] : null);

        $_SESSION['pepp_user_location'] = [
            'status'   => $status,
            'lat'      => $lat,
            'lng'      => $lng,
            'accuracy' => $accuracy,
        ];

        // If updates_visits has location columns, update today's latest record for this visitor
        if (pepp_updates_column_exists($pdo, 'updates_visits', 'location_status')) {
            $hasSess = pepp_updates_column_exists($pdo, 'updates_visits', 'session_id');
            if ($hasSess) {
                $stmtUpd = $pdo->prepare("
                    UPDATE updates_visits 
                    SET location_status = ?, latitude = ?, longitude = ?, accuracy = ?
                    WHERE session_id = ? AND visit_date = ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtUpd->execute([$status, $lat, $lng, $accuracy, $sessionId, $today]);
            } else {
                $stmtUpd = $pdo->prepare("
                    UPDATE updates_visits 
                    SET location_status = ?, latitude = ?, longitude = ?, accuracy = ?
                    WHERE ip_hash = ? AND visit_date = ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtUpd->execute([$status, $lat, $lng, $accuracy, $ipHash, $today]);
            }
        }

        echo json_encode(['ok' => true, 'status' => $status]);
        exit;
    }

    if ($action === 'click' || $action === 'action_button' || $action === 'share') {
        $postId = !empty($_POST['post_id']) ? (int)$_POST['post_id'] : (!empty($_GET['post_id']) ? (int)$_GET['post_id'] : null);
        $actionName = trim($_POST['action_name'] ?? $_GET['action_name'] ?? $action);
        $buttonName = trim($_POST['button_name'] ?? $_GET['button_name'] ?? '');
        $targetUrl = trim($_POST['target_url'] ?? $_GET['target_url'] ?? '');

        if (pepp_updates_table_exists($pdo, 'updates_clicks')) {
            $stmt = $pdo->prepare("
                INSERT INTO updates_clicks (post_id, action_name, button_name, target_url, session_id, ip_hash, created_at)
                VALUES (?, ?, ?, ?, ?, ?, {$nowSql})
            ");
            $stmt->execute([
                $postId,
                substr($actionName, 0, 100),
                $buttonName !== '' ? substr($buttonName, 0, 150) : null,
                $targetUrl !== '' ? substr($targetUrl, 0, 500) : null,
                $sessionId,
                $ipHash
            ]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
