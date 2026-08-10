<?php
require_once 'includes/auth.php';
require_permission('studyplans');
require_once 'config/database.php';

// Handle Sample CSV Download
if (isset($_GET['download_sample_csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_study_plan_chapters.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['chapter_name', 'chapter_code', 'subject_name', 'description']);
    fputcsv($out, ['Introduction to Psychology', 'CH-01', 'Psychology', 'Foundational concepts, history, and major schools of thought']);
    fputcsv($out, ['Cognitive Neuroscience & Brain', 'CH-02', 'Neuroscience', 'Neural structures, perception, and cognitive processes']);
    fputcsv($out, ['Memory Systems & Learning', 'CH-03', 'Cognitive Psychology', 'Encoding, storage, retrieval, and learning theories']);
    fputcsv($out, ['Clinical Diagnosis & DSM-5', 'CH-04', 'Clinical Psychology', 'Diagnostic criteria, classification systems, and assessment']);
    fclose($out);
    exit;
}

$success_msg = '';
$error_msg = '';

// Fetch Academic Years
$academic_years = [];
try {
    $academic_years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
if (empty($academic_years)) {
    $academic_years = ['2026-27', '2025-26', '2024-25'];
}

$selected_year = trim($_GET['academic_year'] ?? $_POST['academic_year'] ?? $academic_years[0]);
$selected_course_filter = (int)($_GET['course_filter'] ?? 0);
$search_query = trim($_GET['search'] ?? '');

// Fetch Active Courses
$courses = [];
try {
    $c_stmt = $pdo->prepare("SELECT id, course_name, course_code, academic_year FROM pepp_courses WHERE status = 'active' ORDER BY course_name ASC");
    $c_stmt->execute();
    $courses = $c_stmt->fetchAll();
} catch (Exception $e) {
    $error_msg = 'Error loading courses: ' . $e->getMessage();
}

// ── Form Actions: Delete Single / Bulk Delete ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify()) {
        $error_msg = 'CSRF verification failed. Please try again.';
    } else {
        $action = $_POST['action'];

        if ($action === 'delete_chapter') {
            $chap_id = (int)($_POST['chapter_id'] ?? 0);
            if ($chap_id > 0) {
                $pdo->prepare("DELETE FROM study_plan_chapters WHERE id = ?")->execute([$chap_id]);
                $success_msg = 'Chapter deleted successfully.';
            }
        } elseif ($action === 'bulk_delete') {
            $ids = $_POST['chapter_ids'] ?? [];
            if (!empty($ids) && is_array($ids)) {
                $clean_ids = array_map('intval', $ids);
                $in = implode(',', array_fill(0, count($clean_ids), '?'));
                $pdo->prepare("DELETE FROM study_plan_chapters WHERE id IN ($in)")->execute($clean_ids);
                $success_msg = count($clean_ids) . ' chapters deleted successfully.';
            }
        } elseif ($action === 'save_chapters') {
            $target_courses = $_POST['target_courses'] ?? [];
            $acad_year = trim($_POST['academic_year'] ?? $selected_year);
            $entry_mode = trim($_POST['entry_mode'] ?? 'manual');

            if (empty($target_courses) || !is_array($target_courses)) {
                $error_msg = 'Please select at least one target course for assigning chapters.';
            } else {
                $chapters_to_insert = [];

                if ($entry_mode === 'csv' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
                    $tmp_file = $_FILES['csv_file']['tmp_name'];
                    if (($handle = fopen($tmp_file, "r")) !== FALSE) {
                        $header = fgetcsv($handle, 1000, ",");
                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            if (count($data) >= 1 && !empty(trim($data[0]))) {
                                $chapters_to_insert[] = [
                                    'chapter_name' => trim($data[0]),
                                    'chapter_code' => trim($data[1] ?? ''),
                                    'subject_name' => trim($data[2] ?? ''),
                                    'description'  => trim($data[3] ?? '')
                                ];
                            }
                        }
                        fclose($handle);
                    } else {
                        $error_msg = 'Could not open uploaded CSV file.';
                    }
                } else {
                    // Manual entry mode
                    $names = $_POST['chap_name'] ?? [];
                    $codes = $_POST['chap_code'] ?? [];
                    $subs  = $_POST['chap_subject'] ?? [];
                    $descs = $_POST['chap_desc'] ?? [];

                    foreach ($names as $idx => $raw_name) {
                        $name = trim($raw_name);
                        if (!empty($name)) {
                            $chapters_to_insert[] = [
                                'chapter_name' => $name,
                                'chapter_code' => trim($codes[$idx] ?? ''),
                                'subject_name' => trim($subs[$idx] ?? ''),
                                'description'  => trim($descs[$idx] ?? '')
                            ];
                        }
                    }
                }

                if (empty($error_msg)) {
                    if (empty($chapters_to_insert)) {
                        $error_msg = 'No valid chapters provided to save. Please enter at least one chapter name.';
                    } else {
                        $inserted_count = 0;
                        $stmt_check = $pdo->prepare("SELECT id FROM study_plan_chapters WHERE academic_year = ? AND course_id = ? AND chapter_name = ?");
                        $stmt_ins = $pdo->prepare("INSERT INTO study_plan_chapters (academic_year, course_id, chapter_name, chapter_code, subject_name, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");

                        $admin_user = $_SESSION['admin_username'] ?? 'System';

                        foreach ($target_courses as $cid_raw) {
                            $cid = (int)$cid_raw;
                            if ($cid <= 0) continue;

                            foreach ($chapters_to_insert as $chap) {
                                $stmt_check->execute([$acad_year, $cid, $chap['chapter_name']]);
                                if (!$stmt_check->fetch()) {
                                    $stmt_ins->execute([
                                        $acad_year,
                                        $cid,
                                        $chap['chapter_name'],
                                        $chap['chapter_code'],
                                        $chap['subject_name'],
                                        $chap['description'],
                                        $admin_user
                                    ]);
                                    $inserted_count++;
                                }
                            }
                        }

                        $success_msg = "Successfully pre-set {$inserted_count} chapter record(s) across " . count($target_courses) . " course(s)!";
                    }
                }
            }
        }
    }
}

