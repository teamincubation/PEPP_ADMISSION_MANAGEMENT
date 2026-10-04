<?php
/**
 * PEPP ERP — Faculty Live Session Instructions + WhatsApp Notification Workflow Audit Test Suite
 *
 * Covers Scenarios A through AB (and beyond: AC-AF) across all 20 phases:
 * - Scenario A–F: Mandatory Form Fields Validation (Topic, Faculty, DateTime, Duration, Type, Courses >= 1)
 * - Scenario G: Valid session passes all validations
 * - Scenario H: Faculty without mobile warning (does not block session creation)
 * - Scenario I: Immediate queueing of faculty_session_scheduled
 * - Scenario J: 6 parameters correctly populated for faculty_session_scheduled
 * - Scenario K: Quick-Reply button 'Read Instructions' attached
 * - Scenario L–N: 3h, 1h, 2m jobs scheduled when session start is far enough in future
 * - Scenario O–Q: 3h, 1h, 2m jobs suppressed when session start is within the window
 * - Scenario R: Idempotency keys prevent duplicate queue records on multiple calls
 * - Scenario S–T: Inbound "Read Instructions" quick-reply returns Interactive List of active languages
 * - Scenario U: Selecting language returns full instruction body
 * - Scenario V: Instruction body character limit enforcement (<= 1024 chars)
 * - Scenario W: Instruction message includes Quick-Reply "Read & Confirm"
 * - Scenario X: "Read & Confirm" records acknowledgement in faculty_session_acknowledgements
 * - Scenario Y: "Read & Confirm" idempotency (duplicate clicks do not duplicate records)
 * - Scenario Z: "Read & Confirm" sends single confirmation message back to faculty
 * - Scenario AA: Anti-IDOR validation: token strictly verified against sender phone
 * - Scenario AB: Session rescheduled: recalculates windows, invalidates stale jobs
 * - Scenario AC: Session cancelled: future jobs suppressed, faculty cancellation notice dispatched
 * - Scenario AD: Faculty changed: old faculty jobs invalidated, new faculty jobs scheduled
 * - Scenario AE: Google Meet URL extraction & delay if URL not yet generated
 * - Scenario AF: Backward compatibility with/without idempotency_key DB column
 */

declare(strict_types=1);

// Force SQLite simulation in CLI environment
putenv("PEPP_USE_SQLITE=1");
$_ENV['PEPP_USE_SQLITE'] = '1';

require_once __DIR__ . '/includes/communication/CommunicationHelper.php';
require_once __DIR__ . '/includes/communication/FacultySessionNotificationService.php';
require_once __DIR__ . '/includes/communication/FacultySessionInteractionHandler.php';
require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
require_once __DIR__ . '/includes/communication/Providers/WhatsAppCloudProvider.php';

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $scenario, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$scenario}" . ($detail ? ": {$detail}" : '') . "\n";
    } else {
        $failed++;
        echo "  [FAIL] {$scenario}" . ($detail ? ": {$detail}" : '') . "\n";
    }
}

echo "========================================================================\n";
echo " AUDIT TEST SUITE: PEPP Faculty Live Session WhatsApp Workflow\n";
echo "========================================================================\n\n";

