<?php
require_once 'config/database.php';
$stmt = $pdo->query("SELECT template_name, meta_data FROM communication_templates");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "Template: " . $row['template_name'] . "\n";
    echo "Meta: " . $row['meta_data'] . "\n\n";
}
exit;
