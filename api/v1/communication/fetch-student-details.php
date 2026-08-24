<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_permission('communication');

header('Content-Type: application/json');

$studentUid = $_GET['student_uid'] ?? '';

if (empty($studentUid)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing student_uid']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$studentUid]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        http_response_code(404);
        echo json_encode(['error' => 'Student not found']);
        exit;
    }

    $collected = (float)($student['paid_amount'] ?? 0);
    try {
        $instStmt = $pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(paid_amount, amount)), 0)
            FROM instalment_details 
            WHERE user_id = ? AND status IN ('approved', 'paid')
        ");
        $instStmt->execute([$student['user_id']]);
        $collected += (float)$instStmt->fetchColumn();
    } catch (Exception $e) {}

    $nextDueDate = 'N/A';
    try {
        $dueStmt = $pdo->prepare("
            SELECT due_date FROM instalment_details 
            WHERE user_id = ? AND status = 'pending' AND due_date >= CURRENT_DATE
            ORDER BY instalment_number ASC LIMIT 1
        ");
        $dueStmt->execute([$student['user_id']]);
        $dueDateVal = $dueStmt->fetchColumn();
        if ($dueDateVal) {
            $nextDueDate = date('d M Y', strtotime($dueDateVal));
        }
    } catch (Exception $e) {}

    $totalPayable = (float)($student['total_fee'] ?? 0);
    $balance = max(0, $totalPayable - $collected);

    // Fetch active/pending/failed queue items for this student
    $queueItems = [];
    try {
        $qStmt = $pdo->prepare("
            SELECT id, event_name, status, retry_count, error_message, updated_at 
            FROM communication_queue 
            WHERE student_uid = ? AND status IN ('pending', 'failed', 'paused')
            ORDER BY id DESC
            LIMIT 5
        ");
        $qStmt->execute([$student['user_id']]);
        $queueItems = $qStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'student' => [
            'id' => $student['id'],
            'user_id' => $student['user_id'],
            'name' => $student['name'],
            'pepp_course' => $student['pepp_course'],
            'pepp_academic_year' => $student['pepp_academic_year'],
            'status' => $student['status'],
            'total_fee' => is_credential_restricted('financials') ? (($admin_credential_visibility ?? 'visible') === 'hide' ? 'x,xxx' : '***') : number_format($totalPayable),
            'total_paid' => is_credential_restricted('financials') ? (($admin_credential_visibility ?? 'visible') === 'hide' ? 'x,xxx' : '***') : number_format($collected),
            'balance' => is_credential_restricted('financials') ? (($admin_credential_visibility ?? 'visible') === 'hide' ? 'x,xxx' : '***') : number_format($balance),
            'next_due_date' => $nextDueDate
        ],
        'active_reminders' => $queueItems
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
