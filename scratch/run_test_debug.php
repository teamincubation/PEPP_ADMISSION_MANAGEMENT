<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: text/plain');

echo "========================================\n";
echo "    RUNNING TEST SUITE WITH DEBUGGING\n";
echo "========================================\n\n";

require __DIR__ . '/test_queue_starvation_prevention.php';
