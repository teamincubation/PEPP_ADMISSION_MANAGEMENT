<?php
require_once 'includes/auth.php';
require_super_admin();

/* Centralized Admin Activity Log - Super Admin only.
   Merges three sources into one filterable timeline:
   • admin_activity_log     : logins / page views / heartbeats / admin events
   • track_records          : every action performed on a student
   • whatsapp_notifications : messages sent to students
   Each source is queried independently and merged, providing failure safety. */

$f_admin   = trim($_GET['admin'] ?? '');
$f_type    = trim($_GET['type'] ?? '');
$f_from    = trim($_GET['from'] ?? '');
$f_to      = trim($_GET['to'] ?? '');
$f_q       = trim($_GET['q'] ?? '');
$f_session = trim($_GET['session'] ?? '');
$f_module  = trim($_GET['module'] ?? '');
$f_page    = trim($_GET['page'] ?? '');
$f_idle    = isset($_GET['idle']) && $_GET['idle'] !== '' ? (int)$_GET['idle'] : '';

if ($f_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = '';
if ($f_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = '';

$success_message = $_SESSION['success_message'] ?? '';
$error_message   = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

function table_exists($pdo, $t) {
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn();
        }
        return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    } catch (Exception $e) { return false; }
}

/**
 * Returns the SQL WHERE condition for filtering admin_activity_log by activity type.
 * By default (empty filter or 'all_meaningful'), heartbeat telemetry records are excluded from the main audit timeline.
 */
function get_activity_type_where($f_type) {
    switch ($f_type) {
        case 'session':
        case 'auth':
            return "action_type IN ('login','logout','auto_logout','forced_logout','failed_login')";
        case 'page_view':
            return "action_type = 'page_view'";
        case 'staff_mgmt':
            return "(action_type IN ('staff_profile_update','staff_status_change','staff_admin_linked','staff_admin_unlinked','staff_created','staff_deleted') OR action_type LIKE 'staff_%')";
        case 'admin_mgmt':
            return "(action_type IN ('permissions_changed','admin_created','admin_deleted','admin_status_changed','password_reset','password_changed','admin_access_updated') OR action_type LIKE 'admin_%')";
        case 'security':
        case 'sensitive_data':
            return "(action_type IN ('sensitive_data_reveal','sensitive_data_copy','password_reset','password_changed','data_export','activity_cleared','activity_deleted') OR action_type LIKE '%sensitive%' OR action_type LIKE '%security%')";
        case 'student_action':
            return "(action_type IN ('student_approved','student_rejected','student_updated','student_reverted','student_deleted') OR action_type LIKE 'student_%')";
        case 'whatsapp':
            return "action_type = 'whatsapp_message'";
        case 'creates':
            return "(action_type LIKE '%create%' OR action_type LIKE '%add%' OR action_type IN ('admin_created','staff_admin_linked'))";
        case 'updates':
            return "(action_type LIKE '%update%' OR action_type LIKE '%edit%' OR action_type LIKE '%change%' OR action_type IN ('permissions_changed','staff_status_change'))";
        case 'deletes':
            return "(action_type LIKE '%delete%' OR action_type LIKE '%clear%' OR action_type IN ('admin_deleted','activity_deleted','activity_cleared','staff_admin_unlinked'))";
        case 'admin_event':
            return "(action_type NOT IN ('login','logout','auto_logout','forced_logout','failed_login','page_view','heartbeat') AND is_heartbeat = 0)";
        case 'heartbeat':
            return "(action_type = 'heartbeat' OR is_heartbeat = 1)";
        case 'all_activities':
            return null; // include all records without filtering
        case 'all_meaningful':
        case '':
        default:
            return "(action_type != 'heartbeat' AND is_heartbeat = 0)";
    }
}

/**
 * Introspect track_records column names dynamically to avoid fatal errors on mixed/legacy schemas.
 */
function get_track_record_info($pdo) {
    static $info = null;
    if ($info !== null) return $info;
    $cols = [];
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(track_records)");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols[strtolower($row['name'])] = true;
                }
            }
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM track_records");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols[strtolower($row['Field'])] = true;
                }
            }
        }
    } catch (Exception $e) {}
    $time_col = isset($cols['performed_at']) ? 'performed_at' : (isset($cols['created_at']) ? 'created_at' : null);
    $act_col = isset($cols['action_type']) ? 'action_type' : (isset($cols['action']) ? 'action' : 'action');
    $det_col = isset($cols['action_details']) ? 'action_details' : (isset($cols['details']) ? 'details' : 'details');
    $perf_col = isset($cols['performed_by']) ? 'performed_by' : (isset($cols['created_by']) ? 'created_by' : 'performed_by');
    $info = [
        'has_table' => !empty($cols) && $time_col !== null,
        'time_col' => $time_col,
        'act_col' => $act_col,
        'det_col' => $det_col,
        'perf_col' => $perf_col
    ];
    return $info;
}

/**
 * Introspect whatsapp_notifications column names dynamically.
 */
function get_whatsapp_notif_info($pdo) {
    static $info = null;
    if ($info !== null) return $info;
    $cols = [];
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(whatsapp_notifications)");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols[strtolower($row['name'])] = true;
                }
            }
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM whatsapp_notifications");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols[strtolower($row['Field'])] = true;
                }
            }
        }
    } catch (Exception $e) {}
    $time_col = isset($cols['created_at']) ? 'created_at' : (isset($cols['sent_at']) ? 'sent_at' : (isset($cols['timestamp']) ? 'timestamp' : null));
    $sender_col = isset($cols['sent_by']) ? 'sent_by' : (isset($cols['admin_username']) ? 'admin_username' : (isset($cols['created_by']) ? 'created_by' : 'sent_by'));
    $info = [
        'has_table' => !empty($cols) && $time_col !== null,
        'time_col' => $time_col,
        'sender_col' => $sender_col
    ];
    return $info;
}

/**
 * Collect activity rows from all three sources, merged & sorted in PHP.
 */
