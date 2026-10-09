<?php
/**
 * PEPP Updates Public Portal — Bootstrap Entrypoint
 *
 * Configures runtime environment, database bridge, core helper functions,
 * and security headers.
 */

if (!defined('PEPP_PUBLIC_UPDATES_PORTAL')) {
    define('PEPP_PUBLIC_UPDATES_PORTAL', true);
}

date_default_timezone_set('Asia/Kolkata');

// Security headers (guarded against CLI/headers_sent)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
}

// Load database bridge
require_once __DIR__ . '/database.php';

// Load helpers
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/seo.php';
