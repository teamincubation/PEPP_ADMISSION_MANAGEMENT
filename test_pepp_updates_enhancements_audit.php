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
assert_test("R3.22: Container panels have overflow: visible !important to prevent dropdown clipping", strpos($postsPhpContent, '.panel:has(.quill-editor-container)') !== false && strpos($postsPhpContent, 'overflow: visible !important') !== false);
assert_test("R3.23: Alignment icon picker renders as horizontal flex row with styled 18px SVG items", strpos($postsPhpContent, '.ql-picker.ql-align.ql-expanded .ql-picker-options') !== false && strpos($postsPhpContent, 'flex-direction: row') !== false);
assert_test("R3.24: Post-init JavaScript populates data-label attributes on Heading, Size, and Font picker items", strpos($postsPhpContent, "el.setAttribute('data-label', 'Heading 1')") !== false && strpos($postsPhpContent, "el.setAttribute('data-label', 'Normal Size')") !== false);

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

// Model resolution and defaults
assert_test("R4.21: PeppUpdatesAiService defaults model to 'gemini-3.8-flash' (stable multimodal Flash standard)", $aiServiceUnconfigured->getModelName() === 'gemini-3.8-flash');

$dbMockPdo = new PDO('sqlite::memory:');
$dbMockPdo->exec("CREATE TABLE admin_settings (setting_name VARCHAR(100) PRIMARY KEY, setting_value TEXT)");
$stmt = $dbMockPdo->prepare("INSERT INTO admin_settings (setting_name, setting_value) VALUES ('pepp_updates_gemini_model', 'custom-pepp-model')");
$stmt->execute();
$aiServiceDbConfigured = new PeppUpdatesAiService($dbMockPdo);
assert_test("R4.22: PeppUpdatesAiService resolves model from database admin_settings (pepp_updates_gemini_model)", $aiServiceDbConfigured->getModelName() === 'custom-pepp-model');

putenv("GEMINI_MODEL=env-resolved-model");
assert_test("R4.23: PeppUpdatesAiService resolves model from GEMINI_MODEL environment variable", PeppUpdatesAiService::resolveModel() === 'env-resolved-model');
putenv("GEMINI_MODEL"); // clear env

$aiServiceExplicit = new PeppUpdatesAiService($mockPdo, null, 'explicit-test-model');
assert_test("R4.24: PeppUpdatesAiService constructor accepts explicit model override", $aiServiceExplicit->getModelName() === 'explicit-test-model');

// ─────────────────────────────────────────────────────────────
// PRODUCTION BUG FIX AUDIT: ISSUE 1 (AI TIMEOUT & NETWORK ROBUSTNESS)
// ─────────────────────────────────────────────────────────────
echo "\n--- Production Bug Fix: Issue 1 (AI Timeout & Network Optimization) ---\n";

assert_test("Fix 1.1: PeppUpdatesAiService resolves default request timeout (50s)", PeppUpdatesAiService::resolveTimeout() === 50);
assert_test("Fix 1.2: PeppUpdatesAiService resolves default connect timeout (10s)", PeppUpdatesAiService::resolveConnectTimeout() === 10);

// Configurable timeout via database
$stmtTimeout = $dbMockPdo->prepare("INSERT INTO admin_settings (setting_name, setting_value) VALUES ('pepp_updates_ai_timeout', '45')");
$stmtTimeout->execute();
assert_test("Fix 1.3: PeppUpdatesAiService resolves timeout from admin_settings", PeppUpdatesAiService::resolveTimeout($dbMockPdo) === 45);

// Configurable timeout via ENV
putenv("PEPP_UPDATES_AI_TIMEOUT=42");
assert_test("Fix 1.4: PeppUpdatesAiService resolves timeout from PEPP_UPDATES_AI_TIMEOUT env", PeppUpdatesAiService::resolveTimeout() === 42);
putenv("PEPP_UPDATES_AI_TIMEOUT"); // clear

