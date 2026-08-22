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
];

echo "4. Checking PHP Binary Locations:\n";
foreach ($possibleBinaries as $bin) {
    if (file_exists($bin)) {
        echo " - {$bin} exists.\n";
    } else {
        echo " - {$bin} does NOT exist.\n";
    }
}

echo "\n5. Checking cron-queue.php absolute path existence:\n";
$cronPath = dirname(__DIR__) . '/cron-queue.php';
if (file_exists($cronPath)) {
    echo " - cron-queue.php exists at: {$cronPath}\n";
} else {
    echo " - cron-queue.php NOT found at: {$cronPath}\n";
}

echo "\n6. Checking disable_functions in php.ini:\n";
echo " - disabled: " . ini_get('disable_functions') . "\n";
