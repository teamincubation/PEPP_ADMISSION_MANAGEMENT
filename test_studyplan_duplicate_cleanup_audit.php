<?php
/**
 * PEPP Study Plan Designer — Duplicate Activities Cleanup Audit Test Suite (DUP-01 through DUP-30)
 *
 * Comprehensive validation of:
 * - Exact 4-field matching (Activity Title, Activity Type, Chapter, Topic)
 * - Strict survivor selection (Oldest MIN(id) when 0 student data, or student-data-bearing activities)
 * - Authoritative student data protection (Cases 1, 2, 3, 4, 5)
 * - Soft deletion only with atomic transactions and fail-closed lock verification
 * - Single consolidated audit logging and version bumping
 * - Zero modification of day_number, activity_date, sort_order, or phantom Rest Day creation
 */

// Set up clean isolated in-memory test database
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURRENT_TIMESTAMP', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('TIMESTAMPDIFF', function($unit, $t1, $t2) {
    if (empty($t1) || empty($t2)) return 0;
    return strtotime((string)$t2) - strtotime((string)$t1);
});

// Setup Schema
$pdo->exec("
    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        plan_type TEXT NOT NULL DEFAULT 'date_wise',
        start_date TEXT,
        end_date TEXT,
        total_days INTEGER DEFAULT 7,
        version INTEGER NOT NULL DEFAULT 1,
        is_deleted INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'published',
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER NOT NULL,
        activity_uid TEXT NOT NULL,
        activity_title TEXT NOT NULL,
        activity_type TEXT NOT NULL,
        activity_date TEXT,
        day_number INTEGER NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        chapter TEXT,
        topic TEXT,
        faculty TEXT,
        estimated_duration INTEGER DEFAULT 60,
        priority TEXT DEFAULT 'medium',
        difficulty_level TEXT DEFAULT 'medium',
        resource_links TEXT,
        custom_activity_badge TEXT,
        custom_activity_color TEXT,
        custom_activity_icon TEXT,
        is_deleted INTEGER NOT NULL DEFAULT 0,
        deleted_at TEXT,
        deleted_by TEXT,
        deletion_reason TEXT
    );

    CREATE TABLE study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER NOT NULL,
        student_email TEXT,
        activity_id INTEGER,
        activity_uid TEXT,
        action_type TEXT NOT NULL DEFAULT 'complete_activity',
        completion_status TEXT NOT NULL DEFAULT 'completed',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE assessment_result_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_id INTEGER NOT NULL,
        study_plan_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'published',
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE assessment_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER NOT NULL,
        student_email TEXT,
        score REAL,
        total_score REAL
    );

    CREATE TABLE study_plan_activity_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_id INTEGER NOT NULL,
        activity_uid TEXT NOT NULL,
        study_plan_id INTEGER NOT NULL,
        version_number INTEGER NOT NULL,
        activity_date TEXT,
        day_number INTEGER NOT NULL,
        sort_order INTEGER NOT NULL,
        chapter TEXT,
        topic TEXT,
        activity_title TEXT NOT NULL,
        activity_description TEXT,
        activity_type TEXT NOT NULL,
        faculty TEXT,
        estimated_duration INTEGER,
        priority TEXT,
        difficulty_level TEXT,
        resource_links TEXT,
        custom_activity_badge TEXT,
        custom_activity_color TEXT,
        custom_activity_icon TEXT,
        created_by TEXT,
        change_type TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE study_plan_audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER NOT NULL,
        admin_username TEXT NOT NULL,
        action TEXT NOT NULL,
        details TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE study_plan_edit_locks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER NOT NULL UNIQUE,
        locked_by_admin_username TEXT NOT NULL,
        locked_by_admin_id INTEGER,
        locked_by_admin_name TEXT,
        locked_by_photo_url TEXT,
        session_token TEXT NOT NULL,
        ip_address TEXT,
        user_agent TEXT,
        locked_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_heartbeat_at TEXT DEFAULT CURRENT_TIMESTAMP,
        is_active INTEGER NOT NULL DEFAULT 1
    );
");

// Helpers matching codebase
require_once __DIR__ . '/includes/study_plan_lock_helper.php';

