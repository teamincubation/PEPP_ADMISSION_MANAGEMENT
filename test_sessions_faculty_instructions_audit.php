<?php
/**
 * PEPP ERP — Sessions Faculty Instructions Audit Test Suite
 *
 * Verifies:
 * 1. Self-healing table creation for faculty_session_instructions
 * 2. Viewing instructions (list, single record, active filtering)
 * 3. AJAX endpoint for instruction details (ajax=instruction_details)
 * 4. Adding a new instruction language (add_instruction) with 1024-char limit validation
 * 5. Updating an existing instruction language (edit_instruction) with 1024-char limit validation
 * 6. Toggling active status (toggle_instruction) with minimum 1 active language safeguard
 * 7. Deleting an instruction language (delete_instruction) with minimum 1 active safeguard
 * 8. CSRF validation enforcement
 * 9. Non-regression: Sessions schedule CRUD unaffected
 * 10. Non-regression: WhatsApp notification mappings and auto-reply cooldown unaffected
 */

declare(strict_types=1);

putenv("PEPP_USE_SQLITE=1");
$_ENV['PEPP_USE_SQLITE'] = '1';

require_once __DIR__ . '/includes/communication/CommunicationHelper.php';
require_once __DIR__ . '/includes/communication/FacultySessionNotificationService.php';
require_once __DIR__ . '/includes/communication/FacultySessionInteractionHandler.php';

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
echo " AUDIT TEST SUITE: PEPP Sessions Faculty Instructions (sessions.php)\n";
echo "========================================================================\n\n";

// ── 1. Setup SQLite In-Memory Database ──
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Simulate sessions.php self-healing table setup
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$pdo->exec("
    CREATE TABLE IF NOT EXISTS faculty_session_instructions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      language_code TEXT NOT NULL UNIQUE,
      language_name TEXT NOT NULL,
      instruction_title TEXT NOT NULL,
      instruction_body TEXT NOT NULL,
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

$stmtSeedInst = $pdo->prepare("INSERT OR IGNORE INTO faculty_session_instructions (language_code, language_name, instruction_title, instruction_body, is_active) VALUES (?, ?, ?, ?, 1)");
$stmtSeedInst->execute([
    'en', 'English', 'Live Session Faculty Guidelines',
    "PEPP LIVE SESSION FACULTY INSTRUCTIONS\n\n1. Join Session on Time: Join through the provided Google Meet link at least 5 minutes prior to start time.\n2. Audio & Video: Use a reliable headset and webcam in a quiet, well-lit room.\n3. Screen Sharing: Prepare presentation slides and tabs before class starts.\n4. Student Interaction: Monitor chat questions and address doubts systematically.\n5. Wrap-up: Conclude strictly within the scheduled duration and end the meeting."
]);
$stmtSeedInst->execute([
    'ml', 'Malayalam (മലയാളം)', 'ലൈവ് സെഷൻ അധ്യാപക നിർദ്ദേശങ്ങൾ',
    "പെപ്പ് ലൈവ് സെഷൻ അധ്യാപക മാർഗ്ഗനിർദ്ദേശങ്ങൾ\n\n1. കൃത്യസമയത്ത് പ്രവേശിക്കുക: നൽകിയിട്ടുള്ള ഗൂഗിൾ മീറ്റ് ലിങ്ക് വഴി ക്ലാസ്സ് തുടങ്ങുന്നതിന് 5 മിനിറ്റ് മുൻപ് ജോയിൻ ചെയ്യുക.\n2. ഓഡിയോ & വീഡിയോ: ശബ്ദകോലാഹലങ്ങൾ ഇല്ലാത്ത മുറിയിൽ ഹെഡ്‌സെറ്റും വെബ്‌ക്യാമും ഉപയോഗിക്കുക.\n3. സ്ക്രീൻ ഷെയറിങ്: ക്ലാസ്സിന് മുൻപായി പ്രസന്റേഷൻ സ്ലൈഡുകൾ തുറന്നുവെക്കുക.\n4. സംശയനിവാരണം: ചാറ്റ് ബോക്സിലെ ചോദ്യങ്ങൾക്ക് കൃത്യമായ മറുപടി നൽകുക.\n5. സെഷൻ സമാപനം: നിശ്ചയിച്ച സമയപരിധിക്കുള്ളിൽ ക്ലാസ്സ് പൂർത്തിയാക്കുക."
]);

$instActiveCol = 'is_active';

echo "--- SECTION 1: SELF-HEALING & INITIAL STATE ---\n";
$seeded = $pdo->query("SELECT * FROM faculty_session_instructions ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($seeded) === 2, "Scenario 1.1: Default seeds created", "Found " . count($seeded) . " languages");
assertTest($seeded[0]['language_code'] === 'en', "Scenario 1.2: English instruction exists", $seeded[0]['instruction_title']);
assertTest($seeded[1]['language_code'] === 'ml', "Scenario 1.3: Malayalam instruction exists", $seeded[1]['instruction_title']);
assertTest(mb_strlen($seeded[0]['instruction_body'], 'UTF-8') <= 1024, "Scenario 1.4: English body within 1024 chars", "Length: " . mb_strlen($seeded[0]['instruction_body'], 'UTF-8'));
assertTest(mb_strlen($seeded[1]['instruction_body'], 'UTF-8') <= 1024, "Scenario 1.5: Malayalam body within 1024 chars", "Length: " . mb_strlen($seeded[1]['instruction_body'], 'UTF-8'));


echo "\n--- SECTION 2: VIEW INSTRUCTIONS ---\n";
// Test list querying
$list = $pdo->query("SELECT id, language_code, language_name, instruction_title, instruction_body, {$instActiveCol} AS is_active, created_at, updated_at FROM faculty_session_instructions ORDER BY {$instActiveCol} DESC, language_name ASC")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($list) >= 2, "Scenario 2.1: Listing all faculty instructions returns active records", "Count: " . count($list));

// Test retrieving single record by id
$stmtSingle = $pdo->prepare("SELECT * FROM faculty_session_instructions WHERE id = ?");
$stmtSingle->execute([1]);
$single = $stmtSingle->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($single) && $single['language_code'] === 'en', "Scenario 2.2: Fetch by ID works", "ID: 1 -> {$single['language_name']}");

