<?php
/**
 * PEPP Learning — Student Birthdays Admin Page.
 * Lists students with today's birthdays, upcoming birthdays, and birthday reward settings.
 * Permission key: student-birthdays
 */
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('student-birthdays');
require_once 'includes/file_helper.php';
require_once 'includes/birthday_scheduler.php';

$success_msg = '';
$error_msg = '';

// ── AJAX: Trigger manual birthday send or resend ────────────────────────
if (isset($_POST['ajax_action']) && in_array($_POST['ajax_action'], ['manual_birthday_send', 'manual_birthday_resend'], true)) {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['error' => 'Security token mismatch.']);
        exit;
    }
    if (!is_super_admin()) {
        echo json_encode(['error' => 'Only super admins can trigger birthday greetings.']);
        exit;
    }
    $studentId = trim($_POST['student_id'] ?? '');
    if (empty($studentId)) {
        echo json_encode(['error' => 'Missing student ID.']);
        exit;
    }

    require_once 'includes/birthday_scheduler.php';
    require_once 'includes/communication/CommunicationEngine.php';

    $isResend = ($_POST['ajax_action'] === 'manual_birthday_resend');
    $commEngine = CommunicationEngine::getInstance($pdo);

    // 1. Guard: Birthday reward system active?
    try {
        $activeStmt = $pdo->query("SELECT id, is_active FROM birthday_reward_settings LIMIT 1");
        $rewardSetting = $activeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$rewardSetting || empty($rewardSetting['is_active'])) {
            echo json_encode(['error' => 'Birthday Reward System is currently disabled. Enable it in settings before sending.']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Birthday reward settings unavailable.']);
        exit;
    }

    // 2. Guard: Active academic year
    $activeYear = get_birthday_active_academic_year($pdo);
    if (!$activeYear) {
        echo json_encode(['error' => 'No active academic year found.']);
        exit;
    }

    // 3. Fetch student details
    $stmt = $pdo->prepare("
        SELECT user_id, name, date_of_birth, whatsapp_number, whatsapp_country_code,
               mobile_number, phone, email, pepp_academic_year, status, student_status, created_at
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        echo json_encode(['error' => 'Student not found.']);
        exit;
    }

    if ($student['status'] !== 'approved' || ($student['student_status'] ?? '') !== 'active') {
        echo json_encode(['error' => 'Student is not currently active and approved.']);
        exit;
    }

    if (($student['pepp_academic_year'] ?? '') !== $activeYear) {
        echo json_encode(['error' => 'Student does not belong to the current active academic year (' . $activeYear . ').']);
        exit;
    }

    // 4. Verify birthday is today in Asia/Kolkata
    $tz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $todayStr = $now->format('Y-m-d');
    if (!birthday_matches_date((string)$student['date_of_birth'], $todayStr)) {
        echo json_encode(['error' => 'Student does not have a birthday today (' . $todayStr . ').']);
        exit;
    }

    // 5. Verify canonical person identity
    $personIdentity = resolve_person_identity($student);
    if (!$personIdentity) {
        echo json_encode(['error' => 'Student has no valid contact details (phone or email).']);
        exit;
    }

    $canonical = get_canonical_student_record($personIdentity, $pdo, $activeYear);
    if (!$canonical || $canonical['user_id'] !== $studentId) {
        echo json_encode(['error' => 'Only the canonical student record (' . ($canonical['user_id'] ?? 'unknown') . ') can receive birthday notifications for this person.']);
        exit;
    }

    // 6. Check existing notification status & queue status
    $stmt = $pdo->prepare("
        SELECT b.status AS bday_status, b.queue_id, q.status AS queue_status
        FROM birthday_notifications_sent b
        LEFT JOIN communication_queue q ON b.queue_id = q.id
        WHERE b.person_identity = ? AND b.birthday_date = ?
    ");
    $stmt->execute([$personIdentity, $todayStr]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $bStat = $existing['bday_status'] ?? '';
        $qStat = $existing['queue_status'] ?? '';

        if ($bStat === 'sent' || in_array($qStat, ['sent', 'delivered', 'read'], true)) {
            echo json_encode(['error' => 'Birthday notification already successfully sent for this person today.']);
            exit;
        }

        if (in_array($qStat, ['pending', 'scheduled', 'processing', 'retrying'], true)) {
            echo json_encode(['error' => 'Birthday notification is currently queued or being dispatched.']);
            exit;
        }

        if (!$isResend && $bStat !== 'failed' && $qStat !== 'failed') {
            echo json_encode(['error' => 'Birthday notification already exists for this person today.']);
            exit;
        }
    }

    // 7. Resolve WhatsApp recipient phone
    $waPhone = '';
    if (str_starts_with($personIdentity, 'phone:')) {
        $waPhone = substr($personIdentity, 6);
    } else {
        $waPhone = CommunicationEngine::normalizePhone(($student['whatsapp_country_code'] ?? '') . ($student['whatsapp_number'] ?? ''));
        if (empty($waPhone) || strlen($waPhone) < 10) {
            $waPhone = CommunicationEngine::normalizePhone($student['phone'] ?? ($student['mobile_number'] ?? ''));
        }
    }

    if (empty($waPhone) || strlen($waPhone) < 10) {
        echo json_encode(['error' => 'Student has no valid WhatsApp number.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $insertIgnore = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';
        $pdo->prepare("
            {$insertIgnore} birthday_notifications_sent (person_identity, student_id, birthday_date, status, created_at)
            VALUES (?, ?, ?, 'queued', NOW())
        ")->execute([$personIdentity, $studentId, $todayStr]);

        if ($existing) {
            $pdo->prepare("UPDATE birthday_notifications_sent SET status = 'queued' WHERE person_identity = ? AND birthday_date = ?")
                ->execute([$personIdentity, $todayStr]);
        }

        // Build context using shared helper (single source of truth with IMAGE header)
        $context = build_birthday_communication_context($student, $pdo);

        $currentAdmin = $_SESSION['admin_username'] ?? 'superadmin';
        $senderTag = $isResend ? ('manual_resend_' . $currentAdmin) : ('manual_send_' . $currentAdmin);

        $queueId = $commEngine->sendEventNotification('birthday_greeting', $waPhone, $context, $senderTag);

        if ($queueId) {
            $pdo->prepare("UPDATE birthday_notifications_sent SET queue_id = ?, status = 'queued' WHERE person_identity = ? AND birthday_date = ?")
                ->execute([$queueId, $personIdentity, $todayStr]);
            $pdo->commit();
            $actionMsg = $isResend ? 'Birthday greeting requeued successfully.' : 'Birthday greeting queued successfully.';
            echo json_encode(['success' => true, 'message' => $actionMsg, 'queue_id' => $queueId]);
        } else {
            $pdo->prepare("UPDATE birthday_notifications_sent SET status = 'failed' WHERE person_identity = ? AND birthday_date = ?")
                ->execute([$personIdentity, $todayStr]);
            $pdo->commit();
            echo json_encode(['error' => 'Failed to queue birthday notification. Check template mapping.']);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Manual Claim WhatsApp Resend ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'manual_claim_whatsapp_resend') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['error' => 'Security token expired. Please refresh and try again.']);
        exit;
    }
    if (!is_super_admin()) {
        echo json_encode(['error' => 'Unauthorized. Super Admin access required.']);
        exit;
    }

    $studentId = trim($_POST['student_id'] ?? '');
    if (empty($studentId)) {
        echo json_encode(['error' => 'Invalid student ID.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT user_id, name, date_of_birth, whatsapp_number, whatsapp_country_code,
                   mobile_number, email, status, student_status
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            echo json_encode(['error' => 'Student record not found.']);
            exit;
        }

        $personIdentity = resolve_person_identity($student);
        if (!$personIdentity) {
            echo json_encode(['error' => 'Could not resolve person identity.']);
            exit;
        }

        // Look up claim record (latest for this student)
        $claimStmt = $pdo->prepare("
            SELECT c.*, q.status AS queue_status
            FROM birthday_reward_claims c
            LEFT JOIN communication_queue q ON c.claim_whatsapp_queue_id = q.id
            WHERE c.person_identity = ? OR c.student_id = ?
            ORDER BY c.id DESC LIMIT 1
        ");
        $claimStmt->execute([$personIdentity, $studentId]);
        $claim = $claimStmt->fetch(PDO::FETCH_ASSOC);

        if (!$claim) {
            echo json_encode(['error' => 'No reward claim found for this student.']);
            exit;
        }

        // Check if already sent or in-flight (Item 17: Successful claim WhatsApp cannot be resent unnecessarily)
        $qs = $claim['queue_status'] ?? '';
        if ($claim['claim_whatsapp_status'] === 'sent' || in_array($qs, ['sent', 'delivered', 'read'], true)) {
            echo json_encode(['error' => 'Claim WhatsApp confirmation has already been successfully sent to this student.']);
            exit;
        }
        if (in_array($qs, ['pending', 'processing', 'scheduled'], true)) {
            echo json_encode(['error' => 'Claim WhatsApp confirmation is currently queued/sending.']);
            exit;
        }

        // Block legacy claims lacking verifiable instruction snapshot
        if (empty($claim['instruction_token'])) {
            echo json_encode(['error' => 'This is a legacy claim recorded prior to Phase 2. Detailed instructions cannot be fabricated without a historical snapshot.']);
            exit;
        }

        // Dispatch post-claim WhatsApp notification using immutable snapshot
        $queueId = dispatch_birthday_claim_whatsapp($pdo, $claim, $student, $personIdentity);

        if ($queueId) {
            echo json_encode(['success' => true, 'message' => 'Claim WhatsApp confirmation requeued.', 'queue_id' => $queueId]);
        } else {
            echo json_encode(['error' => 'Failed to requeue claim WhatsApp. Check template mapping or recipient phone.']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// ── POST: Save reward settings ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_birthday_settings'])) {
    if (!csrf_verify()) {
        $error_msg = 'Security token expired. Please refresh and try again.';
    } elseif (!is_super_admin()) {
        $error_msg = 'Only super admins can modify birthday reward settings.';
    } else {
        $rewardTitle   = trim($_POST['reward_title'] ?? 'Birthday Reward');
        $rewardDesc    = trim($_POST['reward_description'] ?? '');
        $couponCode    = trim($_POST['coupon_code'] ?? '');
        $validTill     = trim($_POST['valid_till'] ?? '');
        $instructions  = sanitize_reward_html($_POST['instructions'] ?? '');
        $terms         = sanitize_reward_html($_POST['terms'] ?? '');
        $claimMessage  = sanitize_reward_html($_POST['claim_message'] ?? '');
        $isActive      = isset($_POST['is_active']) ? 1 : 0;

        // Fetch current settings for old image paths
        $stmt = $pdo->prepare("SELECT * FROM birthday_reward_settings ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $currentSettings = $stmt->fetch(PDO::FETCH_ASSOC);

        // Handle image uploads
        $headerImage = handle_file_upload_with_replace('birthday_header_image', 'birthday', $currentSettings['birthday_header_image'] ?? null, ['jpg', 'jpeg', 'png', 'webp']);
        $voucherImage = handle_file_upload_with_replace('reward_voucher_image', 'birthday', $currentSettings['reward_voucher_image'] ?? null, ['jpg', 'jpeg', 'png', 'webp']);

        try {
            // First record reward version if meaningful content changed
            $newSettingsData = [
                'reward_title'          => $rewardTitle,
                'reward_description'    => $rewardDesc,
                'coupon_code'           => $couponCode,
                'valid_till'            => $validTill ?: null,
                'instructions'          => $instructions,
                'terms'                 => $terms,
                'claim_message'         => $claimMessage,
                'birthday_header_image' => $headerImage ?: ($currentSettings['birthday_header_image'] ?? null),
                'reward_voucher_image'  => $voucherImage ?: ($currentSettings['reward_voucher_image'] ?? null),
                'is_active'             => $isActive
            ];
            $adminUser = $_SESSION['admin_username'] ?? 'admin';
            record_reward_version_if_changed($pdo, $newSettingsData, $adminUser);

            if ($currentSettings) {
                $sql = "UPDATE birthday_reward_settings SET
                    reward_title = ?, reward_description = ?, coupon_code = ?, valid_till = ?,
                    instructions = ?, terms = ?, claim_message = ?, is_active = ?,
                    updated_at = NOW()";
                $params = [$rewardTitle, $rewardDesc, $couponCode, $validTill ?: null, $instructions, $terms, $claimMessage, $isActive];

                if ($headerImage) {
                    $sql .= ", birthday_header_image = ?";
                    $params[] = $headerImage;
                }
                if ($voucherImage) {
                    $sql .= ", reward_voucher_image = ?";
                    $params[] = $voucherImage;
                }
                $sql .= " WHERE id = ?";
                $params[] = $currentSettings['id'];

                $pdo->prepare($sql)->execute($params);
            } else {
                $pdo->prepare("INSERT INTO birthday_reward_settings (reward_title, reward_description, coupon_code, valid_till, instructions, terms, claim_message, is_active, birthday_header_image, reward_voucher_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$rewardTitle, $rewardDesc, $couponCode, $validTill ?: null, $instructions, $terms, $claimMessage, $isActive, $headerImage, $voucherImage]);
            }
            $success_msg = 'Birthday reward settings saved successfully!';
        } catch (Exception $e) {
            $error_msg = 'Error saving settings: ' . $e->getMessage();
        }
    }
}

// ── Fetch Data ──────────────────────────────────────────────────────────
$tz = new DateTimeZone('Asia/Kolkata');
$nowDateTime = new DateTime('now', $tz);
$todayStr = $nowDateTime->format('Y-m-d');
$month = (int)$nowDateTime->format('m');
$day = (int)$nowDateTime->format('d');
$year = (int)$nowDateTime->format('Y');
$isLeapYear = (bool)$nowDateTime->format('L');

// Birthday reward settings
$settings = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM birthday_reward_settings ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Reward version history (all historical versions)
$rewardVersions = [];
try {
    $vStmt = $pdo->query("
        SELECT v.*, COUNT(c.id) AS claims_count
        FROM birthday_reward_versions v
        LEFT JOIN birthday_reward_claims c ON v.id = c.reward_version_id
        GROUP BY v.id
        ORDER BY v.version_number DESC
    ");
    $rewardVersions = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Active academic year
$activeYear = get_birthday_active_academic_year($pdo);

// Today's birthdays with Feb 29 policy
$dobCondition = "MONTH(u.date_of_birth) = {$month} AND DAY(u.date_of_birth) = {$day}";
if ($month === 2 && $day === 28 && !$isLeapYear) {
    $dobCondition = "MONTH(u.date_of_birth) = 2 AND DAY(u.date_of_birth) IN (28, 29)";
}

$todayBirthdays = [];
if ($activeYear) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.name, u.date_of_birth, u.email, u.pepp_course, u.user_photo,
                   u.whatsapp_number, u.whatsapp_country_code, u.mobile_number,
                   u.pepp_academic_year, u.status, u.student_status, u.created_at
            FROM users u
            WHERE u.status = 'approved' AND u.student_status = 'active'
              AND u.pepp_academic_year = ?
              AND u.date_of_birth IS NOT NULL AND u.date_of_birth <> '0000-00-00'
              AND ({$dobCondition})
            ORDER BY u.name ASC
        ");
        $stmt->execute([$activeYear]);
        $rawToday = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $todayBirthdays = resolve_birthday_persons($rawToday, $pdo, $todayStr);

        // Fetch sent notifications and claims for today by person_identity (with real-time queue status)
        if (!empty($todayBirthdays)) {
            $sentStmt = $pdo->prepare("
                SELECT b.person_identity, b.status AS bday_status, b.queue_id,
                       q.status AS queue_status, q.error_message
                FROM birthday_notifications_sent b
                LEFT JOIN communication_queue q ON b.queue_id = q.id
                WHERE b.birthday_date = ?
            ");
            $sentStmt->execute([$todayStr]);
            $sentMap = [];
            while ($row = $sentStmt->fetch(PDO::FETCH_ASSOC)) {
                $sentMap[$row['person_identity']] = $row;
            }

            $claimStmt = $pdo->prepare("
                SELECT c.person_identity, c.claimed_at, c.coupon_code, c.coupon_valid_till,
                       c.instruction_token, c.claim_whatsapp_queue_id, c.claim_whatsapp_status,
                       q.status AS claim_queue_status, q.error_message AS claim_queue_error
                FROM birthday_reward_claims c
                LEFT JOIN communication_queue q ON c.claim_whatsapp_queue_id = q.id
                WHERE c.birthday_date = ?
            ");
            $claimStmt->execute([$todayStr]);
            $claimMap = [];
            while ($row = $claimStmt->fetch(PDO::FETCH_ASSOC)) {
                $claimMap[$row['person_identity']] = $row;
            }

            foreach ($todayBirthdays as &$bday) {
                $pid = $bday['person_identity'];
                $sInfo = $sentMap[$pid] ?? null;
                $bday['notification_status'] = $sInfo['bday_status'] ?? null;
                $bday['queue_status'] = $sInfo['queue_status'] ?? null;
                $bday['queue_id'] = $sInfo['queue_id'] ?? null;
                $bday['error_message'] = $sInfo['error_message'] ?? null;

                $cInfo = $claimMap[$pid] ?? null;
                $bday['claimed_at'] = $cInfo['claimed_at'] ?? null;
                $bday['coupon_code'] = $cInfo['coupon_code'] ?? null;
                $bday['coupon_valid_till'] = $cInfo['coupon_valid_till'] ?? null;
                $bday['instruction_token'] = $cInfo['instruction_token'] ?? null;
                $bday['claim_whatsapp_status'] = $cInfo['claim_whatsapp_status'] ?? 'not_queued';
                $bday['claim_whatsapp_queue_id'] = $cInfo['claim_whatsapp_queue_id'] ?? null;
                $bday['claim_queue_status'] = $cInfo['claim_queue_status'] ?? null;
                $bday['claim_queue_error'] = $cInfo['claim_queue_error'] ?? null;
            }
            unset($bday);
        }
    } catch (Exception $e) {}
}

// Upcoming birthdays (next 30 days)
$upcomingBirthdays = [];
if ($activeYear) {
    try {
        $upcoming_sql = "
            SELECT u.user_id, u.name, u.date_of_birth, u.email, u.pepp_course, u.user_photo,
                   u.whatsapp_number, u.whatsapp_country_code, u.mobile_number,
                   u.pepp_academic_year, u.status, u.student_status, u.created_at,
                   CONCAT('{$year}-', LPAD(MONTH(u.date_of_birth), 2, '0'), '-', LPAD(DAY(u.date_of_birth), 2, '0')) AS next_birthday
            FROM users u
            WHERE u.status = 'approved' AND u.student_status = 'active'
              AND u.pepp_academic_year = ?
              AND u.date_of_birth IS NOT NULL AND u.date_of_birth <> '0000-00-00'
              AND DATE(CONCAT('{$year}-', LPAD(MONTH(u.date_of_birth), 2, '0'), '-', LPAD(DAY(u.date_of_birth), 2, '0'))) BETWEEN DATE_ADD('{$todayStr}', INTERVAL 1 DAY) AND DATE_ADD('{$todayStr}', INTERVAL 30 DAY)
            ORDER BY MONTH(u.date_of_birth) ASC, DAY(u.date_of_birth) ASC
            LIMIT 50
        ";
        $stmt = $pdo->prepare($upcoming_sql);
        $stmt->execute([$activeYear]);
        $rawUpcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $upcomingBirthdays = resolve_birthday_persons($rawUpcoming, $pdo, null);
    } catch (Exception $e) {}
}

// Notification & claim stats (only count actual sent/delivered dispatches)
$totalSent = 0;
$totalClaimed = 0;
try {
    $totalSent = (int)$pdo->query("
        SELECT COUNT(*) FROM birthday_notifications_sent b
        LEFT JOIN communication_queue q ON b.queue_id = q.id
        WHERE b.status = 'sent' OR q.status IN ('sent','delivered','read')
    ")->fetchColumn();
    $totalClaimed = (int)$pdo->query("SELECT COUNT(*) FROM birthday_reward_claims")->fetchColumn();
} catch (Exception $e) {}

$active_page = 'student-birthdays';
$page_title  = 'Student Birthdays';
$page_sub    = 'Birthday greetings & reward management';
include 'includes/admin_nav.php';
?>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>

<style>
.birthday-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
.bday-stat-card { background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 14px; padding: 20px; display: flex; align-items: center; gap: 14px; }
.bday-stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
.bday-stat-value { font-size: 1.4rem; font-weight: 700; color: var(--text-primary, #1e293b); }
.bday-stat-label { font-size: 0.78rem; color: var(--text-secondary, #64748b); margin-top: 2px; }
.birthday-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px; }
.bday-card { background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 14px; padding: 18px; display: flex; gap: 14px; align-items: flex-start; transition: box-shadow 0.2s; }
.bday-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
.bday-avatar { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; background: #e2e8f0; flex-shrink: 0; }
.bday-avatar-placeholder { width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6, #a78bfa); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 1rem; flex-shrink: 0; }
.bday-info h4 { font-size: 0.92rem; font-weight: 600; color: var(--text-primary, #1e293b); margin: 0 0 4px 0; }
.bday-info p { font-size: 0.78rem; color: var(--text-secondary, #64748b); margin: 2px 0; }
.bday-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; font-size: 0.68rem; font-weight: 600; }
.bday-badge-sent { background: rgba(34,197,94,0.12); color: #16a34a; }
.bday-badge-failed { background: rgba(239,68,68,0.12); color: #dc2626; }
.bday-badge-sending { background: rgba(59,130,246,0.12); color: #2563eb; }
.bday-badge-queued { background: rgba(234,179,8,0.12); color: #d97706; }
.bday-badge-pending { background: rgba(148,163,184,0.12); color: #64748b; }
.bday-badge-claimed { background: rgba(139,92,246,0.12); color: #8b5cf6; }
.bday-coupon-pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 700; background: #ede9fe; color: #6d28d9; border: 1px solid #ddd6fe; font-family: monospace; }
.view-reward-btn { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; font-size: 0.68rem; font-weight: 600; background: #f8fafc; color: #64748b; border: 1px solid #cbd5e1; text-decoration: none; transition: all 0.2s; }
.view-reward-btn:hover { background: #8b5cf6; color: #fff; border-color: #8b5cf6; }
.resend-claim-btn { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; font-size: 0.68rem; font-weight: 600; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; cursor: pointer; transition: all 0.2s; }
.resend-claim-btn:hover { background: #dc2626; color: #fff; border-color: #dc2626; }
.send-btn { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 6px; font-size: 0.72rem; font-weight: 600; background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; cursor: pointer; transition: all 0.2s; }
.send-btn:hover { background: #16a34a; color: #fff; border-color: #16a34a; }
.resend-btn { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 6px; font-size: 0.72rem; font-weight: 600; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; cursor: pointer; transition: all 0.2s; }
.resend-btn:hover { background: #dc2626; color: #fff; border-color: #dc2626; }
.bday-badge-failed { background: rgba(239,68,68,0.12); color: #ef4444; }
.send-btn { padding: 5px 12px; border-radius: 8px; border: none; background: #8b5cf6; color: #fff; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.send-btn:hover { background: #7c3aed; transform: translateY(-1px); }
.send-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.settings-section { background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 14px; padding: 24px; margin-bottom: 24px; }
.settings-section h3 { font-size: 1rem; font-weight: 600; margin: 0 0 16px 0; display: flex; align-items: center; gap: 8px; color: var(--text-primary, #1e293b); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 0.8rem; font-weight: 600; color: var(--text-primary, #1e293b); }
.form-group input, .form-group textarea, .form-group select { padding: 10px 12px; border: 1px solid var(--border-color, #d1d5db); border-radius: 8px; font-size: 0.85rem; background: var(--input-bg, #fff); color: var(--text-primary, #1e293b); }
.form-group textarea { min-height: 80px; resize: vertical; }
.quill-editor-container { min-height: 120px; background: #fff; border-radius: 0 0 8px 8px; font-family: inherit; font-size: 0.88rem; }
.ql-toolbar.ql-snow { border-color: var(--border-color, #d1d5db); border-radius: 8px 8px 0 0; background: #f8fafc; }
.ql-container.ql-snow { border-color: var(--border-color, #d1d5db); border-radius: 0 0 8px 8px; }
/* Modern Accessible Toggle Banner */
.reward-toggle-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 16px 20px;
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 22px;
    transition: all 0.25s ease;
}

.reward-toggle-banner:has(.switch-checkbox:checked),
.reward-toggle-banner.is-active {
    background: rgba(139, 92, 246, 0.04);
    border-color: #8b5cf6;
}

.reward-toggle-details {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 0;
}

.reward-toggle-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: #e2e8f0;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
    transition: all 0.25s ease;
}

.reward-toggle-banner:has(.switch-checkbox:checked) .reward-toggle-icon,
.reward-toggle-banner.is-active .reward-toggle-icon {
    background: #8b5cf6;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.35);
}

.reward-toggle-text {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.reward-toggle-title {
    font-size: 0.92rem;
    font-weight: 700;
    letter-spacing: 0.4px;
    color: var(--text-primary, #1e293b);
    text-transform: uppercase;
}

.reward-toggle-hint {
    font-size: 0.8rem;
    line-height: 1.4;
}

.reward-toggle-hint.hint-off {
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 6px;
}

.reward-toggle-hint.hint-off strong {
    color: #ef4444;
}

.reward-toggle-hint.hint-on {
    color: #059669;
    display: none;
    align-items: center;
    gap: 6px;
}

.reward-toggle-hint.hint-on strong {
    color: #10b981;
}

.reward-toggle-banner:has(.switch-checkbox:checked) .reward-toggle-hint.hint-off,
.reward-toggle-banner.is-active .reward-toggle-hint.hint-off {
    display: none;
}

.reward-toggle-banner:has(.switch-checkbox:checked) .reward-toggle-hint.hint-on,
.reward-toggle-banner.is-active .reward-toggle-hint.hint-on {
    display: flex;
}

/* Switch Control (Interactive Label) */
.switch-control {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    user-select: none;
    position: relative;
    padding: 4px;
    border-radius: 50px;
    flex-shrink: 0;
}

/* Visually Hidden Checkbox - Retains Tab Focus */
.switch-checkbox {
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
}

/* Switch Track (58px x 32px) */
.switch-track {
    width: 58px;
    height: 32px;
    background: #cbd5e1;
    border-radius: 50px;
    position: relative;
    display: inline-block;
    transition: background-color 0.25s ease, box-shadow 0.25s ease;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.12);
}

/* Keyboard Focus Ring */
.switch-checkbox:focus-visible + .switch-track {
    outline: 2px solid #8b5cf6;
    outline-offset: 3px;
    box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.3);
}

/* Switch Knob / Thumb */
.switch-thumb {
    width: 24px;
    height: 24px;
    background: #ffffff;
    border-radius: 50%;
    position: absolute;
    top: 4px;
    left: 4px;
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
}

/* Checked State - Brand Color */
.switch-checkbox:checked + .switch-track {
    background: #8b5cf6;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.18), 0 0 10px rgba(139, 92, 246, 0.35);
}

.switch-checkbox:checked + .switch-track .switch-thumb {
    transform: translateX(26px);
}

/* Status Pill Badge */
.switch-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    min-width: 72px;
    justify-content: center;
    transition: all 0.2s ease;
}

.switch-status.status-off {
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #cbd5e1;
}

.switch-status.status-on {
    display: none;
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
    border: 1px solid rgba(16, 185, 129, 0.35);
}

.switch-checkbox:checked ~ .switch-status.status-off {
    display: none;
}

.switch-checkbox:checked ~ .switch-status.status-on {
    display: inline-flex;
}

@media (max-width: 640px) {
    .reward-toggle-banner {
        flex-direction: column;
        align-items: flex-start;
        gap: 14px;
    }
    .switch-control {
        align-self: flex-start;
    }
}
.image-preview { max-width: 200px; border-radius: 8px; margin-top: 8px; }
.btn-save { padding: 10px 24px; background: #8b5cf6; color: #fff; border: none; border-radius: 10px; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: all 0.2s; }
.btn-save:hover { background: #7c3aed; transform: translateY(-1px); }
.section-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.section-tab { padding: 8px 18px; border-radius: 10px; border: 1px solid var(--border-color, #e2e8f0); background: var(--card-bg, #fff); color: var(--text-secondary, #64748b); font-weight: 600; font-size: 0.82rem; cursor: pointer; transition: all 0.2s; }
.section-tab.active { background: #8b5cf6; color: #fff; border-color: #8b5cf6; }
.tab-content { display: none; }
.tab-content.active { display: block; }
@media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } .birthday-grid { grid-template-columns: 1fr; } }
</style>

<!-- Stats Cards -->
<div class="birthday-stats">
    <div class="bday-stat-card">
        <div class="bday-stat-icon" style="background: rgba(234,179,8,0.12); color: #d97706;"><i class="fas fa-cake-candles"></i></div>
        <div><div class="bday-stat-value"><?php echo count($todayBirthdays); ?></div><div class="bday-stat-label">Today's Birthdays</div></div>
    </div>
    <div class="bday-stat-card">
        <div class="bday-stat-icon" style="background: rgba(59,130,246,0.12); color: #3b82f6;"><i class="fas fa-calendar-days"></i></div>
        <div><div class="bday-stat-value"><?php echo count($upcomingBirthdays); ?></div><div class="bday-stat-label">Upcoming (30 days)</div></div>
    </div>
    <div class="bday-stat-card">
        <div class="bday-stat-icon" style="background: rgba(34,197,94,0.12); color: #16a34a;"><i class="fas fa-paper-plane"></i></div>
        <div><div class="bday-stat-value"><?php echo $totalSent; ?></div><div class="bday-stat-label">Total Notifications</div></div>
    </div>
    <div class="bday-stat-card">
        <div class="bday-stat-icon" style="background: rgba(139,92,246,0.12); color: #8b5cf6;"><i class="fas fa-gift"></i></div>
        <div><div class="bday-stat-value"><?php echo $totalClaimed; ?></div><div class="bday-stat-label">Rewards Claimed</div></div>
    </div>
</div>

<?php if ($success_msg): ?>
<div class="alert alert-success" style="margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?php echo e($success_msg); ?></div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert alert-error" style="margin-bottom:16px;"><i class="fas fa-exclamation-triangle"></i> <?php echo e($error_msg); ?></div>
<?php endif; ?>

<!-- Tab Navigation -->
<div class="section-tabs">
    <button class="section-tab active" data-tab="today" onclick="switchTab('today')"><i class="fas fa-cake-candles"></i> Today's Birthdays</button>
    <button class="section-tab" data-tab="upcoming" onclick="switchTab('upcoming')"><i class="fas fa-calendar"></i> Upcoming</button>
    <button class="section-tab" data-tab="settings" onclick="switchTab('settings')"><i class="fas fa-gear"></i> Reward Settings</button>
</div>

<!-- Today's Birthdays -->
<div class="tab-content active" id="tab-today">
    <?php if (empty($todayBirthdays)): ?>
        <div class="settings-section" style="text-align:center; padding:40px;">
            <i class="fas fa-birthday-cake" style="font-size:2.5rem; color:#d1d5db; margin-bottom:12px;"></i>
            <p style="color:var(--text-secondary,#64748b); font-size:0.9rem;">No student birthdays today.</p>
        </div>
    <?php else: ?>
        <div class="birthday-grid">
        <?php foreach ($todayBirthdays as $bday): ?>
            <div class="bday-card" id="bday-card-<?php echo e($bday['user_id']); ?>">
                <?php
                $photoPath = $bday['user_photo'] ? '../uploads/' . $bday['user_photo'] : '';
                $initials = strtoupper(substr($bday['name'] ?? 'S', 0, 1));
                if ($photoPath && file_exists($photoPath)): ?>
                    <img class="bday-avatar" src="<?php echo e($photoPath); ?>" alt="<?php echo e($bday['name']); ?>">
                <?php else: ?>
                    <div class="bday-avatar-placeholder"><?php echo $initials; ?></div>
                <?php endif; ?>
                <div class="bday-info" style="flex:1; min-width:0;">
                    <h4><?php echo e($bday['name']); ?></h4>
                    <p><i class="fas fa-graduation-cap"></i> <?php echo e($bday['pepp_course'] ?? '-'); ?></p>
                    <p><i class="fas fa-calendar"></i> <?php echo date('d M Y', strtotime($bday['date_of_birth'])); ?></p>
                    <div style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                        <?php
                        $ns = $bday['notification_status'] ?? null;
                        $qs = $bday['queue_status'] ?? null;
                        if ($ns === 'sent' || in_array($qs, ['sent', 'delivered', 'read'], true)):
                        ?>
                            <span class="bday-badge bday-badge-sent"><i class="fas fa-check"></i> Sent</span>
                        <?php elseif ($ns === 'failed' || $qs === 'failed'): ?>
                            <span class="bday-badge bday-badge-failed" title="<?php echo htmlspecialchars($bday['error_message'] ?? 'Delivery failed'); ?>"><i class="fas fa-times-circle"></i> Failed</span>
                            <?php if (is_super_admin()): ?>
                                <button class="resend-btn" onclick="resendBirthdayManual('<?php echo e($bday['user_id']); ?>', this)">
                                    <i class="fas fa-redo"></i> Resend
                                </button>
                            <?php endif; ?>
                        <?php elseif ($qs === 'processing'): ?>
                            <span class="bday-badge bday-badge-sending"><i class="fas fa-spinner fa-spin"></i> Sending</span>
                        <?php elseif ($ns === 'queued' || in_array($qs, ['pending', 'scheduled', 'retrying'], true)): ?>
                            <span class="bday-badge bday-badge-queued"><i class="fas fa-clock"></i> Queued</span>
                        <?php else: ?>
                            <span class="bday-badge bday-badge-pending"><i class="fas fa-clock"></i> Pending</span>
                            <?php if (is_super_admin()): ?>
                                <button class="send-btn" onclick="sendBirthdayManual('<?php echo e($bday['user_id']); ?>', this)">
                                    <i class="fas fa-paper-plane"></i> Send
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($bday['claimed_at']): ?>
                            <div style="margin-top:6px; padding-top:6px; border-top:1px dashed #e2e8f0; width:100%;">
                                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                    <span class="bday-badge bday-badge-claimed"><i class="fas fa-gift"></i> Claimed <?php echo date('h:i A', strtotime($bday['claimed_at'])); ?></span>
                                    <?php if (!empty($bday['coupon_code'])): ?>
                                        <span class="bday-coupon-pill" title="Claimed Coupon"><i class="fas fa-ticket"></i> <?php echo e($bday['coupon_code']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($bday['coupon_valid_till'])): ?>
                                        <span style="font-size:0.7rem; color:#64748b;">(Exp: <?php echo date('d M Y', strtotime($bday['coupon_valid_till'])); ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top:5px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                    <?php
                                    $cws = $bday['claim_whatsapp_status'] ?? 'not_queued';
                                    $cqs = $bday['claim_queue_status'] ?? '';
                                    if ($cws === 'sent' || in_array($cqs, ['sent', 'delivered', 'read'], true)):
                                    ?>
                                        <span class="bday-badge bday-badge-sent" title="Post-claim WhatsApp delivered"><i class="fab fa-whatsapp"></i> Claim WA Sent</span>
                                    <?php elseif ($cqs === 'processing'): ?>
                                        <span class="bday-badge bday-badge-sending"><i class="fas fa-spinner fa-spin"></i> WA Sending</span>
                                    <?php elseif ($cws === 'queued' || in_array($cqs, ['pending', 'scheduled', 'retrying'], true)): ?>
                                        <span class="bday-badge bday-badge-queued"><i class="fas fa-clock"></i> Claim WA Queued</span>
                                    <?php elseif ($cws === 'failed' || $cqs === 'failed'): ?>
                                        <span class="bday-badge bday-badge-failed" title="<?php echo htmlspecialchars($bday['claim_queue_error'] ?? 'Claim WhatsApp delivery failed'); ?>"><i class="fas fa-times-circle"></i> Claim WA Failed</span>
                                        <?php if (is_super_admin()): ?>
                                            <button class="resend-claim-btn" onclick="resendClaimWhatsApp('<?php echo e($bday['user_id']); ?>', this)">
                                                <i class="fas fa-redo"></i> Resend WA
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="bday-badge" style="background:#f1f5f9; color:#64748b;"><i class="fab fa-whatsapp"></i> WA Not Queued</span>
                                        <?php if (is_super_admin()): ?>
                                            <button class="resend-claim-btn" onclick="resendClaimWhatsApp('<?php echo e($bday['user_id']); ?>', this)">
                                                <i class="fas fa-paper-plane"></i> Send WA
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (!empty($bday['instruction_token'])): ?>
                                        <a href="birthday-instructions.php/<?php echo e($bday['instruction_token']); ?>" target="_blank" class="view-reward-btn" title="View permanent student reward instruction page">
                                            <i class="fas fa-arrow-up-right-from-square"></i> View Reward
                                        </a>
                                    <?php else: ?>
                                        <span class="bday-badge" style="background:#f1f5f9; color:#64748b;" title="Claim recorded prior to Phase 2 snapshot system"><i class="fas fa-history"></i> Legacy Claim</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Upcoming Birthdays -->
<div class="tab-content" id="tab-upcoming">
    <?php if (empty($upcomingBirthdays)): ?>
        <div class="settings-section" style="text-align:center; padding:40px;">
            <p style="color:var(--text-secondary,#64748b); font-size:0.9rem;">No upcoming birthdays in the next 30 days.</p>
        </div>
    <?php else: ?>
        <div class="birthday-grid">
        <?php foreach ($upcomingBirthdays as $bday): ?>
            <div class="bday-card">
                <?php
                $photoPath = $bday['user_photo'] ? '../uploads/' . $bday['user_photo'] : '';
                $initials = strtoupper(substr($bday['name'] ?? 'S', 0, 1));
                if ($photoPath && file_exists($photoPath)): ?>
                    <img class="bday-avatar" src="<?php echo e($photoPath); ?>" alt="<?php echo e($bday['name']); ?>">
                <?php else: ?>
                    <div class="bday-avatar-placeholder"><?php echo $initials; ?></div>
                <?php endif; ?>
                <div class="bday-info">
                    <h4><?php echo e($bday['name']); ?></h4>
                    <p><i class="fas fa-graduation-cap"></i> <?php echo e($bday['pepp_course'] ?? '-'); ?></p>
                    <p><i class="fas fa-calendar"></i> <?php echo date('d M', strtotime($bday['date_of_birth'])); ?> (<?php
                        $nbDate = new DateTime($bday['next_birthday']);
                        $diff = $nowDateTime->diff($nbDate);
                        echo $diff->days . ' day' . ($diff->days !== 1 ? 's' : '') . ' away';
                    ?>)</p>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Reward Settings -->
<div class="tab-content" id="tab-settings">
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="save_birthday_settings" value="1">

        <div class="settings-section">
            <h3><i class="fas fa-gift"></i> Birthday Reward Configuration</h3>

            <div class="reward-toggle-banner <?php echo ($settings['is_active'] ?? 0) ? 'is-active' : ''; ?>">
                <div class="reward-toggle-details">
                    <div class="reward-toggle-icon">
                        <i class="fas fa-power-off"></i>
                    </div>
                    <div class="reward-toggle-text">
                        <span class="reward-toggle-title">ENABLE BIRTHDAY REWARD SYSTEM</span>
                        <span class="reward-toggle-hint hint-off"><i class="fas fa-circle-xmark"></i> System is currently <strong>DISABLED</strong>. Automatic birthday greetings and reward claims are inactive.</span>
                        <span class="reward-toggle-hint hint-on"><i class="fas fa-circle-check"></i> System is currently <strong>ENABLED</strong>. Automatic birthday greetings and reward claims are active.</span>
                    </div>
                </div>
                <label class="switch-control" for="reward_active" title="Toggle Birthday Reward System">
                    <input type="checkbox" name="is_active" id="reward_active" value="1" class="switch-checkbox" <?php echo ($settings['is_active'] ?? 0) ? 'checked' : ''; ?> aria-label="Enable Birthday Reward System">
                    <span class="switch-track" aria-hidden="true">
                        <span class="switch-thumb"></span>
                    </span>
                    <span class="switch-status status-off" aria-hidden="true"><i class="fas fa-circle-xmark"></i> OFF</span>
                    <span class="switch-status status-on" aria-hidden="true"><i class="fas fa-circle-check"></i> ON</span>
                </label>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Reward Title</label>
                    <input type="text" name="reward_title" value="<?php echo e($settings['reward_title'] ?? 'Birthday Reward'); ?>" placeholder="e.g. PEPP Birthday Reward">
                </div>
                <div class="form-group">
                    <label>Coupon Code</label>
                    <input type="text" name="coupon_code" value="<?php echo e($settings['coupon_code'] ?? ''); ?>" placeholder="e.g. BDAY25OFF">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Valid Till</label>
                    <input type="date" name="valid_till" value="<?php echo e($settings['valid_till'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Reward Description</label>
                    <input type="text" name="reward_description" value="<?php echo e($settings['reward_description'] ?? ''); ?>" placeholder="A short description of the reward">
                </div>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label>Instructions (shown to student)</label>
                <div id="instructions-editor" class="quill-editor-container"><?php echo $settings['instructions'] ?? ''; ?></div>
                <input type="hidden" name="instructions" id="instructions-input">
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label>Terms & Conditions</label>
                <div id="terms-editor" class="quill-editor-container"><?php echo $settings['terms'] ?? ''; ?></div>
                <input type="hidden" name="terms" id="terms-input">
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label>Custom Claim Success Message</label>
                <div id="claim_message-editor" class="quill-editor-container"><?php echo $settings['claim_message'] ?? ''; ?></div>
                <input type="hidden" name="claim_message" id="claim_message-input">
            </div>
        </div>

        <div class="settings-section">
            <h3><i class="fas fa-image"></i> Images</h3>

            <div class="form-row">
                <div class="form-group">
                    <label>WhatsApp Header Image (for birthday greeting)</label>
                    <input type="file" name="birthday_header_image" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($settings['birthday_header_image'])): ?>
                        <img class="image-preview" src="../<?php echo e($settings['birthday_header_image']); ?>" alt="Header Image">
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Reward Voucher Image (shown on claim page)</label>
                    <input type="file" name="reward_voucher_image" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($settings['reward_voucher_image'])): ?>
                        <img class="image-preview" src="../<?php echo e($settings['reward_voucher_image']); ?>" alt="Voucher Image">
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn-save" style="margin-top:12px;"><i class="fas fa-save"></i> Save Settings</button>
        </div>
    </form>

    <!-- Reward Configuration History (Read-Only) -->
    <div class="settings-section" style="margin-top:24px;">
        <h3><i class="fas fa-history"></i> Reward Configuration History (Read-Only)</h3>
        <p style="font-size:0.85rem; color:#64748b; margin-bottom:16px;">Historical record of all published reward configurations. Historical snapshots remain permanently immutable.</p>
        <?php if (empty($rewardVersions)): ?>
            <p style="color:#94a3b8; font-size:0.85rem; font-style:italic;">No historical versions recorded yet.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                    <thead>
                        <tr style="border-bottom:2px solid #e2e8f0; text-align:left; color:#475569;">
                            <th style="padding:10px 12px;">Version</th>
                            <th style="padding:10px 12px;">Coupon Code</th>
                            <th style="padding:10px 12px;">Valid Till</th>
                            <th style="padding:10px 12px;">Created</th>
                            <th style="padding:10px 12px;">Created By</th>
                            <th style="padding:10px 12px;">Claims Count</th>
                            <th style="padding:10px 12px;">Status</th>
                            <th style="padding:10px 12px; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $first = true;
                        foreach ($rewardVersions as $rv):
                            $isCurrent = $first;
                            $first = false;
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px 12px; font-weight:700; color:#1e293b;">v<?php echo e($rv['version_number']); ?></td>
                            <td style="padding:10px 12px;"><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:700; color:#7c3aed;"><?php echo e($rv['coupon_code']); ?></code></td>
                            <td style="padding:10px 12px;"><?php echo $rv['valid_till'] ? date('d M Y', strtotime($rv['valid_till'])) : '<span style="color:#94a3b8;">Permanent</span>'; ?></td>
                            <td style="padding:10px 12px; color:#64748b;"><?php echo date('d M Y, h:i A', strtotime($rv['created_at'])); ?></td>
                            <td style="padding:10px 12px; color:#64748b;"><?php echo e($rv['created_by'] ?? 'admin'); ?></td>
                            <td style="padding:10px 12px; font-weight:600;"><?php echo (int)($rv['claims_count'] ?? 0); ?></td>
                            <td style="padding:10px 12px;">
                                <?php if ($isCurrent): ?>
                                    <span style="background:rgba(16,185,129,0.12); color:#059669; padding:3px 10px; border-radius:12px; font-weight:700; font-size:0.75rem;"><i class="fas fa-check-circle"></i> Current</span>
                                <?php else: ?>
                                    <span style="background:#f1f5f9; color:#64748b; padding:3px 10px; border-radius:12px; font-weight:600; font-size:0.75rem;">Historical</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 12px; text-align:right;">
                                <button type="button" class="btn-view-version" style="padding:5px 12px; font-size:0.75rem; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer; color:#475569;" onclick='showVersionModal(<?php echo json_encode($rv, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'>
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Historical Version Details Modal -->
<div id="versionModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; width:100%; max-width:640px; border-radius:16px; padding:24px; max-height:90vh; overflow-y:auto; position:relative; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
            <h3 id="modalVersionTitle" style="margin:0; font-size:1.1rem; color:#1e293b;"><i class="fas fa-clock-rotate-left"></i> Reward Version Details</h3>
            <button type="button" onclick="closeVersionModal()" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8;"><i class="fas fa-times"></i></button>
        </div>
        <div id="modalVersionContent" style="font-size:0.88rem; color:#334155; line-height:1.5;">
            <!-- Dynamic Content -->
        </div>
        <div style="margin-top:20px; text-align:right; border-top:1px solid #e2e8f0; padding-top:12px;">
            <button type="button" onclick="closeVersionModal()" style="padding:8px 18px; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:8px; font-weight:600; cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.section-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
}

// Initialize Quill Editors for Instructions, Terms & Conditions, and Claim Message
var quillToolbarOptions = [
    ['bold', 'italic', 'underline'],
    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
    [{ 'header': [1, 2, 3, false] }],
    ['link'],
    ['clean']
];

var instructionsQuill = null;
var termsQuill = null;
var claimMessageQuill = null;

if (document.getElementById('instructions-editor')) {
    instructionsQuill = new Quill('#instructions-editor', {
        theme: 'snow',
        placeholder: 'How to redeem the reward...',
        modules: { toolbar: quillToolbarOptions }
    });
}

if (document.getElementById('terms-editor')) {
    termsQuill = new Quill('#terms-editor', {
        theme: 'snow',
        placeholder: 'Terms and conditions...',
        modules: { toolbar: quillToolbarOptions }
    });
}

if (document.getElementById('claim_message-editor')) {
    claimMessageQuill = new Quill('#claim_message-editor', {
        theme: 'snow',
        placeholder: 'Shown after the student claims the reward...',
        modules: { toolbar: quillToolbarOptions }
    });
}

// Synchronize Quill editor contents into hidden inputs on form submit
var settingsForm = document.querySelector('form[method="POST"]');
if (settingsForm) {
    settingsForm.addEventListener('submit', function() {
        if (instructionsQuill) {
            document.getElementById('instructions-input').value = instructionsQuill.root.innerHTML;
        }
        if (termsQuill) {
            document.getElementById('terms-input').value = termsQuill.root.innerHTML;
        }
        if (claimMessageQuill) {
            document.getElementById('claim_message-input').value = claimMessageQuill.root.innerHTML;
        }
    });
}

function sendBirthdayManual(studentId, btn) {
    if (!confirm('Send birthday greeting to this student via WhatsApp?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    fetch('students-birthdays.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=manual_birthday_send&student_id=' + encodeURIComponent(studentId) + '&csrf_token=' + encodeURIComponent(document.querySelector('[name="csrf_token"]')?.value || '<?php echo csrf_token(); ?>')
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.outerHTML = '<span class="bday-badge bday-badge-queued"><i class="fas fa-clock"></i> Queued</span>';
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
            alert(data.error || 'Failed to send.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
        alert('Network error. Please try again.');
    });
}

function resendBirthdayManual(studentId, btn) {
    if (!confirm('Resend birthday greeting to this student via WhatsApp?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    fetch('students-birthdays.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=manual_birthday_resend&student_id=' + encodeURIComponent(studentId) + '&csrf_token=' + encodeURIComponent(document.querySelector('[name="csrf_token"]')?.value || '<?php echo csrf_token(); ?>')
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.outerHTML = '<span class="bday-badge bday-badge-queued"><i class="fas fa-clock"></i> Queued</span>';
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-redo"></i> Resend';
            alert(data.error || 'Failed to resend.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-redo"></i> Resend';
        alert('Network error. Please try again.');
    });
}

function resendClaimWhatsApp(studentId, btn) {
    if (!confirm('Send post-claim reward WhatsApp confirmation to this student?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

    fetch('students-birthdays.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_action=manual_claim_whatsapp_resend&student_id=' + encodeURIComponent(studentId) + '&csrf_token=' + encodeURIComponent(document.querySelector('[name="csrf_token"]')?.value || '<?php echo csrf_token(); ?>')
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.outerHTML = '<span class="bday-badge bday-badge-queued"><i class="fas fa-clock"></i> Claim WA Queued</span>';
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-redo"></i> Resend WA';
            alert(data.error || 'Failed to resend claim WhatsApp.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-redo"></i> Resend WA';
        alert('Network error. Please try again.');
    });
}

function showVersionModal(rv) {
    document.getElementById('modalVersionTitle').innerHTML = '<i class="fas fa-clock-rotate-left"></i> Reward Configuration — Version ' + (rv.version_number || '1');
    var html = `
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; background:#f8fafc; padding:12px; border-radius:10px; border:1px solid #e2e8f0;">
            <div><strong>Reward Title:</strong> <br>${escapeHtml(rv.reward_title || '-')}</div>
            <div><strong>Coupon Code:</strong> <br><code style="background:#ede9fe; color:#6d28d9; padding:2px 6px; border-radius:4px; font-weight:700;">${escapeHtml(rv.coupon_code || '-')}</code></div>
            <div><strong>Valid Till:</strong> <br>${rv.valid_till ? escapeHtml(rv.valid_till) : '<span style="color:#94a3b8;">Permanent</span>'}</div>
            <div><strong>Created:</strong> <br>${escapeHtml(rv.created_at || '-')} by ${escapeHtml(rv.created_by || 'admin')}</div>
        </div>
        <div style="margin-bottom:12px;">
            <div style="font-weight:700; color:#475569; margin-bottom:4px;">Description:</div>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px;">${escapeHtml(rv.reward_description || '-')}</div>
        </div>
        <div style="margin-bottom:12px;">
            <div style="font-weight:700; color:#475569; margin-bottom:4px;">Instructions:</div>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; max-height:120px; overflow-y:auto;">${rv.instructions || '<span style="color:#94a3b8;">None</span>'}</div>
        </div>
        <div style="margin-bottom:12px;">
            <div style="font-weight:700; color:#475569; margin-bottom:4px;">Terms & Conditions:</div>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; max-height:120px; overflow-y:auto;">${rv.terms || '<span style="color:#94a3b8;">None</span>'}</div>
        </div>
        <div style="margin-bottom:12px;">
            <div style="font-weight:700; color:#475569; margin-bottom:4px;">Claim Success Message:</div>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; max-height:120px; overflow-y:auto;">${rv.claim_message || '<span style="color:#94a3b8;">None</span>'}</div>
        </div>
    `;
    document.getElementById('modalVersionContent').innerHTML = html;
    document.getElementById('versionModal').style.display = 'flex';
}

function closeVersionModal() {
    document.getElementById('versionModal').style.display = 'none';
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
}

// Synchronize toggle banner state on switch change
const rewardToggle = document.getElementById('reward_active');
const rewardBanner = document.querySelector('.reward-toggle-banner');
if (rewardToggle && rewardBanner) {
    const updateToggleState = () => {
        if (rewardToggle.checked) {
            rewardBanner.classList.add('is-active');
        } else {
            rewardBanner.classList.remove('is-active');
        }
    };
    rewardToggle.addEventListener('change', updateToggleState);
    updateToggleState();
}

// Auto-switch to settings tab if URL hash is present
if (window.location.hash === '#settings') switchTab('settings');
</script>

<?php include 'includes/admin_footer.php'; ?>
