<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

$templateName = 'pepp_admission_rejected';
$bodyText = "Admission Update\n\nHello {{1}},\n\nThank you for applying to the {{2}} entrance coaching programme for the {{3}} batch.\n\nAfter reviewing your application, we regret to inform you that your admission request has not been approved at this time.\n\nIf you believe this was due to an error or if you need clarification, please contact the PEPP Learning admission team.\n\nWe wish you all the best in your academic journey.\n\nBest regards,\nPEPP Learning";

$metaData = json_encode([
    "body_text" => $bodyText,
    "components" => [
        [
            "type" => "BODY",
            "text" => $bodyText
        ]
    ]
]);

try {
    $stmt = $pdo->prepare("
        INSERT INTO communication_templates (channel, template_name, language, status, category, meta_data, updated_at)
        VALUES ('whatsapp', ?, 'en', 'approved', 'UTILITY', ?, NOW())
        ON DUPLICATE KEY UPDATE status = 'approved', meta_data = ?, updated_at = NOW()
    ");
    $stmt->execute([$templateName, $metaData, $metaData]);
    echo "Successfully seeded pepp_admission_rejected template into database.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
