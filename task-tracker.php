<?php
require_once 'includes/auth.php';
require_permission('task-tracker');
require_once 'config/database.php';

if (!ld_tables_exist($pdo)) {
    $active_page = 'task-tracker';
    $page_title  = 'Intern Task Tracker';
    $page_sub    = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>Intern Task Tracker is not installed yet. Please run the required database migration (<strong>database-update-21.sql</strong>) before using this module.</span></div>';
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
$is_intern = (($_SESSION['admin_role'] ?? '') === 'intern' || ($me['role'] ?? '') === 'intern');


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
            $quantities = $_POST['quantities'] ?? [];

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

                // Fetch mode name and details
                $stmt = $pdo->prepare("SELECT mode_name, quantity_label, charge_per_quantity FROM ld_work_modes WHERE id = ? AND status = 'active'");
                $stmt->execute([$mode_id]);
                $mode_row = $stmt->fetch();
                $mode_name = $mode_row ? $mode_row['mode_name'] : '';
                $qty_label_snapshot = $mode_row ? $mode_row['quantity_label'] : null;
                $charge_snapshot = $mode_row ? (float)$mode_row['charge_per_quantity'] : 0.00;

                if (!$course_name || !$mode_name) {
                    $error_message = "Invalid Course or Work Mode selection.";
                } else {
                    // Check if quantity is required for this Work Mode configuration
                    $qty_required = ($qty_label_snapshot !== null && trim($qty_label_snapshot) !== '' && $mode_row['charge_per_quantity'] !== null);

                    try {
                        $validated_topics = [];
                        $has_at_least_one_valid_topic = false;
                        foreach ($topics as $idx => $topic) {
                            $topic_clean = trim($topic);
                            if ($topic_clean !== '') {
                                $has_at_least_one_valid_topic = true;
                                $raw_qty = isset($quantities[$idx]) ? trim($quantities[$idx]) : '';

                                $qty = null;
                                if ($raw_qty !== '') {
                                    $qty = filter_var($raw_qty, FILTER_VALIDATE_FLOAT);
                                }

                                if ($qty_required) {
                                    if ($qty === null || $qty === false || $qty <= 0) {
                                        throw new Exception("Please enter the quantity for every completed topic.");
                                    }
                                } else {
                                    if ($qty !== null && ($qty === false || $qty < 0)) {
                                        throw new Exception("Quantity for each topic must be a valid non-negative number.");
                                    }
                                }

                                $validated_topics[] = [
                                    'topic_name' => $topic_clean,
                                    'quantity' => $qty
                                ];
                            }
                        }

                        if (!$has_at_least_one_valid_topic) {
                            throw new Exception("Please select a Course, Work Mode, and provide at least one topic.");
                        }

                        $pdo->beginTransaction();

                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

                        $stmt = $pdo->prepare("
                            INSERT INTO ld_tasks (admin_id, admin_username, admin_name, admin_role, course_id, course_name, mode_id, mode_name, latitude, longitude, maps_url, ip_address, user_agent, status, created_at, quantity_label_snapshot, charge_per_quantity_snapshot, mode_name_snapshot)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), ?, ?, ?)
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
                            $ua,
                            $qty_label_snapshot,
                            $charge_snapshot,
                            $mode_name
                        ]);
                        $task_id = $pdo->lastInsertId();

                        // Insert topics
                        $stmt_topic = $pdo->prepare("INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge) VALUES (?, ?, ?, ?)");
                        $clean_topics = [];
                        foreach ($validated_topics as $v_topic) {
                            $qty = $v_topic['quantity'];
                            $calculated_charge = $qty !== null ? ($qty * $charge_snapshot) : 0.00;
                            $stmt_topic->execute([$task_id, $v_topic['topic_name'], $qty, $calculated_charge]);
                            $clean_topics[] = [
                                'topic_name' => $v_topic['topic_name'],
                                'quantity' => $qty,
                                'calculated_charge' => $calculated_charge
                            ];
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
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("Create L&D Task: " . $e->getMessage());
                        $error_message = $e->getMessage() ?: "Database error while creating task.";
                    }
                }
            }
        } elseif ($action === 'update_task') {
            $id = (int)($_POST['task_id'] ?? 0);
            $course_id = (int)($_POST['course_id'] ?? 0);
            $mode_id = (int)($_POST['mode_id'] ?? 0);
            $topics = $_POST['topics'] ?? [];
            $quantities = $_POST['quantities'] ?? [];

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
                } elseif (($lock_info = is_ld_task_locked($pdo, $task['admin_id'], date('Y-m-d', strtotime($task['created_at']))))) {
                    $error_message = "This task belongs to a completed payment period (" . date('d M Y', strtotime($lock_info['period_start_date'])) . " – " . date('d M Y', strtotime($lock_info['period_end_date'])) . ") and can no longer be edited.";
                } elseif (!is_super_admin() && $task['admin_username'] !== $admin_username) {
                    $error_message = "You are not authorized to update this task.";
                } elseif ($course_id <= 0 || $mode_id <= 0 || empty($topics)) {
                    $error_message = "Please select a Course, Work Mode, and provide at least one topic.";
                } else {
                    // Fetch course name
                    $stmt = $pdo->prepare("SELECT course_name FROM ld_work_courses WHERE id = ? AND status = 'active'");
                    $stmt->execute([$course_id]);
                    $course_name = $stmt->fetchColumn();

                    // Fetch mode details
                    $stmt = $pdo->prepare("SELECT mode_name, quantity_label, charge_per_quantity FROM ld_work_modes WHERE id = ? AND status = 'active'");
                    $stmt->execute([$mode_id]);
                    $mode_row = $stmt->fetch();
                    $mode_name = $mode_row ? $mode_row['mode_name'] : '';

                    if (!$course_name || !$mode_name) {
                        $error_message = "Invalid Course or Work Mode selection.";
                    } else {
                        // Determine snapshot rate and label
                        $rate = 0.00;
                        $qty_label = '';

                        if ($mode_id === (int)$task['mode_id']) {
                            // Mode is unchanged
                            if ($task['charge_per_quantity_snapshot'] !== null && $task['quantity_label_snapshot'] !== null) {
                                $rate = (float)$task['charge_per_quantity_snapshot'];
                                $qty_label = $task['quantity_label_snapshot'];
                            } else {
                                // Fallback to current configuration (legacy task)
                                $rate = $mode_row ? (float)$mode_row['charge_per_quantity'] : 0.00;
                                $qty_label = $mode_row ? $mode_row['quantity_label'] : null;
                            }
                        } else {
                            // Mode is changed: fetch new current configuration
                            $rate = $mode_row ? (float)$mode_row['charge_per_quantity'] : 0.00;
                            $qty_label = $mode_row ? $mode_row['quantity_label'] : null;
                        }

                        // Fetch old topics
                        $stmt = $pdo->prepare("SELECT * FROM ld_task_topics WHERE task_id = ?");
                        $stmt->execute([$id]);
                        $old_topics = $stmt->fetchAll();

                        $prev_data = [
                            'id' => $task['id'],
                            'course_name' => $task['course_name'],
                            'mode_name' => $task['mode_name'],
                            'topics' => array_map(function($tp) {
                                return ['topic_name' => $tp['topic_name'], 'quantity' => $tp['quantity'], 'calculated_charge' => $tp['calculated_charge']];
                            }, $old_topics)
                        ];

                        $pdo->beginTransaction();
                        try {
                            $stmt = $pdo->prepare("
                                UPDATE ld_tasks
                                SET course_id = ?, course_name = ?, mode_id = ?, mode_name = ?, updated_at = NOW(),
                                    quantity_label_snapshot = ?, charge_per_quantity_snapshot = ?, mode_name_snapshot = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$course_id, $course_name, $mode_id, $mode_name, $qty_label, $rate, $mode_name, $id]);

                            // Replace topics
                            $stmt = $pdo->prepare("DELETE FROM ld_task_topics WHERE task_id = ?");
                            $stmt->execute([$id]);

                            $stmt_topic = $pdo->prepare("INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge) VALUES (?, ?, ?, ?)");
                            $clean_topics = [];
                            foreach ($topics as $idx => $topic) {
                                $topic_clean = trim($topic);
                                if ($topic_clean !== '') {
                                    $qty = isset($quantities[$idx]) && $quantities[$idx] !== '' ? filter_var($quantities[$idx], FILTER_VALIDATE_FLOAT) : null;
                                    if ($qty !== null && ($qty === false || $qty < 0)) {
                                        throw new Exception("Quantity for each topic must be a valid non-negative number.");
                                    }
                                    $calculated_charge = $qty !== null ? ($qty * $rate) : 0.00;
                                    $stmt_topic->execute([$id, $topic_clean, $qty, $calculated_charge]);
                                    $clean_topics[] = [
                                        'topic_name' => $topic_clean,
                                        'quantity' => $qty,
                                        'calculated_charge' => $calculated_charge
                                    ];
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
                            $error_message = $e->getMessage() ?: "Database error while updating task.";
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
                } elseif (($lock_info = is_ld_task_locked($pdo, $task['admin_id'], date('Y-m-d', strtotime($task['created_at']))))) {
                    $error_message = "This task belongs to a completed payment period (" . date('d M Y', strtotime($lock_info['period_start_date'])) . " – " . date('d M Y', strtotime($lock_info['period_end_date'])) . ") and can no longer be deleted.";
                } elseif (!is_super_admin() && $task['admin_username'] !== $admin_username) {
                    $error_message = "You are not authorized to delete this task.";
                } else {
                    // Fetch topics
                    $stmt = $pdo->prepare("SELECT * FROM ld_task_topics WHERE task_id = ?");
                    $stmt->execute([$id]);
                    $old_topics = $stmt->fetchAll();

                    $prev_data = [
                        'id' => $task['id'],
                        'course_name' => $task['course_name'],
                        'mode_name' => $task['mode_name'],
                        'topics' => array_map(function($tp) {
                            return ['topic_name' => $tp['topic_name'], 'quantity' => $tp['quantity'], 'calculated_charge' => $tp['calculated_charge']];
                        }, $old_topics)
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
    $charge_field_sql = $is_intern ? "NULL AS charge_per_quantity_snapshot" : "t.charge_per_quantity_snapshot";
    $stmt = $pdo->prepare("
        SELECT t.id, t.admin_id, t.admin_username, t.admin_name, t.admin_role, t.course_id, t.course_name, t.mode_id, t.mode_name, t.latitude, t.longitude, t.maps_url, t.ip_address, t.user_agent, t.status, t.created_at, t.updated_at, t.quantity_label_snapshot, t.mode_name_snapshot, $charge_field_sql
        FROM ld_tasks t
        WHERE t.admin_username = ? AND t.status = 'active'
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$admin_username]);
    $my_tasks = $stmt->fetchAll();

    if (!empty($my_tasks)) {
        $task_ids = array_map(function($x) { return (int)$x['id']; }, $my_tasks);
        $in_clause = implode(',', $task_ids);
        $calc_charge_field_sql = $is_intern ? "NULL AS calculated_charge" : "calculated_charge";
        $topics_rows = $pdo->query("SELECT id, task_id, topic_name, quantity, $calc_charge_field_sql FROM ld_task_topics WHERE task_id IN ($in_clause) ORDER BY id ASC")->fetchAll();

        $topics_by_task = [];
        foreach ($topics_rows as $row) {
            $topics_by_task[$row['task_id']][] = $row;
        }

        foreach ($my_tasks as &$t) {
            $t['topics'] = $topics_by_task[$t['id']] ?? [];
        }
        unset($t);
    }
} catch (Exception $e) {
    error_log("My Tasks Load: " . $e->getMessage());
}

// Count L&D tasks with missing quantity details
$incomplete_qty_count = 0;
try {
    $stmt_check = $pdo->prepare("
        SELECT COUNT(DISTINCT t.id)
        FROM ld_tasks t
        JOIN ld_task_topics tp ON tp.task_id = t.id
        WHERE t.admin_username = ? AND t.status = 'active' AND tp.quantity IS NULL
    ");
    $stmt_check->execute([$admin_username]);
    $incomplete_qty_count = (int)$stmt_check->fetchColumn();
} catch (Exception $e) {
    error_log("Incomplete Qty count load: " . $e->getMessage());
}

$active_page = 'task-tracker';
$page_title  = 'Intern Task Tracker';
$page_sub    = 'Daily task logging and local work timeline';
include 'includes/admin_nav.php';
?>

<?php
$modes_json = [];
foreach ($active_modes as $m) {
    $modes_json[$m['id']] = [
        'name' => $m['mode_name'],
        'qty_label' => $m['quantity_label'] ?? '',
        'is_charging' => ($m['charge_per_quantity'] !== null),
        'charge_per_quantity' => !$is_intern && $m['charge_per_quantity'] !== null ? (float)$m['charge_per_quantity'] : null
    ];
}
?>
<script>
var workModesConfig = <?php echo json_encode($modes_json); ?>;
</script>

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
.timeline-card-details {
    display: none;
    margin-top: 10px;
    border-top: 1px dashed rgba(22, 78, 99, 0.15);
    padding-top: 10px;
}
.timeline-card-details.expanded {
    display: block;
}
.details-toggle-btn {
    background: none;
    border: none;
    color: var(--primary);
    font-weight: 600;
    font-size: 0.82rem;
    cursor: pointer;
    padding: 6px 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    outline: none;
}
.details-toggle-btn:hover {
    color: var(--primary-hover, #0e7490);
    text-decoration: underline;
}
</style>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message): ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<?php if ($incomplete_qty_count > 0): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-player/2.0.4/lottie-player.js"></script>
<div class="alert alert-info" id="motivation-box" style="background: linear-gradient(135deg, #f5f3ff, #ede9fe); border: 1px solid #ddd6fe; border-radius: 16px; padding: 20px; margin-bottom: 24px; position: relative; box-shadow: 0 4px 20px rgba(124, 58, 237, 0.05); display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
    <div style="background: #ffffff; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(124, 58, 237, 0.1); width: 56px; height: 56px; flex-shrink: 0; overflow: hidden; position: relative;">
        <!-- Lottie player loading local asset -->
        <lottie-player id="motivation-lottie" src="assets/img/tick.json" background="transparent" speed="1" style="width: 48px; height: 48px; position: relative; z-index: 2;" loop autoplay></lottie-player>
        <!-- Clean static icon fallback in case Lottie fails to load or render -->
        <i class="fas fa-award" id="motivation-fallback-icon" style="color:#7c3aed; font-size: 1.5rem; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 1;"></i>
    </div>
    <div style="flex: 1; min-width: 250px;">
        <h3 style="margin: 0 0 4px 0; font-size: 1.05rem; font-weight: 700; color: #5b21b6;">Make Your Work Count! ✨</h3>
        <p style="margin: 0; font-size: 0.88rem; color: #6d28d9; line-height: 1.4;">
            Some of your previously completed tasks are missing quantity details. Add them now so your work can be properly measured and included in your payment calculation.
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-shrink:0;">
        <button type="button" class="btn btn-primary" onclick="scrollToIncompleteTasks()" style="background:#7c3aed; border-color:#7c3aed; white-space:nowrap;">
            <i class="fas fa-pencil"></i> Update My Tasks
        </button>
        <button type="button" class="btn btn-outline" onclick="closeMotivationBox()" style="padding: 10px; border-color:#c084fc; color:#7c3aed;" title="Dismiss">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var player = document.getElementById("motivation-lottie");
    var fallback = document.getElementById("motivation-fallback-icon");
    if (player) {
        player.addEventListener("ready", function() {
            if (fallback) fallback.style.display = "none";
        });
        player.addEventListener("error", function() {
            if (fallback) fallback.style.display = "block";
            player.style.display = "none";
        });
    }
});
</script>

<script>
function scrollToIncompleteTasks() {
    var card = document.querySelector('.timeline-card.has-incomplete-qty');
    if (card) {
        card.scrollIntoView({ behavior: 'smooth' });
        var editBtn = card.querySelector('.btn-soft-amber');
        if (editBtn) {
            editBtn.focus();
            card.style.outline = '3px solid #7c3aed';
            setTimeout(function() {
                card.style.outline = 'none';
            }, 3000);
        }
    } else {
        var timeline = document.querySelector('.timeline-list');
        if (timeline) {
            timeline.scrollIntoView({ behavior: 'smooth' });
        }
    }
}
function closeMotivationBox() {
    var box = document.getElementById('motivation-box');
    if (box) {
        box.style.display = 'none';
        sessionStorage.setItem('dismiss_ld_motivation', '1');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    if (sessionStorage.getItem('dismiss_ld_motivation') === '1') {
        var box = document.getElementById('motivation-box');
        if (box) box.style.display = 'none';
    }
});
</script>
<?php endif; ?>

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
                            <div class="topic-input-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">
                                <input type="text" name="topics[]" class="topic-input" placeholder="e.g. Personality theories" required style="flex:2;">
                                <div class="qty-container" style="display:flex; align-items:center; gap:6px; flex:1;">
                                    <input type="number" step="any" name="quantities[]" class="qty-input" placeholder="Qty" style="width:100px;">
                                    <span class="qty-label" style="font-size:0.85rem; font-weight:600; color:var(--text-muted);">Qty</span>
                                </div>
                                <button type="button" class="btn btn-sm btn-soft-red" style="opacity:0; pointer-events:none; padding:10px 12px;"><i class="fas fa-trash"></i></button>
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

                        $has_incomplete = false;
                        $total_task_charge = 0.00;
                        foreach ($t['topics'] as $tp) {
                            if ($tp['quantity'] === null) {
                                $has_incomplete = true;
                            }
                            $total_task_charge += (float)$tp['calculated_charge'];
                        }
                        $mode_title = $t['mode_name_snapshot'] ?: $t['mode_name'];
                    ?>
                        <div class="timeline-card <?php echo $has_incomplete ? 'has-incomplete-qty' : ''; ?>" id="task-card-<?php echo (int)$t['id']; ?>" <?php echo $has_incomplete ? 'style="border: 1px dashed var(--warning);"' : ''; ?>>
                            <?php if ($has_incomplete): ?>
                                <div style="font-size:0.75rem; color:var(--warning-ink); background:var(--warning-soft); padding:4px 8px; border-radius:6px; margin-bottom:8px; display:inline-block; font-weight:600;">
                                    <i class="fas fa-circle-exclamation"></i> Missing Quantity data - Click edit to update
                                </div>
                            <?php endif; ?>

                            <div class="timeline-card-header">
                                <div>
                                    <h3 class="timeline-card-title"><?php echo e($mode_title); ?></h3>
                                    <div class="timeline-meta"><i class="fas fa-book"></i> Course: <?php echo e($t['course_name']); ?></div>
                                </div>
                                <div style="text-align:right;">
                                    <span class="badge blue"><?php echo count($t['topics']); ?> topics</span>
                                    <?php if (!$is_intern && !$has_incomplete && $t['charge_per_quantity_snapshot'] !== null): ?>
                                        <div style="font-size:0.8rem; font-weight:700; color:var(--success); margin-top:4px;">₹<?php echo number_format($total_task_charge, 2); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="timeline-meta" style="margin-top:10px; border-top:1px solid rgba(22, 78, 99, 0.05); padding-top:8px;">
                                <div><strong>Created:</strong> <?php echo date('d M Y, h:i A', strtotime($t['created_at'])); ?></div>
                            </div>

                            <button type="button" class="details-toggle-btn" id="toggle-btn-<?php echo (int)$t['id']; ?>" aria-expanded="false" onclick="toggleDetails(<?php echo (int)$t['id']; ?>)" aria-controls="details-<?php echo (int)$t['id']; ?>">
                                <i class="fas fa-chevron-down"></i> View Details
                            </button>

                            <div class="timeline-card-details" id="details-<?php echo (int)$t['id']; ?>" role="region" aria-labelledby="toggle-btn-<?php echo (int)$t['id']; ?>">
                                <div style="font-weight:600; font-size:0.8rem; color:var(--foreground); opacity:0.9; margin-bottom:6px;">Completed Topics</div>
                                <ul class="timeline-topics" style="margin-top:0; padding-left:15px;">
                                    <?php foreach ($t['topics'] as $tp): ?>
                                        <li>
                                            <?php echo e($tp['topic_name']); ?>
                                            <?php if ($tp['quantity'] !== null): ?>
                                                <span style="font-weight:600; color:var(--text-muted);">
                                                    (<?php echo (float)$tp['quantity']; ?> <?php echo e($t['quantity_label_snapshot'] ?? 'units'); ?><?php if (!$is_intern && $t['charge_per_quantity_snapshot'] !== null): ?> @ ₹<?php echo number_format((float)$t['charge_per_quantity_snapshot'], 2); ?>/unit = ₹<?php echo number_format((float)$tp['calculated_charge'], 2); ?><?php endif; ?>)
                                                </span>
                                            <?php else: ?>
                                                <span style="font-weight:600; color:var(--destructive);">
                                                    (Quantity not added)
                                                </span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <div class="timeline-actions">
                                <?php
                                $lock_info = is_ld_task_locked($pdo, $t['admin_id'], date('Y-m-d', strtotime($t['created_at'])));
                                if ($lock_info):
                                ?>
                                    <span style="font-weight:700; color:var(--text-muted); font-size:0.85rem; display:inline-flex; align-items:center; gap:6px;">
                                        <i class="fas fa-lock" style="color:var(--success-ink);"></i> Paid &amp; Locked
                                        <small style="font-weight:500;">(<?php echo date('d M Y', strtotime($lock_info['period_start_date'])) . ' – ' . date('d M Y', strtotime($lock_info['period_end_date'])); ?>)</small>
                                    </span>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-soft-amber" onclick='enterEditMode(<?php echo json_encode([
                                        "id" => (int)$t["id"],
                                        "course_id" => (int)$t["course_id"],
                                        "mode_id" => (int)$t["mode_id"],
                                        "topics" => array_map(function($tp) {
                                            return [
                                                "name" => $tp["topic_name"],
                                                "qty" => $tp["quantity"] !== null ? (float)$tp["quantity"] : ""
                                            ];
                                        }, $t["topics"])
                                    ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i> Edit</button>
                                    <button type="button" class="btn btn-sm btn-soft-red" onclick="openDeleteModal(<?php echo (int)$t['id']; ?>)"><i class="fas fa-trash"></i> Delete</button>
                                <?php endif; ?>
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
    var modeSelect = document.getElementById('form-mode');
    if (modeSelect) {
        modeSelect.addEventListener('change', function() {
            updateAllQtyLabels();
        });
        // Run immediately to set initial required attributes based on selected mode
        updateAllQtyLabels();
    }
});

