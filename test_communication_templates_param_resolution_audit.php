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
$scheduledMeta = json_encode([
    'body_text' => "Hello {{1}},\n\nYou have been scheduled for a PEPP Live Session.\n\nSession: {{2}}\nType: {{3}}\nDate & Time: {{4}}\nCourses: {{5}}\nDuration: {{6}}\n\nPlease review the faculty instructions before the session. You are requested to join on time and not earlier than 5 minutes before the scheduled start.",
    'buttons' => [
        ['type' => 'QUICK_REPLY', 'text' => 'Read Instructions']
    ]
]);
$scheduledDef = CommunicationHelper::getTemplateParameterDefinition($scheduledMeta);
assertTest($scheduledDef['body']['count'] === 6, "faculty_session_scheduled: body count is 6");
assertTest($scheduledDef['body']['indexes'] === [1, 2, 3, 4, 5, 6], "faculty_session_scheduled: body indexes [1, 2, 3, 4, 5, 6]");
assertTest($scheduledDef['button_url']['has_variable'] === false, "faculty_session_scheduled: no button URL variable");

// 2. faculty_session_reminder (4 body params: Name, Topic, Time, Duration)
$reminderMeta = json_encode([
    'body_text' => "Hello {{1}},\n\nThis is a reminder that you have a PEPP Live Session scheduled today.\n\nSession: {{2}}\nTime: {{3}}\nDuration: {{4}}\n\nWe hope you are prepared well for the session.\n\nPlease ensure that your presentation, audio/video setup and internet connection are ready before the scheduled time.\n\nThank you!",
    'buttons' => []
]);
$reminderDef = CommunicationHelper::getTemplateParameterDefinition($reminderMeta);
assertTest($reminderDef['body']['count'] === 4, "faculty_session_reminder: body count is strictly 4");
assertTest($reminderDef['body']['indexes'] === [1, 2, 3, 4], "faculty_session_reminder: body indexes [1, 2, 3, 4]");
assertTest($reminderDef['button_url']['has_variable'] === false, "faculty_session_reminder: no button URL variable");

// 3. faculty_session_start (4 body params + 1 dynamic URL button param {{1}})
$startMeta = json_encode([
    'body_text' => "Hello {{1}},\n\nYour PEPP Live Session starts in approximately 1 hour.\n\nSession: {{2}}\nDate & Time: {{3}}\nDuration: {{4}}\n\nYour Google Meet session link is ready.\n\nImportant: Recording and Gemini meeting notes will start automatically when you enter the session. Please do not enter earlier than 5 minutes before the scheduled start time.",
    'buttons' => [
        ['type' => 'URL', 'text' => 'Start Live', 'url' => 'https://meet.google.com/{{1}}']
    ]
]);
$startDef = CommunicationHelper::getTemplateParameterDefinition($startMeta);
assertTest($startDef['body']['count'] === 4, "faculty_session_start: body count is strictly 4");
assertTest($startDef['body']['indexes'] === [1, 2, 3, 4], "faculty_session_start: body indexes [1, 2, 3, 4]");
assertTest($startDef['button_url']['has_variable'] === true, "faculty_session_start: button URL has variable");
assertTest($startDef['button_url']['indexes'] === [1], "faculty_session_start: button URL index [1]");

// 4. faculty_session_start_now (2 body params + 1 dynamic URL button param {{1}})
$startNowMeta = json_encode([
    'body_text' => "Hello {{1}},\n\nYour PEPP Live Session is starting now.\n\nSession: {{2}}\n\nPlease join using the button below.\n\nReminder: Recording and Gemini meeting notes will start automatically when you enter the session. Please join only now and not earlier.",
    'buttons' => [
        ['type' => 'URL', 'text' => 'Start Now', 'url' => 'https://meet.google.com/{{1}}']
    ]
]);
$startNowDef = CommunicationHelper::getTemplateParameterDefinition($startNowMeta);
assertTest($startNowDef['body']['count'] === 2, "faculty_session_start_now: body count is strictly 2");
assertTest($startNowDef['body']['indexes'] === [1, 2], "faculty_session_start_now: body indexes [1, 2]");
assertTest($startNowDef['button_url']['has_variable'] === true, "faculty_session_start_now: button URL has variable");
assertTest($startNowDef['button_url']['indexes'] === [1], "faculty_session_start_now: button URL index [1]");

