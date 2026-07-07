<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('approvals');

/* View + edit a registration before approval.
   Linked from: student-approval.php (and dashboard recent registrations). */

$student_id = trim($_GET['id'] ?? '');
if ($student_id === '') {
    header('Location: student-approval.php');
    exit();
}

$message = '';
$error   = '';

// Fetch student first
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
} catch (Exception $e) {
    $student = null;
}

if (!$student) {
    $active_page = 'approvals';
    $page_title  = 'Registration Details';
    $page_sub    = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span>Student not found.</span></div>';
    echo '<a class="btn btn-outline" href="student-approval.php"><i class="fas fa-arrow-left"></i> Back to Approvals</a>';
    include 'includes/admin_footer.php';
    exit();
}

// ── Handle update (whitelisted fields, CSRF, audit) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } else {
        try {
            $updateFields = [
                'name', 'gender', 'date_of_birth', 'whatsapp_country_code', 'whatsapp_number',
                'mobile_same_as_whatsapp', 'mobile_number', 'emergency_contact', 'email',
                'college_school', 'course', 'university_board', 'remaining_semesters',
                'postal_address', 'postal_pincode', 'state', 'district', 'place_post_office',
                'pepp_course', 'pepp_academic_year', 'paid_amount', 'paid_date',
                'instagram_id', 'how_know_pepp'
            ];
            $set = [];
            $vals = [];
            $changed = [];
            foreach ($updateFields as $field) {
                if (!isset($_POST[$field])) continue;
                $value = is_array($_POST[$field]) ? implode(',', $_POST[$field]) : trim($_POST[$field]);
                $set[]  = "$field = ?";
                $vals[] = $value;
                if ((string)$student[$field] !== (string)$value) $changed[] = $field;
            }
            if ($set) {
                $vals[] = $student_id;
                $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE user_id = ?");
                $stmt->execute($vals);
                $message = 'Student details updated successfully.';
                if ($changed) {
                    track_record($pdo, $student_id, 'registration_edited',
                        'Fields changed before approval: ' . implode(', ', $changed), $admin_username);
                }
                // Refresh
                $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
                $stmt->execute([$student_id]);
                $student = $stmt->fetch();
            }
        } catch (Exception $e) {
            error_log('Registration edit: ' . $e->getMessage());
            $error = 'Error updating student details.';
        }
    }
}

// Course options
try {
    $courses = $pdo->query("SELECT course_name, total_fee, academic_year FROM pepp_courses WHERE status = 'active' ORDER BY course_name")->fetchAll();
} catch (Exception $e) { $courses = []; }
try {
    $years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $years = []; }

$status_badge = $student['status'] === 'approved' ? 'green' : ($student['status'] === 'rejected' ? 'red' : 'amber');
$selected_semesters = array_filter(array_map('trim', explode(',', (string)$student['remaining_semesters'])));
$all_semesters = ['1st Semester','2nd Semester','3rd Semester','4th Semester','5th Semester','6th Semester','7th Semester','8th Semester','Already Completed','Higher Secondary Student'];

$active_page = 'approvals';
$page_title  = 'Registration Details';
$page_sub    = $student['name'] . ' · ' . $student['user_id'];
include 'includes/admin_nav.php';
?>

<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px;">
    <a class="btn btn-outline" href="student-approval.php"><i class="fas fa-arrow-left"></i> Back to Approvals</a>
    <?php if ($student['status'] === 'approved'): ?>
        <a class="btn btn-soft-violet" href="student-details.php?user_id=<?php echo urlencode($student['user_id']); ?>"><i class="fas fa-user"></i> Open Student Profile</a>
    <?php endif; ?>
    <?php 
    $cleanPhone = preg_replace('/\D/', '', $student['whatsapp_country_code'] . $student['whatsapp_number']);
    if (strlen($cleanPhone) === 10) { $cleanPhone = '91' . $cleanPhone; }
    if ($cleanPhone): 
    ?>
        <a class="btn btn-soft-green" href="https://wa.me/<?php echo $cleanPhone; ?>" target="_blank"><i class="fab fa-whatsapp"></i> View Chat</a>
    <?php endif; ?>
    <span class="badge <?php echo $status_badge; ?>" style="align-self:center;">Status: <?php echo ucfirst($student['status']); ?></span>
</div>

<?php if ($message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($message); ?></span></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error); ?></span></div><?php endif; ?>