function test_log_activity_version($pdo, $activity_id, $change_type, $admin_username) {
    $stmt = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ?");
    $stmt->execute([$activity_id]);
    $act = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$act) return;

    $stmt_ver = $pdo->prepare("SELECT COALESCE(MAX(version_number), 0) + 1 FROM study_plan_activity_versions WHERE activity_id = ?");
    $stmt_ver->execute([$activity_id]);
    $next_ver = (int)$stmt_ver->fetchColumn();

    $stmt_ins = $pdo->prepare("
        INSERT INTO study_plan_activity_versions (
            activity_id, activity_uid, study_plan_id, version_number, activity_date, day_number, sort_order,
            chapter, topic, activity_title, activity_description, activity_type,
            faculty, estimated_duration, priority, difficulty_level, resource_links,
            custom_activity_badge, custom_activity_color, custom_activity_icon, created_by, change_type, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt_ins->execute([
        $activity_id,
        $act['activity_uid'],
        $act['study_plan_id'],
        $next_ver,
        $act['activity_date'],
        (int)$act['day_number'],
        (int)$act['sort_order'],
        $act['chapter'] ?? null,
        $act['topic'] ?? null,
        $act['activity_title'],
        $act['activity_description'] ?? null,
        $act['activity_type'],
        $act['faculty'] ?? null,
        !empty($act['estimated_duration']) ? (int)$act['estimated_duration'] : null,
        $act['priority'] ?? 'medium',
        $act['difficulty_level'] ?? 'medium',
        $act['resource_links'] ?? null,
        $act['custom_activity_badge'] ?? null,
        $act['custom_activity_color'] ?? null,
        $act['custom_activity_icon'] ?? null,
        $admin_username,
        $change_type
    ]);
}

/**
 * Re-include duplicate analysis function from api/studyplans-api.php logic
 */
function run_duplicate_analysis($pdo, $plan_id) {
    $stmt = $pdo->prepare("
        SELECT * 
        FROM study_plan_activities 
        WHERE study_plan_id = ? AND is_deleted = 0 
        ORDER BY id ASC
    ");
    $stmt->execute([$plan_id]);
    $all_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_activities)) {
        return [
            'total_groups' => 0,
            'total_activities_found' => 0,
            'deletable_count' => 0,
            'protected_count' => 0,
            'groups' => [],
            'deletable_ids' => [],
            'deletable_uids' => [],
            'protected_ids' => [],
            'protected_uids' => [],
            'protected_activities' => []
        ];
    }

    $grouped_map = [];
    foreach ($all_activities as $act) {
        $title = (string)($act['activity_title'] ?? '');
        $type = (string)($act['activity_type'] ?? '');
        $chapter = (string)($act['chapter'] ?? '');
        $topic = (string)($act['topic'] ?? '');

        $group_key = $title . "\x1F" . $type . "\x1F" . $chapter . "\x1F" . $topic;
        if (!isset($grouped_map[$group_key])) {
            $grouped_map[$group_key] = [
                'activity_title' => $title,
                'activity_type' => $type,
                'chapter' => $chapter,
                'topic' => $topic,
                'activities' => []
            ];
        }
        $grouped_map[$group_key]['activities'][] = $act;
    }

    $dup_groups = [];
    $target_uids = [];
    $target_ids = [];

    foreach ($grouped_map as $g) {
        if (count($g['activities']) > 1) {
            $dup_groups[] = $g;
            foreach ($g['activities'] as $act) {
                if (!empty($act['activity_uid'])) $target_uids[] = $act['activity_uid'];
                if (!empty($act['id'])) $target_ids[] = (int)$act['id'];
            }
        }
    }

    if (empty($dup_groups)) {
        return [
            'total_groups' => 0,
            'total_activities_found' => 0,
            'deletable_count' => 0,
            'protected_count' => 0,
            'groups' => [],
            'deletable_ids' => [],
            'deletable_uids' => [],
            'protected_ids' => [],
            'protected_uids' => [],
            'protected_activities' => []
        ];
    }

    $counts_by_uid = [];
    $counts_by_id = [];
    $analytics_conditions = [];
    $an_params = [$plan_id];

    if (!empty($target_uids)) {
        $u_place = implode(',', array_fill(0, count($target_uids), '?'));
        $analytics_conditions[] = "activity_uid IN ($u_place)";
        foreach ($target_uids as $u) $an_params[] = $u;
    }
    if (!empty($target_ids)) {
        $i_place = implode(',', array_fill(0, count($target_ids), '?'));
        $analytics_conditions[] = "activity_id IN ($i_place)";
        foreach ($target_ids as $i) $an_params[] = $i;
    }

    if (!empty($analytics_conditions)) {
        try {
            $sql_an = "
                SELECT activity_uid, activity_id, COUNT(*) AS student_cnt
                FROM study_plan_analytics
                WHERE study_plan_id = ?
                  AND (" . implode(' OR ', $analytics_conditions) . ")
                GROUP BY activity_uid, activity_id
            ";
            $stmt_an = $pdo->prepare($sql_an);
            $stmt_an->execute($an_params);
            $an_rows = $stmt_an->fetchAll(PDO::FETCH_ASSOC);

            foreach ($an_rows as $row) {
                $cnt = (int)$row['student_cnt'];
                if (!empty($row['activity_uid'])) {
                    $counts_by_uid[$row['activity_uid']] = ($counts_by_uid[$row['activity_uid']] ?? 0) + $cnt;
                }
                if (!empty($row['activity_id'])) {
                    $counts_by_id[(int)$row['activity_id']] = ($counts_by_id[(int)$row['activity_id']] ?? 0) + $cnt;
                }
            }
        } catch (Exception $e) {}
    }

    $assessment_counts_by_id = [];
    if (!empty($target_ids)) {
        try {
            $id_place = implode(',', array_fill(0, count($target_ids), '?'));
            $sql_ass = "
                SELECT arb.activity_id, COUNT(*) AS assessment_cnt
                FROM assessment_results ar
                JOIN assessment_result_batches arb ON ar.batch_id = arb.id
                WHERE arb.activity_id IN ($id_place) AND (arb.is_deleted IS NULL OR arb.is_deleted = 0)
                GROUP BY arb.activity_id
            ";
            $stmt_ass = $pdo->prepare($sql_ass);
            $stmt_ass->execute($target_ids);
            $ass_rows = $stmt_ass->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ass_rows as $arow) {
                $assessment_counts_by_id[(int)$arow['activity_id']] = (int)$arow['assessment_cnt'];
            }
        } catch (Exception $e) {}
    }

    $processed_groups = [];
    $all_deletable_ids = [];
    $all_deletable_uids = [];
    $all_protected_ids = [];
    $all_protected_uids = [];
    $all_protected_activities = [];
    $total_activities_found = 0;

    $group_index = 1;
    foreach ($dup_groups as $g) {
        $acts = $g['activities'];
        $total_activities_found += count($acts);

        $with_student_data = [];
        $without_student_data = [];

        foreach ($acts as $act) {
            $uid = $act['activity_uid'] ?? '';
            $id = (int)$act['id'];

            $cnt = 0;
            if (!empty($uid) && isset($counts_by_uid[$uid])) $cnt += $counts_by_uid[$uid];
            if (!empty($id) && isset($counts_by_id[$id])) $cnt += $counts_by_id[$id];
            if (!empty($id) && isset($assessment_counts_by_id[$id])) $cnt += $assessment_counts_by_id[$id];

            $act_entry = [
                'id' => $id,
                'activity_uid' => $uid,
                'activity_title' => $act['activity_title'],
                'activity_type' => $act['activity_type'],
                'chapter' => $act['chapter'] ?? '',
                'topic' => $act['topic'] ?? '',
                'day_number' => (int)$act['day_number'],
                'activity_date' => $act['activity_date'],
                'sort_order' => (int)$act['sort_order'],
                'student_count' => $cnt
            ];

            if ($cnt > 0) {
                $act_entry['protection_reason'] = "Student activity recorded ({$cnt} record(s))";
                $with_student_data[] = $act_entry;
            } else {
                $without_student_data[] = $act_entry;
            }
        }

        $survivors = [];
        $deletable_in_group = [];
        $protected_in_group = [];

        if (empty($with_student_data)) {
            // Case 1: 0 student data anywhere -> keep oldest
            $survivor_entry = [
                'id' => (int)$acts[0]['id'],
                'activity_uid' => $acts[0]['activity_uid'],
                'day_number' => (int)$acts[0]['day_number'],
                'activity_date' => $acts[0]['activity_date'],
                'student_count' => 0,
                'reason' => 'Oldest activity kept as survivor'
            ];
            $survivors[] = $survivor_entry;

            for ($i = 1; $i < count($acts); $i++) {
                $del_act = $acts[$i];
                $deletable_in_group[] = [
                    'id' => (int)$del_act['id'],
                    'activity_uid' => $del_act['activity_uid'],
                    'day_number' => (int)$del_act['day_number'],
                    'activity_date' => $del_act['activity_date'],
                    'student_count' => 0
                ];
                $all_deletable_ids[] = (int)$del_act['id'];
                $all_deletable_uids[] = $del_act['activity_uid'];
            }
        } else {
            // Cases 2-5: Protect ALL with student data
            foreach ($with_student_data as $prot_act) {
                $survivors[] = [
                    'id' => $prot_act['id'],
                    'activity_uid' => $prot_act['activity_uid'],
                    'day_number' => $prot_act['day_number'],
                    'activity_date' => $prot_act['activity_date'],
                    'student_count' => $prot_act['student_count'],
                    'reason' => 'Protected — Student activity exists'
                ];
                $protected_in_group[] = $prot_act;
                $all_protected_ids[] = $prot_act['id'];
                $all_protected_uids[] = $prot_act['activity_uid'];
                $all_protected_activities[] = $prot_act;
            }

            foreach ($without_student_data as $del_act) {
                $deletable_in_group[] = [
                    'id' => $del_act['id'],
                    'activity_uid' => $del_act['activity_uid'],
                    'day_number' => $del_act['day_number'],
                    'activity_date' => $del_act['activity_date'],
                    'student_count' => 0
                ];
                $all_deletable_ids[] = $del_act['id'];
                $all_deletable_uids[] = $del_act['activity_uid'];
            }
        }

        $processed_groups[] = [
            'group_id' => $group_index++,
            'activity_title' => $g['activity_title'],
            'activity_type' => $g['activity_type'],
            'chapter' => $g['chapter'],
            'topic' => $g['topic'],
            'total_in_group' => count($acts),
            'survivors' => $survivors,
            'to_delete' => $deletable_in_group,
            'protected' => $protected_in_group
        ];
    }

    return [
        'total_groups' => count($processed_groups),
        'total_activities_found' => $total_activities_found,
        'deletable_count' => count($all_deletable_ids),
        'protected_count' => count($all_protected_ids),
        'groups' => $processed_groups,
        'deletable_ids' => $all_deletable_ids,
        'deletable_uids' => $all_deletable_uids,
        'protected_ids' => $all_protected_ids,
        'protected_uids' => $all_protected_uids,
        'protected_activities' => $all_protected_activities
    ];
}

