<?php
/**
 * PEPP Learning ERP — Invited / Guest Faculty helper.
 *
 * Central, side-effect-contained logic for the 'guest_faculty' application type:
 *  - admin-controlled "Guest Faculty Banking Details" setting (admin_settings)
 *  - schema readiness check (the migration is NEVER auto-applied)
 *  - input validation / normalisation (name, mobile, email, qualifications, bank, UPI)
 *  - secure photo upload (finfo MIME + extension + getimagesize + GD re-encode)
 *  - collision-proof application reference (GUEST-FACULTY-<FY>-<00001>)
 *  - transactional, idempotent approval + payment terms
 *  - transactional, idempotent "Add to Faculty Directory" (faculties.php)
 *
 * Employee / Faculty / Intern flows do NOT use this file. 'guest_faculty' is
 * deliberately NOT added to STAFF_APPLICATION_TYPES (staff_type_helper.php) so the
 * generic employee/custom-field machinery can never treat it as employee-like.
 */

if (!defined('GUEST_FACULTY_TYPE')) define('GUEST_FACULTY_TYPE', 'guest_faculty');
if (!defined('GUEST_FACULTY_BANKING_SETTING')) define('GUEST_FACULTY_BANKING_SETTING', 'guest_faculty_banking_enabled');
if (!defined('GUEST_FACULTY_REF_SEQ_SETTING')) define('GUEST_FACULTY_REF_SEQ_SETTING', 'guest_faculty_ref_seq');
if (!defined('GUEST_FACULTY_PHOTO_MAX_BYTES')) define('GUEST_FACULTY_PHOTO_MAX_BYTES', 8 * 1024 * 1024);
if (!defined('GUEST_FACULTY_RATE_FIELDS')) define('GUEST_FACULTY_RATE_FIELDS', ['rate_live', 'rate_qpd', 'rate_recorded', 'rate_offline']);

/** Row-lock suffix (SQLite used by the audit suite has no FOR UPDATE). */
function gf_lock(PDO $pdo): string {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}
function gf_now(): string { return date('Y-m-d H:i:s'); }

/* ───────────────────────── Settings ───────────────────────── */

/** Server-side truth for the banking toggle. Missing/unreadable row => OFF. */
function guest_faculty_banking_enabled(PDO $pdo): bool {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = ? LIMIT 1");
        $st->execute([GUEST_FACULTY_BANKING_SETTING]);
        return (string)$st->fetchColumn() === '1';
    } catch (Throwable $e) {
        return false;
    }
}

/** Explicit set (never a blind flip). Returns the previous value. */
function guest_faculty_set_banking_enabled(PDO $pdo, bool $on): bool {
    $prev = guest_faculty_banking_enabled($pdo);
    $val = $on ? '1' : '0';
    $st = $pdo->prepare("SELECT COUNT(*) FROM admin_settings WHERE setting_name = ?");
    $st->execute([GUEST_FACULTY_BANKING_SETTING]);
    if ((int)$st->fetchColumn() > 0) {
        $pdo->prepare("UPDATE admin_settings SET setting_value = ?, updated_at = ? WHERE setting_name = ?")
            ->execute([$val, gf_now(), GUEST_FACULTY_BANKING_SETTING]);
    } else {
        $pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, created_at, updated_at) VALUES (?,?,?,?)")
            ->execute([GUEST_FACULTY_BANKING_SETTING, $val, gf_now(), gf_now()]);
    }
    return $prev;
}

/* ───────────────────────── Schema readiness ───────────────────────── */

/**
 * @return array ['ready' => bool, 'missing' => string[]]
 * The public page and admin actions refuse to run (rather than self-ALTER production)
 * until database-update-59-guest-faculty.sql has been applied.
 */
