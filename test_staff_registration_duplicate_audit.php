<?php
/**
 * Test Suite: Staff Registration Duplicate Check Audit
 *
 * Verifies:
 * 1. All 9 Cross-Matrix Combinations (Employee, Faculty, Intern)
 * 2. Active status blocking ('pending', 'under_review', 'approved')
 * 3. Inactive/terminal status allowing ('rejected', 'cancelled')
 * 4. Identifier scoping (matching on email only, mobile only, or both)
 * 5. Static code verification of staff-registration.php
 */

$passed = 0;
$failed = 0;
$total = 0;

function run_test($name, $cb) {
    global $passed, $failed, $total;
    $total++;
    try {
        $result = $cb();
        if ($result !== false) {
            echo "  [PASS] {$name}\n";
            $passed++;
        } else {
            echo "  [FAIL] {$name}\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "======================================================================\n";
echo " STAFF REGISTRATION DUPLICATE CHECK AUDIT\n";
echo "======================================================================\n\n";

// Set up in-memory SQLite database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE staff_registration_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        application_reference TEXT NOT NULL,
        full_name TEXT NOT NULL,
        mobile_number TEXT NOT NULL,
        email TEXT NOT NULL,
        application_for TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

/**
 * Simulates the exact duplicate check logic in staff-registration.php
 */
function check_duplicate(PDO $pdo, string $email, string $mobile, string $application_for): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_registration_requests WHERE (email = ? OR mobile_number = ?) AND application_for = ? AND status IN ('pending','under_review','approved')");
    $stmt->execute([$email, $mobile, $application_for]);
    return ((int)$stmt->fetchColumn() > 0);
}

// ----------------------------------------------------------------------
// 1. ALL 9 CROSS-MATRIX COMBINATIONS
// ----------------------------------------------------------------------
echo "--- 1. Testing All 9 Application Type Combinations ---\n";

$types = ['employee', 'faculty', 'intern'];

foreach ($types as $existing_type) {
    foreach ($types as $new_type) {
        $expected_block = ($existing_type === $new_type);
        $expected_result = $expected_block ? 'BLOCK' : 'ALLOW';
        $test_label = ucfirst($existing_type) . " -> " . ucfirst($new_type) . " = " . $expected_result;

        run_test($test_label, function() use ($pdo, $existing_type, $new_type, $expected_block) {
            // Clear table
            $pdo->exec("DELETE FROM staff_registration_requests");

            // Seed existing active application
            $stmt = $pdo->prepare("INSERT INTO staff_registration_requests (application_reference, full_name, mobile_number, email, application_for, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute(['REF-TEST-001', 'Test Candidate', '9876543210', 'candidate@pepplearning.in', $existing_type, 'approved']);

            // Attempt new registration with the new type
            $is_duplicate = check_duplicate($pdo, 'candidate@pepplearning.in', '9876543210', $new_type);

            return $is_duplicate === $expected_block;
        });
    }
}

// ----------------------------------------------------------------------
// 2. STATUS VARIATION TESTS
// ----------------------------------------------------------------------
echo "\n--- 2. Testing Status Protection Boundaries ---\n";

$statuses = [
    'pending'      => true,  // should block
    'under_review' => true,  // should block
    'approved'     => true,  // should block
    'rejected'     => false, // should allow
    'cancelled'    => false, // should allow
];

foreach ($statuses as $status => $should_block) {
    $expected_action = $should_block ? 'BLOCKED' : 'ALLOWED';
    run_test("Existing status '{$status}' for same type must be {$expected_action}", function() use ($pdo, $status, $should_block) {
        $pdo->exec("DELETE FROM staff_registration_requests");

        $stmt = $pdo->prepare("INSERT INTO staff_registration_requests (application_reference, full_name, mobile_number, email, application_for, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute(['REF-STATUS-001', 'Status Test Candidate', '9876543211', 'status@pepplearning.in', 'faculty', $status]);

        $is_duplicate = check_duplicate($pdo, 'status@pepplearning.in', '9876543211', 'faculty');
        return $is_duplicate === $should_block;
    });
}

// ----------------------------------------------------------------------
// 3. IDENTIFIER SCOPING TESTS (Email only, Mobile only, Both)
// ----------------------------------------------------------------------
echo "\n--- 3. Testing Identifier Scoping (Email / Mobile matches) ---\n";

run_test("Same email + different mobile + same type => BLOCK", function() use ($pdo) {
    $pdo->exec("DELETE FROM staff_registration_requests");
    $stmt = $pdo->prepare("INSERT INTO staff_registration_requests (application_reference, full_name, mobile_number, email, application_for, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['REF-ID-001', 'ID Candidate 1', '9111111111', 'common@pepplearning.in', 'employee', 'pending']);

    $is_duplicate = check_duplicate($pdo, 'common@pepplearning.in', '9222222222', 'employee');
    return $is_duplicate === true;
});

run_test("Different email + same mobile + same type => BLOCK", function() use ($pdo) {
    $pdo->exec("DELETE FROM staff_registration_requests");
    $stmt = $pdo->prepare("INSERT INTO staff_registration_requests (application_reference, full_name, mobile_number, email, application_for, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['REF-ID-002', 'ID Candidate 2', '9333333333', 'user1@pepplearning.in', 'employee', 'under_review']);

    $is_duplicate = check_duplicate($pdo, 'user2@pepplearning.in', '9333333333', 'employee');
    return $is_duplicate === true;
});

run_test("Same email + different mobile + different type => ALLOW", function() use ($pdo) {
    $pdo->exec("DELETE FROM staff_registration_requests");
    $stmt = $pdo->prepare("INSERT INTO staff_registration_requests (application_reference, full_name, mobile_number, email, application_for, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['REF-ID-003', 'ID Candidate 3', '9111111111', 'common@pepplearning.in', 'employee', 'approved']);

    $is_duplicate = check_duplicate($pdo, 'common@pepplearning.in', '9222222222', 'faculty');
    return $is_duplicate === false;
});

run_test("Different email + same mobile + different type => ALLOW", function() use ($pdo) {
    $pdo->exec("DELETE FROM staff_registration_requests");
    $stmt = $pdo->prepare("INSERT INTO staff_registration_requests (application_reference, full_name, mobile_number, email, application_for, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['REF-ID-004', 'ID Candidate 4', '9333333333', 'user1@pepplearning.in', 'employee', 'approved']);

    $is_duplicate = check_duplicate($pdo, 'user2@pepplearning.in', '9333333333', 'intern');
    return $is_duplicate === false;
});

run_test("Completely different email & mobile => ALLOW", function() use ($pdo) {
    $pdo->exec("DELETE FROM staff_registration_requests");
    $stmt = $pdo->prepare("INSERT INTO staff_registration_requests (application_reference, full_name, mobile_number, email, application_for, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['REF-ID-005', 'ID Candidate 5', '9555555555', 'userA@pepplearning.in', 'faculty', 'approved']);

    $is_duplicate = check_duplicate($pdo, 'userB@pepplearning.in', '9666666666', 'faculty');
    return $is_duplicate === false;
});

// ----------------------------------------------------------------------
// 4. MULTI-ROLE COEXISTENCE TEST
// ----------------------------------------------------------------------
echo "\n--- 4. Testing Multi-Role Coexistence for a Single Person ---\n";

run_test("Single person can register for Employee, Faculty, and Intern sequentially", function() use ($pdo) {
    $pdo->exec("DELETE FROM staff_registration_requests");
    $email = 'multirole@pepplearning.in';
    $mobile = '9888877777';

    // Step 1: Employee registration
    if (check_duplicate($pdo, $email, $mobile, 'employee')) return false;
    $stmt = $pdo->prepare("INSERT INTO staff_registration_requests (application_reference, full_name, mobile_number, email, application_for, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['REF-MULTI-EMP', 'Multi Role Person', $mobile, $email, 'employee', 'approved']);

    // Step 2: Employee duplicate is blocked
    if (!check_duplicate($pdo, $email, $mobile, 'employee')) return false;

    // Step 3: Faculty registration is allowed
    if (check_duplicate($pdo, $email, $mobile, 'faculty')) return false;
    $stmt->execute(['REF-MULTI-FAC', 'Multi Role Person', $mobile, $email, 'faculty', 'pending']);

    // Step 4: Faculty duplicate is now blocked
    if (!check_duplicate($pdo, $email, $mobile, 'faculty')) return false;

    // Step 5: Intern registration is allowed
    if (check_duplicate($pdo, $email, $mobile, 'intern')) return false;
    $stmt->execute(['REF-MULTI-INT', 'Multi Role Person', $mobile, $email, 'intern', 'under_review']);

    // Step 6: Intern duplicate is now blocked
    if (!check_duplicate($pdo, $email, $mobile, 'intern')) return false;

    // All three exist actively in the table
    $count = (int)$pdo->query("SELECT COUNT(*) FROM staff_registration_requests WHERE email = '{$email}'")->fetchColumn();
    return $count === 3;
});

// ----------------------------------------------------------------------
// 5. STATIC SOURCE CODE AUDIT of staff-registration.php
// ----------------------------------------------------------------------
echo "\n--- 5. Static Code Inspection of staff-registration.php ---\n";

run_test("staff-registration.php duplicate query contains application_for = ?", function() {
    $code = file_get_contents(__DIR__ . '/staff-registration.php');
    return strpos($code, "WHERE (email = ? OR mobile_number = ?) AND application_for = ? AND status IN ('pending','under_review','approved')") !== false;
});

run_test("staff-registration.php passes [\$email, \$mobile, \$application_for] to execute()", function() {
    $code = file_get_contents(__DIR__ . '/staff-registration.php');
    return strpos($code, '$stmt->execute([$email, $mobile, $application_for]);') !== false;
});

run_test("staff-registration.php maintains allowed types ['employee','faculty','intern']", function() {
    $code = file_get_contents(__DIR__ . '/staff-registration.php');
    return strpos($code, "in_array(\$application_for, ['employee','faculty','intern'])") !== false;
});

echo "\n======================================================================\n";
echo " AUDIT SUMMARY: {$passed} PASSED, {$failed} FAILED (TOTAL: {$total})\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