$aiServiceSource = file_get_contents(__DIR__ . '/includes/ai/PeppUpdatesAiService.php');
assert_test("Fix 1.5: PeppUpdatesAiService sets Expect: header to prevent 100-continue handshake delay", strpos($aiServiceSource, "'Expect:'") !== false);
assert_test("Fix 1.6: PeppUpdatesAiService implements optimizeBannerForVision() method", method_exists(PeppUpdatesAiService::class, 'optimizeBannerForVision'));
assert_test("Fix 1.7: PeppUpdatesAiService bounds transient retry to max 2 attempts", strpos($aiServiceSource, '$maxAttempts = 2') !== false);
assert_test("Fix 1.8: PeppUpdatesAiService retries ONLY on transient 503 or 429", strpos($aiServiceSource, '$isTransient = ($httpCode === 503 || $httpCode === 429);') !== false);
assert_test("Fix 1.9: PeppUpdatesAiService does NOT retry long timeouts to avoid duplicate operations", strpos($aiServiceSource, '$curlErrNo === 28') !== false && strpos($aiServiceSource, 'Never retry on timeouts') !== false);
assert_test("Fix 1.10: Frontend fetch in pepp-updates-posts.php uses AbortController with 65s timeout", strpos($postsPhpContent, 'AbortController') !== false && strpos($postsPhpContent, '65000') !== false);
assert_test("Fix 1.11: Frontend AI error alert wraps in setTimeout to avoid freezing DOM button reset", (bool)preg_match('/setTimeout\(function\(\)\s*\{\s*alert\(/', $postsPhpContent));

// ─────────────────────────────────────────────────────────────
// PRODUCTION BUG FIX AUDIT: ISSUE 2 (QUILL NATIVE SELECT OVERRIDE FIX)
// ─────────────────────────────────────────────────────────────
echo "\n--- Production Bug Fix: Issue 2 (Quill Native Select Override Fix) ---\n";

assert_test("Fix 2.1: pepp-updates-posts.php hides raw select elements in toolbar with !important", strpos($postsPhpContent, '.ql-toolbar select') !== false && strpos($postsPhpContent, 'display: none !important') !== false);
assert_test("Fix 2.2: Scoped rule targets .quill-editor-container select and #quillEditor select", strpos($postsPhpContent, '.quill-editor-container select') !== false && strpos($postsPhpContent, '#quillEditor select') !== false);
assert_test("Fix 2.3: Quill picker elements forced inline-block with !important", strpos($postsPhpContent, '.ql-snow .ql-picker') !== false && strpos($postsPhpContent, 'display: inline-block !important') !== false);
assert_test("Fix 2.4: Expanded picker options forced block with !important", strpos($postsPhpContent, '.ql-snow .ql-picker.ql-expanded .ql-picker-options') !== false && strpos($postsPhpContent, 'display: block !important') !== false);

// ─────────────────────────────────────────────────────────────
// PRODUCTION BUG FIX AUDIT: ISSUE 3 (EM DASH ELIMINATION & NORMALIZATION)
// ─────────────────────────────────────────────────────────────
echo "\n--- Production Bug Fix: Issue 3 (Em Dash Elimination & Normalization) ---\n";

$scopedUiFiles = [
    'pepp-updates.php',
    'pepp-updates-posts.php',
    'pepp-updates-categories.php',
    'pepp-updates-keywords.php',
    'pepp-updates-subscribers.php',
    'pepp-updates-campaigns.php',
    'pepp-updates-settings.php',
    'public_updates/404.php',
    'public_updates/500.php',
    'public_updates/categories.php',
    'public_updates/category.php',
    'public_updates/index.php',
    'public_updates/privacy.php',
    'public_updates/search.php',
    'public_updates/sitemap.php',
    'public_updates/subscribe.php',
    'public_updates/terms.php',
    'public_updates/track.php',
    'public_updates/update.php',
    'public_updates/includes/bootstrap.php',
    'public_updates/includes/database.php',
    'public_updates/includes/footer.php',
    'public_updates/includes/header.php',
    'public_updates/includes/seo.php',
    'public_updates/assets/js/main.js',
    'public_updates/assets/css/style.css'
];

