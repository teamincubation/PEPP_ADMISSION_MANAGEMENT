<?php
/**
 * PEPP Updates Public Portal — WhatsApp Subscription Page
 *
 * Route: /subscribe or /subscribe.php
 *
 * Implements subscriber opt-in and category selection.
 * STRICT SAFETY RULE:
 * - NO WhatsApp message dispatch in Phase 3
 * - NO Meta Graph API calls
 * - NO queueing into communication_queue or whatsapp_messages
 * - Records consent audit trail in updates_subscriber_events with hashed IP
 */

require_once __DIR__ . '/includes/bootstrap.php';

$successMessage = '';
$errorMessage   = '';
$formData = [
    'name'       => '',
    'phone'      => '',
    'categories' => [],
];

// Fetch active categories
$categories = pepp_public_get_categories($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $rawPhone = trim($_POST['phone'] ?? '');
    $catIds   = array_map('intval', $_POST['categories'] ?? []);
    $consent  = !empty($_POST['consent']);

    $formData['name']       = $name;
    $formData['phone']      = $rawPhone;
    $formData['categories'] = $catIds;

    // Standard 10-digit mobile number input
    $cleanDigits = preg_replace('/[^0-9]/', '', $rawPhone);
    if (strlen($cleanDigits) === 11 && str_starts_with($cleanDigits, '0')) {
        $cleanDigits = substr($cleanDigits, 1);
    } elseif (strlen($cleanDigits) === 12 && str_starts_with($cleanDigits, '91')) {
        $cleanDigits = substr($cleanDigits, 2);
    }

    if (strlen($cleanDigits) !== 10 || !preg_match('/^[6-9][0-9]{9}$/', $cleanDigits)) {
        $errorMessage = 'Please enter a valid standard 10-digit mobile number (e.g. 9876543210).';
    } elseif (!$consent) {
        $errorMessage = 'Please check the consent box to receive WhatsApp updates.';
    } elseif (empty($catIds)) {
        $errorMessage = 'Please select at least one educational category you are interested in.';
    } else {
        $cleanPhone = '91' . $cleanDigits;
        try {
            $pdo->beginTransaction();

            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

            // Check if subscriber already exists
            $stmtSub = $pdo->prepare("SELECT id, status, name FROM updates_subscribers WHERE phone = ? LIMIT 1");
            $stmtSub->execute([$cleanPhone]);
            $existingSub = $stmtSub->fetch(PDO::FETCH_ASSOC);

            if ($existingSub) {
                $subscriberId = (int)$existingSub['id'];
                $currentStatus = $existingSub['status'] ?? 'active';

                if ($currentStatus === 'active') {
                    // Prevent duplicate subscription & show clear polite error message
                    $pdo->rollBack();
                    $errorMessage = 'This mobile number (' . htmlspecialchars($cleanDigits) . ') is already subscribed to PEPP Updates on WhatsApp. Your subscription is active, and you are already receiving our latest alerts.';
                } else {
                    // Existing subscriber had stopped / unsubscribed -> handle re-subscription smoothly
                    $stmtUpd = $pdo->prepare("
                        UPDATE updates_subscribers
                        SET name = COALESCE(NULLIF(?, ''), name),
                            status = 'active',
                            stopped_at = NULL,
                            updated_at = {$nowSql}
                        WHERE id = ?
                    ");
                    $stmtUpd->execute([$name ?: null, $subscriberId]);

                    // Sync subscriber categories
                    $stmtDelCats = $pdo->prepare("DELETE FROM updates_subscriber_categories WHERE subscriber_id = ?");
                    $stmtDelCats->execute([$subscriberId]);

                    $stmtInsCat = $pdo->prepare("INSERT INTO updates_subscriber_categories (subscriber_id, category_id) VALUES (?, ?)");
                    foreach ($catIds as $cId) {
                        $stmtCatChk = $pdo->prepare("SELECT id FROM updates_categories WHERE id = ? AND is_active = 1 LIMIT 1");
                        $stmtCatChk->execute([$cId]);
                        if ($stmtCatChk->fetchColumn()) {
                            $stmtInsCat->execute([$subscriberId, $cId]);
                        }
                    }

                    // Record audit event: RESUBSCRIBED
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $today = date('Y-m-d');
                    $ipHash = hash('sha256', $ip . '_' . $today . '_sub_salt');

                    $stmtEvent = $pdo->prepare("
                        INSERT INTO updates_subscriber_events (subscriber_id, event_type, details, source, ip_hash, created_at)
                        VALUES (?, 'RESUBSCRIBED', ?, 'web', ?, {$nowSql})
                    ");
                    $detailsJson = json_encode(['categories' => $catIds, 'name' => $name, 'reactivated' => true]);
                    $stmtEvent->execute([$subscriberId, $detailsJson, $ipHash]);

                    $pdo->commit();
                    $successMessage = 'Welcome back! Your WhatsApp subscription to PEPP Updates has been successfully reactivated.';
                    $formData = ['name' => '', 'phone' => '', 'categories' => []];
                }
            } else {
                // Brand new subscription
                $stmtIns = $pdo->prepare("
                    INSERT INTO updates_subscribers (phone, name, status, preferred_language, subscribed_at, created_at, updated_at)
                    VALUES (?, ?, 'active', 'en', {$nowSql}, {$nowSql}, {$nowSql})
                ");
                $stmtIns->execute([$cleanPhone, $name ?: null]);
                $subscriberId = (int)$pdo->lastInsertId();

                // Sync subscriber categories
                $stmtInsCat = $pdo->prepare("INSERT INTO updates_subscriber_categories (subscriber_id, category_id) VALUES (?, ?)");
                foreach ($catIds as $cId) {
                    $stmtCatChk = $pdo->prepare("SELECT id FROM updates_categories WHERE id = ? AND is_active = 1 LIMIT 1");
                    $stmtCatChk->execute([$cId]);
                    if ($stmtCatChk->fetchColumn()) {
                        $stmtInsCat->execute([$subscriberId, $cId]);
                    }
                }

                // Record audit event: SUBSCRIBED
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $today = date('Y-m-d');
                $ipHash = hash('sha256', $ip . '_' . $today . '_sub_salt');

                $stmtEvent = $pdo->prepare("
                    INSERT INTO updates_subscriber_events (subscriber_id, event_type, details, source, ip_hash, created_at)
                    VALUES (?, 'SUBSCRIBED', ?, 'web', ?, {$nowSql})
                ");
                $detailsJson = json_encode(['categories' => $catIds, 'name' => $name]);
                $stmtEvent->execute([$subscriberId, $detailsJson, $ipHash]);

                $pdo->commit();
                $successMessage = 'Thank you! You have successfully subscribed to PEPP Updates on WhatsApp.';
                $formData = ['name' => '', 'phone' => '', 'categories' => []];
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Subscribe error: ' . $e->getMessage());
            $errorMessage = 'An error occurred while saving your subscription. Please try again later.';
        }
    }
}

// SEO
$pageSeo = [
    'title'       => 'Get Updates on WhatsApp — PEPP Updates',
    'description' => 'Subscribe to official PEPP Learning educational notifications, entrance exam alerts, and university admission announcements on WhatsApp.',
    'canonical'   => pepp_seo_get_base_url() . '/subscribe',
    'og_type'     => 'website',
];

$activeNav = 'subscribe';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding-top:2.5rem;padding-bottom:4rem;">
    <!-- Breadcrumbs -->
    <nav class="breadcrumbs" aria-label="Breadcrumbs">
        <a href="/">Home</a>
        <span>/</span>
        <span style="color:var(--foreground);">Subscribe on WhatsApp</span>
    </nav>

    <div class="subscribe-card">
        <div style="text-align:center;margin-bottom:2rem;">
            <div style="width:54px;height:54px;background:#ecfdf5;color:#10b981;border-radius:var(--radius-full);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
            </div>
            <h1 style="font-size:1.75rem;font-weight:800;color:var(--foreground);line-height:1.2;margin-bottom:0.5rem;">
                Get PEPP Updates on WhatsApp
            </h1>
            <p style="font-size:0.95rem;color:var(--secondary);line-height:1.5;">
                Stay updated on entrance exams, university admissions, and career notifications. Pick your topics and receive verified alerts.
            </p>
        </div>

        <?php if ($successMessage): ?>
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:1.25rem;border-radius:var(--radius);margin-bottom:2rem;text-align:center;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-bottom:0.5rem;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <h3 style="font-size:1.1rem;font-weight:800;margin-bottom:0.25rem;">Subscribed Successfully!</h3>
                <p style="font-size:0.9rem;"><?php echo htmlspecialchars($successMessage); ?></p>
                <div style="margin-top:1.25rem;">
                    <a href="/" class="btn-article-action" style="padding:8px 20px;font-size:0.9rem;">Browse Latest Updates</a>
                </div>
            </div>
        <?php else: ?>

            <?php if ($errorMessage): ?>
                <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:1.5rem;font-size:0.9rem;font-weight:600;">
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <form action="/subscribe" method="POST" novalidate>
                <!-- Name -->
                <div class="form-group">
                    <label class="form-label" for="subName">Your Name (Optional)</label>
                    <input type="text" id="subName" name="name" class="form-control" value="<?php echo htmlspecialchars($formData['name']); ?>" placeholder="e.g. Rahul Sharma">
                </div>

                <!-- Phone -->
                <div class="form-group">
                    <label class="form-label" for="subPhone">WhatsApp Mobile Number <span style="color:#ef4444;">*</span></label>
                    <input type="tel" id="subPhone" name="phone" class="form-control" value="<?php echo htmlspecialchars($formData['phone']); ?>" placeholder="10-digit mobile number (e.g. 9876543210)" pattern="[6-9][0-9]{9}" maxlength="10" inputmode="numeric" required>
                    <small style="display:block;font-size:0.78rem;color:var(--muted);margin-top:4px;">Enter standard 10-digit mobile number without country code (e.g. 9876543210).</small>
                </div>

                <!-- Category Checkboxes -->
                <div class="form-group" style="margin-top:1.5rem;">
                    <label class="form-label">Select Topics of Interest <span style="color:#ef4444;">*</span></label>
                    <div class="category-checkboxes">
                        <?php foreach ($categories as $cat): ?>
                            <?php $checked = in_array((int)$cat['id'], $formData['categories'], true); ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="categories[]" value="<?php echo (int)$cat['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($cat['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Consent Checkbox -->
                <div class="form-group" style="margin-top:1.5rem;">
                    <label class="checkbox-label" style="align-items:flex-start;padding:10px;">
                        <input type="checkbox" name="consent" value="1" required style="margin-top:3px;">
                        <span style="font-size:0.82rem;line-height:1.45;color:var(--secondary);">
                            I consent to receive educational notifications and admission alerts from PEPP Learning on WhatsApp. You can unsubscribe anytime by replying STOP.
                        </span>
                    </label>
                </div>

                <!-- Submit Button -->
                <div style="margin-top:2rem;">
                    <button type="submit" class="btn-whatsapp-large" style="width:100%;justify-content:center;padding:12px;font-size:1.05rem;border:none;cursor:pointer;background:#10b981;color:#ffffff !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                        <span>Confirm WhatsApp Subscription</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
