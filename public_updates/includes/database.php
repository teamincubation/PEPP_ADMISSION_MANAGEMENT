<?php
/**
 * PEPP Updates Public Portal — Database Connection Bridge
 *
 * Connects securely to the database using the existing project database configuration
 * WITHOUT duplicating database passwords or committing credentials.
 * Does NOT require or include admin authentication (auth.php).
 */

if (!defined('PEPP_PUBLIC_UPDATES_PORTAL')) {
    define('PEPP_PUBLIC_UPDATES_PORTAL', true);
}

// If PDO connection already exists in global scope (e.g. CLI, tests, pre-configured environment), reuse it
if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdo = $GLOBALS['pdo'];
    return;
}
if (isset($pdo) && $pdo instanceof PDO) {
    $GLOBALS['pdo'] = $pdo;
    return;
}

// Locate existing project database configuration
// Supports both in-repository structure and Hostinger production deployment:
// 1. In repo: public_updates/ is inside admissions/ -> dirname(__DIR__, 2)/config/database.php
// 2. Production: public_updates/ is deployed to /public_html/updates/ -> /public_html/admissions/config/database.php
$possible_configs = [
    dirname(__DIR__, 2) . '/config/database.php',
    dirname(__DIR__, 3) . '/admissions/config/database.php',
    dirname(__DIR__, 2) . '/admissions/config/database.php',
];

$loaded = false;
foreach ($possible_configs as $cfg) {
    if (file_exists($cfg)) {
        require_once $cfg;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    // Fallback: check environment variables if standalone
    $db_host = getenv('PEPP_DB_HOST') ?: (getenv('DB_HOST') ?: 'localhost');
    $db_name = getenv('PEPP_DB_NAME') ?: (getenv('DB_NAME') ?: 'u361910773_peppadmin');
    $db_user = getenv('PEPP_DB_USER') ?: (getenv('DB_USER') ?: 'root');
    $db_pass = getenv('PEPP_DB_PASS') ?: (getenv('DB_PASS') ?: '');

    try {
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $GLOBALS['pdo'] = $pdo;
    } catch (Throwable $e) {
        error_log('PEPP Public Portal DB connect error: ' . $e->getMessage());
        // Return 500 error page gracefully without exposing credentials or internal paths
        http_response_code(500);
        include __DIR__ . '/../500.php';
        exit();
    }
}
