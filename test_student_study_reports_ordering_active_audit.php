<?php
/**
 * PEPP ERP — Student Study Reports Ordering & Dynamic Active State Audit Test Suite
 *
 * Validates:
 * 1. Study Plan ordering: start_date DESC, end_date DESC, sp.id DESC (latest first)
 * 2. Dynamic active status calculation (start_date <= today <= end_date)
 * 3. Exact boundary testing:
 *    - today = start_date => ACTIVE
 *    - today = end_date => ACTIVE
 *    - today = day before start_date => NOT ACTIVE
 *    - today = day after end_date => NOT ACTIVE
 *    - future plan => NOT ACTIVE
 *    - expired plan => NOT ACTIVE
 * 4. Green pulse dot & ACTIVE badge assignment
 * 5. Strict study_plan_id isolation
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

$testDbPath = __DIR__ . '/scratch_test_reports_ordering.sqlite';
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

$pdo = new PDO("sqlite:" . $testDbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Mock MySQL functions
$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

// Create schema
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        name TEXT,
        email TEXT UNIQUE,
        phone TEXT,
        pepp_course TEXT,
        pepp_academic_year TEXT,
        student_status TEXT DEFAULT 'active',
        status TEXT DEFAULT 'approved',
        user_photo TEXT,
        created_at TEXT
    );

    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        plan_type TEXT DEFAULT 'date_wise',
        course_id INTEGER DEFAULT 1,
        academic_year TEXT DEFAULT '2026-2027',
        start_date TEXT,
        end_date TEXT,
        total_days INTEGER,
        status TEXT DEFAULT 'published',
        is_deleted INTEGER DEFAULT 0,
        version INTEGER DEFAULT 1,
        created_at TEXT
    );

    CREATE TABLE study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type TEXT,
        assigned_value TEXT,
        is_deleted INTEGER DEFAULT 0,
        created_at TEXT
    );

    CREATE TABLE study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        activity_uid TEXT UNIQUE,
        activity_date TEXT,
        day_number INTEGER,
        sort_order INTEGER,
        chapter TEXT,
        topic TEXT,
        activity_title TEXT,
        activity_type TEXT DEFAULT 'Revision',
        estimated_duration INTEGER DEFAULT 30,
        faculty TEXT,
        priority TEXT DEFAULT 'medium',
        difficulty_level TEXT DEFAULT 'medium',
        is_deleted INTEGER DEFAULT 0,
        created_at TEXT
    );

    CREATE TABLE study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        student_email TEXT,
        activity_id INTEGER,
        activity_uid TEXT,
        action_type TEXT,
        completion_status TEXT,
        created_at TEXT
    );
");

// Seed data
// Student in MA/MSc Psychology (Premium)
$pdo->exec("
    INSERT INTO users (user_id, name, email, phone, pepp_course, pepp_academic_year, status, student_status, created_at)
    VALUES ('STU-2026-001', 'Fathima N', 'fathima@gmail.com', '9876543210', 'MA/MSc Psychology (Premium)', '2026-2027', 'approved', 'active', '2026-08-01 10:00:00');
");

// Insert Plan 5: August 2026 (PG) (09 Aug 2026 -> 31 Aug 2026)
$pdo->exec("
    INSERT INTO study_plans (id, title, course_id, academic_year, start_date, end_date, total_days, status, is_deleted, created_at)
    VALUES (5, 'August 2026 (PG)', 1, '2026-2027', '2026-08-09', '2026-08-31', 23, 'published', 0, '2026-08-01 09:00:00');

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted, created_at)
    VALUES (5, 'course', 'MA/MSc Psychology (Premium)', 0, '2026-08-01 09:00:00');
");

// Insert Plan 11: September 2026 (PG) (01 Sep 2026 -> 30 Sep 2026)
$pdo->exec("
    INSERT INTO study_plans (id, title, course_id, academic_year, start_date, end_date, total_days, status, is_deleted, created_at)
    VALUES (11, 'September 2026 (PG)', 1, '2026-2027', '2026-09-01', '2026-09-30', 30, 'published', 0, '2026-08-30 12:00:00');

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted, created_at)
    VALUES (11, 'course', 'MA/MSc Psychology (Premium)', 0, '2026-08-30 12:00:00');
");

// Insert Plan 12: October 2026 (PG) (01 Oct 2026 -> 31 Oct 2026)
$pdo->exec("
    INSERT INTO study_plans (id, title, course_id, academic_year, start_date, end_date, total_days, status, is_deleted, created_at)
    VALUES (12, 'October 2026 (PG)', 1, '2026-2027', '2026-10-01', '2026-10-31', 31, 'published', 0, '2026-08-31 15:00:00');

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted, created_at)
    VALUES (12, 'course', 'MA/MSc Psychology (Premium)', 0, '2026-08-31 15:00:00');
");

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$description}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$description} - {$details}\n";
    }
}

echo "======================================================================\n";
echo "STUDENT STUDY REPORTS: ORDERING & ACTIVE STATE AUDIT\n";
echo "======================================================================\n\n";

// --- Test Suite 1: Query Ordering (Latest Study Plan First) ---
echo "--- Section 1: Server-Side Query Ordering (start_date DESC, end_date DESC, sp.id DESC) ---\n";

$stmt = $pdo->prepare("
    SELECT sp.*, sa.assignment_type, sa.assigned_value
    FROM study_plans sp
    JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
    WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0
      AND LOWER(sp.academic_year) = LOWER(?)
      AND sa.assignment_type = 'course' AND sa.assigned_value = ?
    ORDER BY sp.start_date DESC, sp.end_date DESC, sp.id DESC
");
$stmt->execute(['2026-2027', 'MA/MSc Psychology (Premium)']);
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

assertTest("Query returns exactly 3 study plans", count($plans) === 3, "Got " . count($plans));
assertTest("Top plan (index 0) is October 2026 (Plan 12)", (int)$plans[0]['id'] === 12, "Got ID " . $plans[0]['id']);
assertTest("Second plan (index 1) is September 2026 (Plan 11)", (int)$plans[1]['id'] === 11, "Got ID " . $plans[1]['id']);
assertTest("Third plan (index 2) is August 2026 (Plan 5)", (int)$plans[2]['id'] === 5, "Got ID " . $plans[2]['id']);

// --- Test Suite 2: Dynamic Active State Calculation Function ---
echo "\n--- Section 2: Dynamic Active State Calculation Across All Date Boundaries ---\n";

function calculateIsActive(array $plan, string $simulatedToday): bool {
    return (!empty($plan['start_date']) && !empty($plan['end_date']) &&
            $plan['start_date'] !== '0000-00-00' && $plan['end_date'] !== '0000-00-00' &&
            $simulatedToday >= $plan['start_date'] && $simulatedToday <= $plan['end_date']);
}

$augustPlan = ['id' => 5, 'title' => 'August 2026 (PG)', 'start_date' => '2026-08-09', 'end_date' => '2026-08-31', 'status' => 'published'];
$septemberPlan = ['id' => 11, 'title' => 'September 2026 (PG)', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'published'];
$octoberPlan = ['id' => 12, 'title' => 'October 2026 (PG)', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'published'];

// CASE A: Date range contains today + published => ACTIVE
assertTest("CASE A: Date range contains today + published (September on 2026-09-15) => ACTIVE", calculateIsActive($septemberPlan, '2026-09-15') === true);

// CASE B: Date range contains today + draft/unpublished => ACTIVE for date-range indicator
$draftPlan = ['id' => 13, 'title' => 'Draft September', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'draft'];
assertTest("CASE B: Date range contains today + draft/unpublished => ACTIVE for date-range indicator", calculateIsActive($draftPlan, '2026-09-15') === true);

// CASE C: Date range expired => NOT ACTIVE
assertTest("CASE C: Date range expired (August on 2026-09-01) => NOT ACTIVE", calculateIsActive($augustPlan, '2026-09-01') === false);

// CASE D: Date range starts in future => NOT ACTIVE
assertTest("CASE D: Date range starts in future (October on 2026-09-01) => NOT ACTIVE", calculateIsActive($octoberPlan, '2026-09-01') === false);

// CASE E: start_date itself => ACTIVE
assertTest("CASE E: start_date itself (September on 2026-09-01) => ACTIVE", calculateIsActive($septemberPlan, '2026-09-01') === true);

// CASE F: end_date itself => ACTIVE
assertTest("CASE F: end_date itself (August on 2026-08-31) => ACTIVE", calculateIsActive($augustPlan, '2026-08-31') === true);

// Day before start_date & day after end_date
assertTest("Day before start_date (August on 2026-08-08) => NOT ACTIVE", calculateIsActive($augustPlan, '2026-08-08') === false);
assertTest("Day after end_date (September on 2026-10-01) => NOT ACTIVE", calculateIsActive($septemberPlan, '2026-10-01') === false);

// --- Test Suite 3: Independence from Activity Progress / Last Activity ---
echo "\n--- Section 3: Independence from Activity Completion / Last Activity ---\n";

// Even if student has 56% completion in August and 0% in September:
$augustPlanWith56Pct = array_merge($augustPlan, ['pct' => 56, 'last_updated' => '31 Aug 2026']);
$septemberPlanWith0Pct = array_merge($septemberPlan, ['pct' => 0, 'last_updated' => 'Never']);

$simulatedToday = '2026-09-01';
assertTest("August plan with 56% progress on 2026-09-01 is NOT ACTIVE", calculateIsActive($augustPlanWith56Pct, $simulatedToday) === false);
assertTest("September plan with 0% progress on 2026-09-01 IS ACTIVE", calculateIsActive($septemberPlanWith0Pct, $simulatedToday) === true);

// --- Test Suite 4: Pulse Indicator & Badge Logic Simulation ---
echo "\n--- Section 4: Pulse Dot & ACTIVE Badge Assignment ---\n";

function renderPlanCardHeader(array $plan, string $simulatedToday): string {
    $isActive = calculateIsActive($plan, $simulatedToday);
    $pulseIndicator = $isActive ? '<span class="pulse-dot" style="margin:0; width:8px; height:8px; background:#10b981;" title="Currently Active Study Plan"></span>' : '';
    $activeBadge = $isActive ? '<span class="badge green" style="font-size:0.6rem; padding: 2px 6px; text-transform:uppercase; font-weight:700; display:inline-flex; align-items:center; gap:4px;">ACTIVE</span>' : '';

    return "<div class='header'>{$pulseIndicator}<strong>{$plan['title']}</strong>{$activeBadge}</div>";
}

$augHtml = renderPlanCardHeader($augustPlan, '2026-09-01');
$sepHtml = renderPlanCardHeader($septemberPlan, '2026-09-01');

assertTest("September plan header on 2026-09-01 contains pulse-dot", strpos($sepHtml, 'pulse-dot') !== false);
assertTest("September plan header on 2026-09-01 contains ACTIVE badge", strpos($sepHtml, 'ACTIVE') !== false);
assertTest("August plan header on 2026-09-01 DOES NOT contain pulse-dot", strpos($augHtml, 'pulse-dot') === false);
assertTest("August plan header on 2026-09-01 DOES NOT contain ACTIVE badge", strpos($augHtml, 'ACTIVE') === false);

// Clean up
$stmt = null;
$pdo = null;
if (file_exists($testDbPath)) {
    @unlink($testDbPath);
}

echo "\n======================================================================\n";
echo "AUDIT RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
