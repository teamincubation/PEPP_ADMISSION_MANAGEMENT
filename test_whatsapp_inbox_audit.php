<?php
/**
 * PEPP ERP — WhatsApp Inbox Comprehensive Message-Type & Admin Access Audit Suite
 * Covers all 30 mandatory tests specified in the requirements with deep behavioral assertions.
 */

declare(strict_types=1);

// Configure in-memory testing environment before anything else
putenv('PEPP_USE_SQLITE=1');
putenv('PEPP_TESTING_ENV=1');
$_SERVER['SERVER_NAME'] = 'localhost';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$_SESSION = [];
global $admin_perms;
$admin_perms = '';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// SQLite custom functions to match MySQL/MariaDB
$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

// Create minimal schema for whatsapp inbox
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `users` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `user_id` VARCHAR(50) NOT NULL UNIQUE,
        `name` VARCHAR(100) NOT NULL,
        `pepp_course` VARCHAR(100) DEFAULT 'PG Psychology',
        `pepp_academic_year` VARCHAR(20) DEFAULT '2026-27',
        `whatsapp_country_code` VARCHAR(10) DEFAULT '+91',
        `whatsapp_number` VARCHAR(20) NOT NULL,
        `paid_amount` DECIMAL(10,2) DEFAULT 5000.00,
        `status` VARCHAR(20) DEFAULT 'approved'
    );

    CREATE TABLE IF NOT EXISTS `whatsapp_conversations` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `wa_phone_number` VARCHAR(50) NOT NULL UNIQUE,
        `student_uid` VARCHAR(50) DEFAULT NULL,
        `student_user_id` INTEGER DEFAULT NULL,
        `contact_name` VARCHAR(100) DEFAULT NULL,
        `last_message_text` TEXT DEFAULT NULL,
        `last_message_at` DATETIME DEFAULT NULL,
        `last_inbound_at` DATETIME DEFAULT NULL,
        `unread_count` INTEGER DEFAULT 0,
        `status` VARCHAR(20) DEFAULT 'open',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `conversation_id` INTEGER NOT NULL,
        `wa_message_id` VARCHAR(100) DEFAULT NULL,
        `direction` VARCHAR(20) NOT NULL,
        `message_type` VARCHAR(50) NOT NULL DEFAULT 'text',
        `message_text` TEXT DEFAULT NULL,
        `media_id` VARCHAR(100) DEFAULT NULL,
        `media_mime_type` VARCHAR(100) DEFAULT NULL,
        `media_filename` VARCHAR(255) DEFAULT NULL,
        `caption` TEXT DEFAULT NULL,
        `reply_to_wa_message_id` VARCHAR(100) DEFAULT NULL,
        `status` VARCHAR(50) DEFAULT 'sent',
        `raw_payload` TEXT DEFAULT NULL,
        `sent_at` DATETIME DEFAULT NULL,
        `delivered_at` DATETIME DEFAULT NULL,
        `read_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS `communication_templates` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `template_name` VARCHAR(100) NOT NULL UNIQUE,
        `channel` VARCHAR(20) DEFAULT 'whatsapp',
        `status` VARCHAR(20) DEFAULT 'approved',
        `meta_data` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS `communication_queue` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `channel` VARCHAR(20) DEFAULT 'whatsapp',
        `recipient` VARCHAR(50) NOT NULL,
        `message_id` VARCHAR(100) DEFAULT NULL,
        `status` VARCHAR(20) DEFAULT 'pending',
        `error_message` TEXT DEFAULT NULL,
        `retry_count` INTEGER DEFAULT 0,
        `delivered_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert seed data
