<?php
header('Content-Type: text/plain');

echo "=== PEPP PRODUCTION ENVIRONMENT CAPABILITIES CHECK ===\n\n";

$funcs = ['exec', 'shell_exec', 'popen', 'proc_open', 'curl_multi_init'];
foreach ($funcs as $f) {
    $exists = function_exists($f);
    $disabled = false;
    
    // Some hosts don't remove the function but disable it via disable_functions
    $disable_functions = ini_get('disable_functions');
    if ($disable_functions) {
        $disabled_arr = array_map('trim', explode(',', strtolower($disable_functions)));
        if (in_array(strtolower($f), $disabled_arr, true)) {
            $disabled = true;
        }
    }
    
    echo "Function '{$f}': " . ($exists ? "Exists" : "DOES NOT EXIST");
    echo " | Status in disable_functions: " . ($disabled ? "DISABLED" : "ENABLED") . "\n";
}

echo "\nini_get('disable_functions') output:\n" . (ini_get('disable_functions') ?: '(empty)') . "\n";

// Let's test running a dummy exec command to see if it throws an error/warning
if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', strtolower(ini_get('disable_functions') ?? ''))), true)) {
    try {
        $out = [];
        $res = exec('php -v', $out);
        echo "\nTest exec('php -v') returned: " . ($res ? "Success (output line 0: {$out[0]})" : "False/Empty") . "\n";
    } catch (Exception $e) {
        echo "\nTest exec('php -v') threw Exception: " . $e->getMessage() . "\n";
    }
} else {
    echo "\nSkipped exec() test because it is disabled/unavailable.\n";
}
