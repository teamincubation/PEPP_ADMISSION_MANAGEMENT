<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';

$id = 0;
$authorized = false;
$admin_username = 'system';

// If admin session exists, authenticate using standard require_permission
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    require_once 'includes/auth.php';
    require_permission('invoices');
    $authorized = true;
    $admin_username = $_SESSION['admin_username'] ?? 'admin';
    $id = (int)($_GET['id'] ?? 0);
} else {
    // Check if secure token is valid (must be in format ID-HMAC using INVOICE_HMAC_SECRET)
    $tokenParam = $_GET['token'] ?? '';
    if (!empty($tokenParam) && strpos($tokenParam, '-') !== false) {
        $parts = explode('-', $tokenParam, 2);
        $tempId = (int)$parts[0];
        $hmac = $parts[1];
        
        if ($tempId > 0 && !empty($hmac)) {
            $expected_hmac = hash_hmac('sha256', (string)$tempId, INVOICE_HMAC_SECRET);
            if (hash_equals($expected_hmac, $hmac)) {
                $authorized = true;
                $admin_username = 'student_secure_link';
                $id = $tempId;
            }
        }
    }
}

if (!$authorized || $id <= 0) {
    http_response_code(403);
    exit('Access denied. You are not authorized to view this invoice.');
}


if (!file_exists(__DIR__ . '/includes/pdf_invoice.php')) {
    http_response_code(503);
    exit('includes/pdf_invoice.php is missing on the server - upload it from the package and retry.');
}
require_once __DIR__ . '/includes/pdf_invoice.php';

if (!$id) {
    if (isset($_SESSION['admin_logged_in'])) {
        header('Location: invoices.php');
    } else {
        http_response_code(400);
        exit('Invoice ID is required.');
    }
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT i.*, pa.account_name
        FROM invoices i
        LEFT JOIN payment_accounts pa ON pa.id = i.payment_account_id
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $inv = $stmt->fetch();
} catch (Exception $e) { $inv = null; }

if (!$inv) {
    if (isset($_SESSION['admin_logged_in'])) {
        header('Location: invoices.php');
    } else {
        http_response_code(404);
        exit('Invoice not found.');
    }
    exit();
}

$pdf = render_invoice_pdf($inv, $inv['account_name'] ?? '');
$fname = str_replace(['/', '\\'], '-', $inv['invoice_no']) . '.pdf';

if ($admin_username !== 'student_secure_link') {
    log_admin_activity($pdo, $admin_username, 'invoice_downloaded', 'Downloaded/Viewed ' . $inv['invoice_no'] . ' (' . $inv['user_id'] . ')');
}

header('Content-Type: application/pdf');
if ($view) {
    header('Content-Disposition: inline; filename="' . $fname . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $fname . '"');
}
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit();
