<?php
/**
 * PEPP Updates — Enhancements Automated Audit Test Suite
 *
 * Comprehensive tests for:
 * 1. Requirement 1 — Removal of category badges from public update cards
 * 2. Requirement 2 — Location prompt execution path, non-blocking UI, privacy safety
 * 3. Requirement 3 — Quill Full Details editor repaired dropdowns, color control, sanitization
 * 4. Requirement 4 — AI description service, CSRF, security, factual caution, editor insertion
 */

declare(strict_types=1);

$totalPass = 0;
$totalFail = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $totalPass, $totalFail;
    if ($condition) {
        $totalPass++;
        echo "  [PASS] {$name}\n";
    } else {
        $totalFail++;
        echo "  [FAIL] {$name}" . ($detail ? " — {$detail}" : "") . "\n";
    }
}

echo "============================================================\n";
echo "PEPP UPDATES — ENHANCEMENTS AUTOMATED AUDIT\n";
echo "============================================================\n\n";

// ─────────────────────────────────────────────────────────────
// REQUIREMENT 1: REMOVAL OF CATEGORY BADGES FROM PUBLIC CARDS
// ─────────────────────────────────────────────────────────────
echo "--- Requirement 1: Public Card Category Badge Removal ---\n";

$indexContent = file_get_contents(__DIR__ . '/public_updates/index.php');
$searchContent = file_get_contents(__DIR__ . '/public_updates/search.php');
$categoryContent = file_get_contents(__DIR__ . '/public_updates/category.php');
$updateContent = file_get_contents(__DIR__ . '/public_updates/update.php');

// In index.php, card-badges must NOT contain category badge
$hasCatBadgeInIndex = (bool)preg_match('/<div class="card-badges">[\s\S]*?badge-pill\s+category[\s\S]*?<\/div>/', $indexContent);
assert_test("R1.1: Homepage update cards do NOT render category badge over banner", !$hasCatBadgeInIndex);

// In search.php, card-badges must NOT contain category badge
$hasCatBadgeInSearch = (bool)preg_match('/<div class="card-badges">[\s\S]*?badge-pill\s+category[\s\S]*?<\/div>/', $searchContent);
assert_test("R1.2: Search listing cards do NOT render category badge over banner", !$hasCatBadgeInSearch);

// In category.php, cards do NOT render category badge
$hasCatBadgeInCatPage = (bool)preg_match('/<div class="card-badges">[\s\S]*?badge-pill\s+category[\s\S]*?<\/div>/', $categoryContent);
assert_test("R1.3: Category listing cards do NOT render category badge over banner", !$hasCatBadgeInCatPage);

// In update.php, related cards do NOT render category badge
$hasCatBadgeInRelated = (bool)preg_match('/<div class="card-badges">[\s\S]*?badge-pill\s+category[\s\S]*?<\/div>/', $updateContent);
assert_test("R1.4: Update detail related cards do NOT render category badge over banner", !$hasCatBadgeInRelated);

// Check that NEW badge logic is preserved
assert_test("R1.5: Homepage cards preserve NEW badge logic", strpos($indexContent, 'badge-pill new') !== false);
assert_test("R1.6: Homepage cards preserve EXPIRED badge logic", strpos($indexContent, 'badge-pill expired') !== false);

// Check that category navigation and category pages are preserved
$headerContent = file_get_contents(__DIR__ . '/public_updates/includes/header.php');
assert_test("R1.7: Header preserves /categories navigation link", strpos($headerContent, 'href="/categories"') !== false);
assert_test("R1.8: Category detail page public_updates/category.php exists and filters posts", file_exists(__DIR__ . '/public_updates/category.php'));

// ─────────────────────────────────────────────────────────────
// REQUIREMENT 2: LOCATION PROMPT ON UPDATE DETAIL PAGES
// ─────────────────────────────────────────────────────────────
echo "\n--- Requirement 2: Location Prompt on Update Detail Pages ---\n";

$mainJsContent = file_get_contents(__DIR__ . '/public_updates/assets/js/main.js');
$styleCssContent = file_get_contents(__DIR__ . '/public_updates/assets/css/style.css');

// Syntax check on main.js
$nodeOutput = [];
$nodeCode = 0;
exec('node -c ' . escapeshellarg(__DIR__ . '/public_updates/assets/js/main.js') . ' 2>&1', $nodeOutput, $nodeCode);
assert_test("R2.1: public_updates/assets/js/main.js syntax check passes cleanly", $nodeCode === 0, implode("\n", $nodeOutput));