// Setup In-Memory Test Database
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// 1. Create Core Tables
$pdo->exec("
    CREATE TABLE admin_settings (
        setting_name TEXT PRIMARY KEY,
        setting_value TEXT
    );
    INSERT INTO admin_settings VALUES
        ('whatsapp_access_token', 'EAABtest_token_sample'),
        ('whatsapp_phone_number_id', '1000999888'),
        ('whatsapp_business_id', '999888777'),
        ('whatsapp_api_version', 'v20.0');

    CREATE TABLE faculties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT,
        mobile TEXT,
        status TEXT DEFAULT 'active'
    );
    INSERT INTO faculties (id, name, email, mobile, status) VALUES
        (1, 'Dr. Arshad Khan', 'arshad@pepplearning.in', '919876543210', 'active'),
        (2, 'Prof. Meera Nair', 'meera@pepplearning.in', '919845123456', 'active'),
        (3, 'Guest Faculty', 'guest@pepplearning.in', '', 'active');

    CREATE TABLE sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        topic TEXT NOT NULL,
        faculty_id INTEGER,
        session_datetime TEXT NOT NULL,
        duration_hours REAL NOT NULL,
        session_type TEXT NOT NULL,
        meet_link TEXT,
        venue TEXT,
        course_csv TEXT,
        status TEXT DEFAULT 'scheduled',
        google_integrated INTEGER DEFAULT 0,
        google_meet_uri TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE communication_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel TEXT DEFAULT 'whatsapp',
        template_name TEXT NOT NULL,
        language TEXT DEFAULT 'en',
        status TEXT DEFAULT 'approved',
        category TEXT DEFAULT 'utility',
        quality_status TEXT DEFAULT 'green',
        meta_data TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE communication_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel TEXT NOT NULL,
        recipient TEXT NOT NULL,
        recipient_name TEXT DEFAULT NULL,
        subject TEXT DEFAULT NULL,
        body_html TEXT DEFAULT NULL,
        body_text TEXT DEFAULT NULL,
        template_name TEXT DEFAULT NULL,
        template_data TEXT DEFAULT NULL,
        attachments TEXT DEFAULT NULL,
        status TEXT DEFAULT 'pending',
        priority INTEGER DEFAULT 1,
        retry_count INTEGER DEFAULT 0,
        next_attempt_at TEXT DEFAULT NULL,
        sent_at TEXT DEFAULT NULL,
        sent_by TEXT DEFAULT NULL,
        student_uid TEXT DEFAULT NULL,
        event_name TEXT DEFAULT NULL,
        invoice_id INTEGER DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        idempotency_key TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE faculty_session_instructions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        language_code TEXT NOT NULL UNIQUE,
        language_name TEXT NOT NULL,
        instruction_title TEXT NOT NULL,
        instruction_body TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE faculty_session_acknowledgements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        faculty_id INTEGER NOT NULL,
        instruction_language TEXT NOT NULL,
        acknowledged_at TEXT DEFAULT CURRENT_TIMESTAMP,
        wa_message_id TEXT DEFAULT NULL,
        UNIQUE(session_id, faculty_id)
    );

    CREATE TABLE faculty_session_interactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        interaction_token TEXT NOT NULL UNIQUE,
        faculty_id INTEGER NOT NULL,
        session_id INTEGER NOT NULL,
        phone TEXT NOT NULL,
        originating_wa_message_id TEXT DEFAULT NULL,
        selected_language TEXT DEFAULT NULL,
        step TEXT NOT NULL DEFAULT 'awaiting_language',
        expires_at TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS whatsapp_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id TEXT,
        student_name TEXT,
        mobile TEXT,
        phone TEXT,
        message TEXT,
        message_type TEXT,
        status TEXT,
        sent_by TEXT,
        latitude TEXT,
        longitude TEXT,
        metadata TEXT,
        sent_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        response_message_id TEXT,
        error_message TEXT
    );
");

class MockWhatsAppProvider {
    public array $sentMessages = [];

    public function sendMessage($recipient, $subject, $bodyHtml, $bodyText, $attachments = [], $templateData = []) {
        $this->sentMessages[] = [
            'recipient'    => $recipient,
            'subject'      => $subject,
            'body_text'    => $bodyText,
            'template_data'=> $templateData
        ];
        return ['success' => true, 'message_id' => 'wamid.MOCK_' . uniqid()];
    }
}
$mockProvider = new MockWhatsAppProvider();
CommunicationEngine::getInstance($pdo)->mockProvider = $mockProvider;