/**
 * Re-include execute duplicate cleanup logic from api/studyplans-api.php
 */
function run_duplicate_deletion($pdo, $plan_id, $admin_username = 'alice', $admin_id = 1, $client_version = 0, $reason = 'Duplicate activity cleanup') {
    if (!verify_study_plan_edit_lock_permission($pdo, $plan_id, $admin_username, $admin_id)) {
        return [
            'success' => false,
            'error_code' => 'EDIT_LOCK_HELD',
            'message' => 'This Study Plan is currently locked for editing by another administrator.'
        ];
    }

    $stmt_ver = $pdo->prepare("SELECT version FROM study_plans WHERE id = ?");
    $stmt_ver->execute([$plan_id]);
    $db_version = $stmt_ver->fetchColumn();

    if ($db_version === false) {
        return ['success' => false, 'message' => 'Study Plan not found.'];
    }

    $db_version = (int)$db_version;
    if ($client_version > 0 && $client_version !== $db_version) {
        return [
            'success' => false,
            'error_code' => 'STALE_STUDY_PLAN',
            'message' => 'Stale version.'
        ];
    }

    $analysis = run_duplicate_analysis($pdo, $plan_id);
    $deletable_ids = $analysis['deletable_ids'];
    $deletable_uids = $analysis['deletable_uids'];
    $protected_count = $analysis['protected_count'];
    $protected_activities = $analysis['protected_activities'];
    $total_groups = $analysis['total_groups'];
    $total_found = $analysis['total_activities_found'];

    if (empty($deletable_ids)) {
        return [
            'success' => true,
            'total_groups' => $total_groups,
            'total_activities_found' => $total_found,
            'deleted_count' => 0,
            'protected_count' => $protected_count,
            'deleted_ids' => [],
            'deleted_uids' => [],
            'protected_activities' => $protected_activities,
            'version' => $db_version,
            'message' => '0 activities deleted.'
        ];
    }

    try {
        $pdo->beginTransaction();

        foreach ($deletable_ids as $del_id) {
            test_log_activity_version($pdo, $del_id, 'delete', $admin_username);
        }

        $in_placeholders = implode(',', array_fill(0, count($deletable_ids), '?'));
        $del_sql = "UPDATE study_plan_activities SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, deletion_reason = ? WHERE id IN ($in_placeholders) AND study_plan_id = ? AND is_deleted = 0";
        $stmt_del = $pdo->prepare($del_sql);
        $stmt_del->execute(array_merge([$admin_username, $reason], $deletable_ids, [$plan_id]));

        $pdo->prepare("UPDATE study_plans SET version = version + 1, updated_at = NOW() WHERE id = ?")->execute([$plan_id]);

        $count_del = count($deletable_ids);
        $audit_details = "Study Plan: #{$plan_id} | Duplicate Groups: {$total_groups} | Detected: {$total_found} | Deleted: {$count_del} | Protected: {$protected_count} | Reason: {$reason}";
        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'duplicate_activities_cleanup', ?)");
        $stmt_audit->execute([$plan_id, $admin_username, $audit_details]);

        $pdo->commit();

        return [
            'success' => true,
            'total_groups' => $total_groups,
            'total_activities_found' => $total_found,
            'deleted_count' => $count_del,
            'protected_count' => $protected_count,
            'deleted_ids' => $deletable_ids,
            'deleted_uids' => $deletable_uids,
            'protected_activities' => $protected_activities,
            'version' => $db_version + 1,
            'message' => "{$count_del} deleted."
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Exception $rbEx) {}
        }
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// Test Runner
$passed = 0;
$failed = 0;