function guest_faculty_schema_status(PDO $pdo): array {
    $missing = [];
    $need = [
        'staff_registration_requests' => ['photo', 'qualifications', 'payment_mode', 'guest_banking_submitted', 'rate_live', 'rate_qpd', 'rate_recorded', 'rate_offline'],
        'faculties'                   => ['guest_faculty_registration_id', 'payment_mode'],
    ];
    foreach ($need as $table => $cols) {
        try {
            $stmt = $pdo->query("SELECT * FROM {$table} LIMIT 0");
            $have = [];
            for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
                $m = $stmt->getColumnMeta($i);
                if ($m && isset($m['name'])) $have[] = strtolower($m['name']);
            }
            foreach ($cols as $c) if (!in_array($c, $have, true)) $missing[] = "{$table}.{$c}";
        } catch (Throwable $e) {
            $missing[] = $table;
        }
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        try {
            $st = $pdo->query("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests' AND column_name = 'application_for' LIMIT 1");
            $type = (string)$st->fetchColumn();
            if (stripos($type, 'enum(') === 0 && stripos($type, GUEST_FACULTY_TYPE) === false) {
                $missing[] = 'staff_registration_requests.application_for(enum lacks guest_faculty)';
            }
        } catch (Throwable $e) { $missing[] = 'application_for check failed'; }
    }
    return ['ready' => empty($missing), 'missing' => $missing];
}

/* ───────────────────────── Country codes / mobile ───────────────────────── */

function guest_faculty_country_codes(): array {
    return [
        '+91' => 'India (+91)', '+1' => 'USA / Canada (+1)', '+44' => 'United Kingdom (+44)',
        '+61' => 'Australia (+61)', '+64' => 'New Zealand (+64)', '+65' => 'Singapore (+65)',
        '+60' => 'Malaysia (+60)', '+971' => 'UAE (+971)', '+966' => 'Saudi Arabia (+966)',
        '+974' => 'Qatar (+974)', '+965' => 'Kuwait (+965)', '+968' => 'Oman (+968)',
        '+973' => 'Bahrain (+973)', '+977' => 'Nepal (+977)', '+94' => 'Sri Lanka (+94)',
        '+880' => 'Bangladesh (+880)', '+92' => 'Pakistan (+92)', '+93' => 'Afghanistan (+93)',
        '+975' => 'Bhutan (+975)', '+960' => 'Maldives (+960)', '+95' => 'Myanmar (+95)',
        '+66' => 'Thailand (+66)', '+62' => 'Indonesia (+62)', '+63' => 'Philippines (+63)',
        '+84' => 'Vietnam (+84)', '+81' => 'Japan (+81)', '+82' => 'South Korea (+82)',
        '+86' => 'China (+86)', '+852' => 'Hong Kong (+852)', '+49' => 'Germany (+49)',
        '+33' => 'France (+33)', '+39' => 'Italy (+39)', '+34' => 'Spain (+34)',
        '+31' => 'Netherlands (+31)', '+41' => 'Switzerland (+41)', '+46' => 'Sweden (+46)',
        '+47' => 'Norway (+47)', '+45' => 'Denmark (+45)', '+353' => 'Ireland (+353)',
        '+7' => 'Russia / Kazakhstan (+7)', '+90' => 'Turkey (+90)', '+20' => 'Egypt (+20)',
        '+27' => 'South Africa (+27)', '+234' => 'Nigeria (+234)', '+254' => 'Kenya (+254)',
        '+55' => 'Brazil (+55)', '+52' => 'Mexico (+52)', '+54' => 'Argentina (+54)',
    ];
}

/** @return array [bool ok, string stored_cc, string digits, string error] */
function gf_normalize_mobile(string $cc, string $raw): array {
    $codes = guest_faculty_country_codes();
    $cc = trim($cc);
    if ($cc === '') $cc = '+91';
    if (!isset($codes[$cc])) return [false, '+91', '', 'Please select a valid country code.'];
    $digits = preg_replace('/\D/', '', $raw);
    $ccDigits = ltrim($cc, '+');
    if ($cc === '+91') {
        if (strlen($digits) === 12 && strpos($digits, '91') === 0) $digits = substr($digits, 2);
        $digits = ltrim($digits, '0');
        if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
            return [false, $cc, $digits, 'Enter a valid 10-digit Indian mobile number.'];
        }
    } else {
        if (strpos($digits, $ccDigits) === 0 && strlen($digits) > strlen($ccDigits) + 6) $digits = substr($digits, strlen($ccDigits));
        $digits = ltrim($digits, '0');
        if (!preg_match('/^\d{6,14}$/', $digits)) {
            return [false, $cc, $digits, 'Enter a valid mobile number for the selected country.'];
        }
    }
    return [true, $cc, $digits, ''];
}

