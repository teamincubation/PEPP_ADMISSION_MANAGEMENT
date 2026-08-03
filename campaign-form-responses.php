<?php
require_once 'includes/auth.php';
require_permission('campaigns');
require_once 'config/database.php';

$form_id = (int)($_GET['id'] ?? 0);
if ($form_id <= 0) {
    die("<h3>Invalid Form ID</h3>");
}

// Fetch form details
$stmt = $pdo->prepare("SELECT * FROM campaign_forms WHERE id = ?");
$stmt->execute([$form_id]);
$form = $stmt->fetch();
if (!$form) {
    die("<h3>Form not found</h3>");
}

$page_title  = htmlspecialchars($form['title']) . ' — Responses';
$page_sub    = 'Review and export user submissions for this campaign';
$active_page = 'campaigns';

// Handle AJAX Response Detail View BEFORE layout output
if (isset($_GET['action']) && $_GET['action'] === 'load_detail') {
    $sub_id = (int)($_GET['sub_id'] ?? 0);
    
    // Fetch submission
    $stmt = $pdo->prepare("SELECT * FROM campaign_form_submissions WHERE id = ? AND form_id = ?");
    $stmt->execute([$sub_id, $form_id]);
    $sub = $stmt->fetch();

    if (!$sub) {
        echo "<div class='alert alert-danger'><i class='fas fa-triangle-exclamation'></i> Submission record not found.</div>";
        exit();
    }

    // Fetch ALL form fields LEFT JOIN campaign_form_answers to ensure complete response data is ALWAYS rendered
    $stmt = $pdo->prepare("
        SELECT f.label, f.type, f.id as field_id, a.answer_text, a.file_path 
        FROM campaign_form_fields f 
        LEFT JOIN campaign_form_answers a ON (a.field_id = f.id AND a.submission_id = ?)
        WHERE f.form_id = ? AND f.type != 'section'
        ORDER BY f.sort_order ASC
    ");
    $stmt->execute([$sub_id, $form_id]);
    $answers = $stmt->fetchAll();

    // Render Clean Modern UI Modal Content
    ?>
    <div style="display:flex; flex-direction:column; gap:1.2rem;">
        <!-- Respondent Badge Header -->
        <div style="background:var(--input-bg); border:1.5px solid var(--border); border-radius:16px; padding:1.2rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:46px; height:46px; border-radius:50%; background:rgba(232,152,12,0.15); border:1.5px solid var(--accent); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:1.2rem; font-weight:800;">
                    <i class="fas fa-user"></i>
                </div>
                <div>
                    <div style="font-size:0.75rem; text-transform:uppercase; font-weight:700; color:var(--text-muted); letter-spacing:0.5px;">Respondent</div>
                    <div style="font-size:1.1rem; font-weight:800; color:var(--text-main);"><?php echo htmlspecialchars($sub['respondent_identifier'] ?: 'Anonymous Respondent'); ?></div>
                </div>
            </div>

            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px; font-size:0.8rem; color:var(--text-muted);">
                <?php if (!empty($sub['is_converted_lead'])): ?>
                    <span class="badge green" style="padding:5px 12px; font-size:0.78rem; font-weight:700;"><i class="fas fa-user-check"></i> Converted to Lead</span>
                <?php else: ?>
                    <button type="button" class="btn btn-sm" onclick="openConvertLeadsModal(<?php echo $sub_id; ?>)" style="background:#16a34a; color:#fff; border:none; padding:5px 12px; font-size:0.75rem; font-weight:700; border-radius:8px;"><i class="fas fa-user-plus"></i> Convert to Lead</button>
                <?php endif; ?>
                <span><i class="fas fa-clock" style="color:var(--accent);"></i> <?php echo date('d M Y, h:i A', strtotime($sub['submitted_at'])); ?></span>
                <span><i class="fas fa-network-wired"></i> IP: <?php echo htmlspecialchars($sub['ip_address']); ?></span>
                <?php if (!empty($sub['latitude']) && !empty($sub['longitude'])): ?>
                    <span style="margin-top:2px;">
                        <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $sub['latitude']; ?>,<?php echo $sub['longitude']; ?>" target="_blank" class="btn btn-sm" style="background:rgba(239, 68, 68, 0.12); color:#ef4444; border:1px solid rgba(239, 68, 68, 0.25); padding:2px 8px; font-size:0.72rem; display:inline-flex; align-items:center; gap:4px; border-radius:6px; font-weight:700;">
                            <i class="fas fa-map-location-dot"></i> View exact Google Map Location
                        </a>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Submitted Answers Grid -->
        <div style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.2rem;">
            <h4 style="font-size:0.95rem; font-weight:800; color:var(--accent); margin-bottom:1rem; border-bottom:1.5px solid var(--border); padding-bottom:8px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-clipboard-check"></i> Form Response Data
            </h4>
            
            <div style="display:grid; grid-template-columns:1fr; gap:12px;">
                <?php foreach ($answers as $ans): 
                    $val = trim($ans['answer_text'] ?? '');
                    $file = $ans['file_path'] ?? null;
                    $icon = 'fa-pen-to-square';
                    if ($ans['type'] === 'phone') $icon = 'fa-phone';
                    elseif ($ans['type'] === 'whatsapp') $icon = 'fa-whatsapp';
                    elseif ($ans['type'] === 'email') $icon = 'fa-envelope';
                    elseif ($ans['type'] === 'location') $icon = 'fa-location-dot';
                    elseif ($ans['type'] === 'file') $icon = 'fa-paperclip';
                    elseif ($ans['type'] === 'rating') $icon = 'fa-star';
                ?>
                    <div style="background:var(--input-bg); border:1px solid var(--border); border-radius:12px; padding:12px 16px;">
                        <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                            <i class="fas <?php echo $icon; ?>" style="color:var(--accent);"></i>
                            <?php echo htmlspecialchars($ans['label']); ?>
                        </div>
                        
                        <?php if ($file): ?>
                            <div style="margin-top:4px;">
                                <a href="<?php echo htmlspecialchars($file); ?>" target="_blank" class="btn btn-sm btn-soft-blue" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                                    <i class="fas fa-download"></i> Download Attachment (<?php echo htmlspecialchars($val ?: 'File'); ?>)
                                </a>
                            </div>
                        <?php elseif ($ans['type'] === 'whatsapp' && !empty($val)): ?>
                            <div style="font-size:0.95rem; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                                <?php echo htmlspecialchars($val); ?>
                                <?php $num = preg_replace('/[^0-9]/', '', $val); ?>
                                <a href="https://wa.me/<?php echo $num; ?>" target="_blank" class="btn btn-sm btn-soft-green" style="padding:2px 8px; font-size:0.75rem;"><i class="fab fa-whatsapp"></i> Chat</a>
                            </div>
                        <?php elseif (!empty($val)): ?>
                            <div style="font-size:0.95rem; font-weight:700; color:var(--text-main); white-space:pre-line;">
                                <?php echo htmlspecialchars($val); ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size:0.85rem; font-style:italic; color:var(--text-muted); opacity:0.75;">
                                Not Provided
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Technical Metadata Footer -->
        <div style="background:var(--input-bg); border:1px solid var(--border); border-radius:12px; padding:10px 16px; font-size:0.78rem; color:var(--text-muted);">
            <div><strong>User Agent / Device Info:</strong> <?php echo htmlspecialchars($sub['user_agent']); ?></div>
        </div>
    </div>
    <?php
    exit();
}

// Fetch fields (excluding section breaks)
$stmt = $pdo->prepare("SELECT id, label, field_name, type FROM campaign_form_fields WHERE form_id = ? AND type != 'section' ORDER BY sort_order ASC");
$stmt->execute([$form_id]);
$fields = $stmt->fetchAll();

// Handle Export Routes (CSV/Excel) BEFORE layout output
if (isset($_GET['export'])) {
    $export_type = $_GET['export']; // 'csv' or 'excel'
    $export_scope = $_GET['scope'] ?? 'all'; // 'all', 'filtered', 'selected'
    $selected_ids = !empty($_GET['ids']) ? array_map('intval', explode(',', $_GET['ids'])) : [];

    // Base query
    $sql = "SELECT s.* FROM campaign_form_submissions s WHERE s.form_id = ?";
    $params = [$form_id];

    if ($export_scope === 'filtered') {
        $search = trim($_GET['search'] ?? '');
        if (!empty($search)) {
            $sql .= " AND (s.respondent_identifier LIKE ? OR s.ip_address LIKE ? OR s.id IN (
                SELECT submission_id FROM campaign_form_answers WHERE answer_text LIKE ?
            ))";
            $search_term = "%$search%";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        $start_date = $_GET['start_date'] ?? '';
        if (!empty($start_date)) {
            $sql .= " AND s.submitted_at >= ?";
            $params[] = $start_date . ' 00:00:00';
        }
        $end_date = $_GET['end_date'] ?? '';
        if (!empty($end_date)) {
            $sql .= " AND s.submitted_at <= ?";
            $params[] = $end_date . ' 23:59:59';
        }
    } elseif ($export_scope === 'selected' && !empty($selected_ids)) {
        $in_clause = implode(',', array_fill(0, count($selected_ids), '?'));
        $sql .= " AND s.id IN ($in_clause)";
        $params = array_merge($params, $selected_ids);
    }

    $sql .= " ORDER BY s.submitted_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $export_subs = $stmt->fetchAll();

    // Collect answers
    $sub_ids = array_column($export_subs, 'id');
    $answers_map = [];
    if (!empty($sub_ids)) {
        $in_clause_ans = implode(',', array_fill(0, count($sub_ids), '?'));
        $stmt_ans = $pdo->prepare("SELECT * FROM campaign_form_answers WHERE submission_id IN ($in_clause_ans)");
        $stmt_ans->execute($sub_ids);
        $all_ans = $stmt_ans->fetchAll();
        foreach ($all_ans as $ans) {
            $answers_map[$ans['submission_id']][$ans['field_id']] = $ans;
        }
    }

    $filename = preg_replace('/[^a-zA-Z0-9\-]/', '_', $form['title']) . "_responses_" . date('Ymd_His');

    if ($export_type === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $output = fopen('php://output', 'w');
        
        // Headers
        $headers = ['Submission ID', 'Submitted At', 'Respondent', 'IP Address', 'User Agent'];
        foreach ($fields as $f) {
            $headers[] = $f['label'];
        }
        fputcsv($output, $headers);

        // Rows
        foreach ($export_subs as $s) {
            $row = [
                $s['id'],
                $s['submitted_at'],
                $s['respondent_identifier'] ?: 'Anonymous',
                $s['ip_address'],
                $s['user_agent']
            ];
            foreach ($fields as $f) {
                $ans = $answers_map[$s['id']][$f['id']] ?? null;
                $row[] = $ans ? ($ans['file_path'] ? $ans['file_path'] : $ans['answer_text']) : '';
            }
            fputcsv($output, $row);
        }
        fclose($output);
        exit();

    } elseif ($export_type === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:x='urn:schemas-microsoft-com:office:excel' xmlns='http://www.w3.org/TR/REC-html40'>";
        echo "<head><meta charset='UTF-8'></head><body>";
        echo "<table border='1'>";
        
        // Headers
        echo "<tr style='background-color:#E8980C; color:#ffffff; font-weight:bold;'>";
        echo "<th>Submission ID</th><th>Submitted At</th><th>Respondent</th><th>IP Address</th><th>User Agent</th>";
        foreach ($fields as $f) {
            echo "<th>" . htmlspecialchars($f['label']) . "</th>";
        }
        echo "</tr>";

        // Rows
        foreach ($export_subs as $s) {
            echo "<tr>";
            echo "<td>" . $s['id'] . "</td>";
            echo "<td>" . $s['submitted_at'] . "</td>";
            echo "<td>" . htmlspecialchars($s['respondent_identifier'] ?: 'Anonymous') . "</td>";
            echo "<td>" . htmlspecialchars($s['ip_address']) . "</td>";
            echo "<td>" . htmlspecialchars($s['user_agent']) . "</td>";
            foreach ($fields as $f) {
                $ans = $answers_map[$s['id']][$f['id']] ?? null;
                $val = $ans ? ($ans['file_path'] ? $ans['file_path'] : $ans['answer_text']) : '';
                echo "<td>" . htmlspecialchars($val) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table></body></html>";
        exit();
    }
}

// Soft deletion handler for bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    if (!csrf_verify()) {
        $bulk_error = "Security token mismatch. Please try again.";
    } else {
        $ids = !empty($_POST['ids']) ? array_map('intval', explode(',', $_POST['ids'])) : [];
        if (!empty($ids)) {
            try {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $params_del = array_merge($ids, [$form_id]);
                $stmt = $pdo->prepare("UPDATE campaign_form_submissions SET is_deleted = 1, deleted_at = NOW() WHERE id IN ($in) AND form_id = ?");
                $stmt->execute($params_del);
                $bulk_message = "Selected submissions archived successfully.";
            } catch (Exception $e) {
                $bulk_error = "Delete failed: " . $e->getMessage();
            }
        }
    }
}

