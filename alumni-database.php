<?php
require_once 'includes/auth.php';
require_permission('alumni');
require_once 'includes/file_helper.php';

/* Alumni Database - Admins manage past students for the referral
   program's verification. Add individually or bulk-import CSV. Duplicate
   mobile/email is folded into the secondary mobile/email of the existing row. */

$success_message = ''; $error_message = '';

function check_and_update_alumni_schema($pdo) {
    try {
        $stmt = $pdo->query("DESCRIBE alumni");
        $existing_cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $existing_cols = array_map('strtolower', $existing_cols);
        
        $columns_to_ensure = [
            'gender' => "ENUM('Male','Female','Other') DEFAULT NULL",
            'date_of_birth' => "DATE DEFAULT NULL",
            'whatsapp_country_code' => "VARCHAR(10) DEFAULT '+91'",
            'whatsapp_number' => "VARCHAR(20) DEFAULT NULL",
            'mobile_same_as_whatsapp' => "ENUM('yes','no') DEFAULT 'yes'",
            'mobile_number' => "VARCHAR(20) DEFAULT NULL",
            'emergency_contact' => "VARCHAR(20) DEFAULT NULL",
            'college_school' => "VARCHAR(255) DEFAULT NULL",
            'course' => "VARCHAR(255) DEFAULT NULL",
            'university_board' => "VARCHAR(255) DEFAULT NULL",
            'remaining_semesters' => "TEXT DEFAULT NULL",
            'postal_address' => "TEXT DEFAULT NULL",
            'postal_pincode' => "VARCHAR(10) DEFAULT NULL",
            'state' => "VARCHAR(100) DEFAULT NULL",
            'district' => "VARCHAR(100) DEFAULT NULL",
            'place_post_office' => "VARCHAR(100) DEFAULT NULL",
            'pepp_course' => "VARCHAR(255) DEFAULT NULL",
            'pepp_academic_year' => "VARCHAR(20) DEFAULT NULL",
            'paid_amount' => "DECIMAL(10,2) DEFAULT 0.00",
            'paid_date' => "DATE DEFAULT NULL",
            'payment_screenshot' => "VARCHAR(255) DEFAULT NULL",
            'user_photo' => "VARCHAR(255) DEFAULT NULL",
            'instagram_id' => "VARCHAR(100) DEFAULT NULL",
            'how_know_pepp' => "VARCHAR(255) DEFAULT NULL",
            'terms_agreed' => "ENUM('yes','no') DEFAULT 'no'",
            'user_id' => "VARCHAR(20) DEFAULT NULL",
            'ip_address' => "VARCHAR(45) DEFAULT NULL",
            'entry_datetime' => "DATETIME DEFAULT NULL",
            'submit_datetime' => "DATETIME DEFAULT NULL",
            'time_spent' => "INT(11) DEFAULT 0",
            'isp' => "VARCHAR(255) DEFAULT NULL",
            'as_name' => "VARCHAR(255) DEFAULT NULL",
            'network_type' => "VARCHAR(50) DEFAULT NULL",
            'country' => "VARCHAR(100) DEFAULT NULL",
            'region' => "VARCHAR(100) DEFAULT NULL",
            'device_details' => "TEXT DEFAULT NULL",
            'os_details' => "TEXT DEFAULT NULL",
            'status' => "ENUM('pending','approved','rejected') DEFAULT 'pending'",
            'approved_by' => "VARCHAR(255) DEFAULT NULL",
            'approval_date' => "DATETIME DEFAULT NULL",
            'payment_mode' => "ENUM('Online','Cash','100% Scholarship','Pay later') DEFAULT NULL",
            'payment_account_id' => "INT(11) DEFAULT NULL",
            'discount_amount' => "DECIMAL(10,2) DEFAULT 0.00",
            'discount_remark' => "TEXT DEFAULT NULL",
            'payment_plan' => "VARCHAR(50) DEFAULT 'One Time'",
            'peppkit_eligibility' => "ENUM('Eligible','Not Eligible') DEFAULT 'Not Eligible'",
            'student_status' => "ENUM('active','inactive','suspended','completed') DEFAULT 'active'",
            'joined_date' => "DATE DEFAULT NULL",
            'peppkit_eligible' => "ENUM('Eligible','Not Eligible') DEFAULT 'Not Eligible'",
            'total_fee' => "DECIMAL(10,2) DEFAULT 0.00",
            'course_expiry_date' => "DATE DEFAULT NULL",
            'course_access_provided' => "ENUM('yes','no') DEFAULT 'no'",
            'course_status' => "ENUM('active','completed','suspended') DEFAULT 'active'",
            'course_end_date' => "DATE DEFAULT NULL",
            'phone' => "VARCHAR(20) DEFAULT NULL",
            'onboarding_status' => "ENUM('pending','completed') DEFAULT 'pending'",
            'remaining_semester_exams' => "TEXT DEFAULT NULL",
            'last_visit_ip' => "VARCHAR(45) DEFAULT NULL",
            'last_visit_location' => "VARCHAR(255) DEFAULT NULL",
            'last_visit_isp' => "VARCHAR(255) DEFAULT NULL",
            'last_visit_as' => "VARCHAR(255) DEFAULT NULL",
            'last_visit_time' => "DATETIME DEFAULT NULL",
            'course_duration_date' => "DATE DEFAULT NULL",
            'device_type' => "VARCHAR(50) DEFAULT NULL",
            'device_name' => "VARCHAR(100) DEFAULT NULL",
            'device_brand' => "VARCHAR(50) DEFAULT NULL",
            'os' => "VARCHAR(50) DEFAULT NULL",
            'os_version' => "VARCHAR(50) DEFAULT NULL",
            'browser' => "VARCHAR(50) DEFAULT NULL",
            'browser_version' => "VARCHAR(50) DEFAULT NULL",
            'applied_coupon' => "VARCHAR(40) DEFAULT NULL",
            'referral_code' => "VARCHAR(40) DEFAULT NULL",
            'coupon_discount' => "DECIMAL(10,2) DEFAULT 0.00",
            'academic_track_after_pepp' => "TEXT DEFAULT NULL",
            'current_profession_details' => "TEXT DEFAULT NULL",
            'profile_photo' => "VARCHAR(255) DEFAULT NULL",
            'is_verified' => "TINYINT(1) NOT NULL DEFAULT 0",
            'synced_at' => "DATETIME DEFAULT NULL"
        ];
        
        $to_add = [];
        foreach ($columns_to_ensure as $col => $def) {
            if (!in_array(strtolower($col), $existing_cols)) {
                $to_add[] = "ADD COLUMN `$col` $def";
            }
        }
        
        if (!empty($to_add)) {
            $sql = "ALTER TABLE alumni " . implode(", ", $to_add);
            $pdo->exec($sql);
        }
    } catch (Exception $e) {
        error_log("Alumni schema self-healing error: " . $e->getMessage());
    }
}

function alumni_ready($pdo) {
    try { return (bool)$pdo->query("SHOW TABLES LIKE 'alumni'")->fetchColumn(); }
    catch (Exception $e) { return false; }
}
if (!alumni_ready($pdo)) {
    $active_page = 'alumni'; $page_title = 'Alumni Database'; $page_sub = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>Run <strong>database-update-8.sql</strong> once in phpMyAdmin, then reload.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

check_and_update_alumni_schema($pdo);

function auto_copy_completed_years_to_alumni($pdo) {
    try {
        $ended_years_stmt = $pdo->query("SELECT year FROM academic_years WHERE end_date IS NOT NULL AND end_date <= CURDATE()");
        $ended_years = $ended_years_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($ended_years)) {
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($ended_years), '?'));
        $sql = "SELECT u.* FROM users u 
                LEFT JOIN alumni a ON a.user_id = u.user_id 
                WHERE u.pepp_academic_year IN ($placeholders) AND u.status = 'approved' AND a.user_id IS NULL";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ended_years);
        $students_to_copy = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($students_to_copy)) {
            return;
        }
        
        $columns_to_copy = [
            'gender', 'date_of_birth', 'whatsapp_country_code', 'whatsapp_number', 'mobile_same_as_whatsapp',
            'mobile_number', 'emergency_contact', 'email', 'college_school', 'course', 'university_board',
            'remaining_semesters', 'postal_address', 'postal_pincode', 'state', 'district', 'place_post_office',
            'pepp_course', 'pepp_academic_year', 'paid_amount', 'paid_date', 'user_photo', 'instagram_id',
            'how_know_pepp', 'terms_agreed', 'user_id', 'ip_address', 'entry_datetime', 'submit_datetime',
            'time_spent', 'isp', 'as_name', 'network_type', 'country', 'region', 'device_details', 'os_details',
            'status', 'approved_by', 'approval_date', 'payment_mode', 'payment_account_id', 'discount_amount',
            'discount_remark', 'payment_plan', 'peppkit_eligibility', 'student_status', 'joined_date',
            'peppkit_eligible', 'total_fee', 'course_expiry_date', 'course_access_provided', 'course_status',
            'course_end_date', 'phone', 'onboarding_status', 'remaining_semester_exams', 'last_visit_ip',
            'last_visit_location', 'last_visit_isp', 'last_visit_as', 'last_visit_time', 'course_duration_date',
            'device_type', 'device_name', 'device_brand', 'os', 'os_version', 'browser', 'browser_version',
            'applied_coupon', 'referral_code', 'coupon_discount'
        ];
        
        $pdo->beginTransaction();
        
        $insert_fields = array_merge($columns_to_copy, [
            'payment_screenshot', 'academic_year', 'course_name', 'mobile', 'secondary_mobile', 'profile_photo', 'created_by', 'created_at'
        ]);
        
        $field_placeholders = implode(',', array_fill(0, count($insert_fields), '?'));
        $insert_sql = "INSERT INTO alumni (" . implode(',', array_map(function($f){ return "`$f`"; }, $insert_fields)) . ") VALUES ($field_placeholders)";
        $insert_stmt = $pdo->prepare($insert_sql);
        
        $copied_count = 0;
        foreach ($students_to_copy as $student) {
            $insert_values = [];
            foreach ($columns_to_copy as $col) {
                $insert_values[] = $student[$col];
            }
            $insert_values[] = null;
            $insert_values[] = $student['pepp_academic_year'];
            $insert_values[] = $student['pepp_course'];
            $insert_values[] = $student['whatsapp_number'] ?: $student['mobile_number'];
            $insert_values[] = $student['mobile_number'];
            $insert_values[] = $student['user_photo'];
            $insert_values[] = 'system_auto_copy';
            $insert_values[] = date('Y-m-d H:i:s');
            
            $insert_stmt->execute($insert_values);
            $copied_count++;
        }
        
        $pdo->commit();
        if ($copied_count > 0) {
            log_admin_activity($pdo, 'system', 'alumni_auto_copied', "Auto-copied $copied_count approved students from ended academic years to alumni database.");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Alumni auto-copy failed: " . $e->getMessage());
    }
}

