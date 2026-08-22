<?php
header('Content-Type: text/plain');

echo "========================================\n";
echo "       SEARCHING FOR SERVER LOG FILES\n";
echo "========================================\n\n";

$dirs = [
    dirname(__DIR__),
    dirname(__DIR__) . '/scratch',
    dirname(dirname(dirname(__DIR__))), // home dir
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    echo "Scanning directory: {$dir}\n";
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        if (is_file($path)) {
            $lower = strtolower($file);
            if (strpos($lower, 'log') !== false || strpos($lower, 'error') !== false || filesize($path) > 10000) {
                echo " - File: {$file} (Size: " . filesize($path) . " bytes, Modified: " . date('Y-m-d H:i:s', filemtime($path)) . ")\n";
                if (filesize($path) < 50000 && filesize($path) > 0) {
                    echo "--- Content of {$file} ---\n";
                    echo implode("\n", array_slice(explode("\n", file_get_contents($path)), -15)) . "\n";
                    echo "---------------------------\n\n";
                }
            }
        }
    }
}
