<?php
/**
 * PEPP Learning - automatic session reminder dispatcher.
 * Called lazily from includes/admin_nav.php on each admin page load (no server
 * cron needed on shared hosting). For every scheduled Live/Offline session it
 * sends the learner reminder for any window that is now due and not yet sent:
 *   12h  → 12-13 hours before start
 *   4h   → 4-5 hours before start
 *   10m  → within 15 minutes before start
 *   start→ from start time up to 20 minutes after
 * Each window is sent at most once (session_notifications has a unique key).
 */
function sessions_dispatch_due($pdo) {
    static $ran = false;
    if ($ran) return; $ran = true;   // once per request

    try {
        if (!$pdo->query("SHOW TABLES LIKE 'sessions'")->fetchColumn()) return;
    } catch (Exception $e) { return; }

    require_once __DIR__ . '/session_mailer.php';

    try {
        // Candidate sessions: scheduled, live/offline, within the next 14 hours
        // or just started (so we cover all four windows cheaply).
        $stmt = $pdo->query("
            SELECT s.*, f.name AS faculty_name,
                   TIMESTAMPDIFF(MINUTE, NOW(), s.session_datetime) AS mins_to_start
            FROM sessions s
            LEFT JOIN faculties f ON f.id = s.faculty_id
            WHERE s.status = 'scheduled'
              AND s.session_type IN ('live','offline')
              AND s.session_datetime BETWEEN DATE_SUB(NOW(), INTERVAL 20 MINUTE) AND DATE_ADD(NOW(), INTERVAL 13 HOUR)
        ");
        $rows = $stmt->fetchAll();
        if (!$rows) return;

        // Already-sent windows
        $sent = [];
        foreach ($pdo->query("SELECT session_id, window_key FROM session_notifications")->fetchAll() as $r) {
            $sent[$r['session_id'] . ':' . $r['window_key']] = true;
        }

        foreach ($rows as $s) {
            $m = (int)$s['mins_to_start'];
            $windows = [];
            if ($m <= 780 && $m > 240)      $windows[] = '12h';   // 13h..4h  → 12h notice
            if ($m <= 300 && $m > 15)       $windows[] = '4h';    // 5h..15m  → 4h notice
            if ($m <= 15  && $m > 0)        $windows[] = '10m';   // last 15m → 10m notice
            if ($m <= 0   && $m >= -20)     $windows[] = 'start'; // at/just after start
            foreach ($windows as $w) {
                if (isset($sent[$s['id'] . ':' . $w])) continue;
                notify_session_learners($pdo, $s, $w, 'auto');
            }
        }
    } catch (Exception $e) { error_log('sessions_dispatch_due: ' . $e->getMessage()); }
}
