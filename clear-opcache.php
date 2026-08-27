<?php
/**
 * PEPP ERP Cache Diagnostic Tool.
 * Resets the OPcache to resolve file caching on Hostinger.
 */
require_once __DIR__ . '/includes/auth.php';
require_super_admin();

header('Content-Type: text/plain');

if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "OPcache Reset: SUCCESS\n";
    } else {
        echo "OPcache Reset: FAILED (reset returned false)\n";
    }
} else {
    echo "OPcache Reset: NOT AVAILABLE (opcache_reset function not found)\n";
}

// Print cache status if possible
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    if ($status) {
        echo "OPcache Enabled: " . ($status['opcache_enabled'] ? 'Yes' : 'No') . "\n";
        echo "Cached Scripts: " . count($status['scripts'] ?? []) . "\n";
    }
}
