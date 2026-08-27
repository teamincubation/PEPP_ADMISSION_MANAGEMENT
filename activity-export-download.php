<?php
require_once __DIR__ . '/includes/auth.php';
require_super_admin(); // Ensure only authenticated super admins can download

require_once __DIR__ . '/includes/SecureDownloadManager.php';

$token = trim($_GET['token'] ?? '');
if (!$token) {
    http_response_code(400);
    die("Error: Missing secure download token.");
}

$filePath = SecureDownloadManager::validateToken($token);
if (!$filePath || !file_exists($filePath)) {
    http_response_code(404);
    die("Error: Secure download link is invalid, has expired, or the file does not exist.");
}

// Path validation to prevent directory traversal
$realPath = realpath($filePath);
$expectedDir = realpath(__DIR__ . '/config/activity_exports');

if ($realPath === false || $expectedDir === false || strpos($realPath, $expectedDir) !== 0) {
    http_response_code(403);
    die("Error: Access denied. Unauthorized directory path.");
}

// Serve the file securely
$filename = basename($realPath);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: private, must-revalidate');
header('Pragma: public');

// Stream file securely to prevent high memory usage
readfile($realPath);
exit();
