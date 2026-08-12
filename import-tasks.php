<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

require_once 'config/database.php';

// Embedded CSV data
$csv_data = <<<'CSV'
Username,L&D Work Course,Work Mode,Date,Topic Completed
juzaina,M. Clin Psy,Add New Study Materials,"09 Jun 2026, (Tue)",Classification of mental disorders (Updation)
juzaina,M. Clin Psy,Add New Study Materials,"10 Jun 2026, (Wed)",Terminologies in abnormal psychology (Uodation)
amilah,M. Clin Psy,Add New Study Materials,"16 Jun 2026, (Tue)",Parent management training
amilah,M. Clin Psy,Add New Study Materials,"17 Jun 2026, (Wed)","16 PF, IDEAS"
juzaina,M. Clin Psy,Add New Study Materials,"18 Jun 2026, (Thu)","ISAA, MHCA 2017, Rating scales"
juzaina,M. Clin Psy,Add New Study Materials,"19 Jun 2026, (Fri)","Relapse prevention, NNB 2004"
juzaina,M. Clin Psy,Add New Study Materials,"20 Jun 2026, (Sat)","NNB 2004, BKT, Bhatia"
juzaina,M. Clin Psy,Test,"20 Jun 2026, (Sat)",NIMHANS-2025 MODEL
juzaina,M. Clin Psy,Test,"20 Jun 2026, (Sat)",NIMHANS-2024 MODEL
juzaina,M. Clin Psy,Test,"20 Jun 2026, (Sat)",NIMHANS-2023 MODEL
juzaina,M. Clin Psy,Add New Study Materials,"12 Jun 2026, (Fri)",MOTIVATION (UPDATION)
juzaina,M. Clin Psy,Add New Study Materials,"05 Jun 2026, (Fri)",COGNITIVE BIAS
juzaina,M. Clin Psy,Add New Study Materials,"11 Jun 2026, (Thu)",PHYSIOLOGY OF EMOTION (UPDATION)
juzaina,M. Clin Psy,Add New Study Materials,"12 Jun 2026, (Fri)",Schedules of Reinforcement (UPDATION)
juzaina,M. Clin Psy,Add New Study Materials,"13 Jun 2026, (Sat)",Miscelanious Learning
juzaina,M. Clin Psy,Add New Study Materials,"16 Jun 2026, (Tue)",Concepts
juzaina,M. Clin Psy,Add New Study Materials,"13 Jun 2026, (Sat)",Physiological Basis of Learning (UPDATION)
juzaina,M. Clin Psy,Add New Study Materials,"17 Jun 2026, (Wed)",Bio of Memory (UPDATION)
juzaina,M. Clin Psy,Add New Study Materials,"13 Jun 2026, (Sat)",Object Perception & Pattern Recognition
juzaina,M. Clin Psy,Add New Study Materials,"16 Jun 2026, (Tue)",Reasoning
juzaina,M. Clin Psy,Add New Study Materials,"17 Jun 2026, (Wed)",Semantic Memory Models
juzaina,M. Clin Psy,Add New Study Materials,"16 Jun 2026, (Tue)",Decision Making: Steps & Models
juzaina,M. Clin Psy,Add New Study Materials,"18 Jun 2026, (Thu)",Frontal Lobe
juzaina,M. Clin Psy,Add New Study Materials,"18 Jun 2026, (Thu)",Temporal Lobe
juzaina,M. Clin Psy,Add New Study Materials,"26 Jun 2026, (Fri)",Occipital Lobe & Parietal Lobe
juzaina,M. Clin Psy,Add New Study Materials,"26 Jun 2026, (Fri)","Neurocognitive disorders, Epidemiological studies"
juzaina,M. Clin Psy,Add New Study Materials,"27 Jun 2026, (Sat)",Wechsler Scales
juzaina,M. Clin Psy,Add New Study Materials,"27 Jun 2026, (Sat)",Culture bound syndromes (Updation)
juzaina,M. Clin Psy,Add New Study Materials,"28 Jun 2026, (Sun)",Sexual dysfunctions
juzaina,M. Clin Psy,Add New Study Materials,"30 Jun 2026, (Tue)",Brain Lobes
juzaina,M. Clin Psy,Add New Study Materials,"03 Jul 2026, (Fri)",SCID-5
juzaina,M. Clin Psy,Add New Study Materials,"04 Jul 2026, (Sat)","Concept of normality and abnormality, Clinical interviewing, tests of thought disorders"
amilah,M. Clin Psy,Add New Study Materials,"21 Jun 2026, (Sun)",RPWD
amilah,M. Clin Psy,Add New Study Materials,"21 Jun 2026, (Sun)",Trauma-Informed Approach
amilah,M. Clin Psy,Add New Study Materials,"21 Jun 2026, (Sun)",NIMHANS Index for SLD
amilah,M. Clin Psy,Add New Study Materials,"22 Jun 2026, (Mon)",Third Wave Therapies
amilah,M. Clin Psy,Add New Study Materials,"22 Jun 2026, (Mon)",Draw-a-Man Test
amilah,M. Clin Psy,Add New Study Materials,"25 Jun 2026, (Thu)",Tests of Normality
amilah,M. Clin Psy,Add New Study Materials,"25 Jun 2026, (Thu)",Family Therapy
amilah,M. Clin Psy,Add New Study Materials,"25 Jun 2026, (Thu)",Neurodevelopmental disorders
amilah,M. Clin Psy,Add New Study Materials,"28 Jun 2026, (Sun)",Sleep-wake disorders
amilah,M. Clin Psy,Add New Study Materials,"28 Jun 2026, (Sun)",Substance use disorders
amilah,M. Clin Psy,Add New Study Materials,"28 Jun 2026, (Sun)",Suicide
amilah,M. Clin Psy,Add New Study Materials,"28 Jun 2026, (Sun)",Crisis intervention
amilah,M. Clin Psy,Add New Study Materials,"04 Jul 2026, (Sat)",History of Abnormal Psychology
amilah,M. Clin Psy,Add New Study Materials,"04 Jul 2026, (Sat)",Causes of Mental lllness
amilah,M. Clin Psy,Add New Study Materials,"04 Jul 2026, (Sat)",Substance use disorders (Re-work)
amilah,M. Clin Psy,Add New Study Materials,"08 Jul 2026, (Wed)",AIIMS Neuropsychology Battery
amilah,M. Clin Psy,Add New Study Materials,"26 Jul 2026, (Sun)",Genetics and Behavior
amilah,M. Clin Psy,Add New Study Materials,"26 Jul 2026, (Sun)",Biological Basis of Motivation - Sleep
amilah,M. Clin Psy,Add New Study Materials,"26 Jul 2026, (Sun)",Methods of Physiological Psychology
amilah,M. Clin Psy,Add New Study Materials,"27 Jul 2026, (Mon)",Biological Basis of Emotion
amilah,M. Clin Psy,Add New Study Materials,"27 Jul 2026, (Mon)",Neurological Disorders
amilah,M. Clin Psy,Add New Study Materials,"04 Aug 2026, (Tue)",Sensory Systems
amilah,M. Clin Psy,Add New Study Materials,"04 Aug 2026, (Tue)",Neurons
amilah,M. Clin Psy,Add New Study Materials,"04 Aug 2026, (Tue)",Central Nervous System
amilah,M. Clin Psy,Add New Study Materials,"04 Aug 2026, (Tue)",Peripheral Nervous System
amilah,M. Clin Psy,Add New Study Materials,"05 Aug 2026, (Wed)",Neuroplasticity
amilah,M. Clin Psy,Add New Study Materials,"05 Aug 2026, (Wed)",Muscular and Glandular Systems
amilah,M. Clin Psy,Add New Study Materials,"05 Aug 2026, (Wed)",Biological Basis of Motivation - Hunger
amilah,M. Clin Psy,Add New Study Materials,"05 Aug 2026, (Wed)",Biological Basis of Motivation - Thirst
amilah,M. Clin Psy,Add New Study Materials,"05 Aug 2026, (Wed)",Biological Basis of Motivation - Sex
amilah,M. Clin Psy,Add New Study Materials,"05 Aug 2026, (Wed)",made 14 materials covers
CSV;

