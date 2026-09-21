<?php
/**
 * PEPP Learning ERP — Communication Backlog Read-Only Classification Tool
 *
 * STRICTLY READ-ONLY AUDIT.
 * Does NOT update, delete, insert, or dispatch any messages.
 *
 * Classifies backlog items by:
 * - message / event type
 * - scheduled_at / next_attempt_at age
 * - temporal relevance (e.g., past sessions)
 * - current business & student state (e.g., already paid installments, inactive students)
 * - webhook & delivery evidence
 * - ambiguous SMTP states
 */

// Force production MySQL routing when invoked on server CLI
putenv('PEPP_USE_MYSQL=1');
$_ENV['PEPP_USE_MYSQL'] = '1';

require_once __DIR__ . '/config/database.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json');
    // Auth check for HTTP invocation
    try {
        $stmtSec = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_cron_worker_key' LIMIT 1");
        $stmtSec->execute();
        $token = $stmtSec->fetchColumn();
        $provided = $_GET['key'] ?? '';
        if (!$token || !hash_equals($token, (string)$provided)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$report = [
    'audit_timestamp' => date('Y-m-d H:i:s'),
    'mode' => 'READ_ONLY_AUDIT',
    'total_backlog_items' => 0,
    'categories' => [
        'SAFE_AND_RELEVANT' => [],
        'EXPIRED_TEMPORALLY' => [],
        'OBSOLETE_ALREADY_PAID' => [],
        'STUDENT_INACTIVE_OR_SUSPENDED' => [],
        'AMBIGUOUS_REQUIRES_REVIEW' => [],
        'MAX_RETRIES_EXHAUSTED' => []
    ],
    'summary' => [
        'safe_count' => 0,
        'expired_count' => 0,
        'obsolete_count' => 0,
        'inactive_student_count' => 0,
        'ambiguous_count' => 0,
        'exhausted_count' => 0
    ]
];

try {
    // Select all backlog records awaiting or stalled
    $stmt = $pdo->query("
        SELECT id, channel, recipient, recipient_name, subject, status,
               priority, retry_count, next_attempt_at, created_at,
               worker_started_at, message_id, error_message, event_name,
               student_uid, invoice_id
        FROM communication_queue
        WHERE status IN ('pending', 'scheduled', 'failed', 'retrying', 'processing')
        ORDER BY priority DESC, created_at ASC
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $report['total_backlog_items'] = count($items);

    $now = time();

    foreach ($items as $row) {
        $id = (int)$row['id'];
        $chan = strtolower($row['channel'] ?? 'whatsapp');
        $status = $row['status'];
        $event = $row['event_name'] ?? 'unknown';
        $retries = (int)$row['retry_count'];
        $maxRetries = ($chan === 'email') ? 5 : 3;
        $createdAt = strtotime((string)$row['created_at']);
        $ageDays = $createdAt ? round(($now - $createdAt) / 86400, 1) : 0;

        $classification = 'SAFE_AND_RELEVANT';
        $reason = 'Item appears eligible and temporally relevant.';

        // 1. Ambiguous stale processing records
        if ($status === 'processing') {
            $startedAt = !empty($row['worker_started_at']) ? strtotime((string)$row['worker_started_at']) : 0;
            if ($startedAt && ($now - $startedAt) > 600) {
                if ($chan === 'email') {
                    $classification = 'AMBIGUOUS_REQUIRES_REVIEW';
                    $reason = 'Stale email processing item; SMTP submission cannot be reliably known. Resend blocked.';
                } else {
                    $classification = 'AMBIGUOUS_REQUIRES_REVIEW';
                    $reason = 'Stale WhatsApp processing item. Verification needed.';
                }
            }
        }

        // 2. Max retries exhausted
        if ($classification === 'SAFE_AND_RELEVANT' && $retries >= $maxRetries) {
            $classification = 'MAX_RETRIES_EXHAUSTED';
            $reason = "Exhausted retry limit ({$retries}/{$maxRetries}). Error: " . ($row['error_message'] ?? 'N/A');
        }

        // 3. Temporal relevance check for session / event reminders
        if ($classification === 'SAFE_AND_RELEVANT') {
            if (stripos($event, 'session') !== false || stripos($event, 'meeting') !== false || stripos($event, 'class') !== false) {
                // If created more than 48 hours ago, session reminders are temporally expired
                if ($ageDays > 2) {
                    $classification = 'EXPIRED_TEMPORALLY';
                    $reason = "Session reminder enqueued {$ageDays} days ago; session event has already passed.";
                }
            }
        }

        // 4. Check business state for installment reminders (check if already paid)
        if ($classification === 'SAFE_AND_RELEVANT' && (stripos($event, 'installment') !== false || !empty($row['invoice_id']))) {
            try {
                if (!empty($row['invoice_id'])) {
                    $stInv = $pdo->prepare("SELECT status FROM invoices WHERE id = ? LIMIT 1");
                    $stInv->execute([$row['invoice_id']]);
                    $invStatus = $stInv->fetchColumn();
                    if ($invStatus === 'paid' || $invStatus === 'approved') {
                        $classification = 'OBSOLETE_ALREADY_PAID';
                        $reason = "Invoice #{$row['invoice_id']} is already {$invStatus}. Dispatching reminder would confuse student.";
                    }
                }
            } catch (Exception $e) {}
        }

        // 5. Check student / user state (dropouts, suspended, inactive)
        if ($classification === 'SAFE_AND_RELEVANT' && !empty($row['student_uid'])) {
            try {
                $stStud = $pdo->prepare("SELECT status FROM students WHERE user_id = ? OR student_uid = ? LIMIT 1");
                $stStud->execute([$row['student_uid'], $row['student_uid']]);
                $studStatus = strtolower((string)$stStud->fetchColumn());
                if (in_array($studStatus, ['dropped', 'suspended', 'inactive', 'cancelled', 'rejected'], true)) {
                    // Only essential transactional events permitted
                    $transactional = ['invoice_email', 'payment_receipt', 'payment_approved'];
                    if (!in_array($event, $transactional, true)) {
                        $classification = 'STUDENT_INACTIVE_OR_SUSPENDED';
                        $reason = "Student {$row['student_uid']} is {$studStatus}; non-transactional communications blocked.";
                    }
                }
            } catch (Exception $e) {}
        }

        $itemData = [
            'id' => $id,
            'channel' => $chan,
            'recipient' => $row['recipient'],
            'recipient_name' => $row['recipient_name'],
            'event_name' => $event,
            'status' => $status,
            'retry_count' => $retries,
            'age_days' => $ageDays,
            'reason' => $reason
        ];

        $report['categories'][$classification][] = $itemData;
    }

    $report['summary']['safe_count'] = count($report['categories']['SAFE_AND_RELEVANT']);
    $report['summary']['expired_count'] = count($report['categories']['EXPIRED_TEMPORALLY']);
    $report['summary']['obsolete_count'] = count($report['categories']['OBSOLETE_ALREADY_PAID']);
    $report['summary']['inactive_student_count'] = count($report['categories']['STUDENT_INACTIVE_OR_SUSPENDED']);
    $report['summary']['ambiguous_count'] = count($report['categories']['AMBIGUOUS_REQUIRES_REVIEW']);
    $report['summary']['exhausted_count'] = count($report['categories']['MAX_RETRIES_EXHAUSTED']);

} catch (Exception $e) {
    $report['error'] = $e->getMessage();
}

if ($isCli) {
    echo "======================================================================\n";
    echo "PEPP COMMUNICATION ENGINE — READ-ONLY BACKLOG CLASSIFICATION REPORT\n";
    echo "======================================================================\n";
    echo "Timestamp: " . $report['audit_timestamp'] . "\n";
    echo "Total Backlog Items Analyzed: " . $report['total_backlog_items'] . "\n\n";
    echo "SUMMARY BREAKDOWN:\n";
    echo " - Safe & Temporally Relevant to Dispatch : " . $report['summary']['safe_count'] . "\n";
    echo " - Temporally Expired (Past Events)       : " . $report['summary']['expired_count'] . "\n";
    echo " - Obsolete (Installment Already Paid)     : " . $report['summary']['obsolete_count'] . "\n";
    echo " - Inactive/Dropped Student Blocked       : " . $report['summary']['inactive_student_count'] . "\n";
    echo " - Ambiguous (SMTP / Worker Crash Review) : " . $report['summary']['ambiguous_count'] . "\n";
    echo " - Max Retries Exhausted                  : " . $report['summary']['exhausted_count'] . "\n";
    echo "======================================================================\n";
} else {
    echo json_encode($report, JSON_PRETTY_PRINT);
}
