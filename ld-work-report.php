<?php
require_once 'includes/auth.php';
require_permission('ld-work-report');
require_once 'config/database.php';

if (!ld_tables_exist($pdo)) {
    $active_page = 'ld-work-report';
    $page_title  = 'L&D Work Report';
    $page_sub    = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>L&D Work Report is not installed yet. Please run the required database migration (<strong>database-update-21.sql</strong>) before using this module.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

require_once 'includes/pdf_invoice.php'; // MiniPDF + helpers

// Set time zone
date_default_timezone_set('Asia/Kolkata');

// Filter parameters
$f_staff = trim($_GET['staff'] ?? '');
$f_role = trim($_GET['role'] ?? '');
$f_course = (int)($_GET['course'] ?? 0);
$f_mode = (int)($_GET['mode'] ?? 0);
$f_status = trim($_GET['status'] ?? 'active'); // 'active', 'deleted', 'all'
$f_period = trim($_GET['period'] ?? 'overall'); // 'today', 'weekly', 'monthly', 'overall', 'custom'
$f_from = trim($_GET['from'] ?? '');
$f_to = trim($_GET['to'] ?? '');

// Build where clause
$where = [];
$params = [];

if ($f_staff !== '') {
    $where[] = "t.admin_username = ?";
    $params[] = $f_staff;
}
if ($f_role !== '') {
    $where[] = "t.admin_role = ?";
    $params[] = $f_role;
}
if ($f_course > 0) {
    $where[] = "t.course_id = ?";
    $params[] = $f_course;
}
if ($f_mode > 0) {
    $where[] = "t.mode_id = ?";
    $params[] = $f_mode;
}

// Status filter
if ($f_status === 'active') {
    $where[] = "t.status = 'active'";
} elseif ($f_status === 'deleted') {
    $where[] = "t.status = 'deleted'";
} // 'all' allows both

// Period filter
if ($f_period === 'today') {
    $where[] = "DATE(t.created_at) = CURRENT_DATE()";
} elseif ($f_period === 'weekly') {
    $where[] = "t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($f_period === 'monthly') {
    $where[] = "t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($f_period === 'custom') {
    if ($f_from !== '') {
        $where[] = "t.created_at >= ?";
        $params[] = $f_from . ' 00:00:00';
    }
    if ($f_to !== '') {
        $where[] = "t.created_at <= ?";
        $params[] = $f_to . ' 23:59:59';
    }
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// Fetch all staff members for filters
$staff_list = [];
try {
    $staff_list = $pdo->query("SELECT DISTINCT admin_username, admin_name FROM ld_tasks ORDER BY admin_name ASC")->fetchAll();
} catch (Exception $e) {}

// Fetch courses and modes for filter dropdowns
$courses_filter = [];
try { $courses_filter = $pdo->query("SELECT * FROM ld_work_courses ORDER BY sort_order ASC, course_name ASC")->fetchAll(); } catch (Exception $e) {}
$modes_filter = [];
try { $modes_filter = $pdo->query("SELECT * FROM ld_work_modes ORDER BY sort_order ASC, mode_name ASC")->fetchAll(); } catch (Exception $e) {}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!is_super_admin() && !can_access('ld-work-report')) {
        die('Access denied.');
    }
    
    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, GROUP_CONCAT(tp.topic_name SEPARATOR '|||') AS topics_list
            FROM ld_tasks t
            LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
            $where_sql
            GROUP BY t.id
            ORDER BY t.created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        die("Export Error: " . $e->getMessage());
    }

    log_admin_activity($pdo, $admin_username, 'data_export', "Exported L&D Work report (" . count($rows) . ' rows)');
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ld-work-report-' . date('Y-m-d-Hi') . '.csv"');
    
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM
    fputcsv($out, ['Date', 'Staff', 'Role', 'Course', 'Work Mode', 'Topics Count', 'Topics List', 'IP Address', 'Maps URL', 'Status', 'Deleted By', 'Deleted Reason']);
    
    foreach ($rows as $r) {
        $topics = explode('|||', $r['topics_list'] ?? '');
        $topics_clean = implode(', ', $topics);
        fputcsv($out, [
            $r['created_at'],
            $r['admin_name'],
            $r['admin_role'],
            $r['course_name'],
            $r['mode_name'],
            count($topics),
            $topics_clean,
            $r['ip_address'],
            $r['maps_url'],
            $r['status'],
            $r['deleted_by'] ?? '',
            $r['deleted_reason'] ?? ''
        ]);
    }
    fclose($out);
    exit();
}

