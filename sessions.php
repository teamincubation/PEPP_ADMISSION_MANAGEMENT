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

$success_message = ''; $error_message = ''; $warning_message = '';

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
                $type = in_array($_POST['session_type'] ?? '', array_keys($TYPES), true) ? $_POST['session_type'] : '';
                $courses = array_values(array_filter(array_map('trim', (array)($_POST['courses'] ?? []))));
                $durInput = trim((string)($_POST['duration_hours'] ?? ''));
                $dur = (is_numeric($durInput) && (float)$durInput > 0) ? (float)$durInput : null;
                $status = in_array($_POST['status'] ?? '', ['scheduled', 'completed', 'cancelled'], true) ? $_POST['status'] : 'scheduled';
                $facultyId = ((int)($_POST['faculty_id'] ?? 0)) ?: null;
                $venue = trim($_POST['venue'] ?? '') ?: null;
                $meetLink = trim($_POST['meet_link'] ?? '') ?: null;

                // Mandatory validation for Topic, Faculty, Date & Time, Duration, Session Type, Courses (Phase 1)
                if ($topic === '') {
                    $error_message = 'Session Topic is required.';
                } elseif (!$facultyId) {
                    $error_message = 'Faculty is required.';
                } elseif (!strtotime($dt)) {
                    $error_message = 'A valid Date & Time is required.';
                } elseif ($dur === null || $dur <= 0) {
                    $error_message = 'Session Duration is required.';
                } elseif (empty($type)) {
                    $error_message = 'Session Type is required.';
                } elseif (empty($courses)) {
                    $error_message = 'At least one Course must be selected.';
                } else {
                    $isGoogle = ($type === 'live' && !empty($_POST['google_integrated']));

                    if ($action === 'add_session') {
                        // When Google integration is enabled, manual Meet URL cannot override Google Meet URI
                        $effMeetLink = $isGoogle ? null : $meetLink;
                        $vals = [
                            $topic, $facultyId, date('Y-m-d H:i:s', strtotime($dt)),
                            $dur, $type, $effMeetLink, $venue,
                            implode(',', $courses) ?: null,
                            $status,
                        ];

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

                        // Faculty WhatsApp validation & CommunicationEngine scheduling (Phases 1, 7, 8, 9, 10)
                        $hasFacultyWhatsApp = false;
                        $fData = null;
                        try {
                            $stmtF = $pdo->prepare("SELECT name, mobile FROM faculties WHERE id = ?");
                            $stmtF->execute([$facultyId]);
                            $fData = $stmtF->fetch(PDO::FETCH_ASSOC);
                            if ($fData) {
                                $cleanMobile = preg_replace('/\D/', '', (string)($fData['mobile'] ?? ''));
                                if (!empty($cleanMobile) && strlen($cleanMobile) >= 10) {
                                    $hasFacultyWhatsApp = true;
                                }
                            }
                        } catch (Exception $fEx) {}

                        try {
                            require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
                            $commEngine = CommunicationEngine::getInstance($pdo);
                            $fRes = $commEngine->scheduleFacultySessionNotifications((int)$session_id, $admin_username);
                            if (!empty($fRes['warning'])) {
                                $warning_message = $fRes['warning'];
                            }
                        } catch (Exception $ceEx) {
                            error_log("Failed to schedule faculty session notifications: " . $ceEx->getMessage());
                        }

                        if (!$hasFacultyWhatsApp) {
                            $fName = htmlspecialchars($fData['name'] ?? 'Selected Faculty');
                            $warning_message = "Notice: {$fName} has no valid WhatsApp number configured. Session scheduled, but faculty WhatsApp notifications cannot be sent.";
                        }

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
                            // Google Calendar handles learner invitations with sendUpdates=all; suppress duplicate ERP emails.
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
                                        $effMeetLink ?: '',
                                        $venue ?: '',
                                        $facultyId ?: 0,
                                        $admin_username
                                    );
                                } catch (Exception $mailEx) {
                                    error_log("Failed to enqueue session scheduled email notifications: " . $mailEx->getMessage());
                                }
                            }
                            $success_message = 'Session created.';
                        }
                    } else {
                        // EDIT / UPDATE SESSION
                        $sid = (int)($_POST['session_id'] ?? 0);
                        $curStmt = $pdo->prepare("SELECT * FROM sessions WHERE id = ?");
                        $curStmt->execute([$sid]);
                        $curSess = $curStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$curSess) {
                            $error_message = "Session #{$sid} not found.";
                        } else {
                            $wasGoogle = !empty($curSess['google_integrated']);

                            if ($wasGoogle && $isGoogle) {
                                // Existing Google session being updated: update Google Calendar event and ERP database
                                require_once __DIR__ . '/includes/google/GoogleLiveSessionManager.php';
                                $googleMgr = new GoogleLiveSessionManager($pdo);
                                $upRes = $googleMgr->updateGoogleLiveSession($sid, [
                                    'topic'            => $topic,
                                    'session_datetime' => date('Y-m-d H:i:s', strtotime($dt)),
                                    'duration_hours'   => $dur,
                                    'faculty_id'       => $facultyId ?: 0,
                                    'courses'          => $courses,
                                    'venue'            => $venue,
                                    'status'           => $status,
                                ]);

                                log_admin_activity($pdo, $admin_username, 'session_updated', "Updated Google-integrated session #{$sid}");

                                if ($upRes['success']) {
                                    $success_message = "Session and Google Calendar event updated. Google Calendar invitations sent to {$upRes['invited_count']} active student(s).";
                                } else {
                                    $error_message = "Session updated in ERP, but Google Calendar update encountered an issue: " . htmlspecialchars($upRes['error'] ?? 'Unknown error') . ". You can retry synchronization from the Actions column.";
                                }
                            } elseif (!$wasGoogle && $isGoogle) {
                                // Converted standard session to Google Meet live session
                                $stmt = $pdo->prepare("UPDATE sessions SET topic=?, faculty_id=?, session_datetime=?, duration_hours=?, session_type='live', meet_link=NULL, venue=?, course_csv=?, status=?, google_integrated=1, google_integration_status='pending' WHERE id=?");
                                $stmt->execute([
                                    $topic, $facultyId, date('Y-m-d H:i:s', strtotime($dt)),
                                    $dur, $venue, implode(',', $courses) ?: null, $status, $sid
                                ]);
                                require_once __DIR__ . '/includes/google/GoogleLiveSessionManager.php';
                                $googleMgr = new GoogleLiveSessionManager($pdo);
                                $gRes = $googleMgr->provisionGoogleLiveSession($sid);

                                log_admin_activity($pdo, $admin_username, 'session_updated', "Converted session #{$sid} to Google Meet");
                                if ($gRes['success']) {
                                    $success_message = "Session converted to Google Meet live session. Google Calendar invitations sent to {$gRes['invited_count']} active student(s).";
                                } else {
                                    $error_message = "Session updated in ERP, but Google Meet provisioning failed: " . htmlspecialchars($gRes['error'] ?? 'Unknown error');
                                }
                            } elseif ($wasGoogle && !$isGoogle) {
                                // Converted from Google to manual/offline: cancel Google Calendar event
                                if (!empty($curSess['google_calendar_event_id'])) {
                                    require_once __DIR__ . '/includes/google/GoogleLiveSessionManager.php';
                                    $googleMgr = new GoogleLiveSessionManager($pdo);
                                    $googleMgr->getCalendarService()->deleteEvent((string)$curSess['google_calendar_event_id']);
                                }
                                $stmt = $pdo->prepare("UPDATE sessions SET topic=?, faculty_id=?, session_datetime=?, duration_hours=?, session_type=?, meet_link=?, venue=?, course_csv=?, status=?, google_integrated=0, google_calendar_event_id=NULL, google_integration_status='none' WHERE id=?");
                                $stmt->execute([
                                    $topic, $facultyId, date('Y-m-d H:i:s', strtotime($dt)),
                                    $dur, $type, $meetLink, $venue, implode(',', $courses) ?: null, $status, $sid
                                ]);
                                log_admin_activity($pdo, $admin_username, 'session_updated', "Unlinked session #{$sid} from Google Calendar");
                                $success_message = 'Session updated and unlinked from Google Calendar (Google event cancelled).';
                            } else {
                                // Standard manual session update
                                $stmt = $pdo->prepare("UPDATE sessions SET topic=?, faculty_id=?, session_datetime=?, duration_hours=?, session_type=?, meet_link=?, venue=?, course_csv=?, status=? WHERE id=?");
                                $stmt->execute([
                                    $topic, $facultyId, date('Y-m-d H:i:s', strtotime($dt)),
                                    $dur, $type, $meetLink, $venue, implode(',', $courses) ?: null, $status, $sid
                                ]);
                                log_admin_activity($pdo, $admin_username, 'session_updated', "Updated session #{$sid}");
                                $success_message = 'Session updated.';
                            }

                            // Recalculate faculty session notifications (Phases 8, 9, 10, 11, 12)
                            try {
                                require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
                                $commEngine = CommunicationEngine::getInstance($pdo);
                                $commEngine->updateFacultySessionNotifications((int)$sid, $curSess, $admin_username);
                            } catch (Exception $ceEx) {
                                error_log("Failed to update faculty session notifications: " . $ceEx->getMessage());
                            }
                        }
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
            } elseif ($action === 'end_live_session') {
                $sid = (int)($_POST['session_id'] ?? 0);
                require_once __DIR__ . '/includes/google/GoogleLiveSessionManager.php';
                $googleMgr = new GoogleLiveSessionManager($pdo);
                $endRes = $googleMgr->endGoogleLiveSession($sid);
                if ($endRes['success']) {
                    $success_message = "Google Meet conference ended for Session #{$sid}.";
                    if (!empty($endRes['attendance_synced'])) {
                        $success_message .= " Attendance synchronized ({$endRes['attendance_synced']} participant(s)).";
                    }
                    log_admin_activity($pdo, $admin_username, 'session_ended', "Ended Google Meet Live Session #{$sid}");
                } else {
                    $error_message = "Failed to end Live Session: " . htmlspecialchars($endRes['error'] ?? 'Unknown error');
                }
            } elseif ($action === 'mark_status') {
                $sid = (int)($_POST['session_id'] ?? 0);
                $st = in_array($_POST['status'] ?? '', ['scheduled', 'completed', 'cancelled'], true) ? $_POST['status'] : 'scheduled';
                $pdo->prepare("UPDATE sessions SET status = ? WHERE id = ?")->execute([$st, $sid]);
                log_admin_activity($pdo, $admin_username, 'session_status', "Session #{$sid} → {$st}");
                if ($st === 'cancelled') {
                    try {
                        require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
                        $commEngine = CommunicationEngine::getInstance($pdo);
                        $commEngine->cancelFacultySessionNotifications((int)$sid, $admin_username);
                    } catch (Exception $ceEx) {
                        error_log("Failed to cancel faculty session notifications on mark_status: " . $ceEx->getMessage());
                    }
                }
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
                if (!can_delete()) {
                    $error_message = 'Only the Super Admin can delete a session.';
                } else {
                    $sid = (int)($_POST['session_id'] ?? 0);
                    $stmt = $pdo->prepare("SELECT * FROM sessions WHERE id = ?");
                    $stmt->execute([$sid]);
                    $sess = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$sess) {
                        $error_message = "Session #{$sid} not found.";
                    } else {
                        // Invalidate future jobs and notify faculty of cancellation (Phase 11)
                        try {
                            require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
                            $commEngine = CommunicationEngine::getInstance($pdo);
                            $commEngine->cancelFacultySessionNotifications((int)$sid, $admin_username);
                        } catch (Exception $ceEx) {
                            error_log("Failed to cancel faculty session notifications on delete: " . $ceEx->getMessage());
                        }

                        if (!empty($sess['google_integrated']) && !empty($sess['google_calendar_event_id'])) {
                            // Google integrated session: cancel Google Calendar event with sendUpdates=all
                            require_once __DIR__ . '/includes/google/GoogleLiveSessionManager.php';
                            $googleMgr = new GoogleLiveSessionManager($pdo);
                            $delRes = $googleMgr->deleteGoogleLiveSession($sid);

                            if ($delRes['success']) {
                                log_admin_activity($pdo, $admin_username, 'session_deleted', "Deleted Google-integrated session #{$sid}");
                                $success_message = 'Session and Google Calendar event deleted (cancellation sent to guests).';
                            } else {
                                $error_message = "Failed to cancel Google Calendar event: " . htmlspecialchars($delRes['error'] ?? 'Unknown error') . ". Session was not deleted.";
                            }
                        } else {
                            // Standard session deletion
                            $pdo->prepare("DELETE FROM session_attendance WHERE session_id = ?")->execute([$sid]);
                            $pdo->prepare("DELETE FROM session_google_artifacts WHERE session_id = ?")->execute([$sid]);
                            $pdo->prepare("DELETE FROM session_notifications WHERE session_id = ?")->execute([$sid]);
                            $pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$sid]);
                            log_admin_activity($pdo, $admin_username, 'session_deleted', "Deleted session #{$sid}");
                            $success_message = 'Session deleted.';
                        }
                    }
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

    if ($ajaxAction === 'session_details') {
        try {
            require_once __DIR__ . '/includes/google/GoogleLiveSessionManager.php';
            $googleMgr = new GoogleLiveSessionManager($pdo);
            $details = $googleMgr->getSessionDetails($sessId);
            echo json_encode($details);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    header('Content-Type: application/json; charset=utf-8');

    if ($ajaxAction === 'attendance') {
        try {
            $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'session_attendance'")->fetchColumn();
            if (!$hasTable) {
                echo json_encode(['success' => false, 'error' => 'Attendance table not installed.']);
                exit();
            }
            require_once __DIR__ . '/includes/google/GoogleAttendanceService.php';
            $attService = new GoogleAttendanceService($pdo);
            $summary = $attService->getSessionAttendanceSummary($sessId, true);
            echo json_encode([
                'success'              => true,
                'summary'              => $summary['summary'],
                'faculty'              => $summary['faculty'],
                'registered_students'  => $summary['registered_students'],
                'unknown_participants' => $summary['unknown_participants'],
                'records'              => array_merge($summary['registered_students'], $summary['unknown_participants']),
            ]);
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
            require_once __DIR__ . '/includes/google/GoogleArtifactService.php';
            $artService = new GoogleArtifactService($pdo);
            $summary = $artService->getSessionArtifactsSummary($sessId);
            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'records' => $summary['items'],
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
}

/* ── Data ──────────────────────────────────────────────────────── */
$faculties = []; $courses = [];
try {
    $faculties = $pdo->query("SELECT id, name, mobile FROM faculties WHERE status='active' ORDER BY name")->fetchAll();
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
<?php if ($warning_message): ?><div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($warning_message); ?></span></div><?php endif; ?>
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
                        <?php if (!empty($s['google_integrated'])): ?>
                            <?php if (!empty($s['google_meet_uri'])): ?>
                                <button type="button" class="btn btn-sm btn-soft-blue" title="Copy Google Meet Link" onclick="copyMeetLink('<?php echo e(addslashes($s['google_meet_uri'])); ?>')"><i class="fas fa-copy"></i></button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-soft-violet" title="View Session Details" onclick="openSessionDetails(<?php echo (int)$s['id']; ?>, '<?php echo e(addslashes($s['topic'])); ?>')"><i class="fas fa-circle-info"></i></button>
                        <?php endif; ?>
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
                            <?php if (!empty($s['google_meet_space_name']) && $s['status'] !== 'completed'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('End this Google Meet Live Session? This will disconnect participants, finalize the conference, and process attendance and artifacts.');">
                                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="end_live_session"><input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-soft-red" title="End Live Session (Terminates Google Meet Conference)"><i class="fas fa-phone-slash"></i></button>
                                </form>
                            <?php endif; ?>
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
                            "type"=>$s["session_type"],"meet"=>(string)($s["meet_link"] ?? $s["google_meet_uri"] ?? ''),
                            "venue"=>(string)$s["venue"],
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
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this session? If scheduled with Google, the Google Calendar event and attendee invitations will be cancelled automatically.');">
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
        <form method="POST" id="sess-form" onsubmit="return validateSessionForm(event);">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="sess-action" value="add_session">
            <input type="hidden" name="session_id" id="sess-id">
            <div class="modal-body">
                <!-- Google Scheduling Toggle (Positioned Early at Top of Form) -->
                <div class="field full" id="sess-google-wrap" style="margin-bottom:14px;">
                    <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;font-size:0.88rem;background:linear-gradient(135deg, rgba(66,133,244,0.06), rgba(52,168,83,0.04));border:1.5px solid rgba(66,133,244,0.3);border-radius:12px;padding:12px 16px;">
                        <input type="checkbox" name="google_integrated" id="sess-google" value="1" checked onchange="googleToggle()" style="accent-color:#4285F4;width:19px;height:19px;margin-top:2px;">
                        <div style="flex:1;">
                            <div style="display:flex;align-items:center;gap:6px;">
                                <strong style="color:var(--foreground,#0f172a);font-size:0.92rem;"><i class="fab fa-google" style="color:#4285F4;margin-right:2px;"></i> Schedule with Google Calendar &amp; Google Meet</strong>
                                <span class="badge blue" style="font-size:0.68rem;padding:2px 6px;">Recommended</span>
                            </div>
                            <div style="color:var(--text-muted,#64748b);font-size:0.78rem;margin-top:3px;line-height:1.4;">
                                Generates unique restricted Google Meet room, adds faculty as co-host, invites active students with private guest list, and configures auto-recording &amp; transcription.
                            </div>
                        </div>
                    </label>
                </div>

                <div class="form-grid">
                    <div class="field full"><label>Session Topic <span class="req">*</span></label><input type="text" name="topic" id="sess-topic" required></div>
                    <div class="field">
                        <label>Faculty <span class="req">*</span></label>
                        <select name="faculty_id" id="sess-faculty" required onchange="checkFacultyWhatsAppWarning()">
                            <option value="">-- Select Faculty --</option>
                            <?php foreach ($faculties as $f): 
                                $cleanMob = preg_replace('/\D/', '', (string)($f['mobile'] ?? ''));
                            ?>
                                <option value="<?php echo (int)$f['id']; ?>" data-mobile="<?php echo e($cleanMob); ?>">
                                    <?php echo e($f['name']); ?><?php if (!empty($cleanMob)): ?> (<?php echo e($f['mobile']); ?>)<?php else: ?> [No WhatsApp]<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="faculty-whatsapp-warning" style="display:none;margin-top:6px;padding:6px 10px;background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;color:#92400e;font-size:0.75rem;align-items:center;gap:6px;">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span>Faculty has no WhatsApp number configured. Session will be scheduled, but WhatsApp notifications cannot be delivered.</span>
                        </div>
                    </div>
                    <div class="field"><label>Date &amp; Time <span class="req">*</span></label><input type="datetime-local" name="session_datetime" id="sess-dt" required></div>
                    <div class="field">
                        <label>Duration (hours) <span class="req">*</span></label>
                        <select name="duration_hours" id="sess-dur" required>
                            <option value="">-- Select Duration --</option>
                            <?php foreach ($DURATIONS as $d): ?>
                                <option value="<?php echo $d; ?>"><?php echo $d; ?> hr<?php echo (float)$d > 1 ? 's' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Session Type <span class="req">*</span></label>
                        <select name="session_type" id="sess-type" required onchange="sessTypeToggle()">
                            <?php foreach ($TYPES as $k => $v): ?>
                                <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" id="sess-meet-wrap">
                        <label>Meet Link (live / custom)</label>
                        <input type="url" name="meet_link" id="sess-meet" placeholder="https://meet.google.com/...">
                        <div id="sess-meet-helper" style="font-size:0.75rem;color:var(--text-muted,#64748b);margin-top:4px;display:flex;align-items:center;gap:5px;">
                            <i class="fab fa-google" style="color:#4285F4;"></i> <span>Google Meet link will be generated automatically.</span>
                        </div>
                    </div>
                    <div class="field" id="sess-venue-wrap" style="display:none;"><label>Venue (offline)</label><input type="text" name="venue" id="sess-venue"></div>
                    <div class="field"><label>Status</label><select name="status" id="sess-status"><option value="scheduled">Scheduled</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
                </div>

                <!-- Modern Scannable Multi-Course Selection Area -->
                <div class="field full" style="margin-top:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <label style="margin:0;font-weight:600;">Courses <span class="req">*</span> (select one or more)</label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span id="course-selected-badge" class="badge gray" style="font-size:0.72rem;padding:2px 7px;">0 selected</span>
                            <button type="button" class="btn btn-xs btn-outline" onclick="selectAllCourses(true)" style="padding:2px 8px;font-size:0.72rem;">Select All</button>
                            <button type="button" class="btn btn-xs btn-outline" onclick="selectAllCourses(false)" style="padding:2px 8px;font-size:0.72rem;">Clear</button>
                        </div>
                    </div>
                    <div style="margin-bottom:8px;">
                        <input type="text" id="course-search-input" placeholder="Quick filter courses..." oninput="filterCourseList()" style="width:100%;padding:6px 12px;font-size:0.8rem;border:1px solid var(--border);border-radius:8px;background:var(--card,#ffffff);">
                    </div>
                    <div id="sess-courses" style="display:flex; flex-wrap:wrap; gap:7px; max-height:170px; overflow-y:auto; border:1px solid var(--border); border-radius:10px; padding:10px; background:var(--bg-hover,#f8fafc);">
                        <?php foreach ($courses as $c): ?>
                            <label class="course-chip-label" data-course="<?php echo e(strtolower($c)); ?>" style="display:inline-flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;background:var(--card,#fff);border:1px solid var(--border);border-radius:50px;padding:6px 12px;cursor:pointer;transition:all 0.15s;">
                                <input type="checkbox" name="courses[]" value="<?php echo e($c); ?>" class="sess-course" onchange="updateCourseCount()" style="accent-color:var(--accent);"> <?php echo e($c); ?>
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

<!-- DETAILS MODAL (Session Details, Faculty, Courses, Invited Students, Artifacts) -->
<div class="modal-backdrop" id="sess-details-modal">
    <div class="modal" style="max-width:820px;max-height:90vh;display:flex;flex-direction:column;">
        <div class="modal-head" style="flex-shrink:0;">
            <h3 id="sess-details-title"><i class="fas fa-circle-info" style="color:var(--accent);"></i> Session Details</h3>
            <button class="modal-close" onclick="closeModal('sess-details-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="padding:16px 24px;overflow-y:auto;flex:1;">
            <div class="tabs" style="margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:8px;">
                <button type="button" class="btn btn-sm btn-outline active" id="tab-btn-overview" onclick="switchDetailTab('overview')"><i class="fas fa-list-check"></i> Overview &amp; Google</button>
                <button type="button" class="btn btn-sm btn-outline" id="tab-btn-students" onclick="switchDetailTab('students')"><i class="fas fa-user-graduate"></i> Attendance &amp; Learners <span id="detail-student-count-badge" class="badge blue" style="font-size:0.68rem;padding:2px 6px;">0</span></button>
                <button type="button" class="btn btn-sm btn-outline" id="tab-btn-art" onclick="switchDetailTab('art')"><i class="fas fa-file-video"></i> Recordings &amp; Gemini Notes</button>
            </div>

            <!-- TAB 1: OVERVIEW & GOOGLE -->
            <div id="pane-detail-overview">
                <div id="overview-content">
                    <div style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading session details...</div>
                </div>
            </div>

            <!-- TAB 2: ATTENDANCE & LEARNERS -->
            <div id="pane-detail-students" style="display:none;">
                <div id="attendance-summary-bar" style="margin-bottom:14px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;"></div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:12px;flex-wrap:wrap;">
                    <div style="font-size:0.83rem;color:var(--text-muted);">
                        Authoritative Google Meet participation, segregated faculty tracking, and registered student matching:
                    </div>
                    <input type="text" id="detail-student-search" placeholder="Search learner name, email, or course..." oninput="filterDetailStudents()" style="padding:6px 12px;font-size:0.8rem;border:1px solid var(--border);border-radius:8px;min-width:240px;">
                </div>
                <div id="students-content" style="max-height:460px; overflow-y:auto;">
                    <div style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading attendance &amp; learners...</div>
                </div>
            </div>

            <!-- TAB 3: RECORDINGS & GEMINI NOTES -->
            <div id="pane-detail-art" style="display:none;">
                <div id="art-content" style="max-height:460px; overflow-y:auto;">
                    <div style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading recordings &amp; Gemini notes...</div>
                </div>
            </div>
        </div>
        <div class="modal-foot" style="flex-shrink:0;">
            <button type="button" class="btn btn-outline" onclick="closeModal('sess-details-modal')">Close</button>
        </div>
    </div>
</div>

<!-- Non-blocking Toast Notification for Clipboard Copying -->
<div id="sess-toast" style="position:fixed;bottom:24px;right:24px;z-index:99999;background:#0f172a;color:#ffffff;padding:12px 20px;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.25);display:none;align-items:center;gap:10px;font-size:0.88rem;pointer-events:none;transition:opacity 0.3s ease;">
    <i class="fas fa-circle-check" style="color:#10b981;font-size:1.1rem;"></i>
    <span id="sess-toast-msg">Google Meet link copied.</span>
</div>

<?php
$extra_scripts = "<script>
// Toggle Google scheduling & Meet Link disable/enable
function checkFacultyWhatsAppWarning() {
    var sel = document.getElementById('sess-faculty');
    var opt = sel ? sel.options[sel.selectedIndex] : null;
    var mob = opt ? (opt.getAttribute('data-mobile') || '') : '';
    var warn = document.getElementById('faculty-whatsapp-warning');
    if (warn) {
        if (sel && sel.value && (!mob || mob.length < 10)) {
            warn.style.display = 'flex';
        } else {
            warn.style.display = 'none';
        }
    }
}

function validateSessionForm(e) {
    var topic = (document.getElementById('sess-topic').value || '').trim();
    var faculty = document.getElementById('sess-faculty').value;
    var dt = document.getElementById('sess-dt').value;
    var dur = document.getElementById('sess-dur').value;
    var type = document.getElementById('sess-type').value;
    var coursesChecked = document.querySelectorAll('.sess-course:checked').length;

    var errors = [];
    if (!topic) errors.push('Session Topic is required.');
    if (!faculty) errors.push('Faculty is required.');
    if (!dt) errors.push('Date & Time is required.');
    if (!dur || parseFloat(dur) <= 0) errors.push('Duration is required.');
    if (!type) errors.push('Session Type is required.');
    if (coursesChecked === 0) errors.push('At least one Course must be selected.');

    if (errors.length > 0) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        alert('Please fill all mandatory fields:\\n\\n• ' + errors.join('\\n• '));
        return false;
    }

    return true;
}

function googleToggle() {
    var isGoogle = document.getElementById('sess-google').checked;
    var isLive = (document.getElementById('sess-type').value === 'live');
    var meetInput = document.getElementById('sess-meet');
    var meetHelper = document.getElementById('sess-meet-helper');

    if (isGoogle && isLive) {
        meetInput.disabled = true;
        meetInput.placeholder = 'Generated automatically by Google Meet';
        if (meetHelper) meetHelper.style.display = 'flex';
    } else {
        meetInput.disabled = false;
        meetInput.placeholder = 'https://meet.google.com/...';
        if (meetHelper) meetHelper.style.display = 'none';
    }
}

function sessTypeToggle() {
    var t = document.getElementById('sess-type').value;
    var isLive = (t === 'live');
    document.getElementById('sess-meet-wrap').style.display   = isLive ? 'block' : 'none';
    document.getElementById('sess-google-wrap').style.display = isLive ? 'block' : 'none';
    document.getElementById('sess-venue-wrap').style.display  = (t === 'offline') ? 'block' : 'none';
    if (!isLive) {
        document.getElementById('sess-google').checked = false;
    }
    googleToggle();
}

function openSessModal() {
    document.getElementById('sess-action').value = 'add_session';
    document.getElementById('sess-modal-title').innerHTML = '<i class=\\\"fas fa-video\\\" style=\\\"color:var(--accent)\\\"></i> Add Session';
    document.getElementById('sess-id').value = '';
    document.getElementById('sess-topic').value = '';
    document.getElementById('sess-faculty').value = '';
    document.getElementById('sess-dt').value = '';
    document.getElementById('sess-dur').value = '1.00';
    document.getElementById('sess-type').value = 'live';
    document.getElementById('sess-meet').value = '';
    document.getElementById('sess-venue').value = '';
    document.getElementById('sess-status').value = 'scheduled';
    document.getElementById('sess-google').checked = true; // Default state: enabled/ticked
    document.querySelectorAll('.sess-course').forEach(function(c){ c.checked = false; });
    var searchIn = document.getElementById('course-search-input');
    if (searchIn) searchIn.value = '';
    filterCourseList();
    updateCourseCount();
    checkFacultyWhatsAppWarning();
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
    var set = {}; (s.courses || []).forEach(function(c){ set[c] = true; });
    document.querySelectorAll('.sess-course').forEach(function(c){ c.checked = !!set[c.value]; });
    var searchIn = document.getElementById('course-search-input');
    if (searchIn) searchIn.value = '';
    filterCourseList();
    updateCourseCount();
    checkFacultyWhatsAppWarning();
    sessTypeToggle();
    openModal('sess-modal');
}

// Copy Google Meet link action
function copyMeetLink(url) {
    if (!url || !url.startsWith('https://meet.google.com/')) {
        showToast('Invalid Google Meet URL.', false);
        return;
    }
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(function() {
            showToast('Google Meet link copied.', true);
        }).catch(function() {
            fallbackCopy(url);
        });
    } else {
        fallbackCopy(url);
    }
}

function fallbackCopy(text) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    try {
        document.execCommand('copy');
        showToast('Google Meet link copied.', true);
    } catch (err) {
        showToast('Failed to copy link to clipboard.', false);
    }
    document.body.removeChild(textarea);
}

function showToast(msg, isSuccess) {
    var toast = document.getElementById('sess-toast');
    var msgEl = document.getElementById('sess-toast-msg');
    var icon = toast ? toast.querySelector('i') : null;
    if (msgEl) msgEl.textContent = msg;
    if (icon) {
        icon.className = isSuccess ? 'fas fa-circle-check' : 'fas fa-circle-exclamation';
        icon.style.color = isSuccess ? '#10b981' : '#ef4444';
    }
    if (toast) {
        toast.style.display = 'flex';
        toast.style.opacity = '1';
        clearTimeout(toast._timer);
        toast._timer = setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() { toast.style.display = 'none'; }, 300);
        }, 2500);
    }
}