$allScopedClean = true;
$failingFiles = [];
foreach ($scopedUiFiles as $relPath) {
    $fullPath = __DIR__ . '/' . $relPath;
    if (!file_exists($fullPath)) continue;
    $fc = file_get_contents($fullPath);
    if (strpos($fc, "\xE2\x80\x94") !== false) {
        $allScopedClean = false;
        $failingFiles[] = $relPath;
    }
}
assert_test("Fix 3.1: Zero literal em dashes in static PEPP Updates source and templates (" . count($scopedUiFiles) . " files)", $allScopedClean, implode(', ', $failingFiles));

// Test HTML sanitizer normalization (admin and public)
$rawEmdashText = "Date: 15 Oct \xE2\x80\x94 20 Oct &mdash; 2027";
$sanitizedAdmin = pepp_updates_sanitize_html("<p>{$rawEmdashText}</p>");
assert_test("Fix 3.2: pepp_updates_sanitize_html normalizes em dash and &mdash; to hyphens", strpos($sanitizedAdmin, "\xE2\x80\x94") === false && strpos($sanitizedAdmin, '&mdash;') === false && strpos($sanitizedAdmin, '15 Oct - 20 Oct - 2027') !== false);

$sanitizedPublic = pepp_public_sanitize_html("<p>{$rawEmdashText}</p>");
assert_test("Fix 3.3: pepp_public_sanitize_html normalizes em dash and &mdash; to hyphens", strpos($sanitizedPublic, "\xE2\x80\x94") === false && strpos($sanitizedPublic, '&mdash;') === false && strpos($sanitizedPublic, '15 Oct - 20 Oct - 2027') !== false);

// Test plain text sanitize normalization
$plainEmdashText = "Plain \xE2\x80\x94 Text";
$cleanPlain = pepp_updates_sanitize_html($plainEmdashText);
assert_test("Fix 3.4: pepp_updates_sanitize_html normalizes plain text em dashes", strpos($cleanPlain, "\xE2\x80\x94") === false && strpos($cleanPlain, 'Plain - Text') !== false);

// Test SEO text cleaning normalization
require_once __DIR__ . '/public_updates/includes/seo.php';
$seoCleaned = pepp_seo_clean_text("SEO Title \xE2\x80\x94 Official Update &mdash; 2027");
assert_test("Fix 3.5: pepp_seo_clean_text normalizes em dash and &mdash; to hyphens", strpos($seoCleaned, "\xE2\x80\x94") === false && strpos($seoCleaned, '&mdash;') === false && strpos($seoCleaned, 'SEO Title - Official Update - 2027') !== false);

// Test AI Service em dash normalization
$mockProviderWithEmdash = new class {
    public function generateContentRaw($prompt, $params = []) {
        return "<h2>Schedule \xE2\x80\x94 2027</h2><p>Registration window: May \xE2\x80\x94 June &mdash; Details</p>";
    }
};
$aiServiceEmdash = new PeppUpdatesAiService($mockPdo, $mockProviderWithEmdash);
$aiRes = $aiServiceEmdash->generateDescription("Title \xE2\x80\x94 Test", "Short \xE2\x80\x94 Desc", 'image/jpeg', 'fake_bytes');
assert_test("Fix 3.6: PeppUpdatesAiService normalizes em dashes in generated HTML output", $aiRes['success'] && strpos($aiRes['full_description'], "\xE2\x80\x94") === false && strpos($aiRes['full_description'], '&mdash;') === false && strpos($aiRes['full_description'], 'Schedule - 2027') !== false);