/** faculties.mobile value: bare 10-digit for India (matches existing faculties), full international digits otherwise. */
function gf_faculty_mobile_value(?string $cc, ?string $digits): string {
    $digits = preg_replace('/\D/', '', (string)$digits);
    $cc = trim((string)$cc) ?: '+91';
    return $cc === '+91' ? $digits : ltrim($cc, '+') . $digits;
}

/* ───────────────────────── Field validators ───────────────────────── */

function gf_validate_name(string $raw): array {
    $v = trim(preg_replace('/\s+/u', ' ', $raw));
    if (mb_strlen($v) < 2 || mb_strlen($v) > 150) return [false, '', 'Full name must be 2–150 characters.'];
    if (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'’,\-]*$/u', $v)) return [false, '', 'Full name contains unsupported characters.'];
    return [true, $v, ''];
}
function gf_validate_email(string $raw): array {
    $v = strtolower(trim($raw));
    if ($v === '' || strlen($v) > 190 || !filter_var($v, FILTER_VALIDATE_EMAIL)) return [false, '', 'A valid email address is required.'];
    return [true, $v, ''];
}
function gf_validate_qualifications(string $raw): array {
    $v = trim(preg_replace('/[^\P{C}\n]/u', '', str_replace("\r", '', $raw)));
    if (mb_strlen($v) < 3) return [false, '', 'Qualifications are required.'];
    if (mb_strlen($v) > 1000) return [false, '', 'Qualifications must be 1000 characters or fewer.'];
    return [true, $v, ''];
}
function gf_validate_bank_name(string $raw): array {
    $v = trim(preg_replace('/\s+/u', ' ', $raw));
    if (mb_strlen($v) < 2 || mb_strlen($v) > 100 || !preg_match('/^[\p{L}\p{N} &.\'()\-,\/]+$/u', $v)) return [false, '', 'Enter a valid bank name (2–100 characters).'];
    return [true, $v, ''];
}
function gf_validate_account_number(string $raw): array {
    $v = strtoupper(preg_replace('/[\s\-]/', '', $raw));
    if (!preg_match('/^[A-Z0-9]{6,24}$/', $v) || preg_match('/^(.)\1+$/', $v)) return [false, '', 'Enter a valid account number.'];
    return [true, $v, ''];
}
function gf_validate_ifsc(string $raw): array {
    $v = strtoupper(trim($raw));
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $v)) return [false, '', 'Enter a valid 11-character IFSC code (e.g. SBIN0001234).'];
    return [true, $v, ''];
}
function gf_validate_upi(string $raw): array {
    $v = trim($raw);
    if (strlen($v) > 100) return [false, '', 'UPI ID is too long.'];
    $isVpa = (bool)preg_match('/^[A-Za-z0-9._\-]{2,64}@[A-Za-z][A-Za-z0-9.\-]{1,40}$/', $v);
    $isNumber = (bool)preg_match('/^[6-9]\d{9}$/', preg_replace('/[\s\-]/', '', $v));
    if (!$isVpa && !$isNumber) return [false, '', 'Enter a valid UPI ID (name@bank) or 10-digit UPI number.'];
    return [true, $isNumber && !$isVpa ? preg_replace('/[\s\-]/', '', $v) : $v, ''];
}

/** Banking POST keys that must never be honoured when the setting is OFF. */
function guest_faculty_banking_post_keys(): array {
    return ['bank_name', 'bank_account_number', 'ifsc_code', 'upi_id'];
}

