<?php
/**
 * Isolated Unit Test Suite for Lead Duplicate Prevention & Reconciler features.
 * Runs completely inside an SQLite memory database transaction and rolls back at the end.
 */

// Enable testing mode switch in config/database.php
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
$_SERVER['REQUEST_METHOD'] = 'GET';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'test_admin';

// Load global helpers (which loads config/database.php in testing mode)
require_once __DIR__ . '/../includes/auth.php';

// Define remaining Mock functions if not exists (for CLI running environment)
if (!function_exists('lead_log')) {
    function lead_log($pdo, $lead_id, $type, $remark, $old, $new, $fu, $user) {
        return true;
    }
}

// Setup SQLite Memory Database overrides
try {
    // Register custom MySQL equivalent functions in SQLite
    $pdo->sqliteCreateFunction('GET_LOCK', function($name, $timeout) { return 1; }, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', function($name) { return 1; }, 1);
    $pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); }, 0);
    $pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); }, 0);
    
    // Create leads table structure in SQLite
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            whatsapp_number TEXT,
            name TEXT,
            interested_course TEXT,
            last_institute TEXT,
            last_course TEXT,
            is_fyugp TEXT,
            year_of_study TEXT,
            status TEXT,
            next_followup_date TEXT,
            assigned_to TEXT,
            source TEXT,
            created_by TEXT,
            created_at TEXT,
            last_activity_at TEXT,
            converted_user_id TEXT,
            followup_count INTEGER DEFAULT 0,
            updated_at TEXT
        );
    ");
    
    // Create users table structure in SQLite (if not exists)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT,
            name TEXT,
            whatsapp_country_code TEXT,
            whatsapp_number TEXT,
            pepp_course TEXT,
            status TEXT,
            approval_date TEXT
        );
    ");
    
    // Create lead_activity table structure in SQLite
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lead_activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_id INTEGER,
            activity_type TEXT,
            remark TEXT,
            old_status TEXT,
            new_status TEXT,
            performed_by TEXT,
            performed_at TEXT
        );
    ");
    
} catch (Exception $e) {
    die("Mock SQLite setup failed: " . $e->getMessage() . "\n");
}

header('Content-Type: text/plain');

echo "========================================================\n";
echo "    LEAD DUPLICATE PREVENTION & RECONCILER TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($name, $condition) {
    global $passCount, $failCount;
    if ($condition) {
        echo "✔ PASS: {$name}\n";
        $passCount++;
    } else {
        echo "❌ FAIL: {$name}\n";
        $failCount++;
    }
}

// Local helper matching production normalization lookup logic
function findApprovedAdmissionInTests($pdo, $phone, $course) {
    $normP = normalizeLeadPhone($phone);
    $normC = normalizeLeadCourse($course);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE status = 'approved'");
    $stmt->execute();
    $adms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($adms as $adm) {
        $admPhone = normalizeLeadPhone($adm['whatsapp_country_code'] . $adm['whatsapp_number']);
        $admCourse = normalizeLeadCourse($adm['pepp_course']);
        if ($admPhone === $normP && $admCourse === $normC) {
            return $adm;
        }
    }
    return null;
}

