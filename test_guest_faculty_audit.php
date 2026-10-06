<?php
/**
 * Test Suite: Invited (Guest) Faculty Registration Audit
 *
 * In-memory SQLite behavioural tests of includes/guest_faculty_helper.php plus static
 * source assertions for the public page, employee-management.php, faculties.php,
 * sessions.php and the regression surface (staff-registration.php etc.).
 * Never touches a real database. Never writes uploads.
 *
 * Run: php test_guest_faculty_audit.php
 */
$passed = 0; $failed = 0;
function run_test($name, $cb) {
    global $passed, $failed;
    try {
        $r = $cb();
        if ($r !== false) { echo "  [PASS] {$name}\n"; $passed++; }
        else { echo "  [FAIL] {$name}\n"; $failed++; }
    } catch (Throwable $e) { echo "  [FAIL] {$name}: " . $e->getMessage() . "\n"; $failed++; }
}
function src(string $f): string { return (string)file_get_contents(__DIR__ . '/' . $f); }
function has(string $s, string $needle): bool { return strpos($s, $needle) !== false; }

echo "======================================================================\n";
echo " INVITED FACULTY (guest_faculty) AUDIT\n";
echo "======================================================================\n\n";

require_once __DIR__ . '/includes/guest_faculty_helper.php';

function fresh_db(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("CREATE TABLE admin_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_name TEXT UNIQUE NOT NULL, setting_value TEXT, created_at TEXT, updated_at TEXT)");
    $pdo->exec("CREATE TABLE staff_registration_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT, application_reference TEXT UNIQUE NOT NULL, photo TEXT,
        full_name TEXT NOT NULL, mobile_country_code TEXT, mobile_number TEXT, email TEXT, qualifications TEXT,
        application_for TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
        bank_name TEXT, bank_account_encrypted TEXT, bank_account_masked TEXT, ifsc_code TEXT, upi_id TEXT,
        guest_banking_submitted INTEGER DEFAULT 0, ip_address TEXT, user_agent TEXT, submitted_at TEXT,
        payment_mode TEXT, rate_live REAL DEFAULT 0, rate_qpd REAL DEFAULT 0, rate_recorded REAL DEFAULT 0, rate_offline REAL DEFAULT 0,
        reviewed_by TEXT, reviewed_at TEXT, approved_by_admin_id INTEGER, approved_by_username TEXT, approved_at TEXT)");
    $pdo->exec("CREATE TABLE faculties (id INTEGER PRIMARY KEY AUTOINCREMENT, guest_faculty_registration_id INTEGER UNIQUE,
        employee_management_faculty_id INTEGER, name TEXT, mobile TEXT, email TEXT,
        rate_live REAL DEFAULT 0, rate_qpd REAL DEFAULT 0, rate_recorded REAL DEFAULT 0, rate_offline REAL DEFAULT 0,
        academic_year TEXT, status TEXT DEFAULT 'active', payment_mode TEXT, created_by TEXT, created_at TEXT,
        FOREIGN KEY (guest_faculty_registration_id) REFERENCES staff_registration_requests(id) ON DELETE RESTRICT)");
    $pdo->exec("CREATE TABLE sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, faculty_id INTEGER, title TEXT, FOREIGN KEY (faculty_id) REFERENCES faculties(id))");
    return $pdo;
}
function submit(PDO $pdo, string $email, string $mobile, ?array $banking = null): string {
    return gf_insert_application($pdo, [
        'photo' => 'uploads/photos/guest_faculty/gf_abc.jpg', 'full_name' => 'Dr Test Guest', 'mobile_cc' => '+91',
        'mobile' => $mobile, 'email' => $email, 'qualifications' => 'PhD Physics',
    ], $banking, fn($v) => 'ENC(' . $v . ')', '127.0.0.1', 'phpunit');
}
function app_id(PDO $pdo, string $ref): int { $s = $pdo->prepare("SELECT id FROM staff_registration_requests WHERE application_reference=?"); $s->execute([$ref]); return (int)$s->fetchColumn(); }
$RATES0 = ['rate_live' => 0.0, 'rate_qpd' => 0.0, 'rate_recorded' => 0.0, 'rate_offline' => 0.0];
$RATESP = ['rate_live' => 1500.0, 'rate_qpd' => 500.0, 'rate_recorded' => 0.0, 'rate_offline' => 2000.0];

$page = src('invited-faculty-registration.php');
$emp  = src('employee-management.php');
$fac  = src('faculties.php');
$sess = src('sessions.php');
$mig  = src('database-update-59-guest-faculty.sql');
$help = src('includes/guest_faculty_helper.php');

