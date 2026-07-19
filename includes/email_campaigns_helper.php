<?php
/**
 * PEPP Learning — Email Campaigns Helper.
 * Handles database tables self-healing, email template placeholders rendering,
 * queueing email items, and batch delivery via pure PHP mail().
 */

function check_and_create_email_campaign_tables($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `email_campaigns` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `subject` varchar(255) NOT NULL,
              `body` longtext NOT NULL,
              `target_courses` text NOT NULL,
              `scheduled_at` datetime DEFAULT NULL,
              `status` enum('scheduled','sending','sent','cancelled') NOT NULL DEFAULT 'scheduled',
              `created_by` varchar(100) NOT NULL,
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `email_queue` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `campaign_id` int(11) NOT NULL,
              `student_id` varchar(20) NOT NULL,
              `recipient_email` varchar(255) NOT NULL,
              `recipient_name` varchar(255) NOT NULL,
              `subject` varchar(255) NOT NULL,
              `body` longtext NOT NULL,
              `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
              `error_message` text DEFAULT NULL,
              `sent_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_eq_status` (`status`),
              KEY `idx_eq_campaign` (`campaign_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e) {
        error_log("Email campaign tables check/creation failed: " . $e->getMessage());
    }
}

function send_custom_email($to, $subject, $body_html, $body_text = '') {
    if (!$body_text) {
        $body_text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '<p>', '</p>'], ["\n", "\n", "\n", "\n", "\n"], $body_html));
    }
    
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $bAlt = 'alt_' . md5(uniqid('', true));
    $headers = "From: PEPP Learning <noreply@pepplearning.in>\r\n"
             . "Reply-To: noreply@pepplearning.in\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"{$bAlt}\"";
             
    $body  = "--{$bAlt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $body_text . "\r\n\r\n";
    $body .= "--{$bAlt}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $body_html . "\r\n\r\n";
    $body .= "--{$bAlt}--";
    
    try {
        return @mail($to, $subjectEnc, $body, $headers);
    } catch (Exception $e) {
        error_log('Custom campaign email send failed to ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

function build_campaign_email_html($custom_body) {
    // Branded wrapper matching PEPP invoice style
    $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f5f4;font-family:Segoe UI,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e7e5e4;">
  <tr><td style="background:#E8980C;padding:26px 32px;">
      <div style="font-size:24px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">pepp <span style="font-weight:400;font-size:13px;letter-spacing:3px;">LEARNING</span></div>
      <div style="font-size:12px;color:rgba(255,255,255,.85);margin-top:2px;">Labinc Education Pvt. Ltd.</div>
  </td></tr>
  <tr><td style="padding:32px 32px 28px; font-size:15px; color:#1f2937; line-height:1.6;">
      ' . nl2br($custom_body) . '
  </td></tr>
  <tr><td style="background:#1c1917;padding:16px 32px;text-align:center;">
      <div style="font-size:11px;color:#a8a29e;">&copy; ' . date('Y') . ' PEPP Learning &mdash; Labinc Education Pvt. Ltd. &middot; www.pepplearning.com</div>
  </td></tr>
</table>
</td></tr></table>
</body></html>';
    return $html;
}

function email_campaigns_send_due($pdo) {
    // Ensure tables exist
    check_and_create_email_campaign_tables($pdo);
    
    // Find scheduled campaigns ready to send
    try {
        $stmt = $pdo->prepare("SELECT * FROM email_campaigns WHERE status = 'scheduled' AND (scheduled_at IS NULL OR scheduled_at <= NOW())");
        $stmt->execute();
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($campaigns as $camp) {
            // Update status to locking 'sending' state
            $pdo->prepare("UPDATE email_campaigns SET status = 'sending' WHERE id = ?")->execute([$camp['id']]);
            
            // Resolve courses
            $courses = array_filter(array_map('trim', explode(',', $camp['target_courses'])));
            if (empty($courses)) {
                $pdo->prepare("UPDATE email_campaigns SET status = 'sent' WHERE id = ?")->execute([$camp['id']]);
                continue;
            }
            
            $placeholders = implode(',', array_fill(0, count($courses), '?'));
            $u_stmt = $pdo->prepare("SELECT user_id, name, email, pepp_course FROM users WHERE status = 'approved' AND pepp_course IN ($placeholders)");
            $u_stmt->execute($courses);
            $students = $u_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Queue individuals
            $search = ['{name}', '{email}', '{course}', '{user_id}'];
            $ins = $pdo->prepare("INSERT INTO email_queue (campaign_id, student_id, recipient_email, recipient_name, subject, body, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            
            foreach ($students as $s) {
                if (empty($s['email'])) continue;
                $replace = [$s['name'], $s['email'], $s['pepp_course'], $s['user_id']];
                $custom_subj = str_replace($search, $replace, $camp['subject']);
                $custom_body = str_replace($search, $replace, $camp['body']);
                
                $ins->execute([$camp['id'], $s['user_id'], $s['email'], $s['name'], $custom_subj, $custom_body]);
            }
            
            $pdo->prepare("UPDATE email_campaigns SET status = 'sent' WHERE id = ?")->execute([$camp['id']]);
        }
    } catch (Exception $e) {
        error_log('email_campaigns_send_due check error: ' . $e->getMessage());
    }
    
    // Process batch of pending emails in queue (limit to 10 per page load)
    try {
        $stmt = $pdo->prepare("SELECT * FROM email_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 10");
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as $item) {
            $html = build_campaign_email_html($item['body']);
            $ok = send_custom_email($item['recipient_email'], $item['subject'], $html);
            if ($ok) {
                $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$item['id']]);
            } else {
                $pdo->prepare("UPDATE email_queue SET status = 'failed', error_message = 'Failed to deliver via mail()' WHERE id = ?")->execute([$item['id']]);
            }
        }
    } catch (Exception $e) {
        error_log('email_campaigns_send_due queue process error: ' . $e->getMessage());
    }
}