// Soft deletion handler for single item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_single') {
    if (!csrf_verify()) {
        $bulk_error = "Security token mismatch. Please try again.";
    } else {
        $sub_id = (int)($_POST['sub_id'] ?? 0);
        if ($sub_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE campaign_form_submissions SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND form_id = ?");
                $stmt->execute([$sub_id, $form_id]);
                $bulk_message = "Submission archived successfully.";
            } catch (Exception $e) {
                $bulk_error = "Delete failed: " . $e->getMessage();
            }
        }
    }
}

// Search, filtering, and pagination parameters
$search = trim($_GET['search'] ?? '');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, (int)($_GET['limit'] ?? 20));
$offset = ($page - 1) * $limit;

// Base queries for Submissions listing (excl. soft-deleted)
$sql_where = " WHERE s.form_id = ? AND s.is_deleted = 0";
$params = [$form_id];

if (!empty($search)) {
    $sql_where .= " AND (s.respondent_identifier LIKE ? OR s.ip_address LIKE ? OR s.id IN (
        SELECT submission_id FROM campaign_form_answers WHERE answer_text LIKE ?
    ))";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($start_date)) {
    $sql_where .= " AND s.submitted_at >= ?";
    $params[] = $start_date . ' 00:00:00';
}
if (!empty($end_date)) {
    $sql_where .= " AND s.submitted_at <= ?";
    $params[] = $end_date . ' 23:59:59';
}

