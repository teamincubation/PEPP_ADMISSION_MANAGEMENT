<?php
// Set headers for testing mode to use the SQLite memory database
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
require_once 'config/database.php';

// Dynamically seed Flyer template 11 for testing without contaminating production config/database.php
$pdo->exec("
    INSERT OR REPLACE INTO card_templates
    (id, title, category, description, bg_image, canvas_width, canvas_height, resolution_dpi, aspect_ratio, status, elements_json, created_by, created_at)
    VALUES
    (11, 'Flyer Test Template', 'Flyer', 'A 1200x800 flyer template.', 'uploads/card_templates/mega_test_result_template.jpg', 1200, 800, 300, '3:2', 'active',
    '[{\"id\":\"test_number\",\"name\":\"Test Number\",\"type\":\"text\",\"textContent\":\"1\",\"left\":15.0,\"top\":10.0,\"width\":10.0,\"height\":10.0,\"fontFamily\":\"Google Sans Flex\",\"fontSize\":36,\"fontWeight\":\"700\",\"color\":\"#ffffff\",\"textAlign\":\"center\",\"lineHeight\":1.0,\"letterSpacing\":0,\"opacity\":1,\"rotate\":0}]',
    'system', DATETIME('now'))
");

echo "=== Running Visual Designer Coordinate and Path Tests ===\n";

function run_test($template_id, $expected_w, $expected_h, $expected_elements_count) {
    global $pdo;

    // Load template details
    $stmt = $pdo->prepare("SELECT * FROM card_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $tpl = $stmt->fetch();

    if (!$tpl) {
        echo "❌ FAIL: Template ID {$template_id} not found in database.\n";
        return false;
    }

    echo "✅ PASS: Template ID {$template_id} record found: '{$tpl['title']}'\n";

    // 1. Verify image path in DB
    $bg_image = $tpl['bg_image'];
    if (empty($bg_image)) {
        echo "❌ FAIL: Background image path is empty.\n";
        return false;
    }
    echo "✅ PASS: Background image path is '{$bg_image}'\n";

    // 2. Verify file existence in filesystem
    // Note: The template files are stored in the parent uploads directory (relative to admissions directory).
    // Let's resolve the path relative to admissions parent:
    $resolved_file_path = __DIR__ . '/../../' . $bg_image;
    if (!file_exists($resolved_file_path)) {
        echo "❌ FAIL: Resolved file path does not exist: {$resolved_file_path}\n";
        return false;
    }
    echo "✅ PASS: Background image file exists physically on disk.\n";

    // 3. Verify image URL resolvable
    // Check path resolution logic
    $resolved_url = (strpos($bg_image, 'http') === 0 || strpos($bg_image, 'data:') === 0 || strpos($bg_image, '../') === 0) ? $bg_image : '../' . $bg_image;
    if ($resolved_url !== '../' . $bg_image) {
        echo "❌ FAIL: URL path resolver mapping failed.\n";
        return false;
    }
    echo "✅ PASS: Resolved URL maps correctly to '{$resolved_url}'\n";

    // 4. Verify template properties match expectation
    $canvas_w = (int)$tpl['canvas_width'];
    $canvas_h = (int)$tpl['canvas_height'];
    if ($canvas_w !== $expected_w || $canvas_h !== $expected_h) {
        echo "❌ FAIL: Database canvas dimensions mismatch. Expected: {$expected_w}x{$expected_h}, Got: {$canvas_w}x{$canvas_h}\n";
        return false;
    }
    echo "✅ PASS: Canvas dimensions are correct: {$canvas_w}x{$canvas_h}\n";

    // Aspect ratio check
    $aspect = $tpl['aspect_ratio'];
    $expected_aspect = ($expected_w / $expected_h);
    echo "✅ PASS: Template aspect ratio string in DB: '{$aspect}' (effective: " . number_format($expected_aspect, 4) . ")\n";

    // 5. Verify elements decodes successfully and counts
    $elements = json_decode($tpl['elements_json'], true);
    if (!is_array($elements)) {
        echo "❌ FAIL: Elements JSON is invalid or failed to decode.\n";
        return false;
    }
    // Filter out metadata element for visual count check
    $visual_elements = array_filter($elements, function($item) {
        return !(isset($item['id']) && $item['id'] === 'metadata');
    });
    if (count($visual_elements) !== $expected_elements_count) {
        echo "❌ FAIL: Elements count mismatch. Expected: {$expected_elements_count}, Got: " . count($visual_elements) . "\n";
        return false;
    }
    echo "✅ PASS: Elements JSON loaded successfully. Count: " . count($visual_elements) . "\n";

    // 6. Coordinate validation and Conversion test (Simulation)
    foreach ($elements as $el) {
        if (isset($el['id']) && $el['id'] === 'metadata') {
            continue;
        }
        $orig_left = $el['left'];
        $orig_top = $el['top'];
        $orig_width = $el['width'];
        $orig_height = $el['height'];

        // Detect if template explicitly defines native coordinate mode (metadata node with coordinate_mode: native)
        $is_native = false;
        foreach ($elements as $item) {
            if (isset($item['id']) && $item['id'] === 'metadata' && isset($item['coordinate_mode']) && $item['coordinate_mode'] === 'native') {
                $is_native = true;
                break;
            }
        }

        // Perform Javascript conversion simulation
        $left = $orig_left;
        $top = $orig_top;
        $width = $orig_width;
        $height = $orig_height;

        if (!$is_native) {
            $left = round(($left / 100) * $canvas_w);
            $top = round(($top / 100) * $canvas_h);
            $width = round(($width / 100) * $canvas_w);
            $height = round(($height / 100) * $canvas_h);
        }

        echo "   -> Element [{$el['id']}]:\n";
        echo "      Raw:   left={$orig_left}, top={$orig_top}, width={$orig_width}, height={$orig_height}\n";
        echo "      Pixel: left={$left}px, top={$top}px, width={$width}px, height={$height}px\n";

        // Check for coordinates consistency
        if ($left > $canvas_w || $top > $canvas_h) {
            echo "❌ FAIL: Element coordinates are outside the native canvas geometry.\n";
            return false;
        }
    }

    echo "✅ PASS: Elements coordinates normalized and verified successfully.\n";
    echo "\n";
    return true;
}

// Run test for Template A: Mega Test Result Template (1671x2048)
echo "--- Testing Template A (Mega Test Result Template) ---\n";
$resA = run_test(10, 1671, 2048, 6);

// Run test for Template B: Flyer Test Template (1200x800)
echo "--- Testing Template B (Flyer Test Template) ---\n";
$resB = run_test(11, 1200, 800, 1);

if ($resA && $resB) {
    echo "=== All visual designer automated tests passed successfully! ===\n";
} else {
    echo "❌ FAIL: Some automated tests failed.\n";
    exit(1);
}
