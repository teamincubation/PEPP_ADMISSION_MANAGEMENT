<?php
require_once 'includes/auth.php';
require_permission('sessions');
require_once 'includes/session_mailer.php';

/* Sessions (Students).
   Schedule classes/webinars with a faculty, date/time, duration, type, link or
   venue, and one or more courses. Lists upcoming / ongoing / completed.
   Admins can manually push a learner reminder email per upcoming session.
   Automatic reminders (12h / 4h / 10m / start) are sent by sessions_cron via
   the same mailer when an admin loads any page (see includes/session_cron.php). */

$success_message = ''; $error_message = '';

function sessions_ready($pdo) {
    try { return (bool)$pdo->query("SHOW TABLES LIKE 'sessions'")->fetchColumn(); }
    catch (Exception $e) { return false; }
}
if (!sessions_ready($pdo)) {
    $active_page = 'sessions'; $page_title = 'Sessions'; $page_sub = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>The Sessions module is not installed yet. Run <strong>database-update-7.sql</strong> once in phpMyAdmin, then reload.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

$TYPES = ['live' => 'Live', 'qpd' => 'QPD', 'recorded' => 'Recorded', 'offline' => 'Offline'];
$DURATIONS = ['0.50','1.00','1.30','1.50','2.00','2.30','3.00'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_session' || $action === 'edit_session') {
                $topic = trim($_POST['topic'] ?? '');
                $dt = str_replace('T', ' ', trim($_POST['session_datetime'] ?? ''));
                $type = in_array($_POST['session_type'] ?? '', array_keys($TYPES), true) ? $_POST['session_type'] : 'live';
                $courses = array_filter(array_map('trim', (array)($_POST['courses'] ?? [])));
                $dur = (float)($_POST['duration_hours'] ?? 1);
                if ($topic === '' || !strtotime($dt)) {
                    $error_message = 'Topic and a valid date/time are required.';
                } else {
                    $vals = [
                        $topic, ((int)($_POST['faculty_id'] ?? 0)) ?: null, date('Y-m-d H:i:s', strtotime($dt)),
                        $dur, $type, trim($_POST['meet_link'] ?? '') ?: null, trim($_POST['venue'] ?? '') ?: null,
                        implode(',', $courses) ?: null,
                        in_array($_POST['status'] ?? '', ['scheduled', 'completed', 'cancelled'], true) ? $_POST['status'] : 'scheduled',
                    ];
                    if ($action === 'add_session') {
                        $isGoogle = ($type === 'live' && !empty($_POST['google_integrated']));

                        // Check if google_integrated column exists in sessions table
                        $hasGCol = false;
                        try {
                            $hasGCol = (bool)$pdo->query("SHOW COLUMNS FROM sessions LIKE 'google_integrated'")->fetchColumn();
                        } catch (Exception $e) {}

                        if ($hasGCol && $isGoogle) {
                            $stmt = $pdo->prepare("INSERT INTO sessions (topic, faculty_id, session_datetime, duration_hours, session_type, meet_link, venue, course_csv, google_integrated, google_integration_status, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,1,'pending',?,?,NOW())");
                            $stmt->execute(array_merge($vals, [$admin_username]));
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO sessions (topic, faculty_id, session_datetime, duration_hours, session_type, meet_link, venue, course_csv, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
                            $stmt->execute(array_merge($vals, [$admin_username]));
                        }
                        $session_id = $pdo->lastInsertId();
                        log_admin_activity($pdo, $admin_username, 'session_added', "Session: {$topic} @ {$dt}" . ($isGoogle ? " (Google Meet requested)" : ""));

                        if ($isGoogle) {
                            // Provision Google Workspace Calendar & Meet
                            require_once __DIR__ . '/includes/google/GoogleLiveSessionManager.php';
                            $googleMgr = new GoogleLiveSessionManager($pdo);
                            $gRes = $googleMgr->provisionGoogleLiveSession((int)$session_id);

                            if ($gRes['success']) {
                                $success_message = "Live session created with Google Meet. Google Calendar invitations sent to {$gRes['invited_count']} active student(s).";
                            } else {
                                $error_message = "Session created in ERP, but Google Meet provisioning encountered an issue: " . htmlspecialchars($gRes['error'] ?? 'Unknown error') . ". You can retry synchronization from the Actions column.";
                            }
                            // Google Calendar handles learner invitations; suppress duplicate ERP emails.
                        } else {
                            // Send automatic session scheduled email to enrolled learners asynchronously via queue
                            if (file_exists(__DIR__ . '/includes/session_mailer.php')) {
                                require_once __DIR__ . '/includes/session_mailer.php';
                                try {
                                    enqueue_session_scheduled_emails(
                                        $pdo,
                                        (int)$session_id,
                                        $courses,
                                        $topic,
                                        $dt,
                                        $type,
                                        trim($_POST['meet_link'] ?? ''),
                                        trim($_POST['venue'] ?? ''),
                                        (int)($_POST['faculty_id'] ?? 0),
                                        $admin_username
                                    );
                                } catch (Exception $mailEx) {
                                    error_log("Failed to enqueue session scheduled email notifications: " . $mailEx->getMessage());
                                }
                            }
                            $success_message = 'Session created.';
                        }
                    } else {
                        $sid = (int)($_POST['session_id'] ?? 0);
                        $stmt = $pdo->prepare("UPDATE sessions SET topic=?, faculty_id=?, session_datetime=?, duration_hours=?, session_type=?, meet_link=?, venue=?, course_csv=?, status=? WHERE id=?");
                        $stmt->execute(array_merge($vals, [$sid]));
                        log_admin_activity($pdo, $admin_username, 'session_updated', "Updated session #{$sid}");
                        $success_message = 'Session updated.';
                    }
                }
            } elseif ($action === 'retry_google_sync') {
                $sid = (int)($_POST['session_id'] ?? 0);
                require_once __DIR__ . '/includes/google/GoogleLiveSessionManager.php';
                $googleMgr = new GoogleLiveSessionManager($pdo);
                $gRes = $googleMgr->provisionGoogleLiveSession($sid);
                if ($gRes['success']) {
                    $success_message = "Google Meet synchronized successfully. Calendar invites active for {$gRes['invited_count']} student(s).";
                    log_admin_activity($pdo, $admin_username, 'session_google_synced', "Session #{$sid} synced with Google Meet");
                } else {
                    $error_message = "Google synchronization failed: " . htmlspecialchars($gRes['error'] ?? 'Unknown error');
                }
            } elseif ($action === 'sync_attendance') {
                $sid = (int)($_POST['session_id'] ?? 0);
                require_once __DIR__ . '/includes/google/GoogleAttendanceService.php';
                $attService = new GoogleAttendanceService($pdo);
                $aRes = $attService->syncSessionAttendance($sid);
                if ($aRes['success']) {
                    $success_message = "Attendance synchronized. {$aRes['participants_synced']} participant record(s) processed.";
                    log_admin_activity($pdo, $admin_username, 'session_attendance_synced', "Synced attendance for session #{$sid}");
                } else {
                    $error_message = "Attendance sync failed: " . htmlspecialchars($aRes['error'] ?? 'Unknown error');
                }
            } elseif ($action === 'sync_artifacts') {
                $sid = (int)($_POST['session_id'] ?? 0);
                require_once __DIR__ . '/includes/google/GoogleArtifactService.php';
                $artService = new GoogleArtifactService($pdo);
                $arRes = $artService->syncSessionArtifacts($sid);
                if ($arRes['success']) {
                    $success_message = "Artifacts synchronized. {$arRes['artifacts_synced']} recording/transcript reference(s) linked.";
                    log_admin_activity($pdo, $admin_username, 'session_artifacts_synced', "Synced artifacts for session #{$sid}");
                } else {
                    $error_message = "Artifact sync failed: " . htmlspecialchars($arRes['error'] ?? 'Unknown error');
                }
            } elseif ($action === 'mark_status') {
                $sid = (int)($_POST['session_id'] ?? 0);
                $st = in_array($_POST['status'] ?? '', ['scheduled', 'completed', 'cancelled'], true) ? $_POST['status'] : 'scheduled';
                $pdo->prepare("UPDATE sessions SET status = ? WHERE id = ?")->execute([$st, $sid]);
                log_admin_activity($pdo, $admin_username, 'session_status', "Session #{$sid} → {$st}");
                $success_message = 'Session status updated.';
            } elseif ($action === 'notify') {
                $sid = (int)($_POST['session_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT s.*, f.name AS faculty_name FROM sessions s LEFT JOIN faculties f ON f.id = s.faculty_id WHERE s.id = ?");
                $stmt->execute([$sid]); $sess = $stmt->fetch();
                if ($sess) {
                    $res = notify_session_learners($pdo, $sess, 'manual', $admin_username);
                    $success_message = "Notification sent to {$res} learner(s).";
                    log_admin_activity($pdo, $admin_username, 'session_notified', "Manually notified {$res} learner(s) for session #{$sid}");
                }
            } elseif ($action === 'delete_session') {
                if (!can_delete()) { $error_message = 'Only the Super Admin can delete a session.'; }
                else {
                    $sid = (int)($_POST['session_id'] ?? 0);
                    $pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$sid]);
                    $pdo->prepare("DELETE FROM session_notifications WHERE session_id = ?")->execute([$sid]);
                    log_admin_activity($pdo, $admin_username, 'session_deleted', "Deleted session #{$sid}");
                    $success_message = 'Session deleted.';
                }
            }
        } catch (Exception $e) {
            error_log('Sessions: ' . $e->getMessage());
            $error_message = 'Database error while saving the session.';
        }
    }
}