function collect_activity($pdo, $f_admin, $f_type, $f_from, $f_to, $f_q, $f_session, $f_module, $f_page, $f_idle, $limit = 5000) {
    $all = [];
    $like = $f_q !== '' ? '%' . $f_q . '%' : null;
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // 1. Query admin_activity_log
    if (table_exists($pdo, 'admin_activity_log')) {
        try {
            $w = ['1=1']; $p = [];

            $type_cond = get_activity_type_where($f_type);
            if ($type_cond !== null) {
                $w[] = $type_cond;
            }

            if ($f_admin !== '') { $w[] = "admin_username = ?"; $p[] = $f_admin; }
            if ($f_session !== '') { $w[] = "session_id = ?"; $p[] = $f_session; }
            if ($f_module !== '') { $w[] = "module = ?"; $p[] = $f_module; }
            if ($f_page !== '') { $w[] = "page LIKE ?"; $p[] = '%' . $f_page . '%'; }
            if ($f_idle !== '') { $w[] = "is_idle = ?"; $p[] = $f_idle; }

            if ($f_from !== '') { $w[] = "created_at >= ?"; $p[] = $f_from . ' 00:00:00'; }
            if ($f_to !== '') { $w[] = "created_at <= ?"; $p[] = $f_to . ' 23:59:59'; }

            if ($like) {
                $w[] = "(details LIKE ? OR action_type LIKE ?)";
                $p[] = $like; $p[] = $like;
            }

            $stmt = $pdo->prepare("SELECT id, created_at, admin_id, admin_username, session_id, action_type, module, page, section, target_type, target_id, details, request_method, request_uri, referrer, ip_address, location, user_agent, latitude, longitude, is_heartbeat, is_idle, metadata
                                   FROM admin_activity_log WHERE " . implode(' AND ', $w) . " ORDER BY created_at DESC LIMIT $limit");
            $stmt->execute($p);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $all[] = [
                    'source' => 'admin_activity_log',
                    'source_id' => (int)$r['id'],
                    'at_time' => $r['created_at'],
                    'admin_name' => $r['admin_username'],
                    'admin_id' => $r['admin_id'],
                    'session_id' => $r['session_id'],
                    'act' => $r['action_type'],
                    'module' => $r['module'] ?: 'Administration',
                    'page' => $r['page'],
                    'section' => $r['section'],
                    'target_type' => $r['target_type'],
                    'target_id' => $r['target_id'],
                    'details' => $r['details'],
                    'request_method' => $r['request_method'],
                    'request_uri' => $r['request_uri'],
                    'referrer' => $r['referrer'],
                    'ip' => $r['ip_address'],
                    'loc' => $r['location'],
                    'user_agent' => $r['user_agent'],
                    'student' => $r['target_type'] === 'student' ? $r['target_id'] : null,
                    'lat' => $r['latitude'] ?? null,
                    'lng' => $r['longitude'] ?? null,
                    'meta' => $r['metadata'] ?? null,
                    'is_heartbeat' => (int)$r['is_heartbeat'],
                    'is_idle' => (int)$r['is_idle']
                ];
            }
        } catch (Exception $e) { error_log('activity admin_log: ' . $e->getMessage()); }
    }

    // 2. Query track_records (student actions)
    $tr_info = get_track_record_info($pdo);
    $include_track = ($f_session === '' && $f_idle !== 1 && in_array($f_type, ['', 'all_meaningful', 'student_action', 'creates', 'updates', 'deletes', 'all_activities'], true) && ($f_module === '' || $f_module === 'Students'));
    if ($include_track && $tr_info['has_table']) {
        try {
            $t_col = $tr_info['time_col'];
            $a_col = $tr_info['act_col'];
            $d_col = $tr_info['det_col'];
            $p_col = $tr_info['perf_col'];

            $w = ['1=1']; $p = [];
            if ($f_admin !== '') { $w[] = "{$p_col} = ?"; $p[] = $f_admin; }
            if ($f_page !== '') { $w[] = "(user_id LIKE ? OR {$a_col} LIKE ?)"; $p[] = '%' . $f_page . '%'; $p[] = '%' . $f_page . '%'; }
            if ($f_from !== '') { $w[] = "{$t_col} >= ?"; $p[] = $f_from . ' 00:00:00'; }
            if ($f_to !== '') { $w[] = "{$t_col} <= ?"; $p[] = $f_to . ' 23:59:59'; }
            if ($like) {
                $w[] = "({$d_col} LIKE ? OR {$a_col} LIKE ? OR user_id LIKE ?)";
                $p[] = $like; $p[] = $like; $p[] = $like;
            }

            $stmt = $pdo->prepare("SELECT id, {$t_col} AS performed_at, {$p_col} AS performed_by, {$a_col} AS action_type, {$d_col} AS action_details, user_id
                                   FROM track_records WHERE " . implode(' AND ', $w) . " ORDER BY {$t_col} DESC LIMIT $limit");
            $stmt->execute($p);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $all[] = [
                    'source' => 'track_records',
                    'source_id' => (int)$r['id'],
                    'at_time' => $r['performed_at'],
                    'admin_name' => $r['performed_by'],
                    'admin_id' => null,
                    'session_id' => null,
                    'act' => $r['action_type'],
                    'module' => 'Students',
                    'page' => 'student-details.php',
                    'section' => 'Students',
                    'target_type' => 'student',
                    'target_id' => $r['user_id'],
                    'details' => $r['action_details'],
                    'request_method' => 'POST',
                    'request_uri' => null,
                    'referrer' => null,
                    'ip' => null,
                    'loc' => null,
                    'user_agent' => null,
                    'student' => $r['user_id'],
                    'lat' => null,
                    'lng' => null,
                    'meta' => null,
                    'is_heartbeat' => 0,
                    'is_idle' => 0
                ];
            }
        } catch (Exception $e) { error_log('activity track: ' . $e->getMessage()); }
    }

    // 3. Query whatsapp_notifications
    $wa_info = get_whatsapp_notif_info($pdo);
    $include_wa = ($f_session === '' && $f_idle !== 1 && in_array($f_type, ['', 'all_meaningful', 'whatsapp', 'all_activities'], true) && ($f_module === '' || $f_module === 'Communication'));
    if ($include_wa && $wa_info['has_table']) {
        try {
            $t_col = $wa_info['time_col'];
            $s_col = $wa_info['sender_col'];

            $w = ['1=1']; $p = [];
            if ($f_admin !== '') { $w[] = "{$s_col} = ?"; $p[] = $f_admin; }
            if ($f_from !== '') { $w[] = "{$t_col} >= ?"; $p[] = $f_from . ' 00:00:00'; }
            if ($f_to !== '') { $w[] = "{$t_col} <= ?"; $p[] = $f_to . ' 23:59:59'; }
            if ($like) {
                $w[] = "(message LIKE ? OR student_name LIKE ? OR phone LIKE ?)";
                $p[] = $like; $p[] = $like; $p[] = $like;
            }

            $stmt = $pdo->prepare("SELECT id, {$t_col} AS created_at, {$s_col} AS sent_by, student_name, phone, message
                                   FROM whatsapp_notifications WHERE " . implode(' AND ', $w) . " ORDER BY {$t_col} DESC LIMIT $limit");
            $stmt->execute($p);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $detail = 'To ' . ($r['student_name'] ?: $r['phone']) . ': ' . mb_substr((string)$r['message'], 0, 160);
                $all[] = [
                    'source' => 'whatsapp_notifications',
                    'source_id' => (int)$r['id'],
                    'at_time' => $r['created_at'],
                    'admin_name' => $r['sent_by'],
                    'admin_id' => null,
                    'session_id' => null,
                    'act' => 'whatsapp_message',
                    'module' => 'Communication',
                    'page' => 'whatsapp-notification.php',
                    'section' => 'Communication',
                    'target_type' => 'student',
                    'target_id' => null,
                    'details' => $detail,
                    'request_method' => 'POST',
                    'request_uri' => null,
                    'referrer' => null,
                    'ip' => null,
                    'loc' => null,
                    'user_agent' => null,
                    'student' => null,
                    'lat' => null,
                    'lng' => null,
                    'meta' => null,
                    'is_heartbeat' => 0,
                    'is_idle' => 0
                ];
            }
        } catch (Exception $e) { error_log('activity whatsapp: ' . $e->getMessage()); }
    }

    usort($all, function ($a, $b) { return strtotime($b['at_time']) <=> strtotime($a['at_time']); });
    return array_slice($all, 0, $limit);
}

/**
 * Identify matching sessions from all sources, returning their headers.
 */
