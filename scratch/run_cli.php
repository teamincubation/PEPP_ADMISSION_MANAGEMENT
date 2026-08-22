<?php
header('Content-Type: text/plain');

echo "========================================\n";
echo "    HOSTINGER CLI TEST RUNNER\n";
echo "========================================\n\n";

$cmd = "/opt/alt/php82/usr/bin/php /home/u361910773/domains/pepplearning.in/public_html/admissions/scratch/test_queue_starvation_prevention.php 2>&1";

echo "Executing Command: {$cmd}\n\n";

$output = [];
$return_var = -1;
exec($cmd, $output, $return_var);

echo "Exit Code: {$return_var}\n\n";
echo "Output:\n";
echo implode("\n", $output) . "\n";
