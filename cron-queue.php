<?php
/**
 * Cron runner entry point to process messaging queue.
 * Configured to run in background or via HTTPS.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/communication/QueueProcessor.php';

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
            if ($correctToken && $providedKey === $correctToken) {
                $is_authenticated = true;
            }
        } catch (Exception $secEx) {
            // Fallback fail-closed
        }
    }

    if (!$is_authenticated) {
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
    
    if ($queueId > 0) {
        require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
        $engine = CommunicationEngine::getInstance($pdo);
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
        // Automatically process scheduled/due campaigns in the background
        try {
            $schedStmt = $pdo->prepare("
                SELECT * FROM communication_campaigns 
                WHERE status IN ('scheduled', 'active') 
                  AND (scheduled_at IS NULL OR scheduled_at <= NOW()) 
                  LIMIT 1
            ");
            $schedStmt->execute();
            $dueCampaign = $schedStmt->fetch();
            
            if ($dueCampaign) {
                $campId = $dueCampaign['id'];
                
                $pdo->beginTransaction();
                
                // Concurrency lock using FOR UPDATE if MySQL driver
                $isMysql = (strpos($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') !== false);
                $forUpdate = $isMysql ? ' FOR UPDATE' : '';
                
                // Fetch pending recipients snapshot
                $stmtRec = $pdo->prepare("
                    SELECT * FROM communication_campaign_recipients 
                    WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL 
                    LIMIT 200
                    " . $forUpdate
                );
                $stmtRec->execute([$campId]);
                $recipients = $stmtRec->fetchAll();
                
                if (!empty($recipients)) {
                    // Update campaign status to active/processing if it was scheduled
                    if ($dueCampaign['status'] === 'scheduled') {
                        $pdo->prepare("UPDATE communication_campaigns SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$campId]);
                    }
                    
                    // Fetch template info
                    $stmtTpl = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? LIMIT 1");
                    $stmtTpl->execute([$dueCampaign['template_name']]);
                    $template = $stmtTpl->fetch();
                    
                    if ($template) {
                        $criteria = json_decode($dueCampaign['segment_criteria'], true) ?: [];
                        $varMappings = $criteria['var_mappings'] ?? [];
                        $staticVals = $criteria['static_vals'] ?? [];
                        $mediaUrl = $criteria['header_media'] ?? '';
                        
                        $meta = json_decode($template['meta_data'], true) ?: [];
                        
                        require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
                        $engine = CommunicationEngine::getInstance($pdo);
                        
                        foreach ($recipients as $rec) {
                            $resolvedParams = [];
                            
                            // Reconstruct variable mappings from snapshot lead/student fields
                            if ($dueCampaign['target_audience'] === 'leads') {
                                $stmtLead = $pdo->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
                                $stmtLead->execute([$rec['lead_id']]);
                                $lead = $stmtLead->fetch();
                                if ($lead) {
                                    // Pre-queue opt-out compliance check
                                    if ((int)($lead['is_opted_out'] ?? 0) === 1) {
                                        $pdo->prepare("
                                            UPDATE communication_campaign_recipients 
                                            SET status = 'failed', error_message = 'Lead opted out before queueing' 
                                            WHERE id = ?
                                        ")->execute([$rec['id']]);
                                        continue;
                                    }
                                    
                                    $skippedParam = '';
                                    foreach ($varMappings as $idx => $field) {
                                        $val = ($field === 'static') ? ($staticVals[$idx] ?? '') : ($lead[$field] ?? '');
                                        if ($val === null || trim((string)$val) === '') {
                                            $skippedParam = ($field === 'static') ? "static_var_{$idx}" : $field;
                                            break;
                                        }
                                        $resolvedParams[] = trim((string)$val);
                                    }
                                    
                                    if ($skippedParam !== '') {
                                        $pdo->prepare("
                                            UPDATE communication_campaign_recipients 
                                            SET status = 'failed', error_message = ? 
                                            WHERE id = ?
                                        ")->execute(["Skipped: Required template parameter '{$skippedParam}' is empty.", $rec['id']]);
                                        continue;
                                    }
                                } else {
                                    // Lead deleted
                                    $pdo->prepare("
                                        UPDATE communication_campaign_recipients 
                                        SET status = 'failed', error_message = 'Lead deleted before queueing' 
                                        WHERE id = ?
                                    ")->execute([$rec['id']]);
                                    continue;
                                }
                            } else {
                                $stmtUser = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
                                $stmtUser->execute([$rec['user_id']]);
                                $user = $stmtUser->fetch();
                                if ($user) {
                                    $skippedParam = '';
                                    foreach ($varMappings as $idx => $field) {
                                        $val = ($field === 'static') ? ($staticVals[$idx] ?? '') : ($user[$field] ?? '');
                                        if ($val === null || trim((string)$val) === '') {
                                            $skippedParam = ($field === 'static') ? "static_var_{$idx}" : $field;
                                            break;
                                        }
                                        $resolvedParams[] = trim((string)$val);
                                    }
                                    
                                    if ($skippedParam !== '') {
                                        $pdo->prepare("
                                            UPDATE communication_campaign_recipients 
                                            SET status = 'failed', error_message = ? 
                                            WHERE id = ?
                                        ")->execute(["Skipped: Required template parameter '{$skippedParam}' is empty.", $rec['id']]);
                                        continue;
                                    }
                                }
                            }
                            
                            $templatePayload = [
                                'name' => $dueCampaign['template_name'],
                                'language' => $template['language'] ?: 'en',
                                'parameters' => $resolvedParams
                            ];
                            
                            $headerType = $meta['header_type'] ?? 'NONE';
                            if ($headerType === 'NONE' && !empty($meta['components'])) {
                                foreach ($meta['components'] as $c) {
                                    if (($c['type'] ?? '') === 'HEADER') {
                                        $headerType = $c['format'] ?? 'NONE';
                                        break;
                                    }
                                }
                            }
                            
                            $mediaUrl = $criteria['header_media'] ?? '';
                            if (empty($mediaUrl)) {
                                $fallbackUrl = $meta['header_media_url'] ?? '';
                                if (!empty($fallbackUrl) && strpos($fallbackUrl, 'scontent.whatsapp.net') === false && strpos($fallbackUrl, 'fbcdn.net') === false) {
                                    $mediaUrl = $fallbackUrl;
                                }
                            }

                            if ($headerType !== 'NONE' && $headerType !== 'TEXT' && !empty($mediaUrl)) {
                                $templatePayload['header_type'] = $headerType;
                                $templatePayload['header_parameters'] = [$mediaUrl];
                            }
                            
                            $body = "Campaign message: {$dueCampaign['name']}";
                            
                            $queueId = $engine->queueMessage(
                                'whatsapp',
                                $rec['recipient'],
                                $rec['recipient_name'],
                                $dueCampaign['name'],
                                $body,
                                $body,
                                [],
                                $templatePayload,
                                $dueCampaign['created_by'],
                                date('Y-m-d H:i:s'),
                                $rec['user_id']
                             );
                             
                             $pdo->prepare("
                                 UPDATE communication_campaign_recipients 
                                 SET queue_id = ?, status = 'pending' 
                                 WHERE id = ?
                             ")->execute([$queueId, $rec['id']]);
                        }
                        $pdo->commit();
                    } else {
                        // Template not found/deleted, mark recipients as failed
                        $pdo->prepare("
                            UPDATE communication_campaign_recipients 
                            SET status = 'failed', error_message = 'Marketing template not found or approved' 
                            WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL
                        ")->execute([$campId]);
                        $pdo->commit();
                    }
                } else {
                    $pdo->commit();
                    
                    // Check if campaign is finished (all recipients have queue IDs)
                    $pendingCount = (int)$pdo->query("
                        SELECT COUNT(*) FROM communication_campaign_recipients 
                        WHERE campaign_id = {$campId} AND queue_id IS NULL
                    ")->fetchColumn();
                    
                    if ($pendingCount === 0 && $dueCampaign['status'] === 'active') {
                        $pdo->prepare("UPDATE communication_campaigns SET status = 'completed', updated_at = NOW() WHERE id = ?")->execute([$campId]);
                    }
                }
            }
        } catch (Exception $schedEx) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Scheduled campaign dispatcher error: " . $schedEx->getMessage());
        }

        // Run installment reminder scheduler
        if (file_exists(__DIR__ . '/includes/session_cron.php')) {
            require_once __DIR__ . '/includes/session_cron.php';
            if (function_exists('installments_dispatch_whatsapp_reminders')) {
                installments_dispatch_whatsapp_reminders($pdo);
            }
            if (function_exists('installments_dispatch_whatsapp_overdue_reminders')) {
                installments_dispatch_whatsapp_overdue_reminders($pdo);
            }
        }

        $telemetry = [
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'source' => $is_cli ? 'CLI' : 'HTTP',
            'status' => 'SUCCESS',
            'processed' => 0,
            'failed' => 0,
            'eligible' => 0,
            'ids' => [],
            'duration' => 0.0
        ];

        try {
            $processor = new QueueProcessor($pdo, 25);
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
            $telemetry['error'] = $execEx->getMessage();
            throw $execEx;
        } finally {
            try {
                $telemetryJson = json_encode($telemetry);
                $telStmt = $pdo->prepare("
                    INSERT INTO admin_settings (setting_name, setting_value, updated_at) 
                    VALUES ('whatsapp_last_cron_run', ?, NOW()) 
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                $telStmt->execute([$telemetryJson]);
                error_log("[CRON_COMPLETE] cron completed. Telemetry: " . $telemetryJson);
            } catch (Exception $telEx) {}
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
            'source' => (php_sapi_name() === 'cli') ? 'CLI' : 'HTTP',
            'status' => 'FAILED',
            'error' => $e->getMessage(),
            'processed' => 0,
            'failed' => 0,
            'eligible' => 0,
            'ids' => [],
            'duration' => 0.0
        ];
        $telemetryJson = json_encode($telemetry);
        $telStmt = $pdo->prepare("
            INSERT INTO admin_settings (setting_name, setting_value, updated_at) 
            VALUES ('whatsapp_last_cron_run', ?, NOW()) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        $telStmt->execute([$telemetryJson]);
    } catch (Exception $telEx) {}

    if ($is_cli) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Processor Error: ' . $e->getMessage()
        ]);
        exit;
    }
}
