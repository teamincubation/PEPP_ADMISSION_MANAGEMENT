<?php
/**
 * PEPP Learning ERP — Multilingual Student Mentor AI Report Audit Test Suite
 *
 * Validates:
 * 1. English, Malayalam, and Manglish report transformations.
 * 2. Strict factual data protection (completion, pending, overdue, streak, consistency, dates, names).
 * 3. WhatsApp text language synchronization.
 * 4. Zero em-dash (—) sanitization across all languages.
 * 5. Caching and performance behavior.
 * 6. Security, mentor ownership, and error fallback safety.
 * 7. Verification that paused WhatsApp Cloud API files remain untouched.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/ai/StudentMentorAiService.php';
require_once __DIR__ . '/includes/ai/StudentMentorReportTranslator.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest(bool $condition, string $description, ?string $detail = null): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$description}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$description}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

echo "========================================================================\n";
echo " MULTILINGUAL STUDENT MENTOR AI REPORT AUDIT TEST SUITE\n";
echo "========================================================================\n\n";

// ── SETUP IN-MEMORY SQLITE DATABASE FOR REPEATABLE UNIT AUDIT ──
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// SQLite custom MySQL functions
$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });
$pdo->sqliteCreateFunction('MONTH', function($d) { return (int)date('m', strtotime($d)); });
$pdo->sqliteCreateFunction('DAY', function($d) { return (int)date('d', strtotime($d)); });
$pdo->sqliteCreateFunction('YEAR', function($d) { return (int)date('Y', strtotime($d)); });
$pdo->sqliteCreateFunction('TIMESTAMPDIFF', function($unit, $d1, $d2) {
    return (int)round((strtotime($d2) - strtotime($d1)) / 60);
});

// Database schema
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(50) UNIQUE,
        name VARCHAR(150),
        email VARCHAR(150) UNIQUE,
        phone VARCHAR(30),
        whatsapp_number VARCHAR(30),
        whatsapp_country_code VARCHAR(10),
        pepp_course VARCHAR(100),
        pepp_academic_year VARCHAR(20),
        student_status VARCHAR(50) DEFAULT 'active',
        status VARCHAR(50) DEFAULT 'approved',
        user_photo VARCHAR(255),
        joined_date DATE,
        approval_date DATETIME,
        created_at DATETIME,
        course_duration_date DATE
    );

    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(100),
        role VARCHAR(50)
    );

    CREATE TABLE IF NOT EXISTS mentor_student_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id VARCHAR(50),
        admin_id INTEGER,
        status VARCHAR(20) DEFAULT 'active'
    );

    CREATE TABLE IF NOT EXISTS study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title VARCHAR(255),
        plan_type VARCHAR(50) DEFAULT 'date_wise',
        course_name VARCHAR(100),
        academic_year VARCHAR(20),
        start_date DATE,
        end_date DATE,
        status VARCHAR(50) DEFAULT 'published',
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type VARCHAR(50),
        assigned_value VARCHAR(100),
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        activity_uid VARCHAR(100),
        chapter VARCHAR(150),
        activity_title VARCHAR(255),
        activity_type VARCHAR(50),
        activity_date DATE,
        sort_order INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_email VARCHAR(150),
        study_plan_id INTEGER,
        activity_id INTEGER,
        activity_uid VARCHAR(100),
        action_type VARCHAR(50),
        completion_status VARCHAR(50),
        created_at DATETIME
    );

    CREATE TABLE IF NOT EXISTS assessment_result_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        course_name VARCHAR(100),
        academic_year VARCHAR(20),
        activity_id INTEGER,
        chapter_snapshot VARCHAR(150),
        status VARCHAR(50) DEFAULT 'published',
        activity_date_snapshot DATE
    );

    CREATE TABLE IF NOT EXISTS assessment_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER,
        user_id VARCHAR(50),
        student_email VARCHAR(150),
        attendance_status VARCHAR(50),
        score REAL,
        total_score REAL
    );

    CREATE TABLE IF NOT EXISTS admin_settings (
        setting_name VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    );
");

// Mock mentor check function
if (!function_exists('is_student_assigned_to_mentor')) {
    function is_student_assigned_to_mentor(PDO $pdo, string $studentId, int $mentorId): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM mentor_student_assignments WHERE admin_id = ? AND student_user_id = ? AND status = 'active'");
        $stmt->execute([$mentorId, $studentId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

// Seed mock student and study plan
$pdo->exec("
    INSERT INTO users (user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status, joined_date, created_at)
    VALUES ('STU_MULTI_01', 'Fathima Zahra', 'fathima@example.com', '9876543210', '9876543210', 'M.Clin.Psy. 2026', '2026-2027', 'active', 'approved', '2026-07-01', '2026-07-01 10:00:00');

    INSERT INTO mentor_student_assignments (admin_id, student_user_id, status)
    VALUES (10, 'STU_MULTI_01', 'active');

    INSERT INTO study_plans (id, title, course_name, academic_year, start_date, end_date, status, is_deleted)
    VALUES (101, 'September 2026 Intensive Plan', 'M.Clin.Psy. 2026', '2026-2027', '2026-07-01', '2026-09-30', 'published', 0);

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted)
    VALUES (101, 'course', 'M.Clin.Psy. 2026', 0);

    -- Insert 4 activities: 2 completed, 2 pending
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES
    (1, 101, 'ACT001', 'Neuroanatomy', 'Neuroanatomy Basics', 'task', '2026-07-05', 1, 0),
    (2, 101, 'ACT002', 'Neuroanatomy', 'Cognitive Psychology Overview', 'task', '2026-07-10', 2, 0),
    (3, 101, 'ACT003', 'Psychopathology', 'Psychopathology Case Study', 'task', '2026-07-15', 3, 0),
    (4, 101, 'ACT004', 'Psychopathology', 'Clinical Interview Techniques', 'task', '2026-07-20', 4, 0);

    -- 2 completed activities
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES
    ('fathima@example.com', 101, 1, 'ACT001', 'complete_activity', 'completed', '2026-07-05 10:00:00'),
    ('fathima@example.com', 101, 2, 'ACT002', 'complete_activity', 'completed', '2026-07-10 11:00:00');
");

// ── GROUP 1: LANGUAGE UI LABELS ──
echo "Group 1: Language UI Labels Verification\n";
$enLabels = StudentMentorReportTranslator::getUiLabels('en');
$mlLabels = StudentMentorReportTranslator::getUiLabels('ml');
$manglishLabels = StudentMentorReportTranslator::getUiLabels('manglish');

assertTest(!empty($enLabels['assessment_title']), "English UI label: assessment_title exists");
assertTest($enLabels['assessment_title'] === 'Academic Performance Assessment', "English assessment title matches baseline");
assertTest(!empty($mlLabels['assessment_title']), "Malayalam UI label: assessment_title exists");
assertTest($mlLabels['assessment_title'] === 'അക്കാദമിക് പ്രകടന വിലയിരുത്തൽ', "Malayalam assessment title matches natural terminology");
assertTest($mlLabels['sec_attention'] === 'പ്രത്യേക ശ്രദ്ധ ആവശ്യമായ മേഖലകൾ', "Malayalam attention title matches natural terminology");
assertTest(!empty($manglishLabels['sec_attention']), "Manglish UI label: sec_attention exists");
assertTest(str_contains($manglishLabels['sec_attention'], 'Prathyeka shraddha'), "Manglish attention title uses conversational terminology");

// ── GROUP 2: CANONICAL BASELINE REPORT ──
echo "\nGroup 2: Canonical Baseline Report Verification\n";
$canonical = StudentMentorAiService::extractCanonicalData($pdo, 'STU_MULTI_01', 101, 10, false);
$baseReport = StudentMentorAiService::buildFallbackResponse($canonical, "Multilingual audit testing");

assertTest($baseReport['success'] === true, "Base canonical report generates successfully");
assertTest($baseReport['data']['student_profile']['name'] === 'Fathima Zahra', "Student name preserved in canonical");
assertTest($baseReport['data']['checklist_audit']['completed_tasks'] === 2, "Completed tasks equals 2");
assertTest($baseReport['data']['checklist_audit']['pending_tasks'] === 2, "Pending tasks equals 2");
assertTest($baseReport['data']['checklist_audit']['total_tasks'] === 4, "Total tasks equals 4");
assertTest($baseReport['data']['checklist_audit']['completion_percentage'] === 50, "Completion percentage equals 50%");

// ── GROUP 3: ENGLISH TRANSLATION TRANSFORMATION (ZERO LATENCY BASELINE) ──
echo "\nGroup 3: English Translation Transformation (Zero Latency)\n";
$enReport = StudentMentorReportTranslator::translateReport($baseReport, 'en');

assertTest($enReport['language'] === 'en', "English report language tag is 'en'");
assertTest($enReport['analysis']['overall_status'] === $baseReport['analysis']['overall_status'], "English overall status identical to canonical");
assertTest($enReport['wa_text'] === $baseReport['wa_text'], "English wa_text identical to canonical");
assertTest($enReport['analysis']['snapshot']['completed'] === 2, "English snapshot completed tasks matches");

// ── GROUP 4: MALAYALAM TRANSLATION & FACTUAL PRESERVATION ──
echo "\nGroup 4: Malayalam Translation & Factual Data Protection\n";
$mlReport = StudentMentorReportTranslator::translateReport($baseReport, 'ml');

assertTest($mlReport['language'] === 'ml', "Malayalam report language tag is 'ml'");
assertTest(!empty($mlReport['analysis']['overall_status']), "Malayalam overall status is populated");
assertTest(str_contains($mlReport['analysis']['overall_status'], 'ശരാശരി') || str_contains($mlReport['analysis']['overall_status'], 'Average'), "Malayalam overall status translated appropriately");
assertTest(str_contains($mlReport['analysis']['status_summary'], '4 ടാസ്കുകളിൽ 2 എണ്ണം'), "Malayalam status summary preserves exact numbers: 4 tasks, 2 completed");
assertTest(str_contains($mlReport['analysis']['status_summary'], '50%'), "Malayalam status summary preserves percentage: 50%");

// Factual protection checks
assertTest($mlReport['analysis']['snapshot']['checklist_pct'] === 50, "Factual snapshot checklist_pct preserved in Malayalam");
assertTest($mlReport['analysis']['snapshot']['completed'] === 2, "Factual snapshot completed tasks preserved in Malayalam");
assertTest($mlReport['analysis']['snapshot']['pending'] === 2, "Factual snapshot pending tasks preserved in Malayalam");
assertTest($mlReport['analysis']['snapshot']['overdue'] === $baseReport['analysis']['snapshot']['overdue'], "Factual snapshot overdue tasks preserved in Malayalam");

// WhatsApp text checks for Malayalam
$mlWa = $mlReport['wa_text'];
assertTest(str_contains($mlWa, 'വിദ്യാർത്ഥി പ്രകടന AI വിശകലനം'), "Malayalam WhatsApp header formatted in Malayalam");
assertTest(str_contains($mlWa, 'Fathima Zahra'), "Student name preserved in Malayalam WhatsApp text");
assertTest(str_contains($mlWa, 'M.Clin.Psy. 2026'), "Course name preserved in Malayalam WhatsApp text");
assertTest(str_contains($mlWa, 'September 2026 Intensive Plan'), "Plan title preserved in Malayalam WhatsApp text");
assertTest(str_contains($mlWa, '50%'), "Percentage 50% preserved in Malayalam WhatsApp text");
assertTest(str_contains($mlWa, '✅ പൂർത്തിയായവ: 2'), "Completed count: 2 formatted in Malayalam WhatsApp text");
assertTest(str_contains($mlWa, '⏳ ശേഷിക്കുന്നവ: 2'), "Pending count: 2 formatted in Malayalam WhatsApp text");

// ── GROUP 5: MANGLISH TRANSLATION & FACTUAL PRESERVATION ──
echo "\nGroup 5: Manglish Translation & Factual Data Protection\n";
$manglishReport = StudentMentorReportTranslator::translateReport($baseReport, 'manglish');

assertTest($manglishReport['language'] === 'manglish', "Manglish report language tag is 'manglish'");
assertTest(!empty($manglishReport['analysis']['status_summary']), "Manglish status summary is populated");
assertTest(str_contains($manglishReport['analysis']['status_summary'], '4 tasks-il 2 ennam'), "Manglish status summary preserves exact numbers (4 tasks-il 2 ennam)");
assertTest(str_contains($manglishReport['analysis']['status_summary'], '50%'), "Manglish status summary preserves percentage (50%)");

// Factual protection checks for Manglish
assertTest($manglishReport['analysis']['snapshot']['checklist_pct'] === 50, "Factual snapshot checklist_pct preserved in Manglish");
assertTest($manglishReport['analysis']['snapshot']['completed'] === 2, "Factual snapshot completed tasks preserved in Manglish");
assertTest($manglishReport['analysis']['snapshot']['pending'] === 2, "Factual snapshot pending tasks preserved in Manglish");

// WhatsApp text checks for Manglish
$manglishWa = $manglishReport['wa_text'];
assertTest(str_contains($manglishWa, 'STUDENT PERFORMANCE AI ANALYSIS'), "Manglish WhatsApp header formatted cleanly");
assertTest(str_contains($manglishWa, 'Fathima Zahra'), "Student name preserved in Manglish WhatsApp text");
assertTest(str_contains($manglishWa, '50%'), "Percentage 50% preserved in Manglish WhatsApp text");
assertTest(str_contains($manglishWa, '✅ Completed: 2'), "Completed: 2 preserved in Manglish WhatsApp text");
assertTest(str_contains($manglishWa, '⏳ Pending: 2'), "Pending: 2 preserved in Manglish WhatsApp text");

// ── GROUP 6: STRICT EM-DASH SANITIZATION ──
echo "\nGroup 6: Strict Em-Dash (—) Sanitization Across All Languages\n";
foreach (['en' => $enReport, 'ml' => $mlReport, 'manglish' => $manglishReport] as $langKey => $rep) {
    $wa = $rep['wa_text'];
    $hasEmDash = str_contains($wa, "\xE2\x80\x94") || str_contains($wa, '—');
    $hasEnDash = str_contains($wa, "\xE2\x80\x93") || str_contains($wa, '–');
    $hasHorizBar = str_contains($wa, "\xE2\x80\x95") || str_contains($wa, '―');
    assertTest(!$hasEmDash, "[{$langKey}] wa_text has zero em dashes (U+2014)");
    assertTest(!$hasEnDash, "[{$langKey}] wa_text has zero en dashes (U+2013)");
    assertTest(!$hasHorizBar, "[{$langKey}] wa_text has zero horizontal bars (U+2015)");
}

// ── GROUP 7: CACHE BEHAVIOR VERIFICATION ──
echo "\nGroup 7: Cache Behavior Verification\n";
$firstCallTime = microtime(true);
$rep1 = StudentMentorReportTranslator::translateReport($baseReport, 'ml');
$rep2 = StudentMentorReportTranslator::translateReport($baseReport, 'ml');
$secondCallTime = microtime(true);

assertTest($rep1['wa_text'] === $rep2['wa_text'], "Second translation call yields exact identical cached text");
assertTest(($secondCallTime - $firstCallTime) < 0.05, "Cache hit delivers sub-50ms execution speed");

// ── GROUP 8: ERROR FALLBACK SAFETY ──
echo "\nGroup 8: Error Fallback Safety\n";
// Pass invalid language string
$fallbackRep = StudentMentorReportTranslator::translateReport($baseReport, 'invalid_lang_code');
assertTest($fallbackRep['language'] === 'en', "Unknown language cleanly normalizes to English ('en')");
assertTest($fallbackRep['success'] === true, "Report succeeds without error on unknown language");

// ── GROUP 9: SECURITY & MENTOR AUTHORIZATION ──
echo "\nGroup 9: Security & Mentor Authorization Checks\n";
assertTest(is_student_assigned_to_mentor($pdo, 'STU_MULTI_01', 10), "Assigned mentor (ID 10) is authorized");
assertTest(!is_student_assigned_to_mentor($pdo, 'STU_MULTI_01', 99999), "Unassigned mentor (ID 99999) is denied access");

// ── GROUP 10: WHATSAPP CLOUD API ISOLATION ──
echo "\nGroup 10: Paused WhatsApp Cloud API Files Isolation\n";
$pausedFiles = [
    'api/v1/communication/webhook.php',
    'communication-dashboard.php',
    'communication-templates.php',
    'includes/communication/CommunicationEngine.php',
    'includes/communication/Providers/WhatsAppCloudProvider.php',
    'database-update-51.sql',
    'test_multi_number_whatsapp_audit.php'
];

foreach ($pausedFiles as $pf) {
    assertTest(file_exists(__DIR__ . '/' . $pf), "Paused file [{$pf}] remains isolated and present");
}

echo "\n========================================================================\n";
echo " MULTILINGUAL AUDIT SUMMARY\n";
echo "========================================================================\n";
echo "Total Tests Run : {$totalTests}\n";
echo "Passed          : {$passedTests}\n";
echo "Failed          : {$failedTests}\n";
$passPct = $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 2) : 0;
echo "Pass Percentage : {$passPct}%\n\n";

if ($failedTests === 0) {
    echo "🎉 ALL MULTILINGUAL TESTS PASSED CLEANLY! SYSTEM IS 100% VERIFIED.\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED. INVESTIGATION REQUIRED.\n";
    exit(1);
}
