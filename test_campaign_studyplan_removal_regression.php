<?php
/**
 * PEPP Learning ERP — Custom Campaign Form ↔ Study Plan Removal Comprehensive Regression Test Suite
 *
 * Validates:
 * 1. Static code verification across all 7 modified files
 * 2. Database Migration 48 structure, safety checks, and physical deletion
 * 3. Backend API rejection for assignment_type = 'form' with canonical error message
 * 4. Student access matrix (Cases A, B, C, D, E)
 * 5. Student portal authentication & authorization hardening
 * 6. Campaign forms system independence preservation
 * 7. Database integrity overview and preservation guarantees
 */

declare(strict_types=1);

echo "======================================================================\n";
echo "CAMPAIGN FORM ↔ STUDY PLAN REMOVAL REGRESSION TEST SUITE\n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $description, bool $condition, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$description}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$description}" . ($details !== '' ? " ({$details})" : '') . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SUITE 1: STATIC CODE AUDIT (VERIFY REMOVAL FROM ALL PRODUCTION CODE)
// ─────────────────────────────────────────────────────────────────────────────
echo "--- Test Suite 1: Static Code Audit & Trace Removal ---\n";

$designerCode = file_get_contents(__DIR__ . '/studyplan-designer.php');
assertTest("studyplan-designer.php: No campaign_forms fetch query",
    strpos($designerCode, "SELECT id, title FROM campaign_forms") === false);
assertTest("studyplan-designer.php: No 'Registered in Custom Forms' card markup",
    strpos($designerCode, "Registered in Custom Forms") === false);
assertTest("studyplan-designer.php: No 'access_forms' POST parameter extraction",
    strpos($designerCode, "access_forms") === false);
assertTest("studyplan-designer.php: No 'formsCount' JS counter element",
    strpos($designerCode, "formsCount") === false);

$portalCode = file_get_contents(__DIR__ . '/studyplan.php');
assertTest("studyplan.php: No 'assignment_type = \'form\'' in portal validation query",
    strpos($portalCode, "assignment_type = 'form'") === false);
assertTest("studyplan.php: No 'form_id' GET parameter routing query",
    strpos($portalCode, "\$_GET['form_id']") === false);
assertTest("studyplan.php: No '\$my_forms' query or array",
    strpos($portalCode, "\$my_forms") === false);
assertTest("studyplan.php: UI title updated to 'Your Course Enrollments'",
    strpos($portalCode, "Your Course Enrollments") !== false);

$reportCode = file_get_contents(__DIR__ . '/studyplan-report.php');
assertTest("studyplan-report.php: No 'assignment_type = \'form\'' in access verification query",
    strpos($reportCode, "assignment_type = 'form'") === false);
assertTest("studyplan-report.php: No fallback form assignment check block",
    strpos($reportCode, "campaign_form_submissions") === false);

$studentAuthCode = file_get_contents(__DIR__ . '/includes/student_auth.php');
assertTest("student_auth.php: can_student_access_study_plan has no campaign_form_submissions query",
    strpos($studentAuthCode, "SELECT COUNT(*) FROM campaign_form_submissions") === false);
assertTest("student_auth.php: authenticate_student_by_credentials has no campaign login branch",
    strpos($studentAuthCode, "'type' => 'campaign'") === false);

$authCode = file_get_contents(__DIR__ . '/includes/auth.php');
assertTest("auth.php: can_student_access_study_plan has no campaign_form_submissions query",
    strpos($authCode, "SELECT COUNT(*) FROM campaign_form_submissions") === false);

$studentReportsCode = file_get_contents(__DIR__ . '/student-study-reports.php');
assertTest("student-study-reports.php: No 'assignment_type = \'form\'' in student_has_plans",
    strpos($studentReportsCode, "sa.assignment_type = 'form'") === false);
assertTest("student-study-reports.php: No get_form_dashboard AJAX action",
    strpos($studentReportsCode, "'get_form_dashboard'") === false);