<!-- ── PROOFS ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon"><i class="fas fa-images"></i></span>
        <h2>Uploaded Documents</h2>
    </div>
    <div class="panel-body" style="display:flex; gap:28px; flex-wrap:wrap;">
        <div>
            <div class="cell-sub" style="margin-bottom:6px; font-weight:700;">STUDENT PHOTO</div>
            <?php if (!empty($student['user_photo'])): ?>
                <a href="<?php echo e($student['user_photo']); ?>" target="_blank">
                    <img class="student-photo" src="<?php echo e($student['user_photo']); ?>" alt="Student photo">
                </a>
            <?php else: ?><span class="badge gray">Not uploaded</span><?php endif; ?>
        </div>
        <div>
            <div class="cell-sub" style="margin-bottom:6px; font-weight:700;">PAYMENT SCREENSHOT</div>
            <?php if (!empty($student['payment_screenshot'])): ?>
                <a href="<?php echo e($student['payment_screenshot']); ?>" target="_blank">
                    <img class="student-photo" src="<?php echo e($student['payment_screenshot']); ?>" alt="Payment screenshot">
                </a>
            <?php else: ?><span class="badge gray">Not uploaded</span><?php endif; ?>
        </div>
        <div>
            <div class="cell-sub" style="margin-bottom:6px; font-weight:700;">REGISTRATION META</div>
            <div class="cell-sub">IP: <?php echo e($student['ip_address'] ?: '-'); ?></div>
            <div class="cell-sub">Submitted: <?php echo $student['submit_datetime'] ? date('d M Y, h:i A', strtotime($student['submit_datetime'])) : '-'; ?></div>
            <div class="cell-sub">Terms agreed: <?php echo e($student['terms_agreed']); ?></div>
            <?php if (!empty($student['referral_code'])): ?>
                <div class="cell-sub" style="margin-top:6px;">Referral Applied: <span class="badge violet" style="font-size:0.75rem; padding: 2px 6px; display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-gift"></i> <?php echo e($student['referral_code']); ?></span> (₹<?php echo number_format((float)$student['coupon_discount'], 0); ?> discount)</div>
            <?php elseif (!empty($student['applied_coupon'])): ?>
                <div class="cell-sub" style="margin-top:6px;">Coupon Applied: <span class="badge green" style="font-size:0.75rem; padding: 2px 6px; display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-ticket"></i> <?php echo e($student['applied_coupon']); ?></span> (₹<?php echo number_format((float)$student['coupon_discount'], 0); ?> discount)</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── EDIT FORM ── -->
