<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';

echo "=== INSTALMENT_DETAILS COLUMNS ===\n";
try {
    $stmt = $pdo->query("DESCRIBE instalment_details");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo "{$col['Field']} ({$col['Type']}) {$col['Key']} Default:{$col['Default']}\n";
    }
} catch (Throwable $t) { echo "ERROR: " . $t->getMessage() . "\n"; }

echo "\n=== USERS TABLE (key columns) ===\n";
try {
    $stmt = $pdo->query("DESCRIBE users");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if (in_array($col['Field'], ['user_id','name','email','whatsapp_country_code','whatsapp_number','pepp_course','pepp_academic_year','status','onboarding_status','total_fee','paid_amount','payment_plan','course_duration_date','approval_date','approved_by','course_access_provided','discount_amount','payment_mode','paid_date'])) {
            echo "{$col['Field']} ({$col['Type']}) {$col['Key']} Default:{$col['Default']}\n";
        }
    }
} catch (Throwable $t) { echo "ERROR: " . $t->getMessage() . "\n"; }

echo "\n=== COMMUNICATION_TEMPLATES TABLE ===\n";
try {
    $stmt = $pdo->query("SELECT template_name, language, category, status FROM communication_templates");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "Template: {$row['template_name']} | Lang: {$row['language']} | Cat: {$row['category']} | Status: {$row['status']}\n";
    }
} catch (Throwable $t) { echo "ERROR: " . $t->getMessage() . "\n"; }

echo "\n=== INSTALLMENT_WHATSAPP_REMINDERS TABLE ===\n";
try {
    $stmt = $pdo->query("DESCRIBE installment_whatsapp_reminders");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo "{$col['Field']} ({$col['Type']}) {$col['Key']}\n";
    }
} catch (Throwable $t) { echo "ERROR: " . $t->getMessage() . "\n"; }

echo "\n=== WHATSAPP_CONVERSATIONS COLUMNS ===\n";
try {
    $stmt = $pdo->query("DESCRIBE whatsapp_conversations");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo "{$col['Field']} ({$col['Type']}) {$col['Key']}\n";
    }
} catch (Throwable $t) { echo "ERROR: " . $t->getMessage() . "\n"; }

echo "\n=== WHATSAPP_MESSAGES COLUMNS ===\n";
try {
    $stmt = $pdo->query("DESCRIBE whatsapp_messages");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo "{$col['Field']} ({$col['Type']}) {$col['Key']}\n";
    }
} catch (Throwable $t) { echo "ERROR: " . $t->getMessage() . "\n"; }

echo "\n=== SESSION_CRON INSTALLMENT LOGIC ===\n";
echo "Checking includes/session_cron.php for installment_reminder event usage...\n";
exit;