// Count total
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM campaign_form_submissions s" . $sql_where);
$stmt_count->execute($params);
$total_rows = (int)$stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch Paginated rows
$sql_list = "SELECT s.* FROM campaign_form_submissions s " . $sql_where . " ORDER BY s.submitted_at DESC LIMIT $limit OFFSET $offset";
$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute($params);
$submissions = $stmt_list->fetchAll();

// Fetch Answers for page rows
$sub_ids = array_column($submissions, 'id');
$answers_map = [];
if (!empty($sub_ids)) {
    $in_clause_ans = implode(',', array_fill(0, count($sub_ids), '?'));
    $stmt_ans = $pdo->prepare("SELECT * FROM campaign_form_answers WHERE submission_id IN ($in_clause_ans)");
    $stmt_ans->execute($sub_ids);
    $all_ans = $stmt_ans->fetchAll();
    foreach ($all_ans as $ans) {
        $answers_map[$ans['submission_id']][$ans['field_id']] = $ans;
    }
}

// Calculate metric stats
$total_submissions_count = (int)$pdo->query("SELECT COUNT(*) FROM campaign_form_submissions WHERE form_id = $form_id AND is_deleted = 0")->fetchColumn();
$today_submissions_count = (int)$pdo->query("SELECT COUNT(*) FROM campaign_form_submissions WHERE form_id = $form_id AND is_deleted = 0 AND DATE(submitted_at) = CURDATE()")->fetchColumn();

$total_converted_leads = 0;
try {
    $total_converted_leads = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE source = 'campaign_form'")->fetchColumn();
} catch (Exception $e) {}

$last_sub_at = $pdo->query("SELECT submitted_at FROM campaign_form_submissions WHERE form_id = $form_id AND is_deleted = 0 ORDER BY submitted_at DESC LIMIT 1")->fetchColumn();
$last_sub_formatted = $last_sub_at ? date('d M, h:i A', strtotime($last_sub_at)) : 'No submissions yet';