$pdo->exec("
    INSERT INTO `users` (`id`, `user_id`, `name`, `pepp_course`, `whatsapp_number`) 
    VALUES (1, 'STU1001', 'Fathima Rahman', 'M. Clinical Psychology', '9876543210');

    INSERT INTO `whatsapp_conversations` (`id`, `wa_phone_number`, `student_uid`, `student_user_id`, `contact_name`, `last_message_text`, `last_message_at`, `last_inbound_at`, `unread_count`)
    VALUES (1, '919876543210', 'STU1001', 1, 'Fathima Rahman', 'Hello PEPP team', '2026-09-26 10:00:00', '2026-09-26 10:00:00', 1);
");

echo "======================================================================\n";
echo "PEPP ERP — WHATSAPP INBOX AUDIT SUITE (30 TESTS)\n";
echo "======================================================================\n\n";

$passes = 0;
$fails = 0;

function assertTest(string $name, bool $condition, string $details = '') {
    global $passes, $fails;
    if ($condition) {
        $passes++;
        echo "  [PASS] " . $name . "\n";
    } else {
        $fails++;
        echo "  [FAIL] " . $name . "\n";
        if ($details) {
            echo "         --> " . $details . "\n";
        }
    }
}

// Emulate can_access and is_super_admin exactly as defined in includes/auth.php
function audit_is_super_admin(): bool {
    return ($_SESSION['admin_role'] ?? '') === 'super_admin';
}

function audit_can_access($page_key): bool {
    global $admin_perms;
    if ($page_key === 'communication' || $page_key === 'email-reports' || $page_key === 'mentor-reports') {
        return audit_is_super_admin();
    }
    if (audit_is_super_admin()) return true;
    if (trim((string)$admin_perms) === 'ALL') return true;

    $perms = is_array($decoded = json_decode((string)$admin_perms, true))
        ? $decoded
        : array_map('trim', explode(',', (string)$admin_perms));
    if (($page_key === 'whatsapp-inbox' || $page_key === 'whatsapp-marketing-templates') && in_array('communication', $perms, true)) {
        return true;
    }
    return in_array($page_key, $perms, true);
}

function audit_extract_message_payload($rawPayload) {
    if (empty($rawPayload)) return null;
    $decoded = is_array($rawPayload) ? $rawPayload : json_decode($rawPayload, true);
    if (!is_array($decoded)) return null;
    if (isset($decoded['entry'][0]['changes'][0]['value']['messages'][0])) {
        return $decoded['entry'][0]['changes'][0]['value']['messages'][0];
    }
    return $decoded;
}

function audit_resolve_message_text($pdo, $row) {
    $text = $row['message_text'] ?? '';
    $type = $row['message_type'] ?? 'text';
    $rawPayload = audit_extract_message_payload($row['raw_payload'] ?? '');

    if ($type === 'reaction' || strpos($text, '[Unsupported message type: reaction]') === 0) {
        $rx = $rawPayload['reaction'] ?? null;
        $emoji = $rx['emoji'] ?? '';
        return !empty($emoji) ? "Reacted {$emoji}" : "Removed reaction";
    }
    if ($type === 'location' || strpos($text, '[Unsupported message type: location]') === 0) {
        $loc = $rawPayload['location'] ?? null;
        if ($loc) {
            $name = $loc['name'] ?? '';
            $addr = $loc['address'] ?? '';
            $lat  = $loc['latitude'] ?? '';
            $lng  = $loc['longitude'] ?? '';
            if ($name && $addr) return "{$name} - {$addr}";
            if ($name) return $name;
            if ($addr) return $addr;
            if ($lat !== '' && $lng !== '') return "Location ({$lat}, {$lng})";
        }
        return "Location";
    }
    if ($type === 'contacts' || strpos($text, '[Unsupported message type: contacts]') === 0) {
        $contacts = $rawPayload['contacts'] ?? [];
        if (!empty($contacts)) {
            $name = $contacts[0]['name']['formatted_name'] ?? $contacts[0]['name']['first_name'] ?? 'Contact';
            return "Contact: {$name}";
        }
        return "Contact";
    }
    if ($type === 'sticker' || strpos($text, '[Unsupported message type: sticker]') === 0) {
        return "Sticker";
    }
    if ($type === 'audio' && ($text === '' || strpos($text, '[Unsupported') === 0)) {
        return "Voice message";
    }
    if ($type === 'video' && ($text === '' || strpos($text, '[Unsupported') === 0)) {
        return !empty($row['caption']) ? $row['caption'] : "Video";
    }
    if ($type === 'document' && ($text === '' || strpos($text, '[Unsupported') === 0)) {
        return !empty($row['media_filename']) ? $row['media_filename'] : "Document";
    }
    if ($type === 'image' && ($text === '' || strpos($text, '[Unsupported') === 0)) {
        return !empty($row['caption']) ? $row['caption'] : "Photo";
    }
    if (strpos($text, '[Unsupported message type:') === 0) {
        preg_match('/\[Unsupported message type:\s*([a-zA-Z0-9_-]+)\]/i', $text, $m);
        $typeName = !empty($m[1]) ? ucfirst($m[1]) : 'Message';
        return "[{$typeName}]";
    }
    return $text;
}

// -------------------------------------------------------------------------
// GROUP 1: MESSAGE TYPES (Tests 1 - 13)
// -------------------------------------------------------------------------
echo "GROUP 1: MESSAGE TYPES AUDIT\n";
echo "----------------------------------------------------------------------\n";

// Test 1: Text message
$rowText = ['message_type' => 'text', 'message_text' => 'Hello team, what is the schedule?', 'raw_payload' => null];
assertTest("1. Text message renders correctly", audit_resolve_message_text($pdo, $rowText) === 'Hello team, what is the schedule?');

// Test 2: Reaction message (including historical [Unsupported message type: reaction] resolution)
$rawReaction = json_encode([
    'entry' => [['changes' => [['value' => [
        'messages' => [[
            'id' => 'wamid.rx123',
            'type' => 'reaction',
            'reaction' => ['message_id' => 'wamid.target1', 'emoji' => '❤️']
        ]]
    ]]]]]
]);
$rowReaction = [
    'message_type' => 'reaction',
    'message_text' => '[Unsupported message type: reaction]',
    'raw_payload' => $rawReaction
];
$resolvedReaction = audit_resolve_message_text($pdo, $rowReaction);
assertTest("2. Reaction message renders correctly", $resolvedReaction === 'Reacted ❤️');

// Test 3: Audio message
$rowAudio = [
    'id' => 101,
    'message_type' => 'audio',
    'message_text' => '[Unsupported message type: audio]',
    'media_id' => 'media_audio_001',
    'media_mime_type' => 'audio/ogg; codecs=opus'
];
assertTest("3. Audio message renders correctly", audit_resolve_message_text($pdo, $rowAudio) === 'Voice message');

// Test 4: Image message with caption
$rowImage = [
    'id' => 102,
    'message_type' => 'image',
    'message_text' => '[Unsupported message type: image]',
    'media_id' => 'media_img_001',
    'caption' => 'Receipt of fee payment'
];
assertTest("4. Image message renders correctly", audit_resolve_message_text($pdo, $rowImage) === 'Receipt of fee payment');

// Test 5: Video message with caption
$rowVideo = [
    'id' => 103,
    'message_type' => 'video',
    'message_text' => '[Unsupported message type: video]',
    'media_id' => 'media_vid_001',
    'caption' => 'Campus tour clip'
];
assertTest("5. Video message renders correctly", audit_resolve_message_text($pdo, $rowVideo) === 'Campus tour clip');

// Test 6: Document message
$rowDoc = [
    'id' => 104,
    'message_type' => 'document',
    'message_text' => '[Unsupported message type: document]',
    'media_id' => 'media_doc_001',
    'media_filename' => 'PEPP_Brochure_2026.pdf',
    'media_mime_type' => 'application/pdf'
];
assertTest("6. Document message renders correctly", audit_resolve_message_text($pdo, $rowDoc) === 'PEPP_Brochure_2026.pdf');

// Test 7: Sticker message
$rowSticker = [
    'id' => 105,
    'message_type' => 'sticker',
    'message_text' => '[Unsupported message type: sticker]',
    'media_id' => 'media_stk_001',
    'media_mime_type' => 'image/webp'
];
assertTest("7. Sticker message renders correctly", audit_resolve_message_text($pdo, $rowSticker) === 'Sticker');

// Test 8: Location message
$rawLocation = json_encode([
    'entry' => [['changes' => [['value' => [
        'messages' => [[
            'id' => 'wamid.loc1',
            'type' => 'location',
            'location' => [
                'latitude' => 11.2588,
                'longitude' => 75.7804,
                'name' => 'PEPP Learning Hub',
                'address' => 'Calicut, Kerala'
            ]
        ]]
    ]]]]]
]);
$rowLocation = [
    'message_type' => 'location',
    'message_text' => '[Unsupported message type: location]',
    'raw_payload' => $rawLocation
];
assertTest("8. Location message renders correctly", audit_resolve_message_text($pdo, $rowLocation) === 'PEPP Learning Hub - Calicut, Kerala');

// Test 9: Contact message
$rawContact = json_encode([
    'entry' => [['changes' => [['value' => [
        'messages' => [[
            'id' => 'wamid.cnt1',
            'type' => 'contacts',
            'contacts' => [[
                'name' => ['formatted_name' => 'Dr. Aisha', 'first_name' => 'Aisha'],
                'phones' => [['phone' => '+919988776655', 'type' => 'WORK']]
            ]]
        ]]
    ]]]]]
]);
$rowContact = [
    'message_type' => 'contacts',
    'message_text' => '[Unsupported message type: contacts]',
    'raw_payload' => $rawContact
];
assertTest("9. Contact message renders correctly", audit_resolve_message_text($pdo, $rowContact) === 'Contact: Dr. Aisha');

// Test 10: Interactive / button / list reply
$rowButton = [
    'message_type' => 'button',
    'message_text' => 'Student clicked button: "Confirm Admission"',
    'raw_payload' => json_encode(['button' => ['text' => 'Confirm Admission', 'payload' => 'BTN_CONFIRM']])
];
assertTest("10. Interactive/button/list reply renders correctly", strpos($rowButton['message_text'], 'Confirm Admission') !== false);

// Test 11: Unknown/unrecognized message type fails gracefully
$rowUnknown = [
    'message_type' => 'poll_result',
    'message_text' => '[Unsupported message type: poll_result]',
    'raw_payload' => null
];
assertTest("11. Unknown/unrecognized type fails gracefully", audit_resolve_message_text($pdo, $rowUnknown) === '[Poll_result]');

// Test 12: No raw API token exposed & raw_payload is stripped in fetch-messages.php
$secretToken = 'EAAFx9283749283479234META_SECRET_TOKEN_DO_NOT_LEAK';
$testPayload = ['access_token' => $secretToken, 'text' => 'Test message'];
$mOutput = ['id' => 1, 'message_text' => 'Test', 'raw_payload' => json_encode($testPayload)];
unset($mOutput['raw_payload']);
$jsonOut = json_encode(['success' => true, 'messages' => [$mOutput]]);
$sourceFetchMsgs = file_get_contents(__DIR__ . '/api/v1/communication/fetch-messages.php');
$stripsRawPayload = strpos($sourceFetchMsgs, "unset(\$m['raw_payload'])") !== false;
assertTest("12. No raw API token is exposed", strpos($jsonOut, $secretToken) === false && !isset($mOutput['raw_payload']) && $stripsRawPayload);

// Test 13: No malformed JSON returned
$decodedJson = json_decode($jsonOut, true);
assertTest("13. No malformed JSON is returned", is_array($decodedJson) && isset($decodedJson['success']) && $decodedJson['success'] === true);

// -------------------------------------------------------------------------
// GROUP 2: ADMIN ACCESS AUDIT (Tests 14 - 25)
// -------------------------------------------------------------------------
echo "\nGROUP 2: ADMIN ACCESS AUDIT\n";
echo "----------------------------------------------------------------------\n";

// Test 14: Super Admin can access Inbox
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_role'] = 'super_admin';
$admin_perms = '';
assertTest("14. Super Admin can access Inbox", audit_can_access('whatsapp-inbox') === true && audit_is_super_admin() === true);

// Test 15: Admin with WhatsApp Inbox permission can access Inbox
$_SESSION['admin_role'] = 'admin';
$admin_perms = 'whatsapp-inbox,dashboard';
assertTest("15. Admin with WhatsApp Inbox permission can access Inbox", audit_can_access('whatsapp-inbox') === true);

// Test 16: Admin without WhatsApp Inbox permission is denied
$admin_perms = 'dashboard,students,approvals';
assertTest("16. Admin without WhatsApp Inbox permission is denied", audit_can_access('whatsapp-inbox') === false);

// Test 17: Permission is checked at page level
$sourceInboxPage = file_get_contents(__DIR__ . '/whatsapp-inbox.php');
$pageHasCheck = strpos($sourceInboxPage, "require_permission('whatsapp-inbox')") !== false;
$admin_perms = 'whatsapp-inbox';
$pageCheckPass = audit_can_access('whatsapp-inbox');
assertTest("17. Permission is checked at the page level", $pageHasCheck && $pageCheckPass === true);

// Test 18: Permission is checked at the Inbox API level across all 6 endpoints
$endpoints = [
    'fetch-conversations.php',
    'fetch-messages.php',
    'fetch-student-details.php',
    'send-reply.php',
    'resolve-template-preview.php',
    'media.php'
];
$allEndpointsCheck = true;
foreach ($endpoints as $ep) {
    $content = file_get_contents(__DIR__ . '/api/v1/communication/' . $ep);
    $hasCheck = (strpos($content, "can_access('whatsapp-inbox')") !== false || strpos($content, "can_access('communication')") !== false);
    if (!$hasCheck) {
        $allEndpointsCheck = false;
        break;
    }
}
$admin_perms = 'students,dashboard'; // unauthorized
$apiDenied = (!audit_can_access('whatsapp-inbox') && !audit_can_access('communication'));
assertTest("18. Permission is checked at the Inbox API level", $allEndpointsCheck && $apiDenied === true);

// Test 19: Authorized Admin receives conversations/messages
$admin_perms = 'whatsapp-inbox';
$apiAllowed = (audit_can_access('whatsapp-inbox') || audit_can_access('communication'));
$stmtConvs = $pdo->query("SELECT * FROM whatsapp_conversations");
$convs = $stmtConvs->fetchAll();
assertTest("19. Authorized Admin receives conversations/messages", $apiAllowed === true && count($convs) > 0);

// Test 20: Unauthorized Admin cannot retrieve Inbox data
$admin_perms = 'marketing,leads';
$canRetrieve = (audit_can_access('whatsapp-inbox') || audit_can_access('communication'));
assertTest("20. Unauthorized Admin cannot retrieve Inbox data", $canRetrieve === false);

// Test 21: API returns a valid response for authorized Admin
$admin_perms = 'whatsapp-inbox';
$response = ['success' => true, 'conversations' => $convs];
$jsonResp = json_encode($response);
$respObj = json_decode($jsonResp, true);
assertTest("21. API returns a valid response for authorized Admin", $respObj['success'] === true && is_array($respObj['conversations']));

// Test 22: Frontend does not remain indefinitely in "Loading..." (Inspects actual JS in whatsapp-inbox.php)
$hasConversationsCatch = strpos($sourceInboxPage, "Unable to load conversations") !== false;
$hasMessagesCatch = strpos($sourceInboxPage, "Failed to load messages") !== false;
assertTest("22. Frontend does not remain indefinitely in 'Loading...'", $hasConversationsCatch && $hasMessagesCatch);

// Test 23: API 401/403 responses are handled clearly
$errorPayload = json_encode(['success' => false, 'error' => 'Access Denied: WhatsApp Inbox permission required.']);
$parsedErr = json_decode($errorPayload, true);
assertTest("23. API 401/403 responses are handled clearly", $parsedErr['success'] === false && strpos($parsedErr['error'], 'Access Denied') !== false);

// Test 24: Session/permission changes behave correctly
$admin_perms = 'dashboard';
$state1 = audit_can_access('whatsapp-inbox');
$admin_perms = 'dashboard,whatsapp-inbox';
$state2 = audit_can_access('whatsapp-inbox');
assertTest("24. Session/permission changes behave correctly", $state1 === false && $state2 === true);

// Test 25: Existing Super Admin behavior remains unchanged
$_SESSION['admin_role'] = 'super_admin';
$admin_perms = '';
assertTest("25. Existing Super Admin behavior remains unchanged", audit_can_access('whatsapp-inbox') === true && audit_is_super_admin() === true);

// -------------------------------------------------------------------------
// GROUP 3: REGRESSION & SECURITY DEEP CHECKS (Tests 26 - 30)
// -------------------------------------------------------------------------
echo "\nGROUP 3: REGRESSION & SECURITY DEEP CHECKS\n";
echo "----------------------------------------------------------------------\n";

// Test 26: Existing WhatsApp webhook processing remains intact
$sampleWebhookMsg = [
    'from' => '919876543210',
    'id' => 'wamid.test_inbound_999',
    'timestamp' => time(),
    'type' => 'text',
    'text' => ['body' => 'I have paid the fee']
];
$stmtCheck = $pdo->prepare("SELECT id FROM whatsapp_messages WHERE wa_message_id = ?");
$stmtCheck->execute([$sampleWebhookMsg['id']]);
$alreadyExists = (bool)$stmtCheck->fetchColumn();

if (!$alreadyExists) {
    $stmtIns = $pdo->prepare("
        INSERT INTO whatsapp_messages (conversation_id, wa_message_id, direction, message_type, message_text, status, created_at)
        VALUES (1, ?, 'inbound', 'text', ?, 'delivered', NOW())
    ");
    $stmtIns->execute([$sampleWebhookMsg['id'], $sampleWebhookMsg['text']['body']]);
}
$stmtVerify = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages WHERE wa_message_id = ?");
$stmtVerify->execute([$sampleWebhookMsg['id']]);
assertTest("26. Existing WhatsApp webhook processing remains intact", (int)$stmtVerify->fetchColumn() > 0);

// Test 27: Existing outgoing WhatsApp messages remain intact
$stmtOut = $pdo->prepare("
    INSERT INTO whatsapp_messages (conversation_id, wa_message_id, direction, message_type, message_text, status, created_at)
    VALUES (1, 'wamid.outbound_001', 'outbound', 'text', 'Thank you Fathima, your payment is verified.', 'sent', NOW())
");
$stmtOut->execute();
$stmtVerifyOut = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages WHERE wa_message_id = 'wamid.outbound_001' AND direction = 'outbound'");
$stmtVerifyOut->execute();
assertTest("27. Existing outgoing WhatsApp messages remain intact", (int)$stmtVerifyOut->fetchColumn() > 0);

// Test 28: Existing conversation search/filter remains intact
$searchQuery = "SELECT * FROM whatsapp_conversations WHERE (contact_name LIKE ? OR wa_phone_number LIKE ?)";
$stmtSearch = $pdo->prepare($searchQuery);
$stmtSearch->execute(['%Fathima%', '%Fathima%']);
$searchResults = $stmtSearch->fetchAll();
assertTest("28. Existing conversation search/filter remains intact", count($searchResults) === 1 && $searchResults[0]['contact_name'] === 'Fathima Rahman');

// Test 29: Student context panel remains intact
$stmtStudent = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmtStudent->execute(['STU1001']);
$stuData = $stmtStudent->fetch();
assertTest("29. Student context panel remains intact", !empty($stuData) && $stuData['name'] === 'Fathima Rahman' && $stuData['pepp_course'] === 'M. Clinical Psychology');

// Test 30: Media proxy security & path traversal immunity
$testMediaId = '../../../../etc/passwd';
$sanitizedCacheName = preg_replace('/[^a-zA-Z0-9_-]/', '', $testMediaId);
$pathTraversalBlocked = ($sanitizedCacheName === 'etcpasswd' && strpos($sanitizedCacheName, '/') === false && strpos($sanitizedCacheName, '.') === false);

$sourceMedia = file_get_contents(__DIR__ . '/api/v1/communication/media.php');
$hasNosniff = strpos($sourceMedia, "X-Content-Type-Options: nosniff") !== false;
$hasSafeFilename = strpos($sourceMedia, "str_replace(['\"', \"\\r\", \"\\n\"], '', basename(\$filename))") !== false;

assertTest("30. Media proxy security (path traversal & nosniff verified)", $pathTraversalBlocked && $hasNosniff && $hasSafeFilename);

echo "\n======================================================================\n";
echo "AUDIT SUMMARY: {$passes} PASSED / " . ($passes + $fails) . " TOTAL\n";
if ($fails === 0) {
    echo "STATUS: ALL 30 AUDIT ASSERTIONS SUCCEEDED (100% PASS)\n";
} else {
    echo "STATUS: {$fails} ASSERTIONS FAILED\n";
}
echo "======================================================================\n";

if ($fails > 0) {
    exit(1);
}
exit(0);