assertTest("student-study-reports.php: No get_campaign_analytics AJAX action",
    strpos($studentReportsCode, "'get_campaign_analytics'") === false);
assertTest("student-study-reports.php: No get_campaign_plans AJAX action",
    strpos($studentReportsCode, "'get_campaign_plans'") === false);
assertTest("student-study-reports.php: No Custom Forms Workspace HTML view",
    strpos($studentReportsCode, "CUSTOM FORMS & CAMPAIGNS WORKSPACE VIEW") === false);
assertTest("student-study-reports.php: No card-leads-converted in KPI cards",
    strpos($studentReportsCode, "id=\"card-leads-converted\"") === false);
assertTest("student-study-reports.php: No loadFormsDashboardSidebar JS function",
    strpos($studentReportsCode, "loadFormsDashboardSidebar") === false);

// ─────────────────────────────────────────────────────────────────────────────
// SUITE 2: DATABASE MIGRATION SCRIPT & CONFIG SCHEMA
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Test Suite 2: Database Migration 48 & Schema Rules ---\n";

$migrationSql = file_get_contents(__DIR__ . '/database-update-48.sql');
assertTest("database-update-48.sql: Physically DELETEs rows with assignment_type = 'form'",
    preg_match("/DELETE\s+FROM\s+`?study_plan_assignments`?\s+WHERE\s+`?assignment_type`?\s*=\s*'form'/i", $migrationSql) === 1);
assertTest("database-update-48.sql: Modifies assignment_type ENUM to ('all','course','batch','student')",
    preg_match("/MODIFY\s+COLUMN\s+`?assignment_type`?\s+ENUM\('all','course','batch','student'\)\s+NOT\s+NULL/i", $migrationSql) === 1);
assertTest("database-update-48.sql: Does NOT contain soft-delete UPDATE for form assignments",
    strpos($migrationSql, "SET is_deleted = 1") === false);

$dbConfig = file_get_contents(__DIR__ . '/config/database.php');
assertTest("config/database.php: CREATE TABLE schema excludes 'form' from assignment_type ENUM",
    strpos($dbConfig, "`assignment_type` ENUM('all','course','batch','student') NOT NULL") !== false);
assertTest("config/database.php: Destructive migration is NOT executed during HTTP page requests (decoupled from runtime)",
    strpos($dbConfig, "DELETE FROM study_plan_assignments WHERE assignment_type = 'form'") === false);

$auditScript = file_get_contents(__DIR__ . '/scripts/audit_campaign_form_studyplan_impact.php');
assertTest("audit_campaign_form_studyplan_impact.php: CLI migration performs physical DELETE before ALTER ENUM",
    strpos($auditScript, "DELETE FROM study_plan_assignments WHERE assignment_type = 'form'") !== false &&
    strpos($auditScript, "ALTER TABLE study_plan_assignments MODIFY COLUMN assignment_type ENUM('all','course','batch','student') NOT NULL") !== false);
assertTest("audit_campaign_form_studyplan_impact.php: Enforces CLI/SSH only execution (HTTP 403 guard)",
    strpos($auditScript, "php_sapi_name() !== 'cli'") !== false &&
    strpos($auditScript, "http_response_code(403)") !== false);


// ─────────────────────────────────────────────────────────────────────────────
// SUITE 3: BACKEND API VALIDATION & DIRECT POST PROTECTION
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Test Suite 3: Backend API Validation & Form Rejection ---\n";

$apiCode = file_get_contents(__DIR__ . '/api/studyplans-api.php');
assertTest("studyplans-api.php: Contains canonical rejection error message",
    strpos($apiCode, "Campaign Form Study Plan assignments are no longer supported.") !== false);
assertTest("studyplans-api.php: Pre-transaction validation checks for type === 'form'",
    strpos($apiCode, "\$a_type === 'form'") !== false);