// Fetch active PEPP courses for Convert to Leads modal
$course_list = [];
try {
    $course_list = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses WHERE course_name IS NOT NULL AND course_name != '' ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
if (empty($course_list)) {
    $course_list = ['CUET PG', 'CUET UG', 'NET / JRF', 'KSET / SET', 'Civil Services', 'Other Courses'];
}

// Setup Column visibility (Save in cookie, fallback all active)
$col_cookie = 'form_cols_hide_' . $form_id;
$hidden_cols = isset($_COOKIE[$col_cookie]) ? explode(',', $_COOKIE[$col_cookie]) : [];

include 'includes/admin_nav.php';
?>

<style>
    .campaign-header-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .stat-badge-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .metric-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 1.25rem 1.4rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .metric-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .responses-control-bar {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 1.2rem;
        margin-bottom: 1.5rem;
    }

    .export-dropdown {
        position: relative;
        display: inline-block;
    }
    .dropdown-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 12px;
        box-shadow: 0 12px 35px rgba(0,0,0,0.3);
        z-index: 100;
        min-width: 185px;
        overflow: hidden;
    }
    .dropdown-menu.open {
        display: block;
    }
    .dropdown-item {
        display: block;
        padding: 10px 16px;
        font-size: 0.85rem;
        font-weight: 600;
        text-decoration: none;
        color: var(--text-main);
        transition: background 0.2s;
        cursor: pointer;
        border: none;
        background: transparent;
        width: 100%;
        text-align: left;
    }
    .dropdown-item:hover {
        background: var(--border);
        color: var(--accent);
    }

    .column-picker-modal {
        max-height: 320px;
        overflow-y: auto;
        padding: 6px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .col-row {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.88rem;
        font-weight: 600;
        padding: 6px 10px;
        border-radius: 8px;
        transition: background 0.2s;
        cursor: pointer;
    }
    .col-row:hover {
        background: var(--input-bg);
    }

    /* Table styling tweaks */
    .table-responsive-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    }
</style>

<?php if (isset($bulk_message)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span><?php echo htmlspecialchars($bulk_message); ?></span></div>
<?php endif; ?>
<?php if (isset($bulk_error)): ?>
    <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <span><?php echo htmlspecialchars($bulk_error); ?></span></div>
<?php endif; ?>

<!-- ── CAMPAIGN HEADER & NAVIGATION CARD ── -->
<div class="campaign-header-card">
    <div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:6px;">
            <h2 style="font-size:1.4rem; font-weight:800; color:var(--text-main); margin:0;"><?php echo htmlspecialchars($form['title']); ?></h2>
            <span style="background:rgba(232,152,12,0.12); color:var(--accent); border:1px solid rgba(232,152,12,0.3); font-size:0.75rem; font-weight:700; padding:3px 10px; border-radius:20px; font-family:monospace;">
                /f/<?php echo htmlspecialchars($form['slug']); ?>
            </span>
        </div>
        <div style="font-size:0.85rem; color:var(--text-muted);">
            Campaign Form Submissions Management &amp; Analytics
        </div>
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="f.php?slug=<?php echo htmlspecialchars($form['slug']); ?>" target="_blank" class="btn btn-secondary" style="padding:0.55rem 1.1rem; font-size:0.85rem;"><i class="fas fa-arrow-up-right-from-square" style="color:var(--accent);"></i> View Form</a>
        <a href="campaign-form-builder.php?id=<?php echo $form_id; ?>" class="btn btn-secondary" style="padding:0.55rem 1.1rem; font-size:0.85rem;"><i class="fas fa-pen-to-square"></i> Edit Builder</a>
        <a href="campaign-forms.php" class="btn btn-secondary" style="padding:0.55rem 1.1rem; font-size:0.85rem;"><i class="fas fa-arrow-left"></i> All Forms</a>
    </div>
</div>

<!-- ── METRICS SUMMARY STAT CARDS ── -->
<div class="stat-badge-grid">
    <div class="metric-card">
        <div>
            <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Total Submissions</div>
            <div style="font-size:1.75rem; font-weight:800; color:var(--text-main); margin-top:4px;"><?php echo number_format($total_submissions_count); ?></div>
        </div>
        <div style="width:46px; height:46px; border-radius:14px; background:rgba(232,152,12,0.12); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
            <i class="fas fa-inbox"></i>
        </div>
    </div>

    <div class="metric-card">
        <div>
            <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Today's Entries</div>
            <div style="font-size:1.75rem; font-weight:800; color:#3b82f6; margin-top:4px;"><?php echo number_format($today_submissions_count); ?></div>
        </div>
        <div style="width:46px; height:46px; border-radius:14px; background:rgba(59,130,246,0.12); color:#3b82f6; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
            <i class="fas fa-calendar-day"></i>
        </div>
    </div>

    <div class="metric-card">
        <div>
            <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Converted Leads</div>
            <div style="font-size:1.75rem; font-weight:800; color:#16a34a; margin-top:4px;"><?php echo number_format($total_converted_leads); ?></div>
        </div>
        <div style="width:46px; height:46px; border-radius:14px; background:rgba(22,163,74,0.12); color:#16a34a; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
            <i class="fas fa-user-check"></i>
        </div>
    </div>

    <div class="metric-card">
        <div>
            <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Last Submission</div>
            <div style="font-size:0.95rem; font-weight:700; color:var(--text-main); margin-top:6px;"><?php echo $last_sub_formatted; ?></div>
        </div>
        <div style="width:46px; height:46px; border-radius:14px; background:rgba(168,85,247,0.12); color:#a855f7; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
            <i class="fas fa-clock-rotate-left"></i>
        </div>
    </div>
</div>

<!-- ── CONTROL & SEARCH BAR ── -->
<div class="responses-control-bar">
    
    <!-- Filter Search Form -->
    <form method="GET" action="" style="display:flex; flex-wrap:wrap; gap:10px; flex:1; max-width:820px; align-items:center;">
        <input type="hidden" name="id" value="<?php echo $form_id; ?>">
        
        <div style="position:relative; flex:1; min-width:220px;">
            <i class="fas fa-magnifying-glass" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--accent); font-size:0.9rem;"></i>
            <input type="text" name="search" placeholder="Search responses by name, email, phone, IP..." class="form-input" style="margin-bottom:0; padding-left:42px; padding-right:12px; border-radius:14px; height:44px; font-weight:500;" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <div style="display:flex; align-items:center; gap:8px; background:var(--input-bg); border:1.5px solid var(--border); padding:2px 12px; border-radius:14px; height:44px;">
            <span style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;"><i class="fas fa-calendar-day" style="color:var(--accent);"></i> From</span>
            <input type="date" name="start_date" class="form-input" style="margin-bottom:0; border:none; background:transparent; padding:4px 0; height:auto; width:130px; font-size:0.85rem; font-weight:600; color:var(--text-main);" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>

        <div style="display:flex; align-items:center; gap:8px; background:var(--input-bg); border:1.5px solid var(--border); padding:2px 12px; border-radius:14px; height:44px;">
            <span style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;"><i class="fas fa-calendar-check" style="color:var(--accent);"></i> To</span>
            <input type="date" name="end_date" class="form-input" style="margin-bottom:0; border:none; background:transparent; padding:4px 0; height:auto; width:130px; font-size:0.85rem; font-weight:600; color:var(--text-main);" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>
        
        <button type="submit" class="btn btn-primary" style="padding:0 1.4rem; height:44px; border-radius:14px; font-weight:700; display:flex; align-items:center; gap:6px;"><i class="fas fa-filter"></i> Filter</button>
        <?php if (!empty($search) || !empty($start_date) || !empty($end_date)): ?>
            <a href="campaign-form-responses.php?id=<?php echo $form_id; ?>" class="btn btn-secondary" style="padding:0 1rem; height:44px; border-radius:14px; display:flex; align-items:center; justify-content:center;" title="Reset Filter"><i class="fas fa-rotate-left"></i></a>
        <?php endif; ?>
    </form>

    <!-- Toolbar Actions -->
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <button class="btn btn-secondary" onclick="openModal('col-picker-modal')" style="padding:0.6rem 1.1rem; border-radius:12px;"><i class="fas fa-columns"></i> Columns</button>
        
        <!-- Bulk Action Wrapper -->
        <div id="bulk-actions" style="display:none; gap:10px; align-items:center;">
            <button class="btn btn-secondary" style="padding:0.6rem 1.1rem; border-radius:12px; border-color:#16a34a; color:#16a34a; background:rgba(22, 163, 74, 0.08);" onclick="openConvertLeadsModal()"><i class="fas fa-user-plus"></i> Convert to Leads (<span id="bulk-convert-count">0</span>)</button>
            <button class="btn btn-secondary" style="padding:0.6rem 1.1rem; border-radius:12px; border-color:#ef4444; color:#ef4444;" onclick="bulkDelete()"><i class="fas fa-trash-can"></i> Delete (<span id="bulk-count">0</span>)</button>
            
            <div class="export-dropdown">
                <button class="btn btn-secondary" onclick="toggleDropdown('bulk-export-menu')" style="padding:0.6rem 1.1rem; border-radius:12px;"><i class="fas fa-download"></i> Export Selected</button>
                <div class="dropdown-menu" id="bulk-export-menu">
                    <button class="dropdown-item" onclick="triggerExport('csv', 'selected')"><i class="fas fa-file-csv"></i> CSV Format</button>
                    <button class="dropdown-item" onclick="triggerExport('excel', 'selected')"><i class="fas fa-file-excel"></i> Excel Format</button>
                </div>
            </div>
        </div>

        <div class="export-dropdown" id="export-all-wrapper">
            <button class="btn btn-primary" onclick="toggleDropdown('export-menu')" style="padding:0.6rem 1.2rem; border-radius:12px;"><i class="fas fa-download"></i> Export Responses</button>
            <div class="dropdown-menu" id="export-menu">
                <div style="padding: 6px 12px; font-size: 0.7rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; border-bottom: 1px solid var(--border);">All Submissions</div>
                <button class="dropdown-item" onclick="triggerExport('csv', 'all')"><i class="fas fa-file-csv"></i> Export all (CSV)</button>
                <button class="dropdown-item" onclick="triggerExport('excel', 'all')"><i class="fas fa-file-excel"></i> Export all (Excel)</button>
                
                <?php if (!empty($search) || !empty($start_date) || !empty($end_date)): ?>
                    <div style="padding: 6px 12px; font-size: 0.7rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">Filtered Data</div>
                    <button class="dropdown-item" onclick="triggerExport('csv', 'filtered')"><i class="fas fa-file-csv"></i> Export filtered (CSV)</button>
                    <button class="dropdown-item" onclick="triggerExport('excel', 'filtered')"><i class="fas fa-file-excel"></i> Export filtered (Excel)</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── DATA TABLE CARD ── -->
