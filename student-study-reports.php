<?php
require_once 'includes/auth.php';
require_permission('studyplans');
require_once 'config/database.php';

// Helper to escape output
function r_esc($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// Time ago helper
function time_ago($timestamp) {
    if (empty($timestamp)) return '--';
    $time = strtotime($timestamp);
    $diff = time() - $time;
    
    if ($diff < 1) return 'Just now';
    
    $intervals = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
        1        => 'second'
    ];
    
    foreach ($intervals as $secs => $str) {
        $d = $diff / $secs;
        if ($d >= 1) {
            $r = round($d);
            return $r . ' ' . $str . ($r > 1 ? 's' : '') . ' ago';
        }
    }
    return date('d M Y h:i A', $time);
}

// Get performance status mapping
function get_performance_status($pct) {
    if ($pct >= 85) return ['label' => 'Excellent', 'color' => 'green'];
    if ($pct >= 60) return ['label' => 'Good', 'color' => 'blue'];
    if ($pct >= 40) return ['label' => 'Average', 'color' => 'amber'];
    return ['label' => 'Needs Improvement', 'color' => 'red'];
}

// 1. Fetch all assigned courses and forms with linked study plans
try {
    $assigned_courses = $pdo->query("
        SELECT DISTINCT sa.assigned_value as course_name 
        FROM study_plan_assignments sa
        JOIN study_plans sp ON sa.study_plan_id = sp.id
        WHERE sa.assignment_type = 'course' AND sp.status = 'published'
        ORDER BY sa.assigned_value ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $assigned_forms = $pdo->query("
        SELECT DISTINCT f.id, f.title 
        FROM study_plan_assignments sa
        JOIN study_plans sp ON sa.study_plan_id = sp.id
        JOIN campaign_forms f ON sa.assigned_value = CAST(f.id AS CHAR)
        WHERE sa.assignment_type = 'form' AND sp.status = 'published'
        ORDER BY f.title ASC
    ")->fetchAll();
} catch (Exception $e) {
    $assigned_courses = [];
    $assigned_forms = [];
}

$selected_course = $_GET['course_name'] ?? null;
$selected_form_id = isset($_GET['form_id']) ? (int)$_GET['form_id'] : null;

$students_list = [];

if ($selected_course) {
    try {
        // Fetch all approved students in this course
        $stmt = $pdo->prepare("
            SELECT user_id, name, email, pepp_course, academic_year 
            FROM users 
            WHERE pepp_course = ? AND status = 'approved'
            ORDER BY name ASC
        ");
        $stmt->execute([$selected_course]);
        $raw_students = $stmt->fetchAll();
        
        // Fetch assigned plans details
        $stmt_plans = $pdo->prepare("
            SELECT DISTINCT study_plan_id 
            FROM study_plan_assignments sa
            JOIN study_plans sp ON sa.study_plan_id = sp.id
            WHERE sa.assignment_type = 'course' AND sa.assigned_value = ? AND sp.status = 'published'
        ");
        $stmt_plans->execute([$selected_course]);
        $plan_ids = $stmt_plans->fetchAll(PDO::FETCH_COLUMN);
        
        $plans_count = count($plan_ids);
        
        $total_tasks = 0;
        if ($plans_count > 0) {
            $in_clause = implode(',', array_fill(0, $plans_count, '?'));
            $stmt_tasks = $pdo->prepare("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id IN ($in_clause)");
            $stmt_tasks->execute($plan_ids);
            $total_tasks = (int)$stmt_tasks->fetchColumn();
        }
        
        foreach ($raw_students as $std) {
            $completed_tasks = 0;
            $last_updated = null;
            
            if ($plans_count > 0 && $total_tasks > 0) {
                // Get completed tasks
                $in_clause = implode(',', array_fill(0, $plans_count, '?'));
                $stmt_comp = $pdo->prepare("
                    SELECT COUNT(DISTINCT activity_id), MAX(created_at)
                    FROM study_plan_analytics 
                    WHERE student_email = ? AND action_type = 'complete_activity' AND study_plan_id IN ($in_clause)
                ");
                $params = array_merge([$std['email']], $plan_ids);
                $stmt_comp->execute($params);
                $row_comp = $stmt_comp->fetch(PDO::FETCH_NUM);
                $completed_tasks = (int)($row_comp[0] ?? 0);
                $last_updated = $row_comp[1] ?? null;
            }
            
            $comp_pct = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
            $perf = get_performance_status($comp_pct);
            
            $students_list[] = [
                'name' => $std['name'],
                'email' => $std['email'],
                'type' => 'student',
                'plans_count' => $plans_count,
                'total_tasks' => $total_tasks,
                'completed_tasks' => $completed_tasks,
                'completed_pct' => $comp_pct,
                'pending_pct' => 100 - $comp_pct,
                'performance' => $perf,
                'last_updated' => $last_updated
            ];
        }
    } catch (Exception $e) {
        $students_list = [];
    }
} elseif ($selected_form_id) {
    try {
        // Fetch form details
        $stmt_form = $pdo->prepare("SELECT title FROM campaign_forms WHERE id = ?");
        $stmt_form->execute([$selected_form_id]);
        $form_title = $stmt_form->fetchColumn();
        
        // Fetch all unique respondents
        $stmt_subs = $pdo->prepare("
            SELECT s.id as submission_id, s.respondent_identifier, s.submitted_at
            FROM campaign_form_submissions s
            WHERE s.form_id = ? AND s.is_deleted = 0
            ORDER BY s.submitted_at DESC
        ");
        $stmt_subs->execute([$selected_form_id]);
        $raw_subs = $stmt_subs->fetchAll();
        
        // Fetch plans linked
        $stmt_plans = $pdo->prepare("
            SELECT DISTINCT study_plan_id 
            FROM study_plan_assignments sa
            JOIN study_plans sp ON sa.study_plan_id = sp.id
            WHERE sa.assignment_type = 'form' AND sa.assigned_value = ? AND sp.status = 'published'
        ");
        $stmt_plans->execute([$selected_form_id]);
        $plan_ids = $stmt_plans->fetchAll(PDO::FETCH_COLUMN);
        
        $plans_count = count($plan_ids);
        
        $total_tasks = 0;
        if ($plans_count > 0) {
            $in_clause = implode(',', array_fill(0, $plans_count, '?'));
            $stmt_tasks = $pdo->prepare("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id IN ($in_clause)");
            $stmt_tasks->execute($plan_ids);
            $total_tasks = (int)$stmt_tasks->fetchColumn();
        }
        
        foreach ($raw_subs as $sub) {
            // Resolve email
            $email = $sub['respondent_identifier'];
            
            // Resolve Name from answers
            $stmt_name = $pdo->prepare("
                SELECT a.answer_text 
                FROM campaign_form_answers a
                JOIN campaign_form_fields f ON a.field_id = f.id
                WHERE a.submission_id = ? AND (f.label LIKE '%name%' OR f.field_name LIKE '%name%')
                ORDER BY f.sort_order ASC
                LIMIT 1
            ");
            $stmt_name->execute([$sub['submission_id']]);
            $resolved_name = $stmt_name->fetchColumn();
            
            // If email doesn't look like email, check answers
            if (empty($email) || strpos($email, '@') === false) {
                $stmt_email = $pdo->prepare("
                    SELECT a.answer_text 
                    FROM campaign_form_answers a
                    JOIN campaign_form_fields f ON a.field_id = f.id
                    WHERE a.submission_id = ? AND (f.type = 'email' OR f.label LIKE '%email%' OR f.field_name LIKE '%email%')
                    ORDER BY f.sort_order ASC
                    LIMIT 1
                ");
                $stmt_email->execute([$sub['submission_id']]);
                $resolved_email = $stmt_email->fetchColumn();
                if ($resolved_email) {
                    $email = $resolved_email;
                }
            }
            
            if (empty($email)) continue;
            
            $completed_tasks = 0;
            $last_updated = null;
            
            if ($plans_count > 0 && $total_tasks > 0) {
                // Get completed tasks
                $in_clause = implode(',', array_fill(0, $plans_count, '?'));
                $stmt_comp = $pdo->prepare("
                    SELECT COUNT(DISTINCT activity_id), MAX(created_at)
                    FROM study_plan_analytics 
                    WHERE student_email = ? AND action_type = 'complete_activity' AND study_plan_id IN ($in_clause)
                ");
                $params = array_merge([$email], $plan_ids);
                $stmt_comp->execute($params);
                $row_comp = $stmt_comp->fetch(PDO::FETCH_NUM);
                $completed_tasks = (int)($row_comp[0] ?? 0);
                $last_updated = $row_comp[1] ?? null;
            }
            
            $comp_pct = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
            $perf = get_performance_status($comp_pct);
            
            $students_list[] = [
                'name' => $resolved_name ?: 'User #' . $sub['submission_id'],
                'email' => $email,
                'type' => 'form_user',
                'plans_count' => $plans_count,
                'total_tasks' => $total_tasks,
                'completed_tasks' => $completed_tasks,
                'completed_pct' => $comp_pct,
                'pending_pct' => 100 - $comp_pct,
                'performance' => $perf,
                'last_updated' => $last_updated
            ];
        }
    } catch (Exception $e) {
        $students_list = [];
    }
}

// Search, Performance status filtering & Completion Order usort
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$perf_filter = isset($_GET['perf_status']) ? trim($_GET['perf_status']) : '';

if ($search_query !== '' || $perf_filter !== '') {
    $filtered_list = [];
    foreach ($students_list as $std) {
        if ($search_query !== '') {
            if (stripos($std['name'], $search_query) === false && stripos($std['email'], $search_query) === false) {
                continue;
            }
        }
        if ($perf_filter !== '') {
            if (strcasecmp($std['performance']['label'], $perf_filter) !== 0) {
                continue;
            }
        }
        $filtered_list[] = $std;
    }
    $students_list = $filtered_list;
}

usort($students_list, function($a, $b) {
    return $b['completed_pct'] <=> $a['completed_pct'];
});

// 2. Fetch specific user detail trace
$view_user = $_GET['view_user'] ?? null;
$view_user_email = $_GET['email'] ?? null;
$user_detail = null;
$user_plans = [];

if ($view_user_email) {
    try {
        // Resolve user overall info
        // First try users table
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$view_user_email]);
        $usr = $stmt->fetch();
        
        if ($usr) {
            $user_detail = [
                'name' => $usr['name'],
                'email' => $usr['email'],
                'context' => 'Course: ' . $usr['pepp_course'],
                'course' => $usr['pepp_course'],
                'form_id' => null
            ];
        } else {
            // Check submission
            $stmt = $pdo->prepare("
                SELECT s.id, s.respondent_identifier, f.title 
                FROM campaign_form_submissions s
                JOIN campaign_forms f ON s.form_id = f.id
                LEFT JOIN campaign_form_answers a ON s.id = a.submission_id
                WHERE (s.respondent_identifier = ? OR a.answer_text = ?) AND s.is_deleted = 0
                LIMIT 1
            ");
            $stmt->execute([$view_user_email, $view_user_email]);
            $sub = $stmt->fetch();
            
            if ($sub) {
                // Name
                $stmt_name = $pdo->prepare("
                    SELECT a.answer_text 
                    FROM campaign_form_answers a
                    JOIN campaign_form_fields f ON a.field_id = f.id
                    WHERE a.submission_id = ? AND (f.label LIKE '%name%' OR f.field_name LIKE '%name%')
                    ORDER BY f.sort_order ASC
                    LIMIT 1
                ");
                $stmt_name->execute([$sub['id']]);
                $resolved_name = $stmt_name->fetchColumn();
                
                $user_detail = [
                    'name' => $resolved_name ?: 'User #' . $sub['id'],
                    'email' => $view_user_email,
                    'context' => 'Campaign Form: ' . $sub['title'],
                    'course' => null,
                    'form_id' => $sub['id']
                ];
            }
        }
        
        if ($user_detail) {
            // Find all study plans assigned to this user (based on course or form id)
            $sql_assigned = "
                SELECT DISTINCT sp.* 
                FROM study_plans sp
                JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
                WHERE sp.status = 'published' AND (
                    (sa.assignment_type = 'course' AND sa.assigned_value = ?) OR
                    (sa.assignment_type = 'form' AND sa.assigned_value IN (
                        SELECT CAST(form_id AS CHAR) FROM campaign_form_submissions WHERE respondent_identifier = ? AND is_deleted = 0
                        UNION
                        SELECT CAST(s.form_id AS CHAR) FROM campaign_form_submissions s JOIN campaign_form_answers a ON s.id = a.submission_id WHERE a.answer_text = ? AND s.is_deleted = 0
                    ))
                )
            ";
            $stmt_as = $pdo->prepare($sql_assigned);
            $stmt_as->execute([$user_detail['course'], $view_user_email, $view_user_email]);
            $assigned_plans = $stmt_as->fetchAll();
            
            foreach ($assigned_plans as $p) {
                // Get activities total
                $stmt_t = $pdo->prepare("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = ?");
                $stmt_t->execute([$p['id']]);
                $tot = (int)$stmt_t->fetchColumn();
                
                // Get completed
                $stmt_c = $pdo->prepare("
                    SELECT COUNT(DISTINCT activity_id) 
                    FROM study_plan_analytics 
                    WHERE student_email = ? AND study_plan_id = ? AND action_type = 'complete_activity'
                ");
                $stmt_c->execute([$view_user_email, $p['id']]);
                $comp = (int)$stmt_c->fetchColumn();
                
                $pct = $tot > 0 ? round(($comp / $tot) * 100) : 0;
                $perf = get_performance_status($pct);
                
                $user_plans[] = [
                    'plan' => $p,
                    'total_tasks' => $tot,
                    'completed_tasks' => $comp,
                    'completed_pct' => $pct,
                    'pending_pct' => 100 - $pct,
                    'performance' => $perf
                ];
            }
        }
    } catch (Exception $e) {
        $user_detail = null;
    }
}

// 3. Specific plan view selected of a user
$selected_plan_detail = null;
$plan_activities = [];
$login_logs = [];
$target_plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : null;

if ($user_detail && $target_plan_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM study_plans WHERE id = ? LIMIT 1");
        $stmt->execute([$target_plan_id]);
        $selected_plan_detail = $stmt->fetch();
        
        if ($selected_plan_detail) {
            // Fetch activities
            $stmt_act = $pdo->prepare("
                SELECT a.*, an.created_at as completed_at, an.latitude, an.longitude
                FROM study_plan_activities a
                LEFT JOIN study_plan_analytics an ON a.id = an.activity_id AND an.student_email = ? AND an.action_type = 'complete_activity'
                WHERE a.study_plan_id = ?
                ORDER BY a.activity_date ASC, a.sort_order ASC
            ");
            $stmt_act->execute([$view_user_email, $target_plan_id]);
            $plan_activities = $stmt_act->fetchAll();
            
            // Fetch view logs
            $stmt_log = $pdo->prepare("
                SELECT * 
                FROM study_plan_analytics 
                WHERE student_email = ? AND study_plan_id = ? AND action_type = 'view'
                ORDER BY created_at DESC
            ");
            $stmt_log->execute([$view_user_email, $target_plan_id]);
            $login_logs = $stmt_log->fetchAll();
        }
    } catch (Exception $e) {}
}

$page_title = 'Student Study Reports';
$page_sub = 'Track daily checklist completions, performance indexes, and access traces';
$active_page = 'student-study-reports';

include 'includes/admin_nav.php';
?>

<div class="container-fluid" style="padding: 1.5rem 0;">
    <?php if (!$view_user): ?>
        <!-- Section Selector and Main Grid Listing -->
        <div class="row">
            <div class="col-md-3">
                <div class="panel" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.2rem; margin-bottom:1.5rem;">
                    <div class="panel-header" style="border-bottom:1px solid var(--border); padding-bottom:8px; margin-bottom:12px;">
                        <h4 style="font-family:var(--header-font); font-weight:700; margin:0; color:var(--text-main);"><i class="fas fa-filter" style="color:var(--accent);"></i> Filter Group</h4>
                    </div>
                    
                    <h5 style="font-size:0.75rem; text-transform:uppercase; font-weight:700; color:var(--text-muted); margin-bottom:8px;">PEPP Courses</h5>
                    <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:20px;">
                        <?php if (empty($assigned_courses)): ?>
                            <small style="color:var(--text-muted);">No courses with linked plans.</small>
                        <?php else: ?>
                            <?php foreach ($assigned_courses as $cname): 
                                $active = $selected_course === $cname;
                            ?>
                                <a href="?course_name=<?php echo urlencode($cname); ?>" style="display:flex; justify-content:space-between; align-items:center; text-decoration:none; padding:8px 12px; border-radius:10px; font-size:0.85rem; font-weight:600; background:<?php echo $active ? 'var(--accent-soft)' : 'transparent'; ?>; color:<?php echo $active ? 'var(--accent)' : 'var(--text-main)'; ?>; border:1px solid <?php echo $active ? 'var(--accent)' : 'transparent'; ?>;">
                                    <span><i class="fas fa-graduation-cap"></i> <?php echo r_esc($cname); ?></span>
                                    <i class="fas fa-chevron-right" style="font-size:0.7rem; opacity:0.6;"></i>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <h5 style="font-size:0.75rem; text-transform:uppercase; font-weight:700; color:var(--text-muted); margin-bottom:8px;">Custom Forms</h5>
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <?php if (empty($assigned_forms)): ?>
                            <small style="color:var(--text-muted);">No forms with linked plans.</small>
                        <?php else: ?>
                            <?php foreach ($assigned_forms as $frm): 
                                $active = $selected_form_id === (int)$frm['id'];
                            ?>
                                <a href="?form_id=<?php echo $frm['id']; ?>" style="display:flex; justify-content:space-between; align-items:center; text-decoration:none; padding:8px 12px; border-radius:10px; font-size:0.85rem; font-weight:600; background:<?php echo $active ? 'var(--accent-soft)' : 'transparent'; ?>; color:<?php echo $active ? 'var(--accent)' : 'var(--text-main)'; ?>; border:1px solid <?php echo $active ? 'var(--accent)' : 'transparent'; ?>;">
                                    <span><i class="fab fa-wpforms"></i> <?php echo r_esc($frm['title']); ?></span>
                                    <i class="fas fa-chevron-right" style="font-size:0.7rem; opacity:0.6;"></i>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-9">
                <div class="panel" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.2rem; min-height:400px;">
                    <?php if (!$selected_course && !$selected_form_id): ?>
                        <div style="text-align:center; padding:5rem 2rem; color:var(--text-muted);">
                            <i class="fas fa-chart-line" style="font-size:3.5rem; color:var(--border); margin-bottom:14px; display:block;"></i>
                            <h4 style="font-family:var(--header-font); font-weight:700; color:var(--text-main);">Academic Completion Index</h4>
                            <p style="font-size:0.85rem; max-width:400px; margin:6px auto 0 auto;">Select any course or campaign form from the left panel to drill down into student daily task completions and performance analytics.</p>
                        </div>
                    <?php else: ?>
                        <div style="border-bottom:1px solid var(--border); padding-bottom:10px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
                            <h3 style="font-family:var(--header-font); font-weight:800; font-size:1.15rem; margin:0; color:var(--text-main);">
                                <?php echo $selected_course ? 'Enrolled Course: ' . r_esc($selected_course) : 'Campaign Form: ' . r_esc($form_title); ?>
                            </h3>
                            <span class="badge blue" style="font-size:0.78rem; font-weight:700;"><?php echo count($students_list); ?> total records</span>
                        </div>
                        
                        <!-- Search & Filters Action Bar -->
                        <div class="action-bar" style="background:#f8fafc; border:1px solid var(--border); padding:0.8rem 1rem; border-radius:12px; margin-bottom:1.2rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                            <form method="GET" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0; width:100%;">
                                <?php if ($selected_course): ?>
                                    <input type="hidden" name="course_name" value="<?php echo htmlspecialchars($selected_course); ?>">
                                <?php elseif ($selected_form_id): ?>
                                    <input type="hidden" name="form_id" value="<?php echo $selected_form_id; ?>">
                                <?php endif; ?>
                                
                                <input type="text" name="search" class="form-input" style="margin-bottom:0; width:220px; font-size:0.85rem;" placeholder="Search student name or email..." value="<?php echo r_esc($search_query); ?>">
                                
                                <select name="perf_status" class="form-input" style="margin-bottom:0; width:180px; font-size:0.85rem;">
                                    <option value="">All Performances</option>
                                    <option value="Excellent" <?php echo $perf_filter === 'Excellent' ? 'selected' : ''; ?>>Excellent (>= 85%)</option>
                                    <option value="Good" <?php echo $perf_filter === 'Good' ? 'selected' : ''; ?>>Good (60% - 84%)</option>
                                    <option value="Average" <?php echo $perf_filter === 'Average' ? 'selected' : ''; ?>>Average (40% - 59%)</option>
                                    <option value="Needs Improvement" <?php echo $perf_filter === 'Needs Improvement' ? 'selected' : ''; ?>>Needs Improvement (< 40%)</option>
                                </select>
                                
                                <button type="submit" class="btn btn-sm btn-secondary"><i class="fas fa-filter"></i> Filter</button>
                                <a href="?<?php echo $selected_course ? 'course_name='.urlencode($selected_course) : 'form_id='.$selected_form_id; ?>" class="btn btn-sm btn-outline">Reset</a>
                            </form>
                        </div>
                        
                        <?php if (empty($students_list)): ?>
                            <div style="text-align:center; padding:4rem; color:var(--text-muted);">
                                <i class="fas fa-user-slash" style="font-size:2rem; margin-bottom:8px; display:block;"></i>
                                No student records match the filters.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height:550px; overflow-y:auto; position:relative; border:1.5px solid var(--border); border-radius:12px;">
                                <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem; position:relative;">
                                    <thead>
                                        <tr style="border-bottom:1.5px solid var(--border); text-align:left;">
                                            <th style="padding:12px 10px; color:var(--text-muted); font-weight:700; position:sticky; top:0; background:#ffffff; z-index:10; border-bottom:2px solid var(--border);">Student / User Name</th>
                                            <th style="padding:12px 10px; color:var(--text-muted); font-weight:700; position:sticky; top:0; background:#ffffff; z-index:10; border-bottom:2px solid var(--border);">Plans Assigned</th>
                                            <th style="padding:12px 10px; color:var(--text-muted); font-weight:700; position:sticky; top:0; background:#ffffff; z-index:10; border-bottom:2px solid var(--border);">Completed %</th>
                                            <th style="padding:12px 10px; color:var(--text-muted); font-weight:700; position:sticky; top:0; background:#ffffff; z-index:10; border-bottom:2px solid var(--border);">Pending %</th>
                                            <th style="padding:12px 10px; color:var(--text-muted); font-weight:700; position:sticky; top:0; background:#ffffff; z-index:10; border-bottom:2px solid var(--border);">Performance Status</th>
                                            <th style="padding:12px 10px; color:var(--text-muted); font-weight:700; position:sticky; top:0; background:#ffffff; z-index:10; border-bottom:2px solid var(--border);">Last Active</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students_list as $std): ?>
                                            <tr style="border-bottom:1px solid #f1f5f9; transition:background 0.2s;">
                                                <td style="padding:12px 8px; font-weight:700;">
                                                    <a href="?view_user=1&email=<?php echo urlencode($std['email']); ?>" style="color:var(--text-main); text-decoration:none; display:block;">
                                                        <?php echo r_esc($std['name']); ?>
                                                        <small style="display:block; font-weight:400; color:var(--text-muted); font-size:0.75rem;"><?php echo r_esc($std['email']); ?></small>
                                                    </a>
                                                </td>
                                                <td style="padding:12px 8px; font-weight:600;"><?php echo $std['plans_count']; ?> plans <small style="display:block; color:var(--text-muted); font-size:0.75rem; font-weight:400;"><?php echo $std['total_tasks']; ?> tasks total</small></td>
                                                <td style="padding:12px 8px; font-weight:700; color:#10b981;"><?php echo $std['completed_pct']; ?>% <small style="display:block; font-weight:400; color:var(--text-muted); font-size:0.75rem;"><?php echo $std['completed_tasks']; ?> done</small></td>
                                                <td style="padding:12px 8px; font-weight:600; color:var(--accent);"><?php echo $std['pending_pct']; ?>%</td>
                                                <td style="padding:12px 8px;">
                                                    <span class="badge <?php echo $std['performance']['color']; ?>" style="font-weight:700; font-size:0.7rem; text-transform:uppercase;">
                                                        <?php echo $std['performance']['label']; ?>
                                                    </span>
                                                </td>
                                                <td style="padding:12px 8px; color:var(--text-muted); font-size:0.8rem;">
                                                    <?php echo time_ago($std['last_updated']); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Drill Down specific user detail trace dashboard -->
        <div style="margin-bottom:1.5rem;">
            <a href="student-study-reports.php" class="btn btn-outline" style="font-weight:700;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
        
        <?php if (!$user_detail): ?>
            <div class="alert alert-danger">Selected student/user details could not be found or retrieved.</div>
        <?php else: ?>
            <div class="row">
                <div class="col-md-4">
                    <!-- User Profile & Performance overview card -->
                    <div class="panel" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.2rem; margin-bottom:1.5rem;">
                        <div style="text-align:center; padding:1rem 0; border-bottom:1px solid var(--border); margin-bottom:1rem;">
                            <div style="width:70px; height:70px; border-radius:50%; background:var(--accent-soft); border:2px solid var(--accent); color:var(--accent); display:inline-flex; align-items:center; justify-content:center; font-size:2rem; font-weight:800; margin-bottom:10px;">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <h4 style="font-family:var(--header-font); font-weight:800; color:var(--text-main); margin-bottom:4px;"><?php echo r_esc($user_detail['name']); ?></h4>
                            <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:4px;"><?php echo r_esc($user_detail['email']); ?></p>
                            <span class="badge blue" style="font-size:0.75rem; font-weight:700;"><?php echo r_esc($user_detail['context']); ?></span>
                        </div>
                        
                        <h5 style="font-family:var(--header-font); font-weight:700; font-size:0.9rem; color:var(--text-muted); text-transform:uppercase; margin-bottom:10px;">Assigned Study Plans</h5>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <?php if (empty($user_plans)): ?>
                                <div style="font-size:0.85rem; color:var(--text-muted); text-align:center; padding:2rem;">No published plans assigned to this user.</div>
                            <?php else: ?>
                                <?php foreach ($user_plans as $up): 
                                    $active = $target_plan_id === (int)$up['plan']['id'];
                                ?>
                                    <a href="?view_user=1&email=<?php echo urlencode($view_user_email); ?>&plan_id=<?php echo $up['plan']['id']; ?>" style="display:block; text-decoration:none; color:inherit; background:<?php echo $active ? 'var(--accent-soft)' : '#fff'; ?>; border:1px solid <?php echo $active ? 'var(--accent)' : 'var(--border)'; ?>; padding:12px; border-radius:12px; transition:all 0.2s;">
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:4px;">
                                            <strong style="font-size:0.85rem; color:var(--text-main);"><?php echo r_esc($up['plan']['title']); ?></strong>
                                            <span class="badge <?php echo $up['performance']['color']; ?>" style="font-size:0.65rem; font-weight:700;"><?php echo $up['performance']['label']; ?></span>
                                        </div>
                                        <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:var(--text-muted);">
                                            <span>Progress: <strong><?php echo $up['completed_pct']; ?>%</strong></span>
                                            <span><?php echo $up['completed_tasks']; ?> / <?php echo $up['total_tasks']; ?> tasks</span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <?php if (!$target_plan_id): ?>
                        <div class="panel" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:3rem; text-align:center; min-height:400px; display:flex; flex-direction:column; justify-content:center; align-items:center;">
                            <i class="fas fa-hand-pointer" style="font-size:3rem; color:var(--border); margin-bottom:12px;"></i>
                            <h4 style="font-family:var(--header-font); font-weight:700;">Select a Study Plan</h4>
                            <p style="color:var(--text-muted); font-size:0.85rem; max-width:320px; margin-top:4px;">Select one of the assigned study plans from the left profile panel to track task completion status and map geolocation access traces.</p>
                        </div>
                    <?php else: ?>
                        <?php if (!$selected_plan_detail): ?>
                            <div class="alert alert-danger">Error: The selected study plan could not be loaded.</div>
                        <?php else: ?>
                            <!-- Selected Study Plan Details tabs -->
                            <div class="panel" style="background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:1.2rem; margin-bottom:1.5rem;">
                                <div style="border-bottom:1px solid var(--border); padding-bottom:10px; margin-bottom:16px;">
                                    <span class="badge blue" style="float:right; font-weight:700; font-size:0.72rem;">v<?php echo $selected_plan_detail['version']; ?></span>
                                    <h3 style="font-family:var(--header-font); font-weight:800; font-size:1.15rem; color:var(--text-main); margin:0 0 4px 0;"><?php echo r_esc($selected_plan_detail['title']); ?></h3>
                                    <p style="font-size:0.82rem; color:var(--text-muted); margin:0;"><?php echo r_esc($selected_plan_detail['description'] ?: 'No description provided'); ?></p>
                                </div>
                                
                                <h4 style="font-family:var(--header-font); font-weight:700; font-size:0.95rem; color:var(--text-muted); text-transform:uppercase; margin-bottom:12px;"><i class="fas fa-list-check" style="color:var(--accent);"></i> Tasks Completion Index</h4>
                                <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:24px;">
                                    <?php if (empty($plan_activities)): ?>
                                        <div style="color:var(--text-muted); font-size:0.85rem; text-align:center; padding:2rem;">No activities scheduled in this plan.</div>
                                    <?php else: ?>
                                        <?php 
                                    $is_day_wise = ($selected_plan_detail['plan_type'] ?? 'date_wise') === 'day_wise';
                                    foreach ($plan_activities as $act): 
                                        $is_done = !empty($act['completed_at']);
                                        $day_label = $is_day_wise ? ("Day " . str_pad($act['day_number'], 2, '0', STR_PAD_LEFT)) : $act['activity_date'];
                                    ?>
                                            <div style="background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center;">
                                                <div>
                                                    <strong style="font-size:0.85rem; color:var(--text-main);"><?php echo r_esc($act['activity_title']); ?></strong>
                                                    <div style="font-size:0.75rem; color:var(--text-muted);">
                                                        <?php echo r_esc($day_label); ?> · <?php echo r_esc($act['subject']); ?> · <?php echo r_esc($act['chapter']); ?>
                                                    </div>
                                                    <?php if ($is_done): ?>
                                                        <small style="display:block; font-size:0.7rem; color:#10b981; margin-top:2px;">
                                                            <i class="fas fa-circle-check"></i> Completed on <?php echo date('d M Y h:i A', strtotime($act['completed_at'])); ?>
                                                            <?php if ($act['latitude']): ?>
                                                                · <a href="https://www.google.com/maps?q=<?php echo urlencode($act['latitude'].','.$act['longitude']); ?>" target="_blank" style="color:var(--accent); font-weight:700; text-decoration:none;"><i class="fas fa-location-dot"></i> Maps</a>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <?php if ($is_done): ?>
                                                        <span style="font-size:0.75rem; font-weight:700; color:#10b981; background:rgba(16,185,129,0.1); padding:4px 8px; border-radius:6px; text-transform:uppercase;"><i class="fas fa-check"></i> Done</span>
                                                    <?php else: ?>
                                                        <span style="font-size:0.75rem; font-weight:700; color:var(--text-muted); background:var(--border); padding:4px 8px; border-radius:6px; text-transform:uppercase;">Pending</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <h4 style="font-family:var(--header-font); font-weight:700; font-size:0.95rem; color:var(--text-muted); text-transform:uppercase; margin-bottom:12px;"><i class="fas fa-clock-rotate-left" style="color:#22c55e;"></i> Student Access &amp; Location Logs</h4>
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <?php if (empty($login_logs)): ?>
                                        <div style="color:var(--text-muted); font-size:0.85rem; text-align:center; padding:2rem;">No study plan access logs recorded.</div>
                                    <?php else: ?>
                                        <?php foreach ($login_logs as $log): ?>
                                            <div style="background:#fff; border:1.5px solid var(--border); border-radius:10px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center;">
                                                <div>
                                                    <span style="font-size:0.85rem; font-weight:700; color:var(--text-main);"><i class="fas fa-right-to-bracket" style="color:#22c55e; margin-right:4px;"></i> Portal Accessed / Viewed</span>
                                                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                                                        Time: <?php echo date('d M Y h:i A', strtotime($log['created_at'])); ?> · IP: <?php echo r_esc($log['ip_address']); ?>
                                                    </div>
                                                    <?php if ($log['latitude']): ?>
                                                        <small style="display:block; font-size:0.7rem; color:var(--text-muted); margin-top:1px;">Coordinates: <?php echo r_esc($log['latitude']); ?>, <?php echo r_esc($log['longitude']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <?php if ($log['latitude']): ?>
                                                        <a href="https://www.google.com/maps?q=<?php echo urlencode($log['latitude'].','.$log['longitude']); ?>" target="_blank" class="btn btn-sm btn-outline" style="border-radius:6px; font-weight:700; font-size:0.75rem; padding:4px 8px; color:var(--accent); border-color:var(--accent);"><i class="fas fa-location-dot"></i> Map View</a>
                                                    <?php else: ?>
                                                        <span style="font-size:0.7rem; font-weight:700; color:var(--text-muted); background:var(--border); padding:4px 8px; border-radius:6px;">No Coords</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>