function assertTest($code, $description, $condition) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$code}: {$description}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$code}: {$description}\n";
    }
}

echo "======================================================================\n";
echo "  PEPP STUDY PLAN DUPLICATE CLEANUP AUDIT TEST SUITE (DUP-01..DUP-30)\n";
echo "======================================================================\n\n";

// Setup Plan 1
$pdo->exec("INSERT INTO study_plans (id, title, plan_type, start_date, end_date, total_days, version) VALUES (1, 'Psychology Crash Course', 'date_wise', '2026-08-01', '2026-08-07', 7, 1)");
$pdo->exec("INSERT INTO study_plan_edit_locks (study_plan_id, locked_by_admin_username, locked_by_admin_id, session_token, is_active) VALUES (1, 'alice', 1, 'tok-alice', 1)");

// Seed Activities in Plan 1
// Group 1: 4 exact matches across Title, Type, Chapter, Topic
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date, sort_order) VALUES (101, 1, 'UID-101', 'Intro to Memory', 'Video', 'Memory', 'Encoding', 1, '2026-08-01', 0)");
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date, sort_order) VALUES (115, 1, 'UID-115', 'Intro to Memory', 'Video', 'Memory', 'Encoding', 2, '2026-08-02', 0)");
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date, sort_order) VALUES (132, 1, 'UID-132', 'Intro to Memory', 'Video', 'Memory', 'Encoding', 3, '2026-08-03', 0)");
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date, sort_order) VALUES (144, 1, 'UID-144', 'Intro to Memory', 'Video', 'Memory', 'Encoding', 4, '2026-08-04', 0)");