// Course multi-select helpers
function updateCourseCount() {
    var checked = document.querySelectorAll('.sess-course:checked').length;
    var badge = document.getElementById('course-selected-badge');
    if (badge) {
        badge.textContent = checked + (checked === 1 ? ' course selected' : ' courses selected');
        badge.className = 'badge ' + (checked > 0 ? 'blue' : 'gray');
    }
}

function selectAllCourses(check) {
    var labels = document.querySelectorAll('.course-chip-label');
    labels.forEach(function(l) {
        if (l.style.display !== 'none') {
            var cb = l.querySelector('.sess-course');
            if (cb) cb.checked = check;
        }
    });
    updateCourseCount();
}

function filterCourseList() {
    var q = (document.getElementById('course-search-input').value || '').toLowerCase().trim();
    var labels = document.querySelectorAll('.course-chip-label');
    labels.forEach(function(l) {
        var course = l.getAttribute('data-course') || '';
        l.style.display = (q === '' || course.indexOf(q) !== -1) ? 'inline-flex' : 'none';
    });
}

function switchDetailTab(tab) {
    document.getElementById('pane-detail-overview').style.display = (tab === 'overview') ? 'block' : 'none';
    document.getElementById('pane-detail-students').style.display = (tab === 'students') ? 'block' : 'none';
    document.getElementById('pane-detail-art').style.display      = (tab === 'art') ? 'block' : 'none';

    document.getElementById('tab-btn-overview').classList.toggle('active', tab === 'overview');
    document.getElementById('tab-btn-students').classList.toggle('active', tab === 'students');
    document.getElementById('tab-btn-art').classList.toggle('active', tab === 'art');
}

