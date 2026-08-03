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

    // Fetch answers
    $stmt = $pdo->prepare("
        SELECT a.answer_text, a.file_path, f.label, f.type 
        FROM campaign_form_answers a
        JOIN campaign_form_fields f ON a.field_id = f.id
        WHERE a.submission_id = ?
        ORDER BY f.sort_order ASC
    ");
    $stmt->execute([$sub_id]);
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

            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:4px; font-size:0.8rem; color:var(--text-muted);">
                <span><i class="fas fa-clock" style="color:var(--accent);"></i> <?php echo date('d M Y, h:i A', strtotime($sub['submitted_at'])); ?></span>
                <span><i class="fas fa-network-wired"></i> IP: <?php echo htmlspecialchars($sub['ip_address']); ?></span>
            </div>
        </div>

        <!-- Submitted Answers Grid -->
        <div style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.2rem;">
            <h4 style="font-size:0.95rem; font-weight:800; color:var(--accent); margin-bottom:1rem; border-bottom:1.5px solid var(--border); padding-bottom:8px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-clipboard-check"></i> Form Response Data
            </h4>
            
            <div style="display:grid; grid-template-columns:1fr; gap:12px;">
                <?php foreach ($answers as $ans): 
                    $val = $ans['answer_text'];
                    $file = $ans['file_path'];
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
                                    <i class="fas fa-download"></i> Download Attachment (<?php echo htmlspecialchars($val); ?>)
                                </a>
                            </div>
                        <?php elseif ($ans['type'] === 'whatsapp'): ?>
                            <div style="font-size:0.95rem; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                                <?php echo htmlspecialchars($val ?: '-'); ?>
                                <?php if (!empty($val)): 
                                    $num = preg_replace('/[^0-9]/', '', $val);
                                ?>
                                    <a href="https://wa.me/<?php echo $num; ?>" target="_blank" class="btn btn-sm btn-soft-green" style="padding:2px 8px; font-size:0.75rem;"><i class="fab fa-whatsapp"></i> Chat</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size:0.95rem; font-weight:700; color:var(--text-main); white-space:pre-line;">
                                <?php echo htmlspecialchars($val ?: '-'); ?>
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

// Bulk deletion handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    $ids = !empty($_POST['ids']) ? array_map('intval', explode(',', $_POST['ids'])) : [];
    if (!empty($ids)) {
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM campaign_form_submissions WHERE id IN ($in)");
            $stmt->execute($ids);
            $bulk_message = "Selected submissions deleted successfully.";
        } catch (Exception $e) {
            $bulk_error = "Delete failed: " . $e->getMessage();
        }
    }
}

