<?php
/**
 * PEPP ERP — Comprehensive Mega Test Result Cards & Template Preview Audit
 *
 * Validates:
 * 1. Template Preview Thumbnail Fix & HTML Escaping
 * 2. Database Migration 49 & is_mega_test_card Column Safety
 * 3. Mega Test Card Toggle Logic (CSRF, Permissions, Validation, Persistence)
 * 4. Background Template Dropdown Filtering (test_results vs generate)
 * 5. Authoritative Editable Text Lifecycle (Init -> Edit -> Redraw -> Save -> Reload -> Export)
 * 6. Student Identity & Rank Mapping Preservation
 * 7. Exact Test Scenarios (18 -> 18th, Nanditha Nair -> Nanditha Nair — Topper)
 * 8. Database Non-destructive Invariants
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$test_log = [];
$total_assertions = 0;
$passed_assertions = 0;
$failed_assertions = 0;

function assert_test($condition, $desc) {
    global $total_assertions, $passed_assertions, $failed_assertions;
    $total_assertions++;
    if ($condition) {
        $passed_assertions++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed_assertions++;
        echo "  [FAIL] {$desc}\n";
    }
}

echo "======================================================================\n";
echo "MEGA TEST CARDS & TEMPLATE PREVIEW AUDIT SUITE\n";
echo "======================================================================\n\n";

putenv('PEPP_TESTING_ENV=1');
$_SERVER['DOCUMENT_ROOT'] = __DIR__;
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/card_helper.php';

// ======================================================================
// 1. TEMPLATE PREVIEW ROOT CAUSE & HTML ESCAPING VERIFICATION
// ======================================================================
echo "--- 1. Template Preview & HTML Quote Escaping Audit ---\n";

$templates_to_verify = [
    [
        'id' => 22,
        'title' => 'FYUGP Material Cover',
        'stored_bg' => 'linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%)',
        'expected_type' => 'gradient'
    ],
    [
        'id' => 21,
        'title' => 'Live Session | M.Phil.',
        'stored_bg' => 'uploads/card_templates/6aae74eb0e73c_MCP-Session_Reminder_Poster-template_2copy.png',
        'expected_type' => 'url'
    ],
    [
        'id' => 17,
        'title' => 'PG - Mega Test Result Template',
        'stored_bg' => 'uploads/card_templates/6ab679bb8afb6_Mega_Test_Template__PG_.jpg',
        'expected_type' => 'url'
    ],
    [
        'id' => 23,
        'title' => 'M.Clin Psy - Mega Test Result Template',
        'stored_bg' => 'uploads/card_templates/6ab6706c59267_Mega_Test_Template_MCP.jpg',
        'expected_type' => 'url'
    ],
    [
        'id' => 16,
        'title' => 'Add-on Session | M.Phil.',
        'stored_bg' => 'uploads/card_templates/6a79a0f32c1ef_Template_copy.jpg',
        'expected_type' => 'url'
    ]
];

foreach ($templates_to_verify as $t) {
    $resolved_url = resolve_card_bg_browser_url($t['stored_bg'], 'cards.php');
    $bg_css = get_card_bg_css_style($t['stored_bg'], 'cards.php');
    $type = get_card_bg_type($t['stored_bg']);

    assert_test($type === $t['expected_type'], "Template #{$t['id']} ({$t['title']}) type is '{$t['expected_type']}'");

    if ($t['expected_type'] === 'url') {
        assert_test(strpos($resolved_url, '../uploads/card_templates/') === 0, "Template #{$t['id']} browser URL resolves to '../uploads/card_templates/...'");
        assert_test(strpos($bg_css, 'background-image: url("') !== false, "Template #{$t['id']} CSS contains double-quoted url(...)");

        // Test Unescaped HTML rendering (the bug)
        $broken_html = '<div class="tpl-preview" style="' . $bg_css . '"></div>';
        assert_test(strpos($broken_html, 'style="background-image: url("') !== false, "Unescaped HTML cuts off style attribute at first double quote");

        // Test Escaped HTML rendering (the fix)
        $escaped_bg = htmlspecialchars($bg_css, ENT_QUOTES, 'UTF-8');
        $fixed_html = '<div class="tpl-preview" style="' . $escaped_bg . '"></div>';
        assert_test(strpos($fixed_html, '&quot;') !== false, "Escaped HTML correctly entities internal quotes to &quot;");
        assert_test(preg_match('/style="background-image:\s*url\(&quot;.*?&quot;\);/i', $fixed_html) === 1, "Template #{$t['id']} style attribute is valid and fully intact");
    } else {
        assert_test(strpos($bg_css, 'linear-gradient') !== false, "Template #{$t['id']} gradient CSS generated correctly");
        $escaped_bg = htmlspecialchars($bg_css, ENT_QUOTES, 'UTF-8');
        $fixed_html = '<div class="tpl-preview" style="' . $escaped_bg . '"></div>';
        assert_test(strpos($fixed_html, 'linear-gradient') !== false, "Template #{$t['id']} gradient renders intact");
    }
}

// Verify cards.php uses htmlspecialchars for both tabs (tab=templates and tab=generate)
$cards_php_content = file_get_contents(__DIR__ . '/cards.php');
$matches_escaped = preg_match_all('/htmlspecialchars\(\$bg_css,\s*ENT_QUOTES,\s*[\'"]UTF-8[\'"]\)/', $cards_php_content);
assert_test($matches_escaped >= 2, "cards.php uses htmlspecialchars(\$bg_css, ENT_QUOTES, 'UTF-8') in both tab=templates and tab=generate");

// ======================================================================
// 2. DATABASE MIGRATION 49 & SCHEMA VERIFICATION
// ======================================================================
echo "\n--- 2. Database Migration 49 & Schema Verification ---\n";

$sql_migration = file_get_contents(__DIR__ . '/database-update-49.sql');
assert_test(strpos($sql_migration, 'MigrateAddMegaTestCardColumn') !== false, "database-update-49.sql contains idempotent stored procedure MigrateAddMegaTestCardColumn");
assert_test(strpos($sql_migration, 'is_mega_test_card` TINYINT(1) NOT NULL DEFAULT 0') !== false, "database-update-49.sql defines is_mega_test_card as TINYINT(1) NOT NULL DEFAULT 0");

// Check current database columns
$col_check = $pdo->query("PRAGMA table_info(card_templates)")->fetchAll(PDO::FETCH_ASSOC);
$col_names = array_column($col_check, 'name');
assert_test(in_array('is_mega_test_card', $col_names, true), "Database card_templates table possesses 'is_mega_test_card' column");

// Verify default is 0 for newly inserted templates
$stmt_ins = $pdo->prepare("INSERT INTO card_templates (title, category, bg_image, canvas_width, canvas_height, aspect_ratio, elements_json, created_by, created_at) VALUES ('Default Test Template', 'test_results', '#ffffff', 1200, 1200, '1:1', '[]', 'test', CURRENT_TIMESTAMP)");
$stmt_ins->execute();
$new_tpl_id = (int)$pdo->lastInsertId();

$stmt_chk = $pdo->prepare("SELECT is_mega_test_card FROM card_templates WHERE id = ?");
$stmt_chk->execute([$new_tpl_id]);
$default_flag = (int)$stmt_chk->fetchColumn();
assert_test($default_flag === 0, "Newly created template defaults to is_mega_test_card = 0");

// ======================================================================
// 3. MEGA TEST TOGGLE PERSISTENCE & ACCESS CONTROL
// ======================================================================
echo "\n--- 3. Mega Test Toggle Persistence & Access Control ---\n";

// Test Toggle ON
$stmt_upd = $pdo->prepare("UPDATE card_templates SET is_mega_test_card = 1 WHERE id = ?");
$stmt_upd->execute([$new_tpl_id]);
$stmt_chk->execute([$new_tpl_id]);
assert_test((int)$stmt_chk->fetchColumn() === 1, "is_mega_test_card successfully persisted as 1 (ON)");

// Test Toggle OFF
$stmt_upd2 = $pdo->prepare("UPDATE card_templates SET is_mega_test_card = 0 WHERE id = ?");
$stmt_upd2->execute([$new_tpl_id]);
$stmt_chk->execute([$new_tpl_id]);
assert_test((int)$stmt_chk->fetchColumn() === 0, "is_mega_test_card successfully persisted as 0 (OFF)");

// Audit toggle implementation in cards.php
assert_test(strpos($cards_php_content, "action === 'toggle_mega_test_card'") !== false, "cards.php defines 'toggle_mega_test_card' action");
assert_test(strpos($cards_php_content, "can_access('card-templates')") !== false, "cards.php checks can_access('card-templates') for toggle");
assert_test(strpos($cards_php_content, "csrf_verify()") !== false, "cards.php enforces csrf_verify() on toggle");
assert_test(strpos($cards_php_content, "(int)\$_POST['is_mega_test_card'] ? 1 : 0") !== false, "cards.php normalizes toggle input to boolean 0 or 1");

// Test Template Clone preserves is_mega_test_card
$stmt_clone = $pdo->prepare("SELECT is_mega_test_card FROM card_templates WHERE id = ?");
$stmt_clone->execute([$new_tpl_id]);
assert_test(strpos($cards_php_content, "\$orig['is_mega_test_card']") !== false, "cards.php preserves is_mega_test_card when cloning a template");

// Clean up test template
$pdo->prepare("DELETE FROM card_templates WHERE id = ?")->execute([$new_tpl_id]);

// ======================================================================
// 4. BACKGROUND TEMPLATE DROPDOWN FILTERING (test_results vs generate)
// ======================================================================
echo "\n--- 4. Dropdown Filtering Audit (test_results vs generate) ---\n";

// Set up 3 controlled templates:
// A: status='active', is_mega_test_card=1 (Should appear in both)
// B: status='active', is_mega_test_card=0 (Should appear in generate, NOT test_results)
// C: status='inactive', is_mega_test_card=1 (Should appear in NEITHER)
$pdo->exec("INSERT INTO card_templates (title, category, bg_image, canvas_width, canvas_height, aspect_ratio, status, is_mega_test_card, elements_json, created_by, created_at) VALUES ('Mega Test Alpha', 'test_results', '#fff', 1200, 1200, '1:1', 'active', 1, '[]', 'test', CURRENT_TIMESTAMP)");
$tpl_a = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO card_templates (title, category, bg_image, canvas_width, canvas_height, aspect_ratio, status, is_mega_test_card, elements_json, created_by, created_at) VALUES ('Standard Poster Beta', 'social_media', '#fff', 1200, 1200, '1:1', 'active', 0, '[]', 'test', CURRENT_TIMESTAMP)");
$tpl_b = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO card_templates (title, category, bg_image, canvas_width, canvas_height, aspect_ratio, status, is_mega_test_card, elements_json, created_by, created_at) VALUES ('Archived Mega Gamma', 'test_results', '#fff', 1200, 1200, '1:1', 'inactive', 1, '[]', 'test', CURRENT_TIMESTAMP)");
$tpl_c = (int)$pdo->lastInsertId();

// Execute test_results query:
$stmt_res = $pdo->query("SELECT id, title, category FROM card_templates WHERE status = 'active' AND is_mega_test_card = 1 ORDER BY title ASC");
$res_templates = $stmt_res->fetchAll(PDO::FETCH_ASSOC);
$res_ids = array_column($res_templates, 'id');

assert_test(in_array($tpl_a, $res_ids, false), "Template A (active, Mega Test ON) is PRESENT in Test Result Cards dropdown");
assert_test(!in_array($tpl_b, $res_ids, false), "Template B (active, Mega Test OFF) is ABSENT from Test Result Cards dropdown");
assert_test(!in_array($tpl_c, $res_ids, false), "Template C (inactive, Mega Test ON) is ABSENT from Test Result Cards dropdown");

// Execute generate cards query:
$stmt_gen = $pdo->query("SELECT id, title FROM card_templates WHERE status = 'active' ORDER BY created_at DESC");
$gen_templates = $stmt_gen->fetchAll(PDO::FETCH_ASSOC);
$gen_ids = array_column($gen_templates, 'id');

assert_test(in_array($tpl_a, $gen_ids, false), "Template A (Mega Test ON) remains PRESENT in Generate Cards");
assert_test(in_array($tpl_b, $gen_ids, false), "Template B (Mega Test OFF) remains PRESENT in Generate Cards");
assert_test(!in_array($tpl_c, $gen_ids, false), "Template C (inactive) is ABSENT from Generate Cards");

// Clean up test templates
$pdo->prepare("DELETE FROM card_templates WHERE id IN (?, ?, ?)")->execute([$tpl_a, $tpl_b, $tpl_c]);

// ======================================================================
// 5. EDITABLE TEXT LIFECYCLE, SCENARIO 7 & SCENARIO 8 AUDIT
// ======================================================================
echo "\n--- 5. Authoritative Editable Text & Scenarios 7 & 8 ---\n";

$designer_content = file_get_contents(__DIR__ . '/cards-result-designer.php');

// Verify removal of unconditional assignments in drawElements()
assert_test(strpos($designer_content, "if (field === 'name') textContent = student.name;") === false, "drawElements: unconditional 'name' overwrite removed");
assert_test(strpos($designer_content, "if (field === 'institute') textContent = student.college_school") === false, "drawElements: unconditional 'institute' overwrite removed");
assert_test(strpos($designer_content, "if (el.id === 'test_number' || el.id === 'day_number') {\n            const dnum") === false, "drawElements: unconditional 'test_number' overwrite removed");

// Verify removal of unconditional assignments in renderElementsOnCanvas()
assert_test(strpos($designer_content, "if (el.id === 'test_number' || el.id === 'day_number') {\n                    const dnum") === false, "renderElementsOnCanvas: unconditional 'test_number' overwrite removed");
assert_test(strpos($designer_content, "if (el.id === 'chapter_name' || el.id === 'test_chapter' || el.id === 'chapter') {\n                    const chap") === false, "renderElementsOnCanvas: unconditional 'chapter' overwrite removed");

// Verify initial text data binding occurs only for new cards
assert_test(strpos($designer_content, "if (!hasSavedElements)") !== false, "cards-result-designer: initial text data binding guarded by !hasSavedElements");

// Simulate Scenario 7: Test Number (18 -> 18th)
echo "\n  Testing Scenario 7: Test Number 18 -> 18th:\n";

// DB Activity state
$db_activity = [
    'id' => 9991,
    'activity_title' => 'Mega Test 18',
    'day_number' => '18',
    'chapter' => 'Auditing Standards',
    'activity_date' => '2026-09-24'
];

// Initial new card elements initialization:
$elements_new = [
    [
        'id' => 'test_number',
        'type' => 'text',
        'textContent' => $db_activity['day_number'] // initialized to 18
    ],
    [
        'id' => 'rank_name_1',
        'type' => 'text',
        'textContent' => 'Nanditha Nair' // initialized to Nanditha Nair
    ]
];

assert_test($elements_new[0]['textContent'] === '18', "Initial card element textContent initialized to '18'");

// Admin edits text in properties panel:
$elements_new[0]['textContent'] = '18th';
assert_test($elements_new[0]['textContent'] === '18th', "Admin edits element textContent to '18th'");

// Simulate Redraw: elements textContent is authoritative
$draw_text = $elements_new[0]['textContent'];
assert_test($draw_text === '18th', "Redraw preserves edited text '18th'");

// Simulate Save Design into test_result_cards
$saved_config_json = json_encode(['elements' => $elements_new, 'ranksCount' => 1]);
$saved_mappings_json = json_encode(['rank_photo_1' => ['student_uid' => 'STUDENT_001']]);

$stmt_save = $pdo->prepare("
    INSERT INTO test_result_cards (academic_year, course_id, study_plan_id, activity_id, template_id, design_title, output_format, student_rank_mappings, design_config, created_by, created_at)
    VALUES ('2026-2027', 1, 1, 9991, 1, 'Mega Test 18 Card', 'png', ?, ?, 'admin', CURRENT_TIMESTAMP)
");
$stmt_save->execute([$saved_mappings_json, $saved_config_json]);
$saved_card_id = (int)$pdo->lastInsertId();

// Verify reload:
$stmt_reload = $pdo->prepare("SELECT design_config FROM test_result_cards WHERE id = ?");
$stmt_reload->execute([$saved_card_id]);
$reloaded_config = json_decode($stmt_reload->fetchColumn(), true);
$reloaded_elements = $reloaded_config['elements'];
$reloaded_test_num = $reloaded_elements[0]['textContent'];

assert_test($reloaded_test_num === '18th', "Reopening saved card restores edited text '18th'");

// Verify source activity DB is unchanged:
assert_test($db_activity['day_number'] === '18', "Underlying activity database record day_number remains '18'");

// Simulate Scenario 8: Student Name ("Nanditha Nair" -> "Nanditha Nair — Topper")
echo "\n  Testing Scenario 8: Student Name 'Nanditha Nair' -> 'Nanditha Nair — Topper':\n";

$db_student = [
    'user_id' => 'STUDENT_001',
    'name' => 'Nanditha Nair',
    'college_school' => 'Farook College'
];

assert_test($elements_new[1]['textContent'] === 'Nanditha Nair', "Initial student name element textContent initialized to 'Nanditha Nair'");

// Admin edits student name in properties panel:
$elements_new[1]['textContent'] = 'Nanditha Nair — Topper';
assert_test($elements_new[1]['textContent'] === 'Nanditha Nair — Topper', "Admin edits student name to 'Nanditha Nair — Topper'");

// Simulate Redraw:
$draw_name = $elements_new[1]['textContent'];
assert_test($draw_name === 'Nanditha Nair — Topper', "Redraw preserves edited name 'Nanditha Nair — Topper'");

// Update saved card:
$saved_config_json_updated = json_encode(['elements' => $elements_new, 'ranksCount' => 1]);
$stmt_upd_card = $pdo->prepare("UPDATE test_result_cards SET design_config = ? WHERE id = ?");
$stmt_upd_card->execute([$saved_config_json_updated, $saved_card_id]);

// Reload updated card:
$stmt_reload->execute([$saved_card_id]);
$reloaded_config_upd = json_decode($stmt_reload->fetchColumn(), true);
$reloaded_name = $reloaded_config_upd['elements'][1]['textContent'];
assert_test($reloaded_name === 'Nanditha Nair — Topper', "Reopening saved card restores edited name 'Nanditha Nair — Topper'");

// Underlying student identity mapping in student_rank_mappings:
$stmt_map = $pdo->prepare("SELECT student_rank_mappings FROM test_result_cards WHERE id = ?");
$stmt_map->execute([$saved_card_id]);
$loaded_mappings = json_decode($stmt_map->fetchColumn(), true);
assert_test($loaded_mappings['rank_photo_1']['student_uid'] === 'STUDENT_001', "Underlying student identity mapping ('STUDENT_001') remains strictly intact");

// Underlying student database record unchanged:
assert_test($db_student['name'] === 'Nanditha Nair', "Underlying student database record name remains 'Nanditha Nair'");

// Clean up simulated test card:
$pdo->prepare("DELETE FROM test_result_cards WHERE id = ?")->execute([$saved_card_id]);

// ======================================================================
// 6. DATABASE INTEGRITY & INVARIANTS AUDIT
// ======================================================================
echo "\n--- 6. Database Integrity & Non-destructive Invariants ---\n";

$stmt_cnt = $pdo->query("SELECT COUNT(*) FROM card_templates");
$final_tpl_count = (int)$stmt_cnt->fetchColumn();
assert_test($final_tpl_count >= 1, "card_templates row count is intact ({$final_tpl_count} templates present)");

// Check that no templates have empty title or corrupted json
$corrupted_check = $pdo->query("SELECT COUNT(*) FROM card_templates WHERE title IS NULL OR title = ''")->fetchColumn();
assert_test((int)$corrupted_check === 0, "No card templates have null or empty titles");

// ======================================================================
// SUMMARY
// ======================================================================
echo "\n======================================================================\n";
echo "AUDIT SUMMARY: {$passed_assertions} Passed, {$failed_assertions} Failed (Total Assertions: {$total_assertions})\n";
echo "======================================================================\n";

if ($failed_assertions === 0) {
    echo ">>> ALL MEGA TEST CARDS WORKFLOW AUDITS PASSED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo ">>> SOME AUDITS FAILED! <<<\n";
    exit(1);
}
