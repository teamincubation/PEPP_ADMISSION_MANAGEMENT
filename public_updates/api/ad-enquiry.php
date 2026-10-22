<?php
/**
 * PEPP Updates Public Portal — Ad Enquiry Lead Submission API
 *
 * Secure public endpoint for receiving and validating student enquiries
 * from active popup ads on article pages.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once __DIR__ . '/../includes/bootstrap.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Service temporarily unavailable.']);
    exit;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$res = pepp_public_submit_ad_lead($pdo, $_POST, $clientIp);

http_response_code($res['http_code'] ?? 200);
unset($res['http_code']);
echo json_encode($res);
exit;
