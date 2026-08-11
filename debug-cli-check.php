<?php
header('Content-Type: text/plain');

echo "=== PHP CLI & PROC_OPEN BACKGROUND PROCESS DIAGNOSTICS ===\n\n";

// Clear previous background output file
$outputFile = __DIR__ . '/debug-bg-output.txt';
if (file_exists($outputFile)) {
    unlink($outputFile);
}

// 1. Identify potential PHP CLI binaries
$candidates = [];
$candidates[] = 'php'; // rely on PATH
if (defined('PHP_BINARY')) {
    $candidates[] = PHP_BINARY;
}
$candidates[] = '/usr/bin/php';
$candidates[] = '/usr/local/bin/php';

// Deduplicate candidates
$candidates = array_unique($candidates);

echo "PHP Binary Candidates:\n";
foreach ($candidates as $c) {
    echo "  - {$c}\n";
}
echo "\n";

// 2. Select the best working candidate by running a quick version check using proc_open
$workingBinary = null;
foreach ($candidates as $bin) {
    echo "Testing candidate '{$bin}': ";
    $descriptorspec = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr
    ];
    
    // Command to check php version
    $process = proc_open($bin . " -v", $descriptorspec, $pipes);
    if (is_resource($process)) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $return_value = proc_close($process);
        
        if ($return_value === 0 && !empty($stdout)) {
            $firstLine = strtok($stdout, "\r\n");
            echo "SUCCESS (Version: {$firstLine})\n";
            if (!$workingBinary) {
                $workingBinary = $bin; // Pick the first working binary
            }
        } else {
            echo "FAILED (Code: {$return_value}, Stderr: " . trim($stderr) . ")\n";
        }
    } else {
        echo "FAILED (Cannot open process)\n";
    }
}

if (!$workingBinary) {
    echo "\nCRITICAL ERROR: No working PHP CLI binary found!\n";
    exit;
}

echo "\nSelected PHP CLI Binary: {$workingBinary}\n";

// 3. Attempt to launch the background process asynchronously
// On Linux, appending ' &' detaches the process.
$childScript = __DIR__ . '/debug-bg-child.php';
$cmd = $workingBinary . " " . escapeshellarg($childScript) . " 12345 > /dev/null 2>&1 &";

echo "Executing command: {$cmd}\n";

$descriptorspec = [
    0 => ["pipe", "r"],
    1 => ["file", "/dev/null", "w"],
    2 => ["file", "/dev/null", "w"]
];

$process = proc_open($cmd, $descriptorspec, $pipes);

if (is_resource($process)) {
    // Close stdin pipe
    fclose($pipes[0]);
    // Close process handle
    $res = proc_close($process);
    echo "Background process launched successfully. Parent process exiting now.\n";
} else {
    echo "ERROR: Failed to launch background process.\n";
}
