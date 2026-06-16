<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('add-student');
require_once 'includes/invoice_helper.php';

/* Admin manually adds an already-approved student.
   Fixes vs old version:
   - track_records INSERT used wrong columns (action/description/admin_name)
     → now action_type/action_details/performed_by (matches schema), so the
     false "Error adding student" after a successful insert is gone
   - sets approved_by, approval_date, joined_date, total_fee, phone
   - duplicate checks are PER ACADEMIC YEAR (matches unique keys)
   - installment schedule + approval history recorded, all in a transaction
   - CSV import uses the same fixed logic */

$success_message = '';
$error_message   = '';
$form_data = [];

function gen_user_id($pdo) {
    do {
        $uid = 'PEPP' . date('Y') . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ?");
        $stmt->execute([$uid]);
    } while ($stmt->fetch());
    return $uid;
}

function handle_upload($field, $dir) {
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) return null;
    
    $target_dir = '../' . $dir;
    if (!is_dir($target_dir)) @mkdir($target_dir, 0755, true);
    $name = uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES[$field]['name']));
    $target_path = rtrim($target_dir, '/') . '/' . $name;
    $db_path = rtrim($dir, '/') . '/' . $name;
    return move_uploaded_file($_FILES[$field]['tmp_name'], $target_path) ? $db_path : null;
}

