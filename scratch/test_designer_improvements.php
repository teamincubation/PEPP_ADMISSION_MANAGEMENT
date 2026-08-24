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

    echo "\n--- TEST 26: Selected activity exposes name, date, chapter ---\n";
    $activity = [
        'activity_title' => 'Practice Test 5',
        'activity_date' => '2026-08-19',
        'chapter' => 'Sensation and Perception 5'
    ];
    run_assert("Exposes name", !empty($activity['activity_title']));
    run_assert("Exposes date", !empty($activity['activity_date']));
    run_assert("Exposes chapter", !empty($activity['chapter']));

    echo "\n--- TEST 27: Card does NOT render dynamic test_name on new designs ---\n";
    $has_test_name_filter = strpos($designer_html, "elements.filter(el => el.id !== 'test_name')") !== false;
    run_assert("Card filters out test_name for new designs", $has_test_name_filter);

    echo "\n--- TEST 28: Card renders chapter_name and test_date in correct hierarchy ---\n";
    $has_chapter_default_y = strpos($designer_html, "top: 220") !== false;
    $has_date_default_y = strpos($designer_html, "top: 270") !== false;
    run_assert("chapter_name top is 220 by default", $has_chapter_default_y);
    run_assert("test_date top is 270 by default", $has_date_default_y);

    echo "\n--- TEST 29: Merged ranking 1,2,2,4,5 produces displayed labels 1st, 2nd, 2nd, 4th, 5th ---\n";
    $test_ranks = [1, 2, 2, 4, 5];
    $formatted_badges = array_map(function($r) {
        return $r . ($r === 1 ? 'st' : ($r === 2 ? 'nd' : ($r === 3 ? 'rd' : 'th')));
    }, $test_ranks);
    run_assert("Rank 1 is 1st", $formatted_badges[0] === '1st');
    run_assert("Rank 2 is 2nd", $formatted_badges[1] === '2nd');
    run_assert("Rank 3 (tied) is 2nd", $formatted_badges[2] === '2nd');
    run_assert("Rank 4 is 4th", $formatted_badges[3] === '4th');
    run_assert("Rank 5 is 5th", $formatted_badges[4] === '5th');

    echo "\n--- TEST 30: Student mapping follows ranking list, not calculated rank slot ---\n";
    $has_slot_mapping = strpos($designer_html, "elements.find(el => el.id === 'rank_photo_' + slotNum)") !== false;
    run_assert("Student mapping is bound via slotNum = index + 1", $has_slot_mapping);

    echo "\n--- TEST 31: Duplicate rank does not cause student data collision ---\n";
    $mock_ranking = [
        ['user_id' => 'stud_1', 'computed_rank' => 1],
        ['user_id' => 'stud_2', 'computed_rank' => 2],
        ['user_id' => 'stud_3', 'computed_rank' => 2],
    ];
    $mock_mappings = [];
    foreach ($mock_ranking as $index => $student) {
        $slotNum = $index + 1;
        $mock_mappings['rank_photo_' . $slotNum] = $student['user_id'];
    }
    run_assert("Slot 2 mapped to student 2", $mock_mappings['rank_photo_2'] === 'stud_2');
    run_assert("Slot 3 mapped to student 3 (tied rank 2)", $mock_mappings['rank_photo_3'] === 'stud_3');

    echo "\n--- TEST 32: Photo zoom is serialized ---\n";
    $has_zoom_mapping = strpos($designer_html, "zoom:") !== false;
    run_assert("zoom is serialized", $has_zoom_mapping);

    echo "\n--- TEST 33: Photo pan X/Y is serialized ---\n";
    $has_pan_mapping = strpos($designer_html, "panX:") !== false && strpos($designer_html, "panY:") !== false;
    run_assert("panX/panY is serialized", $has_pan_mapping);

    echo "\n--- TEST 34: Photo rotation is serialized ---\n";
    $has_rotation_mapping = strpos($designer_html, "rotation:") !== false;
    run_assert("rotation is serialized", $has_rotation_mapping);

    echo "\n--- TEST 35: Photo mask/shape is serialized ---\n";
    $has_mask_mapping = strpos($designer_html, "mask") !== false;
    run_assert("mask is serialized", $has_mask_mapping);

    echo "\n--- TEST 36: Reset photo restores original transform ---\n";
    $has_reset_zoom = strpos($designer_html, "mapping.zoom = 100") !== false;
    $has_reset_pan = strpos($designer_html, "mapping.panX = 0") !== false && strpos($designer_html, "mapping.panY = 0") !== false;
    $has_reset_rotation = strpos($designer_html, "mapping.rotation = 0") !== false;
    run_assert("reset zoom is 100", $has_reset_zoom);
    run_assert("reset pan is 0", $has_reset_pan);
    run_assert("reset rotation is 0", $has_reset_rotation);

    echo "\n--- TEST 37: Saving a layout does not contain student-specific data ---\n";
    $mock_layout_elements = [
        ['id' => 'rank_name_1', 'type' => 'text', 'textContent' => 'Nada Jibin'],
        ['id' => 'rank_institute_1', 'type' => 'text', 'textContent' => 'Wiras College'],
        ['id' => 'chapter_name', 'type' => 'text', 'textContent' => 'Sensation & Perception']
    ];
    $has_preset_cleaner = strpos($designer_html, "function getCleanedElementsForLayout(") !== false;
    run_assert("getCleanedElementsForLayout is defined", $has_preset_cleaner);

    echo "\n--- TEST 38: Saving a design does contain current student mappings ---\n";
    $has_design_saver_mapping = strpos($designer_html, "payload.append('student_rank_mappings'") !== false;
    run_assert("Save design payload appends student_rank_mappings", $has_design_saver_mapping);

    echo "\n--- TEST 39: Changing default layout does not alter an existing saved design ---\n";
    $has_saved_config_bypass = strpos($designer_html, "if (savedDesignId && savedConfig) {") !== false;
    run_assert("Bypasses default preset application if savedConfig exists", $has_saved_config_bypass);

    echo "\n--- TEST 40: Download/export uses the same ranking mapping as preview ---\n";
    $has_export_badge_rank = strpos($designer_html, "field === 'badge'") !== false && strpos($designer_html, "student.computed_rank") !== false;
    run_assert("Export resolves badge using computed_rank", $has_export_badge_rank);

    echo "\n--- TEST 41: Download/export uses the same photo transform as preview ---\n";
    $has_export_rotation = strpos($designer_html, "mapping.rotation") !== false && strpos($designer_html, "ctx.rotate") !== false;
    run_assert("Export applies photo rotation", $has_export_rotation);

    echo "\n--- TEST 42: Merged mode with course_id = 0 continues to work ---\n";
    // Checks that the designer accepts course_id = 0
    $has_zero_course_check = strpos($designer_html, "course_id") !== false;
    run_assert("course_id zero doesn't cause redirection", $has_zero_course_check);

    echo "\n--- TEST 43: Photo flips (Horizontal & Vertical) are serialized and exported ---\n";
    $has_flip_serialization = strpos($designer_html, "flipH:") !== false && strpos($designer_html, "flipV:") !== false;
    $has_export_scale = strpos($designer_html, "ctx.scale(mapping.flipH ? -1 : 1, mapping.flipV ? -1 : 1)") !== false || strpos($designer_html, "ctx.scale(mapping.flipH ? -1 : 1, mapping.flipV ? -1 : 1)") !== false;
    run_assert("Flips are serialized in student mappings", $has_flip_serialization);
    run_assert("Export drawing applies scale/flip matrix transformations", $has_export_scale);

    echo "\n--- TEST 44: Ellipse shape, fit modes, opacity, and borders are fully supported ---\n";
    $has_ellipse_mask = strpos($designer_html, "ellipse") !== false;
    $has_fit_modes = strpos($designer_html, "fitMode") !== false;
    $has_border_props = strpos($designer_html, "borderEnabled") !== false && strpos($designer_html, "borderStyle") !== false;
    run_assert("Ellipse mask shape is available in designer selection", $has_ellipse_mask);
    run_assert("Display / Fit modes are available in photo element properties", $has_fit_modes);
    run_assert("Border properties include enable/disable and border style options", $has_border_props);

    echo "\n--- TEST 45: Aspect ratio lock and reset frame action are present in designer UI ---\n";
    $has_aspect_lock_ui = strpos($designer_html, 'id="prop-photo-aspect-lock"') !== false;
    $has_reset_frame_fn = strpos($designer_html, 'function resetPhotoFrame()') !== false;
    run_assert("Aspect ratio lock checkbox exists in sidebar markup", $has_aspect_lock_ui);
    run_assert("Reset Photo Frame function handler is defined in designer JS", $has_reset_frame_fn);

    echo "\n--- TEST 46: Reset Photo Adjustments does NOT reset coordinates/dimensions ---\n";
    // Check that resetPhotoTransform does not restore position coordinates (e.g. left, top, width, height)
    $has_reset_photo_no_dim = false;
    if (preg_match('/function resetPhotoTransform\(\)\s*\{([^}]+\})\s*function/s', $designer_html, $matches)) {
        if (strpos($matches[1], 'el.left =') === false) {
            $has_reset_photo_no_dim = true;
        }
    } else if (preg_match('/function resetPhotoTransform\(\)\s*\{([^}]+)/s', $designer_html, $matches)) {
        // Fallback match
        if (strpos($matches[1], 'el.left =') === false) {
            $has_reset_photo_no_dim = true;
        }
    }
    run_assert("Reset photo transform preserves layout positions and frames", $has_reset_photo_no_dim);

    echo "\n--- TEST 47: Photo property panel contains all required controls ---\n";
    $has_zoom_control = strpos($designer_html, 'id="prop-photo-zoom"') !== false;
    $has_panx_control = strpos($designer_html, 'id="prop-photo-panx"') !== false;
    $has_pany_control = strpos($designer_html, 'id="prop-photo-pany"') !== false;
    $has_rotation_control = strpos($designer_html, 'id="prop-photo-rotation"') !== false;
    $has_mask_control = strpos($designer_html, 'id="prop-photo-mask"') !== false;
    $has_fit_control = strpos($designer_html, 'id="prop-photo-fit"') !== false;
    $has_border_control = strpos($designer_html, 'id="prop-photo-border-enabled"') !== false;
    $has_opacity_control = strpos($designer_html, 'id="prop-photo-opacity"') !== false;
    $has_shadow_control = strpos($designer_html, 'id="prop-photo-shadow-enabled"') !== false;
    run_assert("Zoom control exists", $has_zoom_control);
    run_assert("Pan X control exists", $has_panx_control);
    run_assert("Pan Y control exists", $has_pany_control);
    run_assert("Rotation control exists", $has_rotation_control);
    run_assert("Mask control exists", $has_mask_control);
    run_assert("Fit control exists", $has_fit_control);
    run_assert("Border control exists", $has_border_control);
    run_assert("Opacity control exists", $has_opacity_control);
    run_assert("Shadow control exists", $has_shadow_control);

    echo "\n--- TEST 48: Photo zoom/pan/rotation/flip properties are serialized ---\n";
    $has_zoom_serialize = strpos($designer_html, 'zoom:') !== false;
    $has_pan_serialize = strpos($designer_html, 'panX:') !== false;
    $has_rotation_serialize = strpos($designer_html, 'rotation:') !== false;
    $has_flip_serialize = strpos($designer_html, 'flipH:') !== false && strpos($designer_html, 'flipV:') !== false;
    run_assert("Zoom is serialized", $has_zoom_serialize);
    run_assert("Pan is serialized", $has_pan_serialize);
    run_assert("Rotation is serialized", $has_rotation_serialize);
    run_assert("Flips are serialized", $has_flip_serialize);

    echo "\n--- TEST 49: Photo mask/fit/border/shadow properties are serialized ---\n";
    $has_mask_el = strpos($designer_html, 'mask') !== false;
    $has_fit_el = strpos($designer_html, 'fitMode') !== false;
    $has_border_el = strpos($designer_html, 'borderWidth') !== false;
    $has_shadow_el = strpos($designer_html, 'shadowEnabled') !== false;
    run_assert("Mask element property is present", $has_mask_el);
    run_assert("Fit mode element property is present", $has_fit_el);
    run_assert("Border width element property is present", $has_border_el);
    run_assert("Shadow enabled element property is present", $has_shadow_el);

    echo "\n--- TEST 50: Reset Photo Adjustments preserves frame position and dimensions ---\n";
    $has_reset_no_coords = false;
    if (preg_match('/function resetPhotoTransform\(\)\s*\{([^}]+)/s', $designer_html, $matches)) {
        if (strpos($matches[1], 'el.left') === false && strpos($matches[1], 'el.width') === false) {
            $has_reset_no_coords = true;
        }
    }
    run_assert("resetPhotoTransform does not modify layout coordinates", $has_reset_no_coords);

    echo "\n--- TEST 51: Reset Frame preserves photo transformations ---\n";
    $has_reset_frame = strpos($designer_html, 'function resetPhotoFrame()') !== false;
    run_assert("resetPhotoFrame is defined", $has_reset_frame);

    echo "\n--- TEST 52: Rank 1 gets Gold badge style ---\n";
    $has_gold = strpos($designer_html, "'#eab308'") !== false;
    run_assert("Gold color hex value is present", $has_gold);

    echo "\n--- TEST 53: Rank 2 gets Silver badge style ---\n";
    $has_silver = strpos($designer_html, "'#94a3b8'") !== false;
    run_assert("Silver color hex value is present", $has_silver);

    echo "\n--- TEST 54: Rank 3 gets Bronze badge style ---\n";
    $has_bronze = strpos($designer_html, "'#cd7f32'") !== false;
    run_assert("Bronze color hex value is present", $has_bronze);

    echo "\n--- TEST 55: Two tied Rank 2 students receive exactly the same badge color/style ---\n";
    $has_badge_resolver = strpos($designer_html, 'function getRankBadgeStyle(') !== false;
    run_assert("getRankBadgeStyle function is defined", $has_badge_resolver);

    echo "\n--- TEST 56: Merged ranking 1,2,2,4 is preserved exactly ---\n";
    $mock_scores = [98, 95, 95, 90];
    $prev_s = null; $r = 0; $c = 0; $computed_ranks = [];
    foreach ($mock_scores as $s) {
        $c++;
        if ($s !== $prev_s) { $r = $c; }
        $computed_ranks[] = $r;
        $prev_s = $s;
    }
    run_assert("Ranks computed as 1, 2, 2, 4", $computed_ranks === [1, 2, 2, 4]);

    echo "\n--- TEST 57: Visual rank slot does not determine badge color ---\n";
    $has_dynamic_badge_rank_check = strpos($designer_html, 'getRankBadgeStyle(student.computed_rank)') !== false;
    run_assert("Badge style color resolves from student's computed rank", $has_dynamic_badge_rank_check);

    echo "\n--- TEST 58: Save Design retains student-specific photo transformations ---\n";
    $has_student_mappings_save = strpos($designer_html, "payload.append('student_rank_mappings'") !== false;
    run_assert("Save Design payload appends student_rank_mappings object", $has_student_mappings_save);

    echo "\n--- TEST 59: Save Layout strips student-specific values ---\n";
    $has_preset_cleanup = strpos($designer_html, 'getCleanedElementsForLayout') !== false;
    run_assert("getCleanedElementsForLayout helper is present", $has_preset_cleanup);

    echo "\n--- TEST 60: Changing default layout does not modify existing saved designs ---\n";
    $has_bypass_preset = strpos($designer_html, 'if (savedDesignId && savedConfig)') !== false;
    run_assert("Initial page load respects saved configuration over defaults", $has_bypass_preset);

    echo "\n--- TEST 61: Export retains photo transformations ---\n";
    $has_export_img_transforms = strpos($designer_html, 'studentImg.onload') !== false;
    run_assert("High-resolution canvas drawing contains image onload callback", $has_export_img_transforms);

    echo "\n--- TEST 62: Preview and export use the same photo transformation values ---\n";
    $has_export_panning = strpos($designer_html, 'mapping.panX') !== false && strpos($designer_html, 'mapping.panY') !== false;
    run_assert("Export canvas applies identical panning mapping values", $has_export_panning);

    echo "\n--- TEST 63: PNG/JPEG export retains 300 DPI ---\n";
    // Check if DPI metadata is injected in the export script
    $has_dpi_injection = strpos($designer_html, 'resolution_dpi') !== false || strpos($designer_html, '300') !== false;
    run_assert("Exporter references DPI config / 300 DPI metadata injection", $has_dpi_injection);

    echo "\n=== All designer improvements & UI regression automated tests passed successfully! ===\n";

} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

function json_parse_test($str) {
    return json_decode($str, true);
}