<div class="table-responsive-card">
    <table class="data-table" style="width:100%; border-collapse:collapse; min-width:800px;">
        <thead>
            <tr style="border-bottom:1.5px solid var(--border); text-align:left; background:var(--input-bg);">
                <th style="padding:15px; width:45px; text-align:center;"><input type="checkbox" id="select-all" onclick="toggleSelectAll(this)" style="accent-color:var(--accent);"></th>
                <th style="padding:15px; font-weight:800; font-size:0.8rem; text-transform:uppercase; color:var(--text-muted);">Submission At</th>
                <th style="padding:15px; font-weight:800; font-size:0.8rem; text-transform:uppercase; color:var(--text-muted);">Respondent</th>
                
                <!-- Dynamic Columns -->
                <?php foreach ($fields as $f): 
                    $hid = in_array((string)$f['id'], $hidden_cols) ? 'display:none;' : '';
                ?>
                    <th style="padding:15px; font-weight:800; font-size:0.8rem; text-transform:uppercase; color:var(--text-muted); <?php echo $hid; ?>" class="dyn-col" data-colid="<?php echo $f['id']; ?>">
                        <?php echo htmlspecialchars($f['label']); ?>
                    </th>
                <?php endforeach; ?>
                
                <th style="padding:15px; font-weight:800; font-size:0.8rem; text-transform:uppercase; color:var(--text-muted); text-align:center;">IP Address</th>
                <th style="padding:15px; font-weight:800; font-size:0.8rem; text-transform:uppercase; color:var(--text-muted); text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($submissions)): ?>
                <tr>
                    <td colspan="<?php echo count($fields) + 5; ?>" style="padding:50px 20px; text-align:center; color:var(--text-muted);">
                        <div style="width:70px; height:70px; border-radius:50%; background:var(--input-bg); border:1.5px solid var(--border); display:flex; align-items:center; justify-content:center; margin:0 auto 1rem auto; font-size:2rem; color:var(--accent);">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <h4 style="font-weight:800; font-size:1.1rem; color:var(--text-main); margin-bottom:4px;">No Responses Found</h4>
                        <p style="font-size:0.85rem;">No user responses have been recorded for this form yet.</p>
                    </td>
                </tr>
            <?php else: foreach ($submissions as $s): ?>
                <tr style="border-bottom:1px solid var(--border); transition:background 0.2s;" class="table-row">
                    <td style="padding:15px; text-align:center;">
                        <input type="checkbox" class="row-checkbox" value="<?php echo $s['id']; ?>" onclick="updateRowSelection()" style="accent-color:var(--accent);">
                    </td>
                    <td style="padding:15px; white-space:nowrap; font-size:0.85rem; font-weight:700; color:var(--text-main);">
                        <?php echo date('d M Y, h:i A', strtotime($s['submitted_at'])); ?>
                    </td>
                    <td style="padding:15px; font-weight:700; font-size:0.9rem; color:var(--text-main);">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div style="width:28px; height:28px; border-radius:50%; background:rgba(232,152,12,0.15); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800;">
                                <i class="fas fa-user"></i>
                            </div>
                            <?php echo htmlspecialchars($s['respondent_identifier'] ?: 'Anonymous'); ?>
                        </div>
                    </td>

                    <!-- Dynamic Answer Columns -->
                    <?php foreach ($fields as $f): 
                        $hid = in_array((string)$f['id'], $hidden_cols) ? 'display:none;' : '';
                        $ans = $answers_map[$s['id']][$f['id']] ?? null;
                        
                        $val = $ans ? $ans['answer_text'] : '';
                        $file = $ans ? $ans['file_path'] : null;
                        
                        // Formatting
                        if (empty($val) && empty($file)) {
                            $disp_val = '<span style="color:var(--text-muted); opacity:0.6;">-</span>';
                        } elseif ($file) {
                            $disp_val = "<a href='{$file}' target='_blank' class='btn btn-sm btn-soft-blue' style='padding:2px 8px; font-size:0.75rem; text-decoration:none;'><i class='fas fa-paperclip'></i> File</a>";
                        } else {
                            $disp_val = htmlspecialchars(strlen($val) > 40 ? substr($val, 0, 40) . '...' : $val);
                        }
                    ?>
                        <td style="padding:15px; <?php echo $hid; ?> font-size:0.85rem;" class="dyn-col" data-colid="<?php echo $f['id']; ?>">
                            <?php echo $disp_val; ?>
                        </td>
                    <?php endforeach; ?>

                    <td style="padding:15px; text-align:center; font-family:monospace; font-size:0.8rem; color:var(--text-muted);">
                        <div style="display:inline-flex; align-items:center; gap:6px; justify-content:center; width:100%;">
                            <span><?php echo htmlspecialchars($s['ip_address']); ?></span>
                            <?php if (!empty($s['latitude']) && !empty($s['longitude'])): ?>
                                <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $s['latitude']; ?>,<?php echo $s['longitude']; ?>" target="_blank" style="color:#ef4444; font-size:0.85rem;" title="View Google Map Location"><i class="fas fa-map-location-dot"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="padding:15px; text-align:right;">
                        <div style="display:flex; justify-content:flex-end; gap:6px; align-items:center;">
                            <button type="button" class="btn btn-sm btn-soft-blue" onclick="viewResponseDetail(<?php echo $s['id']; ?>)" title="View Details"><i class="fas fa-eye"></i></button>
                            <?php if (!empty($s['is_converted_lead'])): ?>
                                <span class="badge green" style="padding:4px 8px; font-size:0.75rem; font-weight:700;" title="Already Converted to Lead"><i class="fas fa-user-check"></i> Converted</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm" onclick="openConvertLeadsModal(<?php echo $s['id']; ?>)" style="background:rgba(22, 163, 74, 0.12); color:#16a34a; border:1px solid rgba(22, 163, 74, 0.25);" title="Convert to Lead"><i class="fas fa-user-plus"></i></button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-soft-red" onclick="openDeleteModal(<?php echo $s['id']; ?>)" title="Delete Submission"><i class="fas fa-trash-can"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- ── PAGINATION ── -->
