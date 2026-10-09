<?php
/**
 * PEPP Updates Public Portal — Database Connection Bridge
 *
 * Establishes a lightweight, isolated, read-only PDO connection.
 * Completely decoupled from admissions/config/database.php.
 * Does NOT execute any ERP schema verification or self-healing migrations.
 */

if (!defined('PEPP_PUBLIC_UPDATES_PORTAL')) {
    define('PEPP_PUBLIC_UPDATES_PORTAL', true);
}

// 1. If PDO connection already exists in global scope (e.g. CLI, tests, pre-configured environment), reuse it
if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdo = $GLOBALS['pdo'];
    return;
}
if (isset($pdo) && $pdo instanceof PDO) {
    $GLOBALS['pdo'] = $pdo;
    return;
}

// 2. Discover Database Credentials
// Production: credentials are read from gitignored local config inside includes/
$local_cfg = __DIR__ . '/db_config.php';
if (file_exists($local_cfg)) {
    require_once $local_cfg;
}

// Fallback: check if admissions/config/secrets.php is readable directly (zero ERP code, constants only)
if (!defined('PEPP_DB_HOST') && !defined('DB_HOST')) {
    $possible_secrets = [
        dirname(__DIR__, 2) . '/admissions/config/secrets.php',
        dirname(__DIR__, 2) . '/config/secrets.php',
    ];
    foreach ($possible_secrets as $sec_path) {
        if (@file_exists($sec_path)) {
            require_once $sec_path;
            break;
        }
    }
}

// Extract credentials from constants or environment
$db_host = defined('PEPP_DB_HOST') ? PEPP_DB_HOST : (defined('DB_HOST') ? DB_HOST : (getenv('PEPP_DB_HOST') ?: (getenv('DB_HOST') ?: 'localhost')));
$db_name = defined('PEPP_DB_NAME') ? PEPP_DB_NAME : (defined('DB_NAME') ? DB_NAME : (getenv('PEPP_DB_NAME') ?: (getenv('DB_NAME') ?: 'u361910773_peppadmin')));
$db_user = defined('PEPP_DB_USER') ? PEPP_DB_USER : (defined('DB_USER') ? DB_USER : (getenv('PEPP_DB_USER') ?: (getenv('DB_USER') ?: null)));
$db_pass = defined('PEPP_DB_PASS') ? PEPP_DB_PASS : (defined('DB_PASS') ? DB_PASS : (getenv('PEPP_DB_PASS') ?: (getenv('DB_PASS') ?: null)));

// 3. Connect to MySQL Database (Production / Staging)
if ($db_user !== null && $db_pass !== null) {
    try {
        $pdo = new PDO(
            "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
            $db_user,
            $db_pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET time_zone = '+05:30'");
        $GLOBALS['pdo'] = $pdo;
        return;
    } catch (Throwable $e) {
        error_log('PEPP Public Portal DB connect error: ' . $e->getMessage());
        http_response_code(500);
        include __DIR__ . '/../500.php';
        exit();
    }
}

// 4. Local Development Fallback: In local dev/repo, if credentials are not found and local SQLite exists
$sqlite_dev_db = dirname(__DIR__, 2) . '/scratch_test_db.sqlite';
if (file_exists($sqlite_dev_db)) {
    try {
        $pdo = new PDO("sqlite:" . $sqlite_dev_db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $GLOBALS['pdo'] = $pdo;
        return;
    } catch (Throwable $e) {}
}

// If no credentials or connection could be established, return clean 500 error page
error_log('PEPP Public Portal DB configuration missing: no database credentials found.');
http_response_code(500);
include __DIR__ . '/../500.php';
exit();
