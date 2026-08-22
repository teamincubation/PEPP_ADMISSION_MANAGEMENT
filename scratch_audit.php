<?php
require_once 'config/database.php';
header('Content-Type: application/json');

try {
    // 1. Repeated failures to the same recipient
    $stmt1 = $pdo->query("
        SELECT recipient, COUNT(*) as fail_count, GROUP_CONCAT(id) as queue_ids, GROUP_CONCAT(DISTINCT error_message) as errors
        FROM communication_queue 
        WHERE status = 'failed'
        GROUP BY recipient
        HAVING fail_count > 1
        ORDER BY fail_count DESC
        LIMIT 50
    ");
    $repeatedFailures = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    // 2. Multiple queue IDs targeting the same permanently failed number
    $stmt2 = $pdo->query("
        SELECT recipient, COUNT(DISTINCT id) as queue_count, GROUP_CONCAT(id) as queue_ids
        FROM communication_queue 
        GROUP BY recipient
        HAVING queue_count > 1 AND SUM(CASE WHEN status = 'failed' AND (error_message LIKE '%131026%' OR error_message LIKE '%policy%') THEN 1 ELSE 0 END) > 0
        LIMIT 50
    ");
    $multipleQueueOnFailed = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // 3. Repeated installment reminder generation
    $stmt3 = $pdo->query("
        SELECT installment_id, reminder_stage, COUNT(*) as cnt, GROUP_CONCAT(status) as statuses
        FROM installment_whatsapp_reminders
        GROUP BY installment_id, reminder_stage
        HAVING cnt > 1
        LIMIT 50
    ");
    $repeatedInstallments = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    // 4. Stale recipient numbers
    $stmt4 = $pdo->query("
        SELECT q.id, q.student_uid, q.recipient, u.whatsapp_number, u.whatsapp_country_code
        FROM communication_queue q
        JOIN users u ON q.student_uid = u.user_id
        WHERE q.channel = 'whatsapp' AND q.status = 'pending'
          AND REPLACE(q.recipient, '+', '') != CONCAT(u.whatsapp_country_code, u.whatsapp_number)
        LIMIT 50
    ");
    $staleRecipients = $stmt4->fetchAll(PDO::FETCH_ASSOC);

    // 5. Failed queue items that are still eligible for processing
    $stmt5 = $pdo->query("
        SELECT COUNT(*) as cnt
        FROM communication_queue 
        WHERE status = 'failed' 
          AND next_attempt_at <= NOW() 
          AND (
            (channel = 'whatsapp' AND retry_count < 3) OR
            (channel = 'email' AND retry_count < 5)
          )
    ");
    $eligibleFailedCount = $stmt5->fetchColumn();

    echo json_encode([
        'success' => true,
        'repeated_failures' => $repeatedFailures,
        'multiple_queue_on_failed' => $multipleQueueOnFailed,
        'repeated_installments' => $repeatedInstallments,
        'stale_recipients' => $staleRecipients,
        'eligible_failed_count' => $eligibleFailedCount
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