// Single delete handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_single') {
    $sub_id = (int)($_POST['sub_id'] ?? 0);
    if ($sub_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM campaign_form_submissions WHERE id = ?");
            $stmt->execute([$sub_id]);
            $bulk_message = "Submission deleted successfully.";
        } catch (Exception $e) {
            $bulk_error = "Delete failed: " . $e->getMessage();
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

// Base queries for Submissions listing
$sql_where = " WHERE s.form_id = ?";
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

// Setup Column visibility (Save in cookie, fallback all active)
$col_cookie = 'form_cols_hide_' . $form_id;
$hidden_cols = isset($_COOKIE[$col_cookie]) ? explode(',', $_COOKIE[$col_cookie]) : [];

include 'includes/admin_nav.php';
?>

<style>
    .responses-control-bar {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 16px;
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
        border-radius: 8px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.25);
        z-index: 100;
        min-width: 170px;
        overflow: hidden;
    }
    .dropdown-menu.open {
        display: block;
    }
    .dropdown-item {
        display: block;
        padding: 8px 15px;
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
        max-height: 300px;
        overflow-y: auto;
        padding: 10px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .col-row {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.88rem;
    }
</style>

<?php if (isset($bulk_message)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span><?php echo htmlspecialchars($bulk_message); ?></span></div>
<?php endif; ?>
<?php if (isset($bulk_error)): ?>
    <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <span><?php echo htmlspecialchars($bulk_error); ?></span></div>
<?php endif; ?>

<!-- ── CONTROL & SEARCH BAR ── -->
<div class="responses-control-bar">
    
    <!-- Filter Search Form -->
    <form method="GET" action="" style="display:flex; flex-wrap:wrap; gap:10px; flex:1; max-width:750px;">
        <input type="hidden" name="id" value="<?php echo $form_id; ?>">
        
        <input type="text" name="search" placeholder="Search answers, IPs, email..." class="form-input" style="margin-bottom:0; width:220px;" value="<?php echo htmlspecialchars($search); ?>">
        
        <input type="date" name="start_date" class="form-input" style="margin-bottom:0; width:140px;" value="<?php echo htmlspecialchars($start_date); ?>" placeholder="Start Date">
        <input type="date" name="end_date" class="form-input" style="margin-bottom:0; width:140px;" value="<?php echo htmlspecialchars($end_date); ?>" placeholder="End Date">
        
        <button type="submit" class="btn btn-primary" style="padding:0.5rem 1rem;"><i class="fas fa-search"></i></button>
        <?php if (!empty($search) || !empty($start_date) || !empty($end_date)): ?>
            <a href="campaign-form-responses.php?id=<?php echo $form_id; ?>" class="btn btn-secondary" style="padding:0.5rem 1rem;"><i class="fas fa-xmark"></i></a>
        <?php endif; ?>
    </form>

    <!-- Toolbar Actions -->
    <div style="display:flex; gap:10px; align-items:center;">
        <button class="btn btn-secondary" onclick="openModal('col-picker-modal')" style="padding:0.6rem 1.1rem;"><i class="fas fa-columns"></i> Columns</button>
        
        <!-- Bulk Action Wrapper -->
        <div id="bulk-actions" style="display:none; gap:10px;">
            <button class="btn btn-secondary" style="padding:0.6rem 1.1rem; border-color:#ef4444; color:#ef4444;" onclick="bulkDelete()"><i class="fas fa-trash-can"></i> Delete (<span id="bulk-count">0</span>)</button>
            
            <div class="export-dropdown">
                <button class="btn btn-secondary" onclick="toggleDropdown('bulk-export-menu')" style="padding:0.6rem 1.1rem;"><i class="fas fa-download"></i> Export Selected</button>
                <div class="dropdown-menu" id="bulk-export-menu">
                    <button class="dropdown-item" onclick="triggerExport('csv', 'selected')"><i class="fas fa-file-csv"></i> CSV Format</button>
                    <button class="dropdown-item" onclick="triggerExport('excel', 'selected')"><i class="fas fa-file-excel"></i> Excel Format</button>
                </div>
            </div>
        </div>

        <div class="export-dropdown" id="export-all-wrapper">
            <button class="btn btn-primary" onclick="toggleDropdown('export-menu')" style="padding:0.6rem 1.2rem;"><i class="fas fa-download"></i> Export Responses</button>
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

<!-- ── DATA TABLE ── -->
<div class="card" style="padding:0; overflow-x:auto;">
    <table class="data-table" style="width:100%; border-collapse:collapse; min-width:800px;">
        <thead>
            <tr style="border-bottom:1px solid var(--border); text-align:left;">
                <th style="padding:15px; width:45px; text-align:center;"><input type="checkbox" id="select-all" onclick="toggleSelectAll(this)"></th>
                <th style="padding:15px; font-weight:700;">Submission At</th>
                <th style="padding:15px; font-weight:700;">Respondent</th>
                
                <!-- Dynamic Columns -->
                <?php foreach ($fields as $f): 
                    $hid = in_array((string)$f['id'], $hidden_cols) ? 'display:none;' : '';
                ?>
                    <th style="padding:15px; font-weight:700; <?php echo $hid; ?>" class="dyn-col" data-colid="<?php echo $f['id']; ?>">
                        <?php echo htmlspecialchars($f['label']); ?>
                    </th>
                <?php endforeach; ?>
                
                <th style="padding:15px; font-weight:700; text-align:center;">IP Address</th>
                <th style="padding:15px; font-weight:700; text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($submissions)): ?>
                <tr>
                    <td colspan="<?php echo count($fields) + 5; ?>" style="padding:40px; text-align:center; color:var(--text-muted);">
                        <i class="fas fa-list-ul" style="font-size:3rem; margin-bottom:12px; display:block;"></i>
                        <p>No responses found for this form.</p>
                    </td>
                </tr>
            <?php else: foreach ($submissions as $s): ?>
                <tr style="border-bottom:1px solid var(--border); transition:background 0.2s;" class="table-row">
                    <td style="padding:15px; text-align:center;">
                        <input type="checkbox" class="row-checkbox" value="<?php echo $s['id']; ?>" onclick="updateRowSelection()">
                    </td>
                    <td style="padding:15px; white-space:nowrap; font-size:0.88rem; font-weight:600;">
                        <?php echo date('d M Y, h:i A', strtotime($s['submitted_at'])); ?>
                    </td>
                    <td style="padding:15px; font-weight:700; font-size:0.9rem;">
                        <?php echo htmlspecialchars($s['respondent_identifier'] ?: 'Anonymous'); ?>
                    </td>

                    <!-- Dynamic Answer Columns -->
                    <?php foreach ($fields as $f): 
                        $hid = in_array((string)$f['id'], $hidden_cols) ? 'display:none;' : '';
                        $ans = $answers_map[$s['id']][$f['id']] ?? null;
                        
                        $val = $ans ? $ans['answer_text'] : '';
                        $file = $ans ? $ans['file_path'] : null;
                        
                        // Limit displays
                        $disp_val = htmlspecialchars(strlen($val) > 45 ? substr($val, 0, 45) . '...' : $val);
                        if ($file) {
                            $disp_val = "<a href='{$file}' target='_blank' style='color:var(--accent); text-decoration:none;'><i class='fas fa-paperclip'></i> " . htmlspecialchars($val) . "</a>";
                        }
                    ?>
                        <td style="padding:15px; <?php echo $hid; ?> font-size:0.85rem;" class="dyn-col" data-colid="<?php echo $f['id']; ?>">
                            <?php echo $disp_val; ?>
                        </td>
                    <?php endforeach; ?>

                    <td style="padding:15px; text-align:center; font-family:monospace; font-size:0.8rem;"><?php echo htmlspecialchars($s['ip_address']); ?></td>
                    <td style="padding:15px; text-align:right;">
                        <div style="display:flex; justify-content:flex-end; gap:6px;">
                            <button class="btn btn-sm btn-soft-blue" onclick="viewResponseDetail(<?php echo $s['id']; ?>)"><i class="fas fa-eye"></i></button>
                            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this response permanently?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_single">
                                <input type="hidden" name="sub_id" value="<?php echo $s['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-soft-red"><i class="fas fa-trash-can"></i></button>
                            </form>
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

<!-- Helper bulk form for bulk deletes -->
<form method="POST" action="" id="bulk-delete-form" style="display:none;">
    <input type="hidden" name="action" value="bulk_delete">
    <input type="hidden" name="ids" id="bulk-delete-ids">
</form>

<script>
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

    function bulkDelete() {
        var selected = [];
        document.querySelectorAll('.row-checkbox:checked').forEach(c => selected.push(c.value));
        if (selected.length === 0) return;

        if (confirm('Are you sure you want to permanently delete these ' + selected.length + ' responses? This cannot be undone.')) {
            document.getElementById('bulk-delete-ids').value = selected.join(',');
            document.getElementById('bulk-delete-form').submit();
        }
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

        // We will fetch response details using a dynamic inline lookup
        // To make it simple, we load via Ajax on current page by triggering action = load_detail
        var fd = new FormData();
        fd.append('sub_id', subId);

        fetch('?id=<?php echo $form_id; ?>&action=load_detail&sub_id=' + subId)
        .then(r => r.text())
        .then(html => {
            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = '<div class="alert alert-danger">Failed to load details.</div>';
        });
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
