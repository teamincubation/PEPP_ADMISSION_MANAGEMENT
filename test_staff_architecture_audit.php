<?php
/**
 * Test Suite: Staff Architecture Audit (database-update-56)
 *
 *  Group 2 — Faculty approval: UNIQUE(email, application_for) behaviour
 *  Group 3 — Application-type-scoped custom fields (behavioural + static)
 *  Group 4 — Faculty isolation from generic Employee/Intern modules
 *  Group 5 — Public Home redirects vs admin logout
 *  (Group 1, the duplicate-application matrix, lives in
 *   test_staff_registration_duplicate_audit.php)
 */
require_once __DIR__ . '/includes/staff_type_helper.php';

$passed = 0; $failed = 0;
function t($name, $cb) {
    global $passed, $failed;
    try { $r = $cb(); } catch (Throwable $e) { echo "  [FAIL] $name: " . $e->getMessage() . "\n"; $failed++; return; }
    if ($r !== false) { echo "  [PASS] $name\n"; $passed++; } else { echo "  [FAIL] $name\n"; $failed++; }
}
function src($f) { return file_get_contents(__DIR__ . '/' . $f); }
function has($s, $needle) { return strpos($s, $needle) !== false; }

echo "======================================================================\n STAFF ARCHITECTURE AUDIT\n======================================================================\n";

/* ───────── GROUP 2 ───────── */
echo "\n--- Group 2: Faculty approval / scoped unique key ---\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE employees (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id TEXT, full_name TEXT, email TEXT NOT NULL, application_for TEXT NOT NULL DEFAULT 'employee', status TEXT DEFAULT 'active')");
$pdo->exec("CREATE UNIQUE INDEX uq_emp_email_type ON employees (email, application_for)");
$ins = $pdo->prepare("INSERT INTO employees (employee_id, full_name, email, application_for) VALUES (?,?,?,?)");