function get_session_headers($pdo, $f_admin, $f_type, $f_from, $f_to, $f_q, $f_session, $f_module, $f_page, $f_idle) {
    $sessions = [];
    $like = $f_q !== '' ? '%' . $f_q . '%' : null;

    // 1. Query admin_activity_log
    if (table_exists($pdo, 'admin_activity_log')) {
        $w = ['1=1']; $p = [];
        $type_cond = get_activity_type_where($f_type);
        if ($type_cond !== null) {
            $w[] = $type_cond;
        }
        if ($f_admin !== '') { $w[] = "admin_username = ?"; $p[] = $f_admin; }
        if ($f_session !== '') { $w[] = "session_id = ?"; $p[] = $f_session; }
        if ($f_module !== '') { $w[] = "module = ?"; $p[] = $f_module; }
        if ($f_page !== '') { $w[] = "page LIKE ?"; $p[] = '%' . $f_page . '%'; }
        if ($f_idle !== '') { $w[] = "is_idle = ?"; $p[] = $f_idle; }
        if ($f_from !== '') { $w[] = "created_at >= ?"; $p[] = $f_from . ' 00:00:00'; }
        if ($f_to !== '') { $w[] = "created_at <= ?"; $p[] = $f_to . ' 23:59:59'; }
        if ($like) {
            $w[] = "(details LIKE ? OR action_type LIKE ?)";
            $p[] = $like; $p[] = $like;
        }

        $sql = "SELECT session_id, MAX(created_at) as max_time, admin_username, ip_address, location, COUNT(*) as act_count
                FROM admin_activity_log
                WHERE " . implode(' AND ', $w) . "
                GROUP BY session_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = $row['session_id'] ?: 'legacy_or_direct';
            if (!isset($sessions[$sid])) {
                $sessions[$sid] = [
                    'session_id' => $sid,
                    'admin_name' => $row['admin_username'] ?: 'Legacy',
                    'ip' => $row['ip_address'],
                    'loc' => $row['location'],
                    'max_time' => $row['max_time'],
                    'act_count' => (int)$row['act_count']
                ];
            } else {
                $sessions[$sid]['act_count'] += (int)$row['act_count'];
                if (strtotime($row['max_time']) > strtotime($sessions[$sid]['max_time'])) {
                    $sessions[$sid]['max_time'] = $row['max_time'];
                }
            }
        }
    }

    // 2. Query track_records
    $tr_info = get_track_record_info($pdo);
    $include_track = ($f_session === '' && $f_idle !== 1 && in_array($f_type, ['', 'all_meaningful', 'student_action', 'creates', 'updates', 'deletes', 'all_activities'], true) && ($f_module === '' || $f_module === 'Students'));
    if ($include_track && $tr_info['has_table']) {
        $t_col = $tr_info['time_col'];
        $a_col = $tr_info['act_col'];
        $d_col = $tr_info['det_col'];
        $p_col = $tr_info['perf_col'];

        $w = ['1=1']; $p = [];
        if ($f_admin !== '') { $w[] = "{$p_col} = ?"; $p[] = $f_admin; }
        if ($f_page !== '') { $w[] = "(user_id LIKE ? OR {$a_col} LIKE ?)"; $p[] = '%' . $f_page . '%'; $p[] = '%' . $f_page . '%'; }
        if ($f_from !== '') { $w[] = "{$t_col} >= ?"; $p[] = $f_from . ' 00:00:00'; }
        if ($f_to !== '') { $w[] = "{$t_col} <= ?"; $p[] = $f_to . ' 23:59:59'; }
        if ($like) {
            $w[] = "({$d_col} LIKE ? OR {$a_col} LIKE ? OR user_id LIKE ?)";
            $p[] = $like; $p[] = $like; $p[] = $like;
        }

        $sql = "SELECT MAX({$t_col}) as max_time, {$p_col} as performed_by, COUNT(*) as act_count
                FROM track_records
                WHERE " . implode(' AND ', $w);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['act_count'] > 0) {
            $sid = 'legacy_or_direct';
            if (!isset($sessions[$sid])) {
                $sessions[$sid] = [
                    'session_id' => $sid,
                    'admin_name' => $row['performed_by'] ?: 'Legacy',
                    'ip' => null,
                    'loc' => null,
                    'max_time' => $row['max_time'],
                    'act_count' => (int)$row['act_count']
                ];
            } else {
                $sessions[$sid]['act_count'] += (int)$row['act_count'];
                if (strtotime($row['max_time']) > strtotime($sessions[$sid]['max_time'])) {
                    $sessions[$sid]['max_time'] = $row['max_time'];
                }
            }
        }
    }

    // 3. Query whatsapp_notifications
    $wa_info = get_whatsapp_notif_info($pdo);
    $include_wa = ($f_session === '' && $f_idle !== 1 && in_array($f_type, ['', 'all_meaningful', 'whatsapp', 'all_activities'], true) && ($f_module === '' || $f_module === 'Communication'));
    if ($include_wa && $wa_info['has_table']) {
        $t_col = $wa_info['time_col'];
        $s_col = $wa_info['sender_col'];

        $w = ['1=1']; $p = [];
        if ($f_admin !== '') { $w[] = "{$s_col} = ?"; $p[] = $f_admin; }
        if ($f_from !== '') { $w[] = "{$t_col} >= ?"; $p[] = $f_from . ' 00:00:00'; }
        if ($f_to !== '') { $w[] = "{$t_col} <= ?"; $p[] = $f_to . ' 23:59:59'; }
        if ($like) {
            $w[] = "(message LIKE ? OR student_name LIKE ? OR phone LIKE ?)";
            $p[] = $like; $p[] = $like; $p[] = $like;
        }

        $sql = "SELECT MAX({$t_col}) as max_time, {$s_col} as sent_by, COUNT(*) as act_count
                FROM whatsapp_notifications
                WHERE " . implode(' AND ', $w);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['act_count'] > 0) {
            $sid = 'legacy_or_direct';
            if (!isset($sessions[$sid])) {
                $sessions[$sid] = [
                    'session_id' => $sid,
                    'admin_name' => $row['sent_by'] ?: 'Legacy',
                    'ip' => null,
                    'loc' => null,
                    'max_time' => $row['max_time'],
                    'act_count' => (int)$row['act_count']
                ];
            } else {
                $sessions[$sid]['act_count'] += (int)$row['act_count'];
                if (strtotime($row['max_time']) > strtotime($sessions[$sid]['max_time'])) {
                    $sessions[$sid]['max_time'] = $row['max_time'];
                }
            }
        }
    }

    uasort($sessions, function($a, $b) {
        return strtotime($b['max_time']) <=> strtotime($a['max_time']);
    });

    return $sessions;
}

/**
 * Fetch detailed activity rows only for the specified session IDs.
 */
