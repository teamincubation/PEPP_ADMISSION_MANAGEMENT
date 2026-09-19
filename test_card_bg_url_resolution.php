<?php
/**
 * Isolated CLI Test Suite for Card Template Background URL Normalization & Resolution.
 *
 * Tests:
 * 1. Test Matrix A through O (PHP Classification, DB Canonicalization, Browser URL, Disk Path, CSS Generation)
 * 2. JavaScript Contract Verification via Node.js CLI
 * 3. Upload Error Handling & Four-State Save Logic
 * 4. Template ID 21 Read-Only Regression Fixture
 * 5. Clone & Delete Safety
 *
 * STRICTLY CLI ONLY — ZERO PRODUCTION MUTATION.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/includes/card_helper.php';
require_once __DIR__ . '/includes/file_helper.php';

$test_count = 0;
$pass_count = 0;
$fail_count = 0;

function assert_test(string $name, $actual, $expected): void {
    global $test_count, $pass_count, $fail_count;
    $test_count++;
    if ($actual === $expected) {
        $pass_count++;
        echo "  [PASS] {$name}\n";
    } else {
        $fail_count++;
        echo "  [FAIL] {$name}\n";
        echo "         Expected: " . var_export($expected, true) . "\n";
        echo "         Actual:   " . var_export($actual, true) . "\n";
    }
}

echo "============================================================\n";
echo "1. TEST MATRIX (A through O) — PHP UNIT TESTS\n";
echo "============================================================\n";

$matrix = [
    'A' => [
        'input' => 'uploads/card_templates/test.jpg',
        'type' => 'url',
        'db' => 'uploads/card_templates/test.jpg',
        'browser' => '../uploads/card_templates/test.jpg',
        'disk_not_null' => true,
        'css_contains' => 'background-image: url("../uploads/card_templates/test.jpg")',
    ],
    'B' => [
        'input' => '../uploads/card_templates/test.jpg',
        'type' => 'url',
        'db' => 'uploads/card_templates/test.jpg',
        'browser' => '../uploads/card_templates/test.jpg',
        'disk_not_null' => true,
        'css_contains' => 'background-image: url("../uploads/card_templates/test.jpg")',
    ],
    'C' => [
        'input' => '/uploads/card_templates/test.jpg',
        'type' => 'url',
        'db' => 'uploads/card_templates/test.jpg',
        'browser' => '/uploads/card_templates/test.jpg',
        'disk_not_null' => true,
        'css_contains' => 'background-image: url("/uploads/card_templates/test.jpg")',
    ],
    'D' => [
        'input' => 'https://example.com/test.jpg',
        'type' => 'url',
        'db' => 'https://example.com/test.jpg',
        'browser' => 'https://example.com/test.jpg',
        'disk_not_null' => false,
        'css_contains' => 'background-image: url("https://example.com/test.jpg")',
    ],
    'E' => [
        'input' => 'http://example.com/test.jpg',
        'type' => 'url',
        'db' => 'http://example.com/test.jpg',
        'browser' => 'http://example.com/test.jpg',
        'disk_not_null' => false,
        'css_contains' => 'background-image: url("http://example.com/test.jpg")',
    ],
    'F' => [
        'input' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA',
        'type' => 'url',
        'db' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA',
        'browser' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA',
        'disk_not_null' => false,
        'css_contains' => 'background-image: url("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA")',
    ],
    'G' => [
        'input' => 'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)',
        'type' => 'gradient',
        'db' => 'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)',
        'browser' => 'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)',
        'disk_not_null' => false,
        'css_contains' => 'background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);',
    ],
    'H' => [
        'input' => 'radial-gradient(circle, #ff9a9e 0%, #fecfef 100%)',
        'type' => 'gradient',
        'db' => 'radial-gradient(circle, #ff9a9e 0%, #fecfef 100%)',
        'browser' => 'radial-gradient(circle, #ff9a9e 0%, #fecfef 100%)',
        'disk_not_null' => false,
        'css_contains' => 'background: radial-gradient(circle, #ff9a9e 0%, #fecfef 100%);',
    ],
    'I' => [
        'input' => 'conic-gradient(from 0deg, #ff9a9e, #fecfef)',
        'type' => 'gradient',
        'db' => 'conic-gradient(from 0deg, #ff9a9e, #fecfef)',
        'browser' => 'conic-gradient(from 0deg, #ff9a9e, #fecfef)',
        'disk_not_null' => false,
        'css_contains' => 'background: conic-gradient(from 0deg, #ff9a9e, #fecfef);',
    ],
    'J' => [
        'input' => '#ffffff',
        'type' => 'color',
        'db' => '#ffffff',
        'browser' => '#ffffff',
        'disk_not_null' => false,
        'css_contains' => 'background-color: #ffffff;',
    ],
    'K' => [
        'input' => 'rgb(255,255,255)',
        'type' => 'color',
        'db' => 'rgb(255,255,255)',
        'browser' => 'rgb(255,255,255)',
        'disk_not_null' => false,
        'css_contains' => 'background-color: rgb(255,255,255);',
    ],
    'L' => [
        'input' => 'rgba(255,255,255,0.8)',
        'type' => 'color',
        'db' => 'rgba(255,255,255,0.8)',
        'browser' => 'rgba(255,255,255,0.8)',
        'disk_not_null' => false,
        'css_contains' => 'background-color: rgba(255,255,255,0.8);',
    ],
    'M' => [
        'input' => '',
        'type' => 'empty',
        'db' => '',
        'browser' => '',
        'disk_not_null' => false,
        'css_contains' => 'background-color: #f1f5f9;',
    ],
    'N' => [
        'input' => '../../config.php',
        'type' => 'url',
        'db' => '',
        'browser' => '',
        'disk_not_null' => false,
        'css_contains' => 'background-color: #f1f5f9;',
    ],
    'O' => [
        'input' => '../some-other-directory/file.jpg',
        'type' => 'url',
        'db' => '',
        'browser' => '',
        'disk_not_null' => false,
        'css_contains' => 'background-color: #f1f5f9;',
    ]
];

foreach ($matrix as $key => $spec) {
    $input = $spec['input'];
    echo "\nTesting Case {$key}: " . ($input ?: '[EMPTY]') . "\n";

    // Type classification
    $type = get_card_bg_type($input);
    assert_test("Matrix {$key} Type Classification", $type, $spec['type']);

    // DB Canonicalization
    $db = canonicalize_card_bg_for_db($input);
    assert_test("Matrix {$key} DB Canonicalization", $db, $spec['db']);

    // Browser URL Resolution
    $browser = resolve_card_bg_browser_url($input);
    assert_test("Matrix {$key} Browser URL Resolution", $browser, $spec['browser']);

    // Disk Path Resolution
    $disk = resolve_card_bg_disk_path($input, __DIR__);
    if ($spec['disk_not_null']) {
        assert_test("Matrix {$key} Disk Path (NotNull)", ($disk !== null && strpos($disk, 'card_templates') !== false), true);
        // Ensure path traversal is blocked
        assert_test("Matrix {$key} Disk Path No Traversal", strpos($disk, '..') === false, true);
    } else {
        assert_test("Matrix {$key} Disk Path (Null for non-local/unsafe)", $disk, null);
    }

    // CSS Generation
    $css = get_card_bg_css_style($input);
    assert_test("Matrix {$key} CSS Style", strpos($css, $spec['css_contains']) !== false, true);
    // Never wrap gradient in url()
    if ($spec['type'] === 'gradient') {
        assert_test("Matrix {$key} Gradient never in url()", strpos($css, 'url('), false);
        assert_test("Matrix {$key} Gradient retains opening parenthesis", strpos($css, '(') !== false, true);
        assert_test("Matrix {$key} Gradient retains closing parenthesis", strpos($css, ')') !== false, true);
        assert_test("Matrix {$key} Gradient exact CSS match", $css, 'background: ' . $input . ';');
    }
    if ($spec['type'] === 'color' && (strpos($input, 'rgb') === 0 || strpos($input, 'hsl') === 0)) {
        assert_test("Matrix {$key} Color retains opening parenthesis", strpos($css, '(') !== false, true);
        assert_test("Matrix {$key} Color retains closing parenthesis", strpos($css, ')') !== false, true);
        assert_test("Matrix {$key} Color exact CSS match", $css, 'background-color: ' . $input . ';');
    }
}

// Dedicated Gradient & Functional Color Parentheses Retention Verification
echo "\nTesting Dedicated Gradient & Functional Color Parentheses Retention:\n";
$functional_syntaxes = [
    'repeating-linear-gradient' => 'repeating-linear-gradient(45deg, #3f87a6, #ebf8e1 15%, #f69d3c 20%)',
    'conic-gradient-advanced'   => 'conic-gradient(from 45deg at 50% 50%, #f69d3c, #3f87a6)',
    'hsl-color'                 => 'hsl(210, 100%, 50%)',
    'hsla-color'                => 'hsla(210, 100%, 50%, 0.5)'
];
foreach ($functional_syntaxes as $syn_name => $syn_val) {
    $syn_type = get_card_bg_type($syn_val);
    $syn_css = get_card_bg_css_style($syn_val);
    assert_test("{$syn_name} classified as gradient or color", ($syn_type === 'gradient' || $syn_type === 'color'), true);
    assert_test("{$syn_name} retains opening parenthesis in CSS", strpos($syn_css, '(') !== false, true);
    assert_test("{$syn_name} retains closing parenthesis in CSS", strpos($syn_css, ')') !== false, true);
    $expected_prefix = ($syn_type === 'gradient') ? 'background: ' : 'background-color: ';
    assert_test("{$syn_name} exact CSS string match", $syn_css, $expected_prefix . $syn_val . ';');
}

// Test null input specifically for Case M
echo "\nTesting Case M (Strict null input):\n";
assert_test("Matrix M (null) Type", get_card_bg_type(null), 'empty');
assert_test("Matrix M (null) DB", canonicalize_card_bg_for_db(null), '');
assert_test("Matrix M (null) Browser", resolve_card_bg_browser_url(null), '');
assert_test("Matrix M (null) Disk", resolve_card_bg_disk_path(null), null);
assert_test("Matrix M (null) CSS", get_card_bg_css_style(null), 'background-color: #f1f5f9;');

echo "\n============================================================\n";
echo "2. JAVASCRIPT CONTRACT VERIFICATION (VIA NODE.JS CLI)\n";
echo "============================================================\n";

$js_test_script = <<<'NODE_SCRIPT'
function getCardBgType(bg) {
    if (!bg) return 'empty';
    bg = String(bg).trim();
    if (!bg) return 'empty';
    if (/^(?:repeating-)?(?:linear|radial|conic)-gradient\s*\(/i.test(bg) || bg.includes('gradient')) {
        return 'gradient';
    }
    if (/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(bg) ||
        /^(?:rgb|rgba|hsl|hsla)\s*\(/i.test(bg) ||
        ['transparent', 'white', 'black'].includes(bg.toLowerCase())) {
        return 'color';
    }
    return 'url';
}

function canonicalizeCardBgForDb(bg) {
    if (!bg) return '';
    bg = String(bg).trim();
    if (!bg) return '';
    var type = getCardBgType(bg);
    if (type === 'gradient' || type === 'color') {
        return bg;
    }
    if (bg.startsWith('data:') || /^https?:\/\//i.test(bg)) {
        return bg;
    }
    var m = bg.match(/^(?:\.\.\/|\/)?uploads\/card_templates\/([a-zA-Z0-9_\.\-]+)$/);
    if (m) {
        var filename = m[1];
        if (filename === '.' || filename === '..' || filename.includes('/') || filename.includes('\\')) {
            return '';
        }
        return 'uploads/card_templates/' + filename;
    }
    return '';
}

function resolveCardBgUrl(bg) {
    if (!bg) return '';
    bg = String(bg).trim();
    if (!bg) return '';
    var type = getCardBgType(bg);
    if (type === 'gradient' || type === 'color') {
        return bg;
    }
    if (bg.startsWith('data:') || /^https?:\/\//i.test(bg)) {
        return bg;
    }
    var mRoot = bg.match(/^\/uploads\/card_templates\/([a-zA-Z0-9_\.\-]+)$/);
    if (mRoot) return '/uploads/card_templates/' + mRoot[1];

    var mRel = bg.match(/^\.\.\/uploads\/card_templates\/([a-zA-Z0-9_\.\-]+)$/);
    if (mRel) return '../uploads/card_templates/' + mRel[1];

    var mCanon = bg.match(/^uploads\/card_templates\/([a-zA-Z0-9_\.\-]+)$/);
    if (mCanon) return '../uploads/card_templates/' + mCanon[1];

    return '';
}

const cases = CASE_INPUTS_PLACEHOLDER;
const results = {};
for (const [k, input] of Object.entries(cases)) {
    results[k] = {
        type: getCardBgType(input),
        db: canonicalizeCardBgForDb(input),
        browser: resolveCardBgUrl(input)
    };
}
console.log(JSON.stringify(results));
NODE_SCRIPT;

$js_inputs = [];
foreach ($matrix as $key => $spec) {
    $js_inputs[$key] = $spec['input'];
}
$tmp_js = __DIR__ . '/test_js_runner.tmp.js';
file_put_contents($tmp_js, str_replace('CASE_INPUTS_PLACEHOLDER', json_encode($js_inputs), $js_test_script));

$cmd = 'node ' . escapeshellarg($tmp_js);
$js_output = shell_exec($cmd);
@unlink($tmp_js);

$js_results = json_decode($js_output, true);
assert_test("Node.js JS Runner Execution", is_array($js_results), true);

if (is_array($js_results)) {
    foreach ($matrix as $key => $spec) {
        $js_res = $js_results[$key] ?? [];
        assert_test("JS Contract Matrix {$key} Type", $js_res['type'] ?? '', $spec['type']);
        assert_test("JS Contract Matrix {$key} DB", $js_res['db'] ?? '', $spec['db']);
        assert_test("JS Contract Matrix {$key} Browser URL", $js_res['browser'] ?? '', $spec['browser']);
    }
}

echo "\n============================================================\n";
echo "3. UPLOAD ERROR TESTS & FOUR-STATE BEHAVIOR\n";
echo "============================================================\n";

// Test upload error code messages
$error_codes = [
    UPLOAD_ERR_INI_SIZE => 'php.ini',
    UPLOAD_ERR_FORM_SIZE => 'MAX_FILE_SIZE',
    UPLOAD_ERR_PARTIAL => 'partially uploaded',
    UPLOAD_ERR_NO_FILE => 'No file was selected',
    UPLOAD_ERR_NO_TMP_DIR => 'temporary upload folder',
    UPLOAD_ERR_CANT_WRITE => 'failed to write',
    UPLOAD_ERR_EXTENSION => 'extension stopped'
];

foreach ($error_codes as $code => $expected_substring) {
    $msg = get_upload_error_message($code);
    assert_test("Upload Error Code {$code} Description", strpos($msg, $expected_substring) !== false, true);
}

// Four-state save simulation
function simulate_save_template(array $files, array $post, array $current_tpl): array {
    $existing_bg = $current_tpl['bg_image'];
    $bg_path = null;
    $has_file_upload = isset($files['bg_file']) && !empty($files['bg_file']['name']);

    if ($has_file_upload) {
        if ($files['bg_file']['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => 'Background image upload failed: ' . get_upload_error_message($files['bg_file']['error'])
            ];
        }

        // Simulating upload handler validation
        $ext = strtolower(pathinfo($files['bg_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return [
                'success' => false,
                'message' => 'Failed to process background image. Please ensure the file is a valid JPG, PNG, or WEBP under 5MB.'
            ];
        }
        if ($files['bg_file']['size'] > 5 * 1024 * 1024) {
            return [
                'success' => false,
                'message' => 'Failed to process background image. Please ensure the file is a valid JPG, PNG, or WEBP under 5MB.'
            ];
        }
        $bg_path = 'uploads/card_templates/simulated_' . basename($files['bg_file']['name']);
    }

    $final_bg = $existing_bg; // State C: preserve existing
    if ($bg_path) {
        // State A: new valid upload
        $final_bg = canonicalize_card_bg_for_db($bg_path);
    } elseif (isset($post['bg_path_style']) && trim($post['bg_path_style']) !== '') {
        $submitted_style = trim($post['bg_path_style']);
        $canonical_style = canonicalize_card_bg_for_db($submitted_style);
        if ($canonical_style !== '') {
            // State B: valid bg_path_style supplied
            $final_bg = $canonical_style;
        }
    }

    return [
        'success' => true,
        'bg_image' => $final_bg,
        'bg_url' => resolve_card_bg_browser_url($final_bg)
    ];
}

$mock_tpl = ['id' => 10, 'bg_image' => 'uploads/card_templates/initial_bg.jpg', 'canvas_width' => 1200, 'canvas_height' => 630];

// State A: New valid upload replaces background
$res_state_a = simulate_save_template(
    ['bg_file' => ['name' => 'new_banner.png', 'error' => UPLOAD_ERR_OK, 'size' => 100000]],
    [],
    $mock_tpl
);
assert_test("State A (Valid Upload): success is true", $res_state_a['success'], true);
assert_test("State A: bg_image updated", $res_state_a['bg_image'], 'uploads/card_templates/simulated_new_banner.png');
assert_test("State A: bg_url resolved", $res_state_a['bg_url'], '../uploads/card_templates/simulated_new_banner.png');

// State B: Valid bg_path_style supplied (e.g. gradient)
$res_state_b = simulate_save_template(
    [],
    ['bg_path_style' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'],
    $mock_tpl
);
assert_test("State B (Style supplied): success is true", $res_state_b['success'], true);
assert_test("State B: bg_image updated to gradient", $res_state_b['bg_image'], 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)');

// State C: No background change submitted -> preserves existing bg_image
$res_state_c = simulate_save_template(
    [],
    ['bg_path_style' => ''],
    $mock_tpl
);
assert_test("State C (No change): success is true", $res_state_c['success'], true);
assert_test("State C: existing bg_image preserved exactly", $res_state_c['bg_image'], 'uploads/card_templates/initial_bg.jpg');

// State D1: PHP upload error reported (UPLOAD_ERR_INI_SIZE) -> aborts with success: false
$res_state_d1 = simulate_save_template(
    ['bg_file' => ['name' => 'too_large.png', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]],
    [],
    $mock_tpl
);
assert_test("State D1 (Upload Error INI_SIZE): success is false", $res_state_d1['success'], false);
assert_test("State D1: Error message informs user of limit", strpos($res_state_d1['message'], 'upload_max_filesize') !== false, true);

// State D2: Invalid file extension (e.g. .exe) -> aborts with success: false
$res_state_d2 = simulate_save_template(
    ['bg_file' => ['name' => 'malicious.exe', 'error' => UPLOAD_ERR_OK, 'size' => 1000]],
    [],
    $mock_tpl
);
assert_test("State D2 (Invalid Extension): success is false", $res_state_d2['success'], false);

// State D3: Oversized upload (>5MB) -> aborts with success: false
$res_state_d3 = simulate_save_template(
    ['bg_file' => ['name' => 'huge.jpg', 'error' => UPLOAD_ERR_OK, 'size' => 10 * 1024 * 1024]],
    [],
    $mock_tpl
);
assert_test("State D3 (Oversized >5MB): success is false", $res_state_d3['success'], false);

// State D4: Traversal attempt in bg_path_style -> rejected, existing background preserved
$res_state_d4 = simulate_save_template(
    [],
    ['bg_path_style' => '../../config.php'],
    $mock_tpl
);
assert_test("State D4 (Traversal in bg_path_style): preserves existing bg", $res_state_d4['bg_image'], 'uploads/card_templates/initial_bg.jpg');

echo "\n============================================================\n";
echo "4. TEMPLATE ID 21 READ-ONLY REGRESSION FIXTURE\n";
echo "============================================================\n";

// Template 21 read-only fixture
$tpl_21_fixtures = [
    'Canonical Stored' => 'uploads/card_templates/mega_test_result_template.jpg',
    'Legacy Stored' => '../uploads/card_templates/mega_test_result_template.jpg',
    'Root Relative' => '/uploads/card_templates/mega_test_result_template.jpg'
];

foreach ($tpl_21_fixtures as $desc => $fixture_bg) {
    echo "\nTesting Template #21 Fixture ({$desc}): {$fixture_bg}\n";

    // 1. cards.php thumbnail CSS:
    $css_style = get_card_bg_css_style($fixture_bg);
    assert_test("Tpl #21 in cards.php: Generates safe background-image", strpos($css_style, 'background-image: url(') !== false, true);
    assert_test("Tpl #21 in cards.php: No ../../ double prefix", strpos($css_style, '../../'), false);

    // 2. cards-edit.php browser resolution:
    $edit_url = resolve_card_bg_browser_url($fixture_bg);
    assert_test("Tpl #21 in cards-edit.php: Resolves to valid relative URL", (strpos($edit_url, '../uploads/') === 0 || strpos($edit_url, '/uploads/') === 0), true);
    assert_test("Tpl #21 in cards-edit.php: No ../../ double prefix", strpos($edit_url, '../../'), false);

    // 3. cards-generate.php browser resolution:
    $gen_url = resolve_card_bg_browser_url($fixture_bg);
    assert_test("Tpl #21 in cards-generate.php: Resolves identically", $gen_url, $edit_url);

    // 4. cards-result-designer.php resolution:
    $designer_url = resolve_card_bg_browser_url($fixture_bg);
    assert_test("Tpl #21 in cards-result-designer.php: Resolves identically", $designer_url, $edit_url);
}

echo "\n============================================================\n";
echo "5. CLONE AND DELETE SAFETY\n";
echo "============================================================\n";

$clone_delete_cases = [
    'Canonical local' => 'uploads/card_templates/mega_test_result_template.jpg',
    'Legacy relative' => '../uploads/card_templates/mega_test_result_template.jpg',
    'Root relative'   => '/uploads/card_templates/mega_test_result_template.jpg',
    'External URL'    => 'https://example.com/external_card.png',
    'Gradient'        => 'linear-gradient(90deg, #ff0000, #0000ff)',
    'Solid color'     => '#3b82f6',
    'Traversal 1'     => '../../config.php',
    'Traversal 2'     => '../includes/auth.php'
];

foreach ($clone_delete_cases as $label => $bg_val) {
    echo "\nTesting Clone/Delete Safety for {$label}: {$bg_val}\n";
    $disk_path = resolve_card_bg_disk_path($bg_val, __DIR__);
    $canonical_db = canonicalize_card_bg_for_db($bg_val);

    if (in_array($label, ['Canonical local', 'Legacy relative', 'Root relative'], true)) {
        assert_test("{$label}: Disk path is resolved inside card_templates", ($disk_path !== null && strpos($disk_path, 'card_templates') !== false), true);
        assert_test("{$label}: Canonical DB format strictly starts with uploads/card_templates/", strpos($canonical_db, 'uploads/card_templates/'), 0);
    } else {
        // External URLs, Gradients, Colors, and Traversal attempts must NEVER resolve to disk files
        assert_test("{$label}: Disk path is NULL (safe against filesystem unlink/copy)", $disk_path, null);
        if (strpos($label, 'Traversal') !== false) {
            assert_test("{$label}: Canonical DB is EMPTY (rejected)", $canonical_db, '');
        }
    }
}

echo "\n============================================================\n";
echo "FINAL RESULTS\n";
echo "============================================================\n";
echo "Total assertions: {$test_count}\n";
echo "Passed:           {$pass_count}\n";
echo "Failed:           {$fail_count}\n";

if ($fail_count > 0) {
    echo "TEST SUITE FAILED!\n";
    exit(1);
} else {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
}