auto_copy_completed_years_to_alumni($pdo);

function norm_phone($p) { $p = preg_replace('/\D/', '', (string)$p); return $p !== '' ? substr($p, -10) : ''; }

/** Insert or fold-in a duplicate. Returns 'added' | 'merged' | 'skip'. */
function alumni_upsert($pdo, $d, $by) {
    $mobile = trim($d['mobile'] ?? '');
    if ($mobile === '') return 'skip';
    $m10 = norm_phone($mobile);
    $email = strtolower(trim($d['email'] ?? ''));

    // Find an existing row by mobile (primary or secondary) or by email.
    // We pre-filter on the last 6 mobile digits in SQL, then confirm in PHP -
    // this avoids any cross-collation string comparison in SQL entirely.
    $exist = null;
    if ($m10 !== '' && strlen($m10) >= 6) {
        $tail = '%' . substr($m10, -6);
        $stmt = $pdo->prepare("SELECT * FROM alumni WHERE mobile LIKE ? OR secondary_mobile LIKE ?");
        $stmt->execute([$tail, $tail]);
        foreach ($stmt->fetchAll() as $row) {
            if (norm_phone($row['mobile']) === $m10 || norm_phone($row['secondary_mobile']) === $m10) { $exist = $row; break; }
        }
    }
    if (!$exist && $email !== '') {
        // Match email case-insensitively in PHP to avoid collation conflicts
        $stmt = $pdo->prepare("SELECT * FROM alumni WHERE email LIKE ? OR secondary_email LIKE ?");
        $stmt->execute(['%' . $email, '%' . $email]);
        foreach ($stmt->fetchAll() as $row) {
            if (strtolower((string)$row['email']) === $email || strtolower((string)$row['secondary_email']) === $email) { $exist = $row; break; }
        }
    }

    if ($exist) {
        // Fold the new mobile/email into secondary slots if not already present
        $updates = []; $params = [];
        $existM10 = norm_phone($exist['mobile']); $existSecM10 = norm_phone($exist['secondary_mobile']);
        if ($m10 !== '' && $m10 !== $existM10 && $m10 !== $existSecM10 && empty($exist['secondary_mobile'])) {
            $updates[] = "secondary_mobile = ?"; $params[] = $mobile;
        }
        $existEmail = strtolower((string)$exist['email']); $existSec = strtolower((string)$exist['secondary_email']);
        if ($email !== '' && $email !== $existEmail && $email !== $existSec && empty($exist['secondary_email'])) {
            $updates[] = "secondary_email = ?"; $params[] = $email;
        }
        if ($updates) {
            $params[] = $exist['id'];
            $pdo->prepare("UPDATE alumni SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            return 'merged';
        }
        return 'skip';
    }

    $stmt = $pdo->prepare("INSERT INTO alumni (name, academic_year, course_name, email, secondary_email, mobile, secondary_mobile, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        trim($d['name'] ?? ''), trim($d['academic_year'] ?? '') ?: null, trim($d['course_name'] ?? '') ?: null,
        $email ?: null, strtolower(trim($d['secondary_email'] ?? '')) ?: null,
        $mobile, trim($d['secondary_mobile'] ?? '') ?: null, $by
    ]);
    return 'added';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'toggle_alumni_visibility') {
                header('Content-Type: application/json');
                $val = ($_POST['value'] ?? '') === 'ON' ? 'ON' : 'OFF';
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_settings WHERE setting_name = 'alumni_public_visibility'");
                    $stmt->execute();
                    if ($stmt->fetchColumn() > 0) {
                        $stmt = $pdo->prepare("UPDATE admin_settings SET setting_value = ?, updated_at = NOW() WHERE setting_name = 'alumni_public_visibility'");
                        $stmt->execute([$val]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, created_at, updated_at) VALUES ('alumni_public_visibility', ?, NOW(), NOW())");
                        $stmt->execute([$val]);
                    }
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
                exit;
            }

            if ($action === 'add_alumni') {
                if (trim($_POST['mobile'] ?? '') === '') {
                    $error_message = 'Mobile number is required.';
                } else {
                    $res = alumni_upsert($pdo, $_POST, $admin_username);
                    log_admin_activity($pdo, $admin_username, 'alumni_added', "Alumni {$res}: " . trim($_POST['name'] ?? ''));
                    $success_message = $res === 'merged' ? 'Matched an existing alumnus - added as secondary contact.' : ($res === 'added' ? 'Alumnus added.' : 'Duplicate - nothing to add.');
                }
            } elseif ($action === 'import') {
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    $error_message = 'Please choose a CSV file.';
                } else {
                    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['csv', 'txt'], true)) {
                        $error_message = 'Please upload a .csv file (Excel: Save As → CSV).';
                    } else {
                        // Large files: give ourselves room and handle Windows/Mac line endings.
                        @set_time_limit(0);
                        @ini_set('memory_limit', '512M');
                        @ini_set('auto_detect_line_endings', '1');

                        $h = fopen($_FILES['file']['tmp_name'], 'r');
                        // Skip a UTF-8 BOM if present
                        if ($h) {
                            $bom = fread($h, 3);
                            if ($bom !== "\xEF\xBB\xBF") rewind($h);
                        }
                        $headers = $h ? fgetcsv($h) : null;
                        if (!$headers) { $error_message = 'Could not read the file.'; }
                        else {
                            $norm = function ($s) { return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$s))); };
                            $alias = [
                                'name'=>'name','fullname'=>'name','studentname'=>'name',
                                'academicyear'=>'academic_year','year'=>'academic_year','peppacademicyear'=>'academic_year','batch'=>'academic_year',
                                'coursename'=>'course_name','course'=>'course_name','peppcourse'=>'course_name',
                                'email'=>'email','emailid'=>'email','primaryemail'=>'email','emailaddress'=>'email',
                                'secondaryemail'=>'secondary_email','secondaryemailid'=>'secondary_email','altemail'=>'secondary_email',
                                'mobile'=>'mobile','mobilenumber'=>'mobile','phone'=>'mobile','whatsapp'=>'mobile','phonenumber'=>'mobile','contact'=>'mobile',
                                'secondarymobile'=>'secondary_mobile','secondarymobilenumber'=>'secondary_mobile','altmobile'=>'secondary_mobile','secondaryphone'=>'secondary_mobile',
                            ];
                            $cols = [];
                            foreach ($headers as $i => $hd) { $k = $alias[$norm($hd)] ?? null; if ($k && !in_array($k, $cols, true)) $cols[$i] = $k; }
                            if (!in_array('mobile', $cols, true)) {
                                $error_message = 'The file must have a Mobile column. Found columns: ' . implode(', ', array_map('trim', $headers));
                            } else {
                                $added = $merged = $skipped = $errors = 0;
                                $seen_mobiles = [];   // in-file dedup (fast, avoids re-querying)
                                $rownum = 1;

                                // Prepared statements reused across the loop
                                $findStmt = $pdo->prepare("SELECT id, mobile, secondary_mobile, email, secondary_email FROM alumni WHERE
                                    RIGHT(REPLACE(REPLACE(mobile,' ',''),'-',''),10) = ?
                                    OR (secondary_mobile IS NOT NULL AND RIGHT(REPLACE(REPLACE(secondary_mobile,' ',''),'-',''),10) = ?)
                                    LIMIT 1");
                                $insStmt = $pdo->prepare("INSERT INTO alumni (name, academic_year, course_name, email, secondary_email, mobile, secondary_mobile, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");

                                $batch = 0;
                                $pdo->beginTransaction();
                                while (($line = fgetcsv($h)) !== false) {
                                    $rownum++;
                                    if (count(array_filter($line, function ($v) { return trim((string)$v) !== ''; })) === 0) continue;
                                    $row = []; foreach ($cols as $i => $k) $row[$k] = isset($line[$i]) ? trim($line[$i]) : '';
                                    $mobile = $row['mobile'] ?? '';
                                    if ($mobile === '') { $skipped++; continue; }
                                    $m10 = norm_phone($mobile);

                                    try {
                                        // In-file duplicate by mobile → fold into existing inserted row's secondary
                                        if ($m10 !== '' && isset($seen_mobiles[$m10])) {
                                            $existId = $seen_mobiles[$m10];
                                            $sec = trim($row['secondary_mobile'] ?? '');
                                            $secM10 = norm_phone($sec);
                                            if ($secM10 !== '' && $secM10 !== $m10) {
                                                $pdo->prepare("UPDATE alumni SET secondary_mobile = COALESCE(NULLIF(secondary_mobile,''), ?) WHERE id = ?")->execute([$sec, $existId]);
                                            }
                                            $merged++;
                                        } else {
                                            // Check DB for an existing alumnus by mobile (primary/secondary)
                                            $findStmt->execute([$m10, $m10]);
                                            $exist = $findStmt->fetch();
                                            if ($exist) {
                                                $upd = []; $p = [];
                                                if ($m10 !== '' && norm_phone($exist['mobile']) !== $m10 && norm_phone($exist['secondary_mobile']) !== $m10 && empty($exist['secondary_mobile'])) {
                                                    $upd[] = "secondary_mobile = ?"; $p[] = $mobile;
                                                }
                                                $em = strtolower($row['email'] ?? '');
                                                if ($em !== '' && strtolower((string)$exist['email']) !== $em && strtolower((string)$exist['secondary_email']) !== $em && empty($exist['secondary_email'])) {
                                                    $upd[] = "secondary_email = ?"; $p[] = $em;
                                                }
                                                if ($upd) { $p[] = $exist['id']; $pdo->prepare("UPDATE alumni SET " . implode(', ', $upd) . " WHERE id = ?")->execute($p); $merged++; }
                                                else { $skipped++; }
                                                if ($m10 !== '') $seen_mobiles[$m10] = $exist['id'];
                                            } else {
                                                $insStmt->execute([
                                                    mb_substr($row['name'] ?? '', 0, 150),
                                                    mb_substr($row['academic_year'] ?? '', 0, 20) ?: null,
                                                    mb_substr($row['course_name'] ?? '', 0, 255) ?: null,
                                                    mb_substr(strtolower($row['email'] ?? ''), 0, 190) ?: null,
                                                    mb_substr(strtolower($row['secondary_email'] ?? ''), 0, 190) ?: null,
                                                    mb_substr($mobile, 0, 20),
                                                    mb_substr($row['secondary_mobile'] ?? '', 0, 20) ?: null,
                                                    $admin_username
                                                ]);
                                                if ($m10 !== '') $seen_mobiles[$m10] = $pdo->lastInsertId();
                                                $added++;
                                            }
                                        }
                                    } catch (Exception $rowErr) {
                                        // Skip the bad row, keep going
                                        $errors++;
                                        error_log("Alumni import row {$rownum}: " . $rowErr->getMessage());
                                    }

                                    // Commit in batches of 500 to keep transactions small
                                    if (++$batch >= 500) {
                                        $pdo->commit(); $pdo->beginTransaction(); $batch = 0;
                                    }
                                }
                                if ($pdo->inTransaction()) $pdo->commit();

                                log_admin_activity($pdo, $admin_username, 'alumni_imported', "Imported alumni: {$added} added, {$merged} merged, {$skipped} skipped, {$errors} errors");
                                $success_message = "Import complete - {$added} added, {$merged} merged, {$skipped} skipped" . ($errors ? ", {$errors} rows had errors (skipped)" : "") . ".";
                            }
                        }
                        if ($h) fclose($h);
                    }
                }
            } elseif ($action === 'edit_alumni') {
                $id = (int)($_POST['alumni_id'] ?? 0);
                if ($id && trim($_POST['mobile'] ?? '') !== '') {
                    $profile_photo = $_POST['existing_profile_photo'] ?? null;
                    $uploaded_photo = handle_file_upload_with_replace('profile_photo', 'alumni', $profile_photo, ['jpg', 'jpeg', 'png', 'webp']);
                    if ($uploaded_photo !== null) {
                        $profile_photo = $uploaded_photo;
                    }

                    $fields_to_update = [
                        'name' => mb_substr(trim($_POST['name'] ?? ''), 0, 150),
                        'academic_year' => mb_substr(trim($_POST['academic_year'] ?? ''), 0, 20) ?: null,
                        'course_name' => mb_substr(trim($_POST['course_name'] ?? ''), 0, 255) ?: null,
                        'email' => mb_substr(strtolower(trim($_POST['email'] ?? '')), 0, 190) ?: null,
                        'secondary_email' => mb_substr(strtolower(trim($_POST['secondary_email'] ?? '')), 0, 190) ?: null,
                        'mobile' => mb_substr(trim($_POST['mobile'] ?? ''), 0, 20),
                        'secondary_mobile' => mb_substr(trim($_POST['secondary_mobile'] ?? ''), 0, 20) ?: null,
                        
                        'gender' => $_POST['gender'] ?: null,
                        'date_of_birth' => $_POST['date_of_birth'] ?: null,
                        'whatsapp_country_code' => $_POST['whatsapp_country_code'] ?: '+91',
                        'whatsapp_number' => $_POST['whatsapp_number'] ?: null,
                        'mobile_same_as_whatsapp' => $_POST['mobile_same_as_whatsapp'] ?? 'yes',
                        'mobile_number' => $_POST['mobile_number'] ?: null,
                        'emergency_contact' => $_POST['emergency_contact'] ?: null,
                        'college_school' => $_POST['college_school'] ?: null,
                        'course' => $_POST['course'] ?: null,
                        'university_board' => $_POST['university_board'] ?: null,
                        'remaining_semesters' => $_POST['remaining_semesters'] ?: null,
                        'postal_address' => $_POST['postal_address'] ?: null,
                        'postal_pincode' => $_POST['postal_pincode'] ?: null,
                        'state' => $_POST['state'] ?: null,
                        'district' => $_POST['district'] ?: null,
                        'place_post_office' => $_POST['place_post_office'] ?: null,
                        'pepp_course' => $_POST['course_name'] ?? null,
                        'pepp_academic_year' => $_POST['academic_year'] ?? null,
                        
                        'paid_amount' => (float)($_POST['paid_amount'] ?? 0),
                        'paid_date' => $_POST['paid_date'] ?: null,
                        'instagram_id' => $_POST['instagram_id'] ?: null,
                        'how_know_pepp' => $_POST['how_know_pepp'] ?: null,
                        'terms_agreed' => $_POST['terms_agreed'] ?? 'no',
                        'user_id' => $_POST['user_id'] ?: null,
                        'status' => $_POST['status'] ?? 'pending',
                        'approved_by' => $_POST['approved_by'] ?: null,
                        'approval_date' => $_POST['approval_date'] ?: null,
                        'payment_mode' => $_POST['payment_mode'] ?: null,
                        'payment_account_id' => $_POST['payment_account_id'] ? (int)$_POST['payment_account_id'] : null,
                        'discount_amount' => (float)($_POST['discount_amount'] ?? 0),
                        'discount_remark' => $_POST['discount_remark'] ?: null,
                        'payment_plan' => $_POST['payment_plan'] ?: 'One Time',
                        'peppkit_eligibility' => $_POST['peppkit_eligibility'] ?: 'Not Eligible',
                        'student_status' => $_POST['student_status'] ?: 'active',
                        'joined_date' => $_POST['joined_date'] ?: null,
                        'peppkit_eligible' => $_POST['peppkit_eligible'] ?: 'Not Eligible',
                        'total_fee' => (float)($_POST['total_fee'] ?? 0),
                        'course_expiry_date' => $_POST['course_expiry_date'] ?: null,
                        'course_access_provided' => $_POST['course_access_provided'] ?? 'no',
                        'course_status' => $_POST['course_status'] ?? 'active',
                        'course_end_date' => $_POST['course_end_date'] ?: null,
                        'phone' => $_POST['phone'] ?: null,
                        'onboarding_status' => $_POST['onboarding_status'] ?? 'pending',
                        'remaining_semester_exams' => $_POST['remaining_semester_exams'] ?: null,
                        
                        'academic_track_after_pepp' => $_POST['academic_track_after_pepp'] ?: null,
                        'current_profession_details' => $_POST['current_profession_details'] ?: null,
                        'profile_photo' => $profile_photo
                    ];

                    $set_sql = []; $vals = [];
                    foreach ($fields_to_update as $col => $val) {
                        $set_sql[] = "`$col` = ?";
                        $vals[] = $val;
                    }
                    $vals[] = $id;

                    $pdo->prepare("UPDATE alumni SET " . implode(', ', $set_sql) . " WHERE id = ?")
                        ->execute($vals);

                    log_admin_activity($pdo, $admin_username, 'alumni_edited', "Edited alumni #{$id} ({$_POST['name']}) with all fields");
                    $success_message = 'Alumnus updated.';
                } else { $error_message = 'Mobile number is required.'; }
            } elseif ($action === 'delete_alumni') {
                $id = (int)($_POST['alumni_id'] ?? 0);
                $pdo->prepare("DELETE FROM alumni WHERE id = ?")->execute([$id]);
                log_admin_activity($pdo, $admin_username, 'alumni_deleted', "Deleted alumni #{$id}");
                $success_message = 'Alumnus deleted.';
            } elseif ($action === 'batch_update_course') {
                $new_course = trim($_POST['new_course_name'] ?? '');
                if ($new_course === '') {
                    $error_message = 'Please select a course.';
                } else {
                    $target = $_POST['target_type'] ?? 'selected';
                    if ($target === 'selected') {
                        $ids = array_filter(array_map('intval', explode(',', (string)($_POST['selected_ids'] ?? ''))));
                        if (empty($ids)) {
                            $error_message = 'No alumni selected.';
                        } else {
                            $placeholders = implode(',', array_fill(0, count($ids), '?'));
                            $stmt = $pdo->prepare("UPDATE alumni SET course_name = ?, pepp_course = ? WHERE id IN ($placeholders)");
                            $stmt->execute(array_merge([$new_course, $new_course], $ids));
                            log_admin_activity($pdo, $admin_username, 'alumni_batch_course_update', "Updated course of " . count($ids) . " selected alumni to: $new_course");
                            $success_message = 'Course updated for selected alumni.';
                        }
                    } else {
                        $b_where = []; $b_params = [$new_course, $new_course];
                        $b_q = trim($_POST['filter_q'] ?? '');
                        $b_course_q = trim($_POST['filter_course_q'] ?? '');
                        $b_year = trim($_POST['filter_year'] ?? '');
                        $b_course = trim($_POST['filter_course'] ?? '');
                        $b_email = $_POST['filter_email'] ?? '';
                        
                        if ($b_q !== '') {
                            $b_where[] = "(name LIKE ? OR mobile LIKE ? OR secondary_mobile LIKE ? OR email LIKE ? OR secondary_email LIKE ? OR course_name LIKE ?)";
                            $like = "%$b_q%"; array_push($b_params, $like, $like, $like, $like, $like, $like);
                        }
                        if ($b_course_q !== '') {
                            $b_where[] = "course_name LIKE ?";
                            $b_params[] = "%$b_course_q%";
                        }
                        if ($b_year !== '')   { $b_where[] = "academic_year = ?"; $b_params[] = $b_year; }
                        if ($b_course !== '') { $b_where[] = "course_name = ?"; $b_params[] = $b_course; }
                        if ($b_email === 'yes') { $b_where[] = "(email IS NOT NULL AND email <> '')"; }
                        elseif ($b_email === 'no') { $b_where[] = "(email IS NULL OR email = '')"; }
                        
                        $b_wsql = $b_where ? ('WHERE ' . implode(' AND ', $b_where)) : '';
                        $sql = "UPDATE alumni SET course_name = ?, pepp_course = ? $b_wsql";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($b_params);
                        $affected = $stmt->rowCount();
                        
                        log_admin_activity($pdo, $admin_username, 'alumni_batch_course_update', "Updated course of $affected filtered alumni to: $new_course");
                        $success_message = "Course updated for all $affected filtered alumni.";
                    }
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Exception $e2) {} }
            error_log('Alumni DB: ' . $e->getMessage());
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