// ── Fetch Existing Chapters Data List ──
$ch_where = ["1=1"];
$ch_params = [];

if ($selected_year !== '') {
    $ch_where[] = "spc.academic_year = ?";
    $ch_params[] = $selected_year;
}
if ($selected_course_filter > 0) {
    $ch_where[] = "spc.course_id = ?";
    $ch_params[] = $selected_course_filter;
}
if ($search_query !== '') {
    $ch_where[] = "(spc.chapter_name LIKE ? OR spc.chapter_code LIKE ? OR spc.subject_name LIKE ?)";
    $ch_params[] = "%$search_query%";
    $ch_params[] = "%$search_query%";
    $ch_params[] = "%$search_query%";
}

$ch_where_sql = implode(' AND ', $ch_where);

$existing_chapters = [];
try {
    $ch_stmt = $pdo->prepare("
        SELECT spc.*, pc.course_name, pc.course_code
        FROM study_plan_chapters spc
        LEFT JOIN pepp_courses pc ON spc.course_id = pc.id
        WHERE {$ch_where_sql}
        ORDER BY spc.created_at DESC, spc.chapter_code ASC
    ");
    $ch_stmt->execute($ch_params);
    $existing_chapters = $ch_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching chapters: " . $e->getMessage());
}

$page_title = 'Pre-set Study Plan Chapters';
$page_sub = 'Define and manage curriculum chapters for daily study plan activity assignments';
$active_page = 'studyplans';

include 'includes/admin_nav.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:1.5rem;">
    <div>
        <a href="studyplans.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Study Plans</a>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="studyplan-chapters.php?download_sample_csv=1" class="btn btn-secondary btn-sm"><i class="fas fa-file-csv"></i> Download Sample CSV</a>
    </div>
</div>

<?php if ($success_msg): ?>
    <div class="alert alert-success" style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:12px 16px; border-radius:12px; margin-bottom:1.5rem; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-circle-check" style="font-size:1.2rem; color:#10b981;"></i>
        <div><?php echo htmlspecialchars($success_msg); ?></div>
    </div>
<?php endif; ?>

<?php if ($error_msg): ?>
    <div class="alert alert-danger" style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:12px 16px; border-radius:12px; margin-bottom:1.5rem; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-circle-exclamation" style="font-size:1.2rem; color:#ef4444;"></i>
        <div><?php echo htmlspecialchars($error_msg); ?></div>
    </div>
<?php endif; ?>

<!-- ── Main Card: Pre-set Chapters Creation Form ── -->
<div class="panel" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.5rem; margin-bottom:2rem; box-shadow:0 4px 20px rgba(0,0,0,0.03);">
    <div style="border-bottom:1px solid var(--border); padding-bottom:1rem; margin-bottom:1.25rem;">
        <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-main); margin:0 0 4px 0;"><i class="fas fa-folder-plus" style="color:var(--accent);"></i> Add Pre-set Chapters</h3>
        <p style="font-size:0.82rem; color:var(--text-muted); margin:0;">Select target academic year and courses, then enter or upload chapters to make them auto-selectable during study plan creation.</p>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save_chapters">

        <!-- Step 1: Academic Year & Target Courses -->
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.2rem; margin-bottom:1.5rem;">
            <div style="display:grid; grid-template-columns: 240px 1fr; gap:1.5rem; align-items:start;">
                <div>
                    <label style="font-size:0.8rem; font-weight:700; color:#334155; text-transform:uppercase; display:block; margin-bottom:6px;">
                        1. Select Academic Year <span style="color:#ef4444;">*</span>
                    </label>
                    <select name="academic_year" id="form-academic-year" class="form-input" style="width:100%; font-weight:600;" onchange="filterCoursesByYear(this.value)">
                        <?php foreach ($academic_years as $yr): ?>
                            <option value="<?php echo htmlspecialchars($yr); ?>" <?php echo $selected_year === $yr ? 'selected' : ''; ?>>
                                Academic Year <?php echo htmlspecialchars($yr); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <label style="font-size:0.8rem; font-weight:700; color:#334155; text-transform:uppercase; margin:0;">
                            2. Choose One or Multiple Courses <span style="color:#ef4444;">*</span>
                        </label>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="btn btn-xs btn-outline" onclick="selectAllCourses(true)" style="font-size:0.7rem; padding:2px 8px;">Select All</button>
                            <button type="button" class="btn btn-xs btn-outline" onclick="selectAllCourses(false)" style="font-size:0.7rem; padding:2px 8px;">Deselect All</button>
                        </div>
                    </div>

                    <!-- Course Search & Selection Box -->
                    <div style="margin-bottom:8px;">
                        <input type="text" id="course-search-input" class="form-input" placeholder="Search courses..." style="font-size:0.8rem; padding:6px 10px; margin:0;" oninput="filterCourseCheckboxes(this.value)">
                    </div>

                    <div id="course-checkbox-list" style="background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; max-height:160px; overflow-y:auto; padding:8px 12px; display:grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap:6px;">
                        <?php foreach ($courses as $c): ?>
                            <label class="course-cb-item" data-name="<?php echo htmlspecialchars(strtolower($c['course_name'] . ' ' . $c['course_code'])); ?>" style="display:flex; align-items:center; gap:8px; font-size:0.82rem; cursor:pointer; padding:4px 6px; border-radius:4px; transition:background 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                <input type="checkbox" name="target_courses[]" value="<?php echo $c['id']; ?>" class="course-cb">
                                <span><strong><?php echo htmlspecialchars($c['course_name']); ?></strong> <?php echo $c['course_code'] ? '<small style="color:#64748b;">(' . htmlspecialchars($c['course_code']) . ')</small>' : ''; ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2: Entry Mode Selection (Manual vs CSV Import) -->
        <div style="margin-bottom:1rem;">
            <div style="display:flex; gap:12px; border-bottom:2px solid #e2e8f0; margin-bottom:1.2rem;">
                <button type="button" id="tab-btn-manual" class="tab-toggle-btn active" onclick="switchEntryTab('manual')" style="padding:8px 16px; font-weight:700; font-size:0.88rem; border:none; background:none; border-bottom:3px solid var(--accent); color:var(--accent); cursor:pointer;">
                    <i class="fas fa-list-check"></i> Manual Entry
                </button>
                <button type="button" id="tab-btn-csv" class="tab-toggle-btn" onclick="switchEntryTab('csv')" style="padding:8px 16px; font-weight:600; font-size:0.88rem; border:none; background:none; border-bottom:3px solid transparent; color:#64748b; cursor:pointer;">
                    <i class="fas fa-file-csv"></i> Import via CSV / Excel
                </button>
            </div>
            <input type="hidden" name="entry_mode" id="entry-mode-input" value="manual">

            <!-- Manual Entry Panel -->
            <div id="panel-manual">
                <table class="data-table" id="manual-chapters-table" style="width:100%; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
                    <thead>
                        <tr style="background:#f8fafc; text-align:left;">
                            <th style="width:30%;">Chapter Name <span style="color:#ef4444;">*</span></th>
                            <th style="width:15%;">Code / No.</th>
                            <th style="width:20%;">Subject</th>
                            <th style="width:30%;">Description / Details</th>
                            <th style="width:5%; text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="manual-rows-body">
                        <tr>
                            <td><input type="text" name="chap_name[]" class="form-input" style="margin:0;" placeholder="e.g. Cognitive Psychology" required></td>
                            <td><input type="text" name="chap_code[]" class="form-input" style="margin:0;" placeholder="e.g. CH-01"></td>
                            <td><input type="text" name="chap_subject[]" class="form-input" style="margin:0;" placeholder="e.g. Psychology"></td>
                            <td><input type="text" name="chap_desc[]" class="form-input" style="margin:0;" placeholder="Overview notes..."></td>
                            <td style="text-align:center;"><button type="button" class="btn btn-xs btn-soft-red" onclick="removeChapterRow(this)"><i class="fas fa-trash"></i></button></td>
                        </tr>
                        <tr>
                            <td><input type="text" name="chap_name[]" class="form-input" style="margin:0;" placeholder="e.g. Neurobiology & Memory"></td>
                            <td><input type="text" name="chap_code[]" class="form-input" style="margin:0;" placeholder="e.g. CH-02"></td>
                            <td><input type="text" name="chap_subject[]" class="form-input" style="margin:0;" placeholder="e.g. Neuroscience"></td>
                            <td><input type="text" name="chap_desc[]" class="form-input" style="margin:0;" placeholder="Overview notes..."></td>
                            <td style="text-align:center;"><button type="button" class="btn btn-xs btn-soft-red" onclick="removeChapterRow(this)"><i class="fas fa-trash"></i></button></td>
                        </tr>
                    </tbody>
                </table>
                <div style="margin-top:10px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="addChapterRow()"><i class="fas fa-plus"></i> Add Another Row</button>
                </div>
            </div>

            <!-- CSV Import Panel -->
            <div id="panel-csv" style="display:none; background:#f8fafc; border:2px dashed #cbd5e1; border-radius:12px; padding:2rem; text-align:center;">
                <i class="fas fa-file-arrow-up" style="font-size:2.5rem; color:#8b5cf6; margin-bottom:12px;"></i>
                <h4 style="font-size:1rem; font-weight:700; margin:0 0 6px 0;">Upload CSV File</h4>
                <p style="font-size:0.8rem; color:#64748b; margin-bottom:1rem;">CSV file must contain columns: <code>chapter_name, chapter_code, subject_name, description</code>.</p>
                <input type="file" name="csv_file" accept=".csv" class="form-input" style="max-width:320px; margin:0 auto 12px auto; display:block;">
                <a href="studyplan-chapters.php?download_sample_csv=1" style="font-size:0.8rem; color:#8b5cf6; font-weight:600;"><i class="fas fa-download"></i> Download Sample CSV File</a>
            </div>
        </div>

        <div style="margin-top:1.5rem; text-align:right;">
            <button type="submit" class="btn btn-primary btn-lg" style="padding:10px 24px; font-weight:700;"><i class="fas fa-save"></i> Save Pre-set Chapters</button>
        </div>
    </form>
