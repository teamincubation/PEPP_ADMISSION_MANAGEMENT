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

/** Automatic installment payment reminder dispatcher */
function installments_dispatch_reminders($pdo) {
    static $ran = false;
    if ($ran) return; $ran = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `installment_reminders_sent` (
                `installment_id` INT NOT NULL,
                `window_key` VARCHAR(15) NOT NULL,
                `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`installment_id`, `window_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) { return; }

    try {
        // Fetch pending installments whose due date falls in our reminder windows:
        // 10 days, 3 days, today (0 days), or overdue by 1 day (-1 days).
        $stmt = $pdo->query("
            SELECT i.id, i.user_id, i.instalment_number, i.amount, i.due_date,
                   u.name AS student_name, u.email AS student_email, u.pepp_course
            FROM instalment_details i
            INNER JOIN users u ON u.user_id = i.user_id
            WHERE i.status NOT IN ('approved', 'paid')
              AND i.paid_date IS NULL
              AND u.status = 'approved'
              AND i.due_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 2 DAY) AND DATE_ADD(CURDATE(), INTERVAL 11 DAY)
        ");
        $installments = $stmt->fetchAll();
        if (!$installments) return;

        $sent = [];
        $stmt = $pdo->query("SELECT installment_id, window_key FROM installment_reminders_sent");
        foreach ($stmt->fetchAll() as $s) {
            $sent[$s['installment_id'] . ':' . $s['window_key']] = true;
        }

        foreach ($installments as $inst) {
            $due_time = strtotime($inst['due_date']);
            $today_time = strtotime(date('Y-m-d'));
            $days_diff = (int)round(($due_time - $today_time) / 86400);

            $window = '';
            if ($days_diff === 10) {
                $window = '10d';
            } elseif ($days_diff === 3) {
                $window = '3d';
            } elseif ($days_diff === 0) {
                $window = '0d';
            } elseif ($days_diff === -1) {
                $window = 'overdue';
            }

            if ($window !== '' && !isset($sent[$inst['id'] . ':' . $window])) {
                $ok = send_installment_reminder_email($pdo, $inst, $window);
                if ($ok) {
                    $stmt = $pdo->prepare("INSERT INTO installment_reminders_sent (installment_id, window_key, sent_at) VALUES (?, ?, NOW())");
                    $stmt->execute([$inst['id'], $window]);
                }
            }
        }
    } catch (Exception $e) {
        error_log('installments_dispatch_reminders error: ' . $e->getMessage());
    }
}