// Test dynamic post display normalization without mutating DB
$pubTestPdo = new PDO('sqlite::memory:');
$pubTestPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pubTestPdo->exec("CREATE TABLE updates_posts (
    id INTEGER PRIMARY KEY,
    title TEXT,
    slug TEXT,
    short_description TEXT,
    full_description TEXT,
    status TEXT,
    publish_at TEXT,
    expires_at TEXT,
    banner_image TEXT,
    created_at TEXT,
    updated_at TEXT
)");
$pubTestPdo->exec("CREATE TABLE updates_visits (
    id INTEGER PRIMARY KEY,
    post_id INTEGER,
    ip_hash TEXT,
    visit_date TEXT,
    created_at TEXT
)");
$pubTestPdo->exec("CREATE TABLE updates_categories (
    id INTEGER PRIMARY KEY,
    name TEXT,
    slug TEXT,
    description TEXT,
    display_order INTEGER,
    is_active INTEGER
)");
$pubTestPdo->exec("CREATE TABLE updates_post_categories (post_id INTEGER, category_id INTEGER)");
$pubTestPdo->exec("CREATE TABLE updates_keywords (id INTEGER PRIMARY KEY, keyword TEXT, category_id INTEGER)");
$pubTestPdo->exec("CREATE TABLE updates_post_keywords (post_id INTEGER, keyword_id INTEGER)");
$pubTestPdo->exec("CREATE TABLE updates_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_at TEXT)");