t('Employee + Faculty with same email: insert SUCCEEDS (no uq_emp_email violation)', function() use ($pdo, $ins) {
    $ins->execute(['EMP00124', 'Test', 'test@example.com', 'employee']);
    $ins->execute(['EMP00125', 'Test', 'test@example.com', 'faculty']);
    return (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE email='test@example.com'")->fetchColumn() === 2;
});
t('Employee + Intern with same email allowed', function() use ($ins) { $ins->execute(['EMP00126', 'Test', 'test@example.com', 'intern']); return true; });
t('Employee + Employee with same email BLOCKED at DB level', function() use ($ins) {
    try { $ins->execute(['EMP00127', 'Test', 'test@example.com', 'employee']); return false; } catch (PDOException $e) { return $e->getCode() === '23000'; }
});
t('Faculty + Faculty with same email BLOCKED at DB level', function() use ($ins) {
    try { $ins->execute(['EMP00128', 'Test', 'test@example.com', 'faculty']); return false; } catch (PDOException $e) { return $e->getCode() === '23000'; }
});
t('Intern + Intern with same email BLOCKED at DB level', function() use ($ins) {
    try { $ins->execute(['EMP00129', 'Test', 'test@example.com', 'intern']); return false; } catch (PDOException $e) { return $e->getCode() === '23000'; }
});
t('Employee record remains Employee after Faculty approval', function() use ($pdo) {
    return $pdo->query("SELECT application_for FROM employees WHERE employee_id='EMP00124'")->fetchColumn() === 'employee';
});
t('Employee Management directory (generic predicate) shows employee+intern, NOT faculty', function() use ($pdo) {
    $rows = $pdo->query("SELECT application_for FROM employees e WHERE " . staff_generic_type_sql('e'))->fetchAll(PDO::FETCH_COLUMN);
    return count($rows) === 2 && !in_array('faculty', $rows, true);
});
t('Faculties/Sessions source predicate (application_for=faculty) returns the Faculty record', function() use ($pdo) {
    return (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE application_for='faculty' AND email='test@example.com'")->fetchColumn() === 1;
});

$mig = src('database-update-56.sql');
t('Migration 56 checks uq_emp_email exists before DROP (information_schema)', fn() => has($mig, 'uq_emp_email') && has($mig, 'information_schema.statistics') && has($mig, 'DROP INDEX'));
t('Migration 56 creates uq_emp_email_type (email, application_for)', fn() => has($mig, 'uq_emp_email_type') && has($mig, '`email`, `application_for`') || has($mig, 'email, application_for'));
t('Migration 56 verifies duplicates (GROUP BY email, application_for HAVING COUNT(*) > 1) and deletes nothing', fn() => has($mig, 'HAVING COUNT(*) > 1') && !preg_match('/DELETE\s+FROM/i', $mig));
t('Migration 56 adds employee_custom_fields.application_for with employee default', fn() => has($mig, 'employee_custom_fields') && has($mig, "DEFAULT 'employee'"));
$cfgdb = src('config/database.php');
t('Self-healing: config/database.php loads helper and runs ensure_staff_type_schema', fn() => has($cfgdb, 'staff_type_helper.php') && has($cfgdb, 'ensure_staff_type_schema($pdo)'));
$helper = src('includes/staff_type_helper.php');
t('ensure_staff_type_schema: old index dropped only when present and only after new key exists', fn() =>
    has($helper, "\$idx('employees', 'uq_emp_email_type') && \$idx('employees', 'uq_emp_email')") && strpos($helper, 'ADD UNIQUE KEY') < strpos($helper, 'DROP INDEX'));
$em = src('employee-management.php');
t('approve_application: same-type pre-check + friendly message', fn() => has($em, 'WHERE email = ? AND application_for = ?') && has($em, 'record with this email already exists'));
$sr = src('staff-registration.php');
t('staff-registration duplicate check stays application_for-scoped', fn() => (bool)preg_match("/application_for = \? AND status IN \('pending','under_review','approved'\)/", $sr));

/* ───────── GROUP 3 ───────── */
echo "\n--- Group 3: Application-type-scoped custom fields ---\n";
$fields = [
    ['id'=>1,'field_label'=>'Employee Test','field_type'=>'text','is_required'=>1,'application_for'=>'employee','status'=>'active'],
    ['id'=>2,'field_label'=>'Faculty Test','field_type'=>'text','is_required'=>1,'application_for'=>'faculty','status'=>'active'],
    ['id'=>3,'field_label'=>'Intern Test','field_type'=>'text','is_required'=>1,'application_for'=>'intern','status'=>'active'],
    ['id'=>4,'field_label'=>'Faculty Pick','field_type'=>'dropdown','field_options'=>'A,B','is_required'=>0,'application_for'=>'faculty','status'=>'active'],
    ['id'=>5,'field_label'=>'Legacy','field_type'=>'text','is_required'=>0,'status'=>'active'], // no application_for => employee
];
t('Employee selected: only employee fields validated/stored; required of other types ignored', function() use ($fields) {
    $r = staff_validate_custom_fields($fields, 'employee', ['cf_1'=>'x']);
    return $r['errors'] === [] && array_keys($r['data']) === [1];
});
t('Faculty selected: required faculty field enforced', function() use ($fields) {
    $r = staff_validate_custom_fields($fields, 'faculty', []);
    return count($r['errors']) === 1 && has($r['errors'][0], 'Faculty Test');
});
t('Faculty selected: stores faculty value only', function() use ($fields) {
    $r = staff_validate_custom_fields($fields, 'faculty', ['cf_2'=>'f']);
    return $r['errors'] === [] && $r['data'] === [2 => 'f'];
});
t('Intern selected: stores intern value only', function() use ($fields) {
    $r = staff_validate_custom_fields($fields, 'intern', ['cf_3'=>'i']);
    return $r['errors'] === [] && $r['data'] === [3 => 'i'];
});
t('POST injection: faculty submission with cf_1 / cf_3 (other roles) is dropped', function() use ($fields) {
    $r = staff_validate_custom_fields($fields, 'faculty', ['cf_2'=>'f','cf_1'=>'evil','cf_3'=>'evil','cf_5'=>'evil']);
    return $r['data'] === [2 => 'f'];
});
t('Dropdown validation intact (invalid option rejected)', function() use ($fields) {
    $r = staff_validate_custom_fields($fields, 'faculty', ['cf_2'=>'f','cf_4'=>'ZZZ']);
    return count($r['errors']) === 1 && has($r['errors'][0], 'invalid selection');
});
t('Dropdown validation accepts valid option', function() use ($fields) {
    $r = staff_validate_custom_fields($fields, 'faculty', ['cf_2'=>'f','cf_4'=>'B']);
    return $r['errors'] === [] && $r['data'][4] === 'B';
});
t('Legacy field without application_for is treated as employee', function() use ($fields) {
    $r = staff_validate_custom_fields($fields, 'employee', ['cf_1'=>'x','cf_5'=>'legacy']);
    return $r['data'] === [1 => 'x', 5 => 'legacy'];
});
t('Unknown/empty application type validates nothing', function() use ($fields) {
    return staff_validate_custom_fields($fields, '', ['cf_1'=>'x'])['data'] === [];
});
t('Approval filter drops values from other application types', function() {
    $p = new PDO('sqlite::memory:');
    $p->exec("CREATE TABLE employee_custom_fields (id INTEGER PRIMARY KEY, field_label TEXT, application_for TEXT, status TEXT)");
    $p->exec("INSERT INTO employee_custom_fields VALUES (1,'E','employee','active'),(2,'F','faculty','active'),(3,'I','intern','active')");
    $out = staff_filter_custom_values_for_type($p, json_encode([1=>'a',2=>'b',3=>'c']), 'faculty');
    return $out === [2 => 'b'];
});
t('Faculty custom values retrievable (faculties.php) and typed', function() {
    $p = new PDO('sqlite::memory:');
    $p->exec("CREATE TABLE employee_custom_fields (id INTEGER PRIMARY KEY, field_label TEXT, application_for TEXT, status TEXT, sort_order INT DEFAULT 0)");
    $p->exec("CREATE TABLE employee_custom_values (employee_id INT, field_id INT, field_value TEXT)");
    $p->exec("INSERT INTO employee_custom_fields (id,field_label,application_for,status) VALUES (1,'E','employee','active'),(2,'F','faculty','active')");
    $p->exec("INSERT INTO employee_custom_values VALUES (9,1,'ev'),(9,2,'fv')");
    $rows = staff_get_custom_values($p, 9, 'faculty');
    return count($rows) === 1 && $rows[0]['field_value'] === 'fv';
});
t('staff_save_custom_values ignores other-type field ids', function() {
    $p = new PDO('sqlite::memory:');
    $p->exec("CREATE TABLE employee_custom_fields (id INTEGER PRIMARY KEY, field_label TEXT, application_for TEXT, status TEXT, sort_order INT DEFAULT 0)");
    $p->exec("CREATE TABLE employee_custom_values (employee_id INT, field_id INT, field_value TEXT)");
    $p->exec("INSERT INTO employee_custom_fields (id,field_label,application_for,status) VALUES (1,'E','employee','active'),(2,'F','faculty','active')");
    return staff_save_custom_values($p, 5, 'faculty', [1=>'bad', 2=>'good']) === 1;
});
t('Type change safety: field with stored values reports has_data', function() {
    $p = new PDO('sqlite::memory:');
    $p->exec("CREATE TABLE employee_custom_values (employee_id INT, field_id INT, field_value TEXT)");
    $p->exec("CREATE TABLE staff_registration_requests (id INTEGER PRIMARY KEY, custom_field_values TEXT)");
    $p->exec("INSERT INTO employee_custom_values VALUES (1,7,'x')");
    $p->exec("INSERT INTO staff_registration_requests (custom_field_values) VALUES ('{\"8\":\"y\"}')");
    return staff_custom_field_has_data($p, 7) && staff_custom_field_has_data($p, 8) && !staff_custom_field_has_data($p, 9);
});
t('Add field requires valid Application Type (server-side) and keeps key validation', fn() =>
    has($em, "staff_normalize_type(\$_POST['cf_application_for']") && has($em, 'Please select a valid Application Type') && has($em, 'Field key must be lowercase'));
t('Update field blocks type change when values exist', fn() => has($em, 'Application Type cannot be changed'));
t('EM modal has Application Type select with Employee/Faculty/Intern', fn() => has($em, 'name="cf_application_for"') && has($em, '<option value="faculty">Faculty</option>') && has($em, '<th>Application Type</th>'));
t('Public form: fields carry data-application-for; card hidden until a type is chosen', fn() => has($sr, 'data-application-for') && has($sr, 'id="customFieldsCard"') && has($sr, 'applyType'));
t('Public form JS removes/restores required on hidden/shown fields', fn() => has($sr, 'data-was-required'));
t('staff-registration uses scoped server-side validator', fn() => has($sr, 'staff_validate_custom_fields('));

/* ───────── GROUP 4 ───────── */
echo "\n--- Group 4: Faculty isolation ---\n";
$g = "application_for IN ('employee','intern')";
t('employee-management: approved directory + counts scoped to employee/intern', fn() => has($em, "\$emp_where = [staff_generic_type_sql('e')]") && substr_count($em, 'staff_generic_type_sql()') >= 4);
t('employee-management: directory type filter no longer offers Faculty', fn() => !has($em, "\$type_filter === 'faculty'"));
t('employee-management: AJAX details / reveal / copy / status / profile reject Faculty', fn() => substr_count($em, 'staff_is_generic_type(') >= 6 && has($em, 'managed in the Faculties module'));
t('employee-management: Faculty approval still available (faculty approval modal + approve_application)', fn() => has($em, 'facultyApprovalModal') && has($em, "action === 'approve_application'"));
$am = src('admin-management.php');
t('admin-management: staff dropdown (linked/unlinked) scoped', fn() => substr_count($am, $g) >= 6);
t('admin-management: unlinked staff list scoped', fn() => (bool)preg_match("/WHERE admin_id IS NULL\s+AND application_for IN \('employee','intern'\)/", $am));
t('admin-management: link/relink lookups cannot select Faculty', fn() => has($am, "FROM employees WHERE id = ? AND $g"));
t('Faculty admin type value preserved (no destructive removal)', fn() => has($am, 'faculty') || has($am, 'Faculty'));
t('mentor-reports join scoped', fn() => has(src('mentor-reports.php'), "e.admin_id AND e.$g"));
t('auth.php staff phone lookup scoped', fn() => has(src('includes/auth.php'), "$g LIMIT 1"));
$fac = src('faculties.php');
t('faculties.php still reads Faculty rows (application_for = faculty)', fn() => has($fac, "application_for = 'faculty'"));
t('faculties.php surfaces Faculty custom values', fn() => has($fac, "staff_get_custom_values(") && has($fac, "'faculty'"));
t('faculties.php was NOT converted to generic predicate', fn() => !has($fac, "application_for IN ('employee','intern')"));
$ses = src('sessions.php');
t('sessions.php still keyed by faculty_id / faculties table', fn() => has($ses, 'faculty_id') && has($ses, 'faculties'));
foreach (['task-tracker.php','ld-work-report.php','cards.php','campaign-forms.php','student-mentoring.php','dashboard.php'] as $mod) {
    t("$mod: no un-scoped employees query", function() use ($mod, $g) {
        if (!is_file(__DIR__ . '/' . $mod)) return true;
        $s = src($mod);
        if (!preg_match_all('/(FROM|JOIN)\s+`?employees\b[^;]*/i', $s, $m)) return true;
        foreach ($m[0] as $q) if (!has($q, $g)) return false;
        return true;
    });
}

/* ───────── GROUP 5 ───────── */
echo "\n--- Group 5: Home redirects ---\n";
$auth = src('includes/auth.php');
t('Admin logout (?logout=1) destroys session and redirects to login.php', fn() => has($auth, "\$_GET['logout']") && has($auth, 'session_destroy') && (bool)preg_match("/logout.*?Location: login\.php/s", $auth));
foreach (['staff-registration-success.php','installmentpayment.php'] as $pg) {
    t("$pg Home -> https://pepplearning.com", fn() => has(src($pg), 'https://pepplearning.com'));
}
foreach (['register.php','studyplan.php','installmentpayment.php','staff-registration.php','staff-registration-success.php','alumni-portal.php'] as $pg) {
    t("$pg has no link/redirect to admin login.php", function() use ($pg) {
        if (!is_file(__DIR__ . '/' . $pg)) return true;
        $s = src($pg);
        return !preg_match('/href\s*=\s*["\'](\.\.?\/)?login\.php["\']/i', $s) && !preg_match('/Location:\s*(\.\.?\/)?login\.php/i', $s) && !preg_match('/window\.location(\.href)?\s*=\s*["\'](\.\.?\/)?login\.php/i', $s);
    });
}

echo "\n======================================================================\n";
echo " STAFF ARCHITECTURE AUDIT: $passed PASSED, $failed FAILED\n";
echo "======================================================================\n";
exit($failed ? 1 : 0);