// Handle PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    if (!is_super_admin() && !can_access('ld-work-report')) {
        die('Access denied.');
    }
    
    // Fetch metrics
    $total_tasks = 0;
    $total_topics = 0;
    $active_days = 0;
    $course_breakdown = [];
    $mode_breakdown = [];
    $tasks = [];

    try {
        // Fetch raw tasks matching filters
        $stmt = $pdo->prepare("
            SELECT t.*, GROUP_CONCAT(tp.topic_name SEPARATOR '|||') AS topics_list
            FROM ld_tasks t
            LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
            $where_sql
            GROUP BY t.id
            ORDER BY t.created_at DESC
        ");
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
        
        $dates = [];
        foreach ($tasks as $tk) {
            $total_tasks++;
            $topics = array_filter(explode('|||', $tk['topics_list'] ?? ''));
            $cnt = count($topics);
            $total_topics += $cnt;
            
            $dates[date('Y-m-d', strtotime($tk['created_at']))] = true;
            
            if (!isset($course_breakdown[$tk['course_name']])) {
                $course_breakdown[$tk['course_name']] = 0;
            }
            $course_breakdown[$tk['course_name']] += $cnt;
            
            if (!isset($mode_breakdown[$tk['mode_name']])) {
                $mode_breakdown[$tk['mode_name']] = 0;
            }
            $mode_breakdown[$tk['mode_name']] += $cnt;
        }
        $active_days = count($dates);
    } catch (Exception $e) {
        die("PDF Stats Error: " . $e->getMessage());
    }

    $pdf = new MiniPDF();
    $L = 50; $R = MiniPDF::W - 50; $W = $R - $L;
    
    // Check if logo exists
    $logo = __DIR__ . '/pepp-logo.jpg';
    if (file_exists($logo)) {
        $pdf->image($logo, $L, 44, 92, 42);
    } else {
        $pdf->text($L, 44, 18, 'PEPP Learning', true);
    }
    
    $pdf->text($L, 48, 9, 'L&D Operations Work Report', false, 'R', $W);
    $pdf->text($L, 60, 9, 'Generated: ' . date('d-m-Y h:i A'), false, 'R', $W);
    $pdf->text($L, 95, 14, 'L&D OPERATIONS WORK REPORT', true, 'C', $W);
    
    $y = 120;
    $pdf->line($L, $y, $R, $y); $y += 12;
    
    // Summary table
    $pdf->text($L, $y, 10, 'Summary Metrics', true); $y += 16;
    $pdf->text($L, $y, 9, 'Total Task Logs: ' . $total_tasks);
    $pdf->text($L + 150, $y, 9, 'Total Topics Completed: ' . $total_topics);
    $pdf->text($L + 320, $y, 9, 'Active Work Days: ' . $active_days);
    $y += 14;
    $pdf->text($L, $y, 9, 'Avg Topics/Active Day: ' . ($active_days > 0 ? number_format($total_topics / $active_days, 1) : '0'));
    $y += 16;
    $pdf->line($L, $y, $R, $y); $y += 16;
    
    // Course breakdown table
    $pdf->text($L, $y, 10, 'Course Breakdown (Topics completed)', true); $y += 14;
    $pdf->line($L, $y, $R, $y); $y += 8;
    foreach ($course_breakdown as $c_name => $c_cnt) {
        if ($y > 760) { $pdf->line($L, $y, $R, $y); $y = 50; }
        $pdf->text($L, $y, 9, $c_name);
        $pdf->text($R - 50, $y, 9, $c_cnt, false, 'R');
        $y += 14;
    }
    $y += 10;
    $pdf->line($L, $y, $R, $y); $y += 16;
    
    // Mode breakdown table
    $pdf->text($L, $y, 10, 'Work Mode Breakdown (Topics completed)', true); $y += 14;
    $pdf->line($L, $y, $R, $y); $y += 8;
    foreach ($mode_breakdown as $m_name => $m_cnt) {
        if ($y > 760) { $pdf->line($L, $y, $R, $y); $y = 50; }
        $pdf->text($L, $y, 9, $m_name);
        $pdf->text($R - 50, $y, 9, $m_cnt, false, 'R');
        $y += 14;
    }
    $y += 10;
    $pdf->line($L, $y, $R, $y); $y += 20;
    
    // Daily activity detail list (Up to 15 rows to fit pages)
    $pdf->text($L, $y, 10, 'Recent Activity Logs', true); $y += 14;
    $pdf->line($L, $y, $R, $y); $y += 8;
    
    $pdf->text($L, $y, 8.5, 'Date/Time', true);
    $pdf->text($L + 100, $y, 8.5, 'Staff', true);
    $pdf->text($L + 200, $y, 8.5, 'Course', true);
    $pdf->text($L + 320, $y, 8.5, 'Mode', true);
    $pdf->text($R - 50, $y, 8.5, 'Topics', true, 'R');
    $y += 8; $pdf->line($L, $y, $R, $y); $y += 10;
    
    $limit = 15;
    $count = 0;
    foreach ($tasks as $tk) {
        if ($count >= $limit) break;
        if ($y > 760) { $pdf->line($L, $y, $R, $y); $y = 50; }
        
        $topics = array_filter(explode('|||', $tk['topics_list'] ?? ''));
        $pdf->text($L, $y, 8, date('d-m-y H:i', strtotime($tk['created_at'])));
        $pdf->text($L + 100, $y, 8, substr($tk['admin_name'], 0, 18));
        $pdf->text($L + 200, $y, 8, substr($tk['course_name'], 0, 22));
        $pdf->text($L + 320, $y, 8, substr($tk['mode_name'], 0, 22));
        $pdf->text($R - 50, $y, 8, count($topics), false, 'R');
        
        $y += 14;
        $count++;
    }
    
    $y += 10;
    $pdf->line($L, $y, $R, $y); $y += 12;
    $pdf->text($L, $y, 8, 'PEPP Learning Operations · office@pepplearning.com · Confidential Report', false, 'C', $W);
    
    $bytes = $pdf->output();
    $fname = 'ld-work-report-' . date('Y-m-d-Hi') . '.pdf';
    
    log_admin_activity($pdo, $admin_username, 'data_export', "Exported L&D Work PDF report");
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit();
}

