<?php
/**
 * PEPP Admin - quick server diagnostic. Upload, open once in the browser,
 * then DELETE this file. It checks the usual causes of a blank/500 page:
 * PHP version, required include files, extensions and DB connectivity.
 */
require_once __DIR__ . '/includes/auth.php';
require_super_admin();

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=UTF-8');

echo "PEPP ADMIN SYSTEM CHECK\n=======================\n\n";
echo "PHP version : " . PHP_VERSION . (version_compare(PHP_VERSION, '7.4', '>=') ? "  [OK]" : "  [!] 7.4+ recommended") . "\n\n";

echo "REQUIRED FILES\n--------------\n";
$files = [
    'config/database.php',
    'includes/auth.php', 'includes/admin_nav.php', 'includes/admin_footer.php',
    'includes/invoice_helper.php', 'includes/pdf_invoice.php',
    'includes/invoice_mailer.php', 'includes/template_helper.php',
    'assets/css/admin-theme.css', 'pepp-logo.jpg',
    'lead-management.php', 'lead-details.php', 'invoices.php',
    'reminders-action.php', 'includes/reminders_helper.php',
    'faculties.php', 'faculty-report.php', 'sessions.php', 'accounts.php',
    'includes/session_mailer.php', 'includes/session_cron.php',
    'config/google_oauth.php', 'google-callback.php', 'alumni-database.php',
    'alumni-portal.php', 'marketing.php', 'includes/referral_helper.php',
];
foreach ($files as $f) {
    echo str_pad($f, 36) . (file_exists(__DIR__ . '/' . $f) ? "[FOUND]" : "[MISSING]  <-- upload this") . "\n";
}

echo "\nEXTENSIONS\n----------\n";
foreach (['pdo_mysql', 'mbstring', 'iconv', 'json'] as $ext) {
    echo str_pad($ext, 36) . (extension_loaded($ext) ? "[OK]" : "[MISSING]") . "\n";
}

echo "\nDATABASE\n--------\n";
try {
    require __DIR__ . '/config/database.php';
    echo "Connection                          [OK]\n";
    foreach (['admins', 'admin_activity_log', 'invoices', 'leads', 'lead_activity', 'reminders', 'reminder_seen', 'faculties', 'faculty_payments', 'sessions', 'session_notifications', 'expense_types', 'expenses', 'alumni', 'peppians', 'referral_programs', 'referees', 'referral_earnings', 'referral_payouts', 'coupons', 'coupon_redemptions', 'marketing_updates'] as $t) {
        $ok = (bool)$pdo->query("SHOW TABLES LIKE '" . $t . "'")->fetchColumn();
        echo str_pad("table: $t", 36) . ($ok ? "[OK]" : "[MISSING]  <-- run the SQL update") . "\n";
    }
} catch (Throwable $e) {
    echo "Connection FAILED: " . $e->getMessage() . "\n";
}

echo "\nSYNTAX LOAD TEST (includes)\n---------------------------\n";
foreach (['includes/invoice_helper.php', 'includes/template_helper.php'] as $f) {
    $p = __DIR__ . '/' . $f;
    if (!file_exists($p)) { echo str_pad($f, 36) . "[SKIPPED]\n"; continue; }
    try { require_once $p; echo str_pad($f, 36) . "[LOADS OK]\n"; }
    catch (Throwable $e) { echo str_pad($f, 36) . "[ERROR] " . $e->getMessage() . "\n"; }
}

echo "\nDone. If a file shows MISSING, upload it from the package and reload.\nDELETE system-check.php after use.\n";