// Non-duplicates (differing fields)
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date, sort_order) VALUES (150, 1, 'UID-150', 'Intro to Memory', 'Reading', 'Memory', 'Encoding', 5, '2026-08-05', 0)"); // Different type
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date, sort_order) VALUES (151, 1, 'UID-151', 'Intro to Memory', 'Video', 'Cognition', 'Encoding', 5, '2026-08-05', 1)"); // Different chapter
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date, sort_order) VALUES (152, 1, 'UID-152', 'Intro to Memory', 'Video', 'Memory', 'Retrieval', 5, '2026-08-05', 2)"); // Different topic
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date, sort_order) VALUES (160, 1, 'UID-160', 'Unique Standalone Task', 'MCQ', 'Stats', 'ANOVA', 6, '2026-08-06', 0)");

// --- TEST SUITE EXECUTION ---

// DUP-01: Detect exact four-field duplicates
$analysis1 = run_duplicate_analysis($pdo, 1);
assertTest('DUP-01', 'Detect exact four-field duplicates in Group 1', $analysis1['total_groups'] === 1 && $analysis1['total_activities_found'] === 4);

// DUP-02: Do not detect title-only duplicates (150, 151, 152 share title but differ in type/chapter/topic)
$found_diff = false;
foreach ($analysis1['groups'] as $g) {
    foreach ($g['to_delete'] as $del) {
        if (in_array($del['id'], [150, 151, 152, 160])) $found_diff = true;
    }
}
assertTest('DUP-02', 'Do not detect title-only duplicates as deletion candidates', !$found_diff);

// DUP-03: Do not detect type mismatch as duplicate
$type_del = false;
foreach ($analysis1['deletable_ids'] as $did) { if ($did === 150) $type_del = true; }
assertTest('DUP-03', 'Do not detect type mismatch (Video vs Reading) as duplicate', !$type_del);

// DUP-04: Do not detect chapter mismatch as duplicate
$chap_del = false;
foreach ($analysis1['deletable_ids'] as $did) { if ($did === 151) $chap_del = true; }
assertTest('DUP-04', 'Do not detect chapter mismatch (Memory vs Cognition) as duplicate', !$chap_del);

