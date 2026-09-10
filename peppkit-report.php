<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

require_permission('peppkit');

// ── Self-healing database structure setup ────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_peppkit` (
            `user_id` VARCHAR(50) PRIMARY KEY,
            `status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
            `tracking_id` VARCHAR(100) NULL,
            `updated_by` VARCHAR(100) NULL,
            `updated_at` DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    error_log("Failed to create student_peppkit: " . $e->getMessage());
}

// ── Canonical PEPPKIT address formatter ────────────
/**
 * Canonical PEPPKIT address formatter.
 * Derives formatted address strings consistently from standard address fields.
 *
 * @param array $user Array containing postal_address, place_post_office, district, state, postal_pincode
 * @param string $format 'single_line' | 'with_pin' | 'multiline'
 * @return string
 */
function format_peppkit_canonical_address(array $user, string $format = 'single_line'): string {
    $addr  = trim((string)($user['postal_address'] ?? ''));
    $place = trim((string)($user['place_post_office'] ?? ''));
    $dist  = trim((string)($user['district'] ?? ''));
    $state = trim((string)($user['state'] ?? ''));
    $pin   = trim((string)($user['postal_pincode'] ?? ''));

    // Regional parts: place, district, state
    $regional_parts = array_filter([$place, $dist, $state], function($v) {
        return $v !== '';
    });
    $regional_str = implode(', ', $regional_parts);

    switch ($format) {
        case 'multiline':
            // For print stickers: multiline address + place/district/state.
            // Note: Does NOT include PIN, so sticker can output PIN in its dedicated element without duplication.
            $lines = array_filter([$addr, $regional_str], function($v) {
                return $v !== '';
            });
            return implode("\n", $lines);

        case 'with_pin':
            // For email, WhatsApp, audit log: Address, Place, District, State - Pincode
            $all_parts = array_filter([$addr, $regional_str], function($v) {
                return $v !== '';
            });
            $base = implode(', ', $all_parts);
            return $pin !== '' ? ($base !== '' ? $base . ' - ' . $pin : $pin) : $base;

        case 'single_line':
        default:
            // For table display: Address, Place, District, State
            $all_parts = array_filter([$addr, $regional_str], function($v) {
                return $v !== '';
            });
            return implode(', ', $all_parts);
    }
}

// ── Email notification helper ────────────
function send_peppkit_email($student_name, $to_email, $status, $address_combined, $tracking_id = '') {
    if (!$to_email || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) return;
    
    // Check if auto email toggle is OFF
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'peppkit_auto_email'");
        $stmt->execute();
        $auto_email_setting = $stmt->fetchColumn();
        if ($auto_email_setting === 'OFF') {
            return; // Do not send email
        }
    } catch (Exception $ex) {}
    
    $subject = '';
    $heading = '';
    $body = '';
    
    switch ($status) {
        case 'Addr. Review':
            $subject = 'Confirm your shipping address for PEPPKIT';
            $heading = 'Action Required: Confirm Your Shipping Address';
            $body = "<p>Dear {$student_name},</p>
                    <p>We are preparing your PEPPKIT for shipment. Please review and confirm your shipping address listed below:</p>
                    <div style='background:#fafaf9; border:1px solid #e7e5e4; border-radius:10px; padding:14px 16px; margin: 15px 0; font-family:monospace; line-height:1.5;'>
                        " . nl2br(htmlspecialchars($address_combined)) . "
                    </div>
                    <p>If this address is correct, or if you need to update it, please immediately contact the PEPP Office by WhatsApp or call at +91 70250 00444.</p>";
            break;
            
        case 'Addr. Verified':
            $subject = 'Shipping Address Verified for PEPPKIT';
            $heading = 'Your Shipping Address Has Been Verified';
            $body = "<p>Dear {$student_name},</p>
                    <p>Great news! Your shipping address has been successfully verified for your PEPPKIT shipment:</p>
                    <div style='background:#fafaf9; border:1px solid #e7e5e4; border-radius:10px; padding:14px 16px; margin: 15px 0; font-family:monospace; line-height:1.5;'>
                        " . nl2br(htmlspecialchars($address_combined)) . "
                    </div>
                    <p>We will keep you updated as your kit is prepared and shipped.</p>";
            break;
            
        case 'Packed':
            $subject = 'PEPPKIT Packed & Ready for Dispatch';
            $heading = 'Your PEPPKIT Has Been Packed';
            $now = date('d M Y, h:i A');
            $body = "<p>Dear {$student_name},</p>
                    <p>Your PEPPKIT has been safely packed at <strong>{$now}</strong> and is ready for dispatch.</p>
                    <p>We will be shipping this through <strong>India Post</strong>. Once dispatched, it is expected to be delivered to your address within <strong>3-7 days</strong>. Please ensure someone is available at your address to receive the package.</p>
                    <p style='color:#b45309;'><strong>Important Note:</strong> If the package is returned to us due to address issues, insufficient address, or rejection by the receiver, you will be required to pay the resending courier charges.</p>";
            break;
            
        case 'Sent':
            $subject = 'PEPPKIT Dispatched';
            $heading = 'Your PEPPKIT Has Been Dispatched!';
            if ($tracking_id) {
                $tracking_url = "https://www.indiapost.gov.in/_layouts/15/dop.portal.tracking/trackconsignment.aspx?ConsignmentNo=" . urlencode($tracking_id);
                $body = "<p>Dear {$student_name},</p>
                        <p>Your PEPPKIT has been dispatched and is on its way to your address via India Post!</p>
                        <p><strong>Tracking / Consignment ID:</strong> <code style='font-size:1.1rem; color:#b45309; font-weight:bold;'>{$tracking_id}</code></p>
                        <div style='margin: 20px 0;'>
                            <a href='{$tracking_url}' target='_blank' style='background:#E8980C; color:#fff; padding:10px 20px; border-radius:50px; text-decoration:none; font-weight:700; display:inline-block;'>Track Your Package (India Post)</a>
                        </div>
                        <p>Please note that tracking information may take up to 24 hours to appear on the India Post portal.</p>";
            } else {
                $body = "<p>Dear {$student_name},</p>
                        <p>Your PEPPKIT has been dispatched and is on its way to your address via India Post!</p>
                        <p>It should reach your address within 3-7 business days. Please be ready to receive it.</p>";
            }
            break;
            
        case 'Returned':
            $subject = 'PEPPKIT Returned to Office';
            $heading = 'Notice: Your PEPPKIT Was Returned';
            $body = "<p>Dear {$student_name},</p>
                    <p>We would like to inform you that your PEPPKIT shipment has been returned to our PEPP Learning main office.</p>
                    <p>According to our shipping policy, to resend the package, you must verify/update your address and cover the resending courier charges. Please contact our administrative desk to arrange the re-dispatch.</p>";
            break;
            
        case 'Delivered':
            $subject = 'PEPPKIT Delivered! Welcome to PEPP Learning';
            $heading = 'Congratulations! Your PEPPKIT Has Been Delivered';
            $body = "<p>Dear {$student_name},</p>
                    <p>We are absolutely thrilled to inform you that your PEPPKIT has been successfully delivered to your address!</p>
                    <p>We wish you absolute <strong>\"peppiness\"</strong> as you open it and embark on your learning journey with PEPP Learning. Study hard, stay motivated, and make us proud!</p>
                    <p>All the very best for your studies!</p>";
            break;
    }
    
    if ($subject && $heading && $body) {
        if (file_exists(__DIR__ . '/includes/peppian_notify.php')) {
            require_once __DIR__ . '/includes/peppian_notify.php';
            peppian_send_email($to_email, $subject, $heading, $body, false);
        } else {
            require_once __DIR__ . '/includes/mailer.php';
            @pepp_mail($to_email, $subject, $body, '', [], 'noreply@pepplearning.in', 'PEPP Learning');
        }
    }
}

// ── AJAX: PIN Code lookup (India Post API proxy with timeout) ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'pincode') {
    header('Content-Type: application/json');
    $pincode = trim($_GET['pincode'] ?? '');
    if (strlen($pincode) === 6 && ctype_digit($pincode)) {
        $api_url = "https://api.postalpincode.in/pincode/{$pincode}";
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'user_agent' => 'PEPP-Shipping-Desk/1.0'
            ]
        ]);
        $response = @file_get_contents($api_url, false, $ctx);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data[0]['Status']) && $data[0]['Status'] === 'Success' && !empty($data[0]['PostOffice'])) {
                $places = [];
                foreach ($data[0]['PostOffice'] as $office) {
                    if (!empty($office['Name'])) {
                        $places[] = trim($office['Name']);
                    }
                }
                echo json_encode([
                    'success' => true,
                    'state' => $data[0]['PostOffice'][0]['State'] ?? '',
                    'district' => $data[0]['PostOffice'][0]['District'] ?? '',
                    'places' => array_values(array_unique($places))
                ]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'PIN code not found in postal directory']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Invalid 6-digit PIN code']);
    exit;
}

// ── AJAX POST Actions ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $user_id = trim($_POST['user_id'] ?? '');

    if ($action === 'toggle_auto_email') {
        header('Content-Type: application/json');
        $val = ($_POST['value'] ?? '') === 'ON' ? 'ON' : 'OFF';
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_settings WHERE setting_name = 'peppkit_auto_email'");
            $stmt->execute();
            if ($stmt->fetchColumn() > 0) {
                $stmt = $pdo->prepare("UPDATE admin_settings SET setting_value = ?, updated_at = NOW() WHERE setting_name = 'peppkit_auto_email'");
                $stmt->execute([$val]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, created_at, updated_at) VALUES ('peppkit_auto_email', ?, NOW(), NOW())");
                $stmt->execute([$val]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'update_address') {
        header('Content-Type: application/json');
        $user_id = trim($_POST['user_id'] ?? '');
        $addr    = trim($_POST['postal_address'] ?? '');
        $pin     = trim($_POST['postal_pincode'] ?? '');
        $state   = trim($_POST['state'] ?? '');
        $dist    = trim($_POST['district'] ?? '');
        $place   = trim($_POST['place_post_office'] ?? '');

        // Authoritative server-side structural validation
        if ($user_id === '') {
            echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
            exit;
        }
        if ($addr === '') {
            echo json_encode(['success' => false, 'message' => 'Postal address is required.']);
            exit;
        }
        if ($pin === '' || !preg_match('/^[0-9]{6}$/', $pin)) {
            echo json_encode(['success' => false, 'message' => 'PIN code must be a valid 6-digit number.']);
            exit;
        }
        if ($place === '') {
            echo json_encode(['success' => false, 'message' => 'Place / Post Office is required.']);
            exit;
        }
        if ($dist === '') {
            echo json_encode(['success' => false, 'message' => 'District is required.']);
            exit;
        }
        if ($state === '') {
            echo json_encode(['success' => false, 'message' => 'State is required.']);
            exit;
        }

        try {
            // Fetch previous address for audit trail
            $prev_stmt = $pdo->prepare("SELECT postal_address, postal_pincode, state, district, place_post_office FROM users WHERE user_id = ?");
            $prev_stmt->execute([$user_id]);
            $prev_user = $prev_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$prev_user) {
                echo json_encode(['success' => false, 'message' => 'Student record not found.']);
                exit;
            }

            $old_canonical = format_peppkit_canonical_address($prev_user, 'with_pin');
            $new_user_data = [
                'postal_address'    => $addr,
                'postal_pincode'    => $pin,
                'state'             => $state,
                'district'          => $dist,
                'place_post_office' => $place,
            ];
            $new_canonical = format_peppkit_canonical_address($new_user_data, 'with_pin');

            $stmt = $pdo->prepare("
                UPDATE users
                SET postal_address = ?, postal_pincode = ?, state = ?, district = ?, place_post_office = ?
                WHERE user_id = ?
            ");
            $stmt->execute([$addr, $pin, $state, $dist, $place, $user_id]);

            $audit_note = "PEPPKIT address updated: Old: [{$old_canonical}] -> New: [{$new_canonical}]";
            track_record($pdo, $user_id, 'address_updated_peppkit', $audit_note, $admin_username);
            log_admin_activity($pdo, $admin_username, 'address_updated_peppkit', "Updated address for student {$user_id}. Old: {$old_canonical} | New: {$new_canonical}");

            echo json_encode([
                'success'           => true,
                'user_id'           => $user_id,
                'postal_address'    => $addr,
                'postal_pincode'    => $pin,
                'state'             => $state,
                'district'          => $dist,
                'place_post_office' => $place,
                'formatted_address' => format_peppkit_canonical_address($new_user_data, 'single_line'),
                'full_address'      => $new_canonical
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'update_status') {
        header('Content-Type: application/json');
        $status = trim($_POST['status'] ?? '');
        $tracking_id = trim($_POST['tracking_id'] ?? '');
        
        $allowed_statuses = ['Pending', 'Addr. Review', 'Addr. Verified', 'Packed', 'Sent', 'Returned', 'Delivered'];
        if (!in_array($status, $allowed_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status option.']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT user_id FROM student_peppkit WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("UPDATE student_peppkit SET status = ?, tracking_id = ?, updated_by = ?, updated_at = NOW() WHERE user_id = ?");
                $stmt->execute([$status, $tracking_id ?: null, $admin_username, $user_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO student_peppkit (user_id, status, tracking_id, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$user_id, $status, $tracking_id ?: null, $admin_username]);
            }
            
            $pdo->commit();
            
            $stmt = $pdo->prepare("SELECT name, email, postal_address, postal_pincode, state, district, place_post_office FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stud = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $address_combined = format_peppkit_canonical_address($stud ?: [], 'with_pin');
            
            send_peppkit_email($stud['name'] ?? '', $stud['email'] ?? '', $status, $address_combined, $tracking_id);
            
            track_record($pdo, $user_id, 'peppkit_status_changed', "PEPPKIT status: $status" . ($tracking_id ? " (Tracking ID: $tracking_id)" : ""), $admin_username);
            log_admin_activity($pdo, $admin_username, 'peppkit_status_changed', "Changed PEPPKIT status for student $user_id to $status");
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// ── Print Mode ────────────
if (isset($_GET['print']) && $_GET['print'] === '1') {
    try {
        $stmt = $pdo->query("
            SELECT u.name, u.postal_address, u.place_post_office, u.district, u.state, u.postal_pincode, u.whatsapp_country_code, u.whatsapp_number, u.mobile_number, u.emergency_contact
            FROM users u
            INNER JOIN student_peppkit pk ON pk.user_id = u.user_id
            WHERE u.status = 'approved' AND u.peppkit_eligible = 'Eligible' AND pk.status = 'Addr. Verified' AND (u.student_status <> 'dropout' OR u.student_status IS NULL)
            ORDER BY u.joined_date DESC
        ");
        $stickers = $stmt->fetchAll();
    } catch (Exception $e) {
        $stickers = [];
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Print Address Stickers</title>
        <link href="https://fonts.googleapis.com/css2?family=Google+Sans+Flex:wght@400;500;700&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: 'Google Sans Flex', sans-serif;
                margin: 0;
                padding: 10mm;
                background: #fff;
                font-size: 15px;
            }
            .grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .sticker {
                border: 1px dashed #7f8c8d;
                padding: 18px;
                border-radius: 6px;
                box-sizing: border-box;
                height: auto;
                min-height: 200px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                background: #fff;
            }
            .to-label {
                font-weight: 700;
                color: #555;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin-bottom: 4px;
            }
            .name {
                font-size: 18px;
                font-weight: 700;
                margin: 0 0 10px 0;
                color: #000;
            }
            .address {
                line-height: 1.6;
                color: #222;
                margin-bottom: 12px;
                white-space: pre-wrap;
                flex: 1;
            }
            .pincode {
                font-weight: 700;
                font-size: 16px;
                margin-bottom: 10px;
            }
            .phones {
                border-top: 1px solid #eee;
                padding-top: 8px;
                font-size: 13px;
                color: #333;
            }
            @media print {
                body {
                    padding: 0;
                }
                .sticker {
                    page-break-inside: avoid;
                }
            }
        </style>
    </head>
    <body onload="window.print()">
        <?php if (empty($stickers)): ?>
            <div style="text-align:center; padding: 50px; color:#555;">
                <h2>No Address Verified stickers found to print.</h2>
                <p>Ensure students have status set to "Addr. Verified" in the PEPPKIT shipping desk.</p>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($stickers as $stk):
                    $combined = format_peppkit_canonical_address($stk, 'multiline');
                ?>
                    <div class="sticker">
                        <div>
                            <div class="to-label">TO,</div>
                            <div class="name"><?php echo htmlspecialchars($stk['name']); ?></div>
                            <div class="address"><?php echo htmlspecialchars($combined); ?></div>
                            <div class="pincode">PIN: <?php echo htmlspecialchars($stk['postal_pincode']); ?></div>
                        </div>
                        <div class="phones">
                            <strong>Ph:</strong> <?php 
                            $cc = $stk['whatsapp_country_code'];
                            if (strpos($cc, '+') !== 0) { $cc = '+' . $cc; }
                            echo htmlspecialchars($cc . ' ' . format_credential_text($stk['whatsapp_number'], 'phone')); 
                            ?>
                            <?php if ($stk['mobile_number'] && $stk['mobile_number'] !== $stk['whatsapp_number']): ?>
                                 / <?php echo htmlspecialchars(format_credential_text($stk['mobile_number'], 'phone')); ?>
                            <?php endif; ?>
                            <?php if ($stk['emergency_contact']): ?>
                                <br><strong>Emergency:</strong> +<?php echo htmlspecialchars($stk['emergency_contact']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

// ── Load Filters & Search ────────────
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$where = ["u.status = 'approved'", "u.peppkit_eligible = 'Eligible'", "(u.student_status <> 'dropout' OR u.student_status IS NULL)"];
$params = [];

if ($search !== '') {
    $where[] = "(u.name LIKE ? OR u.whatsapp_number LIKE ? OR u.user_id LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like);
}

if ($status_filter !== '') {
    if ($status_filter === 'Pending') {
        $where[] = "(pk.status IS NULL OR pk.status = 'Pending')";
    } else {
        $where[] = "pk.status = ?";
        $params[] = $status_filter;
    }
}

$where_clause = implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.name, u.pepp_course, u.whatsapp_country_code, u.whatsapp_number, u.mobile_number, u.emergency_contact,
               u.postal_address, u.postal_pincode, u.place_post_office, u.district, u.state, u.joined_date,
               COALESCE(pk.status, 'Pending') AS item_status, pk.tracking_id, pk.updated_by, pk.updated_at
        FROM users u
        LEFT JOIN student_peppkit pk ON pk.user_id = u.user_id
        WHERE {$where_clause}
        ORDER BY 
            CASE COALESCE(pk.status, 'Pending')
                WHEN 'Pending' THEN 1
                WHEN 'Addr. Review' THEN 2
                WHEN 'Addr. Verified' THEN 3
                WHEN 'Packed' THEN 4
                WHEN 'Sent' THEN 5
                WHEN 'Returned' THEN 6
                WHEN 'Delivered' THEN 7
                ELSE 8
            END ASC,
            u.joined_date DESC
    ");
    $stmt->execute($params);
    $kits = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("PEPPKIT load error: " . $e->getMessage());
    $kits = [];
}

// ── Major Status Counts Summary Query ────────────
$status_counts = [
    'Total' => 0,
    'Pending' => 0,
    'Addr. Review' => 0,
    'Addr. Verified' => 0,
    'Packed' => 0,
    'Sent' => 0,
    'Returned' => 0,
    'Delivered' => 0,
];

try {
    $stmt_summary = $pdo->query("
        SELECT COALESCE(pk.status, 'Pending') AS st, COUNT(*) as cnt
        FROM users u
        LEFT JOIN student_peppkit pk ON pk.user_id = u.user_id
        WHERE u.status = 'approved' AND u.peppkit_eligible = 'Eligible' AND (u.student_status <> 'dropout' OR u.student_status IS NULL)
        GROUP BY COALESCE(pk.status, 'Pending')
    ");
    $summary_rows = $stmt_summary->fetchAll();
    foreach ($summary_rows as $sr) {
        $st_name = $sr['st'];
        $st_cnt = (int)$sr['cnt'];
        $status_counts['Total'] += $st_cnt;
        if (isset($status_counts[$st_name])) {
            $status_counts[$st_name] = $st_cnt;
        }
    }
} catch (Exception $e) {
    error_log("PEPPKIT status summary query error: " . $e->getMessage());
}

// Helper to format WhatsApp compose links
function get_peppkit_wa_text($student_name, $status, $address_combined, $tracking_id = '') {
    $msg = '';
    switch ($status) {
        case 'Addr. Review':
            $msg = "Hi {$student_name}, we are preparing your PEPPKIT. Please confirm if your shipping address is correct:\n\n{$address_combined}\n\nReply to confirm or let us know if you need to make changes.";
            break;
        case 'Addr. Verified':
            $msg = "Hi {$student_name}, your shipping address for PEPPKIT has been verified successfully. We will notify you once it's shipped.";
            break;
        case 'Packed':
            $msg = "Hi {$student_name}, your PEPPKIT is packed. Delivery via India Post is expected in 3-7 days. Please make sure someone is available to receive it.";
            break;
        case 'Sent':
            $msg = "Hi {$student_name}, your PEPPKIT has been dispatched via India Post. Tracking Consignment ID: {$tracking_id}. Track it here: https://www.indiapost.gov.in";
            break;
        case 'Returned':
            $msg = "Hi {$student_name}, your PEPPKIT was returned to our office. Please confirm your address details and coordinate the resending charges.";
            break;
        case 'Delivered':
            $msg = "Hi {$student_name}, congratulations! Your PEPPKIT has been delivered. We wish you absolute peppiness on your learning journey with PEPP Learning!";
            break;
        default:
            $msg = "Hi {$student_name}, this is regarding your PEPPKIT shipment from PEPP Learning.";
            break;
    }
    return rawurlencode($msg);
}

$auto_email_setting = 'ON';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'peppkit_auto_email'");
    $stmt->execute();
    $auto_email_setting = $stmt->fetchColumn() ?: 'ON';
} catch (Exception $e) {}

$active_page = 'peppkit';
$page_title  = 'PEPPKIT Shipping Report';
$page_sub    = 'Track and update shipping status for student PEPPKITs';
include 'includes/admin_nav.php';
?>

<style>
.switch {
  position: relative;
  display: inline-block;
  width: 50px;
  height: 24px;
}
.switch input { 
  opacity: 0;
  width: 0;
  height: 0;
}
.slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: #cbd5e1;
  transition: .3s;
  border-radius: 34px;
}
.slider:before {
  position: absolute;
  content: "";
  height: 16px;
  width: 16px;
  left: 4px;
  bottom: 4px;
  background-color: white;
  transition: .3s;
  border-radius: 50%;
}
input:checked + .slider {
  background-color: #16a34a;
}
input:checked + .slider:before {
  transform: translateX(26px);
}
.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}
</style>

<!-- ── MAJOR STATUS COUNT CARDS ── -->
<div class="stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 1.2rem;">
    <a href="peppkit-report.php" class="stat-card" style="text-decoration:none; background:var(--card-bg); border:1.5px solid <?php echo $status_filter === '' ? 'var(--accent)' : 'var(--border)'; ?>; border-radius:12px; padding:12px 14px; transition:all 0.2s;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-boxes-packing" style="color:var(--accent);"></i> Total Kits</div>
        <div style="font-size:1.4rem; font-weight:800; color:var(--text-main);"><?php echo number_format($status_counts['Total']); ?></div>
    </a>

    <a href="peppkit-report.php?status=Pending" class="stat-card" style="text-decoration:none; background:var(--card-bg); border:1.5px solid <?php echo $status_filter === 'Pending' ? '#64748b' : 'var(--border)'; ?>; border-radius:12px; padding:12px 14px; transition:all 0.2s;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-clock" style="color:#64748b;"></i> Pending</div>
        <div style="font-size:1.4rem; font-weight:800; color:#64748b;"><?php echo number_format($status_counts['Pending']); ?></div>
    </a>

    <a href="peppkit-report.php?status=Addr.+Review" class="stat-card" style="text-decoration:none; background:var(--card-bg); border:1.5px solid <?php echo $status_filter === 'Addr. Review' ? '#f59e0b' : 'var(--border)'; ?>; border-radius:12px; padding:12px 14px; transition:all 0.2s;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-location-dot" style="color:#f59e0b;"></i> Addr. Review</div>
        <div style="font-size:1.4rem; font-weight:800; color:#f59e0b;"><?php echo number_format($status_counts['Addr. Review']); ?></div>
    </a>

    <a href="peppkit-report.php?status=Addr.+Verified" class="stat-card" style="text-decoration:none; background:var(--card-bg); border:1.5px solid <?php echo $status_filter === 'Addr. Verified' ? '#10b981' : 'var(--border)'; ?>; border-radius:12px; padding:12px 14px; transition:all 0.2s;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-circle-check" style="color:#10b981;"></i> Addr. Verified</div>
        <div style="font-size:1.4rem; font-weight:800; color:#10b981;"><?php echo number_format($status_counts['Addr. Verified']); ?></div>
    </a>

    <a href="peppkit-report.php?status=Packed" class="stat-card" style="text-decoration:none; background:var(--card-bg); border:1.5px solid <?php echo $status_filter === 'Packed' ? '#8b5cf6' : 'var(--border)'; ?>; border-radius:12px; padding:12px 14px; transition:all 0.2s;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-box" style="color:#8b5cf6;"></i> Packed</div>
        <div style="font-size:1.4rem; font-weight:800; color:#8b5cf6;"><?php echo number_format($status_counts['Packed']); ?></div>
    </a>

    <a href="peppkit-report.php?status=Sent" class="stat-card" style="text-decoration:none; background:var(--card-bg); border:1.5px solid <?php echo $status_filter === 'Sent' ? '#3b82f6' : 'var(--border)'; ?>; border-radius:12px; padding:12px 14px; transition:all 0.2s;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-truck-fast" style="color:#3b82f6;"></i> Dispatched</div>
        <div style="font-size:1.4rem; font-weight:800; color:#3b82f6;"><?php echo number_format($status_counts['Sent']); ?></div>
    </a>

    <a href="peppkit-report.php?status=Returned" class="stat-card" style="text-decoration:none; background:var(--card-bg); border:1.5px solid <?php echo $status_filter === 'Returned' ? '#ef4444' : 'var(--border)'; ?>; border-radius:12px; padding:12px 14px; transition:all 0.2s;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-rotate-left" style="color:#ef4444;"></i> Returned</div>
        <div style="font-size:1.4rem; font-weight:800; color:#ef4444;"><?php echo number_format($status_counts['Returned']); ?></div>
    </a>

    <a href="peppkit-report.php?status=Delivered" class="stat-card" style="text-decoration:none; background:var(--card-bg); border:1.5px solid <?php echo $status_filter === 'Delivered' ? '#16a34a' : 'var(--border)'; ?>; border-radius:12px; padding:12px 14px; transition:all 0.2s;">
        <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-house-chimney-check" style="color:#16a34a;"></i> Delivered</div>
        <div style="font-size:1.4rem; font-weight:800; color:#16a34a;"><?php echo number_format($status_counts['Delivered']); ?></div>
    </a>
</div>

<div class="filter-bar" style="margin-bottom:16px;">
    <form method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; width:100%;">
        <div class="field grow-2" style="margin:0;">
            <label>Search Student</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, ID or phone...">
        </div>
        <div class="field" style="margin:0;">
            <label>Shipping Status</label>
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach (['Pending', 'Addr. Review', 'Addr. Verified', 'Packed', 'Sent', 'Returned', 'Delivered'] as $st): ?>
                    <option value="<?php echo $st; ?>" <?php echo $status_filter === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
        <a href="peppkit-report.php" class="btn btn-outline">Reset</a>
        
        <!-- Auto Email Toggle -->
        <div style="display:flex; align-items:center; gap:8px; margin-left:auto; background:#fafaf9; border:1px solid #e7e5e4; padding:6px 14px; border-radius:10px;">
            <span style="font-size:0.85rem; font-weight:600; color:#374151;"><i class="fas fa-envelope-open-text" style="color:var(--accent);"></i> Auto Email</span>
            <label class="switch" style="position:relative; display:inline-block; width:50px; height:24px; margin:0;">
                <input type="checkbox" id="toggle-auto-email" <?php echo $auto_email_setting === 'ON' ? 'checked' : ''; ?> onchange="toggleAutoEmail(this.checked)" style="opacity:0; width:0; height:0;">
                <span class="slider"></span>
            </label>
        </div>
        
        <a href="peppkit-report.php?print=1" target="_blank" class="btn btn-soft-violet"><i class="fas fa-print"></i> Print Address Stickers</a>
    </form>
</div>

<div class="panel">
    <div class="panel-head" style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px; border-bottom:1px solid var(--border);">
        <h3 style="font-size:1rem; font-weight:800; margin:0; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-list-check" style="color:var(--accent);"></i> PEPPKIT Shipping List
        </h3>
        <span class="badge blue" style="font-weight:700; padding:4px 10px; font-size:0.78rem;">
            Showing <?php echo count($kits); ?> of <?php echo number_format($status_counts['Total']); ?> eligible students
        </span>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($kits)): ?>
            <div class="empty-state" style="padding:40px;"><i class="fas fa-box-open"></i><p>No eligible PEPPKIT shipments found.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:45px; text-align:center;">Sl. No.</th>
                    <th>Student Details</th>
                    <th>Course</th>
                    <th>Combined Address</th>
                    <th>Pincode</th>
                    <th>Shipping Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sl = 1;
                foreach ($kits as $k):
                    $combined_display = format_peppkit_canonical_address($k, 'single_line');
                    $full_address = format_peppkit_canonical_address($k, 'with_pin');
                    $phone = preg_replace('/\D/', '', $k['whatsapp_country_code'] . $k['whatsapp_number']);
                    $wa_text = get_peppkit_wa_text($k['name'], $k['item_status'], $full_address, $k['tracking_id']);
                    
                    $badge_class = 'gray';
                    if ($k['item_status'] === 'Addr. Review') $badge_class = 'amber';
                    elseif ($k['item_status'] === 'Addr. Verified') $badge_class = 'green';
                    elseif ($k['item_status'] === 'Packed') $badge_class = 'violet';
                    elseif ($k['item_status'] === 'Sent') $badge_class = 'blue';
                    elseif ($k['item_status'] === 'Returned') $badge_class = 'red';
                    elseif ($k['item_status'] === 'Delivered') $badge_class = 'green';
                ?>
                <tr id="row-<?php echo htmlspecialchars($k['user_id']); ?>">
                    <td style="text-align:center; font-weight:700; color:var(--text-muted); font-size:0.85rem;">
                        <?php echo $sl++; ?>
                    </td>
                    <td>
                        <div class="cell-main">
                            <a href="student-details.php?user_id=<?php echo urlencode($k['user_id']); ?>" style="text-decoration:none; color:inherit; font-weight:700;">
                                <?php echo htmlspecialchars($k['name']); ?>
                            </a>
                        </div>
                        <div class="cell-sub">
                            <?php 
                            $cc = $k['whatsapp_country_code'];
                            if (strpos($cc, '+') !== 0) { $cc = '+' . $cc; }
                            echo htmlspecialchars($k['user_id']); 
                            ?> · Ph: <?php echo $cc; ?> <?php echo format_credential($k['whatsapp_number'], 'phone'); ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:0.8rem; font-weight:600;"><?php echo htmlspecialchars($k['pepp_course']); ?></div>
                        <div class="cell-sub">Joined: <?php echo date('d M Y', strtotime($k['joined_date'])); ?></div>
                    </td>
                    <td>
                        <div id="addr-display-<?php echo htmlspecialchars($k['user_id']); ?>"
                             class="cell-sub addr-edit-trigger"
                             style="max-width:280px; word-break:break-word; cursor:pointer;"
                             data-user-id="<?php echo htmlspecialchars($k['user_id']); ?>"
                             data-address="<?php echo htmlspecialchars($k['postal_address'] ?? ''); ?>"
                             data-pincode="<?php echo htmlspecialchars($k['postal_pincode'] ?? ''); ?>"
                             data-state="<?php echo htmlspecialchars($k['state'] ?? ''); ?>"
                             data-district="<?php echo htmlspecialchars($k['district'] ?? ''); ?>"
                             data-place="<?php echo htmlspecialchars($k['place_post_office'] ?? ''); ?>"
                             data-name="<?php echo htmlspecialchars($k['name'] ?? ''); ?>"
                             data-status="<?php echo htmlspecialchars($k['item_status'] ?? ''); ?>"
                             data-tracking="<?php echo htmlspecialchars($k['tracking_id'] ?? ''); ?>"
                             data-phone="<?php echo htmlspecialchars($phone); ?>"
                             onclick="openAddressEditFromElem(this)">
                            <?php echo htmlspecialchars($combined_display); ?> <i class="fas fa-edit" style="color:var(--accent); font-size:0.75rem; margin-left:4px;"></i>
                        </div>
                    </td>
                    <td>
                        <div class="cell-main" id="pin-display-<?php echo htmlspecialchars($k['user_id']); ?>"><?php echo htmlspecialchars($k['postal_pincode']); ?></div>
                    </td>
                    <td>
                        <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">
                            <span class="badge <?php echo $badge_class; ?>" style="font-weight:bold;">
                                <?php echo htmlspecialchars($k['item_status']); ?>
                            </span>
                            <?php if ($k['tracking_id']): ?>
                                <span class="cell-sub" style="font-size:0.7rem; font-weight:bold; color:var(--text-muted);">
                                    India Post: <?php echo htmlspecialchars($k['tracking_id']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($k['updated_at']): ?>
                                <span class="cell-sub" style="font-size:0.65rem; color:#888;">
                                    By <?php echo htmlspecialchars($k['updated_by']); ?> on <?php echo date('d M Y, h:i A', strtotime($k['updated_at'])); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button class="btn btn-sm btn-outline" title="Update status" onclick="openStatusChange('<?php echo htmlspecialchars($k['user_id']); ?>', '<?php echo htmlspecialchars(addslashes($k['name'])); ?>', '<?php echo htmlspecialchars($k['item_status']); ?>', '<?php echo htmlspecialchars($k['tracking_id'] ?? ''); ?>')"><i class="fas fa-truck-ramp-box"></i></button>
                        <a id="wa-link-<?php echo htmlspecialchars($k['user_id']); ?>" href="https://wa.me/<?php echo $phone; ?>?text=<?php echo $wa_text; ?>" target="_blank" class="btn btn-sm btn-soft-green" title="Send WhatsApp Update"><i class="fab fa-whatsapp"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Status modal -->
<div class="modal-backdrop" id="status-change-modal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-head">
            <h3><i class="fas fa-truck-ramp-box" style="color:var(--accent);"></i> Update PEPPKIT Status</h3>
            <button class="modal-close" onclick="closeModal('status-change-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form id="status-change-form" onsubmit="submitStatusChange(event)">
            <input type="hidden" name="user_id" id="sc-user-id">
            <div class="modal-body">
                <p id="sc-student-name" style="font-weight:700; margin-bottom:12px;"></p>
                <div class="field" style="margin-bottom:12px;">
                    <label>Shipping Status</label>
                    <select name="status" id="sc-status" onchange="toggleTrackingField()">
                        <option value="Pending">Pending</option>
                        <option value="Addr. Review">Addr. Review</option>
                        <option value="Addr. Verified">Addr. Verified</option>
                        <option value="Packed">Packed</option>
                        <option value="Sent">Sent</option>
                        <option value="Returned">Returned</option>
                        <option value="Delivered">Delivered</option>
                    </select>
                </div>
                <div class="field" id="sc-tracking-field" style="display:none;">
                    <label>India Post Consignment / Tracking ID <span class="req">*</span></label>
                    <input type="text" name="tracking_id" id="sc-tracking-id" placeholder="e.g. EX123456789IN">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('status-change-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Status</button>
            </div>
        </form>
    </div>
</div>

<!-- Inline Address Edit Modal -->
<div class="modal-backdrop" id="address-edit-modal">
    <div class="modal" style="max-width:540px; width:94%; border-radius:16px;">
        <div class="modal-head" style="padding:16px 20px; border-bottom:1px solid var(--border);">
            <h3 style="margin:0; font-size:1.1rem; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-edit" style="color:var(--accent);"></i> Edit Shipping Address
            </h3>
            <button class="modal-close" onclick="closeModal('address-edit-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form id="address-edit-form" onsubmit="submitAddressEdit(event)">
            <input type="hidden" name="user_id" id="ae-user-id">
            <div class="modal-body" style="padding:20px;">
                <!-- Error banner inside modal -->
                <div id="ae-error-alert" style="display:none; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:10px 14px; border-radius:8px; font-size:0.85rem; margin-bottom:14px; align-items:center; gap:8px;">
                    <i class="fas fa-circle-exclamation" style="flex-shrink:0;"></i>
                    <span id="ae-error-message"></span>
                </div>

                <!-- Postal Address -->
                <div class="field full" style="margin-bottom:14px;">
                    <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">
                        Address Details <span class="req" style="color:#ef4444;">*</span>
                    </label>
                    <textarea name="postal_address" id="ae-address" rows="3" required placeholder="House / Flat, Street, Area" style="width:100%; box-sizing:border-box; border-radius:8px; padding:10px 12px; border:1.5px solid var(--border); background:var(--card); color:var(--text); font-size:0.9rem; font-family:inherit; resize:vertical;"></textarea>
                </div>

                <!-- PIN Code & Post Office Row -->
                <div style="display:grid; grid-template-columns: 1fr 1.3fr; gap:12px; margin-bottom:14px;">
                    <div class="field">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin:0;">
                                PIN Code <span class="req" style="color:#ef4444;">*</span>
                            </label>
                            <span id="ae-pin-spinner" style="display:none; font-size:0.7rem; color:var(--accent);">
                                <i class="fas fa-spinner fa-spin"></i>
                            </span>
                        </div>
                        <input type="text" name="postal_pincode" id="ae-pincode" maxlength="6" pattern="[0-9]{6}" placeholder="6-digit PIN" required style="width:100%; box-sizing:border-box; border-radius:8px; padding:9px 12px; border:1.5px solid var(--border); background:var(--card); color:var(--text); font-size:0.9rem;">
                        <div id="ae-pin-status" style="display:none; font-size:0.7rem; color:var(--text-muted); margin-top:3px;"></div>
                    </div>

                    <div class="field">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin:0;">
                                Post Office <span class="req" style="color:#ef4444;">*</span>
                            </label>
                            <button type="button" id="ae-toggle-place-mode" onclick="togglePlaceMode()" style="background:none; border:none; color:var(--accent); font-size:0.72rem; cursor:pointer; font-weight:600; padding:0; text-decoration:underline;">Type manually</button>
                        </div>
                        <div id="ae-place-select-wrap">
                            <select name="place_post_office" id="ae-place-select" style="width:100%; box-sizing:border-box; border-radius:8px; padding:9px 12px; border:1.5px solid var(--border); background:var(--card); color:var(--text); font-size:0.9rem;">
                                <option value="">Select Post Office</option>
                            </select>
                        </div>
                        <div id="ae-place-input-wrap" style="display:none;">
                            <input type="text" id="ae-place-input" placeholder="Type Post Office / Place" style="width:100%; box-sizing:border-box; border-radius:8px; padding:9px 12px; border:1.5px solid var(--border); background:var(--card); color:var(--text); font-size:0.9rem;">
                        </div>
                    </div>
                </div>

                <!-- District & State Row -->
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="field">
                        <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">
                            District <span class="req" style="color:#ef4444;">*</span>
                        </label>
                        <input type="text" name="district" id="ae-district" placeholder="District" required style="width:100%; box-sizing:border-box; border-radius:8px; padding:9px 12px; border:1.5px solid var(--border); background:var(--card); color:var(--text); font-size:0.9rem;">
                    </div>

                    <div class="field">
                        <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">
                            State <span class="req" style="color:#ef4444;">*</span>
                        </label>
                        <input type="text" name="state" id="ae-state" placeholder="State" required style="width:100%; box-sizing:border-box; border-radius:8px; padding:9px 12px; border:1.5px solid var(--border); background:var(--card); color:var(--text); font-size:0.9rem;">
                    </div>
                </div>
            </div>
            <div class="modal-foot" style="padding:14px 20px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('address-edit-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="ae-submit-btn"><i class="fas fa-save"></i> Update Address</button>
            </div>
        </form>
    </div>
</div>

<script>
function openStatusChange(userId, name, currentStatus, currentTracking) {
    document.getElementById('sc-user-id').value = userId;
    document.getElementById('sc-student-name').textContent = name + ' (' + userId + ')';
    document.getElementById('sc-status').value = currentStatus;
    document.getElementById('sc-tracking-id').value = currentTracking;
    toggleTrackingField();
    openModal('status-change-modal');
}

function toggleTrackingField() {
    var status = document.getElementById('sc-status').value;
    var field = document.getElementById('sc-tracking-field');
    var input = document.getElementById('sc-tracking-id');
    if (status === 'Sent') {
        field.style.display = 'block';
        input.required = true;
    } else {
        field.style.display = 'none';
        input.required = false;
    }
}

function submitStatusChange(e) {
    e.preventDefault();
    var form = document.getElementById('status-change-form');
    var formData = new FormData(form);
    formData.append('action', 'update_status');
    formData.append('csrf_token', '<?php echo csrf_token(); ?>');
    
    fetch('peppkit-report.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeModal('status-change-modal');
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Server connection error.');
    });
}

// ── Edit Address Modal Logic ─────────────────────────────────
let currentAddressData = {};
let placeModeManual = false;
let pinLookupAbort = null;

function togglePlaceMode(forceManual) {
    if (typeof forceManual === 'boolean') {
        placeModeManual = forceManual;
    } else {
        placeModeManual = !placeModeManual;
    }

    const selectWrap = document.getElementById('ae-place-select-wrap');
    const inputWrap = document.getElementById('ae-place-input-wrap');
    const select = document.getElementById('ae-place-select');
    const input = document.getElementById('ae-place-input');
    const toggleBtn = document.getElementById('ae-toggle-place-mode');

    if (placeModeManual) {
        input.value = select.value || input.value || currentAddressData.place || '';
        select.removeAttribute('name');
        input.setAttribute('name', 'place_post_office');
        selectWrap.style.display = 'none';
        inputWrap.style.display = 'block';
        toggleBtn.textContent = 'Select from list';
        input.focus();
    } else {
        input.removeAttribute('name');
        select.setAttribute('name', 'place_post_office');
        inputWrap.style.display = 'none';
        selectWrap.style.display = 'block';
        toggleBtn.textContent = 'Type manually';
        if (input.value && !Array.from(select.options).some(o => o.value.toLowerCase() === input.value.trim().toLowerCase())) {
            const opt = document.createElement('option');
            opt.value = input.value.trim();
            opt.textContent = input.value.trim();
            opt.selected = true;
            select.appendChild(opt);
        } else if (input.value) {
            select.value = input.value.trim();
        }
    }
}

function openAddressEditFromElem(elem) {
    const d = elem.dataset;
    openAddressEdit(
        d.userId,
        d.address,
        d.pincode,
        d.state,
        d.district,
        d.place,
        {
            name: d.name,
            status: d.status,
            tracking: d.tracking,
            phone: d.phone
        }
    );
}

function openAddressEdit(userId, address, pincode, state, district, place, meta) {
    currentAddressData = {
        userId: userId || '',
        address: address || '',
        pincode: pincode || '',
        state: state || '',
        district: district || '',
        place: place || '',
        meta: meta || {}
    };

    const errorAlert = document.getElementById('ae-error-alert');
    if (errorAlert) errorAlert.style.display = 'none';

    document.getElementById('ae-user-id').value = currentAddressData.userId;
    document.getElementById('ae-address').value = currentAddressData.address;
    document.getElementById('ae-pincode').value = currentAddressData.pincode;
    document.getElementById('ae-state').value = currentAddressData.state;
    document.getElementById('ae-district').value = currentAddressData.district;

    const select = document.getElementById('ae-place-select');
    const input = document.getElementById('ae-place-input');
    select.innerHTML = '<option value="">Select Post Office</option>';

    if (currentAddressData.place) {
        const opt = document.createElement('option');
        opt.value = currentAddressData.place;
        opt.textContent = currentAddressData.place;
        opt.selected = true;
        select.appendChild(opt);
        input.value = currentAddressData.place;
    } else {
        input.value = '';
    }

    togglePlaceMode(false);

    const spinner = document.getElementById('ae-pin-spinner');
    const statusEl = document.getElementById('ae-pin-status');
    if (spinner) spinner.style.display = 'none';
    if (statusEl) statusEl.style.display = 'none';

    openModal('address-edit-modal');

    // If PIN is already 6 digits, populate dropdown options in background
    if (/^\d{6}$/.test(currentAddressData.pincode)) {
        fetchPinLookup(currentAddressData.pincode, false);
    }
}

function fetchPinLookup(pin, isUserInitiated) {
    if (pinLookupAbort) {
        pinLookupAbort.abort();
    }
    pinLookupAbort = new AbortController();

    const spinner = document.getElementById('ae-pin-spinner');
    const statusEl = document.getElementById('ae-pin-status');
    const stateInput = document.getElementById('ae-state');
    const districtInput = document.getElementById('ae-district');
    const select = document.getElementById('ae-place-select');
    const input = document.getElementById('ae-place-input');

    if (spinner) spinner.style.display = 'inline-block';
    if (statusEl) statusEl.style.display = 'none';

    fetch('peppkit-report.php?ajax=pincode&pincode=' + encodeURIComponent(pin), {
        signal: pinLookupAbort.signal
    })
    .then(r => r.json())
    .then(data => {
        if (spinner) spinner.style.display = 'none';
        if (data.success && data.places && data.places.length > 0) {
            // Auto-fill state & district:
            // If user explicitly initiated PIN change, always set them.
            // If background modal open, only set if currently empty to avoid changing valid existing values.
            if (isUserInitiated || !stateInput.value.trim()) {
                stateInput.value = data.state || stateInput.value;
            }
            if (isUserInitiated || !districtInput.value.trim()) {
                districtInput.value = data.district || districtInput.value;
            }

            // Current value to preserve:
            const targetPlace = (isUserInitiated ? (input.value || select.value) : (currentAddressData.place || input.value || select.value)).trim();

            // Rebuild select options
            select.innerHTML = '<option value="">Select Post Office</option>';
            let found = false;
            data.places.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p;
                opt.textContent = p;
                if (targetPlace && p.toLowerCase() === targetPlace.toLowerCase()) {
                    opt.selected = true;
                    found = true;
                }
                select.appendChild(opt);
            });

            // CORRECTION 1: If current post office is not present in the API result,
            // add it as a preserved selectable option so it is NEVER lost!
            if (targetPlace && !found) {
                const existingOpt = document.createElement('option');
                existingOpt.value = targetPlace;
                existingOpt.textContent = targetPlace + ' (Current)';
                existingOpt.selected = true;
                select.appendChild(existingOpt);
                found = true;
            }

            if (!targetPlace && data.places.length === 1) {
                select.value = data.places[0];
                input.value = data.places[0];
            } else if (targetPlace) {
                input.value = targetPlace;
            }

            if (statusEl) {
                statusEl.textContent = `${data.places.length} post office(s) found`;
                statusEl.style.color = 'var(--text-muted)';
                statusEl.style.display = 'block';
            }
        } else {
            if (statusEl) {
                statusEl.textContent = data.message || 'Postal lookup unavailable';
                statusEl.style.color = '#b45309';
                statusEl.style.display = 'block';
            }
            if (isUserInitiated && select.options.length <= 1) {
                togglePlaceMode(true);
            }
        }
    })
    .catch(err => {
        if (err.name === 'AbortError') return;
        if (spinner) spinner.style.display = 'none';
        if (statusEl) {
            statusEl.textContent = 'Postal lookup unavailable (manual entry allowed)';
            statusEl.style.color = '#b45309';
            statusEl.style.display = 'block';
        }
        if (isUserInitiated && select.options.length <= 1) {
            togglePlaceMode(true);
        }
    });
}

// PIN input change listener
document.addEventListener('DOMContentLoaded', function() {
    const pinInput = document.getElementById('ae-pincode');
    if (pinInput) {
        let pinTimer = null;
        pinInput.addEventListener('input', function() {
            const val = this.value.trim();
            clearTimeout(pinTimer);
            if (val.length === 6 && /^\d{6}$/.test(val)) {
                pinTimer = setTimeout(() => {
                    fetchPinLookup(val, true);
                }, 300);
            }
        });
        pinInput.addEventListener('blur', function() {
            const val = this.value.trim();
            if (val.length === 6 && /^\d{6}$/.test(val)) {
                fetchPinLookup(val, true);
            }
        });
    }

    const placeSelect = document.getElementById('ae-place-select');
    if (placeSelect) {
        placeSelect.addEventListener('change', function() {
            document.getElementById('ae-place-input').value = this.value;
        });
    }
});

function submitAddressEdit(e) {
    e.preventDefault();
    const errorAlert = document.getElementById('ae-error-alert');
    const submitBtn = document.getElementById('ae-submit-btn');

    if (errorAlert) errorAlert.style.display = 'none';

    const userId = document.getElementById('ae-user-id').value.trim();
    const address = document.getElementById('ae-address').value.trim();
    const pincode = document.getElementById('ae-pincode').value.trim();
    const state = document.getElementById('ae-state').value.trim();
    const district = document.getElementById('ae-district').value.trim();
    const place = (placeModeManual ? document.getElementById('ae-place-input').value : document.getElementById('ae-place-select').value).trim();

    // Client-side validation
    if (!address) {
        showAddressError('Please enter the postal address details.');
        document.getElementById('ae-address').focus();
        return;
    }
    if (!pincode || !/^\d{6}$/.test(pincode)) {
        showAddressError('PIN code must be exactly 6 digits.');
        document.getElementById('ae-pincode').focus();
        return;
    }
    if (!place) {
        showAddressError('Please select or enter the Post Office / Place.');
        if (placeModeManual) {
            document.getElementById('ae-place-input').focus();
        } else {
            document.getElementById('ae-place-select').focus();
        }
        return;
    }
    if (!district) {
        showAddressError('Please enter the district.');
        document.getElementById('ae-district').focus();
        return;
    }
    if (!state) {
        showAddressError('Please enter the state.');
        document.getElementById('ae-state').focus();
        return;
    }

    const formData = new FormData();
    formData.append('action', 'update_address');
    formData.append('csrf_token', '<?php echo csrf_token(); ?>');
    formData.append('user_id', userId);
    formData.append('postal_address', address);
    formData.append('postal_pincode', pincode);
    formData.append('state', state);
    formData.append('district', district);
    formData.append('place_post_office', place);

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    fetch('peppkit-report.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Update Address';

        if (data.success) {
            closeModal('address-edit-modal');

            // In-place UI update without requiring full reload
            const addrDisplay = document.getElementById('addr-display-' + userId);
            if (addrDisplay) {
                addrDisplay.dataset.address = data.postal_address;
                addrDisplay.dataset.pincode = data.postal_pincode;
                addrDisplay.dataset.state = data.state;
                addrDisplay.dataset.district = data.district;
                addrDisplay.dataset.place = data.place_post_office;
                addrDisplay.innerHTML = escapeHtml(data.formatted_address) + ' <i class="fas fa-edit" style="color:var(--accent); font-size:0.75rem; margin-left:4px;"></i>';
            }

            const pinDisplay = document.getElementById('pin-display-' + userId);
            if (pinDisplay) {
                pinDisplay.textContent = data.postal_pincode;
            }

            // Update WhatsApp link if present
            const waLink = document.getElementById('wa-link-' + userId);
            if (waLink && addrDisplay) {
                const studentName = addrDisplay.dataset.name || '';
                const itemStatus = addrDisplay.dataset.status || 'Pending';
                const trackingId = addrDisplay.dataset.tracking || '';
                const phone = addrDisplay.dataset.phone || '';
                if (phone) {
                    const waText = buildWhatsAppText(studentName, itemStatus, data.full_address, trackingId);
                    waLink.href = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(waText);
                }
            }

            // Visual feedback: briefly highlight row
            const row = document.getElementById('row-' + userId);
            if (row) {
                const origBg = row.style.backgroundColor;
                row.style.transition = 'background-color 0.4s ease';
                row.style.backgroundColor = '#ecfdf5';
                setTimeout(() => {
                    row.style.backgroundColor = origBg;
                }, 1500);
            }
        } else {
            showAddressError(data.message || 'Failed to update address.');
        }
    })
    .catch(err => {
        console.error(err);
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Update Address';
        showAddressError('Server connection error. Please try again.');
    });
}

function showAddressError(msg) {
    const errorAlert = document.getElementById('ae-error-alert');
    const errorMsg = document.getElementById('ae-error-message');
    if (errorAlert && errorMsg) {
        errorMsg.textContent = msg;
        errorAlert.style.display = 'flex';
    } else {
        alert(msg);
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function buildWhatsAppText(studentName, status, fullAddress, trackingId) {
    switch (status) {
        case 'Addr. Review':
            return `Hi ${studentName}, we are preparing your PEPPKIT. Please confirm if your shipping address is correct:\n\n${fullAddress}\n\nReply to confirm or let us know if you need to make changes.`;
        case 'Addr. Verified':
            return `Hi ${studentName}, your shipping address for PEPPKIT has been verified successfully. We will notify you once it's shipped.`;
        case 'Packed':
            return `Hi ${studentName}, your PEPPKIT is packed. Delivery via India Post is expected in 3-7 days. Please make sure someone is available to receive it.`;
        case 'Sent':
            return `Hi ${studentName}, your PEPPKIT has been dispatched via India Post. Tracking Consignment ID: ${trackingId}. Track it here: https://www.indiapost.gov.in`;
        case 'Returned':
            return `Hi ${studentName}, your PEPPKIT was returned to our office. Please confirm your address details and coordinate the resending charges.`;
        case 'Delivered':
            return `Hi ${studentName}, congratulations! Your PEPPKIT has been delivered. We wish you absolute peppiness on your learning journey with PEPP Learning!`;
        default:
            return `Hi ${studentName}, this is regarding your PEPPKIT shipment from PEPP Learning.`;
    }
}

function toggleAutoEmail(checked) {
    var val = checked ? 'ON' : 'OFF';
    var formData = new FormData();
    formData.append('action', 'toggle_auto_email');
    formData.append('value', val);
    formData.append('csrf_token', '<?php echo csrf_token(); ?>');
    
    fetch('peppkit-report.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert('Error: ' + data.message);
            document.getElementById('toggle-auto-email').checked = !checked;
        }
    })
    .catch(err => {
        console.error(err);
        alert('Server connection error.');
        document.getElementById('toggle-auto-email').checked = !checked;
    });
}
</script>

<?php include 'includes/admin_footer.php'; ?>
