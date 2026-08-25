<?php
/**
 * Backend Functional Unit Tests for Test Result Card Database Operations.
 */

// Enable SQLite Memory Database Testing Mode
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

require_once dirname(__DIR__) . '/config/database.php';

function assert_test($label, $assertion) {
    if ($assertion) {
        echo "✅ PASS: {$label}\n";
    } else {
        echo "❌ FAIL: {$label}\n";
        exit(1);
    }
}

global $pdo;

echo "=== Running Test Result Card DB Tests ===\n";

try {
    // 1. Verify table exists in SQLite memory mode
    // We already registered it in config/database.php under testing mode
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    assert_test("test_result_cards table exists", in_array('test_result_cards', $tables));
    assert_test("card_templates table exists", in_array('card_templates', $tables));

    // 2. Insert mock template
    $default_elements = [
        ["id" => "test_number", "name" => "Test Number", "type" => "text", "left" => 1215, "top" => 165],
        ["id" => "chapter_name", "name" => "Chapter Name", "type" => "text", "left" => 290, "top" => 340]
    ];
    $ins_tpl = $pdo->prepare("
        INSERT INTO card_templates 
        (title, category, description, bg_image, canvas_width, canvas_height, resolution_dpi, aspect_ratio, status, elements_json, created_by)
        VALUES 
        ('Mega Test Result Template', 'Achievement', 'Mock template description', 'uploads/card_templates/mega_test_result_template.jpg', 1671, 2048, 300, '1671:2048', 'active', ?, 'system')
    ");
    $ins_tpl->execute([json_encode($default_elements)]);
    $template_id = $pdo->lastInsertId();
    assert_test("Default template inserted successfully", $template_id > 0);

    // 3. Save a Test Result Card design
    $mappings = [
        "rank_photo_1" => ["student_uid" => "student1@pepp.com", "zoom" => 110, "panX" => 5, "panY" => -10],
        "rank_photo_2" => ["student_uid" => "student2@pepp.com", "zoom" => 120, "panX" => 0, "panY" => 0]
    ];
    $design_config = [
        "elements" => $default_elements,
        "ranksCount" => 4
    ];

    $ins_card = $pdo->prepare("
        INSERT INTO test_result_cards 
        (academic_year, course_id, study_plan_id, activity_id, template_id, design_title, output_format, output_file, student_rank_mappings, design_config, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATETIME('now'))
    ");
    $ins_card->execute([
        '2026-27', 10, 20, 30, $template_id, 'CUET PG Psychology - Mock Design', 'png', 
        'uploads/generated_cards/mock_card.png', json_encode($mappings), json_encode($design_config), 'admin_test'
    ]);
    $card_id = $pdo->lastInsertId();
    assert_test("Saved result card design successfully", $card_id > 0);

    // 4. Load & Validate Saved Card Design
    $stmt = $pdo->prepare("SELECT * FROM test_result_cards WHERE id = ?");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch();

    assert_test("Retrieved card title matches", $card['design_title'] === 'CUET PG Psychology - Mock Design');
    assert_test("Retrieved template_id matches", (int)$card['template_id'] === (int)$template_id);
    assert_test("Retrieved output_format matches", $card['output_format'] === 'png');

    $loaded_mappings = json_decode($card['student_rank_mappings'], true);
    assert_test("Loaded rank mappings structure is correct", is_array($loaded_mappings) && isset($loaded_mappings['rank_photo_1']));
    assert_test("Student 1 email matches in mapping", $loaded_mappings['rank_photo_1']['student_uid'] === 'student1@pepp.com');
    assert_test("Student 1 zoom setting matches in mapping", $loaded_mappings['rank_photo_1']['zoom'] === 110);

    $loaded_config = json_decode($card['design_config'], true);
    assert_test("Loaded design config structure is correct", is_array($loaded_config) && isset($loaded_config['elements']));
    assert_test("Loaded elements count matches", count($loaded_config['elements']) === 2);

    // 5. Delete saved design
    $del = $pdo->prepare("DELETE FROM test_result_cards WHERE id = ?");
    $del->execute([$card_id]);
    
    $check_del = $pdo->prepare("SELECT COUNT(*) FROM test_result_cards WHERE id = ?");
    $check_del->execute([$card_id]);
    assert_test("Card deleted successfully", $check_del->fetchColumn() == 0);

    echo "\n=== All database integration tests passed successfully! ===\n";

} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
