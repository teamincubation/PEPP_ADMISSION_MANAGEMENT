<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

require_once 'config/database.php';

$res = [];
try {
    $sql = file_get_contents('database-update-21.sql');
    if (!$sql) {
        throw new Exception("SQL file not found.");
    }
    
    $pdo->exec($sql);
    $res['migration'] = 'success';
} catch (Exception $e) {
    $res['migration'] = 'failed';
    $res['error'] = $e->getMessage();
}

// Verify tables
$tables = ['ld_work_courses', 'ld_work_modes', 'ld_tasks', 'ld_task_topics', 'ld_task_audit'];
$res['tables'] = [];
foreach ($tables as $t) {
    try {
        $has = $pdo->query("SHOW TABLES LIKE '$t'")->fetchColumn();
        $res['tables'][$t] = $has ? 'exists' : 'missing';
    } catch (Exception $e) {
        $res['tables'][$t] = 'error: ' . $e->getMessage();
    }
}

// Verify foreign keys and indexes for ld_task_topics
try {
    $stmt = $pdo->query("SHOW CREATE TABLE ld_task_topics");
    $create_sql = $stmt->fetchColumn();
    $res['task_topics_schema'] = $create_sql;
} catch (Exception $e) {
    $res['task_topics_schema'] = 'error: ' . $e->getMessage();
}

echo json_encode($res, JSON_PRETTY_PRINT);
