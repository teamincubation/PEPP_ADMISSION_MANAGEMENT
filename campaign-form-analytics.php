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
if (!has_form_access($pdo, $admin_username, $form_id)) {
    die("<div style='padding:2rem; font-family:sans-serif; text-align:center; color:#ef4444;'><h2>Access Denied</h2><p>You do not have permission to view analytics for this form.</p></div>");
}

$page_title  = htmlspecialchars($form['title']) . ' — Advanced Analytics';
$page_sub    = 'Visual performance reports, traffic metrics, conversion trends, and choice analysis';
$active_page = 'campaigns';

// Date range filters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Load dynamic data for charts
try {
    // 1. Daily views
    $stmt = $pdo->prepare("
        SELECT DATE(visited_at) as v_date, COUNT(*) as views 
        FROM campaign_form_analytics 
        WHERE form_id = ? AND DATE(visited_at) BETWEEN ? AND ?
        GROUP BY DATE(visited_at)
        ORDER BY v_date ASC
    ");
    $stmt->execute([$form_id, $start_date, $end_date]);
    $views_raw = $stmt->fetchAll();

    // 2. Daily submissions
    $stmt = $pdo->prepare("
        SELECT DATE(submitted_at) as s_date, COUNT(*) as subs 
        FROM campaign_form_submissions 
        WHERE form_id = ? AND DATE(submitted_at) BETWEEN ? AND ?
        GROUP BY DATE(submitted_at)
        ORDER BY s_date ASC
    ");
    $stmt->execute([$form_id, $start_date, $end_date]);
    $subs_raw = $stmt->fetchAll();

    // Merge dates into a unified timeline
    $dates_timeline = [];
    $views_timeline = [];
    $subs_timeline = [];
    
    $period = new DatePeriod(
        new DateTime($start_date),
        new DateInterval('P1D'),
        (new DateTime($end_date))->modify('+1 day')
    );

    $views_by_date = array_column($views_raw, 'views', 'v_date');
    $subs_by_date = array_column($subs_raw, 'subs', 's_date');

    foreach ($period as $key => $value) {
        $curr_date = $value->format('Y-m-d');
        $dates_timeline[] = $value->format('d M');
        $views_timeline[] = (int)($views_by_date[$curr_date] ?? 0);
        $subs_timeline[] = (int)($subs_by_date[$curr_date] ?? 0);
    }

    // 3. Device breakdown
    $stmt = $pdo->prepare("
        SELECT device, COUNT(*) as count 
        FROM campaign_form_analytics 
        WHERE form_id = ? AND DATE(visited_at) BETWEEN ? AND ?
        GROUP BY device
    ");
    $stmt->execute([$form_id, $start_date, $end_date]);
    $devices = $stmt->fetchAll();

    // 4. Browser breakdown
    $stmt = $pdo->prepare("
        SELECT browser, COUNT(*) as count 
        FROM campaign_form_analytics 
        WHERE form_id = ? AND DATE(visited_at) BETWEEN ? AND ?
        GROUP BY browser
        ORDER BY count DESC LIMIT 5
    ");
    $stmt->execute([$form_id, $start_date, $end_date]);
    $browsers = $stmt->fetchAll();

    // 5. Choices Analysis for dropdown/radio/checkbox fields
    $stmt = $pdo->prepare("
        SELECT id, label, choices, type 
        FROM campaign_form_fields 
        WHERE form_id = ? AND type IN ('dropdown', 'multiselect', 'checkboxes', 'radio')
    ");
    $stmt->execute([$form_id]);
    $choice_fields = $stmt->fetchAll();

    $question_charts = [];

    foreach ($choice_fields as $cf) {
        $choices_list = json_decode($cf['choices'], true) ?: [];
        if (empty($choices_list)) continue;

        // Fetch all answers for this field
        $stmt_ans = $pdo->prepare("
            SELECT a.answer_text 
            FROM campaign_form_answers a
            JOIN campaign_form_submissions s ON a.submission_id = s.id
            WHERE a.field_id = ? AND DATE(s.submitted_at) BETWEEN ? AND ?
        ");
        $stmt_ans->execute([$cf['id'], $start_date, $end_date]);
        $answers = $stmt_ans->fetchAll(PDO::FETCH_COLUMN);

        // Calculate counts
        $counts = array_fill_keys($choices_list, 0);
        foreach ($answers as $ans) {
            // Checkboxes can have multiple comma-separated selections
            if ($cf['type'] === 'checkboxes' || $cf['type'] === 'multiselect') {
                $selections = array_map('trim', explode(',', $ans));
                foreach ($selections as $sel) {
                    if (isset($counts[$sel])) {
                        $counts[$sel]++;
                    }
                }
            } else {
                $ans_trim = trim($ans);
                if (isset($counts[$ans_trim])) {
                    $counts[$ans_trim]++;
                }
            }
        }

        $question_charts[] = [
            'id' => $cf['id'],
            'label' => $cf['label'],
            'labels' => array_keys($counts),
            'data' => array_values($counts)
        ];
    }

    // 6. Location Geolocation Points & Region Breakdown
    $stmt = $pdo->prepare("
        SELECT id, respondent_identifier, latitude, longitude, submitted_at 
        FROM campaign_form_submissions 
        WHERE form_id = ? AND is_deleted = 0 AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude != '' AND longitude != ''
        AND DATE(submitted_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$form_id, $start_date, $end_date]);
    $geo_points = $stmt->fetchAll();

    // Fetch location field answers (from location field types or pincode/place/district fields)
    $stmt = $pdo->prepare("
        SELECT a.answer_text, f.label, f.type 
        FROM campaign_form_answers a
        JOIN campaign_form_submissions s ON a.submission_id = s.id
        JOIN campaign_form_fields f ON a.field_id = f.id
        WHERE f.form_id = ? AND s.is_deleted = 0 AND (f.type = 'location' OR f.label LIKE '%pincode%' OR f.label LIKE '%district%' OR f.label LIKE '%state%' OR f.label LIKE '%place%' OR f.label LIKE '%city%')
        AND DATE(s.submitted_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$form_id, $start_date, $end_date]);
    $loc_answers = $stmt->fetchAll();

    $location_counts = [];
    foreach ($loc_answers as $la) {
        $txt = trim($la['answer_text']);
        if (!empty($txt) && strlen($txt) > 2) {
            $location_counts[$txt] = ($location_counts[$txt] ?? 0) + 1;
        }
    }
    arsort($location_counts);
    $top_locations = array_slice($location_counts, 0, 10, true);

} catch (Exception $e) {
    $error_msg = "Failed to compile analytics: " . $e->getMessage();
}

$extra_head = '
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
';
include 'includes/admin_nav.php';
?>

<style>
    .analytics-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 1.5rem;
        margin-top: 1rem;
    }

    .analytics-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 1.5rem;
    }

    .chart-container {
        position: relative;
        height: 320px;
        width: 100%;
    }

    .mini-charts-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.2rem;
        margin-top: 1.5rem;
    }

    .question-analysis-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-top: 1.5rem;
    }

    @media (max-width: 900px) {
        .analytics-grid, .mini-charts-grid, .question-analysis-grid {
            grid-template-columns: 1fr;
        }
    }

    @media print {
        body {
            background: #ffffff !important;
            color: #000000 !important;
        }
        .sidebar, .topbar, .action-bar, .btn {
            display: none !important;
        }
        .main-content {
            margin: 0 !important;
            padding: 0 !important;
        }
        .analytics-card {
            border: none !important;
            box-shadow: none !important;
            page-break-inside: avoid;
        }
    }
</style>

<!-- ── CONTROL FILTER BAR ── -->
<div class="action-bar" style="background:var(--card-bg); border:1px solid var(--border); padding:1rem 1.2rem; border-radius:16px; margin-bottom:1.5rem; display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px;">
    <form method="GET" action="" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="id" value="<?php echo $form_id; ?>">
        
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size:0.8rem; font-weight:700; text-transform:uppercase; color:var(--text-muted);">Range:</span>
            <input type="date" name="start_date" class="form-input" style="margin-bottom:0; width:140px;" value="<?php echo $start_date; ?>">
            <span style="color:var(--text-muted);">to</span>
            <input type="date" name="end_date" class="form-input" style="margin-bottom:0; width:140px;" value="<?php echo $end_date; ?>">
        </div>
        
        <button type="submit" class="btn btn-secondary" style="padding:0.5rem 1.2rem;"><i class="fas fa-filter"></i> Apply Dates</button>
    </form>
    
    <div style="display:flex; gap:10px;">
        <button class="btn btn-secondary" onclick="window.print()" style="padding:0.6rem 1.2rem;"><i class="fas fa-print"></i> Print Report</button>
        <a href="campaign-form-responses.php?id=<?php echo $form_id; ?>" class="btn btn-primary" style="padding:0.6rem 1.2rem;"><i class="fas fa-list"></i> View Responses</a>
    </div>
</div>

<!-- ── PERFORMANCE ANALYSIS OVERVIEW ── -->
<div class="analytics-grid">
    <!-- Views vs Submissions Trend Line Chart -->
    <div class="analytics-card">
        <h3 style="font-size:1rem; font-weight:800; margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fas fa-chart-area" style="color:var(--accent);"></i> Form Traffic &amp; Conversion Trends</span>
            <button class="btn btn-sm btn-secondary" style="padding:2px 8px; font-size:0.75rem;" onclick="exportChart('trend-chart')"><i class="fas fa-image"></i> Export</button>
        </h3>
        <div class="chart-container">
            <canvas id="trend-chart"></canvas>
        </div>
    </div>

    <!-- Conversion Funnel Analysis -->
    <div class="analytics-card" style="display:flex; flex-direction:column; justify-content:space-between;">
        <div>
            <h3 style="font-size:1rem; font-weight:800; margin-bottom:1rem;"><i class="fas fa-filter" style="color:var(--accent);"></i> Conversion Funnel</h3>
            
            <?php
            $funnel_views = array_sum($views_timeline);
            $funnel_subs = array_sum($subs_timeline);
            $funnel_rate = $funnel_views > 0 ? round(($funnel_subs / $funnel_views) * 100, 1) : 0;
            ?>
            <div style="margin-top:1.5rem; display:flex; flex-direction:column; gap:1.5rem;">
                <div>
                    <div style="display:flex; justify-content:space-between; font-size:0.85rem; font-weight:700; margin-bottom:4px;">
                        <span>1. Visited / Viewed Form</span>
                        <span><?php echo number_format($funnel_views); ?> views</span>
                    </div>
                    <div style="background:var(--border); height:12px; border-radius:50px; overflow:hidden;">
                        <div style="background:#3b82f6; width:100%; height:100%;"></div>
                    </div>
                </div>

                <div>
                    <div style="display:flex; justify-content:space-between; font-size:0.85rem; font-weight:700; margin-bottom:4px;">
                        <span>2. Submitted Response</span>
                        <span><?php echo number_format($funnel_subs); ?> submissions</span>
                    </div>
                    <div style="background:var(--border); height:12px; border-radius:50px; overflow:hidden;">
                        <div style="background:#10b981; width:<?php echo $funnel_rate; ?>%; height:100%;"></div>
                    </div>
                    <small style="color:var(--text-muted); font-size:0.75rem; display:block; margin-top:2px;">Conversion rate of <?php echo $funnel_rate; ?>% from visits</small>
                </div>
            </div>
        </div>
        
        <div style="background:rgba(232,152,12,0.06); padding:1rem; border-radius:12px; border:1px dashed var(--border); text-align:center; margin-top:1.5rem;">
            <div style="font-size:0.8rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:2px;">Avg. Fill Rate</div>
            <div style="font-size:1.8rem; font-weight:800; color:var(--accent);"><?php echo $funnel_rate; ?>%</div>
        </div>
    </div>
</div>

<!-- ── DEVICE & BROWSER SEGMENTATION ── -->
<div class="mini-charts-grid">
    <!-- Device Segmentation (Donut) -->
    <div class="analytics-card">
        <h3 style="font-size:1rem; font-weight:800; margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fas fa-mobile-screen" style="color:var(--accent);"></i> Traffic by Device Type</span>
            <button class="btn btn-sm btn-secondary" style="padding:2px 8px; font-size:0.75rem;" onclick="exportChart('device-chart')"><i class="fas fa-image"></i> Export</button>
        </h3>
        <div class="chart-container" style="height:250px;">
            <canvas id="device-chart"></canvas>
        </div>
    </div>

    <!-- Top Browsers (Horizontal Bar) -->
    <div class="analytics-card">
        <h3 style="font-size:1rem; font-weight:800; margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fas fa-compass" style="color:var(--accent);"></i> Top Visitor Browsers</span>
            <button class="btn btn-sm btn-secondary" style="padding:2px 8px; font-size:0.75rem;" onclick="exportChart('browser-chart')"><i class="fas fa-image"></i> Export</button>
        </h3>
        <div class="chart-container" style="height:250px;">
            <canvas id="browser-chart"></canvas>
        </div>
    </div>
</div>

<!-- ── LOCATION BASED GEOGRAPHIC ANALYSIS & INTERACTIVE MAP ── -->
<div style="margin-top: 2rem;">
    <h3 style="font-size:1.1rem; font-weight:800; margin-bottom:0.8rem; border-bottom:1.5px solid var(--border); padding-bottom:6px; display:flex; align-items:center; gap:8px;">
        <i class="fas fa-map-location-dot" style="color:var(--accent);"></i> Location Based Response Analysis
    </h3>

    <div class="analytics-grid" style="grid-template-columns: 2fr 1fr;">
        <!-- Leaflet Interactive Map Card -->
        <div class="analytics-card" style="padding: 1.2rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.8rem;">
                <h4 style="font-size:0.95rem; font-weight:700; color:var(--text-main); margin:0;">
                    <i class="fas fa-earth-americas" style="color:#3b82f6;"></i> Submissions Geolocation Map
                </h4>
                <span class="badge blue" style="padding:3px 8px; font-size:0.75rem; font-weight:700;"><?php echo count($geo_points); ?> Mapped Locations</span>
            </div>
            <div id="analytics-map" style="height: 340px; width:100%; border-radius: 12px; border: 1px solid var(--border); z-index:1;"></div>
        </div>

        <!-- Top Regions / Districts Stats -->
        <div class="analytics-card" style="display:flex; flex-direction:column; justify-content:space-between;">
            <div>
                <h4 style="font-size:0.95rem; font-weight:700; color:var(--text-main); margin-bottom:1rem;">
                    <i class="fas fa-city" style="color:#10b981;"></i> Top Respondent Locations
                </h4>
                
                <?php if (empty($top_locations)): ?>
                    <div style="text-align:center; padding:2rem 1rem; color:var(--text-muted); font-size:0.85rem;">
                        <i class="fas fa-location-dot" style="font-size:2rem; margin-bottom:8px; display:block; opacity:0.5;"></i>
                        No specific location response data found for this date range.
                    </div>
                <?php else: ?>
                    <div style="display:flex; flex-direction:column; gap:8px; max-height:260px; overflow-y:auto; padding-right:4px;">
                        <?php 
                        $max_loc = max($top_locations);
                        foreach ($top_locations as $loc_name => $count): 
                            $pct = round(($count / max(1, $funnel_subs)) * 100, 1);
                        ?>
                            <div style="background:var(--input-bg); padding:8px 12px; border-radius:8px; border:1px solid var(--border);">
                                <div style="display:flex; justify-content:space-between; font-size:0.82rem; font-weight:700; margin-bottom:3px;">
                                    <span style="color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:170px;"><?php echo htmlspecialchars($loc_name); ?></span>
                                    <span style="color:var(--accent);"><?php echo $count; ?> (<?php echo $pct; ?>%)</span>
                                </div>
                                <div style="background:var(--border); height:6px; border-radius:10px; overflow:hidden;">
                                    <div style="background:var(--accent); width:<?php echo min(100, round(($count / $max_loc) * 100)); ?>%; height:100%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div style="margin-top:1rem; padding:10px; background:rgba(59, 130, 246, 0.08); border-radius:10px; border:1px solid rgba(59, 130, 246, 0.2); font-size:0.78rem; color:var(--text-muted); display:flex; align-items:center; gap:8px;">
                <i class="fas fa-circle-info" style="color:#3b82f6; font-size:1.1rem;"></i>
                <span>Map markers show exact GPS location coordinates saved automatically upon submission.</span>
            </div>
        </div>
    </div>
</div>

<!-- ── QUESTION-WISE CHOICE ANALYSIS ── -->
<div style="margin-top:2rem;">
    <h3 style="font-size:1.1rem; font-weight:800; margin-bottom:0.8rem; border-bottom:1.5px solid var(--border); padding-bottom:6px;"><i class="fas fa-chart-pie" style="color:var(--accent);"></i> Question Choice Distribution</h3>
    
    <?php if (empty($question_charts)): ?>
        <div class="card" style="text-align:center; padding:3rem; color:var(--text-muted);">
            <i class="fas fa-chart-bar" style="font-size:3rem; margin-bottom:10px; display:block;"></i>
            <p>This form does not contain any choices-based questions (Dropdown, Radio, Checkboxes, Multi-select) to analyze.</p>
        </div>
    <?php else: ?>
        <div class="question-analysis-grid">
            <?php foreach ($question_charts as $qc): ?>
                <div class="analytics-card">
                    <h4 style="font-size:0.92rem; font-weight:700; margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center;">
                        <span><?php echo htmlspecialchars($qc['label']); ?></span>
                        <button class="btn btn-sm btn-secondary" style="padding:2px 8px; font-size:0.75rem;" onclick="exportChart('q-chart-<?php echo $qc['id']; ?>')"><i class="fas fa-image"></i> Export</button>
                    </h4>
                    <div class="chart-container" style="height:240px;">
                        <canvas id="q-chart-<?php echo $qc['id']; ?>"></canvas>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    // Data variables loaded from PHP
    var timelineDates = <?php echo json_encode($dates_timeline); ?>;
    var timelineViews = <?php echo json_encode($views_timeline); ?>;
    var timelineSubs  = <?php echo json_encode($subs_timeline); ?>;

    var deviceLabels = <?php echo json_encode(array_column($devices, 'device')); ?>;
    var deviceData   = <?php echo json_encode(array_map('intval', array_column($devices, 'count'))); ?>;

    var browserLabels = <?php echo json_encode(array_column($browsers, 'browser')); ?>;
    var browserData   = <?php echo json_encode(array_map('intval', array_column($browsers, 'count'))); ?>;

    var chartObjects = {};

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Trend Line Chart
        var ctxTrend = document.getElementById('trend-chart').getContext('2d');
        chartObjects['trend-chart'] = new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: timelineDates,
                datasets: [
                    {
                        label: 'Page Views',
                        data: timelineViews,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.08)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 2.5
                    },
                    {
                        label: 'Submissions',
                        data: timelineSubs,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.08)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 2.5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-color').trim() } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#94a3b8' } },
                    y: { grid: { color: 'rgba(148, 163, 184, 0.1)' }, ticks: { color: '#94a3b8', stepSize: 1 } }
                }
            }
        });

        // Initialize Device Pie Chart
        var ctxDevice = document.getElementById('device-chart').getContext('2d');
        chartObjects['device-chart'] = new Chart(ctxDevice, {
            type: 'doughnut',
            data: {
                labels: deviceLabels.map(l => l.charAt(0).toUpperCase() + l.slice(1)),
                datasets: [{
                    data: deviceData,
                    backgroundColor: ['#e8980c', '#3b82f6', '#ec4899', '#10b981'],
                    borderWidth: 1.5,
                    borderColor: 'rgba(0,0,0,0.1)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { color: '#94a3b8' } }
                }
            }
        });

        // Initialize Browser Bar Chart
        var ctxBrowser = document.getElementById('browser-chart').getContext('2d');
        chartObjects['browser-chart'] = new Chart(ctxBrowser, {
            type: 'bar',
            data: {
                labels: browserLabels,
                datasets: [{
                    label: 'Visits',
                    data: browserData,
                    backgroundColor: '#10b981',
                    borderRadius: 5
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { ticks: { color: '#94a3b8', stepSize: 1 } },
                    y: { ticks: { color: '#94a3b8' } }
                }
            }
        });

        // Initialize Question-wise Charts
        <?php foreach ($question_charts as $qc): ?>
        var ctxQ_<?php echo $qc['id']; ?> = document.getElementById('q-chart-<?php echo $qc['id']; ?>').getContext('2d');
        chartObjects['q-chart-<?php echo $qc['id']; ?>'] = new Chart(ctxQ_<?php echo $qc['id']; ?>, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($qc['labels']); ?>,
                datasets: [{
                    data: <?php echo json_encode($qc['data']); ?>,
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6'],
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { ticks: { color: '#94a3b8' } },
                    y: { ticks: { color: '#94a3b8', stepSize: 1 } }
                }
            }
        });
        <?php endforeach; ?>

        // Initialize Leaflet Map
        var geoPoints = <?php echo json_encode($geo_points); ?>;
        var mapEl = document.getElementById('analytics-map');
        if (mapEl && typeof L !== 'undefined') {
            var map = L.map('analytics-map').setView([10.8505, 76.2711], 6); // Default Kerala / India

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap'
            }).addTo(map);

            var bounds = [];
            if (geoPoints && geoPoints.length > 0) {
                geoPoints.forEach(function(pt) {
                    var lat = parseFloat(pt.latitude);
                    var lng = parseFloat(pt.longitude);
                    if (!isNaN(lat) && !isNaN(lng)) {
                        bounds.push([lat, lng]);
                        var respondentName = pt.respondent_identifier || ('Respondent #' + pt.id);
                        var popupContent = '<div style="font-family:sans-serif; font-size:12px; padding:4px;">' +
                            '<strong style="font-size:13px; color:#1e293b;">' + respondentName + '</strong><br>' +
                            '<span style="color:#64748b;">Submitted: ' + pt.submitted_at + '</span><br>' +
                            '<span style="color:#64748b; font-family:monospace; font-size:11px;">Lat: ' + lat.toFixed(4) + ', Lng: ' + lng.toFixed(4) + '</span><br>' +
                            '<a href="https://www.google.com/maps/search/?api=1&query=' + lat + ',' + lng + '" target="_blank" style="color:#2563eb; font-weight:bold; text-decoration:none; display:inline-block; margin-top:6px;"><i class="fas fa-arrow-up-right-from-square"></i> Open Google Maps</a>' +
                            '</div>';
                        L.marker([lat, lng]).addTo(map).bindPopup(popupContent);
                    }
                });
                if (bounds.length > 0) {
                    map.fitBounds(bounds, { padding: [35, 35] });
                }
            }
        }
    });

    // Export chart as base64 image download
    function exportChart(chartId) {
        var chart = chartObjects[chartId];
        if (chart) {
            var url = chart.toBase64Image();
            var a = document.createElement('a');
            a.href = url;
            a.download = chartId + '_' + Date.now() + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