// DUP-05: Do not detect topic mismatch as duplicate
$top_del = false;
foreach ($analysis1['deletable_ids'] as $did) { if ($did === 152) $top_del = true; }
assertTest('DUP-05', 'Do not detect topic mismatch (Encoding vs Retrieval) as duplicate', !$top_del);

// DUP-06: Keep exactly one duplicate when no student data exists
assertTest('DUP-06', 'Keep exactly one duplicate when no student data exists (deletable: 3, survivor: 1)', count($analysis1['groups'][0]['survivors']) === 1 && count($analysis1['groups'][0]['to_delete']) === 3);

// DUP-07: Keep oldest activity when all are safe (MIN(id) = 101)
$survivor_id = $analysis1['groups'][0]['survivors'][0]['id'];
assertTest('DUP-07', 'Keep oldest activity when all are safe (MIN(id) = 101)', $survivor_id === 101);

// DUP-08: Protect activity containing student data on oldest activity
// Seed student data on 101
$pdo->exec("INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid) VALUES (1, 's1@test.com', 101, 'UID-101')");
$analysis_case2 = run_duplicate_analysis($pdo, 1);
assertTest('DUP-08', 'Protect activity containing student data (101 has 1 student -> kept as survivor, 115, 132, 144 deletable)', $analysis_case2['groups'][0]['survivors'][0]['id'] === 101 && $analysis_case2['deletable_count'] === 3);

// DUP-09: Prefer student-data-bearing activity as survivor when non-oldest has student data (Case 3)
// Clear 101 analytics, add on 115
$pdo->exec("DELETE FROM study_plan_analytics WHERE activity_id = 101");
$pdo->exec("INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid) VALUES (1, 's2@test.com', 115, 'UID-115')");
$analysis_case3 = run_duplicate_analysis($pdo, 1);
$c3_survivors = array_column($analysis_case3['groups'][0]['survivors'], 'id');
$c3_deletables = array_column($analysis_case3['groups'][0]['to_delete'], 'id');
assertTest('DUP-09', 'Prefer student-data-bearing activity (#115) as survivor; #101, #132, #144 become deletable', in_array(115, $c3_survivors) && in_array(101, $c3_deletables) && !in_array(115, $c3_deletables));

// DUP-10: Protect multiple student-data-bearing duplicates (Case 4)
// Seed on 101 AND 115
$pdo->exec("INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid) VALUES (1, 's1@test.com', 101, 'UID-101')");
$analysis_case4 = run_duplicate_analysis($pdo, 1);
$c4_survivors = array_column($analysis_case4['groups'][0]['survivors'], 'id');
$c4_deletables = array_column($analysis_case4['groups'][0]['to_delete'], 'id');
assertTest('DUP-10', 'Protect multiple student-data-bearing duplicates (#101 and #115 both kept)', in_array(101, $c4_survivors) && in_array(115, $c4_survivors) && count($c4_deletables) === 2);

// DUP-11: Delete only zero-student-data duplicates (132 and 144)
assertTest('DUP-11', 'Delete only zero-student-data duplicates (#132 and #144 in deletable)', in_array(132, $c4_deletables) && in_array(144, $c4_deletables));

// Execute deletion under Alice's lock for Plan 1
$del_res = run_duplicate_deletion($pdo, 1, 'alice', 1, 1, 'Duplicate cleanup test');
assertTest('DUP-11b', 'Execute cleanup deletes exactly 2 safe tasks (132, 144)', $del_res['deleted_count'] === 2 && $del_res['protected_count'] === 2);

// DUP-12: Preserve unselected/nonduplicate activities
$stmt_chk150 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 150")->fetchColumn();
$stmt_chk160 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 160")->fetchColumn();
assertTest('DUP-12', 'Preserve unselected/nonduplicate activities (#150 and #160 active)', (int)$stmt_chk150 === 0 && (int)$stmt_chk160 === 0);

// DUP-13: Preserve activity UID of surviving tasks
$uid_101 = $pdo->query("SELECT activity_uid FROM study_plan_activities WHERE id = 101")->fetchColumn();
$uid_115 = $pdo->query("SELECT activity_uid FROM study_plan_activities WHERE id = 115")->fetchColumn();
assertTest('DUP-13', 'Preserve activity UID of surviving tasks', $uid_101 === 'UID-101' && $uid_115 === 'UID-115');

// DUP-14: Preserve day number of surviving tasks
$day_101 = $pdo->query("SELECT day_number FROM study_plan_activities WHERE id = 101")->fetchColumn();
$day_115 = $pdo->query("SELECT day_number FROM study_plan_activities WHERE id = 115")->fetchColumn();
assertTest('DUP-14', 'Preserve day number of surviving tasks (Day 1 and Day 2 unmodified)', (int)$day_101 === 1 && (int)$day_115 === 2);

