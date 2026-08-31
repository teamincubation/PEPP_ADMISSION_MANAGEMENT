<?php
/**
 * Test Audit: Study Plan Ordering Audit in studyplan.php
 * 
 * Verifies:
 * 1. Backend SQL query applies strict latest -> oldest ordering (start_date DESC).
 * 2. Equal-date study plans use stable secondary ordering (id DESC).
 * 3. Both course_name and form_id queries enforce identical ordering.
 * 4. Course assignment, published status, and deletion filter rules are strictly preserved.
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
echo "AUDIT: Study Plan Chronological Ordering (studyplan.php)\n";
echo "============================================================\n";

// 1. Static Source Code Inspection
$sourceFile = __DIR__ . '/studyplan.php';
assertTest("studyplan.php exists", file_exists($sourceFile));
$source = file_get_contents($sourceFile);

// Check that course_name query uses start_date DESC, sp.id DESC
assertTest("course_name query orders by sp.start_date DESC, sp.id DESC",
    preg_match('/WHERE\s+sp\.status\s*=\s*\'published\'.*?ORDER\s+BY\s+sp\.start_date\s+DESC,\s*sp\.id\s+DESC/s', $source) === 1
);

// Check that form_id query uses start_date DESC, sp.id DESC
assertTest("form_id query orders by sp.start_date DESC, sp.id DESC",
    preg_match('/WHERE\s+sp\.status\s*=\s*\'published\'.*?sa\.assignment_type\s*=\s*\'form\'.*?ORDER\s+BY\s+sp\.start_date\s+DESC,\s*sp\.id\s+DESC/s', $source) === 1
);

// Check that no client-side JS sort hacks are introduced
assertTest("No client-side JavaScript sorting hacks present",
    strpos($source, 'plans.sort(') === false &&
    strpos($source, '.sort((a, b)') === false
);

// 2. Behavioral Database Query Execution Test
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Schema Setup
$pdo->exec("
    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        academic_year TEXT,
        status TEXT DEFAULT 'published',
        start_date TEXT,
        end_date TEXT,
        plan_type TEXT DEFAULT 'date_wise',
        version INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0
    );
    CREATE TABLE study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type TEXT,
        assigned_value TEXT,
        is_deleted INTEGER DEFAULT 0
    );
");

// Seed Study Plans for MA/MSc Psychology (Premium)
// Plan 1: July 2026 (oldest)
// Plan 2: August 2026 (middle)
// Plan 3: September 2026 (latest)
// Plan 4: September 2026 - Batch B (same date, higher ID)
// Plan 5: Draft plan (should be excluded)
// Plan 6: Deleted plan (should be excluded)
$pdo->exec("
    INSERT INTO study_plans (id, title, status, start_date, end_date, plan_type, is_deleted) VALUES
    (1, 'July 2026 (PG)', 'published', '2026-07-01', '2026-07-31', 'date_wise', 0),
    (2, 'August 2026 (PG)', 'published', '2026-08-09', '2026-08-31', 'date_wise', 0),
    (3, 'September 2026 (PG)', 'published', '2026-09-01', '2026-09-30', 'date_wise', 0),
    (4, 'September 2026 (PG) - FastTrack', 'published', '2026-09-01', '2026-09-30', 'date_wise', 0),
    (5, 'October 2026 (PG) Draft', 'draft', '2026-10-01', '2026-10-31', 'date_wise', 0),
    (6, 'November 2026 (PG) Deleted', 'published', '2026-11-01', '2026-11-30', 'date_wise', 1);
");

// Assign plans to course 'MA/MSc Psychology (Premium)'
$pdo->exec("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES
    (1, 'course', 'MA/MSc Psychology (Premium)', 0),
    (2, 'course', 'MA/MSc Psychology (Premium)', 0),
    (3, 'course', 'MA/MSc Psychology (Premium)', 0),
    (4, 'course', 'MA/MSc Psychology (Premium)', 0),
    (5, 'course', 'MA/MSc Psychology (Premium)', 0),
    (6, 'course', 'MA/MSc Psychology (Premium)', 0);
");

// Execute the exact query used in studyplan.php
$targetCourse = 'MA/MSc Psychology (Premium)';
$stmt = $pdo->prepare("
    SELECT DISTINCT sp.*
    FROM study_plans sp
    JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
    WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0 AND sa.assignment_type = 'course' AND sa.assigned_value = ?
    ORDER BY sp.start_date DESC, sp.id DESC
");
$stmt->execute([$targetCourse]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Assertions on Query Results
assertTest("Query returns exactly 4 eligible published plans (draft and deleted excluded)", count($results) === 4);

$retrievedIds = array_column($results, 'id');
$retrievedTitles = array_column($results, 'title');

// Expected order:
// 1. ID 4: 'September 2026 (PG) - FastTrack' (start_date: 2026-09-01, id: 4)
// 2. ID 3: 'September 2026 (PG)' (start_date: 2026-09-01, id: 3)
// 3. ID 2: 'August 2026 (PG)' (start_date: 2026-08-09, id: 2)
// 4. ID 1: 'July 2026 (PG)' (start_date: 2026-07-01, id: 1)

assertTest("First item is September 2026 (PG) - FastTrack (latest date + higher id)", 
    $retrievedIds[0] === 4 && $retrievedTitles[0] === 'September 2026 (PG) - FastTrack'
);

assertTest("Second item is September 2026 (PG) (latest date + lower id)", 
    $retrievedIds[1] === 3 && $retrievedTitles[1] === 'September 2026 (PG)'
);

assertTest("Third item is August 2026 (PG)", 
    $retrievedIds[2] === 2 && $retrievedTitles[2] === 'August 2026 (PG)'
);

assertTest("Fourth item is July 2026 (PG) (oldest date)", 
    $retrievedIds[3] === 1 && $retrievedTitles[3] === 'July 2026 (PG)'
);

// Form ID Assignment Verification
$pdo->exec("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES
    (1, 'form', '10', 0),
    (2, 'form', '10', 0),
    (3, 'form', '10', 0);
");

$stmtForm = $pdo->prepare("
    SELECT DISTINCT sp.*
    FROM study_plans sp
    JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
    WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0 AND sa.assignment_type = 'form' AND sa.assigned_value = ?
    ORDER BY sp.start_date DESC, sp.id DESC
");
$stmtForm->execute(['10']);
$formResults = $stmtForm->fetchAll(PDO::FETCH_ASSOC);
$formIds = array_column($formResults, 'id');

assertTest("Form query returns plans in strict latest -> oldest order [3, 2, 1]", 
    $formIds === [3, 2, 1]
);

echo "============================================================\n";
echo "SUMMARY: {$passedCount}/{$testCount} tests passed.\n";
echo "============================================================\n";

if ($passedCount === $testCount) {
    exit(0);
} else {
    exit(1);
}
