<?php
/**
 * Regression Suite: Multi-Number WhatsApp Inbox (database-update-52)
 *
 * Runs fully offline (SQLite in-memory + static source assertions). No production DB, no Meta API,
 * no WhatsApp messages. Covers the 13 approved scenarios.
 *
 * NOTE: the MySQL stored-procedure migration cannot execute on SQLite, so scenario 7 verifies the
 * migration SQL structurally (ordering/invariants) and the runtime split invariants it relies on.
 */
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
putenv('PEPP_USE_SQLITE=1');

require_once __DIR__ . '/includes/communication/CommunicationEngine.php';

$testCount = 0; $passedCount = 0;
function assertTest($d, $c) { global $testCount, $passedCount; $testCount++; if ($c) { $passedCount++; echo " [PASS] $d\n"; } else { echo " [FAIL] $d\n"; } }
function src($rel) { return file_get_contents(__DIR__ . '/' . $rel); }

function newDb(bool $aware = true): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
    $acc = $aware ? "account_id INTEGER NULL, classification_confidence TEXT NOT NULL DEFAULT 'legacy_unclassified'," : '';
    $accM = $aware ? "account_id INTEGER NULL, classification_confidence TEXT NOT NULL DEFAULT 'legacy_unclassified'," : '';
    $pdo->exec("CREATE TABLE whatsapp_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, wa_phone_number TEXT NOT NULL, $acc
        student_uid TEXT, student_user_id TEXT, contact_name TEXT, last_message_text TEXT,
        last_message_at TEXT, last_inbound_at TEXT, unread_count INTEGER DEFAULT 0,
        status TEXT DEFAULT 'open', created_at TEXT, updated_at TEXT)");
    $pdo->exec($aware
        ? "CREATE UNIQUE INDEX uq_conv ON whatsapp_conversations(wa_phone_number, account_id)"
        : "CREATE UNIQUE INDEX uq_conv ON whatsapp_conversations(wa_phone_number)");
    $pdo->exec("CREATE TABLE whatsapp_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL, $accM
        wa_message_id TEXT UNIQUE, direction TEXT, message_text TEXT, created_at TEXT)");
    return $pdo;
}
function convCount($pdo, $phone) { return (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_conversations WHERE wa_phone_number='$phone'")->fetchColumn(); }
function ctx($in = true) { return ['student_uid' => 'S1', 'student_user_id' => 1, 'contact_name' => 'Stu', 'snippet' => 'hi', 'inbound' => $in]; }

echo "======================================================================\n";
echo "REGRESSION: Multi-Number WhatsApp Inbox (update-52)\n";
echo "======================================================================\n\n";

$webhook = src('api/v1/communication/webhook.php');
$reply   = src('api/v1/communication/send-reply.php');
$engine  = src('includes/communication/CommunicationEngine.php');
$inbox   = src('whatsapp-inbox.php');
$sql     = src('database-update-52-multi-number-inbox.sql');
$fetchC  = src('api/v1/communication/fetch-conversations.php');

// ---- 1. PEPP Learning inbound
echo "--- 1. PEPP Learning inbound ---\n";
$pdo = newDb();
[$a1, $c1] = CommunicationEngine::classifyInboxAccount(1);
$id1 = CommunicationEngine::upsertInboxConversation($pdo, '919999900001', 1, ctx());
$row = $pdo->query("SELECT * FROM whatsapp_conversations WHERE id=$id1")->fetch(PDO::FETCH_ASSOC);
assertTest('Account 1 inbound -> Tier A (account 1 / proven_account_1)', $row['account_id'] == 1 && $row['classification_confidence'] === 'proven_account_1');
assertTest('Unread incremented on first inbound', (int)$row['unread_count'] === 1);

// ---- 2. PEPP Updates inbound
echo "--- 2. PEPP Updates inbound ---\n";
$id3 = CommunicationEngine::upsertInboxConversation($pdo, '919999900001', 3, ctx());
$row3 = $pdo->query("SELECT * FROM whatsapp_conversations WHERE id=$id3")->fetch(PDO::FETCH_ASSOC);
assertTest('Account 3 inbound -> Tier B (account 3 / proven_account_3)', $row3['account_id'] == 3 && $row3['classification_confidence'] === 'proven_account_3');

// ---- 3. Dual-number separation
echo "--- 3. Dual-number conversation separation ---\n";
assertTest('Same student phone yields two distinct conversations', $id1 !== $id3 && convCount($pdo, '919999900001') === 2);
$again = CommunicationEngine::upsertInboxConversation($pdo, '919999900001', 1, ctx());
assertTest('Repeat inbound on Account 1 reuses its conversation', $again === $id1 && convCount($pdo, '919999900001') === 2);
$u = CommunicationEngine::upsertInboxConversation($pdo, '919999900002', null, ctx());
$ur = $pdo->query("SELECT * FROM whatsapp_conversations WHERE id=$u")->fetch(PDO::FETCH_ASSOC);
assertTest('Unresolved destination -> Tier C (NULL / legacy_unclassified), never Account 1', $ur['account_id'] === null && $ur['classification_confidence'] === 'legacy_unclassified');
assertTest('Unknown account id (e.g. 2) never becomes proven', CommunicationEngine::classifyInboxAccount(2) === [null, 'legacy_unclassified']);
assertTest('Webhook resolves account only from positively resolved destination', strpos($webhook, 'receivingAccountResolved') !== false && strpos($webhook, 'receivingInboxAccountId') !== false);
assertTest('Webhook uses account-aware upsert', strpos($webhook, 'upsertInboxConversation') !== false);

// ---- 4/5. Reply integrity
echo "--- 4/5. Reply integrity (PEPP Learning / PEPP Updates) ---\n";
assertTest('send-reply derives sender from conversation DB row (account_sender_key)', strpos($reply, "\$conv['account_sender_key']") !== false);
assertTest('send-reply passes conversation-bound sender_key to queueMessage', preg_match('/null, \/\/ invoiceId\s*\n\s*\$senderKey/', $reply) === 1);
assertTest('send-reply joins whatsapp_accounts via wc.account_id', strpos($reply, 'wa.id = wc.account_id') !== false);
assertTest('send-reply rejects inactive account', strpos($reply, "account_status'] ?? '') !== 'active'") !== false);

// ---- 6. Outbound mirror scoping
echo "--- 6. Outbound mirror account scoping ---\n";
$pdo6 = newDb();
$m1 = CommunicationEngine::upsertInboxConversation($pdo6, '919999900003', 1, ctx(false));
$m3 = CommunicationEngine::upsertInboxConversation($pdo6, '919999900003', 3, ctx(false));
assertTest('Outbound mirror for same recipient is scoped per sender account', $m1 !== $m3 && convCount($pdo6, '919999900003') === 2);
assertTest('Outbound mirror does not touch unread counters', (int)$pdo6->query("SELECT SUM(unread_count) FROM whatsapp_conversations")->fetchColumn() === 0);
assertTest('Engine mirror uses sender_account_id based mirrorAccountId', strpos($engine, 'mirrorAccountId') !== false);
assertTest('Engine mirror records account_id on mirrored message', strpos($engine, 'INSERT INTO whatsapp_messages') !== false && strpos($engine, 'classification_confidence') !== false);

// ---- 7. Mixed-thread splitting + audit logging (structural)
echo "--- 7. Mixed-thread splitting + audit logging ---\n";
$posLog = strpos($sql, 'INSERT INTO whatsapp_migration_split_log');
$posMove = strpos($sql, 'UPDATE whatsapp_messages SET conversation_id = v_new');
assertTest('Split log table is created', strpos($sql, 'CREATE TABLE IF NOT EXISTS `whatsapp_migration_split_log`') !== false);
assertTest('Every moved message is logged BEFORE conversation_id changes', $posLog !== false && $posMove !== false && $posLog < $posMove);
assertTest('Split log preserves message/original/new/account/timestamp', preg_match('/original_conversation_id.*new_conversation_id.*message_id.*account_id.*split_at/s', $sql) === 1);
assertTest('Old unique index discovered dynamically (information_schema)', strpos($sql, 'information_schema.STATISTICS') !== false && strpos($sql, 'v_old_idx') !== false);
assertTest('Composite unique uq_conv_phone_account added', strpos($sql, 'uq_conv_phone_account') !== false);
assertTest('Old unique dropped before split', strpos($sql, 'DROP INDEX') < $posLog);
assertTest('Composite unique added after duplicate verification and split', strrpos($sql, "ADD UNIQUE KEY `uq_conv_phone_account`") > $posMove);
assertTest('Foreign keys on both tables', strpos($sql, 'fk_conv_account_id') !== false && strpos($sql, 'fk_msg_account_id') !== false);
assertTest('Pre-flight and post-flight assertions present', strpos($sql, 'PRE-FLIGHT') !== false && strpos($sql, 'POST-FLIGHT') !== false);
assertTest('Migration never assigns Account 1 by default to Tier C', strpos($sql, "DEFAULT 'proven_account_1'") === false && preg_match('/ELSE NULL END/', $sql) === 1);
assertTest('Migration does not use nonexistent cq.sender_key', strpos($sql, 'sender_key') !== false && strpos($sql, 'q.sender_key') === false);
assertTest('Migration does not use queue pause/lock', stripos($sql, 'communication_queue_paused') === false && strpos($sql, 'queue.lock') === false);
// runtime invariant: moving rows between conversations preserves count and order
$pdo7 = newDb();
$cm = CommunicationEngine::upsertInboxConversation($pdo7, '919999900004', 1, ctx());
for ($i = 1; $i <= 4; $i++) { $pdo7->exec("INSERT INTO whatsapp_messages (conversation_id, account_id, classification_confidence, wa_message_id, direction, created_at) VALUES ($cm, " . ($i % 2 ? 1 : 3) . ", '" . ($i % 2 ? 'proven_account_1' : 'proven_account_3') . "', 'w$i', 'inbound', '2026-01-0$i')"); }
$new = CommunicationEngine::upsertInboxConversation($pdo7, '919999900004', 3, ctx());
$pdo7->exec("UPDATE whatsapp_messages SET conversation_id=$new WHERE conversation_id=$cm AND account_id=3");
assertTest('Split by account leaves no cross-account messages per thread',
    (int)$pdo7->query("SELECT COUNT(*) FROM whatsapp_messages WHERE (conversation_id=$cm AND account_id<>1) OR (conversation_id=$new AND account_id<>3)")->fetchColumn() === 0
    && (int)$pdo7->query("SELECT COUNT(*) FROM whatsapp_messages")->fetchColumn() === 4);

// ---- 8. Tier C reply lockout
echo "--- 8. Tier C reply lockout ---\n";
assertTest('send-reply returns 422 for NULL account / legacy_unclassified', strpos($reply, 'http_response_code(422)') !== false && strpos($reply, "=== 'legacy_unclassified'") !== false);
assertTest('send-reply Tier C branch exits before queueMessage', strpos($reply, 'legacy/unknown WhatsApp channel') !== false && strpos($reply, 'legacy/unknown') < strpos($reply, '->queueMessage('));
assertTest('Inbox UI disables replies for legacy conversations', strpos($inbox, 'legacy-reply-lock') !== false && strpos($inbox, 'isLegacyConversation(activeConv)') !== false);
assertTest('No Account 1 fallback in send-reply', stripos($reply, "'admissions'") === false);

// ---- 9. Webhook concurrency race
echo "--- 9. Webhook concurrency race ---\n";
class RacePdo extends PDO {
    public $raced = false;
    public function prepare($q, $o = []): PDOStatement|false {
        if (!$this->raced && strpos($q, 'SELECT id FROM whatsapp_conversations WHERE wa_phone_number') === 0) {
            $this->raced = true;
            // A competing request commits its row between our find and our insert.
            $this->exec("INSERT INTO whatsapp_conversations (wa_phone_number, account_id, classification_confidence, unread_count) VALUES ('919999900005', 3, 'proven_account_3', 1)");
            return parent::prepare("SELECT id FROM whatsapp_conversations WHERE 0 = 1 AND wa_phone_number = ? AND account_id = ?");
        }
        return parent::prepare($q, $o);
    }
}
$race = new RacePdo('sqlite::memory:');
$race->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$race->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
$race->exec("CREATE TABLE whatsapp_conversations (id INTEGER PRIMARY KEY AUTOINCREMENT, wa_phone_number TEXT NOT NULL, account_id INTEGER NULL, classification_confidence TEXT NOT NULL DEFAULT 'legacy_unclassified', student_uid TEXT, student_user_id TEXT, contact_name TEXT, last_message_text TEXT, last_message_at TEXT, last_inbound_at TEXT, unread_count INTEGER DEFAULT 0, status TEXT DEFAULT 'open', created_at TEXT, updated_at TEXT)");
$race->exec("CREATE UNIQUE INDEX uq_conv ON whatsapp_conversations(wa_phone_number, account_id)");
$race->exec("CREATE TABLE whatsapp_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER, account_id INTEGER, classification_confidence TEXT, wa_message_id TEXT UNIQUE, direction TEXT, message_text TEXT, created_at TEXT)");
$rid = CommunicationEngine::upsertInboxConversation($race, '919999900005', 3, ctx());
assertTest('Duplicate-key race recovered without exception', $race->raced && $rid > 0);
assertTest('Only one conversation exists after race', convCount($race, '919999900005') === 1);
assertTest('Racing inbound still counted (unread 1 -> 2)', (int)$race->query("SELECT unread_count FROM whatsapp_conversations WHERE id=$rid")->fetchColumn() === 2);
assertTest('Engine recovery uses locking re-read on MySQL', strpos($engine, 'FOR UPDATE') !== false);

// ---- 10. 24-hour window isolation
echo "--- 10. 24-hour window isolation ---\n";
$pdo10 = newDb();
$a = CommunicationEngine::upsertInboxConversation($pdo10, '919999900006', 1, ctx(true));
$pdo10->exec("UPDATE whatsapp_conversations SET last_inbound_at='2020-01-01 00:00:00' WHERE id=$a");
$b = CommunicationEngine::upsertInboxConversation($pdo10, '919999900006', 3, ctx(true));
$ia = $pdo10->query("SELECT last_inbound_at FROM whatsapp_conversations WHERE id=$a")->fetchColumn();
$ib = $pdo10->query("SELECT last_inbound_at FROM whatsapp_conversations WHERE id=$b")->fetchColumn();
assertTest('Inbound on Account 3 does not open Account 1 24h window', $ia === '2020-01-01 00:00:00' && $ib > '2024-01-01');

// ---- 11. Quick Reply preservation
echo "--- 11. Quick Reply preservation ---\n";
assertTest('Webhook keeps synchronous Quick Reply processQueueItem(..., true)', preg_match('/processQueueItem\(\s*\$\w+\s*,\s*true\s*\)/', $webhook) === 1);
assertTest('Engine processQueueItem force flag preserved', strpos($engine, 'processQueueItem(') !== false && preg_match('/function processQueueItem\(\$\w+,\s*\$force\s*=\s*false\)/', $engine) === 1);
assertTest('Webhook Quick Reply still limited to admissions inbound', strpos($webhook, 'isAdmissionsInbound') !== false);

// ---- 12. Campaign / notification routing preservation
echo "--- 12. Campaign/notification routing preservation ---\n";
$g = fn($f) => trim((string)shell_exec('git diff --name-only -- ' . escapeshellarg($f)));
foreach (['includes/communication/CampaignConfig.php', 'includes/auth.php', 'includes/student_status_helpers.php'] as $f) {
    assertTest("$f untouched", $g($f) === '');
}
assertTest("cron-queue.php preserves QueueProcessor runner", strpos(file_get_contents(__DIR__ . '/cron-queue.php'), 'QueueProcessor') !== false);
assertTest("includes/communication/WhatsAppAccountResolver.php multi-number intact", method_exists('WhatsAppAccountResolver', 'getAccount') && method_exists('WhatsAppAccountResolver', 'getWabaId'));
assertTest("includes/communication/Providers/WhatsAppCloudProvider.php multi-WABA intact", method_exists('WhatsAppCloudProvider', 'getBusinessId') && method_exists('WhatsAppCloudProvider', 'getPhoneId'));
assertTest('Engine getProvider routing untouched (admissions fallback for NULL sender kept)', strpos($engine, 'function getProvider') !== false);

// ---- 13. Client tamper resistance
echo "--- 13. Client-side tamper resistance ---\n";
assertTest('send-reply never reads account/sender from request input', preg_match('/\$input\[\s*[\'"](account_id|sender_key|sender_account_id|phone_number_id)/', $reply) === 0);
assertTest('send-reply never reads $_POST/$_GET/$_REQUEST', preg_match('/\$_(POST|GET|REQUEST)/', $reply) === 0);
assertTest('Inbox sendReply payload carries no account field', preg_match('/const payload = \{\s*conversation_id: currentConversationId,\s*message_text: [^\n]+\n\s*template_name: templateName\s*\};/', $inbox) === 1);
assertTest('Client account_id is only a read filter in fetch-conversations', strpos($fetchC, "\$_GET['account_id']") !== false && stripos($fetchC, 'INSERT') === false);

// ---- 14. Detailed verification of all 8 migration thread cases
echo "\n--- 14. Detailed verification of all 8 migration split cases ---\n";
function runSyntheticMigrationSim(array $cases): array {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->sqliteCreateFunction('NOW', fn() => '2026-10-08 12:00:00');
    $db->exec("CREATE TABLE whatsapp_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, wa_phone_number TEXT NOT NULL,
        account_id INTEGER NULL, classification_confidence TEXT NOT NULL DEFAULT 'legacy_unclassified',
        contact_name TEXT, unread_count INTEGER DEFAULT 0, created_at TEXT)");
    $db->exec("CREATE TABLE whatsapp_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL,
        account_id INTEGER NULL, classification_confidence TEXT,
        wa_message_id TEXT UNIQUE, direction TEXT, message_text TEXT, created_at TEXT)");
    $db->exec("CREATE TABLE whatsapp_migration_split_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, original_conversation_id INTEGER NOT NULL,
        new_conversation_id INTEGER NOT NULL, message_id INTEGER NOT NULL,
        account_id INTEGER NOT NULL, split_at TEXT)");

    // Seed conversations & messages
    foreach ($cases as $cKey => $cData) {
        $phone = $cData['phone'];
        $db->exec("INSERT INTO whatsapp_conversations (id, wa_phone_number, contact_name, unread_count, created_at)
                   VALUES ({$cData['id']}, '$phone', 'Student $cKey', {$cData['unread']}, '2026-10-01 10:00:00')");
        foreach ($cData['msgs'] as $idx => $m) {
            $accCol = $m['account_id'] === null ? 'NULL' : (int)$m['account_id'];
            $confCol = $m['account_id'] === 1 ? "'proven_account_1'" : ($m['account_id'] === 3 ? "'proven_account_3'" : "'legacy_unclassified'");
            $mid = "m_{$cData['id']}_{$idx}";
            $db->exec("INSERT INTO whatsapp_messages (conversation_id, account_id, classification_confidence, wa_message_id, direction, message_text, created_at)
                       VALUES ({$cData['id']}, $accCol, $confCol, '$mid', 'inbound', 'text', '2026-10-01 10:0$idx:00')");
        }
    }

    // Run cursor split (simulating Step 6)
    $splitConvStmt = $db->query("
        SELECT m3.conversation_id
        FROM whatsapp_messages m3
        WHERE m3.account_id = 3
          AND EXISTS (SELECT 1 FROM whatsapp_messages mx
                      WHERE mx.conversation_id = m3.conversation_id
                        AND (mx.account_id = 1 OR mx.account_id IS NULL))
        GROUP BY m3.conversation_id
        ORDER BY m3.conversation_id
    ");
    $toSplit = $splitConvStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($toSplit as $v_conv) {
        $cRow = $db->query("SELECT * FROM whatsapp_conversations WHERE id = $v_conv")->fetch(PDO::FETCH_ASSOC);
        $db->exec("INSERT INTO whatsapp_conversations (wa_phone_number, account_id, classification_confidence, contact_name, unread_count, created_at)
                   VALUES ('{$cRow['wa_phone_number']}', 3, 'proven_account_3', '{$cRow['contact_name']}', 0, '{$cRow['created_at']}')");
        $v_new = (int)$db->lastInsertId();

        $msgsToMove = $db->query("SELECT id FROM whatsapp_messages WHERE conversation_id = $v_conv AND account_id = 3 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($msgsToMove as $mid) {
            $db->exec("INSERT INTO whatsapp_migration_split_log (original_conversation_id, new_conversation_id, message_id, account_id, split_at)
                       VALUES ($v_conv, $v_new, $mid, 3, datetime('now'))");
        }
        $db->exec("UPDATE whatsapp_messages SET conversation_id = $v_new WHERE conversation_id = $v_conv AND account_id = 3");
    }

    // Run conversation classification (simulating Step 7)
    $db->exec("
        UPDATE whatsapp_conversations
        SET account_id = (
            SELECT CASE WHEN COUNT(*) > 0 AND SUM(account_id = 3) = COUNT(*) THEN 3
                        WHEN COUNT(*) > 0 AND SUM(account_id = 1) = COUNT(*) THEN 1
                        ELSE NULL END
            FROM whatsapp_messages m WHERE m.conversation_id = whatsapp_conversations.id
        ),
        classification_confidence = (
            SELECT CASE WHEN COUNT(*) > 0 AND SUM(account_id = 3) = COUNT(*) THEN 'proven_account_3'
                        WHEN COUNT(*) > 0 AND SUM(account_id = 1) = COUNT(*) THEN 'proven_account_1'
                        ELSE 'legacy_unclassified' END
            FROM whatsapp_messages m WHERE m.conversation_id = whatsapp_conversations.id
        )
    ");

    return $db->query("SELECT id, wa_phone_number, account_id, classification_confidence FROM whatsapp_conversations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

$scenarios = [
    'case1' => ['id' => 1, 'phone' => '919000000001', 'unread' => 2, 'msgs' => [['account_id' => 1], ['account_id' => 3]]],
    'case2' => ['id' => 2, 'phone' => '919000000002', 'unread' => 1, 'msgs' => [['account_id' => 3], ['account_id' => 1]]],
    'case3' => ['id' => 3, 'phone' => '919000000003', 'unread' => 0, 'msgs' => [['account_id' => 1], ['account_id' => null]]],
    'case4' => ['id' => 4, 'phone' => '919000000004', 'unread' => 1, 'msgs' => [['account_id' => 3], ['account_id' => null]]],
    'case5' => ['id' => 5, 'phone' => '919000000005', 'unread' => 3, 'msgs' => [['account_id' => 1], ['account_id' => 3], ['account_id' => null], ['account_id' => 3]]],
    'case6' => ['id' => 6, 'phone' => '919000000006', 'unread' => 1, 'msgs' => [['account_id' => 1]]],
    'case7' => ['id' => 7, 'phone' => '919000000007', 'unread' => 1, 'msgs' => [['account_id' => 3]]],
    'case8' => ['id' => 8, 'phone' => '919000000008', 'unread' => 1, 'msgs' => [['account_id' => null]]],
];
$simResults = runSyntheticMigrationSim($scenarios);
$byPhone = [];
foreach ($simResults as $row) {
    $byPhone[$row['wa_phone_number']][] = $row;
}

// Case 1: Account 1 -> Account 3 => splits into 1 & 3
assertTest('Case 1: (Account 1 -> 3) splits into 2 conversations', count($byPhone['919000000001']) === 2);
assertTest('Case 1: Has pure Account 1 and pure Account 3',
    $byPhone['919000000001'][0]['account_id'] == 1 && $byPhone['919000000001'][0]['classification_confidence'] === 'proven_account_1' &&
    $byPhone['919000000001'][1]['account_id'] == 3 && $byPhone['919000000001'][1]['classification_confidence'] === 'proven_account_3');

// Case 2: Account 3 -> Account 1 => splits into 1 & 3
assertTest('Case 2: (Account 3 -> 1) splits into 2 conversations', count($byPhone['919000000002']) === 2);
assertTest('Case 2: Has pure Account 1 and pure Account 3',
    $byPhone['919000000002'][0]['account_id'] == 1 && $byPhone['919000000002'][0]['classification_confidence'] === 'proven_account_1' &&
    $byPhone['919000000002'][1]['account_id'] == 3 && $byPhone['919000000002'][1]['classification_confidence'] === 'proven_account_3');

// Case 3: Account 1 -> legacy unknown => stays single, classified as Tier C (never Account 1)
assertTest('Case 3: (Account 1 -> legacy unknown) is single conversation', count($byPhone['919000000003']) === 1);
assertTest('Case 3: Classified as Tier C (account_id NULL / legacy_unclassified)',
    $byPhone['919000000003'][0]['account_id'] === null && $byPhone['919000000003'][0]['classification_confidence'] === 'legacy_unclassified');

// Case 4: Account 3 -> legacy unknown => splits into Tier C & Account 3
assertTest('Case 4: (Account 3 -> legacy unknown) splits into 2 conversations', count($byPhone['919000000004']) === 2);
assertTest('Case 4: Original is Tier C, new is proven Account 3',
    $byPhone['919000000004'][0]['account_id'] === null && $byPhone['919000000004'][0]['classification_confidence'] === 'legacy_unclassified' &&
    $byPhone['919000000004'][1]['account_id'] == 3 && $byPhone['919000000004'][1]['classification_confidence'] === 'proven_account_3');

// Case 5: Complex mixed (1 -> 3 -> legacy -> 3) => splits into Tier C & Account 3
assertTest('Case 5: (1 -> 3 -> legacy -> 3) splits into 2 conversations', count($byPhone['919000000005']) === 2);
assertTest('Case 5: Legacy+1 half is Tier C, Account 3 half is proven_account_3',
    $byPhone['919000000005'][0]['account_id'] === null && $byPhone['919000000005'][0]['classification_confidence'] === 'legacy_unclassified' &&
    $byPhone['919000000005'][1]['account_id'] == 3 && $byPhone['919000000005'][1]['classification_confidence'] === 'proven_account_3');

// Case 6: Pure Account 1
assertTest('Case 6: Pure Account 1 remains single', count($byPhone['919000000006']) === 1);
assertTest('Case 6: Classified as proven_account_1', $byPhone['919000000006'][0]['account_id'] == 1 && $byPhone['919000000006'][0]['classification_confidence'] === 'proven_account_1');

// Case 7: Pure Account 3
assertTest('Case 7: Pure Account 3 remains single', count($byPhone['919000000007']) === 1);
assertTest('Case 7: Classified as proven_account_3', $byPhone['919000000007'][0]['account_id'] == 3 && $byPhone['919000000007'][0]['classification_confidence'] === 'proven_account_3');

// Case 8: Pure Tier C
assertTest('Case 8: Pure Tier C remains single', count($byPhone['919000000008']) === 1);
assertTest('Case 8: Classified as legacy_unclassified (account_id NULL)', $byPhone['919000000008'][0]['account_id'] === null && $byPhone['919000000008'][0]['classification_confidence'] === 'legacy_unclassified');

echo "\n======================================================================\n";
echo "RESULT: $passedCount / $testCount passed\n";
exit($passedCount === $testCount ? 0 : 1);