/**
 * Validate the banking block. Only call when the setting is ON.
 * Bank trio is all-or-none; UPI is optional; everything empty is allowed ("can provide").
 * @return array ['errors'=>[], 'data'=>[bank_name, account, ifsc, upi, submitted(bool)]]
 */
function gf_validate_banking(array $post): array {
    $errors = [];
    $bn = trim((string)($post['bank_name'] ?? ''));
    $ac = trim((string)($post['bank_account_number'] ?? ''));
    $if = trim((string)($post['ifsc_code'] ?? ''));
    $up = trim((string)($post['upi_id'] ?? ''));
    $data = ['bank_name' => null, 'account' => null, 'ifsc' => null, 'upi' => null, 'submitted' => false];
    if ($bn !== '' || $ac !== '' || $if !== '') {
        if ($bn === '' || $ac === '' || $if === '') {
            $errors[] = 'To provide bank details, please enter Bank Name, Account Number and IFSC Code together.';
        } else {
            [$ok1, $bnv, $e1] = gf_validate_bank_name($bn);
            [$ok2, $acv, $e2] = gf_validate_account_number($ac);
            [$ok3, $ifv, $e3] = gf_validate_ifsc($if);
            if (!$ok1) $errors[] = $e1;
            if (!$ok2) $errors[] = $e2;
            if (!$ok3) $errors[] = $e3;
            if ($ok1 && $ok2 && $ok3) { $data['bank_name'] = $bnv; $data['account'] = $acv; $data['ifsc'] = $ifv; }
        }
    }
    if ($up !== '') {
        [$ok, $upv, $e] = gf_validate_upi($up);
        if (!$ok) $errors[] = $e; else $data['upi'] = $upv;
    }
    $data['submitted'] = ($data['account'] !== null || $data['upi'] !== null);
    return ['errors' => $errors, 'data' => $data];
}

/* ───────────────────────── Photo upload ───────────────────────── */

/**
 * Secure photo storage. Never trusts the client filename/MIME. Re-encodes through GD
 * (strips any embedded payload/metadata) at high quality because photos are used on posters.
 * @return array [string|null $db_path, string $error]
 */
