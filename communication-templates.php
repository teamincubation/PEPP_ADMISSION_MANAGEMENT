<?php
/**
 * PEPP Learning ERP - WhatsApp Templates Management & Sync Page.
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('communication');

$active_page = 'communication';
$page_title  = 'WhatsApp Templates';
$page_sub    = 'Synchronize and map Meta-approved WhatsApp Cloud API templates';

$success_message = '';
$error_message   = '';

$events = ['student_registration', 'student_approval', 'student_rejection', 'installment_reminder', 'payment_receipt', 'session_scheduled', 'payment_rejection', 'installment_overdue', 'course_migration_completed', 'alumni_verification_completed', 'alumni_referral_code_generated', 'referral_earning_credited', 'referral_payout_sent', 'birthday_greeting', 'birthday_reward_claimed'];

// Self-healing database structure initialization
try {
    $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isSqlite = ($driverName === 'sqlite');

    if ($isSqlite) {
        $has_table = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='communication_queue'")->fetchColumn();
    } else {
        $has_table = (bool)$pdo->query("SHOW TABLES LIKE 'communication_queue'")->fetchColumn();
    }

    if (!$has_table && file_exists(__DIR__ . '/database-update-16.sql')) {
        $sql = file_get_contents(__DIR__ . '/database-update-16.sql');
        $pdo->exec($sql);
        $success_message = 'Database tables for Communication Engine initialized successfully.';
    }

    // Check and add columns to communication_templates
    if ($isSqlite) {
        $cols = $pdo->query("PRAGMA table_info(communication_templates)")->fetchAll(PDO::FETCH_COLUMN, 1);
    } else {
        $cols = $pdo->query("SHOW COLUMNS FROM communication_templates")->fetchAll(PDO::FETCH_COLUMN);
    }
    if (!in_array('quality_status', $cols)) {
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN quality_status VARCHAR(50) DEFAULT NULL" . ($isSqlite ? "" : " AFTER category"));
    }
    if (!in_array('rejection_reason', $cols)) {
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN rejection_reason TEXT DEFAULT NULL" . ($isSqlite ? "" : " AFTER quality_status"));
    }

    // Check and create communication_event_mappings
    if ($isSqlite) {
        $has_event_table = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='communication_event_mappings'")->fetchColumn();
    } else {
        $has_event_table = (bool)$pdo->query("SHOW TABLES LIKE 'communication_event_mappings'")->fetchColumn();
    }
    if (!$has_event_table) {
        if ($isSqlite) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS communication_event_mappings (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  event_name VARCHAR(100) NOT NULL UNIQUE,
                  template_name VARCHAR(100) DEFAULT NULL,
                  parameter_mappings TEXT DEFAULT NULL,
                  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `communication_event_mappings` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `event_name` VARCHAR(100) NOT NULL UNIQUE,
                  `template_name` VARCHAR(100) DEFAULT NULL,
                  `parameter_mappings` LONGTEXT DEFAULT NULL,
                  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    }

    // Seed default event mappings
    $ignoreClause = $isSqlite ? "INSERT OR IGNORE" : "INSERT IGNORE";
    $stmtSeed = $pdo->prepare("$ignoreClause INTO communication_event_mappings (event_name) VALUES (?)");
    foreach ($events as $ev) {
        $stmtSeed->execute([$ev]);
    }

    // Cross-DB upsert for the course_migration_completed template to ensure it is locally present
    $stmtTplCheck = $pdo->prepare("SELECT COUNT(*) FROM communication_templates WHERE template_name = 'course_migration_completed'");
    $stmtTplCheck->execute();
    $tplExists = (int)$stmtTplCheck->fetchColumn() > 0;

    $tplMeta = [
        'components' => [
            [
                'type' => 'BODY',
                'text' => "Dear *{{1}}*, 🎉 Your course migration/upgrade has been successfully completed.\nPrevious Course: *{{2}}*\n🔴 New Course: *{{3}}*\n🟩 Previous Fee: ₹{{4}}\n↔️ New Course Fee: ₹{{5}}\n💳 Amount Paid: ₹{{6}}\nOutstanding Balance: ₹{{7}}\nYour updated course and payment details are now reflected in your PEPP Learning account. Thank you."
            ]
        ],
        'body_text' => "Dear *{{1}}*, 🎉 Your course migration/upgrade has been successfully completed.\nPrevious Course: *{{2}}*\n🔴 New Course: *{{3}}*\n🟩 Previous Fee: ₹{{4}}\n↔️ New Course Fee: ₹{{5}}\n💳 Amount Paid: ₹{{6}}\nOutstanding Balance: ₹{{7}}\nYour updated course and payment details are now reflected in your PEPP Learning account. Thank you.",
        'header_text' => '',
        'footer_text' => ''
    ];
    $tplMetaJson = json_encode($tplMeta);

    if (!$tplExists) {
        $stmtTplInsert = $pdo->prepare("
            INSERT INTO communication_templates (channel, template_name, language, status, category, quality_status, meta_data, updated_at)
            VALUES ('whatsapp', 'course_migration_completed', 'en', 'approved', 'utility', 'green', ?, CURRENT_TIMESTAMP)
        ");
        $stmtTplInsert->execute([$tplMetaJson]);
    }

    // Set default mapping for course_migration_completed if it is blank or outdated
    $stmtCheck = $pdo->prepare("SELECT template_name, parameter_mappings FROM communication_event_mappings WHERE event_name = 'course_migration_completed'");
    $stmtCheck->execute();
    $migMap = $stmtCheck->fetch();
    $needsUpdate = false;
    if ($migMap) {
        if (empty($migMap['template_name'])) {
            $needsUpdate = true;
        } else {
            $currentParams = json_decode($migMap['parameter_mappings'], true) ?: [];
            if (!isset($currentParams[7]) || ($currentParams[7]['value'] ?? '') !== 'updated_payment_details' || ($currentParams[4]['value'] ?? '') !== 'new_course_fee') {
                $needsUpdate = true;
            }
        }
    }
    if ($needsUpdate) {
        $defaultParams = [
            1 => ['type' => 'variable', 'value' => 'student_name'],
            2 => ['type' => 'variable', 'value' => 'previous_course_name'],
            3 => ['type' => 'variable', 'value' => 'new_course_name'],
            4 => ['type' => 'variable', 'value' => 'new_course_fee'],
            5 => ['type' => 'variable', 'value' => 'migration_amount_paid'],
            6 => ['type' => 'variable', 'value' => 'new_outstanding_balance'],
            7 => ['type' => 'variable', 'value' => 'updated_payment_details']
        ];
        $stmtUpdateDefault = $pdo->prepare("UPDATE communication_event_mappings SET template_name = 'course_migration_completed', parameter_mappings = ? WHERE event_name = 'course_migration_completed'");
        $stmtUpdateDefault->execute([json_encode($defaultParams)]);
    }

    // ── Self-healing for Faculty Live Session Instructions (Phase 2) ──
    $hasInstTable = false;
    try {
        $pdo->query("SELECT 1 FROM faculty_session_instructions LIMIT 0");
        $hasInstTable = true;
    } catch (Exception $e) {}

    if (!$hasInstTable) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS faculty_session_instructions (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  language_code TEXT NOT NULL UNIQUE,
                  language_name TEXT NOT NULL,
                  instruction_title TEXT NOT NULL,
                  instruction_body TEXT NOT NULL,
                  is_active INTEGER NOT NULL DEFAULT 1,
                  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `faculty_session_instructions` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `language_code` VARCHAR(10) NOT NULL UNIQUE,
                  `language_name` VARCHAR(50) NOT NULL,
                  `instruction_title` VARCHAR(150) NOT NULL,
                  `instruction_body` VARCHAR(1024) NOT NULL,
                  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
        $insertKw = ($driver === 'sqlite') ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $stmtSeedInst = $pdo->prepare("{$insertKw} INTO faculty_session_instructions (language_code, language_name, instruction_title, instruction_body, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmtSeedInst->execute([
            'en',
            'English',
            'Live Session Faculty Guidelines',
            "PEPP LIVE SESSION FACULTY INSTRUCTIONS\n\n1. Join Session on Time: Join through the provided Google Meet link at least 5 minutes prior to start time.\n2. Audio & Video: Use a reliable headset and webcam in a quiet, well-lit room.\n3. Screen Sharing: Prepare presentation slides and tabs before class starts.\n4. Student Interaction: Monitor chat questions and address doubts systematically.\n5. Wrap-up: Conclude strictly within the scheduled duration and end the meeting."
        ]);
        $stmtSeedInst->execute([
            'ml',
            'Malayalam (മലയാളം)',
            'ലൈവ് സെഷൻ അധ്യാപക നിർദ്ദേശങ്ങൾ',
            "പെപ്പ് ലൈവ് സെഷൻ അധ്യാപക മാർഗ്ഗനിർദ്ദേശങ്ങൾ\n\n1. കൃത്യസമയത്ത് പ്രവേശിക്കുക: നൽകിയിട്ടുള്ള ഗൂഗിൾ മീറ്റ് ലിങ്ക് വഴി ക്ലാസ്സ് തുടങ്ങുന്നതിന് 5 മിനിറ്റ് മുൻപ് ജോയിൻ ചെയ്യുക.\n2. ഓഡിയോ & വീഡിയോ: ശബ്ദകോലാഹലങ്ങൾ ഇല്ലാത്ത മുറിയിൽ ഹെഡ്‌സെറ്റും വെബ്‌ക്യാമും ഉപയോഗിക്കുക.\n3. സ്ക്രീൻ ഷെയറിങ്: ക്ലാസ്സിന് മുൻപായി പ്രസന്റേഷൻ സ്ലൈഡുകൾ തുറന്നുവെക്കുക.\n4. സംശയനിവാരണം: ചാറ്റ് ബോക്സിലെ ചോദ്യങ്ങൾക്ക് കൃത്യമായ മറുപടി നൽകുക.\n5. സെഷൻ സമാപനം: നിശ്ചയിച്ച സമയപരിധിക്കുള്ളിൽ ക്ലാസ്സ് പൂർത്തിയാക്കുക."
        ]);
    }

    // ── Self-healing for 5 Faculty Live Session Utility Templates (Phase 3 & Phase 13) ──
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $insTplKw = ($driver === 'sqlite') ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    $facultyTplsToSeed = [
        'faculty_session_scheduled' => [
            'body' => "Hi *{{1}}*,\n\n✅Confirm the following schedule. \n\nThis is a {{2}} session.\nTopic: {{3}}\nDate & Time: *{{4}}*\nCourses: {{5}}\nProposed Duration: *{{6}}*\n\nPlease join on time and be ready before the scheduled start time.\n*Read the faculty instructions before your session.*",
            'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Read Instructions']
            ]
        ],
        'faculty_session_reminder' => [
            'body' => "Hi *{{1}}*, \n \nThis is a reminder that you have a PEPP live session today at *{{2}}*.  \n\nWe hope you are prepared well for the session.  \nThank you!",
            'buttons' => []
        ],
        'faculty_session_start' => [
            'body' => "Dear *{{1}}*,  \n\nYour PEPP live session is scheduled to start at *{{2}}*.  \n\nSession: {{3}}\nCourses: {{4}}\nDuration: {{5}}\n\nNote: \n1. *Automatic recording* and Gemini notes *will start when you enter the session*.\n2. Please *do not enter the session earlier than 5 minutes* before the scheduled start time.",
            'buttons' => [
                ['type' => 'URL', 'text' => 'Start Live', 'url' => 'https://meet.google.com/{{1}}']
            ]
        ],
        'faculty_session_start_now' => [
            'body' => "Hi *{{1}}*,  \n\n✅ *Your PEPP live session is starting now.*\n_Please join your session now and begin the session as scheduled._",
            'buttons' => [
                ['type' => 'URL', 'text' => 'Start Now', 'url' => 'https://meet.google.com/{{1}}']
            ]
        ],
        'faculty_session_cancelled' => [
            'body' => "Hi *{{1}}*,  \nYour PEPP live session scheduled for *{{2}}* has been cancelled. \n\nSession: {{3}}\nCourses: {{4}} \n\nPlease do not join the previously shared session link.",
            'buttons' => []
        ]
    ];

    $stmtCheckTpl = $pdo->prepare("SELECT COUNT(*) FROM communication_templates WHERE template_name = ?");
    $stmtAddTpl = $pdo->prepare("
        {$insTplKw} INTO communication_templates (channel, template_name, language, status, category, quality_status, meta_data, updated_at)
        VALUES ('whatsapp', ?, 'en', 'approved', 'utility', 'green', ?, CURRENT_TIMESTAMP)
    ");

    foreach ($facultyTplsToSeed as $fTplName => $fTplConf) {
        $stmtCheckTpl->execute([$fTplName]);
        if ((int)$stmtCheckTpl->fetchColumn() === 0) {
            $components = [
                ['type' => 'BODY', 'text' => $fTplConf['body']]
            ];
            if (!empty($fTplConf['buttons'])) {
                $components[] = [
                    'type' => 'BUTTONS',
                    'buttons' => $fTplConf['buttons']
                ];
            }
            $fMeta = [
                'components' => $components,
                'body_text' => $fTplConf['body'],
                'header_text' => '',
                'footer_text' => '',
                'buttons' => $fTplConf['buttons']
            ];
            $stmtAddTpl->execute([$fTplName, json_encode($fMeta)]);
        }
    }
} catch (Exception $e) {
    $error_message = 'Self-healing database setup failed. Error: ' . $e->getMessage();
}

// Load settings for Meta API connection
$stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$businessId  = $settings['whatsapp_business_id'] ?? '';
$accessToken = $settings['whatsapp_access_token'] ?? '';
$apiVersion  = $settings['whatsapp_api_version'] ?? 'v20.0';

/* ── POST: Sync templates from Meta Cloud API ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_templates') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } elseif (empty($accessToken)) {
        $error_message = 'Please configure the Global WhatsApp Access Token in communication settings first.';
    } else {
        require_once 'includes/communication/WhatsAppAccountResolver.php';
        $resolver = WhatsAppAccountResolver::getInstance($pdo);

        // Build list of target WABAs to sync using the single shared global access token
        $wabaTargets = [];
        $admissionsWaba = $resolver->getWabaId('admissions') ?: $businessId;
        if (!empty($admissionsWaba)) {
            $wabaTargets['admissions'] = [
                'waba_id' => $admissionsWaba,
                'account_id' => 1,
                'sender_key' => 'admissions',
                'label' => 'PEPP Learning'
            ];
        }

        $notifWaba = $resolver->getWabaId('notifications');
        if (!empty($notifWaba) && $notifWaba !== $admissionsWaba) {
            $wabaTargets['notifications'] = [
                'waba_id' => $notifWaba,
                'account_id' => 3,
                'sender_key' => 'notifications',
                'label' => 'PEPP Updates'
            ];
        }

        $totalSynced = 0;
        $syncBreakdown = [];
        $syncErrors = [];

        $pdo->beginTransaction();
        try {
            $hasScopeCols = $resolver->hasTemplateAccountColumns();
            if ($hasScopeCols) {
                $stmtFindExisting = $pdo->prepare("
                    SELECT id FROM communication_templates
                    WHERE channel = 'whatsapp'
                      AND sender_account_id = ?
                      AND (template_name = ? OR (meta_template_id IS NOT NULL AND meta_template_id = ?))
                    ORDER BY id ASC LIMIT 1
                ");
                $stmtUpdateInPlace = $pdo->prepare("
                    UPDATE communication_templates
                    SET waba_id = ?,
                        meta_template_id = ?,
                        template_name = ?,
                        language = ?,
                        status = ?,
                        category = ?,
                        quality_status = ?,
                        rejection_reason = ?,
                        meta_data = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUpsert = $pdo->prepare("
                    INSERT INTO communication_templates (channel, sender_account_id, waba_id, meta_template_id, template_name, language, status, category, quality_status, rejection_reason, meta_data, updated_at)
                    VALUES ('whatsapp', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        waba_id = VALUES(waba_id),
                        meta_template_id = VALUES(meta_template_id),
                        status = VALUES(status),
                        category = VALUES(category),
                        quality_status = VALUES(quality_status),
                        rejection_reason = VALUES(rejection_reason),
                        meta_data = VALUES(meta_data),
                        updated_at = NOW()
                ");
            } else {
                $stmtUpsert = $pdo->prepare("
                    INSERT INTO communication_templates (channel, template_name, language, status, category, quality_status, rejection_reason, meta_data, updated_at)
                    VALUES ('whatsapp', ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE status = VALUES(status), category = VALUES(category), quality_status = VALUES(quality_status), rejection_reason = VALUES(rejection_reason), meta_data = VALUES(meta_data), updated_at = NOW()
                ");
            }

            foreach ($wabaTargets as $tKey => $target) {
                $targetWaba = $target['waba_id'];
                $targetAccountId = (int)$target['account_id'];
                $url = "https://graph.facebook.com/{$apiVersion}/{$targetWaba}/message_templates?limit=100";
                $headers = ["Authorization: Bearer {$accessToken}"];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                if ($err) {
                    $syncErrors[] = "{$target['label']} (WABA {$targetWaba}): CURL Error - {$err}";
                    continue;
                }

                $data = json_decode($response, true);
                if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
                    $templates = $data['data'];
                    $wabaCount = 0;

                    foreach ($templates as $tpl) {
                        $metaTplId = $tpl['id'] ?? null;
                        $name = $tpl['name'] ?? '';
                        $lang = $tpl['language'] ?? 'en';
                        $status = strtolower($tpl['status'] ?? 'approved');
                        $category = $tpl['category'] ?? '';
                        $qualityStatus = strtolower($tpl['quality_score']['score'] ?? 'unknown');
                        $rejectedReason = $tpl['rejected_reason'] ?? null;

                        // Extract text body and components metadata
                        $bodyText = '';
                        $headerText = '';
                        $footerText = '';
                        foreach ($tpl['components'] ?? [] as $comp) {
                            if (($comp['type'] ?? '') === 'BODY') {
                                $bodyText = $comp['text'] ?? '';
                            } elseif (($comp['type'] ?? '') === 'HEADER') {
                                $headerText = $comp['text'] ?? '';
                            } elseif (($comp['type'] ?? '') === 'FOOTER') {
                                $footerText = $comp['text'] ?? '';
                            }
                        }

                        $bodyVars = [];
                        preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $bMatches);
                        if (!empty($bMatches[1])) {
                            $bodyVars = array_values(array_unique(array_map('intval', $bMatches[1])));
                            sort($bodyVars);
                        }

                        $metaData = json_encode([
                            'components' => $tpl['components'] ?? [],
                            'body_text' => $bodyText,
                            'body_vars' => $bodyVars,
                            'header_text' => $headerText,
                            'footer_text' => $footerText,
                            'waba_id' => $targetWaba,
                            'sender_key' => $target['sender_key'],
                            'account_id' => $targetAccountId,
                            'meta_template_id' => $metaTplId
                        ]);

                        if ($hasScopeCols) {
                            $stmtFindExisting->execute([$targetAccountId, $name, $metaTplId]);
                            $existingId = $stmtFindExisting->fetchColumn();

                            if ($existingId) {
                                $stmtUpdateInPlace->execute([
                                    $targetWaba,
                                    $metaTplId,
                                    $name,
                                    $lang,
                                    $status,
                                    $category,
                                    $qualityStatus,
                                    $rejectedReason,
                                    $metaData,
                                    $existingId
                                ]);
                            } else {
                                $stmtUpsert->execute([
                                    $targetAccountId,
                                    $targetWaba,
                                    $metaTplId,
                                    $name,
                                    $lang,
                                    $status,
                                    $category,
                                    $qualityStatus,
                                    $rejectedReason,
                                    $metaData
                                ]);
                            }
                        } else {
                            $stmtUpsert->execute([$name, $lang, $status, $category, $qualityStatus, $rejectedReason, $metaData]);
                        }
                        $wabaCount++;
                    }

                    $totalSynced += $wabaCount;
                    $syncBreakdown[] = "{$wabaCount} from {$target['label']}";
                } else {
                    $details = $data['error']['message'] ?? 'Meta API error';
                    $syncErrors[] = "{$target['label']} (WABA {$targetWaba}) [{$httpCode}]: {$details}";
                }
            }

            $pdo->commit();

            if ($totalSynced > 0) {
                $summary = implode(', ', $syncBreakdown);
                $success_message = "Successfully synchronized {$totalSynced} templates ({$summary}) using global System User token.";
            } elseif (!empty($syncErrors)) {
                $error_message = "Template sync failed: " . implode(' | ', $syncErrors);
            } else {
                $success_message = "Synchronization complete. No templates found on configured WABAs.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = "Database Synchronization failed: " . $e->getMessage();
        }
    }
}

// Load event mappings
$eventMappings = [];
try {
    $eventMappings = $pdo->query("SELECT * FROM communication_event_mappings ORDER BY id ASC")->fetchAll();
} catch (Exception $ex) {}

// Handle save mappings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_event_mappings') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $posted_mappings = $_POST['mappings'] ?? [];
        require_once 'includes/communication/WhatsAppAccountResolver.php';
        $resolver = WhatsAppAccountResolver::getInstance($pdo);

        // Fetch all approved templates for validation lookup
        $stmtTpls = $pdo->query("SELECT * FROM communication_templates WHERE channel = 'whatsapp'");
        $allTplsById = [];
        $allTplsByName = [];
        while ($tRow = $stmtTpls->fetch(PDO::FETCH_ASSOC)) {
            $tNorm = $resolver->normalizeTemplateRow($tRow);
            $allTplsById[(int)$tNorm['id']] = $tNorm;
            $allTplsByName[$tNorm['template_name']][] = $tNorm;
        }

        // Fetch valid ERP variables list
        require_once 'includes/communication/CommunicationHelper.php';
        $validERPKeys = array_keys(CommunicationHelper::getERPVariables());

        $validationError = '';
        foreach ($posted_mappings as $evName => $data) {
            $tplId = !empty($data['template_id']) ? (int)$data['template_id'] : null;
            $tplName = !empty($data['template_name']) ? trim($data['template_name']) : null;
            $evSenderAcc = $resolver->resolveAccountForEvent($evName);
            $evSenderId = $evSenderAcc ? (int)$evSenderAcc['id'] : 1;

            $tpl = null;
            if ($tplId && isset($allTplsById[$tplId])) {
                $tpl = $allTplsById[$tplId];
            } elseif ($tplName && isset($allTplsByName[$tplName])) {
                // Find template matching event sender account
                foreach ($allTplsByName[$tplName] as $cand) {
                    if ((int)$cand['sender_account_id'] === $evSenderId) {
                        $tpl = $cand;
                        break;
                    }
                }
                if (!$tpl) {
                    $tpl = $allTplsByName[$tplName][0];
                }
            }

            if ($tplId || $tplName) {
                if (!$tpl) {
                    $validationError = "Selected template for event '{$evName}' does not exist.";
                    break;
                }

                if (strtolower($tpl['status']) !== 'approved') {
                    $validationError = "Selected template '{$tpl['template_name']}' for event '{$evName}' is not APPROVED (Current status: {$tpl['status']}).";
                    break;
                }

                // Verify sender account alignment: PEPP Updates vs PEPP Learning
                if ((int)$tpl['sender_account_id'] !== $evSenderId) {
                    $expectedSenderName = $evSenderAcc['display_name'] ?? "Account {$evSenderId}";
                    $tplSenderAcc = $resolver->getAccount($tpl['sender_account_id']);
                    $tplSenderName = $tplSenderAcc['display_name'] ?? "Account {$tpl['sender_account_id']}";
                    $validationError = "Cross-WABA Mismatch: Event '{$evName}' belongs to {$expectedSenderName}, but template '{$tpl['template_name']}' belongs to {$tplSenderName}.";
                    break;
                }

                // Get parameter definition from canonical helper
                $paramDef = CommunicationHelper::getTemplateParameterDefinition($tpl['meta_data']);
                $expectedIndexes = $paramDef['body']['indexes'];

                $rawParams = $data['parameters'] ?? [];

                // Ensure all expected parameters are mapped
                foreach ($expectedIndexes as $i) {
                    if (!isset($rawParams[$i])) {
                        $validationError = "Parameter {{{$i}}} is required but missing in mapping for template '{$tpl['template_name']}'.";
                        break 2;
                    }

                    $paramType = $rawParams[$i]['type'] ?? 'variable';
                    $paramVal = trim($rawParams[$i]['value'] ?? '');

                    if ($paramType === 'variable') {
                        if (empty($paramVal)) {
                            $validationError = "Please select an ERP variable for parameter {{{$i}}} of template '{$tpl['template_name']}'.";
                            break 2;
                        }
                        if (!in_array($paramVal, $validERPKeys, true)) {
                            $validationError = "Invalid ERP variable key '{$paramVal}' for parameter {{{$i}}} of template '{$tpl['template_name']}'.";
                            break 2;
                        }
                    } else {
                        // Custom text validation
                        if ($paramVal === '') {
                            $validationError = "Custom text for parameter {{{$i}}} of template '{$tpl['template_name']}' cannot be empty.";
                            break 2;
                        }
                    }
                }

                // Ensure no extraneous parameters beyond expected indexes are sent
                foreach ($rawParams as $idx => $param) {
                    if (!in_array((int)$idx, $expectedIndexes, true)) {
                        $validationError = "Invalid variable index '{{{$idx}}}' for template '{$tpl['template_name']}' (not part of approved BODY variables).";
                        break 2;
                    }
                }
            }
        }

        if ($validationError !== '') {
            $error_message = $validationError;
        } else {
            $pdo->beginTransaction();
            try {
                $hasCemCols = $resolver->hasEventMappingAccountColumns();
                if ($hasCemCols) {
                    $stmtUp = $pdo->prepare("UPDATE communication_event_mappings SET template_id = ?, template_name = ?, parameter_mappings = ?, sender_account_id = ? WHERE event_name = ?");
                } else {
                    $stmtUp = $pdo->prepare("UPDATE communication_event_mappings SET template_name = ?, parameter_mappings = ? WHERE event_name = ?");
                }

                foreach ($posted_mappings as $evName => $data) {
                    $tplId = !empty($data['template_id']) ? (int)$data['template_id'] : null;
                    $tplName = !empty($data['template_name']) ? trim($data['template_name']) : null;
                    $evSenderAcc = $resolver->resolveAccountForEvent($evName);
                    $evSenderId = $evSenderAcc ? (int)$evSenderAcc['id'] : 1;

                    $tpl = null;
                    if ($tplId && isset($allTplsById[$tplId])) {
                        $tpl = $allTplsById[$tplId];
                        $tplName = $tpl['template_name'];
                    } elseif ($tplName && isset($allTplsByName[$tplName])) {
                        foreach ($allTplsByName[$tplName] as $cand) {
                            if ((int)$cand['sender_account_id'] === $evSenderId) {
                                $tpl = $cand;
                                break;
                            }
                        }
                        if (!$tpl) $tpl = $allTplsByName[$tplName][0];
                        $tplId = (int)$tpl['id'];
                    }

                    $rawParams = $data['parameters'] ?? [];
                    $params = [];
                    if ($tpl) {
                        $paramDef = CommunicationHelper::getTemplateParameterDefinition($tpl['meta_data'] ?? []);
                        $expectedIndexes = $paramDef['body']['indexes'];
                        foreach ($expectedIndexes as $idx) {
                            if (isset($rawParams[$idx])) {
                                $params[(int)$idx] = [
                                    'type' => $rawParams[$idx]['type'] ?? 'variable',
                                    'value' => trim($rawParams[$idx]['value'] ?? '')
                                ];
                            }
                        }
                    }

                    if ($hasCemCols) {
                        $stmtUp->execute([$tpl ? $tplId : null, $tpl ? $tplName : null, json_encode($params), $evSenderId, $evName]);
                    } else {
                        $stmtUp->execute([$tpl ? $tplName : null, json_encode($params), $evName]);
                    }
                }
                $pdo->commit();
                $success_message = 'Event template mappings updated successfully!';
                // Reload event mappings
                $eventMappings = $pdo->query("SELECT * FROM communication_event_mappings ORDER BY id ASC")->fetchAll();
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = 'Failed to save mappings: ' . $e->getMessage();
            }
        }
    }
}

// Handle send test template
$test_response = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_test_template') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $phone = trim($_POST['test_phone'] ?? '');
        $tplName = trim($_POST['test_template_name'] ?? '');
        $paramsInput = $_POST['test_params'] ?? [];

        if (empty($phone) || empty($tplName)) {
            $error_message = 'Please specify both recipient phone and template.';
        } else {
            try {
                // Fetch template details to get language
                $testTplId = !empty($_POST['test_template_id']) ? (int)$_POST['test_template_id'] : 0;
                $testSenderId = !empty($_POST['test_sender_account_id']) ? (int)$_POST['test_sender_account_id'] : 0;

                $template = null;
                if ($testTplId > 0) {
                    $template = $resolver->getTemplateById($testTplId, $testSenderId ?: null);
                }
                if (!$template && !empty($tplName)) {
                    $template = $resolver->resolveTemplate($tplName, $testSenderId ?: null);
                }

                if (!$template) {
                    throw new Exception("Template '{$tplName}' not found for the selected sender account.");
                }

                $template = $resolver->normalizeTemplateRow($template);

                if (strtolower($template['status']) !== 'approved') {
                    throw new Exception("Template '{$tplName}' is not approved (Status: {$template['status']}).");
                }

                require_once 'includes/communication/CommunicationHelper.php';
                $paramDef = CommunicationHelper::getTemplateParameterDefinition($template['meta_data']);
                $expectedIndexes = $paramDef['body']['indexes'];

                // Parse parameters strictly according to canonical BODY indexes
                $resolvedParams = [];
                foreach ($expectedIndexes as $idx) {
                    $resolvedParams[] = trim($paramsInput[$idx] ?? '');
                }

                $templateData = [
                    'name' => $template['template_name'],
                    'language' => $template['language'] ?? 'en',
                    'parameters' => $resolvedParams
                ];

                require_once 'includes/communication/CommunicationEngine.php';
                $engine = CommunicationEngine::getInstance($pdo);

                // Authoritative sender account from normalized template
                $senderAccId = (int)$template['sender_account_id'];
                $provider = $engine->getProvider('whatsapp', $senderAccId);

                // Trigger send directly via provider for instant feedback
                $res = $provider->sendMessage($phone, 'Test Dispatch', '', '', [], $templateData);

                if ($res && isset($res['success']) && $res['success'] === true) {
                    $success_message = "Test WhatsApp template '{$tplName}' successfully sent to {$phone}! Message ID: " . $res['message_id'];
                    $test_response = $res['response'];
                } else {
                    $error_message = "Meta API Dispatch Failed: " . $provider->getLastError();
                }
            } catch (Exception $e) {
                $error_message = "Test failed: " . $e->getMessage();
            }
        }
    }
}

// ── POST: Faculty Instruction Management (Phase 2) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add_instruction', 'edit_instruction', 'toggle_instruction', 'delete_instruction'], true)) {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $act = $_POST['action'];
        if ($act === 'add_instruction') {
            $code = strtolower(trim($_POST['language_code'] ?? ''));
            $name = trim($_POST['language_name'] ?? '');
            $title = trim($_POST['instruction_title'] ?? '');
            $body = trim($_POST['instruction_body'] ?? '');
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if (empty($code) || empty($name) || empty($title) || empty($body)) {
                $error_message = 'Language code, language name, title, and instruction content are all required.';
            } elseif (mb_strlen($body, 'UTF-8') > 1024) {
                $error_message = 'Instruction body exceeds maximum allowed 1024 characters (Current: ' . mb_strlen($body, 'UTF-8') . ').';
            } else {
                try {
                    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                    $insKw = ($driver === 'sqlite') ? 'INSERT OR REPLACE' : 'INSERT INTO';
                    $stmtIns = $pdo->prepare("{$insKw} faculty_session_instructions (language_code, language_name, instruction_title, instruction_body, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                    $stmtIns->execute([$code, $name, $title, $body, $isActive]);
                    $success_message = "Instruction language '{$name}' ({$code}) created successfully!";
                } catch (Exception $e) {
                    $error_message = 'Failed to add instruction: ' . $e->getMessage();
                }
            }
        } elseif ($act === 'edit_instruction') {
            $id = (int)($_POST['instruction_id'] ?? 0);
            $name = trim($_POST['language_name'] ?? '');
            $title = trim($_POST['instruction_title'] ?? '');
            $body = trim($_POST['instruction_body'] ?? '');
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($id <= 0 || empty($name) || empty($title) || empty($body)) {
                $error_message = 'All fields are required to update instructions.';
            } elseif (mb_strlen($body, 'UTF-8') > 1024) {
                $error_message = 'Instruction body exceeds maximum allowed 1024 characters (Current: ' . mb_strlen($body, 'UTF-8') . ').';
            } else {
                try {
                    $stmtUpd = $pdo->prepare("UPDATE faculty_session_instructions SET language_name = ?, instruction_title = ?, instruction_body = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmtUpd->execute([$name, $title, $body, $isActive, $id]);
                    $success_message = "Instruction for '{$name}' updated successfully!";
                } catch (Exception $e) {
                    $error_message = 'Failed to update instruction: ' . $e->getMessage();
                }
            }
        } elseif ($act === 'toggle_instruction') {
            $id = (int)($_POST['instruction_id'] ?? 0);
            try {
                $stmtCur = $pdo->prepare("SELECT is_active FROM faculty_session_instructions WHERE id = ?");
                $stmtCur->execute([$id]);
                $cur = (int)$stmtCur->fetchColumn();

                if ($cur === 1) {
                    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM faculty_session_instructions WHERE is_active = 1")->fetchColumn();
                    if ($activeCount <= 1) {
                        $error_message = 'Cannot deactivate: At least one instruction language must remain active.';
                    } else {
                        $pdo->prepare("UPDATE faculty_session_instructions SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                        $success_message = 'Instruction language deactivated.';
                    }
                } else {
                    $pdo->prepare("UPDATE faculty_session_instructions SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                    $success_message = 'Instruction language activated.';
                }
            } catch (Exception $e) {
                $error_message = 'Failed to toggle instruction: ' . $e->getMessage();
            }
        } elseif ($act === 'delete_instruction') {
            $id = (int)($_POST['instruction_id'] ?? 0);
            try {
                $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM faculty_session_instructions WHERE is_active = 1 AND id != {$id}")->fetchColumn();
                if ($activeCount < 1) {
                    $error_message = 'Cannot delete: At least one active instruction language must remain in the system.';
                } else {
                    $pdo->prepare("DELETE FROM faculty_session_instructions WHERE id = ?")->execute([$id]);
                    $success_message = 'Instruction language deleted successfully.';
                }
            } catch (Exception $e) {
                $error_message = 'Failed to delete instruction: ' . $e->getMessage();
            }
        }
    }
}

// Determine active sender account context (default to Account 3 / PEPP Updates)
$selectedAccountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 3;
if ($selectedAccountId !== 1 && $selectedAccountId !== 3) {
    $selectedAccountId = 3;
}

// Load local synchronized templates
$localTemplatesRaw = [];
try {
    $localTemplatesRaw = $pdo->query("SELECT * FROM communication_templates WHERE channel = 'whatsapp' ORDER BY template_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {}

require_once 'includes/communication/WhatsAppAccountResolver.php';
$resolver = WhatsAppAccountResolver::getInstance($pdo);

// Deduplicate rows canonically at the presentation layer (resolving en vs en_US duplicates)
$canonicalAll = $resolver->canonicalizeTemplateRows($localTemplatesRaw);

$templatesBySender = [1 => [], 3 => []];
$admissionsApprovedCount = 0;
$notifApprovedCount = 0;
$admissionsMarketingCount = 0;
$notifMarketingCount = 0;
$approvedTemplates = [];
$approvedTemplatesById = [];
$approvedTemplatesBySender = [1 => [], 3 => []];

require_once 'includes/communication/CommunicationHelper.php';
foreach ($canonicalAll as $tpl) {
    $sId = (int)$tpl['sender_account_id'];
    if (!isset($templatesBySender[$sId])) {
        $templatesBySender[$sId] = [];
    }
    $templatesBySender[$sId][] = $tpl;

    $isApproved = (strtolower($tpl['status']) === 'approved');
    $isMarketing = (strtoupper($tpl['category'] ?? '') === 'MARKETING');

    if ($sId === 3) {
        if ($isApproved) $notifApprovedCount++;
        if ($isMarketing) $notifMarketingCount++;
    } else {
        if ($isApproved) $admissionsApprovedCount++;
        if ($isMarketing) $admissionsMarketingCount++;
    }

    if ($isApproved) {
        $paramDef = CommunicationHelper::getTemplateParameterDefinition($tpl['meta_data']);
        $tData = [
            'id' => (int)$tpl['id'],
            'name' => $tpl['template_name'],
            'sender_account_id' => $sId,
            'waba_id' => $tpl['waba_id'],
            'category' => $tpl['category'],
            'language' => $tpl['language'],
            'param_count' => $paramDef['body']['count'],
            'body_indexes' => $paramDef['body']['indexes'],
            'body_text' => $paramDef['body']['text'],
            'has_header_var' => $paramDef['header']['has_variable'],
            'header_indexes' => $paramDef['header']['indexes'],
            'has_button_url_var' => $paramDef['button_url']['has_variable'],
            'button_url_indexes' => $paramDef['button_url']['indexes']
        ];
        $approvedTemplates[$tpl['template_name']] = $tData;
        $approvedTemplatesById[$tpl['id']] = $tData;
        $approvedTemplatesBySender[$sId][$tpl['id']] = $tData;
    }
}

// Strictly scope displayed templates to the selected account
$localTemplates = $templatesBySender[$selectedAccountId] ?? [];

$admissionsAcc = $resolver->getAccount(1);
$notifAcc = $resolver->getAccount(3);
$selectedAcc = $resolver->getAccount($selectedAccountId);

$notifTotalCount = count($templatesBySender[3] ?? []);
$admissionsTotalCount = count($templatesBySender[1] ?? []);

// Load faculty instructions
$facultyInstructions = [];
try {
    $facultyInstructions = $pdo->query("SELECT * FROM faculty_session_instructions ORDER BY is_active DESC, language_name ASC")->fetchAll();
} catch (Exception $ex) {}

include 'includes/admin_nav.php';
?>

<div class="container-fluid" style="padding:20px;">
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
        <a href="communication-templates.php" class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-layer-group"></i> Meta Templates Sync</a>
        <a href="whatsapp-marketing-templates.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-magic"></i> Marketing Templates</a>
        <a href="communication-campaigns.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-bullhorn"></i> Bulk Campaigns</a>
        <a href="whatsapp-inbox.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fab fa-whatsapp"></i> WhatsApp Inbox</a>
    </div>

    <?php $currentTab = $_GET['tab'] ?? 'sync'; ?>
    <!-- ── SUB-PAGE TABS ── -->
    <div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:2px solid #e5e7eb; padding-bottom:8px; flex-wrap:wrap;">
        <a href="?tab=sync&account_id=<?php echo $selectedAccountId; ?>" class="btn btn-sm <?php echo $currentTab === 'sync' ? 'btn-primary' : 'btn-outline'; ?>" style="border-radius:8px; font-weight:700;">
            <i class="fas fa-layer-group"></i> Meta Templates Sync &amp; Mappings
        </a>
        <a href="?tab=instructions&account_id=<?php echo $selectedAccountId; ?>" class="btn btn-sm <?php echo $currentTab === 'instructions' ? 'btn-primary' : 'btn-outline'; ?>" style="border-radius:8px; font-weight:700;">
            <i class="fas fa-chalkboard-user"></i> Faculty Live Session Instructions (<?php echo count($facultyInstructions); ?>)
        </a>
        <a href="?tab=faculty_templates&account_id=<?php echo $selectedAccountId; ?>" class="btn btn-sm <?php echo $currentTab === 'faculty_templates' ? 'btn-primary' : 'btn-outline'; ?>" style="border-radius:8px; font-weight:700;">
            <i class="fab fa-whatsapp"></i> Faculty Live Session Templates (5)
        </a>
    </div>

    <?php if ($currentTab === 'sync'): ?>
    <!-- Sync Action Widget -->
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:#1f2937;"><i class="fas fa-sync" style="color:#8b5cf6; margin-right:4px;"></i> Synchronize Approved Meta Templates</h3>
            <p style="margin:4px 0 0; font-size:0.8rem; color:#6b7280;">Downloads and syncs all message templates across both PEPP Learning and PEPP Updates WABAs using the global System User token.</p>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="sync_templates">
            <button type="submit" class="btn btn-primary" style="padding:10px 20px; font-weight:700; border-radius:8px;">
                <i class="fas fa-arrow-rotate-forward"></i> Sync WhatsApp Templates
            </button>
        </form>
    </div>

    <!-- ── CLEAN ACCOUNT SELECTOR (PHASE 3) ── -->
    <div style="margin-bottom:24px;">
        <div style="font-size:0.8rem; font-weight:700; color:#4b5563; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">
            <i class="fas fa-building" style="color:#7c3aed; margin-right:4px;"></i> Select WhatsApp Account Scope
        </div>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:16px;">
            <!-- Card 3: PEPP Updates (Account 3 - Default / Marketing) -->
            <a href="?tab=sync&account_id=3" style="text-decoration:none !important; color:inherit;">
                <div id="sender-card-3" class="sender-scope-card" style="cursor:pointer; background:<?php echo $selectedAccountId === 3 ? '#faf5ff' : '#fff'; ?>; border:<?php echo $selectedAccountId === 3 ? '2.5px solid #7c3aed' : '1.5px solid #e5e7eb'; ?>; border-radius:14px; padding:18px; box-shadow:<?php echo $selectedAccountId === 3 ? '0 4px 12px rgba(124,58,237,0.12)' : '0 1px 3px rgba(0,0,0,0.03)'; ?>; transition:all 0.15s ease;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span style="display:inline-flex; align-items:center; gap:6px; font-size:0.7rem; font-weight:800; background:#f5f3ff; color:#6d28d9; padding:3px 8px; border-radius:6px; text-transform:uppercase;">
                                    <span style="width:7px; height:7px; border-radius:50%; background:#8b5cf6;"></span> Account 3 &bull; Notifications
                                </span>
                                <?php if ($selectedAccountId === 3): ?>
                                    <span style="font-size:0.68rem; font-weight:800; background:#7c3aed; color:#fff; padding:2px 8px; border-radius:6px;">
                                        <i class="fas fa-check"></i> ACTIVE SCOPE
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h4 style="margin:8px 0 2px; font-size:1.15rem; font-weight:800; color:#111827;">PEPP Updates</h4>
                            <div style="font-size:0.82rem; font-weight:600; color:#374151; margin-top:2px;">
                                <i class="fab fa-whatsapp" style="color:#7c3aed;"></i> +91 79943 04400
                            </div>
                            <div style="font-size:0.72rem; color:#6b7280; margin-top:4px;">
                                WABA: <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:600;">1099020233033644</code>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:1.8rem; font-weight:800; color:#7c3aed; line-height:1;"><?php echo $notifTotalCount; ?></div>
                            <div style="font-size:0.7rem; color:#6b7280; font-weight:600; margin-top:4px;">Total Templates</div>
                            <div style="font-size:0.68rem; color:#059669; font-weight:700; margin-top:2px;"><?php echo $notifApprovedCount; ?> Approved</div>
                        </div>
                    </div>
                    <div style="margin-top:14px; padding-top:10px; border-top:1px dashed #e5e7eb; display:flex; justify-content:space-between; align-items:center; font-size:0.75rem;">
                        <span style="color:#6b7280;">Marketing: <strong style="color:#7c3aed;"><?php echo $notifMarketingCount; ?></strong></span>
                        <span style="color:<?php echo $selectedAccountId === 3 ? '#7c3aed' : '#94a3b8'; ?>; font-weight:700;">
                            <?php echo $selectedAccountId === 3 ? '<i class="fas fa-circle-dot"></i> Currently Viewing' : 'Click to view PEPP Updates templates'; ?>
                        </span>
                    </div>
                </div>
            </a>

            <!-- Card 1: PEPP Learning (Admissions) -->
            <a href="?tab=sync&account_id=1" style="text-decoration:none !important; color:inherit;">
                <div id="sender-card-1" class="sender-scope-card" style="cursor:pointer; background:<?php echo $selectedAccountId === 1 ? '#ecfdf5' : '#fff'; ?>; border:<?php echo $selectedAccountId === 1 ? '2.5px solid #059669' : '1.5px solid #e5e7eb'; ?>; border-radius:14px; padding:18px; box-shadow:<?php echo $selectedAccountId === 1 ? '0 4px 12px rgba(5,150,105,0.12)' : '0 1px 3px rgba(0,0,0,0.03)'; ?>; transition:all 0.15s ease;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span style="display:inline-flex; align-items:center; gap:6px; font-size:0.7rem; font-weight:800; background:#ecfdf5; color:#047857; padding:3px 8px; border-radius:6px; text-transform:uppercase;">
                                    <span style="width:7px; height:7px; border-radius:50%; background:#10b981;"></span> Account 1 &bull; Admissions
                                </span>
                                <?php if ($selectedAccountId === 1): ?>
                                    <span style="font-size:0.68rem; font-weight:800; background:#059669; color:#fff; padding:2px 8px; border-radius:6px;">
                                        <i class="fas fa-check"></i> ACTIVE SCOPE
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h4 style="margin:8px 0 2px; font-size:1.15rem; font-weight:800; color:#111827;">PEPP Learning</h4>
                            <div style="font-size:0.82rem; font-weight:600; color:#374151; margin-top:2px;">
                                <i class="fab fa-whatsapp" style="color:#059669;"></i> +91 62825 63209
                            </div>
                            <div style="font-size:0.72rem; color:#6b7280; margin-top:4px;">
                                WABA: <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:600;">1410328164305566</code>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:1.8rem; font-weight:800; color:#059669; line-height:1;"><?php echo $admissionsTotalCount; ?></div>
                            <div style="font-size:0.7rem; color:#6b7280; font-weight:600; margin-top:4px;">Total Templates</div>
                            <div style="font-size:0.68rem; color:#059669; font-weight:700; margin-top:2px;"><?php echo $admissionsApprovedCount; ?> Approved</div>
                        </div>
                    </div>
                    <div style="margin-top:14px; padding-top:10px; border-top:1px dashed #e5e7eb; display:flex; justify-content:space-between; align-items:center; font-size:0.75rem;">
                        <span style="color:#6b7280;">Marketing: <strong style="color:#059669;"><?php echo $admissionsMarketingCount; ?></strong></span>
                        <span style="color:<?php echo $selectedAccountId === 1 ? '#059669' : '#94a3b8'; ?>; font-weight:700;">
                            <?php echo $selectedAccountId === 1 ? '<i class="fas fa-circle-dot"></i> Currently Viewing' : 'Click to view PEPP Learning templates'; ?>
                        </span>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <?php
    // Pre-defined events and their descriptions
    $eventDescriptions = [
        'student_registration' => 'Triggered when a student initiates registration / onboarding starts.',
        'student_approval' => 'Triggered when student enrollment is approved by administrators.',
        'student_rejection' => 'Triggered when student enrollment is rejected by administrators.',
        'installment_reminder' => 'Triggered when an installment payment is due (reminders).',
        'payment_receipt' => 'Triggered when a student payment is received and approved.',
        'session_scheduled' => 'Triggered when a live learning session or activity is scheduled.',
        'payment_rejection' => 'Triggered when a student payment proof is rejected by accounts.',
        'installment_overdue' => 'Triggered when a student installment payment due date has passed (overdue reminder).',
        'course_migration_completed' => 'Triggered when a student course migration or upgrade is successfully completed.',
        'alumni_verification_completed' => 'Triggered immediately after a PEPPian successfully completes alumni verification.',
        'alumni_referral_code_generated' => 'Triggered immediately after a new referral record and referral code are successfully created for an alumnus.',
        'referral_earning_credited' => 'Triggered after a referral earning is successfully credited to an alumnus wallet.',
        'referral_payout_sent' => 'Triggered after a referral payout is successfully recorded and paid to an alumnus.',
        'birthday_greeting' => "Triggered daily on a student's birthday with a personalized greeting and claim link.",
        'birthday_reward_claimed' => "Triggered after a student claims their birthday reward, sending coupon code, validity, and instructions link."
    ];
    ?>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-bottom:24px;">
        <!-- Event Mappings Card -->
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:#1f2937;"><i class="fas fa-link" style="color:#4f46e5; margin-right:4px;"></i> PEPP ERP Event Mappings</h3>
                <span class="badge" style="background:#e0e7ff; color:#3730a3; font-size:0.75rem; font-weight:700;">WABA / Sender Scoped</span>
            </div>
            <p style="margin:0 0 20px; font-size:0.8rem; color:#6b7280;">Map PEPP ERP core notification events to Meta-approved message templates. Dropdowns are automatically filtered to the correct sender WABA to prevent cross-account misconfiguration.</p>

            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_event_mappings">

                <div style="display:flex; flex-direction:column; gap:20px;">
                    <?php foreach ($eventMappings as $mapping): ?>
                        <?php
                            $eventName = $mapping['event_name'];
                            $mappedTpl = $mapping['template_name'] ?? '';
                            $mappedTplId = (int)($mapping['template_id'] ?? 0);
                            $paramMappings = json_decode($mapping['parameter_mappings'], true) ?: [];
                            $label = str_replace('_', ' ', $eventName);
                            $description = $eventDescriptions[$eventName] ?? '';

                            // Resolve canonical sender account for this event
                            $evSenderAcc = $resolver->resolveAccountForEvent($eventName);
                            $evSenderId = $evSenderAcc ? (int)$evSenderAcc['id'] : 1;
                            $evSenderBadge = ($evSenderId === 3)
                                ? '<span class="badge" style="background:#f5f3ff; color:#7c3aed; font-size:0.68rem; font-weight:700;"><i class="fab fa-whatsapp"></i> PEPP Updates (+91 79943 04400)</span>'
                                : '<span class="badge" style="background:#ecfdf5; color:#059669; font-size:0.68rem; font-weight:700;"><i class="fab fa-whatsapp"></i> PEPP Learning (+91 62825 63209)</span>';

                            // Templates available for this event (scoped to event sender account)
                            $evAvailableTemplates = $approvedTemplatesBySender[$evSenderId] ?? [];
                        ?>
                        <div style="border:1px solid #f3f4f6; padding:16px; border-radius:12px; background:#fbfbfb;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                                <div>
                                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                                        <h4 style="margin:0; font-size:0.9rem; font-weight:700; color:#374151; text-transform:capitalize;"><?php echo htmlspecialchars($label); ?></h4>
                                        <?php echo $evSenderBadge; ?>
                                    </div>
                                    <span style="font-size:0.75rem; color:#9ca3af;"><?php echo htmlspecialchars($description); ?></span>
                                </div>
                                <div>
                                    <input type="hidden" name="mappings[<?php echo htmlspecialchars($eventName); ?>][sender_account_id]" value="<?php echo $evSenderId; ?>">
                                    <select name="mappings[<?php echo htmlspecialchars($eventName); ?>][template_name]" class="form-control" style="width:260px; max-width:100%; border-radius:8px;" onchange="onMappingTemplateChange('<?php echo htmlspecialchars($eventName); ?>', this.value)">
                                        <option value="">- None (Disabled) -</option>
                                        <?php foreach ($evAvailableTemplates as $tpl): ?>
                                            <option value="<?php echo htmlspecialchars($tpl['name']); ?>" <?php echo $mappedTpl === $tpl['name'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tpl['name']))); ?> (<?php echo strtoupper(htmlspecialchars($tpl['language'])); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="mapping-tpl-hint-<?php echo htmlspecialchars($eventName); ?>" style="font-size:0.68rem; color:#6b7280; margin-top:3px; text-align:right;">
                                        <?php if (!empty($mappedTpl)): ?>
                                            Meta: <code><?php echo htmlspecialchars($mappedTpl); ?></code> &bull; <span style="color:#059669; font-weight:700;">Approved</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Parameter Fields Container -->
                            <div id="mapping-params-<?php echo htmlspecialchars($eventName); ?>" style="display: <?php echo !empty($mappedTpl) ? 'block' : 'none'; ?>; border-top:1px dashed #e5e7eb; padding-top:12px; margin-top:8px;">
                                <h5 style="margin:0 0 8px; font-size:0.8rem; font-weight:600; color:#4b5563;">Parameter Values Mappings</h5>
                                <div class="params-list" style="display:flex; flex-direction:column; gap:8px;">
                                    <?php if (!empty($mappedTpl) && isset($approvedTemplates[$mappedTpl])): ?>
                                        <?php
                                            $tplInfo = $approvedTemplates[$mappedTpl];
                                            $bodyIndexes = $tplInfo['body_indexes'] ?? [];
                                            if (!empty($bodyIndexes)):
                                                foreach ($bodyIndexes as $i):
                                                    $mType = $paramMappings[$i]['type'] ?? 'variable';
                                                    $mVal = $paramMappings[$i]['value'] ?? '';
                                        ?>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <span style="font-size:0.75rem; font-weight:700; color:#4b5563; min-width:40px;">{{<?php echo $i; ?>}} :</span>
                                                <select name="mappings[<?php echo htmlspecialchars($eventName); ?>][parameters][<?php echo $i; ?>][type]" class="form-control" style="width:110px; font-size:0.75rem;" onchange="onParamTypeChange('<?php echo htmlspecialchars($eventName); ?>', <?php echo $i; ?>, this.value)">
                                                    <option value="variable" <?php echo $mType === 'variable' ? 'selected' : ''; ?>>ERP Variable</option>
                                                    <option value="custom" <?php echo $mType === 'custom' ? 'selected' : ''; ?>>Custom Text</option>
                                                </select>

                                                <select name="mappings[<?php echo htmlspecialchars($eventName); ?>][parameters][<?php echo $i; ?>][value]" class="form-control value-field-variable" id="val-var-<?php echo htmlspecialchars($eventName); ?>-<?php echo $i; ?>" style="flex:1; font-size:0.75rem; display: <?php echo $mType === 'variable' ? 'inline-block' : 'none'; ?>;" onchange="updatePreviews('<?php echo htmlspecialchars($eventName); ?>')">
                                                    <option value="">-- Select Variable --</option>
                                                    <?php
                                                    $groupedVars = [];
                                                    foreach (CommunicationHelper::getERPVariables() as $k => $varInfo) {
                                                        $cat = $varInfo['category'] ?? 'General';
                                                        $groupedVars[$cat][$k] = $varInfo;
                                                    }
                                                    foreach ($groupedVars as $cat => $vars): ?>
                                                        <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                                                            <?php foreach ($vars as $k => $varInfo): ?>
                                                                <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $mVal === $k ? 'selected' : ''; ?> title="<?php echo htmlspecialchars($varInfo['description']); ?>">
                                                                    <?php echo htmlspecialchars($varInfo['label']); ?> — <?php echo htmlspecialchars($k); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </optgroup>
                                                    <?php endforeach; ?>
                                                </select>

                                                <!-- Custom string input -->
                                                <input type="text" name="mappings[<?php echo htmlspecialchars($eventName); ?>][parameters][<?php echo $i; ?>][value]" class="form-control value-field-custom" id="val-cust-<?php echo htmlspecialchars($eventName); ?>-<?php echo $i; ?>" value="<?php echo $mType === 'custom' ? htmlspecialchars($mVal) : ''; ?>" placeholder="Enter custom value..." style="flex:1; font-size:0.75rem; display: <?php echo $mType === 'custom' ? 'inline-block' : 'none'; ?>;" <?php echo $mType !== 'custom' ? 'disabled' : ''; ?> oninput="updatePreviews('<?php echo htmlspecialchars($eventName); ?>')">
                                            </div>

                                            <!-- Field Detail Description Info under dropdown -->
                                            <div id="desc-container-<?php echo htmlspecialchars($eventName); ?>-<?php echo $i; ?>" style="font-size:0.7rem; color:#6b7280; padding-left:48px; margin-top:-4px; margin-bottom:4px; display:<?php echo $mType === 'variable' ? 'block' : 'none'; ?>;">
                                                <?php
                                                if ($mType === 'variable' && isset(CommunicationHelper::getERPVariables()[$mVal])) {
                                                    echo '<i class="fas fa-info-circle"></i> ' . htmlspecialchars(CommunicationHelper::getERPVariables()[$mVal]['description']);
                                                }
                                                ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (!empty($tplInfo['has_button_url_var'])): ?>
                                            <div style="font-size:0.75rem; color:#4338ca; background:#e0e7ff; padding:6px 10px; border-radius:6px; margin-top:4px; display:flex; align-items:center; gap:6px;">
                                                <i class="fas fa-info-circle"></i>
                                                <span>Dynamic Button URL parameter (Google Meet link) is automatically resolved by the notification service and does not require manual ERP mapping.</span>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="font-size:0.75rem; color:#9ca3af;">No parameters required for this template.</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                </div>

                                <!-- Mapping Preview Card -->
                                <div id="mapping-preview-card-<?php echo htmlspecialchars($eventName); ?>" style="display: <?php echo !empty($mappedTpl) ? 'block' : 'none'; ?>; border: 1px solid #e5e7eb; background: #fff; padding: 14px; border-radius: 8px; margin-top: 15px;">
                                    <h6 style="margin: 0 0 8px 0; font-size: 0.8rem; font-weight: 700; color: #4b5563;"><i class="fas fa-eye" style="color: #10b981;"></i> Dynamic Mapping Preview: <span class="preview-template-name" style="color: #4f46e5;"><?php echo htmlspecialchars($mappedTpl); ?></span></h6>
                                    <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem; color: #374151;">
                                        <thead>
                                            <tr style="border-bottom: 1px solid #e5e7eb; text-align: left;">
                                                <th style="padding: 4px 8px; font-weight: 700; width: 25%;">Meta Variable</th>
                                                <th style="padding: 4px 8px; font-weight: 700; width: 35%;">ERP Variable Key</th>
                                                <th style="padding: 4px 8px; font-weight: 700; width: 40%;">Actual Example</th>
                                            </tr>
                                        </thead>
                                        <tbody class="preview-tbody" id="preview-tbody-<?php echo htmlspecialchars($eventName); ?>">
                                            <!-- Dynamically populated by updatePreviews() -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:20px; text-align:right;">
                    <button type="submit" class="btn btn-primary" style="padding:10px 20px; border-radius:8px;"><i class="fas fa-check"></i> Save Event Mappings</button>
                </div>
            </form>
        </div>

        <!-- Test Dispatch Form -->
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); height:fit-content;">
            <h3 style="margin:0 0 12px; font-size:1.1rem; font-weight:700; color:#1f2937;"><i class="fas fa-paper-plane" style="color:#10b981; margin-right:4px;"></i> Send Test Template</h3>
            <p style="margin:0 0 20px; font-size:0.8rem; color:#6b7280;">Test template parameters routing and validation directly via Meta APIs in real time.</p>

            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="send_test_template">

                <div style="display:flex; flex-direction:column; gap:16px;">
                    <div class="field">
                        <label style="font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:4px; display:block;">Recipient Phone <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="test_phone" class="form-control" style="width:100%; border-radius:8px;" placeholder="e.g. 919876543210" required>
                    </div>

                    <div class="field">
                        <label style="font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:4px; display:block;">Template Name <span style="color:#ef4444;">*</span></label>
                        <select name="test_template_name" id="test-tpl-select" class="form-control" style="width:100%; border-radius:8px;" onchange="onTestTemplateSelect(this)" required>
                            <option value="">- Select Template -</option>
                            <optgroup label="PEPP Learning (+91 62825 63209 &bull; WABA: 1410328164305566)">
                                <?php foreach ($approvedTemplatesBySender[1] as $tpl): ?>
                                    <option value="<?php echo htmlspecialchars($tpl['name']); ?>" data-id="<?php echo (int)$tpl['id']; ?>" data-sender="1" data-lang="<?php echo htmlspecialchars($tpl['language']); ?>">
                                        <?php echo htmlspecialchars($tpl['name']); ?> (<?php echo htmlspecialchars($tpl['language']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="PEPP Updates (+91 79943 04400 &bull; WABA: 1099020233033644)">
                                <?php foreach ($approvedTemplatesBySender[3] as $tpl): ?>
                                    <option value="<?php echo htmlspecialchars($tpl['name']); ?>" data-id="<?php echo (int)$tpl['id']; ?>" data-sender="3" data-lang="<?php echo htmlspecialchars($tpl['language']); ?>">
                                        <?php echo htmlspecialchars($tpl['name']); ?> (<?php echo htmlspecialchars($tpl['language']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <input type="hidden" name="test_template_id" id="test-tpl-id" value="">
                        <input type="hidden" name="test_sender_account_id" id="test-tpl-sender-id" value="">
                        <div id="test-tpl-sender-info" style="font-size:0.75rem; color:#6b7280; margin-top:4px;"></div>
                    </div>

                    <!-- Dynamic Test Parameters List -->
                    <div id="test-params-section" style="display:none; border-top:1px solid #f3f4f6; padding-top:16px;">
                        <h5 style="margin:0 0 8px; font-size:0.8rem; font-weight:700; color:#374151;">Parameter Values</h5>
                        <div id="test-params-list" style="display:flex; flex-direction:column; gap:12px;"></div>
                    </div>

                    <button type="submit" class="btn btn-success" style="width:100%; padding:10px; font-weight:700; border-radius:8px; margin-top:8px;">
                        <i class="fas fa-paper-plane"></i> Send Test Message
                    </button>
                </div>
            </form>

            <!-- Raw API Logs if response exists -->
            <?php if ($test_response): ?>
                <div style="margin-top:20px; border-top:1px solid #f3f4f6; padding-top:16px;">
                    <h5 style="margin:0 0 8px; font-size:0.8rem; font-weight:700; color:#374151;">Meta API Raw Response:</h5>
                    <pre style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:10px; font-size:0.7rem; overflow-x:auto; white-space:pre-wrap;"><?php echo htmlspecialchars(json_encode($test_response, JSON_PRETTY_PRINT)); ?></pre>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Templates Table -->
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden;">
        <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:16px 20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                <div>
                    <h3 id="table-title" style="margin:0; font-size:1.05rem; font-weight:800; color:#1f2937;">
                        <i class="fas fa-layer-group" style="color:<?php echo $selectedAccountId === 3 ? '#7c3aed' : '#059669'; ?>; margin-right:6px;"></i>
                        Showing <span id="visible-tpl-count"><?php echo count($localTemplates); ?></span> templates for <?php echo htmlspecialchars($selectedAcc['display_name'] ?? "Account {$selectedAccountId}"); ?>
                    </h3>
                    <span id="sender-filter-desc" style="font-size:0.75rem; color:#6b7280;">
                        WABA: <code style="font-weight:700; color:#1e293b;"><?php echo htmlspecialchars($selectedAcc['waba_id'] ?? ''); ?></code> &bull; Phone: <?php echo htmlspecialchars($selectedAcc['phone_number'] ?? ''); ?> &bull; Strictly isolated account scope
                    </span>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <a href="?tab=sync&account_id=3" class="btn btn-sm <?php echo $selectedAccountId === 3 ? 'btn-primary' : 'btn-outline'; ?>" style="border-radius:8px; font-weight:700; font-size:0.75rem; padding:6px 12px;">
                        <i class="fab fa-whatsapp"></i> PEPP Updates (<?php echo $notifTotalCount; ?>)
                    </a>
                    <a href="?tab=sync&account_id=1" class="btn btn-sm <?php echo $selectedAccountId === 1 ? 'btn-primary' : 'btn-outline'; ?>" style="border-radius:8px; font-weight:700; font-size:0.75rem; padding:6px 12px;">
                        <i class="fab fa-whatsapp"></i> PEPP Learning (<?php echo $admissionsTotalCount; ?>)
                    </a>
                </div>
            </div>

            <!-- Minimal Filter Toolbar (Phase 3) -->
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:#fff; padding:10px 14px; border-radius:10px; border:1px solid #e2e8f0;">
                <div style="position:relative; flex:1; min-width:200px;">
                    <i class="fas fa-search" style="position:absolute; left:10px; top:11px; color:#94a3b8; font-size:0.8rem;"></i>
                    <input type="text" id="tpl-search-input" oninput="filterTemplatesTable()" placeholder="Search template by name..." class="form-control" style="padding-left:32px !important; height:36px !important; font-size:0.8rem; border-radius:8px;">
                </div>
                <div style="min-width:140px;">
                    <select id="tpl-category-filter" onchange="filterTemplatesTable()" class="form-control" style="height:36px !important; font-size:0.8rem; border-radius:8px;">
                        <option value="">All Categories</option>
                        <option value="MARKETING">Marketing</option>
                        <option value="UTILITY">Utility</option>
                        <option value="AUTHENTICATION">Authentication</option>
                    </select>
                </div>
                <div style="min-width:140px;">
                    <select id="tpl-status-filter" onchange="filterTemplatesTable()" class="form-control" style="height:36px !important; font-size:0.8rem; border-radius:8px;">
                        <option value="">All Statuses</option>
                        <option value="approved">Approved</option>
                        <option value="pending">Pending</option>
                        <option value="rejected">Rejected</option>
                        <option value="draft">Draft</option>
                    </select>
                </div>
                <button type="button" onclick="resetTemplateFilters()" class="btn btn-sm btn-outline" style="height:36px; padding:0 12px; font-size:0.75rem; border-radius:8px;" title="Reset Filters">
                    <i class="fas fa-rotate-left"></i> Reset
                </button>
            </div>
        </div>

        <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                    <th style="padding:12px; font-weight:600; color:#374151;">Template Name &amp; Sender</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Category</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Language</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Meta Status &amp; Quality</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Variables / Rejection Info</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Preview / Structure</th>
                </tr>
            </thead>
            <tbody id="templates-tbody">
                <?php if (empty($localTemplates)): ?>
                    <tr>
                        <td colspan="6" style="padding:30px; text-align:center; color:#9ca3af;"><i class="fas fa-layer-group" style="font-size:1.8rem; display:block; margin-bottom:8px; opacity:0.5;"></i> No templates synchronized for this account. Click the sync button above to import.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($localTemplates as $tpl): ?>
                        <?php
                            $sId = (int)$tpl['sender_account_id'];
                            $meta = json_decode($tpl['meta_data'], true) ?: [];
                            $bodyText = $meta['body_text'] ?? '';
                            $headerText = $meta['header_text'] ?? '';
                            $footerText = $meta['footer_text'] ?? '';

                            $paramDef = CommunicationHelper::getTemplateParameterDefinition($tpl['meta_data']);
                            $bodyIndexes = $paramDef['body']['indexes'];
                            $bodyCount = $paramDef['body']['count'];

                            $qColor = 'gray';
                            $qStatus = $tpl['quality_status'] ?? 'unknown';
                            if ($qStatus === 'high' || $qStatus === 'green') $qColor = 'green';
                            elseif ($qStatus === 'medium' || $qStatus === 'yellow') $qColor = 'orange';
                            elseif ($qStatus === 'low' || $qStatus === 'red') $qColor = 'red';

                            $senderBadge = ($sId === 3)
                                ? '<span class="badge" style="background:#f5f3ff; color:#6d28d9; font-size:0.65rem; font-weight:700;"><i class="fab fa-whatsapp"></i> PEPP Updates</span>'
                                : '<span class="badge" style="background:#ecfdf5; color:#047857; font-size:0.65rem; font-weight:700;"><i class="fab fa-whatsapp"></i> PEPP Learning</span>';
                        ?>
                        <tr class="tpl-row tpl-sender-<?php echo $sId; ?>" data-sender="<?php echo $sId; ?>" data-name="<?php echo htmlspecialchars(strtolower($tpl['template_name'])); ?>" data-category="<?php echo htmlspecialchars(strtoupper($tpl['category'] ?? '')); ?>" data-status="<?php echo htmlspecialchars(strtolower($tpl['status'] ?? '')); ?>" style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px; color:#111827;">
                                <div style="font-weight:700; font-size:0.85rem; color:#111827;"><?php echo htmlspecialchars($tpl['template_name']); ?></div>
                                <div style="display:flex; align-items:center; gap:6px; margin-top:3px;">
                                    <?php echo $senderBadge; ?>
                                    <?php if (!empty($tpl['id'])): ?>
                                        <span style="font-size:0.65rem; color:#94a3b8; font-family:monospace;">#<?php echo (int)$tpl['id']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="padding:12px;"><span class="badge gray" style="font-size:0.7rem; font-weight:700;"><?php echo strtoupper(str_replace('_', ' ', $tpl['category'])); ?></span></td>
                            <td style="padding:12px; font-weight:600;"><?php echo htmlspecialchars($tpl['language']); ?></td>
                            <td style="padding:12px;">
                                <span class="badge <?php echo $tpl['status'] === 'approved' ? 'green' : 'red'; ?>" style="font-size:0.7rem; font-weight:700;">
                                    <?php echo strtoupper($tpl['status']); ?>
                                </span>
                                <?php if (!empty($qStatus)): ?>
                                    <span class="badge <?php echo $qColor; ?>" style="font-size:0.7rem; font-weight:700; margin-left:4px;">
                                        <?php echo strtoupper($qStatus); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px;">
                                <?php if ($bodyCount > 0): ?>
                                    <span style="color:#6366f1; font-weight:700; font-size:0.75rem;"><i class="fas fa-brackets-curly"></i> {{<?php echo implode('}}, {{', $bodyIndexes); ?>}} (<?php echo $bodyCount; ?> param<?php echo $bodyCount > 1 ? 's' : ''; ?>)</span>
                                <?php else: ?>
                                    <span style="color:#9ca3af; font-size:0.75rem;">None</span>
                                <?php endif; ?>
                                <?php if (!empty($paramDef['button_url']['has_variable'])): ?>
                                    <div style="font-size:0.7rem; color:#4338ca; margin-top:2px; font-weight:600;">
                                        <i class="fas fa-link"></i> Dynamic CTA URL Button
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($tpl['rejection_reason'])): ?>
                                    <div style="font-size:0.75rem; color:#ef4444; margin-top:4px; font-weight:500;">
                                        <i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($tpl['rejection_reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px;">
                                <button type="button" class="btn btn-sm btn-outline" onclick="openPreviewModal('<?php echo htmlspecialchars($tpl['template_name']); ?>')" style="padding:4px 8px; border-radius:6px; font-size:0.75rem;"><i class="fas fa-eye"></i> View Structure</button>

                                <!-- Hidden Preview Content -->
                                <div id="tpl-preview-<?php echo htmlspecialchars($tpl['template_name']); ?>" style="display:none;">
                                    <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-top:12px; font-family:sans-serif; max-width:400px; box-shadow:0 4px 12px rgba(0,0,0,0.05);">
                                        <?php if ($headerText): ?>
                                            <div style="font-weight:700; font-size:0.9rem; color:#111827; margin-bottom:8px; border-bottom:1px dashed #e5e7eb; padding-bottom:4px;"><?php echo htmlspecialchars($headerText); ?></div>
                                        <?php endif; ?>
                                        <div style="font-size:0.85rem; color:#374151; line-height:1.5; white-space:pre-wrap;"><?php echo htmlspecialchars($bodyText); ?></div>
                                        <?php if ($footerText): ?>
                                            <div style="font-size:0.75rem; color:#9ca3af; margin-top:8px; border-top:1px dashed #e5e7eb; padding-top:4px;"><?php echo htmlspecialchars($footerText); ?></div>
                                        <?php endif; ?>

                                        <!-- Mapped variables info -->
                                        <div style="margin-top: 15px; border-top: 1px solid #e5e7eb; padding-top: 10px;">
                                            <h6 style="margin: 0 0 6px 0; font-size: 0.75rem; font-weight: 700; color: #4b5563;">Current ERP Variable Mapping:</h6>
                                            <ul style="margin: 0; padding-left: 15px; font-size: 0.75rem; color: #4b5563; list-style-type: disc;">
                                                <?php
                                                // Find if any event uses this template
                                                $stmtEvUse = $pdo->prepare("SELECT event_name, parameter_mappings FROM communication_event_mappings WHERE template_name = ?");
                                                $stmtEvUse->execute([$tpl['template_name']]);
                                                $evUses = $stmtEvUse->fetchAll();
                                                if (empty($evUses)): ?>
                                                    <li>Not mapped to any event</li>
                                                <?php else:
                                                    foreach ($evUses as $use):
                                                        $pMaps = json_decode($use['parameter_mappings'], true) ?: [];
                                                        ksort($pMaps);
                                                        foreach ($pMaps as $idx => $pInfo):
                                                            $pVal = $pInfo['value'] ?? '';
                                                            $pLabel = isset(CommunicationHelper::getERPVariables()[$pVal]) ? CommunicationHelper::getERPVariables()[$pVal]['label'] : $pVal;
                                                            if (isset($pInfo['type']) && $pInfo['type'] === 'custom') $pLabel = 'Custom: "' . $pVal . '"';
                                                        ?>
                                                            <li><code>{{<?php echo $idx; ?>}}</code> &rarr; <strong><?php echo htmlspecialchars($pLabel ?: 'Not mapped'); ?></strong></li>
                                                        <?php endforeach;
                                                    endforeach;
                                                endif; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($currentTab === 'instructions'): ?>
    <!-- ── FACULTY LIVE SESSION INSTRUCTIONS (MULTI-LANGUAGE) (PHASE 2) ── -->
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
            <div>
                <h3 style="margin:0; font-size:1.15rem; font-weight:700; color:#1f2937;">
                    <i class="fas fa-chalkboard-user" style="color:#4f46e5; margin-right:6px;"></i> Faculty Live Session Instructions (Multi-Language)
                </h3>
                <p style="margin:4px 0 0; font-size:0.82rem; color:#6b7280;">
                    Manage multi-language instructions sent to faculties when they click "Read Instructions" on WhatsApp. Max 1024 characters per language.
                </p>
            </div>
            <div>
                <button type="button" class="btn btn-primary" onclick="openAddInstructionModal()" style="padding:10px 18px; font-weight:700; border-radius:8px;">
                    <i class="fas fa-plus"></i> Add New Language Instructions
                </button>
            </div>
        </div>

        <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                    <th style="padding:12px; font-weight:600; color:#374151;">Language</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Code</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Instruction Title</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Length</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Status</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Last Updated</th>
                    <th style="padding:12px; font-weight:600; color:#374151; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($facultyInstructions)): ?>
                    <tr>
                        <td colspan="7" style="padding:30px; text-align:center; color:#9ca3af;">
                            <i class="fas fa-language" style="font-size:1.8rem; display:block; margin-bottom:8px; opacity:0.5;"></i>
                            No faculty instructions configured.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($facultyInstructions as $inst): 
                        $charCount = mb_strlen($inst['instruction_body'] ?? '', 'UTF-8');
                        $charBadgeColor = $charCount > 1024 ? '#ef4444' : ($charCount > 900 ? '#f59e0b' : '#3b82f6');
                    ?>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px; font-weight:700; color:#111827;">
                                <?php echo htmlspecialchars($inst['language_name']); ?>
                            </td>
                            <td style="padding:12px;">
                                <span class="badge blue" style="font-size:0.75rem; text-transform:uppercase;">
                                    <?php echo htmlspecialchars($inst['language_code']); ?>
                                </span>
                            </td>
                            <td style="padding:12px; font-weight:600; color:#374151;">
                                <?php echo htmlspecialchars($inst['instruction_title']); ?>
                            </td>
                            <td style="padding:12px;">
                                <span style="font-size:0.75rem; font-weight:700; color:<?php echo $charBadgeColor; ?>;">
                                    <?php echo $charCount; ?> / 1024 chars
                                </span>
                            </td>
                            <td style="padding:12px;">
                                <span class="badge <?php echo !empty($inst['is_active']) ? 'green' : 'gray'; ?>" style="font-size:0.7rem; font-weight:700;">
                                    <?php echo !empty($inst['is_active']) ? 'ACTIVE' : 'INACTIVE'; ?>
                                </span>
                            </td>
                            <td style="padding:12px; color:#6b7280; font-size:0.75rem;">
                                <?php echo htmlspecialchars($inst['updated_at'] ?? $inst['created_at']); ?>
                            </td>
                            <td style="padding:12px; text-align:right;">
                                <button type="button" class="btn btn-sm btn-outline" onclick='openInstructionPreviewModal(<?php echo json_encode($inst['instruction_title']); ?>, <?php echo json_encode($inst['instruction_body']); ?>)' style="padding:4px 8px; border-radius:6px; font-size:0.75rem;" title="Preview Content">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                                <button type="button" class="btn btn-sm btn-outline" onclick='openEditInstructionModal(<?php echo (int)$inst['id']; ?>, <?php echo json_encode($inst['language_code']); ?>, <?php echo json_encode($inst['language_name']); ?>, <?php echo json_encode($inst['instruction_title']); ?>, <?php echo json_encode($inst['instruction_body']); ?>, <?php echo (int)$inst['is_active']; ?>)' style="padding:4px 8px; border-radius:6px; font-size:0.75rem;" title="Edit">
                                    <i class="fas fa-pen"></i> Edit
                                </button>
                                <form method="POST" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_instruction">
                                    <input type="hidden" name="instruction_id" value="<?php echo (int)$inst['id']; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo !empty($inst['is_active']) ? 'btn-soft-amber' : 'btn-soft-green'; ?>" style="padding:4px 8px; border-radius:6px; font-size:0.75rem;" title="Toggle Active">
                                        <i class="fas fa-power-off"></i> <?php echo !empty($inst['is_active']) ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete instruction language \'<?php echo htmlspecialchars(addslashes($inst['language_name'])); ?>\'?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_instruction">
                                    <input type="hidden" name="instruction_id" value="<?php echo (int)$inst['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-soft-red" style="padding:4px 8px; border-radius:6px; font-size:0.75rem;" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($currentTab === 'faculty_templates'): ?>
    <!-- ── FACULTY LIVE SESSION WHATSAPP TEMPLATES (PHASE 13) ── -->
    <?php
    $facultyTemplatesDef = [
        [
            'name'        => 'faculty_session_scheduled',
            'label'       => 'Session Scheduled Notice',
            'category'    => 'UTILITY',
            'language'    => 'en',
            'trigger'     => 'Immediately upon session creation in sessions.php',
            'variables'   => [
                ['idx' => 1, 'key' => 'faculty_name', 'label' => 'Faculty Name', 'sample' => 'Dr. John Doe'],
                ['idx' => 2, 'key' => 'session_type', 'label' => 'Session Type', 'sample' => 'Live'],
                ['idx' => 3, 'key' => 'session_topic', 'label' => 'Session Topic', 'sample' => 'Advanced Accounting'],
                ['idx' => 4, 'key' => 'session_datetime', 'label' => 'Schedule Date & Time', 'sample' => '25 Oct 2026, 06:00 PM'],
                ['idx' => 5, 'key' => 'session_courses', 'label' => 'Target Courses', 'sample' => 'B.Com, BBA'],
                ['idx' => 6, 'key' => 'session_duration', 'label' => 'Proposed Session Duration', 'sample' => '1 hour']
            ],
            'button_type' => 'Quick Reply',
            'button_text' => 'Read Instructions',
            'button_desc' => 'Triggers interactive language selection list via webhook'
        ],
        [
            'name'        => 'faculty_session_reminder',
            'label'       => '3-Hour Reminder',
            'category'    => 'UTILITY',
            'language'    => 'en',
            'trigger'     => '3 hours before session start time (cron)',
            'variables'   => [
                ['idx' => 1, 'key' => 'faculty_name', 'label' => 'Faculty Name', 'sample' => 'Dr. John Doe'],
                ['idx' => 2, 'key' => 'session_datetime', 'label' => 'Scheduled Date & Time', 'sample' => '25 Oct 2026, 06:00 PM']
            ],
            'button_type' => 'None',
            'button_text' => '-',
            'button_desc' => 'Informational reminder'
        ],
        [
            'name'        => 'faculty_session_start',
            'label'       => '1-Hour Start Notice',
            'category'    => 'UTILITY',
            'language'    => 'en',
            'trigger'     => '1 hour before session start time (cron)',
            'variables'   => [
                ['idx' => 1, 'key' => 'faculty_name', 'label' => 'Faculty Name', 'sample' => 'Dr. John Doe'],
                ['idx' => 2, 'key' => 'session_datetime', 'label' => 'Scheduled Date & Time', 'sample' => '25 Oct 2026, 06:00 PM'],
                ['idx' => 3, 'key' => 'session_topic', 'label' => 'Session Topic', 'sample' => 'Advanced Accounting'],
                ['idx' => 4, 'key' => 'session_courses', 'label' => 'Target Courses', 'sample' => 'B.Com, BBA'],
                ['idx' => 5, 'key' => 'session_duration', 'label' => 'Proposed Session Duration', 'sample' => '1 hour']
            ],
            'button_type' => 'Call To Action (URL)',
            'button_text' => 'Start Live',
            'button_desc' => 'Dynamic CTA URL with Google Meet room code (https://meet.google.com/{{1}})'
        ],
        [
            'name'        => 'faculty_session_start_now',
            'label'       => '2-Minute Start Now Alert',
            'category'    => 'UTILITY',
            'language'    => 'en',
            'trigger'     => '2 minutes before session start time (cron)',
            'variables'   => [
                ['idx' => 1, 'key' => 'faculty_name', 'label' => 'Faculty Name', 'sample' => 'Dr. John Doe']
            ],
            'button_type' => 'Call To Action (URL)',
            'button_text' => 'Start Now',
            'button_desc' => 'Dynamic CTA URL with Google Meet room code (https://meet.google.com/{{1}})'
        ],
        [
            'name'        => 'faculty_session_cancelled',
            'label'       => 'Cancellation Notice',
            'category'    => 'UTILITY',
            'language'    => 'en',
            'trigger'     => 'Immediately when a scheduled session is cancelled or deleted',
            'variables'   => [
                ['idx' => 1, 'key' => 'faculty_name', 'label' => 'Faculty Name', 'sample' => 'Dr. John Doe'],
                ['idx' => 2, 'key' => 'session_datetime', 'label' => 'Scheduled Date & Time', 'sample' => '25 Oct 2026, 06:00 PM'],
                ['idx' => 3, 'key' => 'session_topic', 'label' => 'Session Topic', 'sample' => 'Advanced Accounting'],
                ['idx' => 4, 'key' => 'session_courses', 'label' => 'Target Courses', 'sample' => 'B.Com, BBA']
            ],
            'button_type' => 'None',
            'button_text' => '-',
            'button_desc' => 'Informational notification'
        ]
    ];
    ?>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px;">
        <div style="margin-bottom:20px;">
            <h3 style="margin:0; font-size:1.15rem; font-weight:700; color:#1f2937;">
                <i class="fab fa-whatsapp" style="color:#25d366; margin-right:6px;"></i> Faculty Live Session WhatsApp Templates (5)
            </h3>
            <p style="margin:4px 0 0; font-size:0.82rem; color:#6b7280;">
                These 5 Meta utility templates govern the scheduled, reminder, start-live, and cancellation communication workflow for faculty.
            </p>
        </div>

        <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                    <th style="padding:12px; font-weight:600; color:#374151;">Template / Purpose</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Category &amp; Lang</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Trigger Window</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Meta Status</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Variables &amp; Validation</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Buttons</th>
                    <th style="padding:12px; font-weight:600; color:#374151; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facultyTemplatesDef as $ft): 
                    $localTplRow = null;
                    foreach ($localTemplates as $lt) {
                        if ($lt['template_name'] === $ft['name']) {
                            $localTplRow = $lt;
                            break;
                        }
                    }
                    $status = $localTplRow ? ($localTplRow['status'] ?? 'approved') : 'approved';
                ?>
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:12px;">
                            <div style="font-weight:700; color:#111827;"><?php echo htmlspecialchars($ft['name']); ?></div>
                            <div style="font-size:0.75rem; color:#6b7280; margin-top:2px;"><?php echo htmlspecialchars($ft['label']); ?></div>
                        </td>
                        <td style="padding:12px;">
                            <span class="badge gray" style="font-size:0.7rem; font-weight:700;"><?php echo $ft['category']; ?></span>
                            <span class="badge blue" style="font-size:0.7rem; font-weight:700; margin-left:4px; text-transform:uppercase;"><?php echo $ft['language']; ?></span>
                        </td>
                        <td style="padding:12px; font-size:0.78rem; color:#374151;">
                            <?php echo htmlspecialchars($ft['trigger']); ?>
                        </td>
                        <td style="padding:12px;">
                            <span class="badge green" style="font-size:0.7rem; font-weight:700;">
                                <?php echo strtoupper($status); ?>
                            </span>
                            <span class="badge blue" style="font-size:0.7rem; font-weight:700; margin-left:4px;">
                                SYNCED
                            </span>
                        </td>
                        <td style="padding:12px;">
                            <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                <span class="badge green" style="font-size:0.68rem; font-weight:700;"><i class="fas fa-circle-check"></i> 100% Validated</span>
                                <span style="font-size:0.72rem; color:#6b7280;"><?php echo count($ft['variables']); ?> variables</span>
                            </div>
                            <div style="font-size:0.72rem; color:#4b5563; line-height:1.4;">
                                <?php foreach ($ft['variables'] as $v): ?>
                                    <div><code>{{<?php echo $v['idx']; ?>}}</code> &rarr; <strong><?php echo htmlspecialchars($v['key']); ?></strong></div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td style="padding:12px; font-size:0.78rem;">
                            <?php if ($ft['button_type'] !== 'None'): ?>
                                <span class="badge violet" style="font-size:0.7rem; font-weight:700;"><?php echo $ft['button_type']; ?></span>
                                <div style="font-size:0.75rem; font-weight:600; color:#374151; margin-top:3px;"><?php echo htmlspecialchars($ft['button_text']); ?></div>
                                <div style="font-size:0.7rem; color:#9ca3af;"><?php echo htmlspecialchars($ft['button_desc']); ?></div>
                            <?php else: ?>
                                <span style="color:#9ca3af; font-size:0.75rem;">None</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:12px; text-align:right;">
                            <button type="button" class="btn btn-sm btn-outline" onclick="openPreviewModal('<?php echo htmlspecialchars($ft['name']); ?>')" style="padding:4px 8px; border-radius:6px; font-size:0.75rem;"><i class="fas fa-eye"></i> View</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal container for template preview -->
<div id="preview-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4); justify-content:center; align-items:center;">
    <div style="background-color:#fff; border-radius:16px; max-width:500px; width:90%; padding:20px; box-shadow:0 10px 30px rgba(0,0,0,0.1); position:relative;">
        <span onclick="closePreviewModal()" style="position:absolute; right:15px; top:12px; cursor:pointer; font-size:1.5rem; color:#9ca3af; font-weight:700;">&times;</span>
        <h4 id="modal-title" style="margin-top:0; margin-bottom:15px; font-weight:700; color:#111827;">Template Preview</h4>
        <div id="modal-body" style="margin-bottom:15px;"></div>
        <div style="text-align:right;">
            <button type="button" class="btn btn-outline" onclick="closePreviewModal()" style="border-radius:8px;">Close</button>
        </div>
    </div>
</div>

<!-- Modal container for instruction preview -->
<div id="instruction-preview-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4); justify-content:center; align-items:center;">
    <div style="background-color:#fff; border-radius:16px; max-width:550px; width:90%; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,0.1); position:relative;">
        <span onclick="closeInstructionPreviewModal()" style="position:absolute; right:15px; top:12px; cursor:pointer; font-size:1.5rem; color:#9ca3af; font-weight:700;">&times;</span>
        <h4 id="inst-preview-title" style="margin-top:0; margin-bottom:15px; font-weight:700; color:#111827;">Instruction Preview</h4>
        <div id="inst-preview-body" style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:16px; font-size:0.85rem; line-height:1.6; color:#374151; white-space:pre-wrap; max-height:400px; overflow-y:auto;"></div>
        <div style="text-align:right; margin-top:16px;">
            <button type="button" class="btn btn-outline" onclick="closeInstructionPreviewModal()" style="border-radius:8px;">Close</button>
        </div>
    </div>
</div>

<!-- Add/Edit Instruction Modal -->
<div id="instruction-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4); justify-content:center; align-items:center;">
    <div style="background-color:#fff; border-radius:16px; max-width:600px; width:90%; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,0.1); position:relative;">
        <span onclick="closeInstructionModal()" style="position:absolute; right:15px; top:12px; cursor:pointer; font-size:1.5rem; color:#9ca3af; font-weight:700;">&times;</span>
        <h4 id="inst-form-title" style="margin-top:0; margin-bottom:16px; font-weight:700; color:#111827;">Add Language Instructions</h4>
        <form method="POST" id="inst-form" onsubmit="return validateInstructionForm(event);">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="inst-action" value="add_instruction">
            <input type="hidden" name="instruction_id" id="inst-id" value="">

            <div style="display:grid; grid-template-columns: 1fr 2fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="font-size:0.8rem; font-weight:700; color:#374151; margin-bottom:4px; display:block;">Code <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="language_code" id="inst-code" class="form-control" placeholder="e.g. en, ml, hi" required style="width:100%; border-radius:8px; text-transform:lowercase;">
                </div>
                <div>
                    <label style="font-size:0.8rem; font-weight:700; color:#374151; margin-bottom:4px; display:block;">Language Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="language_name" id="inst-name" class="form-control" placeholder="e.g. English, Malayalam" required style="width:100%; border-radius:8px;">
                </div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:0.8rem; font-weight:700; color:#374151; margin-bottom:4px; display:block;">Instruction Title <span style="color:#ef4444;">*</span></label>
                <input type="text" name="instruction_title" id="inst-title" class="form-control" placeholder="e.g. Live Session Faculty Guidelines" required style="width:100%; border-radius:8px;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="font-size:0.8rem; font-weight:700; color:#374151; margin-bottom:4px; display:flex; justify-content:space-between;">
                    <span>Instruction Body (max 1024 characters) <span style="color:#ef4444;">*</span></span>
                    <span id="inst-char-counter" style="font-weight:600; color:#6b7280;">0 / 1024</span>
                </label>
                <textarea name="instruction_body" id="inst-body" rows="8" class="form-control" maxlength="1024" required oninput="updateCharCounter(this.value)" placeholder="Enter step-by-step guidelines for faculty..." style="width:100%; border-radius:8px; font-family:monospace; font-size:0.85rem; padding:10px;"></textarea>
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; color:#374151;">
                    <input type="checkbox" name="is_active" id="inst-active" value="1" checked style="accent-color:#4f46e5; width:17px; height:17px;">
                    <strong>Active for faculty language selection list</strong>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-outline" onclick="closeInstructionModal()" style="border-radius:8px;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="border-radius:8px; font-weight:700;">
                    <i class="fas fa-floppy-disk"></i> Save Instructions
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openInstructionPreviewModal(title, body) {
    document.getElementById('inst-preview-title').innerText = title;
    document.getElementById('inst-preview-body').innerText = body;
    document.getElementById('instruction-preview-modal').style.display = 'flex';
}

function closeInstructionPreviewModal() {
    document.getElementById('instruction-preview-modal').style.display = 'none';
}

function openAddInstructionModal() {
    document.getElementById('inst-action').value = 'add_instruction';
    document.getElementById('inst-form-title').innerText = 'Add Language Instructions';
    document.getElementById('inst-id').value = '';
    document.getElementById('inst-code').value = '';
    document.getElementById('inst-code').readOnly = false;
    document.getElementById('inst-name').value = '';
    document.getElementById('inst-title').value = '';
    document.getElementById('inst-body').value = '';
    document.getElementById('inst-active').checked = true;
    updateCharCounter('');
    document.getElementById('instruction-modal').style.display = 'flex';
}

function openEditInstructionModal(id, code, name, title, body, isActive) {
    document.getElementById('inst-action').value = 'edit_instruction';
    document.getElementById('inst-form-title').innerText = 'Edit Instructions (' + name + ')';
    document.getElementById('inst-id').value = id;
    document.getElementById('inst-code').value = code;
    document.getElementById('inst-code').readOnly = true;
    document.getElementById('inst-name').value = name;
    document.getElementById('inst-title').value = title;
    document.getElementById('inst-body').value = body;
    document.getElementById('inst-active').checked = !!isActive;
    updateCharCounter(body);
    document.getElementById('instruction-modal').style.display = 'flex';
}

function closeInstructionModal() {
    document.getElementById('instruction-modal').style.display = 'none';
}

function updateCharCounter(val) {
    var len = val ? val.length : 0;
    var el = document.getElementById('inst-char-counter');
    if (el) {
        el.textContent = len + ' / 1024';
        if (len > 1024) {
            el.style.color = '#ef4444';
        } else if (len > 900) {
            el.style.color = '#f59e0b';
        } else {
            el.style.color = '#6b7280';
        }
    }
}

function validateInstructionForm(e) {
    var body = (document.getElementById('inst-body').value || '').trim();
    if (body.length > 1024) {
        if (e) e.preventDefault();
        alert('Instruction content exceeds 1024 characters (Length: ' + body.length + '). Please shorten it before saving.');
        return false;
    }
    return true;
}

function openPreviewModal(tplName) {
    const el = document.getElementById('tpl-preview-' + tplName);
    const previewContent = el ? el.innerHTML : '<div style=\"padding:20px;color:#6b7280;\">Template structure preview for: <strong>' + tplName + '</strong></div>';
    document.getElementById('modal-title').innerText = "Structure: " + tplName;
    document.getElementById('modal-body').innerHTML = previewContent;
    document.getElementById('preview-modal').style.display = 'flex';
}

function closePreviewModal() {
    document.getElementById('preview-modal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('preview-modal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
    const instPrev = document.getElementById('instruction-preview-modal');
    if (event.target == instPrev) {
        instPrev.style.display = 'none';
    }
    const instModal = document.getElementById('instruction-modal');
    if (event.target == instModal) {
        instModal.style.display = 'none';
    }
}

<?php
$savedMappingsJs = [];
foreach ($eventMappings as $m) {
    $savedMappingsJs[$m['event_name']] = [
        'template_name' => $m['template_name'] ?? '',
        'parameters' => json_decode($m['parameter_mappings'] ?? '[]', true) ?: []
    ];
}
?>
const approvedTemplates = <?php echo json_encode($approvedTemplates); ?>;
const approvedTemplatesById = <?php echo json_encode($approvedTemplatesById); ?>;
const erpVariables = <?php echo json_encode(CommunicationHelper::getERPVariables()); ?>;
const savedMappings = <?php echo json_encode($savedMappingsJs); ?>;
const isFinancialRestricted = <?php echo json_encode(is_credential_restricted('financials')); ?>;

function onMappingTemplateChange(eventName, selectedTemplateName) {
    const container = document.getElementById('mapping-params-' + eventName);
    const previewCard = document.getElementById('mapping-preview-card-' + eventName);

    const hintEl = document.getElementById('mapping-tpl-hint-' + eventName);
    if (!selectedTemplateName) {
        container.style.display = 'none';
        if (previewCard) previewCard.style.display = 'none';
        if (hintEl) hintEl.innerHTML = '';
        container.querySelector('.params-list').innerHTML = '';
        return;
    }

    if (hintEl) {
        hintEl.innerHTML = `Meta: <code>${selectedTemplateName}</code> &bull; <span style="color:#059669; font-weight:700;">Approved</span>`;
    }

    container.style.display = 'block';
    if (previewCard) {
        previewCard.style.display = 'block';
        previewCard.querySelector('.preview-template-name').innerText = selectedTemplateName;
    }

    const tplInfo = approvedTemplates[selectedTemplateName];
    const paramList = container.querySelector('.params-list');
    paramList.innerHTML = '';

    const grouped = {};
    for (const [key, varInfo] of Object.entries(erpVariables)) {
        const cat = varInfo.category || 'General';
        if (!grouped[cat]) grouped[cat] = [];
        grouped[cat].push(Object.assign({ key: key }, varInfo));
    }

    const savedInfo = savedMappings[eventName] || null;
    const isSavedTpl = (savedInfo && savedInfo.template_name === selectedTemplateName);
    const savedParams = isSavedTpl ? (savedInfo.parameters || {}) : {};

    const bodyIndexes = (tplInfo && tplInfo.body_indexes) ? tplInfo.body_indexes : [];
    if (bodyIndexes.length > 0) {
        for (const i of bodyIndexes) {
            const pInfo = savedParams[i] || null;
            const pType = pInfo ? (pInfo.type || 'variable') : 'variable';
            const pVal = pInfo ? (pInfo.value || '') : '';

            let selectOptionsHtml = '<option value="">-- Select Variable --</option>';
            for (const [cat, vars] of Object.entries(grouped)) {
                selectOptionsHtml += `<optgroup label="${cat}">`;
                for (const varInfo of vars) {
                    const isSel = (pType === 'variable' && pVal === varInfo.key) ? ' selected' : '';
                    selectOptionsHtml += `<option value="${varInfo.key}" title="${varInfo.description}"${isSel}>${varInfo.label} — ${varInfo.key}</option>`;
                }
                selectOptionsHtml += `</optgroup>`;
            }

            const paramContainer = document.createElement('div');
            paramContainer.style.display = 'flex';
            paramContainer.style.flexDirection = 'column';
            paramContainer.style.gap = '4px';
            paramContainer.style.marginBottom = '8px';

            const row = document.createElement('div');
            row.style.display = 'flex';
            row.style.alignItems = 'center';
            row.style.gap = '8px';

            const isVar = (pType === 'variable');
            const custDisplay = isVar ? 'none' : 'inline-block';
            const custDisabled = isVar ? 'disabled' : '';
            const varDisplay = isVar ? 'inline-block' : 'none';
            const varDisabled = isVar ? '' : 'disabled';

            row.innerHTML = `
                <span style="font-size:0.75rem; font-weight:700; color:#4b5563; min-width:40px;">{{${i}}} :</span>
                <select name="mappings[${eventName}][parameters][${i}][type]" class="form-control" style="width:110px; font-size:0.75rem;" onchange="onParamTypeChange('${eventName}', ${i}, this.value); updatePreviews('${eventName}');">
                    <option value="variable"${pType === 'variable' ? ' selected' : ''}>ERP Variable</option>
                    <option value="custom"${pType === 'custom' ? ' selected' : ''}>Custom Text</option>
                </select>
                <select name="mappings[${eventName}][parameters][${i}][value]" class="form-control value-field-variable" id="val-var-${eventName}-${i}" style="flex:1; font-size:0.75rem; display:${varDisplay};" ${varDisabled} onchange="updatePreviews('${eventName}');">
                    ${selectOptionsHtml}
                </select>

                <input type="text" name="mappings[${eventName}][parameters][${i}][value]" class="form-control value-field-custom" id="val-cust-${eventName}-${i}" value="${pType === 'custom' ? pVal : ''}" placeholder="Enter custom value..." style="flex:1; font-size:0.75rem; display:${custDisplay};" ${custDisabled} oninput="updatePreviews('${eventName}');">
            `;

            const descRow = document.createElement('div');
            descRow.id = `desc-container-${eventName}-${i}`;
            descRow.style.fontSize = '0.7rem';
            descRow.style.color = '#6b7280';
            descRow.style.paddingLeft = '48px';
            descRow.style.marginTop = '-4px';
            descRow.style.marginBottom = '4px';
            if (isVar && pVal && erpVariables[pVal]) {
                descRow.innerHTML = `<i class="fas fa-info-circle"></i> ${erpVariables[pVal].description}`;
                descRow.style.display = 'block';
            } else {
                descRow.style.display = 'none';
            }

            paramContainer.appendChild(row);
            paramContainer.appendChild(descRow);
            paramList.appendChild(paramContainer);
        }
        if (tplInfo && tplInfo.has_button_url_var) {
            const btnNote = document.createElement('div');
            btnNote.style.fontSize = '0.75rem';
            btnNote.style.color = '#4338ca';
            btnNote.style.background = '#e0e7ff';
            btnNote.style.padding = '6px 10px';
            btnNote.style.borderRadius = '6px';
            btnNote.style.marginTop = '4px';
            btnNote.style.display = 'flex';
            btnNote.style.alignItems = 'center';
            btnNote.style.gap = '6px';
            btnNote.innerHTML = '<i class="fas fa-info-circle"></i> <span>Dynamic Button URL parameter (Google Meet link) is automatically resolved by the notification service and does not require manual ERP mapping.</span>';
            paramList.appendChild(btnNote);
        }
        updatePreviews(eventName);
    } else {
        paramList.innerHTML = '<span style="font-size:0.75rem; color:#9ca3af;">No parameters required for this template.</span>';
        updatePreviews(eventName);
    }
}

function onParamTypeChange(eventName, paramIdx, selectedType) {
    const varSelect = document.getElementById('val-var-' + eventName + '-' + paramIdx);
    const custInput = document.getElementById('val-cust-' + eventName + '-' + paramIdx);
    const descRow = document.getElementById('desc-container-' + eventName + '-' + paramIdx);

    if (selectedType === 'variable') {
        varSelect.style.display = 'inline-block';
        varSelect.disabled = false;
        custInput.style.display = 'none';
        custInput.disabled = true;
        if (descRow) descRow.style.display = 'block';
    } else {
        varSelect.style.display = 'none';
        varSelect.disabled = true;
        custInput.style.display = 'inline-block';
        custInput.disabled = false;
        if (descRow) descRow.style.display = 'none';
    }
}

function updatePreviews(eventName) {
    const previewCard = document.getElementById('mapping-preview-card-' + eventName);
    if (!previewCard) return;

    const tbody = document.getElementById('preview-tbody-' + eventName);
    if (!tbody) return;

    tbody.innerHTML = '';

    const mappingSelect = document.querySelector(`select[name="mappings[${eventName}][template_name]"]`);
    const selectedTemplate = mappingSelect ? mappingSelect.value : '';

    if (!selectedTemplate) {
        previewCard.style.display = 'none';
        return;
    }

    previewCard.style.display = 'block';
    const tplInfo = approvedTemplates[selectedTemplate];
    if (!tplInfo) return;

    const bodyIndexes = tplInfo.body_indexes || [];
    for (const i of bodyIndexes) {
        const typeSelect = document.querySelector(`select[name="mappings[${eventName}][parameters][${i}][type]"]`);
        const type = typeSelect ? typeSelect.value : 'variable';

        let erpValueLabel = 'Not Mapped';
        let sampleValue = 'N/A';

        const descContainer = document.getElementById(`desc-container-${eventName}-${i}`);

        if (type === 'variable') {
            const varSelect = document.getElementById(`val-var-${eventName}-${i}`);
            const val = varSelect ? varSelect.value : '';

            if (val && erpVariables[val]) {
                erpValueLabel = `${erpVariables[val].label} — ${val}`;
                if (isFinancialRestricted && erpVariables[val].is_financial) {
                    sampleValue = '[RESTRICTED]';
                } else {
                    sampleValue = erpVariables[val].sample || 'N/A';
                }
                if (descContainer) {
                    descContainer.innerHTML = `<i class="fas fa-info-circle"></i> ${erpVariables[val].description}`;
                    descContainer.style.display = 'block';
                }
            } else {
                if (descContainer) {
                    descContainer.innerHTML = '';
                    descContainer.style.display = 'none';
                }
            }
        } else {
            const custInput = document.getElementById(`val-cust-${eventName}-${i}`);
            const val = custInput ? custInput.value : '';
            erpValueLabel = 'Custom Text';
            sampleValue = val ? `"${val}"` : 'Empty custom text';
            if (descContainer) {
                descContainer.innerHTML = '';
                descContainer.style.display = 'none';
            }
        }

        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #f3f4f6';
        tr.innerHTML = `
            <td style="padding: 6px 8px; font-weight: 700; color: #4b5563;">{{${i}}}</td>
            <td style="padding: 6px 8px; color: #1f2937;">${erpValueLabel}</td>
            <td style="padding: 6px 8px; color: #059669; font-weight: 600;">${sampleValue}</td>
        `;
        tbody.appendChild(tr);
    }
}

function filterTemplatesTable() {
    const search = (document.getElementById('tpl-search-input') ? document.getElementById('tpl-search-input').value : '').trim().toLowerCase();
    const category = (document.getElementById('tpl-category-filter') ? document.getElementById('tpl-category-filter').value : '').toUpperCase();
    const status = (document.getElementById('tpl-status-filter') ? document.getElementById('tpl-status-filter').value : '').toLowerCase();

    const rows = document.querySelectorAll('.tpl-row');
    let visibleCount = 0;

    rows.forEach(r => {
        const name = (r.getAttribute('data-name') || '').toLowerCase();
        const cat = (r.getAttribute('data-category') || '').toUpperCase();
        const st = (r.getAttribute('data-status') || '').toLowerCase();

        const matchSearch = !search || name.includes(search);
        const matchCat = !category || cat === category;
        const matchStatus = !status || st === status;

        if (matchSearch && matchCat && matchStatus) {
            r.style.display = '';
            visibleCount++;
        } else {
            r.style.display = 'none';
        }
    });

    const countSpan = document.getElementById('visible-tpl-count');
    if (countSpan) countSpan.innerText = visibleCount;
}

function resetTemplateFilters() {
    if (document.getElementById('tpl-search-input')) document.getElementById('tpl-search-input').value = '';
    if (document.getElementById('tpl-category-filter')) document.getElementById('tpl-category-filter').value = '';
    if (document.getElementById('tpl-status-filter')) document.getElementById('tpl-status-filter').value = '';
    filterTemplatesTable();
}

function filterTemplatesBySender(senderId) {
    if (senderId === 1 || senderId === 3) {
        window.location.href = `?tab=sync&account_id=${senderId}`;
    } else {
        window.location.href = `?tab=sync`;
    }
}

function onTestTemplateSelect(selectEl) {
    const sel = (typeof selectEl === 'string') ? document.getElementById('test-tpl-select') : selectEl;
    if (!sel) return;

    const opt = sel.options[sel.selectedIndex];
    const selectedTemplateName = sel.value;
    const tplId = opt ? opt.getAttribute('data-id') : '';
    const senderId = opt ? parseInt(opt.getAttribute('data-sender') || '1', 10) : 1;

    const tplIdInput = document.getElementById('test-tpl-id');
    const tplSenderInput = document.getElementById('test-tpl-sender-id');
    if (tplIdInput) tplIdInput.value = tplId || '';
    if (tplSenderInput) tplSenderInput.value = senderId || '';

    const senderInfo = document.getElementById('test-tpl-sender-info');
    if (senderInfo) {
        if (!selectedTemplateName) {
            senderInfo.innerHTML = '';
        } else if (senderId === 3) {
            senderInfo.innerHTML = '<span style="color:#6d28d9; font-weight:700;"><i class="fab fa-whatsapp"></i> Routes via PEPP Updates (+91 79943 04400 &bull; WABA: 1099020233033644)</span>';
        } else {
            senderInfo.innerHTML = '<span style="color:#047857; font-weight:700;"><i class="fab fa-whatsapp"></i> Routes via PEPP Learning (+91 62825 63209 &bull; WABA: 1410328164305566)</span>';
        }
    }

    const section = document.getElementById('test-params-section');
    const paramList = document.getElementById('test-params-list');
    paramList.innerHTML = '';

    if (!selectedTemplateName) {
        section.style.display = 'none';
        return;
    }

    section.style.display = 'block';
    const tplInfo = (tplId && approvedTemplatesById && approvedTemplatesById[tplId])
        ? approvedTemplatesById[tplId]
        : approvedTemplates[selectedTemplateName];

    const bodyIndexes = (tplInfo && tplInfo.body_indexes) ? tplInfo.body_indexes : [];
    if (bodyIndexes.length > 0) {
        for (const i of bodyIndexes) {
            const div = document.createElement('div');
            div.className = 'field';
            div.style.marginBottom = '8px';
            div.innerHTML = `
                <label style="font-size:0.75rem; font-weight:700; color:#4b5563; margin-bottom:2px; display:block;">Parameter {{${i}}}</label>
                <input type="text" name="test_params[${i}]" class="form-control" style="width:100%; border-radius:8px; font-size:0.8rem;" placeholder="Test value for {{${i}}}" required>
            `;
            paramList.appendChild(div);
        }
        if (tplInfo && tplInfo.has_button_url_var) {
            const btnNote = document.createElement('div');
            btnNote.style.fontSize = '0.75rem';
            btnNote.style.color = '#4338ca';
            btnNote.style.background = '#e0e7ff';
            btnNote.style.padding = '6px 10px';
            btnNote.style.borderRadius = '6px';
            btnNote.style.marginTop = '8px';
            btnNote.innerHTML = '<i class="fas fa-info-circle"></i> Dynamic CTA URL Button is automatically tested at dispatch.';
            paramList.appendChild(btnNote);
        }
    } else {
        paramList.innerHTML = '<span style="font-size:0.75rem; color:#9ca3af;">No parameters required.</span>';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Initial previews load for all events
    const eventNames = <?php echo json_encode($events); ?>;
    eventNames.forEach(evName => {
        updatePreviews(evName);
    });
});
</script>

<?php include 'includes/admin_footer.php'; ?>