function getSelectedModeQtyLabel() {
    var modeSelect = document.getElementById('form-mode');
    var modeId = modeSelect ? modeSelect.value : '';
    if (modeId && workModesConfig[modeId]) {
        return workModesConfig[modeId].qty_label || 'Qty';
    }
    return 'Qty';
}

function isQtyRequired() {
    var action = document.getElementById('form-action').value;
    if (action !== 'create_task') {
        return false;
    }
    var modeSelect = document.getElementById('form-mode');
    var modeId = modeSelect ? modeSelect.value : '';
    if (modeId && workModesConfig[modeId]) {
        var cfg = workModesConfig[modeId];
        return (cfg.qty_label !== undefined && cfg.qty_label !== null && cfg.qty_label.trim() !== '' && cfg.is_charging);
    }
    return false;
}

function updateAllQtyLabels() {
    var labelText = getSelectedModeQtyLabel();
    var required = isQtyRequired();

    var labels = document.querySelectorAll('.qty-label');
    labels.forEach(function(lbl) {
        lbl.textContent = labelText;
    });

    var inputs = document.querySelectorAll('.qty-input');
    inputs.forEach(function(inp) {
        inp.placeholder = labelText;
        if (required) {
            inp.required = true;
            inp.classList.add('qty-required');
        } else {
            inp.required = false;
            inp.classList.remove('qty-required');
        }
    });
}