function send_installment_reminder_email($pdo, $inst, $window) {
    if (!file_exists(__DIR__ . '/peppian_notify.php')) return false;
    require_once __DIR__ . '/peppian_notify.php';

    $to_email = $inst['student_email'];
    if (!$to_email || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) return false;

    $student_name = $inst['student_name'];
    $course = $inst['pepp_course'];
    $inst_num = $inst['instalment_number'];
    $amount = number_format((float)$inst['amount'], 2);
    $due_date = date('d M Y', strtotime($inst['due_date']));
    $pay_link = "https://pepplearning.in/admissions/installmentpayment.php?user_id=" . urlencode($inst['user_id']);

    $subject = '';
    $heading = '';
    $message_html = '';

    switch ($window) {
        case '10d':
            $subject = "Upcoming Installment Payment Reminder - 10 Days Left";
            $heading = "Installment #{$inst_num} Due in 10 Days";
            $message_html = "<p>Dear {$student_name},</p>
                             <p>This is a friendly reminder that your upcoming installment <strong>#{$inst_num}</strong> for the course <strong>{$course}</strong> is due on <strong>{$due_date}</strong>.</p>
                             <div style='background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin:20px 0;'>
                                 <table style='width:100%; border-collapse:collapse; font-size:14px;'>
                                     <tr><td style='padding:6px 0; color:#64748b;'>Installment Number:</td><td style='padding:6px 0; font-weight:700;'>#{$inst_num}</td></tr>
                                     <tr><td style='padding:6px 0; color:#64748b;'>Amount Due:</td><td style='padding:6px 0; font-weight:700; color:#0f172a;'>₹{$amount}</td></tr>
                                     <tr><td style='padding:6px 0; color:#64748b;'>Due Date:</td><td style='padding:6px 0; font-weight:700; color:#0f172a;'>{$due_date}</td></tr>
                                 </table>
                             </div>
                             <p>Please click the button below to update your payment details and upload your receipt on the portal:</p>
                             <div style='margin:24px 0; text-align:center;'>
                                 <a href='{$pay_link}' target='_blank' style='background:#E8980C; color:#fff; padding:12px 24px; border-radius:50px; text-decoration:none; font-weight:700; display:inline-block; box-shadow:0 4px 12px rgba(232,152,12,0.2);'>Update Payment Details</a>
                             </div>";
            break;

        case '3d':
            $subject = "Action Required: Installment Payment Due in 3 Days";
            $heading = "Installment #{$inst_num} Due in 3 Days";
            $message_html = "<p>Dear {$student_name},</p>
                             <p>This is an important reminder that your installment <strong>#{$inst_num}</strong> for the course <strong>{$course}</strong> is due in 3 days, on <strong>{$due_date}</strong>.</p>
                             <div style='background:#fef3c7; border:1px solid #fde68a; border-radius:12px; padding:16px; margin:20px 0;'>
                                 <table style='width:100%; border-collapse:collapse; font-size:14px;'>
                                     <tr><td style='padding:6px 0; color:#78350f;'>Installment Number:</td><td style='padding:6px 0; font-weight:700;'>#{$inst_num}</td></tr>
                                     <tr><td style='padding:6px 0; color:#78350f;'>Amount Due:</td><td style='padding:6px 0; font-weight:700; color:#78350f;'>₹{$amount}</td></tr>
                                     <tr><td style='padding:6px 0; color:#78350f;'>Due Date:</td><td style='padding:6px 0; font-weight:700; color:#78350f;'>{$due_date}</td></tr>
                                 </table>
                             </div>
                             <p>To avoid any disruption to your course access, please pay the installment and upload your payment proof on the portal immediately:</p>
                             <div style='margin:24px 0; text-align:center;'>
                                 <a href='{$pay_link}' target='_blank' style='background:#e11d48; color:#fff; padding:12px 24px; border-radius:50px; text-decoration:none; font-weight:700; display:inline-block; box-shadow:0 4px 12px rgba(225,29,72,0.2);'>Pay &amp; Upload Receipt</a>
                             </div>";
            break;

        case '0d':
            $subject = "Urgent: Installment Payment Due Today";
            $heading = "Installment #{$inst_num} is Due Today!";
            $message_html = "<p>Dear {$student_name},</p>
                             <p>Your installment <strong>#{$inst_num}</strong> for the course <strong>{$course}</strong> is due <strong>TODAY ({$due_date})</strong>.</p>
                             <div style='background:#fee2e2; border:1px solid #fca5a5; border-radius:12px; padding:16px; margin:20px 0;'>
                                 <table style='width:100%; border-collapse:collapse; font-size:14px;'>
                                     <tr><td style='padding:6px 0; color:#991b1b;'>Installment Number:</td><td style='padding:6px 0; font-weight:700;'>#{$inst_num}</td></tr>
                                     <tr><td style='padding:6px 0; color:#991b1b;'>Amount Due:</td><td style='padding:6px 0; font-weight:700; color:#991b1b;'>₹{$amount}</td></tr>
                                     <tr><td style='padding:6px 0; color:#991b1b;'>Due Date:</td><td style='padding:6px 0; font-weight:700; color:#991b1b;'>Today ({$due_date})</td></tr>
                                 </table>
                             </div>
                             <p>Please settle the payment immediately and submit the receipt to ensure your study materials and session access remain active:</p>
                             <div style='margin:24px 0; text-align:center;'>
                                 <a href='{$pay_link}' target='_blank' style='background:#dc2626; color:#fff; padding:12px 24px; border-radius:50px; text-decoration:none; font-weight:700; display:inline-block; box-shadow:0 4px 12px rgba(220,38,38,0.25);'>Pay Now &amp; Submit Proof</a>
                             </div>";
            break;

        case 'overdue':
            $subject = "Urgent: Course Access Suspended (Overdue Installment)";
            $heading = "Your Course Access Has Been Suspended";
            $message_html = "<p>Dear {$student_name},</p>
                             <p>We regret to inform you that your course access for <strong>{$course}</strong> has been suspended because installment <strong>#{$inst_num}</strong> (₹{$amount}) is overdue.</p>
                             <div style='background:#fef2f2; border:1px solid #fee2e2; border-radius:12px; padding:16px; margin:20px 0;'>
                                 <p style='margin:0; color:#b91c1c; font-weight:700;'>Course Access Status: Suspended</p>
                                 <p style='margin:6px 0 0 0; font-size:0.82rem; color:#7f1d1d;'>Access will be restored automatically once the payment proof is verified by our accounts desk.</p>
                             </div>
                             <p>To renew your access and resume your classes, please complete the pending payment and upload the transaction screenshot here:</p>
                             <div style='margin:24px 0; text-align:center;'>
                                 <a href='{$pay_link}' target='_blank' style='background:#0f172a; color:#fff; padding:12px 24px; border-radius:50px; text-decoration:none; font-weight:700; display:inline-block; box-shadow:0 4px 12px rgba(15,23,42,0.3);'>Renew Course Access</a>
                             </div>";
            
            // Suspend course access in database
            try {
                $stmt = $pdo->prepare("UPDATE users SET student_status = 'suspended', course_status = 'suspended' WHERE user_id = ?");
                $stmt->execute([$inst['user_id']]);
            } catch (Exception $ex) {}
            break;
    }

    if ($subject && $heading && $message_html) {
        return peppian_send_email_general($to_email, $subject, $heading, $message_html, false);
    }
    return false;
}

