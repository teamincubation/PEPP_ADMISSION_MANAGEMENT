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

// ── AJAX: Trigger manual birthday send ──────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'manual_birthday_send') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['error' => 'Security token mismatch.']);
        exit;
    }
    if (!is_super_admin()) {
        echo json_encode(['error' => 'Only super admins can manually trigger birthday sends.']);
        exit;
    }
    $studentId = trim($_POST['student_id'] ?? '');
    if (empty($studentId)) {
        echo json_encode(['error' => 'Missing student ID.']);
        exit;
    }

    require_once 'includes/birthday_scheduler.php';
    require_once 'includes/communication/CommunicationEngine.php';

    $todayStr = date('Y-m-d');
    $commEngine = CommunicationEngine::getInstance($pdo);

    $activeYear = get_birthday_active_academic_year($pdo);
    if (!$activeYear) {
        echo json_encode(['error' => 'No active academic year found.']);
        exit;
    }

    // Fetch student details
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

    $personIdentity = resolve_person_identity($student);
    if (!$personIdentity) {
        echo json_encode(['error' => 'Student has no valid contact details (phone or email).']);
        exit;
    }

    // Verify canonical record status
    $canonical = get_canonical_student_record($personIdentity, $pdo, $activeYear);
    if (!$canonical || $canonical['user_id'] !== $studentId) {
        echo json_encode(['error' => 'Only the canonical student record (' . ($canonical['user_id'] ?? 'unknown') . ') can receive birthday notifications for this person.']);
        exit;
    }

    // Check idempotency by (person_identity, birthday_date)
    $stmt = $pdo->prepare("SELECT status FROM birthday_notifications_sent WHERE person_identity = ? AND birthday_date = ?");
    $stmt->execute([$personIdentity, $todayStr]);
    $existing = $stmt->fetchColumn();
    if ($existing === 'queued' || $existing === 'sent') {
        echo json_encode(['error' => 'Birthday notification already sent for this person today.']);
        exit;
    }

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
        $insertStmt = $pdo->prepare("
            {$insertIgnore} birthday_notifications_sent (person_identity, student_id, birthday_date, status, created_at)
            VALUES (?, ?, ?, 'queued', NOW())
        ");
        $insertStmt->execute([$personIdentity, $studentId, $todayStr]);

        if ($insertStmt->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Birthday notification already exists for this person today.']);
            exit;
        }

        $hmac = hash_hmac('sha256', $studentId, BIRTHDAY_CLAIM_HMAC_SECRET);
        $context = [
            'student_uid'  => $studentId,
            'student_name' => $student['name'] ?? 'Student',
            'claim_url'    => "https://pepplearning.in/admissions/birthday-rewards.php/{$studentId}?token={$hmac}"
        ];

        $queueId = $commEngine->sendEventNotification('birthday_greeting', $waPhone, $context, 'system_scheduler');

        if ($queueId) {
            $pdo->prepare("UPDATE birthday_notifications_sent SET queue_id = ? WHERE person_identity = ? AND birthday_date = ?")
                ->execute([$queueId, $personIdentity, $todayStr]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Birthday greeting queued successfully.', 'queue_id' => $queueId]);
        } else {
            $pdo->prepare("UPDATE birthday_notifications_sent SET status = 'failed' WHERE person_identity = ? AND birthday_date = ?")
                ->execute([$personIdentity, $todayStr]);
            $pdo->commit();
            echo json_encode(['error' => 'Failed to queue birthday notification. Check template mapping.']);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
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
        $instructions  = trim($_POST['instructions'] ?? '');
        $terms         = trim($_POST['terms'] ?? '');
        $claimMessage  = trim($_POST['claim_message'] ?? '');
        $isActive      = isset($_POST['is_active']) ? 1 : 0;

        // Fetch current settings for old image paths
        $stmt = $pdo->prepare("SELECT * FROM birthday_reward_settings ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $currentSettings = $stmt->fetch(PDO::FETCH_ASSOC);

        // Handle image uploads
        $headerImage = handle_file_upload_with_replace('birthday_header_image', 'birthday', $currentSettings['birthday_header_image'] ?? null, ['jpg', 'jpeg', 'png', 'webp']);
        $voucherImage = handle_file_upload_with_replace('reward_voucher_image', 'birthday', $currentSettings['reward_voucher_image'] ?? null, ['jpg', 'jpeg', 'png', 'webp']);

        try {
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

        // Fetch sent notifications and claims for today by person_identity
        if (!empty($todayBirthdays)) {
            $sentStmt = $pdo->prepare("SELECT person_identity, status, queue_id FROM birthday_notifications_sent WHERE birthday_date = ?");
            $sentStmt->execute([$todayStr]);
            $sentMap = [];
            while ($row = $sentStmt->fetch(PDO::FETCH_ASSOC)) {
                $sentMap[$row['person_identity']] = $row;
            }

            $claimStmt = $pdo->prepare("SELECT person_identity, claimed_at FROM birthday_reward_claims WHERE birthday_date = ?");
            $claimStmt->execute([$todayStr]);
            $claimMap = [];
            while ($row = $claimStmt->fetch(PDO::FETCH_ASSOC)) {
                $claimMap[$row['person_identity']] = $row;
            }

            foreach ($todayBirthdays as &$bday) {
                $pid = $bday['person_identity'];
                $bday['notification_status'] = $sentMap[$pid]['status'] ?? null;
                $bday['queue_id'] = $sentMap[$pid]['queue_id'] ?? null;
                $bday['claimed_at'] = $claimMap[$pid]['claimed_at'] ?? null;
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

// Notification & claim stats
$totalSent = 0;
$totalClaimed = 0;
try {
    $totalSent = (int)$pdo->query("SELECT COUNT(*) FROM birthday_notifications_sent WHERE status IN ('queued','sent')")->fetchColumn();
    $totalClaimed = (int)$pdo->query("SELECT COUNT(*) FROM birthday_reward_claims")->fetchColumn();
} catch (Exception $e) {}

$active_page = 'student-birthdays';
$page_title  = 'Student Birthdays';
$page_sub    = 'Birthday greetings & reward management';
include 'includes/admin_nav.php';
?>

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
.bday-badge-pending { background: rgba(234,179,8,0.12); color: #d97706; }
.bday-badge-claimed { background: rgba(139,92,246,0.12); color: #8b5cf6; }
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
.image-preview { max-width: 200px; border-radius: 8px; margin-top: 8px; }
.toggle-switch { display: flex; align-items: center; gap: 10px; }
.toggle-switch input[type="checkbox"] { width: 40px; height: 22px; appearance: none; background: #d1d5db; border-radius: 11px; position: relative; cursor: pointer; transition: 0.3s; }
.toggle-switch input[type="checkbox"]:checked { background: #8b5cf6; }
.toggle-switch input[type="checkbox"]::before { content: ''; position: absolute; width: 18px; height: 18px; border-radius: 50%; background: #fff; top: 2px; left: 2px; transition: 0.3s; }
.toggle-switch input[type="checkbox"]:checked::before { left: 20px; }
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
                        if ($ns === 'sent' || $ns === 'queued'):
                        ?>
                            <span class="bday-badge bday-badge-sent"><i class="fas fa-check"></i> Sent</span>
                        <?php elseif ($ns === 'failed'): ?>
                            <span class="bday-badge bday-badge-failed"><i class="fas fa-times"></i> Failed</span>
                        <?php else: ?>
                            <span class="bday-badge bday-badge-pending"><i class="fas fa-clock"></i> Pending</span>
                        <?php endif; ?>

                        <?php if ($bday['claimed_at']): ?>
                            <span class="bday-badge bday-badge-claimed"><i class="fas fa-gift"></i> Claimed <?php echo date('h:i A', strtotime($bday['claimed_at'])); ?></span>
                        <?php endif; ?>

                        <?php if (is_super_admin() && !$ns): ?>
                            <button class="send-btn" onclick="sendBirthdayManual('<?php echo e($bday['user_id']); ?>', this)">
                                <i class="fas fa-paper-plane"></i> Send
                            </button>
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
        <?php csrf_field(); ?>
        <input type="hidden" name="save_birthday_settings" value="1">

        <div class="settings-section">
            <h3><i class="fas fa-gift"></i> Birthday Reward Configuration</h3>

            <div class="toggle-switch" style="margin-bottom:16px;">
                <input type="checkbox" name="is_active" id="reward_active" <?php echo ($settings['is_active'] ?? 0) ? 'checked' : ''; ?>>
                <label for="reward_active" style="font-weight:600; font-size:0.85rem; cursor:pointer;">Enable Birthday Reward System</label>
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

            <div class="form-group" style="margin-bottom:16px;">
                <label>Instructions (shown to student)</label>
                <textarea name="instructions" placeholder="How to redeem the reward..."><?php echo e($settings['instructions'] ?? ''); ?></textarea>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label>Terms & Conditions</label>
                <textarea name="terms" placeholder="Terms and conditions..."><?php echo e($settings['terms'] ?? ''); ?></textarea>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label>Custom Claim Success Message</label>
                <textarea name="claim_message" placeholder="Shown after the student claims the reward..."><?php echo e($settings['claim_message'] ?? ''); ?></textarea>
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
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.section-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
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
            btn.outerHTML = '<span class="bday-badge bday-badge-sent"><i class="fas fa-check"></i> Sent</span>';
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

// Auto-switch to settings tab if URL hash is present
if (window.location.hash === '#settings') switchTab('settings');
</script>

<?php include 'includes/admin_footer.php'; ?>
