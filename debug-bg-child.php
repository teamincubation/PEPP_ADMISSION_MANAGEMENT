<?php
// Prevent timing out
set_time_limit(20);

$outputFile = __DIR__ . '/debug-bg-output.txt';
$arg = $argv[1] ?? 'no-arg';

file_put_contents($outputFile, "Child started at " . date('Y-m-d H:i:s') . " with arg: {$arg}\n", FILE_APPEND);

// Sleep to verify it survives the parent HTTP request completion
sleep(4);

file_put_contents($outputFile, "Child completed at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
echo "Child execution done.\n";