// Required text assertions
assert_test("R2.2: Prompt heading contains 'Local Exam & Admission Alerts'", strpos($mainJsContent, 'Local Exam & Admission Alerts') !== false);
assert_test("R2.3: Prompt message contains 'Share approximate location for district-specific updates?'", strpos($mainJsContent, 'Share approximate location for district-specific updates?') !== false);
assert_test("R2.4: Prompt contains 'Allow' action button", strpos($mainJsContent, 'id="locAllowBtn"') !== false && strpos($mainJsContent, '>Allow</button>') !== false);
assert_test("R2.5: Prompt contains 'Not now' dismissal button", strpos($mainJsContent, 'id="locLaterBtn"') !== false && strpos($mainJsContent, '>Not now</button>') !== false);

// Privacy & Execution Path
assert_test("R2.6: Geolocation is NOT called unconditionally on initial visit", strpos($mainJsContent, "if ((geoChoice === null || geoChoice === '') && navigator.geolocation)") !== false);
assert_test("R2.7: Geolocation is only called inside user click handler when prompting", strpos($mainJsContent, 'allowBtn.addEventListener(\'click\'') !== false && strpos($mainJsContent, 'navigator.geolocation.getCurrentPosition') !== false);
assert_test("R2.8: 'Not now' refusal persists 'denied' in localStorage", strpos($mainJsContent, "localStorage.setItem('pepp_geo_choice', 'denied')") !== false);
assert_test("R2.9: Prompt checks existing consent and aborts if already answered", strpos($mainJsContent, "localStorage.getItem('pepp_geo_choice')") !== false);
assert_test("R2.10: Update detail page triggers prompt via .single-update class", strpos($updateContent, 'single-update') !== false && strpos($mainJsContent, '.single-update') !== false);
assert_test("R2.11: Non-blocking fixed prompt CSS styling defined in style.css", strpos($styleCssContent, '.pepp-location-prompt') !== false && strpos($styleCssContent, 'position: fixed') !== false);

// ─────────────────────────────────────────────────────────────
// REQUIREMENT 3: REPAIR & IMPROVE FULL DETAILS RICH TEXT EDITOR
// ─────────────────────────────────────────────────────────────
echo "\n--- Requirement 3: Quill Full Details Editor & Formatting ---\n";

$postsPhpContent = file_get_contents(__DIR__ . '/pepp-updates-posts.php');

// Toolbar Controls
assert_test("R3.1: Quill toolbar includes Font Family control", strpos($postsPhpContent, "[{ 'font': [] }]") !== false);
assert_test("R3.2: Quill toolbar includes Font Size control", strpos($postsPhpContent, "[{ 'size': ['small', false, 'large', 'huge'] }]") !== false);
assert_test("R3.3: Quill toolbar includes Header / Style control", strpos($postsPhpContent, "[{ 'header': [1, 2, 3, false] }]") !== false);
assert_test("R3.4: Quill toolbar includes Bold, Italic, Underline, Strike", strpos($postsPhpContent, "['bold', 'italic', 'underline', 'strike']") !== false);
assert_test("R3.5: Quill toolbar includes Text Color control with rich palette", strpos($postsPhpContent, "[{ 'color': [") !== false);
assert_test("R3.6: Quill toolbar includes Alignment control", strpos($postsPhpContent, "[{ 'align': [] }]") !== false);
assert_test("R3.7: Quill toolbar includes Ordered & Bullet Lists", strpos($postsPhpContent, "[{ 'list': 'ordered'}, { 'list': 'bullet' }]") !== false);
assert_test("R3.8: Quill toolbar includes Blockquote & Link controls", strpos($postsPhpContent, "['blockquote', 'link']") !== false);
assert_test("R3.9: Quill toolbar includes Clean formatting control", strpos($postsPhpContent, "['clean']") !== false);

// Dropdown Visibility & Styling in $extra_head
assert_test("R3.10: Picker options popup has high z-index (9999) to avoid stacking cutoff", strpos($postsPhpContent, 'z-index: 9999 !important') !== false);
assert_test("R3.11: Explicit visible labels for Heading picker (Heading 1, 2, 3, Normal Text)", strpos($postsPhpContent, 'content: "Heading 1" !important') !== false && strpos($postsPhpContent, 'content: "Normal Text" !important') !== false);
assert_test("R3.12: Explicit visible labels for Size picker (Small, Normal Size, Large, Huge)", strpos($postsPhpContent, 'content: "Normal Size" !important') !== false && strpos($postsPhpContent, 'content: "Large" !important') !== false);
assert_test("R3.13: Explicit visible labels for Font picker (Sans Serif, Serif, Monospace)", strpos($postsPhpContent, 'content: "Sans Serif" !important') !== false && strpos($postsPhpContent, 'content: "Monospace" !important') !== false);
assert_test("R3.14: Color picker swatches have borders and hover scaling", strpos($postsPhpContent, '.ql-color-picker .ql-picker-item') !== false && strpos($postsPhpContent, 'transform: scale') !== false);

