<?php
header('Content-Type: text/plain');
$file = __DIR__ . '/debug-bg-output.txt';
if (file_exists($file)) {
    echo file_get_contents($file);
} else {
    echo "FILE NOT FOUND";
}
