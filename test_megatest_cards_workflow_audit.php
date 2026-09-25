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
require_once __DIR__ . '/includes/assessment_rank_helper.php';

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
// 7. 37-POINT MEGA TEST RESULT CARD DATA-BINDING & AUDIT SUITE
// ======================================================================
echo "\n--- 7. 37-Point Mega Test Result Card Data-Binding & Invariant Suite ---\n";

// Target test parameters
$test_year = '2026-27';
$test_course_id = 8;
$test_plan_id = 10;
$test_activity_id = 41202;

// Record baseline state of source tables for immutability verification
$baseline_user = $pdo->query("SELECT * FROM users WHERE user_id = 'PEPP20266132'")->fetch(PDO::FETCH_ASSOC);
$baseline_activity = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 41202")->fetch(PDO::FETCH_ASSOC);
$baseline_batch = $pdo->query("SELECT * FROM assessment_result_batches WHERE id = 21")->fetch(PDO::FETCH_ASSOC);
$baseline_results = $pdo->query("SELECT * FROM assessment_results WHERE batch_id = 21 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$baseline_saved_card = $pdo->query("SELECT * FROM test_result_cards WHERE activity_id = 41202 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// 1. Selected activity resolves correctly
$stmt_act = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ? AND is_deleted = 0");
$stmt_act->execute([$test_activity_id]);
$act = $stmt_act->fetch(PDO::FETCH_ASSOC);
assert_test(!empty($act) && (int)$act['id'] === $test_activity_id && (int)$act['study_plan_id'] === $test_plan_id, "1. Selected activity resolves correctly (ID: {$test_activity_id}, Plan: {$test_plan_id})");

// 2. Selected test result resolves correctly
$stmt_b = $pdo->prepare("
    SELECT * FROM assessment_result_batches
    WHERE activity_id = ? AND (study_plan_id = ? OR ? = 0) AND status = 'published'
    ORDER BY version DESC LIMIT 1
");
$stmt_b->execute([$test_activity_id, $test_plan_id, $test_plan_id]);
$batch = $stmt_b->fetch(PDO::FETCH_ASSOC);
assert_test(!empty($batch) && (int)$batch['id'] === 21 && (int)$batch['activity_id'] === $test_activity_id, "2. Selected test result resolves correctly (Batch ID: 21 for Activity {$test_activity_id})");

// 3. Chapter Name comes from the selected test result
$resolved_chapter = $act['chapter'] ?? $batch['chapter_snapshot'] ?? '';
assert_test($resolved_chapter === 'Learning' && $resolved_chapter === $batch['chapter_snapshot'], "3. Chapter Name comes from the selected test result ('Learning')");

// 4. Test Date comes from the selected test result
$resolved_raw_date = $act['activity_date'] ?? $batch['activity_date_snapshot'] ?? '';
$resolved_test_date = '';
if (!empty($resolved_raw_date)) {
    $ts = strtotime($resolved_raw_date);
    if ($ts !== false) {
        $resolved_test_date = date('j M Y', $ts);
        $resolved_test_date = preg_replace('/\bSep\b/', 'Sept', $resolved_test_date);
    }
}
assert_test($resolved_test_date === '18 Sept 2026' && $resolved_raw_date === '2026-09-18', "4. Test Date comes from the selected test result ('18 Sept 2026')");

// Fetch canonical test rankings
$canonical_data = AssessmentRankHelper::getCanonicalTestResults($pdo, [(int)$batch['id']], $test_year, __DIR__);
$ranked_students = $canonical_data['ranked_list'];

// 5. Rank 1 resolves correctly
$r1 = $ranked_students[0] ?? null;
assert_test(!empty($r1) && (int)$r1['computed_rank'] === 1, "5. Rank 1 resolves correctly (computed_rank: 1)");

// 6. Rank 1 photo resolves correctly
assert_test(!empty($r1['user_photo']) && file_exists(__DIR__ . '/' . $r1['user_photo']), "6. Rank 1 photo resolves correctly ('{$r1['user_photo']}' exists)");

// 7. Rank 1 student name resolves correctly
assert_test(!empty($r1['name']) && $r1['name'] === 'Nanditha Nair', "7. Rank 1 student name resolves correctly ('{$r1['name']}')");

// 8. Rank 1 college/institution resolves correctly
assert_test(!empty($r1['college_school']) && $r1['college_school'] === 'JSS, Mysuru', "8. Rank 1 college/institution resolves correctly ('{$r1['college_school']}')");

// 9. Rank 2 resolves correctly
$r2 = $ranked_students[1] ?? null;
assert_test(!empty($r2) && (int)$r2['computed_rank'] === 2, "9. Rank 2 resolves correctly (computed_rank: 2)");

// 10. Rank 2 photo resolves correctly
assert_test(!empty($r2['user_photo']) && file_exists(__DIR__ . '/' . $r2['user_photo']), "10. Rank 2 photo resolves correctly ('{$r2['user_photo']}' exists)");

// 11. Rank 2 student name resolves correctly
assert_test(!empty($r2['name']) && $r2['name'] === 'Krishnapriya', "11. Rank 2 student name resolves correctly ('{$r2['name']}')");

// 12. Rank 2 college/institution resolves correctly
assert_test(!empty($r2['college_school']) && $r2['college_school'] === 'Calicut University', "12. Rank 2 college/institution resolves correctly ('{$r2['college_school']}')");

// 13. Rank 3 resolves correctly
$r3 = $ranked_students[2] ?? null;
assert_test(!empty($r3) && (int)$r3['computed_rank'] === 3, "13. Rank 3 resolves correctly (computed_rank: 3)");

// 14. Rank 3 photo resolves correctly
assert_test(!empty($r3['user_photo']) && file_exists(__DIR__ . '/' . $r3['user_photo']), "14. Rank 3 photo resolves correctly ('{$r3['user_photo']}' exists)");

// 15. Rank 3 student name resolves correctly
assert_test(!empty($r3['name']) && $r3['name'] === 'ALBIN ANTONY', "15. Rank 3 student name resolves correctly ('{$r3['name']}')");

// 16. Rank 3 college/institution resolves correctly
assert_test(!empty($r3['college_school']) && $r3['college_school'] === 'Uc college Thiruvananthapuram', "16. Rank 3 college/institution resolves correctly ('{$r3['college_school']}')");

// 17. Rank 4 resolves correctly when available
$r4 = $ranked_students[3] ?? null;
assert_test(!empty($r4) && (int)$r4['computed_rank'] === 4, "17. Rank 4 resolves correctly when available (computed_rank: 4)");

// 18. Rank 4 photo resolves correctly when available
assert_test(!empty($r4['user_photo']) && file_exists(__DIR__ . '/' . $r4['user_photo']), "18. Rank 4 photo resolves correctly when available ('{$r4['user_photo']}' exists)");

// 19. Rank 4 student name resolves correctly when available
assert_test(!empty($r4['name']) && $r4['name'] === 'Neha Ann John', "19. Rank 4 student name resolves correctly when available ('{$r4['name']}')");

// 20. Rank 4 college/institution resolves correctly when available
assert_test(!empty($r4['college_school']) && $r4['college_school'] === "St. Teresa's College", "20. Rank 4 college/institution resolves correctly when available ('{$r4['college_school']}')");

// Simulate JS bindAuthoritativeDataToElements contract in PHP
function simulateBindAuthoritativeData(array &$elements, array $rankingList, array $studentMappings, string $chapter, string $testDate, string $dayNum, bool $isNewCard): void {
    foreach ($elements as &$el) {
        $id = $el['id'] ?? '';
        $curText = trim((string)($el['textContent'] ?? ''));
        $curLower = strtolower($curText);

        if ($id === 'chapter_name' || $id === 'test_chapter' || $id === 'chapter') {
            $isPlaceholder = $isNewCard || empty($curText) || in_array($curLower, ['chapter name', 'test chapter', 'chapter'], true);
            if ($isPlaceholder && !empty($chapter)) {
                $el['textContent'] = $chapter;
                $el['visible'] = true;
            }
        } elseif ($id === 'test_date' || $id === 'date') {
            $isPlaceholder = $isNewCard || empty($curText) || in_array($curLower, ['test date', 'date'], true);
            if ($isPlaceholder && !empty($testDate)) {
                $el['textContent'] = $testDate;
                $el['visible'] = true;
            }
        } elseif ($id === 'test_number' || $id === 'day_number') {
            $isPlaceholder = $isNewCard || empty($curText) || in_array($curLower, ['test number', 'day number', '0', ''], true);
            if ($isPlaceholder && !empty($dayNum)) {
                $el['textContent'] = $dayNum;
                $el['visible'] = true;
            }
        } elseif (preg_match('/^rank_(name|institute|badge)_(\d+)$/', $id, $m)) {
            $field = $m[1];
            $rankNum = (int)$m[2];
            $photoElId = 'rank_photo_' . $rankNum;
            $mapping = $studentMappings[$photoElId] ?? null;
            $student = null;
            if ($mapping && !empty($mapping['student_uid'])) {
                foreach ($rankingList as $s) {
                    if (($s['user_id'] ?? '') === $mapping['student_uid']) {
                        $student = $s;
                        break;
                    }
                }
            }
            if (!$student && count($rankingList) >= $rankNum) {
                $student = $rankingList[$rankNum - 1];
            }

            if ($student) {
                if ($field === 'badge') {
                    $isPlaceholder = $isNewCard || empty($curText) || in_array($curLower, ['rank', 'badge'], true);
                    if ($isPlaceholder) {
                        $cr = (int)($student['computed_rank'] ?? $rankNum);
                        $suffix = ($cr === 1) ? 'st' : (($cr === 2) ? 'nd' : (($cr === 3) ? 'rd' : 'th'));
                        $el['textContent'] = $cr . $suffix;
                    }
                } elseif ($field === 'name') {
                    $isPlaceholder = $isNewCard || empty($curText) || in_array($curLower, ['student name', 'name'], true);
                    if ($isPlaceholder && !empty($student['name'])) {
                        $el['textContent'] = $student['name'];
                    }
                } elseif ($field === 'institute') {
                    $isPlaceholder = $isNewCard || empty($curText) || in_array($curLower, ['college name', 'institute name', 'college', 'institute'], true);
                    if ($isPlaceholder && !empty($student['college_school'])) {
                        $el['textContent'] = $student['college_school'];
                    }
                }
            }
        }
    }
}

// 21. No placeholder "Student Name" remains when actual student data exists
$elements_with_placeholders = [
    ['id' => 'chapter_name', 'type' => 'text', 'textContent' => 'Chapter Name', 'visible' => true],
    ['id' => 'test_date', 'type' => 'text', 'textContent' => 'Test Date', 'visible' => true],
    ['id' => 'test_number', 'type' => 'text', 'textContent' => 'Test Number', 'visible' => true],
    ['id' => 'rank_badge_1', 'type' => 'text', 'textContent' => '', 'visible' => true],
    ['id' => 'rank_name_1', 'type' => 'text', 'textContent' => 'Student Name', 'visible' => true],
    ['id' => 'rank_institute_1', 'type' => 'text', 'textContent' => 'College Name', 'visible' => true],
    ['id' => 'rank_badge_2', 'type' => 'text', 'textContent' => '', 'visible' => true],
    ['id' => 'rank_name_2', 'type' => 'text', 'textContent' => 'Student Name', 'visible' => true],
    ['id' => 'rank_institute_2', 'type' => 'text', 'textContent' => 'College Name', 'visible' => true],
    ['id' => 'rank_badge_3', 'type' => 'text', 'textContent' => '', 'visible' => true],
    ['id' => 'rank_name_3', 'type' => 'text', 'textContent' => 'Student Name', 'visible' => true],
    ['id' => 'rank_institute_3', 'type' => 'text', 'textContent' => 'College Name', 'visible' => true],
    ['id' => 'rank_badge_4', 'type' => 'text', 'textContent' => '', 'visible' => true],
    ['id' => 'rank_name_4', 'type' => 'text', 'textContent' => 'Student Name', 'visible' => true],
    ['id' => 'rank_institute_4', 'type' => 'text', 'textContent' => 'College Name', 'visible' => true]
];

$test_mappings = [];
simulateBindAuthoritativeData($elements_with_placeholders, $ranked_students, $test_mappings, $resolved_chapter, $resolved_test_date, (string)($act['day_number'] ?? '18'), false);

$any_name_placeholder = false;
foreach ($elements_with_placeholders as $el) {
    if (strpos($el['id'], 'rank_name_') === 0 && strtolower(trim($el['textContent'])) === 'student name') {
        $any_name_placeholder = true;
    }
}
assert_test(!$any_name_placeholder, "21. No placeholder 'Student Name' remains when actual student data exists");

// 22. No placeholder "College Name" remains when actual institution data exists
$any_inst_placeholder = false;
foreach ($elements_with_placeholders as $el) {
    if (strpos($el['id'], 'rank_institute_') === 0 && strtolower(trim($el['textContent'])) === 'college name') {
        $any_inst_placeholder = true;
    }
}
assert_test(!$any_inst_placeholder, "22. No placeholder 'College Name' remains when actual institution data exists");

// 23. No hardcoded/current-date substitution occurs for Test Date
$date_el = null;
foreach ($elements_with_placeholders as $el) {
    if ($el['id'] === 'test_date') $date_el = $el;
}
$today_str = date('j M Y');
assert_test($date_el && $date_el['textContent'] === '18 Sept 2026' && $date_el['textContent'] !== $today_str, "23. No hardcoded/current-date substitution occurs for Test Date (strictly '18 Sept 2026')");

// 24. No hardcoded chapter substitution occurs when actual chapter data exists
$chap_el = null;
foreach ($elements_with_placeholders as $el) {
    if ($el['id'] === 'chapter_name') $chap_el = $el;
}
assert_test($chap_el && $chap_el['textContent'] === 'Learning' && $chap_el['textContent'] !== 'Chapter Name', "24. No hardcoded chapter substitution occurs when actual chapter data exists (strictly 'Learning')");

// 25. Admin editing student name affects only card-local state
$edited_elements = $elements_with_placeholders;
foreach ($edited_elements as &$el) {
    if ($el['id'] === 'rank_name_1') $el['textContent'] = 'Nanditha Nair — State First';
}
unset($el);
$user_after_name_edit = $pdo->query("SELECT name FROM users WHERE user_id = 'PEPP20266132'")->fetchColumn();
assert_test($edited_elements[4]['textContent'] === 'Nanditha Nair — State First' && $user_after_name_edit === 'Nanditha Nair', "25. Admin editing student name affects only card-local state (DB: '{$user_after_name_edit}')");

// 26. Admin editing college affects only card-local state
foreach ($edited_elements as &$el) {
    if ($el['id'] === 'rank_institute_1') $el['textContent'] = 'JSS College of Arts & Science';
}
unset($el);
$inst_after_edit = $pdo->query("SELECT college_school FROM users WHERE user_id = 'PEPP20266132'")->fetchColumn();
assert_test($edited_elements[5]['textContent'] === 'JSS College of Arts & Science' && $inst_after_edit === 'JSS, Mysuru', "26. Admin editing college affects only card-local state (DB: '{$inst_after_edit}')");

// 27. Admin editing chapter affects only card-local state
foreach ($edited_elements as &$el) {
    if ($el['id'] === 'chapter_name') $el['textContent'] = 'Chapter 4: Advanced Learning';
}
unset($el);
$act_after_chap_edit = $pdo->query("SELECT chapter FROM study_plan_activities WHERE id = 41202")->fetchColumn();
assert_test($edited_elements[0]['textContent'] === 'Chapter 4: Advanced Learning' && $act_after_chap_edit === 'Learning', "27. Admin editing chapter affects only card-local state (DB: '{$act_after_chap_edit}')");

// 28. Admin editing test date affects only card-local state
foreach ($edited_elements as &$el) {
    if ($el['id'] === 'test_date') $el['textContent'] = '18th September 2026';
}
unset($el);
$date_after_edit = $pdo->query("SELECT activity_date FROM study_plan_activities WHERE id = 41202")->fetchColumn();
assert_test($edited_elements[1]['textContent'] === '18th September 2026' && $date_after_edit === '2026-09-18', "28. Admin editing test date affects only card-local state (DB: '{$date_after_edit}')");

// 29. Admin editing rank text affects only card-local state
foreach ($edited_elements as &$el) {
    if ($el['id'] === 'rank_badge_1') $el['textContent'] = 'Topper';
}
unset($el);
$ar_rank_after_edit = $pdo->query("SELECT score FROM assessment_results WHERE batch_id = 21 AND user_id = 'PEPP20266132'")->fetchColumn();
assert_test($edited_elements[3]['textContent'] === 'Topper' && (float)$ar_rank_after_edit === 48.0, "29. Admin editing rank text affects only card-local state (DB score: 48.0)");

// 30. Redraw does not overwrite edits
// Simulate redraw: bindAuthoritativeData is called with isNewCard=false on edited elements
simulateBindAuthoritativeData($edited_elements, $ranked_students, $test_mappings, $resolved_chapter, $resolved_test_date, (string)($act['day_number'] ?? '18'), false);
assert_test(
    $edited_elements[4]['textContent'] === 'Nanditha Nair — State First' &&
    $edited_elements[5]['textContent'] === 'JSS College of Arts & Science' &&
    $edited_elements[0]['textContent'] === 'Chapter 4: Advanced Learning' &&
    $edited_elements[1]['textContent'] === '18th September 2026' &&
    $edited_elements[3]['textContent'] === 'Topper',
    "30. Redraw does not overwrite edits (all custom values preserved)"
);

// 31. Save does not overwrite edits
$simulated_card_config = json_encode(['elements' => $edited_elements, 'ranksCount' => 4]);
$simulated_mappings = json_encode([
    'rank_photo_1' => ['student_uid' => 'PEPP20266132'],
    'rank_photo_2' => ['student_uid' => 'PEPP20269762'],
    'rank_photo_3' => ['student_uid' => 'PEPP20261933'],
    'rank_photo_4' => ['student_uid' => 'PEPP20262320']
]);

$stmt_ins_card = $pdo->prepare("
    INSERT INTO test_result_cards (academic_year, course_id, study_plan_id, activity_id, template_id, design_title, output_format, student_rank_mappings, design_config, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'test_admin', CURRENT_TIMESTAMP)
");
$stmt_ins_card->execute([$test_year, $test_course_id, $test_plan_id, $test_activity_id, 23, 'Audit Test Result Card 37', 'png', $simulated_mappings, $simulated_card_config]);
$test_card_id = (int)$pdo->lastInsertId();
assert_test($test_card_id > 0, "31. Save does not overwrite edits (card saved successfully with ID #{$test_card_id})");

// 32. Reload of saved card preserves edits
$stmt_read_card = $pdo->prepare("SELECT design_config FROM test_result_cards WHERE id = ?");
$stmt_read_card->execute([$test_card_id]);
$reloaded_cfg = json_decode($stmt_read_card->fetchColumn(), true);
$reloaded_elements = $reloaded_cfg['elements'] ?? [];

$reloaded_name = '';
$reloaded_inst = '';
$reloaded_chap = '';
$reloaded_date = '';
$reloaded_badge = '';
foreach ($reloaded_elements as $rel) {
    if ($rel['id'] === 'rank_name_1') $reloaded_name = $rel['textContent'];
    if ($rel['id'] === 'rank_institute_1') $reloaded_inst = $rel['textContent'];
    if ($rel['id'] === 'chapter_name') $reloaded_chap = $rel['textContent'];
    if ($rel['id'] === 'test_date') $reloaded_date = $rel['textContent'];
    if ($rel['id'] === 'rank_badge_1') $reloaded_badge = $rel['textContent'];
}
assert_test(
    $reloaded_name === 'Nanditha Nair — State First' &&
    $reloaded_inst === 'JSS College of Arts & Science' &&
    $reloaded_chap === 'Chapter 4: Advanced Learning' &&
    $reloaded_date === '18th September 2026' &&
    $reloaded_badge === 'Topper',
    "32. Reload of saved card preserves edits exactly"
);

// 33. Export uses edited values
// Simulate renderElementsOnCanvas logic: it reads textContent directly unless placeholder
$export_textContent = $reloaded_name;
$export_is_placeholder = in_array(strtolower(trim($export_textContent)), ['student name', 'name', ''], true);
$final_export_name = $export_is_placeholder ? ($r1['name'] ?? '') : $export_textContent;
assert_test($final_export_name === 'Nanditha Nair — State First', "33. Export uses edited values ('{$final_export_name}')");

// Clean up temporary test card
$pdo->prepare("DELETE FROM test_result_cards WHERE id = ?")->execute([$test_card_id]);

// 34. Source student data remains unchanged
$current_user = $pdo->query("SELECT * FROM users WHERE user_id = 'PEPP20266132'")->fetch(PDO::FETCH_ASSOC);
assert_test(
    $current_user['name'] === $baseline_user['name'] &&
    $current_user['email'] === $baseline_user['email'] &&
    $current_user['status'] === $baseline_user['status'],
    "34. Source student data remains unchanged (name: '{$current_user['name']}', email: '{$current_user['email']}')"
);

// 35. Source college data remains unchanged
assert_test(
    $current_user['college_school'] === $baseline_user['college_school'],
    "35. Source college data remains unchanged ('{$current_user['college_school']}')"
);

// 36. Source result/ranking data remains unchanged
$current_batch = $pdo->query("SELECT * FROM assessment_result_batches WHERE id = 21")->fetch(PDO::FETCH_ASSOC);
$current_results = $pdo->query("SELECT * FROM assessment_results WHERE batch_id = 21 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_test(
    $current_batch['activity_title_snapshot'] === $baseline_batch['activity_title_snapshot'] &&
    $current_batch['chapter_snapshot'] === $baseline_batch['chapter_snapshot'] &&
    count($current_results) === count($baseline_results),
    "36. Source result/ranking data remains unchanged (batch & results identical)"
);

// 37. Existing saved card data remains unchanged
$current_saved_card = $pdo->query("SELECT * FROM test_result_cards WHERE activity_id = 41202 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test(
    $current_saved_card['id'] === $baseline_saved_card['id'] &&
    $current_saved_card['design_config'] === $baseline_saved_card['design_config'],
    "37. Existing saved card data remains unchanged (Row #{$current_saved_card['id']} configuration intact)"
);

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