<?php if ($total_pages > 1): ?>
<div style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem; flex-wrap:wrap; gap:10px;">
    <div style="font-size:0.85rem; color:var(--text-muted);">
        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> entries
    </div>
    
    <div style="display:flex; gap:5px;">
        <?php if ($page > 1): ?>
            <a href="?id=<?php echo $form_id; ?>&page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&limit=<?php echo $limit; ?>" class="btn btn-sm btn-secondary">&laquo; Prev</a>
        <?php endif; ?>
        
        <?php for ($i = 1; $i <= $total_pages; $i++): 
            $active = ($i === $page) ? 'btn-primary' : 'btn-secondary';
        ?>
            <a href="?id=<?php echo $form_id; ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&limit=<?php echo $limit; ?>" class="btn btn-sm <?php echo $active; ?>" style="min-width:32px; text-align:center; padding:5px;"><?php echo $i; ?></a>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
            <a href="?id=<?php echo $form_id; ?>&page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&limit=<?php echo $limit; ?>" class="btn btn-sm btn-secondary">Next &raquo;</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── RESPONSE DETAIL MODAL ── -->
<div class="modal-backdrop" id="response-detail-modal">
    <div class="modal" style="max-width:700px; width:100%;">
        <div class="modal-head">
            <h3><i class="fas fa-eye" style="color:var(--accent);"></i> Submission Detailed Review</h3>
            <button class="modal-close" onclick="closeModal('response-detail-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="detail-modal-body">
            <!-- Loaded dynamically in JavaScript -->
        </div>
    </div>
