<?php
/**
 * test_communication_templates_param_resolution_audit.php
 *
 * Dedicated forensic verification for Template Parameter Resolution,
 * Dynamic CTA Button URL handling, and Communication Templates UI logic.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/communication/CommunicationHelper.php';
require_once __DIR__ . '/includes/communication/Providers/WhatsAppCloudProvider.php';
require_once __DIR__ . '/includes/communication/FacultySessionNotificationService.php';

$passed = 0;
$total = 0;

function assertTest(bool $condition, string $msg): void {
    global $passed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$msg}\n";
    } else {
        echo "  [FAIL] {$msg}\n";
    }
}

echo "========================================================================\n";
echo " AUDIT: Parameter Resolution & Dynamic CTA URL Button Verification\n";
echo "========================================================================\n\n";

// --- SECTION 1: Canonical Meta Template Parameter Definition Parsing ---
echo "--- SECTION 1: Canonical Meta Template Parameter Definition Parsing ---\n";

// 1. faculty_session_scheduled (6 body params + quick reply button)
// {{1}} Name, {{2}} Type, {{3}} Topic, {{4}} Datetime, {{5}} Courses, {{6}} Duration
$scheduledMeta = json_encode([
    'body_text' => "Hi *{{1}}*,\n\n✅Confirm the following schedule. \n\nThis is a {{2}} session.\nTopic: {{3}}\nDate & Time: *{{4}}*\nCourses: {{5}}\nProposed Duration: *{{6}}*\n\nPlease join on time and be ready before the scheduled start time.\n*Read the faculty instructions before your session.*",
    'buttons' => [
        ['type' => 'QUICK_REPLY', 'text' => 'Read Instructions']
    ]
]);
$scheduledDef = CommunicationHelper::getTemplateParameterDefinition($scheduledMeta);
assertTest($scheduledDef['body']['count'] === 6, "faculty_session_scheduled: body count is 6");
assertTest($scheduledDef['body']['indexes'] === [1, 2, 3, 4, 5, 6], "faculty_session_scheduled: body indexes [1, 2, 3, 4, 5, 6]");
assertTest($scheduledDef['button_url']['has_variable'] === false, "faculty_session_scheduled: no button URL variable");

// 2. faculty_session_reminder (2 body params: Name, Datetime; No button, no topic or duration)
$reminderMeta = json_encode([
    'body_text' => "Hi *{{1}}*, \n \nThis is a reminder that you have a PEPP live session today at *{{2}}*.  \n\nWe hope you are prepared well for the session.  \nThank you!",
    'buttons' => []
]);
$reminderDef = CommunicationHelper::getTemplateParameterDefinition($reminderMeta);
assertTest($reminderDef['body']['count'] === 2, "faculty_session_reminder: body count is strictly 2");
assertTest($reminderDef['body']['indexes'] === [1, 2], "faculty_session_reminder: body indexes [1, 2]");
assertTest($reminderDef['button_url']['has_variable'] === false, "faculty_session_reminder: no button URL variable");

// 3. faculty_session_start (5 body params: Name, Datetime, Topic, Courses, Duration + 1 dynamic URL button param {{1}})
$startMeta = json_encode([
    'body_text' => "Dear *{{1}}*,  \n\nYour PEPP live session is scheduled to start at *{{2}}*.  \n\nSession: {{3}}\nCourses: {{4}}\nDuration: {{5}}\n\nNote: \n1. *Automatic recording* and Gemini notes *will start when you enter the session*.\n2. Please *do not enter the session earlier than 5 minutes* before the scheduled start time.",
    'buttons' => [
        ['type' => 'URL', 'text' => 'Start Live', 'url' => 'https://meet.google.com/{{1}}']
    ]
]);
$startDef = CommunicationHelper::getTemplateParameterDefinition($startMeta);
assertTest($startDef['body']['count'] === 5, "faculty_session_start: body count is strictly 5");
assertTest($startDef['body']['indexes'] === [1, 2, 3, 4, 5], "faculty_session_start: body indexes [1, 2, 3, 4, 5]");
assertTest($startDef['button_url']['has_variable'] === true, "faculty_session_start: button URL has variable");
assertTest($startDef['button_url']['indexes'] === [1], "faculty_session_start: button URL index [1]");

// 4. faculty_session_start_now (1 body param: Name + 1 dynamic URL button param {{1}})
$startNowMeta = json_encode([
    'body_text' => "Hi *{{1}}*,  \n\n✅ *Your PEPP live session is starting now.*\n_Please join your session now and begin the session as scheduled._",
    'buttons' => [
        ['type' => 'URL', 'text' => 'Start Now', 'url' => 'https://meet.google.com/{{1}}']
    ]
]);
$startNowDef = CommunicationHelper::getTemplateParameterDefinition($startNowMeta);
assertTest($startNowDef['body']['count'] === 1, "faculty_session_start_now: body count is strictly 1");
assertTest($startNowDef['body']['indexes'] === [1], "faculty_session_start_now: body indexes [1]");
assertTest($startNowDef['button_url']['has_variable'] === true, "faculty_session_start_now: button URL has variable");
assertTest($startNowDef['button_url']['indexes'] === [1], "faculty_session_start_now: button URL index [1]");

// 5. faculty_session_cancelled (4 body params: Name, Datetime, Topic, Courses)
$cancelledMeta = json_encode([
    'body_text' => "Hi *{{1}}*,  \nYour PEPP live session scheduled for *{{2}}* has been cancelled. \n\nSession: {{3}}\nCourses: {{4}} \n\nPlease do not join the previously shared session link.",
    'buttons' => []
]);
$cancelledDef = CommunicationHelper::getTemplateParameterDefinition($cancelledMeta);
assertTest($cancelledDef['body']['count'] === 4, "faculty_session_cancelled: body count is strictly 4");
assertTest($cancelledDef['body']['indexes'] === [1, 2, 3, 4], "faculty_session_cancelled: body indexes [1, 2, 3, 4]");
assertTest($cancelledDef['button_url']['has_variable'] === false, "faculty_session_cancelled: no button URL variable");


// --- SECTION 2: Parameter Interpolation Integrity ---
echo "\n--- SECTION 2: Parameter Interpolation Integrity ---\n";

$erpContext = [
    'faculty_name' => 'Dr. A. Sharma',
    'session_topic' => 'Advanced Microeconomics',
    'session_type' => 'Live Lecture',
    'session_datetime' => '10 Oct 2026, 04:00 PM',
    'session_courses' => 'UGC NET Commerce, Kerala SET',
    'session_duration' => '90 mins',
    'google_meet_url' => 'https://meet.google.com/abc-defg-hij'
];

// 1. Scheduled Mapping (6 params: Type at 2, Topic at 3)
$scheduledMapping = [
    1 => ['type' => 'variable', 'value' => 'faculty_name'],
    2 => ['type' => 'variable', 'value' => 'session_type'],
    3 => ['type' => 'variable', 'value' => 'session_topic'],
    4 => ['type' => 'variable', 'value' => 'session_datetime'],
    5 => ['type' => 'variable', 'value' => 'session_courses'],
    6 => ['type' => 'variable', 'value' => 'session_duration'],
];
$resolvedScheduled = CommunicationHelper::interpolateERPVariables($scheduledMapping, $erpContext);
assertTest(count($resolvedScheduled) === 6, "Interpolated scheduled params count = 6");
assertTest($resolvedScheduled[0] === 'Dr. A. Sharma', "Scheduled param 1 = Dr. A. Sharma");
assertTest($resolvedScheduled[1] === 'Live Lecture', "Scheduled param 2 = Live Lecture (Type)");
assertTest($resolvedScheduled[2] === 'Advanced Microeconomics', "Scheduled param 3 = Advanced Microeconomics (Topic)");
assertTest($resolvedScheduled[3] === '10 Oct 2026, 04:00 PM', "Scheduled param 4 = 10 Oct 2026, 04:00 PM");
assertTest($resolvedScheduled[4] === 'UGC NET Commerce, Kerala SET', "Scheduled param 5 = UGC NET Commerce, Kerala SET");
assertTest($resolvedScheduled[5] === '90 mins', "Scheduled param 6 = 90 mins");

// 2. Reminder Mapping (2 params: Name, Datetime)
$reminderMapping = [
    1 => ['type' => 'variable', 'value' => 'faculty_name'],
    2 => ['type' => 'variable', 'value' => 'session_datetime'],
];
$resolvedReminder = CommunicationHelper::interpolateERPVariables($reminderMapping, $erpContext);
assertTest(count($resolvedReminder) === 2, "Interpolated reminder params count = 2");
assertTest($resolvedReminder[0] === 'Dr. A. Sharma', "Reminder param 1 = Dr. A. Sharma");
assertTest($resolvedReminder[1] === '10 Oct 2026, 04:00 PM', "Reminder param 2 = 10 Oct 2026, 04:00 PM");

// 3. Start Notice Mapping (5 body params: Name, Datetime, Topic, Courses, Duration)
$startMapping = [
    1 => ['type' => 'variable', 'value' => 'faculty_name'],
    2 => ['type' => 'variable', 'value' => 'session_datetime'],
    3 => ['type' => 'variable', 'value' => 'session_topic'],
    4 => ['type' => 'variable', 'value' => 'session_courses'],
    5 => ['type' => 'variable', 'value' => 'session_duration'],
];
$resolvedStart = CommunicationHelper::interpolateERPVariables($startMapping, $erpContext);
assertTest(count($resolvedStart) === 5, "Interpolated start notice body params count = 5");
assertTest($resolvedStart[0] === 'Dr. A. Sharma', "Start param 1 = Dr. A. Sharma");
assertTest($resolvedStart[1] === '10 Oct 2026, 04:00 PM', "Start param 2 = 10 Oct 2026, 04:00 PM");
assertTest($resolvedStart[2] === 'Advanced Microeconomics', "Start param 3 = Advanced Microeconomics");
assertTest($resolvedStart[3] === 'UGC NET Commerce, Kerala SET', "Start param 4 = UGC NET Commerce, Kerala SET");
assertTest($resolvedStart[4] === '90 mins', "Start param 5 = 90 mins");

// 4. Start Now Notice Mapping (1 body param: Name)
$startNowMapping = [
    1 => ['type' => 'variable', 'value' => 'faculty_name'],
];
$resolvedStartNow = CommunicationHelper::interpolateERPVariables($startNowMapping, $erpContext);
assertTest(count($resolvedStartNow) === 1, "Interpolated start now body params count = 1");
assertTest($resolvedStartNow[0] === 'Dr. A. Sharma', "Start Now param 1 = Dr. A. Sharma");

// 5. Cancelled Notice Mapping (4 body params: Name, Datetime, Topic, Courses)
$cancelledMapping = [
    1 => ['type' => 'variable', 'value' => 'faculty_name'],
    2 => ['type' => 'variable', 'value' => 'session_datetime'],
    3 => ['type' => 'variable', 'value' => 'session_topic'],
    4 => ['type' => 'variable', 'value' => 'session_courses'],
];
$resolvedCancelled = CommunicationHelper::interpolateERPVariables($cancelledMapping, $erpContext);
assertTest(count($resolvedCancelled) === 4, "Interpolated cancelled body params count = 4");
assertTest($resolvedCancelled[0] === 'Dr. A. Sharma', "Cancelled param 1 = Dr. A. Sharma");
assertTest($resolvedCancelled[1] === '10 Oct 2026, 04:00 PM', "Cancelled param 2 = 10 Oct 2026, 04:00 PM");
assertTest($resolvedCancelled[2] === 'Advanced Microeconomics', "Cancelled param 3 = Advanced Microeconomics");
assertTest($resolvedCancelled[3] === 'UGC NET Commerce, Kerala SET', "Cancelled param 4 = UGC NET Commerce, Kerala SET");


// --- SECTION 3: WhatsApp Cloud Provider Payload Construction ---
echo "\n--- SECTION 3: WhatsApp Cloud Provider Payload Construction ---\n";

$provider = new WhatsAppCloudProvider('0987654321', '1234567890', 'test-token');

// Test 1: Scheduled template payload (6 body params)
$scheduledPayload = $provider->buildMessagePayload(
    '+919876543210',
    '',
    '',
    '',
    [],
    [
        'name' => 'faculty_session_scheduled',
        'language' => 'en',
        'parameters' => $resolvedScheduled
    ]
);
assertTest($scheduledPayload['type'] === 'template', "Scheduled payload type is template");
assertTest($scheduledPayload['template']['name'] === 'faculty_session_scheduled', "Scheduled template name matches");
assertTest(count($scheduledPayload['template']['components']) === 1, "Scheduled components has 1 element (body)");
assertTest(count($scheduledPayload['template']['components'][0]['parameters']) === 6, "Scheduled body parameters count is 6");

// Test 2: Reminder template payload (2 body params)
$reminderPayload = $provider->buildMessagePayload(
    '+919876543210',
    '',
    '',
    '',
    [],
    [
        'name' => 'faculty_session_reminder',
        'language' => 'en',
        'parameters' => $resolvedReminder
    ]
);
assertTest($reminderPayload['type'] === 'template', "Reminder payload type is template");
assertTest($reminderPayload['template']['name'] === 'faculty_session_reminder', "Reminder template name matches");
assertTest(count($reminderPayload['template']['components']) === 1, "Reminder components has 1 element (body)");
assertTest(count($reminderPayload['template']['components'][0]['parameters']) === 2, "Reminder body parameters count is 2");

// Test 3: Start template payload with dynamic CTA URL button (5 body params + 1 URL button param)
$startPayload = $provider->buildMessagePayload(
    '+919876543210',
    '',
    '',
    '',
    [],
    [
        'name' => 'faculty_session_start',
        'language' => 'en',
        'parameters' => $resolvedStart,
        'button_url_parameter' => 'abc-defg-hij'
    ]
);
assertTest($startPayload['type'] === 'template', "Start payload type is template");
assertTest(count($startPayload['template']['components']) === 2, "Start template components count = 2 (body + button)");

$bodyComp = null;
$buttonComp = null;
foreach ($startPayload['template']['components'] as $c) {
    if ($c['type'] === 'body') $bodyComp = $c;
    if ($c['type'] === 'button') $buttonComp = $c;
}
assertTest($bodyComp !== null, "Body component present in start payload");
assertTest(count($bodyComp['parameters']) === 5, "Start body parameters count is strictly 5 (no bleed from button param)");
assertTest($buttonComp !== null, "Button component present in start payload");
assertTest($buttonComp['sub_type'] === 'url', "Button sub_type is url");
assertTest($buttonComp['index'] === '0', "Button index is '0'");
assertTest($buttonComp['parameters'][0]['type'] === 'text', "Button parameter type is text");
assertTest($buttonComp['parameters'][0]['text'] === 'abc-defg-hij', "Button parameter text is 'abc-defg-hij'");

// Test 4: Start Now template payload with dynamic CTA URL button (1 body param + 1 URL button param)
$startNowPayload = $provider->buildMessagePayload(
    '+919876543210',
    '',
    '',
    '',
    [],
    [
        'name' => 'faculty_session_start_now',
        'language' => 'en',
        'parameters' => $resolvedStartNow,
        'button_url_parameter' => 'abc-defg-hij'
    ]
);
assertTest($startNowPayload['type'] === 'template', "Start Now payload type is template");
assertTest(count($startNowPayload['template']['components']) === 2, "Start Now template components count = 2 (body + button)");

$bodyNowComp = null;
$buttonNowComp = null;
foreach ($startNowPayload['template']['components'] as $c) {
    if ($c['type'] === 'body') $bodyNowComp = $c;
    if ($c['type'] === 'button') $buttonNowComp = $c;
}
assertTest($bodyNowComp !== null, "Body component present in Start Now payload");
assertTest(count($bodyNowComp['parameters']) === 1, "Start Now body parameters count is strictly 1 (no bleed from button param)");
assertTest($buttonNowComp !== null, "Button component present in Start Now payload");
assertTest($buttonNowComp['sub_type'] === 'url', "Start Now button sub_type is url");
assertTest($buttonNowComp['index'] === '0', "Start Now button index is '0'");
assertTest($buttonNowComp['parameters'][0]['text'] === 'abc-defg-hij', "Start Now button parameter text is 'abc-defg-hij'");

// Test 5: Cancelled template payload (4 body params)
$cancelledPayload = $provider->buildMessagePayload(
    '+919876543210',
    '',
    '',
    '',
    [],
    [
        'name' => 'faculty_session_cancelled',
        'language' => 'en',
        'parameters' => $resolvedCancelled
    ]
);
assertTest($cancelledPayload['type'] === 'template', "Cancelled payload type is template");
assertTest(count($cancelledPayload['template']['components']) === 1, "Cancelled components has 1 element (body)");
assertTest(count($cancelledPayload['template']['components'][0]['parameters']) === 4, "Cancelled body parameters count is 4");


// --- SECTION 4: Non-Inflation & UI Canonical Alignment Checks ---
echo "\n--- SECTION 4: Non-Inflation & UI Canonical Alignment Checks ---\n";

// Ensure CTA URL variables never inflate body parameter count
$testDynamicUrlRawMeta = json_encode([
    'body_text' => 'Dear {{1}}, your live session starts in 1 hour. Session: {{2}}, Date & Time: {{3}}, Duration: {{4}}.',
    'buttons' => [
        ['type' => 'URL', 'text' => 'Join Now', 'url' => 'https://meet.google.com/{{1}}']
    ]
]);
$dynamicDef = CommunicationHelper::getTemplateParameterDefinition($testDynamicUrlRawMeta);
assertTest($dynamicDef['body']['count'] === 4, "Body count remains strictly 4 despite button containing {{1}}");
assertTest($dynamicDef['button_url']['has_variable'] === true, "button_url detects variable separately");
assertTest($dynamicDef['button_url']['indexes'] === [1], "button_url indexes is [1]");

// Test with no button variable
$noButtonMeta = json_encode([
    'body_text' => 'Dear {{1}}, {{2}}, {{3}}.',
    'buttons' => [
        ['type' => 'URL', 'text' => 'Visit PEPP', 'url' => 'https://pepponline.in/']
    ]
]);
$noButtonDef = CommunicationHelper::getTemplateParameterDefinition($noButtonMeta);
assertTest($noButtonDef['body']['count'] === 3, "Body count is 3");
assertTest($noButtonDef['button_url']['has_variable'] === false, "Static URL has no variable");


// --- SECTION 5: Service Canonical Meta Variable Map & Generated Array Integrity ---
echo "\n--- SECTION 5: Service Canonical Meta Variable Map & Generated Array Integrity ---\n";

// Create SQLite in-memory PDO to test FacultySessionNotificationService parameter resolution
$memPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$memPdo->exec("
    CREATE TABLE communication_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_name TEXT NOT NULL,
        meta_data TEXT
    );
");

// Insert authoritative Meta-synced metadata into test database
$metaSeed = [
    'faculty_session_scheduled' => $scheduledMeta,
    'faculty_session_reminder'  => $reminderMeta,
    'faculty_session_start'     => $startMeta,
    'faculty_session_start_now' => $startNowMeta,
    'faculty_session_cancelled' => $cancelledMeta
];
$insMetaStmt = $memPdo->prepare("INSERT INTO communication_templates (template_name, meta_data) VALUES (?, ?)");
foreach ($metaSeed as $tName => $mData) {
    $insMetaStmt->execute([$tName, $mData]);
}

$service = new FacultySessionNotificationService($memPdo);

$testContext = [
    'faculty_name'     => 'Dr. Arshad Khan',
    'session_type'     => 'Live',
    'session_topic'    => 'Corporate Law & Ethics',
    'session_datetime' => '15 Nov 2026, 11:00 AM',
    'session_courses'  => 'B.Com, BBA, LLB',
    'session_duration' => '2 hours'
];

// 1. Scheduled array: [faculty_name, session_type, session_topic, session_datetime, session_courses, session_duration]
$genScheduled = $service->resolveTemplateParameters('faculty_session_scheduled', $testContext);
assertTest(count($genScheduled) === 6, "Service scheduled array has exactly 6 elements");
assertTest($genScheduled === [
    'Dr. Arshad Khan',
    'Live',
    'Corporate Law & Ethics',
    '15 Nov 2026, 11:00 AM',
    'B.Com, BBA, LLB',
    '2 hours'
], "Service scheduled array matches exact Meta specification (Type before Topic)");

// 2. Reminder array: [faculty_name, session_datetime]
$genReminder = $service->resolveTemplateParameters('faculty_session_reminder', $testContext);
assertTest(count($genReminder) === 2, "Service reminder array has exactly 2 elements");
assertTest($genReminder === [
    'Dr. Arshad Khan',
    '15 Nov 2026, 11:00 AM'
], "Service reminder array matches exact Meta specification (only Name & Datetime, no topic/duration)");

// 3. Start array: [faculty_name, session_datetime, session_topic, session_courses, session_duration]
$genStart = $service->resolveTemplateParameters('faculty_session_start', $testContext);
assertTest(count($genStart) === 5, "Service start array has exactly 5 elements");
assertTest($genStart === [
    'Dr. Arshad Khan',
    '15 Nov 2026, 11:00 AM',
    'Corporate Law & Ethics',
    'B.Com, BBA, LLB',
    '2 hours'
], "Service start array matches exact Meta specification (5 body params)");

// 4. Start Now array: [faculty_name]
$genStartNow = $service->resolveTemplateParameters('faculty_session_start_now', $testContext);
assertTest(count($genStartNow) === 1, "Service start_now array has exactly 1 element");
assertTest($genStartNow === [
    'Dr. Arshad Khan'
], "Service start_now array matches exact Meta specification (only Name)");

// 5. Cancelled array: [faculty_name, session_datetime, session_topic, session_courses]
$genCancelled = $service->resolveTemplateParameters('faculty_session_cancelled', $testContext);
assertTest(count($genCancelled) === 4, "Service cancelled array has exactly 4 elements");
assertTest($genCancelled === [
    'Dr. Arshad Khan',
    '15 Nov 2026, 11:00 AM',
    'Corporate Law & Ethics',
    'B.Com, BBA, LLB'
], "Service cancelled array matches exact Meta specification (4 params including courses)");

// 6. Test fallback resolution when database table has no meta_data
$emptyPdo = new PDO('sqlite::memory:');
$fallbackService = new FacultySessionNotificationService($emptyPdo);
$fallbackScheduled = $fallbackService->resolveTemplateParameters('faculty_session_scheduled', $testContext);
assertTest($fallbackScheduled === $genScheduled, "Fallback resolution produces identical 6-element scheduled array");
$fallbackReminder = $fallbackService->resolveTemplateParameters('faculty_session_reminder', $testContext);
assertTest($fallbackReminder === $genReminder, "Fallback resolution produces identical 2-element reminder array");
$fallbackStart = $fallbackService->resolveTemplateParameters('faculty_session_start', $testContext);
assertTest($fallbackStart === $genStart, "Fallback resolution produces identical 5-element start array");
$fallbackStartNow = $fallbackService->resolveTemplateParameters('faculty_session_start_now', $testContext);
assertTest($fallbackStartNow === $genStartNow, "Fallback resolution produces identical 1-element start_now array");
$fallbackCancelled = $fallbackService->resolveTemplateParameters('faculty_session_cancelled', $testContext);
assertTest($fallbackCancelled === $genCancelled, "Fallback resolution produces identical 4-element cancelled array");

echo "\n========================================================================\n";
echo " AUDIT SUMMARY: {$passed} / {$total} TESTS PASSED\n";
if ($passed === $total) {
    echo " STATUS: PARAMETER RESOLUTION & DYNAMIC BUTTON URL AUDIT 100% SUCCESSFUL\n";
} else {
    echo " STATUS: FAILURES DETECTED\n";
}
echo "========================================================================\n";