function gf_store_photo(array $file, bool $require_uploaded = true): array {
    if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return [null, 'Photo is required.'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Photo is too large (maximum 8 MB).' : 'Photo upload failed. Please try again.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || ($require_uploaded && !is_uploaded_file($tmp))) return [null, 'Invalid photo upload.'];
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > GUEST_FACULTY_PHOTO_MAX_BYTES) return [null, 'Photo must be larger than 0 and at most 8 MB.'];

    $orig = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) return [null, 'Only JPG, PNG or WEBP photos are allowed.'];
    if (preg_match('/\.(php\d?|phtml|phar|pl|py|cgi|sh|exe|dll|js|jsp|asp|aspx|html?|svg)(\.|$)/i', $orig) || strpos($orig, "\0") !== false) {
        return [null, 'This file name is not allowed.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $mimeToExt = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
    if (!isset($mimeToExt[$mime]) || !in_array($ext, $mimeToExt[$mime], true)) return [null, 'The file is not a valid JPG, PNG or WEBP image.'];

    $info = @getimagesize($tmp);
    if ($info === false || empty($info[0]) || empty($info[1])) return [null, 'The file is not a valid image.'];
    [$w, $h] = $info;
    if ($w < 300 || $h < 300) return [null, 'Photo is too small. Please upload a clear, high-quality photo (at least 300×300 pixels).'];
    if ($w * $h > 40000000) return [null, 'Photo dimensions are too large.'];
    if (($info['mime'] ?? '') !== $mime) return [null, 'The file is not a valid image.'];

    if (!function_exists('imagecreatetruecolor')) return [null, 'Image processing is unavailable. Please contact PEPP Learning.'];
    $img = null;
    if ($mime === 'image/jpeg') $img = @imagecreatefromjpeg($tmp);
    elseif ($mime === 'image/png') $img = @imagecreatefrompng($tmp);
    elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($tmp);
    if (!$img) return [null, 'The image could not be processed. Please try a different photo.'];

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $o = (int)($exif['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot !== 0) { $r = imagerotate($img, $rot, 0); if ($r) { imagedestroy($img); $img = $r; } }
    }

    $base = dirname(__DIR__) . '/../uploads';
    $dir = $base . '/photos/guest_faculty';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { imagedestroy($img); return [null, 'Could not store the photo. Please try again later.']; }
    $realBase = realpath($base);
    $realDir = realpath($dir);
    if ($realBase === false || $realDir === false || strpos($realDir, $realBase) !== 0) { imagedestroy($img); return [null, 'Could not store the photo. Please try again later.']; }

    $outExt = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
    $name = 'gf_' . bin2hex(random_bytes(16)) . '.' . $outExt;
    $target = $realDir . DIRECTORY_SEPARATOR . $name;
    $ok = false;
    if ($outExt === 'png') { imagealphablending($img, false); imagesavealpha($img, true); $ok = @imagepng($img, $target, 6); }
    elseif ($outExt === 'webp') { $ok = @imagewebp($img, $target, 92); }
    else { $ok = @imagejpeg($img, $target, 95); }
    imagedestroy($img);
    if (!$ok) return [null, 'Could not store the photo. Please try again later.'];
    @chmod($target, 0644);
    return ['uploads/photos/guest_faculty/' . $name, ''];
}

/* ───────────────────────── Application reference ───────────────────────── */

function gf_fy_compact(PDO $pdo): string {
    if (function_exists('get_active_academic_year_compact')) {
        try { $v = get_active_academic_year_compact($pdo); if ($v) return (string)$v; } catch (Throwable $e) {}
    }
    $m = (int)date('n'); $y = (int)date('Y'); $s = ($m >= 6) ? $y : ($y - 1);
    return substr((string)$s, 2) . substr((string)($s + 1), 2);
}

/**
 * MUST be called inside an open transaction. The counter row is locked, and the
 * UNIQUE(application_reference) index is the final backstop; the loop also skips
 * any value already present (e.g. if the counter was reset).
 */
function gf_generate_reference(PDO $pdo): string {
    $st = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = ?" . gf_lock($pdo));
    $st->execute([GUEST_FACULTY_REF_SEQ_SETTING]);
    $raw = $st->fetchColumn();
    if ($raw === false) {
        $pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, created_at, updated_at) VALUES (?,?,?,?)")
            ->execute([GUEST_FACULTY_REF_SEQ_SETTING, '1', gf_now(), gf_now()]);
        $seq = 1;
    } else {
        $seq = max(1, (int)$raw);
    }
    $fy = gf_fy_compact($pdo);
    $exists = $pdo->prepare("SELECT COUNT(*) FROM staff_registration_requests WHERE application_reference = ?");
    for ($i = 0; $i < 100; $i++) {
        $ref = 'GUEST-FACULTY-' . $fy . '-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
        $exists->execute([$ref]);
        if ((int)$exists->fetchColumn() === 0) {
            $pdo->prepare("UPDATE admin_settings SET setting_value = ?, updated_at = ? WHERE setting_name = ?")
                ->execute([(string)($seq + 1), gf_now(), GUEST_FACULTY_REF_SEQ_SETTING]);
            return $ref;
        }
        $seq++;
    }
    throw new RuntimeException('Could not allocate a unique application reference.');
}

/* ───────────────────────── Public submission ───────────────────────── */

/** Same-type duplicate guard (mirrors staff-registration.php, scoped to guest_faculty). */
function gf_has_active_duplicate(PDO $pdo, string $email, string $mobile_digits): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM staff_registration_requests WHERE (email = ? OR mobile_number = ?) AND application_for = ? AND status IN ('pending','under_review','approved')");
    $st->execute([$email, $mobile_digits, GUEST_FACULTY_TYPE]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Insert a guest_faculty application. $banking is the validated banking array or null
 * (null whenever the server-side setting was OFF). Always status = 'pending'.
 * @return string application reference
 */
function gf_insert_application(PDO $pdo, array $d, ?array $banking, ?callable $encrypt, string $ip, string $ua): string {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $ref = gf_generate_reference($pdo);
        $acc_enc = $acc_mask = $bank_name = $ifsc = $upi = null; $submitted = 0;
        if ($banking !== null && !empty($banking['submitted'])) {
            $submitted = 1;
            $bank_name = $banking['bank_name'];
            $ifsc = $banking['ifsc'];
            $upi = $banking['upi'];
            if ($banking['account'] !== null) {
                if ($encrypt === null) throw new RuntimeException('Encryption unavailable.');
                $acc_enc = $encrypt($banking['account']);
                $clean = $banking['account']; $len = strlen($clean);
                $acc_mask = $len <= 4 ? str_repeat('X', $len) : 'XXXX XXXX ' . substr($clean, -4);
            }
        }
        $pdo->prepare("
            INSERT INTO staff_registration_requests
              (application_reference, photo, full_name, mobile_country_code, mobile_number, email, qualifications,
               application_for, status, bank_name, bank_account_encrypted, bank_account_masked, ifsc_code, upi_id,
               guest_banking_submitted, ip_address, user_agent, submitted_at)
            VALUES (?,?,?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?)
        ")->execute([
            $ref, $d['photo'], $d['full_name'], $d['mobile_cc'], $d['mobile'], $d['email'], $d['qualifications'],
            GUEST_FACULTY_TYPE, 'pending', $bank_name, $acc_enc, $acc_mask, $ifsc, $upi,
            $submitted, $ip, substr($ua, 0, 500), gf_now(),
        ]);
        if ($own) $pdo->commit();
        return $ref;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* ───────────────────────── Payment terms ───────────────────────── */

/**
 * Parse the four hourly rates. Every rate MUST be supplied (blank is rejected so that
 * 0.00 is always an explicit decision). All zero => 'free'; any > 0 => 'paid'.
 * @return array ['error'=>?string, 'rates'=>[field=>float], 'mode'=>?string]
 */
function gf_parse_rates(array $post): array {
    $rates = [];
    foreach (GUEST_FACULTY_RATE_FIELDS as $f) {
        if (!array_key_exists($f, $post)) return ['error' => 'Enter a payment amount for every session type (use 0.00 for unpaid/free faculty).', 'rates' => [], 'mode' => null];
        $raw = trim((string)$post[$f]);
        if ($raw === '') return ['error' => 'Enter a payment amount for every session type (use 0.00 for unpaid/free faculty).', 'rates' => [], 'mode' => null];
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $raw)) return ['error' => 'Payment amounts must be valid non-negative numbers (max 2 decimals).', 'rates' => [], 'mode' => null];
        $rates[$f] = round((float)$raw, 2);
    }
    $sum = array_sum($rates);
    return ['error' => null, 'rates' => $rates, 'mode' => ($sum > 0 ? 'paid' : 'free')];
}

