<?php
require_once 'includes/auth.php';
require_super_admin();   // Super Admin only

/* Alumni Database - Super Admin manages past students for the referral
   program's verification. Add individually or bulk-import CSV. Duplicate
   mobile/email is folded into the secondary mobile/email of the existing row. */

$success_message = ''; $error_message = '';

function alumni_ready($pdo) {
    try { return (bool)$pdo->query("SHOW TABLES LIKE 'alumni'")->fetchColumn(); }
    catch (Exception $e) { return false; }
}
if (!alumni_ready($pdo)) {
    $active_page = 'settings'; $page_title = 'Alumni Database'; $page_sub = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>Run <strong>database-update-8.sql</strong> once in phpMyAdmin, then reload.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

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
                    $pdo->prepare("UPDATE alumni SET name=?, academic_year=?, course_name=?, email=?, secondary_email=?, mobile=?, secondary_mobile=? WHERE id=?")
                        ->execute([
                            mb_substr(trim($_POST['name'] ?? ''), 0, 150),
                            mb_substr(trim($_POST['academic_year'] ?? ''), 0, 20) ?: null,
                            mb_substr(trim($_POST['course_name'] ?? ''), 0, 255) ?: null,
                            mb_substr(strtolower(trim($_POST['email'] ?? '')), 0, 190) ?: null,
                            mb_substr(strtolower(trim($_POST['secondary_email'] ?? '')), 0, 190) ?: null,
                            mb_substr(trim($_POST['mobile'] ?? ''), 0, 20),
                            mb_substr(trim($_POST['secondary_mobile'] ?? ''), 0, 20) ?: null,
                            $id
                        ]);
                    log_admin_activity($pdo, $admin_username, 'alumni_edited', "Edited alumni #{$id}");
                    $success_message = 'Alumnus updated.';
                } else { $error_message = 'Mobile number is required.'; }
            } elseif ($action === 'delete_alumni') {
                $id = (int)($_POST['alumni_id'] ?? 0);
                $pdo->prepare("DELETE FROM alumni WHERE id = ?")->execute([$id]);
                log_admin_activity($pdo, $admin_username, 'alumni_deleted', "Deleted alumni #{$id}");
                $success_message = 'Alumnus deleted.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Exception $e2) {} }
            error_log('Alumni DB: ' . $e->getMessage());
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

// Inactive academic years only (per requirement)
$inactive_years = [];
try { $inactive_years = $pdo->query("SELECT year FROM academic_years WHERE status='inactive' ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

$f_q = trim($_GET['q'] ?? '');
$f_year = trim($_GET['fyear'] ?? '');
$f_course = trim($_GET['fcourse'] ?? '');
$f_email = $_GET['femail'] ?? '';   // '', 'yes', 'no'

// Distinct years & courses present in the alumni table (for filter dropdowns)
$alumni_years = []; $alumni_courses = [];
try {
    $alumni_years = $pdo->query("SELECT DISTINCT academic_year FROM alumni WHERE academic_year IS NOT NULL AND academic_year<>'' ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $alumni_courses = $pdo->query("SELECT DISTINCT course_name FROM alumni WHERE course_name IS NOT NULL AND course_name<>'' ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$page = max(1, (int)($_GET['page'] ?? 1)); $per = 30;
$total = 0; $rows = [];

// Build a parameterised WHERE from the active filters
$where = []; $params = [];
if ($f_q !== '') {
    $where[] = "(name LIKE ? OR mobile LIKE ? OR secondary_mobile LIKE ? OR email LIKE ? OR secondary_email LIKE ? OR course_name LIKE ?)";
    $like = "%$f_q%"; array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($f_year !== '')   { $where[] = "academic_year = ?"; $params[] = $f_year; }
if ($f_course !== '') { $where[] = "course_name = ?"; $params[] = $f_course; }
if ($f_email === 'yes') { $where[] = "(email IS NOT NULL AND email <> '')"; }
elseif ($f_email === 'no') { $where[] = "(email IS NULL OR email = '')"; }
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cstmt = $pdo->prepare("SELECT COUNT(*) FROM alumni $wsql");
    $cstmt->execute($params); $total = (int)$cstmt->fetchColumn();
    $lstmt = $pdo->prepare("SELECT * FROM alumni $wsql ORDER BY id DESC LIMIT $per OFFSET " . (($page-1)*$per));
    $lstmt->execute($params); $rows = $lstmt->fetchAll();
} catch (Exception $e) { error_log('Alumni list: ' . $e->getMessage()); }
$total_pages = max(1, (int)ceil($total / $per));

$active_page = 'settings';
$page_title  = 'Alumni Database';
$page_sub    = 'Past students - used to verify PEPPians';
include 'includes/admin_nav.php';
?>

<div style="margin-bottom:16px;"><a href="settings.php" class="btn btn-sm btn-outline"><i class="fas fa-arrow-left"></i> Back to Settings</a></div>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="panel">
    <div class="panel-head"><span class="head-icon"><i class="fas fa-user-graduate"></i></span><h2>Add Alumnus</h2>
        <div class="head-right"><button class="btn btn-sm btn-outline" onclick="openModal('import-modal')"><i class="fas fa-file-import"></i> Bulk Import</button>
        <a class="btn btn-sm btn-soft-blue" href="alumni-sample.csv" download><i class="fas fa-download"></i> Sample CSV</a></div>
    </div>
    <div class="panel-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_alumni">
            <div class="form-grid">
                <div class="field"><label>Name</label><input type="text" name="name"></div>
                <div class="field"><label>PEPP Academic Year</label>
                    <select name="academic_year"><option value="">-</option><?php foreach ($inactive_years as $y): ?><option value="<?php echo e($y); ?>"><?php echo e($y); ?></option><?php endforeach; ?></select>
                    <div class="help">Only inactive (past) batches are listed</div></div>
                <div class="field"><label>Course Name</label><input type="text" name="course_name" placeholder="Type the course name"></div>
                <div class="field"><label>Mobile Number <span class="req">*</span></label><input type="text" name="mobile" required></div>
                <div class="field"><label>Secondary Mobile</label><input type="text" name="secondary_mobile"></div>
                <div class="field"><label>Email ID</label><input type="email" name="email"></div>
                <div class="field"><label>Secondary Email</label><input type="email" name="secondary_email"></div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:12px;"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Alumnus</button></div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--accent-soft);color:var(--accent-dark);"><i class="fas fa-list"></i></span><h2>Alumni (<?php echo number_format($total); ?>)</h2></div>
    <div class="panel-body" style="border-bottom:1px solid var(--border);">
        <form method="GET" class="filter-bar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
            <div class="field grow-2" style="margin:0;flex:1;min-width:180px;"><label>Search</label><input type="text" name="q" value="<?php echo e($f_q); ?>" placeholder="Name, mobile, email, course"></div>
            <div class="field" style="margin:0;"><label>Academic Year</label><select name="fyear"><option value="">All</option><?php foreach ($alumni_years as $y): ?><option value="<?php echo e($y); ?>" <?php echo $f_year===$y?'selected':''; ?>><?php echo e($y); ?></option><?php endforeach; ?></select></div>
            <div class="field" style="margin:0;"><label>Course</label><select name="fcourse"><option value="">All</option><?php foreach ($alumni_courses as $c): ?><option value="<?php echo e($c); ?>" <?php echo $f_course===$c?'selected':''; ?>><?php echo e($c); ?></option><?php endforeach; ?></select></div>
            <div class="field" style="margin:0;"><label>Email</label><select name="femail"><option value="">Any</option><option value="yes" <?php echo $f_email==='yes'?'selected':''; ?>>Has email</option><option value="no" <?php echo $f_email==='no'?'selected':''; ?>>No email</option></select></div>
            <button class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($f_q!==''||$f_year!==''||$f_course!==''||$f_email!==''): ?><a class="btn btn-sm btn-outline" href="alumni-database.php">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($rows)): ?>
            <div class="empty-state"><i class="fas fa-user-graduate"></i><p>No alumni yet. Add individually or import a CSV.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Name</th><th>Year / Course</th><th>Mobile(s)</th><th>Email(s)</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $a): ?>
                <tr>
                    <td class="cell-main"><?php echo e($a['name'] ?: '-'); ?></td>
                    <td class="cell-sub"><?php echo e($a['academic_year'] ?: '-'); ?><?php echo $a['course_name'] ? '<br>' . e($a['course_name']) : ''; ?></td>
                    <td class="cell-sub"><?php echo e($a['mobile']); ?><?php echo $a['secondary_mobile'] ? '<br>' . e($a['secondary_mobile']) : ''; ?></td>
                    <td class="cell-sub"><?php echo e($a['email'] ?: '-'); ?><?php echo $a['secondary_email'] ? '<br>' . e($a['secondary_email']) : ''; ?></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button class="btn btn-sm btn-outline" onclick='editAlum(<?php echo json_encode([
                            "id"=>(int)$a["id"],"name"=>(string)$a["name"],"academic_year"=>(string)$a["academic_year"],"course_name"=>(string)$a["course_name"],
                            "email"=>(string)$a["email"],"secondary_email"=>(string)$a["secondary_email"],"mobile"=>(string)$a["mobile"],"secondary_mobile"=>(string)$a["secondary_mobile"],
                        ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this alumnus?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_alumni"><input type="hidden" name="alumni_id" value="<?php echo (int)$a['id']; ?>"><button class="btn btn-sm btn-soft-red"><i class="fas fa-trash"></i></button></form>
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

<div class="modal-backdrop" id="edit-modal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-head"><h3><i class="fas fa-pen" style="color:var(--accent);"></i> Edit Alumnus</h3><button class="modal-close" onclick="closeModal('edit-modal')"><i class="fas fa-xmark"></i></button></div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_alumni">
            <input type="hidden" name="alumni_id" id="e-id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" id="e-name"></div>
                    <div class="field"><label>PEPP Academic Year</label>
                        <select name="academic_year" id="e-year"><option value="">-</option><?php foreach ($inactive_years as $y): ?><option value="<?php echo e($y); ?>"><?php echo e($y); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Course Name</label><input type="text" name="course_name" id="e-course"></div>
                    <div class="field"><label>Mobile Number <span class="req">*</span></label><input type="text" name="mobile" id="e-mobile" required></div>
                    <div class="field"><label>Secondary Mobile</label><input type="text" name="secondary_mobile" id="e-mobile2"></div>
                    <div class="field"><label>Email ID</label><input type="email" name="email" id="e-email"></div>
                    <div class="field"><label>Secondary Email</label><input type="email" name="secondary_email" id="e-email2"></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('edit-modal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Changes</button></div>
        </form>
    </div>
</div>

<script>
function editAlum(a) {
    document.getElementById('e-id').value = a.id;
    document.getElementById('e-name').value = a.name || '';
    var ys = document.getElementById('e-year');
    // If the alumnus's year isn't in the inactive list, add it so it shows
    if (a.academic_year && ![].some.call(ys.options, function(o){return o.value===a.academic_year;})) {
        var op = document.createElement('option'); op.value = a.academic_year; op.textContent = a.academic_year; ys.appendChild(op);
    }
    ys.value = a.academic_year || '';
    document.getElementById('e-course').value = a.course_name || '';
    document.getElementById('e-mobile').value = a.mobile || '';
    document.getElementById('e-mobile2').value = a.secondary_mobile || '';
    document.getElementById('e-email').value = a.email || '';
    document.getElementById('e-email2').value = a.secondary_email || '';
    openModal('edit-modal');
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