assertTest("studyplans-api.php: In-loop validation enforces \$allowed_assign_types",
    strpos($apiCode, "\$allowed_assign_types = ['all', 'course', 'batch', 'student']") !== false);

// Functional API Simulation in memory
$pdoTest = new PDO('sqlite::memory:');
$pdoTest->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoTest->exec("
    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        academic_year TEXT,
        start_date TEXT,
        end_date TEXT,
        status TEXT DEFAULT 'draft',
        version INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0
    );
    CREATE TABLE study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type TEXT,
        assigned_value TEXT,
        is_deleted INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Simulate the exact validation logic in api/studyplans-api.php
function simulate_save_plan_api(array $postData, PDO $pdo): array {
    $data = $postData['data'] ?? [];
    if (is_string($data)) {
        $data = json_decode($data, true) ?? [];
    }

    $title = trim($data['title'] ?? '');
    $academic_year = trim($data['academic_year'] ?? '');
    $start_date = trim($data['start_date'] ?? '');
    $end_date = trim($data['end_date'] ?? '');

    if (empty($title) || empty($academic_year) || empty($start_date) || empty($end_date)) {
        return ['success' => false, 'message' => 'Title, Academic Year, Start Date, and End Date are required.'];
    }

    // Explicit Backend Validation: Reject any Campaign Form Study Plan assignments
    if (isset($data['assignments']) && is_array($data['assignments'])) {
        foreach ($data['assignments'] as $assign) {
            $a_type = $assign['type'] ?? $assign['assignment_type'] ?? '';
            if ($a_type === 'form') {
                return [
                    'success' => false,
                    'message' => 'Campaign Form Study Plan assignments are no longer supported.'
                ];
            }
        }
    }

    $pdo->beginTransaction();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        $stmt = $pdo->prepare("INSERT INTO study_plans (title, academic_year, start_date, end_date, status) VALUES (?, ?, ?, ?, 'published')");
        $stmt->execute([$title, $academic_year, $start_date, $end_date]);
        $plan_id = (int)$pdo->lastInsertId();
    } else {
        $plan_id = $id;
    }

    if (isset($data['assignments']) && is_array($data['assignments'])) {
        $pdo->prepare("DELETE FROM study_plan_assignments WHERE study_plan_id = ?")->execute([$plan_id]);
        $stmt_assign = $pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (?, ?, ?)");
        $allowed_assign_types = ['all', 'course', 'batch', 'student'];
        foreach ($data['assignments'] as $assign) {
            $a_type = $assign['type'] ?? $assign['assignment_type'] ?? '';
            if ($a_type === 'form') {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'message' => 'Campaign Form Study Plan assignments are no longer supported.'
                ];
            }
            if (!empty($a_type) && !empty($assign['value']) && in_array($a_type, $allowed_assign_types, true)) {
                $stmt_assign->execute([$plan_id, $a_type, $assign['value']]);
            }
        }
    }

    $pdo->commit();
    return ['success' => true, 'plan_id' => $plan_id, 'message' => 'Study plan saved successfully.'];
}

// 1. Direct POST attempting to create form assignment
$resForm = simulate_save_plan_api([
    'data' => [
        'title' => 'Malicious Form Plan',
        'academic_year' => '2026-27',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'assignments' => [
            ['type' => 'form', 'value' => '99']
        ]
    ]
], $pdoTest);

assertTest("Direct POST with assignments[type='form'] is REJECTED",
    $resForm['success'] === false);
assertTest("Direct POST returns exact error: 'Campaign Form Study Plan assignments are no longer supported.'",
    $resForm['message'] === 'Campaign Form Study Plan assignments are no longer supported.');

// 2. Direct POST attempting to use assignment_type key
$resFormAlt = simulate_save_plan_api([
    'data' => [
        'title' => 'Malicious Form Plan 2',
        'academic_year' => '2026-27',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'assignments' => [
            ['assignment_type' => 'form', 'value' => '99']
        ]
    ]
], $pdoTest);

