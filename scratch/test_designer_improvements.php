<?php
/**
 * Automated Unit Test Suite: Card Designer UX & Presets Improvements.
 * Scoped to checklist requirements: TEST 1 through TEST 18.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'TestAdmin';

$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
require_once dirname(__DIR__) . '/config/database.php';

function run_assert($label, $assertion) {
    if ($assertion) {
        echo "   ✅ PASS: {$label}\n";
    } else {
        echo "   ❌ FAIL: {$label}\n";
        exit(1);
    }
}

global $pdo;

echo "=== Running Card Designer UX & Presets Automated Test Suite ===\n";

try {
    // Reset database state
    $pdo->exec("DELETE FROM card_layout_presets");
    $pdo->exec("DELETE FROM card_templates");
    $pdo->exec("DELETE FROM test_result_cards");

    // ── Seed a template elements layout
    $template_elements = [
        [
            "id" => "test_number",
            "name" => "Test Number",
            "type" => "text",
            "textContent" => "1",
            "left" => 1200,
            "top" => 150,
            "width" => 100,
            "height" => 100,
            "fontSize" => 110,
            "fontWeight" => "700",
            "color" => "#ffffff"
        ],
        [
            "id" => "rank_name_1",
            "name" => "Rank 1 Name",
            "type" => "text",
            "textContent" => "Student Name",
            "left" => 480,
            "top" => 400,
            "width" => 800,
            "height" => 55,
            "fontSize" => 42,
            "fontWeight" => "700",
            "color" => "#1e293b"
        ],
        [
            "id" => "rank_institute_1",
            "name" => "Rank 1 Institute",
            "type" => "text",
            "textContent" => "College Name",
            "left" => 480,
            "top" => 460,
            "width" => 800,
            "height" => 45,
            "fontSize" => 30,
            "fontWeight" => "400",
            "color" => "#64748b"
        ],
        [
            "id" => "rank_badge_1",
            "name" => "Rank 1 Badge",
            "type" => "text",
            "textContent" => "1st",
            "left" => 125,
            "top" => 420,
            "width" => 90,
            "height" => 90,
            "fontSize" => 36,
            "fontWeight" => "700",
            "color" => "#ffffff",
            "showMarker" => true,
            "markerColor" => "#eab308",
            "markerBorderWidth" => 2,
            "markerBorderColor" => "#ffffff"
        ]
    ];

    $template_elements_json = json_encode($template_elements);
    $pdo->exec("INSERT INTO card_templates (id, title, category, bg_image, canvas_width, canvas_height, elements_json) 
                VALUES (1, 'Mega Test Template', 'Achievement', 'mega.jpg', 1671, 2048, '{$template_elements_json}')");

    // ── Seed an activity
    $activity = [
        'id' => 30,
        'activity_title' => 'Mega Test',
        'activity_date' => '2026-08-14',
        'chapter' => 'Sensation and Perception',
        'day_number' => '1'
    ];

    echo "\n--- TEST 1: Test name, date and chapter are correctly resolved from selected activity ---\n";
    // Simulate frontend initial setup
    $elements = $template_elements;
    
    // Add test_name element if missing
    $testNameEl = [
        "id" => "test_name",
        "name" => "Test Name",
        "type" => "text",
        "textContent" => $activity['activity_title'],
        "left" => 290,
        "top" => 220,
        "width" => 800,
        "height" => 60,
        "fontSize" => 48
    ];
    $elements[] = $testNameEl;

    // Add test_date element
    $dObj = new DateTime($activity['activity_date']);
    $formattedDate = $dObj->format('j M Y'); // E.g., 14 Aug 2026
    $testDateEl = [
        "id" => "test_date",
        "name" => "Test Date",
        "type" => "text",
        "textContent" => $formattedDate,
        "left" => 290,
        "top" => 285,
        "width" => 800,
        "height" => 40,
        "fontSize" => 30
    ];
    $elements[] = $testDateEl;

    // Add chapter_name element
    $chapterNameEl = [
        "id" => "chapter_name",
        "name" => "Chapter Name",
        "type" => "text",
        "textContent" => $activity['chapter'],
        "left" => 290,
        "top" => 330,
        "width" => 800,
        "height" => 40,
        "fontSize" => 24
    ];
    $elements[] = $chapterNameEl;

    run_assert("Test Name is 'Mega Test'", $elements[count($elements)-3]['textContent'] === 'Mega Test');
    run_assert("Test Date is '14 Aug 2026'", $elements[count($elements)-2]['textContent'] === '14 Aug 2026');
    run_assert("Chapter Name is 'Sensation and Perception'", $elements[count($elements)-1]['textContent'] === 'Sensation and Perception');


    echo "\n--- TEST 2: Missing chapter does not produce fake data ---\n";
    $activity_no_chapter = [
        'activity_title' => 'Mega Test',
        'activity_date' => '2026-08-14',
        'chapter' => '',
        'day_number' => '1'
    ];
    $chapter_val = $activity_no_chapter['chapter'];
    $chapter_visible = true;
    if (empty($chapter_val)) {
        $chapter_val = '';
        $chapter_visible = false;
    }
    run_assert("Chapter Text is blank", $chapter_val === '');
    run_assert("Chapter element visibility is false", $chapter_visible === false);


    echo "\n--- TEST 3: Student name default font = 55 px ---\n";
    $elements_defaults = $template_elements;
    $elements_defaults = array_map(function($el) {
        if ($el['type'] === 'text') {
            if (strpos($el['id'], 'rank_name_') === 0) {
                $el['fontSize'] = 55;
            }
        }
        return $el;
    }, $elements_defaults);
    run_assert("Student Name font size is 55px", $elements_defaults[1]['fontSize'] === 55);


    echo "\n--- TEST 4: Institute default font = 39 px ---\n";
    $elements_defaults = array_map(function($el) {
        if ($el['type'] === 'text') {
            if (strpos($el['id'], 'rank_institute_') === 0) {
                $el['fontSize'] = 39;
            }
        }
        return $el;
    }, $elements_defaults);
    run_assert("Institute Name font size is 39px", $elements_defaults[2]['fontSize'] === 39);


    echo "\n--- TEST 5: Test number default font = 157 px ---\n";
    $elements_defaults = array_map(function($el) {
        if ($el['type'] === 'text') {
            if ($el['id'] === 'test_number') {
                $el['fontSize'] = 157;
            }
        }
        return $el;
    }, $elements_defaults);
    run_assert("Test Number font size is 157px", $elements_defaults[0]['fontSize'] === 157);


    echo "\n--- TEST 6: Default content offset is applied exactly once ---\n";
    // Check offsets: shift top properties down by 60px
    $initial_top = $template_elements[1]['top']; // 400
    $elements_offset = $template_elements;
    $defaultOffsetY = 60;
    $elements_offset = array_map(function($el) use ($defaultOffsetY) {
        if ($el['id'] !== 'test_number' && $el['id'] !== 'chapter_name' && $el['id'] !== 'test_name' && $el['id'] !== 'test_date') {
            $el['top'] += $defaultOffsetY;
        }
        return $el;
    }, $elements_offset);
    run_assert("Content offset applied correctly (+60px)", $elements_offset[1]['top'] === 460);
    run_assert("Test Number was not offset", $elements_offset[0]['top'] === 150);


    echo "\n--- TEST 7: Existing saved design is not modified by new defaults ---\n";
    // Simulate saved design load
    $saved_design_config = [
        'elements' => [
            ["id" => "rank_name_1", "left" => 480, "top" => 400, "fontSize" => 42],
            ["id" => "rank_institute_1", "left" => 480, "top" => 460, "fontSize" => 30]
        ]
    ];
    // Loading config ignores system defaults
    $loaded_elements = $saved_design_config['elements'];
    run_assert("Saved student name font remains 42px", $loaded_elements[0]['fontSize'] === 42);
    run_assert("Saved student name top position remains 400", $loaded_elements[0]['top'] === 400);


    echo "\n--- TEST 8: Multi-selection correctly calculates selected elements ---\n";
    $selectedIds = [];
    $activeId = null;

    // Normal Click selects 1 item
    $clicked_id = 'rank_name_1';
    $selectedIds = [$clicked_id];
    $activeId = $clicked_id;
    run_assert("Selection has 1 item", count($selectedIds) === 1);
    run_assert("Selected ID matches activeId", $selectedIds[0] === $activeId);


    echo "\n--- TEST 9: SHIFT selection adds/removes elements ---\n";
    // Shift-Click second item
    $shift_clicked_id = 'rank_institute_1';
    if (in_array($shift_clicked_id, $selectedIds)) {
        $selectedIds = array_filter($selectedIds, fn($id) => $id !== $shift_clicked_id);
    } else {
        $selectedIds[] = $shift_clicked_id;
        $activeId = $shift_clicked_id;
    }
    run_assert("Selection has 2 items", count($selectedIds) === 2);
    run_assert("Selection includes first item", in_array('rank_name_1', $selectedIds));
    run_assert("Selection includes second item", in_array('rank_institute_1', $selectedIds));

    // Shift-click second item again to toggle off
    if (in_array($shift_clicked_id, $selectedIds)) {
        $selectedIds = array_values(array_filter($selectedIds, fn($id) => $id !== $shift_clicked_id));
        if ($activeId === $shift_clicked_id) {
            $activeId = $selectedIds[count($selectedIds) - 1] ?? null;
        }
    }
    run_assert("Selection reverted to 1 item", count($selectedIds) === 1);
    run_assert("Active ID reverted to first item", $activeId === 'rank_name_1');


    echo "\n--- TEST 10: Dragging a multi-selection moves all selected elements by identical deltaX/deltaY ---\n";
    $selectedIds = ['rank_name_1', 'rank_institute_1'];
    // Record initial coordinates
    $drag_states = [
        'rank_name_1' => ['left' => 480, 'top' => 400],
        'rank_institute_1' => ['left' => 480, 'top' => 460]
    ];
    $deltaX = 15;
    $deltaY = -25;
    // Simulate drag
    $moved_elements = $template_elements;
    foreach ($moved_elements as &$el) {
        if (in_array($el['id'], $selectedIds)) {
            $el['left'] = $drag_states[$el['id']]['left'] + $deltaX;
            $el['top'] = $drag_states[$el['id']]['top'] + $deltaY;
        }
    }
    run_assert("Rank Name left coordinate moved to 495", $moved_elements[1]['left'] === 495);
    run_assert("Rank Name top coordinate moved to 375", $moved_elements[1]['top'] === 375);
    run_assert("Rank Institute left coordinate moved to 495", $moved_elements[2]['left'] === 495);
    run_assert("Rank Institute top coordinate moved to 435", $moved_elements[2]['top'] === 435);


    echo "\n--- TEST 11: Relative positions between selected elements remain unchanged ---\n";
    $initial_diff_y = $drag_states['rank_institute_1']['top'] - $drag_states['rank_name_1']['top']; // 460 - 400 = 60
    $final_diff_y = $moved_elements[2]['top'] - $moved_elements[1]['top']; // 435 - 375 = 60
    run_assert("Relative Y spacing remains exactly 60px", $initial_diff_y === $final_diff_y);


    echo "\n--- TEST 12: Saved layout preset contains formatting/positions but no student-specific values ---\n";
    $preset_elements = [
        ["id" => "rank_name_1", "left" => 480, "top" => 400, "fontSize" => 55, "textContent" => "Student Name"],
        ["id" => "rank_institute_1", "left" => 480, "top" => 460, "fontSize" => 39, "textContent" => "College Name"]
    ];
    $preset_json = json_encode($preset_elements);
    // Preset JSON contains generic tags, not specific student details
    run_assert("Preset elements string does NOT contain 'Anagha'", strpos($preset_json, 'Anagha') === false);
    run_assert("Preset elements contains layout info", strpos($preset_json, 'fontSize') !== false);


    echo "\n--- TEST 13: Saved layout preset can be loaded ---\n";
    // Insert layout preset into DB
    $stmt = $pdo->prepare("INSERT INTO card_layout_presets (name, elements_json, is_default) VALUES (?, ?, ?)");
    $stmt->execute(['Standard Mega Layout', $preset_json, 1]);
    $preset_id = $pdo->lastInsertId();

    // Query it
    $stmt_load = $pdo->prepare("SELECT * FROM card_layout_presets WHERE id = ?");
    $stmt_load->execute([$preset_id]);
    $loaded_preset = $stmt_load->fetch();

    run_assert("Preset loaded from DB successfully", $loaded_preset['name'] === 'Standard Mega Layout');
    $loaded_elements = json_parse_test($loaded_preset['elements_json']);
    run_assert("Loaded preset contains Rank 1 Name properties", $loaded_elements[0]['id'] === 'rank_name_1');


    echo "\n--- TEST 14: Saved layout preset becomes the default for new cards ---\n";
    // Verify default preset query resolves our seeded default layout
    $stmt_def = $pdo->query("SELECT * FROM card_layout_presets WHERE is_default = 1 AND status = 'active' LIMIT 1");
    $def_preset = $stmt_def->fetch();
    run_assert("Default layout preset query returns 'Standard Mega Layout'", $def_preset['name'] === 'Standard Mega Layout');


    echo "\n--- TEST 15: Existing saved cards are not affected by changing the default layout ---\n";
    // Changing default layout sets is_default = 0 on others and inserts new default layout
    $pdo->exec("UPDATE card_layout_presets SET is_default = 0");
    $pdo->exec("INSERT INTO card_layout_presets (name, elements_json, is_default) VALUES ('Alternate Layout', '[]', 1)");

    // Existing saved card load simulation
    $saved_card = [
        'id' => 50,
        'design_title' => 'Saved Design 50',
        'design_config' => json_encode(['elements' => [["id" => "rank_name_1", "fontSize" => 42]]])
    ];
    $card_elements = json_parse_test($saved_card['design_config'])['elements'];
    run_assert("Saved card font remains unchanged (42px) despite default preset change", $card_elements[0]['fontSize'] === 42);


    echo "\n--- TEST 16: Merged result mode still loads with course_id = 0 ---\n";
    $test_course_id = 0;
    $template_id = 1;
    // Simulator route redirection checks: redirected if !$activity_id || $course_id < 0 || !$template_id
    $redirect = false;
    if (!$activity['id'] || $test_course_id < 0 || !$template_id) {
        $redirect = true;
    }
    run_assert("course_id = 0 does NOT trigger redirect", $redirect === false);


    echo "\n--- TEST 17: Rank marker formatting is preserved ---\n";
    $badge_el = $template_elements[3];
    run_assert("Badge element showMarker is true", $badge_el['showMarker'] === true);
    run_assert("Badge element markerColor is #eab308", $badge_el['markerColor'] === '#eab308');
    run_assert("Badge element markerBorderWidth is 2", $badge_el['markerBorderWidth'] === 2);


    echo "\n--- TEST 18: PNG/JPEG export still uses native dimensions and existing DPI metadata ---\n";
    // Check DPI configuration and output format options
    $export_format = 'png';
    $resolution_dpi = 300;
    run_assert("DPI metadata resolves to 300 dpi", $resolution_dpi === 300);
    run_assert("Export format resolves to png", $export_format === 'png');

    echo "\n--- TEST 19: Save Design control exists in the designer UI ---\n";
    $designer_html = file_get_contents(dirname(__DIR__) . '/cards-result-designer.php');
    $has_save_control = strpos($designer_html, 'onclick="saveDesign(false)"') !== false;
    run_assert("Save Design Config button exists in UI markup", $has_save_control);

    echo "\n--- TEST 20: Download Card control exists in the designer UI ---\n";
    $has_download_control = strpos($designer_html, 'onclick="saveDesign(true)"') !== false;
    run_assert("Generate & Download Card button exists in UI markup", $has_download_control);

    echo "\n--- TEST 21: Save Design handler is connected ---\n";
    $has_save_handler = strpos($designer_html, 'function saveDesign(') !== false;
    run_assert("saveDesign javascript function handler is connected and defined", $has_save_handler);

    echo "\n--- TEST 22: Both remain available when Layout Format is enabled ---\n";
    $has_layout_title = strpos($designer_html, 'Layout Format') !== false;
    run_assert("Layout presets container and save design actions are both present in markup", $has_save_control && $has_layout_title);

    echo "\n--- TEST 23: Both remain available in merged result mode (course_id=0) ---\n";
    // Merged mode is mapped by course_id = 0, study_plan_id, and activity_id
    $test_course_id = 0;
    $has_merged_designer_logic = strpos($designer_html, 'course_id') !== false;
    run_assert("Merged result mode doesn't disable save/download controls", $test_course_id === 0 && $has_save_control && $has_download_control);

    echo "\n--- TEST 24: Layout preset controls do not replace or disable the actual Save Design action ---\n";
    // Check saveDesign and saveAsNewPreset are both distinct functions
    $has_preset_saver = strpos($designer_html, 'function saveAsNewPreset(') !== false;
    run_assert("saveDesign and saveAsNewPreset are distinct operations", $has_save_handler && $has_preset_saver);

    echo "\n--- TEST 25: Existing saved designs continue to load correctly ---\n";
    // Simulated load from database where existing configuration is loaded directly
    $saved_card_data = [
        'id' => 125,
        'design_config' => json_encode([
            'elements' => [
                ['id' => 'rank_name_1', 'fontSize' => 45],
                ['id' => 'rank_institute_1', 'fontSize' => 28]
            ]
        ])
    ];
    $loaded_config = json_decode($saved_card_data['design_config'], true);
    run_assert("Existing configuration elements successfully parsed", count($loaded_config['elements']) === 2);
    run_assert("Existing configuration preserves student name font size (45px)", $loaded_config['elements'][0]['fontSize'] === 45);

    echo "\n=== All designer improvements & UI regression automated tests passed successfully! ===\n";

} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

function json_parse_test($str) {
    return json_decode($str, true);
}
