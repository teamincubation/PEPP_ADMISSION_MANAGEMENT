<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $stmt = $pdo->query("
        SELECT id, channel, recipient, recipient_name, template_name, template_data, status, error_message, updated_at 
        FROM communication_queue 
        ORDER BY id DESC LIMIT 1
    ");
    $log = $stmt->fetch();
    
    if ($log) {
        echo "=== QUEUED WHATSAPP MESSAGE LOG ===\n";
        echo "Queue ID: #" . $log['id'] . "\n";
        echo "Recipient: " . $log['recipient_name'] . " (" . $log['recipient'] . ")\n";
        echo "Template: " . $log['template_name'] . "\n";
        echo "Status: " . strtoupper($log['status']) . "\n";
        echo "Error/Info: " . ($log['error_message'] ?: '-') . "\n";
        echo "Updated At: " . $log['updated_at'] . "\n\n";
        
        $data = json_decode($log['template_data'], true) ?: [];
        echo "--- Button Parameters (URL Token) ---\n";
        $btnParam = $data['button_parameters'][0] ?? '';
        echo "Button Param {{1}}: " . $btnParam . "\n";
        
        // Verify token signature
        $parts = explode('-', $btnParam, 2);
        if (count($parts) === 2) {
            $id = (int)$parts[0];
            $sig = $parts[1];
            $expected_sig = hash_hmac('sha256', (string)$id, INVOICE_HMAC_SECRET);
            if (hash_equals($expected_sig, $sig)) {
                echo "Token Signature VALID [OK]\n";
                echo "Final URL equivalent: https://pepplearning.in/admissions/invoice-pdf.php?token=" . $btnParam . "\n";
            } else {
                echo "Token Signature INVALID!\n";
            }
        } else {
            echo "Button parameter format incorrect or empty!\n";
        }
        
    } else {
        echo "No queued messages found.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
