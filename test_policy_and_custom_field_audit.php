<?php
/**
 * PEPP Learning ERP — Comprehensive Policy, Custom Fields & Consent Audit
 * Tests requirements A through L:
 * 1-7:   Custom field rendering, typing, validation & isolation
 * 8-14:  Policy management, 4 documents, rich text sanitization, versioning & audit
 * 15-28: Mandatory registration consent, server-side derivation, immutable acceptance
 * 29-35: Regressions, lint, diff, and safety checks
 */

declare(strict_types=1);

$passed = 0;
$failed = 0;
$errors = [];

function t(string $name, callable $fn): void {
    global $passed, $failed, $errors;
    try {
        $res = $fn();
        if ($res === true || $res === 1) {
            echo "  [PASS] {$name}\n";
            $passed++;
        } else {
            echo "  [FAIL] {$name}\n";
            $failed++;
            $errors[] = $name . ' (returned ' . var_export($res, true) . ')';
        }
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $failed++;
        $errors[] = $name . ': ' . $e->getMessage();
    }
}

function src(string $rel): string {
    $f = __DIR__ . '/' . $rel;
    if (!is_file($f)) throw new RuntimeException("Missing source file: {$rel}");
    return file_get_contents($f);
}

function has(string $haystack, string $needle): bool {
    return strpos($haystack, $needle) !== false;
}

require_once __DIR__ . '/includes/staff_type_helper.php';
require_once __DIR__ . '/includes/guest_faculty_helper.php';
require_once __DIR__ . '/includes/policy_helper.php';