// Page query variables
$stats_error = '';
$total_tasks = 0;
$total_topics = 0;
$active_days = 0;
$courses_worked = 0;
$modes_used = 0;

try {
    // 1. Aggregated Metrics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT t.id) AS total_tasks,
            COUNT(tp.id) AS total_topics,
            COUNT(DISTINCT DATE(t.created_at)) AS active_days,
            COUNT(DISTINCT t.course_id) AS courses_worked,
            COUNT(DISTINCT t.mode_id) AS modes_used
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql
    ");
    $stmt->execute($params);
    $totals = $stmt->fetch();
    
    $total_tasks = $totals['total_tasks'] ?? 0;
    $total_topics = $totals['total_topics'] ?? 0;
    $active_days = $totals['active_days'] ?? 0;
    $courses_worked = $totals['courses_worked'] ?? 0;
    $modes_used = $totals['modes_used'] ?? 0;
    
} catch (Exception $e) {
    error_log("Report stats error: " . $e->getMessage());
    $stats_error = 'Error calculating summary metrics.';
}

// 2. Fetch Detail Records (Paginated)
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;
$detail_rows = [];
$total_rows = 0;

try {
    // Count total rows matching filters for pagination
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM ld_tasks t $where_sql");
    $stmt->execute($params);
    $total_rows = (int)$stmt->fetchColumn();
    
    // Fetch records
    $stmt = $pdo->prepare("
        SELECT t.*, GROUP_CONCAT(tp.topic_name SEPARATOR '|||') AS topics_list
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql
        GROUP BY t.id
        ORDER BY t.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $detail_rows = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Report details error: " . $e->getMessage());
}

// 3. Fetch Chart Data
$chart_daily_labels = [];
$chart_daily_data = [];
$chart_weekly_labels = [];
$chart_weekly_data = [];
$chart_monthly_labels = [];
$chart_monthly_data = [];
$chart_course_labels = [];
$chart_course_data = [];
$chart_mode_labels = [];
$chart_mode_data = [];

try {
    // Daily Productivity (Last 7 Days)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(t.created_at, '%d %b') AS day_label, COUNT(tp.id) AS topic_cnt
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(t.created_at)
        ORDER BY DATE(t.created_at) ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chart_daily_labels[] = $row['day_label'];
        $chart_daily_data[] = (int)$row['topic_cnt'];
    }

    // Weekly Productivity (Last 4 Weeks)
    $stmt = $pdo->prepare("
        SELECT YEARWEEK(t.created_at) AS wk, CONCAT('Wk ', WEEK(t.created_at)) AS wk_label, COUNT(tp.id) AS topic_cnt
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql AND t.created_at >= DATE_SUB(NOW(), INTERVAL 4 WEEK)
        GROUP BY YEARWEEK(t.created_at)
        ORDER BY YEARWEEK(t.created_at) ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chart_weekly_labels[] = $row['wk_label'];
        $chart_weekly_data[] = (int)$row['topic_cnt'];
    }

    // Monthly Productivity (Last 6 Months)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(t.created_at, '%b %y') AS mon_label, COUNT(tp.id) AS topic_cnt
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql AND t.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(t.created_at, '%Y-%m')
        ORDER BY DATE(t.created_at) ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chart_monthly_labels[] = $row['mon_label'];
        $chart_monthly_data[] = (int)$row['topic_cnt'];
    }

    // Course Distribution
    $stmt = $pdo->prepare("
        SELECT t.course_name, COUNT(tp.id) AS topic_cnt
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql
        GROUP BY t.course_id
        ORDER BY topic_cnt DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chart_course_labels[] = $row['course_name'];
        $chart_course_data[] = (int)$row['topic_cnt'];
    }

    // Work Mode Distribution
    $stmt = $pdo->prepare("
        SELECT t.mode_name, COUNT(tp.id) AS topic_cnt
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        $where_sql
        GROUP BY t.mode_id
        ORDER BY topic_cnt DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chart_mode_labels[] = $row['mode_name'];
        $chart_mode_data[] = (int)$row['topic_cnt'];
    }

} catch (Exception $e) {
    error_log("Chart load error: " . $e->getMessage());
}

