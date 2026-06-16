<?php
require_once 'includes/auth.php';
require_super_admin();
require_once 'includes/reminders_helper.php';
header('Content-Type: text/plain; charset=UTF-8');

echo "REMINDERS DIAGNOSTIC\n====================\n\n";
echo "Logged in as: {$admin_username}\n\n";

echo "Table 'reminders' exists: " . (reminders_table_exists($pdo) ? "YES" : "NO  <-- run database-update-5.sql") . "\n";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM admins LIKE 'email'")->fetchColumn();
    echo "admins.email column: " . ($cols ? "YES" : "NO  <-- run database-update-5.sql") . "\n";
} catch (Throwable $e) { echo "admins.email check failed: " . $e->getMessage() . "\n"; }

if (reminders_table_exists($pdo)) {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM reminders")->fetchColumn();
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM reminders WHERE status='pending'")->fetchColumn();
    echo "\nTotal reminders in table: {$total}\n";
    echo "Pending reminders: {$pending}\n\n";

    echo "ALL REMINDERS (newest first):\n";
    foreach ($pdo->query("SELECT id, title, remind_at, assigned_to, status, created_by FROM reminders ORDER BY id DESC LIMIT 20") as $r) {
        echo "  #{$r['id']} [{$r['status']}] \"{$r['title']}\" @ {$r['remind_at']} -> {$r['assigned_to']} (by {$r['created_by']})\n";
    }

    echo "\nVISIBLE TO YOU ({$admin_username}) as pending:\n";
    $mine = reminders_for($pdo, $admin_username, ['pending']);
    if (empty($mine)) {
        echo "  (none) - a reminder shows for you only if assigned_to = '{$admin_username}' or '__ALL__'\n";
    } else {
        foreach ($mine as $r) echo "  #{$r['id']} \"{$r['title']}\" -> {$r['assigned_to']}\n";
    }

    echo "\nDUE NOW for you:\n";
    foreach (reminders_due($pdo, $admin_username) as $r) echo "  #{$r['id']} \"{$r['title']}\" @ {$r['remind_at']}\n";
}
echo "\nDone. Delete reminders-check.php after use.\n";