/** Automatic installment payment reminder dispatcher via WhatsApp */
function installments_dispatch_whatsapp_reminders($pdo) {
    static $ran = false;
    if ($ran) return; $ran = true;

    // Global outbound mode guard: automated WhatsApp reminders only run in META API mode
    $waMode = 'manual';
    if (function_exists('whatsapp_outbound_mode')) {
        $waMode = whatsapp_outbound_mode($pdo);
    } else {
        try {
            $mStmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_outbound_mode' LIMIT 1");
            $mStmt->execute();
            $waMode = $mStmt->fetchColumn() ?: 'manual';
        } catch (Exception $e) {}
    }
    if ($waMode !== 'meta_api') {
        return; // Automated WhatsApp reminders only dispatch in META API mode
    }

    // 1) Verify current time is between 08:00 AM and 09:00 AM IST
    $tz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $hour = (int)$now->format('H');
    if ($hour !== 8 && !defined('FORCE_INSTALLMENT_REMINDER_TEST')) {
        return; // Only dispatch during the 08:00-09:00 AM IST window
    }

    try {
        $eligible = get_eligible_whatsapp_reminders($pdo);
        if (empty($eligible)) return;

        // Fetch active public banking details
        $public_banking_details = '';
        try {
            $public_accs = $pdo->query("SELECT account_name, banking_details FROM payment_accounts WHERE is_public = 1 AND status = 'active' LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
            $details_arr = [];
            foreach ($public_accs as $pa) {
                $details_arr[] = $pa['account_name'] . ($pa['banking_details'] ? " (" . $pa['banking_details'] . ")" : "");
            }
            $public_banking_details = implode(" or ", $details_arr);
        } catch (Exception $e) {}

        require_once __DIR__ . '/communication/CommunicationEngine.php';
        $commEngine = CommunicationEngine::getInstance($pdo);

        foreach ($eligible as $inst) {
            if ($inst['is_overdue']) {
                continue; // Only process upcoming reminders in this function
            }
            $stage = $inst['stage'];

            try {
                $pdo->beginTransaction();

                // Check existing tracking status to prevent race conditions
                $stmtCheck = $pdo->prepare("SELECT status FROM installment_whatsapp_reminders WHERE installment_id = ? AND reminder_stage = ?");
                $stmtCheck->execute([$inst['installment_id'], $stage]);
                $existingStatus = $stmtCheck->fetchColumn();

                if ($existingStatus === 'sent' || $existingStatus === 'queued') {
                    $pdo->rollBack();
                    continue; // Skip already successfully queued or sent reminders
                }

                // If doesn't exist, insert as 'queued'. If 'failed', update to 'queued'.
                if ($existingStatus === false) {
                    $stmtTrack = $pdo->prepare("INSERT INTO installment_whatsapp_reminders (installment_id, reminder_stage, status, last_attempted_at) VALUES (?, ?, 'queued', NOW())");
                    $stmtTrack->execute([$inst['installment_id'], $stage]);
                } else {
                    $stmtTrack = $pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'queued', last_attempted_at = NOW() WHERE installment_id = ? AND reminder_stage = ? AND status NOT IN ('queued', 'sent')");
                    $stmtTrack->execute([$inst['installment_id'], $stage]);
                    if ($stmtTrack->rowCount() === 0) {
                        $pdo->rollBack();
                        continue;
                    }
                }

                // Normalize recipient phone number
                $wa_phone = preg_replace('/\D/', '', $inst['whatsapp_country_code'] . $inst['whatsapp_number']);
                if (empty($wa_phone)) {
                    $pdo->rollBack();
                    continue;
                }

                $ord = (int)$inst['instalment_number'];
                if ($ord === 1) $ordStr = "1st";
                elseif ($ord === 2) $ordStr = "2nd";
                elseif ($ord === 3) $ordStr = "3rd";
                else $ordStr = $ord . "th";

                $context = [
                    'student_name' => $inst['student_name'] ?? '',
                    'course_name' => $inst['pepp_course'] ?? '',
                    'academic_year' => $inst['pepp_academic_year'] ?? '',
                    'installment_number' => $ordStr,
                    'installment_amount' => number_format((float)$inst['amount']),
                    'installment_due_date' => date('d M Y', strtotime($inst['due_date'])),
                    'banking_details' => $public_banking_details
                ];

                $queueId = $commEngine->sendEventNotification(
                    'installment_reminder',
                    $wa_phone,
                    $context,
                    'system_scheduler'
                );

                if ($queueId) {
                    // Update tracking row with queueId
                    $stmtUpd = $pdo->prepare("UPDATE installment_whatsapp_reminders SET queue_id = ? WHERE installment_id = ? AND reminder_stage = ?");
                    $stmtUpd->execute([$queueId, $inst['installment_id'], $stage]);
                    $pdo->commit();
                } else {
                    $pdo->rollBack();
                }

            } catch (Exception $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Failed to queue installment reminder for {$inst['installment_id']}: " . $ex->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log('installments_dispatch_whatsapp_reminders error: ' . $e->getMessage());
    }
}

/** Automatic installment payment overdue reminder dispatcher via WhatsApp */
function installments_dispatch_whatsapp_overdue_reminders($pdo) {
    static $ran = false;
    if ($ran) return; $ran = true;

    // Global outbound mode guard: automated WhatsApp reminders only run in META API mode
    $waMode = 'manual';
    if (function_exists('whatsapp_outbound_mode')) {
        $waMode = whatsapp_outbound_mode($pdo);
    } else {
        try {
            $mStmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_outbound_mode' LIMIT 1");
            $mStmt->execute();
            $waMode = $mStmt->fetchColumn() ?: 'manual';
        } catch (Exception $e) {}
    }
    if ($waMode !== 'meta_api') {
        return;
    }

    // 1) Verify current time is between 08:00 AM and 09:00 AM IST
    $tz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $hour = (int)$now->format('H');
    if ($hour !== 8 && !defined('FORCE_INSTALLMENT_REMINDER_TEST')) {
        return; // Only dispatch during the 08:00-09:00 AM IST window
    }

    try {
        $eligible = get_eligible_whatsapp_reminders($pdo);
        if (empty($eligible)) return;

        require_once __DIR__ . '/communication/CommunicationEngine.php';
        $commEngine = CommunicationEngine::getInstance($pdo);

        foreach ($eligible as $inst) {
            if (!$inst['is_overdue']) {
                continue; // Only process overdue reminders in this function
            }
            $stage = $inst['stage'];

            try {
                $pdo->beginTransaction();

                // Check existing tracking status
                $stmtCheck = $pdo->prepare("SELECT status FROM installment_whatsapp_reminders WHERE installment_id = ? AND reminder_stage = ?");
                $stmtCheck->execute([$inst['installment_id'], $stage]);
                $existingStatus = $stmtCheck->fetchColumn();

                if ($existingStatus === 'sent' || $existingStatus === 'queued') {
                    $pdo->rollBack();
                    continue; // Skip already successfully queued or sent reminders
                }

                // If doesn't exist, insert as 'queued'. If 'failed', update to 'queued'.
                if ($existingStatus === false) {
                    $stmtTrack = $pdo->prepare("INSERT INTO installment_whatsapp_reminders (installment_id, reminder_stage, status, last_attempted_at) VALUES (?, ?, 'queued', NOW())");
                    $stmtTrack->execute([$inst['installment_id'], $stage]);
                } else {
                    $stmtTrack = $pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'queued', last_attempted_at = NOW() WHERE installment_id = ? AND reminder_stage = ? AND status NOT IN ('queued', 'sent')");
                    $stmtTrack->execute([$inst['installment_id'], $stage]);
                    if ($stmtTrack->rowCount() === 0) {
                        $pdo->rollBack();
                        continue;
                    }
                }

                // Normalize recipient phone number
                $wa_phone = preg_replace('/\D/', '', $inst['whatsapp_country_code'] . $inst['whatsapp_number']);
                if (empty($wa_phone)) {
                    $pdo->rollBack();
                    continue;
                }

                $ord = (int)$inst['instalment_number'];
                if ($ord === 1) $ordStr = "1st";
                elseif ($ord === 2) $ordStr = "2nd";
                elseif ($ord === 3) $ordStr = "3rd";
                else $ordStr = $ord . "th";

                $context = [
                    'student_name' => $inst['student_name'] ?? '',
                    'course_name' => $inst['pepp_course'] ?? '',
                    'academic_year' => $inst['pepp_academic_year'] ?? '',
                    'installment_number' => $ordStr,
                    'installment_amount' => number_format((float)$inst['amount']),
                    'installment_due_date' => date('d M Y', strtotime($inst['due_date']))
                ];

                // Trigger installment_overdue notification event (student_uid is omitted from context so engine duplicate check is bypassed; stage-based idempotency is handled here)
                $queueId = $commEngine->sendEventNotification(
                    'installment_overdue',
                    $wa_phone,
                    $context,
                    'system_scheduler'
                );

                if ($queueId) {
                    // Update tracking row with queueId
                    $stmtUpd = $pdo->prepare("UPDATE installment_whatsapp_reminders SET queue_id = ? WHERE installment_id = ? AND reminder_stage = ?");
                    $stmtUpd->execute([$queueId, $inst['installment_id'], $stage]);
                    $pdo->commit();
                } else {
                    $pdo->rollBack();
                }

            } catch (Exception $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Failed to queue installment overdue reminder for {$inst['installment_id']}: " . $ex->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log('installments_dispatch_whatsapp_overdue_reminders error: ' . $e->getMessage());
    }
}

/**
 * Consolidates eligibility logic for both upcoming and overdue installment reminders.
 * Returns an array of eligible installments with target stages.
 *
 * @param PDO $pdo
 * @return array
 */
function get_eligible_whatsapp_reminders($pdo) {
    $tz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $kolkataDate = $now->format('Y-m-d');

    // Milestone target dates relative to Kolkata current date
    $date3d = date('Y-m-d', strtotime('+3 days', strtotime($kolkataDate)));
    $date7d = date('Y-m-d', strtotime('+7 days', strtotime($kolkataDate)));
    $overdue3d = date('Y-m-d', strtotime('-3 days', strtotime($kolkataDate)));
    $overdue7d = date('Y-m-d', strtotime('-7 days', strtotime($kolkataDate)));

    try {
        // Query installments due exactly on target dates
        $stmt = $pdo->prepare("
            SELECT i.id, i.user_id, i.instalment_number, i.amount, i.due_date,
                   u.name AS student_name, u.pepp_course, u.pepp_academic_year,
                   u.whatsapp_country_code, u.whatsapp_number, u.student_status
            FROM instalment_details i
            INNER JOIN users u ON u.user_id = i.user_id
            WHERE i.status NOT IN ('approved', 'paid')
              AND i.paid_date IS NULL
              AND u.status = 'approved'
              AND i.due_date IN (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$kolkataDate, $date3d, $date7d, $overdue3d, $overdue7d]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return [];
        }

        // Fetch existing tracking statuses for idempotency check
        $instIds = array_column($rows, 'id');
        $inClause = implode(',', array_fill(0, count($instIds), '?'));
        $trackStmt = $pdo->prepare("SELECT installment_id, reminder_stage, status FROM installment_whatsapp_reminders WHERE installment_id IN ($inClause)");
        $trackStmt->execute($instIds);

        $tracking = [];
        foreach ($trackStmt->fetchAll(PDO::FETCH_ASSOC) as $track) {
            $tracking[$track['installment_id']][$track['reminder_stage']] = $track['status'];
        }

        $eligible = [];
        foreach ($rows as $r) {
            $due_time = strtotime($r['due_date']);
            $today_time = strtotime($kolkataDate);
            $days_diff = (int)round(($due_time - $today_time) / 86400);

            $stage = '';
            $is_overdue = false;

            if ($days_diff === 7) {
                $stage = '7d';
            } elseif ($days_diff === 3) {
                $stage = '3d';
            } elseif ($days_diff === 0) {
                $stage = '0d';
            } elseif ($days_diff === -3) {
                $stage = 'overdue_3d';
                $is_overdue = true;
            } elseif ($days_diff === -7) {
                $stage = 'overdue_7d';
                $is_overdue = true;
            }

            if ($stage === '') {
                continue;
            }

            // Dropout safety constraint for overdue milestones
            if ($is_overdue && $r['student_status'] === 'dropout') {
                continue;
            }

            // Check tracking to prevent duplicate dispatch
            $existingStatus = $tracking[$r['id']][$stage] ?? null;
            if ($existingStatus === 'sent' || $existingStatus === 'queued') {
                continue;
            }

            $eligible[] = [
                'installment_id'        => $r['id'],
                'user_id'               => $r['user_id'],
                'instalment_number'     => $r['instalment_number'],
                'amount'                => $r['amount'],
                'due_date'              => $r['due_date'],
                'student_name'          => $r['student_name'],
                'pepp_course'           => $r['pepp_course'],
                'pepp_academic_year'    => $r['pepp_academic_year'],
                'whatsapp_country_code' => $r['whatsapp_country_code'],
                'whatsapp_number'       => $r['whatsapp_number'],
                'stage'                 => $stage,
                'is_overdue'            => $is_overdue
            ];
        }

        return $eligible;

    } catch (Exception $e) {
        error_log('get_eligible_whatsapp_reminders error: ' . $e->getMessage());
        return [];
    }
}

