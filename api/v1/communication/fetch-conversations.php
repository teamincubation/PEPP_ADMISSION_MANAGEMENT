<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_permission('communication');

header('Content-Type: application/json');

$filter = $_GET['filter'] ?? 'all'; // all, unread, students, unknown
$search = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM whatsapp_conversations WHERE 1=1";
$params = [];

if ($filter === 'unread') {
    $sql .= " AND unread_count > 0";
} elseif ($filter === 'students') {
    $sql .= " AND student_uid IS NOT NULL";
} elseif ($filter === 'unknown') {
    $sql .= " AND student_uid IS NULL";
}

if ($search !== '') {
    $sql .= " AND (contact_name LIKE ? OR wa_phone_number LIKE ? OR student_uid LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY last_message_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'conversations' => $conversations]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
