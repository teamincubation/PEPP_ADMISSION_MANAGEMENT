<?php
header('Content-Type: text/plain');

echo "========================================\n";
echo "       HOSTINGER CLI ENVIRONMENT CHECK\n";
echo "========================================\n\n";

echo "1. Server OS/Uname: " . php_uname() . "\n";
echo "2. Absolute path of this file: " . __FILE__ . "\n";
echo "3. Admissions Root directory: " . dirname(__DIR__) . "\n\n";

$possibleBinaries = [
    '/opt/alt/php82/usr/bin/php',
    '/opt/alt/php81/usr/bin/php',
    '/usr/local/bin/php',
    '/usr/bin/php',
    'php'
];

echo "4. Checking PHP Binary Locations:\n";
foreach ($possibleBinaries as $bin) {
    if ($bin === 'php') {
        $output = [];
        $return_var = -1;
        @exec("php -v", $output, $return_var);
        if ($return_var === 0) {
            echo " - 'php' is available on PATH: " . implode(" | ", array_slice($output, 0, 1)) . "\n";
        } else {
            echo " - 'php' is NOT directly available on PATH.\n";
        }
    } else {
        if (file_exists($bin)) {
            $output = [];
            $return_var = -1;
            @exec($bin . " -v", $output, $return_var);
            echo " - {$bin} exists and is executable: " . implode(" | ", array_slice($output, 0, 1)) . "\n";
        } else {
            echo " - {$bin} does NOT exist.\n";
        }
    }
}

echo "\n5. Checking cron-queue.php absolute path existence:\n";
$cronPath = dirname(__DIR__) . '/cron-queue.php';
if (file_exists($cronPath)) {
    echo " - cron-queue.php exists at: {$cronPath}\n";
} else {
    echo " - cron-queue.php NOT found at: {$cronPath}\n";
}