// 5. faculty_session_cancelled (3 body params: Name, Datetime, Topic)
$cancelledMeta = json_encode([
    'body_text' => "Hello {{1}},\n\nYour PEPP Live Session scheduled for:\n\n{{2}}\n\nSession: {{3}}\n\nhas been cancelled by the PEPP Admin.\n\nPlease do not use the previously shared session link.\n\nIf a new schedule is confirmed, you will receive a separate notification.\n\nThank you.",
    'buttons' => []
]);
$cancelledDef = CommunicationHelper::getTemplateParameterDefinition($cancelledMeta);
assertTest($cancelledDef['body']['count'] === 3, "faculty_session_cancelled: body count is strictly 3");
assertTest($cancelledDef['body']['indexes'] === [1, 2, 3], "faculty_session_cancelled: body indexes [1, 2, 3]");
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

// 1. Scheduled Mapping (6 params)
$scheduledMapping = [
    1 => ['type' => 'variable', 'value' => 'faculty_name'],
    2 => ['type' => 'variable', 'value' => 'session_topic'],
    3 => ['type' => 'variable', 'value' => 'session_type'],
    4 => ['type' => 'variable', 'value' => 'session_datetime'],
    5 => ['type' => 'variable', 'value' => 'session_courses'],
    6 => ['type' => 'variable', 'value' => 'session_duration'],
];
$resolvedScheduled = CommunicationHelper::interpolateERPVariables($scheduledMapping, $erpContext);
assertTest(count($resolvedScheduled) === 6, "Interpolated scheduled params count = 6");
assertTest($resolvedScheduled[0] === 'Dr. A. Sharma', "Scheduled param 1 = Dr. A. Sharma");
assertTest($resolvedScheduled[1] === 'Advanced Microeconomics', "Scheduled param 2 = Advanced Microeconomics");
assertTest($resolvedScheduled[2] === 'Live Lecture', "Scheduled param 3 = Live Lecture");
assertTest($resolvedScheduled[3] === '10 Oct 2026, 04:00 PM', "Scheduled param 4 = 10 Oct 2026, 04:00 PM");
assertTest($resolvedScheduled[4] === 'UGC NET Commerce, Kerala SET', "Scheduled param 5 = UGC NET Commerce, Kerala SET");
assertTest($resolvedScheduled[5] === '90 mins', "Scheduled param 6 = 90 mins");

// 2. Reminder Mapping (4 params)
$reminderMapping = [
    1 => ['type' => 'variable', 'value' => 'faculty_name'],
    2 => ['type' => 'variable', 'value' => 'session_topic'],
    3 => ['type' => 'variable', 'value' => 'session_datetime'],
    4 => ['type' => 'variable', 'value' => 'session_duration'],
];
$resolvedReminder = CommunicationHelper::interpolateERPVariables($reminderMapping, $erpContext);
assertTest(count($resolvedReminder) === 4, "Interpolated reminder params count = 4");
assertTest($resolvedReminder[0] === 'Dr. A. Sharma', "Reminder param 1 = Dr. A. Sharma");
assertTest($resolvedReminder[1] === 'Advanced Microeconomics', "Reminder param 2 = Advanced Microeconomics");
assertTest($resolvedReminder[2] === '10 Oct 2026, 04:00 PM', "Reminder param 3 = 10 Oct 2026, 04:00 PM");
assertTest($resolvedReminder[3] === '90 mins', "Reminder param 4 = 90 mins");

// 3. Start Notice Mapping (4 body params)
$startMapping = [
    1 => ['type' => 'variable', 'value' => 'faculty_name'],
    2 => ['type' => 'variable', 'value' => 'session_topic'],
    3 => ['type' => 'variable', 'value' => 'session_datetime'],
    4 => ['type' => 'variable', 'value' => 'session_duration'],
];
$resolvedStart = CommunicationHelper::interpolateERPVariables($startMapping, $erpContext);
assertTest(count($resolvedStart) === 4, "Interpolated start notice body params count = 4");
assertTest($resolvedStart[0] === 'Dr. A. Sharma', "Start param 1 = Dr. A. Sharma");
assertTest($resolvedStart[1] === 'Advanced Microeconomics', "Start param 2 = Advanced Microeconomics");
assertTest($resolvedStart[2] === '10 Oct 2026, 04:00 PM', "Start param 3 = 10 Oct 2026, 04:00 PM");
assertTest($resolvedStart[3] === '90 mins', "Start param 4 = 90 mins");

