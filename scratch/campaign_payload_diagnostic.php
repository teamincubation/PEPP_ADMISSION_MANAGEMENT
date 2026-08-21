<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT * FROM communication_campaigns WHERE name = ? LIMIT 1");
    $stmt->execute(['Sample Test']);
    $camp = $stmt->fetch(PDO::FETCH_ASSOC);

    $recipients = [];
    $queue_items = [];

    if ($camp) {
        $stmtRec = $pdo->prepare("SELECT * FROM communication_campaign_recipients WHERE campaign_id = ?");
        $stmtRec->execute([$camp['id']]);
        $recipients = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

        $queueIds = array_filter(array_map(function($r) { return $r['queue_id']; }, $recipients));
        if (!empty($queueIds)) {
            $inQuery = implode(',', array_map('intval', $queueIds));
            $queue_items = $pdo->query("SELECT * FROM communication_queue WHERE id IN ($inQuery)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($queue_items as &$item) {
                $item['template_data_decoded'] = json_decode($item['template_data'], true);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'campaign' => $camp,
        'recipients' => $recipients,
        'queue_items' => $queue_items
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