/* ───────────────────────── Approval (transactional, idempotent) ───────────────────────── */

/**
 * @return array ['ok'=>bool,'already'=>bool,'faculty_id'=>?int,'mode'=>?string,'error'=>?string]
 */
function gf_approve_request(PDO $pdo, int $app_id, array $rates, string $mode, ?int $admin_id, string $admin_username, bool $add_to_directory = false): array {
    if (!in_array($mode, ['free', 'paid'], true)) return ['ok' => false, 'already' => false, 'faculty_id' => null, 'mode' => null, 'error' => 'Invalid payment mode.'];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, status, full_name FROM staff_registration_requests WHERE id = ? AND application_for = ?" . gf_lock($pdo));
        $st->execute([$app_id, GUEST_FACULTY_TYPE]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { if ($own) $pdo->rollBack(); return ['ok' => false, 'already' => false, 'faculty_id' => null, 'mode' => null, 'error' => 'Invited faculty application not found.']; }
        if ($row['status'] === 'approved') {
            if ($own) $pdo->rollBack();
            return ['ok' => true, 'already' => true, 'faculty_id' => null, 'mode' => null, 'error' => null];
        }
        if (!in_array($row['status'], ['pending', 'under_review'], true)) {
            if ($own) $pdo->rollBack();
            return ['ok' => false, 'already' => false, 'faculty_id' => null, 'mode' => null, 'error' => 'This application has already been processed (' . $row['status'] . ').'];
        }
        $now = gf_now();
        $up = $pdo->prepare("
            UPDATE staff_registration_requests
               SET status = 'approved', payment_mode = ?, rate_live = ?, rate_qpd = ?, rate_recorded = ?, rate_offline = ?,
                   reviewed_by = ?, reviewed_at = ?, approved_by_admin_id = ?, approved_by_username = ?, approved_at = ?
             WHERE id = ? AND application_for = ? AND status IN ('pending','under_review')
        ");
        $up->execute([$mode, $rates['rate_live'], $rates['rate_qpd'], $rates['rate_recorded'], $rates['rate_offline'],
                      $admin_username, $now, $admin_id, $admin_username, $now, $app_id, GUEST_FACULTY_TYPE]);
        if ($up->rowCount() !== 1) { if ($own) $pdo->rollBack(); return ['ok' => false, 'already' => false, 'faculty_id' => null, 'mode' => null, 'error' => 'Approval could not be applied (concurrent change). Please refresh.']; }

        $faculty_id = null;
        if ($add_to_directory) {
            $r = gf_add_to_directory($pdo, $app_id, $admin_username);
            if (!$r['ok']) throw new RuntimeException($r['error'] ?? 'Could not add to the Faculty Directory.');
            $faculty_id = $r['faculty_id'];
        }
        if ($own) $pdo->commit();
        return ['ok' => true, 'already' => false, 'faculty_id' => $faculty_id, 'mode' => $mode, 'error' => null];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* ───────────────────────── Add to Faculty Directory ───────────────────────── */

/**
 * Creates the single operational faculties row for an APPROVED guest_faculty application
 * from authoritative request data. Idempotent: an existing link is returned, never duplicated
 * (UNIQUE faculties.guest_faculty_registration_id is the DB backstop).
 * @return array ['ok'=>bool,'already'=>bool,'faculty_id'=>?int,'error'=>?string]
 */
function gf_add_to_directory(PDO $pdo, int $app_id, string $created_by): array {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM staff_registration_requests WHERE id = ? AND application_for = ?" . gf_lock($pdo));
        $st->execute([$app_id, GUEST_FACULTY_TYPE]);
        $app = $st->fetch(PDO::FETCH_ASSOC);
        $fail = function (string $m) use ($pdo, $own) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); return ['ok' => false, 'already' => false, 'faculty_id' => null, 'error' => $m]; };
        if (!$app) return $fail('Invited faculty application not found.');
        if ($app['status'] !== 'approved') return $fail('Only approved invited faculty can be added to the Faculty Directory.');
        if (!in_array((string)($app['payment_mode'] ?? ''), ['free', 'paid'], true)) return $fail('Payment terms have not been configured for this applicant.');

        $ex = $pdo->prepare("SELECT id FROM faculties WHERE guest_faculty_registration_id = ?" . gf_lock($pdo));
        $ex->execute([$app_id]);
        $existing = $ex->fetchColumn();
        if ($existing) { if ($own) $pdo->rollBack(); return ['ok' => true, 'already' => true, 'faculty_id' => (int)$existing, 'error' => null]; }

        try {
            $pdo->prepare("
                INSERT INTO faculties (guest_faculty_registration_id, name, mobile, email, rate_live, rate_qpd, rate_recorded, rate_offline,
                                       academic_year, status, payment_mode, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $app_id, trim((string)$app['full_name']),
                gf_faculty_mobile_value($app['mobile_country_code'] ?? '+91', $app['mobile_number'] ?? ''),
                trim((string)$app['email']) ?: null,
                (float)$app['rate_live'], (float)$app['rate_qpd'], (float)$app['rate_recorded'], (float)$app['rate_offline'],
                null, 'active', $app['payment_mode'], $created_by, gf_now(),
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') { // unique race → someone else linked first
                if ($own && $pdo->inTransaction()) $pdo->rollBack();
                $q = $pdo->prepare("SELECT id FROM faculties WHERE guest_faculty_registration_id = ?");
                $q->execute([$app_id]);
                $id = $q->fetchColumn();
                return ['ok' => (bool)$id, 'already' => (bool)$id, 'faculty_id' => $id ? (int)$id : null, 'error' => $id ? null : 'Could not add faculty.'];
            }
            throw $e;
        }
        $fid = (int)$pdo->lastInsertId();
        if ($own) $pdo->commit();
        return ['ok' => true, 'already' => false, 'faculty_id' => $fid, 'error' => null];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Revise payment terms of an invited faculty (keeps request + faculties row in sync, one transaction).
 * @return array ['ok'=>bool,'error'=>?string,'mode'=>?string]
 */
function gf_update_payment_terms(PDO $pdo, int $faculty_id, array $rates, string $mode): array {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, guest_faculty_registration_id FROM faculties WHERE id = ?" . gf_lock($pdo));
        $st->execute([$faculty_id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f || empty($f['guest_faculty_registration_id'])) { if ($own) $pdo->rollBack(); return ['ok' => false, 'error' => 'This faculty is not an invited faculty record.', 'mode' => null]; }
        // Update ONLY the operational faculties record.
        // The original staff_registration_requests row remains an immutable approval snapshot.
        $pdo->prepare("UPDATE faculties SET payment_mode = ?, rate_live = ?, rate_qpd = ?, rate_recorded = ?, rate_offline = ? WHERE id = ?")
            ->execute([$mode, $rates['rate_live'], $rates['rate_qpd'], $rates['rate_recorded'], $rates['rate_offline'], $faculty_id]);
        if ($own) $pdo->commit();
        return ['ok' => true, 'error' => null, 'mode' => $mode];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Admin-facing banking view of a guest_faculty request row. Never returns ciphertext.
 * Without the "view bank credentials" permission everything is masked/restricted.
 * Even WITH permission the account number is returned only in its masked form here;
 * full reveal/copy lives in faculties.php behind CSRF + permission + audit log.
 */
function gf_admin_banking_view(array $row, bool $can_view): array {
    require_once __DIR__ . '/staff_type_helper.php';
    $has = !empty($row['guest_banking_submitted']) || !empty($row['bank_account_masked']) || !empty($row['upi_id']);
    $out = ['submitted' => (bool)$has, 'restricted' => !$can_view, 'bank_name' => '', 'account_masked' => '', 'ifsc' => '', 'upi' => ''];
    if (!$has) return $out;
    if ($can_view) {
        $out['bank_name'] = (string)($row['bank_name'] ?? '');
        $out['account_masked'] = (string)($row['bank_account_masked'] ?? '');
        $out['ifsc'] = (string)($row['ifsc_code'] ?? '');
        $out['upi'] = (string)($row['upi_id'] ?? '');
    } else {
        $out['bank_name'] = !empty($row['bank_name']) ? '[Restricted]' : '';
        $out['account_masked'] = staff_mask_account_number((string)($row['bank_account_masked'] ?? ''));
        $out['ifsc'] = staff_mask_ifsc((string)($row['ifsc_code'] ?? ''));
        $out['upi'] = staff_mask_upi((string)($row['upi_id'] ?? ''));
    }
    return $out;
}

/** Safe display helpers */
function gf_payment_label(?string $mode): string {
    if ($mode === 'free') return 'Not Payable (Free Faculty)';
    if ($mode === 'paid') return 'Payable';
    return 'Not configured';
}
function gf_photo_url_valid(?string $p): bool {
    return $p !== null && $p !== '' && preg_match('#^uploads/photos/[A-Za-z0-9_/]+\.(jpg|png|webp)$#', $p) === 1 && strpos($p, '..') === false;
}
