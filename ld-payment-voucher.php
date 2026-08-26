<?php
require_once 'includes/auth.php';
require_permission('ld-work-report');

// Restrict intern access
if (get_admin_type() === 'intern') {
    http_response_code(403);
    die("Access denied. Financial data is restricted.");
}

require_once 'config/database.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    die("Invalid voucher ID.");
}

// Fetch payment record
$stmt = $pdo->prepare("
    SELECT p.*, a.account_name, a.account_type
    FROM ld_intern_payments p
    LEFT JOIN payment_accounts a ON a.id = p.payment_account_id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Voucher not found.");
}

// Fetch aggregated payment items strictly from the child table snapshot
$stmt = $pdo->prepare("
    SELECT work_mode_name_snapshot, quantity, quantity_label_snapshot
    FROM ld_intern_payment_items
    WHERE payment_id = ?
    ORDER BY work_mode_name_snapshot ASC
");
$stmt->execute([$id]);
$work_completed = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Voucher - <?php echo htmlspecialchars($payment['voucher_no']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #164e63;
            --primary-light: #ecfeff;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --bg-light: #f8fafc;
        }
        body {
            font-family: 'Outfit', sans-serif;
            color: var(--text-dark);
            margin: 0;
            padding: 40px;
            background-color: #ffffff;
        }
        .voucher-container {
            max-width: 700px;
            margin: 0 auto;
            border: 2px solid var(--primary);
            border-radius: 16px;
            padding: 40px;
            position: relative;
            background: #ffffff;
            box-shadow: 0 10px 25px rgba(22, 78, 99, 0.05);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid var(--border);
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .logo-section h1 {
            color: var(--primary);
            margin: 0;
            font-size: 1.8rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .logo-section p {
            margin: 4px 0 0 0;
            color: var(--text-muted);
            font-size: 0.85rem;
            font-weight: 500;
        }
        .voucher-title-section {
            text-align: right;
        }
        .voucher-title {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }
        .voucher-no {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 40px;
            background: var(--bg-light);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        .meta-item label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .meta-item span {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        .work-section {
            margin-bottom: 40px;
        }
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary);
            border-bottom: 1px solid var(--border);
            padding-bottom: 8px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .work-list {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }
        .work-list li {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.95rem;
            font-weight: 500;
        }
        .work-list li:last-child {
            border-bottom: none;
        }
        .work-mode-name {
            font-weight: 600;
            color: var(--text-dark);
        }
        .work-qty {
            background: var(--primary-light);
            color: var(--primary);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
        }
        .payout-section {
            background: var(--primary);
            color: #ffffff;
            padding: 24px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .payout-label {
            font-size: 1.1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .payout-amount {
            font-size: 1.8rem;
            font-weight: 700;
        }
        .footer-note {
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
            padding-top: 16px;
        }
        .actions-bar {
            max-width: 700px;
            margin: 20px auto 0 auto;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        @media print {
            body {
                padding: 0;
            }
            .voucher-container {
                box-shadow: none;
                border: 1px solid var(--primary);
            }
            .actions-bar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="voucher-container">
        <div class="header">
            <div class="logo-section">
                <h1>PEPP Learning</h1>
                <p>Academic &amp; Operations Support</p>
            </div>
            <div class="voucher-title-section">
                <div class="voucher-title">Payment Voucher</div>
                <div class="voucher-no"><?php echo htmlspecialchars($payment['voucher_no']); ?></div>
            </div>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <label>Intern Name</label>
                <span><?php echo htmlspecialchars($payment['intern_name_snapshot']); ?></span>
            </div>
            <div class="meta-item">
                <label>Date of Payout</label>
                <span><?php echo date('d-M-Y', strtotime($payment['paid_date'])); ?></span>
            </div>
            <div class="meta-item">
                <label>Payment Period</label>
                <span><?php echo date('d-M-Y', strtotime($payment['period_start_date'])) . ' to ' . date('d-M-Y', strtotime($payment['period_end_date'])); ?></span>
            </div>
            <div class="meta-item">
                <label>Paid Account</label>
                <span><?php echo htmlspecialchars($payment['payment_account_name_snapshot'] ?: 'N/A'); ?></span>
            </div>
        </div>

        <div class="work-section">
            <div class="section-title">
                <i class="fas fa-cubes"></i> Work Completed Summary
            </div>
            <ul class="work-list">
                <?php foreach ($work_completed as $wc): 
                    $mode_title = $wc['work_mode_name_snapshot'];
                    $qty_label = $wc['quantity_label_snapshot'] ?: 'units';
                ?>
                    <li>
                        <span class="work-mode-name"><?php echo htmlspecialchars($mode_title); ?></span>
                        <span class="work-qty"><?php echo (float)$wc['quantity'] . ' ' . htmlspecialchars($qty_label); ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($work_completed)): ?>
                    <li style="justify-content: center; color: var(--text-muted);">No recorded work logs for this period.</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="payout-section">
            <span class="payout-label">Total Amount Paid</span>
            <span class="payout-amount">₹<?php echo number_format($payment['paid_amount'], 2); ?></span>
        </div>

        <div class="footer-note">
            This is a computer-generated document. No signature is required.
        </div>
    </div>

    <div class="actions-bar">
        <button onclick="window.print()" style="padding: 10px 20px; font-family: inherit; font-size: 0.9rem; font-weight: 600; color: #fff; background: var(--primary); border: none; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 10px rgba(22, 78, 99, 0.15);">
            <i class="fas fa-print"></i> Print Voucher
        </button>
    </div>
</body>
</html>
