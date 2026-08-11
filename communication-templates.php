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

// Self-healing database structure initialization
try {
    $has_table = (bool)$pdo->query("SHOW TABLES LIKE 'communication_queue'")->fetchColumn();
    if (!$has_table && file_exists(__DIR__ . '/database-update-16.sql')) {
        $sql = file_get_contents(__DIR__ . '/database-update-16.sql');
        $pdo->exec($sql);
        $success_message = 'Database tables for Communication Engine initialized successfully.';
    }
    
    // Check and add columns to communication_templates
    $cols = $pdo->query("SHOW COLUMNS FROM communication_templates")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('quality_status', $cols)) {
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN quality_status VARCHAR(50) DEFAULT NULL AFTER category");
    }
    if (!in_array('rejection_reason', $cols)) {
        $pdo->exec("ALTER TABLE communication_templates ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER quality_status");
    }

    // Check and create communication_event_mappings
    $has_event_table = (bool)$pdo->query("SHOW TABLES LIKE 'communication_event_mappings'")->fetchColumn();
    if (!$has_event_table) {
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

    // Seed default event mappings
    $events = ['student_registration', 'student_approval', 'installment_reminder', 'payment_receipt', 'session_scheduled'];
    $stmtSeed = $pdo->prepare("INSERT IGNORE INTO communication_event_mappings (event_name) VALUES (?)");
    foreach ($events as $ev) {
        $stmtSeed->execute([$ev]);
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
    } elseif (empty($businessId) || empty($accessToken)) {
        $error_message = 'Please configure Business Account ID and Access Token in settings first.';
    } else {
        $url = "https://graph.facebook.com/{$apiVersion}/{$businessId}/message_templates?limit=100";
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
            $error_message = "Meta API Connection Error: " . $err;
        } else {
            $data = json_decode($response, true);
            if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
                $templates = $data['data'];
                $syncedCount = 0;

                $pdo->beginTransaction();
                try {
                    $stmtUpsert = $pdo->prepare("
                        INSERT INTO communication_templates (channel, template_name, language, status, category, quality_status, rejection_reason, meta_data, updated_at) 
                        VALUES ('whatsapp', ?, ?, ?, ?, ?, ?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE status = VALUES(status), category = VALUES(category), quality_status = VALUES(quality_status), rejection_reason = VALUES(rejection_reason), meta_data = VALUES(meta_data), updated_at = NOW()
                    ");

                    foreach ($templates as $tpl) {
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

                        $metaData = json_encode([
                            'components' => $tpl['components'] ?? [],
                            'body_text' => $bodyText,
                            'header_text' => $headerText,
                            'footer_text' => $footerText
                        ]);

                        $stmtUpsert->execute([$name, $lang, $status, $category, $qualityStatus, $rejectedReason, $metaData]);
                        $syncedCount++;
                    }

                    $pdo->commit();
                    $success_message = "Successfully synchronized {$syncedCount} templates from Meta Cloud Account.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error_message = "Database Synchronization failed: " . $e->getMessage();
                }
            } else {
                $details = $data['error']['message'] ?? 'Meta API responded with an error.';
                $error_message = "Meta API Error [{$httpCode}]: " . $details;
            }
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
        $pdo->beginTransaction();
        try {
            $stmtUp = $pdo->prepare("UPDATE communication_event_mappings SET template_name = ?, parameter_mappings = ? WHERE event_name = ?");
            foreach ($posted_mappings as $evName => $data) {
                $tplName = !empty($data['template_name']) ? $data['template_name'] : null;
                
                $rawParams = $data['parameters'] ?? [];
                $params = [];
                foreach ($rawParams as $idx => $param) {
                    $params[(int)$idx] = [
                        'type' => $param['type'] ?? 'variable',
                        'value' => trim($param['value'] ?? '')
                    ];
                }
                
                $stmtUp->execute([$tplName, json_encode($params), $evName]);
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
                $stmtTpl = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? LIMIT 1");
                $stmtTpl->execute([$tplName]);
                $template = $stmtTpl->fetch();
                
                if (!$template) {
                    throw new Exception("Template '{$tplName}' not found.");
                }
                
                if (strtolower($template['status']) !== 'approved') {
                    throw new Exception("Template '{$tplName}' is not approved (Status: {$template['status']}).");
                }
                
                // Parse parameters
                $resolvedParams = [];
                ksort($paramsInput);
                foreach ($paramsInput as $idx => $val) {
                    $resolvedParams[] = trim($val);
                }
                
                $templateData = [
                    'name' => $tplName,
                    'language' => $template['language'] ?? 'en',
                    'parameters' => $resolvedParams
                ];
                
                require_once 'includes/communication/CommunicationEngine.php';
                $engine = CommunicationEngine::getInstance($pdo);
                $provider = $engine->getProvider('whatsapp');
                
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

// Load local synchronized templates
$localTemplates = [];
try {
    $localTemplates = $pdo->query("SELECT * FROM communication_templates WHERE channel = 'whatsapp' ORDER BY template_name ASC")->fetchAll();
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
        <a href="communication-campaigns.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-bullhorn"></i> Bulk Campaigns</a>
    </div>

    <!-- Sync Action Widget -->
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:#1f2937;"><i class="fas fa-sync" style="color:#8b5cf6; margin-right:4px;"></i> Synchronize Approved Meta Templates</h3>
            <p style="margin:4px 0 0; font-size:0.8rem; color:#6b7280;">Downloads and syncs all message templates approved in your Facebook Business account.</p>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="sync_templates">
            <button type="submit" class="btn btn-primary" style="padding:10px 20px; font-weight:700; border-radius:8px;">
                <i class="fas fa-arrow-rotate-forward"></i> Sync WhatsApp Templates
            </button>
        </form>
    </div>

    <?php
    // Pre-defined events and their descriptions
    $eventDescriptions = [
        'student_registration' => 'Triggered when a student initiates registration / onboarding starts.',
        'student_approval' => 'Triggered when student enrollment is approved by administrators.',
        'installment_reminder' => 'Triggered when an installment payment is due (reminders).',
        'payment_receipt' => 'Triggered when a student payment is received and approved.',
        'session_scheduled' => 'Triggered when a live learning session or activity is scheduled.'
    ];

    // Build array of approved templates for JS
    $approvedTemplates = [];
    foreach ($localTemplates as $tpl) {
        if (strtolower($tpl['status']) === 'approved') {
            $meta = json_decode($tpl['meta_data'], true) ?: [];
            $bodyTpl = $meta['body_text'] ?? '';
            preg_match_all('/\{\{(\d+)\}\}/', $bodyTpl, $matches);
            $expectedParamsCount = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;
            
            $approvedTemplates[$tpl['template_name']] = [
                'name' => $tpl['template_name'],
                'language' => $tpl['language'],
                'param_count' => $expectedParamsCount,
                'body_text' => $bodyTpl
            ];
        }
    }
    ?>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-bottom:24px;">
        <!-- Event Mappings Card -->
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin:0 0 12px; font-size:1.1rem; font-weight:700; color:#1f2937;"><i class="fas fa-link" style="color:#4f46e5; margin-right:4px;"></i> PEPP ERP Event Mappings</h3>
            <p style="margin:0 0 20px; font-size:0.8rem; color:#6b7280;">Map PEPP ERP core notification events to Meta-approved message templates and configure parameter interpolation.</p>
            
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_event_mappings">
                
                <div style="display:flex; flex-direction:column; gap:20px;">
                    <?php foreach ($eventMappings as $mapping): ?>
                        <?php 
                            $eventName = $mapping['event_name'];
                            $mappedTpl = $mapping['template_name'] ?? '';
                            $paramMappings = json_decode($mapping['parameter_mappings'], true) ?: [];
                            $label = str_replace('_', ' ', $eventName);
                            $description = $eventDescriptions[$eventName] ?? '';
                        ?>
                        <div style="border:1px solid #f3f4f6; padding:16px; border-radius:12px; background:#fbfbfb;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                                <div>
                                    <h4 style="margin:0; font-size:0.9rem; font-weight:700; color:#374151; text-transform:capitalize;"><?php echo htmlspecialchars($label); ?></h4>
                                    <span style="font-size:0.75rem; color:#9ca3af;"><?php echo htmlspecialchars($description); ?></span>
                                </div>
                                <div>
                                    <select name="mappings[<?php echo htmlspecialchars($eventName); ?>][template_name]" class="form-control" style="width:220px; max-width:100%; border-radius:8px;" onchange="onMappingTemplateChange('<?php echo htmlspecialchars($eventName); ?>', this.value)">
                                        <option value="">- None (Disabled) -</option>
                                        <?php foreach ($approvedTemplates as $tpl): ?>
                                            <option value="<?php echo htmlspecialchars($tpl['name']); ?>" <?php echo $mappedTpl === $tpl['name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tpl['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Parameter Fields Container -->
                            <div id="mapping-params-<?php echo htmlspecialchars($eventName); ?>" style="display: <?php echo !empty($mappedTpl) ? 'block' : 'none'; ?>; border-top:1px dashed #e5e7eb; padding-top:12px; margin-top:8px;">
                                <h5 style="margin:0 0 8px; font-size:0.8rem; font-weight:600; color:#4b5563;">Parameter Values Mappings</h5>
                                <div class="params-list" style="display:flex; flex-direction:column; gap:8px;">
                                    <?php if (!empty($mappedTpl) && isset($approvedTemplates[$mappedTpl])): ?>
                                        <?php 
                                            $tplInfo = $approvedTemplates[$mappedTpl];
                                            for ($i = 1; $i <= $tplInfo['param_count']; $i++):
                                                $mType = $paramMappings[$i]['type'] ?? 'variable';
                                                $mVal = $paramMappings[$i]['value'] ?? '';
                                        ?>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <span style="font-size:0.75rem; font-weight:700; color:#4b5563; min-width:40px;">{{<?php echo $i; ?>}} :</span>
                                                <select name="mappings[<?php echo htmlspecialchars($eventName); ?>][parameters][<?php echo $i; ?>][type]" class="form-control" style="width:110px; font-size:0.75rem;" onchange="onParamTypeChange('<?php echo htmlspecialchars($eventName); ?>', <?php echo $i; ?>, this.value)">
                                                    <option value="variable" <?php echo $mType === 'variable' ? 'selected' : ''; ?>>ERP Variable</option>
                                                    <option value="custom" <?php echo $mType === 'custom' ? 'selected' : ''; ?>>Custom Text</option>
                                                </select>
                                                
                                                <!-- Variable selector -->
                                                <select name="mappings[<?php echo htmlspecialchars($eventName); ?>][parameters][<?php echo $i; ?>][value]" class="form-control value-field-variable" id="val-var-<?php echo htmlspecialchars($eventName); ?>-<?php echo $i; ?>" style="flex:1; font-size:0.75rem; display: <?php echo $mType === 'variable' ? 'inline-block' : 'none'; ?>;">
                                                    <option value="student_name" <?php echo $mVal === 'student_name' ? 'selected' : ''; ?>>Student Name</option>
                                                    <option value="application_id" <?php echo $mVal === 'application_id' ? 'selected' : ''; ?>>Application ID / Roll No</option>
                                                    <option value="course_name" <?php echo $mVal === 'course_name' ? 'selected' : ''; ?>>Course Name</option>
                                                    <option value="payment_amount" <?php echo $mVal === 'payment_amount' ? 'selected' : ''; ?>>Payment Amount</option>
                                                    <option value="invoice_number" <?php echo $mVal === 'invoice_number' ? 'selected' : ''; ?>>Invoice Number</option>
                                                    <option value="invoice_link" <?php echo $mVal === 'invoice_link' ? 'selected' : ''; ?>>Secure Invoice Link</option>
                                                    <option value="balance_amount" <?php echo $mVal === 'balance_amount' ? 'selected' : ''; ?>>Balance Amount</option>
                                                    <option value="installment_due_date" <?php echo $mVal === 'installment_due_date' ? 'selected' : ''; ?>>Installment Due Date</option>
                                                    <option value="session_date" <?php echo $mVal === 'session_date' ? 'selected' : ''; ?>>Session Date</option>
                                                    <option value="trainer_name" <?php echo $mVal === 'trainer_name' ? 'selected' : ''; ?>>Trainer Name</option>
                                                    <option value="meeting_link" <?php echo $mVal === 'meeting_link' ? 'selected' : ''; ?>>Meeting Link</option>
                                                    <option value="current_datetime" <?php echo $mVal === 'current_datetime' ? 'selected' : ''; ?>>Current Date/Time</option>
                                                </select>
                                                
                                                <!-- Custom string input -->
                                                <input type="text" name="mappings[<?php echo htmlspecialchars($eventName); ?>][parameters][<?php echo $i; ?>][value]" class="form-control value-field-custom" id="val-cust-<?php echo htmlspecialchars($eventName); ?>-<?php echo $i; ?>" value="<?php echo $mType === 'custom' ? htmlspecialchars($mVal) : ''; ?>" placeholder="Enter custom value..." style="flex:1; font-size:0.75rem; display: <?php echo $mType === 'custom' ? 'inline-block' : 'none'; ?>;" <?php echo $mType !== 'custom' ? 'disabled' : ''; ?>>
                                            </div>
                                        <?php endfor; ?>
                                    <?php endif; ?>
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
                        <select name="test_template_name" id="test-tpl-select" class="form-control" style="width:100%; border-radius:8px;" onchange="onTestTemplateSelect(this.value)" required>
                            <option value="">- Select Template -</option>
                            <?php foreach ($approvedTemplates as $tpl): ?>
                                <option value="<?php echo htmlspecialchars($tpl['name']); ?>"><?php echo htmlspecialchars($tpl['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
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
        <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px;">
            <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-list" style="margin-right:4px;"></i> Synchronized Templates (<?php echo count($localTemplates); ?>)</h3>
        </div>
        
        <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                    <th style="padding:12px; font-weight:600; color:#374151;">Template Name</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Category</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Language</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Meta Status &amp; Quality</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Variables / Rejection Info</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Preview / Structure</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($localTemplates)): ?>
                    <tr>
                        <td colspan="6" style="padding:30px; text-align:center; color:#9ca3af;"><i class="fas fa-layer-group" style="font-size:1.8rem; display:block; margin-bottom:8px; opacity:0.5;"></i> No templates synchronized. Click the sync button above to import.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($localTemplates as $tpl): ?>
                        <?php 
                            $meta = json_decode($tpl['meta_data'], true); 
                            $bodyText = $meta['body_text'] ?? '';
                            $headerText = $meta['header_text'] ?? '';
                            $footerText = $meta['footer_text'] ?? '';
                            
                            preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches);
                            $expectedParamsCount = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;
                            
                            $qColor = 'gray';
                            $qStatus = $tpl['quality_status'] ?? 'unknown';
                            if ($qStatus === 'high' || $qStatus === 'green') $qColor = 'green';
                            elseif ($qStatus === 'medium' || $qStatus === 'yellow') $qColor = 'orange';
                            elseif ($qStatus === 'low' || $qStatus === 'red') $qColor = 'red';
                        ?>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px; font-weight:700; color:#111827;"><?php echo htmlspecialchars($tpl['template_name']); ?></td>
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
                                <?php if ($expectedParamsCount > 0): ?>
                                    <span style="color:#6366f1; font-weight:700; font-size:0.75rem;"><i class="fas fa-brackets-curly"></i> {{1}} to {{<?php echo $expectedParamsCount; ?>}}</span>
                                <?php else: ?>
                                    <span style="color:#9ca3af; font-size:0.75rem;">None</span>
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
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
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

<script>
function openPreviewModal(tplName) {
    const previewContent = document.getElementById('tpl-preview-' + tplName).innerHTML;
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
}

const approvedTemplates = <?php echo json_encode($approvedTemplates); ?>;

function onMappingTemplateChange(eventName, selectedTemplateName) {
    const container = document.getElementById('mapping-params-' + eventName);
    if (!selectedTemplateName) {
        container.style.display = 'none';
        container.querySelector('.params-list').innerHTML = '';
        return;
    }
    
    container.style.display = 'block';
    const tplInfo = approvedTemplates[selectedTemplateName];
    const paramList = container.querySelector('.params-list');
    paramList.innerHTML = '';
    
    if (tplInfo && tplInfo.param_count > 0) {
        for (let i = 1; i <= tplInfo.param_count; i++) {
            const row = document.createElement('div');
            row.style.display = 'flex';
            row.style.alignItems = 'center';
            row.style.gap = '8px';
            
            row.innerHTML = `
                <span style="font-size:0.75rem; font-weight:700; color:#4b5563; min-width:40px;">{{${i}}} :</span>
                <select name="mappings[${eventName}][parameters][${i}][type]" class="form-control" style="width:110px; font-size:0.75rem;" onchange="onParamTypeChange('${eventName}', ${i}, this.value)">
                    <option value="variable" selected>ERP Variable</option>
                    <option value="custom">Custom Text</option>
                </select>
                <select name="mappings[${eventName}][parameters][${i}][value]" class="form-control value-field-variable" id="val-var-${eventName}-${i}" style="flex:1; font-size:0.75rem;">
                    <option value="student_name" selected>Student Name</option>
                    <option value="application_id">Application ID / Roll No</option>
                    <option value="course_name">Course Name</option>
                    <option value="payment_amount">Payment Amount</option>
                    <option value="invoice_number">Invoice Number</option>
                    <option value="invoice_link">Secure Invoice Link</option>
                    <option value="balance_amount">Balance Amount</option>
                    <option value="installment_due_date">Installment Due Date</option>
                    <option value="session_date">Session Date</option>
                    <option value="trainer_name">Trainer Name</option>
                    <option value="meeting_link">Meeting Link</option>
                    <option value="current_datetime">Current Date/Time</option>
                </select>
                <input type="text" name="mappings[${eventName}][parameters][${i}][value]" class="form-control value-field-custom" id="val-cust-${eventName}-${i}" placeholder="Enter custom value..." style="flex:1; font-size:0.75rem; display:none;" disabled>
            `;
            paramList.appendChild(row);
        }
    } else {
        paramList.innerHTML = '<span style="font-size:0.75rem; color:#9ca3af;">No parameters required for this template.</span>';
    }
}

function onParamTypeChange(eventName, paramIdx, selectedType) {
    const varSelect = document.getElementById('val-var-' + eventName + '-' + paramIdx);
    const custInput = document.getElementById('val-cust-' + eventName + '-' + paramIdx);
    
    if (selectedType === 'variable') {
        varSelect.style.display = 'inline-block';
        varSelect.disabled = false;
        custInput.style.display = 'none';
        custInput.disabled = true;
    } else {
        varSelect.style.display = 'none';
        varSelect.disabled = true;
        custInput.style.display = 'inline-block';
        custInput.disabled = false;
    }
}

function onTestTemplateSelect(selectedTemplateName) {
    const section = document.getElementById('test-params-section');
    const paramList = document.getElementById('test-params-list');
    paramList.innerHTML = '';
    
    if (!selectedTemplateName) {
        section.style.display = 'none';
        return;
    }
    
    section.style.display = 'block';
    const tplInfo = approvedTemplates[selectedTemplateName];
    
    if (tplInfo && tplInfo.param_count > 0) {
        for (let i = 1; i <= tplInfo.param_count; i++) {
            const div = document.createElement('div');
            div.className = 'field';
            div.style.marginBottom = '8px';
            div.innerHTML = `
                <label style="font-size:0.75rem; font-weight:700; color:#4b5563; margin-bottom:2px; display:block;">Parameter {{${i}}}</label>
                <input type="text" name="test_params[${i}]" class="form-control" style="width:100%; border-radius:8px; font-size:0.8rem;" placeholder="Test value for {{${i}}}" required>
            `;
            paramList.appendChild(div);
        }
    } else {
        paramList.innerHTML = '<span style="font-size:0.75rem; color:#9ca3af;">No parameters required.</span>';
    }
}
</script>

<?php include 'includes/admin_footer.php'; ?>