$all_years = [];
try { $all_years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

$inactive_years = [];
try { $inactive_years = $pdo->query("SELECT year FROM academic_years WHERE status='inactive' ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

$active_courses_list = [];
try {
    $active_courses_list = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses WHERE status='active' ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$f_q = trim($_GET['q'] ?? '');
$f_course_q = trim($_GET['f_course_q'] ?? '');
$f_year = trim($_GET['fyear'] ?? '');
$f_courses = $_GET['fcourse'] ?? [];
if (!is_array($f_courses)) {
    $f_courses = $f_courses !== '' ? [$f_courses] : [];
}
$f_courses = array_filter(array_map('strval', $f_courses));
$f_email = $_GET['femail'] ?? '';   // '', 'yes', 'no'
$sort_by = $_GET['sort_by'] ?? '';

// Distinct years & courses present in the alumni table (for filter dropdowns)
$alumni_years = []; $alumni_courses = [];
try {
    $alumni_years = $pdo->query("SELECT DISTINCT academic_year FROM alumni WHERE academic_year IS NOT NULL AND academic_year<>'' ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $alumni_courses = $pdo->query("SELECT DISTINCT course_name FROM alumni WHERE course_name IS NOT NULL AND course_name<>'' ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$total_all_alumni = 0;
try {
    $total_all_alumni = (int)$pdo->query("SELECT COUNT(*) FROM alumni")->fetchColumn();
} catch (Exception $e) {}

$page = max(1, (int)($_GET['page'] ?? 1)); $per = 30;
$total = 0; $rows = [];

// Build a parameterised WHERE from the active filters
$where = []; $params = [];
if ($f_q !== '') {
    $where[] = "(name LIKE ? OR mobile LIKE ? OR secondary_mobile LIKE ? OR email LIKE ? OR secondary_email LIKE ? OR course_name LIKE ?)";
    $like = "%$f_q%"; array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($f_course_q !== '') {
    $where[] = "course_name LIKE ?";
    $params[] = "%$f_course_q%";
}
if ($f_year !== '')   { $where[] = "academic_year = ?"; $params[] = $f_year; }
if (!empty($f_courses)) {
    $placeholders = implode(',', array_fill(0, count($f_courses), '?'));
    $where[] = "course_name IN ($placeholders)";
    foreach ($f_courses as $c) {
        $params[] = $c;
    }
}
if ($f_email === 'yes') { $where[] = "(email IS NOT NULL AND email <> '')"; }
elseif ($f_email === 'no') { $where[] = "(email IS NULL OR email = '')"; }
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$order_by = "ORDER BY id DESC";
if ($sort_by === 'verified_desc') {
    $order_by = "ORDER BY is_verified DESC, id DESC";
} elseif ($sort_by === 'verified_asc') {
    $order_by = "ORDER BY is_verified ASC, id DESC";
} elseif ($sort_by === 'tracks_pending') {
    $order_by = "ORDER BY (academic_track_after_pepp IS NULL OR academic_track_after_pepp = '' OR academic_track_after_pepp = '[]') DESC, id DESC";
} elseif ($sort_by === 'tracks_updated') {
    $order_by = "ORDER BY (
        (academic_track_after_pepp IS NOT NULL AND academic_track_after_pepp <> '' AND academic_track_after_pepp <> '[]') 
        OR 
        (current_profession_details IS NOT NULL AND current_profession_details <> '' AND current_profession_details <> '[]')
    ) DESC, id DESC";
}

try {
    $cstmt = $pdo->prepare("SELECT COUNT(*) FROM alumni $wsql");
    $cstmt->execute($params); $total = (int)$cstmt->fetchColumn();
    $lstmt = $pdo->prepare("SELECT * FROM alumni $wsql $order_by LIMIT $per OFFSET " . (($page-1)*$per));
    $lstmt->execute($params); $rows = $lstmt->fetchAll();
} catch (Exception $e) { error_log('Alumni list: ' . $e->getMessage()); }
$total_pages = max(1, (int)ceil($total / $per));

$alumni_public_visibility = 'ON';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'alumni_public_visibility'");
    $stmt->execute();
    $alumni_public_visibility = $stmt->fetchColumn() ?: 'ON';
} catch (Exception $e) {}

$active_page = 'alumni';
$page_title  = 'Alumni Database';
$page_sub    = 'Past students - used to verify PEPPians';
include 'includes/admin_nav.php';
?>

<style>
.switch {
  position: relative;
  display: inline-block;
  width: 50px;
  height: 24px;
}
.switch input { 
  opacity: 0;
  width: 0;
  height: 0;
}
.slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: #cbd5e1;
  transition: .3s;
  border-radius: 34px;
}
.slider:before {
  position: absolute;
  content: "";
  height: 16px;
  width: 16px;
  left: 4px;
  bottom: 4px;
  background-color: white;
  transition: .3s;
  border-radius: 50%;
}
input:checked + .slider {
  background-color: #16a34a;
}
input:checked + .slider:before {
  transform: translateX(26px);
}
</style>

<div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div style="display:flex; gap:10px;">
        <a href="dashboard.php" class="btn btn-sm btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <button class="btn btn-sm btn-primary" onclick="openModal('add-alumnus-modal')"><i class="fas fa-user-plus"></i> Add Alumnus</button>
    </div>
    
    <!-- Public Alumni Visibility Toggle -->
    <div style="display:flex; align-items:center; gap:8px; background:#fafaf9; border:1px solid #e7e5e4; padding:6px 14px; border-radius:10px;">
        <span style="font-size:0.85rem; font-weight:600; color:#374151;"><i class="fas fa-eye" style="color:var(--accent);"></i> Public Alumni Showcase</span>
        <label class="switch" style="position:relative; display:inline-block; width:50px; height:24px; margin:0;">
            <input type="checkbox" id="toggle-alumni-visibility" <?php echo $alumni_public_visibility === 'ON' ? 'checked' : ''; ?> onchange="toggleAlumniVisibility(this.checked)" style="opacity:0; width:0; height:0;">
            <span class="slider"></span>
        </label>
    </div>
</div>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<!-- ── ADD ALUMNUS MODAL ── -->
<div class="modal-backdrop" id="add-alumnus-modal">
    <div class="modal" style="max-width:720px; width:90%;">
        <div class="modal-head">
            <h3><i class="fas fa-user-plus" style="color:var(--accent);"></i> Add Alumnus</h3>
            <button class="modal-close" onclick="closeModal('add-alumnus-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_alumni">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name"></div>
                    <div class="field"><label>PEPP Academic Year</label>
                        <select name="academic_year"><option value="">-</option><option value="All years">All years</option><?php foreach ($all_years as $y): ?><option value="<?php echo e($y); ?>"><?php echo e($y); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Course Name</label>
                        <select name="course_name">
                            <option value="">- Select active course -</option>
                            <?php foreach ($active_courses_list as $cname): ?>
                                <option value="<?php echo e($cname); ?>"><?php echo e($cname); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="field"><label>Mobile Number <span class="req">*</span></label><input type="text" name="mobile" required></div>
                    <div class="field"><label>Secondary Mobile</label><input type="text" name="secondary_mobile"></div>
                    <div class="field"><label>Email ID</label><input type="email" name="email"></div>
                    <div class="field"><label>Secondary Email</label><input type="email" name="secondary_email"></div>
                </div>
                <div style="margin-top:14px; padding:12px; background:var(--gray-50); border:1px solid var(--border); border-radius:6px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <span class="cell-sub" style="font-weight:600;"><i class="fas fa-file-import"></i> Need to add in bulk?</span>
                    <div style="display:flex; gap:8px;">
                        <a class="btn btn-sm btn-outline" href="alumni-sample.csv" download><i class="fas fa-download"></i> Sample CSV</a>
                        <button type="button" class="btn btn-sm btn-soft-blue" onclick="closeModal('add-alumnus-modal'); openModal('import-modal');"><i class="fas fa-file-import"></i> Bulk Import CSV</button>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('add-alumnus-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Alumnus</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--accent-soft);color:var(--accent-dark);"><i class="fas fa-list"></i></span>
        <?php 
        $is_filter_active = ($f_q!==''||$f_course_q!==''||$f_year!==''||!empty($f_courses)||$f_email!==''||$sort_by!=='');
        ?>
        <h2>Alumni (<?php echo $is_filter_active ? 'Filtered: ' . number_format($total) . ' of ' . number_format($total_all_alumni) . ' total' : number_format($total); ?>)</h2>
    </div>
    <div class="panel-body" style="border-bottom:1px solid var(--border);">
        <form method="GET" class="filter-bar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
            <div class="field grow-2" style="margin:0;flex:1;min-width:180px;"><label>Search Student</label><input type="text" name="q" value="<?php echo e($f_q); ?>" placeholder="Name, mobile, email"></div>
            <div class="field grow-2" style="margin:0;flex:1;min-width:180px;"><label>Course Keyword</label><input type="text" name="f_course_q" value="<?php echo e($f_course_q); ?>" placeholder="Search course keyword..."></div>
            <div class="field" style="margin:0;"><label>Academic Year</label><select name="fyear"><option value="">All</option><?php foreach ($alumni_years as $y): ?><option value="<?php echo e($y); ?>" <?php echo $f_year===$y?'selected':''; ?>><?php echo e($y); ?></option><?php endforeach; ?></select></div>
            
            <div class="field" style="margin:0; position:relative; min-width:180px;"><label>Course Dropdown</label>
                <button type="button" class="btn btn-outline" style="width:100%; text-align:left; justify-content:space-between; height:38px; display:inline-flex; align-items:center; font-size:0.85rem; padding:6px 12px; background:white; border:1px solid var(--border);" onclick="toggleCourseDropdown(event)">
                    <span id="course-sel-label" style="text-overflow:ellipsis; overflow:hidden; white-space:nowrap; max-width:130px;">All Courses</span>
                    <i class="fas fa-chevron-down" style="font-size:0.8rem; color:var(--text-muted);"></i>
                </button>
                <div id="course-multiselect-dropdown" style="display:none; position:absolute; top:100%; left:0; width:100%; min-width:240px; background:white; border:1px solid var(--border); border-radius:6px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); z-index:1000; padding:10px; max-height:220px; overflow-y:auto; margin-top:4px;">
                    <?php foreach ($alumni_courses as $c): 
                        $checked = in_array($c, $f_courses, true);
                    ?>
                        <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-bottom:8px; cursor:pointer; font-size:0.85rem; user-select:none; color:var(--text-main);">
                            <input type="checkbox" name="fcourse[]" value="<?php echo e($c); ?>" <?php echo $checked ? 'checked' : ''; ?> onchange="updateCourseLabel()" class="course-filter-chk" style="width:15px; height:15px; accent-color:var(--accent);"> <?php echo e($c); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="field" style="margin:0;"><label>Email</label><select name="femail"><option value="">Any</option><option value="yes" <?php echo $f_email==='yes'?'selected':''; ?>>Has email</option><option value="no" <?php echo $f_email==='no'?'selected':''; ?>>No email</option></select></div>
            <div class="field" style="margin:0;"><label>Sort By</label>
                <select name="sort_by">
                    <option value="">Default (Latest)</option>
                    <option value="verified_desc" <?php echo $sort_by==='verified_desc'?'selected':''; ?>>Verified First</option>
                    <option value="verified_asc" <?php echo $sort_by==='verified_asc'?'selected':''; ?>>Unverified First</option>
                    <option value="tracks_pending" <?php echo $sort_by==='tracks_pending'?'selected':''; ?>>Tracks Pending First</option>
                    <option value="tracks_updated" <?php echo $sort_by==='tracks_updated'?'selected':''; ?>>Track Updated First</option>
                </select>
            </div>
            <button class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($f_q!==''||$f_course_q!==''||$f_year!==''||!empty($f_courses)||$f_email!==''||$sort_by!==''): ?><a class="btn btn-sm btn-outline" href="alumni-database.php">Clear</a><?php endif; ?>
        </form>
    </div>
    
    <!-- Bulk update action bar -->
    <?php if (!empty($rows)): ?>
    <div class="panel-body" id="batch-action-bar" style="background:var(--accent-soft); border-bottom:1px solid var(--border); padding:12px; display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
        <div style="font-weight:600; color:var(--accent-dark);"><i class="fas fa-square-check"></i> <span id="checked-count">0</span> alumni selected</div>
        <form method="POST" style="display:inline-flex; align-items:center; gap:10px; margin:0;" onsubmit="return confirmBatchUpdate();">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="batch_update_course">
            <input type="hidden" name="target_type" id="batch-target-type" value="selected">
            <input type="hidden" name="selected_ids" id="batch-selected-ids" value="">
            
            <input type="hidden" name="filter_q" value="<?php echo e($f_q); ?>">
            <input type="hidden" name="filter_course_q" value="<?php echo e($f_course_q); ?>">
            <input type="hidden" name="filter_year" value="<?php echo e($f_year); ?>">
            <input type="hidden" name="filter_course" value="<?php echo e($f_course); ?>">
            <input type="hidden" name="filter_email" value="<?php echo e($f_email); ?>">
            
            <div class="field" style="margin:0;">
                <select id="batch-scope-select" onchange="toggleBatchScope()" style="padding: 6px 12px; font-size: 0.85rem; border-radius: 4px; border: 1px solid var(--border);">
                    <option value="selected">Apply to ticked rows only</option>
                    <option value="filtered">Apply to all matching filters (<?php echo $total; ?> records)</option>
                </select>
            </div>
            
            <div class="field" style="margin:0;">
                <select name="new_course_name" required style="padding: 6px 12px; font-size: 0.85rem; border-radius: 4px; border: 1px solid var(--border);">
                    <option value="">- Change Course To -</option>
                    <?php foreach ($active_courses_list as $cname): ?>
                        <option value="<?php echo e($cname); ?>"><?php echo e($cname); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Update Course</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="panel-body flush table-wrap">
        <?php if (empty($rows)): ?>
            <div class="empty-state"><i class="fas fa-user-graduate"></i><p>No alumni yet. Add individually or import a CSV.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 40px; text-align: center;"><input type="checkbox" id="select-all-master" onclick="toggleSelectAll(this)"></th>
                    <th style="width: 60px; text-align: center;">Photo</th>
                    <th>Name</th>
                    <th>Year / Course</th>
                    <th>Mobile(s)</th>
                    <th>Email(s)</th>
                    <th>Tracks Updated?</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $a): ?>
                <tr>
                    <td style="text-align: center;"><input type="checkbox" class="alumni-checkbox" value="<?php echo (int)$a['id']; ?>" onclick="updateBatchUI()"></td>
                    <td style="text-align: center;">
                        <?php 
                        $photo = $a['profile_photo'] ?: $a['user_photo'] ?: 'assets/img/default-avatar.svg';
                        if (strpos($photo, 'uploads/') === 0 && !file_exists(__DIR__ . '/../' . $photo)) {
                            // If relative upload path but doesn't exist, we fall back to user_photo or default
                            $photo = $a['user_photo'] ?: 'assets/img/default-avatar.svg';
                        }
                        if (strpos($photo, 'uploads/') === 0 && !file_exists(__DIR__ . '/../' . $photo)) {
                            $photo = 'assets/img/default-avatar.svg';
                        }
                        $photoUrl = (strpos($photo, 'uploads/') === 0) ? '../' . $photo : $photo;
                        ?>
                        <img src="<?php echo e($photoUrl); ?>" onerror="this.src='assets/img/default-avatar.svg'; this.onerror=null;" style="width:40px; height:40px; border-radius:50%; object-fit:cover; border:2px solid var(--border); cursor:pointer;" onclick="viewPhoto('<?php echo e($photoUrl); ?>')" alt="Photo">
                    </td>
                    <td>
                        <div class="cell-main"><?php echo e($a['name'] ?: '-'); ?></div>
                        <div style="margin-top:2px;">
                            <?php if ($a['is_verified'] == 1): ?>
                                <span class="badge green" style="font-size:0.7rem; padding: 2px 6px;"><i class="fas fa-circle-check"></i> Verified</span>
                            <?php else: ?>
                                <span class="badge gray" style="font-size:0.7rem; padding: 2px 6px;">Unverified</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="cell-sub"><?php echo e($a['academic_year'] ?: '-'); ?><?php echo $a['course_name'] ? '<br>' . e($a['course_name']) : ''; ?></td>
                    <td class="cell-sub"><?php echo e($a['mobile']); ?><?php echo $a['secondary_mobile'] ? '<br>' . e($a['secondary_mobile']) : ''; ?></td>
                    <td class="cell-sub"><?php echo e($a['email'] ?: '-'); ?><?php echo $a['secondary_email'] ? '<br>' . e($a['secondary_email']) : ''; ?></td>
                    <td>
                        <?php if (!empty($a['academic_track_after_pepp']) && $a['academic_track_after_pepp'] !== '[]'): ?>
                            <span class="badge green" style="font-size:0.7rem; padding: 2.5px 7px;"><i class="fas fa-circle-check"></i> Updated</span>
                        <?php else: ?>
                            <span class="badge red" style="font-size:0.7rem; padding: 2.5px 7px;"><i class="fas fa-triangle-exclamation"></i> Pending</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button class="btn btn-sm btn-soft-blue" onclick='showDetails(<?php echo json_encode($a, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-eye"></i> Details</button>
                        <button class="btn btn-sm btn-outline" onclick='editAlum(<?php echo json_encode($a, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this alumnus?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_alumni">
                            <input type="hidden" name="alumni_id" value="<?php echo (int)$a['id']; ?>">
                            <button class="btn btn-sm btn-soft-red"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($p = max(1,$page-3); $p <= min($total_pages,$page+3); $p++): ?>
                <a class="page-link <?php echo $p===$page?'active':''; ?>" href="?<?php echo http_build_query(array_merge($_GET,['page'=>$p])); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Detailed View Modal -->
<div class="modal-backdrop" id="detail-modal">
    <div class="modal" style="max-width:800px; width:90%; max-height: 90vh; overflow-y: auto;">
        <div class="modal-head">
            <h3><i class="fas fa-id-card" style="color:var(--accent);"></i> Alumnus Profile Details</h3>
            <button class="modal-close" onclick="closeModal('detail-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="detail-modal-body">
            <!-- Loaded dynamically in JS -->
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" onclick="closeModal('detail-modal')">Close</button>
        </div>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="modal-backdrop" id="photo-modal">
    <div class="modal" style="max-width:400px; text-align: center;">
        <div class="modal-head">
            <h3>Profile Photo</h3>
            <button class="modal-close" onclick="closeModal('photo-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <img id="photo-zoom" src="" style="width: 100%; border-radius: 8px; max-height: 350px; object-fit: contain;">
        </div>
    </div>
</div>

<div class="modal-backdrop" id="edit-modal">
    <div class="modal" style="max-width:750px; width:95%; max-height: 90vh; overflow-y: auto;">
        <div class="modal-head">
            <h3><i class="fas fa-pen" style="color:var(--accent);"></i> Edit Alumnus Record</h3>
            <button class="modal-close" onclick="closeModal('edit-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_alumni">
            <input type="hidden" name="alumni_id" id="e-id">
            <input type="hidden" name="existing_profile_photo" id="e-existing-photo">
            
            <div class="modal-body">
                <div style="display:flex; gap:16px; align-items:center; margin-bottom:16px;">
                    <img id="e-photo-preview" src="assets/img/default-avatar.svg" onerror="this.src='assets/img/default-avatar.svg'; this.onerror=null;" style="width:60px; height:60px; border-radius:50%; object-fit:cover; border:2px solid var(--border);">
                    <div class="field" style="margin:0;"><label>Update Profile Photo</label><input type="file" name="profile_photo" accept="image/*"></div>
                </div>

                <h3 style="margin-top: 10px; border-bottom: 1px solid var(--border); padding-bottom: 6px; color: var(--accent-dark);">Personal &amp; Contact Info</h3>
                <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" id="e-name" required></div>
                    <div class="field"><label>Gender</label>
                        <select name="gender" id="e-gender">
                            <option value="">Select</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select></div>
                    <div class="field"><label>Date of Birth</label><input type="date" name="date_of_birth" id="e-dob"></div>
                    <div class="field"><label>Email</label><input type="email" name="email" id="e-email"></div>
                    <div class="field"><label>Secondary Email</label><input type="email" name="secondary_email" id="e-email2"></div>
                    <div class="field"><label>Mobile (Primary) <span class="req">*</span></label><input type="text" name="mobile" id="e-mobile" required></div>
                    <div class="field"><label>Secondary Mobile</label><input type="text" name="secondary_mobile" id="e-mobile2"></div>
                    <div class="field"><label>WhatsApp Country Code</label><input type="text" name="whatsapp_country_code" id="e-wa-cc"></div>
                    <div class="field"><label>WhatsApp Number</label><input type="text" name="whatsapp_number" id="e-wa-num"></div>
                    <div class="field"><label>Mobile Same As WhatsApp</label>
                        <select name="mobile_same_as_whatsapp" id="e-mobile-same">
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                        </select></div>
                    <div class="field"><label>Emergency Contact</label><input type="text" name="emergency_contact" id="e-emergency"></div>
                    <div class="field"><label>Instagram ID</label><input type="text" name="instagram_id" id="e-instagram"></div>
                </div>

                <h3 style="margin-top:20px; border-bottom: 1px solid var(--border); padding-bottom: 6px; color: var(--accent-dark);">Community Career Sync Info</h3>
                <div style="margin-bottom: 15px; padding: 0 10px;">
                    <label style="font-weight: 600; font-size: 0.85rem; margin-bottom: 6px; display: block;">Academic Tracks After PEPP</label>
                    <div id="academic-tracks-container" style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px;"></div>
                    <button type="button" class="btn btn-sm btn-outline" onclick="addAcademicTrackRow()"><i class="fas fa-plus"></i> Add Academic Track</button>
                    <input type="hidden" name="academic_track_after_pepp" id="e-tracks">
                </div>
                <div class="form-grid" style="margin-top:10px; border-top:1px dashed var(--border); padding-top:12px;">
                    <div class="field"><label>Profession Status</label>
                        <select id="e-prof-status" onchange="serializeProfession()">
                            <option value="">Select Status</option>
                            <option value="student">Student</option>
                            <option value="professional">Professional</option>
                            <option value="unemployed">Unemployed</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="field"><label>Profession / Job Title</label>
                        <input type="text" id="e-prof-title" placeholder="e.g. Software Engineer" oninput="serializeProfession()">
                    </div>
                    <div class="field full"><label>Working Institute / Company</label>
                        <input type="text" id="e-prof-institute" placeholder="e.g. Google / ABC University" oninput="serializeProfession()">
                    </div>
                    <input type="hidden" name="current_profession_details" id="e-prof">
                </div>

                <h3 style="margin-top:20px; border-bottom: 1px solid var(--border); padding-bottom: 6px; color: var(--accent-dark);">Address &amp; Education</h3>
                <div class="form-grid">
                    <div class="field full"><label>Postal Address</label><input type="text" name="postal_address" id="e-address"></div>
                    <div class="field"><label>PIN Code</label><input type="text" name="postal_pincode" id="e-pincode"></div>
                    <div class="field"><label>Place / Post Office</label><input type="text" name="place_post_office" id="e-place"></div>
                    <div class="field"><label>District</label><input type="text" name="district" id="e-district"></div>
                    <div class="field"><label>State</label><input type="text" name="state" id="e-state"></div>
                    <div class="field"><label>College / School</label><input type="text" name="college_school" id="e-college"></div>
                    <div class="field"><label>Current Course</label><input type="text" name="course" id="e-course-curr"></div>
                    <div class="field"><label>University / Board</label><input type="text" name="university_board" id="e-board"></div>
                    <div class="field"><label>Remaining Semesters</label><input type="text" name="remaining_semesters" id="e-remaining"></div>
                </div>

                <h3 style="margin-top:20px; border-bottom: 1px solid var(--border); padding-bottom: 6px; color: var(--accent-dark);">PEPP Study Program</h3>
                <div class="form-grid">
                    <div class="field"><label>PEPP Academic Year</label>
                        <select name="academic_year" id="e-year">
                            <option value="">-</option>
                            <option value="All years">All years</option>
                            <?php foreach ($all_years as $y): ?><option value="<?php echo e($y); ?>"><?php echo e($y); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field"><label>PEPP Course Name</label>
                        <select name="course_name" id="e-course" required>
                            <option value="">- Select active course -</option>
                            <?php foreach ($active_courses_list as $cname): ?><option value="<?php echo e($cname); ?>"><?php echo e($cname); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field"><label>Joined Date</label><input type="date" name="joined_date" id="e-joined"></div>
                    <div class="field"><label>Student Status</label>
                        <select name="student_status" id="e-stud-status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                            <option value="completed">Completed</option>
                        </select></div>
                    <div class="field"><label>Onboarding Status</label>
                        <select name="onboarding_status" id="e-onboard-status">
                            <option value="pending">Pending</option>
                            <option value="completed">Completed</option>
                        </select></div>
                </div>

                <h3 style="margin-top:20px; border-bottom: 1px solid var(--border); padding-bottom: 6px; color: var(--accent-dark);">Payout &amp; Payment Details</h3>
                <div class="form-grid">
                    <div class="field"><label>Total Fee (₹)</label><input type="number" name="total_fee" id="e-total-fee" min="0" step="0.01"></div>
                    <div class="field"><label>Paid Amount (₹)</label><input type="number" name="paid_amount" id="e-paid-amount" min="0" step="0.01"></div>
                    <div class="field"><label>Paid Date</label><input type="date" name="paid_date" id="e-paid-date"></div>
                    <div class="field"><label>Discount Amount (₹)</label><input type="number" name="discount_amount" id="e-discount-amount" min="0" step="0.01"></div>
                    <div class="field"><label>Discount Remark</label><input type="text" name="discount_remark" id="e-discount-remark"></div>
                    <div class="field"><label>Payment Plan</label><input type="text" name="payment_plan" id="e-pay-plan"></div>
                    <div class="field"><label>Payment Mode</label><input type="text" name="payment_mode" id="e-pay-mode"></div>
                    <div class="field"><label>PEPP Kit Eligibility</label>
                        <select name="peppkit_eligibility" id="e-peppkit-elig">
                            <option value="Eligible">Eligible</option>
                            <option value="Not Eligible">Not Eligible</option>
                        </select></div>
                </div>
            </div>
            
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('edit-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleCourseDropdown(e) {
    if (e) e.stopPropagation();
    var d = document.getElementById('course-multiselect-dropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
}

function updateCourseLabel() {
    var chks = document.querySelectorAll('.course-filter-chk:checked');
    var label = document.getElementById('course-sel-label');
    if (!label) return;
    if (chks.length === 0) {
        label.textContent = 'All Courses';
    } else if (chks.length === 1) {
        label.textContent = chks[0].parentElement.textContent.trim();
    } else {
        label.textContent = chks.length + ' Courses selected';
    }
}

document.addEventListener('click', function(e) {
    var d = document.getElementById('course-multiselect-dropdown');
    var btn = document.querySelector('[onclick=\"toggleCourseDropdown(event)\"]');
    if (d && btn && !d.contains(e.target) && !btn.contains(e.target)) {
        d.style.display = 'none';
    }
});

window.addEventListener('DOMContentLoaded', function() {
    updateCourseLabel();
});

function escapeHtml(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function viewPhoto(url) {
    document.getElementById('photo-zoom').src = url;
    openModal('photo-modal');
}

function showDetails(a) {
    const body = document.getElementById('detail-modal-body');
    
    let tracksHtml = '<em>No academic tracks recorded</em>';
    if (a.academic_track_after_pepp) {
        try {
            const tracks = JSON.parse(a.academic_track_after_pepp);
            if (Array.isArray(tracks) && tracks.length > 0) {
                tracksHtml = '<table class="data-table" style="width:100%;"><thead><tr><th>Course</th><th>Institute</th></tr></thead><tbody>';
                tracks.forEach(t => {
                    tracksHtml += `<tr><td>${escapeHtml(t.course)}</td><td>${escapeHtml(t.institute)}</td></tr>`;
                });
                tracksHtml += '</tbody></table>';
            }
        } catch(e) {}
    }
    
    let profHtml = '<em>No profession details recorded</em>';
    if (a.current_profession_details) {
        try {
            const prof = JSON.parse(a.current_profession_details);
            if (prof && typeof prof === 'object') {
                profHtml = `<div class="detail-list">
                    <div class="detail-row"><div class="dl">Status</div><div class="dv">${escapeHtml(prof.status || '-')}</div></div>
                    <div class="detail-row"><div class="dl">Profession</div><div class="dv">${escapeHtml(prof.profession || '-')}</div></div>
                    <div class="detail-row"><div class="dl">Working Institute</div><div class="dv">${escapeHtml(prof.working_institute || '-')}</div></div>
                </div>`;
            } else if (typeof prof === 'string') {
                profHtml = `<div>${escapeHtml(prof)}</div>`;
            }
        } catch(e) {}
    }

    let photo = a.profile_photo || a.user_photo || 'assets/img/default-avatar.svg';
    if (photo.startsWith('uploads/')) {
        photo = '../' + photo;
    }

    body.innerHTML = `
        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px; align-items:center;">
            <img src="${photo}" onerror="this.src='assets/img/default-avatar.svg'; this.onerror=null;" style="width:90px; height:90px; border-radius:50%; object-fit:cover; border:3px solid var(--accent); cursor:pointer;" onclick="viewPhoto('${photo}')">
            <div>
                <h2 style="margin:0; font-size:1.5rem;">${escapeHtml(a.name)}</h2>
                <p class="cell-sub" style="margin:4px 0 0 0;">User ID: ${escapeHtml(a.user_id || '-')}</p>
                <div style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap;">
                    ${a.is_verified == 1 ? '<span class="badge green"><i class="fas fa-circle-check"></i> Verified PEPPian</span>' : '<span class="badge gray">Unverified</span>'}
                    ${a.synced_at ? `<span class="badge blue">Synced: ${a.synced_at}</span>` : ''}
                    ${a.student_status ? `<span class="badge blue">Status: ${escapeHtml(a.student_status)}</span>` : ''}
                </div>
            </div>
        </div>
        
        <div class="panel">
            <div class="panel-head"><h2>Personal &amp; Contact Details</h2></div>
            <div class="panel-body">
                <div class="detail-list">
                    <div class="detail-row"><div class="dl">Gender / DOB</div><div class="dv">${escapeHtml(a.gender || '-')} / ${escapeHtml(a.date_of_birth || '-')}</div></div>
                    <div class="detail-row"><div class="dl">Email</div><div class="dv">${escapeHtml(a.email || '-')} ${a.secondary_email ? `(Sec: ${escapeHtml(a.secondary_email)})` : ''}</div></div>
                    <div class="detail-row"><div class="dl">Mobile / WhatsApp</div><div class="dv">${escapeHtml(a.whatsapp_country_code || '+91')} ${escapeHtml(a.whatsapp_number || a.mobile || '-')} ${a.secondary_mobile ? `(Sec: ${escapeHtml(a.secondary_mobile)})` : ''}</div></div>
                    <div class="detail-row"><div class="dl">Emergency Contact</div><div class="dv">${escapeHtml(a.emergency_contact || '-')}</div></div>
                    <div class="detail-row"><div class="dl">Address</div><div class="dv">${escapeHtml(a.postal_address || '')}, ${escapeHtml(a.place_post_office || '')}, ${escapeHtml(a.district || '')}, ${escapeHtml(a.state || '')} - ${escapeHtml(a.postal_pincode || '')}</div></div>
                    <div class="detail-row"><div class="dl">Instagram ID</div><div class="dv">${escapeHtml(a.instagram_id || '-')}</div></div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>PEPP Course &amp; Admission</h2></div>
            <div class="panel-body">
                <div class="detail-list">
                    <div class="detail-row"><div class="dl">PEPP Course</div><div class="dv">${escapeHtml(a.course_name || '-')}</div></div>
                    <div class="detail-row"><div class="dl">Academic Year</div><div class="dv">${escapeHtml(a.academic_year || '-')}</div></div>
                    <div class="detail-row"><div class="dl">School/College</div><div class="dv">${escapeHtml(a.college_school || '-')}</div></div>
                    <div class="detail-row"><div class="dl">Current College Course</div><div class="dv">${escapeHtml(a.course || '-')} (${escapeHtml(a.university_board || '-')})</div></div>
                    <div class="detail-row"><div class="dl">Remaining Semesters</div><div class="dv">${escapeHtml(a.remaining_semesters || '-')}</div></div>
                    <div class="detail-row"><div class="dl">Joined Date</div><div class="dv">${escapeHtml(a.joined_date || '-')}</div></div>
                    <div class="detail-row"><div class="dl">Onboarding Status</div><div class="dv"><span class="badge ${a.onboarding_status === 'completed' ? 'green' : 'amber'}">${escapeHtml(a.onboarding_status || 'pending')}</span></div></div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Payment Summary</h2></div>
            <div class="panel-body">
                <div class="detail-list">
                    <div class="detail-row"><div class="dl">Total Fee</div><div class="dv">₹${Number(a.total_fee || 0).toLocaleString('en-IN')}</div></div>
                    <div class="detail-row"><div class="dl">Paid Amount</div><div class="dv">₹${Number(a.paid_amount || 0).toLocaleString('en-IN')} (on ${escapeHtml(a.paid_date || '-')})</div></div>
                    <div class="detail-row"><div class="dl">Discount</div><div class="dv">₹${Number(a.discount_amount || 0).toLocaleString('en-IN')} (${escapeHtml(a.discount_remark || '-')})</div></div>
                    <div class="detail-row"><div class="dl">Payment Plan</div><div class="dv">${escapeHtml(a.payment_plan || '-')}</div></div>
                    <div class="detail-row"><div class="dl">Payment Mode</div><div class="dv">${escapeHtml(a.payment_mode || '-')}</div></div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Community Career &amp; Post-PEPP Info</h2></div>
            <div class="panel-body">
                <h4 style="margin: 0 0 8px 0; color: var(--accent-dark);">Academic Track After PEPP</h4>
                <div style="margin-bottom:15px; margin-top:5px;">${tracksHtml}</div>
                <h4 style="margin: 0 0 8px 0; color: var(--accent-dark);">Current Profession details</h4>
                <div>${profHtml}</div>
            </div>
        </div>
    `;
    openModal('detail-modal');
}

function editAlum(a) {
    document.getElementById('e-id').value = a.id;
    document.getElementById('e-name').value = a.name || '';
    document.getElementById('e-gender').value = a.gender || '';
    document.getElementById('e-dob').value = a.date_of_birth || '';
    document.getElementById('e-email').value = a.email || '';
    document.getElementById('e-email2').value = a.secondary_email || '';
    document.getElementById('e-mobile').value = a.mobile || '';
    document.getElementById('e-mobile2').value = a.secondary_mobile || '';
    document.getElementById('e-wa-cc').value = a.whatsapp_country_code || '+91';
    document.getElementById('e-wa-num').value = a.whatsapp_number || '';
    document.getElementById('e-mobile-same').value = a.mobile_same_as_whatsapp || 'yes';
    document.getElementById('e-emergency').value = a.emergency_contact || '';
    document.getElementById('e-instagram').value = a.instagram_id || '';
    
    document.getElementById('e-address').value = a.postal_address || '';
    document.getElementById('e-pincode').value = a.postal_pincode || '';
    document.getElementById('e-place').value = a.place_post_office || '';
    document.getElementById('e-district').value = a.district || '';
    document.getElementById('e-state').value = a.state || '';
    document.getElementById('e-college').value = a.college_school || '';
    document.getElementById('e-course-curr').value = a.course || '';
    document.getElementById('e-board').value = a.university_board || '';
    document.getElementById('e-remaining').value = a.remaining_semesters || '';
    
    var ys = document.getElementById('e-year');
    if (a.academic_year && ![].some.call(ys.options, function(o){return o.value===a.academic_year;})) {
        var op = document.createElement('option'); op.value = a.academic_year; op.textContent = a.academic_year; ys.appendChild(op);
    }
    ys.value = a.academic_year || '';
    
    var cs = document.getElementById('e-course');
    if (a.course_name && ![].some.call(cs.options, function(o){return o.value===a.course_name;})) {
        var opc = document.createElement('option'); opc.value = a.course_name; opc.textContent = a.course_name; cs.appendChild(opc);
    }
    cs.value = a.course_name || '';
    
    document.getElementById('e-joined').value = a.joined_date || '';
    document.getElementById('e-stud-status').value = a.student_status || 'active';
    document.getElementById('e-onboard-status').value = a.onboarding_status || 'pending';
    
    document.getElementById('e-total-fee').value = a.total_fee || 0;
    document.getElementById('e-paid-amount').value = a.paid_amount || 0;
    document.getElementById('e-paid-date').value = a.paid_date || '';
    document.getElementById('e-discount-amount').value = a.discount_amount || 0;
    document.getElementById('e-discount-remark').value = a.discount_remark || '';
    document.getElementById('e-pay-plan').value = a.payment_plan || 'One Time';
    document.getElementById('e-pay-mode').value = a.payment_mode || '';
    document.getElementById('e-peppkit-elig').value = a.peppkit_eligibility || 'Not Eligible';
    
    renderAcademicTracks(a.academic_track_after_pepp || '');
    renderProfession(a.current_profession_details || '');
    
    document.getElementById('e-existing-photo').value = a.profile_photo || '';
    let pSrc = a.profile_photo || a.user_photo || 'assets/img/default-avatar.svg';
    if (pSrc.startsWith('uploads/')) {
        pSrc = '../' + pSrc;
    }
    document.getElementById('e-photo-preview').src = pSrc;
    
    openModal('edit-modal');
}

function renderAcademicTracks(tracksJson) {
    const container = document.getElementById('academic-tracks-container');
    container.innerHTML = '';
    let tracks = [];
    if (tracksJson) {
        try {
            tracks = JSON.parse(tracksJson);
        } catch(e) {
            console.error("Invalid tracks JSON", e);
        }
    }
    if (!Array.isArray(tracks) || tracks.length === 0) {
        tracks = [{course: '', institute: ''}];
    }
    tracks.forEach(t => {
        createTrackRow(t.course || '', t.institute || '');
    });
    serializeAcademicTracks();
}

function createTrackRow(course = '', institute = '') {
    const container = document.getElementById('academic-tracks-container');
    const row = document.createElement('div');
    row.className = 'track-row';
    row.style.display = 'flex';
    row.style.gap = '10px';
    row.style.alignItems = 'center';
    row.style.marginTop = '6px';
    row.innerHTML = `
        <input type="text" placeholder="Course (e.g. MSc Computer Science)" class="track-course" value="${escapeHtml(course)}" style="flex:1; padding: 6px 12px; border-radius: 4px; border: 1px solid var(--border);" oninput="serializeAcademicTracks()">
        <input type="text" placeholder="Institute (e.g. University of Kerala)" class="track-institute" value="${escapeHtml(institute)}" style="flex:1; padding: 6px 12px; border-radius: 4px; border: 1px solid var(--border);" oninput="serializeAcademicTracks()">
        <button type="button" class="btn btn-sm btn-soft-red" style="padding: 6px 10px;" onclick="removeTrackRow(this)"><i class="fas fa-trash"></i></button>
    `;
    container.appendChild(row);
}

function addAcademicTrackRow() {
    createTrackRow();
    serializeAcademicTracks();
}

function removeTrackRow(btn) {
    const container = document.getElementById('academic-tracks-container');
    btn.closest('.track-row').remove();
    if (container.children.length === 0) {
        createTrackRow();
    }
    serializeAcademicTracks();
}

function serializeAcademicTracks() {
    const container = document.getElementById('academic-tracks-container');
    const rows = container.getElementsByClassName('track-row');
    const tracks = [];
    for (let i = 0; i < rows.length; i++) {
        const course = rows[i].querySelector('.track-course').value.trim();
        const institute = rows[i].querySelector('.track-institute').value.trim();
        if (course !== '' || institute !== '') {
            tracks.push({ course: course, institute: institute });
        }
    }
    document.getElementById('e-tracks').value = tracks.length > 0 ? JSON.stringify(tracks) : '';
}

function renderProfession(profJson) {
    document.getElementById('e-prof-status').value = '';
    document.getElementById('e-prof-title').value = '';
    document.getElementById('e-prof-institute').value = '';
    document.getElementById('e-prof').value = '';
    
    if (profJson) {
        try {
            const prof = JSON.parse(profJson);
            if (prof && typeof prof === 'object') {
                document.getElementById('e-prof-status').value = prof.status || '';
                document.getElementById('e-prof-title').value = prof.profession || '';
                document.getElementById('e-prof-institute').value = prof.working_institute || '';
            }
        } catch(e) {
            console.error("Invalid profession JSON", e);
        }
    }
    serializeProfession();
}

function serializeProfession() {
    const status = document.getElementById('e-prof-status').value;
    const profession = document.getElementById('e-prof-title').value.trim();
    const institute = document.getElementById('e-prof-institute').value.trim();
    
    if (status !== '' || profession !== '' || institute !== '') {
        const obj = {
            status: status,
            profession: profession,
            working_institute: institute
        };
        document.getElementById('e-prof').value = JSON.stringify(obj);
    } else {
        document.getElementById('e-prof').value = '';
    }
}

function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.alumni-checkbox');
    checkboxes.forEach(cb => {
        if (!cb.disabled) cb.checked = master.checked;
    });
    updateBatchUI();
}

function updateBatchUI() {
    const checkboxes = document.querySelectorAll('.alumni-checkbox:checked');
    const checkedCount = checkboxes.length;
    document.getElementById('checked-count').textContent = checkedCount;
    
    const scopeSelect = document.getElementById('batch-scope-select');
    const targetInput = document.getElementById('batch-target-type');
    const selectedIdsInput = document.getElementById('batch-selected-ids');
    
    const ids = Array.from(checkboxes).map(cb => cb.value);
    selectedIdsInput.value = ids.join(',');
    
    if (scopeSelect.value === 'selected') {
        targetInput.value = 'selected';
    } else {
        targetInput.value = 'filtered';
    }
}

function toggleBatchScope() {
    const scopeSelect = document.getElementById('batch-scope-select');
    const checkboxes = document.querySelectorAll('.alumni-checkbox');
    
    if (scopeSelect.value === 'filtered') {
        checkboxes.forEach(cb => cb.disabled = true);
        document.getElementById('select-all-master').disabled = true;
    } else {
        checkboxes.forEach(cb => cb.disabled = false);
        document.getElementById('select-all-master').disabled = false;
    }
    updateBatchUI();
}

function confirmBatchUpdate() {
    const scope = document.getElementById('batch-scope-select').value;
    const checkboxes = document.querySelectorAll('.alumni-checkbox:checked');
    
    if (scope === 'selected' && checkboxes.length === 0) {
        alert('Please select at least one alumnus using the checkboxes.');
        return false;
    }
    
    const msg = scope === 'selected' 
        ? `Are you sure you want to update the course for the ${checkboxes.length} selected alumni?`
        : `Are you sure you want to update the course for ALL matching alumni?`;
        
    return confirm(msg);
}

function toggleAlumniVisibility(checked) {
    var val = checked ? 'ON' : 'OFF';
    var formData = new FormData();
    formData.append('action', 'toggle_alumni_visibility');
    formData.append('value', val);
    formData.append('csrf_token', '<?php echo csrf_token(); ?>');
    
    fetch('alumni-database.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert('Error: ' + data.message);
            document.getElementById('toggle-alumni-visibility').checked = !checked;
        }
    })
    .catch(err => {
        console.error(err);
        alert('Server connection error.');
        document.getElementById('toggle-alumni-visibility').checked = !checked;
    });
}
</script>

<div class="modal-backdrop" id="import-modal">
    <div class="modal" style="max-width:540px;">
        <div class="modal-head"><h3><i class="fas fa-file-import" style="color:var(--accent);"></i> Import Alumni</h3><button class="modal-close" onclick="closeModal('import-modal')"><i class="fas fa-xmark"></i></button></div>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="import">
            <div class="modal-body">
                <div class="alert alert-info"><i class="fas fa-circle-info"></i><span>CSV columns: <code>name</code>, <code>academic_year</code>, <code>course_name</code>, <code>email</code>, <code>secondary_email</code>, <code>mobile</code> (required), <code>secondary_mobile</code>. Rows whose mobile/email matches an existing alumnus are folded into that alumnus's secondary contact.</span></div>
                <div class="field"><label>CSV file <span class="req">*</span></label><input type="file" name="file" accept=".csv,.txt" required></div>
                <a href="alumni-sample.csv" download style="font-size:.8rem;font-weight:600;color:var(--accent);"><i class="fas fa-download"></i> Download sample CSV</a>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('import-modal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-file-import"></i> Import</button></div>
        </form>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