// Dynamic Topics Inputs
function addTopicRow(val = '', qty = '') {
    var container = document.getElementById('topics-container');
    var row = document.createElement('div');
    row.className = 'topic-input-row';
    row.style.display = 'flex';
    row.style.gap = '10px';
    row.style.marginBottom = '8px';
    row.style.alignItems = 'center';

    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'topics[]';
    input.className = 'topic-input';
    input.placeholder = 'Next topic completed';
    input.value = val;
    input.required = true;
    input.style.flex = '2';

    var qtyContainer = document.createElement('div');
    qtyContainer.className = 'qty-container';
    qtyContainer.style.display = 'flex';
    qtyContainer.style.alignItems = 'center';
    qtyContainer.style.gap = '6px';
    qtyContainer.style.flex = '1';

    var qtyLabelText = getSelectedModeQtyLabel();

    var qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.step = 'any';
    qtyInput.name = 'quantities[]';
    qtyInput.className = 'qty-input';
    qtyInput.placeholder = qtyLabelText;
    qtyInput.value = qty;

    if (isQtyRequired()) {
        qtyInput.required = true;
        qtyInput.classList.add('qty-required');
    } else {
        qtyInput.required = false;
    }
    qtyInput.style.width = '100px';

    var qtyLabel = document.createElement('span');
    qtyLabel.className = 'qty-label';
    qtyLabel.style.fontSize = '0.85rem';
    qtyLabel.style.fontWeight = '600';
    qtyLabel.style.color = 'var(--text-muted)';
    qtyLabel.textContent = qtyLabelText;

    qtyContainer.appendChild(qtyInput);
    qtyContainer.appendChild(qtyLabel);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-soft-red';
    btn.innerHTML = '<i class="fas fa-trash"></i>';
    btn.style.padding = '10px 12px';
    btn.onclick = function() {
        row.remove();
    };

    row.appendChild(input);
    row.appendChild(qtyContainer);
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
            row.style.display = 'flex';
            row.style.gap = '10px';
            row.style.marginBottom = '8px';
            row.style.alignItems = 'center';

            var input = document.createElement('input');
            input.type = 'text';
            input.name = 'topics[]';
            input.className = 'topic-input';
            input.value = tp.name;
            input.required = true;
            input.style.flex = '2';

            var qtyContainer = document.createElement('div');
            qtyContainer.className = 'qty-container';
            qtyContainer.style.display = 'flex';
            qtyContainer.style.alignItems = 'center';
            qtyContainer.style.gap = '6px';
            qtyContainer.style.flex = '1';

            var qtyLabelText = getSelectedModeQtyLabel();

            var qtyInput = document.createElement('input');
            qtyInput.type = 'number';
            qtyInput.step = 'any';
            qtyInput.name = 'quantities[]';
            qtyInput.className = 'qty-input';
            qtyInput.placeholder = qtyLabelText;
            qtyInput.value = tp.qty;
            qtyInput.required = isQtyRequired();
            if (isQtyRequired()) {
                qtyInput.classList.add('qty-required');
            } else {
                qtyInput.classList.remove('qty-required');
            }
            qtyInput.style.width = '100px';

            var qtyLabel = document.createElement('span');
            qtyLabel.className = 'qty-label';
            qtyLabel.style.fontSize = '0.85rem';
            qtyLabel.style.fontWeight = '600';
            qtyLabel.style.color = 'var(--text-muted)';
            qtyLabel.textContent = qtyLabelText;

            qtyContainer.appendChild(qtyInput);
            qtyContainer.appendChild(qtyLabel);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-soft-red';
            btn.style.opacity = '0';
            btn.style.pointerEvents = 'none';
            btn.innerHTML = '<i class="fas fa-trash"></i>';
            btn.style.padding = '10px 12px';

            row.appendChild(input);
            row.appendChild(qtyContainer);
            row.appendChild(btn);
            container.appendChild(row);
        } else {
            addTopicRow(tp.name, tp.qty);
        }
    });

    updateAllQtyLabels();

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
    row.style.display = 'flex';
    row.style.gap = '10px';
    row.style.marginBottom = '8px';
    row.style.alignItems = 'center';

    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'topics[]';
    input.className = 'topic-input';
    input.placeholder = 'e.g. Personality theories';
    input.required = true;
    input.style.flex = '2';

    var qtyContainer = document.createElement('div');
    qtyContainer.className = 'qty-container';
    qtyContainer.style.display = 'flex';
    qtyContainer.style.alignItems = 'center';
    qtyContainer.style.gap = '6px';
    qtyContainer.style.flex = '1';

    var qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.step = 'any';
    qtyInput.name = 'quantities[]';
    qtyInput.className = 'qty-input';
    qtyInput.placeholder = 'Qty';
    qtyInput.required = isQtyRequired();
    if (isQtyRequired()) {
        qtyInput.classList.add('qty-required');
    } else {
        qtyInput.classList.remove('qty-required');
    }
    qtyInput.style.width = '100px';

    var qtyLabel = document.createElement('span');
    qtyLabel.className = 'qty-label';
    qtyLabel.style.fontSize = '0.85rem';
    qtyLabel.style.fontWeight = '600';
    qtyLabel.style.color = 'var(--text-muted)';
    qtyLabel.textContent = 'Qty';

    qtyContainer.appendChild(qtyInput);
    qtyContainer.appendChild(qtyLabel);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-soft-red';
    btn.style.opacity = '0';
    btn.style.pointerEvents = 'none';
    btn.innerHTML = '<i class="fas fa-trash"></i>';
    btn.style.padding = '10px 12px';

    row.appendChild(input);
    row.appendChild(qtyContainer);
    row.appendChild(btn);
    container.appendChild(row);

    document.getElementById('btn-cancel-edit').style.display = 'none';
    document.getElementById('btn-submit').innerHTML = '<i class="fas fa-floppy-disk"></i> Log Task';

    updateAllQtyLabels();
}

