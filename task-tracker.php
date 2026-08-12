<?php
require_once 'includes/auth.php';
require_permission('task-tracker');
require_once 'config/database.php';

if (!ld_tables_exist($pdo)) {
    $active_page = 'task-tracker';
    $page_title  = 'L&D Task Tracker';
    $page_sub    = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>L&D Task Tracker is not installed yet. Please run the required database migration (<strong>database-update-21.sql</strong>) before using this module.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

$success_message = '';
$error_message = '';

// Get logged-in user profile details
$stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
$stmt->execute([$admin_username]);
$me = $stmt->fetch();
if (!$me) {
    $me = [
        'id' => 0,
        'username' => $admin_username,
        'full_name' => $admin_username,
        'role' => $_SESSION['admin_role'] ?? 'admin'
    ];
}

// Audit logger helper
function log_ld_audit($pdo, $taskId, $adminId, $username, $action, $prev, $new, $lat, $lon, $mapsUrl) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $pdo->prepare("
        INSERT INTO ld_task_audit (task_id, admin_id, admin_username, action, previous_values, new_values, latitude, longitude, maps_url, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $taskId, 
        $adminId, 
        $username, 
        $action, 
        $prev ? json_encode($prev, JSON_UNESCAPED_UNICODE) : null, 
        $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null, 
        $lat, 
        $lon, 
        $mapsUrl, 
        $ip, 
        $ua
    ]);
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create_task') {
            $course_id = (int)($_POST['course_id'] ?? 0);
            $mode_id = (int)($_POST['mode_id'] ?? 0);
            $topics = $_POST['topics'] ?? [];
            
            // Check location coordinates
            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            
            // Validate location
            if ($lat === false || $lon === false || $lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                $error_message = "Location access is required to record this activity.";
            } elseif ($course_id <= 0 || $mode_id <= 0 || empty($topics)) {
                $error_message = "Please select a Course, Work Mode, and provide at least one topic.";
            } else {
                $mapsUrl = "https://www.google.com/maps?q=" . $lat . "," . $lon;
                
                // Fetch course name
                $stmt = $pdo->prepare("SELECT course_name FROM ld_work_courses WHERE id = ? AND status = 'active'");
                $stmt->execute([$course_id]);
                $course_name = $stmt->fetchColumn();
                
                // Fetch mode name
                $stmt = $pdo->prepare("SELECT mode_name FROM ld_work_modes WHERE id = ? AND status = 'active'");
                $stmt->execute([$mode_id]);
                $mode_name = $stmt->fetchColumn();
                
                if (!$course_name || !$mode_name) {
                    $error_message = "Invalid Course or Work Mode selection.";
                } else {
                    $pdo->beginTransaction();
                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO ld_tasks (admin_id, admin_username, admin_name, admin_role, course_id, course_name, mode_id, mode_name, latitude, longitude, maps_url, ip_address, user_agent, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                        ");
                        $stmt->execute([
                            $me['id'], 
                            $admin_username, 
                            $me['full_name'], 
                            $me['role'], 
                            $course_id, 
                            $course_name, 
                            $mode_id, 
                            $mode_name, 
                            $lat, 
                            $lon, 
                            $mapsUrl, 
                            $ip, 
                            $ua
                        ]);
                        $task_id = $pdo->lastInsertId();
                        
                        // Insert topics
                        $stmt_topic = $pdo->prepare("INSERT INTO ld_task_topics (task_id, topic_name) VALUES (?, ?)");
                        $clean_topics = [];
                        foreach ($topics as $topic) {
                            $topic_clean = trim($topic);
                            if ($topic_clean !== '') {
                                $stmt_topic->execute([$task_id, $topic_clean]);
                                $clean_topics[] = $topic_clean;
                            }
                        }
                        
                        // Log Audit CREATE
                        $new_data = [
                            'id' => $task_id,
                            'course_name' => $course_name,
                            'mode_name' => $mode_name,
                            'topics' => $clean_topics
                        ];
                        log_ld_audit($pdo, $task_id, $me['id'], $admin_username, 'CREATE', null, $new_data, $lat, $lon, $mapsUrl);
                        
                        $pdo->commit();
                        $success_message = "Task created successfully.";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        error_log("Create L&D Task: " . $e->getMessage());
                        $error_message = "Database error while creating task.";
                    }
                }
            }
        } elseif ($action === 'update_task') {
            $id = (int)($_POST['task_id'] ?? 0);
            $course_id = (int)($_POST['course_id'] ?? 0);
            $mode_id = (int)($_POST['mode_id'] ?? 0);
            $topics = $_POST['topics'] ?? [];
            
            // Check location coordinates
            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            
            if ($lat === false || $lon === false || $lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                $error_message = "Location access is required to record this activity.";
            } else {
                $mapsUrl = "https://www.google.com/maps?q=" . $lat . "," . $lon;
                
                $stmt = $pdo->prepare("SELECT * FROM ld_tasks WHERE id = ? AND status = 'active'");
                $stmt->execute([$id]);
                $task = $stmt->fetch();
                
                if (!$task) {
                    $error_message = "Task not found.";
                } elseif (!is_super_admin() && $task['admin_username'] !== $admin_username) {
                    $error_message = "You are not authorized to update this task.";
                } elseif ($course_id <= 0 || $mode_id <= 0 || empty($topics)) {
                    $error_message = "Please select a Course, Work Mode, and provide at least one topic.";
                } else {
                    // Fetch course name
                    $stmt = $pdo->prepare("SELECT course_name FROM ld_work_courses WHERE id = ? AND status = 'active'");
                    $stmt->execute([$course_id]);
                    $course_name = $stmt->fetchColumn();
                    
                    // Fetch mode name
                    $stmt = $pdo->prepare("SELECT mode_name FROM ld_work_modes WHERE id = ? AND status = 'active'");
                    $stmt->execute([$mode_id]);
                    $mode_name = $stmt->fetchColumn();
                    
                    if (!$course_name || !$mode_name) {
                        $error_message = "Invalid Course or Work Mode selection.";
                    } else {
                        // Fetch old topics
                        $stmt = $pdo->prepare("SELECT topic_name FROM ld_task_topics WHERE task_id = ?");
                        $stmt->execute([$id]);
                        $old_topics = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        $prev_data = [
                            'id' => $task['id'],
                            'course_name' => $task['course_name'],
                            'mode_name' => $task['mode_name'],
                            'topics' => $old_topics
                        ];
                        
                        $pdo->beginTransaction();
                        try {
                            $stmt = $pdo->prepare("
                                UPDATE ld_tasks 
                                SET course_id = ?, course_name = ?, mode_id = ?, mode_name = ?, updated_at = NOW() 
                                WHERE id = ?
                            ");
                            $stmt->execute([$course_id, $course_name, $mode_id, $mode_name, $id]);
                            
                            // Replace topics
                            $stmt = $pdo->prepare("DELETE FROM ld_task_topics WHERE task_id = ?");
                            $stmt->execute([$id]);
                            
                            $stmt_topic = $pdo->prepare("INSERT INTO ld_task_topics (task_id, topic_name) VALUES (?, ?)");
                            $clean_topics = [];
                            foreach ($topics as $topic) {
                                $topic_clean = trim($topic);
                                if ($topic_clean !== '') {
                                    $stmt_topic->execute([$id, $topic_clean]);
                                    $clean_topics[] = $topic_clean;
                                }
                            }
                            
                            $new_data = [
                                'id' => $id,
                                'course_name' => $course_name,
                                'mode_name' => $mode_name,
                                'topics' => $clean_topics
                            ];
                            
                            log_ld_audit($pdo, $id, $me['id'], $admin_username, 'UPDATE', $prev_data, $new_data, $lat, $lon, $mapsUrl);
                            
                            $pdo->commit();
                            $success_message = "Task updated successfully.";
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            error_log("Update L&D Task: " . $e->getMessage());
                            $error_message = "Database error while updating task.";
                        }
                    }
                }
            }
        } elseif ($action === 'delete_task') {
            $id = (int)($_POST['task_id'] ?? 0);
            $reason = trim($_POST['delete_reason'] ?? '');
            
            // Check location coordinates
            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            
            if ($lat === false || $lon === false || $lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                $error_message = "Location access is required to record this activity.";
            } else {
                $mapsUrl = "https://www.google.com/maps?q=" . $lat . "," . $lon;
                
                $stmt = $pdo->prepare("SELECT * FROM ld_tasks WHERE id = ? AND status = 'active'");
                $stmt->execute([$id]);
                $task = $stmt->fetch();
                
                if (!$task) {
                    $error_message = "Task not found.";
                } elseif (!is_super_admin() && $task['admin_username'] !== $admin_username) {
                    $error_message = "You are not authorized to delete this task.";
                } else {
                    // Fetch topics
                    $stmt = $pdo->prepare("SELECT topic_name FROM ld_task_topics WHERE task_id = ?");
                    $stmt->execute([$id]);
                    $topics = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $prev_data = [
                        'id' => $task['id'],
                        'course_name' => $task['course_name'],
                        'mode_name' => $task['mode_name'],
                        'topics' => $topics
                    ];
                    
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE ld_tasks 
                            SET status = 'deleted', 
                                deleted_at = NOW(), 
                                deleted_by = ?, 
                                deleted_reason = ?, 
                                deleted_latitude = ?, 
                                deleted_longitude = ?, 
                                deleted_maps_url = ? 
                            WHERE id = ?
                        ");
                        $stmt->execute([$admin_username, $reason, $lat, $lon, $mapsUrl, $id]);
                        
                        log_ld_audit($pdo, $id, $me['id'], $admin_username, 'DELETE', $prev_data, null, $lat, $lon, $mapsUrl);
                        
                        $pdo->commit();
                        $success_message = "Task deleted successfully.";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        error_log("Delete L&D Task: " . $e->getMessage());
                        $error_message = "Database error while deleting task.";
                    }
                }
            }
        }
    }
}