$active_page = 'ld-work-report';
$page_title  = 'L&D Operations Work Report';
$page_sub    = 'Operational L&D stats, charts and logs';
include 'includes/admin_nav.php';
?>

<style>
.report-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}
.report-grid-two {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
@media (max-width: 992px) {
    .report-grid-two {
        grid-template-columns: 1fr;
    }
}
.stats-card-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.stats-card {
    background: var(--card);
    color: var(--card-foreground);
    border: 1px solid var(--border);
    padding: 18px;
    border-radius: 12px;
    box-shadow: 0 4px 14px rgba(22, 78, 99, 0.05);
}
.stats-card h3 {
    margin: 0;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.8;
}
.stats-card p {
    margin: 8px 0 0 0;
    font-size: 1.8rem;
    font-weight: 700;
}
.timeline-card-mobile {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
}
.desktop-view {
    display: block;
}
.mobile-view {
    display: none;
}
@media (max-width: 768px) {
    .desktop-view {
        display: none;
    }
    .mobile-view {
        display: block;
    }
}
.chart-box {
    background: #ffffff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.03);
}
</style>

<div class="report-grid">
    <!-- Filters Panel -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon"><i class="fas fa-filter"></i></span>
            <h2>Filter Work Reports</h2>
        </div>
        <div class="panel-body">
            <form method="GET" class="filter-bar" style="flex-wrap: wrap; gap: 14px; align-items: end;">
                <div class="field" style="margin: 0; min-width: 140px;">
                    <label>Staff</label>
                    <select name="staff">
                        <option value="">- All Staff -</option>
                        <?php foreach ($staff_list as $st): ?>
                            <option value="<?php echo e($st['admin_username']); ?>" <?php echo $f_staff === $st['admin_username'] ? 'selected' : ''; ?>><?php echo e($st['admin_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" style="margin: 0; min-width: 120px;">
                    <label>Role</label>
                    <select name="role">
                        <option value="">- All Roles -</option>
                        <option value="super_admin" <?php echo $f_role === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                        <option value="admin" <?php echo $f_role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>

                <div class="field" style="margin: 0; min-width: 140px;">
                    <label>Course</label>
                    <select name="course">
                        <option value="0">- All Courses -</option>
                        <?php foreach ($courses_filter as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo $f_course === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['course_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" style="margin: 0; min-width: 140px;">
                    <label>Work Mode</label>
                    <select name="mode">
                        <option value="0">- All Modes -</option>
                        <?php foreach ($modes_filter as $m): ?>
                            <option value="<?php echo (int)$m['id']; ?>" <?php echo $f_mode === (int)$m['id'] ? 'selected' : ''; ?>><?php echo e($m['mode_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" style="margin: 0; min-width: 120px;">
                    <label>Period</label>
                    <select name="period" onchange="togglePeriodFields(this.value)">
                        <option value="overall" <?php echo $f_period === 'overall' ? 'selected' : ''; ?>>Overall</option>
                        <option value="today" <?php echo $f_period === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="weekly" <?php echo $f_period === 'weekly' ? 'selected' : ''; ?>>Weekly (Last 7d)</option>
                        <option value="monthly" <?php echo $f_period === 'monthly' ? 'selected' : ''; ?>>Monthly (Last 30d)</option>
                        <option value="custom" <?php echo $f_period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    </select>
                </div>

                <div class="field custom-period" style="margin: 0; min-width: 130px; display: <?php echo $f_period === 'custom' ? 'block' : 'none'; ?>;">
                    <label>From Date</label>
                    <input type="date" name="from" value="<?php echo e($f_from); ?>">
                </div>

                <div class="field custom-period" style="margin: 0; min-width: 130px; display: <?php echo $f_period === 'custom' ? 'block' : 'none'; ?>;">
                    <label>To Date</label>
                    <input type="date" name="to" value="<?php echo e($f_to); ?>">
                </div>

                <div class="field" style="margin: 0; min-width: 100px;">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?php echo $f_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="deleted" <?php echo $f_status === 'deleted' ? 'selected' : ''; ?>>Deleted</option>
                        <option value="all" <?php echo $f_status === 'all' ? 'selected' : ''; ?>>All (Active + Deleted)</option>
                    </select>
                </div>

                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Filter</button>
                    <a href="ld-work-report.php" class="btn btn-outline" title="Clear Filters"><i class="fas fa-rotate"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Metrics Dashboard -->
    <div class="stats-card-list">
        <div class="stats-card">
            <h3>Task Records</h3>
            <p><?php echo $total_tasks; ?></p>
        </div>
        <div class="stats-card">
            <h3>Completed Topics</h3>
            <p><?php echo $total_topics; ?></p>
        </div>
        <div class="stats-card">
            <h3>Active Days</h3>
            <p><?php echo $active_days; ?></p>
        </div>
        <div class="stats-card">
            <h3>Courses Worked</h3>
            <p><?php echo $courses_worked; ?></p>
        </div>
        <div class="stats-card">
            <h3>Work Modes Used</h3>
            <p><?php echo $modes_used; ?></p>
        </div>
        <div class="stats-card">
            <h3>Avg Topics/Day</h3>
            <p><?php echo $active_days > 0 ? number_format($total_topics / $active_days, 1) : '0.0'; ?></p>
        </div>
    </div>

    <!-- Charts and Breakdown Visualizations -->
    <div class="report-grid-two">
        <div class="chart-box">
            <h3 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 12px; color: var(--primary);"><i class="fas fa-chart-line"></i> Productivity Over Time</h3>
            <div style="height:250px; position:relative;">
                <canvas id="productivityChart"></canvas>
            </div>
            <div style="display:flex; justify-content:center; gap:16px; margin-top:10px;">
                <label style="font-size:0.8rem; cursor:pointer;"><input type="radio" name="timeframe" value="daily" checked onchange="updateProductivityChart(this.value)"> Daily</label>
                <label style="font-size:0.8rem; cursor:pointer;"><input type="radio" name="timeframe" value="weekly" onchange="updateProductivityChart(this.value)"> Weekly</label>
                <label style="font-size:0.8rem; cursor:pointer;"><input type="radio" name="timeframe" value="monthly" onchange="updateProductivityChart(this.value)"> Monthly</label>
            </div>
        </div>

        <div class="chart-box">
            <h3 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 12px; color: var(--primary);"><i class="fas fa-chart-pie"></i> Distribution Breakdown</h3>
            <div style="height:250px; position:relative;">
                <canvas id="distributionChart"></canvas>
            </div>
            <div style="display:flex; justify-content:center; gap:16px; margin-top:10px;">
                <label style="font-size:0.8rem; cursor:pointer;"><input type="radio" name="distType" value="course" checked onchange="updateDistributionChart(this.value)"> Courses</label>
                <label style="font-size:0.8rem; cursor:pointer;"><input type="radio" name="distType" value="mode" onchange="updateDistributionChart(this.value)"> Work Modes</label>
            </div>
        </div>
    </div>

    <!-- Details List -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-list"></i></span>
            <h2>Activity Logs</h2>
            <div class="head-right" style="display:flex; gap:8px;">
                <!-- Respect Active Filters inside URL -->
                <?php 
                $query_str = http_build_query($_GET);
                $csv_url = "ld-work-report.php?export=csv" . ($query_str ? '&' . $query_str : '');
                $pdf_url = "ld-work-report.php?export=pdf" . ($query_str ? '&' . $query_str : '');
                ?>
                <a href="<?php echo $csv_url; ?>" class="btn btn-sm btn-outline"><i class="fas fa-file-excel"></i> Export CSV</a>
                <a href="<?php echo $pdf_url; ?>" class="btn btn-sm btn-outline"><i class="fas fa-file-pdf"></i> Export PDF</a>
            </div>
        </div>
        <div class="panel-body">
            <!-- Desktop Layout -->
            <div class="desktop-view table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Staff Name</th>
                            <th>Course</th>
                            <th>Work Mode</th>
                            <th>Completed Topics</th>
                            <th>Location</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detail_rows as $row): 
                            $topics = explode('|||', $row['topics_list'] ?? '');
                        ?>
                            <tr>
                                <td style="white-space:nowrap; font-size:0.8rem;">
                                    <div class="cell-main"><?php echo date('d M Y', strtotime($row['created_at'])); ?></div>
                                    <div class="cell-sub"><?php echo date('h:i A', strtotime($row['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="cell-main"><?php echo e($row['admin_name']); ?></div>
                                    <div class="cell-sub">Role: <?php echo ucfirst(e($row['admin_role'])); ?></div>
                                </td>
                                <td><?php echo e($row['course_name']); ?></td>
                                <td><?php echo e($row['mode_name']); ?></td>
                                <td>
                                    <ul style="padding-left: 14px; list-style-type: disc; font-size: 0.8rem; margin: 0;">
                                        <?php foreach ($topics as $tp): ?>
                                            <li><?php echo e($tp); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                                <td>
                                    <a href="<?php echo e($row['maps_url']); ?>" target="_blank" class="btn btn-sm btn-soft-violet" title="View location"><i class="fas fa-map-location-dot"></i> View Map</a>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'active'): ?>
                                        <span class="badge green">Active</span>
                                    <?php else: ?>
                                        <span class="badge red" title="Deleted Reason: <?php echo e($row['deleted_reason']); ?>">Deleted</span>
                                        <div class="cell-sub" style="font-size:0.72rem; margin-top:2px;">By: <?php echo e($row['deleted_by']); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($detail_rows)): ?>
                            <tr><td colspan="7"><div class="empty-state" style="padding:24px;"><p>No task entries match the selected filters.</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Layout -->
            <div class="mobile-view">
                <?php foreach ($detail_rows as $row): 
                    $topics = explode('|||', $row['topics_list'] ?? '');
                ?>
                    <div class="timeline-card-mobile">
                        <div style="display:flex; justify-content:space-between; align-items:start;">
                            <div>
                                <strong style="font-size:0.9rem; color:var(--primary);"><?php echo e($row['admin_name']); ?></strong>
                                <div style="font-size:0.75rem; color:var(--foreground); opacity:0.85;">Role: <?php echo ucfirst(e($row['admin_role'])); ?></div>
                            </div>
                            <?php if ($row['status'] === 'active'): ?>
                                <span class="badge green">Active</span>
                            <?php else: ?>
                                <span class="badge red">Deleted</span>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:8px; font-size:0.82rem;">
                            <div><strong>Course:</strong> <?php echo e($row['course_name']); ?></div>
                            <div><strong>Mode:</strong> <?php echo e($row['mode_name']); ?></div>
                        </div>
                        <ul style="margin-top:6px; padding-left:14px; list-style-type:disc; font-size:0.8rem;">
                            <?php foreach ($topics as $tp): ?>
                                <li><?php echo e($tp); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <div style="margin-top:10px; font-size:0.75rem; border-top:1px solid rgba(22, 78, 99, 0.05); padding-top:8px; display:flex; justify-content:space-between; align-items:center;">
                            <div><i class="fas fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?></div>
                            <a href="<?php echo e($row['maps_url']); ?>" target="_blank" class="btn btn-sm btn-soft-violet" style="padding:2px 8px; font-size:0.72rem;"><i class="fas fa-map-location-dot"></i> View Map</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($detail_rows)): ?>
                    <div class="empty-state" style="padding:24px;"><p>No task entries match the selected filters.</p></div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_rows > $limit): 
                $total_pages = ceil($total_rows / $limit);
                $q = $_GET;
            ?>
                <div style="display:flex; justify-content:center; gap:8px; margin-top:20px;">
                    <?php if ($page > 1): $q['page'] = $page - 1; ?>
                        <a href="ld-work-report.php?<?php echo http_build_query($q); ?>" class="btn btn-sm btn-outline"><i class="fas fa-chevron-left"></i> Previous</a>
                    <?php endif; ?>
                    <span class="btn btn-sm btn-soft-violet" style="pointer-events:none;">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                    <?php if ($page < $total_pages): $q['page'] = $page + 1; ?>
                        <a href="ld-work-report.php?<?php echo http_build_query($q); ?>" class="btn btn-sm btn-outline">Next <i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function togglePeriodFields(val) {
    var els = document.querySelectorAll('.custom-period');
    els.forEach(function(el) {
        el.style.display = (val === 'custom') ? 'block' : 'none';
    });
}

// Chart.js Configuration
var productivityChart = null;
var distributionChart = null;

// Productivity Chart Datasets
var dailyLabels = <?php echo json_encode($chart_daily_labels); ?>;
var dailyData = <?php echo json_encode($chart_daily_data); ?>;
var weeklyLabels = <?php echo json_encode($chart_weekly_labels); ?>;
var weeklyData = <?php echo json_encode($chart_weekly_data); ?>;
var monthlyLabels = <?php echo json_encode($chart_monthly_labels); ?>;
var monthlyData = <?php echo json_encode($chart_monthly_data); ?>;

// Distribution Datasets
var courseLabels = <?php echo json_encode($chart_course_labels); ?>;
var courseData = <?php echo json_encode($chart_course_data); ?>;
var modeLabels = <?php echo json_encode($chart_mode_labels); ?>;
var modeData = <?php echo json_encode($chart_mode_data); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // 1. Initialize Productivity Chart
    var ctxProd = document.getElementById('productivityChart').getContext('2d');
    productivityChart = new Chart(ctxProd, {
        type: 'line',
        data: {
            labels: dailyLabels,
            datasets: [{
                label: 'Topics Completed',
                data: dailyData,
                borderColor: '#164e63',
                backgroundColor: 'rgba(22, 78, 99, 0.05)',
                borderWidth: 2,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // 2. Initialize Distribution Chart
    var ctxDist = document.getElementById('distributionChart').getContext('2d');
    distributionChart = new Chart(ctxDist, {
        type: 'doughnut',
        data: {
            labels: courseLabels,
            datasets: [{
                data: courseData,
                backgroundColor: [
                    '#164e63', '#0891b2', '#06b6d4', '#22d3ee', '#67e8f9',
                    '#6366f1', '#818cf8', '#a5b4fc', '#c7d2fe', '#e0e7ff'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 10 } } }
            }
        }
    });
});

function updateProductivityChart(type) {
    if (!productivityChart) return;
    if (type === 'daily') {
        productivityChart.data.labels = dailyLabels;
        productivityChart.data.datasets[0].data = dailyData;
    } else if (type === 'weekly') {
        productivityChart.data.labels = weeklyLabels;
        productivityChart.data.datasets[0].data = weeklyData;
    } else if (type === 'monthly') {
        productivityChart.data.labels = monthlyLabels;
        productivityChart.data.datasets[0].data = monthlyData;
    }
    productivityChart.update();
}

function updateDistributionChart(type) {
    if (!distributionChart) return;
    if (type === 'course') {
        distributionChart.data.labels = courseLabels;
        distributionChart.data.datasets[0].data = courseData;
    } else if (type === 'mode') {
        distributionChart.data.labels = modeLabels;
        distributionChart.data.datasets[0].data = modeData;
    }
    distributionChart.update();
}
</script>

<?php include 'includes/admin_footer.php'; ?>