function validateAndSubmit(e) {
    e.preventDefault();

    // Reset previous validation highlights
    var inputs = document.querySelectorAll('.qty-input');
    inputs.forEach(function(inp) {
        inp.style.border = '';
    });

    if (isQtyRequired()) {
        var hasEmptyQty = false;
        inputs.forEach(function(inp) {
            var row = inp.closest('.topic-input-row');
            if (row) {
                var topicInput = row.querySelector('.topic-input');
                if (topicInput && topicInput.value.trim() !== '') {
                    var val = inp.value.trim();
                    var numVal = parseFloat(val);
                    if (val === '' || isNaN(numVal) || numVal <= 0) {
                        inp.style.border = '2px solid #ef4444'; // Clear red highlight
                        hasEmptyQty = true;
                    }
                }
            }
        });

        if (hasEmptyQty) {
            alert("Please enter the quantity for every completed topic.");
            return false;
        }
    }

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

function toggleDetails(taskId) {
    var detailsEl = document.getElementById('details-' + taskId);
    var btnEl = document.getElementById('toggle-btn-' + taskId);

    if (detailsEl.classList.contains('expanded')) {
        detailsEl.classList.remove('expanded');
        btnEl.setAttribute('aria-expanded', 'false');
        btnEl.innerHTML = '<i class="fas fa-chevron-down"></i> View Details';
    } else {
        detailsEl.classList.add('expanded');
        btnEl.setAttribute('aria-expanded', 'true');
        btnEl.innerHTML = '<i class="fas fa-chevron-up"></i> Hide Details';
    }
}
</script>

<?php include 'includes/admin_footer.php'; ?>
