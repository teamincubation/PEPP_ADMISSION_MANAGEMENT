<?php
require_once 'includes/auth.php';
require_permission('invoices');
if (!file_exists(__DIR__ . '/includes/pdf_invoice.php')) {
    http_response_code(503);
    exit('includes/pdf_invoice.php is missing on the server - upload it from the package and retry.');
}
require_once __DIR__ . '/includes/pdf_invoice.php';

/* Streams one invoice as a downloadable PDF. */
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: invoices.php'); exit(); }

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

if (!$inv) { header('Location: invoices.php'); exit(); }

$pdf = render_invoice_pdf($inv, $inv['account_name'] ?? '');
$fname = str_replace(['/', '\\'], '-', $inv['invoice_no']) . '.pdf';

log_admin_activity($pdo, $admin_username, 'invoice_downloaded', 'Downloaded ' . $inv['invoice_no'] . ' (' . $inv['user_id'] . ')');

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit();