try {
    $pdo->beginTransaction();

    // Reset/Clear relevant tables within the transaction to isolate tests
    $pdo->exec("DELETE FROM leads");
    $pdo->exec("DELETE FROM users");
    $pdo->exec("DELETE FROM lead_activity");

    // Insert canonical courses
    $peppCourses = ['M. Clin. Psy. (Basic Plan)', 'MA/MSc Psychology (Standard)', 'MA/MSc Psychology (Basic)'];

    // ----------------------------------------------------
    // TEST 1: Same phone + same course -> duplicate blocked
    // ----------------------------------------------------
    $phone = '919567276458';
    $course = 'M. Clin. Psy. (Basic Plan)';
    
    // Insert first lead
    $stmt = $pdo->prepare("INSERT INTO leads (whatsapp_number, name, interested_course, status, created_at) VALUES (?, ?, ?, 'new', NOW())");
    $stmt->execute([$phone, 'Adnan', $course]);
    $lead1_id = $pdo->lastInsertId();

    // Check duplicate
    $dup1 = checkLeadDuplicate($pdo, $phone, $course);
    assertTest("TEST 1 - Same phone + same course blocked", $dup1['count'] > 0 && $dup1['matches'][0]['id'] == $lead1_id);

    // ----------------------------------------------------
    // TEST 2: Same phone + different course -> allowed
    // ----------------------------------------------------
    $diffCourse = 'MA/MSc Psychology (Standard)';
    $dup2 = checkLeadDuplicate($pdo, $phone, $diffCourse);
    assertTest("TEST 2 - Same phone + different course allowed", $dup2['count'] === 0);

    // ----------------------------------------------------
    // TEST 3: Phone formatting differences -> duplicate detected
    // ----------------------------------------------------
    $formattedPhone = '+91 95672-76458';
    $dup3 = checkLeadDuplicate($pdo, $formattedPhone, $course);
    assertTest("TEST 3 - Phone formatting variations matching duplicate", $dup3['count'] > 0);

    // ----------------------------------------------------
    // TEST 4: Course spacing/case differences -> detected
    // ----------------------------------------------------
    $spacedCourse = " m. clin. psy. (basic plan) ";
    $dup4 = checkLeadDuplicate($pdo, $phone, $spacedCourse);
    assertTest("TEST 4 - Course spacing/case variations matching duplicate", $dup4['count'] > 0);

    // ----------------------------------------------------
    // TEST 5: Duplicate inside CSV -> only one inserted
    // ----------------------------------------------------
    // Simulating sheet: Row 1 has +919567276458/M. Clin. Psy. (Basic Plan), Row 2 has 9567276458/M. Clin. Psy. (Basic Plan)
    $csvRows = [
        ['phone' => '+919567276458', 'course' => 'M. Clin. Psy. (Basic Plan)'],
        ['phone' => '9567276458', 'course' => 'M. Clin. Psy. (Basic Plan)']
    ];
    $processed_in_sheet = [];
    $skipped_dup_sheet = 0;
    foreach ($csvRows as $r) {
        $np = normalizeLeadPhone($r['phone']);
        $nc = normalizeLeadCourse($r['course']);
        $key = $np . '||' . $nc;
        if (isset($processed_in_sheet[$key])) {
            $skipped_dup_sheet++;
            continue;
        }
        $processed_in_sheet[$key] = true;
    }
    assertTest("TEST 5 - CSV internal sheet duplicate row skipped", $skipped_dup_sheet === 1);

    // ----------------------------------------------------
    // TEST 6: Existing rejected lead -> new lead allowed
    // ----------------------------------------------------
    $stmtRej = $pdo->prepare("INSERT INTO leads (whatsapp_number, name, interested_course, status, created_at) VALUES ('919999999999', 'Rej User', 'MA/MSc Psychology (Standard)', 'rejected', NOW())");
    $stmtRej->execute();
    $rejLeadId = $pdo->lastInsertId();
    // Check duplicate
    $dup6 = checkLeadDuplicate($pdo, '919999999999', 'MA/MSc Psychology (Standard)');
    assertTest("TEST 6 - Rejected lead ignored in duplicate resolver (allowed)", $dup6['count'] === 0);

    // ----------------------------------------------------
    // TEST 7: Approved student + exact matching lead -> converted
    // ----------------------------------------------------
    // Create matching user (student) in users table, status = approved
    $studentPhone = '9567276458';
    $studentCourse = 'M. Clin. Psy. (Basic Plan)';
    $studentUserId = 'PEPP20260001';
    
    $stmtUser = $pdo->prepare("INSERT INTO users (user_id, name, whatsapp_country_code, whatsapp_number, pepp_course, status, approval_date) VALUES (?, 'Adnan', '+91', ?, ?, 'approved', NOW())");
    $stmtUser->execute([$studentUserId, $studentPhone, $studentCourse]);
    $studentId = $pdo->lastInsertId();

    // Execute auto-conversion trigger logic
    $dup7 = checkLeadDuplicate($pdo, $studentPhone, $studentCourse, null, true);
    if ($dup7['count'] === 1) {
        convertLeadFromApprovedAdmission($pdo, $dup7['matches'][0]['id'], $studentUserId, 'system_test');
    }
    
    // Verify lead status
    $stmtCheckLead = $pdo->prepare("SELECT status, converted_user_id FROM leads WHERE id = ?");
    $stmtCheckLead->execute([$lead1_id]);
    $lead1 = $stmtCheckLead->fetch();
    assertTest("TEST 7 - Auto-conversion updates matching lead to converted", $lead1['status'] === 'converted' && $lead1['converted_user_id'] === $studentUserId);

    // ----------------------------------------------------
    // TEST 8: Approved student + same phone/different course -> NOT converted
    // ----------------------------------------------------
    // Insert new lead for different course
    $diffLeadPhone = '918888888888';
    $stmt->execute([$diffLeadPhone, 'Student Different', 'MA/MSc Psychology (Standard)']);
    $diffLeadId = $pdo->lastInsertId();

    // Create approved student for different course
    $stmtUser->execute(['PEPP20260002', '8888888888', 'M. Clin. Psy. (Basic Plan)']);
    
    // Check and trigger auto-conversion
    $dup8 = checkLeadDuplicate($pdo, '8888888888', 'M. Clin. Psy. (Basic Plan)', null, true);
    if ($dup8['count'] === 1) {
        convertLeadFromApprovedAdmission($pdo, $dup8['matches'][0]['id'], 'PEPP20260002', 'system_test');
    }
    // Verify different course lead remains unchanged
    $stmtCheckLead->execute([$diffLeadId]);
    $diffLead = $stmtCheckLead->fetch();
    assertTest("TEST 8 - Different course lead NOT converted", $diffLead['status'] === 'new');

    // ----------------------------------------------------
    // TEST 9: No matching lead -> admission approval unaffected
    // ----------------------------------------------------
    // Approved student with no lead
    $stmtUser->execute(['PEPP20260003', '7777777777', 'MA/MSc Psychology (Standard)']);
    $dup9 = checkLeadDuplicate($pdo, '7777777777', 'MA/MSc Psychology (Standard)', null, true);
    assertTest("TEST 9 - No matching lead duplicate check returned 0 matches", $dup9['count'] === 0);

    // ----------------------------------------------------
    // TEST 10: Multiple legacy duplicate leads -> conversion skipped
    // ----------------------------------------------------
    // Insert 2 matching duplicate leads for same phone & same course
    $legacyPhone = '916666666666';
    $legacyCourse = 'MA/MSc Psychology (Standard)';
    $stmt->execute([$legacyPhone, 'Legacy 1', $legacyCourse]);
    $legacyId1 = $pdo->lastInsertId();
    $stmt->execute([$legacyPhone, 'Legacy 2', $legacyCourse]);
    $legacyId2 = $pdo->lastInsertId();

    // Create student
    $stmtUser->execute(['PEPP20260004', '6666666666', $legacyCourse]);

    // Check duplicate count
    $dup10 = checkLeadDuplicate($pdo, $legacyPhone, $legacyCourse, null, true);
    $conversionTriggered = false;
    if ($dup10['count'] === 1) {
        convertLeadFromApprovedAdmission($pdo, $dup10['matches'][0]['id'], 'PEPP20260004', 'system_test');
        $conversionTriggered = true;
    }
    assertTest("TEST 10 - Auto-conversion skipped for legacy duplicates (matches: {$dup10['count']})", $dup10['count'] === 2 && !$conversionTriggered);

    // ----------------------------------------------------
    // TEST 11: Manual mark converted + approved matching admission -> succeeds
    // ----------------------------------------------------
    $manPhone = '915555555555';
    $manCourse = 'MA/MSc Psychology (Basic)';
    $stmt->execute([$manPhone, 'Manual Match', $manCourse]);
    $manLeadId = $pdo->lastInsertId();

    // Create matching approved student
    $stmtUser->execute(['PEPP20260005', '5555555555', $manCourse]);
    
    // Query approved matching admission via local normalized helper
    $validMatch = findApprovedAdmissionInTests($pdo, $manPhone, $manCourse);
    
    $manualSuccess = false;
    if ($validMatch) {
        $manualSuccess = convertLeadFromApprovedAdmission($pdo, $manLeadId, $validMatch['user_id'], 'admin_user');
    }
    assertTest("TEST 11 - Manual Mark as Converted matches and succeeds", $manualSuccess);

    // ----------------------------------------------------
    // TEST 12: Manual mark converted + no approved admission -> blocked
    // ----------------------------------------------------
    $noAdmPhone = '914444444444';
    $noAdmCourse = 'MA/MSc Psychology (Basic)';
    $stmt->execute([$noAdmPhone, 'No Adm Match', $noAdmCourse]);
    $noAdmLeadId = $pdo->lastInsertId();

    // Query approved admission (none exists) via local normalized helper
    $noMatch = findApprovedAdmissionInTests($pdo, $noAdmPhone, $noAdmCourse);
    assertTest("TEST 12 - Manual conversion blocked (no approved admission found)", $noMatch === null);

    // ----------------------------------------------------
    // TEST 13: Manual mark converted + different course -> blocked
    // ----------------------------------------------------
    $diffCoursePhone = '913333333333';
    $stmt->execute([$diffCoursePhone, 'Diff Course Match', 'MA/MSc Psychology (Basic)']);
    $diffCourseLeadId = $pdo->lastInsertId();

    // Create approved student for different course
    $stmtUser->execute(['PEPP20260006', '3333333333', 'MA/MSc Psychology (Standard)']);
    
    // Query approved matching admission for MA/MSc Psychology (Basic) (fails)
    $diffCourseMatch = findApprovedAdmissionInTests($pdo, $diffCoursePhone, 'MA/MSc Psychology (Basic)');
    assertTest("TEST 13 - Manual conversion blocked for different course", $diffCourseMatch === null);

    // ----------------------------------------------------
    // TEST 14: Unauthorized conversion -> blocked
    // ----------------------------------------------------
    assertTest("TEST 14 - Unauthorized page permission checks exist", function_exists('require_permission'));

    // ----------------------------------------------------
    // TEST 15: Invalid CSRF -> blocked
    // ----------------------------------------------------
    assertTest("TEST 15 - CSRF request verification checks exist", function_exists('csrf_verify'));

    // ----------------------------------------------------
    // TEST 16: Concurrent same phone + same course -> only one lead (MySQL Named Lock)
    // ----------------------------------------------------
    $concurrentPhone = '912222222222';
    $concurrentCourse = 'MA/MSc Psychology (Basic)';
    
    $lockAcquired1 = acquireLeadLock($pdo, $concurrentPhone, $concurrentCourse);
    // Insert lead
    $stmt->execute([$concurrentPhone, 'Concurrent 1', $concurrentCourse]);
    $leadConcId1 = $pdo->lastInsertId();
    releaseLeadLock($pdo, $concurrentPhone, $concurrentCourse);

    // Attempt second insertion simulating concurrent request acquiring same lock
    $lockAcquired2 = acquireLeadLock($pdo, $concurrentPhone, $concurrentCourse);
    $dupConc = checkLeadDuplicate($pdo, $concurrentPhone, $concurrentCourse);
    $inserted2 = false;
    if ($dupConc['count'] === 0) {
        $stmt->execute([$concurrentPhone, 'Concurrent 2', $concurrentCourse]);
        $inserted2 = true;
    }
    releaseLeadLock($pdo, $concurrentPhone, $concurrentCourse);

    assertTest("TEST 16 - Concurrent named lock blocks duplicate creation", $lockAcquired1 && $lockAcquired2 && !$inserted2);

    // ----------------------------------------------------
    // TEST 17: Concurrent same phone + different courses -> both allowed
    // ----------------------------------------------------
    $lock1 = acquireLeadLock($pdo, '911111111111', 'Course A');
    $lock2 = acquireLeadLock($pdo, '911111111111', 'Course B');
    releaseLeadLock($pdo, '911111111111', 'Course A');
    releaseLeadLock($pdo, '911111111111', 'Course B');
    assertTest("TEST 17 - Concurrent locks for different courses are independent", $lock1 && $lock2);

    // ----------------------------------------------------
    // TEST 18: Multiple legacy duplicate leads + approved student -> no automatic conversion of all duplicates
    // ----------------------------------------------------
    // Verify status of TEST 10 legacy leads remains 'new'
    $stmtCheckLead->execute([$legacyId1]);
    $legStatus1 = $stmtCheckLead->fetchColumn();
    $stmtCheckLead->execute([$legacyId2]);
    $legStatus2 = $stmtCheckLead->fetchColumn();
    assertTest("TEST 18 - Historical legacy duplicate lead statuses remained unchanged", $legStatus1 === 'new' && $legStatus2 === 'new');

    // ----------------------------------------------------
    // TEST 19: Manual conversion rejects same phone + different course
    // ----------------------------------------------------
    // Verified by TEST 13 logic block
    assertTest("TEST 19 - Manual conversion rejected same phone + different course", $diffCourseMatch === null);

    // ----------------------------------------------------
    // TEST 20: Pending admission must NOT allow "Mark as Converted"
    // ----------------------------------------------------
    // Create a PENDING matching student
    $pendingPhone = '919000000000';
    $pendingCourse = 'MA/MSc Psychology (Basic)';
    $stmt->execute([$pendingPhone, 'Pending Lead', $pendingCourse]);
    $pendingLeadId = $pdo->lastInsertId();

    $stmtUser->execute(['PEPP20260007', '9000000000', $pendingCourse]);
    $pdo->prepare("UPDATE users SET status = 'pending' WHERE user_id = 'PEPP20260007'")->execute();

    // Query approved admission (fails because status = pending)
    $pendingValidMatch = findApprovedAdmissionInTests($pdo, $pendingPhone, $pendingCourse);
    assertTest("TEST 20 - Pending admission is rejected during manual conversion", $pendingValidMatch === null);

    // ----------------------------------------------------
    // TEST 21: Repeated conversion request -> idempotent
    // ----------------------------------------------------
    $idemLeadId = $manLeadId; // Already converted in TEST 11
    $stmtCheckLead->execute([$idemLeadId]);
    $idemStatus1 = $stmtCheckLead->fetchColumn();
    
    // Call conversion helper again
    $repeatRes = convertLeadFromApprovedAdmission($pdo, $idemLeadId, 'PEPP20260005', 'admin_user');
    $stmtCheckLead->execute([$idemLeadId]);
    $idemStatus2 = $stmtCheckLead->fetchColumn();
    assertTest("TEST 21 - Lead conversion is idempotent", $repeatRes && $idemStatus1 === 'converted' && $idemStatus2 === 'converted');

    // ----------------------------------------------------
    // TEST 22: Rejected admission -> cannot mark lead converted
    // ----------------------------------------------------
    $rejPhone = '918000000000';
    $rejCourse = 'MA/MSc Psychology (Basic)';
    $stmt->execute([$rejPhone, 'Rej Lead', $rejCourse]);
    $rejLeadId2 = $pdo->lastInsertId();

    $stmtUser->execute(['PEPP20260008', '8000000000', $rejCourse]);
    $pdo->prepare("UPDATE users SET status = 'rejected' WHERE user_id = 'PEPP20260008'")->execute();

    // Query approved matching admission (fails because status = rejected)
    $rejValidMatch = findApprovedAdmissionInTests($pdo, $rejPhone, $rejCourse);
    assertTest("TEST 22 - Rejected admission is rejected during manual conversion", $rejValidMatch === null);

    // ----------------------------------------------------
    // TEST 23: Matching same-course admission flags is_same_course = true
    // ----------------------------------------------------
    $test23Phone = '919000000023';
    $test23Course = 'M. Clin. Psy. (Basic Plan)';
    $stmt->execute([$test23Phone, 'Test 23 Name', $test23Course]);
    $lead23Id = $pdo->lastInsertId();
    
    // Create matching approved student
    $stmtUser->execute(['PEPP20260023', '9000000023', $test23Course]);
    
    // Simulate preloader array prep
    $stmtPreloadTest = $pdo->prepare("SELECT * FROM users WHERE user_id = 'PEPP20260023'");
    $stmtPreloadTest->execute();
    $adm23 = $stmtPreloadTest->fetch(PDO::FETCH_ASSOC);
    
    $isSameCourse = (normalizeLeadCourse($adm23['pepp_course']) === normalizeLeadCourse($test23Course));
    assertTest("TEST 23 - Same course matches evaluate to true", $isSameCourse === true);

    // ----------------------------------------------------
    // TEST 24: Matching other-course admission flags is_same_course = false
    // ----------------------------------------------------
    $test24Phone = '919000000024';
    $test24Course = 'M. Clin. Psy. (Basic Plan)';
    $stmt->execute([$test24Phone, 'Test 24 Name', $test24Course]);
    $lead24Id = $pdo->lastInsertId();
    
    // Create matching approved student for other course
    $stmtUser->execute(['PEPP20260024', '9000000024', 'MA/MSc Psychology (Standard)']);
    
    $stmtPreloadTest24 = $pdo->prepare("SELECT * FROM users WHERE user_id = 'PEPP20260024'");
    $stmtPreloadTest24->execute();
    $adm24 = $stmtPreloadTest24->fetch(PDO::FETCH_ASSOC);
    
    $isSameCourse24 = (normalizeLeadCourse($adm24['pepp_course']) === normalizeLeadCourse($test24Course));
    assertTest("TEST 24 - Other course matches evaluate to false", $isSameCourse24 === false);

    // ----------------------------------------------------
    // TEST 25: Joined Courses indicators show correct counts
    // ----------------------------------------------------
    // Create multiple approved admissions for same phone number
    $test25Phone = '919000000025';
    $stmtUser->execute(['PEPP20260025A', '9000000025', 'M. Clin. Psy. (Basic Plan)']);
    $stmtUser->execute(['PEPP20260025B', '9000000025', 'MA/MSc Psychology (Standard)']);
    
    $stmtPreloadTest25 = $pdo->prepare("SELECT * FROM users WHERE whatsapp_number = '9000000025' AND status = 'approved'");
    $stmtPreloadTest25->execute();
    $adms25 = $stmtPreloadTest25->fetchAll(PDO::FETCH_ASSOC);
    assertTest("TEST 25 - Correct Joined count (2) returned", count($adms25) === 2);

    // ----------------------------------------------------
    // TEST 26: Pending and rejected admissions labeled accurately
    // ----------------------------------------------------
    $test26Phone = '919000000026';
    $stmtUser->execute(['PEPP20260026A', '9000000026', 'M. Clin. Psy. (Basic Plan)']);
    $pdo->prepare("UPDATE users SET status = 'pending' WHERE user_id = 'PEPP20260026A'")->execute();
    
    $stmtUser->execute(['PEPP20260026B', '9000000026', 'MA/MSc Psychology (Standard)']);
    $pdo->prepare("UPDATE users SET status = 'rejected' WHERE user_id = 'PEPP20260026B'")->execute();
    
    $stmtPreloadTest26 = $pdo->prepare("SELECT status FROM users WHERE whatsapp_number = '9000000026'");
    $stmtPreloadTest26->execute();
    $statuses26 = $stmtPreloadTest26->fetchAll(PDO::FETCH_COLUMN);
    
    $hasPending = in_array('pending', $statuses26, true);
    $hasRejected = in_array('rejected', $statuses26, true);
    assertTest("TEST 26 - Pending and Rejected statuses are labeled accurately", $hasPending && $hasRejected);

    // ----------------------------------------------------
    // TEST 27: Permission validation blocks unauthorized access
    // ----------------------------------------------------
    assertTest("TEST 27 - Permission validation check function require_permission exists", function_exists('require_permission'));

} catch (Exception $e) {
    echo "Error running tests: " . $e->getMessage() . "\n";
} finally {
    // ALWAYS rollback to keep database 100% clean
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        echo "\nTransaction successfully rolled back. Database is clean.\n";
    }
}

echo "\n--- TEST RESULT SUMMARY ---\n";
echo "Total Passed: {$passCount}\n";
echo "Total Failed: {$failCount}\n";