if (isset($_GET['ajax']) && !empty($_GET['session_id'])) {
    $ajaxAction = $_GET['ajax'];
    $sessId = (int)$_GET['session_id'];
    header('Content-Type: application/json; charset=utf-8');

    if ($ajaxAction === 'attendance') {
        try {
            $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'session_attendance'")->fetchColumn();
            if (!$hasTable) {
                echo json_encode(['success' => false, 'error' => 'Attendance table not installed.']);
                exit();
            }
            $stmt = $pdo->prepare("
                SELECT google_participant_name, first_join_time, last_leave_time, total_duration_seconds, attendance_status
                FROM session_attendance
                WHERE session_id = ?
                ORDER BY attendance_status ASC, total_duration_seconds DESC
            ");
            $stmt->execute([$sessId]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'records' => $records]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    } elseif ($ajaxAction === 'artifacts') {
        try {
            $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'session_google_artifacts'")->fetchColumn();
            if (!$hasTable) {
                echo json_encode(['success' => false, 'error' => 'Artifacts table not installed.']);
                exit();
            }
            $stmt = $pdo->prepare("
                SELECT artifact_type, google_resource_name, drive_file_id, artifact_state, artifact_url, updated_at
                FROM session_google_artifacts
                WHERE session_id = ?
                ORDER BY artifact_type ASC
            ");
            $stmt->execute([$sessId]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'records' => $records]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
}

/* ── Data ──────────────────────────────────────────────────────── */
$faculties = []; $courses = [];
try {
    $faculties = $pdo->query("SELECT id, name FROM faculties WHERE status='active' ORDER BY name")->fetchAll();
    $courses   = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$f_status = $_GET['status'] ?? '';
$now = date('Y-m-d H:i:s');
$where = ['1=1']; $params = [];
if ($f_status === 'upcoming')  { $where[] = "s.status='scheduled' AND s.session_datetime > ?"; $params[] = $now; }
elseif ($f_status === 'ongoing') { $where[] = "s.status='scheduled' AND s.session_datetime <= ? AND DATE_ADD(s.session_datetime, INTERVAL (s.duration_hours*60) MINUTE) >= ?"; $params[] = $now; $params[] = $now; }
elseif ($f_status === 'completed') { $where[] = "s.status='completed'"; }
elseif ($f_status === 'cancelled') { $where[] = "s.status='cancelled'"; }

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT s.*, f.name AS faculty_name FROM sessions s LEFT JOIN faculties f ON f.id = s.faculty_id WHERE " . implode(' AND ', $where) . " ORDER BY s.session_datetime DESC LIMIT 300");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Exception $e) { error_log('Sessions list: ' . $e->getMessage()); }

// Quick stats
$stats = ['upcoming' => 0, 'ongoing' => 0, 'completed' => 0];
try {
    $stats['upcoming'] = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE status='scheduled' AND session_datetime > '$now'")->fetchColumn();
    $stats['completed'] = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE status='completed'")->fetchColumn();
    $stats['ongoing'] = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE status='scheduled' AND session_datetime <= '$now' AND DATE_ADD(session_datetime, INTERVAL (duration_hours*60) MINUTE) >= '$now'")->fetchColumn();
} catch (Exception $e) {}

function session_state($s, $now) {
    if ($s['status'] !== 'scheduled') return $s['status'];
    $start = strtotime($s['session_datetime']);
    $end = $start + (float)$s['duration_hours'] * 3600;
    $t = strtotime($now);
    if ($t < $start) return 'upcoming';
    if ($t <= $end) return 'ongoing';
    return 'ended';
}

$active_page = 'sessions';
$page_title  = 'Sessions';
$page_sub    = 'Classes, webinars & learner notifications';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="stats-grid">
    <a href="?status=upcoming" class="stat-card" style="text-decoration:none;"><div class="stat-top"><span class="stat-label">Upcoming</span><span class="stat-icon violet"><i class="fas fa-calendar-day"></i></span></div><div class="stat-value"><?php echo $stats['upcoming']; ?></div><div class="stat-hint">Scheduled ahead</div></a>
    <a href="?status=ongoing" class="stat-card" style="text-decoration:none;"><div class="stat-top"><span class="stat-label">Ongoing</span><span class="stat-icon green"><i class="fas fa-circle-play"></i></span></div><div class="stat-value"><?php echo $stats['ongoing']; ?></div><div class="stat-hint">Happening now</div></a>
    <a href="?status=completed" class="stat-card" style="text-decoration:none;"><div class="stat-top"><span class="stat-label">Completed</span><span class="stat-icon green"><i class="fas fa-circle-check"></i></span></div><div class="stat-value"><?php echo $stats['completed']; ?></div><div class="stat-hint">Done</div></a>
    <div class="stat-card" style="justify-content:center; align-items:center; display:flex;"><button class="btn btn-primary" onclick="openSessModal()"><i class="fas fa-plus"></i> Add Session</button></div>
</div>

<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--accent-soft);color:var(--accent-dark);"><i class="fas fa-video"></i></span><h2>Sessions<?php echo $f_status ? ' - ' . ucfirst($f_status) : ''; ?> (<?php echo count($rows); ?>)</h2>
        <div class="head-right tabs">
            <a class="tab <?php echo $f_status === '' ? 'active' : ''; ?>" href="sessions.php">All</a>
            <a class="tab <?php echo $f_status === 'upcoming' ? 'active' : ''; ?>" href="?status=upcoming">Upcoming</a>
            <a class="tab <?php echo $f_status === 'ongoing' ? 'active' : ''; ?>" href="?status=ongoing">Ongoing</a>
            <a class="tab <?php echo $f_status === 'completed' ? 'active' : ''; ?>" href="?status=completed">Completed</a>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($rows)): ?>
            <div class="empty-state"><i class="fas fa-video"></i><p>No sessions<?php echo $f_status ? ' in this view' : ' yet'; ?>. Create one to get started.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Session</th><th>Faculty</th><th>When</th><th>Type</th><th>Courses</th><th>State</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $s): $state = session_state($s, $now); ?>
                <tr>
                    <td>
                        <div class="cell-main" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <span><?php echo e($s['topic']); ?></span>
                            <?php if (!empty($s['google_integrated'])): ?>
                                <span class="badge blue" style="font-size:0.7rem;padding:2px 7px;"><i class="fab fa-google"></i> Meet</span>
                                <?php if (($s['google_integration_status'] ?? '') === 'failed'): ?>
                                    <span class="badge red" style="font-size:0.7rem;padding:2px 7px;" title="<?php echo e($s['google_error_message'] ?? ''); ?>">Sync Failed</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="cell-sub">
                            <?php echo rtrim(rtrim(number_format((float)$s['duration_hours'],2),'0'),'.'); ?> hr
                            <?php if ($s['session_type'] === 'live' && $s['meet_link']): ?>
                                · <a href="<?php echo e($s['meet_link']); ?>" target="_blank" style="color:var(--primary);font-weight:600;"><i class="fas fa-video"></i> Join Meet</a>
                            <?php endif; ?>
                            <?php if (!empty($s['google_calendar_event_id'])): ?>
                                · <a href="https://calendar.google.com/calendar/u/0/r" target="_blank" style="color:#64748b;" title="Event ID: <?php echo e($s['google_calendar_event_id']); ?>"><i class="fas fa-calendar-alt"></i> Cal</a>
                            <?php endif; ?>
                            <?php if ($s['session_type'] === 'offline' && $s['venue']): ?>
                                · <?php echo e($s['venue']); ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($s['google_integrated']) && ($s['google_integration_status'] ?? '') === 'failed' && !empty($s['google_error_message'])): ?>
                            <div style="font-size:0.75rem;color:#ef4444;margin-top:2px;">
                                <i class="fas fa-triangle-exclamation"></i> <?php echo e(mb_strimwidth($s['google_error_message'], 0, 48, '...')); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="cell-sub"><?php echo e($s['faculty_name'] ?: '-'); ?></td>
                    <td class="cell-sub"><?php echo date('d M Y', strtotime($s['session_datetime'])); ?><br><?php echo date('h:i A', strtotime($s['session_datetime'])); ?></td>
                    <td><span class="badge gray"><?php echo $TYPES[$s['session_type']] ?? $s['session_type']; ?></span></td>
                    <td class="cell-sub" style="max-width:160px;"><?php echo e($s['course_csv'] ? mb_strimwidth($s['course_csv'], 0, 40, '…') : '-'); ?></td>
                    <td>
                        <?php
                        $stateBadge = ['upcoming'=>'violet','ongoing'=>'green','ended'=>'amber','completed'=>'green','cancelled'=>'red'];
                        ?>
                        <span class="badge <?php echo $stateBadge[$state] ?? 'gray'; ?>"><?php echo ucfirst($state); ?></span>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <?php if (!empty($s['google_integrated']) && ($s['google_integration_status'] ?? '') === 'failed'): ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="retry_google_sync"><input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-soft-amber" title="Retry Google Meet Provisioning"><i class="fas fa-rotate"></i></button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($s['google_integrated']) && ($s['google_integration_status'] ?? '') === 'synced'): ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="sync_attendance"><input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-soft-blue" title="Sync Attendance from Google Meet"><i class="fas fa-clipboard-user"></i></button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="sync_artifacts"><input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-soft-violet" title="Sync Meet Recordings & Notes"><i class="fas fa-cloud-arrow-down"></i></button>
                            </form>
                            <button type="button" class="btn btn-sm btn-soft-green" title="View Attendance & Artifacts" onclick="openSessionDetails(<?php echo (int)$s['id']; ?>, '<?php echo e(addslashes($s['topic'])); ?>')"><i class="fas fa-users-viewfinder"></i></button>
                        <?php endif; ?>
                        <?php if (empty($s['google_integrated']) && $s['status'] === 'scheduled' && $state !== 'ended' && in_array($s['session_type'], ['live','offline'], true)): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Send a reminder email to all learners of the selected course(s)?');">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="notify"><input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-soft-blue" title="Notify learners"><i class="fas fa-paper-plane"></i></button>
                        </form>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline" title="Edit" onclick='editSess(<?php echo json_encode([
                            "id"=>(int)$s["id"],"topic"=>$s["topic"],"faculty_id"=>(int)$s["faculty_id"],
                            "dt"=>date('Y-m-d\TH:i', strtotime($s["session_datetime"])),"dur"=>$s["duration_hours"],
                            "type"=>$s["session_type"],"meet"=>(string)$s["meet_link"],"venue"=>(string)$s["venue"],
                            "courses"=>$s["course_csv"] ? explode(',', $s["course_csv"]) : [],"status"=>$s["status"],
                            "google"=>(int)($s["google_integrated"] ?? 0),
                        ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
                        <?php if ($s['status'] !== 'completed'): ?>
                        <form method="POST" style="display:inline;">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_status"><input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="status" value="completed">
                            <button type="submit" class="btn btn-sm btn-soft-green" title="Mark completed"><i class="fas fa-check"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php if (can_delete()): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this session?');">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_session"><input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-soft-red" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info"><i class="fas fa-circle-info"></i><span>For <strong>Google-integrated Live Sessions</strong>, learners receive automated Google Calendar invitations with private guest lists, and automatic recording &amp; smart notes are configured. For standard sessions, automatic reminder emails dispatch according to the standard schedule.</span></div>

<!-- ADD/EDIT MODAL -->
<div class="modal-backdrop" id="sess-modal">
    <div class="modal" style="max-width:680px;">
        <div class="modal-head"><h3 id="sess-modal-title"><i class="fas fa-video" style="color:var(--accent);"></i> Add Session</h3><button class="modal-close" onclick="closeModal('sess-modal')"><i class="fas fa-xmark"></i></button></div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="sess-action" value="add_session">
            <input type="hidden" name="session_id" id="sess-id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field full"><label>Session Topic <span class="req">*</span></label><input type="text" name="topic" id="sess-topic" required></div>
                    <div class="field"><label>Faculty</label><select name="faculty_id" id="sess-faculty"><option value="">-</option><?php foreach ($faculties as $f): ?><option value="<?php echo (int)$f['id']; ?>"><?php echo e($f['name']); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Date &amp; Time <span class="req">*</span></label><input type="datetime-local" name="session_datetime" id="sess-dt" required></div>
                    <div class="field"><label>Duration (hours)</label>
                        <select name="duration_hours" id="sess-dur"><?php foreach ($DURATIONS as $d): ?><option value="<?php echo $d; ?>"><?php echo $d; ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Session Type</label>
                        <select name="session_type" id="sess-type" onchange="sessTypeToggle()"><?php foreach ($TYPES as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?></select></div>
                    <div class="field" id="sess-meet-wrap"><label>Meet Link (live / custom)</label><input type="url" name="meet_link" id="sess-meet" placeholder="https://meet.google.com/..."></div>
                    <div class="field" id="sess-venue-wrap" style="display:none;"><label>Venue (offline)</label><input type="text" name="venue" id="sess-venue"></div>
                    <div class="field"><label>Status</label><select name="status" id="sess-status"><option value="scheduled">Scheduled</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
                </div>

                <div class="field full" id="sess-google-wrap" style="margin-top:6px;">
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:0.86rem;background:var(--bg-hover,#f8fafc);border:1px solid var(--border);border-radius:10px;padding:10px 14px;">
                        <input type="checkbox" name="google_integrated" id="sess-google" value="1" checked style="accent-color:var(--accent);width:18px;height:18px;margin-top:2px;">
                        <div>
                            <strong style="color:var(--foreground,#0f172a);"><i class="fab fa-google" style="color:#4285F4;margin-right:4px;"></i> Schedule with Google Calendar &amp; Google Meet</strong>
                            <div style="color:var(--text-muted,#64748b);font-size:0.78rem;margin-top:2px;">Generates unique restricted Google Meet room, adds faculty as co-host, invites active students with private guest list, and configures auto-recording &amp; transcription.</div>
                        </div>
                    </label>
                </div>

                <div class="field full" style="margin-top:8px;"><label>Courses (select one or more)</label>
                    <div id="sess-courses" style="display:flex; flex-wrap:wrap; gap:7px; max-height:160px; overflow:auto; border:1px solid var(--border); border-radius:10px; padding:10px;">
                        <?php foreach ($courses as $c): ?>
                            <label style="display:inline-flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;background:var(--card);border-radius:50px;padding:6px 12px;cursor:pointer;">
                                <input type="checkbox" name="courses[]" value="<?php echo e($c); ?>" class="sess-course" style="accent-color:var(--accent);"> <?php echo e($c); ?>
                            </label>
                        <?php endforeach; ?>
                        <?php if (empty($courses)): ?><span class="cell-sub">No PEPP courses found.</span><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('sess-modal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Session</button></div>
        </form>
    </div>
</div>

<!-- DETAILS MODAL (Attendance & Artifacts) -->
<div class="modal-backdrop" id="sess-details-modal">
    <div class="modal" style="max-width:760px;">
        <div class="modal-head">
            <h3 id="sess-details-title"><i class="fas fa-users-viewfinder" style="color:var(--accent);"></i> Session Attendance &amp; Artifacts</h3>
            <button class="modal-close" onclick="closeModal('sess-details-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="padding:16px 24px;">
            <div class="tabs" style="margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:8px;">
                <button type="button" class="btn btn-sm btn-outline active" id="tab-btn-att" onclick="switchDetailTab('att')"><i class="fas fa-clipboard-user"></i> Attendance</button>
                <button type="button" class="btn btn-sm btn-outline" id="tab-btn-art" onclick="switchDetailTab('art')"><i class="fas fa-file-video"></i> Recordings &amp; Notes</button>
            </div>
            <div id="pane-detail-att">
                <div id="att-content" style="max-height:360px; overflow-y:auto;">
                    <div style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading attendance...</div>
                </div>
            </div>
            <div id="pane-detail-art" style="display:none;">
                <div id="art-content" style="max-height:360px; overflow-y:auto;">
                    <div style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading artifacts...</div>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" onclick="closeModal('sess-details-modal')">Close</button>
        </div>
    </div>
</div>

<?php
$extra_scripts = "<script>
function sessTypeToggle() {
    var t = document.getElementById('sess-type').value;
    var isLive = (t === 'live');
    document.getElementById('sess-meet-wrap').style.display   = isLive ? 'block' : 'none';
    document.getElementById('sess-google-wrap').style.display = isLive ? 'block' : 'none';
    document.getElementById('sess-venue-wrap').style.display  = (t === 'offline') ? 'block' : 'none';
    if (!isLive) {
        document.getElementById('sess-google').checked = false;
    }
}
function openSessModal() {
    document.getElementById('sess-action').value = 'add_session';
    document.getElementById('sess-modal-title').innerHTML = '<i class=\\\"fas fa-video\\\" style=\\\"color:var(--accent)\\\"></i> Add Session';
    document.getElementById('sess-id').value='';
    document.getElementById('sess-topic').value='';
    document.getElementById('sess-faculty').value='';
    document.getElementById('sess-dt').value='';
    document.getElementById('sess-dur').value='1.00';
    document.getElementById('sess-type').value='live';
    document.getElementById('sess-meet').value='';
    document.getElementById('sess-venue').value='';
    document.getElementById('sess-status').value='scheduled';
    document.getElementById('sess-google').checked = true;
    document.querySelectorAll('.sess-course').forEach(function(c){c.checked=false;});
    sessTypeToggle();
    openModal('sess-modal');
}
function editSess(s) {
    document.getElementById('sess-action').value = 'edit_session';
    document.getElementById('sess-modal-title').innerHTML = '<i class=\\\"fas fa-pen\\\" style=\\\"color:var(--accent)\\\"></i> Edit Session';
    document.getElementById('sess-id').value = s.id;
    document.getElementById('sess-topic').value = s.topic || '';
    document.getElementById('sess-faculty').value = s.faculty_id || '';
    document.getElementById('sess-dt').value = s.dt || '';
    document.getElementById('sess-dur').value = (parseFloat(s.dur).toFixed(2));
    document.getElementById('sess-type').value = s.type;
    document.getElementById('sess-meet').value = s.meet || '';
    document.getElementById('sess-venue').value = s.venue || '';
    document.getElementById('sess-status').value = s.status;
    document.getElementById('sess-google').checked = !!s.google;
    var set = {}; (s.courses||[]).forEach(function(c){set[c]=true;});
    document.querySelectorAll('.sess-course').forEach(function(c){c.checked=!!set[c.value];});
    sessTypeToggle();
    openModal('sess-modal');
}

function switchDetailTab(tab) {
    if (tab === 'att') {
        document.getElementById('pane-detail-att').style.display = 'block';
        document.getElementById('pane-detail-art').style.display = 'none';
        document.getElementById('tab-btn-att').classList.add('active');
        document.getElementById('tab-btn-art').classList.remove('active');
    } else {
        document.getElementById('pane-detail-att').style.display = 'none';
        document.getElementById('pane-detail-art').style.display = 'block';
        document.getElementById('tab-btn-att').classList.remove('active');
        document.getElementById('tab-btn-art').classList.add('active');
    }
}

function openSessionDetails(sessionId, topic) {
    document.getElementById('sess-details-title').innerHTML = '<i class=\"fas fa-users-viewfinder\" style=\"color:var(--accent);\"></i> ' + topic;
    switchDetailTab('att');
    openModal('sess-details-modal');

    // Fetch attendance
    var attEl = document.getElementById('att-content');
    attEl.innerHTML = '<div style=\"text-align:center; padding:24px; color:var(--text-muted);\"><i class=\"fas fa-spinner fa-spin\"></i> Loading attendance...</div>';
    fetch('sessions.php?ajax=attendance&session_id=' + sessionId)
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data.success || !data.records || data.records.length === 0) {
                attEl.innerHTML = '<div class=\"empty-state\" style=\"padding:20px;\"><p>No attendance records recorded yet. Click Sync Attendance after the meeting.</p></div>';
                return;
            }
            var h = '<table class=\"data-table\" style=\"font-size:0.85rem;\"><thead><tr><th>Participant</th><th>First Join</th><th>Last Leave</th><th>Duration</th><th>Status</th></tr></thead><tbody>';
            data.records.forEach(function(r){
                var mins = Math.round(parseInt(r.total_duration_seconds || 0) / 60);
                var stColor = {'full attendance':'green','partial attendance':'amber','joined':'blue','invited':'gray','absent':'red'}[r.attendance_status] || 'gray';
                h += '<tr>';
                h += '<td><strong>' + (r.google_participant_name || 'Participant') + '</strong></td>';
                h += '<td>' + (r.first_join_time ? r.first_join_time.substring(11,16) : '-') + '</td>';
                h += '<td>' + (r.last_leave_time ? r.last_leave_time.substring(11,16) : '-') + '</td>';
                h += '<td>' + mins + ' min</td>';
                h += '<td><span class=\"badge ' + stColor + '\">' + r.attendance_status + '</span></td>';
                h += '</tr>';
            });
            h += '</tbody></table>';
            attEl.innerHTML = h;
        })
        .catch(function(e){
            attEl.innerHTML = '<div class=\"alert alert-error\">Failed to load attendance.</div>';
        });

    // Fetch artifacts
    var artEl = document.getElementById('art-content');
    artEl.innerHTML = '<div style=\"text-align:center; padding:24px; color:var(--text-muted);\"><i class=\"fas fa-spinner fa-spin\"></i> Loading artifacts...</div>';
    fetch('sessions.php?ajax=artifacts&session_id=' + sessionId)
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data.success || !data.records || data.records.length === 0) {
                artEl.innerHTML = '<div class=\"empty-state\" style=\"padding:20px;\"><p>No recordings or transcripts linked yet. Click Sync Recordings & Notes after conference processing.</p></div>';
                return;
            }
            var h = '<div style=\"display:flex;flex-direction:column;gap:10px;\">';
            data.records.forEach(function(a){
                var icon = a.artifact_type === 'recording' ? 'fa-video' : (a.artifact_type === 'transcript' ? 'fa-file-lines' : 'fa-brain');
                var typeTitle = a.artifact_type.replace('_', ' ').toUpperCase();
                h += '<div style=\"display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:var(--bg-hover,#f8fafc);border:1px solid var(--border);border-radius:10px;\">';
                h += '<div style=\"display:flex;align-items:center;gap:10px;\"><i class=\"fas ' + icon + '\" style=\"font-size:1.2rem;color:var(--primary);\"></i><div><strong>' + typeTitle + '</strong><div style=\"font-size:0.75rem;color:var(--text-muted);\">Status: ' + a.artifact_state + '</div></div></div>';
                if (a.artifact_url) {
                    h += '<a href=\"' + a.artifact_url + '\" target=\"_blank\" class=\"btn btn-sm btn-primary\"><i class=\"fas fa-arrow-up-right-from-square\"></i> Open in Google Drive</a>';
                } else {
                    h += '<span class=\"badge gray\">Pending Drive export</span>';
                }
                h += '</div>';
            });
            h += '</div>';
            artEl.innerHTML = h;
        })
        .catch(function(e){
            artEl.innerHTML = '<div class=\"alert alert-error\">Failed to load artifacts.</div>';
        });
}
</script>";
include 'includes/admin_footer.php';
?>