var currentInvitedStudents = [];

function filterDetailStudents() {
    var q = (document.getElementById('detail-student-search').value || '').toLowerCase().trim();
    var tbody = document.getElementById('students-table-body');
    if (!tbody) return;
    var rows = tbody.querySelectorAll('tr');
    var matchCount = 0;
    rows.forEach(function(r) {
        var txt = (r.getAttribute('data-search') || '').toLowerCase();
        var match = (q === '' || txt.indexOf(q) !== -1);
        r.style.display = match ? '' : 'none';
        if (match) matchCount++;
    });
    var emptyEl = document.getElementById('students-search-empty');
    if (emptyEl) {
        emptyEl.style.display = (matchCount === 0) ? 'block' : 'none';
    }
}

function openSessionDetails(sessionId, topic) {
    document.getElementById('sess-details-title').innerHTML = '<i class=\"fas fa-circle-info\" style=\"color:var(--accent);\"></i> ' + topic;
    switchDetailTab('overview');
    openModal('sess-details-modal');

    var overviewEl = document.getElementById('overview-content');
    var studentsEl = document.getElementById('students-content');
    var artEl      = document.getElementById('art-content');
    var sumBar     = document.getElementById('attendance-summary-bar');

    overviewEl.innerHTML = '<div style=\"text-align:center; padding:28px; color:var(--text-muted);\"><i class=\"fas fa-spinner fa-spin\"></i> Loading session details...</div>';
    studentsEl.innerHTML = '<div style=\"text-align:center; padding:28px; color:var(--text-muted);\"><i class=\"fas fa-spinner fa-spin\"></i> Loading attendance &amp; learners...</div>';
    artEl.innerHTML      = '<div style=\"text-align:center; padding:28px; color:var(--text-muted);\"><i class=\"fas fa-spinner fa-spin\"></i> Loading recordings &amp; Gemini notes...</div>';
    if (sumBar) sumBar.innerHTML = '';

    // Fetch complete session details
    fetch('sessions.php?ajax=session_details&session_id=' + sessionId)
        .then(function(r){ return r.json(); })
        .then(function(res){
            if (!res.success) {
                overviewEl.innerHTML = '<div class=\"alert alert-error\">' + (res.error || 'Failed to load session details.') + '</div>';
                return;
            }

            var s = res.session;
            var f = res.faculty;
            var c = res.courses;
            var g = res.google;
            var stList = res.invited_students || [];
            currentInvitedStudents = stList;

            var regStudents = res.registered_students || [];
            var unkStudents = res.unknown_participants || [];
            var facAtt = res.faculty_attendance || {};
            var sum = res.attendance_summary || {};

            var stBadge = document.getElementById('detail-student-count-badge');
            if (stBadge) stBadge.textContent = regStudents.length > 0 ? regStudents.length : stList.length;

            // Render Overview (Tab 1)
            var ovHtml = '';
            ovHtml += '<div style=\"display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px;\">';

            // Section 1: Session Details
            ovHtml += '<div style=\"background:var(--bg-hover,#f8fafc); border:1px solid var(--border); border-radius:12px; padding:16px;\">';
            ovHtml += '<div style=\"font-weight:700; font-size:0.9rem; margin-bottom:10px; color:var(--foreground,#0f172a); display:flex; align-items:center; gap:8px;\"><i class=\"fas fa-video\" style=\"color:var(--accent);\"></i> Section 1 — Session Details</div>';
            ovHtml += '<div style=\"display:flex; flex-direction:column; gap:6px; font-size:0.83rem;\">';
            ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Topic:</span> <strong>' + (s.topic || '-') + '</strong></div>';
            ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Date:</span> ' + (s.date || '-') + '</div>';
            ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Time Window:</span> ' + (s.start_time || '-') + ' - ' + (s.end_time || '-') + ' (' + s.duration + ')</div>';
            ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Session Type:</span> <span class=\"badge gray\">' + s.session_type + '</span></div>';
            ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Session Status:</span> <span class=\"badge ' + (s.status === 'Scheduled' ? 'violet' : (s.status === 'Completed' ? 'green' : 'red')) + '\">' + s.status + '</span></div>';
            ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Created By:</span> ' + s.created_by + '</div>';
            ovHtml += '</div></div>';

            // Section 2: Faculty
            ovHtml += '<div style=\"background:var(--bg-hover,#f8fafc); border:1px solid var(--border); border-radius:12px; padding:16px;\">';
            ovHtml += '<div style=\"font-weight:700; font-size:0.9rem; margin-bottom:10px; color:var(--foreground,#0f172a); display:flex; align-items:center; gap:8px;\"><i class=\"fas fa-chalkboard-user\" style=\"color:#3b82f6;\"></i> Section 2 — Faculty &amp; Co-host</div>';
            ovHtml += '<div style=\"display:flex; flex-direction:column; gap:6px; font-size:0.83rem;\">';
            ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Name:</span> <strong>' + (f.name || 'Not assigned') + '</strong></div>';
            if (f.email) {
                ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Faculty Email:</span> ' + f.email + '</div>';
            }
            var coColor = (f.cohost_status.indexOf('Confirmed') !== -1 || f.cohost_status.indexOf('Configured') !== -1) ? 'green' : (f.cohost_status.indexOf('Failed') !== -1 ? 'red' : 'gray');
            ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Meet Co-host:</span> <span class=\"badge ' + coColor + '\">' + f.cohost_status + '</span></div>';
            ovHtml += '</div></div>';

            // Section 3: Courses
            ovHtml += '<div style=\"background:var(--bg-hover,#f8fafc); border:1px solid var(--border); border-radius:12px; padding:16px;\">';
            ovHtml += '<div style=\"font-weight:700; font-size:0.9rem; margin-bottom:10px; color:var(--foreground,#0f172a); display:flex; align-items:center; gap:8px;\"><i class=\"fas fa-graduation-cap\" style=\"color:#10b981;\"></i> Section 3 — Target Courses (' + c.count + ')</div>';
            ovHtml += '<div style=\"display:flex; flex-wrap:wrap; gap:6px;\">';
            if (c.list && c.list.length > 0) {
                c.list.forEach(function(courseName){
                    ovHtml += '<span class=\"badge blue\" style=\"font-size:0.75rem;\">' + courseName + '</span>';
                });
            } else {
                ovHtml += '<span class=\"cell-sub\">No courses attached.</span>';
            }
            ovHtml += '</div></div>';

            // Section 5: Google Details
            ovHtml += '<div style=\"background:var(--bg-hover,#f8fafc); border:1px solid var(--border); border-radius:12px; padding:16px;\">';
            ovHtml += '<div style=\"font-weight:700; font-size:0.9rem; margin-bottom:10px; color:var(--foreground,#0f172a); display:flex; align-items:center; gap:8px;\"><i class=\"fab fa-google\" style=\"color:#4285F4;\"></i> Section 5 — Google Workspace Integration</div>';
            ovHtml += '<div style=\"display:flex; flex-direction:column; gap:6px; font-size:0.83rem;\">';
            ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Integration:</span> ' + (g.is_integrated ? '<span class=\"badge blue\"><i class=\"fab fa-google\"></i> Enabled</span>' : '<span class=\"badge gray\">Disabled</span>') + '</div>';

            if (g.is_integrated) {
                ovHtml += '<div style=\"margin:6px 0; padding:8px 12px; background:rgba(0,0,0,0.02); border-radius:8px; border:1px solid var(--border); font-size:0.8rem; display:flex; flex-direction:column; gap:4px;\">';
                ovHtml += '<div style=\"font-weight:600; color:var(--text-muted); font-size:0.75rem; text-transform:uppercase;\">Google Setup Checklist</div>';
                ovHtml += '<div>' + (g.meet_space_created ? '<span style=\"color:#10b981; font-weight:bold;\">✓</span> Meet Space Created' : '<span style=\"color:#ef4444; font-weight:bold;\">✗</span> Meet Space Not Created') + '</div>';
                ovHtml += '<div>' + (g.cohost_confirmed ? '<span style=\"color:#10b981; font-weight:bold;\">✓</span> Faculty Co-host Confirmed' : (g.cohost_status === 'Co-host Setup Failed' ? '<span style=\"color:#ef4444; font-weight:bold;\">⚠</span> Faculty Co-host Setup Failed' : '<span style=\"color:#f59e0b; font-weight:bold;\">⏳</span> ' + (g.cohost_status || 'Co-host Pending'))) + '</div>';
                ovHtml += '<div>' + (g.calendar_event_created ? '<span style=\"color:#10b981; font-weight:bold;\">✓</span> Calendar Event Created' : '<span style=\"color:#ef4444; font-weight:bold;\">✗</span> Calendar Event Not Created') + '</div>';
                ovHtml += '<div>' + (g.integration_status === 'synced' ? '<span style=\"color:#10b981; font-weight:bold;\">✓</span> Google Integration Ready' : '<span style=\"color:#f59e0b; font-weight:bold;\">⚠</span> Integration Incomplete (' + g.integration_status + ')') + '</div>';
                ovHtml += '</div>';
            }

            if (g.meet_uri) {
                ovHtml += '<div style=\"display:flex; align-items:center; gap:6px;\"><span style=\"color:var(--text-muted); width:110px;\">Meet Link:</span> <a href=\"' + g.meet_uri + '\" target=\"_blank\" style=\"font-weight:600; color:var(--primary); word-break:break-all;\">' + g.meet_uri + '</a> <button type=\"button\" class=\"btn btn-xs btn-outline\" onclick=\"copyMeetLink(\'' + g.meet_uri + '\')\"><i class=\"fas fa-copy\"></i></button></div>';
            }
            if (g.calendar_event_id) {
                ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Calendar Event:</span> <span style=\"font-family:monospace; font-size:0.75rem; background:rgba(0,0,0,0.05); padding:2px 6px; border-radius:4px;\">' + g.calendar_event_id + '</span></div>';
            }
            if (g.meet_space_name) {
                ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Meet Space:</span> <span style=\"font-family:monospace; font-size:0.75rem;\">' + g.meet_space_name + '</span></div>';
            }
            ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Sync Status:</span> <span class=\"badge ' + (g.integration_status === 'synced' ? 'green' : (g.integration_status === 'failed' ? 'red' : 'gray')) + '\">' + g.integration_status + '</span></div>';
            if (g.last_sync_at) {
                ovHtml += '<div><span style=\"color:var(--text-muted); width:110px; display:inline-block;\">Last Sync:</span> ' + g.last_sync_at + '</div>';
            }
            if (g.error_message) {
                ovHtml += '<div style=\"color:#ef4444; margin-top:4px;\"><i class=\"fas fa-triangle-exclamation\"></i> ' + g.error_message + '</div>';
            }
            ovHtml += '</div></div>';

            ovHtml += '</div>';
            overviewEl.innerHTML = ovHtml;

            // Render Tab 2: Attendance & Learners
            if (sumBar) {
                var sbHtml = '';
                var regCount = sum.registered_students_count || regStudents.length || stList.length;
                var pCount = sum.present_count || 0;
                var aCount = sum.absent_count || (regCount - pCount);
                var fStatus = sum.faculty_status || (facAtt.attendance_status || 'Not Assigned');
                var uCount = sum.unknown_count || unkStudents.length;

                sbHtml += '<span class=\"badge blue\" style=\"font-size:0.8rem; padding:5px 12px;\"><i class=\"fas fa-user-graduate\"></i> Registered Students: <strong>' + regCount + '</strong></span>';
                sbHtml += '<span class=\"badge green\" style=\"font-size:0.8rem; padding:5px 12px;\"><i class=\"fas fa-check\"></i> Present: <strong>' + pCount + '</strong></span>';
                sbHtml += '<span class=\"badge red\" style=\"font-size:0.8rem; padding:5px 12px;\"><i class=\"fas fa-xmark\"></i> Absent: <strong>' + aCount + '</strong></span>';
                var fCol = fStatus === 'Present' ? 'green' : (fStatus === 'Absent' ? 'red' : 'gray');
                sbHtml += '<span class=\"badge ' + fCol + '\" style=\"font-size:0.8rem; padding:5px 12px;\"><i class=\"fas fa-chalkboard-user\"></i> Faculty: <strong>' + fStatus + '</strong></span>';
                if (uCount > 0) {
                    sbHtml += '<span class=\"badge amber\" style=\"font-size:0.8rem; padding:5px 12px;\"><i class=\"fas fa-triangle-exclamation\"></i> Unknown Participants: <strong>' + uCount + '</strong></span>';
                }
                sumBar.innerHTML = sbHtml;
            }

            var sHtml = '';

            // Section 2A: Faculty Attendance Block
            if (facAtt && facAtt.name && facAtt.name !== 'Not assigned') {
                var fBadge = facAtt.attendance_status === 'Present' ? '<span class=\"badge green\">Present</span>' : '<span class=\"badge red\">Absent</span>';
                sHtml += '<div style=\"background:var(--bg-hover,#f8fafc); border:1px solid var(--border); border-radius:10px; padding:14px 16px; margin-bottom:16px;\">';
                sHtml += '<div style=\"display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;\">';
                sHtml += '<div>';
                sHtml += '<div style=\"font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:var(--text-muted);\"><i class=\"fas fa-chalkboard-user\" style=\"color:#3b82f6;\"></i> Assigned Faculty Attendance</div>';
                sHtml += '<div style=\"font-weight:700; font-size:0.95rem; margin-top:2px;\">' + facAtt.name + ' ' + (facAtt.email ? ('<span style=\"font-weight:normal; font-size:0.8rem; color:var(--text-muted);\">(' + facAtt.email + ')</span>') : '') + '</div>';
                sHtml += '</div>';
                sHtml += '<div style=\"display:flex; align-items:center; gap:14px; font-size:0.83rem;\">';
                sHtml += '<div>' + fBadge + '</div>';
                sHtml += '<div><span style=\"color:var(--text-muted);\">Joined:</span> <strong>' + (facAtt.first_join_time || '-') + '</strong></div>';
                sHtml += '<div><span style=\"color:var(--text-muted);\">Left:</span> <strong>' + (facAtt.last_leave_time || '-') + '</strong></div>';
                sHtml += '<div><span style=\"color:var(--text-muted);\">Duration:</span> <strong>' + (facAtt.duration || '0m') + '</strong></div>';
                sHtml += '</div>';
                sHtml += '</div></div>';
            }

            // Section 2B: Registered Students
            sHtml += '<div style=\"font-size:0.82rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:8px;\"><i class=\"fas fa-user-graduate\" style=\"color:var(--primary);\"></i> Registered Students (' + (regStudents.length > 0 ? regStudents.length : stList.length) + ')</div>';

            var effectiveStudents = regStudents.length > 0 ? regStudents : stList;

            if (effectiveStudents.length === 0) {
                sHtml += '<div class=\"empty-state\" style=\"padding:20px; margin-bottom:14px;\"><p>No registered students associated with this session.</p></div>';
            } else {
                sHtml += '<table class=\"data-table\" style=\"font-size:0.83rem; margin-bottom:16px;\"><thead><tr><th style=\"width:35px;\">#</th><th>Student Name</th><th>Course</th><th>Status</th><th>Joined</th><th>Left</th><th style=\"text-align:right;\">Duration</th></tr></thead><tbody id=\"students-table-body\">';
                effectiveStudents.forEach(function(st, idx){
                    var stColor = {'full attendance':'green','partial attendance':'amber','joined':'blue','invited':'gray','absent':'red'}[st.attendance_status || st.status] || 'gray';
                    var statusText = st.attendance_status || st.status || 'invited';
                    sHtml += '<tr data-search=\"' + (st.name + ' ' + (st.course || '')).replace(/\"/g, '') + '\">';
                    sHtml += '<td style=\"color:var(--text-muted);\">' + (idx + 1) + '</td>';
                    sHtml += '<td><strong>' + st.name + '</strong></td>';
                    sHtml += '<td><span class=\"badge gray\" style=\"font-size:0.72rem;\">' + (st.course || '-') + '</span></td>';
                    sHtml += '<td><span class=\"badge ' + stColor + '\">' + statusText + '</span></td>';
                    sHtml += '<td style=\"font-size:0.78rem;\">' + (st.first_join_time || '-') + '</td>';
                    sHtml += '<td style=\"font-size:0.78rem;\">' + (st.last_leave_time || '-') + '</td>';
                    sHtml += '<td style=\"text-align:right; font-weight:600;\">' + (st.duration || '0m') + '</td>';
                    sHtml += '</tr>';
                });
                sHtml += '</tbody></table>';
                sHtml += '<div id=\"students-search-empty\" style=\"display:none; text-align:center; padding:16px; color:var(--text-muted);\">No matching learners found.</div>';
            }

            // Section 2C: Unknown / Unregistered Participants
            if (unkStudents.length > 0) {
                sHtml += '<div style=\"background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px 16px; margin-top:20px;\">';
                sHtml += '<div style=\"display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;\">';
                sHtml += '<div style=\"font-weight:700; font-size:0.86rem; color:#92400e; display:flex; align-items:center; gap:8px;\"><i class=\"fas fa-user-secret\" style=\"color:#f59e0b;\"></i> Unknown / Unregistered Participants (' + unkStudents.length + ')</div>';
                sHtml += '<span class=\"badge amber\">OPEN Meet Guests</span>';
                sHtml += '</div>';
                sHtml += '<div style=\"font-size:0.76rem; color:#b45309; margin-bottom:10px;\">The following participant(s) entered the Google Meet URL without matching registered course students or assigned faculty. Kept strictly segregated for administrator audit:</div>';
                sHtml += '<table class=\"data-table\" style=\"font-size:0.8rem; background:#ffffff;\"><thead><tr><th style=\"width:30px;\">#</th><th>Google Display Name</th><th>Google Email / ID</th><th>Joined</th><th>Left</th><th>Duration</th><th style=\"text-align:right;\">Classification</th></tr></thead><tbody>';
                unkStudents.forEach(function(u, idx){
                    sHtml += '<tr>';
                    sHtml += '<td style=\"color:var(--text-muted);\">' + (idx + 1) + '</td>';
                    sHtml += '<td><strong>' + u.name + '</strong></td>';
                    sHtml += '<td style=\"font-family:monospace; font-size:0.75rem; color:var(--text-muted);\">' + u.email + '</td>';
                    sHtml += '<td style=\"font-size:0.78rem;\">' + (u.first_join_time || '-') + '</td>';
                    sHtml += '<td style=\"font-size:0.78rem;\">' + (u.last_leave_time || '-') + '</td>';
                    sHtml += '<td>' + (u.duration || '0m') + '</td>';
                    sHtml += '<td style=\"text-align:right;\"><span class=\"badge amber\">' + u.attendance_status + '</span></td>';
                    sHtml += '</tr>';
                });
                sHtml += '</tbody></table>';
                sHtml += '</div>';
            }

            studentsEl.innerHTML = sHtml;

            // Render Tab 3: Artifacts Cards
            var artSummary = res.artifacts_summary || {};
            var rec = artSummary.recording || {};
            var tr  = artSummary.transcript || {};
            var sn  = artSummary.smart_notes || {};

            var aHtml = '<div style=\"display:grid; grid-template-columns:repeat(auto-fit, minmax(230px, 1fr)); gap:16px;\">';

            // Card 1: Recording
            aHtml += '<div style=\"background:var(--bg-hover,#f8fafc); border:1px solid var(--border); border-radius:12px; padding:18px; display:flex; flex-direction:column; justify-content:space-between; gap:14px;\">';
            aHtml += '<div>';
            aHtml += '<div style=\"display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;\">';
            aHtml += '<div style=\"font-size:1.6rem; color:#ef4444;\"><i class=\"fas fa-video\"></i></div>';
            aHtml += '<span class=\"badge ' + (rec.available ? 'green' : (rec.status === 'PROCESSING' ? 'amber' : 'gray')) + '\">' + (rec.status || 'NOT AVAILABLE') + '</span>';
            aHtml += '</div>';
            aHtml += '<div style=\"font-weight:700; font-size:0.95rem; margin-bottom:4px;\">Google Meet Recording</div>';
            aHtml += '<div style=\"font-size:0.78rem; color:var(--text-muted);\">Official session recording video file hosted in Google Drive.</div>';
            aHtml += '</div>';
            if (rec.available && rec.url) {
                aHtml += '<a href=\"' + rec.url + '\" target=\"_blank\" class=\"btn btn-sm btn-primary\" style=\"display:inline-flex; align-items:center; justify-content:center; gap:6px;\"><i class=\"fas fa-play\"></i> Open Recording</a>';
            } else {
                aHtml += '<button type=\"button\" class=\"btn btn-sm btn-outline\" disabled style=\"opacity:0.6;\"><i class=\"fas fa-clock\"></i> ' + (rec.status === 'PROCESSING' ? 'Processing...' : 'Not Available Yet') + '</button>';
            }
            aHtml += '</div>';

            var isConsolidatedDoc = (tr.file_id && sn.file_id && tr.file_id === sn.file_id);

            // Card 2: Transcript
            aHtml += '<div style=\"background:var(--bg-hover,#f8fafc); border:1px solid var(--border); border-radius:12px; padding:18px; display:flex; flex-direction:column; justify-content:space-between; gap:14px;\">';
            aHtml += '<div>';
            aHtml += '<div style=\"display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;\">';
            aHtml += '<div style=\"font-size:1.6rem; color:#3b82f6;\"><i class=\"fas fa-file-lines\"></i></div>';
            aHtml += '<span class=\"badge ' + (tr.available ? 'green' : (tr.status === 'PROCESSING' ? 'amber' : 'gray')) + '\">' + (tr.status || 'NOT AVAILABLE') + '</span>';
            aHtml += '</div>';
            aHtml += '<div style=\"font-weight:700; font-size:0.95rem; margin-bottom:4px;\">Google Meet Transcript</div>';
            aHtml += '<div style=\"font-size:0.78rem; color:var(--text-muted);\">Verbatim speech transcription document generated in Google Docs.</div>';
            if (isConsolidatedDoc) {
                aHtml += '<div style=\"font-size:0.73rem; color:#2563eb; background:#eff6ff; padding:4px 8px; border-radius:6px; margin-top:6px;\"><i class=\"fas fa-layer-group\"></i> Consolidated with Gemini Smart Notes in Google Docs</div>';
            }
            aHtml += '</div>';
            if (tr.available && tr.url) {
                aHtml += '<a href=\"' + tr.url + '\" target=\"_blank\" class=\"btn btn-sm btn-primary\" style=\"display:inline-flex; align-items:center; justify-content:center; gap:6px;\"><i class=\"fas fa-arrow-up-right-from-square\"></i> Open Transcript</a>';
            } else {
                aHtml += '<button type=\"button\" class=\"btn btn-sm btn-outline\" disabled style=\"opacity:0.6;\"><i class=\"fas fa-clock\"></i> ' + (tr.status === 'PROCESSING' ? 'Processing...' : 'Not Available Yet') + '</button>';
            }
            aHtml += '</div>';

            // Card 3: Gemini / Smart Notes
            aHtml += '<div style=\"background:var(--bg-hover,#f8fafc); border:1px solid var(--border); border-radius:12px; padding:18px; display:flex; flex-direction:column; justify-content:space-between; gap:14px;\">';
            aHtml += '<div>';
            aHtml += '<div style=\"display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;\">';
            aHtml += '<div style=\"font-size:1.6rem; color:#8b5cf6;\"><i class=\"fas fa-wand-magic-sparkles\"></i></div>';
            aHtml += '<span class=\"badge ' + (sn.available ? 'green' : (sn.status === 'PROCESSING' ? 'amber' : 'gray')) + '\">' + (sn.status || 'NOT AVAILABLE') + '</span>';
            aHtml += '</div>';
            aHtml += '<div style=\"font-weight:700; font-size:0.95rem; margin-bottom:4px;\">Gemini Smart Notes</div>';
            aHtml += '<div style=\"font-size:0.78rem; color:var(--text-muted);\">AI-generated executive summary, recap notes, and key takeaways document.</div>';
            if (isConsolidatedDoc) {
                aHtml += '<div style=\"font-size:0.73rem; color:#7c3aed; background:#f5f3ff; padding:4px 8px; border-radius:6px; margin-top:6px;\"><i class=\"fas fa-wand-magic-sparkles\"></i> Includes Executive Summary, Action Items & Full Transcript</div>';
            }
            aHtml += '</div>';
            if (sn.available && sn.url) {
                aHtml += '<a href=\"' + sn.url + '\" target=\"_blank\" class=\"btn btn-sm btn-primary\" style=\"display:inline-flex; align-items:center; justify-content:center; gap:6px; background:#8b5cf6; border-color:#8b5cf6;\"><i class=\"fas fa-wand-magic-sparkles\"></i> Open Gemini Notes</a>';
            } else {
                aHtml += '<button type=\"button\" class=\"btn btn-sm btn-outline\" disabled style=\"opacity:0.6;\"><i class=\"fas fa-clock\"></i> ' + (sn.status === 'PROCESSING' ? 'Generating Notes...' : 'Not Available Yet') + '</button>';
            }
            aHtml += '</div>';

            aHtml += '</div>';
            aHtml += '<div style=\"margin-top:16px; font-size:0.77rem; color:var(--text-muted); text-align:center;\"><i class=\"fas fa-shield-halved\"></i> Google Workspace Drive references are stored in ERP. No large video files are stored locally on Hostinger.</div>';

            artEl.innerHTML = aHtml;
        })
        .catch(function(err){
            overviewEl.innerHTML = '<div class=\"alert alert-error\">Failed to load session details.</div>';
        });
}
</script>";
include 'includes/admin_footer.php';
?>