// DUP-15: Preserve activity date of surviving tasks
$date_101 = $pdo->query("SELECT activity_date FROM study_plan_activities WHERE id = 101")->fetchColumn();
$date_115 = $pdo->query("SELECT activity_date FROM study_plan_activities WHERE id = 115")->fetchColumn();
assertTest('DUP-15', 'Preserve activity date of surviving tasks (2026-08-01 and 2026-08-02 unmodified)', $date_101 === '2026-08-01' && $date_115 === '2026-08-02');

// DUP-16: Preserve sort order of surviving tasks
$sort_101 = $pdo->query("SELECT sort_order FROM study_plan_activities WHERE id = 101")->fetchColumn();
$sort_152 = $pdo->query("SELECT sort_order FROM study_plan_activities WHERE id = 152")->fetchColumn();
assertTest('DUP-16', 'Preserve sort order of surviving tasks', (int)$sort_101 === 0 && (int)$sort_152 === 2);

// DUP-17: Do not generate Rest Day on newly empty days (Day 3 & Day 4)
$day3_cnt = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 1 AND day_number = 3 AND is_deleted = 0")->fetchColumn();
$rest_days = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 1 AND (activity_title LIKE '%Rest Day%' OR activity_type = 'Rest Day')")->fetchColumn();
assertTest('DUP-17', 'Do not generate Rest Day on newly empty days (Day 3 has 0 rows, 0 Rest Day rows)', (int)$day3_cnt === 0 && (int)$rest_days === 0);

// DUP-18: Cross-plan activities are never affected
$pdo->exec("INSERT INTO study_plans (id, title, plan_type, version) VALUES (2, 'Economics Plan', 'date_wise', 1)");
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date) VALUES (201, 2, 'UID-201', 'Intro to Memory', 'Video', 'Memory', 'Encoding', 1, '2026-08-01')");
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date) VALUES (202, 2, 'UID-202', 'Intro to Memory', 'Video', 'Memory', 'Encoding', 2, '2026-08-02')");
$del_p1_again = run_duplicate_deletion($pdo, 1, 'alice', 1, 2, 'Repeat');
$act_201_del = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 201")->fetchColumn();
$act_202_del = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 202")->fetchColumn();
assertTest('DUP-18', 'Cross-plan activities in Plan #2 are completely untouched when Plan #1 is cleaned', (int)$act_201_del === 0 && (int)$act_202_del === 0);

// DUP-19: Non-lock owner (Bob) cannot execute duplicate cleanup on Plan #1
$bob_attempt = run_duplicate_deletion($pdo, 1, 'bob', 2, 2, 'Bob unauthorized cleanup');
assertTest('DUP-19', 'Non-lock owner (Bob) is rejected with EDIT_LOCK_HELD on Plan #1', $bob_attempt['success'] === false && $bob_attempt['error_code'] === 'EDIT_LOCK_HELD');

// DUP-20: Read-only admin without lock cannot execute on locked Plan #2
$pdo->exec("INSERT INTO study_plan_edit_locks (study_plan_id, locked_by_admin_username, locked_by_admin_id, session_token, is_active) VALUES (2, 'alice', 1, 'tok-alice-2', 1)");
$no_lock_attempt = run_duplicate_deletion($pdo, 2, 'bob', 2, 1, 'No lock cleanup');
assertTest('DUP-20', 'Read-only admin (Bob) without active edit lock is blocked from cleanup on Plan #2', $no_lock_attempt['success'] === false && $no_lock_attempt['error_code'] === 'EDIT_LOCK_HELD');

// Release Alice's lock on Plan #2 and transfer to Bob
$pdo->exec("UPDATE study_plan_edit_locks SET locked_by_admin_username = 'bob', locked_by_admin_id = 2, session_token = 'tok-bob' WHERE study_plan_id = 2");

// DUP-21: Transaction rollback works on forced DB error
$pdo->exec("CREATE TRIGGER force_error_trigger BEFORE UPDATE ON study_plans WHEN NEW.id = 2 BEGIN SELECT RAISE(ABORT, 'Simulated failure during cleanup'); END;");
$rollback_res = run_duplicate_deletion($pdo, 2, 'bob', 2, 1, 'Trigger failure');
$pdo->exec("DROP TRIGGER force_error_trigger");
$act_202_after_rb = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 202")->fetchColumn();
assertTest('DUP-21', 'Transaction rollback preserves all rows when error occurs', $rollback_res['success'] === false && (int)$act_202_after_rb === 0);

