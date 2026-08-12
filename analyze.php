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

// Parse lines
$lines = explode("\n", trim($csv_data));
$header = str_getcsv(array_shift($lines));

$rows = [];
foreach ($lines as $line) {
    if (trim($line) === '') continue;
    $rows[] = str_getcsv($line);
}

// 1. Basic Stats
$total_rows = count($rows);
$unique_usernames = [];
$unique_courses = [];
$unique_modes = [];
$dates = [];

foreach ($rows as $r) {
    $unique_usernames[trim($r[0])] = true;
    $unique_courses[trim($r[1])] = true;
    $unique_modes[trim($r[2])] = true;
    
    // Parse date
    $date_raw = trim($r[3]);
    $date_part = trim(explode(',', $date_raw)[0]);
    $parsed_date = date('Y-m-d', strtotime($date_part));
    $dates[$parsed_date] = true;
}

ksort($dates);
$date_keys = array_keys($dates);
$first_date = reset($date_keys);
$last_date = end($date_keys);

// 2. Fetch DB admins, courses, modes
$db_admins = [];
try {
    $db_admins = $pdo->query("SELECT id, username, full_name, role FROM admins")->fetchAll();
} catch (Exception $e) {}

$db_courses = [];
try {
    $db_courses = $pdo->query("SELECT id, course_name FROM ld_work_courses")->fetchAll();
} catch (Exception $e) {}

$db_modes = [];
try {
    $db_modes = $pdo->query("SELECT id, mode_name FROM ld_work_modes")->fetchAll();
} catch (Exception $e) {}

// 3. User mapping resolution
$user_mapping = [];
$unmatched_users = [];
foreach (array_keys($unique_usernames) as $csv_user) {
    $matched = null;
    foreach ($db_admins as $admin) {
        if (strtolower($admin['username']) === strtolower($csv_user)) {
            $matched = $admin;
            break;
        }
    }
    if ($matched) {
        $user_mapping[$csv_user] = [
            'erp_username' => $matched['username'],
            'admin_id' => (int)$matched['id']
        ];
    } else {
        $unmatched_users[] = $csv_user;
    }
}

// 4. Course mapping resolution
$course_mapping = [];
$unmatched_courses = [];
foreach (array_keys($unique_courses) as $csv_course) {
    $matched = null;
    foreach ($db_courses as $course) {
        if (strtolower($course['course_name']) === strtolower($csv_course)) {
            $matched = $course;
            break;
        }
    }
    if ($matched) {
        $course_mapping[$csv_course] = [
            'course_name' => $matched['course_name'],
            'course_id' => (int)$matched['id']
        ];
    } else {
        $unmatched_courses[] = $csv_course;
    }
}

// 5. Work Mode mapping resolution
$mode_mapping = [];
$unmatched_modes = [];
foreach (array_keys($unique_modes) as $csv_mode) {
    $matched = null;
    foreach ($db_modes as $mode) {
        if (strtolower($mode['mode_name']) === strtolower($csv_mode)) {
            $matched = $mode;
            break;
        }
    }
    if ($matched) {
        $mode_mapping[$csv_mode] = [
            'mode_name' => $matched['mode_name'],
            'mode_id' => (int)$matched['id']
        ];
    } else {
        $unmatched_modes[] = $csv_mode;
    }
}

// 6. Proposed grouping (Group by User, Course, Mode, Date)
$grouped_tasks = [];
foreach ($rows as $idx => $r) {
    $csv_user = trim($r[0]);
    $csv_course = trim($r[1]);
    $csv_mode = trim($r[2]);
    $date_raw = trim($r[3]);
    $topic = trim($r[4]);
    
    $date_part = trim(explode(',', $date_raw)[0]);
    $parsed_date = date('Y-m-d', strtotime($date_part));
    
    $group_key = "{$csv_user}|{$csv_course}|{$csv_mode}|{$parsed_date}";
    
    if (!isset($grouped_tasks[$group_key])) {
        $grouped_tasks[$group_key] = [
            'username' => $csv_user,
            'course' => $csv_course,
            'mode' => $csv_mode,
            'date' => $parsed_date,
            'topics' => []
        ];
    }
    $grouped_tasks[$group_key]['topics'][] = $topic;
}

$proposed_tasks_count = count($grouped_tasks);

// 7. Duplicate Checks against DB (simulated query verification)
$duplicates = [];
foreach ($grouped_tasks as $key => $task) {
    $admin_id = $user_mapping[$task['username']]['admin_id'] ?? null;
    $course_id = $course_mapping[$task['course']]['course_id'] ?? null;
    $mode_id = $mode_mapping[$task['mode']]['mode_id'] ?? null;
    
    if ($admin_id && $course_id && $mode_id) {
        try {
            // Check if active task exists for this user, course, mode, and created_at date
            $stmt = $pdo->prepare("
                SELECT id FROM ld_tasks 
                WHERE admin_id = ? AND course_id = ? AND mode_id = ? AND DATE(created_at) = ? AND status = 'active'
            ");
            $stmt->execute([$admin_id, $course_id, $mode_id, $task['date']]);
            $existing_task_id = $stmt->fetchColumn();
            
            if ($existing_task_id) {
                // Check if topics list also matches
                $stmt = $pdo->prepare("SELECT topic_name FROM ld_task_topics WHERE task_id = ?");
                $stmt->execute([$existing_task_id]);
                $existing_topics = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Compare arrays
                $diff1 = array_diff($task['topics'], $existing_topics);
                $diff2 = array_diff($existing_topics, $task['topics']);
                
                if (empty($diff1) && empty($diff2)) {
                    $duplicates[] = [
                        'group_key' => $key,
                        'existing_task_id' => (int)$existing_task_id,
                        'topics' => $task['topics']
                    ];
                }
            }
        } catch (Exception $e) {}
    }
}

// Build Output Report
$report = [
    'total_csv_rows' => $total_rows,
    'unique_users_count' => count($unique_usernames),
    'unique_courses_count' => count($unique_courses),
    'unique_modes_count' => count($unique_modes),
    'date_range' => [
        'first' => $first_date,
        'last' => $last_date
    ],
    'proposed_tasks_count' => $proposed_tasks_count,
    'proposed_topics_count' => $total_rows,
    'user_mapping' => $user_mapping,
    'unmatched_users' => $unmatched_users,
    'course_mapping' => $course_mapping,
    'unmatched_courses' => $unmatched_courses,
    'mode_mapping' => $mode_mapping,
    'unmatched_modes' => $unmatched_modes,
    'potential_duplicates' => $duplicates,
    'proposed_tasks_list' => array_values($grouped_tasks)
];

echo json_encode($report, JSON_PRETTY_PRINT);