function fetch_session_activities($pdo, $sids, $f_admin, $f_type, $f_from, $f_to, $f_q, $f_module, $f_page, $f_idle) {
    $activities = [];
    $like = $f_q !== '' ? '%' . $f_q . '%' : null;
    $has_legacy = in_array('legacy_or_direct', $sids, true);
    $active_sids = array_filter($sids, function($id) { return $id !== 'legacy_or_direct'; });

    // 1. From admin_activity_log
    if (table_exists($pdo, 'admin_activity_log')) {
        $w = ['1=1']; $p = [];
        $type_cond = get_activity_type_where($f_type);
        if ($type_cond !== null) {
            $w[] = $type_cond;
        }
        if ($f_admin !== '') { $w[] = "admin_username = ?"; $p[] = $f_admin; }
        if ($f_module !== '') { $w[] = "module = ?"; $p[] = $f_module; }
        if ($f_page !== '') { $w[] = "page LIKE ?"; $p[] = '%' . $f_page . '%'; }
        if ($f_idle !== '') { $w[] = "is_idle = ?"; $p[] = $f_idle; }
        if ($f_from !== '') { $w[] = "created_at >= ?"; $p[] = $f_from . ' 00:00:00'; }
        if ($f_to !== '') { $w[] = "created_at <= ?"; $p[] = $f_to . ' 23:59:59'; }
        if ($like) {
            $w[] = "(details LIKE ? OR action_type LIKE ?)";
            $p[] = $like; $p[] = $like;
        }

        $sid_w = [];
        if (!empty($active_sids)) {
            $ph = implode(',', array_fill(0, count($active_sids), '?'));
            $sid_w[] = "session_id IN ($ph)";
            foreach ($active_sids as $id) { $p[] = $id; }
        }
        if ($has_legacy) {
            $sid_w[] = "session_id IS NULL OR session_id = ''";
        }

        if (!empty($sid_w)) {
            $w[] = "(" . implode(' OR ', $sid_w) . ")";
            $stmt = $pdo->prepare("SELECT id, created_at, admin_id, admin_username, session_id, action_type, module, page, section, target_type, target_id, details, request_method, request_uri, referrer, ip_address, location, user_agent, latitude, longitude, is_heartbeat, is_idle, metadata
                                   FROM admin_activity_log WHERE " . implode(' AND ', $w) . " ORDER BY created_at DESC LIMIT 5000");
            $stmt->execute($p);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sid = $r['session_id'] ?: 'legacy_or_direct';
                $activities[$sid][] = [
                    'source' => 'admin_activity_log',
                    'source_id' => (int)$r['id'],
                    'at_time' => $r['created_at'],
                    'admin_name' => $r['admin_username'],
                    'admin_id' => $r['admin_id'],
                    'session_id' => $r['session_id'],
                    'act' => $r['action_type'],
                    'module' => $r['module'] ?: 'Administration',
                    'page' => $r['page'],
                    'section' => $r['section'],
                    'target_type' => $r['target_type'],
                    'target_id' => $r['target_id'],
                    'details' => $r['details'],
                    'request_method' => $r['request_method'],
                    'request_uri' => $r['request_uri'],
                    'referrer' => $r['referrer'],
                    'ip' => $r['ip_address'],
                    'loc' => $r['location'],
                    'user_agent' => $r['user_agent'],
                    'student' => $r['target_type'] === 'student' ? $r['target_id'] : null,
                    'lat' => $r['latitude'] ?? null,
                    'lng' => $r['longitude'] ?? null,
                    'meta' => $r['metadata'] ?? null,
                    'is_heartbeat' => (int)$r['is_heartbeat'],
                    'is_idle' => (int)$r['is_idle']
                ];
            }
        }
    }

    // 2. From track_records
    if ($has_legacy) {
        $tr_info = get_track_record_info($pdo);
        $include_track = ($f_idle !== 1 && in_array($f_type, ['', 'all_meaningful', 'student_action', 'creates', 'updates', 'deletes', 'all_activities'], true) && ($f_module === '' || $f_module === 'Students'));
        if ($include_track && $tr_info['has_table']) {
            $t_col = $tr_info['time_col'];
            $a_col = $tr_info['act_col'];
            $d_col = $tr_info['det_col'];
            $p_col = $tr_info['perf_col'];

            $w = ['1=1']; $p = [];
            if ($f_admin !== '') { $w[] = "{$p_col} = ?"; $p[] = $f_admin; }
            if ($f_page !== '') { $w[] = "(user_id LIKE ? OR {$a_col} LIKE ?)"; $p[] = '%' . $f_page . '%'; $p[] = '%' . $f_page . '%'; }
            if ($f_from !== '') { $w[] = "{$t_col} >= ?"; $p[] = $f_from . ' 00:00:00'; }
            if ($f_to !== '') { $w[] = "{$t_col} <= ?"; $p[] = $f_to . ' 23:59:59'; }
            if ($like) {
                $w[] = "({$d_col} LIKE ? OR {$a_col} LIKE ? OR user_id LIKE ?)";
                $p[] = $like; $p[] = $like; $p[] = $like;
            }

            $stmt = $pdo->prepare("SELECT id, {$t_col} AS performed_at, {$p_col} AS performed_by, {$a_col} AS action_type, {$d_col} AS action_details, user_id
                                   FROM track_records WHERE " . implode(' AND ', $w) . " ORDER BY {$t_col} DESC LIMIT 5000");
            $stmt->execute($p);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $activities['legacy_or_direct'][] = [
                    'source' => 'track_records',
                    'source_id' => (int)$r['id'],
                    'at_time' => $r['performed_at'],
                    'admin_name' => $r['performed_by'],
                    'admin_id' => null,
                    'session_id' => null,
                    'act' => $r['action_type'],
                    'module' => 'Students',
                    'page' => 'student-details.php',
                    'section' => 'Students',
                    'target_type' => 'student',
                    'target_id' => $r['user_id'],
                    'details' => $r['action_details'],
                    'request_method' => 'POST',
                    'request_uri' => null,
                    'referrer' => null,
                    'ip' => null,
                    'loc' => null,
                    'user_agent' => null,
                    'student' => $r['user_id'],
                    'lat' => null,
                    'lng' => null,
                    'meta' => null,
                    'is_heartbeat' => 0,
                    'is_idle' => 0
                ];
            }
        }

        // 3. From whatsapp_notifications
        $wa_info = get_whatsapp_notif_info($pdo);
        $include_wa = ($f_idle !== 1 && in_array($f_type, ['', 'all_meaningful', 'whatsapp', 'all_activities'], true) && ($f_module === '' || $f_module === 'Communication'));
        if ($include_wa && $wa_info['has_table']) {
            $t_col = $wa_info['time_col'];
            $s_col = $wa_info['sender_col'];

            $w = ['1=1']; $p = [];
            if ($f_admin !== '') { $w[] = "{$s_col} = ?"; $p[] = $f_admin; }
            if ($f_from !== '') { $w[] = "{$t_col} >= ?"; $p[] = $f_from . ' 00:00:00'; }
            if ($f_to !== '') { $w[] = "{$t_col} <= ?"; $p[] = $f_to . ' 23:59:59'; }
            if ($like) {
                $w[] = "(message LIKE ? OR student_name LIKE ? OR phone LIKE ?)";
                $p[] = $like; $p[] = $like; $p[] = $like;
            }

            $stmt = $pdo->prepare("SELECT id, {$t_col} AS created_at, {$s_col} AS sent_by, student_name, phone, message
                                   FROM whatsapp_notifications WHERE " . implode(' AND ', $w) . " ORDER BY {$t_col} DESC LIMIT 5000");
            $stmt->execute($p);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $detail = 'To ' . ($r['student_name'] ?: $r['phone']) . ': ' . mb_substr((string)$r['message'], 0, 160);
                $activities['legacy_or_direct'][] = [
                    'source' => 'whatsapp_notifications',
                    'source_id' => (int)$r['id'],
                    'at_time' => $r['created_at'],
                    'admin_name' => $r['sent_by'],
                    'admin_id' => null,
                    'session_id' => null,
                    'act' => 'whatsapp_message',
                    'module' => 'Communication',
                    'page' => 'whatsapp-notification.php',
                    'section' => 'Communication',
                    'target_type' => 'student',
                    'target_id' => null,
                    'details' => $detail,
                    'request_method' => 'POST',
                    'request_uri' => null,
                    'referrer' => null,
                    'ip' => null,
                    'loc' => null,
                    'user_agent' => null,
                    'student' => null,
                    'lat' => null,
                    'lng' => null,
                    'meta' => null,
                    'is_heartbeat' => 0,
                    'is_idle' => 0
                ];
            }
        }
    }

    foreach ($activities as $sid => &$list) {
        usort($list, function($a, $b) {
            return strtotime($b['at_time']) <=> strtotime($a['at_time']);
        });
    }

    return $activities;
}

/* ── POST: delete actions ───────────────────────────────────────── */
$valid_sources = ['admin_activity_log', 'track_records', 'whatsapp_notifications'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'delete_one') {
                $src = $_POST['source'] ?? '';
                $sid = (int)($_POST['source_id'] ?? 0);
                if (in_array($src, $valid_sources, true) && $sid > 0) {
                    $pdo->prepare("DELETE FROM `$src` WHERE id = ?")->execute([$sid]);
                    log_admin_activity($pdo, $admin_username, 'activity_deleted', "Deleted one activity record from {$src} (#{$sid})");
                    $success_message = 'Activity record deleted.';
                } else {
                    $error_message = 'Invalid record.';
                }
            } elseif ($action === 'clear_filtered') {
                $rows = collect_activity($pdo, $f_admin, $f_type, $f_from, $f_to, $f_q, $f_session, $f_module, $f_page, $f_idle, 100000);
                $byTable = ['admin_activity_log' => [], 'track_records' => [], 'whatsapp_notifications' => []];
                foreach ($rows as $r) { $byTable[$r['source']][] = (int)$r['source_id']; }
                $deleted = 0;
                foreach ($byTable as $tbl => $ids) {
                    if (empty($ids)) continue;
                    $ids = array_values(array_unique(array_filter($ids)));
                    foreach (array_chunk($ids, 500) as $chunk) {
                        $ph = implode(',', array_fill(0, count($chunk), '?'));
                        $stmt = $pdo->prepare("DELETE FROM `$tbl` WHERE id IN ($ph)");
                        $stmt->execute($chunk);
                        $deleted += $stmt->rowCount();
                    }
                }
                log_admin_activity($pdo, $admin_username, 'activity_cleared',
                    "Cleared {$deleted} activity record(s) matching filter" .
                    ($f_admin || $f_type || $f_from || $f_to || $f_q || $f_session || $f_module || $f_page || $f_idle !== '' ? '' : ' (ALL records)'));
                $success_message = "Deleted {$deleted} activity record(s).";
            }
        } catch (Exception $e) {
            error_log('Activity delete: ' . $e->getMessage());
            $error_message = 'Database error while deleting records.';
        }
    }
}

/* ── EXPORT (before any output) ─────────────────────────────────── */
if (isset($_GET['export'])) {
    $token = bin2hex(random_bytes(32));
    $exportDir = __DIR__ . '/config/activity_exports';
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }

    $filename = 'PEPP_Admin_Activity_Export_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
    $filePath = $exportDir . '/' . $filename;

    $out = fopen($filePath, 'w');
    if ($out) {
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date & Time', 'Admin', 'Action', 'Details', 'Student ID', 'IP Address', 'Location', 'Latitude', 'Longitude', 'Metadata'], ',', '"', "\\");

        $rows = collect_activity($pdo, $f_admin, $f_type, $f_from, $f_to, $f_q, $f_session, $f_module, $f_page, $f_idle, 20000);
        $totalRecords = 0;
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['at_time'],
                $r['admin_name'],
                $r['act'],
                $r['details'],
                $r['student'],
                $r['ip'],
                $r['loc'],
                $r['lat'] ?? '',
                $r['lng'] ?? '',
                $r['meta'] ?? ''
            ], ',', '"', "\\");
            $totalRecords++;
        }
        fclose($out);

        log_admin_activity($pdo, $admin_username, 'data_export', "Initiated secure export of admin activity ({$totalRecords} rows)");

        require_once __DIR__ . '/includes/SecureDownloadManager.php';
        $expiresAt = time() + 86400; // 24 hours
        SecureDownloadManager::registerToken($token, $filePath, $expiresAt);

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/admissions/admin-activity.php';
        $baseUrl = 'http://' . $host . dirname($scriptName);
        $downloadUrl = rtrim($baseUrl, '/') . '/activity-export-download.php?token=' . $token;

        $subject = 'PEPP ERP – Secure Activity Log Export – ' . date('d M Y');
        $html = '<h3>PEPP ERP Secure Activity Log Export</h3>';
        $html .= '<p>A secure export of the PEPP ERP Activity Log has been generated based on your requested filters.</p>';
        $html .= '<p><strong>Details:</strong></p>';
        $html .= '<ul>';
        $html .= '<li><strong>Requested By:</strong> ' . htmlspecialchars($admin_username) . '</li>';
        $html .= '<li><strong>Total Records:</strong> ' . $totalRecords . '</li>';
        $html .= '<li><strong>Generated At:</strong> ' . date('d M Y h:i A') . '</li>';
        $html .= '<li><strong>Expires In:</strong> 24 Hours</li>';
        $html .= '</ul>';
        $html .= '<p><a href="' . htmlspecialchars($downloadUrl) . '" style="display:inline-block; padding:10px 20px; background:#7c3aed; color:#fff; text-decoration:none; border-radius:6px; font-weight:bold;">Download CSV Export File</a></p>';
        $html .= '<p><small>If the button above does not work, copy and paste the following link into your browser:<br>' . htmlspecialchars($downloadUrl) . '</small></p>';

        require_once __DIR__ . '/includes/mail_queue.php';
        pepp_enqueue_mail('incubation.ngo@gmail.com', $subject, $html, '', [], 'noreply@pepplearning.in', 'PEPP Learning', 10, 'activity_log_export', $admin_username);
        pepp_enqueue_mail('office@pepplearning.com', $subject, $html, '', [], 'noreply@pepplearning.in', 'PEPP Learning', 10, 'activity_log_export', $admin_username);

        $_SESSION['success_message'] = 'Export generated and queued for delivery to the configured administrators.';
    } else {
        $_SESSION['error_message'] = 'Failed to generate export file server-side.';
    }

    $redirectUrl = aqs(['export' => null]);
    header("Location: admin-activity.php" . $redirectUrl);
    exit();
}

