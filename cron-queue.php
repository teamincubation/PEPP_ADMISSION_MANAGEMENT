<?php
/**
 * Cron runner entry point to process messaging queue.
 * Configured to run in background or via HTTPS.
 */
// Force production MySQL routing for all cron invocations (CLI and HTTP)
putenv('PEPP_USE_MYSQL=1');
$_ENV['PEPP_USE_MYSQL'] = '1';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/communication/QueueProcessor.php';
require_once __DIR__ . '/includes/communication/CommunicationEngine.php';

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
                  LIMIT 1
            ");
            $schedStmt->execute();
            $dueCampaign = $schedStmt->fetch();

            if ($dueCampaign) {
                $campId = $dueCampaign['id'];

                $pdo->beginTransaction();

                // Concurrency lock using FOR UPDATE if MySQL driver
                $forUpdate = $isMysql ? ' FOR UPDATE' : '';

                // Fetch pending recipients snapshot
                $stmtRec = $pdo->prepare("
                    SELECT * FROM communication_campaign_recipients
                    WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL
                    ORDER BY id ASC LIMIT 50" . $forUpdate
                );
                $stmtRec->execute([$campId]);
                $batchRecipients = $stmtRec->fetchAll();

                if (!empty($batchRecipients)) {
                    // Change campaign to active
                    $pdo->prepare("UPDATE communication_campaigns SET status = 'active', updated_at = " . ($isMysql ? "NOW()" : "datetime('now')") . " WHERE id = ?")->execute([$campId]);

                    // Fetch Template Info
                    $tplStmt = $pdo->prepare("
                        SELECT * FROM communication_templates
                        WHERE template_name = ? AND channel = ?
                        LIMIT 1
                    ");
                    $tplStmt->execute([$dueCampaign['template_name'], $dueCampaign['channel']]);
                    $template = $tplStmt->fetch();

                    if ($template) {
                        $metaData = json_decode($template['meta_data'] ?? '{}', true);

                        foreach ($batchRecipients as $rec) {
                            $studentData = null;
                            if (!empty($rec['user_id'])) {
                                $stStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = ? LIMIT 1");
                                $stStmt->execute([$rec['user_id']]);
                                $studentData = $stStmt->fetch();
                            } elseif (!empty($rec['lead_id'])) {
                                $ldStmt = $pdo->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
                                $ldStmt->execute([$rec['lead_id']]);
                                $studentData = $ldStmt->fetch();
                            }

                            $resolvedBodyVars = [];
                            $resolvedButtonVars = [];

                            if (isset($metaData['body_vars']) && is_array($metaData['body_vars'])) {
                                foreach ($metaData['body_vars'] as $idx => $token) {
                                    $val = '';
                                    if ($token === 'student_name' || $token === 'name') {
                                        $val = $rec['recipient_name'] ?? ($studentData['name'] ?? '');
                                    } elseif ($studentData && isset($studentData[$token])) {
                                        $val = (string)$studentData[$token];
                                    }
                                    $resolvedBodyVars[] = $val;
                                }
                            }

                            if (isset($metaData['button_vars']) && is_array($metaData['button_vars'])) {
                                foreach ($metaData['button_vars'] as $btnIdx => $dynSuffix) {
                                    $resolvedButtonVars[$btnIdx] = $dynSuffix;
                                }
                            }

                            $templateData = [
                                'template_name' => $dueCampaign['template_name'],
                                'language' => $template['language'] ?? 'en',
                                'body_parameters' => $resolvedBodyVars,
                                'button_parameters' => $resolvedButtonVars
                            ];

                            $queueId = $engine->queueMessage(
                                $dueCampaign['channel'],
                                $rec['recipient'],
                                $rec['recipient_name'],
                                "Campaign: " . $dueCampaign['name'],
                                null,
                                null,
                                $templateData,
                                [],
                                $dueCampaign['created_by'] ?? 'Campaign Worker',
                                null,
                                null,
                                'campaign_message',
                                0,
                                null,
                                $rec['lead_id'] ?? null,
                                $rec['user_id'] ?? null
                            );

                            $pdo->prepare("
                                UPDATE communication_campaign_recipients
                                SET queue_id = ?, status = 'queued'
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
                    $pendingStmt = $pdo->prepare("
                        SELECT COUNT(*) FROM communication_campaign_recipients
                        WHERE campaign_id = ? AND queue_id IS NULL
                    ");
                    $pendingStmt->execute([$campId]);
                    $pendingCount = (int)$pendingStmt->fetchColumn();

                    if ($pendingCount === 0 && $dueCampaign['status'] === 'active') {
                        $pdo->prepare("UPDATE communication_campaigns SET status = 'completed', updated_at = " . ($isMysql ? "NOW()" : "datetime('now')") . " WHERE id = ?")->execute([$campId]);
                    }
                }
            }
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
