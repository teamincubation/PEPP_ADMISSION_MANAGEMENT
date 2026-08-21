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
        exit;
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
                            if ($headerType !== 'NONE' && $headerType !== 'TEXT') {
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

        $processor = new QueueProcessor($pdo, 25);
        $processed = $processor->execute();
        
        if ($is_cli) {
            echo "Queue processed successfully. Items dispatched: " . $processed . "\n";
        } else {
            echo json_encode([
                'success' => true,
                'message' => "Queue processed successfully.",
                'dispatched_count' => $processed
            ]);
        }
    }
} catch (Exception $e) {
    error_log("Cron Queue Processor Error: " . $e->getMessage());
    if ($is_cli) {
        echo "Error: " . $e->getMessage() . "\n";
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Processor Error: ' . $e->getMessage()
        ]);
    }
}