/* ── ONLINE METRICS & COMPATIBILITY CHECKS ──────────────────────── */
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$active_now = 0;
$online_admins = 0;
$today_activities = 0;
$active_time_mins = 0;

try {
    $time_cond = $driver === 'sqlite' ? "last_seen >= datetime('now', '-5 minutes', 'localtime')" : "last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
    $cur_date_cond = $driver === 'sqlite' ? "strftime('%Y-%m-%d', created_at) = date('now', 'localtime')" : "created_at >= CURDATE()";
    $cur_date_track = $driver === 'sqlite' ? "strftime('%Y-%m-%d', performed_at) = date('now', 'localtime')" : "performed_at >= CURDATE()";

    if (table_exists($pdo, 'admin_presence')) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM admin_presence WHERE $time_cond AND is_idle = 0");
        $active_now = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM admin_presence WHERE $time_cond");
        $online_admins = (int)$stmt->fetchColumn();
    }

    if (table_exists($pdo, 'admin_activity_log')) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE $cur_date_cond AND (action_type != 'heartbeat' AND is_heartbeat = 0)");
        $today_activities = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE is_heartbeat = 1 AND is_idle = 0 AND $cur_date_cond");
        $active_time_mins = (int)$stmt->fetchColumn();
    }

    if (table_exists($pdo, 'track_records')) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM track_records WHERE $cur_date_track");
        $today_activities += (int)$stmt->fetchColumn();
    }

    if (table_exists($pdo, 'whatsapp_notifications')) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM whatsapp_notifications WHERE $cur_date_cond");
        $today_activities += (int)$stmt->fetchColumn();
    }
} catch (Exception $e) { error_log("Failed to calculate metrics: " . $e->getMessage()); }

if ($active_time_mins < 60) {
    $active_time_display = $active_time_mins . ' mins';
} else {
    $hrs = floor($active_time_mins / 60);
    $mins = $active_time_mins % 60;
    $active_time_display = $hrs . 'h ' . $mins . 'm';
}

$online_list = [];
try {
    if (table_exists($pdo, 'admin_presence')) {
        $stmt_on = $pdo->query("SELECT * FROM admin_presence WHERE $time_cond ORDER BY last_seen DESC");
        while ($r = $stmt_on->fetch(PDO::FETCH_ASSOC)) {
            $duration_secs = time() - strtotime($r['login_time']);
            if ($duration_secs < 60) {
                $dur = 'Just logged in';
            } elseif ($duration_secs < 3600) {
                $dur = round($duration_secs / 60) . ' mins';
            } else {
                $hrs = floor($duration_secs / 3600);
                $mins = round(($duration_secs % 3600) / 60);
                $dur = $hrs . ' hr ' . $mins . ' mins';
            }
            $r['active_duration'] = $dur;
            $online_list[] = $r;
        }
    }
} catch (Exception $e) { error_log('Online query error: ' . $e->getMessage()); }

/* ── TIMELINE DATA GENERATION (OPTIMIZED PERFORMANCE) ───────────────── */
$all_session_headers = get_session_headers($pdo, $f_admin, $f_type, $f_from, $f_to, $f_q, $f_session, $f_module, $f_page, $f_idle);
$total_sessions = count($all_session_headers);

// Calculate total matched activities
$total = 0;
foreach ($all_session_headers as $h) {
    $total += $h['act_count'];
}

// Paginate sessions (10 sessions per page)
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$total_pages = max(1, (int)ceil($total_sessions / $per_page));
$page     = min($page, $total_pages);
$paged_headers = array_slice($all_session_headers, ($page - 1) * $per_page, $per_page, true);

// Fetch activities only for the paged sessions
$paged_sids = array_keys($paged_headers);
$session_activities = !empty($paged_sids)
    ? fetch_session_activities($pdo, $paged_sids, $f_admin, $f_type, $f_from, $f_to, $f_q, $f_module, $f_page, $f_idle)
    : [];

$session_groups = [];
foreach ($paged_headers as $sid => $header) {
    $session_groups[$sid] = [
        'session_id' => $sid,
        'admin_name' => $header['admin_name'],
        'ip' => $header['ip'],
        'loc' => $header['loc'],
        'activities' => $session_activities[$sid] ?? []
    ];
}

