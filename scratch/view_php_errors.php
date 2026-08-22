<?php
header('Content-Type: text/plain');

echo "========================================\n";
echo "       PHP ERROR LOGS DIAGNOSTIC\n";
echo "========================================\n\n";

$logPaths = [
    dirname(__DIR__) . '/error_log',
    dirname(__DIR__) . '/scratch/error_log',
    '/home/u361910773/logs/pepplearning_in.php.error.log',
    ini_get('error_log')
];

foreach ($logPaths as $path) {
    if (empty($path)) continue;
    if (file_exists($path)) {
        echo "Log found: {$path} (Size: " . filesize($path) . " bytes)\n";
        $lines = array_slice(explode("\n", file_get_contents($path)), -30);
        echo "Last 30 lines:\n";
        echo implode("\n", $lines) . "\n\n";
    } else {
        echo "Log NOT found: {$path}\n";
    }
}
