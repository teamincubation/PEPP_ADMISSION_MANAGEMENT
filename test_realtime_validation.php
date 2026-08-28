<?php
/**
 * PEPP ERP - Student Registration Real-Time Validation Unit/Integration Tests
 *
 * Runs test cases for email/whatsapp validation logic under register.php
 * using a sandboxed SQLite memory database.
 *
 * Run: php test_realtime_validation.php
 */

$passed = 0;
$failed = 0;

function assert_json_response($label, $expected_json, $actual_output) {
    global $passed, $failed;
    $actual_json = json_decode($actual_output, true);
    if ($actual_json === null) {
        $failed++;
        echo "❌ FAIL: {$label}\n";
        echo "   Could not parse JSON. Raw output: {$actual_output}\n\n";
        return;
    }

    $all_match = true;
    foreach ($expected_json as $key => $expected_val) {
        if (!array_key_exists($key, $actual_json) || $actual_json[$key] !== $expected_val) {
            $all_match = false;
            break;
        }
    }

    if ($all_match) {
        $passed++;
        echo "✅ PASS: {$label}\n";
    } else {
        $failed++;
        echo "❌ FAIL: {$label}\n";
        echo "   Expected subset: " . json_encode($expected_json) . "\n";
        echo "   Got response:    " . json_encode($actual_json) . "\n\n";
    }
}

echo "=== Running PEPP ERP Student Registration Real-Time Validation Tests ===\n\n";

// Helper to run sandbox subprocess
function run_sandbox_request($get_params) {
    $php_code = '
        $_SERVER["HTTP_X_TESTING_MODE"] = "true";
        $_SERVER["REQUEST_METHOD"] = "GET";
        require_once "config/database.php";

        // Insert mock data
        $stmt = $pdo->prepare("
            INSERT INTO users (user_id, name, email, whatsapp_country_code, whatsapp_number, status, pepp_course, pepp_academic_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(["u1", "John Doe", "john@example.com", "+91", "9876543210", "approved", "science", "2026-27"]);

        $_GET = ' . var_export($get_params, true) . ';
        require "register.php";
    ';
    
    // Write code to temp file to execute safely across platforms
    $temp_file = __DIR__ . '/scratch/temp_sandbox_run.php';
    if (!is_dir(__DIR__ . '/scratch')) {
        mkdir(__DIR__ . '/scratch', 0777, true);
    }
    file_put_contents($temp_file, '<?php ' . $php_code);
    
    $output = shell_exec('php ' . escapeshellarg($temp_file));
    if (file_exists($temp_file)) {
        unlink($temp_file);
    }
    return trim($output);
}

// Test Case 1: Existing Email check (case-insensitive)
$res = run_sandbox_request([
    'ajax' => 'check_email',
    'email' => 'JOHN@example.com',
    'course' => 'science',
    'academic_year' => '2026-27'
]);
assert_json_response("Test Case 1: Existing Email check (case-insensitive)", [
    'exists' => true,
    'eligible' => false,
    'reason' => 'already_registered'
], $res);

// Test Case 2: Available Email check
$res = run_sandbox_request([
    'ajax' => 'check_email',
    'email' => 'alice@example.com',
    'course' => 'science',
    'academic_year' => '2026-27'
]);
assert_json_response("Test Case 2: Available Email check", [
    'exists' => false,
    'eligible' => true,
    'reason' => null
], $res);

// Test Case 3: Invalid Email format check
$res = run_sandbox_request([
    'ajax' => 'check_email',
    'email' => 'invalid-email-format',
    'course' => 'science',
    'academic_year' => '2026-27'
]);
assert_json_response("Test Case 3: Invalid Email format check", [
    'exists' => false,
    'eligible' => false,
    'reason' => 'invalid_format'
], $res);

// Test Case 4: Existing WhatsApp check (normalized input)
$res = run_sandbox_request([
    'ajax' => 'check_whatsapp',
    'whatsapp' => ' +91 98765-43210 ',
    'course' => 'science',
    'academic_year' => '2026-27'
]);
assert_json_response("Test Case 4: Existing WhatsApp check (normalized input)", [
    'exists' => true,
    'eligible' => false,
    'reason' => 'already_registered'
], $res);

// Test Case 5: Available WhatsApp check
$res = run_sandbox_request([
    'ajax' => 'check_whatsapp',
    'whatsapp' => '9999999999',
    'course' => 'science',
    'academic_year' => '2026-27'
]);
assert_json_response("Test Case 5: Available WhatsApp check", [
    'exists' => false,
    'eligible' => true,
    'reason' => null
], $res);

// Test Case 6: Invalid WhatsApp format check
$res = run_sandbox_request([
    'ajax' => 'check_whatsapp',
    'whatsapp' => '12345',
    'course' => 'science',
    'academic_year' => '2026-27'
]);
assert_json_response("Test Case 6: Invalid WhatsApp format check", [
    'exists' => false,
    'eligible' => false,
    'reason' => 'invalid_format'
], $res);

// Test Case 7: Duplicate Email allowed for different course
$res = run_sandbox_request([
    'ajax' => 'check_email',
    'email' => 'john@example.com',
    'course' => 'commerce',
    'academic_year' => '2026-27'
]);
assert_json_response("Test Case 7: Duplicate Email allowed for different course", [
    'exists' => false,
    'eligible' => true,
    'reason' => null
], $res);

// Test Case 8: Duplicate WhatsApp allowed for different academic year
$res = run_sandbox_request([
    'ajax' => 'check_whatsapp',
    'whatsapp' => '9876543210',
    'course' => 'science',
    'academic_year' => '2027-28'
]);
assert_json_response("Test Case 8: Duplicate WhatsApp allowed for different academic year", [
    'exists' => false,
    'eligible' => true,
    'reason' => null
], $res);

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
