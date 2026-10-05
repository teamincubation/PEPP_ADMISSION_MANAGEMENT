<?php
/**
 * PEPP Learning ERP - Bulk Communication Campaigns Page (Phase 2).
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/communication/WhatsAppAccountResolver.php';
require_once 'includes/communication/CampaignConfig.php';
require_permission('communication');

$active_page = 'communication';
$page_title  = 'Bulk Communication Campaigns';
$page_sub    = 'Broadcast WhatsApp alerts to segmented student and lead lists';

$success_message = '';
$error_message   = '';

// Standard phone cleaning matching PEPP WABA format
function clean_wa_phone($num) {
    $num = preg_replace('/\D/', '', $num);
    if (strlen($num) === 10) {
        $num = '91' . $num;
    }
    return $num;
}

/* ─────────────────────────────────────────────────────────────────────────────
   AJAX APIs
   ───────────────────────────────────────────────────────────────────────────── */
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // 0. Download Campaign Report (CSV/Excel)
    if ($action === 'download_report') {
        $id = (int)$_GET['campaign_id'];
        $format = $_GET['format'] ?? 'csv'; // csv | excel
        
        $stmt = $pdo->prepare("SELECT * FROM communication_campaigns WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $camp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$camp) {
            echo "Campaign not found.";
            exit;
        }

        $stmtRec = $pdo->prepare("
            SELECT r.*, 
                   q.status as queue_status, 
                   q.error_message as queue_error, 
                   q.delivered_at, 
                   q.message_id, 
                   q.retry_count, 
                   q.last_retry_at, 
                   wm.read_at 
            FROM communication_campaign_recipients r
            LEFT JOIN communication_queue q ON r.queue_id = q.id
            LEFT JOIN whatsapp_messages wm ON q.message_id = wm.wa_message_id
            WHERE r.campaign_id = ? 
            ORDER BY r.id ASC
        ");
        $stmtRec->execute([$id]);
        $recipients = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

        $filename = "campaign_report_" . $id . "_" . date('Ymd_His');

        if ($format === 'excel') {
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
            header('Cache-Control: max-age=0');
            
            echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
            echo '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>';
            echo '<body>';
            echo '<table border="1">';
            echo '<tr style="background:#f1f5f9; font-weight:bold;">';
            echo '<th>Campaign ID</th><th>Campaign Name</th><th>Template Name</th><th>Target Audience</th>';
            echo '<th>Recipient Name</th><th>WhatsApp Phone</th><th>Queue ID</th><th>Queue Status</th>';
            echo '<th>Meta Message ID</th><th>Sent Time</th><th>Delivery Status</th><th>Delivery Time</th><th>Read Time</th>';
            echo '<th>Failure Reason</th><th>Retry Count</th>';
            echo '</tr>';
            foreach ($recipients as $r) {
                $status = $r['queue_status'] ?: $r['status'] ?: 'pending';
                echo '<tr>';
                echo '<td>' . htmlspecialchars($camp['id']) . '</td>';
                echo '<td>' . htmlspecialchars($camp['name']) . '</td>';
                echo '<td>' . htmlspecialchars($camp['template_name']) . '</td>';
                echo '<td>' . htmlspecialchars(strtoupper($camp['target_audience'])) . '</td>';
                echo '<td>' . htmlspecialchars($r['recipient_name']) . '</td>';
                echo '<td>' . htmlspecialchars($r['recipient']) . '</td>';
                echo '<td>' . htmlspecialchars($r['queue_id'] ?: '-') . '</td>';
                echo '<td>' . htmlspecialchars(strtoupper($status)) . '</td>';
                echo '<td>' . htmlspecialchars($r['message_id'] ?: '-') . '</td>';
                echo '<td>' . htmlspecialchars($r['sent_at'] ?: '-') . '</td>';
                echo '<td>' . htmlspecialchars(strtoupper($status)) . '</td>';
                echo '<td>' . htmlspecialchars($r['delivered_at'] ?: '-') . '</td>';
                echo '<td>' . htmlspecialchars($r['read_at'] ?: '-') . '</td>';
                echo '<td>' . htmlspecialchars($r['error_message'] ?: $r['queue_error'] ?: '-') . '</td>';
                echo '<td>' . htmlspecialchars($r['retry_count'] !== null ? $r['retry_count'] : 0) . '</td>';
                echo '</tr>';
            }
            echo '</table></body></html>';
        } else {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            header('Cache-Control: max-age=0');
            
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            
            fputcsv($out, [
                'Campaign ID', 'Campaign Name', 'Template Name', 'Target Audience',
                'Recipient Name', 'WhatsApp Phone', 'Queue ID', 'Queue Status',
                'Meta Message ID', 'Sent Time', 'Delivery Status', 'Delivery Time', 'Read Time',
                'Failure Reason', 'Retry Count'
            ]);
            
            foreach ($recipients as $r) {
                $status = $r['queue_status'] ?: $r['status'] ?: 'pending';
                fputcsv($out, [
                    $camp['id'],
                    $camp['name'],
                    $camp['template_name'],
                    strtoupper($camp['target_audience']),
                    $r['recipient_name'],
                    $r['recipient'],
                    $r['queue_id'] ?: '-',
                    strtoupper($status),
                    $r['message_id'] ?: '-',
                    $r['sent_at'] ?: '-',
                    strtoupper($status),
                    $r['delivered_at'] ?: '-',
                    $r['read_at'] ?: '-',
                    $r['error_message'] ?: $r['queue_error'] ?: '-',
                    $r['retry_count'] !== null ? $r['retry_count'] : 0
                ]);
            }
            fclose($out);
        }
        exit;
    }

    header('Content-Type: application/json');

    // 1. Fetch Template Metadata Details
    if ($action === 'ajax_get_template') {
        $name = $_GET['template_name'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? AND status='approved' LIMIT 1");
        $stmt->execute([$name]);
        $tpl = $stmt->fetch();
        if ($tpl) {
            $meta = json_decode($tpl['meta_data'], true) ?: [];
            echo json_encode(['success' => true, 'template' => $tpl, 'meta' => $meta]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Template not found or not approved.']);
        }
        exit;
    }

    // 2. AJAX Recipient Preview Calculator
    if ($action === 'ajax_preview_audience') {
        $courses = $_POST['courses'] ?? [];
        $statuses = $_POST['statuses'] ?? [];

        if (empty($courses) || empty($statuses)) {
            echo json_encode([
                'success' => true,
                'total_matching' => 0,
                'eligible_count' => 0,
                'duplicates' => 0,
                'opted_out' => 0,
                'invalid' => 0,
                'recipients' => []
            ]);
            exit;
        }

        // Build target segments queries
        $where = [];
        $params = [];

        $coursePlaceholders = implode(',', array_fill(0, count($courses), '?'));
        $where[] = "interested_course IN ($coursePlaceholders)";
        $params = array_merge($params, $courses);

        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "status IN ($statusPlaceholders)";
        $params = array_merge($params, $statuses);

        $where_sql = implode(' AND ', $where);
        $stmt = $pdo->prepare("SELECT id, name, whatsapp_number, interested_course, status, is_opted_out, assigned_to FROM leads WHERE $where_sql ORDER BY id ASC");
        $stmt->execute($params);
        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalMatching = count($leads);
        $eligibleList = [];
        $duplicates = 0;
        $optedOut = 0;
        $invalid = 0;
        $seenPhones = [];

        foreach ($leads as $l) {
            if ((int)$l['is_opted_out'] === 1) {
                $optedOut++;
                continue;
            }

            $phone = clean_wa_phone($l['whatsapp_number']);
            if (empty($phone) || strlen($phone) < 10) {
                $invalid++;
                continue;
            }

            if (in_array($phone, $seenPhones, true)) {
                $duplicates++;
                continue;
            }

            $seenPhones[] = $phone;
            $eligibleList[] = [
                'id' => $l['id'],
                'name' => $l['name'] ?: 'Unknown',
                'phone' => $phone,
                'raw_phone' => $l['whatsapp_number'],
                'course' => $l['interested_course'] ?: 'None',
                'status' => ucfirst($l['status']),
                'assigned' => $l['assigned_to'] ?: '-'
            ];
        }

        $est = CampaignConfig::estimateProcessing($pdo, count($eligibleList));

        echo json_encode([
            'success' => true,
            'total_matching' => $totalMatching,
            'eligible_count' => count($eligibleList),
            'duplicates' => $duplicates,
            'opted_out' => $optedOut,
            'invalid' => $invalid,
            'estimated_time' => $est['label'],
            'estimated_details' => $est,
            'recipients' => array_slice($eligibleList, 0, 100) // Limit list size returned to client
        ]);
        exit;
    }

    // 3. Drilldown Details Panel
    if ($action === 'ajax_campaign_details') {
        $id = (int)$_GET['campaign_id'];
        $stmt = $pdo->prepare("SELECT * FROM communication_campaigns WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $camp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$camp) {
            echo json_encode(['success' => false, 'message' => 'Campaign not found.']);
            exit;
        }

        try {
            $stmtRec = $pdo->prepare("
                SELECT r.*, 
                       q.status as queue_status, 
                       q.error_message as queue_error, 
                       q.delivered_at, 
                       q.message_id, 
                       q.retry_count, 
                       q.last_retry_at, 
                       wm.read_at 
                FROM communication_campaign_recipients r
                LEFT JOIN communication_queue q ON r.queue_id = q.id
                LEFT JOIN whatsapp_messages wm ON q.message_id = wm.wa_message_id
                WHERE r.campaign_id = ? 
                ORDER BY r.id ASC
            ");
            $stmtRec->execute([$id]);
            $recipients = $stmtRec->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'campaign' => $camp,
            'recipients' => $recipients
        ]);
        exit;
    }

    // 4. AJAX Control Actions: Pause, Resume, Cancel, Retry Failed
    if ($action === 'ajax_campaign_control') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
            exit;
        }

        $id = (int)$_POST['campaign_id'];
        $act = $_POST['control_action'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM communication_campaigns WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $camp = $stmt->fetch();

        if (!$camp) {
            echo json_encode(['success' => false, 'message' => 'Campaign not found.']);
            exit;
        }

        if ($act === 'pause') {
            $stmtUpd = $pdo->prepare("UPDATE communication_campaigns SET status = 'paused', updated_at = NOW() WHERE id = ?");
            $stmtUpd->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Campaign paused. Background dispatches suspended.']);
        } elseif ($act === 'resume') {
            $stmtUpd = $pdo->prepare("UPDATE communication_campaigns SET status = 'active', updated_at = NOW() WHERE id = ?");
            $stmtUpd->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Campaign resumed. Sending in progress.']);
        } elseif ($act === 'cancel') {
            $pdo->beginTransaction();
            try {
                $stmtUpd = $pdo->prepare("UPDATE communication_campaigns SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                $stmtUpd->execute([$id]);

                // Skip pending queue items
                $stmtRec = $pdo->prepare("SELECT queue_id FROM communication_campaign_recipients WHERE campaign_id = ? AND status='pending'");
                $stmtRec->execute([$id]);
                $qIds = $stmtRec->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($qIds)) {
                    $qPlaceholders = implode(',', array_fill(0, count($qIds), '?'));
                    $stmtQ = $pdo->prepare("UPDATE communication_queue SET status='cancelled', error_message='cancelled:campaign_stopped' WHERE id IN ($qPlaceholders)");
                    $stmtQ->execute($qIds);
                }

                $stmtRecUpd = $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed' WHERE campaign_id = ? AND status='pending'");
                $stmtRecUpd->execute([$id]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Campaign cancelled. Pending dispatches aborted.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Cancellation failed: ' . $e->getMessage()]);
            }
        } elseif ($act === 'retry') {
            // Fetch failed recipients with sender and context data
            $stmtFailed = $pdo->prepare("
                SELECT r.*, q.template_name, q.template_data, q.attachments, q.subject, q.body_html, q.body_text,
                       q.sender_account_id, q.event_name, q.lead_id AS q_lead_id
                FROM communication_campaign_recipients r
                JOIN communication_queue q ON r.queue_id = q.id
                WHERE r.campaign_id = ? AND r.status = 'failed'
            ");
            $stmtFailed->execute([$id]);
            $failedRecipients = $stmtFailed->fetchAll(PDO::FETCH_ASSOC);

            if (empty($failedRecipients)) {
                echo json_encode(['success' => false, 'message' => 'No failed dispatches found to retry.']);
                exit;
            }

            require_once 'includes/communication/CommunicationEngine.php';
            require_once 'includes/communication/WhatsAppAccountResolver.php';
            require_once 'includes/communication/CampaignConfig.php';
            $engine = CommunicationEngine::getInstance($pdo);
            $resolver = WhatsAppAccountResolver::getInstance($pdo);

            // Determine campaign-level sender account fallback
            $campSenderId = null;
            if (!empty($camp['sender_account_id'])) {
                $campSenderId = (int)$camp['sender_account_id'];
            } else {
                $crit = json_decode($camp['segment_criteria'] ?? '{}', true) ?: [];
                if (!empty($crit['sender_account_id'])) {
                    $campSenderId = (int)$crit['sender_account_id'];
                } elseif (!empty($crit['sender_key'])) {
                    $cAcc = $resolver->getAccount($crit['sender_key']);
                    if ($cAcc) $campSenderId = (int)$cAcc['id'];
                }
            }

            $pdo->beginTransaction();
            try {
                $retriedCount = 0;
                foreach ($failedRecipients as $rec) {
                    $tplData = json_decode($rec['template_data'], true) ?: [];
                    $attData = json_decode($rec['attachments'], true) ?: [];

                    $retrySenderAccountId = !empty($rec['sender_account_id']) ? (int)$rec['sender_account_id'] : $campSenderId;
                    if ($retrySenderAccountId !== null) {
                        $senderAcc = $resolver->getAccount($retrySenderAccountId);
                        if (!$senderAcc || (isset($senderAcc['status']) && $senderAcc['status'] !== 'active') || empty($senderAcc['phone_number_id'])) {
                            throw new RuntimeException("Configured sender account is inactive or missing Phone Number ID. Cannot retry without a valid configured sender.");
                        }
                    }

                    $idempotencyKey = "campaign:{$id}:rec:{$rec['id']}:retry:" . time() . ":" . uniqid();

                    $newQueueId = $engine->queueMessage(
                        'whatsapp',
                        $rec['recipient'],
                        $rec['recipient_name'],
                        $rec['subject'],
                        $rec['body_html'],
                        $rec['body_text'],
                        $attData,
                        $tplData,
                        $admin_username,
                        $rec['event_name'] ?: 'campaign_broadcast',
                        'lead',
                        $rec['lead_id'] ?? $rec['q_lead_id'],
                        $retrySenderAccountId,
                        $idempotencyKey,
                        CampaignConfig::CAMPAIGN_QUEUE_PRIORITY
                    );

                    $stmtUpdRec = $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'pending', queue_id = ?, sent_at = NULL WHERE id = ?");
                    $stmtUpdRec->execute([$newQueueId, $rec['id']]);
                    $retriedCount++;
                }

                $stmtCampActive = $pdo->prepare("UPDATE communication_campaigns SET status = 'active', updated_at = NOW() WHERE id = ?");
                $stmtCampActive->execute([$id]);

                $pdo->commit();
                try {
                    $engine->triggerCronBackground();
                } catch (Exception $bgEx) {}

                echo json_encode(['success' => true, 'message' => "Successfully re-queued {$retriedCount} failed dispatches."]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Retry failed: ' . $e->getMessage()]);
            }
        }
        exit;
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   Campaign Form Submit Logic (POST)
   ───────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_campaign') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $campaignName = trim($_POST['campaign_name'] ?? '');
        $targetAudience = 'leads'; // Marketing campaigns target leads
        $templateName = trim($_POST['template_name'] ?? '');
        $senderKey = trim($_POST['sender_key'] ?? 'notifications');
        $senderAccountId = !empty($_POST['sender_account_id']) ? (int)$_POST['sender_account_id'] : null;

        $scheduleType = $_POST['schedule_type'] ?? 'now';
        $scheduleDate = $_POST['schedule_date'] ?? '';
        $scheduleTime = $_POST['schedule_time'] ?? '';

        // Media header uploaded file url
        $headerMediaUrl = '';
        if (isset($_FILES['header_media_file']) && $_FILES['header_media_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['header_media_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'mp4', 'pdf'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            if (!in_array($ext, $allowedExtensions, true)) {
                $error_message = 'Invalid media header file extension. Allowed: JPG, PNG, MP4, PDF.';
            } elseif ($file['size'] > $maxSize) {
                $error_message = 'Media file is too large (max 5MB).';
            } else {
                $dir = __DIR__ . '/uploads/whatsapp_campaign_media/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/', '', pathinfo($file['name'], PATHINFO_FILENAME)) . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $safeName)) {
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $headerMediaUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/uploads/whatsapp_campaign_media/' . $safeName;
                } else {
                    $error_message = 'Failed to save uploaded media file.';
                }
            }
        }

        // 1. Resolve and Validate WhatsApp Sender Account
        $resolver = WhatsAppAccountResolver::getInstance($pdo);
        $senderAcc = $senderAccountId ? $resolver->getAccount($senderAccountId) : $resolver->getAccount($senderKey);
        if (!$senderAcc) {
            $senderAcc = $resolver->getAccount('notifications');
        }

        if (empty($error_message)) {
            if (!$senderAcc || (isset($senderAcc['status']) && $senderAcc['status'] !== 'active')) {
                $error_message = 'Selected WhatsApp sender account is inactive or not found.';
            } elseif (empty($senderAcc['phone_number_id'])) {
                $error_message = ($senderAcc['display_name'] ?? 'Selected sender') . ' is not configured for WhatsApp sending (missing Phone Number ID). Campaign creation aborted. The system will never silently fall back to admissions.';
            } elseif (!$campaignName) {
                $error_message = 'Please specify a campaign name.';
            } elseif (!$templateName) {
                $error_message = 'Please select a Meta Marketing Template for the WhatsApp campaign.';
            } else {
                // 2. Fetch and Validate Template
                $stmtTpl = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? AND status='approved' LIMIT 1");
                $stmtTpl->execute([$templateName]);
                $template = $stmtTpl->fetch();

                if (!$template) {
                    $error_message = 'Selected template is missing or not approved.';
                } elseif (strtoupper($template['category'] ?? '') !== 'MARKETING') {
                    $error_message = 'Only approved WhatsApp MARKETING templates can be used for bulk marketing campaigns.';
                } else {
                    $meta = json_decode($template['meta_data'], true) ?: [];
                    $varMappings = $_POST['vars'] ?? [];
                    $staticVals = $_POST['static_vars'] ?? [];

                    // 3. Target Audience Segmentation (Leads)
                    $recipients = [];
                    $courses = $_POST['target_leads_courses'] ?? [];
                    $statuses = $_POST['target_leads_statuses'] ?? [];

                    if (empty($courses) || empty($statuses)) {
                        $error_message = 'Please select at least one course and one lead status.';
                    } else {
                        $where = [];
                        $params = [];

                        $coursePlaceholders = implode(',', array_fill(0, count($courses), '?'));
                        $where[] = "interested_course IN ($coursePlaceholders)";
                        $params = array_merge($params, $courses);

                        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
                        $where[] = "status IN ($statusPlaceholders)";
                        $params = array_merge($params, $statuses);

                        $where_sql = implode(' AND ', $where);
                        $stmt = $pdo->prepare("SELECT id, name, whatsapp_number, interested_course, status, is_opted_out FROM leads WHERE $where_sql ORDER BY id ASC");
                        $stmt->execute($params);
                        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        $seenPhones = [];
                        foreach ($leads as $l) {
                            if ((int)$l['is_opted_out'] === 1) continue;
                            $phone = clean_wa_phone($l['whatsapp_number']);
                            if (empty($phone) || strlen($phone) < 10) continue;
                            if (in_array($phone, $seenPhones, true)) continue;

                            $seenPhones[] = $phone;
                            $recipients[] = [
                                'lead_id' => $l['id'],
                                'user_id' => null,
                                'name' => $l['name'] ?: 'Prospect',
                                'phone' => $phone,
                                'course' => $l['interested_course'],
                                'status' => $l['status'],
                                'raw_lead' => $l
                            ];
                        }

                        $segmentCriteria = [
                            'target_audience' => 'leads',
                            'courses' => $courses,
                            'statuses' => $statuses,
                            'var_mappings' => $varMappings,
                            'static_vals' => $staticVals,
                            'header_media' => $headerMediaUrl,
                            'sender_account_id' => (int)$senderAcc['id'],
                            'sender_key' => $senderAcc['sender_key'],
                            'sender_name' => $senderAcc['display_name']
                        ];

                        if (empty($recipients)) {
                            $error_message = 'No eligible recipients found matching the target segmentation criteria.';
                        } else {
                            // Calculate scheduling datetime
                            $scheduledAtVal = null;
                            $campaignStatus = 'active';

                            if ($scheduleType === 'schedule' && !empty($scheduleDate) && !empty($scheduleTime)) {
                                $scheduledAtVal = $scheduleDate . ' ' . $scheduleTime . ':00';
                                $campaignStatus = 'scheduled';
                            }

                            // Check if sender_account_id column exists on communication_campaigns (dual-compatibility)
                            $hasCampSenderCol = false;
                            try {
                                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                                if ($driver === 'sqlite') {
                                    $colStmt = $pdo->query("PRAGMA table_info(communication_campaigns)");
                                    while ($cRow = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                                        if ($cRow['name'] === 'sender_account_id') { $hasCampSenderCol = true; break; }
                                    }
                                } else {
                                    $colStmt = $pdo->query("SHOW COLUMNS FROM communication_campaigns LIKE 'sender_account_id'");
                                    $hasCampSenderCol = (bool)$colStmt->fetchColumn();
                                }
                            } catch (Throwable $e) {}

                            $pdo->beginTransaction();
                            try {
                                if ($hasCampSenderCol) {
                                    $stmtCamp = $pdo->prepare("
                                        INSERT INTO communication_campaigns (name, channel, target_audience, template_name, segment_criteria, status, scheduled_at, sender_account_id, created_by, created_at, updated_at)
                                        VALUES (?, 'whatsapp', 'leads', ?, ?, ?, ?, ?, ?, NOW(), NOW())
                                    ");
                                    $stmtCamp->execute([
                                        $campaignName,
                                        $templateName,
                                        json_encode($segmentCriteria),
                                        $campaignStatus,
                                        $scheduledAtVal,
                                        (int)$senderAcc['id'],
                                        $admin_username
                                    ]);
                                } else {
                                    $stmtCamp = $pdo->prepare("
                                        INSERT INTO communication_campaigns (name, channel, target_audience, template_name, segment_criteria, status, scheduled_at, created_by, created_at, updated_at)
                                        VALUES (?, 'whatsapp', 'leads', ?, ?, ?, ?, ?, NOW(), NOW())
                                    ");
                                    $stmtCamp->execute([
                                        $campaignName,
                                        $templateName,
                                        json_encode($segmentCriteria),
                                        $campaignStatus,
                                        $scheduledAtVal,
                                        $admin_username
                                    ]);
                                }
                                $campaignId = (int)$pdo->lastInsertId();

                                $stmtRecip = $pdo->prepare("
                                    INSERT INTO communication_campaign_recipients (campaign_id, lead_id, recipient, recipient_name, queue_id, status, created_at)
                                    VALUES (?, ?, ?, ?, NULL, 'pending', NOW())
                                ");

                                $queuedCount = 0;
                                foreach ($recipients as $rec) {
                                    $stmtRecip->execute([
                                        $campaignId,
                                        $rec['lead_id'],
                                        $rec['phone'],
                                        $rec['name']
                                    ]);
                                    $queuedCount++;
                                }

                                $pdo->commit();
                                try {
                                    require_once 'includes/communication/CommunicationEngine.php';
                                    $commEngine = CommunicationEngine::getInstance($pdo);
                                    $commEngine->triggerCronBackground();
                                } catch (Exception $bgEx) {}

                                $success_message = "Campaign successfully configured and snapshot saved! Enqueued {$queuedCount} recipients for background dispatch using " . htmlspecialchars($senderAcc['display_name']) . ".";
                            } catch (Exception $e) {
                                $pdo->rollBack();
                                $error_message = 'Campaign configuration failed: ' . $e->getMessage();
                            }
                        }
                    }
                }
            }
        }
    }
}

/* ── Load Campaigns and Templates list ── */
$campaigns = [];
try {
    $campaigns = $pdo->query("
        SELECT c.*, 
          COUNT(r.id) as total_recipients,
          SUM(CASE WHEN r.queue_id IS NULL AND r.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
          SUM(CASE WHEN r.queue_id IS NOT NULL AND q.status = 'pending' THEN 1 ELSE 0 END) as queued_count,
          SUM(CASE WHEN q.status = 'processing' THEN 1 ELSE 0 END) as processing_count,
          SUM(CASE WHEN q.status = 'sent' OR r.status = 'sent' THEN 1 ELSE 0 END) as sent_count,
          SUM(CASE WHEN q.status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
          SUM(CASE WHEN q.status = 'read' THEN 1 ELSE 0 END) as read_count,
          SUM(CASE WHEN r.status = 'failed' OR q.status = 'failed' THEN 1 ELSE 0 END) as failed_count,
          SUM(CASE WHEN q.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
        FROM communication_campaigns c
        LEFT JOIN communication_campaign_recipients r ON c.id = r.campaign_id
        LEFT JOIN communication_queue q ON r.queue_id = q.id
        GROUP BY c.id
        ORDER BY c.id DESC
    ")->fetchAll();
} catch (Exception $ex) {}

$marketingTemplates = [];
try {
    $marketingTemplates = $pdo->query("
        SELECT * FROM communication_templates 
        WHERE channel='whatsapp' AND status='approved' AND category='MARKETING'
        ORDER BY template_name ASC
    ")->fetchAll();
} catch (Exception $ex) {}

// Query courses available for dropdown segments
$leadCourses = [];
try {
    $leadCourses = $pdo->query("SELECT DISTINCT interested_course FROM leads WHERE interested_course IS NOT NULL AND interested_course <> '' ORDER BY interested_course ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {}

// Sender Accounts Resolution for UI
$resolver = WhatsAppAccountResolver::getInstance($pdo);
$allAccounts = $resolver->getAllAccounts(false);
$notifAccount = $resolver->getAccount('notifications') ?: [
    'id' => 2,
    'sender_key' => 'notifications',
    'display_name' => 'PEPP Updates',
    'display_number' => '917994304400',
    'phone_number_id' => '',
    'purpose' => 'Session reminders, daily tasks & marketing campaigns',
    'status' => 'active'
];
$admissionsAccount = $resolver->getAccount('admissions') ?: [
    'id' => 1,
    'sender_key' => 'admissions',
    'display_name' => 'PEPP Learning',
    'display_number' => '916282563209',
    'phone_number_id' => '',
    'purpose' => 'Admissions communication & student records',
    'status' => 'active'
];
$isNotifConfigured = !empty($notifAccount['phone_number_id']);
$isNotifActive = (!isset($notifAccount['status']) || $notifAccount['status'] === 'active');
$isAdmissionsConfigured = !empty($admissionsAccount['phone_number_id']);
$isAdmissionsActive = (!isset($admissionsAccount['status']) || $admissionsAccount['status'] === 'active');

// Overall KPIs calculation
$totalAudienceAll = 0;
$totalSentAll = 0;
$totalDeliveredAll = 0;
$totalReadAll = 0;
$totalFailedAll = 0;
foreach ($campaigns as $cItem) {
    $totalAudienceAll += (int)$cItem['total_recipients'];
    $totalSentAll += (int)$cItem['sent_count'];
    $totalDeliveredAll += (int)($cItem['delivered_count'] ?? 0);
    $totalReadAll += (int)($cItem['read_count'] ?? 0);
    $totalFailedAll += (int)$cItem['failed_count'];
}

include 'includes/admin_nav.php';
?>

<div class="container-fluid" style="padding:20px;">
    <style>
    /* Responsive Layout Grid */
    .campaign-main-layout-grid {
        display: grid;
        grid-template-columns: minmax(320px, 360px) minmax(0, 1fr);
        gap: 20px;
        align-items: start;
    }
    @media (max-width: 991px) {
        .campaign-main-layout-grid {
            grid-template-columns: 1fr !important;
        }
    }

    /* Modern Form Sectioning */
    .form-step-section {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(15,23,42,0.02);
    }
    .form-step-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 12px;
    }
    .form-step-number {
        width: 32px !important;
        height: 32px !important;
        min-width: 32px !important;
        font-size: 0.9rem !important;
        border-radius: 8px !important;
        font-weight: 800 !important;
        background: #f3f4f6 !important;
        color: #4b5563 !important;
        border: 1px solid #cbd5e1 !important;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .form-step-title {
        font-size: 0.85rem !important;
        font-weight: 700;
        color: #1e293b;
        text-transform: capitalize !important;
        letter-spacing: 0.2px;
    }

    /* Override standard inputs and select styling */
    .form-control, select.form-control {
        height: 40px !important;
        padding: 8px 14px !important;
        font-size: 0.82rem !important;
        border-radius: 8px !important;
        border: 1.5px solid #cbd5e1 !important;
        background-color: #fff !important;
        color: #1e293b !important;
        font-weight: 500 !important;
        box-shadow: none !important;
        transition: all 0.15s ease-in-out !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    .form-control:focus, select.form-control:focus {
        border-color: #7c3aed !important;
        outline: none !important;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15) !important;
    }

    /* Scrollable checklist customize */
    #leads-courses-checklist {
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 #f1f5f9;
    }
    #leads-courses-checklist::-webkit-scrollbar {
        width: 6px;
    }
    #leads-courses-checklist::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 4px;
    }
    #leads-courses-checklist::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }
    #leads-courses-checklist::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    /* Modern Checkbox Layout */
    .chk-card-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    .chk-card-label {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 10px;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.78rem;
        font-weight: 600;
        background: #fff;
        transition: all 0.15s ease;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .chk-card-label:hover {
        border-color: #94a3b8;
        background: #f8fafc;
    }
    .chk-card-label input[type="checkbox"] {
        width: 15px;
        height: 15px;
        border-radius: 4px;
        accent-color: #7c3aed;
        cursor: pointer;
    }
    .chk-card-label.checked {
        border-color: #7c3aed;
        background: #f5f3ff;
        color: #7c3aed;
        font-weight: 700;
    }

    /* Premium KPI Cards */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 20px;
    }
    @media (max-width: 1024px) {
        .kpi-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 640px) {
        .kpi-grid { grid-template-columns: 1fr; }
    }
    .kpi-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }
    .kpi-icon.audience { background: #ede9fe; color: #7c3aed; }
    .kpi-icon.sent { background: #d1fae5; color: #047857; }
    .kpi-icon.read { background: #dbeafe; color: #1d4ed8; }
    .kpi-icon.failed { background: #fee2e2; color: #b91c1c; }
    .kpi-info {
        display: flex;
        flex-direction: column;
    }
    .kpi-value {
        font-size: 1.4rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.2;
    }
    .kpi-label {
        font-size: 0.68rem;
        color: #64748b;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-top: 2px;
    }

    /* Custom Table Avatar Circle */
    .table-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #f5f3ff;
        color: #7c3aed;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        border: 1.5px solid #ddd6fe;
        text-transform: uppercase;
    }

    /* Dropdown Menu styling */
    .report-dropdown {
        position: relative;
        display: inline-block;
    }
    .report-dropdown-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        margin-top: 6px;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        z-index: 1000;
        min-width: 170px;
        overflow: hidden;
    }
    .report-dropdown-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        font-size: 0.8rem;
        color: #334155;
        font-weight: 600;
        cursor: pointer;
        background: #fff;
        border: none;
        width: 100%;
        text-align: left;
        transition: background 0.1s ease;
        text-decoration: none !important;
    }
    .report-dropdown-item:hover {
        background: #f8fafc;
        color: #7c3aed;
        text-decoration: none !important;
    }
    .report-dropdown-item i {
        font-size: 0.9rem;
    }

    /* Badges styles */
    .badge {
        display: inline-block;
        padding: 4px 8px;
        font-size: 0.7rem;
        font-weight: 700;
        border-radius: 6px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .badge.gray { background: #e2e8f0; color: #475569; }
    .badge.green { background: #d1fae5; color: #065f46; }
    .badge.blue { background: #dbeafe; color: #1e40af; }
    .badge.orange { background: #fef3c7; color: #92400e; }
    .badge.red { background: #fee2e2; color: #991b1b; }

    /* Modals Overlay */
    .popover-modal-backdrop {
        display: none;
        position: fixed;
        z-index: 99999;
        left: 0; top: 0;
        width: 100%; height: 100%;
        overflow: auto;
        background-color: rgba(15,23,42,0.45);
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(4px);
    }
    .popover-modal {
        background-color: #fff;
        border-radius: 16px;
        max-width: 460px;
        width: 90%;
        padding: 24px;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
        position: relative;
        border: 1px solid #e2e8f0;
    }
    .popover-modal-close {
        position: absolute;
        right: 18px; top: 14px;
        cursor: pointer;
        font-size: 1.4rem;
        color: #94a3b8;
        font-weight: 700;
        background: none;
        border: none;
    }
    .popover-modal-close:hover {
        color: #475569;
    }

    /* Actions styling overrides */
    .btn-primary-action {
        background: #7c3aed !important;
        border-color: #7c3aed !important;
        color: #fff !important;
    }
    .btn-primary-action:hover {
        background: #6d28d9 !important;
        border-color: #6d28d9 !important;
    }
    .btn-danger-action {
        background: #ef4444 !important;
        border-color: #ef4444 !important;
        color: #fff !important;
    }
    .btn-danger-action:hover {
        background: #dc2626 !important;
        border-color: #dc2626 !important;
    }
    </style>
    <?php if ($success_message): ?>
        <div class="alert alert-success" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:12px 18px; border-radius:12px; margin-bottom:20px;">
            <i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger" style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:12px 18px; border-radius:12px; margin-bottom:20px;">
            <i class="fas fa-circle-xmark"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- ── NAVIGATION TABS ── -->
    <div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid #e5e7eb; padding-bottom:8px;">
        <a href="communication-dashboard.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-gears"></i> API Settings &amp; Queue</a>
        <a href="communication-templates.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-layer-group"></i> Meta Templates Sync</a>
        <a href="whatsapp-marketing-templates.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-magic"></i> Marketing Templates</a>
        <a href="communication-campaigns.php" class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-bullhorn"></i> Bulk Campaigns</a>
        <a href="whatsapp-inbox.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fab fa-whatsapp"></i> WhatsApp Inbox</a>
    </div>

    <!-- Layout Grid -->
    <div class="campaign-main-layout-grid">
        
        <!-- Left Column: Create Campaign Form -->
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.02);">
            <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px;">
                <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-bullhorn" style="color:#8b5cf6; margin-right:4px;"></i> Create Bulk Campaign</h3>
            </div>
            
            <div style="padding:20px;">
                <!-- Target Audience Switch Tabs -->
                <div style="display:flex; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:16px;">
                    <button type="button" onclick="switchAudience('leads')" id="btn-tab-leads" style="flex:1; padding:10px; border:none; outline:none; font-weight:700; cursor:pointer; font-size:0.8rem; background:#f1f5f9; color:#475569;">Leads Database</button>
                    <button type="button" onclick="switchAudience('students')" id="btn-tab-students" style="flex:1; padding:10px; border:none; outline:none; font-weight:700; cursor:pointer; font-size:0.8rem; background:#fff; color:#64748b; border-left:1px solid #e2e8f0;">Students Database</button>
                </div>

                <form method="POST" id="campaign-create-form" enctype="multipart/form-data" onsubmit="return validateFormSubmit(event)">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_campaign">
                    <input type="hidden" name="target_audience" value="leads">
                    <input type="hidden" name="sender_account_id" id="inp-sender-account-id" value="<?php echo htmlspecialchars((string)($notifAccount['id'] ?? '2')); ?>">
                    <input type="hidden" name="sender_key" id="inp-sender-key" value="<?php echo htmlspecialchars((string)($notifAccount['sender_key'] ?? 'notifications')); ?>">

                    <!-- STEP 1 — TEMPLATE -->
                    <div class="form-step-section">
                        <div class="form-step-header">
                            <div class="form-step-number">01</div>
                            <div style="display:flex; flex-direction:column; gap:2px;">
                                <div class="form-step-title">Select Marketing Template</div>
                                <div style="font-size:0.7rem; color:#64748b; font-weight:500;">Approved WhatsApp marketing templates</div>
                            </div>
                        </div>

                        <div style="margin-bottom:10px;">
                            <label style="display:block; font-size:0.78rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Approved Template <span style="color:#ef4444;">*</span></label>
                            <select name="template_name" id="sel-template-name" class="form-control" onchange="onTemplateSelected(this.value)" required>
                                <option value="">-- Choose Approved Marketing Template --</option>
                                <?php foreach ($marketingTemplates as $m_tpl): ?>
                                    <option value="<?php echo htmlspecialchars($m_tpl['template_name']); ?>" data-lang="<?php echo htmlspecialchars($m_tpl['language']); ?>">
                                        <?php echo htmlspecialchars($m_tpl['template_name']); ?> (<?php echo htmlspecialchars($m_tpl['language']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Template Preview Card -->
                        <div id="tpl-info-card" style="display:none; border:1px solid #cbd5e1; border-radius:10px; padding:10px; background:#f8fafc; font-size:0.75rem;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <strong id="tpl-card-name" style="color:#1e293b; font-size:0.8rem;">-</strong>
                                <div style="display:flex; gap:4px;">
                                    <span class="badge blue" style="font-size:0.6rem;">MARKETING</span>
                                    <span class="badge gray" id="tpl-card-lang" style="font-size:0.6rem;">en</span>
                                </div>
                            </div>
                            <div id="tpl-card-body-preview" style="color:#475569; font-size:0.73rem; line-height:1.35; max-height:80px; overflow-y:auto; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:8px; white-space:pre-wrap;"></div>
                        </div>
                    </div>

                    <!-- STEP 2 — AUDIENCE -->
                    <div class="form-step-section">
                        <div class="form-step-header">
                            <div class="form-step-number">02</div>
                            <div style="display:flex; flex-direction:column; gap:2px;">
                                <div class="form-step-title">Target Audience</div>
                                <div style="font-size:0.7rem; color:#64748b; font-weight:500;">Segment leads database</div>
                            </div>
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:0.75rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Lead Statuses <span style="color:#ef4444;">*</span></label>
                            <div class="chk-card-grid">
                                <label class="chk-card-label checked">
                                    <input type="checkbox" name="target_leads_statuses[]" value="new" checked class="chk-status" onchange="onFilterChanged(); this.parentElement.classList.toggle('checked', this.checked);"> New
                                </label>
                                <label class="chk-card-label checked">
                                    <input type="checkbox" name="target_leads_statuses[]" value="contacted" checked class="chk-status" onchange="onFilterChanged(); this.parentElement.classList.toggle('checked', this.checked);"> Contacted
                                </label>
                                <label class="chk-card-label checked">
                                    <input type="checkbox" name="target_leads_statuses[]" value="interested" checked class="chk-status" onchange="onFilterChanged(); this.parentElement.classList.toggle('checked', this.checked);"> Interested
                                </label>
                                <label class="chk-card-label checked">
                                    <input type="checkbox" name="target_leads_statuses[]" value="follow_up" checked class="chk-status" onchange="onFilterChanged(); this.parentElement.classList.toggle('checked', this.checked);"> Follow-up
                                </label>
                            </div>
                        </div>

                        <div style="margin-bottom:12px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <label style="font-size:0.75rem; font-weight:700; color:#4b5563;">Interested Course Targets <span style="color:#ef4444;">*</span></label>
                                <div style="display:flex; gap:6px;">
                                    <button type="button" onclick="toggleLeadCheckboxes(true)" style="border:none; background:none; font-size:0.65rem; color:#8b5cf6; font-weight:700; cursor:pointer; padding:0;">Select All</button>
                                    <span style="font-size:0.65rem; color:#94a3b8;">|</span>
                                    <button type="button" onclick="toggleLeadCheckboxes(false)" style="border:none; background:none; font-size:0.65rem; color:#ef4444; font-weight:700; cursor:pointer; padding:0;">Clear All</button>
                                </div>
                            </div>
                            <div style="max-height:130px; overflow-y:auto; border:1.5px solid #cbd5e1; border-radius:10px; padding:10px; background:#fff;" id="leads-courses-checklist">
                                <?php foreach ($leadCourses as $lc): ?>
                                    <label style="font-size:0.75rem; display:flex; align-items:center; gap:8px; margin-bottom:6px; cursor:pointer;">
                                        <input type="checkbox" name="target_leads_courses[]" value="<?php echo htmlspecialchars($lc); ?>" class="chk-course" onchange="onFilterChanged()" style="width:15px; height:15px; accent-color:#7c3aed;"> <?php echo htmlspecialchars($lc); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Live Summary Chips -->
                        <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px; margin-bottom:10px;" id="audience-summary-chips">
                            <div style="background:#ecfdf5; border:1px solid #10b981; border-radius:8px; padding:8px 10px; text-align:center;">
                                <div style="font-size:1.1rem; font-weight:800; color:#047857;" id="chip-recipients-count">0</div>
                                <div style="font-size:0.65rem; font-weight:700; color:#065f46;">FINAL RECIPIENTS</div>
                            </div>
                            <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:8px 10px; text-align:center;">
                                <div style="font-size:1.1rem; font-weight:800; color:#475569;" id="chip-excluded-count">0</div>
                                <div style="font-size:0.65rem; font-weight:700; color:#64748b;">EXCLUDED TOTAL</div>
                            </div>
                            <div style="display:flex; justify-content:space-between; grid-column:span 2; font-size:0.68rem; color:#64748b; background:#f1f5f9; padding:6px 10px; border-radius:6px;">
                                <span>Duplicates: <b id="chip-dup-count">0</b></span>
                                <span>Invalid: <b id="chip-inv-count">0</b></span>
                                <span>Opted-out: <b id="chip-opt-count">0</b></span>
                            </div>
                        </div>

                        <button type="button" onclick="calculatePreview()" class="btn btn-outline" style="width:100%; border-radius:8px; font-weight:700; padding:8px; font-size:0.78rem; display:flex; align-items:center; justify-content:center; gap:6px;">
                            <i class="fas fa-calculator"></i> Calculate &amp; Preview Recipients
                        </button>
                    </div>

                    <!-- STEP 3 — SENDER -->
                    <div class="form-step-section">
                        <div class="form-step-header">
                            <div class="form-step-number">03</div>
                            <div style="display:flex; flex-direction:column; gap:2px;">
                                <div class="form-step-title">Sender Account</div>
                                <div style="font-size:0.7rem; color:#64748b; font-weight:500;">Multi-number WhatsApp routing</div>
                            </div>
                        </div>

                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <!-- PEPP Updates Card (Default) -->
                            <label class="sender-card-option" id="sender-card-notifications" style="display:block; border:1.5px solid <?php echo $isNotifConfigured ? '#7c3aed' : '#fca5a5'; ?>; border-radius:10px; padding:10px 12px; background:<?php echo $isNotifConfigured ? '#f5f3ff' : '#fef2f2'; ?>; cursor:pointer;">
                                <div style="display:flex; align-items:flex-start; gap:10px;">
                                    <input type="radio" name="selected_sender" value="notifications" <?php echo $isNotifConfigured ? 'checked' : 'disabled'; ?> onchange="onSenderSelected('notifications', <?php echo (int)($notifAccount['id'] ?? 2); ?>, 'PEPP Updates', <?php echo $isNotifConfigured ? 'true' : 'false'; ?>)" style="margin-top:2px; accent-color:#7c3aed;">
                                    <div style="flex:1;">
                                        <div style="display:flex; justify-content:space-between; align-items:center;">
                                            <strong style="color:#1e293b; font-size:0.83rem;"><?php echo htmlspecialchars($notifAccount['display_name'] ?? 'PEPP Updates'); ?></strong>
                                            <span class="badge <?php echo $isNotifConfigured ? 'blue' : 'red'; ?>" style="font-size:0.62rem;">
                                                <?php echo $isNotifConfigured ? 'Default (Marketing)' : 'Not Configured'; ?>
                                            </span>
                                        </div>
                                        <div style="font-size:0.75rem; color:#475569; margin-top:2px;">
                                            <i class="fab fa-whatsapp" style="color:#25d366;"></i> +91 79943 04400
                                        </div>
                                        <div style="font-size:0.67rem; color:#64748b; margin-top:2px;">
                                            Purpose: Marketing campaigns &amp; reminders
                                        </div>
                                    </div>
                                </div>
                                <?php if (!$isNotifConfigured): ?>
                                    <div style="margin-top:8px; font-size:0.72rem; color:#b91c1c; background:#fee2e2; border-radius:6px; padding:6px 10px;">
                                        <i class="fas fa-triangle-exclamation"></i> <strong>PEPP Updates is not configured for WhatsApp sending.</strong><br>
                                        Meta Phone Number ID is missing. Sending via this account is disabled and will <em>never</em> silently fall back to admissions.
                                    </div>
                                <?php endif; ?>
                            </label>

                            <!-- PEPP Learning Card (Admissions) -->
                            <label class="sender-card-option" id="sender-card-admissions" style="display:block; border:1.5px solid #cbd5e1; border-radius:10px; padding:10px 12px; background:#fff; cursor:pointer;">
                                <div style="display:flex; align-items:flex-start; gap:10px;">
                                    <input type="radio" name="selected_sender" value="admissions" <?php echo ($isAdmissionsConfigured && !$isNotifConfigured) ? 'checked' : ''; ?> <?php echo $isAdmissionsConfigured ? '' : 'disabled'; ?> onchange="onSenderSelected('admissions', <?php echo (int)($admissionsAccount['id'] ?? 1); ?>, 'PEPP Learning', <?php echo $isAdmissionsConfigured ? 'true' : 'false'; ?>)" style="margin-top:2px; accent-color:#7c3aed;">
                                    <div style="flex:1;">
                                        <div style="display:flex; justify-content:space-between; align-items:center;">
                                            <strong style="color:#1e293b; font-size:0.83rem;"><?php echo htmlspecialchars($admissionsAccount['display_name'] ?? 'PEPP Learning'); ?></strong>
                                            <span class="badge <?php echo $isAdmissionsConfigured ? 'gray' : 'red'; ?>" style="font-size:0.62rem;">
                                                <?php echo $isAdmissionsConfigured ? 'Admissions Sender' : 'Not Configured'; ?>
                                            </span>
                                        </div>
                                        <div style="font-size:0.75rem; color:#475569; margin-top:2px;">
                                            <i class="fab fa-whatsapp" style="color:#25d366;"></i> +91 62825 63209
                                        </div>
                                        <div style="font-size:0.67rem; color:#64748b; margin-top:2px;">
                                            Purpose: Admissions communication &amp; student records
                                        </div>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- STEP 4 — REVIEW & SEND -->
                    <div class="form-step-section">
                        <div class="form-step-header">
                            <div class="form-step-number">04</div>
                            <div style="display:flex; flex-direction:column; gap:2px;">
                                <div class="form-step-title">Review &amp; Send</div>
                                <div style="font-size:0.7rem; color:#64748b; font-weight:500;">Summary and dispatch</div>
                            </div>
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:0.78rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Campaign Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="campaign_name" id="inp-campaign-name" class="form-control" placeholder="e.g. M.Phil Entrance Exam Campaign 2026" oninput="updateReviewCard()" required>
                        </div>

                        <!-- Schedule Option -->
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:0.75rem; font-weight:700; color:#4b5563; margin-bottom:4px;"><i class="fas fa-clock" style="color:#7c3aed;"></i> Launch Schedule</label>
                            <div style="display:flex; gap:16px; margin-bottom:8px;">
                                <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600;">
                                    <input type="radio" name="schedule_type" value="now" checked onchange="toggleScheduleBlock(false)" style="accent-color:#7c3aed;"> Send Immediately (ASAP)
                                </label>
                                <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600;">
                                    <input type="radio" name="schedule_type" value="schedule" onchange="toggleScheduleBlock(true)" style="accent-color:#7c3aed;"> Schedule Launch
                                </label>
                            </div>
                            <div id="section-schedule-datetime" style="display:none; grid-template-columns:1fr 1fr; gap:10px; border:1px solid #cbd5e1; padding:8px; border-radius:8px; background:#f8fafc;">
                                <div>
                                    <label style="font-size:0.65rem; color:#64748b; font-weight:700; text-transform:uppercase; margin-bottom:2px; display:block;">Date</label>
                                    <input type="date" name="schedule_date" id="inp-sched-date" class="form-control" style="font-size:0.75rem; height:36px !important;">
                                </div>
                                <div>
                                    <label style="font-size:0.65rem; color:#64748b; font-weight:700; text-transform:uppercase; margin-bottom:2px; display:block;">Time</label>
                                    <input type="time" name="schedule_time" id="inp-sched-time" class="form-control" style="font-size:0.75rem; height:36px !important;">
                                </div>
                            </div>
                        </div>

                        <!-- Review Summary Card -->
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; margin-bottom:14px; font-size:0.75rem;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px;"><span style="color:#64748b;">Campaign:</span><strong id="rev-camp-name" style="color:#1e293b;">—</strong></div>
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px;"><span style="color:#64748b;">Template:</span><strong id="rev-template-name" style="color:#1e293b;">None selected</strong></div>
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px;"><span style="color:#64748b;">Sender:</span><strong id="rev-sender-name" style="color:#7c3aed;"><?php echo htmlspecialchars($isNotifConfigured ? ($notifAccount['display_name'] ?? 'PEPP Updates') : ($admissionsAccount['display_name'] ?? 'PEPP Learning')); ?></strong></div>
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px;"><span style="color:#64748b;">Recipients:</span><strong id="rev-recip-count" style="color:#047857;">0 leads</strong></div>
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px;"><span style="color:#64748b;">Excluded:</span><span id="rev-excluded-count" style="color:#64748b;">0</span></div>
                            <div style="border-top:1px dashed #cbd5e1; padding-top:6px; margin-top:6px;">
                                <span style="color:#64748b; display:block; font-size:0.7rem;">Estimated processing time:</span>
                                <strong id="rev-est-time" style="color:#4f46e5; font-size:0.75rem;">Calculate audience to estimate</strong>
                            </div>
                        </div>

                        <!-- Collapsible Advanced Settings -->
                        <details style="margin-bottom:14px; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; background:#fff;">
                            <summary style="font-size:0.75rem; font-weight:700; color:#475569; cursor:pointer;">
                                <i class="fas fa-sliders" style="margin-right:4px;"></i> Advanced Settings (Variables &amp; Media Header)
                            </summary>

                            <div style="padding-top:10px;">
                                <!-- Upload Image Header Block -->
                                <div id="section-media-header" style="display:none; border:1px solid #cbd5e1; border-radius:8px; padding:10px; background:#fcfcfc; margin-bottom:10px;">
                                    <label style="display:block; font-size:0.72rem; font-weight:700; color:#1e293b; margin-bottom:4px;"><i class="fas fa-file-image" style="color:#7c3aed;"></i> Required Header Media File</label>
                                    <input type="file" name="header_media_file" id="inp-media-file" class="form-control" style="font-size:0.75rem; height:auto !important; padding:4px 8px !important;" accept="image/*,video/mp4,application/pdf" onchange="onMediaFileChange(event)">
                                    <span style="font-size:0.65rem; color:#64748b; display:block; margin-top:2px;">Select JPG, PNG, MP4, or PDF. Max file size: 5MB.</span>
                                </div>

                                <!-- Dynamic Variables Mapping Block -->
                                <div id="section-variable-mapping" style="display:none; border:1px solid #cbd5e1; border-radius:8px; padding:10px; background:#f8fafc;">
                                    <span style="font-size:0.72rem; font-weight:700; color:#4b5563; display:block; margin-bottom:6px;"><i class="fas fa-brackets-curly" style="color:#6366f1;"></i> Variable Mappings</span>
                                    <div id="variable-mappings-inputs" style="display:flex; flex-direction:column; gap:8px;"></div>
                                </div>
                            </div>
                        </details>

                        <!-- Send Campaign Button -->
                        <button type="submit" class="btn btn-primary" id="btn-submit-campaign" style="width:100%; border-radius:10px; font-weight:700; padding:12px; height:44px; display:flex; align-items:center; justify-content:center; gap:8px;" disabled>
                            <i class="fas fa-paper-plane"></i> Send Campaign
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column: Campaigns Dashboard list and dynamic details drilldown -->
        <div>
            <!-- Active Campaigns List -->
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.02); margin-bottom:20px;" id="panel-campaigns-list">
                <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px;">
                    <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-chart-column" style="margin-right:4px;"></i> Bulk Marketing Campaigns (<?php echo count($campaigns); ?>)</h3>
                </div>

                <div style="padding:16px 20px 0 20px;">
                    <div class="kpi-grid">
                        <div class="kpi-card">
                            <div class="kpi-icon audience"><i class="fas fa-users"></i></div>
                            <div class="kpi-info">
                                <span class="kpi-value"><?php echo number_format($totalAudienceAll); ?></span>
                                <span class="kpi-label">Audience Size</span>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon sent"><i class="fas fa-paper-plane"></i></div>
                            <div class="kpi-info">
                                <span class="kpi-value"><?php echo number_format($totalSentAll); ?></span>
                                <span class="kpi-label">Dispatched</span>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon read"><i class="fas fa-check-double"></i></div>
                            <div class="kpi-info">
                                <span class="kpi-value"><?php echo number_format($totalReadAll); ?></span>
                                <span class="kpi-label">Read Receipts</span>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon failed"><i class="fas fa-circle-xmark"></i></div>
                            <div class="kpi-info">
                                <span class="kpi-value"><?php echo number_format($totalFailedAll); ?></span>
                                <span class="kpi-label">Failed</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                    <thead>
                        <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                            <th style="padding:12px; font-weight:700; color:#374151;">Campaign Details</th>
                            <th style="padding:12px; font-weight:700; color:#374151;">Sender</th>
                            <th style="padding:12px; font-weight:700; color:#374151;">Recipients</th>
                            <th style="padding:12px; font-weight:700; color:#374151;">Progress</th>
                            <th style="padding:12px; font-weight:700; color:#374151; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campaigns)): ?>
                            <tr>
                                <td colspan="5" style="padding:30px; text-align:center; color:#9ca3af;">No bulk marketing campaigns have been created yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($campaigns as $camp): ?>
                                <?php 
                                    $recipCount = (int)$camp['total_recipients'];
                                    $sentCount = (int)$camp['sent_count'];
                                    $failCount = (int)$camp['failed_count'];
                                    $deliveredCount = (int)($camp['delivered_count'] ?? 0);
                                    $readCount = (int)($camp['read_count'] ?? 0);
                                    $cancelledCount = (int)($camp['cancelled_count'] ?? 0);
                                    
                                    $processed = $sentCount + $failCount + $deliveredCount + $readCount + $cancelledCount;
                                    $prog = $recipCount > 0 ? round($processed / $recipCount * 100) : 0;
                                    if ($prog > 100) $prog = 100;
                                    $remainingCount = max(0, $recipCount - $processed);
                                    
                                    // Resolve sender display
                                    $campSenderKey = 'notifications';
                                    $campSenderName = 'PEPP Updates';
                                    $campCriteria = json_decode($camp['segment_criteria'] ?? '{}', true) ?: [];
                                    if (!empty($campCriteria['sender_name'])) {
                                        $campSenderName = $campCriteria['sender_name'];
                                        $campSenderKey = $campCriteria['sender_key'] ?? 'notifications';
                                    } elseif (!empty($camp['sender_account_id'])) {
                                        $sAcc = $resolver->getAccount($camp['sender_account_id']);
                                        if ($sAcc) {
                                            $campSenderName = $sAcc['display_name'];
                                            $campSenderKey = $sAcc['sender_key'];
                                        }
                                    }
                                    $senderBadgeColor = ($campSenderKey === 'notifications') ? 'blue' : 'gray';
                                ?>
                                <tr style="border-bottom:1px solid #f3f4f6;">
                                    <td style="padding:12px;">
                                        <div style="font-weight:700; color:#111827;"><?php echo htmlspecialchars($camp['name']); ?></div>
                                        <div style="font-size:0.7rem; color:#64748b; margin-top:2px;">Template: <b><?php echo htmlspecialchars($camp['template_name']); ?></b> • <?php echo date('M d, Y H:i', strtotime($camp['created_at'])); ?></div>
                                    </td>
                                    <td style="padding:12px;">
                                        <span class="badge <?php echo $senderBadgeColor; ?>" style="font-size:0.65rem; font-weight:700;">
                                            <i class="fab fa-whatsapp" style="margin-right:2px;"></i> <?php echo htmlspecialchars($campSenderName); ?>
                                        </span>
                                    </td>
                                    <td style="padding:12px; font-weight:700; color:#1e293b;"><?php echo number_format($recipCount); ?></td>
                                    <td style="padding:12px;">
                                        <div style="display:flex; justify-content:space-between; font-size:0.7rem; font-weight:700; color:#475569; margin-bottom:4px;">
                                            <span><?php echo $prog; ?>% complete</span>
                                            <span>Remaining: <?php echo $remainingCount; ?></span>
                                        </div>
                                        <div style="display:flex; flex-direction:column; gap:6px;">
                                            <div style="width:100%; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden;">
                                                <div style="width:<?php echo $prog; ?>%; height:100%; background:<?php echo ($failCount > 0 ? '#ef4444' : '#10b981'); ?>;"></div>
                                            </div>
                                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                                <span style="font-size:0.68rem; padding:2px 6px; background:#f1f5f9; color:#475569; border-radius:4px; font-weight:700;" title="Sent">
                                                    <i class="fas fa-paper-plane" style="margin-right:2px; font-size:0.6rem;"></i> <?php echo $sentCount; ?> Sent
                                                </span>
                                                <span style="font-size:0.68rem; padding:2px 6px; background:#e0f2fe; color:#0369a1; border-radius:4px; font-weight:700;" title="Delivered">
                                                    <i class="fas fa-check" style="margin-right:2px; font-size:0.6rem;"></i> <?php echo $deliveredCount; ?> Delivered
                                                </span>
                                                <span style="font-size:0.68rem; padding:2px 6px; background:#dcfce7; color:#15803d; border-radius:4px; font-weight:700;" title="Read">
                                                    <i class="fas fa-check-double" style="margin-right:2px; font-size:0.6rem;"></i> <?php echo $readCount; ?> Read
                                                </span>
                                                <?php if ($failCount > 0): ?>
                                                    <span style="font-size:0.68rem; padding:2px 6px; background:#fee2e2; color:#b91c1c; border-radius:4px; font-weight:700;" title="Failed">
                                                        <i class="fas fa-circle-xmark" style="margin-right:2px; font-size:0.6rem;"></i> <?php echo $failCount; ?> Failed
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding:12px; text-align:right;">
                                        <button type="button" onclick="loadCampaignDrilldown(<?php echo $camp['id']; ?>)" class="btn btn-sm btn-outline" style="border-radius:6px; font-size:0.75rem;"><i class="fas fa-eye"></i> Details</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Audience Preview and Visual preview block -->
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.02); display:none;" id="panel-audience-preview">
                <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-users-viewfinder" style="margin-right:4px;"></i> Audience Recipient Preview</h3>
                    <button type="button" class="btn btn-sm btn-outline" onclick="calculatePreview()" style="font-size:0.75rem;"><i class="fas fa-rotate"></i> Refresh Audience</button>
                </div>
                
                <div style="padding:16px;">
                    <!-- Metrics grid -->
                    <div style="display:grid; grid-template-columns: repeat(5, 1fr); gap:10px; margin-bottom:20px; text-align:center;">
                        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fbfbfb;">
                            <div style="font-size:1.1rem; font-weight:800; color:#111827;" id="lbl-matching-leads">0</div>
                            <div style="font-size:0.65rem; color:#64748b; font-weight:700; margin-top:2px;">MATCHING LEADS</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fbfbfb;">
                            <div style="font-size:1.1rem; font-weight:800; color:#f59e0b;" id="lbl-duplicates">0</div>
                            <div style="font-size:0.65rem; color:#64748b; font-weight:700; margin-top:2px;">DUPLICATES EXCLUDED</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fbfbfb;">
                            <div style="font-size:1.1rem; font-weight:800; color:#ef4444;" id="lbl-opted-out">0</div>
                            <div style="font-size:0.65rem; color:#64748b; font-weight:700; margin-top:2px;">OPTED-OUT EXCLUDED</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fbfbfb;">
                            <div style="font-size:1.1rem; font-weight:800; color:#6b7280;" id="lbl-invalid">0</div>
                            <div style="font-size:0.65rem; color:#64748b; font-weight:700; margin-top:2px;">INVALID PHONES</div>
                        </div>
                        <div style="border:1px solid #10b981; border-radius:10px; padding:10px; background:#ecfdf5;">
                            <div style="font-size:1.2rem; font-weight:800; color:#059669;" id="lbl-eligible-recipients">0</div>
                            <div style="font-size:0.65rem; color:#047857; font-weight:800; margin-top:2px;">FINAL RECIPIENTS</div>
                        </div>
                    </div>

                    <!-- Split Layout: Left Table / Right visual preview -->
                    <div style="display:grid; grid-template-columns:1.8fr 1.2fr; gap:16px; align-items:start;">
                        <!-- Preview Table -->
                        <div style="max-height:280px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:10px;">
                            <table style="width:100%; border-collapse:collapse; font-size:0.75rem; text-align:left;">
                                <thead style="background:#f8fafc; position:sticky; top:0; z-index:10; border-bottom:1px solid #cbd5e1;">
                                    <tr>
                                        <th style="padding:8px;">Name</th>
                                        <th style="padding:8px;">Phone</th>
                                        <th style="padding:8px;">Course</th>
                                        <th style="padding:8px;">Status</th>
                                    </tr>
                                </thead>
                                <tbody id="table-body-preview"></tbody>
                            </table>
                        </div>
                        
                        <!-- Visual Chat preview bubble -->
                        <div style="background:#e5ddd5; border:1px solid #cbd5e1; border-radius:12px; overflow:hidden; font-family:sans-serif; background-image:url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); background-repeat:repeat; padding:10px; min-height:180px; display:flex; flex-direction:column; justify-content:center;">
                            <div style="background:#fff; border-radius:8px 8px 8px 0; max-width:98%; padding:8px; align-self:flex-start; box-shadow:0 1px 2px rgba(0,0,0,0.15); width:100%;">
                                <div id="preview-box-header-media" style="display:none; background:#ece5dd; border-radius:6px; height:80px; align-items:center; justify-content:center; font-size:1.4rem; color:#94a3b8; margin-bottom:6px;">
                                    <i class="fas fa-file-image" id="preview-box-media-icon"></i>
                                </div>
                                <div id="preview-box-header" style="font-weight:700; font-size:0.75rem; color:#111827; margin-bottom:4px; display:none;"></div>
                                <div id="preview-box-body" style="font-size:0.75rem; color:#374151; line-height:1.3; white-space:pre-wrap;"></div>
                                <div id="preview-box-footer" style="font-size:0.65rem; color:#94a3b8; margin-top:4px; display:none; border-top:1px dashed #f1f5f9; padding-top:2px;"></div>
                                <div id="preview-box-bubble-buttons" style="display:none; flex-direction:column; gap:4px; margin-top:6px; border-top:1px solid #f1f5f9; padding-top:4px;"></div>
                            </div>
                            <div id="preview-box-floating-buttons" style="display:none; width:98%; align-self:flex-start; margin-top:4px; flex-direction:column; gap:4px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Campaign Drilldown Details Panel -->
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.02); display:none;" id="panel-campaign-drilldown">
                <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:16px 20px; display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px;">
                    <div>
                        <span style="font-size:0.65rem; font-weight:800; color:#8b5cf6; text-transform:uppercase; letter-spacing:0.05em; display:block; margin-bottom:2px;">Campaign</span>
                        <h3 style="margin:0; font-size:1.25rem; font-weight:800; color:#0f172a;" id="drilldown-title">Campaign Detail</h3>
                        <div style="display:flex; align-items:center; gap:14px; margin-top:8px; flex-wrap:wrap;" id="drilldown-subtitle"></div>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <button type="button" onclick="closeCampaignDrilldown()" class="btn btn-outline" style="border-radius:8px; font-size:0.8rem; height:36px; display:flex; align-items:center; gap:6px; font-weight:700;"><i class="fas fa-chevron-left"></i> Back to Dashboard</button>
                        
                        <!-- Download Report Dropdown -->
                        <div class="report-dropdown">
                            <button type="button" class="btn btn-primary" onclick="toggleReportDropdown(event)" style="border-radius:8px; font-size:0.8rem; height:36px; display:flex; align-items:center; gap:6px; font-weight:700; background:#7c3aed; border-color:#7c3aed;"><i class="fas fa-file-export"></i> Download Report <i class="fas fa-chevron-down" style="font-size:0.7rem;"></i></button>
                            <div class="report-dropdown-menu" id="report-dropdown-menu">
                                <a href="#" class="report-dropdown-item" onclick="triggerReportDownload('csv'); return false;">
                                    <i class="fas fa-file-csv" style="color:#10b981;"></i> Download as CSV
                                </a>
                                <a href="#" class="report-dropdown-item" onclick="triggerReportDownload('excel'); return false;">
                                    <i class="fas fa-file-excel" style="color:#047857;"></i> Download as Excel
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="padding:20px;">
                    <!-- Campaign Control Actions Panel -->
                    <div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; border-bottom:1px dashed #e2e8f0; padding-bottom:14px; align-items:center;">
                        <input type="hidden" id="drilldown-camp-id">
                        <button type="button" id="btn-ctrl-resume" onclick="triggerCampaignControl('resume')" class="btn btn-primary btn-primary-action" style="display:none; border-radius:8px; font-size:0.8rem; height:36px; padding:0 14px; align-items:center; gap:6px; font-weight:700;"><i class="fas fa-play"></i> Resume Campaign</button>
                        <button type="button" id="btn-ctrl-pause" onclick="triggerCampaignControl('pause')" class="btn btn-outline" style="border-radius:8px; font-size:0.8rem; height:36px; padding:0 14px; display:inline-flex; align-items:center; gap:6px; font-weight:700; border-color:#d97706; color:#d97706; background:#fff;"><i class="fas fa-pause"></i> Pause Campaign</button>
                        <button type="button" id="btn-ctrl-retry" onclick="triggerCampaignControl('retry')" class="btn btn-outline" style="border-radius:8px; font-size:0.8rem; height:36px; padding:0 14px; display:inline-flex; align-items:center; gap:6px; font-weight:700; border-color:#8b5cf6; color:#8b5cf6; background:#fff;"><i class="fas fa-arrow-rotate-forward"></i> Retry Failed Dispatches</button>
                        <button type="button" id="btn-ctrl-cancel" onclick="triggerCampaignControl('cancel')" class="btn btn-danger btn-danger-action" style="border-radius:8px; font-size:0.8rem; height:36px; padding:0 14px; display:inline-flex; align-items:center; gap:6px; font-weight:700;"><i class="fas fa-stop"></i> Cancel Campaign</button>
                    </div>

                    <!-- Statistics Info Grid -->
                    <div class="kpi-grid" id="drilldown-metrics-grid"></div>

                    <!-- Search Filter Row -->
                    <div style="margin-bottom:16px; display:grid; grid-template-columns: 2.2fr 1fr; gap:12px;">
                        <div style="position:relative; width:100%;">
                            <i class="fas fa-magnifying-glass" style="position:absolute; left:12px; top:13px; color:#64748b; font-size:0.85rem;"></i>
                            <input type="text" id="drilldown-search-recip" placeholder="Search by recipient name, phone, or error message..." class="form-control" style="font-size:0.8rem; padding-left:36px !important;" oninput="filterDrilldownRecipients()">
                        </div>
                        <select id="drilldown-status-filter" class="form-control" style="font-size:0.8rem;" onchange="filterDrilldownRecipients()">
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="sent">Sent (Meta Dispatched)</option>
                            <option value="delivered">Delivered</option>
                            <option value="read">Read (Opened)</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>

                    <!-- Recipients Drilldown Table -->
                    <div style="max-height:360px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:12px;">
                        <table style="width:100%; border-collapse:collapse; font-size:0.8rem; text-align:left;">
                            <thead style="background:#f8fafc; position:sticky; top:0; z-index:10; border-bottom:1px solid #e5e7eb;">
                                <tr>
                                    <th style="padding:12px; font-weight:700; color:#475569;">Recipient Name</th>
                                    <th style="padding:12px; font-weight:700; color:#475569;">WhatsApp Phone</th>
                                    <th style="padding:12px; font-weight:700; color:#475569;">Meta Message ID</th>
                                    <th style="padding:12px; font-weight:700; color:#475569;">Queue Status</th>
                                    <th style="padding:12px; font-weight:700; color:#475569;">Delivery Details</th>
                                    <th style="padding:12px; font-weight:700; color:#475569;">Failure Log</th>
                                </tr>
                            </thead>
                            <tbody id="table-body-drilldown-recipients"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Dynamic Preview Confirmation Dialog Overlay -->
<div id="confirm-modal" class="popover-modal-backdrop" onclick="if(event.target===this)closeConfirmModal()">
    <div class="popover-modal" style="max-width:480px;">
        <h4 style="margin-top:0; margin-bottom:12px; font-weight:800; color:#1e293b; font-size:1.1rem; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
            <i class="fas fa-bullhorn" style="color:#7c3aed;"></i> Confirm Marketing Campaign
        </h4>
        
        <p style="font-size:0.82rem; color:#475569; line-height:1.4; margin-bottom:16px;" id="modal-confirm-lead-text">
            You are about to launch a bulk marketing campaign to <strong id="lbl-confirm-recipients-count">0</strong> leads.
        </p>
        
        <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; border-radius:10px; font-size:0.8rem; display:flex; flex-direction:column; gap:6px; margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Campaign:</span><strong id="lbl-confirm-name">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Template:</span><strong id="lbl-confirm-template">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Sender Account:</span><strong id="lbl-confirm-sender" style="color:#7c3aed;">PEPP Updates</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Recipients:</span><strong style="color:#047857;" id="lbl-confirm-recipients">0 leads</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Estimated Time:</span><strong style="color:#4f46e5;" id="lbl-confirm-time">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Schedule:</span><strong id="lbl-confirm-schedule">-</strong></div>
        </div>

        <div style="display:flex; gap:12px; justify-content:flex-end;">
            <button type="button" class="btn btn-outline" onclick="closeConfirmModal()" style="border-radius:8px;">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitCampaignForm()" style="border-radius:8px; font-weight:700;"><i class="fas fa-paper-plane"></i> Confirm &amp; Send Campaign</button>
        </div>
    </div>
</div>

<!-- Meta ID Modal -->
<div id="meta-id-modal" class="popover-modal-backdrop" onclick="if(event.target===this)closeMetaIdModal()">
    <div class="popover-modal">
        <button type="button" class="popover-modal-close" onclick="closeMetaIdModal()">&times;</button>
        <h4 style="margin-top:0; margin-bottom:14px; font-weight:700; color:#1f2937; font-size:1.05rem; display:flex; align-items:center; gap:8px;"><i class="fab fa-whatsapp" style="color:#25d366; font-size:1.25rem;"></i> Meta Message ID</h4>
        
        <div style="background:#f8fafc; border:1.5px solid #cbd5e1; padding:14px; border-radius:10px; font-family:monospace; font-size:0.8rem; word-break:break-all; margin-bottom:20px; color:#334155; min-height:48px;" id="meta-id-text"></div>
        
        <div style="display:flex; gap:12px; justify-content:flex-end;">
            <button type="button" class="btn btn-outline" onclick="closeMetaIdModal()" style="border-radius:8px;">Close</button>
            <button type="button" class="btn btn-primary" onclick="copyMetaId()" id="btn-copy-meta-id" style="border-radius:8px; font-weight:700; display:flex; align-items:center; gap:6px;"><i class="fas fa-copy"></i> Copy ID</button>
        </div>
    </div>
</div>

<!-- Failure Details Modal -->
<div id="failure-detail-modal" class="popover-modal-backdrop" onclick="if(event.target===this)closeFailureDetailModal()">
    <div class="popover-modal" style="max-width:520px;">
        <button type="button" class="popover-modal-close" onclick="closeFailureDetailModal()">&times;</button>
        <h4 style="margin-top:0; margin-bottom:14px; font-weight:700; color:#dc2626; font-size:1.05rem; display:flex; align-items:center; gap:8px;"><i class="fas fa-circle-exclamation" style="font-size:1.2rem;"></i> Dispatch Failure Log</h4>
        
        <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:14px; border-radius:10px; font-size:0.82rem; display:flex; flex-direction:column; gap:8px; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Recipient:</span><strong id="fail-modal-recip">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">WhatsApp Phone:</span><strong id="fail-modal-phone">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Queue Status:</span><span class="badge red" id="fail-modal-status">-</span></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Retries Count:</span><strong id="fail-modal-retries">0</strong></div>
        </div>

        <label style="display:block; font-size:0.75rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Error Message</label>
        <div style="background:#fff5f5; border:1px solid #fee2e2; padding:14px; border-radius:10px; font-size:0.8rem; color:#b91c1c; line-height:1.4; white-space:pre-wrap; margin-bottom:20px; font-family:monospace;" id="failure-log-text"></div>
        
        <div style="text-align:right;">
            <button type="button" class="btn btn-outline" onclick="closeFailureDetailModal()" style="border-radius:8px;">Close</button>
        </div>
    </div>
</div>

<script>
let currentAudience = 'leads';
let currentTemplateMeta = null;
let eligibleRecipientsList = [];
let calculationValid = false;
let currentSenderConfigured = <?php echo $isNotifConfigured ? 'true' : 'false'; ?>;
let currentSenderName = '<?php echo addslashes($isNotifConfigured ? ($notifAccount['display_name'] ?? 'PEPP Updates') : ($admissionsAccount['display_name'] ?? 'PEPP Learning')); ?>';

// Initialize switcher on page load if query parameter specifies leads target
window.addEventListener('DOMContentLoaded', () => {
    updateReviewCard();
});

function onTemplateSelected(tplName) {
    const infoCard = document.getElementById('tpl-info-card');
    const revTpl = document.getElementById('rev-template-name');

    if (!tplName) {
        currentTemplateMeta = null;
        infoCard.style.display = 'none';
        revTpl.innerText = 'None selected';
        document.getElementById('section-variable-mapping').style.display = 'none';
        document.getElementById('section-media-header').style.display = 'none';
        return;
    }

    revTpl.innerText = tplName;
    document.getElementById('tpl-card-name').innerText = tplName;

    // Auto populate campaign name if blank
    const nameInp = document.getElementById('inp-campaign-name');
    if (!nameInp.value || nameInp.value.includes('Campaign')) {
        nameInp.value = tplName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) + ' Campaign';
        updateReviewCard();
    }

    fetch(`communication-campaigns.php?action=ajax_get_template&template_name=${encodeURIComponent(tplName)}`)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.meta) {
                currentTemplateMeta = res.meta;
                infoCard.style.display = 'block';
                document.getElementById('tpl-card-lang').innerText = res.meta.language || 'en';
                document.getElementById('tpl-card-body-preview').innerText = res.meta.body_text || '(No body text preview)';
                renderVariableMappingUI(res.meta);
                renderMediaHeaderUI(res.meta);
                updateVisualCardPreview();
            } else {
                infoCard.style.display = 'none';
            }
        });
}

function onFilterChanged() {
    calculationValid = false;
    document.getElementById('btn-submit-campaign').disabled = true;
    document.getElementById('rev-recip-count').innerText = 'Recalculation needed';
    document.getElementById('rev-est-time').innerText = 'Calculate audience to estimate';
}

function onSenderSelected(key, id, name, isConfigured) {
    document.getElementById('inp-sender-key').value = key;
    document.getElementById('inp-sender-account-id').value = id;
    currentSenderConfigured = isConfigured;
    currentSenderName = name;

    const cardNotif = document.getElementById('sender-card-notifications');
    const cardAdm = document.getElementById('sender-card-admissions');

    if (key === 'notifications') {
        if (cardNotif) { cardNotif.style.borderColor = '#7c3aed'; cardNotif.style.background = '#f5f3ff'; }
        if (cardAdm) { cardAdm.style.borderColor = '#cbd5e1'; cardAdm.style.background = '#fff'; }
    } else {
        if (cardNotif) { cardNotif.style.borderColor = '#cbd5e1'; cardNotif.style.background = '#fff'; }
        if (cardAdm) { cardAdm.style.borderColor = '#7c3aed'; cardAdm.style.background = '#f5f3ff'; }
    }

    updateReviewCard();
}

function updateReviewCard() {
    const nameVal = document.getElementById('inp-campaign-name').value.trim();
    document.getElementById('rev-camp-name').innerText = nameVal || '—';
    document.getElementById('rev-sender-name').innerText = currentSenderName;

    if (!currentSenderConfigured) {
        document.getElementById('btn-submit-campaign').disabled = true;
    } else if (calculationValid) {
        document.getElementById('btn-submit-campaign').disabled = false;
    }
}

function toggleLeadCheckboxes(checked) {
    const checkBoxes = document.querySelectorAll('#leads-courses-checklist input[type="checkbox"]');
    checkBoxes.forEach(c => c.checked = checked);
    onFilterChanged();
}

function toggleScheduleBlock(show) {
    document.getElementById('section-schedule-datetime').style.display = show ? 'grid' : 'none';
    document.getElementById('inp-sched-date').required = show;
    document.getElementById('inp-sched-time').required = show;
}

function renderMediaHeaderUI(meta) {
    const mediaBlock = document.getElementById('section-media-header');
    let headerType = meta.header_type || 'NONE';
    if (headerType === 'NONE' && meta.components) {
        const headerComp = meta.components.find(c => c.type === 'HEADER');
        if (headerComp) {
            headerType = headerComp.format || 'NONE';
        }
    }
    if (headerType !== 'NONE' && headerType !== 'TEXT') {
        mediaBlock.style.display = 'block';
        document.getElementById('inp-media-file').required = true;
    } else {
        mediaBlock.style.display = 'none';
        document.getElementById('inp-media-file').required = false;
        document.getElementById('inp-media-file').value = '';
    }
}

function onMediaFileChange(event) {
    updateVisualCardPreview();
}

function renderVariableMappingUI(meta) {
    const container = document.getElementById('variable-mappings-inputs');
    const panel = document.getElementById('section-variable-mapping');
    container.innerHTML = '';

    // Count variables in template body
    const bodyText = meta.body_text || '';
    const matches = bodyText.match(/\{\{(\d+)\}\}/g);
    const varIndices = matches ? [...new Set(matches.map(m => parseInt(m.replace(/\D/g, ''))))].sort((a,b)=>a-b) : [];

    if (varIndices.length > 0) {
        panel.style.display = 'block';
        varIndices.forEach(idx => {
            const row = document.createElement('div');
            row.style.display = 'grid';
            row.style.gridTemplateColumns = '1.2fr 2fr';
            row.style.gap = '10px';
            row.style.alignItems = 'center';
            row.innerHTML = `
                <span style="font-size:0.75rem; font-weight:700; color:#475569;">Variable {{${idx}}}:</span>
                <div>
                    <select name="vars[${idx}]" class="form-control mapping-select" data-index="${idx}" style="font-size:0.8rem; border-radius:6px; margin-bottom:4px;" onchange="toggleStaticValueInput(${idx}, this.value)" required>
                        <option value="name">Lead/Student Name</option>
                        <option value="interested_course">Course of Interest</option>
                        <option value="whatsapp_number">WhatsApp Phone</option>
                        <option value="last_institute">Last Studied Institute</option>
                        <option value="last_course">Last Studied Course</option>
                        <option value="status">Lead Status</option>
                        <option value="source">Lead Source</option>
                        <option value="assigned_to">Assigned Counselor</option>
                        <option value="static">-- Custom Static Value --</option>
                    </select>
                    <input type="text" name="static_vars[${idx}]" id="inp-static-val-${idx}" class="form-control static-input" placeholder="Enter static text..." style="display:none; font-size:0.75rem; border-radius:6px;" oninput="updateVisualCardPreview()">
                </div>
            `;
            container.appendChild(row);
        });
    } else {
        panel.style.display = 'none';
    }
}

function toggleStaticValueInput(idx, val) {
    const input = document.getElementById(`inp-static-val-${idx}`);
    if (val === 'static') {
        input.style.display = 'block';
        input.required = true;
    } else {
        input.style.display = 'none';
        input.required = false;
        input.value = '';
    }
    updateVisualCardPreview();
}

function calculatePreview() {
    const formData = new FormData();
    const statuses = Array.from(document.querySelectorAll('.chk-status:checked')).map(c => c.value);
    const courses = Array.from(document.querySelectorAll('.chk-course:checked')).map(c => c.value);

    if (statuses.length === 0 || courses.length === 0) {
        alert('Please select at least one course and status filter.');
        return;
    }

    courses.forEach(c => formData.append('courses[]', c));
    statuses.forEach(s => formData.append('statuses[]', s));

    fetch('communication-campaigns.php?action=ajax_preview_audience', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            eligibleRecipientsList = res.recipients;

            // Step 2 Summary Chips
            document.getElementById('chip-recipients-count').innerText = res.eligible_count.toLocaleString();
            document.getElementById('chip-excluded-count').innerText = (res.duplicates + res.opted_out + res.invalid).toLocaleString();
            document.getElementById('chip-dup-count').innerText = res.duplicates;
            document.getElementById('chip-inv-count').innerText = res.invalid;
            document.getElementById('chip-opt-count').innerText = res.opted_out;

            // Step 4 Review Card
            document.getElementById('rev-recip-count').innerText = res.eligible_count.toLocaleString() + ' leads';
            document.getElementById('rev-excluded-count').innerText = (res.duplicates + res.opted_out + res.invalid).toLocaleString();
            document.getElementById('rev-est-time').innerText = res.estimated_time || 'Calculating...';

            // Preview Table Metrics
            document.getElementById('lbl-matching-leads').innerText = res.total_matching.toLocaleString();
            document.getElementById('lbl-duplicates').innerText = res.duplicates.toLocaleString();
            document.getElementById('lbl-opted-out').innerText = res.opted_out.toLocaleString();
            document.getElementById('lbl-invalid').innerText = res.invalid.toLocaleString();
            document.getElementById('lbl-eligible-recipients').innerText = res.eligible_count.toLocaleString();

            renderRecipientPreviewTable(res.recipients);
            document.getElementById('panel-audience-preview').style.display = 'block';

            if (res.eligible_count > 0 && currentSenderConfigured) {
                calculationValid = true;
                document.getElementById('btn-submit-campaign').disabled = false;
            } else {
                calculationValid = false;
                document.getElementById('btn-submit-campaign').disabled = true;
                if (!currentSenderConfigured) {
                    alert('Selected sender account is not configured with a valid WhatsApp phone number ID. Sending is disabled.');
                } else {
                    alert('No eligible recipients match these filters.');
                }
            }
            updateVisualCardPreview();
        } else {
            alert(res.message || 'Audience preview calculation failed.');
        }
    });
}

function renderRecipientPreviewTable(recipients) {
    const tbody = document.getElementById('table-body-preview');
    tbody.innerHTML = '';

    if (recipients.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" style="padding:15px; text-align:center; color:#94a3b8;">No matching leads.</td></tr>`;
        return;
    }

    recipients.forEach(r => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #f1f5f9';
        tr.innerHTML = `
            <td style="padding:8px; font-weight:700;">${escapeHtml(r.name)}</td>
            <td style="padding:8px;">${escapeHtml(r.phone)}</td>
            <td style="padding:8px; color:#64748b;">${escapeHtml(r.course)}</td>
            <td style="padding:8px;"><span class="badge gray" style="font-size:0.65rem;">${escapeHtml(r.status)}</span></td>
        `;
        tbody.appendChild(tr);
    });
}

function updateVisualCardPreview() {
    if (!currentTemplateMeta) return;

    // Header Media Block preview
    let hType = currentTemplateMeta.header_type || 'NONE';
    if (hType === 'NONE' && currentTemplateMeta.components) {
        const headerComp = currentTemplateMeta.components.find(c => c.type === 'HEADER');
        if (headerComp) {
            hType = headerComp.format || 'NONE';
        }
    }
    const previewMedia = document.getElementById('preview-box-header-media');
    const previewMediaIcon = document.getElementById('preview-box-media-icon');
    if (hType !== 'NONE' && hType !== 'TEXT') {
        previewMedia.style.display = 'flex';
        if (hType === 'IMAGE') previewMediaIcon.className = 'fas fa-image';
        else if (hType === 'VIDEO') previewMediaIcon.className = 'fas fa-video';
        else if (hType === 'DOCUMENT') previewMediaIcon.className = 'fas fa-file-pdf';
    } else {
        previewMedia.style.display = 'none';
    }

    // Header Text
    const hText = currentTemplateMeta.header_text || '';
    const headerPreview = document.getElementById('preview-box-header');
    if (hType === 'TEXT' && hText.trim()) {
        headerPreview.style.display = 'block';
        headerPreview.innerText = hText;
    } else {
        headerPreview.style.display = 'none';
    }

    // Body text variable mapping resolution
    let bodyText = currentTemplateMeta.body_text || '';
    const sampleLead = eligibleRecipientsList[0] || { name: 'Sample Name', course: 'Psychology' };

    // Resolve mappings
    const selectMappings = document.querySelectorAll('.mapping-select');
    selectMappings.forEach(select => {
        const idx = select.getAttribute('data-index');
        const fieldVal = select.value;
        let finalVal = `{{${idx}}}`;

        if (fieldVal === 'static') {
            finalVal = document.getElementById(`inp-static-val-${idx}`).value || `{{${idx}}}`;
        } else {
            // Mapping from sample lead snapshot
            if (sampleLead) {
                finalVal = sampleLead[fieldVal] || sampleLead.raw_lead?.[fieldVal] || `{{${idx}}}`;
            }
        }
        bodyText = bodyText.split(`{{${idx}}}`).join(finalVal);
    });

    document.getElementById('preview-box-body').innerText = bodyText;

    // Footer Text
    const fText = currentTemplateMeta.footer_text || '';
    const footerPreview = document.getElementById('preview-box-footer');
    if (fText.trim()) {
        footerPreview.style.display = 'block';
        footerPreview.innerText = fText;
    } else {
        footerPreview.style.display = 'none';
    }

    // Buttons Rendering
    const bubbleButtons = document.getElementById('preview-box-bubble-buttons');
    const floatButtons = document.getElementById('preview-box-floating-buttons');
    bubbleButtons.innerHTML = '';
    floatButtons.innerHTML = '';

    const btnType = currentTemplateMeta.button_type || 'NONE';
    if (btnType === 'QUICK_REPLY' && currentTemplateMeta.buttons?.quick_reply) {
        bubbleButtons.style.display = 'flex';
        floatButtons.style.display = 'none';
        Object.values(currentTemplateMeta.buttons.quick_reply).forEach(txt => {
            if (txt) {
                const btn = document.createElement('div');
                btn.style.background = '#f8fafc';
                btn.style.color = '#3b82f6';
                btn.style.padding = '6px';
                btn.style.textAlign = 'center';
                btn.style.borderRadius = '6px';
                btn.style.fontSize = '0.7rem';
                btn.style.fontWeight = '700';
                btn.style.border = '1px solid #e2e8f0';
                let btnText = txt;
                if (typeof txt === 'object' && txt !== null) {
                    btnText = txt.text || '';
                }
                btn.innerText = btnText;
                bubbleButtons.appendChild(btn);
            }
        });
    } else if (btnType === 'CTA' && currentTemplateMeta.buttons) {
        bubbleButtons.style.display = 'none';
        floatButtons.style.display = 'flex';
        const phone = currentTemplateMeta.buttons.phone_text;
        const url = currentTemplateMeta.buttons.url_text;

        if (phone) {
            const btn = document.createElement('div');
            btn.style.background = '#fff';
            btn.style.color = '#00a884';
            btn.style.padding = '8px';
            btn.style.textAlign = 'center';
            btn.style.borderRadius = '6px';
            btn.style.fontSize = '0.7rem';
            btn.style.fontWeight = '700';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.1)';
            btn.innerHTML = `<i class="fas fa-phone"></i> ${phone}`;
            floatButtons.appendChild(btn);
        }
        if (url) {
            const btn = document.createElement('div');
            btn.style.background = '#fff';
            btn.style.color = '#00a884';
            btn.style.padding = '8px';
            btn.style.textAlign = 'center';
            btn.style.borderRadius = '6px';
            btn.style.fontSize = '0.7rem';
            btn.style.fontWeight = '700';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.1)';
            btn.innerHTML = `<i class="fas fa-arrow-up-right-from-square"></i> ${url}`;
            floatButtons.appendChild(btn);
        }
    } else {
        bubbleButtons.style.display = 'none';
        floatButtons.style.display = 'none';
    }
}

/* ── Form validations & Confirmation Overlay dialog ── */
function validateFormSubmit(event) {
    event.preventDefault();
    if (!calculationValid) {
        alert('Please calculate and preview your target audience before launching.');
        return false;
    }
    if (!currentSenderConfigured) {
        alert('Selected WhatsApp sender account is not configured with a valid phone number ID.');
        return false;
    }

    const recipText = document.getElementById('chip-recipients-count').innerText || '0';
    const campName = document.getElementById('inp-campaign-name').value.trim();
    const tplName = document.getElementById('sel-template-name').value;
    const estTime = document.getElementById('rev-est-time').innerText;

    // Modal populate fields
    document.getElementById('lbl-confirm-name').innerText = campName || 'Untitled Campaign';
    document.getElementById('lbl-confirm-template').innerText = tplName || 'None';
    document.getElementById('lbl-confirm-sender').innerText = currentSenderName;
    document.getElementById('lbl-confirm-recipients-count').innerText = recipText;
    document.getElementById('lbl-confirm-recipients').innerText = recipText + ' leads';
    document.getElementById('lbl-confirm-time').innerText = estTime;

    const isSched = document.querySelector('input[name="schedule_type"]:checked').value === 'schedule';
    if (isSched) {
        document.getElementById('lbl-confirm-schedule').innerText = `${document.getElementById('inp-sched-date').value} at ${document.getElementById('inp-sched-time').value}`;
    } else {
        document.getElementById('lbl-confirm-schedule').innerText = 'Send Immediately (ASAP)';
    }

    document.getElementById('confirm-modal').style.display = 'flex';
    return false;
}

function closeConfirmModal() {
    document.getElementById('confirm-modal').style.display = 'none';
}

function submitCampaignForm() {
    document.getElementById('confirm-modal').style.display = 'none';
    document.getElementById('campaign-create-form').submit();
}

/* ─────────────────────────────────────────────────────────────────────────────
   Campaign Drilldown Statistics details panel
   ───────────────────────────────────────────────────────────────────────────── */
let activeCampaignRecipients = [];

function loadCampaignDrilldown(campId) {
    fetch(`communication-campaigns.php?action=ajax_campaign_details&campaign_id=${campId}`)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                const c = res.campaign;
                activeCampaignRecipients = res.recipients;

                document.getElementById('drilldown-camp-id').value = c.id;
                document.getElementById('drilldown-title').innerText = c.name;
                document.getElementById('drilldown-subtitle').innerHTML = `
                    <span style="font-size:0.75rem; color:#475569; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fas fa-layer-group" style="color:#94a3b8; font-size:0.8rem;"></i> Template: <strong style="color:#1e293b;">${escapeHtml(c.template_name)}</strong>
                    </span>
                    <span style="font-size:0.75rem; color:#cbd5e1;">•</span>
                    <span style="font-size:0.75rem; color:#475569; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fas fa-users" style="color:#94a3b8; font-size:0.8rem;"></i> Target: <span class="badge gray" style="font-size:0.65rem; font-weight:700;">${escapeHtml(c.target_audience.toUpperCase())}</span>
                    </span>
                `;

                // Adjust Action control buttons
                if (c.status === 'paused') {
                    document.getElementById('btn-ctrl-pause').style.display = 'none';
                    document.getElementById('btn-ctrl-resume').style.display = 'inline-flex';
                } else if (c.status === 'completed' || c.status === 'cancelled') {
                    document.getElementById('btn-ctrl-pause').style.display = 'none';
                    document.getElementById('btn-ctrl-resume').style.display = 'none';
                    document.getElementById('btn-ctrl-cancel').style.display = 'none';
                } else {
                    document.getElementById('btn-ctrl-pause').style.display = 'inline-flex';
                    document.getElementById('btn-ctrl-resume').style.display = 'none';
                    document.getElementById('btn-ctrl-cancel').style.display = 'inline-flex';
                }

                // Render metrics info grid
                const grid = document.getElementById('drilldown-metrics-grid');
                grid.innerHTML = '';

                const total = res.recipients.length;
                const sent = res.recipients.filter(r => r.queue_status === 'sent' || r.queue_status === 'delivered' || r.queue_status === 'read').length;
                const read = res.recipients.filter(r => r.queue_status === 'read').length;
                const failed = res.recipients.filter(r => r.status === 'failed' || r.queue_status === 'failed').length;

                grid.innerHTML = `
                    <div class="kpi-card">
                        <div class="kpi-icon audience"><i class="fas fa-users"></i></div>
                        <div class="kpi-info">
                            <span class="kpi-value">${total}</span>
                            <span class="kpi-label">Audience Size</span>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon sent"><i class="fas fa-paper-plane"></i></div>
                        <div class="kpi-info">
                            <span class="kpi-value">${sent}</span>
                            <span class="kpi-label">Sent</span>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon read"><i class="fas fa-check-double"></i></div>
                        <div class="kpi-info">
                            <span class="kpi-value">${read}</span>
                            <span class="kpi-label">Read Receipts</span>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon failed"><i class="fas fa-circle-xmark"></i></div>
                        <div class="kpi-info">
                            <span class="kpi-value">${failed}</span>
                            <span class="kpi-label">Failed</span>
                        </div>
                    </div>
                `;

                // Render recipients table
                renderDrilldownRecipientsTable(activeCampaignRecipients);

                document.getElementById('panel-campaigns-list').style.display = 'none';
                document.getElementById('panel-campaign-drilldown').style.display = 'block';
            } else {
                alert(res.message);
            }
        });
}