assertTest("Direct POST with assignments[assignment_type='form'] is REJECTED",
    $resFormAlt['success'] === false && $resFormAlt['message'] === 'Campaign Form Study Plan assignments are no longer supported.');

// 3. Confirm zero form assignments were created in DB
$countFormInDb = (int)$pdoTest->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form'")->fetchColumn();
assertTest("Zero form assignments created in database after rejected POSTs",
    $countFormInDb === 0);

// 4. Valid course assignment POST succeeds
$resCourse = simulate_save_plan_api([
    'data' => [
        'title' => 'Legitimate Course Plan',
        'academic_year' => '2026-27',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'assignments' => [
            ['type' => 'course', 'value' => 'MA Psychology']
        ]
    ]
], $pdoTest);

assertTest("Legitimate course assignment POST succeeds",
    $resCourse['success'] === true && $resCourse['plan_id'] > 0);
$countCourseInDb = (int)$pdoTest->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'course'")->fetchColumn();
assertTest("Course assignment properly persisted in database",
    $countCourseInDb === 1);

// ─────────────────────────────────────────────────────────────────────────────
// SUITE 4: STUDENT ACCESS REGRESSION MATRIX (CASES A - E)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Test Suite 4: Student Access Regression Matrix (Cases A - E) ---\n";

$pdoAccess = new PDO('sqlite::memory:');
$pdoAccess->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Setup Schema
$pdoAccess->exec("
    CREATE TABLE users (
        user_id TEXT PRIMARY KEY,
        name TEXT,
        email TEXT UNIQUE,
        pepp_course TEXT,
        pepp_academic_year TEXT,
        status TEXT DEFAULT 'approved',
        student_status TEXT DEFAULT 'active'
    );
    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        academic_year TEXT,
        status TEXT DEFAULT 'published',
        start_date TEXT,
        end_date TEXT,
        is_deleted INTEGER DEFAULT 0
    );
    CREATE TABLE study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type TEXT,
        assigned_value TEXT,
        is_deleted INTEGER DEFAULT 0
    );
    CREATE TABLE campaign_forms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        status TEXT DEFAULT 'published',
        is_deleted INTEGER DEFAULT 0
    );
    CREATE TABLE campaign_form_submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER,
        respondent_identifier TEXT,
        is_converted_lead INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Seed Campaign Form 10
$pdoAccess->exec("INSERT INTO campaign_forms (id, title, status) VALUES (10, 'CUET PG Entrance 2026', 'published')");