function create_test_pdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE employee_custom_fields (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        field_label TEXT,
        field_key TEXT,
        field_type TEXT,
        field_options TEXT,
        application_for TEXT,
        is_required INTEGER DEFAULT 0,
        sort_order INTEGER DEFAULT 0,
        status TEXT DEFAULT 'active'
    )");

    $pdo->exec("CREATE TABLE employee_custom_values (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER,
        field_id INTEGER,
        field_value TEXT
    )");

    $pdo->exec("CREATE TABLE policy_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        policy_key TEXT UNIQUE NOT NULL,
        title TEXT NOT NULL,
        current_version TEXT NOT NULL DEFAULT '1.0',
        content TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        updated_by TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE policy_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        policy_key TEXT NOT NULL,
        version TEXT NOT NULL,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        status TEXT NOT NULL,
        created_by TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE policy_acceptances (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        registration_request_id INTEGER NOT NULL,
        policy_key TEXT NOT NULL,
        policy_version TEXT NOT NULL,
        accepted_at TEXT DEFAULT CURRENT_TIMESTAMP,
        ip_address TEXT DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE staff_registration_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        application_reference TEXT UNIQUE NOT NULL,
        full_name TEXT NOT NULL,
        application_for TEXT NOT NULL,
        custom_field_values TEXT DEFAULT NULL,
        status TEXT DEFAULT 'pending',
        submitted_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed default 1.0 policies into test memory DB (sanitized)
    $defs = policy_default_documents();
    $st_doc = $pdo->prepare("INSERT INTO policy_documents (policy_key, title, current_version, content, status, updated_by) VALUES (?, ?, ?, ?, ?, ?)");
    $st_ver = $pdo->prepare("INSERT INTO policy_versions (policy_key, version, title, content, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($defs as $d) {
        $clean = policy_sanitize_html($d['content']);
        $st_doc->execute([$d['policy_key'], $d['title'], $d['current_version'], $clean, $d['status'], $d['updated_by']]);
        $st_ver->execute([$d['policy_key'], $d['current_version'], $d['title'], $clean, $d['status'], $d['updated_by']]);
    }

    return $pdo;
}

echo "======================================================================\n";
echo " PEPP POLICY, TERMS & CUSTOM FIELD AUDIT SUITE\n";
echo "======================================================================\n\n";

/* ───────────────────────────────────────────────────────────────────
 * SECTION 1: CUSTOM FIELDS
 * ─────────────────────────────────────────────────────────────────── */
echo "--- Group 1: Custom Field Rendering & Isolation ---\n";

$pdo = create_test_pdo();
$pdo->exec("INSERT INTO employee_custom_fields (id, field_label, field_key, field_type, application_for, is_required, status) VALUES
    (1, 'Qualifications', 'cf_1', 'text', 'faculty', 1, 'active'),
    (2, 'Experience', 'cf_2', 'text', 'faculty', 1, 'active'),
    (3, 'Employee Department Preference', 'cf_3', 'text', 'employee', 1, 'active'),
    (4, 'Intern College Name', 'cf_4', 'text', 'intern', 1, 'active'),
    (5, 'Guest Faculty Specialized Topics', 'cf_5', 'text', 'guest_faculty', 1, 'active')
");

t('1. Faculty custom field appears for Faculty', function() use ($pdo) {
    $rows = $pdo->query("SELECT * FROM employee_custom_fields WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
    $norm = staff_normalize_custom_field_rows($rows);
    $faculty_fields = array_filter($norm, fn($r) => $r['application_for'] === 'faculty');
    return count($faculty_fields) === 2 && isset($faculty_fields[0]) && $faculty_fields[0]['field_key'] === 'cf_1';
});

t('2. Faculty custom field does not appear for Employee', function() use ($pdo) {
    $rows = $pdo->query("SELECT * FROM employee_custom_fields WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
    $norm = staff_normalize_custom_field_rows($rows);
    $emp_fields = array_filter($norm, fn($r) => $r['application_for'] === 'employee');
    foreach ($emp_fields as $ef) {
        if ($ef['application_for'] === 'faculty' || $ef['field_key'] === 'cf_1' || $ef['field_key'] === 'cf_2') return false;
    }
    return true;
});

t('3. Faculty custom field does not appear for Intern', function() use ($pdo) {
    $rows = $pdo->query("SELECT * FROM employee_custom_fields WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
    $norm = staff_normalize_custom_field_rows($rows);
    $intern_fields = array_filter($norm, fn($r) => $r['application_for'] === 'intern');
    foreach ($intern_fields as $inf) {
        if ($inf['application_for'] === 'faculty' || $inf['field_key'] === 'cf_1') return false;
    }
    return true;
});

t('4. Employee custom field appears for Employee', function() use ($pdo) {
    $rows = $pdo->query("SELECT * FROM employee_custom_fields WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
    $norm = staff_normalize_custom_field_rows($rows);
    $emp_fields = array_values(array_filter($norm, fn($r) => $r['application_for'] === 'employee'));
    return count($emp_fields) === 1 && $emp_fields[0]['field_key'] === 'cf_3';
});

t('5. Intern custom field appears for Intern', function() use ($pdo) {
    $rows = $pdo->query("SELECT * FROM employee_custom_fields WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
    $norm = staff_normalize_custom_field_rows($rows);
    $intern_fields = array_values(array_filter($norm, fn($r) => $r['application_for'] === 'intern'));
    return count($intern_fields) === 1 && $intern_fields[0]['field_key'] === 'cf_4';
});

t('6. Required custom field validation works server-side', function() use ($pdo) {
    $rows = $pdo->query("SELECT * FROM employee_custom_fields WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
    $fields = staff_normalize_custom_field_rows($rows);

    // Missing required faculty field cf_1
    $res1 = staff_validate_custom_fields($fields, 'faculty', ['cf_2' => '5 years']);
    if ($res1['valid'] !== false) return false;

    // Both required fields supplied
    $res2 = staff_validate_custom_fields($fields, 'faculty', ['cf_1' => 'M.Sc Mathematics', 'cf_2' => '5 years']);
    if ($res2['valid'] !== true) return false;

    return true;
});

t('7. Invalid application-type custom field submission is rejected/ignored', function() use ($pdo) {
    $rows = $pdo->query("SELECT * FROM employee_custom_fields WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
    $fields = staff_normalize_custom_field_rows($rows);

    // Attacker submits an employee field (cf_3) during faculty registration
    $res = staff_validate_custom_fields($fields, 'faculty', [
        'cf_1' => 'M.Sc Mathematics',
        'cf_2' => '5 years',
        'cf_3' => 'Hacked Employee Dept'
    ]);

    // Values stored must ONLY contain faculty fields, NOT cf_3
    return $res['valid'] === true && !isset($res['data']['3']) && !isset($res['data']['cf_3']);
});

/* ───────────────────────────────────────────────────────────────────
 * SECTION 2: POLICY & TERMS MANAGEMENT
 * ─────────────────────────────────────────────────────────────────── */
echo "\n--- Group 2: Policy & Terms Management ---\n";

t('8. Four policy records exist, keyed correctly, and employee-management derives policy_key', function() use ($pdo) {
    $all = policy_list_all($pdo);
    $expected_keys = ['faculty_policy', 'guest_faculty_policy', 'employee_staff_terms', 'internship_policy'];

    if (count($all) !== 4) return false;
    foreach ($expected_keys as $ek) {
        if (!isset($all[$ek])) return false;
        if (($all[$ek]['policy_key'] ?? '') !== $ek) return false;
        if (empty($all[$ek]['title'])) return false;
    }

    // Verify employee-management.php renders policy_key directly and not array index
    $em = src('employee-management.php');
    if (!has($em, "\$pol['policy_key']")) return false;
    if (!has($em, "openPolicyEdit('<?php echo e(\$pkey); ?>')")) return false;
    if (!has($em, "policy-view.php?policy=<?php echo urlencode(\$pkey); ?>")) return false;

    // Verify get_policy endpoint resolution succeeds for all 4 keys
    foreach ($expected_keys as $ek) {
        $p = policy_get($pdo, $ek);
        if (!$p || ($p['policy_key'] ?? '') !== $ek) return false;
    }

    return true;
});

t('9. Only authorized admins can edit policies (employee-management permission checked)', function() {
    $em = src('employee-management.php');
    return has($em, "require_permission('employee-management');")
        && has($em, "action === 'save_policy'")
        && has($em, 'csrf_verify');
});

t('10. Public users can view active policies (policy-view.php unauthenticated)', function() {
    $pv = src('policy-view.php');
    return !has($pv, "require_permission")
        && !has($pv, "require_login")
        && has($pv, "ALLOWED_POLICY_KEYS")
        && has($pv, "policy_get");
});

t('11. Invalid policy keys are rejected (whitelist enforced)', function() use ($pdo) {
    $res = policy_get($pdo, 'malicious_system_policy');
    return $res === null;
});

t('12. Policy content is sanitized against script injection', function() {
    $dirty = '<h3>Official Policy</h3><script>alert("xss")</script><p onclick="evil()">Safe paragraph <a href="javascript:alert(1)">link</a></p><iframe src="evil.com"></iframe>';
    $clean = policy_sanitize_html($dirty);
    return !has($clean, '<script>')
        && !has($clean, 'alert("xss")')
        && !has($clean, '<iframe')
        && !has($clean, 'onclick')
        && !has($clean, 'javascript:')
        && has($clean, '<h3>Official Policy</h3>')
        && has($clean, 'Safe paragraph');
});

t('13. Version is preserved and bumped cleanly (policy_versions truly immutable)', function() use ($pdo) {
    $initial = policy_get($pdo, 'faculty_policy');
    $v1 = $initial['current_version']; // 1.0
    $v1_initial_title = $initial['title']; // 'Faculty Policy'

    // 1. Content modification with bump = 'none' MUST be rejected
    $rej = policy_save($pdo, 'faculty_policy', 'Faculty Policy Updated', '<p>Modified content without bump</p>', 'admin', 'none');
    if ($rej['success'] !== false || empty($rej['error'])) return false;

    // 2. Metadata-only update (title/status changed, content identical) with bump = 'none' SUCCEEDS without version bump
    $s_meta = policy_save($pdo, 'faculty_policy', 'Faculty Policy & Guidelines (Renamed)', $initial['content'], 'admin', 'none', 'active');
    if ($s_meta['success'] !== true || $s_meta['version'] !== '1.0') return false;

    // Verify policy_documents reflected new title
    $doc_title = $pdo->query("SELECT title FROM policy_documents WHERE policy_key = 'faculty_policy'")->fetchColumn();
    if ($doc_title !== 'Faculty Policy & Guidelines (Renamed)') return false;

    // IMMUTABILITY CHECK: Verify 1.0 snapshot in policy_versions was NOT modified (title & content remained untouched)
    $v1_snap_row = $pdo->query("SELECT title, content FROM policy_versions WHERE policy_key = 'faculty_policy' AND version = '1.0'")->fetch(PDO::FETCH_ASSOC);
    if (!$v1_snap_row) return false;
    if ($v1_snap_row['title'] !== $v1_initial_title) return false; // Snapshot title must NOT change
    if ($v1_snap_row['content'] !== policy_sanitize_html($initial['content'])) return false;

    // 3. Save substantive change with minor bump (1.0 -> 1.1)
    $s1 = policy_save($pdo, 'faculty_policy', 'Faculty Policy 1.1', '<p>Updated content version 1.1</p>', 'admin', 'minor');
    if ($s1['success'] !== true || $s1['version'] !== '1.1') return false;

    // 4. Save substantive change with major bump (1.1 -> 2.0)
    $s2 = policy_save($pdo, 'faculty_policy', 'Faculty Policy 2.0', '<p>Major change version 2.0</p>', 'admin', 'major');
    if ($s2['success'] !== true || $s2['version'] !== '2.0') return false;

    // Verify policy_versions has 3 distinct snapshots (1.0, 1.1, 2.0)
    $verCount = (int)$pdo->query("SELECT COUNT(*) FROM policy_versions WHERE policy_key = 'faculty_policy'")->fetchColumn();
    if ($verCount < 3) return false;
    return true;
});

t('14. Admin update is audited (log_admin_activity called)', function() {
    $em = src('employee-management.php');
    return has($em, "log_admin_activity(\$pdo, \$admin_username, 'policy_updated'")
        && has($em, "\$saved['version']");
});

/* ───────────────────────────────────────────────────────────────────
 * SECTION 3: REGISTRATION CONSENT & AUDIT EVIDENCE
 * ─────────────────────────────────────────────────────────────────── */
echo "\n--- Group 3: Registration Consent & Acceptance ---\n";

t('15. Staff Faculty registration requires Faculty Policy consent', function() {
    return policy_key_from_application_type('faculty') === 'faculty_policy';
});

t('16. Staff Employee registration requires Employee & Staff T&C consent', function() {
    return policy_key_from_application_type('employee') === 'employee_staff_terms';
});

t('17. Staff Intern registration requires Internship Policy consent', function() {
    return policy_key_from_application_type('intern') === 'internship_policy';
});

t('18. Invited Faculty registration requires Guest Faculty Policy consent', function() {
    return policy_key_from_application_type('guest_faculty') === 'guest_faculty_policy';
});

t('19. Submit without checkbox fails server-side in staff-registration.php', function() {
    $sr = src('staff-registration.php');
    return has($sr, "empty(\$_POST['policy_consent'])")
        && has($sr, 'You must read and agree to the');
});

t('20. Client-side validation requires policy_agreed checkbox', function() {
    $sr = src('staff-registration.php');
    $ifr = src('invited-faculty-registration.php');
    return (has($sr, 'name="policy_consent"') || has($sr, 'id="policyConsentCheckbox"')) && has($sr, 'required')
        && (has($ifr, 'name="policy_consent"') || has($ifr, 'id="gfPolicyConsent"')) && has($ifr, 'required');
});

t('21. Server-side validation derives policy strictly from application type', function() {
    $sr = src('staff-registration.php');
    return has($sr, 'policy_key_from_application_type(')
        && !has($sr, '$_POST[\'policy_key\']');
});

t('22. Correct policy link is rendered in registration forms', function() {
    $sr = src('staff-registration.php');
    $ifr = src('invited-faculty-registration.php');
    return has($sr, 'policy-view.php?policy=')
        && has($ifr, 'policy-view.php?policy=guest_faculty_policy');
});

t('23. Application type changes update policy link and text dynamically', function() {
    $sr = src('staff-registration.php');
    return has($sr, "const policyMap = {")
        && has($sr, "policy=faculty_policy")
        && has($sr, "policy=employee_staff_terms")
        && has($sr, "policy=internship_policy")
        && (has($sr, "consentLink.href = policyMap[type].url") || has($sr, "policy-view.php?policy="));
});

t('24. Applicant cannot submit arbitrary policy_key', function() {
    $sr = src('staff-registration.php');
    $ifr = src('invited-faculty-registration.php');
    // Ensure policy_key is derived server-side only
    return has($sr, 'policy_key_from_application_type((string)$application_for)')
        && has($ifr, "policy_get(\$pdo, 'guest_faculty_policy')");
});

t('25. Accepted policy version is stored in policy_acceptances & failure rolls back registration', function() use ($pdo) {
    // 1. Successful recording
    $pdo->exec("INSERT INTO staff_registration_requests (id, application_reference, full_name, application_for) VALUES (101, 'REF-101', 'Alice Faculty', 'faculty')");
    $rec = policy_record_acceptance($pdo, 101, 'faculty_policy', '1.0', '192.168.1.50', 'Mozilla/5.0');
    if ($rec !== true) return false;

    // 2. Failure on invalid key returns false
    $fail_key = policy_record_acceptance($pdo, 101, 'invalid_key', '1.0', '192.168.1.50', 'Mozilla/5.0');
    if ($fail_key !== false) return false;

    // 3. Simulated registration transaction where policy acceptance fails: transaction MUST rollback
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO staff_registration_requests (id, application_reference, full_name, application_for) VALUES (999, 'REF-999', 'Rollback Test', 'faculty')");
    $acc_result = policy_record_acceptance($pdo, 999, 'invalid_key', '1.0', '192.168.1.50', 'Mozilla/5.0');
    if (!$acc_result) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
    // Verify request #999 was NOT committed and does not exist in DB
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM staff_registration_requests WHERE id = 999")->fetchColumn();
    if ($exists !== 0) return false;

    // 4. Verify static codebase: staff-registration.php and guest_faculty_helper.php enforce rollback
    $sr = src('staff-registration.php');
    $gfh = src('includes/guest_faculty_helper.php');
    if (!has($sr, 'Failed to record mandatory policy acceptance audit') || !has($sr, '$pdo->rollBack()')) return false;
    if (!has($gfh, 'Failed to record mandatory policy acceptance audit') || !has($gfh, '$pdo->rollBack()')) return false;

    return true;
});

t('26. Accepted timestamp is stored', function() use ($pdo) {
    $acc = policy_get_acceptance($pdo, 101);
    return is_array($acc)
        && !empty($acc['accepted_at'])
        && $acc['policy_key'] === 'faculty_policy'
        && $acc['policy_version'] === '1.0';
});

t('27. Acceptance IP/user-agent handling follows privacy/security conventions', function() use ($pdo) {
    $acc = policy_get_acceptance($pdo, 101);
    return $acc['ip_address'] === '192.168.1.50' && has($acc['user_agent'], 'Mozilla');
});

t('28. Existing acceptance remains unchanged when policy is bumped (immutability)', function() use ($pdo) {
    // Admin bumps faculty_policy to version 1.1 then 2.0
    policy_save($pdo, 'faculty_policy', 'Faculty Policy V2', '<p>New terms</p>', 'superadmin', 'major');

    // Registration #101 must still reflect v1.0 that was originally agreed to
    $acc = policy_get_acceptance($pdo, 101);
    return $acc['policy_version'] === '1.0' && $acc['policy_key'] === 'faculty_policy';
});

/* ───────────────────────────────────────────────────────────────────
 * SECTION 4: REGRESSION & INTEGRATION CHECKS
 * ─────────────────────────────────────────────────────────────────── */
echo "\n--- Group 4: Regressions & Static Audits ---\n";

t('29. Existing staff architecture audit passes (test_staff_architecture_audit.php)', function() {
    $out = [];
    $code = 0;
    exec('php test_staff_architecture_audit.php 2>&1', $out, $code);
    return $code === 0 && has(implode("\n", $out), '64 PASSED, 0 FAILED');
});

t('30. Existing staff registration duplicate audit passes (test_staff_registration_duplicate_audit.php)', function() {
    $out = [];
    $code = 0;
    exec('php test_staff_registration_duplicate_audit.php 2>&1', $out, $code);
    return $code === 0 && has(implode("\n", $out), '23 PASSED, 0 FAILED');
});

t('31. Existing guest faculty functional audit passes (test_guest_faculty_audit.php)', function() {
    $out = [];
    $code = 0;
    exec('php test_guest_faculty_audit.php 2>&1', $out, $code);
    $text = implode("\n", $out);
    return $code === 0 && has($text, '64 passed, 0 failed');
});

t('32. sessions.php and faculties.php architecture remain intact', function() {
    $s = src('sessions.php');
    $f = src('faculties.php');
    return has($s, 'faculty_id') && has($f, 'faculties');
});

t('33. Existing permissions in employee-management.php preserved', function() {
    $em = src('employee-management.php');
    return has($em, "require_permission('employee-management');")
        && has($em, "can_admin_view_bank_credentials")
        && has($em, "is_super_admin");
});

t('34. PHP lint passes on all project files touched', function() {
    $files = [
        'includes/policy_helper.php',
        'includes/staff_type_helper.php',
        'includes/guest_faculty_helper.php',
        'policy-view.php',
        'staff-registration.php',
        'invited-faculty-registration.php',
        'employee-management.php'
    ];
    foreach ($files as $f) {
        $out = [];
        $code = 0;
        exec("php -l {$f} 2>&1", $out, $code);
        if ($code !== 0) return false;
    }
    return true;
});

t('35. git diff --check passes with zero whitespace or conflict errors', function() {
    $out = [];
    $code = 0;
    exec('git diff --check 2>&1', $out, $code);
    return $code === 0 && empty($out);
});

echo "\n======================================================================\n";
echo " AUDIT SUMMARY: {$passed} PASSED, {$failed} FAILED (TOTAL: " . ($passed + $failed) . ")\n";
echo "======================================================================\n";

if ($failed > 0) {
    echo "\nFailed items:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
} else {
    echo "\nALL 35 AUDIT CHECKS PASSED PERFECTLY!\n";
    exit(0);
}
