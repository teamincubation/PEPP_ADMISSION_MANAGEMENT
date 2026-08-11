<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');
echo "=== POST-ACTIVATION VERIFY ===\n";

$stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name='whatsapp_outbound_mode'");
$stmt->execute();
echo "Mode: " . $stmt->fetchColumn() . "\n";

$a = $pdo->query("SELECT id, old_mode, new_mode, changed_by, changed_at FROM whatsapp_mode_audit ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "Audit: id=" . $a['id'] . " old=" . $a['old_mode'] . " new=" . $a['new_mode'] . " by=" . $a['changed_by'] . " at=" . $a['changed_at'] . "\n";

echo "Pending: " . $pdo->query("SELECT COUNT(*) FROM communication_queue WHERE status='pending'")->fetchColumn() . "\n";
echo "Total queue: " . $pdo->query("SELECT COUNT(*) FROM communication_queue")->fetchColumn() . "\n";
echo "=== DONE ===\n";
