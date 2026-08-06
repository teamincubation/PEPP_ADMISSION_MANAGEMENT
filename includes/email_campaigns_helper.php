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
        
        // Self-healing columns check
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM email_campaigns LIKE 'target_forms'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE email_campaigns ADD COLUMN `target_forms` TEXT DEFAULT NULL AFTER `target_courses`");
            }
        } catch (Exception $e) {}
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM email_campaigns LIKE 'target_lists'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE email_campaigns ADD COLUMN `target_lists` TEXT DEFAULT NULL AFTER `target_forms`");
            }
        } catch (Exception $e) {}
        
        // Custom imported lists tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `email_campaign_lists` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `label` varchar(255) NOT NULL,
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `email_campaign_list_emails` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `list_id` int(11) NOT NULL,
              `name` varchar(255) DEFAULT NULL,
              `email` varchar(255) NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_ecle_list` (`list_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        // Custom email templates table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `email_campaign_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `template_name` varchar(255) NOT NULL,
              `subject` varchar(255) NOT NULL,
              `body` longtext NOT NULL,
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`)
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
      ' . $custom_body . '
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
            $courses = array_filter(array_map('trim', explode(',', $camp['target_courses'] ?? '')));
            $students = [];
            if (!empty($courses)) {
                $placeholders = implode(',', array_fill(0, count($courses), '?'));
                $u_stmt = $pdo->prepare("SELECT user_id, name, email, pepp_course FROM users WHERE status = 'approved' AND pepp_course IN ($placeholders)");
                $u_stmt->execute($courses);
                $students = $u_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Resolve forms
            $forms = array_filter(array_map('intval', explode(',', $camp['target_forms'] ?? '')));
            $form_users = [];
            if (!empty($forms)) {
                $placeholders_f = implode(',', array_fill(0, count($forms), '?'));
                $f_stmt = $pdo->prepare("
                    SELECT s.id as submission_id, s.respondent_identifier, s.form_id, f.title as form_title
                    FROM campaign_form_submissions s
                    JOIN campaign_forms f ON s.form_id = f.id
                    WHERE s.form_id IN ($placeholders_f) AND s.is_deleted = 0
                ");
                $f_stmt->execute($forms);
                $submissions = $f_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($submissions as $sub) {
                    $email = $sub['respondent_identifier'];
                    
                    // Fetch name from answers
                    $stmt_name = $pdo->prepare("
                        SELECT a.answer_text 
                        FROM campaign_form_answers a
                        JOIN campaign_form_fields f ON a.field_id = f.id
                        WHERE a.submission_id = ? AND (f.label LIKE '%name%' OR f.field_name LIKE '%name%')
                        ORDER BY f.sort_order ASC
                        LIMIT 1
                    ");
                    $stmt_name->execute([$sub['submission_id']]);
                    $name = $stmt_name->fetchColumn();
                    
                    // Fetch email if respondent_identifier is not email
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
                        $ans_email = $stmt_email->fetchColumn();
                        if ($ans_email) {
                            $email = $ans_email;
                        }
                    }
                    
                    if (!empty($email) && strpos($email, '@') !== false) {
                        $form_users[] = [
                            'user_id' => 'form_' . $sub['submission_id'],
                            'name' => $name ?: 'Form Registrant',
                            'email' => $email,
                            'pepp_course' => $sub['form_title']
                        ];
                    }
                }
            }
            
            
            // Resolve custom lists
            $lists = array_filter(array_map('intval', explode(',', $camp['target_lists'] ?? '')));
            $list_users = [];
            if (!empty($lists)) {
                $placeholders_l = implode(',', array_fill(0, count($lists), '?'));
                $l_stmt = $pdo->prepare("
                    SELECT le.name, le.email, l.label as list_title
                    FROM email_campaign_list_emails le
                    JOIN email_campaign_lists l ON le.list_id = l.id
                    WHERE le.list_id IN ($placeholders_l)
                ");
                $l_stmt->execute($lists);
                $list_emails = $l_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($list_emails as $le) {
                    if (!empty($le['email']) && strpos($le['email'], '@') !== false) {
                        $list_users[] = [
                            'user_id' => 'list_custom',
                            'name' => $le['name'] ?: 'Recipient',
                            'email' => $le['email'],
                            'pepp_course' => $le['list_title']
                        ];
                    }
                }
            }
            
            // Merge uniquely by email address
            $recipients = [];
            $seen_emails = [];
            foreach ($students as $s) {
                if (empty($s['email'])) continue;
                $lowercase_email = strtolower(trim($s['email']));
                if (!isset($seen_emails[$lowercase_email])) {
                    $seen_emails[$lowercase_email] = true;
                    $recipients[] = [
                        'user_id' => $s['user_id'],
                        'name' => $s['name'],
                        'email' => $s['email'],
                        'pepp_course' => $s['pepp_course']
                    ];
                }
            }
            foreach ($form_users as $fu) {
                $lowercase_email = strtolower(trim($fu['email']));
                if (!isset($seen_emails[$lowercase_email])) {
                    $seen_emails[$lowercase_email] = true;
                    $recipients[] = $fu;
                }
            }
            foreach ($list_users as $lu) {
                $lowercase_email = strtolower(trim($lu['email']));
                if (!isset($seen_emails[$lowercase_email])) {
                    $seen_emails[$lowercase_email] = true;
                    $recipients[] = $lu;
                }
            }
            
            if (empty($recipients)) {
                $pdo->prepare("UPDATE email_campaigns SET status = 'sent' WHERE id = ?")->execute([$camp['id']]);
                continue;
            }
            
            // Queue individuals
            $search = ['{name}', '{email}', '{course}', '{user_id}'];
            $ins = $pdo->prepare("INSERT INTO email_queue (campaign_id, student_id, recipient_email, recipient_name, subject, body, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            
            foreach ($recipients as $r) {
                $replace = [$r['name'], $r['email'], $r['pepp_course'], $r['user_id']];
                $custom_subj = str_replace($search, $replace, $camp['subject']);
                $custom_body = str_replace($search, $replace, $camp['body']);
                
                $ins->execute([$camp['id'], $r['user_id'], $r['email'], $r['name'], $custom_subj, $custom_body]);
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
