<?php
// require_once 'includes/auth.php';
// require_permission('invoices');
require_once 'config/database.php';
require_once 'includes/invoice_helper.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "=== EMAIL DIAGNOSTIC ===\n\n";

try {
    $stmt = $pdo->query("SELECT id, invoice_no, student_name, email, email_status FROM invoices ORDER BY id DESC LIMIT 5");
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($invoices as $inv) {
        echo "ID: {$inv['id']} | Invoice: {$inv['invoice_no']} | Student: {$inv['student_name']} | Email in DB: '{$inv['email']}' | Status: {$inv['email_status']}\n";
        if (!filter_var($inv['email'], FILTER_VALIDATE_EMAIL)) {
            echo "  --> ERROR: This email is NOT a valid email address!\n";
        }
    }
} catch (Exception $e) {
    echo "Error querying invoices: " . $e->getMessage() . "\n";
}

echo "\n=== TESTING MAIL SEND FOR LATEST INVOICE ===\n\n";

if (!empty($invoices)) {
    $target = $invoices[0];
    echo "Testing for Student: {$target['student_name']} | Target Email: '{$target['email']}'\n";
    
    // Check if companions exist
    $pdf_file = __DIR__ . '/includes/pdf_invoice.php';
    $mail_file = __DIR__ . '/includes/invoice_mailer.php';
    echo "pdf_invoice.php exists: " . (file_exists($pdf_file) ? 'YES' : 'NO') . "\n";
    echo "invoice_mailer.php exists: " . (file_exists($mail_file) ? 'YES' : 'NO') . "\n";
    
    if (file_exists($pdf_file) && file_exists($mail_file)) {
        require_once $pdf_file;
        require_once $mail_file;
        
        // Render PDF
        try {
            $pdfBytes = render_invoice_pdf($target, 'TEST ACCOUNT');
            echo "PDF rendered successfully. Size: " . strlen($pdfBytes) . " bytes\n";
            
            // Temporary error handler to catch mail() warnings
            set_error_handler(function($errno, $errstr, $errfile, $errline) {
                echo "PHP WARNING during mail(): [$errno] $errstr on line $errline\n";
            });
            
            echo "Sending mail...\n";
            $sent = send_invoice_email($target, $pdfBytes);
            echo "send_invoice_email return value: " . ($sent ? "TRUE" : "FALSE") . "\n";
            
            restore_error_handler();
        } catch (Exception $e) {
            echo "Exception during render/send: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "No invoices found in database.\n";
}