$admin_list = [];
try {
    if (table_exists($pdo, 'admins')) {
        $admin_list = $pdo->query("SELECT DISTINCT username FROM admins WHERE username IS NOT NULL AND username != '' ORDER BY username ASC")->fetchAll(PDO::FETCH_COLUMN);
    }
    if (empty($admin_list) && table_exists($pdo, 'admin_activity_log')) {
        $admin_list = $pdo->query("SELECT DISTINCT admin_username FROM admin_activity_log WHERE admin_username IS NOT NULL AND admin_username != '' ORDER BY admin_username ASC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) { error_log('activity admin list: ' . $e->getMessage()); }

$has_filter = ($f_admin || ($f_type !== '' && $f_type !== 'all_meaningful') || $f_from || $f_to || $f_q || $f_session || $f_module || $f_page || $f_idle !== '');

function aqs($overrides = []) {
    $q = array_merge($_GET, $overrides);
    unset($q['logout'], $q['export']);
    return '?' . http_build_query($q);
}

function act_badge($act) {
    if ($act === 'login') return ['green', 'fa-right-to-bracket', 'Login'];
    if ($act === 'logout') return ['gray', 'fa-right-from-bracket', 'Logout'];
    if (in_array($act, ['auto_logout', 'forced_logout'], true)) return ['amber', 'fa-clock', $act === 'auto_logout' ? 'Auto-logout' : 'Forced logout'];
    if ($act === 'failed_login') return ['red', 'fa-triangle-exclamation', 'Failed Login'];
    if ($act === 'whatsapp_message') return ['teal', 'fa-comment', 'WhatsApp'];
    if ($act === 'page_view') return ['blue', 'fa-eye', 'Page View'];
    if ($act === 'heartbeat') return ['sky', 'fa-pulse fa-heartbeat', 'Heartbeat'];
    if (in_array($act, ['sensitive_data_reveal', 'sensitive_data_copy'], true)) return ['amber', 'fa-shield-halved', ucwords(str_replace('_', ' ', $act))];
    if (strpos($act, 'staff_') === 0) return ['indigo', 'fa-id-badge', ucwords(str_replace('_', ' ', $act))];
    if (strpos($act, 'student_') === 0) return ['emerald', 'fa-user-graduate', ucwords(str_replace('_', ' ', $act))];
    if (strpos($act, 'admin_') === 0 || strpos($act, 'lead_') === 0 || strpos($act, 'invoice') === 0 ||
        in_array($act, ['permissions_changed', 'password_reset', 'password_changed', 'data_export', 'activity_deleted', 'activity_cleared', 'student_reverted'], true))
        return ['violet', 'fa-user-shield', ucwords(str_replace('_', ' ', $act))];
    return ['blue', 'fa-pen', ucwords(str_replace('_', ' ', $act))];
}

$active_page = 'admin-activity';
$page_title  = 'Activity Log';
$page_sub    = 'Reconstruct admin timeline history, session activity metrics and heartbeats';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<!-- Real-time metrics overview -->
<div class="row" style="display:flex; gap:16px; margin-bottom:24px; flex-wrap:wrap;">
    <div class="card" style="flex:1; min-width:200px; padding:18px; border-left:4px solid #10b981; background:var(--surface);">
        <div style="font-size:0.8rem; color:var(--muted-foreground); font-weight:600; text-transform:uppercase;">Active Admins Now</div>
        <div style="font-size:1.8rem; font-weight:700; margin-top:8px; display:flex; align-items:center; gap:8px;">
            <span style="width:12px; height:12px; border-radius:50%; background:#10b981; display:inline-block; animation: pulse 1.5s infinite;"></span>
            <?php echo $active_now; ?>
        </div>
    </div>
    <div class="card" style="flex:1; min-width:200px; padding:18px; border-left:4px solid #8b5cf6; background:var(--surface);">
        <div style="font-size:0.8rem; color:var(--muted-foreground); font-weight:600; text-transform:uppercase;">Total Online (5m)</div>
        <div style="font-size:1.8rem; font-weight:700; margin-top:8px;"><?php echo $online_admins; ?></div>
    </div>
    <div class="card" style="flex:1; min-width:200px; padding:18px; border-left:4px solid #3b82f6; background:var(--surface);">
        <div style="font-size:0.8rem; color:var(--muted-foreground); font-weight:600; text-transform:uppercase;">Today's Logged Actions</div>
        <div style="font-size:1.8rem; font-weight:700; margin-top:8px;"><?php echo number_format($today_activities); ?></div>
    </div>
    <div class="card" style="flex:1; min-width:200px; padding:18px; border-left:4px solid #ec4899; background:var(--surface);">
        <div style="font-size:0.8rem; color:var(--muted-foreground); font-weight:600; text-transform:uppercase;">Active Duration Today</div>
        <div style="font-size:1.8rem; font-weight:700; margin-top:8px;"><?php echo $active_time_display; ?></div>
    </div>
</div>

<style>
@keyframes pulse {
    0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
.session-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.session-header {
    background: var(--card);
    padding: 12px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s ease;
}
.session-header:hover {
    background: rgba(124, 58, 237, 0.02);
}
.session-toggle-btn {
    background: none;
    border: none;
    color: var(--muted-foreground);
    cursor: pointer;
    padding: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    border-radius: 6px;
    transition: background 0.15s, color 0.15s;
}
.session-toggle-btn:hover, .session-toggle-btn:focus {
    background: rgba(124, 58, 237, 0.08);
    color: var(--accent, #7c3aed);
    outline: none;
}
.session-toggle-btn i {
    transition: transform 0.2s ease;
}
.session-card.expanded .session-toggle-btn i {
    transform: rotate(180deg);
}
.session-card .timeline-list {
    display: none;
}
.session-card.expanded .timeline-list {
    display: flex;
}
.timeline-list {
    padding: 14px 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.timeline-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border-radius: 8px;
    transition: background 0.15s ease;
}
.timeline-item:hover {
    background: var(--card);
}
</style>

<div class="panel">
    <div class="panel-body">
        <form method="GET" class="filter-bar" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
            <div class="field" style="flex:1; min-width:150px;">
                <label>Admin</label>
                <select name="admin">
                    <option value="">All admins</option>
                    <?php foreach ($admin_list as $a): ?>
                        <option value="<?php echo e($a); ?>" <?php echo $f_admin === $a ? 'selected' : ''; ?>><?php echo e($a); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex:1; min-width:160px;">
                <label>Event Type / Category</label>
                <select name="type">
                    <option value="">All Meaningful Activity (Default)</option>
                    <option value="page_view"      <?php echo $f_type === 'page_view' ? 'selected' : ''; ?>>Page Visits</option>
                    <option value="staff_mgmt"     <?php echo $f_type === 'staff_mgmt' ? 'selected' : ''; ?>>Staff Management</option>
                    <option value="admin_mgmt"     <?php echo $f_type === 'admin_mgmt' ? 'selected' : ''; ?>>Admin Management</option>
                    <option value="security"       <?php echo $f_type === 'security' ? 'selected' : ''; ?>>Security &amp; Sensitive Access</option>
                    <option value="student_action" <?php echo $f_type === 'student_action' ? 'selected' : ''; ?>>Student Actions</option>
                    <option value="whatsapp"       <?php echo $f_type === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp Messages</option>
                    <option value="session"        <?php echo $f_type === 'session' ? 'selected' : ''; ?>>Logins &amp; Sessions</option>
                    <option value="creates"        <?php echo $f_type === 'creates' ? 'selected' : ''; ?>>Create / Add Events</option>
                    <option value="updates"        <?php echo $f_type === 'updates' ? 'selected' : ''; ?>>Update / Edit Events</option>
                    <option value="deletes"        <?php echo $f_type === 'deletes' ? 'selected' : ''; ?>>Delete / Clear Events</option>
                    <option value="admin_event"    <?php echo $f_type === 'admin_event' ? 'selected' : ''; ?>>System Events</option>
                    <option value="heartbeat"      <?php echo $f_type === 'heartbeat' ? 'selected' : ''; ?>>Heartbeats &amp; Telemetry</option>
                    <option value="all_activities" <?php echo $f_type === 'all_activities' ? 'selected' : ''; ?>>All Raw Records (incl. Heartbeats)</option>
                </select>
            </div>
            <div class="field" style="flex:1; min-width:150px;">
                <label>Module</label>
                <select name="module">
                    <option value="">All modules</option>
                    <option value="Overview" <?php echo $f_module === 'Overview' ? 'selected' : ''; ?>>Overview</option>
                    <option value="Registrations" <?php echo $f_module === 'Registrations' ? 'selected' : ''; ?>>Registrations</option>
                    <option value="Students" <?php echo $f_module === 'Students' ? 'selected' : ''; ?>>Students</option>
                    <option value="Leads & Mentoring" <?php echo $f_module === 'Leads & Mentoring' ? 'selected' : ''; ?>>Leads & Mentoring</option>
                    <option value="Communication" <?php echo $f_module === 'Communication' ? 'selected' : ''; ?>>Communication</option>
                    <option value="Academics" <?php echo $f_module === 'Academics' ? 'selected' : ''; ?>>Academics</option>
                    <option value="Settings" <?php echo $f_module === 'Settings' ? 'selected' : ''; ?>>Settings</option>
                    <option value="Admin Panel" <?php echo $f_module === 'Admin Panel' ? 'selected' : ''; ?>>Admin Panel</option>
                </select>
            </div>
            <div class="field" style="flex:1; min-width:120px;">
                <label>Activity State</label>
                <select name="idle">
                    <option value="">All states</option>
                    <option value="0" <?php echo $f_idle === 0 ? 'selected' : ''; ?>>Active</option>
                    <option value="1" <?php echo $f_idle === 1 ? 'selected' : ''; ?>>Idle</option>
                </select>
            </div>
            <div class="field" style="flex:1; min-width:120px;"><label>From</label><input type="date" name="from" value="<?php echo e($f_from); ?>"></div>
            <div class="field" style="flex:1; min-width:120px;"><label>To</label><input type="date" name="to" value="<?php echo e($f_to); ?>"></div>
            <div class="field" style="flex:1; min-width:150px;"><label>Page</label><input type="text" name="page" value="<?php echo e($f_page); ?>" placeholder="e.g. task-tracker.php"></div>
            <div class="field" style="flex:1; min-width:150px;"><label>Session Reference</label><input type="text" name="session" value="<?php echo e($f_session); ?>" placeholder="Enter hex string"></div>
            <div class="field grow-2" style="flex:2; min-width:200px;"><label>Search Keywords</label><input type="text" name="q" value="<?php echo e($f_q); ?>" placeholder="Details, target ID or coordinates"></div>
            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="admin-activity.php" class="btn btn-outline">Reset</a>
                <a href="<?php echo e(aqs(['export' => 1])); ?>" class="btn btn-soft-green"><i class="fas fa-file-excel"></i> Export</a>
            </div>
        </form>
    </div>
</div>

<div class="panel" style="margin-bottom: 24px;">
    <div class="panel-head" style="border-bottom: 1px solid var(--border);">
        <span class="head-icon" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="fas fa-circle-nodes"></i></span>
        <h2>Online Admins (<?php echo count($online_list); ?> active now)</h2>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($online_list)): ?>
            <div class="empty-state" style="padding: 24px;"><i class="fas fa-users-slash"></i>
                <p>No other admins are online right now.</p>
            </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Admin</th>
                    <th>Current Section</th>
                    <th>Current Page</th>
                    <th>Activity State</th>
                    <th>IP / Location</th>
                    <th>Active Duration</th>
                    <th>Last Seen</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($online_list as $oa): ?>
                <tr>
                    <td class="cell-main" style="font-weight: 600;">
                        <span style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $oa['is_idle'] ? '#f59e0b' : '#10b981'; ?>; display: inline-block; margin-right: 8px; vertical-align: middle; box-shadow: 0 0 8px <?php echo $oa['is_idle'] ? '#f59e0b' : '#10b981'; ?>;"></span>
                        <?php echo e($oa['username']); ?>
                    </td>
                    <td><span class="badge blue" style="font-size:0.75rem; padding: 4px 8px; font-weight: 600;"><i class="fas fa-folder-open" style="margin-right: 4px;"></i><?php echo e($oa['current_section']); ?></span></td>
                    <td class="cell-sub"><code><?php echo e($oa['current_page']); ?></code></td>
                    <td>
                        <?php if ($oa['is_idle']): ?>
                            <span class="badge amber" style="font-size: 0.75rem; padding: 4px 8px;"><i class="fas fa-moon" style="margin-right: 4px;"></i>Idle</span>
                        <?php else: ?>
                            <span class="badge green" style="font-size: 0.75rem; padding: 4px 8px;"><i class="fas fa-check" style="margin-right: 4px;"></i>Active</span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-sub" style="vertical-align: middle;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <?php if (!empty($oa['latitude']) && !empty($oa['longitude'])): ?>
                                <a href="https://www.google.com/maps?q=<?php echo urlencode($oa['latitude'] . ',' . $oa['longitude']); ?>" target="_blank" class="btn btn-sm btn-soft-red" style="padding: 4px 8px; font-size: 0.8rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;" title="View Exact Google Map Location">
                                    <i class="fas fa-location-dot"></i> Map
                                </a>
                            <?php endif; ?>
                            <?php if ($oa['ip_address']): ?>
                                <span><?php echo e($oa['ip_address']); ?></span>
                            <?php else: ?>-<?php endif; ?>
                        </div>
                    </td>
                    <td class="cell-main" style="font-weight: 500;"><?php echo e($oa['active_duration']); ?></td>
                    <td class="cell-sub" style="color: var(--muted-foreground);"><?php echo date('h:i:s A', strtotime($r['last_seen'] ?? $oa['last_seen'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head" style="display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span class="head-icon" style="background:var(--card);color:var(--secondary);"><i class="fas fa-clock-rotate-left"></i></span>
            <h2>Session Timeline (<?php echo number_format($total); ?><?php echo $total >= 5000 ? '+' : ''; ?> records)</h2>
        </div>
        <div class="head-right" style="display:flex; align-items:center; gap:10px;">
            <?php if (!empty($session_groups)): ?>
                <button type="button" class="btn btn-sm btn-outline" onclick="expandAllSessions()" style="padding:6px 12px; font-size:0.8rem; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-arrows-up-down"></i> Expand All
                </button>
                <button type="button" class="btn btn-sm btn-outline" onclick="collapseAllSessions()" style="padding:6px 12px; font-size:0.8rem; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-compress"></i> Collapse All
                </button>
            <?php endif; ?>
            <?php if ($total > 0): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirmClear();">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="clear_filtered">
                <button type="submit" class="btn btn-sm btn-soft-red">
                    <i class="fas fa-trash"></i> <?php echo $has_filter ? 'Delete Filtered' : 'Clear All'; ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="panel-body" style="padding: 20px;">
        <?php if (empty($session_groups)): ?>
            <div class="empty-state"><i class="fas fa-clock-rotate-left"></i>
                <p><?php echo $has_filter ? 'No activity matches these filters.' : 'No activity recorded yet.'; ?></p>
            </div>
        <?php else: ?>

            <?php
            foreach ($session_groups as $sid => $group):
                if (empty($group['activities'])) continue;
                // Calculate date & time span for the session
                $first_act = end($group['activities']);
                $last_act = $group['activities'][0];
                $time_span = date('h:i A', strtotime($first_act['at_time'])) . ' - ' . date('h:i A', strtotime($last_act['at_time']));
                $date_span = date('d M Y', strtotime($last_act['at_time']));
            ?>
                <div class="session-card" id="session-card-<?php echo htmlspecialchars($sid); ?>">
                    <div class="session-header" onclick="toggleSession('<?php echo htmlspecialchars($sid); ?>')">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <i class="fas fa-user-tie" style="color:var(--accent); margin-right:2px;"></i>
                            <strong><?php echo e($group['admin_name']); ?></strong>
                            <?php if ($sid !== 'legacy_or_direct'): ?>
                                <span style="font-size:0.8rem; color:var(--secondary);" title="<?php echo htmlspecialchars($sid); ?>">Session: <code><?php echo htmlspecialchars(substr($sid, 0, 12)); ?>...</code></span>
                            <?php else: ?>
                                <span class="badge gray" style="font-size:0.7rem;">Direct DB Audit / Legacy</span>
                            <?php endif; ?>
                            <span class="badge blue" style="font-size:0.75rem; padding: 2px 6px; font-weight:600;"><?php echo count($group['activities']); ?> activities</span>
                            <span style="font-size:0.78rem; color:var(--muted-foreground); display:inline-flex; align-items:center; gap:4px; margin-left:6px;">
                                <i class="far fa-calendar"></i> <?php echo $date_span; ?> · <i class="far fa-clock"></i> <?php echo $time_span; ?>
                            </span>
                        </div>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="font-size:0.8rem; color:var(--muted-foreground);">
                                <?php if ($group['ip']): ?><i class="fas fa-network-wired"></i> <?php echo e($group['ip']); ?><?php endif; ?>
                                <?php if ($group['loc']): ?> · <i class="fas fa-map-pin"></i> <?php echo e($group['loc']); ?><?php endif; ?>
                            </div>
                            <button class="session-toggle-btn" aria-expanded="false" aria-controls="timeline-list-<?php echo htmlspecialchars($sid); ?>" onclick="event.stopPropagation(); toggleSession('<?php echo htmlspecialchars($sid); ?>')" title="Toggle session activities">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="timeline-list" id="timeline-list-<?php echo htmlspecialchars($sid); ?>">
                        <?php foreach ($group['activities'] as $act):
                            [$badge_color, $badge_icon, $badge_label] = act_badge($act['act']);
                        ?>
                            <div class="timeline-item" style="cursor:pointer;" onclick='showDetail(<?php echo json_encode($act); ?>)'>
                                <span style="font-size:0.8rem; color:var(--secondary); width:80px; flex-shrink:0;">
                                    <?php echo date('h:i:s A', strtotime($act['at_time'])); ?>
                                </span>
                                <span class="badge <?php echo $badge_color; ?>" style="flex-shrink:0; display:inline-flex; align-items:center; gap:4px; font-size:0.75rem; padding:4px 8px;">
                                    <i class="fas <?php echo $badge_icon; ?>"></i> <?php echo e($badge_label); ?>
                                </span>
                                <span style="font-size:0.85rem; color:var(--muted-foreground); flex-shrink:0; font-weight:600; width:120px;">
                                    <?php echo e($act['module']); ?>
                                </span>
                                <span style="font-size:0.85rem; color:var(--foreground); flex-grow:1; word-break:break-word;">
                                    <?php echo e($act['details']); ?>
                                </span>
                                <div style="display:flex; gap:6px; flex-shrink:0; align-items:center;">
                                    <?php if ($act['is_idle']): ?>
                                        <span class="badge amber" style="font-size:0.7rem; padding: 2px 6px;">Idle</span>
                                    <?php endif; ?>
                                    <?php if (!empty($act['lat']) && !empty($act['lng'])): ?>
                                        <span class="badge red" style="font-size:0.7rem; padding: 2px 6px;"><i class="fas fa-location-dot"></i> Geo</span>
                                    <?php endif; ?>
                                    <?php if ($act['student']): ?>
                                        <span class="badge blue" style="font-size:0.7rem; padding: 2px 6px;">Student: <?php echo e($act['student']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($total_pages > 1): ?>
                <div class="pagination" style="margin-top:20px; display:flex; justify-content:center; gap:6px;">
                    <?php if ($page > 1): ?><a class="page-link" href="<?php echo e(aqs(['page' => $page - 1])); ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
                    <?php for ($p = max(1, $page - 3); $p <= min($total_pages, $page + 3); $p++): ?>
                        <a class="page-link <?php echo $p === $page ? 'active' : ''; ?>" href="<?php echo e(aqs(['page' => $p])); ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?><a class="page-link" href="<?php echo e(aqs(['page' => $page + 1])); ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<!-- Modal: Event Details -->
<div class="modal-backdrop" id="detail-modal">
    <div class="modal" style="max-width:600px; background:var(--surface);">
        <div class="modal-head" style="border-bottom:1px solid var(--border); padding:14px 20px;">
            <h3><i class="fas fa-circle-info" style="color:var(--accent);"></i> Activity Details</h3>
            <button class="modal-close" onclick="closeModal('detail-modal')">&times;</button>
        </div>
        <div class="modal-body" style="padding:20px; font-size:0.88rem; line-height:1.6;">
            <table class="data-table" style="width:100%; border-collapse:collapse;">
                <tbody>
                    <tr><td style="font-weight:600; color:var(--secondary); width:140px; padding:8px 0;">Timestamp</td><td id="det-time" style="padding:8px 0;"></td></tr>
                    <tr><td style="font-weight:600; color:var(--secondary); padding:8px 0;">Admin Name</td><td id="det-admin" style="padding:8px 0;"></td></tr>
                    <tr><td style="font-weight:600; color:var(--secondary); padding:8px 0;">Session Reference</td><td id="det-session" style="padding:8px 0;"></td></tr>
                    <tr><td style="font-weight:600; color:var(--secondary); padding:8px 0;">Module</td><td id="det-module" style="padding:8px 0;"></td></tr>
                    <tr><td style="font-weight:600; color:var(--secondary); padding:8px 0;">Page script</td><td id="det-page" style="padding:8px 0;"></td></tr>
                    <tr><td style="font-weight:600; color:var(--secondary); padding:8px 0;">Action Type</td><td id="det-action" style="padding:8px 0;"></td></tr>
                    <tr><td style="font-weight:600; color:var(--secondary); padding:8px 0;">IP / Location</td><td id="det-ip-loc" style="padding:8px 0;"></td></tr>
                    <tr><td style="font-weight:600; color:var(--secondary); padding:8px 0;">HTTP Request</td><td id="det-request" style="padding:8px 0;"></td></tr>
                    <tr><td style="font-weight:600; color:var(--secondary); padding:8px 0;">Referrer URI</td><td id="det-referrer" style="padding:8px 0; word-break:break-all;"></td></tr>
                    <tr><td style="font-weight:600; color:var(--secondary); padding:8px 0;">User Agent</td><td id="det-ua" style="padding:8px 0; word-break:break-all;"></td></tr>
                    <tr><td style="font-weight:600; color:var(--secondary); padding:8px 0;">Description</td><td id="det-details" style="padding:8px 0; font-weight:600;"></td></tr>
                </tbody>
            </table>

            <div id="det-geo-section" style="margin-top:14px; display:none; padding-top:10px; border-top:1px dashed var(--border);">
                <strong>Geographical Coordinates:</strong>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px;">
                    <span id="det-coords" style="font-family:monospace;"></span>
                    <a id="det-map-link" href="#" target="_blank" class="btn btn-sm btn-soft-red"><i class="fas fa-location-dot"></i> View on Google Maps</a>
                </div>
            </div>

            <div id="det-meta-section" style="margin-top:14px; display:none; padding-top:10px; border-top:1px dashed var(--border);">
                <strong>Browser Metadata Snapshot:</strong>
                <div id="det-meta-table" style="margin-top:8px;"></div>
            </div>
        </div>
    </div>
</div>

<?php
$extra_scripts = "<script>
function confirmClear() {
    " . ($has_filter
        ? "return confirm('Delete ALL " . (int)$total . " activity records matching the current filter? This cannot be undone.');"
        : "return confirm('Delete the ENTIRE activity log (" . (int)$total . " records)? This cannot be undone.');") . "
}

function showDetail(act) {
    document.getElementById('det-time').textContent = formatDateTime(act.at_time);
    document.getElementById('det-admin').textContent = act.admin_name || '-';
    document.getElementById('det-session').textContent = act.session_id || 'Legacy / Direct DB Operation';
    document.getElementById('det-module').textContent = act.module || '-';
    document.getElementById('det-page').textContent = act.page || '-';
    document.getElementById('det-action').textContent = act.act || '-';

    let ipLoc = act.ip || 'Local / Private';
    if (act.loc) {
        ipLoc += ' (' + act.loc + ')';
    }
    document.getElementById('det-ip-loc').textContent = ipLoc;

    let method = act.request_method || 'GET';
    let uri = act.request_uri || '-';
    document.getElementById('det-request').textContent = method + ' ' + uri;
    document.getElementById('det-referrer').textContent = act.referrer || '-';
    document.getElementById('det-ua').textContent = act.user_agent || '-';
    document.getElementById('det-details').textContent = act.details || '-';

    // Geographical coordinates display
    let geo = document.getElementById('det-geo-section');
    if (act.lat && act.lng) {
        geo.style.display = 'block';
        document.getElementById('det-coords').textContent = 'Lat: ' + act.lat + ', Lng: ' + act.lng;
        document.getElementById('det-map-link').href = 'https://www.google.com/maps?q=' + act.lat + ',' + act.lng;
    } else {
        geo.style.display = 'none';
    }

    // Parse metadata JSON
    let metaSection = document.getElementById('det-meta-section');
    if (act.meta) {
        try {
            const meta = typeof act.meta === 'string' ? JSON.parse(act.meta) : act.meta;
            let h = '<table class=\"data-table\" style=\"width:100%; border-collapse:collapse; font-size:0.78rem;\">';
            h += '<tbody>';
            const labels = {
                user_agent: 'User Agent',
                platform: 'Platform / OS',
                screen_width: 'Screen Width',
                screen_height: 'Screen Height',
                viewport_width: 'Viewport Width',
                viewport_height: 'Viewport Height',
                device_pixel_ratio: 'Pixel Ratio',
                timezone: 'Timezone',
                language: 'Language',
                accuracy: 'GPS Accuracy',
                connection: 'Connection'
            };
            for (const k in meta) {
                h += `<tr><td style=\"padding:4px 8px; border-bottom:1px solid var(--border); font-weight:600; width:140px;\">\${labels[k] || k}</td><td style=\"padding:4px 8px; border-bottom:1px solid var(--border); word-break:break-all;\">\${escH(String(meta[k]))}</td></tr>`;
            }
            h += '</tbody></table>';
            document.getElementById('det-meta-table').innerHTML = h;
            metaSection.style.display = 'block';
        } catch (e) {
            metaSection.style.display = 'none';
        }
    } else {
        metaSection.style.display = 'none';
    }

    openModal('detail-modal');
}

function toggleSession(sid) {
    var card = document.getElementById('session-card-' + sid);
    if (!card) return;
    var isExpanded = card.classList.toggle('expanded');
    var btn = card.querySelector('.session-toggle-btn');
    if (btn) {
        btn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
    }
}

function expandAllSessions() {
    var cards = document.querySelectorAll('.session-card');
    cards.forEach(function(card) {
        card.classList.add('expanded');
        var btn = card.querySelector('.session-toggle-btn');
        if (btn) {
            btn.setAttribute('aria-expanded', 'true');
        }
    });
}

function collapseAllSessions() {
    var cards = document.querySelectorAll('.session-card');
    cards.forEach(function(card) {
        card.classList.remove('expanded');
        var btn = card.querySelector('.session-toggle-btn');
        if (btn) {
            btn.setAttribute('aria-expanded', 'false');
        }
    });
}

function formatDateTime(dtStr) {
    if (!dtStr) return '-';
    const d = new Date(dtStr.replace(/-/g, '/'));
    if (isNaN(d)) return dtStr;
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let hours = d.getHours();
    let minutes = d.getMinutes();
    let seconds = d.getSeconds();
    let ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    minutes = minutes < 10 ? '0'+minutes : minutes;
    seconds = seconds < 10 ? '0'+seconds : seconds;
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() + ', ' + hours + ':' + minutes + ':' + seconds + ' ' + ampm;
}

function escH(s) {
    if (!s) return '';
    return s.replace(/&/g, \"&amp;\").replace(/</g, \"&lt;\").replace(/>/g, \"&gt;\").replace(/\"/g, \"&quot;\").replace(/'/g, \"&#039;\");
}
</script>";
include 'includes/admin_footer.php';
?>
