<?php
header('Content-Type: text/plain; charset=UTF-8');

try {
    require_once __DIR__ . '/config/database.php';
    if (!isset($pdo) && isset($conn)) {
        $pdo = $conn;
    }
    
    echo "DATABASE CONNECTION SUCCESSFUL\n\n";
    
    echo "ACADEMIC YEARS:\n";
    $stmt = $pdo->query("SELECT * FROM academic_years");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    
    echo "\nASSESSMENT RESULT BATCHES count for 2026-27:\n";
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assessment_result_batches WHERE academic_year = ?");
    $stmt->execute(['2026-27']);
    echo "Total: " . $stmt->fetchColumn() . "\n";
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assessment_result_batches WHERE academic_year = ? AND status = 'published'");
    $stmt->execute(['2026-27']);
    echo "Published: " . $stmt->fetchColumn() . "\n";
    
    $stmt = $pdo->prepare("SELECT id, activity_id, study_plan_id, academic_year, status, activity_title_snapshot, activity_date_snapshot, chapter_snapshot FROM assessment_result_batches WHERE academic_year = ? AND status = 'published'");
    $stmt->execute(['2026-27']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