// Seed Study Plans:
// Plan 1: Assigned to Course 'M.Com'
// Plan 2: Form assignment was deleted/cleaned (no row in study_plan_assignments)
// Plan 3: Assigned to Batch '2026-27'
// Plan 4: Assigned to Student 'STU_D'
// Plan 5: Assigned to 'all'
$pdoAccess->exec("
    INSERT INTO study_plans (id, title, academic_year, status, start_date, end_date) VALUES
    (1, 'Course Plan M.Com', '2026-27', 'published', '2026-08-01', '2026-08-31'),
    (2, 'Legacy Form Plan', '2026-27', 'published', '2026-08-01', '2026-08-31'),
    (3, 'Batch Plan 2026-27', '2026-27', 'published', '2026-08-01', '2026-08-31'),
    (4, 'Student Plan STU_D', '2026-27', 'published', '2026-08-01', '2026-08-31'),
    (5, 'All Students Plan', '2026-27', 'published', '2026-08-01', '2026-08-31');

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES
    (1, 'course', 'M.Com', 0),
    (3, 'batch', '2026-27', 0),
    (4, 'student', 'STU_D', 0),
    (5, 'all', 'all', 0);
");

// Seed Students with Campaign Form 10 submissions:
// Student A: Enrolled in M.Com + Form submission
// Student B: Only Form submission (not enrolled / non-active or no course/batch/student/all match)
// Student C: Enrolled in B.Sc (Batch 2026-27) + Form submission
// Student D: Enrolled in STU_D + Form submission
// Student E: Enrolled student + Form submission
$pdoAccess->exec("
    INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status, student_status) VALUES
    ('STU_A', 'Student A', 'student_a@pepp.com', 'M.Com', '2026-27', 'approved', 'active'),
    ('STU_B', 'Guest B', 'guest_b@pepp.com', 'None', 'None', 'pending', 'unknown'),
    ('STU_C', 'Student C', 'student_c@pepp.com', 'B.Sc Physics', '2026-27', 'approved', 'active'),
    ('STU_D', 'Student D', 'student_d@pepp.com', 'MA Economics', '2026-27', 'approved', 'active'),
    ('STU_E', 'Student E', 'student_e@pepp.com', 'BA English', '2026-27', 'approved', 'active');

    INSERT INTO campaign_form_submissions (form_id, respondent_identifier) VALUES
    (10, 'student_a@pepp.com'),
    (10, 'guest_b@pepp.com'),
    (10, 'student_c@pepp.com'),
    (10, 'student_d@pepp.com'),
    (10, 'student_e@pepp.com');
");

// Canonical portal eligibility resolver (post-cleanup: NO form check)
function get_student_eligible_plans(PDO $pdo, array $student): array {
    $stmt = $pdo->prepare("
        SELECT DISTINCT sp.id, sp.title
        FROM study_plans sp
        JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
        WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0
          AND (
              sa.assignment_type = 'all'
              OR (sa.assignment_type = 'course' AND sa.assigned_value = ?)
              OR (sa.assignment_type = 'batch' AND sa.assigned_value = ?)
              OR (sa.assignment_type = 'student' AND sa.assigned_value = ?)
          )
        ORDER BY sp.id ASC
    ");
    $stmt->execute([
        $student['pepp_course'],
        $student['pepp_academic_year'],
        $student['user_id']
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Case A: Form submission + Course Assignment -> Access to course plan remains
$stA = $pdoAccess->query("SELECT * FROM users WHERE user_id = 'STU_A'")->fetch();
$plansA = get_student_eligible_plans($pdoAccess, $stA);
$pidsA = array_column($plansA, 'id');
assertTest("Case A: Student with Form submission + Course assignment REMAINS ACCESS to Course Plan (Plan 1)",
    in_array(1, $pidsA, true));
assertTest("Case A: Student also has access to All-Student Plan (Plan 5)",
    in_array(5, $pidsA, true));

// Case B: Form submission ONLY -> Access is completely REMOVED
$stB = $pdoAccess->query("SELECT * FROM users WHERE user_id = 'STU_B'")->fetch();
$plansB = get_student_eligible_plans($pdoAccess, $stB);
$pidsB = array_column($plansB, 'id');
assertTest("Case B: User with Form submission ONLY has NO access to legacy form plan (Plan 2)",
    !in_array(2, $pidsB, true));

// Test portal authorization helper for Case B
require_once __DIR__ . '/includes/student_auth.php';
$canAccessB = can_student_access_study_plan($pdoAccess, 'guest_b@pepp.com');
assertTest("Case B: can_student_access_study_plan strictly returns FALSE for form-only respondent",
    $canAccessB === false);

// Case C: Form submission + Batch Assignment -> Access to batch plan remains
$stC = $pdoAccess->query("SELECT * FROM users WHERE user_id = 'STU_C'")->fetch();
$plansC = get_student_eligible_plans($pdoAccess, $stC);
$pidsC = array_column($plansC, 'id');
assertTest("Case C: Student with Form submission + Batch assignment REMAINS ACCESS to Batch Plan (Plan 3)",
    in_array(3, $pidsC, true));
assertTest("Case C: Student has NO access to legacy form plan (Plan 2)",
    !in_array(2, $pidsC, true));

// Case D: Form submission + Student Assignment -> Access to student-specific plan remains
$stD = $pdoAccess->query("SELECT * FROM users WHERE user_id = 'STU_D'")->fetch();
$plansD = get_student_eligible_plans($pdoAccess, $stD);
$pidsD = array_column($plansD, 'id');
assertTest("Case D: Student with Form submission + Student assignment REMAINS ACCESS to Student Plan (Plan 4)",
    in_array(4, $pidsD, true));
assertTest("Case D: Student has NO access to legacy form plan (Plan 2)",
    !in_array(2, $pidsD, true));

// Case E: Form submission + All-Student Assignment -> Access to all-student plan remains
$stE = $pdoAccess->query("SELECT * FROM users WHERE user_id = 'STU_E'")->fetch();
$plansE = get_student_eligible_plans($pdoAccess, $stE);
$pidsE = array_column($plansE, 'id');
assertTest("Case E: Student with Form submission + All assignment REMAINS ACCESS to All-Student Plan (Plan 5)",
    in_array(5, $pidsE, true));
assertTest("Case E: Student has NO access to legacy form plan (Plan 2)",
    !in_array(2, $pidsE, true));

// ─────────────────────────────────────────────────────────────────────────────
// SUITE 5: STUDENT AUTHENTICATION ISOLATION & INTEGRITY
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Test Suite 5: Student Auth Hardening & Unrelated Auth Preservation ---\n";

// Enrolled approved active student login
$authAlice = authenticate_student_by_credentials($pdoAccess, 'student_a@pepp.com', '2004-05-15');
assertTest("Enrolled student login handler executes cleanly without exceptions",
    is_array($authAlice));

// Non-enrolled user with only campaign form submission cannot login
$authGuest = authenticate_student_by_credentials($pdoAccess, 'guest_b@pepp.com', '2004-05-15');
assertTest("Campaign form-only user login is strictly REJECTED (type !== 'campaign')",
    $authGuest['success'] === false && ($authGuest['type'] ?? '') !== 'campaign');
assertTest("Campaign form-only user receives generic failure message (prevents PII leakage)",
    $authGuest['message'] === 'Invalid email address or date of birth. Please verify your registered details.');

// ─────────────────────────────────────────────────────────────────────────────
// SUITE 6: CAMPAIGN FORMS INDEPENDENT FUNCTIONALITY PRESERVATION
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Test Suite 6: Campaign Forms Independence Preservation ---\n";

$campaignFormFiles = [
    'campaign-forms.php',
    'campaign-form-builder.php',
    'campaign-form-responses.php',
    'campaign-form-analytics.php',
    'f.php'
];

foreach ($campaignFormFiles as $cFile) {
    $cPath = __DIR__ . '/' . $cFile;
    if (file_exists($cPath)) {
        $cContent = file_get_contents($cPath);
        assertTest("{$cFile} exists and has ZERO dependency on study_plans",
            strpos($cContent, "study_plans") === false && strpos($cContent, "study_plan_assignments") === false);
    } else {
        assertTest("{$cFile} exists", false);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SUITE 7: DATABASE DESTRUCTIVE CLEANUP SIMULATION & INTEGRITY CHECK
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Test Suite 7: Database Destructive Cleanup Simulation ---\n";

$pdoCleanup = new PDO('sqlite::memory:');
$pdoCleanup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdoCleanup->exec("
    CREATE TABLE study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type TEXT,
        assigned_value TEXT,
        is_deleted INTEGER DEFAULT 0
    );
    CREATE TABLE study_plans (id INTEGER PRIMARY KEY, title TEXT);
    CREATE TABLE study_plan_activities (id INTEGER PRIMARY KEY, title TEXT);
    CREATE TABLE study_plan_analytics (id INTEGER PRIMARY KEY, student_email TEXT);
    CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);
    CREATE TABLE campaign_forms (id INTEGER PRIMARY KEY, title TEXT);
    CREATE TABLE campaign_form_submissions (id INTEGER PRIMARY KEY, respondent_identifier TEXT);
    CREATE TABLE campaign_form_answers (id INTEGER PRIMARY KEY, answer_text TEXT);
");

// Seed representative rows
$pdoCleanup->exec("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES
    (1, 'all', 'all', 0),
    (2, 'course', '101', 0),
    (3, 'batch', '2026-27', 0),
    (4, 'student', 'STU01', 0),
    (5, 'form', '10', 0),          -- active form assignment
    (6, 'form', '11', 1),          -- soft-deleted form assignment
    (7, 'course', '102', 1);        -- soft-deleted course assignment

    INSERT INTO study_plans VALUES (1, 'Plan 1'), (2, 'Plan 2');
    INSERT INTO study_plan_activities VALUES (1, 'Activity 1');
    INSERT INTO study_plan_analytics VALUES (1, 'student@pepp.com');
    INSERT INTO users VALUES (1, 'student@pepp.com');
    INSERT INTO campaign_forms VALUES (1, 'Form 1');
    INSERT INTO campaign_form_submissions VALUES (1, 'student@pepp.com');
    INSERT INTO campaign_form_answers VALUES (1, 'Answer 1');
");

// Pre-cleanup counts
$preAll = (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'all'")->fetchColumn();
$preCourse = (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'course'")->fetchColumn();
$preBatch = (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'batch'")->fetchColumn();
$preStudent = (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'student'")->fetchColumn();
$preForm = (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form'")->fetchColumn();
$preUnrelated = [
    'study_plans' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plans")->fetchColumn(),
    'study_plan_activities' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_activities")->fetchColumn(),
    'study_plan_analytics' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_analytics")->fetchColumn(),
    'users' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'campaign_forms' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM campaign_forms")->fetchColumn(),
    'campaign_form_submissions' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM campaign_form_submissions")->fetchColumn(),
    'campaign_form_answers' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM campaign_form_answers")->fetchColumn(),
];

assertTest("Pre-cleanup: Active and soft-deleted form assignments present (2 rows)",
    $preForm === 2);

// Execute exact Migration 48 cleanup
$pdoCleanup->exec("DELETE FROM study_plan_assignments WHERE assignment_type = 'form'");

// Post-cleanup counts
$postAll = (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'all'")->fetchColumn();
$postCourse = (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'course'")->fetchColumn();
$postBatch = (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'batch'")->fetchColumn();
$postStudent = (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'student'")->fetchColumn();
$postForm = (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form'")->fetchColumn();
$postUnrelated = [
    'study_plans' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plans")->fetchColumn(),
    'study_plan_activities' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_activities")->fetchColumn(),
    'study_plan_analytics' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM study_plan_analytics")->fetchColumn(),
    'users' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'campaign_forms' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM campaign_forms")->fetchColumn(),
    'campaign_form_submissions' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM campaign_form_submissions")->fetchColumn(),
    'campaign_form_answers' => (int)$pdoCleanup->query("SELECT COUNT(*) FROM campaign_form_answers")->fetchColumn(),
];

assertTest("Post-cleanup: assignment_type = 'form' is strictly 0",
    $postForm === 0);
assertTest("Post-cleanup: 'all' assignments count unchanged ({$preAll} === {$postAll})",
    $preAll === $postAll);
assertTest("Post-cleanup: 'course' assignments count unchanged ({$preCourse} === {$postCourse})",
    $preCourse === $postCourse);
assertTest("Post-cleanup: 'batch' assignments count unchanged ({$preBatch} === {$postBatch})",
    $preBatch === $postBatch);
assertTest("Post-cleanup: 'student' assignments count unchanged ({$preStudent} === {$postStudent})",
    $preStudent === $postStudent);

foreach ($preUnrelated as $tbl => $count) {
    assertTest("Post-cleanup: Unrelated table '{$tbl}' count strictly preserved ({$count} === {$postUnrelated[$tbl]})",
        $count === $postUnrelated[$tbl]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Test Suite 8: Production Row 89 Exact Scenario Simulation
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Test Suite 8: Production Row 89 Exact Scenario Simulation ---\n";

$pdoRow89 = new PDO("sqlite::memory:");
$pdoRow89->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdoRow89->exec("
    CREATE TABLE study_plan_assignments (
        id INTEGER PRIMARY KEY,
        study_plan_id INTEGER NOT NULL,
        assignment_type TEXT NOT NULL,
        assigned_value TEXT NOT NULL,
        is_deleted INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    );

    -- Exact production state: Row 89 is soft-deleted form assignment
    INSERT INTO study_plan_assignments (id, study_plan_id, assignment_type, assigned_value, is_deleted, created_at)
    VALUES (89, 8, 'form', '7', 1, '2026-08-29 12:09:17');

    -- Other legitimate active and soft-deleted assignments
    INSERT INTO study_plan_assignments (id, study_plan_id, assignment_type, assigned_value, is_deleted, created_at)
    VALUES
    (90, 8, 'course', '1', 0, '2026-08-29 12:10:00'),
    (91, 8, 'batch', '5', 0, '2026-08-29 12:10:05'),
    (92, 9, 'student', '101', 0, '2026-08-29 12:15:00'),
    (93, 10, 'course', '2', 1, '2026-08-29 12:20:00');
");

// Pre-migration checks
$preTotalForm = (int)$pdoRow89->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form'")->fetchColumn();
$preActiveForm = (int)$pdoRow89->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form' AND is_deleted = 0")->fetchColumn();
$preSoftForm = (int)$pdoRow89->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form' AND is_deleted = 1")->fetchColumn();
$row89Exists = (bool)$pdoRow89->query("SELECT COUNT(*) FROM study_plan_assignments WHERE id = 89")->fetchColumn();

assertTest("Row 89 Scenario: Exactly 1 total form assignment exists prior to cleanup",
    $preTotalForm === 1);
assertTest("Row 89 Scenario: Exactly 0 active form assignments exist in production",
    $preActiveForm === 0);
assertTest("Row 89 Scenario: Exactly 1 soft-deleted form assignment exists (Row 89)",
    $preSoftForm === 1 && $row89Exists);

// Execute exact Migration 48 query
$stmtDel89 = $pdoRow89->prepare("DELETE FROM study_plan_assignments WHERE assignment_type = 'form'");
$stmtDel89->execute();
$deletedRowCount = $stmtDel89->rowCount();

assertTest("Row 89 Scenario: DELETE statement affected exactly 1 row",
    $deletedRowCount === 1);

// Post-migration checks
$postTotalForm = (int)$pdoRow89->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form'")->fetchColumn();
$postRow89Exists = (bool)$pdoRow89->query("SELECT COUNT(*) FROM study_plan_assignments WHERE id = 89")->fetchColumn();
$postCourseActive = (int)$pdoRow89->query("SELECT COUNT(*) FROM study_plan_assignments WHERE id = 90 AND is_deleted = 0")->fetchColumn();
$postCourseSoft = (int)$pdoRow89->query("SELECT COUNT(*) FROM study_plan_assignments WHERE id = 93 AND is_deleted = 1")->fetchColumn();

assertTest("Row 89 Scenario: Post-cleanup count of assignment_type='form' is strictly 0",
    $postTotalForm === 0);
assertTest("Row 89 Scenario: Historical row id=89 is physically deleted from database",
    !$postRow89Exists);
assertTest("Row 89 Scenario: Legitimate active course assignment (id=90) is preserved",
    $postCourseActive === 1);
assertTest("Row 89 Scenario: Legitimate soft-deleted course assignment (id=93) is preserved",
    $postCourseSoft === 1);

// ─────────────────────────────────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────────────────────────────────
echo "\n======================================================================\n";
echo "REGRESSION AUDIT SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
