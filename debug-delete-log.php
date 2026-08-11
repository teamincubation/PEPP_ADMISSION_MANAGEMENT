<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$files = [
    'webhook-debug.log',
    'debug-check-db.php',
    'debug-fetch-conv.php',
    'debug-e2e-inbox.php'
];

foreach ($files as $file) {
    if (file_exists($file)) {
        unlink($file);
        echo "Deleted: $file\n";
    } else {
        echo "Not found: $file\n";
    }
}
echo "Cleanup completed successfully!\n";
exit;