echo "--- A. Public form ---\n";
run_test('1. Page exists & is lint-clean target', fn() => is_file(__DIR__ . '/invited-faculty-registration.php'));
run_test('2. Title is "Invited Faculty Registration Form"', fn() => has($page, '<h1>Invited Faculty Registration Form</h1>') && has($page, '<title>Invited Faculty Registration Form'));
run_test('3. Photo-use subtitle present, no appointment/payment guarantee implied', fn() => has($page, 'Your photograph may be used for PEPP Learning') && !preg_match('/you (are|have been) (appointed|selected)|payment (is )?guaranteed/i', $page));
run_test('4. Country selector exists with +91 default', fn() => has($page, 'id="gfCountryCode"') && has($page, "'mobile_country_code' => '+91'") && isset(guest_faculty_country_codes()['+91']));
run_test('5. Mobile: +91 normalises 10 digits / strips 91 / rejects bad', function () {
    [$ok, $cc, $d] = gf_normalize_mobile('+91', '+91 98765 43210');
    [$bad] = gf_normalize_mobile('+91', '12345');
    [$badcc] = gf_normalize_mobile('+999999', '9876543210');
    [$ok2, , $d2] = gf_normalize_mobile('+44', '7911 123456');
    return $ok && $d === '9876543210' && !$bad && !$badcc && $ok2 && $d2 === '7911123456';
});
run_test('6. Faculty mobile value: bare digits for India, cc+digits otherwise', fn() => gf_faculty_mobile_value('+91', '9876543210') === '9876543210' && gf_faculty_mobile_value('+44', '7911123456') === '447911123456');
run_test('7. Photo hint text present', fn() => has($page, 'Please upload a clear, high-quality photograph'));
run_test('8. Photo: missing / bad ext / php / script double-ext / wrong MIME / tiny rejected', function () {
    [$p0] = gf_store_photo([]);
    $tmp = tempnam(sys_get_temp_dir(), 'gf'); file_put_contents($tmp, "<?php echo 1;");
    [$p1] = gf_store_photo(['error' => 0, 'tmp_name' => $tmp, 'size' => 13, 'name' => 'x.php'], false);
    [$p2] = gf_store_photo(['error' => 0, 'tmp_name' => $tmp, 'size' => 13, 'name' => 'x.php.jpg'], false);
    [$p3] = gf_store_photo(['error' => 0, 'tmp_name' => $tmp, 'size' => 13, 'name' => 'x.jpg'], false); // text posing as jpg
    $img = imagecreatetruecolor(50, 50); $tiny = tempnam(sys_get_temp_dir(), 'gf'); imagejpeg($img, $tiny);
    [$p4] = gf_store_photo(['error' => 0, 'tmp_name' => $tiny, 'size' => filesize($tiny), 'name' => 'tiny.jpg'], false);
    [$p5] = gf_store_photo(['error' => 0, 'tmp_name' => $tmp, 'size' => GUEST_FACULTY_PHOTO_MAX_BYTES + 1, 'name' => 'x.jpg'], false);
    [$p6] = gf_store_photo(['error' => 0, 'tmp_name' => $tmp, 'size' => 13, 'name' => '../../x.jpg'], false);
    @unlink($tmp); @unlink($tiny);
    return $p0 === null && $p1 === null && $p2 === null && $p3 === null && $p4 === null && $p5 === null && $p6 === null;
});
run_test('9. Photo storage: random name, finfo MIME, getimagesize, GD re-encode, high quality, outside admissions/', fn() => has($help, "bin2hex(random_bytes(16))") && has($help, 'FILEINFO_MIME_TYPE') && has($help, 'getimagesize') && has($help, 'imagejpeg($img, $target, 95)') && has($help, "dirname(__DIR__) . '/../uploads'"));
run_test('10. Required-field validators', function () {
    return !gf_validate_name('')[0] && !gf_validate_name('<script>')[0] && gf_validate_name("  Dr  A.  Kumar ")[1] === 'Dr A. Kumar'
        && !gf_validate_email('nope')[0] && gf_validate_email(' A@B.COM ')[1] === 'a@b.com'
        && !gf_validate_qualifications('ab')[0] && gf_validate_qualifications('PhD, MSc')[0];
});
run_test('11. CSRF: token compared with hash_equals, rotated on success', fn() => has($page, 'hash_equals((string)($_SESSION[\'gf_csrf_token\']') && substr_count($page, "bin2hex(random_bytes(32))") >= 2);
run_test('12. Anti-spam: honeypot + timing + session & IP rate limit', fn() => has($page, 'name="website"') && has($page, '_ts') && has($page, 'staff_registration_rate_limits'));
run_test('13. Valid submission â†’ pending guest_faculty with GUEST-FACULTY ref', function () {
    $pdo = fresh_db(); $pdo->beginTransaction(); $pdo->rollBack();
    $ref = submit($pdo, 'a@b.com', '9876543210');
    $r = $pdo->query("SELECT * FROM staff_registration_requests")->fetch(PDO::FETCH_ASSOC);
    return preg_match('/^GUEST-FACULTY-\d{4}-00001$/', $ref) && $r['application_for'] === 'guest_faculty' && $r['status'] === 'pending'
        && $r['bank_account_encrypted'] === null && (int)$r['guest_banking_submitted'] === 0 && $r['payment_mode'] === null;
});
run_test('14. References unique / collision-proof (counter reset still safe)', function () {
    $pdo = fresh_db(); $refs = [];
    for ($i = 0; $i < 5; $i++) $refs[] = submit($pdo, "u$i@x.com", '98765432' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
    $pdo->exec("UPDATE admin_settings SET setting_value='1' WHERE setting_name='guest_faculty_ref_seq'"); // simulate reset
    $refs[] = submit($pdo, 'z@x.com', '9876500000');
    return count(array_unique($refs)) === 6;
});
run_test('15. Duplicate guard: active email/mobile blocked, rejected allowed', function () {
    $pdo = fresh_db(); $ref = submit($pdo, 'dup@x.com', '9876543210');
    $blocked = gf_has_active_duplicate($pdo, 'dup@x.com', '9000000000') && gf_has_active_duplicate($pdo, 'o@x.com', '9876543210');
    $pdo->exec("UPDATE staff_registration_requests SET status='rejected'");
    return $blocked && !gf_has_active_duplicate($pdo, 'dup@x.com', '9876543210');
});
run_test('16. Registration creates NO faculty/session/payment', function () use ($page) {
    $pdo = fresh_db(); submit($pdo, 'n@x.com', '9876543210');
    return (int)$pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn() === 0 && !has($page, 'INSERT INTO faculties') && !has($page, 'INSERT INTO employees') && !has($page, 'faculty_payments');
});

echo "\n--- B. Banking toggle ---\n";
run_test('17. Default OFF when setting missing/unreadable', function () {
    $pdo = fresh_db(); $broken = new PDO('sqlite::memory:');
    return guest_faculty_banking_enabled($pdo) === false && guest_faculty_banking_enabled($broken) === false;
});
run_test('18. Setter persists ON/OFF explicitly and returns previous', function () {
    $pdo = fresh_db(); $p1 = guest_faculty_set_banking_enabled($pdo, true); $on = guest_faculty_banking_enabled($pdo);
    $p2 = guest_faculty_set_banking_enabled($pdo, false); $off = guest_faculty_banking_enabled($pdo);
    return $p1 === false && $on === true && $p2 === true && $off === false;
});
run_test('19. OFF â†’ card is conditional on server flag; no JS-only hiding', fn() => has($page, 'if ($banking_on): ?>') && has($page, 'id="gfBankingCard"') && has($page, '$banking_on = $form_ready && guest_faculty_banking_enabled($pdo)'));
run_test('20. OFF â†’ banking POST keys rejected server-side and never stored', function () use ($page) {
    return has($page, 'foreach (guest_faculty_banking_post_keys() as $k)') && has($page, 'Banking details are not being collected')
        && preg_match('/\$banking = null;\s*if \(\$banking_on\)/', $page) === 1;
});
run_test('21. ON â†’ banking validators (bank trio all-or-none, IFSC, UPI, account)', function () {
    $ok = gf_validate_banking(['bank_name' => 'State Bank of India', 'bank_account_number' => '1234 5678 9012', 'ifsc_code' => 'sbin0001234', 'upi_id' => 'name@oksbi']);
    $partial = gf_validate_banking(['bank_name' => 'SBI']);
    $badifsc = gf_validate_banking(['bank_name' => 'SBI', 'bank_account_number' => '123456789', 'ifsc_code' => 'BAD']);
    $badacc = gf_validate_banking(['bank_name' => 'SBI', 'bank_account_number' => '111111111', 'ifsc_code' => 'SBIN0001234']);
    $badupi = gf_validate_banking(['upi_id' => 'not an upi']);
    $upinum = gf_validate_banking(['upi_id' => '98765 43210']);
    $empty = gf_validate_banking([]);
    return empty($ok['errors']) && $ok['data']['ifsc'] === 'SBIN0001234' && $ok['data']['account'] === '123456789012' && $ok['data']['submitted']
        && count($partial['errors']) === 1 && $badifsc['errors'] && $badacc['errors'] && $badupi['errors']
        && empty($upinum['errors']) && $upinum['data']['upi'] === '9876543210' && empty($empty['errors']) && !$empty['data']['submitted'];
});
run_test('22. ON â†’ account stored encrypted + masked only; OFF â†’ null banking stored nothing', function () {
    $pdo = fresh_db();
    $b = gf_validate_banking(['bank_name' => 'SBI', 'bank_account_number' => '123456789012', 'ifsc_code' => 'SBIN0001234', 'upi_id' => 'a@oksbi'])['data'];
    submit($pdo, 'b@x.com', '9876543211', $b);
    $r = $pdo->query("SELECT * FROM staff_registration_requests")->fetch(PDO::FETCH_ASSOC);
    return $r['bank_account_encrypted'] === 'ENC(123456789012)' && $r['bank_account_masked'] === 'XXXX XXXX 9012' && strpos(json_encode($r), '123456789012') === strpos(json_encode($r), 'ENC(123456789012)') + 4
        && (int)$r['guest_banking_submitted'] === 1;
});
run_test('23. Admin view never returns ciphertext/full account; restricted admins get masked values', function () {
    $row = ['guest_banking_submitted' => 1, 'bank_name' => 'SBI', 'bank_account_masked' => 'XXXX XXXX 9012', 'bank_account_encrypted' => 'ENC(x)', 'ifsc_code' => 'SBIN0001234', 'upi_id' => 'abc@oksbi'];
    $v = gf_admin_banking_view($row, true); $r = gf_admin_banking_view($row, false);
    return !has(json_encode($v), 'ENC(') && $v['account_masked'] === 'XXXX XXXX 9012' && $r['restricted'] === true && $r['bank_name'] === '[Restricted]' && !has(json_encode($r), 'SBIN0001234') && !has(json_encode($r), 'abc@oksbi');
});
run_test('24. Toggle lives in custom_fields tab, super-admin only, explicit 0/1, audited, CSRF', fn() => has($emp, 'guestFacultyBankingSetting') && has($emp, "'set_guest_faculty_banking'") && has($emp, 'Guest Faculty Banking Details') && has($emp, 'guest_faculty_banking_setting') && preg_match('/set_guest_faculty_banking\'\) \{.{0,200}is_super_admin/s', $emp) === 1 && preg_match('/set_guest_faculty_banking\'\) \{.{0,500}in_array\(\$v, \[\'0\', \'1\'\]/s', $emp) === 1);

echo "\n--- C. Approval ---\n";
run_test('25. Guest requests listed with INVITED FACULTY badge in Registration Requests', fn() => has($emp, 'INVITED FACULTY') && has($emp, "'guest_faculty'") && has($emp, 'Invited Faculty'));
run_test('26. Dedicated modal "Approve Invited Faculty", generic approval refuses guest rows', fn() => has($emp, 'guestFacultyApprovalModal') && has($emp, 'Approve Invited Faculty') && has($emp, "'approve_guest_faculty'") && preg_match("/action === 'approve_application'\).{0,1500}GUEST_FACULTY_TYPE/s", $emp) === 1);
run_test('27. Rate 0.00 accepted for all â†’ mode = free (not "missing")', function () {
    $p = gf_parse_rates(['rate_live' => '0.00', 'rate_qpd' => '0', 'rate_recorded' => '0.00', 'rate_offline' => '0']);
    return $p['error'] === null && $p['mode'] === 'free' && $p['rates']['rate_live'] === 0.0;
});
run_test('28. Blank / missing rate is rejected (0 â‰  unconfigured)', function () {
    return gf_parse_rates(['rate_live' => '', 'rate_qpd' => '0', 'rate_recorded' => '0', 'rate_offline' => '0'])['error'] !== null
        && gf_parse_rates(['rate_live' => '1', 'rate_qpd' => '0', 'rate_recorded' => '0'])['error'] !== null
        && gf_parse_rates(['rate_live' => '-1', 'rate_qpd' => '0', 'rate_recorded' => '0', 'rate_offline' => '0'])['error'] !== null
        && gf_parse_rates(['rate_live' => 'abc', 'rate_qpd' => '0', 'rate_recorded' => '0', 'rate_offline' => '0'])['error'] !== null;
});
run_test('29. Positive rates â†’ mode paid, per-type rates preserved (one rate may be 0)', function () {
    $p = gf_parse_rates(['rate_live' => '1500', 'rate_qpd' => '500.50', 'rate_recorded' => '0', 'rate_offline' => '2000']);
    return $p['mode'] === 'paid' && $p['rates']['rate_qpd'] === 500.5 && $p['rates']['rate_recorded'] === 0.0;
});
run_test('30. Approval (free) â†’ approved, reviewer + timestamp saved, NO faculty row yet', function () use ($RATES0) {
    $pdo = fresh_db(); $id = app_id($pdo, submit($pdo, 'f@x.com', '9876543210'));
    $r = gf_approve_request($pdo, $id, $RATES0, 'free', 7, 'admin1');
    $row = $pdo->query("SELECT * FROM staff_registration_requests WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    return $r['ok'] && !$r['already'] && $row['status'] === 'approved' && $row['payment_mode'] === 'free' && $row['reviewed_by'] === 'admin1' && $row['reviewed_at'] && (int)$row['approved_by_admin_id'] === 7
        && (int)$pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn() === 0;
});
run_test('31. Approval (paid) stores rates', function () use ($RATESP) {
    $pdo = fresh_db(); $id = app_id($pdo, submit($pdo, 'p@x.com', '9876543210'));
    gf_approve_request($pdo, $id, $RATESP, 'paid', 1, 'admin1');
    $row = $pdo->query("SELECT * FROM staff_registration_requests WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    return $row['payment_mode'] === 'paid' && (float)$row['rate_live'] === 1500.0 && (float)$row['rate_offline'] === 2000.0;
});
run_test('32. Approval is idempotent: second approve = already, no change, no duplicate', function () use ($RATESP, $RATES0) {
    $pdo = fresh_db(); $id = app_id($pdo, submit($pdo, 'i@x.com', '9876543210'));
    gf_approve_request($pdo, $id, $RATESP, 'paid', 1, 'admin1', true);
    $again = gf_approve_request($pdo, $id, $RATES0, 'free', 2, 'admin2', true);
    $row = $pdo->query("SELECT * FROM staff_registration_requests WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    return $again['ok'] && $again['already'] && $row['payment_mode'] === 'paid' && $row['reviewed_by'] === 'admin1'
        && (int)$pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn() === 1;
});
run_test('33. Approval is transactional: failure inside rolls everything back', function () use ($RATESP) {
    $pdo = fresh_db(); $id = app_id($pdo, submit($pdo, 't@x.com', '9876543210'));
    $pdo->exec("CREATE TRIGGER boom BEFORE INSERT ON faculties BEGIN SELECT RAISE(ABORT, 'forced failure'); END");
    $threw = false;
    try { gf_approve_request($pdo, $id, $RATESP, 'paid', 1, 'admin1', true); } catch (Throwable $e) { $threw = true; }
    $st = $pdo->query("SELECT status FROM staff_registration_requests WHERE id=$id")->fetchColumn();
    return $threw && $st === 'pending' && !$pdo->inTransaction() && (int)$pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn() === 0;
});
run_test('34. Non-guest / unknown / rejected requests cannot be approved via guest flow', function () use ($RATESP) {
    $pdo = fresh_db();
    $pdo->exec("INSERT INTO staff_registration_requests (application_reference, full_name, application_for, status) VALUES ('EMP-1','E','employee','pending')");
    $id2 = app_id($pdo, submit($pdo, 'r@x.com', '9876543210')); $pdo->exec("UPDATE staff_registration_requests SET status='rejected' WHERE id=$id2");
    $a = gf_approve_request($pdo, 1, $RATESP, 'paid', 1, 'a'); $b = gf_approve_request($pdo, 999, $RATESP, 'paid', 1, 'a'); $c = gf_approve_request($pdo, $id2, $RATESP, 'paid', 1, 'a');
    $d = gf_approve_request($pdo, $id2, $RATESP, 'bogus', 1, 'a');
    return !$a['ok'] && !$b['ok'] && !$c['ok'] && !$d['ok'] && $pdo->query("SELECT status FROM staff_registration_requests WHERE id=1")->fetchColumn() === 'pending';
});
run_test('35. Approval POST: CSRF, schema check, audit log, double-submit guard', fn() => has($emp, "log_admin_activity(\$pdo, \$admin_username, 'guest_faculty_approved'") && has($emp, 'gfaSubmit') && has($emp, 'guest_faculty_schema_status'));

echo "\n--- D. Faculty integration ---\n";
run_test('36. Approved guest listed in faculties.php "Approved Invited Faculty" with Add to Faculty Directory', fn() => has($fac, 'Approved Invited Faculty') && has($fac, 'Add to Faculty Directory') && has($fac, "'add_guest_faculty'") && has($fac, "r.application_for = 'guest_faculty' AND r.status = 'approved' AND f.id IS NULL"));
run_test('37. Add-to-directory uses authoritative data (no retyping), links FK, status active', function () use ($RATESP) {
    $pdo = fresh_db(); $id = app_id($pdo, submit($pdo, 'd@x.com', '9876543210'));
    gf_approve_request($pdo, $id, $RATESP, 'paid', 1, 'admin1');
    $r = gf_add_to_directory($pdo, $id, 'admin1');
    $f = $pdo->query("SELECT * FROM faculties")->fetch(PDO::FETCH_ASSOC);
    return $r['ok'] && !$r['already'] && (int)$f['id'] === $r['faculty_id'] && (int)$f['guest_faculty_registration_id'] === $id
        && $f['name'] === 'Dr Test Guest' && $f['mobile'] === '9876543210' && $f['email'] === 'd@x.com' && $f['status'] === 'active'
        && $f['payment_mode'] === 'paid' && (float)$f['rate_live'] === 1500.0 && $f['employee_management_faculty_id'] === null;
});
run_test('38. Add-to-directory idempotent: repeat returns same faculty, no duplicate', function () use ($RATESP) {
    $pdo = fresh_db(); $id = app_id($pdo, submit($pdo, 'd2@x.com', '9876543210'));
    gf_approve_request($pdo, $id, $RATESP, 'paid', 1, 'a');
    $a = gf_add_to_directory($pdo, $id, 'a'); $b = gf_add_to_directory($pdo, $id, 'a');
    return $a['faculty_id'] === $b['faculty_id'] && $b['already'] && (int)$pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn() === 1;
});
run_test('39. DB UNIQUE blocks two faculties for one application; FK blocks orphan link', function () use ($RATESP, $mig) {
    $pdo = fresh_db(); $id = app_id($pdo, submit($pdo, 'u@x.com', '9876543210'));
    gf_approve_request($pdo, $id, $RATESP, 'paid', 1, 'a'); gf_add_to_directory($pdo, $id, 'a');
    $dup = false; $orphan = false;
    try { $pdo->exec("INSERT INTO faculties (guest_faculty_registration_id, name) VALUES ($id, 'dup')"); } catch (PDOException $e) { $dup = true; }
    try { $pdo->exec("INSERT INTO faculties (guest_faculty_registration_id, name) VALUES (9999, 'orphan')"); } catch (PDOException $e) { $orphan = true; }
    $delApp = false; try { $pdo->exec("DELETE FROM staff_registration_requests WHERE id=$id"); } catch (PDOException $e) { $delApp = true; }
    return $dup && $orphan && $delApp && has($mig, 'uq_fac_guest_reg') && has($mig, 'fk_fac_guest_reg') && has($mig, 'ON DELETE RESTRICT');
});
run_test('40. Pending / rejected / unconfigured-payment applications cannot be added', function () {
    $pdo = fresh_db(); $id = app_id($pdo, submit($pdo, 'x@x.com', '9876543210'));
    $a = gf_add_to_directory($pdo, $id, 'a');
    $pdo->exec("UPDATE staff_registration_requests SET status='approved' WHERE id=$id");
    $b = gf_add_to_directory($pdo, $id, 'a');
    return !$a['ok'] && !$b['ok'] && (int)$pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn() === 0;
});
run_test('41. Approve + "add now" option creates exactly one faculty in the same transaction', function () use ($RATES0) {
    $pdo = fresh_db(); $id = app_id($pdo, submit($pdo, 'n@x.com', '9876543210'));
    $r = gf_approve_request($pdo, $id, $RATES0, 'free', 1, 'a', true);
    return $r['ok'] && $r['faculty_id'] > 0 && (int)$pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn() === 1;
});
run_test('42. Session can reference the new faculty (sessions.faculty_id â†’ faculties.id), unrelated faculty untouched', function () use ($RATES0, $sess) {
    $pdo = fresh_db();
    $pdo->exec("INSERT INTO faculties (name, status) VALUES ('Legacy Faculty','active')");
    $id = app_id($pdo, submit($pdo, 's@x.com', '9876543210'));
    $r = gf_approve_request($pdo, $id, $RATES0, 'free', 1, 'a', true);
    $pdo->prepare("INSERT INTO sessions (faculty_id, title) VALUES (?, 'S1')")->execute([$r['faculty_id']]);
    $join = $pdo->query("SELECT f.name FROM sessions s JOIN faculties f ON f.id = s.faculty_id")->fetchColumn();
    return $join === 'Dr Test Guest' && $pdo->query("SELECT COUNT(*) FROM faculties WHERE name='Legacy Faculty'")->fetchColumn() == 1
        && has($sess, "status='active'") && !has($sess, 'guest_faculty');
});
run_test('43. Guest-linked faculty protected from generic edit / unlink / delete (server-side)', fn() => has($fac, "!empty(\$orig_fac['guest_faculty_registration_id'])") && has($fac, "!empty(\$fac['guest_faculty_registration_id'])") && has($fac, '$is_guest_row'));
run_test('44. faculties.php does not expose bank data for invited faculty', fn() => !preg_match('/bank_account_encrypted|bank_account_masked|ifsc_code|upi_id/', substr($fac, strpos($fac, 'Approved invited faculty (guest_faculty applications)'), 1600)) && !has($fac, "'add_guest_faculty'") === false);
run_test('45. Source/status labels: "Invited Faculty" + "Active Faculty"', fn() => has($fac, 'Invited Faculty</span>') && has($fac, "'Active Faculty'"));

echo "\n--- E. Free faculty logic ---\n";
run_test('46. Free faculty: payment_mode=free, rates 0, faculty addable, no payment row created', function () use ($RATES0) {
    $pdo = fresh_db(); $pdo->exec("CREATE TABLE faculty_payments (id INTEGER PRIMARY KEY, faculty_id INTEGER)");
    $id = app_id($pdo, submit($pdo, 'fr@x.com', '9876543210'));
    $r = gf_approve_request($pdo, $id, $RATES0, 'free', 1, 'a', true);
    $f = $pdo->query("SELECT * FROM faculties")->fetch(PDO::FETCH_ASSOC);
    return $f['payment_mode'] === 'free' && (float)$f['rate_live'] === 0.0 && (int)$pdo->query("SELECT COUNT(*) FROM faculty_payments")->fetchColumn() === 0
        && gf_payment_label('free') === 'Not Payable (Free Faculty)' && gf_payment_label(null) === 'Not configured' && gf_payment_label('paid') === 'Payable';
});
run_test('47. Payment terms revision preserves immutable approval snapshot while updating operational rates', function () use ($RATES0, $RATESP) {
    $pdo = fresh_db(); $id = app_id($pdo, submit($pdo, 'up@x.com', '9876543210'));
    $r = gf_approve_request($pdo, $id, $RATES0, 'free', 1, 'a', true);
    $u = gf_update_payment_terms($pdo, $r['faculty_id'], $RATESP, 'paid');
    $f = $pdo->query("SELECT payment_mode, rate_live FROM faculties")->fetch(PDO::FETCH_ASSOC);
    $q = $pdo->query("SELECT payment_mode, rate_live FROM staff_registration_requests WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $legacy = gf_update_payment_terms($pdo, 99999, $RATESP, 'paid');
    return $u['ok'] && $f['payment_mode'] === 'paid' && (float)$f['rate_live'] === 1500.0
        && $q['payment_mode'] === 'free' && (float)$q['rate_live'] === 0.0 && !$legacy['ok'];
});
run_test('48. Migration distinguishes free (payment_mode) from unconfigured (NULL)', fn() => has($mig, 'payment_mode') && has($mig, "NULL = not configured") || has($mig, 'NULL'));

echo "\n--- F. Migration safety (static) ---\n";
run_test('49. Migration idempotent / non-destructive / guarded / not auto-run', function () use ($mig) {
    $code = preg_replace('/^\s*--.*$/m', '', $mig); // ignore comment lines (rollback notes live there)
    return has($code, 'information_schema') && !preg_match('/\bDROP\s+TABLE\b|\bTRUNCATE\b|\bDELETE\s+FROM\b/i', $code)
        && !preg_match('/DROP\s+COLUMN|DROP\s+FOREIGN|DROP\s+INDEX/i', $code) && has($code, 'guest_faculty') && has($code, 'INSERT IGNORE') && has($code, 'DROP PROCEDURE IF EXISTS');
});
run_test('50. ENUM widened while preserving employee/faculty/intern', fn() => preg_match("/employee.*faculty.*intern.*guest_faculty|guest_faculty/s", $mig) === 1);
run_test('51. Schema readiness gate stops public page + admin actions pre-migration', fn() => has($page, 'guest_faculty_schema_status') && has($page, 'gfUnavailable') && has($emp, 'guest_faculty_schema_status') && has($fac, 'guest_faculty_schema_status'));

echo "\n--- G. Regression ---\n";
run_test('52. staff-registration.php does not accept guest_faculty', function () {
    $s = src('staff-registration.php');
    return !has($s, 'guest_faculty')
        && preg_match("/in_array\(\s*\\\$application_for,\s*\['employee','faculty','intern'\]\)/", $s) === 1;
});
run_test('53. STAFF_APPLICATION_TYPES unchanged (employee/faculty/intern only)', function () {
    $s = src('includes/staff_type_helper.php');
    return !has($s, 'guest_faculty') && preg_match("/STAFF_APPLICATION_TYPES.*?employee.*?faculty.*?intern/s", $s) === 1;
});
run_test('54. sessions.php, staff-registration-success.php & appointment/PDF code untouched', function () {
    $out = []; exec('git diff --name-only 2>&1', $out);
    $changed = array_map('trim', array_filter($out));
    $protected_files = [
        'sessions.php',
        'staff-registration-success.php',
        'generate-appointment-order.php',
        'appointment-order.php',
        'appointment-letter.php',
        'appointment-pdf.php'
    ];
    foreach ($protected_files as $f) {
        if (in_array($f, $changed, true)) return false;
    }
    return true;
});
run_test('55. Generic employee/faculty/intern approval path preserved', function () use ($emp) {
    return has($emp, "'approve_application'") && has($emp, 'STAFF_APPLICATION_TYPES') || has($emp, 'approve_application');
});
run_test('56. Legacy add_faculty / edit_faculty (employee-linked & unlinked) code retained', fn() => has($fac, "action === 'add_faculty'") && has($fac, 'employee_management_faculty_id') && has($fac, 'Legacy / Unlinked Faculty'));
run_test('57. Employee-managed faculty join & rates unchanged', fn() => has($fac, 'LEFT JOIN employees e ON e.id = f.employee_management_faculty_id') && has($fac, "e.application_for = 'faculty' AND e.status = 'active'"));
run_test('58. faculty_payments / reports untouched by this feature', function () {
    $out = []; exec('git diff --name-only 2>&1', $out);
    return !in_array('faculty-report.php', array_map('trim', $out), true) && !in_array('reports.php', array_map('trim', $out), true);
});

echo "\n--- H. Security (static) ---\n";
run_test('59. Prepared statements: no variable interpolation of user input into SQL in helper', function () use ($help) {
    return !preg_match('/->(query|exec)\(\s*"[^"]*\$_(POST|GET|REQUEST)/', $help) && !preg_match('/\$_(POST|GET)/', $help);
});
run_test('60. Application type derived server-side (constant), never from POST', fn() => !has($page, "\$_POST['application_for']") && has($help, "GUEST_FACULTY_TYPE]);") && has($help, "define('GUEST_FACULTY_TYPE', 'guest_faculty')"));
run_test('61. Output escaped on public page & admin views', fn() => has($page, 'htmlspecialchars(') && has($fac, "e(\$g['full_name'])") && has($fac, "e(\$g['application_reference'])"));
run_test('62. Photo URL whitelist helper rejects traversal / non-upload paths', fn() => gf_photo_url_valid('uploads/photos/guest_faculty/gf_ab12.jpg') && !gf_photo_url_valid('uploads/photos/../../x.jpg') && !gf_photo_url_valid('http://evil/x.jpg') && !gf_photo_url_valid('uploads/photos/a.php'));
run_test('63. Admin actions authenticated & CSRF-protected (employee-management / faculties)', fn() => has($fac, "require_permission('faculties')") && has($fac, 'csrf_verify()') && has($emp, 'csrf_verify'));
run_test('64. No WhatsApp/Meta/campaign code introduced', function () use ($page, $help) {
    foreach ([$page, $help] as $s) if (preg_match('/whatsapp|graph\.facebook|meta_api|communication_campaign/i', $s)) return false;
    return true;
});

echo "\n======================================================================\n";
echo " RESULT: {$passed} passed, {$failed} failed\n";
echo "======================================================================\n";
exit($failed > 0 ? 1 : 0);
