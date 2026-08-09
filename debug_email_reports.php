<?php
/**
 * Diagnostic debug runner for email-reports.php SQL queries.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

echo "<h2>Email Reports Debug Trace</h2>";

try {
    echo "1. Checking email_queue table... ";
    $cnt1 = $pdo->query("SELECT COUNT(*) FROM email_queue")->fetchColumn();
    echo "OK ($cnt1 rows)<br>";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "<br>";
}

try {
    echo "2. Checking email_campaigns table... ";
    $cnt2 = $pdo->query("SELECT COUNT(*) FROM email_campaigns")->fetchColumn();
    echo "OK ($cnt2 rows)<br>";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "<br>";
}

try {
    echo "3. Checking communication_queue table... ";
    $cnt3 = $pdo->query("SELECT COUNT(*) FROM communication_queue")->fetchColumn();
    echo "OK ($cnt3 rows)<br>";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "<br>";
}

require_once 'includes/invoice_helper.php';

try {
    echo "4. Checking invoices table via invoices_table_exists()... ";
    $cnt4 = invoices_table_exists($pdo) ? 'EXISTS' : 'DOES NOT EXIST';
    echo "OK ($cnt4)<br>";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "<br>";
}

try {
    echo "5. Testing Unified Subquery 1 (email_queue)... ";
    $sql1 = "SELECT 
        eq.id as unique_id,
        'email_campaigns' as module_type,
        'Bulk Email Campaign' as module_label,
        COALESCE(ec.subject, 'Marketing Campaign') as campaign_title,
        eq.recipient_email,
        eq.recipient_name,
        eq.subject,
        eq.body as body_preview,
        eq.status,
        eq.error_message,
        COALESCE(eq.sent_at, eq.created_at) as dispatched_at,
        eq.created_at,
        COALESCE(ec.created_by, 'System') as admin_username
    FROM email_queue eq
    LEFT JOIN email_campaigns ec ON eq.campaign_id = ec.id";
    $r1 = $pdo->query($sql1)->fetchAll();
    echo "OK (" . count($r1) . " rows)<br>";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "<br>";
}

try {
    echo "6. Testing Unified Subquery 2 (communication_queue)... ";
    $sql2 = "SELECT 
        cq.id as unique_id,
        'communication_engine' as module_type,
        'Communication Engine' as module_label,
        COALESCE(cq.template_name, 'Direct Dispatch') as campaign_title,
        cq.recipient as recipient_email,
        cq.recipient_name,
        COALESCE(cq.subject, 'Notification') as subject,
        COALESCE(cq.body_html, cq.body_text) as body_preview,
        cq.status,
        cq.error_message,
        COALESCE(cq.updated_at, cq.created_at) as dispatched_at,
        cq.created_at,
        COALESCE(cq.sent_by, 'System') as admin_username
    FROM communication_queue cq
    WHERE cq.channel = 'email'";
    $r2 = $pdo->query($sql2)->fetchAll();
    echo "OK (" . count($r2) . " rows)<br>";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "<br>";
}

try {
    echo "7. Testing Unified Subquery 3 (invoices)... ";
    $sql3 = "SELECT 
        inv.id as unique_id,
        'invoices' as module_type,
        'Invoices & Billing' as module_label,
        CONCAT('Invoice #', COALESCE(inv.invoice_no, inv.id)) as campaign_title,
        inv.email as recipient_email,
        inv.student_name as recipient_name,
        CONCAT('Invoice #', COALESCE(inv.invoice_no, inv.id), ' - PEPP Learning') as subject,
        CONCAT('Invoice notification dispatched for ', inv.student_name) as body_preview,
        CASE WHEN inv.email_status = 'sent' THEN 'sent' WHEN inv.email_status = 'failed' THEN 'failed' ELSE 'pending' END as status,
        NULL as error_message,
        COALESCE(inv.paid_date, inv.created_at) as dispatched_at,
        inv.created_at,
        COALESCE(inv.generated_by, 'System') as admin_username
    FROM invoices inv
    WHERE inv.email_status IS NOT NULL AND inv.email_status != ''";
    $r3 = $pdo->query($sql3)->fetchAll();
    echo "OK (" . count($r3) . " rows)<br>";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "<br>";
}

echo "Done.";
