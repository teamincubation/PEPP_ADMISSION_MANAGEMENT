<?php
/**
 * Cron runner entry point to process messaging queue.
 * Configured to run in background.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/communication/QueueProcessor.php';

try {
    $queueId = isset($argv[1]) ? (int)$argv[1] : null;
    
    if ($queueId > 0) {
        require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
        $engine = CommunicationEngine::getInstance($pdo);
        $success = $engine->processQueueItem($queueId);
        echo "Queue item #{$queueId} processed: " . ($success ? "Success" : "Failed") . "\n";
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
                
                // Fetch pending recipients snapshot
                $stmtRec = $pdo->prepare("
                    SELECT * FROM communication_campaign_recipients 
                    WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL 
                    LIMIT 200
                ");
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
                                            SET status = 'failed', error_message = 'Skipped: Required template parameter \'' . $skippedParam . '\' is empty.' 
                                            WHERE id = ?
                                        ")->execute([$rec['id']]);
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
                                            SET status = 'failed', error_message = 'Skipped: Required template parameter \'' . $skippedParam . '\' is empty.' 
                                            WHERE id = ?
                                        ")->execute([$rec['id']]);
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
                    } else {
                        // Template not found/deleted, mark recipients as failed
                        $pdo->prepare("
                            UPDATE communication_campaign_recipients 
                            SET status = 'failed' 
                            WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL
                        ")->execute([$campId]);
                    }
                } else {
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
        echo "Queue processed successfully. Items dispatched: " . $processed . "\n";
    }
} catch (Exception $e) {
    error_log("Cron Queue Processor Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