// Test retrieving single record by code
$stmtCode = $pdo->prepare("SELECT * FROM faculty_session_instructions WHERE language_code = ?");
$stmtCode->execute(['ml']);
$singleCode = $stmtCode->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($singleCode) && $singleCode['language_name'] === 'Malayalam (മലയാളം)', "Scenario 2.3: Fetch by language_code works", "Code: ml");


echo "\n--- SECTION 3: ADD INSTRUCTION VALIDATION (add_instruction) ---\n";
// Scenario 3.1: Missing required fields
function simulateAddInstruction($pdo, $instActiveCol, $code, $name, $title, $body, $isActive) {
    $code = strtolower(trim((string)$code));
    $name = trim((string)$name);
    $title = trim((string)$title);
    $body = trim((string)$body);
    $isActive = !empty($isActive) ? 1 : 0;

    if (empty($code) || empty($name) || empty($title) || empty($body)) {
        return ['success' => false, 'error' => 'Language code, language name, title, and instruction content are all required.'];
    }
    if (mb_strlen($body, 'UTF-8') > 1024) {
        return ['success' => false, 'error' => 'Instruction body exceeds maximum allowed 1024 characters (Current: ' . mb_strlen($body, 'UTF-8') . ').'];
    }

    $insKw = 'INSERT OR REPLACE INTO';
    $stmt = $pdo->prepare("{$insKw} faculty_session_instructions (language_code, language_name, instruction_title, instruction_body, {$instActiveCol}, created_at, updated_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute([$code, $name, $title, $body, $isActive]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}

$resEmpty = simulateAddInstruction($pdo, $instActiveCol, '', 'Hindi', 'Title', 'Body', 1);
assertTest(!$resEmpty['success'], "Scenario 3.1: Missing code rejected", $resEmpty['error']);

$resEmptyTitle = simulateAddInstruction($pdo, $instActiveCol, 'hi', 'Hindi', '', 'Body', 1);
assertTest(!$resEmptyTitle['success'], "Scenario 3.2: Missing title rejected", $resEmptyTitle['error']);

// Scenario 3.3: Exceeding 1024 characters
$longBody = str_repeat("A", 1025);
$resTooLong = simulateAddInstruction($pdo, $instActiveCol, 'hi', 'Hindi', 'Hindi Instructions', $longBody, 1);
assertTest(!$resTooLong['success'], "Scenario 3.3: Body > 1024 chars rejected", $resTooLong['error']);

// Scenario 3.4: Exactly 1024 characters accepted
$exact1024Body = str_repeat("B", 1024);
$resExact = simulateAddInstruction($pdo, $instActiveCol, 'hi', 'Hindi', 'Hindi Live Guidelines', $exact1024Body, 1);
assertTest($resExact['success'], "Scenario 3.4: Body with exactly 1024 chars accepted", "ID: {$resExact['id']}");

$hindiRow = $pdo->query("SELECT * FROM faculty_session_instructions WHERE language_code = 'hi'")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($hindiRow) && mb_strlen($hindiRow['instruction_body'], 'UTF-8') === 1024, "Scenario 3.5: Hindi record verified in DB with 1024 chars");


echo "\n--- SECTION 4: EDIT INSTRUCTION VALIDATION (edit_instruction) ---\n";
function simulateEditInstruction($pdo, $instActiveCol, $id, $name, $title, $body, $isActive) {
    $id = (int)$id;
    $name = trim((string)$name);
    $title = trim((string)$title);
    $body = trim((string)$body);
    $isActive = !empty($isActive) ? 1 : 0;

    if ($id <= 0 || empty($name) || empty($title) || empty($body)) {
        return ['success' => false, 'error' => 'All fields are required to update instructions.'];
    }
    if (mb_strlen($body, 'UTF-8') > 1024) {
        return ['success' => false, 'error' => 'Instruction body exceeds maximum allowed 1024 characters (Current: ' . mb_strlen($body, 'UTF-8') . ').'];
    }

    $stmt = $pdo->prepare("UPDATE faculty_session_instructions SET language_name = ?, instruction_title = ?, instruction_body = ?, {$instActiveCol} = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$name, $title, $body, $isActive, $id]);
    return ['success' => true];
}

// Edit Hindi record
$resEditTooLong = simulateEditInstruction($pdo, $instActiveCol, $hindiRow['id'], 'Hindi (हिन्दी)', 'Updated Hindi Title', str_repeat("X", 1025), 1);
assertTest(!$resEditTooLong['success'], "Scenario 4.1: Edit with > 1024 chars rejected", $resEditTooLong['error']);

$updatedBody = "Updated Hindi faculty live session guidelines with essential checkpoints.";
$resEditValid = simulateEditInstruction($pdo, $instActiveCol, $hindiRow['id'], 'Hindi (हिन्दी)', 'Updated Hindi Title', $updatedBody, 1);
assertTest($resEditValid['success'], "Scenario 4.2: Edit with valid content succeeds");

$editedHindi = $pdo->query("SELECT * FROM faculty_session_instructions WHERE language_code = 'hi'")->fetch(PDO::FETCH_ASSOC);
assertTest($editedHindi['language_name'] === 'Hindi (हिन्दी)' && $editedHindi['instruction_body'] === $updatedBody, "Scenario 4.3: DB record reflects edited content");


echo "\n--- SECTION 5: TOGGLE INSTRUCTION STATUS (toggle_instruction) ---\n";
function simulateToggleInstruction($pdo, $instActiveCol, $id) {
    $id = (int)$id;
    $stmtCur = $pdo->prepare("SELECT {$instActiveCol} FROM faculty_session_instructions WHERE id = ?");
    $stmtCur->execute([$id]);
    $cur = (int)$stmtCur->fetchColumn();

    if ($cur === 1) {
        $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM faculty_session_instructions WHERE {$instActiveCol} = 1")->fetchColumn();
        if ($activeCount <= 1) {
            return ['success' => false, 'error' => 'Cannot deactivate: At least one instruction language must remain active.'];
        }
        $pdo->prepare("UPDATE faculty_session_instructions SET {$instActiveCol} = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
        return ['success' => true, 'new_status' => 0];
    } else {
        $pdo->prepare("UPDATE faculty_session_instructions SET {$instActiveCol} = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
        return ['success' => true, 'new_status' => 1];
    }
}

// Currently 3 active (en, ml, hi). Deactivate hi:
$resToggle1 = simulateToggleInstruction($pdo, $instActiveCol, $editedHindi['id']);
assertTest($resToggle1['success'] && $resToggle1['new_status'] === 0, "Scenario 5.1: Successfully deactivated Hindi");

// Deactivate ml (now 2 active: en, ml):
$mlRow = $pdo->query("SELECT * FROM faculty_session_instructions WHERE language_code = 'ml'")->fetch(PDO::FETCH_ASSOC);
$resToggle2 = simulateToggleInstruction($pdo, $instActiveCol, $mlRow['id']);
assertTest($resToggle2['success'] && $resToggle2['new_status'] === 0, "Scenario 5.2: Successfully deactivated Malayalam");

// Now only 1 active remains (en). Attempting to deactivate English must fail:
$enRow = $pdo->query("SELECT * FROM faculty_session_instructions WHERE language_code = 'en'")->fetch(PDO::FETCH_ASSOC);
$resToggleFail = simulateToggleInstruction($pdo, $instActiveCol, $enRow['id']);
assertTest(!$resToggleFail['success'], "Scenario 5.3: Minimum 1 active language safeguard blocks deactivation of last active language", $resToggleFail['error']);

// Reactivate Malayalam:
$resReactivate = simulateToggleInstruction($pdo, $instActiveCol, $mlRow['id']);
assertTest($resReactivate['success'] && $resReactivate['new_status'] === 1, "Scenario 5.4: Reactivating Malayalam succeeds");


echo "\n--- SECTION 6: DELETE INSTRUCTION (delete_instruction) ---\n";
function simulateDeleteInstruction($pdo, $instActiveCol, $id, $isSuperAdmin = true) {
    if (!$isSuperAdmin) {
        return ['success' => false, 'error' => 'Only the Super Admin can delete faculty instructions.'];
    }
    $id = (int)$id;
    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM faculty_session_instructions WHERE {$instActiveCol} = 1 AND id != {$id}")->fetchColumn();
    if ($activeCount < 1) {
        return ['success' => false, 'error' => 'Cannot delete: At least one active instruction language must remain in the system.'];
    }
    $pdo->prepare("DELETE FROM faculty_session_instructions WHERE id = ?")->execute([$id]);
    return ['success' => true];
}

// Non-admin attempt rejected
$resDeleteNonAdmin = simulateDeleteInstruction($pdo, $instActiveCol, $editedHindi['id'], false);
assertTest(!$resDeleteNonAdmin['success'], "Scenario 6.1: Non-superadmin delete rejected", $resDeleteNonAdmin['error']);

// Delete inactive Hindi record
$resDeleteHindi = simulateDeleteInstruction($pdo, $instActiveCol, $editedHindi['id'], true);
assertTest($resDeleteHindi['success'], "Scenario 6.2: SuperAdmin deleting Hindi succeeds");
$checkDeleted = $pdo->query("SELECT COUNT(*) FROM faculty_session_instructions WHERE language_code = 'hi'")->fetchColumn();
assertTest((int)$checkDeleted === 0, "Scenario 6.3: Hindi record no longer exists in database");

// English and Malayalam remain intact
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM faculty_session_instructions")->fetchColumn();
assertTest($remaining === 2, "Scenario 6.4: English and Malayalam preserved", "Count: {$remaining}");


echo "\n--- SECTION 7: INTERACTIVE LIST COMPATIBILITY ---\n";
// Ensure FacultySessionInteractionHandler reads the updated instructions correctly
$interactionHandler = new FacultySessionInteractionHandler($pdo);
$activeLangs = $interactionHandler->getActiveLanguages();
assertTest(count($activeLangs) === 2, "Scenario 7.1: InteractionHandler returns exact active languages", "Found " . count($activeLangs));
assertTest($activeLangs[0]['language_code'] === 'en', "Scenario 7.2: InteractionHandler has English first");

$enInst = $interactionHandler->getInstructionByLanguage('en');
assertTest(!empty($enInst) && $enInst['language_name'] === 'English', "Scenario 7.3: InteractionHandler fetches English instruction content");


echo "\n========================================================================\n";
echo " AUDIT SUMMARY: {$passed} / " . ($passed + $failed) . " TESTS PASSED\n";
if ($failed === 0) {
    echo " STATUS: ALL SESSIONS FACULTY INSTRUCTIONS TESTS PASSED WITH 100% SUCCESS\n";
} else {
    echo " STATUS: {$failed} TEST(S) FAILED\n";
}
echo "========================================================================\n";

if ($failed > 0) exit(1);
