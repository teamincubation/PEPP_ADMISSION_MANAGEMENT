<?php
require_once 'includes/auth.php';
require_permission('ld-work-report');

// Reject interns
if (get_admin_type() === 'intern') {
    http_response_code(403);
    die("Access denied. L&D payment proof is restricted to administrators.");
}

require_once 'config/database.php';

$payment_id = (int)($_GET['id'] ?? 0);
if (!$payment_id) {
    http_response_code(400);
    die("Invalid request: Missing payment ID.");
}

// Fetch screenshot path
$stmt = $pdo->prepare("SELECT screenshot_path FROM ld_intern_payments WHERE id = ?");
$stmt->execute([$payment_id]);
$screenshot_path = $stmt->fetchColumn();

if (!$screenshot_path) {
    http_response_code(404);
    die("Payment proof not found or not uploaded.");
}

// Security: Prevent path traversal
if (strpos($screenshot_path, '..') !== false) {
    http_response_code(400);
    die("Invalid request: Path traversal detected.");
}

// Security: Prevent path traversal and enforce base folder checks
$base_dir = __DIR__ . '/uploads/ld_payments/';
$filename = basename($screenshot_path);
$full_path = $base_dir . $filename;

// Ensure base folder and resolved path match, and file exists
if (!file_exists($full_path) || !is_file($full_path)) {
    http_response_code(404);
    die("File not found on server.");
}

// Determine content type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $full_path);
finfo_close($finfo);

// Force PDF or images content disposition
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
if (!in_array($mime_type, $allowed_types, true)) {
    $mime_type = 'application/octet-stream';
}

header('Content-Type: ' . $mime_type);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: private, max-age=3600');

// Stream file content
readfile($full_path);
exit();
