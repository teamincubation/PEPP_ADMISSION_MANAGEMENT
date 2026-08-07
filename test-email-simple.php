<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=UTF-8');
echo "=== INVOICE DETAILS DUMP ===\n\n";
try {
    $stmt = $pdo->query("SELECT id, invoice_no, student_name, email, gross_amount, invoice_type, paid_date, email_status, created_at FROM invoices ORDER BY id DESC LIMIT 10");
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($invoices as $inv) {
        echo "ID: {$inv['id']} | No: {$inv['invoice_no']} | Name: {$inv['student_name']} | Email: '{$inv['email']}' | Amt: {$inv['gross_amount']} | Type: {$inv['invoice_type']} | Paid: {$inv['paid_date']} | Status: {$inv['email_status']} | Created: {$inv['created_at']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