function closeCampaignDrilldown() {
    document.getElementById('panel-campaigns-list').style.display = 'block';
    document.getElementById('panel-campaign-drilldown').style.display = 'none';
}

function renderDrilldownRecipientsTable(list) {
    const tbody = document.getElementById('table-body-drilldown-recipients');
    tbody.innerHTML = '';

    if (list.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="padding:15px; text-align:center; color:#94a3b8;">No recipients found.</td></tr>`;
        return;
    }

    list.forEach(r => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #f1f5f9';
        
        let qStatusClass = 'gray';
        if (r.queue_status === 'sent') qStatusClass = 'green';
        else if (r.queue_status === 'read') qStatusClass = 'blue';
        else if (r.queue_status === 'failed' || r.status === 'failed') qStatusClass = 'red';
        else if (r.queue_status === 'processing') qStatusClass = 'orange';
        else if (r.queue_status === 'delivered') qStatusClass = 'green';

        // Avatar Initial
        const initial = r.recipient_name ? r.recipient_name.charAt(0) : 'R';

        // Meta Message ID column
        let metaIdCol = '-';
        if (r.message_id) {
            metaIdCol = `<button type="button" class="btn btn-sm btn-outline" style="border-radius:6px; font-size:0.7rem; padding:4px 8px; display:inline-flex; align-items:center; gap:4px;" onclick="openMetaIdModal('${escapeHtml(r.message_id)}')"><i class="fas fa-eye"></i> View Meta ID</button>
                         <div style="font-size:0.65rem; color:#64748b; margin-top:4px;">Queue ID: ${escapeHtml(r.queue_id || '-')}</div>`;
        }

        // Delivery details column
        let deliveryCol = '';
        if (r.sent_at) deliveryCol += `<div style="font-size:0.7rem; color:#64748b;">Sent: ${escapeHtml(r.sent_at)}</div>`;
        if (r.delivered_at) deliveryCol += `<div style="font-size:0.7rem; color:#059669;">Delivered: ${escapeHtml(r.delivered_at)}</div>`;
        if (r.read_at) deliveryCol += `<div style="font-size:0.7rem; color:#2563eb;">Read: ${escapeHtml(r.read_at)}</div>`;
        if (!deliveryCol) deliveryCol = '<span style="color:#94a3b8;">-</span>';

        // Failure log column
        let error = r.error_message || r.queue_error;
        let failureCol = '-';
        if (error) {
            failureCol = `<button type="button" class="btn btn-sm btn-outline" style="border-radius:6px; border-color:#fee2e2; color:#dc2626; background:#fef2f2; font-size:0.7rem; padding:4px 8px; display:inline-flex; align-items:center; gap:4px;" onclick="openFailureModal('${escapeHtml(r.recipient_name)}', '${escapeHtml(r.recipient)}', '${escapeHtml(qStatusClass.toUpperCase())}', '${escapeHtml(r.retry_count || 0)}', '${escapeHtml(error)}')"><i class="fas fa-circle-exclamation"></i> View Error</button>`;
        }

        tr.innerHTML = `
            <td style="padding:12px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div class="table-avatar">${escapeHtml(initial)}</div>
                    <div style="font-weight:700; color:#1e293b;">${escapeHtml(r.recipient_name)}</div>
                </div>
            </td>
            <td style="padding:12px; font-weight:600; color:#475569;">
                <div style="display:flex; align-items:center; gap:6px;">
                    <i class="fab fa-whatsapp" style="color:#25d366; font-size:0.9rem;"></i>
                    ${escapeHtml(r.recipient)}
                </div>
            </td>
            <td style="padding:12px;">${metaIdCol}</td>
            <td style="padding:12px;">
                <span class="badge ${qStatusClass}" style="font-size:0.68rem; font-weight:700; text-transform:uppercase;">${escapeHtml(r.queue_status || r.status || 'pending')}</span>
                <div style="font-size:0.65rem; color:#64748b; margin-top:4px;">Retries: ${r.retry_count !== null && r.retry_count !== undefined ? r.retry_count : 0}</div>
            </td>
            <td style="padding:12px;">${deliveryCol}</td>
            <td style="padding:12px;">${failureCol}</td>
        `;
        tbody.appendChild(tr);
    });
}

function filterDrilldownRecipients() {
    const query = document.getElementById('drilldown-search-recip').value.toLowerCase();
    const statusVal = document.getElementById('drilldown-status-filter').value;

    const filtered = activeCampaignRecipients.filter(r => {
        const matchesQuery = r.recipient_name.toLowerCase().includes(query) || 
                             r.recipient.toLowerCase().includes(query) || 
                             (r.error_message && r.error_message.toLowerCase().includes(query));
        
        const matchesStatus = !statusVal || (r.queue_status === statusVal);
        return matchesQuery && matchesStatus;
    });

    renderDrilldownRecipientsTable(filtered);
}

function triggerCampaignControl(action) {
    const campId = document.getElementById('drilldown-camp-id').value;
    
    if (action === 'cancel' && !confirm('Are you sure you want to cancel all pending messages for this campaign? Already sent messages cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('campaign_id', campId);
    formData.append('control_action', action);
    formData.append('csrf_token', '<?php echo csrf_token(); ?>');

    fetch('communication-campaigns.php?action=ajax_campaign_control', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        alert(res.message);
        if (res.success) {
            loadCampaignDrilldown(campId);
        }
    });
}

// Report dropdown controls
function toggleReportDropdown(event) {
    event.stopPropagation();
    const menu = document.getElementById('report-dropdown-menu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
}

function triggerReportDownload(format) {
    const campId = document.getElementById('drilldown-camp-id').value;
    window.location.href = `communication-campaigns.php?action=download_report&campaign_id=${campId}&format=${format}`;
    document.getElementById('report-dropdown-menu').style.display = 'none';
}

// Close report dropdown on click outside
window.addEventListener('click', () => {
    const menu = document.getElementById('report-dropdown-menu');
    if (menu) menu.style.display = 'none';
});

// Modal popups controls
function openMetaIdModal(metaId) {
    document.getElementById('meta-id-text').innerText = metaId;
    const btn = document.getElementById('btn-copy-meta-id');
    btn.innerHTML = '<i class="fas fa-copy"></i> Copy ID';
    btn.style.background = '';
    btn.style.borderColor = '';
    document.getElementById('meta-id-modal').style.display = 'flex';
}

document.addEventListener('click', function(e) {
    const menu = document.getElementById('report-dropdown-menu');
    if (menu && !e.target.closest('.report-dropdown')) {
        menu.style.display = 'none';
    }
});

function closeMetaIdModal() {
    document.getElementById('meta-id-modal').style.display = 'none';
}

function copyMetaId() {
    const metaId = document.getElementById('meta-id-text').innerText;
    navigator.clipboard.writeText(metaId).then(() => {
        const btn = document.getElementById('btn-copy-meta-id');
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.style.background = '#10b981';
        btn.style.borderColor = '#10b981';
        setTimeout(() => {
            btn.innerHTML = '<i class="fas fa-copy"></i> Copy ID';
            btn.style.background = '';
            btn.style.borderColor = '';
        }, 2000);
    });
}

function openFailureModal(name, phone, status, retries, errorLog) {
    document.getElementById('fail-modal-recip').innerText = name;
    document.getElementById('fail-modal-phone').innerText = phone;
    document.getElementById('fail-modal-status').innerText = status;
    document.getElementById('fail-modal-retries').innerText = retries;
    document.getElementById('failure-log-text').innerText = errorLog;
    document.getElementById('failure-detail-modal').style.display = 'flex';
}

function closeFailureDetailModal() {
    document.getElementById('failure-detail-modal').style.display = 'none';
}

// ESC key support to close modals
window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeMetaIdModal();
        closeFailureDetailModal();
        closeConfirmModal();
    }
});

// Visual layout helper tools
function escapeHtml(text) {
    if (!text) return '';
    return text
        .toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>

<?php include 'includes/admin_footer.php'; ?>
