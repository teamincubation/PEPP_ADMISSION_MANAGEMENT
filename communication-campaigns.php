<?php
/**
 * PEPP Learning ERP - Bulk Communication Campaigns Page (Phase 2).
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
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

        echo json_encode([
            'success' => true,
            'total_matching' => $totalMatching,
            'eligible_count' => count($eligibleList),
            'duplicates' => $duplicates,
            'opted_out' => $optedOut,
            'invalid' => $invalid,
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
            // Fetch failed recipients
            $stmtFailed = $pdo->prepare("
                SELECT r.*, q.template_name, q.template_data, q.attachments, q.subject, q.body_html, q.body_text 
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
            $engine = CommunicationEngine::getInstance($pdo);

            $pdo->beginTransaction();
            try {
                $retriedCount = 0;
                foreach ($failedRecipients as $rec) {
                    $tplData = json_decode($rec['template_data'], true) ?: [];
                    $attData = json_decode($rec['attachments'], true) ?: [];

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
                        null,
                        null
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
        $targetAudience = $_POST['target_audience'] ?? 'students';
        $templateName = $_POST['template_name'] ?? '';
        
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
                    // Build public URL safely
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $headerMediaUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/uploads/whatsapp_campaign_media/' . $safeName;
                } else {
                    $error_message = 'Failed to save uploaded media file.';
                }
            }
        }

        if (empty($error_message)) {
            if (!$campaignName) {
                $error_message = 'Please specify a campaign name.';
            } elseif (!$templateName) {
                $error_message = 'Please select a Meta Template for the WhatsApp campaign.';
            } else {
                // Fetch template metadata to compile mappings
                $stmtTpl = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? AND status='approved' LIMIT 1");
                $stmtTpl->execute([$templateName]);
                $template = $stmtTpl->fetch();

                if (!$template) {
                    $error_message = 'Selected template is missing or not approved.';
                } else {
                    $meta = json_decode($template['meta_data'], true) ?: [];
                    $varMappings = $_POST['vars'] ?? [];
                    $staticVals = $_POST['static_vars'] ?? [];

                    // ── Target segmentation ──
                    $recipients = [];
                    $segmentCriteria = [];

                    if ($targetAudience === 'leads') {
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
                                'header_media' => $headerMediaUrl
                            ];
                        }
                    } else {
                        // LEGACY Student targeting configuration
                        $targetCourse = $_POST['target_course'] ?? '';
                        $targetStatus = $_POST['target_status'] ?? '';

                        $where = ["whatsapp_number IS NOT NULL AND whatsapp_number <> ''"];
                        $params = [];
                        if ($targetCourse) {
                            $where[] = "pepp_course = ?";
                            $params[] = $targetCourse;
                        }
                        if ($targetStatus) {
                            $where[] = "status = ?";
                            $params[] = $targetStatus;
                        } else {
                            $where[] = "status IN ('approved', 'pending')";
                        }

                        $queryStr = "SELECT user_id, name, whatsapp_country_code, whatsapp_number, pepp_course, status FROM users WHERE " . implode(' AND ', $where);
                        $stmtUsers = $pdo->prepare($queryStr);
                        $stmtUsers->execute($params);
                        $students = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($students as $stu) {
                            $phone = clean_wa_phone($stu['whatsapp_country_code'] . $stu['whatsapp_number']);
                            $recipients[] = [
                                'lead_id' => null,
                                'user_id' => $stu['user_id'],
                                'name' => $stu['name'],
                                'phone' => $phone,
                                'course' => $stu['pepp_course'],
                                'status' => $stu['status'],
                                'raw_student' => $stu
                            ];
                        }
                        $segmentCriteria = [
                            'target_audience' => 'students',
                            'course' => $targetCourse,
                            'status' => $targetStatus,
                            'var_mappings' => $varMappings,
                            'static_vals' => $staticVals,
                            'header_media' => $headerMediaUrl
                        ];
                    }

                    if (empty($recipients)) {
                        $error_message = 'No eligible recipients found matching the target segmentation criteria.';
                    } else {
                        // Calculate scheduling datetime
                        $nextAttempt = 'NOW()';
                        $scheduledAtVal = null;
                        $campaignStatus = 'active';

                        if ($scheduleType === 'schedule' && !empty($scheduleDate) && !empty($scheduleTime)) {
                            $scheduledAtVal = $scheduleDate . ' ' . $scheduleTime . ':00';
                            $nextAttempt = '?' ;
                            $campaignStatus = 'scheduled';
                        }

                        $pdo->beginTransaction();
                        try {
                            // Insert Campaign record
                            $stmtCamp = $pdo->prepare("
                                INSERT INTO communication_campaigns (name, channel, target_audience, template_name, segment_criteria, status, scheduled_at, created_by, created_at, updated_at) 
                                VALUES (?, 'whatsapp', ?, ?, ?, ?, ?, ?, NOW(), NOW())
                            ");
                            $stmtCamp->execute([
                                $campaignName,
                                $targetAudience,
                                $templateName,
                                json_encode($segmentCriteria),
                                $campaignStatus,
                                $scheduledAtVal,
                                $admin_username
                            ]);
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

                            $success_message = "Campaign successfully configured and snapshot saved! Enqueued {$queuedCount} recipients for background dispatch.";
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

$studentCourses = [];
try {
    $studentCourses = $pdo->query("SELECT DISTINCT pepp_course FROM users WHERE pepp_course IS NOT NULL AND pepp_course <> '' ORDER BY pepp_course ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {}

include 'includes/admin_nav.php';
?>

<div class="container-fluid" style="padding:20px;">
    <style>
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
        gap: 10px;
        margin-bottom: 16px;
        border-bottom: 1px dashed #e5e7eb;
        padding-bottom: 10px;
    }
    .form-step-number {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: var(--accent-soft);
        color: var(--accent-dark);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.8rem;
    }
    .form-step-title {
        font-size: 0.82rem;
        font-weight: 700;
        color: #1e293b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
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
        gap: 10px;
        padding: 10px 14px;
        border: 1.5px solid #cbd5e1;
        border-radius: 10px;
        cursor: pointer;
        font-size: 0.8rem;
        font-weight: 500;
        background: #fff;
        transition: all 0.15s ease;
    }
    .chk-card-label:hover {
        border-color: #94a3b8;
        background: #f8fafc;
    }
    .chk-card-label input[type="checkbox"] {
        width: 16px;
        height: 16px;
        border-radius: 4px;
        accent-color: var(--accent-dark);
        cursor: pointer;
    }
    .chk-card-label.checked {
        border-color: var(--accent-dark);
        background: var(--accent-soft);
        color: var(--accent-dark);
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
        background: var(--accent-soft);
        color: var(--accent-dark);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        border: 1.5px solid var(--border);
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
        color: var(--accent-dark);
        text-decoration: none !important;
    }
    .report-dropdown-item i {
        font-size: 0.9rem;
    }

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
        box-shadow: var(--shadow-md);
        position: relative;
        border: 1px solid var(--border);
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
    <div style="display:grid; grid-template-columns: 1.1fr 1.9fr; gap:20px; align-items:start;">
        
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
                    <input type="hidden" name="target_audience" id="inp-target-audience" value="leads">
                    
                    <!-- STEP 1: CAMPAIGN DETAILS -->
                    <div class="form-step-section">
                        <div class="form-step-header">
                            <div class="form-step-number">1</div>
                            <div class="form-step-title">Campaign Details</div>
                        </div>
                        <div style="margin-bottom:0;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Campaign Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="campaign_name" id="inp-campaign-name" class="form-control" placeholder="e.g. CUET PG August Admission Campaign" style="font-size:0.82rem;" required>
                        </div>
                    </div>

                    <!-- STEP 2: AUDIENCE FILTERS -->
                    <div class="form-step-section">
                        <div class="form-step-header">
                            <div class="form-step-number">2</div>
                            <div class="form-step-title">Audience Filters</div>
                        </div>
                        
                        <!-- Leads Target Filters Section -->
                        <div id="section-target-leads" style="display:block;">
                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-size:0.75rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Lead Statuses <span style="color:#ef4444;">*</span></label>
                                <div class="chk-card-grid">
                                    <label class="chk-card-label checked">
                                        <input type="checkbox" name="target_leads_statuses[]" value="new" checked class="chk-status" onchange="triggerAudiencePreview(); this.parentElement.classList.toggle('checked', this.checked);"> New
                                    </label>
                                    <label class="chk-card-label checked">
                                        <input type="checkbox" name="target_leads_statuses[]" value="contacted" checked class="chk-status" onchange="triggerAudiencePreview(); this.parentElement.classList.toggle('checked', this.checked);"> Contacted
                                    </label>
                                    <label class="chk-card-label checked">
                                        <input type="checkbox" name="target_leads_statuses[]" value="interested" checked class="chk-status" onchange="triggerAudiencePreview(); this.parentElement.classList.toggle('checked', this.checked);"> Interested
                                    </label>
                                    <label class="chk-card-label checked">
                                        <input type="checkbox" name="target_leads_statuses[]" value="follow_up" checked class="chk-status" onchange="triggerAudiencePreview(); this.parentElement.classList.toggle('checked', this.checked);"> Follow-up
                                    </label>
                                </div>
                            </div>

                            <div>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <label style="font-size:0.75rem; font-weight:700; color:#4b5563;">PEPP Course Targets <span style="color:#ef4444;">*</span></label>
                                    <div style="display:flex; gap:6px;">
                                        <button type="button" onclick="toggleLeadCheckboxes(true)" style="border:none; background:none; font-size:0.65rem; color:#8b5cf6; font-weight:700; cursor:pointer; padding:0;">Select All</button>
                                        <span style="font-size:0.65rem; color:#94a3b8;">|</span>
                                        <button type="button" onclick="toggleLeadCheckboxes(false)" style="border:none; background:none; font-size:0.65rem; color:#ef4444; font-weight:700; cursor:pointer; padding:0;">Clear All</button>
                                    </div>
                                </div>
                                <div style="max-height:150px; overflow-y:auto; border:1.5px solid #cbd5e1; border-radius:10px; padding:10px; background:#fff;" id="leads-courses-checklist">
                                    <?php foreach ($leadCourses as $lc): ?>
                                        <label style="font-size:0.75rem; display:flex; align-items:center; gap:8px; margin-bottom:6px; cursor:pointer;"><input type="checkbox" name="target_leads_courses[]" value="<?php echo htmlspecialchars($lc); ?>" class="chk-course" onchange="triggerAudiencePreview()" style="width:15px; height:15px; accent-color:var(--accent-dark);"> <?php echo htmlspecialchars($lc); ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Students Target Filters Section -->
                        <div id="section-target-students" style="display:none;">
                            <div style="display:flex; flex-direction:column; gap:12px;">
                                <div>
                                    <label style="font-size:0.75rem; font-weight:700; color:#4b5563; display:block; margin-bottom:4px;">Enrolled Course</label>
                                    <select name="target_course" class="form-control" style="font-size:0.8rem;">
                                        <option value="">All Courses</option>
                                        <?php foreach ($studentCourses as $sc): ?>
                                            <option value="<?php echo htmlspecialchars($sc); ?>"><?php echo htmlspecialchars($sc); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:0.75rem; font-weight:700; color:#4b5563; display:block; margin-bottom:4px;">Approval Status</label>
                                    <select name="target_status" class="form-control" style="font-size:0.8rem;">
                                        <option value="">Approved &amp; Pending</option>
                                        <option value="approved">Approved Only</option>
                                        <option value="pending">Pending Only</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 3: MESSAGE CONTENT -->
                    <div class="form-step-section">
                        <div class="form-step-header">
                            <div class="form-step-number">3</div>
                            <div class="form-step-title">Message Content</div>
                        </div>
                        
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Meta Approved Template <span style="color:#ef4444;">*</span></label>
                            <select name="template_name" id="sel-template-name" class="form-control" onchange="loadTemplateDetails(this.value)" required>
                                <option value="">-- Select Marketing Template --</option>
                                <?php foreach ($marketingTemplates as $m_tpl): ?>
                                    <option value="<?php echo htmlspecialchars($m_tpl['template_name']); ?>"><?php echo htmlspecialchars($m_tpl['template_name']); ?> (<?php echo $m_tpl['language']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Upload Image Header Block -->
                        <div id="section-media-header" style="display:none; border:1.5px solid #cbd5e1; border-radius:12px; padding:12px; background:#fcfcfc; margin-bottom:12px;">
                            <label style="display:block; font-size:0.75rem; font-weight:700; color:#1e293b; margin-bottom:6px;"><i class="fas fa-file-image" style="color:var(--accent-dark);"></i> Required Header Media File</label>
                            <input type="file" name="header_media_file" id="inp-media-file" class="form-control" style="font-size:0.75rem; height:auto !important; padding:6px 12px !important;" accept="image/*,video/mp4,application/pdf" onchange="onMediaFileChange(event)">
                            <span style="font-size:0.65rem; color:#64748b; display:block; margin-top:4px;">Select JPG, PNG, MP4, or PDF. Max file size: 5MB.</span>
                        </div>

                        <!-- Dynamic Variables Mapping Block -->
                        <div id="section-variable-mapping" style="display:none; border:1.5px solid #cbd5e1; border-radius:12px; padding:12px; background:#f8fafc; margin-bottom:0;">
                            <span style="font-size:0.75rem; font-weight:700; color:#4b5563; display:block; margin-bottom:8px;"><i class="fas fa-brackets-curly" style="color:#6366f1;"></i> Variable Mappings</span>
                            <div id="variable-mappings-inputs" style="display:flex; flex-direction:column; gap:10px;"></div>
                        </div>
                    </div>

                    <!-- STEP 4: SCHEDULING -->
                    <div class="form-step-section">
                        <div class="form-step-header">
                            <div class="form-step-number">4</div>
                            <div class="form-step-title">Scheduling</div>
                        </div>
                        
                        <div style="margin-bottom:0;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;"><i class="fas fa-clock" style="color:var(--accent-dark);"></i> Launch Schedule</label>
                            <div style="display:flex; gap:16px; margin-bottom:12px;">
                                <label style="font-size:0.78rem; display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600;"><input type="radio" name="schedule_type" value="now" checked onchange="toggleScheduleBlock(false)" style="width:16px; height:16px; accent-color:var(--accent-dark);"> Send Immediately</label>
                                <label style="font-size:0.78rem; display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600;"><input type="radio" name="schedule_type" value="schedule" onchange="toggleScheduleBlock(true)" style="width:16px; height:16px; accent-color:var(--accent-dark);"> Schedule Launch</label>
                            </div>
                            <div id="section-schedule-datetime" style="display:none; grid-template-columns:1fr 1fr; gap:10px; border:1.5px solid #cbd5e1; padding:10px; border-radius:10px; background:#f8fafc;">
                                <div>
                                    <label style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase; margin-bottom:4px; display:block;">Date</label>
                                    <input type="date" name="schedule_date" id="inp-sched-date" class="form-control" style="font-size:0.8rem;">
                                </div>
                                <div>
                                    <label style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase; margin-bottom:4px; display:block;">Time</label>
                                    <input type="time" name="schedule_time" id="inp-sched-time" class="form-control" style="font-size:0.8rem;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Calculate Button -->
                    <button type="button" onclick="calculatePreview()" class="btn btn-outline" style="width:100%; border-radius:10px; font-weight:700; margin-bottom:12px; padding:12px; height:44px; display:flex; align-items:center; justify-content:center; gap:8px;"><i class="fas fa-calculator"></i> Calculate &amp; Preview Recipients</button>

                    <!-- Launch Submit Button -->
                    <button type="submit" class="btn btn-primary" id="btn-submit-campaign" style="width:100%; border-radius:10px; font-weight:700; padding:12px; height:44px; display:flex; align-items:center; justify-content:center; gap:8px;" disabled><i class="fas fa-rocket"></i> Confirm &amp; Queue Campaign</button>
                </form>
            </div>
        </div>

        <!-- Right Column: Campaigns Dashboard list and dynamic details drilldown -->
        <div>
            <!-- Active Campaigns List -->
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.02); margin-bottom:20px;" id="panel-campaigns-list">
                <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px;">
                    <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-chart-column" style="margin-right:4px;"></i> Active &amp; Completed Campaigns (<?php echo count($campaigns); ?>)</h3>
                </div>
                
                <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                    <thead>
                        <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                            <th style="padding:12px; font-weight:700; color:#374151;">Campaign Details</th>
                            <th style="padding:12px; font-weight:700; color:#374151;">Target</th>
                            <th style="padding:12px; font-weight:700; color:#374151;">Audience</th>
                            <th style="padding:12px; font-weight:700; color:#374151;">Progress</th>
                            <th style="padding:12px; font-weight:700; color:#374151; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campaigns)): ?>
                            <tr>
                                <td colspan="5" style="padding:30px; text-align:center; color:#9ca3af;">No bulk campaigns have been created yet.</td>
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
                                    
                                    $statusColor = 'gray';
                                    if ($camp['status'] === 'active') $statusColor = 'blue';
                                    elseif ($camp['status'] === 'completed') $statusColor = 'green';
                                    elseif ($camp['status'] === 'paused') $statusColor = 'orange';
                                    elseif ($camp['status'] === 'cancelled') $statusColor = 'red';
                                ?>
                                <tr style="border-bottom:1px solid #f3f4f6;">
                                    <td style="padding:12px;">
                                        <div style="font-weight:700; color:#111827;"><?php echo htmlspecialchars($camp['name']); ?></div>
                                        <div style="font-size:0.7rem; color:#9ca3af; margin-top:2px;">Template: <b><?php echo htmlspecialchars($camp['template_name']); ?></b></div>
                                    </td>
                                    <td style="padding:12px;"><span class="badge gray" style="font-size:0.65rem; font-weight:700;"><?php echo strtoupper($camp['target_audience']); ?></span></td>
                                    <td style="padding:12px; font-weight:700;"><?php echo $recipCount; ?></td>
                                    <td style="padding:12px;">
                                        <div style="display:flex; justify-content:space-between; font-size:0.7rem; font-weight:700; color:#475569; margin-bottom:4px;">
                                            <span>Progress: <?php echo $prog; ?>%</span>
                                        </div>
                                        <div style="display:flex; flex-direction:column; gap:6px;">
                                            <div style="width:100%; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden;">
                                                <div style="width:<?php echo $prog; ?>%; height:100%; background:<?php echo ($failCount > 0 ? '#ef4444' : '#10b981'); ?>;"></div>
                                            </div>
                                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                                <span style="font-size:0.68rem; padding:2px 6px; background:#f1f5f9; color:#475569; border-radius:4px; font-weight:700;" title="Sent">
                                                    <i class="fas fa-paper-plane" style="margin-right:2px; font-size:0.6rem;"></i> <?php echo $sentCount; ?> S
                                                </span>
                                                <span style="font-size:0.68rem; padding:2px 6px; background:#e0f2fe; color:#0369a1; border-radius:4px; font-weight:700;" title="Delivered">
                                                    <i class="fas fa-check" style="margin-right:2px; font-size:0.6rem;"></i> <?php echo $deliveredCount; ?> D
                                                </span>
                                                <span style="font-size:0.68rem; padding:2px 6px; background:#dcfce7; color:#15803d; border-radius:4px; font-weight:700;" title="Read">
                                                    <i class="fas fa-check-double" style="margin-right:2px; font-size:0.6rem;"></i> <?php echo $readCount; ?> R
                                                </span>
                                                <span style="font-size:0.68rem; padding:2px 6px; background:#fee2e2; color:#b91c1c; border-radius:4px; font-weight:700;" title="Failed">
                                                    <i class="fas fa-circle-xmark" style="margin-right:2px; font-size:0.6rem;"></i> <?php echo $failCount; ?> F
                                                </span>
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
                <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:#1f2937;" id="drilldown-title">Campaign Detail</h3>
                        <p style="margin:4px 0 0; font-size:0.78rem; color:#64748b;" id="drilldown-subtitle"></p>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <button type="button" onclick="closeCampaignDrilldown()" class="btn btn-outline" style="border-radius:8px; font-size:0.8rem; height:36px; display:flex; align-items:center; gap:6px;"><i class="fas fa-chevron-left"></i> Back to Dashboard</button>
                        
                        <!-- Download Report Dropdown -->
                        <div class="report-dropdown">
                            <button type="button" class="btn btn-primary" onclick="toggleReportDropdown(event)" style="border-radius:8px; font-size:0.8rem; height:36px; display:flex; align-items:center; gap:6px; font-weight:700;"><i class="fas fa-file-export"></i> Download Report <i class="fas fa-chevron-down" style="font-size:0.7rem;"></i></button>
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
                    <div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; border-bottom:1px dashed #e2e8f0; padding-bottom:14px;">
                        <input type="hidden" id="drilldown-camp-id">
                        <button type="button" id="btn-ctrl-pause" onclick="triggerCampaignControl('pause')" class="btn btn-sm btn-outline" style="border-radius:8px; border-color:#f59e0b; color:#d97706; font-weight:700;"><i class="fas fa-pause"></i> Pause campaign</button>
                        <button type="button" id="btn-ctrl-resume" onclick="triggerCampaignControl('resume')" class="btn btn-sm btn-outline" style="border-radius:8px; border-color:#10b981; color:#059669; display:none; font-weight:700;"><i class="fas fa-play"></i> Resume campaign</button>
                        <button type="button" id="btn-ctrl-cancel" onclick="triggerCampaignControl('cancel')" class="btn btn-sm btn-outline" style="border-radius:8px; border-color:#ef4444; color:#dc2626; font-weight:700;"><i class="fas fa-stop"></i> Cancel campaign</button>
                        <button type="button" id="btn-ctrl-retry" onclick="triggerCampaignControl('retry')" class="btn btn-sm btn-success" style="border-radius:8px; font-weight:700;"><i class="fas fa-arrow-rotate-forward"></i> Retry Failed dispatches</button>
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
        <h4 style="margin-top:0; margin-bottom:12px; font-weight:800; color:#1e293b; font-size:1.1rem; border-bottom:1px solid #e2e8f0; padding-bottom:8px;"><i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> Confirm Campaign Launch</h4>
        
        <p style="font-size:0.82rem; color:#64748b; line-height:1.4; margin-bottom:16px;">You are about to launch a bulk campaign. Messages will be enqueued in the communication queue and processed asynchronously by the background runner.</p>
        
        <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; border-radius:10px; font-size:0.8rem; display:flex; flex-direction:column; gap:6px; margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Campaign:</span><strong id="lbl-confirm-name">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Target Audience:</span><strong id="lbl-confirm-audience">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Total Recipients:</span><strong style="color:#10b981;" id="lbl-confirm-recipients">0</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">WhatsApp Template:</span><strong id="lbl-confirm-template">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Schedule:</span><strong id="lbl-confirm-schedule">-</strong></div>
        </div>

        <div style="display:flex; gap:12px; justify-content:flex-end;">
            <button type="button" class="btn btn-outline" onclick="closeConfirmModal()" style="border-radius:8px;">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitCampaignForm()" style="border-radius:8px; font-weight:700;"><i class="fas fa-rocket"></i> Confirm &amp; Queue Campaign</button>
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

// Initialize switcher on page load if query parameter specifies leads target
window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('target') === 'leads') {
        switchAudience('leads');
    }
});

function switchAudience(target) {
    currentAudience = target;
    document.getElementById('inp-target-audience').value = target;
    calculationValid = false;
    document.getElementById('btn-submit-campaign').disabled = true;

    if (target === 'leads') {
        document.getElementById('section-target-leads').style.display = 'block';
        document.getElementById('section-target-students').style.display = 'none';
        document.getElementById('btn-tab-leads').style.background = '#f1f5f9';
        document.getElementById('btn-tab-leads').style.color = '#475569';
        document.getElementById('btn-tab-students').style.background = '#fff';
        document.getElementById('btn-tab-students').style.color = '#64748b';
    } else {
        document.getElementById('section-target-leads').style.display = 'none';
        document.getElementById('section-target-students').style.display = 'block';
        document.getElementById('btn-tab-leads').style.background = '#fff';
        document.getElementById('btn-tab-leads').style.color = '#64748b';
        document.getElementById('btn-tab-students').style.background = '#f1f5f9';
        document.getElementById('btn-tab-students').style.color = '#475569';
    }
    triggerAudiencePreview();
}

function toggleLeadCheckboxes(checked) {
    const checkBoxes = document.querySelectorAll('#leads-courses-checklist input[type="checkbox"]');
    checkBoxes.forEach(c => c.checked = checked);
    triggerAudiencePreview();
}

function triggerAudiencePreview() {
    calculationValid = false;
    document.getElementById('btn-submit-campaign').disabled = true;
}

function toggleScheduleBlock(show) {
    document.getElementById('section-schedule-datetime').style.display = show ? 'grid' : 'none';
    document.getElementById('inp-sched-date').required = show;
    document.getElementById('inp-sched-time').required = show;
}

function loadTemplateDetails(tplName) {
    if (!tplName) {
        currentTemplateMeta = null;
        document.getElementById('section-variable-mapping').style.display = 'none';
        document.getElementById('section-media-header').style.display = 'none';
        return;
    }

    fetch(`communication-campaigns.php?action=ajax_get_template&template_name=${encodeURIComponent(tplName)}`)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                currentTemplateMeta = res.meta;
                renderVariableMappingUI(res.meta);
                renderMediaHeaderUI(res.meta);
                updateVisualCardPreview();
            } else {
                alert(res.message || 'Failed to fetch template.');
            }
        });
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
    
    if (currentAudience === 'leads') {
        const statuses = Array.from(document.querySelectorAll('.chk-status:checked')).map(c => c.value);
        const courses = Array.from(document.querySelectorAll('.chk-course:checked')).map(c => c.value);

        if (statuses.length === 0 || courses.length === 0) {
            alert('Please select at least one course and status filter.');
            return;
        }

        courses.forEach(c => formData.append('courses[]', c));
        statuses.forEach(s => formData.append('statuses[]', s));
    } else {
        // Handle Students filter defaults
        formData.append('courses[]', document.querySelector('select[name="target_course"]').value);
        formData.append('statuses[]', document.querySelector('select[name="target_status"]').value);
    }

    fetch('communication-campaigns.php?action=ajax_preview_audience', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            eligibleRecipientsList = res.recipients;
            document.getElementById('lbl-matching-leads').innerText = res.total_matching;
            document.getElementById('lbl-duplicates').innerText = res.duplicates;
            document.getElementById('lbl-opted-out').innerText = res.opted_out;
            document.getElementById('lbl-invalid').innerText = res.invalid;
            document.getElementById('lbl-eligible-recipients').innerText = res.eligible_count;

            renderRecipientPreviewTable(res.recipients);
            document.getElementById('panel-audience-preview').style.display = 'block';

            if (res.eligible_count > 0) {
                calculationValid = true;
                document.getElementById('btn-submit-campaign').disabled = false;
            } else {
                calculationValid = false;
                document.getElementById('btn-submit-campaign').disabled = true;
                alert('No eligible recipients match these filters.');
            }
            updateVisualCardPreview();
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

    // Modal populate fields
    document.getElementById('lbl-confirm-name').innerText = document.getElementById('inp-campaign-name').value;
    document.getElementById('lbl-confirm-audience').innerText = currentAudience === 'leads' ? 'Leads Segment filters' : 'Students list';
    document.getElementById('lbl-confirm-recipients').innerText = document.getElementById('lbl-eligible-recipients').innerText;
    document.getElementById('lbl-confirm-template').innerText = document.getElementById('sel-template-name').value;

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
                document.getElementById('drilldown-title').innerText = "Campaign Detail: " + c.name;
                document.getElementById('drilldown-subtitle').innerHTML = `Template: <strong>${escapeHtml(c.template_name)}</strong> &nbsp;|&nbsp; Target: <strong>${escapeHtml(c.target_audience.toUpperCase())}</strong>`;

                // Adjust Action control buttons
                if (c.status === 'paused') {
                    document.getElementById('btn-ctrl-pause').style.display = 'none';
                    document.getElementById('btn-ctrl-resume').style.display = 'inline-block';
                } else if (c.status === 'completed' || c.status === 'cancelled') {
                    document.getElementById('btn-ctrl-pause').style.display = 'none';
                    document.getElementById('btn-ctrl-resume').style.display = 'none';
                    document.getElementById('btn-ctrl-cancel').style.display = 'none';
                } else {
                    document.getElementById('btn-ctrl-pause').style.display = 'inline-block';
                    document.getElementById('btn-ctrl-resume').style.display = 'none';
                    document.getElementById('btn-ctrl-cancel').style.display = 'inline-block';
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