// DUP-22: No "There is no active transaction" error thrown during rollback
assertTest('DUP-22', 'Guarded rollback prevents "There is no active transaction"', !str_contains($rollback_res['message'] ?? '', 'no active transaction'));

// DUP-23: Study plan version increments only when deletion occurs
$v_before = $pdo->query("SELECT version FROM study_plans WHERE id = 2")->fetchColumn();
$clean_p2 = run_duplicate_deletion($pdo, 2, 'bob', 2, 1, 'Clean plan 2');
$v_after = $pdo->query("SELECT version FROM study_plans WHERE id = 2")->fetchColumn();
assertTest('DUP-23', 'Study plan version increments (+1) when duplicate is deleted', (int)$v_after === (int)$v_before + 1);

// DUP-24: Consolidated audit record created
$audit_rows = $pdo->query("SELECT * FROM study_plan_audit_logs WHERE study_plan_id = 2 AND action = 'duplicate_activities_cleanup'")->fetchAll();
assertTest('DUP-24', 'Consolidated audit record created with action = duplicate_activities_cleanup', count($audit_rows) === 1 && str_contains($audit_rows[0]['details'], 'Duplicate Groups: 1'));

// DUP-25: Repeated cleanup is idempotent (returns 0 groups, 0 deleted, no extra version bump)
$repeat_p2 = run_duplicate_deletion($pdo, 2, 'bob', 2, (int)$v_after, 'Repeat clean');
$v_final = $pdo->query("SELECT version FROM study_plans WHERE id = 2")->fetchColumn();
assertTest('DUP-25', 'Repeated cleanup is idempotent (deleted_count: 0, version unmodified)', ($repeat_p2['deleted_count'] ?? -1) === 0 && (int)$v_final === (int)$v_after);

// DUP-26: Soft-deleted records are excluded from duplicate detection
$analysis_p2_after = run_duplicate_analysis($pdo, 2);
assertTest('DUP-26', 'Soft-deleted records are excluded from duplicate detection (total_groups: 0)', $analysis_p2_after['total_groups'] === 0);

// DUP-27: Exact text matching is enforced (case & whitespace differences are NOT duplicates)
$pdo->exec("INSERT INTO study_plans (id, title, plan_type, version) VALUES (3, 'Text Matching Verification', 'date_wise', 1)");
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number) VALUES (301, 3, 'UID-301', 'Intro to Memory', 'Video', 'Memory', 'Encoding', 1)");
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number) VALUES (302, 3, 'UID-302', 'intro to memory', 'Video', 'Memory', 'Encoding', 2)"); // Lowercase title
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number) VALUES (303, 3, 'UID-303', 'Intro to Memory ', 'Video', 'Memory', 'Encoding', 3)"); // Trailing space
$analysis_p3 = run_duplicate_analysis($pdo, 3);
assertTest('DUP-27', 'Exact text matching is enforced (case/space differences are not grouped as duplicates)', $analysis_p3['total_groups'] === 0);

// DUP-28: Empty/null field handling is deterministic
$pdo->exec("INSERT INTO study_plans (id, title, plan_type, version) VALUES (4, 'Null Field Handling', 'date_wise', 1)");
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number) VALUES (401, 4, 'UID-401', 'Self Study', 'Reading', NULL, NULL, 1)");
$pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number) VALUES (402, 4, 'UID-402', 'Self Study', 'Reading', '', '', 2)");
$analysis_p4 = run_duplicate_analysis($pdo, 4);
assertTest('DUP-28', 'Empty/null field handling is deterministic (NULL and empty string normalize cleanly to exact match)', $analysis_p4['total_groups'] === 1 && $analysis_p4['deletable_count'] === 1);

// DUP-29: No activity dates are shifted on any day
$date_401 = $pdo->query("SELECT activity_date FROM study_plan_activities WHERE id = 401")->fetchColumn();
assertTest('DUP-29', 'No activity dates are shifted on survivor activities', $date_401 === null || $date_401 === '');

// DUP-30: No automatic Rest Day creation
$all_rest = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE (activity_title LIKE '%Rest Day%' OR activity_type = 'Rest Day')")->fetchColumn();
assertTest('DUP-30', 'Zero automatic Rest Day activities created across all test operations', (int)$all_rest === 0);

$total = $passed + $failed;
$pct = $total > 0 ? round(($passed / $total) * 100) : 0;
echo "\n----------------------------------------------------------------------\n";
echo "  RESULT: {$passed}/{$total} TESTS PASSED ({$pct}%)\n";
echo "======================================================================\n\n";

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
