<?php
require_once 'includes/auth.php';
require_permission('studyplans');
require_once 'config/database.php';

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$course_filter = trim($_GET['course_id'] ?? '');

$where = ['sp.is_deleted = 0'];
$params = [];

if ($search !== '') {
    $where[] = "sp.title LIKE ?";
    $params[] = "%$search%";
}
if ($status_filter !== '') {
    $where[] = "sp.status = ?";
    $params[] = $status_filter;
}
if ($course_filter !== '') {
    $c_stmt = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE id = ?");
    $c_stmt->execute([(int)$course_filter]);
    $filter_course_name = $c_stmt->fetchColumn();
    if ($filter_course_name) {
        $where[] = "sp.id IN (SELECT study_plan_id FROM study_plan_assignments WHERE assignment_type = 'course' AND assigned_value = ? AND is_deleted = 0)";
        $params[] = $filter_course_name;
    } else {
        $where[] = "1=0";
    }
}

$where_clause = implode(' AND ', $where);

// Fetch study plans
try {
    $stmt = $pdo->prepare("
        SELECT sp.*, 
               (SELECT GROUP_CONCAT(assigned_value SEPARATOR ', ') 
                FROM study_plan_assignments 
                WHERE study_plan_id = sp.id AND assignment_type = 'course' AND is_deleted = 0) as course_name
        FROM study_plans sp
        WHERE {$where_clause}
        ORDER BY sp.created_at DESC
    ");
    $stmt->execute($params);
    $plans = $stmt->fetchAll();
    
    // Fetch stats
    $stats = [
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM study_plans WHERE is_deleted = 0")->fetchColumn(),
        'active' => (int)$pdo->query("SELECT COUNT(*) FROM study_plans WHERE status = 'published' AND is_deleted = 0")->fetchColumn(),
        'draft' => (int)$pdo->query("SELECT COUNT(*) FROM study_plans WHERE status = 'draft' AND is_deleted = 0")->fetchColumn(),
        'templates' => (int)$pdo->query("SELECT COUNT(*) FROM study_plans WHERE is_template = 1 AND is_deleted = 0")->fetchColumn(),
    ];
} catch (Exception $e) {
    error_log("Studyplans dashboard load error: " . $e->getMessage());
    $plans = [];
    $stats = ['total' => 0, 'active' => 0, 'draft' => 0, 'templates' => 0];
}

// Fetch active courses for filtering
$courses = $pdo->query("SELECT * FROM pepp_courses WHERE status = 'active' ORDER BY course_name ASC")->fetchAll();

$page_title = 'Study Plans Dashboard';
$page_sub = 'Manage, design, schedule, and assign study plans for courses & batches';
$active_page = 'studyplans';

include 'includes/admin_nav.php';
?>

<!-- Metrics Overview -->
<div class="stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="stat-card" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.2rem;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-calendar-days" style="color:var(--accent);"></i> Total Study Plans</div>
        <div style="font-size:1.8rem; font-weight:800; color:var(--text-main);"><?php echo $stats['total']; ?></div>
    </div>
    
    <div class="stat-card" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.2rem;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-circle-check" style="color:#10b981;"></i> Active / Published</div>
        <div style="font-size:1.8rem; font-weight:800; color:#10b981;"><?php echo $stats['active']; ?></div>
    </div>

    <div class="stat-card" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.2rem;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-file-signature" style="color:#64748b;"></i> Draft Plans</div>
        <div style="font-size:1.8rem; font-weight:800; color:#64748b;"><?php echo $stats['draft']; ?></div>
    </div>

    <div class="stat-card" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.2rem;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-paste" style="color:#8b5cf6;"></i> Saved Templates</div>
        <div style="font-size:1.8rem; font-weight:800; color:#8b5cf6;"><?php echo $stats['templates']; ?></div>
    </div>
</div>

<div class="action-bar" style="background:var(--card-bg); border:1px solid var(--border); padding:1rem 1.2rem; border-radius:16px; margin-bottom:1.5rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; flex:1; min-width:280px; margin:0;">
        <div style="flex:1.5; min-width:180px; position:relative;">
            <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.85rem; pointer-events:none;"></i>
            <input type="text" name="search" class="form-input" style="margin-bottom:0; width:100% !important; padding-left:34px !important; height:42px !important; border-radius:10px;" placeholder="Search study plan name..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <select name="status" class="form-input" style="margin-bottom:0; flex:1; min-width:125px; width:auto !important; height:42px !important; border-radius:10px;">
            <option value="">All Statuses</option>
            <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
            <option value="published" <?php echo $status_filter === 'published' ? 'selected' : ''; ?>>Published</option>
            <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
        </select>
        
        <select name="course_id" class="form-input" style="margin-bottom:0; flex:1; min-width:150px; width:auto !important; height:42px !important; border-radius:10px;">
            <option value="">All Courses</option>
            <?php foreach ($courses as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $course_filter == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['course_name']); ?></option>
            <?php endforeach; ?>
        </select>
        
        <button type="submit" class="btn btn-primary" style="flex-shrink:0; height:42px; padding:0 16px; border-radius:10px; white-space:nowrap; display:inline-flex; align-items:center; gap:6px;"><i class="fas fa-filter"></i> Apply Filters</button>
        <a href="studyplans.php" class="btn btn-outline" style="flex-shrink:0; height:42px; padding:0 16px; border-radius:10px; white-space:nowrap; display:inline-flex; align-items:center; justify-content:center; gap:6px;"><i class="fas fa-rotate-left"></i> Reset</a>
    </form>
    
    <div style="display:flex; gap:10px; align-items:center; flex-shrink:0;">
        <a href="studyplan-chapters.php" class="btn btn-outline" style="border-color:#8b5cf6; color:#8b5cf6; background:#f5f3ff; height:42px; border-radius:10px; display:inline-flex; align-items:center; gap:6px;"><i class="fas fa-book-bookmark"></i> Add Chapters</a>
        <a href="studyplan-designer.php" class="btn btn-primary" style="height:42px; border-radius:10px; display:inline-flex; align-items:center; gap:6px;"><i class="fas fa-plus"></i> Create Study Plan</a>
    </div>
</div>

<!-- Main Table List of Study Plans -->
<div class="panel">
    <div class="panel-body flush table-wrap">
        <?php if (empty($plans)): ?>
            <div class="empty-state" style="padding:4rem; text-align:center;">
                <i class="fas fa-calendar-xmark" style="font-size:3rem; color:var(--text-muted); margin-bottom:12px; display:block;"></i>
                <p style="color:var(--text-muted);">No study plans matching your criteria found.</p>
                <a href="studyplan-designer.php" class="btn btn-primary" style="margin-top:12px;"><i class="fas fa-plus"></i> Design First Plan</a>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Study Plan &amp; Description</th>
                        <th>Target Course / Batch</th>
                        <th>Duration Period</th>
                        <th>Version</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plans as $p): 
                        $status_badge = 'gray';
                        if ($p['status'] === 'published') $status_badge = 'green';
                        elseif ($p['status'] === 'draft') $status_badge = 'amber';
                        elseif ($p['status'] === 'archived') $status_badge = 'red';
                    ?>
                    <tr id="row-<?php echo $p['id']; ?>">
                        <td>
                            <div class="cell-main" style="font-weight:700; color:var(--text-main);">
                                <?php echo htmlspecialchars($p['title']); ?>
                                <?php if ($p['is_template']): ?>
                                    <span class="badge violet" style="font-size:0.65rem; padding:2px 6px; margin-left:6px;"><i class="fas fa-paste"></i> Template</span>
                                <?php endif; ?>
                            </div>
                            <div class="cell-sub" style="max-width:320px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <?php echo htmlspecialchars($p['description'] ?: 'No description provided'); ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:0.85rem;"><?php echo htmlspecialchars($p['course_name'] ?: 'All Courses'); ?></div>
                            <div class="cell-sub">Batch: <?php echo htmlspecialchars($p['academic_year']); ?></div>
                        </td>
                        <td>
                            <?php if (($p['plan_type'] ?? 'date_wise') === 'day_wise'): ?>
                                <div style="font-size:0.85rem; font-weight:600;"><i class="fas fa-calendar-day" style="color:var(--accent);"></i> <?php echo ($p['total_days'] ?? 0); ?> Days</div>
                                <div class="cell-sub">Day Count Wise</div>
                            <?php else: ?>
                                <div style="font-size:0.85rem; font-weight:600;"><i class="fas fa-clock" style="color:var(--accent);"></i> <?php echo date('d M Y', strtotime($p['start_date'])); ?></div>
                                <div class="cell-sub">to <?php echo date('d M Y', strtotime($p['end_date'])); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge blue" style="font-weight:700;">v<?php echo $p['version']; ?></span>
                        </td>
                        <td>
                            <span class="badge <?php echo $status_badge; ?>" style="font-weight:700; text-transform:uppercase;">
                                <?php echo htmlspecialchars($p['status']); ?>
                            </span>
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            <a href="studyplan-designer.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline" title="Visual Designer"><i class="fas fa-wand-magic-sparkles"></i> Edit</a>
                            <button class="btn btn-sm btn-outline" title="Clone / Duplicate" onclick="duplicatePlan(<?php echo $p['id']; ?>)"><i class="fas fa-copy"></i></button>
                            <?php if ($p['status'] === 'published'): ?>
                                <button class="btn btn-sm btn-outline" title="Copy Public URL" onclick="copyPublicUrl(<?php echo $p['id']; ?>)"><i class="fas fa-link"></i> Link</button>
                                <button class="btn btn-sm btn-soft-green" title="Send Email Update Notification" onclick="sendEmailNotification(<?php echo $p['id']; ?>)"><i class="fas fa-paper-plane"></i> Send Notify</button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-soft-red" title="Delete Plan" onclick="deletePlan(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['title'])); ?>', <?php echo (int)$p['version']; ?>)"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
    function copyPublicUrl(id) {
        var url = window.location.origin + window.location.pathname.replace('studyplans.php', 'studyplan.php') + '?plan_id=' + id;
        navigator.clipboard.writeText(url).then(function() {
            alert('Public Study Plan link copied to clipboard!');
        }).catch(function() {
            alert('Failed to copy: ' + url);
        });
    }

    function duplicatePlan(id) {
        if (!confirm('Are you sure you want to duplicate this study plan? All activities and styles will be copied.')) return;
        
        var fd = new FormData();
        fd.append('action', 'duplicate_plan');
        fd.append('id', id);
        fd.append('csrf_token', '<?php echo csrf_token(); ?>');
        
        fetch('api/studyplans-api.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Duplicate failed: ' + data.message);
            }
        });
    }

    function sendEmailNotification(id) {
        if (!confirm('Are you sure you want to manually dispatch email notifications to all assigned students for this study plan update?')) return;
        
        var fd = new FormData();
        fd.append('action', 'send_email_notification');
        fd.append('plan_id', id);
        fd.append('csrf_token', '<?php echo csrf_token(); ?>');
        
        fetch('api/studyplans-api.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
        })
        .catch(err => {
            console.error(err);
            alert('Server connection error.');
        });
    }

    function deletePlan(id, title, version) {
        var conf = prompt('To permanently delete study plan "' + title + '", type "DELETE" below:');
        if (conf !== 'DELETE') return;
        
        var fd = new FormData();
        fd.append('action', 'delete_plan');
        fd.append('id', id);
        fd.append('confirm', conf);
        fd.append('version', version);
        fd.append('csrf_token', '<?php echo csrf_token(); ?>');
        
        fetch('api/studyplans-api.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('row-' + id).remove();
            } else {
                alert('Delete failed: ' + data.message);
            }
        });
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