/** Insert one approved student. Returns the new user_id. Throws on failure. */
function insert_student($pdo, array $d, $admin_username, $photo = null, $screenshot = null) {
    // Duplicate checks scoped to COURSE + academic year (a student may join
    // multiple courses in the same year; matches the DB unique keys)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND pepp_course = ? AND pepp_academic_year = ?");
    $stmt->execute([$d['email'], $d['pepp_course'], $d['pepp_academic_year']]);
    if ($stmt->fetchColumn() > 0) throw new Exception("Email {$d['email']} is already registered for this course in {$d['pepp_academic_year']}.");

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE whatsapp_number = ? AND pepp_course = ? AND pepp_academic_year = ?");
    $stmt->execute([$d['whatsapp_number'], $d['pepp_course'], $d['pepp_academic_year']]);
    if ($stmt->fetchColumn() > 0) throw new Exception("WhatsApp number {$d['whatsapp_number']} is already registered for this course in {$d['pepp_academic_year']}.");

    // Fee from the course catalogue
    $stmt = $pdo->prepare("SELECT total_fee FROM pepp_courses WHERE course_name = ? LIMIT 1");
    $stmt->execute([$d['pepp_course']]);
    $course_fee = (float)($stmt->fetchColumn() ?: 0);
    $discount   = (float)($d['discount_amount'] ?? 0);
    $total_fee  = max(0, $course_fee - $discount);

    $user_id = gen_user_id($pdo);
    $phone   = $d['mobile_number'] !== '' ? $d['mobile_number'] : $d['whatsapp_number'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (
                name, gender, date_of_birth, whatsapp_country_code, whatsapp_number,
                mobile_same_as_whatsapp, mobile_number, emergency_contact, email,
                college_school, course, university_board, remaining_semesters,
                postal_address, postal_pincode, state, district, place_post_office,
                pepp_course, pepp_academic_year, paid_amount, paid_date,
                payment_screenshot, user_photo, instagram_id, how_know_pepp, terms_agreed,
                user_id, ip_address, submit_datetime, status,
                approved_by, approval_date, joined_date, payment_mode, payment_account_id,
                discount_amount, discount_remark, payment_plan, peppkit_eligible,
                total_fee, student_status, course_status, course_duration_date, phone,
                created_at, updated_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'yes',
                      ?, ?, NOW(), 'approved',
                      ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'active', ?, ?,
                      NOW(), NOW())
        ");
        $stmt->execute([
            $d['name'], $d['gender'], $d['date_of_birth'], $d['whatsapp_country_code'], $d['whatsapp_number'],
            $d['mobile_same_as_whatsapp'], $d['mobile_number'], $d['emergency_contact'], $d['email'],
            $d['college_school'], $d['course'], $d['university_board'], $d['remaining_semesters'],
            $d['postal_address'], $d['postal_pincode'], $d['state'], $d['district'], $d['place_post_office'],
            $d['pepp_course'], $d['pepp_academic_year'], $d['paid_amount'], $d['paid_date'],
            $screenshot, $photo, $d['instagram_id'], $d['how_know_pepp'],
            $user_id, $_SERVER['REMOTE_ADDR'] ?? null,
            $admin_username, $d['joined_date'], $d['payment_mode'], $d['payment_account_id'],
            $discount, $d['discount_remark'], $d['payment_plan'], $d['peppkit_eligible'],
            $total_fee, $d['course_duration_date'] ?: null, $phone
        ]);

        // Future installments (#1 = registration payment)
        if ($d['payment_plan'] !== 'One Time' && !empty($d['installments'])) {
            $ins = $pdo->prepare("INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())");
            foreach ($d['installments'] as $n => $row) {
                if ((float)$row['amount'] > 0 && $row['due_date']) {
                    $ins->execute([$user_id, $n, $row['amount'], $row['due_date']]);
                }
            }
        }

        // Approval history
        $plan_hist = preg_match('/^(\d) /', $d['payment_plan'], $m) ? $m[1] . ' Instalments' : 'One Time';
        $stmt = $pdo->prepare("
            INSERT INTO student_approval_history
                (user_id, action, approved_by, payment_mode, payment_account_id, discount_amount, discount_remark, payment_plan, peppkit_eligible, approval_date, notes)
            VALUES (?, 'approved', ?, ?, ?, ?, ?, ?, ?, NOW(), 'Added manually by admin')
        ");
        $stmt->execute([
            $user_id, $admin_username, $d['payment_mode'], $d['payment_account_id'],
            $discount, $d['discount_remark'], $plan_hist, $d['peppkit_eligible']
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Audit (correct columns - this was the bug that broke the old page)
    track_record($pdo, $user_id, 'student_added', 'Student added manually by admin', $admin_username);
    status_log($pdo, $user_id, 'new', 'approved', 'Added manually by admin', $admin_username);

    // Automatic invoice for the registration payment
    if ((float)$d['paid_amount'] > 0) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $row_id = (int)$stmt->fetchColumn();
        generate_payment_invoice($pdo, [
            'source' => 'registration', 'source_ref' => $row_id, 'user_id' => $user_id,
            'amount' => $d['paid_amount'], 'account_id' => $d['payment_account_id'],
            'payment_mode' => $d['payment_mode'], 'paid_date' => $d['paid_date'],
            'generated_by' => $admin_username,
            'send_email' => !empty($d['send_invoice_email']),
        ]);
    }

    return $user_id;
}

/* ── POST: single add ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_student') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
        $form_data = $_POST;
    } else {
        $required = ['name', 'whatsapp_number', 'email', 'pepp_course', 'pepp_academic_year', 'paid_amount', 'paid_date'];
        $missing = array_filter($required, function ($f) { return trim($_POST[$f] ?? '') === ''; });
        if ($missing) {
            $error_message = 'Please fill the required fields: ' . implode(', ', $missing);
            $form_data = $_POST;
        } else {
            try {
                $plan = $_POST['payment_plan'] ?? 'One Time';
                $installments = [];
                if ($plan !== 'One Time') {
                    $n = (int)explode(' ', $plan)[0];
                    for ($i = 2; $i <= $n; $i++) {
                        $installments[$i] = [
                            'amount'   => (float)($_POST["installment_{$i}_amount"] ?? 0),
                            'due_date' => $_POST["installment_{$i}_due_date"] ?? '',
                        ];
                    }
                }
                $sem = isset($_POST['remaining_semesters']) && is_array($_POST['remaining_semesters'])
                    ? implode(',', $_POST['remaining_semesters']) : trim($_POST['remaining_semesters'] ?? '');

                $data = [
                    'name' => trim($_POST['name']), 'gender' => $_POST['gender'] ?? 'Male',
                    'date_of_birth' => $_POST['date_of_birth'] ?: '2000-01-01',
                    'whatsapp_country_code' => trim($_POST['whatsapp_country_code'] ?? '+91'),
                    'whatsapp_number' => preg_replace('/\D/', '', $_POST['whatsapp_number']),
                    'mobile_same_as_whatsapp' => ($_POST['mobile_same_as_whatsapp'] ?? 'yes') === 'no' ? 'no' : 'yes',
                    'mobile_number' => preg_replace('/\D/', '', $_POST['mobile_number'] ?? ''),
                    'emergency_contact' => trim($_POST['emergency_contact'] ?? ''),
                    'email' => strtolower(trim($_POST['email'])),
                    'college_school' => trim($_POST['college_school'] ?? ''),
                    'course' => trim($_POST['course'] ?? ''),
                    'university_board' => trim($_POST['university_board'] ?? ''),
                    'remaining_semesters' => $sem,
                    'postal_address' => trim($_POST['postal_address'] ?? ''),
                    'postal_pincode' => trim($_POST['postal_pincode'] ?? ''),
                    'state' => trim($_POST['state'] ?? ''), 'district' => trim($_POST['district'] ?? ''),
                    'place_post_office' => trim($_POST['place_post_office'] ?? ''),
                    'pepp_course' => $_POST['pepp_course'], 'pepp_academic_year' => $_POST['pepp_academic_year'],
                    'paid_amount' => (float)$_POST['paid_amount'], 'paid_date' => $_POST['paid_date'],
                    'instagram_id' => trim($_POST['instagram_id'] ?? ''),
                    'how_know_pepp' => trim($_POST['how_know_pepp'] ?? 'Admin Entry'),
                    'joined_date' => $_POST['joined_date'] ?: date('Y-m-d'),
                    'payment_mode' => in_array($_POST['payment_mode'] ?? '', ['Online','Cash','100% Scholarship','Pay later'], true) ? $_POST['payment_mode'] : 'Online',
                    'payment_account_id' => !empty($_POST['payment_account_id']) ? (int)$_POST['payment_account_id'] : null,
                    'discount_amount' => (float)($_POST['discount_amount'] ?? 0),
                    'discount_remark' => trim($_POST['discount_remark'] ?? ''),
                    'payment_plan' => $plan,
                    'peppkit_eligible' => ($_POST['peppkit_eligible'] ?? '') === 'Eligible' ? 'Eligible' : 'Not Eligible',
                    'course_duration_date' => $_POST['course_duration_date'] ?? '',
                    'installments' => $installments,
                ];
                if ($data['mobile_same_as_whatsapp'] === 'yes') $data['mobile_number'] = $data['whatsapp_number'];
                if ($data['emergency_contact'] === '') $data['emergency_contact'] = $data['whatsapp_number'];

                $data['send_invoice_email'] = true; // manual add → email the invoice
                $photo      = handle_upload('user_photo', 'uploads/photos');
                $screenshot = handle_upload('payment_screenshot', 'uploads/payments');

                $new_id = insert_student($pdo, $data, $admin_username, $photo, $screenshot);
                $success_message = "Student added successfully with ID: {$new_id}";
            } catch (Exception $e) {
                $error_message = $e->getMessage();
                $form_data = $_POST;
            }
        }
    }
}

/* ── POST: CSV import ───────────────────────────────────────────── */
$import_results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } elseif (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error_message = 'Please choose a CSV file to import.';
    } else {
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $header = fgetcsv($fh); // name,email,whatsapp_number,pepp_course,pepp_academic_year,paid_amount,paid_date
        $row_no = 1; $ok = 0; $fail = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $row_no++;
            if (count(array_filter($row)) === 0) continue;
            try {
                [$nm, $em, $wa, $pc, $yr, $amt, $pd] = array_pad($row, 7, '');
                if (!$nm || !$em || !$wa || !$pc) throw new Exception('missing required column(s)');
                $data = [
                    'name' => trim($nm), 'gender' => 'Male', 'date_of_birth' => '2000-01-01',
                    'whatsapp_country_code' => '+91', 'whatsapp_number' => preg_replace('/\D/', '', $wa),
                    'mobile_same_as_whatsapp' => 'yes', 'mobile_number' => preg_replace('/\D/', '', $wa),
                    'emergency_contact' => preg_replace('/\D/', '', $wa), 'email' => strtolower(trim($em)),
                    'college_school' => '-', 'course' => '-', 'university_board' => '-',
                    'remaining_semesters' => '', 'postal_address' => '-', 'postal_pincode' => '',
                    'state' => '', 'district' => '', 'place_post_office' => '',
                    'pepp_course' => trim($pc), 'pepp_academic_year' => trim($yr) ?: date('Y') . '-' . substr(date('Y') + 1, 2),
                    'paid_amount' => (float)$amt, 'paid_date' => $pd ?: date('Y-m-d'),
                    'instagram_id' => '', 'how_know_pepp' => 'CSV Import',
                    'joined_date' => date('Y-m-d'), 'payment_mode' => 'Online', 'payment_account_id' => null,
                    'discount_amount' => 0, 'discount_remark' => '', 'payment_plan' => 'One Time',
                    'peppkit_eligible' => 'Not Eligible', 'course_duration_date' => '', 'installments' => [],
                ];
                $new_id = insert_student($pdo, $data, $admin_username);
                $import_results[] = ['row' => $row_no, 'ok' => true, 'msg' => "{$nm} → {$new_id}"];
                $ok++;
            } catch (Exception $e) {
                $import_results[] = ['row' => $row_no, 'ok' => false, 'msg' => $e->getMessage()];
                $fail++;
            }
        }
        fclose($fh);
        $success_message = "CSV import finished: {$ok} added, {$fail} skipped.";
    }
}