</div>

<!-- ── DYNAMIC COLUMNS PICKER MODAL ── -->
<div class="modal-backdrop" id="col-picker-modal">
    <div class="modal" style="max-width:400px; width:100%;">
        <div class="modal-head">
            <h3><i class="fas fa-columns" style="color:var(--accent);"></i> Manage Columns</h3>
            <button class="modal-close" onclick="closeModal('col-picker-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:12px;">Toggle column headers visibility in the responses listing table:</p>
            <div class="column-picker-modal">
                <?php foreach ($fields as $f): 
                    $checked = !in_array((string)$f['id'], $hidden_cols) ? 'checked' : '';
                ?>
                    <label class="col-row">
                        <input type="checkbox" class="col-toggle" value="<?php echo $f['id']; ?>" <?php echo $checked; ?> onclick="toggleColumnVisibility(this)">
                        <span><?php echo htmlspecialchars($f['label']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:12px;">
                <button class="btn btn-sm btn-primary" onclick="closeModal('col-picker-modal')">Apply &amp; Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ── SAFE DELETION CONFIRMATION MODAL ── -->
<div class="modal-backdrop" id="delete-confirm-modal">
    <div class="modal" style="max-width:460px; width:92%;">
        <div class="modal-head">
            <h3 style="color:#ef4444;"><i class="fas fa-triangle-exclamation"></i> Confirm Permanent Deletion</h3>
            <button type="button" class="modal-close" onclick="closeModal('delete-confirm-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.88rem; color:var(--text-muted); margin-bottom:1rem;">
                You are about to archive <strong id="delete-count-label" style="color:#ef4444;">1</strong> response submission(s). To confirm this action, please type <strong style="color:var(--text-main); font-family:monospace; background:var(--input-bg); padding:2px 6px; border-radius:4px;">DELETE</strong> in the box below:
            </p>

            <div class="field full" style="margin-bottom:1.2rem;">
                <input type="text" id="delete-confirm-input" class="form-input" placeholder="Type DELETE to confirm" style="width:100%; text-align:center; font-weight:700; letter-spacing:1px;" oninput="onDeleteConfirmInput(this)">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('delete-confirm-modal')">Cancel</button>
            <button type="button" class="btn btn-danger" id="btn-execute-delete" disabled onclick="executeConfirmedDelete()"><i class="fas fa-trash-can"></i> Permanently Delete</button>
        </div>
    </div>
</div>

<!-- Hidden forms for delete submission execution with CSRF protection -->
<form method="POST" action="" id="single-delete-form" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="delete_single">
    <input type="hidden" name="sub_id" id="single-delete-sub-id">
</form>

<form method="POST" action="" id="bulk-delete-form" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="ids" id="bulk-delete-ids">
</form>

<script>
    var pendingDeleteSubId = null;
    var formId = <?php echo $form_id; ?>;

    // Handles toggling individual menus
    function toggleDropdown(id) {
        var menu = document.getElementById(id);
        var open = menu.classList.contains('open');
        closeAllDropdowns();
        if (!open) {
            menu.classList.add('open');
        }
    }

    function closeAllDropdowns() {
        document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('open'));
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.export-dropdown')) {
            closeAllDropdowns();
        }
    });

    // Checkbox selectors
    function toggleSelectAll(master) {
        var checks = document.querySelectorAll('.row-checkbox');
        checks.forEach(c => c.checked = master.checked);
        updateRowSelection();
    }

    function updateRowSelection() {
        var selected = [];
        document.querySelectorAll('.row-checkbox:checked').forEach(c => {
            selected.push(c.value);
        });

        var bulkBar = document.getElementById('bulk-actions');
        var exportAll = document.getElementById('export-all-wrapper');
        
        if (selected.length > 0) {
            bulkBar.style.display = 'inline-flex';
            exportAll.style.display = 'none';
            document.getElementById('bulk-count').textContent = selected.length;
            var convertBadge = document.getElementById('bulk-convert-count');
            if (convertBadge) convertBadge.textContent = selected.length;
        } else {
            bulkBar.style.display = 'none';
            exportAll.style.display = 'inline-block';
            document.getElementById('select-all').checked = false;
        }
    }

    function triggerExport(type, scope) {
        var url = 'campaign-form-responses.php?id=<?php echo $form_id; ?>&export=' + type + '&scope=' + scope;
        
        if (scope === 'filtered') {
            url += '&search=' + encodeURIComponent('<?php echo $search; ?>') +
                   '&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>';
        } else if (scope === 'selected') {
            var selected = [];
            document.querySelectorAll('.row-checkbox:checked').forEach(c => selected.push(c.value));
            url += '&ids=' + selected.join(',');
        }

        window.location.href = url;
    }

    function openDeleteModal(subId) {
        pendingDeleteSubId = subId;
        var count = subId ? 1 : document.querySelectorAll('.row-checkbox:checked').length;
        if (count === 0 && !subId) return;

        document.getElementById('delete-count-label').textContent = count;
        var input = document.getElementById('delete-confirm-input');
        input.value = '';
        document.getElementById('btn-execute-delete').disabled = true;
        openModal('delete-confirm-modal');
    }

    function onDeleteConfirmInput(input) {
        var btn = document.getElementById('btn-execute-delete');
        if (input.value.trim() === 'DELETE') {
            btn.disabled = false;
        } else {
            btn.disabled = true;
        }
    }

    function executeConfirmedDelete() {
        if (pendingDeleteSubId) {
            document.getElementById('single-delete-sub-id').value = pendingDeleteSubId;
            document.getElementById('single-delete-form').submit();
        } else {
            var selected = [];
            document.querySelectorAll('.row-checkbox:checked').forEach(c => selected.push(c.value));
            if (selected.length === 0) return;
            document.getElementById('bulk-delete-ids').value = selected.join(',');
            document.getElementById('bulk-delete-form').submit();
        }
    }

    function bulkDelete() {
        openDeleteModal(null);
    }

    // Dynamic Columns hide/show
    function toggleColumnVisibility(chk) {
        var colId = chk.value;
        var hide = !chk.checked;
        
        // Hide table columns
        document.querySelectorAll('.dyn-col[data-colid="' + colId + '"]').forEach(cell => {
            cell.style.display = hide ? 'none' : '';
        });

        // Save preferences to cookie
        var hidden = [];
        document.querySelectorAll('.col-toggle:not(:checked)').forEach(c => hidden.push(c.value));
        
        // Set cookie
        var d = new Date();
        d.setTime(d.getTime() + (365*24*60*60*1000));
        document.cookie = 'form_cols_hide_<?php echo $form_id; ?>=' + hidden.join(',') + ';expires=' + d.toUTCString() + ';path=/';
    }

    // View submission detail loader
    function viewResponseDetail(subId) {
        var body = document.getElementById('detail-modal-body');
        body.innerHTML = '<div style="text-align:center; padding:2rem;"><i class="fas fa-spinner fa-spin" style="font-size:2rem; color:var(--accent);"></i><p style="margin-top:8px;">Loading response data...</p></div>';
        openModal('response-detail-modal');

        fetch('?id=<?php echo $form_id; ?>&action=load_detail&sub_id=' + subId)
        .then(r => r.text())
        .then(html => {
            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = '<div class="alert alert-danger">Failed to load details.</div>';
        });
    }

    // Convert Registrations to Leads Handlers
    var targetConvertSubIds = [];

    function openConvertLeadsModal(singleSubId) {
        if (singleSubId) {
            targetConvertSubIds = [singleSubId];
        } else {
            targetConvertSubIds = [];
            document.querySelectorAll('.row-checkbox:checked').forEach(function(cb) {
                targetConvertSubIds.push(parseInt(cb.value));
            });
        }

        if (targetConvertSubIds.length === 0) {
            alert('Please select at least one registration response row to convert.');
            return;
        }

        document.getElementById('convert-count-label').textContent = targetConvertSubIds.length;
        document.getElementById('convert-results-box').style.display = 'none';
        openModal('convert-leads-modal');
    }

    function closeConvertLeadsModal() {
        closeModal('convert-leads-modal');
    }

    function submitConvertLeads() {
        var course = document.getElementById('convert-course-select').value;
        if (!course) {
            alert('Please select a course to assign leads to.');
            return;
        }

        var btn = document.getElementById('btn-submit-convert');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Converting...';

        var fd = new FormData();
        fd.append('action', 'convert_to_leads');
        fd.append('form_id', formId);
        fd.append('course_name', course);
        fd.append('sub_ids', targetConvertSubIds.join(','));

        fetch('api/campaign-forms.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Convert Now';

            if (res.success) {
                var box = document.getElementById('convert-results-box');
                box.style.display = 'block';
                
                var html = '<div style="color:#16a34a; font-weight:700; margin-bottom:4px;"><i class="fas fa-circle-check"></i> ' + res.converted + ' lead(s) created successfully!</div>';
                if (res.skipped > 0) {
                    html += '<div style="color:#eab308; font-weight:600;"><i class="fas fa-triangle-exclamation"></i> ' + res.skipped + ' skipped.</div>';
                }
                if (res.errors && res.errors.length > 0) {
                    html += '<ul style="margin-top:6px; padding-left:18px; color:#ef4444;">';
                    res.errors.forEach(err => {
                        html += '<li>' + err + '</li>';
                    });
                    html += '</ul>';
                }
                box.innerHTML = html;

                if (res.converted > 0 && res.skipped === 0) {
                    setTimeout(function() {
                        closeConvertLeadsModal();
                    }, 1800);
                }
            } else {
                alert(res.message || 'Failed to convert registrations.');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Convert Now';
            alert('Network error communicating with server.');
        });
    }
