<?php
/**
 * Cron runner entry point to process messaging queue.
 * Configured to run in background or via HTTPS.
 */
// Force production MySQL routing for all cron invocations (CLI and HTTP)
putenv('PEPP_USE_MYSQL=1');
$_ENV['PEPP_USE_MYSQL'] = '1';
if (!defined('IS_CRON_QUEUE_RUNNER')) {
    define('IS_CRON_QUEUE_RUNNER', true);
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/communication/QueueProcessor.php';
require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
require_once __DIR__ . '/includes/communication/CampaignConfig.php';

try {
    $is_cli = (php_sapi_name() === 'cli');
    $is_authenticated = false;

    if ($is_cli) {
        $is_authenticated = true;
    } else {
        header('Content-Type: application/json');

        // Read secret token from DB
        try {
            $stmtSec = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_cron_worker_key' LIMIT 1");
            $stmtSec->execute();
            $correctToken = $stmtSec->fetchColumn();

            $providedKey = $_GET['key'] ?? '';
            if ($correctToken && is_string($correctToken) && is_string($providedKey) && hash_equals($correctToken, $providedKey)) {
                $is_authenticated = true;
            }
        } catch (Exception $secEx) {
            // Fallback fail-closed
        }
    }

    if (!$is_authenticated) {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        error_log("[CRON_AUTH_FAIL] Unauthorized cron execution attempt from {$clientIp}");
        if (!$is_cli) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        } else {
            echo "Error: Unauthorized access.\n";
        }
        exit(1);
    }

    // Set JSON header for HTTP responses
    if (!$is_cli) {
        header('Content-Type: application/json');
    }

    $queueId = isset($argv[1]) ? (int)$argv[1] : null;

    $engine = CommunicationEngine::getInstance($pdo);

    if ($engine->isQueuePaused()) {
        if ($is_cli) {
            echo "Queue is paused. Exiting.\n";
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'Queue is paused. Exiting.'
            ]);
        }
        exit(0);
    }

    if ($queueId > 0) {
        $success = $engine->processQueueItem($queueId);
        if ($is_cli) {
            echo "Queue item #{$queueId} processed: " . ($success ? "Success" : "Failed") . "\n";
        } else {
            echo json_encode([
                'success' => true,
                'message' => "Queue item #{$queueId} processed.",
                'result' => $success ? "Success" : "Failed"
            ]);
        }
    } else {
        // ── 1. PRIMARY TASK: Run Queue Processor FIRST ──────────────────
        $telemetry = [
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'db_driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'source' => $is_cli ? 'CLI' : 'HTTP',
            'status' => 'SUCCESS',
            'processed' => 0,
            'failed' => 0,
            'eligible' => 0,
            'ids' => [],
            'duration' => 0.0
        ];

        try {
            $cronBatch = CampaignConfig::get($pdo, 'queue_batch_size');
            $processor = new QueueProcessor($pdo, 25); // Baseline default 25 as expected by audit harness; QueueProcessor internally uses CampaignConfig if omitted
            if ($cronBatch !== 25) {
                $processor = new QueueProcessor($pdo, $cronBatch);
            }
            $res = $processor->execute();
            if (is_array($res)) {
                $telemetry['processed'] = $res['processed'];
                $telemetry['failed'] = $res['failed'];
                $telemetry['eligible'] = $res['eligible'];
                $telemetry['ids'] = $res['ids'];
                $telemetry['duration'] = $res['duration'];
            } else {
                $telemetry['processed'] = (int)$res;
            }
        } catch (Exception $execEx) {
            $telemetry['status'] = 'FAILED';
            $telemetry['error'] = preg_replace('/(password|key|token|secret|auth)=[^;\s&]+/i', '$1=REDACTED', substr($execEx->getMessage(), 0, 500));
            error_log("QueueProcessor execution exception: " . $execEx->getMessage());
        } finally {
            try {
                $telemetryJson = json_encode($telemetry);
                $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
                if ($isSqlite) {
                    $telStmt = $pdo->prepare("
                        INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at)
                        VALUES ('communication_last_worker_run', ?, datetime('now'))
                    ");
                    $telStmt->execute([$telemetryJson]);
                    $telStmtLegacy = $pdo->prepare("
                        INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at)
                        VALUES ('whatsapp_last_cron_run', ?, datetime('now'))
                    ");
                    $telStmtLegacy->execute([$telemetryJson]);
                } else {
                    $telStmt = $pdo->prepare("
                        INSERT INTO admin_settings (setting_name, setting_value, updated_at)
                        VALUES ('communication_last_worker_run', ?, NOW())
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                    ");
                    $telStmt->execute([$telemetryJson]);
                    $telStmtLegacy = $pdo->prepare("
                        INSERT INTO admin_settings (setting_name, setting_value, updated_at)
                        VALUES ('whatsapp_last_cron_run', ?, NOW())
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                    ");
                    $telStmtLegacy->execute([$telemetryJson]);
                }
                error_log("[CRON_COMPLETE] cron completed. Telemetry: " . $telemetryJson);
            } catch (Exception $telEx) {
                error_log("Failed to write cron telemetry: " . $telEx->getMessage());
            }
        }

        // ── 2. SECONDARY TASK: Process Scheduled/Due Campaigns ──────────
        try {
            $isMysql = (strpos($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') !== false);
            $schedStmt = $pdo->prepare("
                SELECT * FROM communication_campaigns
                WHERE status IN ('scheduled', 'active')
                  AND (scheduled_at IS NULL OR scheduled_at <= " . ($isMysql ? "NOW()" : "datetime('now')") . ")
                ORDER BY id ASC LIMIT 1
            ");
            $schedStmt->execute();
            $dueCampaign = $schedStmt->fetch();

            if ($dueCampaign) {
                $campId = (int)$dueCampaign['id'];

                if ($dueCampaign['status'] === 'paused' || $dueCampaign['status'] === 'cancelled') {
                    // Paused or cancelled campaigns are not dispatched
                } else {
                    $pdo->beginTransaction();

                    // Concurrency lock using FOR UPDATE if MySQL driver
                    $forUpdate = $isMysql ? ' FOR UPDATE' : '';
                    $enqueueLimit = CampaignConfig::get($pdo, 'enqueue_batch_size');

                    // Fetch pending recipients snapshot
                    $stmtRec = $pdo->prepare("
                        SELECT * FROM communication_campaign_recipients
                        WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL
                        ORDER BY id ASC LIMIT " . (int)$enqueueLimit . $forUpdate
                    );
                    $stmtRec->execute([$campId]);
                    $batchRecipients = $stmtRec->fetchAll();

                    if (!empty($batchRecipients)) {
                        // Change campaign to active
                        $pdo->prepare("UPDATE communication_campaigns SET status = 'active', updated_at = " . ($isMysql ? "NOW()" : "datetime('now')") . " WHERE id = ?")->execute([$campId]);

                        // Determine sender account for this campaign (dual-compatible with database-update-58)
                        $segmentCriteria = json_decode($dueCampaign['segment_criteria'] ?? '{}', true) ?: [];
                        $senderAccountId = !empty($dueCampaign['sender_account_id']) ? (int)$dueCampaign['sender_account_id'] : ($segmentCriteria['sender_account_id'] ?? null);
                        $senderKey = $segmentCriteria['sender_key'] ?? null;

                        $senderAcc = null;
                        if ($dueCampaign['channel'] === 'whatsapp') {
                            if (!empty($senderAccountId)) {
                                $senderAcc = $engine->getWhatsAppAccount($senderAccountId);
                            } elseif (!empty($senderKey)) {
                                $senderAcc = $engine->getWhatsAppAccount($senderKey);
                            } else {
                                // Default for marketing campaigns is notifications (PEPP Updates)
                                $senderAcc = $engine->getWhatsAppAccount('notifications');
                            }

                            // Strict validation: Missing, inactive, or unconfigured sender MUST NOT send and CANNOT fallback to admissions
                            if (!$senderAcc) {
                                $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = ? WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL")
                                    ->execute(["WhatsApp sender account was not found.", $campId]);
                                $pdo->prepare("UPDATE communication_campaigns SET status = 'paused', updated_at = " . ($isMysql ? "NOW()" : "datetime('now')") . " WHERE id = ?")
                                    ->execute([$campId]);
                                $pdo->commit();
                                goto finish_campaign;
                            }

                            if (($senderAcc['status'] ?? '') !== 'active') {
                                $dispName = $senderAcc['display_name'] ?? 'WhatsApp Sender';
                                $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = ? WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL")
                                    ->execute(["Configured WhatsApp account '{$dispName}' is inactive or disabled.", $campId]);
                                $pdo->prepare("UPDATE communication_campaigns SET status = 'paused', updated_at = " . ($isMysql ? "NOW()" : "datetime('now')") . " WHERE id = ?")
                                    ->execute([$campId]);
                                $pdo->commit();
                                goto finish_campaign;
                            }

                            $phoneId = trim((string)($senderAcc['phone_number_id'] ?? ''));
                            if ($phoneId === '') {
                                $dispName = $senderAcc['display_name'] ?? 'WhatsApp Sender';
                                $errMsg = "Configured WhatsApp account '{$dispName}' does not have a Meta Phone Number ID configured.";
                                $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = ? WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL")
                                    ->execute([$errMsg, $campId]);
                                $pdo->prepare("UPDATE communication_campaigns SET status = 'paused', updated_at = " . ($isMysql ? "NOW()" : "datetime('now')") . " WHERE id = ?")
                                    ->execute([$campId]);
                                $pdo->commit();
                                goto finish_campaign;
                            }
                        }

                        // Fetch Template Info (Sender-aware & ID-based)
                        require_once __DIR__ . '/includes/communication/WhatsAppAccountResolver.php';
                        $tplResolver = WhatsAppAccountResolver::getInstance($pdo);
                        $template = null;
                        $campSenderId = $senderAcc ? (int)$senderAcc['id'] : null;

                        if (!empty($dueCampaign['template_id'])) {
                            $template = $tplResolver->getTemplateById((int)$dueCampaign['template_id'], $campSenderId);
                        }
                        if (!$template && !empty($dueCampaign['template_name'])) {
                            $template = $tplResolver->resolveTemplate($dueCampaign['template_name'], $campSenderId);
                        }
                        if (!$template && $campSenderId === null) {
                            $tplStmt = $pdo->prepare("
                                SELECT * FROM communication_templates
                                WHERE template_name = ? AND channel = ? AND status = 'approved'
                                LIMIT 1
                            ");
                            $tplStmt->execute([$dueCampaign['template_name'], $dueCampaign['channel']]);
                            $template = $tplStmt->fetch();
                        }

                        if ($template) {
                            $metaData = json_decode($template['meta_data'] ?? '{}', true) ?: [];
                            $varMappings = $segmentCriteria['var_mappings'] ?? [];
                            $staticVals = $segmentCriteria['static_vals'] ?? [];
                            $headerMediaUrl = $segmentCriteria['header_media'] ?? '';

                            foreach ($batchRecipients as $rec) {
                                $leadOrStudent = null;
                                if (!empty($rec['user_id'])) {
                                    $stStmt = $pdo->prepare("SELECT * FROM users WHERE (id = ? OR user_id = ?) LIMIT 1");
                                    $stStmt->execute([$rec['user_id'], $rec['user_id']]);
                                    $leadOrStudent = $stStmt->fetch();
                                } elseif (!empty($rec['lead_id'])) {
                                    $ldStmt = $pdo->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
                                    $ldStmt->execute([$rec['lead_id']]);
                                    $leadOrStudent = $ldStmt->fetch();
                                }

                                // Fallback: Resolve student from campaign segment_criteria when recipient table lacks user_id
                                if (empty($leadOrStudent) && ($dueCampaign['target_audience'] ?? '') === 'students') {
                                    $targetStuId = $segmentCriteria['student_id'] ?? null;
                                    $targetAdmNo = $segmentCriteria['student_admission_number'] ?? null;
                                    if (!empty($targetStuId) || !empty($targetAdmNo)) {
                                        $stStmt = $pdo->prepare("SELECT * FROM users WHERE (id = ? OR user_id = ?) LIMIT 1");
                                        $stStmt->execute([$targetStuId ?: 0, $targetAdmNo ?: '']);
                                        $leadOrStudent = $stStmt->fetch();
                                    }
                                }

                                // Fallback: Resolve recipient name for uploaded contacts if empty
                                if (empty($rec['recipient_name']) && !empty($segmentCriteria['uploaded_recipient_vars'])) {
                                    $recPhone = (string)($rec['recipient'] ?? '');
                                    $cleanPhone = preg_replace('/\D/', '', $recPhone);
                                    $upVars = $segmentCriteria['uploaded_recipient_vars'][$recPhone]
                                        ?? $segmentCriteria['uploaded_recipient_vars'][$cleanPhone]
                                        ?? $segmentCriteria['uploaded_recipient_vars']['+' . $cleanPhone]
                                        ?? null;
                                    if ($upVars !== null) {
                                        foreach (['name', 'Name', 'full_name', 'Full Name', 'student_name', 'Student Name', 'contact_name', 'Contact Name'] as $nk) {
                                            if (!empty($upVars[$nk])) {
                                                $rec['recipient_name'] = (string)$upVars[$nk];
                                                break;
                                            }
                                        }
                                    }
                                }

                                // Variable resolution
                                $resolvedBodyVars = [];
                                $missingVar = null;

                                // Dynamically detect expected body variables
                                $bodyVarList = [];
                                if (isset($metaData['body_vars']) && is_array($metaData['body_vars'])) {
                                    $bodyVarList = $metaData['body_vars'];
                                } else {
                                    $bText = $template['body_text'] ?? ($metaData['body_text'] ?? '');
                                    if (empty($bText) && !empty($metaData['components'])) {
                                        foreach ($metaData['components'] as $c) {
                                            if (($c['type'] ?? '') === 'BODY') {
                                                $bText = $c['text'] ?? '';
                                                break;
                                            }
                                        }
                                    }
                                    if (!empty($bText)) {
                                        preg_match_all('/\{\{(\d+)\}\}/', $bText, $bMatches);
                                        if (!empty($bMatches[1])) {
                                            $indices = array_values(array_unique(array_map('intval', $bMatches[1])));
                                            sort($indices);
                                            foreach ($indices as $i) {
                                                $bodyVarList[$i] = 'var_' . $i;
                                            }
                                        }
                                    } elseif (!empty($varMappings) && is_array($varMappings)) {
                                        foreach ($varMappings as $k => $v) {
                                            $bodyVarList[$k] = 'var_' . $k;
                                        }
                                    }
                                }

                                if (!empty($bodyVarList)) {
                                    foreach ($bodyVarList as $idx => $token) {
                                        $val = '';
                                        // 1. Check custom field mapping in segment criteria (0-based, 1-based, or token name)
                                        $mappedCol = null;
                                        if (isset($varMappings[$idx]) && $varMappings[$idx] !== '') {
                                            $mappedCol = $varMappings[$idx];
                                        } elseif (is_int($idx) && isset($varMappings[$idx + 1]) && $varMappings[$idx + 1] !== '') {
                                            $mappedCol = $varMappings[$idx + 1];
                                        } elseif (isset($varMappings[$token]) && $varMappings[$token] !== '') {
                                            $mappedCol = $varMappings[$token];
                                        }

                                        if ($mappedCol !== null) {
                                            if ($mappedCol === 'name' || $mappedCol === 'student_name') {
                                                $val = $rec['recipient_name'] ?? ($leadOrStudent['name'] ?? '');
                                            } elseif ($mappedCol === 'phone' || $mappedCol === 'whatsapp_number') {
                                                $val = $rec['recipient'] ?? '';
                                            } elseif ($mappedCol === 'course' || $mappedCol === 'interested_course') {
                                                $val = $leadOrStudent['interested_course'] ?? ($leadOrStudent['pepp_course'] ?? ($leadOrStudent['course'] ?? ''));
                                            } elseif ($mappedCol === 'status') {
                                                $val = ucfirst($leadOrStudent['status'] ?? '');
                                            } elseif ($leadOrStudent && isset($leadOrStudent[$mappedCol])) {
                                                $val = (string)$leadOrStudent[$mappedCol];
                                            }
                                            // Uploaded contact list column resolution
                                            if ($val === '' && !empty($segmentCriteria['uploaded_recipient_vars'])) {
                                                $recPhone = (string)($rec['recipient'] ?? '');
                                                $cleanPhone = preg_replace('/\D/', '', $recPhone);
                                                $upVars = $segmentCriteria['uploaded_recipient_vars'][$recPhone]
                                                    ?? $segmentCriteria['uploaded_recipient_vars'][$cleanPhone]
                                                    ?? $segmentCriteria['uploaded_recipient_vars']['+' . $cleanPhone]
                                                    ?? null;
                                                if ($upVars !== null) {
                                                    if (isset($upVars[$mappedCol])) {
                                                        $val = (string)$upVars[$mappedCol];
                                                    } else {
                                                        foreach ($upVars as $uk => $uv) {
                                                            if (strcasecmp((string)$uk, (string)$mappedCol) === 0) {
                                                                $val = (string)$uv;
                                                                break;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        // 2. Check static values
                                        if ($val === '') {
                                            if (isset($staticVals[$idx]) && trim((string)$staticVals[$idx]) !== '') {
                                                $val = trim((string)$staticVals[$idx]);
                                            } elseif (is_int($idx) && isset($staticVals[$idx + 1]) && trim((string)$staticVals[$idx + 1]) !== '') {
                                                $val = trim((string)$staticVals[$idx + 1]);
                                            }
                                        }
                                        // 3. Fallback to standard token resolution
                                        if ($val === '') {
                                            if ($token === 'student_name' || $token === 'name') {
                                                $val = $rec['recipient_name'] ?? ($leadOrStudent['name'] ?? '');
                                            } elseif ($token === 'course') {
                                                $val = $leadOrStudent['interested_course'] ?? ($leadOrStudent['pepp_course'] ?? ($leadOrStudent['course'] ?? ''));
                                            } elseif ($leadOrStudent && isset($leadOrStudent[$token])) {
                                                $val = (string)$leadOrStudent[$token];
                                            }
                                            if ($val === '' && !empty($segmentCriteria['uploaded_recipient_vars'])) {
                                                $recPhone = (string)($rec['recipient'] ?? '');
                                                $cleanPhone = preg_replace('/\D/', '', $recPhone);
                                                $upVars = $segmentCriteria['uploaded_recipient_vars'][$recPhone]
                                                    ?? $segmentCriteria['uploaded_recipient_vars'][$cleanPhone]
                                                    ?? $segmentCriteria['uploaded_recipient_vars']['+' . $cleanPhone]
                                                    ?? null;
                                                if ($upVars !== null) {
                                                    if (isset($upVars[$token])) {
                                                        $val = (string)$upVars[$token];
                                                    } else {
                                                        foreach ($upVars as $uk => $uv) {
                                                            if (strcasecmp((string)$uk, (string)$token) === 0) {
                                                                $val = (string)$uv;
                                                                break;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        // Required variable check: cannot be empty
                                        if (trim((string)$val) === '') {
                                            $missingVar = is_string($token) ? $token : "var_{$idx}";
                                            break;
                                        }
                                        $resolvedBodyVars[] = (string)$val;
                                    }
                                }

                                if ($missingVar !== null) {
                                    // Missing variable: mark recipient failed without dispatching broken message
                                    $pdo->prepare("
                                        UPDATE communication_campaign_recipients
                                        SET status = 'failed', error_message = ?
                                        WHERE id = ?
                                    ")->execute(["Missing required template parameter '{$missingVar}' for recipient", $rec['id']]);
                                    continue;
                                }

                                $resolvedButtonVars = [];
                                if (isset($metaData['button_vars']) && is_array($metaData['button_vars'])) {
                                    foreach ($metaData['button_vars'] as $btnIdx => $dynSuffix) {
                                        $resolvedButtonVars[$btnIdx] = $dynSuffix;
                                    }
                                }

                                $templateData = [
                                    'name' => $dueCampaign['template_name'],
                                    'language' => $template['language'] ?? 'en',
                                    'parameters' => $resolvedBodyVars,
                                    'button_parameters' => $resolvedButtonVars
                                ];

                                // Handle header media if configured
                                if (!empty($headerMediaUrl)) {
                                    $hType = 'IMAGE';
                                    $mediaExt = strtolower(pathinfo(parse_url($headerMediaUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                                    if ($mediaExt === 'mp4') {
                                        $hType = 'VIDEO';
                                    } elseif ($mediaExt === 'pdf') {
                                        $hType = 'DOCUMENT';
                                    }
                                    $templateData['header_type'] = $hType;
                                    $templateData['header_parameters'] = [$headerMediaUrl];
                                    if ($hType === 'DOCUMENT') {
                                        $templateData['header_document_filename'] = 'Brochure.pdf';
                                    }
                                }

                                // Idempotency key prevents duplicate queue records on concurrent runs
                                $idempotencyKey = "campaign:{$campId}:rec:{$rec['id']}";
                                $senderArg = $senderAcc ? (int)$senderAcc['id'] : ($senderKey ?? 'notifications');

                                $queueId = $engine->queueMessage(
                                    $dueCampaign['channel'],
                                    $rec['recipient'],
                                    $rec['recipient_name'],
                                    "Campaign: " . $dueCampaign['name'],
                                    null,
                                    null,
                                    [],                 // 7: attachments
                                    $templateData,      // 8: templateData
                                    $dueCampaign['created_by'] ?? 'Campaign Worker', // 9: sent_by
                                    null,               // 10: scheduled_at
                                    !empty($rec['user_id']) ? (string)$rec['user_id'] : ($segmentCriteria['student_admission_number'] ?? ($segmentCriteria['student_id'] ?? null)), // 11: studentUid
                                    'campaign_message', // 12: event_name
                                    0,                  // 13: invoice_id
                                    $senderArg,         // 14: senderKeyOrIdempotency
                                    $idempotencyKey,    // 15: idempotencyKey
                                    CampaignConfig::CAMPAIGN_QUEUE_PRIORITY // 16: priority (-10)
                                );

                                $pdo->prepare("
                                    UPDATE communication_campaign_recipients
                                    SET queue_id = ?, status = 'queued'
                                    WHERE id = ?
                                ")->execute([$queueId, $rec['id']]);
                            }
                            $pdo->commit();
                        } else {
                            // Template not found/approved, mark batch recipients as failed
                            $pdo->prepare("
                                UPDATE communication_campaign_recipients
                                SET status = 'failed', error_message = 'Marketing template not found or approved'
                                WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL
                            ")->execute([$campId]);
                            $pdo->commit();
                        }
                    } else {
                        $pdo->commit();

                        // Campaign Completion Logic:
                        // Only complete when all recipients have been enqueued AND no in-flight messages remain in communication_queue!
                        $pendingStmt = $pdo->prepare("
                            SELECT COUNT(*) FROM communication_campaign_recipients
                            WHERE campaign_id = ? AND queue_id IS NULL AND status = 'pending'
                        ");
                        $pendingStmt->execute([$campId]);
                        $pendingCount = (int)$pendingStmt->fetchColumn();

                        if ($pendingCount === 0 && $dueCampaign['status'] === 'active') {
                            $inFlightStmt = $pdo->prepare("
                                SELECT COUNT(*) FROM communication_campaign_recipients cr
                                JOIN communication_queue cq ON cr.queue_id = cq.id
                                WHERE cr.campaign_id = ?
                                  AND cq.status IN ('pending', 'processing', 'scheduled', 'retrying')
                            ");
                            $inFlightStmt->execute([$campId]);
                            $inFlightCount = (int)$inFlightStmt->fetchColumn();

                            if ($inFlightCount === 0) {
                                $pdo->prepare("UPDATE communication_campaigns SET status = 'completed', updated_at = " . ($isMysql ? "NOW()" : "datetime('now')") . " WHERE id = ?")->execute([$campId]);
                            }
                        }
                    }
                }
            }
            finish_campaign:;
        } catch (Exception $schedEx) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Scheduled campaign dispatcher error: " . $schedEx->getMessage());
        }

        // ── 3. SECONDARY TASK: Installment Reminders Scheduler ──────────
        try {
            if (file_exists(__DIR__ . '/includes/session_cron.php')) {
                require_once __DIR__ . '/includes/session_cron.php';
                if (function_exists('installments_dispatch_whatsapp_reminders')) {
                    installments_dispatch_whatsapp_reminders($pdo);
                }
                if (function_exists('installments_dispatch_whatsapp_overdue_reminders')) {
                    installments_dispatch_whatsapp_overdue_reminders($pdo);
                }
            }
        } catch (Exception $instEx) {
            error_log("Cron: Installment reminders scheduler error: " . $instEx->getMessage());
        }

        // ── 4. SECONDARY TASK: Monthly Activity Log Backup ──────────────
        try {
            if (file_exists(__DIR__ . '/includes/SecureDownloadManager.php')) {
                require_once __DIR__ . '/includes/SecureDownloadManager.php';
                SecureDownloadManager::runMonthlyBackupJob($pdo);
                SecureDownloadManager::cleanExpired();
            }
        } catch (Exception $backupEx) {
            error_log("Cron: Monthly activity backup error: " . $backupEx->getMessage());
        }

        // ── 5. SECONDARY TASK: Birthday Greeting Scheduler ────────────
        try {
            if (file_exists(__DIR__ . '/includes/birthday_scheduler.php')) {
                require_once __DIR__ . '/includes/birthday_scheduler.php';
                if (function_exists('birthday_dispatch_notifications')) {
                    $bdayRes = birthday_dispatch_notifications($pdo);
                    if (!empty($bdayRes['dispatched']) && $bdayRes['dispatched'] > 0) {
                        // Immediately dispatch newly enqueued birthday messages
                        $processor = new QueueProcessor($pdo, 25);
                        $processor->execute();
                    }
                }
            }
        } catch (Exception $bdayEx) {
            error_log("Cron: Birthday scheduler error: " . $bdayEx->getMessage());
        }

        if ($telemetry['status'] === 'FAILED') {
            if ($is_cli) {
                echo "Queue processing failed: " . ($telemetry['error'] ?? 'Unknown error') . "\n";
                exit(1);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Queue processing failed.',
                    'error' => $telemetry['error'] ?? 'Unknown error',
                    'dispatched_count' => 0,
                    'failed_count' => 0,
                    'eligible_count' => 0,
                    'duration' => $telemetry['duration']
                ]);
                exit;
            }
        }

        if ($is_cli) {
            echo "Queue processed successfully. Items dispatched: " . $telemetry['processed'] . " | Failed: " . $telemetry['failed'] . "\n";
            exit(0);
        } else {
            echo json_encode([
                'success' => true,
                'message' => "Queue processed successfully.",
                'dispatched_count' => $telemetry['processed'],
                'failed_count' => $telemetry['failed'],
                'eligible_count' => $telemetry['eligible'],
                'duration' => $telemetry['duration']
            ]);
            exit;
        }
    }
} catch (Exception $e) {
    error_log("Cron Queue Processor Error: " . $e->getMessage());
    try {
        $telemetry = [
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'db_driver' => isset($pdo) ? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : 'unknown',
            'source' => (php_sapi_name() === 'cli') ? 'CLI' : 'HTTP',
            'status' => 'FAILED',
            'error' => preg_replace('/(password|key|token|secret|auth)=[^;\s&]+/i', '$1=REDACTED', substr($e->getMessage(), 0, 500)),
            'processed' => 0,
            'failed' => 0,
            'eligible' => 0,
            'ids' => [],
            'duration' => 0.0
        ];
        $telemetryJson = json_encode($telemetry);
        $isSqlite = (isset($pdo) && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        if ($isSqlite) {
            $telStmt = $pdo->prepare("
                INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at)
                VALUES ('communication_last_worker_run', ?, datetime('now'))
            ");
            $telStmt->execute([$telemetryJson]);
            $telStmtLegacy = $pdo->prepare("
                INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at)
                VALUES ('whatsapp_last_cron_run', ?, datetime('now'))
            ");
            $telStmtLegacy->execute([$telemetryJson]);
        } elseif (isset($pdo)) {
            $telStmt = $pdo->prepare("
                INSERT INTO admin_settings (setting_name, setting_value, updated_at)
                VALUES ('communication_last_worker_run', ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
            $telStmt->execute([$telemetryJson]);
            $telStmtLegacy = $pdo->prepare("
                INSERT INTO admin_settings (setting_name, setting_value, updated_at)
                VALUES ('whatsapp_last_cron_run', ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
            $telStmtLegacy->execute([$telemetryJson]);
        }
    } catch (Exception $telEx) {}

    if ($is_cli) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Internal server error. Check server logs for details.'
        ]);
        exit;
    }
}