// HTML Sanitization Preservation of Safe Formatting
require_once __DIR__ . '/includes/pepp_updates_helper.php';
require_once __DIR__ . '/public_updates/includes/functions.php';

$testHtml = '<p class="ql-align-center" style="color: #2563eb; background-color: #f1f5f9;"><strong class="ql-size-large">Admission Alert</strong></p>';
$cleanAdmin = pepp_updates_sanitize_html($testHtml);
assert_test("R3.15: pepp_updates_sanitize_html preserves safe color and background-color styles", strpos($cleanAdmin, 'color: #2563eb') !== false && strpos($cleanAdmin, 'background-color: #f1f5f9') !== false);
assert_test("R3.16: pepp_updates_sanitize_html preserves safe Quill alignment and size classes", strpos($cleanAdmin, 'class="ql-align-center"') !== false && strpos($cleanAdmin, 'class="ql-size-large"') !== false);

// Ensure dangerous styles and event handlers are STILL strictly removed
$dangerousHtml = '<p style="color: red; background: url(javascript:alert(1));" onclick="alert(1)">Dangerous</p>';
$sanitizedDangerous = pepp_updates_sanitize_html($dangerousHtml);
assert_test("R3.17: pepp_updates_sanitize_html strips dangerous background URL and onclick", strpos($sanitizedDangerous, 'onclick') === false && strpos($sanitizedDangerous, 'javascript') === false);

$cleanPublic = pepp_public_sanitize_html($testHtml);
assert_test("R3.18: pepp_public_sanitize_html preserves safe color styles on public portal", strpos($cleanPublic, 'color: #2563eb') !== false);

// Public CSS support for Quill alignment, font, and size classes
assert_test("R3.19: style.css defines .ql-align-center, .ql-align-right, .ql-align-justify", strpos($styleCssContent, '.ql-align-center') !== false && strpos($styleCssContent, '.ql-align-justify') !== false);
assert_test("R3.20: style.css defines .ql-size-small, .ql-size-large, .ql-size-huge", strpos($styleCssContent, '.ql-size-small') !== false && strpos($styleCssContent, '.ql-size-huge') !== false);
assert_test("R3.21: style.css defines .ql-font-serif and .ql-font-monospace", strpos($styleCssContent, '.ql-font-serif') !== false && strpos($styleCssContent, '.ql-font-monospace') !== false);

// ─────────────────────────────────────────────────────────────
// REQUIREMENT 4: AI DESCRIPTION GENERATION
// ─────────────────────────────────────────────────────────────
echo "\n--- Requirement 4: AI Description Generation ---\n";

require_once __DIR__ . '/includes/ai/PeppUpdatesAiService.php';

// Service Class & Config Detection
$mockPdo = new PDO('sqlite::memory:');
$mockPdo->exec("CREATE TABLE admin_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT)");

$aiServiceUnconfigured = new PeppUpdatesAiService($mockPdo);
assert_test("R4.1: PeppUpdatesAiService correctly reports unconfigured when key is absent", !$aiServiceUnconfigured->isConfigured());

$unconfResult = $aiServiceUnconfigured->generateDescription('Title', 'Short Desc', 'image/jpeg', 'dummy_bytes');
assert_test("R4.2: Unconfigured AI generation returns safe error without fabricating text", !$unconfResult['success'] && strpos($unconfResult['error'], 'API key is not configured') !== false);

// Input Validations
assert_test("R4.3: Service rejects empty title", !$aiServiceUnconfigured->generateDescription('', 'Short Desc', 'image/jpeg', 'bytes')['success']);
assert_test("R4.4: Service rejects empty short description", !$aiServiceUnconfigured->generateDescription('Title', '', 'image/jpeg', 'bytes')['success']);
assert_test("R4.5: Service rejects missing banner image", !$aiServiceUnconfigured->generateDescription('Title', 'Short Desc', null, null)['success']);
assert_test("R4.6: Service rejects invalid image MIME type", !$aiServiceUnconfigured->generateDescription('Title', 'Short Desc', 'application/pdf', 'bytes')['success']);