</div>

<!-- ── Data Table: Existing Pre-set Chapters List ── -->
<div class="panel" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.5rem; box-shadow:0 4px 20px rgba(0,0,0,0.03);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:1.2rem;">
        <div>
            <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-main); margin:0 0 4px 0;"><i class="fas fa-book-open" style="color:#10b981;"></i> Existing Pre-set Chapters</h3>
            <p style="font-size:0.8rem; color:var(--text-muted); margin:0;">Total <?php echo count($existing_chapters); ?> chapter(s) saved for selected filters.</p>
        </div>

        <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0;">
            <select name="academic_year" class="form-input" style="margin:0; width:160px;" onchange="this.form.submit()">
                <?php foreach ($academic_years as $yr): ?>
                    <option value="<?php echo htmlspecialchars($yr); ?>" <?php echo $selected_year === $yr ? 'selected' : ''; ?>>Year: <?php echo htmlspecialchars($yr); ?></option>
                <?php endforeach; ?>
            </select>

            <select name="course_filter" class="form-input" style="margin:0; width:180px;" onchange="this.form.submit()">
                <option value="0">All Courses</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $selected_course_filter == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['course_name']); ?></option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="search" class="form-input" placeholder="Search chapters..." style="margin:0; width:180px;" value="<?php echo htmlspecialchars($search_query); ?>">
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-filter"></i> Filter</button>
            <a href="studyplan-chapters.php" class="btn btn-outline btn-sm">Reset</a>
        </form>
    </div>

    <?php if (empty($existing_chapters)): ?>
        <div style="text-align:center; padding:3rem; color:var(--text-muted);">
            <i class="fas fa-book-bookmark" style="font-size:2.5rem; margin-bottom:10px; display:block; opacity:0.5;"></i>
            <p style="margin:0;">No pre-set chapters found for the selected filter criteria.</p>
        </div>
    <?php else: ?>
        <form method="POST" id="bulk-delete-form">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_delete">
            
            <div style="margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                <button type="submit" class="btn btn-soft-red btn-xs" onclick="return confirm('Are you sure you want to delete selected chapters?')" style="font-size:0.75rem;"><i class="fas fa-trash"></i> Delete Selected</button>
            </div>

            <div class="table-wrap">
                <table class="data-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" onclick="toggleAllTableCbs(this)"></th>
                            <th>Chapter Code</th>
                            <th>Chapter Name</th>
                            <th>Course Name</th>
                            <th>Subject</th>
                            <th>Academic Year</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($existing_chapters as $ch): ?>
                            <tr>
                                <td><input type="checkbox" name="chapter_ids[]" value="<?php echo $ch['id']; ?>" class="table-cb"></td>
                                <td><span class="badge" style="background:#f1f5f9; color:#475569; font-weight:700; border:1px solid #cbd5e1;"><?php echo htmlspecialchars($ch['chapter_code'] ?: '-'); ?></span></td>
                                <td>
                                    <strong style="color:var(--text-main); font-size:0.9rem;"><?php echo htmlspecialchars($ch['chapter_name']); ?></strong>
                                    <?php if ($ch['description']): ?>
                                        <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($ch['description']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($ch['course_name'] ?: 'Course #' . $ch['course_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($ch['subject_name'] ?: '-'); ?></td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars($ch['academic_year']); ?></span></td>
                                <td style="text-align:right;">
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete this chapter?')">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_chapter">
                                        <input type="hidden" name="chapter_id" value="<?php echo $ch['id']; ?>">
                                        <button type="submit" class="btn btn-soft-red btn-xs" title="Delete chapter"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
function switchEntryTab(mode) {
    document.getElementById('entry-mode-input').value = mode;
    document.querySelectorAll('.tab-toggle-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.style.borderBottom = '3px solid transparent';
        btn.style.color = '#64748b';
    });
    if (mode === 'manual') {
        document.getElementById('tab-btn-manual').classList.add('active');
        document.getElementById('tab-btn-manual').style.borderBottom = '3px solid var(--accent)';
        document.getElementById('tab-btn-manual').style.color = 'var(--accent)';
        document.getElementById('panel-manual').style.display = 'block';
        document.getElementById('panel-csv').style.display = 'none';
    } else {
        document.getElementById('tab-btn-csv').classList.add('active');
        document.getElementById('tab-btn-csv').style.borderBottom = '3px solid var(--accent)';
        document.getElementById('tab-btn-csv').style.color = 'var(--accent)';
        document.getElementById('panel-manual').style.display = 'none';
        document.getElementById('panel-csv').style.display = 'block';
    }
}

function selectAllCourses(select) {
    document.querySelectorAll('.course-cb').forEach(cb => {
        if (cb.offsetParent !== null) { // Only affect visible checkboxes
            cb.checked = select;
        }
    });
}

function filterCourseCheckboxes(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.course-cb-item').forEach(item => {
        var name = item.dataset.name || '';
        if (!q || name.indexOf(q) !== -1) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function addChapterRow() {
    var tbody = document.getElementById('manual-rows-body');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="text" name="chap_name[]" class="form-input" style="margin:0;" placeholder="e.g. Chapter Name" required></td>' +
                   '<td><input type="text" name="chap_code[]" class="form-input" style="margin:0;" placeholder="e.g. CH-03"></td>' +
                   '<td><input type="text" name="chap_subject[]" class="form-input" style="margin:0;" placeholder="e.g. Subject"></td>' +
                   '<td><input type="text" name="chap_desc[]" class="form-input" style="margin:0;" placeholder="Overview notes..."></td>' +
                   '<td style="text-align:center;"><button type="button" class="btn btn-xs btn-soft-red" onclick="removeChapterRow(this)"><i class="fas fa-trash"></i></button></td>';
    tbody.appendChild(tr);
}

function removeChapterRow(btn) {
    var tbody = document.getElementById('manual-rows-body');
    if (tbody.children.length > 1) {
        btn.closest('tr').remove();
    } else {
        alert('At least one row must remain.');
    }
}

function toggleAllTableCbs(master) {
    document.querySelectorAll('.table-cb').forEach(cb => cb.checked = master.checked);
}
</script>

<?php include 'includes/footer.php'; ?>
