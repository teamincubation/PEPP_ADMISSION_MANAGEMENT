<?php
header('Content-Type: text/plain; charset=UTF-8');
echo "=== SERVER PHP ERROR LOG ===\n\n";

$log_path = ini_get('error_log');
echo "Configured error_log path: " . $log_path . "\n\n";

if ($log_path && file_exists($log_path)) {
    echo "Content of $log_path (last 30 lines):\n";
    $lines = file($log_path);
    $last_lines = array_slice($lines, -30);
    echo implode("", $last_lines);
} else {
    echo "Error log file does not exist or is not readable.\n";
}

echo "\n--- CHECKING LOCAL php_errors.log ---\n";
$local_log = __DIR__ . '/php_errors.log';
if (file_exists($local_log)) {
    echo "Content of $local_log (last 30 lines):\n";
    $lines = file($local_log);
    $last_lines = array_slice($lines, -30);
    echo implode("", $last_lines);
} else {
    echo "Local php_errors.log does not exist.\n";
}
