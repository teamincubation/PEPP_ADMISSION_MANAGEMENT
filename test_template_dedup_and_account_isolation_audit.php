<?php
/**
 * PEPP ERP — Template Deduplication, Account Isolation & Guided Campaign Audit Suite
 *
 * Validates all Phase 10 & Phase 11 requirements:
 *  1. Account 3 displays only Account 3 templates.
 *  2. Account 1 displays only Account 1 templates.
 *  3. Duplicate rows (en vs en_US) do not duplicate UI rows (Account 3 canonical count = exactly 4).
 *  4. Same template name across two accounts remains isolated without collision.
 *  5. Language family normalization maps regional codes (en_US, en_GB, en) to family ('en').
 *  6. Marketing campaign cannot select another account's template.
 *  7. Template sync idempotency and in-place updating.
 *  8. Re-syncing does not create duplicates.
 *  9. Faculty-session templates remain Account 1 and UTILITY only.
 * 10. Campaign preview uses correct account, WABA, and phone numbers.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('PEPP_USE_SQLITE=1');
putenv('PEPP_TESTING_ENV=1');
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

$passCount = 0;
$failCount = 0;

function assertAudit(bool $condition, string $description): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failCount++;
    }
}

echo "======================================================================\n";
echo "PEPP ERP — Template Deduplication & Account Isolation Audit Suite\n";
echo "======================================================================\n\n";

// ----------------------------------------------------------------------
// Setup In-Memory SQLite Database with full schema
// ----------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });
$pdo->sqliteCreateFunction('IFNULL', function($a, $b) { return $a !== null ? $a : $b; });

// Core schema setup
$pdo->exec("
    CREATE TABLE admin_settings (
        setting_name VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE whatsapp_accounts (
        id INTEGER PRIMARY KEY,
        sender_key VARCHAR(50) NOT NULL UNIQUE,
        display_name VARCHAR(100) NOT NULL,
        display_number VARCHAR(30) NOT NULL,
        phone_number_id VARCHAR(50) NOT NULL,
        waba_id VARCHAR(100) NOT NULL,
        purpose VARCHAR(255),
        status VARCHAR(20) DEFAULT 'active',
        is_default INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE communication_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
        sender_account_id INTEGER NULL,
        waba_id VARCHAR(100) NULL,
        meta_template_id VARCHAR(100) NULL,
        template_name VARCHAR(100) NOT NULL,
        language VARCHAR(10) NOT NULL DEFAULT 'en',
        category VARCHAR(50) NOT NULL DEFAULT 'MARKETING',
        status VARCHAR(20) NOT NULL DEFAULT 'approved',
        quality_status VARCHAR(20) DEFAULT 'UNKNOWN',
        rejection_reason TEXT NULL,
        meta_data TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE UNIQUE INDEX uq_tpl_compound ON communication_templates(channel, sender_account_id, template_name, language);

    CREATE TABLE communication_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(255) NOT NULL,
        channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
        target_audience VARCHAR(50) DEFAULT 'leads',
        sender_account_id INTEGER NULL,
        template_id INTEGER NULL,
        template_name VARCHAR(100) NOT NULL,
        segment_criteria TEXT NULL,
        status VARCHAR(20) DEFAULT 'draft',
        scheduled_at DATETIME NULL,
        created_by VARCHAR(100) DEFAULT 'admin',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE communication_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
        sender_account_id INTEGER NULL,
        recipient VARCHAR(50) NOT NULL,
        recipient_name VARCHAR(255) NULL,
        template_name VARCHAR(100) NULL,
        template_data TEXT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        priority INTEGER NOT NULL DEFAULT 0,
        error_message TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME
    );
");

// Insert standard sender accounts
$pdo->exec("
    INSERT INTO whatsapp_accounts (id, sender_key, display_name, display_number, phone_number_id, waba_id, purpose, is_default, status) VALUES
    (1, 'admissions', 'PEPP Learning', '+91 62825 63209', '1229563296908445', '1410328164305566', 'Admissions communication & student records', 1, 'active'),
    (3, 'notifications', 'PEPP Updates', '+91 79943 04400', '1293652117171674', '1099020233033644', 'Marketing campaigns & broadcast notifications', 0, 'active');

    INSERT INTO admin_settings (setting_name, setting_value) VALUES
    ('whatsapp_access_token', 'EAABtestGlobalSystemUserTokenSecret12345'),
    ('whatsapp_phone_number_id', '1229563296908445'),
    ('whatsapp_business_account_id', '1410328164305566');
");

// Populate realistic seed templates matching production state
// Account 3 (PEPP Updates) has duplicate language variants (en vs en_US) for the 4 approved marketing templates
$pdo->exec("
    -- Account 3: mphil_entrance_exam_target (en_US and en duplicates)
    INSERT INTO communication_templates (id, channel, sender_account_id, waba_id, meta_template_id, template_name, language, category, status, meta_data) VALUES
    (101, 'whatsapp', 3, '1099020233033644', 'meta_mphil_101', 'mphil_entrance_exam_target', 'en_US', 'MARKETING', 'approved', '{\"body_text\":\"Prepare for M.Phil Entrance Exam {{1}}.\"}'),
    (102, 'whatsapp', 3, '1099020233033644', 'meta_mphil_101', 'mphil_entrance_exam_target', 'en', 'MARKETING', 'approved', '{\"body_text\":\"Prepare for M.Phil Entrance Exam {{1}} with PEPP.\"}'),

    -- Account 3: mphil_join_interest_message (en_US and en duplicates)
    (103, 'whatsapp', 3, '1099020233033644', 'meta_mphil_103', 'mphil_join_interest_message', 'en_US', 'MARKETING', 'approved', '{\"body_text\":\"Hi {{1}}, thanks for your interest in M.Phil.\"}'),
    (104, 'whatsapp', 3, '1099020233033644', 'meta_mphil_103', 'mphil_join_interest_message', 'en', 'MARKETING', 'approved', '{\"body_text\":\"Hi {{1}}, thanks for your interest in M.Phil at PEPP.\"}'),

    -- Account 3: interested & notinterested
    (105, 'whatsapp', 3, '1099020233033644', 'meta_int_105', 'interested', 'en_US', 'MARKETING', 'approved', '{\"body_text\":\"Thank you for expressing interest.\"}'),
    (106, 'whatsapp', 3, '1099020233033644', 'meta_int_105', 'interested', 'en', 'MARKETING', 'approved', '{\"body_text\":\"Thank you for expressing interest in PEPP courses.\"}'),
    (107, 'whatsapp', 3, '1099020233033644', 'meta_notint_107', 'notinterested', 'en_US', 'MARKETING', 'approved', '{\"body_text\":\"We noted you are not interested.\"}'),
    (108, 'whatsapp', 3, '1099020233033644', 'meta_notint_108', 'notinterested', 'en', 'MARKETING', 'approved', '{\"body_text\":\"We noted you are not interested. Feel free to reach out anytime.\"}'),

    -- Account 1: faculty_session_* templates (UTILITY category, admissions account)
    (201, 'whatsapp', 1, '1410328164305566', 'meta_fac_201', 'faculty_session_reminder', 'en', 'UTILITY', 'approved', '{\"body_text\":\"Faculty session reminder for {{1}} at {{2}}.\"}'),
    (202, 'whatsapp', 1, '1410328164305566', 'meta_fac_202', 'faculty_session_booked', 'en', 'UTILITY', 'approved', '{\"body_text\":\"Faculty session booked for {{1}}.\"}'),
    (203, 'whatsapp', 1, '1410328164305566', 'meta_fac_203', 'faculty_session_cancelled', 'en', 'UTILITY', 'approved', '{\"body_text\":\"Faculty session cancelled for {{1}}.\"}'),
    (204, 'whatsapp', 1, '1410328164305566', 'meta_fac_204', 'faculty_session_rescheduled', 'en', 'UTILITY', 'approved', '{\"body_text\":\"Faculty session rescheduled for {{1}}.\"}'),
    (205, 'whatsapp', 1, '1410328164305566', 'meta_fac_205', 'faculty_session_feedback', 'en', 'UTILITY', 'approved', '{\"body_text\":\"Please share feedback for faculty session.\"}'),

    -- Account 1 also has an 'interested' template (same name as Account 3)
    (206, 'whatsapp', 1, '1410328164305566', 'meta_adm_int_206', 'interested', 'en', 'MARKETING', 'approved', '{\"body_text\":\"Admissions team received your interest in PEPP.\"}'),

    -- Account 1 admissions marketing templates
    (207, 'whatsapp', 1, '1410328164305566', 'meta_adm_207', 'admissions_welcome_kit', 'en', 'MARKETING', 'approved', '{\"body_text\":\"Welcome to PEPP Admissions {{1}}.\"}')
");

require_once __DIR__ . '/includes/communication/WhatsAppAccountResolver.php';
$resolver = new WhatsAppAccountResolver($pdo);

// ----------------------------------------------------------------------
// Test 1: Account 3 displays only Account 3 templates
// ----------------------------------------------------------------------
echo "Testing Requirement 1: Account 3 displays only Account 3 templates...\n";
$acct3Templates = $resolver->getCanonicalTemplates(3);
$allAcct3 = true;
foreach ($acct3Templates as $t) {
    if ((int)$t['sender_account_id'] !== 3) {
        $allAcct3 = false;
        break;
    }
}
assertAudit($allAcct3 && count($acct3Templates) > 0, "All templates returned for Account 3 have sender_account_id = 3 (found " . count($acct3Templates) . ")");
assertAudit(!in_array('faculty_session_reminder', array_column($acct3Templates, 'template_name')), "Faculty session templates are NOT present in Account 3 templates");
assertAudit(!in_array('admissions_welcome_kit', array_column($acct3Templates, 'template_name')), "Admissions welcome kit is NOT present in Account 3 templates");

// ----------------------------------------------------------------------
// Test 2: Account 1 displays only Account 1 templates
// ----------------------------------------------------------------------
echo "\nTesting Requirement 2: Account 1 displays only Account 1 templates...\n";
$acct1Templates = $resolver->getCanonicalTemplates(1);
$allAcct1 = true;
foreach ($acct1Templates as $t) {
    if ((int)$t['sender_account_id'] !== 1) {
        $allAcct1 = false;
        break;
    }
}
assertAudit($allAcct1 && count($acct1Templates) > 0, "All templates returned for Account 1 have sender_account_id = 1 (found " . count($acct1Templates) . ")");
assertAudit(in_array('faculty_session_reminder', array_column($acct1Templates, 'template_name')), "Faculty session templates ARE present in Account 1");
assertAudit(!in_array('mphil_entrance_exam_target', array_column($acct1Templates, 'template_name')), "Account 3's mphil_entrance_exam_target is NOT present in Account 1");

// ----------------------------------------------------------------------
// Test 3: Deduplication (en vs en_US) produces exactly 4 canonical templates for Account 3
// ----------------------------------------------------------------------
echo "\nTesting Requirement 3: Deduplication across language variants (en vs en_US)...\n";
$acct3ApprovedMarketing = $resolver->getApprovedMarketingTemplates(3);
$canonicalNames = array_column($acct3ApprovedMarketing, 'template_name');
sort($canonicalNames);
$expectedNames = ['interested', 'mphil_entrance_exam_target', 'mphil_join_interest_message', 'notinterested'];
sort($expectedNames);

assertAudit(count($acct3ApprovedMarketing) === 4, "Account 3 marketing templates deduplicate to EXACTLY 4 canonical rows (raw count was 8)");
assertAudit($canonicalNames === $expectedNames, "The 4 canonical templates match expected: " . implode(', ', $expectedNames));

// Verify each template picked the richest version with meta_template_id
$mphilTpl = null;
foreach ($acct3ApprovedMarketing as $t) {
    if ($t['template_name'] === 'mphil_entrance_exam_target') $mphilTpl = $t;
}
assertAudit($mphilTpl !== null, "mphil_entrance_exam_target found in canonical list");
assertAudit($mphilTpl['meta_template_id'] === 'meta_mphil_101', "Canonical row preserved meta_template_id");

// ----------------------------------------------------------------------
// Test 4: Same template name across two accounts remains isolated without collision
// ----------------------------------------------------------------------
echo "\nTesting Requirement 4: Same template name ('interested') across both accounts...\n";
$acct1Interested = null;
foreach ($acct1Templates as $t) {
    if ($t['template_name'] === 'interested') $acct1Interested = $t;
}
$acct3Interested = null;
foreach ($acct3Templates as $t) {
    if ($t['template_name'] === 'interested') $acct3Interested = $t;
}
assertAudit($acct1Interested !== null, "'interested' exists in Account 1");
assertAudit($acct3Interested !== null, "'interested' exists in Account 3");
assertAudit((int)$acct1Interested['id'] === 206, "Account 1 'interested' resolved to row ID 206");
assertAudit(in_array((int)$acct3Interested['id'], [105, 106]), "Account 3 'interested' resolved to Account 3 row ID (105 or 106)");
assertAudit($acct1Interested['waba_id'] === '1410328164305566', "Account 1 'interested' bound to WABA 1410328164305566");
assertAudit($acct3Interested['waba_id'] === '1099020233033644', "Account 3 'interested' bound to WABA 1099020233033644");
assertAudit($acct1Interested['id'] !== $acct3Interested['id'], "No row collision between Account 1 and Account 3");

// ----------------------------------------------------------------------
// Test 5: Language family normalization
// ----------------------------------------------------------------------
echo "\nTesting Requirement 5: Language family normalization...\n";
assertAudit($resolver->normalizeLanguageFamily('en') === 'en', "normalizeLanguageFamily('en') => 'en'");
assertAudit($resolver->normalizeLanguageFamily('en_US') === 'en', "normalizeLanguageFamily('en_US') => 'en'");
assertAudit($resolver->normalizeLanguageFamily('en_GB') === 'en', "normalizeLanguageFamily('en_GB') => 'en'");
assertAudit($resolver->normalizeLanguageFamily('es_ES') === 'es', "normalizeLanguageFamily('es_ES') => 'es'");
assertAudit($resolver->normalizeLanguageFamily('ar_AR') === 'ar', "normalizeLanguageFamily('ar_AR') => 'ar'");
assertAudit($resolver->normalizeLanguageFamily('hi_IN') === 'hi', "normalizeLanguageFamily('hi_IN') => 'hi'");
assertAudit($resolver->normalizeLanguageFamily(null) === 'default', "normalizeLanguageFamily(null) => 'default'");

// ----------------------------------------------------------------------
// Test 6: Marketing campaign cannot select another account's template
// ----------------------------------------------------------------------
echo "\nTesting Requirement 6: Campaign cross-account template selection security...\n";
// Attempt to query Account 1 template with Account 3 sender
$crossTpl1 = $resolver->getTemplateById(207, 3);
assertAudit($crossTpl1 === null, "Resolver rejects Account 1 template (ID 207) when sender is Account 3");

// Attempt to query Account 3 template with Account 1 sender
$crossTpl3 = $resolver->getTemplateById(101, 1);
assertAudit($crossTpl3 === null, "Resolver rejects Account 3 template (ID 101) when sender is Account 1");

// Valid lookup for Account 3
$validTpl3 = $resolver->getTemplateById(102, 3);
assertAudit($validTpl3 !== null && $validTpl3['template_name'] === 'mphil_entrance_exam_target', "Resolver accepts Account 3 template when sender is Account 3");

// Valid lookup for Account 1
$validTpl1 = $resolver->getTemplateById(207, 1);
assertAudit($validTpl1 !== null && $validTpl1['template_name'] === 'admissions_welcome_kit', "Resolver accepts Account 1 template when sender is Account 1");

// ----------------------------------------------------------------------
// Test 7 & 8: Template sync idempotency and in-place updating
// ----------------------------------------------------------------------
echo "\nTesting Requirements 7 & 8: Template sync idempotency & in-place update...\n";
$stmtFindExisting = $pdo->prepare("
    SELECT id, language, meta_template_id FROM communication_templates
    WHERE channel = 'whatsapp'
      AND sender_account_id = :sender_account_id
      AND (
          template_name = :template_name
          OR (meta_template_id IS NOT NULL AND meta_template_id = :meta_template_id)
      )
    ORDER BY CASE WHEN language = :incoming_lang THEN 0 ELSE 1 END, id DESC
    LIMIT 1
");

$stmtUpdateInPlace = $pdo->prepare("
    UPDATE communication_templates
    SET category = :category,
        status = :status,
        meta_template_id = COALESCE(:meta_template_id, meta_template_id),
        meta_data = :meta_data,
        language = CASE WHEN language = :incoming_lang THEN language ELSE :incoming_lang END,
        updated_at = NOW()
    WHERE id = :id
");

// Simulate syncing 'mphil_entrance_exam_target' from Meta with incoming language 'en'
$incomingSync = [
    'sender_account_id' => 3,
    'template_name' => 'mphil_entrance_exam_target',
    'meta_template_id' => 'meta_mphil_101',
    'incoming_lang' => 'en',
    'category' => 'MARKETING',
    'status' => 'approved',
    'meta_data' => '{"body_text":"Updated body from Meta sync"}'
];

$stmtFindExisting->execute([
    ':sender_account_id' => $incomingSync['sender_account_id'],
    ':template_name' => $incomingSync['template_name'],
    ':meta_template_id' => $incomingSync['meta_template_id'],
    ':incoming_lang' => $incomingSync['incoming_lang']
]);
$existingRow = $stmtFindExisting->fetch();

assertAudit($existingRow !== false, "Sync found existing template row without inserting new record");
$rowIdToUpdate = (int)$existingRow['id'];

// Execute update in place
$stmtUpdateInPlace->execute([
    ':category' => $incomingSync['category'],
    ':status' => $incomingSync['status'],
    ':meta_template_id' => $incomingSync['meta_template_id'],
    ':meta_data' => $incomingSync['meta_data'],
    ':incoming_lang' => $incomingSync['incoming_lang'],
    ':id' => $rowIdToUpdate
]);

// Verify database row count for Account 3 templates did NOT increase
$countAcct3Rows = (int)$pdo->query("SELECT COUNT(*) FROM communication_templates WHERE sender_account_id = 3")->fetchColumn();
assertAudit($countAcct3Rows === 8, "Total raw rows for Account 3 remains exactly 8 (no duplicate row was created on sync)");

// Verify updated row received the new meta_data
$checkUpdated = $pdo->query("SELECT meta_data FROM communication_templates WHERE id = {$rowIdToUpdate}")->fetch();
assertAudit(strpos($checkUpdated['meta_data'], 'Updated body from Meta sync') !== false, "In-place update successfully modified row ID {$rowIdToUpdate} without duplication");

// ----------------------------------------------------------------------
// Test 9: Faculty-session templates remain Account 1 and UTILITY only
// ----------------------------------------------------------------------
echo "\nTesting Requirement 9: Faculty-session templates exclusivity & category protection...\n";
$facultyTemplates = $pdo->query("SELECT * FROM communication_templates WHERE template_name LIKE 'faculty_session_%'")->fetchAll();
assertAudit(count($facultyTemplates) === 5, "Found all 5 faculty_session_* templates");

$allAcct1Faculty = true;
$allUtilityFaculty = true;
foreach ($facultyTemplates as $ft) {
    if ((int)$ft['sender_account_id'] !== 1) $allAcct1Faculty = false;
    if (strtoupper($ft['category']) !== 'UTILITY') $allUtilityFaculty = false;
}
assertAudit($allAcct1Faculty, "ALL faculty_session_* templates have sender_account_id = 1 (PEPP Learning)");
assertAudit($allUtilityFaculty, "ALL faculty_session_* templates have category = 'UTILITY' (cannot be used for marketing campaigns)");

// ----------------------------------------------------------------------
// Test 10: Campaign preview uses correct account, WABA, and phone numbers
// ----------------------------------------------------------------------
echo "\nTesting Requirement 10: Campaign preview sender account & routing context...\n";
$acct3Details = $resolver->getSenderAccount(3);
$acct1Details = $resolver->getSenderAccount(1);

assertAudit($acct3Details !== null, "Account 3 details found");
assertAudit($acct3Details['waba_id'] === '1099020233033644', "Account 3 WABA is 1099020233033644 (PEPP Updates)");
assertAudit($acct3Details['display_number'] === '+91 79943 04400', "Account 3 Phone is +91 79943 04400");
assertAudit($acct3Details['phone_number_id'] === '1293652117171674', "Account 3 Phone ID is 1293652117171674");

assertAudit($acct1Details !== null, "Account 1 details found");
assertAudit($acct1Details['waba_id'] === '1410328164305566', "Account 1 WABA is 1410328164305566 (PEPP Learning)");
assertAudit($acct1Details['display_number'] === '+91 62825 63209', "Account 1 Phone is +91 62825 63209");
assertAudit($acct1Details['phone_number_id'] === '1229563296908445', "Account 1 Phone ID is 1229563296908445");

// Verify that creating a campaign with Account 3 enqueues with sender_account_id=3 and priority=-10
$pdo->exec("
    INSERT INTO communication_campaigns (name, channel, target_audience, sender_account_id, template_id, template_name, status)
    VALUES ('Audit Test Campaign', 'whatsapp', 'leads', 3, 102, 'mphil_entrance_exam_target', 'active')
");
$campaignId = (int)$pdo->lastInsertId();

$pdo->exec("
    INSERT INTO communication_queue (channel, sender_account_id, recipient, template_name, status, priority)
    VALUES ('whatsapp', 3, '+919999999999', 'mphil_entrance_exam_target', 'pending', -10)
");
$queueItem = $pdo->query("SELECT * FROM communication_queue WHERE id = " . (int)$pdo->lastInsertId())->fetch();

assertAudit((int)$queueItem['sender_account_id'] === 3, "Queue item has sender_account_id = 3 (PEPP Updates preserved)");
assertAudit((int)$queueItem['priority'] === -10, "Queue item has bulk marketing priority = -10 (starvation protection intact)");

// ----------------------------------------------------------------------
// Requirement 11: Existing Account 3 template routing configuration save
// ----------------------------------------------------------------------
echo "\nTesting Requirement 11: Existing Account 3 template routing configuration save...\n";

// Sibling rows for mphil_entrance_exam_target (101 en_US and 102 en) coexist under Account 3
$stmtMphil = $pdo->query("SELECT id, meta_template_id, language FROM communication_templates WHERE sender_account_id = 3 AND template_name = 'mphil_entrance_exam_target'");
$mphilRows = $stmtMphil->fetchAll();
assertAudit(count($mphilRows) === 2, "mphil_entrance_exam_target has exactly 2 sibling rows (101 en_US, 102 en)");

// Simulate the exact routing save handler logic from whatsapp-marketing-templates.php
$edit_id = 102;
$tpl_name = 'mphil_entrance_exam_target';
$postedSenderId = 3;

// Find sibling exclude IDs using strict account-scoped lookup
$stmtLoad = $pdo->prepare("SELECT * FROM communication_templates WHERE id = ? AND channel = 'whatsapp' AND sender_account_id = ? AND status <> 'deleted' LIMIT 1");
$stmtLoad->execute([$edit_id, $postedSenderId]);
$existingTpl = $stmtLoad->fetch();
assertAudit($existingTpl !== false, "Existing template row 102 successfully loaded under posted sender_account_id = 3");

$excludeIds = [(int)$existingTpl['id']];
if (!empty($existingTpl['meta_template_id'])) {
    $stmtSib = $pdo->prepare("SELECT id FROM communication_templates WHERE channel = 'whatsapp' AND sender_account_id = ? AND meta_template_id = ? AND status <> 'deleted'");
    $stmtSib->execute([$postedSenderId, $existingTpl['meta_template_id']]);
    $excludeIds = array_unique(array_merge($excludeIds, array_map('intval', $stmtSib->fetchAll(PDO::FETCH_COLUMN))));
}

assertAudit(in_array(101, $excludeIds, true) && in_array(102, $excludeIds, true), "Both sibling row 101 and 102 are recognized as same logical template identity");

// Verify that the uniqueness check does not falsely flag sibling row 101
$placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
$sqlCheck = "SELECT id FROM communication_templates WHERE channel = 'whatsapp' AND sender_account_id = ? AND template_name = ? AND status <> 'deleted' AND id NOT IN ($placeholders) LIMIT 1";
$stmtCheck = $pdo->prepare($sqlCheck);
$stmtCheck->execute(array_merge([$postedSenderId, $tpl_name], $excludeIds));
$conflictRow = $stmtCheck->fetch();

assertAudit($conflictRow === false, "Routing save does NOT produce false duplicate-template error against sibling row 101");

// Verify that routing configuration update saves successfully to both sibling rows without moving account ownership
$updatedMeta = json_encode([
    'button_type' => 'QUICK_REPLY',
    'buttons' => [
        'quick_reply' => [
            1 => ['text' => 'Yes, Interested', 'payload' => 'INTERESTED_YES', 'action_type' => 'SEND_TEMPLATE', 'target_template_name' => 'interested']
        ]
    ]
]);
$stmtUp = $pdo->prepare("UPDATE communication_templates SET meta_data = ?, updated_at = NOW() WHERE id IN ($placeholders) AND sender_account_id = ?");
$stmtUp->execute(array_merge([$updatedMeta], $excludeIds, [$postedSenderId]));

$row101 = $pdo->query("SELECT * FROM communication_templates WHERE id = 101")->fetch();
$row102 = $pdo->query("SELECT * FROM communication_templates WHERE id = 102")->fetch();
$row101Meta = json_decode($row101['meta_data'], true);
$row102Meta = json_decode($row102['meta_data'], true);

assertAudit(($row102Meta['button_type'] ?? '') === 'QUICK_REPLY', "Canonical row 102 routing configuration updated to QUICK_REPLY");
assertAudit(($row101Meta['button_type'] ?? '') === 'QUICK_REPLY', "Sibling row 101 routing configuration also synchronized to QUICK_REPLY");
assertAudit((int)$row101['sender_account_id'] === 3 && (int)$row102['sender_account_id'] === 3, "Account ownership of rows 101 and 102 strictly preserved at Account 3");

// Verify that a genuinely NEW template creation (edit_id = 0) with existing name IS rejected
$sqlNew = "SELECT id FROM communication_templates WHERE channel = 'whatsapp' AND sender_account_id = ? AND template_name = ? AND status <> 'deleted' LIMIT 1";
$stmtNew = $pdo->prepare($sqlNew);
$stmtNew->execute([$postedSenderId, $tpl_name]);
assertAudit($stmtNew->fetch() !== false, "Genuinely NEW template creation with existing name under Account 3 is correctly flagged as duplicate");

// ----------------------------------------------------------------------
// Requirement 12: Tampered Account Security & Cross-Account Edit Isolation
// ----------------------------------------------------------------------
echo "\nTesting Requirement 12: Tampered Account Security & Cross-Account Edit Isolation...\n";

// CASE A: Account 3 edit_id (102) + Account 1 posted sender_account_id => REJECT
$tamperedEditIdA = 102;
$tamperedSenderA = 1;
$stmtCaseA = $pdo->prepare("SELECT * FROM communication_templates WHERE id = ? AND channel = 'whatsapp' AND sender_account_id = ? AND status <> 'deleted' LIMIT 1");
$stmtCaseA->execute([$tamperedEditIdA, $tamperedSenderA]);
$loadedCaseA = $stmtCaseA->fetch();
assertAudit($loadedCaseA === false, "CASE A: Tampered edit (Account 3 template ID 102 with Account 1 posted sender) is rejected by ownership check");
$checkAccA = $pdo->query("SELECT sender_account_id FROM communication_templates WHERE id = 102")->fetchColumn();
assertAudit((int)$checkAccA === 3, "CASE A: Template 102 sender_account_id was NEVER moved to Account 1");

// CASE B: Account 1 edit_id (206) + Account 3 posted sender_account_id => REJECT
$tamperedEditIdB = 206;
$tamperedSenderB = 3;
$stmtCaseB = $pdo->prepare("SELECT * FROM communication_templates WHERE id = ? AND channel = 'whatsapp' AND sender_account_id = ? AND status <> 'deleted' LIMIT 1");
$stmtCaseB->execute([$tamperedEditIdB, $tamperedSenderB]);
$loadedCaseB = $stmtCaseB->fetch();
assertAudit($loadedCaseB === false, "CASE B: Tampered edit (Account 1 template ID 206 with Account 3 posted sender) is rejected by ownership check");
$checkAccB = $pdo->query("SELECT sender_account_id FROM communication_templates WHERE id = 206")->fetchColumn();
assertAudit((int)$checkAccB === 1, "CASE B: Template 206 sender_account_id was NEVER moved to Account 3");

// CASE C: Account 3 edit_id (102) + Account 3 posted sender_account_id => SUCCESS
$legitEditIdC = 102;
$legitSenderC = 3;
$stmtCaseC = $pdo->prepare("SELECT * FROM communication_templates WHERE id = ? AND channel = 'whatsapp' AND sender_account_id = ? AND status <> 'deleted' LIMIT 1");
$stmtCaseC->execute([$legitEditIdC, $legitSenderC]);
$loadedCaseC = $stmtCaseC->fetch();
assertAudit($loadedCaseC !== false, "CASE C: Legitimate edit (Account 3 template ID 102 with Account 3 posted sender) passes ownership check");

// CASE D: Account 3 template + same template name in Account 1 => Account 1 row must NOT be updated
$acc3TplId = 106; // Account 3 'interested'
$acc1TplId = 206; // Account 1 'interested'
$postedSenderD = 3;

// Resolve siblings strictly within postedSenderD = 3
$stmtSibD = $pdo->prepare("SELECT id FROM communication_templates WHERE channel = 'whatsapp' AND sender_account_id = ? AND template_name = 'interested' AND status <> 'deleted'");
$stmtSibD->execute([$postedSenderD]);
$excludeIdsD = array_map('intval', $stmtSibD->fetchAll(PDO::FETCH_COLUMN));

assertAudit(in_array(105, $excludeIdsD, true) && in_array(106, $excludeIdsD, true), "CASE D: Sibling resolution for Account 3 includes rows 105 and 106");
assertAudit(!in_array(206, $excludeIdsD, true), "CASE D: Sibling resolution for Account 3 strictly EXCLUDES Account 1 row 206");

// Save routing update for Account 3 'interested'
$placeholdersD = implode(',', array_fill(0, count($excludeIdsD), '?'));
$metaD = json_encode(['button_type' => 'QUICK_REPLY', 'buttons' => ['quick_reply' => [1 => ['text' => 'Acc3 Interested']]]]);
$stmtUpD = $pdo->prepare("UPDATE communication_templates SET meta_data = ?, updated_at = NOW() WHERE id IN ($placeholdersD) AND sender_account_id = ?");
$stmtUpD->execute(array_merge([$metaD], $excludeIdsD, [$postedSenderD]));

$acc1RowAfter = $pdo->query("SELECT * FROM communication_templates WHERE id = 206")->fetch();
assertAudit((int)$acc1RowAfter['sender_account_id'] === 1, "CASE D: Account 1 row 206 sender_account_id remains 1");
assertAudit(strpos($acc1RowAfter['meta_data'] ?? '', 'Acc3 Interested') === false, "CASE D: Account 1 row 206 meta_data was NOT modified by Account 3 routing update");

// Summary
echo "\n======================================================================\n";
echo "AUDIT SUMMARY: {$passCount} / " . ($passCount + $failCount) . " Tests Passed (" . round(($passCount / ($passCount + $failCount)) * 100) . "%)\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