/* ── Page data ──────────────────────────────────────────────────── */
try {
    $courses = $pdo->query("SELECT course_name, total_fee, course_type FROM pepp_courses WHERE status = 'active' ORDER BY course_name")->fetchAll();
} catch (Exception $e) { $courses = []; }
try {
    $years = $pdo->query("SELECT year FROM academic_years WHERE status = 'active' ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $years = []; }
try {
    $payment_accounts = $pdo->query("SELECT id, account_name, account_type FROM payment_accounts WHERE status = 'active' ORDER BY account_name")->fetchAll();
} catch (Exception $e) { $payment_accounts = []; }

$fd = function ($k, $def = '') use ($form_data) { return e($form_data[$k] ?? $def); };
$all_semesters = ['1st Semester','2nd Semester','3rd Semester','4th Semester','5th Semester','6th Semester','7th Semester','8th Semester','Already Completed','Higher Secondary Student'];

$active_page = 'add-student';
$page_title  = 'Add Student';
$page_sub    = 'Manually enroll an approved student';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<?php if ($import_results): ?>
<div class="panel">
    <div class="panel-head"><span class="head-icon"><i class="fas fa-file-csv"></i></span><h2>Import Results</h2></div>
    <div class="panel-body flush table-wrap">
        <table class="data-table">
            <thead><tr><th>Row</th><th>Result</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($import_results as $r): ?>
                <tr>
                    <td><?php echo (int)$r['row']; ?></td>
                    <td><span class="badge <?php echo $r['ok'] ? 'green' : 'red'; ?>"><?php echo $r['ok'] ? 'Added' : 'Skipped'; ?></span></td>
                    <td class="cell-sub"><?php echo e($r['msg']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="add_student">

    <div class="panel">
        <div class="panel-head"><span class="head-icon"><i class="fas fa-id-card"></i></span><h2>Personal &amp; Contact</h2></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="field"><label>Full Name <span class="req">*</span></label><input type="text" name="name" value="<?php echo $fd('name'); ?>" required></div>
                <div class="field"><label>Gender</label>
                    <select name="gender"><?php foreach (['Male','Female','Other'] as $g) echo "<option" . (($form_data['gender'] ?? '') === $g ? ' selected' : '') . ">$g</option>"; ?></select></div>
                <div class="field"><label>Date of Birth</label><input type="date" name="date_of_birth" value="<?php echo $fd('date_of_birth'); ?>"></div>
                <div class="field"><label>Email <span class="req">*</span></label><input type="email" name="email" value="<?php echo $fd('email'); ?>" required></div>
                <div class="field"><label>WhatsApp Code</label><input type="text" name="whatsapp_country_code" value="<?php echo $fd('whatsapp_country_code', '+91'); ?>"></div>
                <div class="field"><label>WhatsApp Number <span class="req">*</span></label><input type="text" name="whatsapp_number" value="<?php echo $fd('whatsapp_number'); ?>" required></div>
                <div class="field"><label>Mobile same as WhatsApp</label>
                    <select name="mobile_same_as_whatsapp" id="same-toggle" onchange="document.getElementById('mob-field').style.display = this.value === 'no' ? '' : 'none';">
                        <option value="yes">Yes</option>
                        <option value="no" <?php echo ($form_data['mobile_same_as_whatsapp'] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                    </select></div>
                <div class="field" id="mob-field" style="<?php echo ($form_data['mobile_same_as_whatsapp'] ?? 'yes') === 'no' ? '' : 'display:none;'; ?>">
                    <label>Mobile Number</label><input type="text" name="mobile_number" value="<?php echo $fd('mobile_number'); ?>"></div>
                <div class="field"><label>Emergency Contact</label><input type="text" name="emergency_contact" value="<?php echo $fd('emergency_contact'); ?>"></div>
                <div class="field"><label>Instagram ID</label><input type="text" name="instagram_id" value="<?php echo $fd('instagram_id'); ?>"></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-location-dot"></i></span><h2>Address</h2></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="field full"><label>Postal Address</label><textarea name="postal_address"><?php echo $fd('postal_address'); ?></textarea></div>
                <div class="field"><label>PIN Code</label><input type="text" name="postal_pincode" maxlength="6" value="<?php echo $fd('postal_pincode'); ?>"></div>
                <div class="field"><label>State</label><input type="text" name="state" value="<?php echo $fd('state'); ?>"></div>
                <div class="field"><label>District</label><input type="text" name="district" value="<?php echo $fd('district'); ?>"></div>
                <div class="field"><label>Place / Post Office</label><input type="text" name="place_post_office" value="<?php echo $fd('place_post_office'); ?>"></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span class="head-icon" style="background:var(--pink-soft);color:var(--pink-ink);"><i class="fas fa-graduation-cap"></i></span><h2>Academic &amp; PEPP Course</h2></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="field"><label>College / School</label><input type="text" name="college_school" value="<?php echo $fd('college_school'); ?>"></div>
                <div class="field"><label>Current Course</label><input type="text" name="course" value="<?php echo $fd('course'); ?>"></div>
                <div class="field"><label>University / Board</label><input type="text" name="university_board" value="<?php echo $fd('university_board'); ?>"></div>
                <div class="field"><label>PEPP Course <span class="req">*</span></label>
                    <select name="pepp_course" id="pepp-course" required onchange="updateFeeHint()">
                        <option value="">- Select course -</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?php echo e($c['course_name']); ?>" data-fee="<?php echo (float)$c['total_fee']; ?>"
                                <?php echo ($form_data['pepp_course'] ?? '') === $c['course_name'] ? 'selected' : ''; ?>>
                                <?php echo e($c['course_name']); ?> (₹<?php echo number_format((float)$c['total_fee'], 0); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help" id="fee-hint"></div></div>
                <div class="field"><label>Academic Year <span class="req">*</span></label>
                    <select name="pepp_academic_year" required>
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo e($y); ?>" <?php echo ($form_data['pepp_academic_year'] ?? '') === $y ? 'selected' : ''; ?>><?php echo e($y); ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label>How they heard of PEPP</label><input type="text" name="how_know_pepp" value="<?php echo $fd('how_know_pepp', 'Admin Entry'); ?>"></div>
                <div class="field full">
                    <label>Remaining Semesters</label>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <?php foreach ($all_semesters as $sem): ?>
                            <label style="display:inline-flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;background:var(--card);border-radius:50px;padding:6px 13px;cursor:pointer;text-transform:none;letter-spacing:0;color:var(--foreground);">
                                <input type="checkbox" name="remaining_semesters[]" value="<?php echo e($sem); ?>" style="width:auto;accent-color:var(--accent);"> <?php echo e($sem); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-wallet"></i></span><h2>Payment &amp; Enrollment</h2></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="field"><label>Paid Amount (₹) <span class="req">*</span></label><input type="number" name="paid_amount" id="paid-amount" min="0" step="0.01" value="<?php echo $fd('paid_amount'); ?>" required onchange="renderInstallments()"></div>
                <div class="field"><label>Paid Date <span class="req">*</span></label><input type="date" name="paid_date" value="<?php echo $fd('paid_date', date('Y-m-d')); ?>" required></div>
                <div class="field"><label>Joined Date</label><input type="date" name="joined_date" value="<?php echo $fd('joined_date', date('Y-m-d')); ?>"></div>
                <div class="field"><label>Course access until</label><input type="date" name="course_duration_date" value="<?php echo $fd('course_duration_date', date('Y-m-d', strtotime('+1 year'))); ?>"></div>
                <div class="field"><label>Payment Mode</label>
                    <select name="payment_mode">
                        <?php foreach (['Online','Cash','100% Scholarship','Pay later'] as $m) echo "<option" . (($form_data['payment_mode'] ?? '') === $m ? ' selected' : '') . ">$m</option>"; ?>
                    </select></div>
                <div class="field"><label>Payment Account</label>
                    <select name="payment_account_id">
                        <option value="">- Select -</option>
                        <?php foreach ($payment_accounts as $a): ?>
                            <option value="<?php echo (int)$a['id']; ?>"><?php echo e($a['account_name']); ?> (<?php echo e($a['account_type']); ?>)</option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label>Payment Plan</label>
                    <select name="payment_plan" id="pay-plan" onchange="renderInstallments()">
                        <?php foreach (['One Time','2 Installments','3 Installments','4 Installments','5 Installments'] as $p) echo "<option" . (($form_data['payment_plan'] ?? '') === $p ? ' selected' : '') . ">$p</option>"; ?>
                    </select></div>
                <div class="field"><label>Discount (₹)</label><input type="number" name="discount_amount" id="discount" min="0" step="0.01" value="<?php echo $fd('discount_amount', '0'); ?>" onchange="renderInstallments()"></div>
                <div class="field"><label>Discount Remark</label><input type="text" name="discount_remark" value="<?php echo $fd('discount_remark'); ?>"></div>
                <div class="field"><label>PEPP Kit</label>
                    <select name="peppkit_eligible">
                        <option value="Not Eligible">Not Eligible</option>
                        <option value="Eligible" <?php echo ($form_data['peppkit_eligible'] ?? '') === 'Eligible' ? 'selected' : ''; ?>>Eligible</option>
                    </select></div>
                <div class="field"><label>Student Photo (optional)</label><input type="file" name="user_photo" accept="image/*"></div>
                <div class="field"><label>Payment Proof (optional)</label><input type="file" name="payment_screenshot" accept="image/*,.pdf"></div>
            </div>
            <div id="installments-box" style="margin-top:14px;"></div>
        </div>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Student</button>
    </div>
</form>

<!-- ── CSV IMPORT ── -->
<div class="panel" style="margin-top:24px;">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-file-csv"></i></span>
        <h2>Bulk Import (CSV)</h2>
    </div>
    <div class="panel-body">
        <div class="alert alert-info"><i class="fas fa-circle-info"></i>
            <span>Columns (with a header row): <code>name, email, whatsapp_number, pepp_course, pepp_academic_year, paid_amount, paid_date</code>.
            Course names must match the Courses page exactly. Imported students are approved with a One Time plan; details can be edited afterwards.</span>
        </div>
        <form method="POST" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="import_csv">
            <div class="field" style="flex:1; min-width:220px;">
                <label>CSV file</label>
                <input type="file" name="csv_file" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-soft-blue"><i class="fas fa-upload"></i> Import</button>
        </form>
    </div>
</div>

<?php
$extra_scripts = "<script>
function courseFee() {
    const sel = document.getElementById('pepp-course');
    const opt = sel.options[sel.selectedIndex];
    return opt ? parseFloat(opt.dataset.fee || 0) : 0;
}
function updateFeeHint() {
    const f = courseFee();
    document.getElementById('fee-hint').textContent = f ? 'Course fee: ₹' + f.toLocaleString('en-IN') : '';
    renderInstallments();
}
function renderInstallments() {
    const plan = document.getElementById('pay-plan').value;
    const box = document.getElementById('installments-box');
    box.innerHTML = '';
    if (plan === 'One Time') return;
    const n = parseInt(plan);
    const paid = parseFloat(document.getElementById('paid-amount').value || 0);
    const disc = parseFloat(document.getElementById('discount').value || 0);
    const remaining = Math.max(0, courseFee() - disc - paid);
    const per = n > 1 ? Math.round(remaining / (n - 1)) : 0;
    let html = '<div class=\"alert alert-warn\"><i class=\"fas fa-circle-info\"></i><span>Installment #1 = the payment above (₹' + paid.toLocaleString('en-IN') + '). Schedule the remaining ₹' + remaining.toLocaleString('en-IN') + ':</span></div><div class=\"form-grid\">';
    for (let i = 2; i <= n; i++) {
        const d = new Date(); d.setMonth(d.getMonth() + (i - 1));
        html += '<div class=\"field\"><label>Installment #' + i + ' amount (₹)</label><input type=\"number\" name=\"installment_' + i + '_amount\" min=\"0\" step=\"0.01\" value=\"' + per + '\"></div>' +
                '<div class=\"field\"><label>Installment #' + i + ' due date</label><input type=\"date\" name=\"installment_' + i + '_due_date\" value=\"' + d.toISOString().slice(0,10) + '\"></div>';
    }
    box.innerHTML = html + '</div>';
}
updateFeeHint();
</script>";
include 'includes/admin_footer.php';
?>