<form method="POST">
    <?php echo csrf_field(); ?>

    <div class="panel">
        <div class="panel-head"><span class="head-icon"><i class="fas fa-id-card"></i></span><h2>Personal Information</h2></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="field"><label>Full Name <span class="req">*</span></label>
                    <input type="text" name="name" value="<?php echo e($student['name']); ?>" required></div>
                <div class="field"><label>Gender</label>
                    <select name="gender">
                        <?php foreach (['Male','Female','Other'] as $g): ?>
                            <option value="<?php echo $g; ?>" <?php echo $student['gender'] === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label>Date of Birth</label>
                    <input type="date" name="date_of_birth" value="<?php echo e($student['date_of_birth']); ?>"></div>
                <div class="field"><label>Email <span class="req">*</span></label>
                    <input type="email" name="email" value="<?php echo e($student['email']); ?>" required></div>
                <div class="field"><label>Instagram ID</label>
                    <input type="text" name="instagram_id" value="<?php echo e($student['instagram_id']); ?>"></div>
                <div class="field"><label>How they heard about PEPP</label>
                    <input type="text" name="how_know_pepp" value="<?php echo e($student['how_know_pepp']); ?>"></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-phone"></i></span><h2>Contact Information</h2></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="field"><label>WhatsApp Country Code</label>
                    <input type="text" name="whatsapp_country_code" value="<?php echo e($student['whatsapp_country_code']); ?>"></div>
                <div class="field"><label>WhatsApp Number <span class="req">*</span></label>
                    <input type="text" name="whatsapp_number" value="<?php echo e($student['whatsapp_number']); ?>" required></div>
                <div class="field"><label>Mobile same as WhatsApp</label>
                    <select name="mobile_same_as_whatsapp">
                        <option value="yes" <?php echo $student['mobile_same_as_whatsapp'] === 'yes' ? 'selected' : ''; ?>>Yes</option>
                        <option value="no"  <?php echo $student['mobile_same_as_whatsapp'] === 'no'  ? 'selected' : ''; ?>>No</option>
                    </select></div>
                <div class="field"><label>Mobile Number</label>
                    <input type="text" name="mobile_number" value="<?php echo e($student['mobile_number']); ?>"></div>
                <div class="field"><label>Emergency Contact</label>
                    <input type="text" name="emergency_contact" value="<?php echo e($student['emergency_contact']); ?>"></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-location-dot"></i></span><h2>Address</h2></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="field full"><label>Postal Address</label>
                    <textarea name="postal_address"><?php echo e($student['postal_address']); ?></textarea></div>
                <div class="field"><label>PIN Code</label>
                    <input type="text" name="postal_pincode" value="<?php echo e($student['postal_pincode']); ?>" maxlength="6"></div>
                <div class="field"><label>State</label>
                    <input type="text" name="state" value="<?php echo e($student['state']); ?>"></div>
                <div class="field"><label>District</label>
                    <input type="text" name="district" value="<?php echo e($student['district']); ?>"></div>
                <div class="field"><label>Place / Post Office</label>
                    <input type="text" name="place_post_office" value="<?php echo e($student['place_post_office']); ?>"></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span class="head-icon" style="background:var(--pink-soft);color:var(--pink-ink);"><i class="fas fa-graduation-cap"></i></span><h2>Academic &amp; PEPP Course</h2></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="field"><label>College / School</label>
                    <input type="text" name="college_school" value="<?php echo e($student['college_school']); ?>"></div>
                <div class="field"><label>Current Course</label>
                    <input type="text" name="course" value="<?php echo e($student['course']); ?>"></div>
                <div class="field"><label>University / Board</label>
                    <input type="text" name="university_board" value="<?php echo e($student['university_board']); ?>"></div>
                <div class="field"><label>PEPP Course</label>
                    <select name="pepp_course" id="pepp-course">
                        <?php
                        $found = false;
                        foreach ($courses as $c) {
                            $sel = $student['pepp_course'] === $c['course_name'] ? 'selected' : '';
                            if ($sel) $found = true;
                            echo '<option value="' . e($c['course_name']) . '" ' . $sel . '>' . e($c['course_name']) . ' (₹' . number_format((float)$c['total_fee'], 0) . ')</option>';
                        }
                        if (!$found && $student['pepp_course']) {
                            echo '<option value="' . e($student['pepp_course']) . '" selected>' . e($student['pepp_course']) . '</option>';
                        }
                        ?>
                    </select></div>
                <div class="field"><label>Academic Year</label>
                    <select name="pepp_academic_year" id="pepp-academic-year">
                        <?php
                        $foundY = false;
                        foreach ($years as $y) {
                            $sel = $student['pepp_academic_year'] === $y ? 'selected' : '';
                            if ($sel) $foundY = true;
                            echo '<option value="' . e($y) . '" ' . $sel . '>' . e($y) . '</option>';
                        }
                        if (!$foundY && $student['pepp_academic_year']) {
                            echo '<option value="' . e($student['pepp_academic_year']) . '" selected>' . e($student['pepp_academic_year']) . '</option>';
                        }
                        ?>
                    </select></div>
                <div class="field full">
                    <label>Remaining Semesters</label>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <?php foreach ($all_semesters as $sem): ?>
                            <label style="display:inline-flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;background:var(--card);border-radius:50px;padding:6px 13px;cursor:pointer;text-transform:none;letter-spacing:0;color:var(--foreground);">
                                <input type="checkbox" name="remaining_semesters[]" value="<?php echo e($sem); ?>" <?php echo in_array($sem, $selected_semesters) ? 'checked' : ''; ?> style="width:auto;accent-color:var(--accent);">
                                <?php echo e($sem); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-wallet"></i></span><h2>Registration Payment</h2></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="field"><label>Paid Amount (₹)</label>
                    <input type="number" name="paid_amount" min="0" step="0.01" value="<?php echo e($student['paid_amount']); ?>"></div>
                <div class="field"><label>Paid Date</label>
                    <input type="date" name="paid_date" value="<?php echo e($student['paid_date']); ?>"></div>
            </div>
        </div>
    </div>

    <div style="display:flex; gap:10px; justify-content:flex-end;">
        <a class="btn btn-outline" href="student-approval.php">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Changes</button>
    </div>
</form>

<?php
$extra_scripts = "<script>
const activeCourses = " . json_encode($courses, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ";
const courseSel = document.getElementById('pepp-course');
const yearSel = document.getElementById('pepp-academic-year');

function filterCourses() {
    if (!courseSel || !yearSel) return;
    const selectedYear = yearSel.value;
    const currentSelectedCourse = courseSel.value || \"" . htmlspecialchars($student['pepp_course'] ?? '') . "\";
    
    courseSel.innerHTML = '';
    
    const filtered = activeCourses.filter(function(c) {
        return !selectedYear || c.academic_year === selectedYear || c.academic_year === 'All years';
    });
    
    let foundCurrent = false;
    filtered.forEach(function(c) {
        if (c.course_name === currentSelectedCourse) foundCurrent = true;
    });
    if (!foundCurrent && currentSelectedCourse) {
        const opt = document.createElement('option');
        opt.value = currentSelectedCourse;
        opt.textContent = currentSelectedCourse;
        opt.selected = true;
        courseSel.appendChild(opt);
    }

    filtered.forEach(function(c) {
        const opt = document.createElement('option');
        opt.value = c.course_name;
        opt.textContent = c.course_name + ' (₹' + Number(c.total_fee).toLocaleString('en-IN') + ')';
        if (c.course_name === currentSelectedCourse) {
            opt.selected = true;
        }
        courseSel.appendChild(opt);
    });
}

if (yearSel) {
    yearSel.addEventListener('change', filterCourses);
}
filterCourses();
</script>";
include 'includes/admin_footer.php';
?>