</script>

<!-- Convert Registrations to Leads Modal -->
<div class="modal-backdrop" id="convert-leads-modal">
    <div class="modal" style="max-width:460px; width:92%;">
        <div class="modal-head">
            <h3><i class="fas fa-user-plus" style="color:#16a34a;"></i> Convert to Leads</h3>
            <button type="button" class="modal-close" onclick="closeConvertLeadsModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.88rem; color:var(--text-muted); margin-bottom:1.2rem;">
                Select a target course to assign <strong id="convert-count-label" style="color:var(--accent);">0</strong> registration(s) to. Original entries in this campaign form will remain intact.
            </p>

            <div class="field full" style="margin-bottom:1.2rem;">
                <label style="font-size:0.78rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); display:block; margin-bottom:6px;">Target Course <span style="color:#ef4444;">*</span></label>
                <select id="convert-course-select" class="form-input" style="width:100%; padding:0.7rem 0.9rem; border-radius:10px; background:var(--input-bg); border:1.5px solid var(--border); color:var(--text-main); font-weight:600;">
                    <option value="">-- Select Course --</option>
                    <?php foreach ($course_list as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="convert-results-box" style="display:none; font-size:0.82rem; margin-bottom:1rem; padding:12px; border-radius:10px; background:var(--input-bg); border:1.5px solid var(--border);"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeConvertLeadsModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="btn-submit-convert" onclick="submitConvertLeads()" style="background:#16a34a; border-color:#16a34a;"><i class="fas fa-check"></i> Convert Now</button>
        </div>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