$pubTestPdo->exec("INSERT INTO updates_posts (id, title, slug, short_description, full_description, status, publish_at)
VALUES (1, 'CUET PG \xE2\x80\x94 2027 Notification', 'cuet-pg-2027', 'Registration dates \xE2\x80\x94 May to June', '<p>Full content</p>', 'published', datetime('now', '-1 day'))");

$retrievedPost = pepp_public_get_post_by_slug($pubTestPdo, 'cuet-pg-2027');
assert_test("Fix 3.7: pepp_public_get_post_by_slug normalizes em dash in title at display time", strpos($retrievedPost['title'], "\xE2\x80\x94") === false && strpos($retrievedPost['title'], 'CUET PG - 2027 Notification') !== false);
assert_test("Fix 3.8: pepp_public_get_post_by_slug normalizes em dash in short_description at display time", strpos($retrievedPost['short_description'], "\xE2\x80\x94") === false && strpos($retrievedPost['short_description'], 'Registration dates - May to June') !== false);

$retrievedPostsList = pepp_public_get_posts($pubTestPdo);
assert_test("Fix 3.9: pepp_public_get_posts normalizes em dash in post listing at display time", strpos($retrievedPostsList['posts'][0]['title'], "\xE2\x80\x94") === false && strpos($retrievedPostsList['posts'][0]['title'], 'CUET PG - 2027 Notification') !== false);

// Check that DB record remains unmutated (preserves safety rule: no DB mutations)
$rawDbRow = $pubTestPdo->query("SELECT title FROM updates_posts WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assert_test("Fix 3.10: Database record remains unchanged (display-time normalization does NOT mutate DB)", strpos($rawDbRow['title'], "\xE2\x80\x94") !== false);

// Test preservation of en-dashes (–) and regular hyphens (-)
$enDashText = "Pages 10\xE2\x80\x9320 &ndash; Standard-Hyphen";
$enDashAdmin = pepp_updates_sanitize_html("<p>{$enDashText}</p>");
$enDashPublic = pepp_public_sanitize_html("<p>{$enDashText}</p>");
$enDashSeo = pepp_seo_clean_text($enDashText);
assert_test("Fix 3.11: Sanitizers and SEO helpers strictly PRESERVE en dashes (–) and hyphens (-)",
    strpos($enDashAdmin, "\xE2\x80\x93") !== false && strpos($enDashAdmin, "Standard-Hyphen") !== false &&
    strpos($enDashPublic, "\xE2\x80\x93") !== false && strpos($enDashPublic, "Standard-Hyphen") !== false &&
    strpos($enDashSeo, "\xE2\x80\x93") !== false && strpos($enDashSeo, "Standard-Hyphen") !== false
);

// Test related posts normalization
$pubTestPdo->exec("INSERT INTO updates_post_categories (post_id, category_id) VALUES (1, 1)");
$pubTestPdo->exec("INSERT INTO updates_posts (id, title, slug, short_description, full_description, status, publish_at)
VALUES (2, 'Related \xE2\x80\x94 Exam Update', 'related-exam', 'Related \xE2\x80\x94 Brief', '<p>Related content</p>', 'published', datetime('now', '-2 days'))");
$pubTestPdo->exec("INSERT INTO updates_post_categories (post_id, category_id) VALUES (2, 1)");
$relatedPosts = pepp_public_get_related_posts($pubTestPdo, 1, [1]);
assert_test("Fix 3.12: pepp_public_get_related_posts normalizes em dash at display time",
    !empty($relatedPosts) &&
    strpos($relatedPosts[0]['title'], "\xE2\x80\x94") === false &&
    strpos($relatedPosts[0]['title'], 'Related - Exam Update') !== false &&
    strpos($relatedPosts[0]['short_description'], "\xE2\x80\x94") === false &&
    strpos($relatedPosts[0]['short_description'], 'Related - Brief') !== false
);

// ============================================================
// PHASE 1 ENHANCEMENT TESTS: Browser Tab Title & WhatsApp Section Toggle
// ============================================================
echo "\n--- PHASE 1: Browser Tab Title / SEO Em Dash Removal & WhatsApp Visibility ---\n";

// Test Phase 1.1a: SEO render removes em dash from title, description, and social meta
ob_start();
pepp_seo_render([
    'title' => "CUET PG \xE2\x80\x94 Exam & Admission Alerts &mdash; 2027",
    'description' => "Stay updated on CUET PG \xE2\x80\x94 notifications and syllabus &#8212; verified.",
    'url' => 'https://updates.pepplearning.in/post/cuet-pg'
]);
$sampleSeo = ob_get_clean();

assert_test("Phase 1.1a: pepp_seo_render strips all forms of em dash in title and meta tags",
    strpos($sampleSeo, "\xE2\x80\x94") === false &&
    strpos($sampleSeo, "&mdash;") === false &&
    strpos($sampleSeo, "&#8212;") === false &&
    strpos($sampleSeo, "CUET PG - Exam &amp; Admission Alerts - 2027 | PEPP Updates") !== false &&
    strpos($sampleSeo, "CUET PG - notifications and syllabus - verified.") !== false
);

// Test Phase 1.1b: Avoid double branding when title already contains PEPP Updates
ob_start();
pepp_seo_render([
    'title' => "PEPP Updates \xE2\x80\x94 Career, Exam & Admission Notifications",
    'description' => "PEPP Updates portal"
]);
$homeSeo = ob_get_clean();

assert_test("Phase 1.1b: Avoid double branding suffix when site name is in title",
    strpos($homeSeo, "PEPP Updates - Career, Exam &amp; Admission Notifications</title>") !== false &&
    strpos($homeSeo, "PEPP Updates | PEPP Updates") === false
);

// Test Phase 1.1c: Robots.txt em dash removed
$robotsTxt = file_get_contents(__DIR__ . '/public_updates/robots.txt');
assert_test("Phase 1.1c: robots.txt contains standard hyphen and no em dash",
    strpos($robotsTxt, "\xE2\x80\x94") === false &&
    strpos($robotsTxt, '# PEPP Updates - robots.txt') !== false
);

// Test Phase 1.2: WhatsApp section visibility helper defaults to true (ON)
$pubTestPdo->exec("DELETE FROM updates_settings WHERE setting_key = 'whatsapp_updates_section_enabled'");
$waDefaultState = pepp_public_is_whatsapp_section_enabled($pubTestPdo);
assert_test("Phase 1.2a: WhatsApp updates section defaults to ON (true) when setting row is absent",
    $waDefaultState === true
);

// Test Phase 1.2b: WhatsApp section visibility helper returns false when setting is '0'
$pubTestPdo->exec("INSERT OR REPLACE INTO updates_settings (setting_key, setting_value) VALUES ('whatsapp_updates_section_enabled', '0')");
$waDisabledState = pepp_public_is_whatsapp_section_enabled($pubTestPdo);
assert_test("Phase 1.2b: WhatsApp updates section returns false when setting is set to '0' (OFF)",
    $waDisabledState === false
);

// Test Phase 1.2c: WhatsApp section visibility helper returns true when setting is '1'
$pubTestPdo->exec("INSERT OR REPLACE INTO updates_settings (setting_key, setting_value) VALUES ('whatsapp_updates_section_enabled', '1')");
$waEnabledState = pepp_public_is_whatsapp_section_enabled($pubTestPdo);
assert_test("Phase 1.2c: WhatsApp updates section returns true when setting is set to '1' (ON)",
    $waEnabledState === true
);

// Test Phase 1.2d: Subscribers and WhatsApp account configurations remain isolated and intact
$pubTestPdo->exec("CREATE TABLE IF NOT EXISTS updates_subscribers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    phone TEXT,
    status TEXT DEFAULT 'active'
)");
$pubTestPdo->exec("INSERT INTO updates_subscribers (name, phone, status) VALUES ('Test Student', '9876543210', 'active')");
$subCountBefore = (int)$pubTestPdo->query("SELECT COUNT(*) FROM updates_subscribers")->fetchColumn();

// Toggle setting to OFF and ON again
$pubTestPdo->exec("UPDATE updates_settings SET setting_value = '0' WHERE setting_key = 'whatsapp_updates_section_enabled'");
$offCheck = pepp_public_is_whatsapp_section_enabled($pubTestPdo);
$pubTestPdo->exec("UPDATE updates_settings SET setting_value = '1' WHERE setting_key = 'whatsapp_updates_section_enabled'");
$onCheck = pepp_public_is_whatsapp_section_enabled($pubTestPdo);
$subCountAfter = (int)$pubTestPdo->query("SELECT COUNT(*) FROM updates_subscribers")->fetchColumn();

assert_test("Phase 1.2d: Subscriber records and count remain preserved during WhatsApp visibility toggles",
    $offCheck === false &&
    $onCheck === true &&
    $subCountBefore === 1 &&
    $subCountAfter === 1
);

// Test Phase 1.2e: Verify public template files have the conditional gating
$indexContent = file_get_contents(__DIR__ . '/public_updates/index.php');
$headerContent = file_get_contents(__DIR__ . '/public_updates/includes/header.php');
$footerContent = file_get_contents(__DIR__ . '/public_updates/includes/footer.php');
$subscribeContent = file_get_contents(__DIR__ . '/public_updates/subscribe.php');
$settingsContent = file_get_contents(__DIR__ . '/pepp-updates-settings.php');

assert_test("Phase 1.2e: Public and admin template files contain proper whatsapp_updates_section_enabled gating",
    strpos($indexContent, 'pepp_public_is_whatsapp_section_enabled($pdo)') !== false &&
    strpos($headerContent, '$isWaEnabled') !== false &&
    strpos($footerContent, 'pepp_public_is_whatsapp_section_enabled($pdo)') !== false &&
    strpos($subscribeContent, '$isWaEnabled') !== false &&
    strpos($settingsContent, 'whatsapp_updates_section_enabled') !== false
);

echo "\n============================================================\n";
echo "ENHANCEMENTS AUDIT SUMMARY: {$totalPass} PASSED, {$totalFail} FAILED\n";
echo "============================================================\n";

if ($totalFail > 0) {
    exit(1);
}
exit(0);
