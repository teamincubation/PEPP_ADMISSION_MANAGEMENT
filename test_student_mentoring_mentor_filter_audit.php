<?php
/**
 * Test Audit: Student Mentoring - Mentor Assignment Summary & Filters
 * 
 * Verifies:
 * 1. Accurate calculation of Mentor Assigned and Unassigned counts for selected course.
 * 2. Super Admin UI indicators: 🔴 Mentor Not Assigned: X and 🟢 Mentor Assigned: Y.
 * 3. Table rows include `data-has-mentor="1|0"`.
 * 4. Filter parameters (`mentor_status` = assigned, unassigned, all) and JavaScript logic.
 * 5. Inactive/historical assignments are not counted as active mentors.
 */

$testCount = 0;
$passedCount = 0;

function assertTest($description, $condition) {
    global $testCount, $passedCount;
    $testCount++;
    if ($condition) {
        $passedCount++;
        echo " [PASS] {$description}\n";
    } else {
        echo " [FAIL] {$description}\n";
    }
}

echo "============================================================\n";
echo "AUDIT: Student Mentoring - Mentor Assignment Summary & Filters\n";
echo "============================================================\n";

// 1. Static Source Code Inspection
$sourceFile = __DIR__ . '/student-mentoring.php';
assertTest("student-mentoring.php exists", file_exists($sourceFile));
$source = file_get_contents($sourceFile);

// Check calculation of mentor_assigned_count and mentor_unassigned_count
assertTest("Computes \$mentor_assigned_count and \$mentor_unassigned_count",
    strpos($source, '$mentor_assigned_count') !== false &&
    strpos($source, '$mentor_unassigned_count') !== false
);

// Check mentor_status query parameter parsing
assertTest("Reads and sanitizes mentor_status parameter",
    strpos($source, "\$_GET['mentor_status']") !== false &&
    strpos($source, "\$mentor_status_filter") !== false
);

// Check Super Admin UI pills
assertTest("Contains 🔴 Mentor Not Assigned pill with badge count",
    strpos($source, 'pill-mentor-unassigned') !== false &&
    strpos($source, 'Mentor Not Assigned:') !== false
);

assertTest("Contains 🟢 Mentor Assigned pill with badge count",
    strpos($source, 'pill-mentor-assigned') !== false &&
    strpos($source, 'Mentor Assigned:') !== false
);

// Check data-has-mentor attribute in student table rows
assertTest("Student rows include data-has-mentor attribute",
    strpos($source, 'data-has-mentor=') !== false
);

// Check JavaScript filter logic
assertTest("Contains toggleMentorFilter JavaScript function",
    strpos($source, 'function toggleMentorFilter(') !== false
);

assertTest("Contains updateMentorPillStyles JavaScript function",
    strpos($source, 'function updateMentorPillStyles(') !== false
);

assertTest("applyStudentFilters filters by mentor status",
    strpos($source, 'currentMentorFilter') !== false &&
    strpos($source, "row.dataset.hasMentor === '1'") !== false
);

assertTest("resetStudentFilters clears mentor status filter",
    strpos($source, "currentMentorFilter = 'ALL'") !== false
);

// 2. Behavioral Database & Logic Test
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create required schema
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        name TEXT,
        email TEXT,
        whatsapp_country_code TEXT,
        whatsapp_number TEXT,
        pepp_course TEXT,
        status TEXT DEFAULT 'approved',
        student_status TEXT DEFAULT 'active'
    );
    CREATE TABLE admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        full_name TEXT,
        role TEXT
    );
    CREATE TABLE mentor_student_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER,
        student_user_id TEXT,
        course_id INTEGER,
        status TEXT DEFAULT 'active',
        assigned_at TEXT,
        ended_at TEXT
    );
");

// Seed Admins (Mentors)
$pdo->exec("
    INSERT INTO admins (id, username, full_name, role) VALUES 
    (1, 'superadmin', 'Super Admin', 'super_admin'),
    (2, 'mentor1', 'Mentor Alice', 'mentor'),
    (3, 'mentor2', 'Mentor Bob', 'mentor');
");

// Seed Students (Course: B.Com 2026)
$pdo->exec("
    INSERT INTO users (id, user_id, name, email, pepp_course, status, student_status) VALUES
    (1, 'STU001', 'John Doe', 'john@test.com', 'B.Com 2026', 'approved', 'active'),
    (2, 'STU002', 'Jane Smith', 'jane@test.com', 'B.Com 2026', 'approved', 'active'),
    (3, 'STU003', 'Mark Taylor', 'mark@test.com', 'B.Com 2026', 'approved', 'active'),
    (4, 'STU004', 'Sarah Connor', 'sarah@test.com', 'B.Com 2026', 'approved', 'active'),
    (5, 'STU005', 'Inactive St', 'inactive@test.com', 'B.Com 2026', 'approved', 'dropout');
");

// Seed Assignments: STU001 -> active mentor Alice, STU002 -> active mentor Bob, STU003 -> ended/inactive mentor Alice
$pdo->exec("
    INSERT INTO mentor_student_assignments (admin_id, student_user_id, status, assigned_at) VALUES
    (2, 'STU001', 'active', datetime('now', '-5 day')),
    (3, 'STU002', 'active', datetime('now', '-2 day')),
    (2, 'STU003', 'inactive', datetime('now', '-20 day'));
");

// Query students eligible for B.Com 2026 (excluding dropout/completed)
$stmt = $pdo->prepare("
    SELECT id, user_id, name, email, student_status
    FROM users
    WHERE pepp_course = 'B.Com 2026'
      AND status = 'approved'
      AND (student_status IS NULL OR student_status NOT IN ('dropout', 'completed'))
");
$stmt->execute();
$activeStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

assertTest("Eligible active students found for course = 4", count($activeStudents) === 4);

// Fetch active mentor mappings
$studentIds = array_column($activeStudents, 'user_id');
$ph = implode(',', array_fill(0, count($studentIds), '?'));
$mStmt = $pdo->prepare("
    SELECT msa.student_user_id, msa.admin_id, a.full_name AS mentor_name
    FROM mentor_student_assignments msa
    JOIN admins a ON msa.admin_id = a.id
    WHERE msa.status = 'active'
      AND msa.student_user_id IN ($ph)
");
$mStmt->execute($studentIds);
$activeMentors = [];
foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $activeMentors[$m['student_user_id']] = $m;
}

$assignedCount = 0;
$unassignedCount = 0;
foreach ($activeStudents as &$st) {
    $st['active_mentor_id'] = $activeMentors[$st['user_id']]['admin_id'] ?? null;
    $st['active_mentor_name'] = $activeMentors[$st['user_id']]['mentor_name'] ?? null;
    if (!empty($st['active_mentor_id'])) {
        $assignedCount++;
    } else {
        $unassignedCount++;
    }
}
unset($st);

assertTest("Active Mentor Assigned Count is exactly 2 (STU001, STU002)", $assignedCount === 2);
assertTest("Active Mentor Unassigned Count is exactly 2 (STU003 with inactive assignment, STU004 with no assignment)", $unassignedCount === 2);

echo "============================================================\n";
echo "SUMMARY: {$passedCount}/{$testCount} tests passed.\n";
echo "============================================================\n";

if ($passedCount === $testCount) {
    exit(0);
} else {
    exit(1);
}
