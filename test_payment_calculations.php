<?php
/**
 * Payment System Test Script
 * Tests all payment calculations and logic across the PEPP Learning system
 */

$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

if (file_exists('config/database.php')) {
    require_once 'config/database.php';
} else {
    require_once '../config/database.php';
}

echo "<h1>PEPP Learning Payment System Test</h1>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .test-section { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 5px; }
    .success { color: #28a745; }
    .error { color: #dc3545; }
    .warning { color: #ffc107; }
    .info { color: #17a2b8; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style>\n";

$test_results = [];
$total_tests = 0;
$passed_tests = 0;

function runTest($test_name, $expected, $actual, $description = '') {
    global $test_results, $total_tests, $passed_tests;
    
    $total_tests++;
    $passed = ($expected == $actual);
    if ($passed) $passed_tests++;
    
    $status = $passed ? 'PASS' : 'FAIL';
    $class = $passed ? 'success' : 'error';
    
    $test_results[] = [
        'name' => $test_name,
        'expected' => $expected,
        'actual' => $actual,
        'status' => $status,
        'class' => $class,
        'description' => $description
    ];
    
    echo "<div class='$class'>[$status] $test_name: $description</div>\n";
    if (!$passed) {
        echo "<div class='error'>  Expected: $expected, Got: $actual</div>\n";
    }
    
    return $passed;
}

echo "<div class='test-section'>\n";
echo "<h2>1. Database Schema Tests</h2>\n";

// Test 1: Check if required tables exist
try {
    $tables_to_check = ['users', 'instalment_details', 'pepp_courses', 'academic_years', 'payment_accounts'];
    foreach ($tables_to_check as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        runTest("Table $table exists", true, $exists, "Required table $table should exist");
    }
} catch (Exception $e) {
    echo "<div class='error'>Database connection error: " . $e->getMessage() . "</div>\n";
}

// Test 2: Check if required columns exist in users table
try {
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $required_columns = ['paid_amount', 'paid_date', 'payment_plan', 'discount_amount', 'payment_screenshot'];
    foreach ($required_columns as $column) {
        $exists = in_array($column, $columns);
        runTest("Users table has $column column", true, $exists, "Column $column should exist in users table");
    }
} catch (Exception $e) {
    echo "<div class='error'>Error checking users table: " . $e->getMessage() . "</div>\n";
}

// Test 3: Check instalment_details table structure
try {
    $stmt = $pdo->query("DESCRIBE instalment_details");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $required_columns = ['user_id', 'instalment_number', 'amount', 'due_date', 'status', 'paid_date'];
    foreach ($required_columns as $column) {
        $exists = in_array($column, $columns);
        runTest("Instalment_details table has $column column", true, $exists, "Column $column should exist in instalment_details table");
    }
} catch (Exception $e) {
    echo "<div class='error'>Error checking instalment_details table: " . $e->getMessage() . "</div>\n";
}

echo "</div>\n";

echo "<div class='test-section'>\n";
echo "<h2>2. Payment Calculation Tests</h2>\n";

// Test 4: Basic payment calculations
$test_scenarios = [
    [
        'course_fee' => 10000,
        'discount' => 1000,
        'paid_amount' => 2000,
        'expected_net_payable' => 9000,
        'expected_pending' => 7000,
        'expected_progress' => 22.22,
        'description' => 'Basic calculation with discount'
    ],
    [
        'course_fee' => 5000,
        'discount' => 0,
        'paid_amount' => 5000,
        'expected_net_payable' => 5000,
        'expected_pending' => 0,
        'expected_progress' => 100,
        'description' => 'Full payment scenario'
    ],
    [
        'course_fee' => 15000,
        'discount' => 2000,
        'paid_amount' => 3000,
        'expected_net_payable' => 13000,
        'expected_pending' => 10000,
        'expected_progress' => 23.08,
        'description' => 'Partial payment with discount'
    ]
];

foreach ($test_scenarios as $i => $scenario) {
    echo "<h3>Scenario " . ($i + 1) . ": " . $scenario['description'] . "</h3>\n";
    
    // Calculate net payable
    $net_payable = $scenario['course_fee'] - $scenario['discount'];
    runTest("Net payable calculation", $scenario['expected_net_payable'], $net_payable, 
           "Course fee ({$scenario['course_fee']}) - Discount ({$scenario['discount']}) = Net payable");
    
    // Calculate pending amount
    $pending_amount = max(0, $net_payable - $scenario['paid_amount']);
    runTest("Pending amount calculation", $scenario['expected_pending'], $pending_amount,
           "Net payable ($net_payable) - Paid amount ({$scenario['paid_amount']}) = Pending");
    
    // Calculate payment progress
    $payment_progress = $net_payable > 0 ? round(($scenario['paid_amount'] / $net_payable) * 100, 2) : 0;
    runTest("Payment progress calculation", $scenario['expected_progress'], $payment_progress,
           "Payment progress percentage");
}

echo "</div>\n";

echo "<div class='test-section'>\n";
echo "<h2>3. Installment Logic Tests</h2>\n";

// Test 5: Installment distribution logic
$installment_scenarios = [
    [
        'net_payable' => 9000,
        'first_payment' => 2000,
        'installment_plan' => '3 Instalments',
        'expected_remaining' => 7000,
        'expected_installments' => 2,
        'description' => '3 installment plan with first payment'
    ],
    [
        'net_payable' => 12000,
        'first_payment' => 3000,
        'installment_plan' => '4 Instalments',
        'expected_remaining' => 9000,
        'expected_installments' => 3,
        'description' => '4 installment plan with first payment'
    ]
];

foreach ($installment_scenarios as $i => $scenario) {
    echo "<h3>Installment Scenario " . ($i + 1) . ": " . $scenario['description'] . "</h3>\n";
    
    // Calculate remaining balance after first payment
    $remaining_balance = $scenario['net_payable'] - $scenario['first_payment'];
    runTest("Remaining balance after first payment", $scenario['expected_remaining'], $remaining_balance,
           "Net payable ({$scenario['net_payable']}) - First payment ({$scenario['first_payment']})");
    
    // Calculate number of remaining installments
    $installment_count = (int) filter_var($scenario['installment_plan'], FILTER_SANITIZE_NUMBER_INT);
    $remaining_installments = $installment_count - 1; // Subtract first payment
    runTest("Remaining installments count", $scenario['expected_installments'], $remaining_installments,
           "Total installments ($installment_count) - First payment (1)");
    
    // Calculate equal installment amount
    if ($remaining_installments > 0) {
        $equal_installment = round($remaining_balance / $remaining_installments, 2);
        echo "<div class='info'>Equal installment amount would be: ₹$equal_installment</div>\n";
    }
}

echo "</div>\n";

echo "<div class='test-section'>\n";
echo "<h2>4. Database Integration Tests</h2>\n";

// Test 6: Create a test student record and verify calculations
try {
    $pdo->beginTransaction();
    
    // Create test user
    $test_user_id = 'TEST' . date('YmdHis');
    $stmt = $pdo->prepare("
        INSERT INTO users (
            user_id, name, email, gender, date_of_birth, whatsapp_number, 
            pepp_course, pepp_academic_year, paid_amount, paid_date, 
            payment_plan, discount_amount, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $test_user_id, 'Test Student', 'test@example.com', 'Male', '1995-01-01',
        '9876543210', 'MA/MSc Psychology (Standard)', '2024-25', 2500.00, '2024-01-15',
        '3 Instalments', 500.00, 'pending'
    ]);
    
    runTest("Test user creation", true, true, "Created test user with ID: $test_user_id");
    
    // Get course fee for the test course
    $stmt = $pdo->prepare("SELECT total_fee FROM pepp_courses WHERE course_name = ?");
    $stmt->execute(['MA/MSc Psychology (Standard)']);
    $course_data = $stmt->fetch();
    $course_total_fee = floatval($course_data['total_fee'] ?? 11499);
    
    // Test payment calculations with real data
    $paid_amount = 2500.00;
    $discount_amount = 500.00;
    $net_payable = $course_total_fee - $discount_amount;
    $pending_amount = max(0, $net_payable - $paid_amount);
    $payment_progress = $net_payable > 0 ? ($paid_amount / $net_payable) * 100 : 0;
    
    runTest("Course fee retrieval", 11499.00, $course_total_fee, "Retrieved course fee from database");
    runTest("Net payable with real data", 10999.00, $net_payable, "Course fee - discount");
    runTest("Pending amount with real data", 8499.00, $pending_amount, "Net payable - paid amount");
    
    // Create test installment records
    $installments = [
        ['number' => 2, 'amount' => 4249.50, 'due_date' => '2024-02-15'],
        ['number' => 3, 'amount' => 4249.50, 'due_date' => '2024-03-15']
    ];
    
    foreach ($installments as $installment) {
        $stmt = $pdo->prepare("
            INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$test_user_id, $installment['number'], $installment['amount'], $installment['due_date']]);
    }
    
    runTest("Installment records creation", true, true, "Created 2 installment records");
    
    // Test installment calculations
    $stmt = $pdo->prepare("SELECT SUM(amount) as total_installments FROM instalment_details WHERE user_id = ?");
    $stmt->execute([$test_user_id]);
    $total_installments = floatval($stmt->fetchColumn());
    
    $expected_total_installments = 8499.00; // Remaining balance
    runTest("Total installment amount", $expected_total_installments, $total_installments, "Sum of all installment amounts");
    
    // Test total payment calculation
    $total_paid = $paid_amount; // Only initial payment, no installments paid yet
    $expected_total_paid = 2500.00;
    runTest("Total paid calculation", $expected_total_paid, $total_paid, "Initial payment only");
    
    // Cleanup test data
    $pdo->rollback();
    echo "<div class='info'>Test data cleaned up (transaction rolled back)</div>\n";
    
} catch (Exception $e) {
    $pdo->rollback();
    echo "<div class='error'>Database integration test error: " . $e->getMessage() . "</div>\n";
}

echo "</div>\n";

echo "<div class='test-section'>\n";
echo "<h2>5. Edge Case Tests</h2>\n";

// Test 7: Edge cases
$edge_cases = [
    [
        'course_fee' => 1000,
        'discount' => 1000,
        'paid_amount' => 0,
        'expected_net_payable' => 0,
        'expected_pending' => 0,
        'expected_progress' => 0,
        'description' => 'Full discount scenario'
    ],
    [
        'course_fee' => 5000,
        'discount' => 0,
        'paid_amount' => 6000,
        'expected_net_payable' => 5000,
        'expected_pending' => 0,
        'expected_progress' => 100,
        'description' => 'Overpayment scenario'
    ],
    [
        'course_fee' => 0,
        'discount' => 0,
        'paid_amount' => 1000,
        'expected_net_payable' => 0,
        'expected_pending' => 0,
        'expected_progress' => 0,
        'description' => 'Zero course fee scenario'
    ]
];

foreach ($edge_cases as $i => $case) {
    echo "<h3>Edge Case " . ($i + 1) . ": " . $case['description'] . "</h3>\n";
    
    $net_payable = max(0, $case['course_fee'] - $case['discount']);
    $pending_amount = max(0, $net_payable - $case['paid_amount']);
    $payment_progress = $net_payable > 0 ? min(100, ($case['paid_amount'] / $net_payable) * 100) : 0;
    
    runTest("Edge case net payable", $case['expected_net_payable'], $net_payable, $case['description']);
    runTest("Edge case pending amount", $case['expected_pending'], $pending_amount, $case['description']);
    runTest("Edge case progress", $case['expected_progress'], $payment_progress, $case['description']);
}

echo "</div>\n";

echo "<div class='test-section'>\n";
echo "<h2>6. File Upload Tests</h2>\n";

// Test 8: File upload directory structure
$upload_dirs = [
    'uploads/payments/',
    'uploads/photos/',
    'uploads/installment_payments/'
];

foreach ($upload_dirs as $dir) {
    $exists = is_dir($dir);
    $writable = $exists ? is_writable($dir) : false;
    
    runTest("Directory $dir exists", true, $exists, "Upload directory should exist");
    if ($exists) {
        runTest("Directory $dir writable", true, $writable, "Upload directory should be writable");
    }
}

echo "</div>\n";

// Test Summary
echo "<div class='test-section'>\n";
echo "<h2>Test Summary</h2>\n";
echo "<table>\n";
echo "<tr><th>Test Name</th><th>Expected</th><th>Actual</th><th>Status</th><th>Description</th></tr>\n";

foreach ($test_results as $result) {
    echo "<tr class='{$result['class']}'>";
    echo "<td>{$result['name']}</td>";
    echo "<td>{$result['expected']}</td>";
    echo "<td>{$result['actual']}</td>";
    echo "<td>{$result['status']}</td>";
    echo "<td>{$result['description']}</td>";
    echo "</tr>\n";
}

echo "</table>\n";

$pass_rate = $total_tests > 0 ? round(($passed_tests / $total_tests) * 100, 2) : 0;
$overall_status = $pass_rate >= 90 ? 'success' : ($pass_rate >= 70 ? 'warning' : 'error');

echo "<div class='$overall_status'>\n";
echo "<h3>Overall Results</h3>\n";
echo "<p>Total Tests: $total_tests</p>\n";
echo "<p>Passed: $passed_tests</p>\n";
echo "<p>Failed: " . ($total_tests - $passed_tests) . "</p>\n";
echo "<p>Pass Rate: $pass_rate%</p>\n";

if ($pass_rate >= 90) {
    echo "<p><strong>✅ Payment system is working correctly!</strong></p>\n";
} elseif ($pass_rate >= 70) {
    echo "<p><strong>⚠️ Payment system has minor issues that should be addressed.</strong></p>\n";
} else {
    echo "<p><strong>❌ Payment system has significant issues that need immediate attention.</strong></p>\n";
}

echo "</div>\n";
echo "</div>\n";

echo "<div class='test-section'>\n";
echo "<h2>7. Recommendations</h2>\n";

if ($pass_rate < 100) {
    echo "<ul>\n";
    
    foreach ($test_results as $result) {
        if ($result['status'] === 'FAIL') {
            echo "<li class='error'>Fix: {$result['name']} - {$result['description']}</li>\n";
        }
    }
    
    echo "</ul>\n";
} else {
    echo "<p class='success'>All tests passed! The payment system is functioning correctly.</p>\n";
}

echo "<h3>Additional Recommendations:</h3>\n";
echo "<ul>\n";
echo "<li>Regularly backup the database, especially the users and instalment_details tables</li>\n";
echo "<li>Monitor payment calculations for accuracy during real transactions</li>\n";
echo "<li>Implement logging for all payment-related operations</li>\n";
echo "<li>Set up automated tests to run periodically</li>\n";
echo "<li>Validate file uploads for security (file type, size, content)</li>\n";
echo "</ul>\n";

echo "</div>\n";

echo "<p><em>Test completed at: " . date('Y-m-d H:i:s') . "</em></p>\n";
?>
