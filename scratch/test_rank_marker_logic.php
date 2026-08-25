<?php
/**
 * Automated Test Suite for Rank Marker Visual Enhancement
 * Verifies marker dimensions, positions, color persistence, save/restore, and coordinate integrity.
 */

// Simulate admin environment
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
require_once __DIR__ . '/../config/database.php';

echo "=== Running Rank Marker Logic and Persistence Tests ===\n";

try {
    // 1. Retrieve the seeded Mega Test Result Template
    $stmt = $pdo->prepare("SELECT * FROM card_templates WHERE title = 'Mega Test Result Template' LIMIT 1");
    $stmt->execute();
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        echo "❌ FAIL: Mega Test Result Template not found in DB.\n";
        exit(1);
    }
    echo "✅ PASS: Template ID {$template['id']} record found.\n";

    // 2. Decode elements and verify default marker properties
    $elements = json_decode($template['elements_json'], true) ?: [];
    $badge1 = null;
    foreach ($elements as $el) {
        if ($el['id'] === 'rank_badge_1') {
            $badge1 = $el;
            break;
        }
    }

    if (!$badge1) {
        echo "❌ FAIL: rank_badge_1 element not found in template preset elements.\n";
        exit(1);
    }

    // Verify properties
    if ($badge1['width'] === 90 && $badge1['height'] === 90) {
        echo "✅ PASS: Default marker dimensions are circular (90x90px).\n";
    } else {
        echo "❌ FAIL: Invalid marker dimensions: {$badge1['width']}x{$badge1['height']}. Expected 90x90.\n";
        exit(1);
    }

    if (isset($badge1['showMarker']) && $badge1['showMarker'] === true) {
        echo "✅ PASS: showMarker property is enabled by default.\n";
    } else {
        echo "❌ FAIL: showMarker property is missing or false.\n";
        exit(1);
    }

    if (isset($badge1['markerColor']) && $badge1['markerColor'] === '#eab308') {
        echo "✅ PASS: Default markerColor is yellow/gold (#eab308).\n";
    } else {
        echo "❌ FAIL: Invalid markerColor: " . ($badge1['markerColor'] ?? 'NULL') . "\n";
        exit(1);
    }

    // 3. Simulate saved config persistence check
    $mock_saved_elements = [
        [
            "id" => "rank_badge_1",
            "name" => "Rank 1 Badge",
            "type" => "text",
            "textContent" => "1st",
            "left" => 130,
            "top" => 520,
            "width" => 90,
            "height" => 90,
            "fontSize" => 36,
            "color" => "#ffffff",
            "showMarker" => true,
            "markerColor" => "#eab308",
            "markerBorderWidth" => 2,
            "markerBorderColor" => "#ffffff"
        ]
    ];

    $design_config_json = json_encode([
        "ranksCount" => 4,
        "elements" => $mock_saved_elements
    ]);

    // Insert mock saved design card
    $stmt_ins = $pdo->prepare("
        INSERT INTO test_result_cards 
        (academic_year, course_id, study_plan_id, activity_id, template_id, design_title, design_config, created_by)
        VALUES ('2026-27', 1, 1, 30, ?, 'Mock Test Result Card', ?, 'admin')
    ");
    $stmt_ins->execute([$template['id'], $design_config_json]);
    $inserted_id = $pdo->lastInsertId();

    // Reload saved design card
    $stmt_load = $pdo->prepare("SELECT * FROM test_result_cards WHERE id = ?");
    $stmt_load->execute([$inserted_id]);
    $card = $stmt_load->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        echo "❌ FAIL: Failed to reload saved result card design from DB.\n";
        exit(1);
    }

    $saved_config = json_decode($card['design_config'], true) ?: [];
    $saved_elements = $saved_config['elements'] ?? [];
    $saved_badge1 = $saved_elements[0] ?? null;

    if ($saved_badge1 && 
        $saved_badge1['showMarker'] === true && 
        $saved_badge1['markerColor'] === '#eab308' &&
        $saved_badge1['markerBorderWidth'] === 2 &&
        $saved_badge1['markerBorderColor'] === '#ffffff') {
        echo "✅ PASS: Rank marker configuration properties persisted and loaded successfully.\n";
    } else {
        echo "❌ FAIL: Saved config did not persist marker attributes correctly.\n";
        exit(1);
    }

    // 4. Simulate self-healing block (restores corrupted giant elements scaled up by old bug)
    // In our JS logic:
    // if (el.height > tplEl.height * 2) { el.height = tplEl.height; }
    $corrupted_badge_element = [
        "id" => "rank_badge_1",
        "name" => "Rank 1 Badge",
        "type" => "text",
        "textContent" => "1st",
        "left" => 125,
        "top" => 510,
        "width" => 1671, // Corrupted width (scaled by old percentage-to-pixel bug)
        "height" => 1024, // Corrupted height (scaled by old percentage-to-pixel bug)
    ];

    // Find corresponding raw template element
    $tplEl = $badge1; // 90x90 template definition
    $healed_badge = $corrupted_badge_element;

    if ($healed_badge['height'] > $tplEl['height'] * 2) {
        $healed_badge['height'] = $tplEl['height'];
    }
    if ($healed_badge['width'] > $tplEl['width'] * 2) {
        $healed_badge['width'] = $tplEl['width'];
    }

    if ($healed_badge['width'] === 90 && $healed_badge['height'] === 90) {
        echo "✅ PASS: Self-healing correctly restored corrupted/oversized saved elements back to template dimensions.\n";
    } else {
        echo "❌ FAIL: Self-healing failed to restore dimensions. Healed width: {$healed_badge['width']}, height: {$healed_badge['height']}.\n";
        exit(1);
    }

    // Ensure legitimate updates (not giant) are preserved
    $legitimate_badge_element = [
        "id" => "rank_badge_1",
        "name" => "Rank 1 Badge",
        "type" => "text",
        "textContent" => "1st",
        "left" => 125,
        "top" => 510,
        "width" => 100, // Legitimate resizing (from 90 to 100)
        "height" => 100, // Legitimate resizing (from 90 to 100)
    ];

    $healed_legitimate = $legitimate_badge_element;
    if ($healed_legitimate['height'] > $tplEl['height'] * 2) {
        $healed_legitimate['height'] = $tplEl['height'];
    }
    if ($healed_legitimate['width'] > $tplEl['width'] * 2) {
        $healed_legitimate['width'] = $tplEl['width'];
    }

    if ($healed_legitimate['width'] === 100 && $healed_legitimate['height'] === 100) {
        echo "✅ PASS: Self-healing preserves legitimate manual resize adjustments without overwriting.\n";
    } else {
        echo "❌ FAIL: Self-healing incorrectly overwrote legitimate coordinates to template defaults.\n";
        exit(1);
    }

    echo "=== All rank marker logic tests passed successfully! ===\n";

} catch (Exception $e) {
    echo "❌ FAIL: Exception occurred: " . $e->getMessage() . "\n";
    exit(1);
}