// 4. Start Now Notice Mapping (2 body params)
$startNowMapping = [
    1 => ['type' => 'variable', 'value' => 'faculty_name'],
    2 => ['type' => 'variable', 'value' => 'session_topic'],
];
$resolvedStartNow = CommunicationHelper::interpolateERPVariables($startNowMapping, $erpContext);
assertTest(count($resolvedStartNow) === 2, "Interpolated start now body params count = 2");
assertTest($resolvedStartNow[0] === 'Dr. A. Sharma', "Start Now param 1 = Dr. A. Sharma");
assertTest($resolvedStartNow[1] === 'Advanced Microeconomics', "Start Now param 2 = Advanced Microeconomics");

// 5. Cancelled Notice Mapping (3 body params)
$cancelledMapping = [
    1 => ['type' => 'variable', 'value' => 'faculty_name'],
    2 => ['type' => 'variable', 'value' => 'session_datetime'],
    3 => ['type' => 'variable', 'value' => 'session_topic'],
];
$resolvedCancelled = CommunicationHelper::interpolateERPVariables($cancelledMapping, $erpContext);
assertTest(count($resolvedCancelled) === 3, "Interpolated cancelled body params count = 3");
assertTest($resolvedCancelled[0] === 'Dr. A. Sharma', "Cancelled param 1 = Dr. A. Sharma");
assertTest($resolvedCancelled[1] === '10 Oct 2026, 04:00 PM', "Cancelled param 2 = 10 Oct 2026, 04:00 PM");
assertTest($resolvedCancelled[2] === 'Advanced Microeconomics', "Cancelled param 3 = Advanced Microeconomics");


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

// Test 2: Reminder template payload (4 body params)
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
assertTest(count($reminderPayload['template']['components'][0]['parameters']) === 4, "Reminder body parameters count is 4");

// Test 3: Start template payload with dynamic CTA URL button (4 body params + 1 URL button param)
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
assertTest(count($bodyComp['parameters']) === 4, "Start body parameters count is strictly 4 (no bleed from button param)");
assertTest($buttonComp !== null, "Button component present in start payload");
assertTest($buttonComp['sub_type'] === 'url', "Button sub_type is url");
assertTest($buttonComp['index'] === '0', "Button index is '0'");
assertTest($buttonComp['parameters'][0]['type'] === 'text', "Button parameter type is text");
assertTest($buttonComp['parameters'][0]['text'] === 'abc-defg-hij', "Button parameter text is 'abc-defg-hij'");

// Test 4: Start Now template payload with dynamic CTA URL button (2 body params + 1 URL button param)
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
assertTest(count($bodyNowComp['parameters']) === 2, "Start Now body parameters count is strictly 2 (no bleed from button param)");
assertTest($buttonNowComp !== null, "Button component present in Start Now payload");
assertTest($buttonNowComp['sub_type'] === 'url', "Start Now button sub_type is url");
assertTest($buttonNowComp['index'] === '0', "Start Now button index is '0'");
assertTest($buttonNowComp['parameters'][0]['text'] === 'abc-defg-hij', "Start Now button parameter text is 'abc-defg-hij'");

// Test 5: Cancelled template payload (3 body params)
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
assertTest(count($cancelledPayload['template']['components'][0]['parameters']) === 3, "Cancelled body parameters count is 3");


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

echo "\n========================================================================\n";
echo " AUDIT SUMMARY: {$passed} / {$total} TESTS PASSED\n";
if ($passed === $total) {
    echo " STATUS: PARAMETER RESOLUTION & DYNAMIC BUTTON URL AUDIT 100% SUCCESSFUL\n";
} else {
    echo " STATUS: FAILURES DETECTED\n";
}
echo "========================================================================\n";