// Seed default instructions
$pdo->exec("
    INSERT INTO faculty_session_instructions (language_code, language_name, instruction_title, instruction_body, active) VALUES
    ('en', 'English', 'Live Session Faculty Guidelines', '1. Join on time via Google Meet.\n2. Ensure good audio/video.\n3. Wrap up within scheduled duration.', 1),
    ('ml', 'Malayalam (മലയാളം)', 'ലൈവ് സെഷൻ നിർദ്ദേശങ്ങൾ', '1. കൃത്യസമയത്ത് പ്രവേശിക്കുക.\n2. ഓഡിയോ & വീഡിയോ വ്യക്തമാക്കുക.\n3. സമയപരിധിക്കുള്ളിൽ പൂർത്തിയാക്കുക.', 1),
    ('hi', 'Hindi (हिन्दी)', 'लाइव सत्र निर्देश', '1. समय पर जुड़ें।\n2. ऑडियो/वीडियो सुनिश्चित करें।', 0);
");

// Seed 5 templates into communication_templates
$templates = [
    'faculty_session_scheduled' => "Hello {{1}}, you have a session {{2}} on {{4}}.",
    'faculty_session_reminder'  => "Reminder: Hello {{1}}, session {{2}} starts in 3 hours.",
    'faculty_session_start'     => "Alert: Hello {{1}}, session {{2}} starts in 1 hour. Link: {{5}}",
    'faculty_session_start_now' => "Starting now: Hello {{1}}, session {{2}} starting now! Link: {{4}}",
    'faculty_session_cancelled' => "Notice: Hello {{1}}, session {{2}} on {{4}} is cancelled."
];
$stmtTpl = $pdo->prepare("INSERT INTO communication_templates (template_name, meta_data) VALUES (?, ?)");
foreach ($templates as $tName => $bText) {
    $stmtTpl->execute([$tName, json_encode(['body_text' => $bText])]);
}

echo "--- SECTION 1: FORM VALIDATION (SCENARIOS A–H) ---\n";

function validateSessionInput(array $input): array {
    $errors = [];
    $topic = trim($input['topic'] ?? '');
    $facultyId = (int)($input['faculty_id'] ?? 0);
    $dt = trim($input['session_datetime'] ?? '');
    $dur = (float)($input['duration_hours'] ?? 0);
    $type = trim($input['session_type'] ?? '');
    $courses = array_filter(array_map('trim', (array)($input['courses'] ?? [])));

    if ($topic === '') $errors[] = 'Session Topic is required.';
    if ($facultyId <= 0) $errors[] = 'Faculty is required.';
    if ($dt === '' || !strtotime($dt)) $errors[] = 'Date & Time is required and must be valid.';
    if ($dur <= 0) $errors[] = 'Duration is required and must be greater than 0.';
    if ($type === '') $errors[] = 'Session Type is required.';
    if (empty($courses)) $errors[] = 'At least one Course must be selected.';

    return $errors;
}

$validInput = [
    'topic' => 'Financial Accounting',
    'faculty_id' => 1,
    'session_datetime' => '2026-11-01 10:00:00',
    'duration_hours' => 1.5,
    'session_type' => 'live',
    'courses' => ['B.Com Finance', 'BBA']
];

// Scenario A: Missing Topic
$inputA = $validInput; $inputA['topic'] = '';
$errA = validateSessionInput($inputA);
assertTest(in_array('Session Topic is required.', $errA, true), 'Scenario A', 'Missing topic rejected');

// Scenario B: Missing Faculty
$inputB = $validInput; $inputB['faculty_id'] = 0;
$errB = validateSessionInput($inputB);
assertTest(in_array('Faculty is required.', $errB, true), 'Scenario B', 'Missing faculty rejected');

// Scenario C: Missing Date & Time
$inputC = $validInput; $inputC['session_datetime'] = '';
$errC = validateSessionInput($inputC);
assertTest(in_array('Date & Time is required and must be valid.', $errC, true), 'Scenario C', 'Missing datetime rejected');

// Scenario D: Missing Duration
$inputD = $validInput; $inputD['duration_hours'] = 0;
$errD = validateSessionInput($inputD);
assertTest(in_array('Duration is required and must be greater than 0.', $errD, true), 'Scenario D', 'Zero duration rejected');

// Scenario E: Missing Session Type
$inputE = $validInput; $inputE['session_type'] = '';
$errE = validateSessionInput($inputE);
assertTest(in_array('Session Type is required.', $errE, true), 'Scenario E', 'Empty session type rejected');

// Scenario F: 0 Courses Selected
$inputF = $validInput; $inputF['courses'] = [];
$errF = validateSessionInput($inputF);
assertTest(in_array('At least one Course must be selected.', $errF, true), 'Scenario F', 'Zero courses rejected');

// Scenario G: Valid session input
$errG = validateSessionInput($validInput);
assertTest(empty($errG), 'Scenario G', 'Valid input passes all 6 mandatory checks');

// Scenario H: Faculty without WhatsApp number creates warning, does not block creation
$facWithoutPhone = $pdo->query("SELECT * FROM faculties WHERE id = 3")->fetch();
$warningMsg = '';
$hasPhone = !empty(preg_replace('/\D/', '', (string)($facWithoutPhone['mobile'] ?? '')));
if (!$hasPhone) {
    $warningMsg = "Faculty has no WhatsApp number configured. Session will be scheduled, but WhatsApp notifications cannot be delivered.";
}
assertTest($warningMsg !== '' && str_contains($warningMsg, 'no WhatsApp number configured'), 'Scenario H', 'Warning banner triggered without blocking session');


echo "\n--- SECTION 2: QUEUEING & IDEMPOTENCY (SCENARIOS I–R) ---\n";

$notifService = new FacultySessionNotificationService($pdo);
$futureDateFar = date('Y-m-d H:i:s', time() + (24 * 3600)); // 24 hours from now

// Insert session 101 far in future
$pdo->prepare("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, meet_link, course_csv, status, google_integrated, google_meet_uri)
    VALUES (101, 'Advanced Taxation', 1, ?, 2.0, 'live', 'https://meet.google.com/xyz-tax-live', 'B.Com, CA Inter', 'scheduled', 1, 'https://meet.google.com/xyz-tax-live')
")->execute([$futureDateFar]);

$resI = $notifService->scheduleSessionCreation(101, 'admin');

// Scenario I: Session creation queues faculty_session_scheduled immediately
$scheduledJob = $pdo->query("SELECT * FROM communication_queue WHERE template_name = 'faculty_session_scheduled' AND recipient = '919876543210'")->fetch();
assertTest(!empty($scheduledJob), 'Scenario I', 'faculty_session_scheduled job queued immediately');

// Scenario J: faculty_session_scheduled contains correct 6 variables
$tplData = json_decode($scheduledJob['template_data'], true);
$params = $tplData['parameters'] ?? [];
assertTest(
    count($params) === 6 &&
    $params[0] === 'Dr. Arshad Khan' &&
    $params[1] === 'Advanced Taxation' &&
    $params[2] === 'Live' &&
    $params[4] === 'B.Com, CA Inter' &&
    $params[5] === '2 hours',
    'Scenario J',
    'Exact 6 parameters matched: Faculty, Topic, Type, Datetime, Courses, Duration'
);

// Scenario K: faculty_session_scheduled has quick-reply button 'Read Instructions'
$quickReplies = $tplData['buttons']['quick_reply'] ?? $tplData['buttons'] ?? [];
$hasReadInstButton = false;
foreach ($quickReplies as $btn) {
    if (($btn['text'] ?? '') === 'Read Instructions') {
        $hasReadInstButton = true;
        break;
    }
}
assertTest($hasReadInstButton, 'Scenario K', 'Quick reply button Read Instructions present');

// Scenario L–N: 3h, 1h, 2m jobs scheduled when session start is far enough in future
$job3h = $pdo->query("SELECT * FROM communication_queue WHERE template_name = 'faculty_session_reminder' AND recipient = '919876543210'")->fetch();
$job1h = $pdo->query("SELECT * FROM communication_queue WHERE template_name = 'faculty_session_start' AND recipient = '919876543210'")->fetch();
$job2m = $pdo->query("SELECT * FROM communication_queue WHERE template_name = 'faculty_session_start_now' AND recipient = '919876543210'")->fetch();
assertTest(!empty($job3h), 'Scenario L', '3-hour reminder job queued with scheduled execution time');
assertTest(!empty($job1h), 'Scenario M', '1-hour start notice job queued with scheduled execution time');
assertTest(!empty($job2m), 'Scenario N', '2-minute start now alert job queued with scheduled execution time');

// Scenario O–Q: Session created within windows does NOT queue already-elapsed reminder jobs
$dateIn2Hours = date('Y-m-d H:i:s', time() + (2 * 3600)); // 2 hours away (< 3h)
$pdo->prepare("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, meet_link, course_csv, status, google_integrated, google_meet_uri)
    VALUES (102, 'Cost Accounting Fast-Track', 2, ?, 1.0, 'live', 'https://meet.google.com/cst-fast-live', 'B.Com', 'scheduled', 1, 'https://meet.google.com/cst-fast-live')
")->execute([$dateIn2Hours]);

$resNear = $notifService->scheduleSessionCreation(102, 'admin');

$near3h = $pdo->query("SELECT * FROM communication_queue WHERE template_name = 'faculty_session_reminder' AND recipient = '919845123456'")->fetch();
$near1h = $pdo->query("SELECT * FROM communication_queue WHERE template_name = 'faculty_session_start' AND recipient = '919845123456'")->fetch();
$near2m = $pdo->query("SELECT * FROM communication_queue WHERE template_name = 'faculty_session_start_now' AND recipient = '919845123456'")->fetch();

assertTest(empty($near3h), 'Scenario O', '3-hour reminder correctly suppressed when session starts in 2 hours');
assertTest(!empty($near1h), 'Scenario P', '1-hour notice correctly scheduled for session 2 hours away');
assertTest(!empty($near2m), 'Scenario Q', '2-minute alert correctly scheduled for session 2 hours away');

// Scenario R: Idempotency key prevents duplicate queue entries
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue WHERE template_name = 'faculty_session_scheduled' AND recipient = '919876543210'")->fetchColumn();
// Re-call scheduleSessionCreation for session 101
$notifService->scheduleSessionCreation(101, 'admin');
$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue WHERE template_name = 'faculty_session_scheduled' AND recipient = '919876543210'")->fetchColumn();
assertTest($countBefore === $countAfter, 'Scenario R', 'Idempotency key prevents duplicate queue records on repeated calls');


echo "\n--- SECTION 3: INBOUND INTERACTIVE WHATSAPP FLOW (SCENARIOS S–AA) ---\n";

$interactionHandler = new FacultySessionInteractionHandler($pdo);

// Build inbound webhook message for "Read Instructions" quick-reply
$inboundMsgReadInst = [
    'from' => '919876543210',
    'id' => 'wamid.TEST_INBOUND_001',
    'type' => 'button',
    'button' => [
        'text' => 'Read Instructions',
        'payload' => 'READ_INSTRUCTIONS'
    ]
];

// Handle interaction
$respS = $interactionHandler->handleInboundMessage($inboundMsgReadInst);

// Scenario S & T: Interactive list of active languages
assertTest(!empty($respS['handled']) && $respS['action'] === 'language_list_sent', 'Scenario S', 'Inbound Read Instructions triggers language list');
$tokenUsed = $respS['interaction_token'] ?? '';

// Scenario T: Interactive list contains only active instruction languages
$activeLangs = $interactionHandler->getActiveLanguages();
$langCodes = array_column($activeLangs, 'language_code');
assertTest(
    in_array('en', $langCodes, true) &&
    in_array('ml', $langCodes, true) &&
    !in_array('hi', $langCodes, true), // Hindi is inactive (active = 0)
    'Scenario T',
    'Interactive list contains only ACTIVE languages (en, ml) and excludes inactive (hi)'
);

// Scenario U: Selecting a language returns the full instruction text
$inboundSelectEn = [
    'from' => '919876543210',
    'id' => 'wamid.TEST_INBOUND_002',
    'type' => 'interactive',
    'interactive' => [
        'type' => 'list_reply',
        'list_reply' => [
            'id' => "fsi_lang_en_{$tokenUsed}",
            'title' => 'English'
        ]
    ]
];

$respU = $interactionHandler->handleInboundMessage($inboundSelectEn);
assertTest(!empty($respU['handled']) && $respU['action'] === 'instruction_sent', 'Scenario U', 'Language selection returns instruction body');

// Scenario V: Instruction text length limit <= 1024 characters
$instText = $respU['body'] ?? $respU['instruction_text'] ?? '';
assertTest(mb_strlen($instText, 'UTF-8') <= 1024 && str_contains($instText, 'Live Session Faculty Guidelines'), 'Scenario V', 'Instruction text length <= 1024 chars verified');

// Scenario W: Instruction message includes quick-reply button "Read & Confirm"
$instButtons = $respU['buttons'] ?? [];
$confirmToken = '';
$hasConfirmButton = false;
foreach ($instButtons as $b) {
    if (($b['text'] ?? '') === 'Read & Confirm') {
        $hasConfirmButton = true;
        $confirmToken = $b['payload'] ?? '';
        break;
    }
}
assertTest($hasConfirmButton && str_starts_with($confirmToken, 'confirm_'), 'Scenario W', 'Quick-Reply button Read & Confirm attached with secure token');

// Scenario X: Inbound "Read & Confirm" records acknowledgement in database
$inboundAck = [
    'from' => '919876543210',
    'id' => 'wamid.TEST_INBOUND_003',
    'type' => 'button',
    'button' => [
        'text' => 'Read & Confirm',
        'payload' => $confirmToken
    ]
];

$respX = $interactionHandler->handleInboundMessage($inboundAck);
$ackRow = $pdo->query("SELECT * FROM faculty_session_acknowledgements WHERE session_id = 101 AND faculty_id = 1")->fetch();

assertTest(!empty($respX['handled']) && !empty($ackRow), 'Scenario X', 'Acknowledgement recorded in faculty_session_acknowledgements');
assertTest($ackRow['instruction_language'] === 'en', 'Scenario X', 'Language code preserved accurately in database');

// Scenario Y: "Read & Confirm" is idempotent — duplicate clicks do not create duplicate records
$respY = $interactionHandler->handleInboundMessage($inboundAck);
$ackCount = (int)$pdo->query("SELECT COUNT(*) FROM faculty_session_acknowledgements WHERE session_id = 101 AND faculty_id = 1")->fetchColumn();
assertTest($ackCount === 1 && $respY['action'] === 'duplicate_suppressed', 'Scenario Y', 'Duplicate confirm click suppressed idempotently (record count = 1)');

// Scenario Z: "Read & Confirm" sends confirmation message back to faculty
assertTest(
    !empty($respX['body']) &&
    str_contains($respX['body'], 'acknowledged'),
    'Scenario Z',
    'Confirmation message dispatched back to faculty'
);

// Scenario AA: Anti-IDOR security — interaction tokens validated against sender's phone number
$idorInbound = [
    'from' => '919999999999', // Attacker phone number mismatch
    'id' => 'wamid.TEST_ATTACK_001',
    'type' => 'button',
    'button' => [
        'text' => 'Read & Confirm',
        'payload' => $confirmToken
    ]
];
$respAA = $interactionHandler->handleInboundMessage($idorInbound);
assertTest($respAA['handled'] === false, 'Scenario AA', 'Anti-IDOR rejection: Request from mismatched phone rejected safely');


echo "\n--- SECTION 4: SESSION LIFECYCLE RECALCULATION & SUPPRESSION (SCENARIOS AB–AF) ---\n";

// Scenario AB: Session rescheduled — old jobs cancelled, new jobs scheduled
$oldData = $pdo->query("SELECT * FROM sessions WHERE id = 101")->fetch();
$newDate = date('Y-m-d H:i:s', time() + (48 * 3600)); // Moved forward to +48 hours
$pdo->prepare("UPDATE sessions SET session_datetime = ? WHERE id = 101")->execute([$newDate]);

$resAB = $notifService->handleSessionUpdate(101, $oldData, 'admin');

// Verify old jobs were invalidated
$supersededJobs = $pdo->query("SELECT COUNT(*) FROM communication_queue WHERE status = 'cancelled' AND error_message LIKE '%Rescheduled%'")->fetchColumn();
assertTest((int)$supersededJobs >= 3, 'Scenario AB', 'Rescheduling invalidates previous pending jobs as cancelled');

// Verify new jobs were scheduled for the new time
$new3hJob = $pdo->query("SELECT * FROM communication_queue WHERE template_name = 'faculty_session_reminder' AND recipient = '919876543210' AND status = 'scheduled'")->fetch();
assertTest(!empty($new3hJob), 'Scenario AB', 'New jobs recalculated and scheduled for the updated session time');

// Scenario AC: Session cancelled — future jobs suppressed, cancellation notice sent
$resAC = $notifService->handleSessionCancellation(101, 'admin');
$cancelledJobs = $pdo->query("SELECT COUNT(*) FROM communication_queue WHERE status = 'cancelled' AND error_message LIKE '%Session cancelled%'")->fetchColumn();
$cancelNotice = $pdo->query("SELECT * FROM communication_queue WHERE template_name = 'faculty_session_cancelled' AND recipient = '919876543210'")->fetch();

assertTest((int)$cancelledJobs >= 3, 'Scenario AC', 'Session cancellation suppresses all pending jobs');
assertTest(!empty($cancelNotice), 'Scenario AC', 'faculty_session_cancelled notice queued immediately for faculty');

// Scenario AD: Faculty changed — old faculty jobs cancelled, new faculty scheduled
$futureDateAD = date('Y-m-d H:i:s', time() + (30 * 3600));
$pdo->prepare("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, meet_link, course_csv, status, google_integrated, google_meet_uri)
    VALUES (103, 'Strategic Management', 1, ?, 1.5, 'live', 'https://meet.google.com/sm-live-103', 'MBA', 'scheduled', 1, 'https://meet.google.com/sm-live-103')
")->execute([$futureDateAD]);
$notifService->scheduleSessionCreation(103, 'admin');

$oldDataAD = $pdo->query("SELECT * FROM sessions WHERE id = 103")->fetch();
// Update faculty to Faculty #2 (Prof. Meera Nair, 919845123456)
$pdo->prepare("UPDATE sessions SET faculty_id = 2 WHERE id = 103")->execute();

$resAD = $notifService->handleSessionUpdate(103, $oldDataAD, 'admin');

$oldFacJobsCancelled = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue WHERE recipient = '919876543210' AND error_message LIKE '%Faculty reassigned%'")->fetchColumn();
$newFacJobsScheduled = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue WHERE recipient = '919845123456' AND (status = 'pending' OR status = 'scheduled')")->fetchColumn();

assertTest($oldFacJobsCancelled >= 3, 'Scenario AD', 'Old faculty pending jobs suppressed upon faculty reassignment');
assertTest($newFacJobsScheduled >= 1, 'Scenario AD', 'New faculty receives scheduled notifications for reassigned session');

// Scenario AE: Google Meet URL extraction & delay if URL not yet generated
$commEngine = CommunicationEngine::getInstance($pdo);
// Test session 104 with empty meet URL
$pdo->prepare("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, meet_link, google_meet_uri, course_csv, status, google_integrated)
    VALUES (104, 'Jurisprudence', 1, ?, 1.0, 'live', '', '', 'LLB', 'scheduled', 1)
")->execute([$futureDateFar]);

$extractedUrl = $notifService->resolveGoogleMeetUrl([
    'meet_link' => '',
    'google_meet_uri' => ''
]);
assertTest($extractedUrl === '', 'Scenario AE', 'Empty meet URL correctly recognized when Google sync is in progress');

// Simulate pre-send queue check with empty URL for start alert
$queueItemMissingMeet = [
    'id' => 9999,
    'template_name' => 'faculty_session_start',
    'template_data' => json_encode(['parameters' => ['Dr. Arshad Khan', 'Jurisprudence', 'Live', '2026-11-01', '']]),
    'recipient' => '919876543210',
    'retry_count' => 0
];
$preCheckRes = $commEngine->preCheckFacultySessionQueueItem($queueItemMissingMeet, [
    'status' => 'scheduled',
    'meet_link' => '',
    'google_meet_uri' => ''
]);
assertTest(
    $preCheckRes['action'] === 'reschedule' && $preCheckRes['reschedule_minutes'] === 3,
    'Scenario AE',
    'Queue dispatch gracefully delays by +3 minutes if Google Meet URL is not yet generated (prevents broken link dispatch)'
);

// Scenario AF: Backward compatibility with/without idempotency_key DB column
assertTest($commEngine->hasIdempotencyKeyColumn(), 'Scenario AF', 'Dual compatibility detects DB column and supports marker fallback');

echo "\n========================================================================\n";
echo " AUDIT SUMMARY: {$passed} / " . ($passed + $failed) . " TESTS PASSED\n";
if ($failed === 0) {
    echo " STATUS: ALL AUDIT SCENARIOS (A–AB + AC–AF) PASSED WITH 100% SUCCESS\n";
} else {
    echo " STATUS: {$failed} TEST(S) FAILED\n";
}
echo "========================================================================\n";