// Fetch Active Settings Lists for Dropdowns
$active_courses = $pdo->query("SELECT * FROM ld_work_courses WHERE status = 'active' ORDER BY sort_order ASC, course_name ASC")->fetchAll();
$active_modes = $pdo->query("SELECT * FROM ld_work_modes WHERE status = 'active' ORDER BY sort_order ASC, mode_name ASC")->fetchAll();

// Fetch Logged-in User's Active Tasks with Topics
$my_tasks = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, GROUP_CONCAT(tp.topic_name SEPARATOR '|||') AS topics_list
        FROM ld_tasks t
        LEFT JOIN ld_task_topics tp ON tp.task_id = t.id
        WHERE t.admin_username = ? AND t.status = 'active'
        GROUP BY t.id
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$admin_username]);
    $my_tasks = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("My Tasks Load: " . $e->getMessage());
}

$active_page = 'task-tracker';
$page_title  = 'L&D Task Tracker';
$page_sub    = 'Daily task logging and local work timeline';
include 'includes/admin_nav.php';
?>

<style>
/* Custom local styles to make the module look extremely premium and responsive */
.ld-layout {
    display: grid;
    grid-template-columns: 1.2fr 1.8fr;
    gap: 20px;
    align-items: start;
}
@media (max-width: 992px) {
    .ld-layout {
        grid-template-columns: 1fr;
    }
}
.topic-input-row {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
    align-items: center;
}
.topic-input-row input {
    flex: 1;
}
.timeline-list {
    position: relative;
    border-left: 2px solid var(--primary);
    padding-left: 20px;
    margin-left: 10px;
    margin-top: 15px;
}
.timeline-card {
    position: relative;
    background: var(--card);
    color: var(--card-foreground);
    padding: 16px;
    border-radius: 12px;
    border: 1px solid var(--border);
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(22, 78, 99, 0.05);
}
.timeline-card::before {
    content: '';
    position: absolute;
    left: -27px;
    top: 20px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--primary);
    border: 2px solid #ffffff;
}
.timeline-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}
.timeline-card-title {
    font-size: 0.95rem;
    font-weight: 600;
    margin: 0;
    color: var(--primary);
}
.timeline-meta {
    font-size: 0.78rem;
    color: var(--foreground);
    opacity: 0.85;
    margin-top: 4px;
}
.timeline-topics {
    margin-top: 10px;
    padding-left: 15px;
    list-style-type: disc;
    font-size: 0.85rem;
}
.timeline-topics li {
    margin-bottom: 4px;
}
.timeline-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 12px;
    border-top: 1px solid rgba(22, 78, 99, 0.1);
    padding-top: 10px;
}
.geo-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8rem;
    color: var(--success);
    font-weight: 600;
    background: rgba(16, 185, 129, 0.1);
    padding: 4px 10px;
    border-radius: 30px;
    margin-top: 6px;
}
.geo-indicator.denied {
    color: var(--destructive);
    background: rgba(220, 38, 38, 0.1);
}
</style>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message): ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="ld-layout">
    <!-- 1. Task Entry Form Panel -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon"><i class="fas fa-list-check"></i></span>
            <h2>Log L&D Activity</h2>
        </div>
        <div class="panel-body">
            <form id="task-form" method="POST" onsubmit="return validateAndSubmit(event)">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" id="form-action" value="create_task">
                <input type="hidden" name="task_id" id="form-task-id" value="">
                <input type="hidden" name="latitude" id="form-latitude" value="">
                <input type="hidden" name="longitude" id="form-longitude" value="">

                <div class="form-grid" style="grid-template-columns: 1fr;">
                    <div class="field">
                        <label>L&D Work Course <span class="req">*</span></label>
                        <select name="course_id" id="form-course" required>
                            <option value="">- Select Course -</option>
                            <?php foreach ($active_courses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['course_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Work Mode <span class="req">*</span></label>
                        <select name="mode_id" id="form-mode" required>
                            <option value="">- Select Work Mode -</option>
                            <?php foreach ($active_modes as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>"><?php echo e($m['mode_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Topics Completed <span class="req">*</span></label>
                        <div id="topics-container">
                            <div class="topic-input-row">
                                <input type="text" name="topics[]" class="topic-input" placeholder="e.g. Personality theories" required>
                                <button type="button" class="btn btn-sm btn-soft-red" style="opacity:0; pointer-events:none;"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline" style="margin-top:8px;" onclick="addTopicRow()"><i class="fas fa-plus"></i> Add Topic</button>
                    </div>
                </div>

                <div id="geo-status-box">
                    <span id="geo-text" class="geo-indicator"><i class="fas fa-location-dot"></i> Fetching location coordinates...</span>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px;">
                    <button type="button" id="btn-cancel-edit" class="btn btn-outline" style="display:none;" onclick="cancelEditMode()">Cancel Edit</button>
                    <button type="submit" id="btn-submit" class="btn btn-primary" style="margin-left:auto;"><i class="fas fa-floppy-disk"></i> Log Task</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. My Work Report timeline View -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-clock-rotate-left"></i></span>
            <h2>My Work Report</h2>
        </div>
        <div class="panel-body">
            <?php if (empty($my_tasks)): ?>
                <div class="empty-state" style="padding:40px;">
                    <i class="fas fa-folder-open"></i>
                    <p>You have not logged any tasks yet today.</p>
                </div>
            <?php else: ?>
                <div class="timeline-list">
                    <?php 
                    $current_date = '';
                    foreach ($my_tasks as $t): 
                        $task_date = date('d M Y', strtotime($t['created_at']));
                        if ($task_date !== $current_date) {
                            $current_date = $task_date;
                            echo "<div style='font-weight:700; font-size:0.9rem; color:var(--primary); margin-top:14px; margin-bottom:8px;'><i class='fas fa-calendar-day'></i> {$current_date}</div>";
                        }
                        $topics = explode('|||', $t['topics_list'] ?? '');
                    ?>
                        <div class="timeline-card" id="task-card-<?php echo (int)$t['id']; ?>">
                            <div class="timeline-card-header">
                                <div>
                                    <h3 class="timeline-card-title"><?php echo e($t['mode_name']); ?></h3>
                                    <div class="timeline-meta"><i class="fas fa-book"></i> Course: <?php echo e($t['course_name']); ?></div>
                                </div>
                                <span class="badge blue"><?php echo count($topics); ?> topics</span>
                            </div>

                            <ul class="timeline-topics">
                                <?php foreach ($topics as $tp): ?>
                                    <li><?php echo e($tp); ?></li>
                                <?php endforeach; ?>
                            </ul>

                            <div class="timeline-meta" style="margin-top:10px; border-top:1px solid rgba(22, 78, 99, 0.05); padding-top:8px;">
                                <div><strong>Created:</strong> <?php echo date('d M Y, h:i A', strtotime($t['created_at'])); ?></div>
                            </div>

                            <div class="timeline-actions">
                                <button type="button" class="btn btn-sm btn-soft-amber" onclick='enterEditMode(<?php echo json_encode([
                                    "id" => (int)$t["id"],
                                    "course_id" => (int)$t["course_id"],
                                    "mode_id" => (int)$t["mode_id"],
                                    "topics" => $topics
                                ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i> Edit</button>
                                <button type="button" class="btn btn-sm btn-soft-red" onclick="openDeleteModal(<?php echo (int)$t['id']; ?>)"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── DELETE REASON MODAL ── -->
<div class="modal-backdrop" id="delete-task-modal">
    <div class="modal" style="max-width:450px;">
        <div class="modal-head">
            <h3>Delete L&D Task Log</h3>
            <button class="modal-close" onclick="closeModal('delete-task-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" id="delete-form" onsubmit="return validateDelete(event)">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete_task">
            <input type="hidden" name="task_id" id="delete-task-id">
            <input type="hidden" name="latitude" id="delete-latitude">
            <input type="hidden" name="longitude" id="delete-longitude">
            <div class="modal-body">
                <div class="field">
                    <label>Reason for deletion <span class="req">*</span></label>
                    <textarea name="delete_reason" rows="3" placeholder="e.g. Duplicate logging / incorrect course selection" required></textarea>
                </div>
                <div id="delete-geo-box" style="margin-top:10px;">
                    <span id="delete-geo-text" class="geo-indicator"><i class="fas fa-location-dot"></i> Accessing location coordinates...</span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('delete-task-modal')">Cancel</button>
                <button type="submit" id="btn-confirm-delete" class="btn btn-danger">Confirm Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
// Geolocation cache
var clientLatitude = null;
var clientLongitude = null;
var geoErrorMsg = null;

function requestLocation(callback) {
    if (!navigator.geolocation) {
        geoErrorMsg = "Geolocation is not supported by your browser.";
        updateGeoUI();
        if (callback) callback(false);
        return;
    }

    navigator.geolocation.getCurrentPosition(function(pos) {
        clientLatitude = pos.coords.latitude;
        clientLongitude = pos.coords.longitude;
        geoErrorMsg = null;
        updateGeoUI();
        if (callback) callback(true);
    }, function(err) {
        // Fallback to low accuracy network-based location
        navigator.geolocation.getCurrentPosition(function(pos2) {
            clientLatitude = pos2.coords.latitude;
            clientLongitude = pos2.coords.longitude;
            geoErrorMsg = null;
            updateGeoUI();
            if (callback) callback(true);
        }, function(err2) {
            clientLatitude = null;
            clientLongitude = null;
            geoErrorMsg = "Location access is required to record this activity. Please allow site location permissions in your browser.";
            updateGeoUI();
            if (callback) callback(false);
        }, {
            enableHighAccuracy: false,
            timeout: 10000,
            maximumAge: 60000
        });
    }, {
        enableHighAccuracy: true,
        timeout: 5000,
        maximumAge: 0
    });
}

function updateGeoUI() {
    var textEl = document.getElementById('geo-text');
    var delTextEl = document.getElementById('delete-geo-text');
    var btnSubmit = document.getElementById('btn-submit');
    var btnDelete = document.getElementById('btn-confirm-delete');

    if (clientLatitude !== null && clientLongitude !== null) {
        var successHTML = '<i class="fas fa-circle-check"></i> Location captured successfully';
        textEl.className = 'geo-indicator';
        textEl.innerHTML = successHTML;
        if (delTextEl) {
            delTextEl.className = 'geo-indicator';
            delTextEl.innerHTML = successHTML;
        }
        btnSubmit.disabled = false;
        if (btnDelete) btnDelete.disabled = false;
    } else {
        var errHTML = '<i class="fas fa-triangle-exclamation"></i> ' + (geoErrorMsg || "Accessing location coordinates...");
        textEl.className = 'geo-indicator denied';
        textEl.innerHTML = errHTML;
        if (delTextEl) {
            delTextEl.className = 'geo-indicator denied';
            delTextEl.innerHTML = errHTML;
        }
        // Keep buttons disabled until location is confirmed
        btnSubmit.disabled = true;
        if (btnDelete) btnDelete.disabled = true;
    }
}

// Request location on load
document.addEventListener('DOMContentLoaded', function() {
    requestLocation();
});

// Dynamic Topics Inputs
function addTopicRow(val = '') {
    var container = document.getElementById('topics-container');
    var row = document.createElement('div');
    row.className = 'topic-input-row';
    
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'topics[]';
    input.className = 'topic-input';
    input.placeholder = 'Next topic completed';
    input.value = val;
    input.required = true;
    
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-soft-red';
    btn.innerHTML = '<i class="fas fa-trash"></i>';
    btn.onclick = function() {
        row.remove();
    };
    
    row.appendChild(input);
    row.appendChild(btn);
    container.appendChild(row);
}

function enterEditMode(data) {
    document.getElementById('form-action').value = 'update_task';
    document.getElementById('form-task-id').value = data.id;
    document.getElementById('form-course').value = data.course_id;
    document.getElementById('form-mode').value = data.mode_id;
    
    // Clear topics container
    var container = document.getElementById('topics-container');
    container.innerHTML = '';
    
    // Fill topics
    data.topics.forEach(function(tp, idx) {
        if (idx === 0) {
            var row = document.createElement('div');
            row.className = 'topic-input-row';
            
            var input = document.createElement('input');
            input.type = 'text';
            input.name = 'topics[]';
            input.className = 'topic-input';
            input.value = tp;
            input.required = true;
            
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-soft-red';
            btn.style.opacity = '0';
            btn.style.pointerEvents = 'none';
            btn.innerHTML = '<i class="fas fa-trash"></i>';
            
            row.appendChild(input);
            row.appendChild(btn);
            container.appendChild(row);
        } else {
            addTopicRow(tp);
        }
    });
    
    document.getElementById('btn-cancel-edit').style.display = 'inline-block';
    document.getElementById('btn-submit').innerHTML = '<i class="fas fa-floppy-disk"></i> Update Task';
    
    // Focus course
    document.getElementById('form-course').focus();
    
    // Scroll to form
    document.getElementById('task-form').scrollIntoView({ behavior: 'smooth' });
}

function cancelEditMode() {
    document.getElementById('form-action').value = 'create_task';
    document.getElementById('form-task-id').value = '';
    document.getElementById('form-course').value = '';
    document.getElementById('form-mode').value = '';
    
    var container = document.getElementById('topics-container');
    container.innerHTML = '';
    
    var row = document.createElement('div');
    row.className = 'topic-input-row';
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'topics[]';
    input.className = 'topic-input';
    input.placeholder = 'e.g. Personality theories';
    input.required = true;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-soft-red';
    btn.style.opacity = '0';
    btn.style.pointerEvents = 'none';
    btn.innerHTML = '<i class="fas fa-trash"></i>';
    
    row.appendChild(input);
    row.appendChild(btn);
    container.appendChild(row);
    
    document.getElementById('btn-cancel-edit').style.display = 'none';
    document.getElementById('btn-submit').innerHTML = '<i class="fas fa-floppy-disk"></i> Log Task';
}

function validateAndSubmit(e) {
    e.preventDefault();
    
    requestLocation(function(success) {
        if (!success) {
            alert("Location access is required to record this activity. Submission blocked.");
            return false;
        }
        
        document.getElementById('form-latitude').value = clientLatitude;
        document.getElementById('form-longitude').value = clientLongitude;
        
        document.getElementById('task-form').submit();
    });
}

function openDeleteModal(id) {
    document.getElementById('delete-task-id').value = id;
    openModal('delete-task-modal');
    requestLocation();
}

function validateDelete(e) {
    e.preventDefault();
    
    requestLocation(function(success) {
        if (!success) {
            alert("Location access is required to record this activity. Deletion blocked.");
            return false;
        }
        
        document.getElementById('delete-latitude').value = clientLatitude;
        document.getElementById('delete-longitude').value = clientLongitude;
        
        document.getElementById('delete-form').submit();
    });
}
</script>

<?php include 'includes/admin_footer.php'; ?>