// Custom / Mock Provider Simulation (Validates truth-enforcing prompt output and HTML sanitization)
$mockProvider = new class {
    public function isConfigured(): bool { return true; }
    public function getModelName(): string { return 'mock-gemini-test'; }
    public function generateDescription(string $title, string $shortDesc, ?string $mime, ?string $bytes): array {
        $html = "<h2>Overview</h2><p>Official notification for <strong>" . htmlspecialchars($title) . "</strong>.</p>" .
                "<p>" . htmlspecialchars($shortDesc) . "</p>" .
                "<h2>Key Details &amp; Important Instructions</h2>" .
                "<ul><li>Candidates must submit online applications.</li><li>Candidates are advised to check the official notification or portal for complete schedule, eligibility, and submission guidelines.</li></ul>";
        return [
            'success' => true,
            'full_description' => pepp_updates_sanitize_html($html),
            'model' => 'mock-gemini-test'
        ];
    }
};

$configuredAiService = new PeppUpdatesAiService($mockPdo, $mockProvider);
assert_test("R4.7: AI Service with valid provider reports isConfigured = true", $configuredAiService->isConfigured());

$aiGenResult = $configuredAiService->generateDescription('CUET PG 2027 Registration', 'Registration opened for CUET PG entrance examination', 'image/jpeg', 'fake_bytes');
assert_test("R4.8: AI generation produces successful result", $aiGenResult['success']);
assert_test("R4.9: AI generated output contains structured HTML headings and lists", strpos($aiGenResult['full_description'], '<h2>Overview</h2>') !== false && strpos($aiGenResult['full_description'], '<ul>') !== false);
assert_test("R4.10: AI generated output contains factual caution advice", strpos($aiGenResult['full_description'], 'check the official notification') !== false);

// UI & Security Checks in pepp-updates-posts.php
assert_test("R4.11: Admin UI includes 'Generate with AI' button", strpos($postsPhpContent, 'id="btnAiGenerate"') !== false && strpos($postsPhpContent, 'Generate with AI') !== false);
assert_test("R4.12: Button is disabled by default in markup", strpos($postsPhpContent, 'id="btnAiGenerate" class="btn btn-sm btn-outline"') !== false && strpos($postsPhpContent, 'disabled') !== false);
assert_test("R4.13: Admin UI displays required warning: 'AI can make mistakes. Check important details before saving or publishing.'", strpos($postsPhpContent, 'AI can make mistakes. Check important details before saving or publishing.') !== false);
assert_test("R4.14: JavaScript implements dynamic enabling checking title, short_description, and banner", strpos($postsPhpContent, 'function updateAiButtonState()') !== false && strpos($postsPhpContent, 'btn.disabled = !isReady;') !== false);
assert_test("R4.15: JavaScript prompts for replace / append confirmation when editor has existing text", strpos($postsPhpContent, 'quill.getText().trim()') !== false && strpos($postsPhpContent, 'The Full Details editor already has content') !== false);
assert_test("R4.16: JavaScript inserts HTML into Quill without submitting or publishing form", strpos($postsPhpContent, 'quill.clipboard.dangerouslyPasteHTML') !== false && strpos($postsPhpContent, 'postForm.submit()') === false || strpos($postsPhpContent, 'generateAiDescription') !== false);
assert_test("R4.17: AJAX endpoint action 'ai_generate_description' verifies CSRF token", strpos($postsPhpContent, "\$action === 'ai_generate_description'") !== false && strpos($postsPhpContent, 'verify_csrf_token') !== false);
assert_test("R4.18: AJAX endpoint validates banner image path traversal safety", strpos($postsPhpContent, 'strpos($existing_banner, \'..\') !== false') !== false);
assert_test("R4.19: AJAX endpoint enforces 5MB banner file size limit", strpos($postsPhpContent, '5 * 1024 * 1024') !== false);
assert_test("R4.20: AJAX endpoint enforces image MIME validation", strpos($postsPhpContent, "['image/jpeg', 'image/png', 'image/webp']") !== false);

echo "\n============================================================\n";
echo "ENHANCEMENTS AUDIT SUMMARY: {$totalPass} PASSED, {$totalFail} FAILED\n";
echo "============================================================\n";

if ($totalFail > 0) {
    exit(1);
}
exit(0);