$lines = explode("\n", trim($csv_data));
array_shift($lines); // remove header

$rows = [];
foreach ($lines as $line) {
    if (trim($line) === '') continue;
    $rows[] = str_getcsv($line);
}

// Map users
$db_admins = $pdo->query("SELECT id, username, full_name, role FROM admins")->fetchAll();
$admin_map = [];
foreach ($db_admins as $admin) {
    $admin_map[strtolower($admin['username'])] = $admin;
}

// Group tasks
$grouped = [];
foreach ($rows as $r) {
    $csv_user = trim($r[0]);
    $csv_course = trim($r[1]);
    $csv_mode = trim($r[2]);
    $date_raw = trim($r[3]);
    $topic = trim($r[4]);
    
    $date_part = trim(explode(',', $date_raw)[0]);
    $parsed_date = date('Y-m-d', strtotime($date_part));
    
    $key = "{$csv_user}|{$csv_course}|{$csv_mode}|{$parsed_date}";
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'username' => $csv_user,
            'course' => $csv_course,
            'mode' => $csv_mode,
            'date' => $parsed_date,
            'topics' => []
        ];
    }
    $grouped[$key]['topics'][] = $topic;
}

$imported_tasks = 0;
$imported_topics = 0;
$imported_audits = 0;

try {
    $pdo->beginTransaction();
    
    foreach ($grouped as $key => $task) {
        $user_lower = strtolower($task['username']);
        if (!isset($admin_map[$user_lower])) {
            throw new Exception("Username '{$task['username']}' not found in admins table.");
        }
        $admin = $admin_map[$user_lower];
        
        // Match Work Mode
        $mode_id = null;
        $mode_name = null;
        if ($task['mode'] === 'Add New Study Materials') {
            $mode_id = 2;
            $mode_name = 'Add New Study Materials';
        } elseif ($task['mode'] === 'Test') {
            $mode_id = 1;
            $mode_name = 'Tests'; // Plural per approval mapping
        } else {
            throw new Exception("Unsupported work mode: " . $task['mode']);
        }
        
        // 1. Insert Task
        $stmt = $pdo->prepare("
            INSERT INTO ld_tasks (
                admin_id, admin_username, admin_name, admin_role,
                course_id, course_name, mode_id, mode_name,
                latitude, longitude, maps_url, ip_address, user_agent,
                status, created_at
            ) VALUES (?, ?, ?, ?, 1, 'M. Clin Psy', ?, ?, 0.0, 0.0, '', '127.0.0.1', 'Historical CSV Import', 'active', ?)
        ");
        $stmt->execute([
            (int)$admin['id'],
            $admin['username'],
            $admin['full_name'],
            $admin['role'],
            $mode_id,
            $mode_name,
            $task['date'] . ' 00:00:00'
        ]);
        
        $task_id = $pdo->lastInsertId();
        $imported_tasks++;
        
        // 2. Insert Topics
        $stmt_topic = $pdo->prepare("
            INSERT INTO ld_task_topics (task_id, topic_name, created_at)
            VALUES (?, ?, ?)
        ");
        foreach ($task['topics'] as $topic) {
            $stmt_topic->execute([
                $task_id,
                $topic,
                $task['date'] . ' 00:00:00'
            ]);
            $imported_topics++;
        }
        
        // 3. Insert Audit Record
        $audit_new_values = json_encode([
            'action_type' => 'historical_csv_import',
            'course_name' => 'M. Clin Psy',
            'mode_name' => $mode_name,
            'topics' => $task['topics'],
            'created_at' => $task['date'] . ' 00:00:00'
        ]);
        
        $stmt_audit = $pdo->prepare("
            INSERT INTO ld_task_audit (
                task_id, admin_id, admin_username, action,
                previous_values, new_values, latitude, longitude, maps_url,
                ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, 'CREATE', NULL, ?, 0.0, 0.0, '', '127.0.0.1', 'Historical CSV Import', ?)
        ");
        $stmt_audit->execute([
            $task_id,
            (int)$admin['id'],
            $admin['username'],
            $audit_new_values,
            $task['date'] . ' 00:00:00'
        ]);
        $imported_audits++;
    }
    
    $pdo->commit();
    echo json_encode([
        'status' => 'success',
        'imported_tasks' => $imported_tasks,
        'imported_topics' => $imported_topics,
        'imported_audits' => $imported_audits
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
