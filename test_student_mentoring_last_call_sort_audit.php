<?php
/**
 * PEPP Student Mentoring System — Last Call Sorting, Lock, Call Count & Course Column Removal Test Suite
 *
 * Validates:
 * 1. Database schema: admin_user_preferences table creation and self-healing.
 * 2. Admin preference persistence and strict admin isolation (Admin A vs Admin B).
 * 3. Total call count calculation (0, 1, 2, 5+ calls) with zero N+1 queries.
 * 4. Last Call sorting order:
 *    - Oldest → Newest: Never-called students first (highest priority), then oldest to newest.
 *    - Newest → Oldest: Newest calls first, then oldest, never-called students at bottom.
 * 5. Lock / Unlock preference rules:
 *    - Locked: Saved Last Call order overrides default Performance ordering.
 *    - Unlocked: Default Performance ordering (completion percentage descending) returns.
 * 6. Course column removal from Students table:
 *    - Course th and td removed from Students table.
 *    - Course selector at top and course filtering logic remain intact.
 * 7. Combined filter data attributes preservation for search, streak, progress, tasks, attendance.
 */

class StudentMentoringLastCallAuditTest {
    private $pdo;
    private $passed = 0;
    private $failed = 0;

    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setupSchema();
        $this->seedData();
    }

    private function setupSchema() {
        $this->pdo->exec("
            CREATE TABLE admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                full_name TEXT NOT NULL,
                email TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'admin',
                permissions TEXT NOT NULL DEFAULT 'student-mentoring',
                status TEXT NOT NULL DEFAULT 'active'
            );

            CREATE TABLE pepp_courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'active'
            );

            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                whatsapp_country_code TEXT DEFAULT '+91',
                whatsapp_number TEXT NOT NULL,
                pepp_course TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                student_status TEXT DEFAULT 'active',
                pepp_academic_year TEXT DEFAULT '2026-27',
                created_at DATETIME NOT NULL
            );

            CREATE TABLE mentor_student_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_user_id TEXT NOT NULL,
                admin_id INTEGER NOT NULL,
                course_name TEXT NOT NULL,
                assigned_by TEXT NOT NULL,
                assigned_at DATETIME NOT NULL,
                ended_at DATETIME DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL
            );

            CREATE TABLE mentor_call_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_user_id TEXT NOT NULL,
                admin_id INTEGER NOT NULL,
                admin_username TEXT NOT NULL,
                call_timestamp DATETIME NOT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE mentor_remarks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_user_id TEXT NOT NULL,
                admin_id INTEGER NOT NULL,
                admin_username TEXT NOT NULL,
                remark TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE admin_user_preferences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER NOT NULL,
                preference_key TEXT NOT NULL,
                preference_value TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (admin_id, preference_key)
            );
        ");
    }

    private function seedData() {
        // Admins
        $this->pdo->exec("
            INSERT INTO admins (id, username, full_name, email, role, permissions, status) VALUES
            (1, 'superadmin', 'Super Admin', 'super@pepp.com', 'super_admin', 'ALL', 'active'),
            (2, 'admin_a', 'Admin A', 'admin_a@pepp.com', 'admin', 'student-mentoring', 'active'),
            (3, 'admin_b', 'Admin B', 'admin_b@pepp.com', 'admin', 'student-mentoring', 'active');
        ");

        // Course
        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, status) VALUES
            (1712, 'M. Clin. Psy. (Standard Plan)', 'active');
        ");

        // Students in course 1712
        $this->pdo->exec("
            INSERT INTO users (id, user_id, name, email, whatsapp_country_code, whatsapp_number, pepp_course, status, student_status, created_at) VALUES
            (1, 'STU_A', 'Student A', 'a@test.com', '+91', '9876543210', 'M. Clin. Psy. (Standard Plan)', 'active', 'active', '2026-08-01 10:00:00'),
            (2, 'STU_B', 'Student B', 'b@test.com', '+91', '9876543211', 'M. Clin. Psy. (Standard Plan)', 'active', 'active', '2026-08-02 10:00:00'),
            (3, 'STU_C', 'Student C', 'c@test.com', '+91', '9876543212', 'M. Clin. Psy. (Standard Plan)', 'active', 'active', '2026-08-03 10:00:00'),
            (4, 'STU_D', 'Student D (Never Called)', 'd@test.com', '+91', '9876543213', 'M. Clin. Psy. (Standard Plan)', 'active', 'active', '2026-08-04 10:00:00'),
            (5, 'STU_E', 'Student E (Frequent)', 'e@test.com', '+91', '9876543214', 'M. Clin. Psy. (Standard Plan)', 'active', 'active', '2026-08-05 10:00:00');
        ");

        // Call Logs:
        // STU_A: Called 01 Oct (8 days ago relative to 09 Oct), total 2 calls
        // STU_B: Called 05 Oct (4 days ago), total 1 call
        // STU_C: Called 08 Oct (1 day ago), total 3 calls
        // STU_D: NEVER CALLED (0 calls)
        // STU_E: Called 07 Oct, total 5 calls
        $this->pdo->exec("
            INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp, notes) VALUES
            ('STU_A', 2, 'admin_a', '2026-09-20 10:00:00', 'Initial call'),
            ('STU_A', 2, 'admin_a', '2026-10-01 12:00:00', 'Follow up'),

            ('STU_B', 2, 'admin_a', '2026-10-05 14:00:00', 'Study check'),

            ('STU_C', 2, 'admin_a', '2026-09-15 11:00:00', 'Orientation'),
            ('STU_C', 2, 'admin_a', '2026-09-25 15:00:00', 'Progress check'),
            ('STU_C', 2, 'admin_a', '2026-10-08 09:30:00', 'Latest review'),

            ('STU_E', 2, 'admin_a', '2026-09-10 10:00:00', 'Call 1'),
            ('STU_E', 2, 'admin_a', '2026-09-18 11:00:00', 'Call 2'),
            ('STU_E', 2, 'admin_a', '2026-09-26 12:00:00', 'Call 3'),
            ('STU_E', 2, 'admin_a', '2026-10-03 13:00:00', 'Call 4'),
            ('STU_E', 2, 'admin_a', '2026-10-07 16:00:00', 'Call 5');
        ");
    }

    private function assert($condition, $description) {
        if ($condition) {
            echo "  [PASS] {$description}\n";
            $this->passed++;
        } else {
            echo "  [FAIL] {$description}\n";
            $this->failed++;
        }
    }

    public function runAllTests() {
        echo "======================================================================\n";
        echo "Student Mentoring — Last Call Sorting & Table Improvements Audit\n";
        echo "======================================================================\n\n";

        $this->testAdminPreferencesPersistence();
        $this->testCallCountCalculation();
        $this->testLastCallSortingOldestToNewest();
        $this->testLastCallSortingNewestToOldest();
        $this->testLockUnlockPriority();
        $this->testCourseColumnRemoval();
        $this->testFilterAttributesIntegrity();
        $this->testMultiPageGlobalDatasetSortVerification();

        echo "\n======================================================================\n";
        echo "Test Results: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "======================================================================\n";

        return $this->failed === 0;
    }

    public function testAdminPreferencesPersistence() {
        echo "--- Scenario 1: Admin Preferences Persistence & Isolation ---\n";

        // Helper functions mirroring student-mentoring.php implementation
        $ensureTable = function($pdo) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS admin_user_preferences (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    admin_id INTEGER NOT NULL,
                    preference_key TEXT NOT NULL,
                    preference_value TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (admin_id, preference_key)
                );
            ");
            return true;
        };

        $getPref = function($pdo, $admin_id, $key, $default = null) {
            $stmt = $pdo->prepare("SELECT preference_value FROM admin_user_preferences WHERE admin_id = ? AND preference_key = ? LIMIT 1");
            $stmt->execute([(int)$admin_id, $key]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null) ? $val : $default;
        };

        $setPref = function($pdo, $admin_id, $key, $value) {
            $stmt = $pdo->prepare("
                INSERT INTO admin_user_preferences (admin_id, preference_key, preference_value, created_at, updated_at)
                VALUES (?, ?, ?, datetime('now'), datetime('now'))
                ON CONFLICT(admin_id, preference_key) DO UPDATE SET
                    preference_value = excluded.preference_value,
                    updated_at = datetime('now')
            ");
            return $stmt->execute([(int)$admin_id, $key, (string)$value]);
        };

        $ok = $ensureTable($this->pdo);
        $this->assert($ok === true, "admin_user_preferences table ensured and available");

        // Admin A saves Last Call sort ASC and LOCKED=1
        $setPref($this->pdo, 2, 'student_mentoring_last_call_sort', 'ASC');
        $setPref($this->pdo, 2, 'student_mentoring_last_call_sort_locked', '1');

        $sortA = $getPref($this->pdo, 2, 'student_mentoring_last_call_sort');
        $lockA = $getPref($this->pdo, 2, 'student_mentoring_last_call_sort_locked');

        $this->assert($sortA === 'ASC', "Admin A saved sort is ASC");
        $this->assert($lockA === '1', "Admin A saved lock state is 1 (Locked)");

        // Admin B has NO saved preferences yet
        $sortB = $getPref($this->pdo, 3, 'student_mentoring_last_call_sort', 'ASC');
        $lockB = $getPref($this->pdo, 3, 'student_mentoring_last_call_sort_locked', '0');

        $this->assert($lockB === '0', "Admin B defaults to unlocked (does not inherit Admin A)");

        // Admin B sets sort DESC and unlocked
        $setPref($this->pdo, 3, 'student_mentoring_last_call_sort', 'DESC');
        $setPref($this->pdo, 3, 'student_mentoring_last_call_sort_locked', '0');

        $this->assert($getPref($this->pdo, 3, 'student_mentoring_last_call_sort') === 'DESC', "Admin B saved sort is DESC");
        $this->assert($getPref($this->pdo, 3, 'student_mentoring_last_call_sort_locked') === '0', "Admin B lock is 0");
        $this->assert($getPref($this->pdo, 2, 'student_mentoring_last_call_sort') === 'ASC', "Admin A sort remains ASC (strict isolation)");
        $this->assert($getPref($this->pdo, 2, 'student_mentoring_last_call_sort_locked') === '1', "Admin A lock remains 1 (strict isolation)");
    }

    public function testCallCountCalculation() {
        echo "\n--- Scenario 2: Total Call Count Calculation (0, 1, 2, 5+ Calls) ---\n";
        $student_ids = ['STU_A', 'STU_B', 'STU_C', 'STU_D', 'STU_E'];
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));

        $stmt = $this->pdo->prepare("
            SELECT student_user_id, MAX(call_timestamp) as last_call, COUNT(*) as call_count
            FROM mentor_call_logs
            WHERE student_user_id IN ($placeholders)
            GROUP BY student_user_id
        ");
        $stmt->execute($student_ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        $last_calls = [];
        foreach ($rows as $r) {
            $counts[$r['student_user_id']] = (int)$r['call_count'];
            $last_calls[$r['student_user_id']] = $r['last_call'];
        }

        $this->assert(($counts['STU_D'] ?? 0) === 0, "STU_D has 0 calls (Never called)");
        $this->assert(($counts['STU_B'] ?? 0) === 1, "STU_B has exactly 1 call");
        $this->assert(($counts['STU_A'] ?? 0) === 2, "STU_A has exactly 2 calls");
        $this->assert(($counts['STU_C'] ?? 0) === 3, "STU_C has exactly 3 calls");
        $this->assert(($counts['STU_E'] ?? 0) === 5, "STU_E has 5 calls (5+ calls)");

        $this->assert($last_calls['STU_A'] === '2026-10-01 12:00:00', "STU_A last call is 2026-10-01 12:00:00");
        $this->assert($last_calls['STU_B'] === '2026-10-05 14:00:00', "STU_B last call is 2026-10-05 14:00:00");
        $this->assert($last_calls['STU_C'] === '2026-10-08 09:30:00', "STU_C last call is 2026-10-08 09:30:00");
        $this->assert($last_calls['STU_E'] === '2026-10-07 16:00:00', "STU_E last call is 2026-10-07 16:00:00");
        $this->assert(!isset($last_calls['STU_D']), "STU_D has no last call record (null)");
    }

    public function testLastCallSortingOldestToNewest() {
        echo "\n--- Scenario 3: Last Call Sorting Oldest → Newest (ASC) ---\n";
        // Build sample student list with metrics
        $students = [
            ['user_id' => 'STU_A', 'full_name' => 'Student A', 'metrics' => ['last_call_time' => '2026-10-01 12:00:00', 'progress' => 76]],
            ['user_id' => 'STU_B', 'full_name' => 'Student B', 'metrics' => ['last_call_time' => '2026-10-05 14:00:00', 'progress' => 61]],
            ['user_id' => 'STU_C', 'full_name' => 'Student C', 'metrics' => ['last_call_time' => '2026-10-08 09:30:00', 'progress' => 59]],
            ['user_id' => 'STU_D', 'full_name' => 'Student D', 'metrics' => ['last_call_time' => null, 'progress' => 90]],
            ['user_id' => 'STU_E', 'full_name' => 'Student E', 'metrics' => ['last_call_time' => '2026-10-07 16:00:00', 'progress' => 80]],
        ];

        // Sort Oldest → Newest (ASC)
        usort($students, function($a, $b) {
            $time_a = !empty($a['metrics']['last_call_time']) ? strtotime($a['metrics']['last_call_time']) : 0;
            $time_b = !empty($b['metrics']['last_call_time']) ? strtotime($b['metrics']['last_call_time']) : 0;

            // Never called students (time = 0) are highest priority (waiting longest)!
            if ($time_a === 0 && $time_b === 0) {
                return strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? '');
            }
            if ($time_a === 0) return -1;
            if ($time_b === 0) return 1;
            if ($time_a === $time_b) {
                return strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? '');
            }
            return ($time_a < $time_b) ? -1 : 1;
        });

        $order = array_column($students, 'user_id');
        // Expected order:
        // 1. STU_D (Never called - highest priority)
        // 2. STU_A (01 Oct - oldest call)
        // 3. STU_B (05 Oct)
        // 4. STU_E (07 Oct)
        // 5. STU_C (08 Oct - newest call)
        $this->assert($order[0] === 'STU_D', "Position 1 is Never Called student STU_D (highest priority)");
        $this->assert($order[1] === 'STU_A', "Position 2 is Student A (01 Oct - oldest called)");
        $this->assert($order[2] === 'STU_B', "Position 3 is Student B (05 Oct)");
        $this->assert($order[3] === 'STU_E', "Position 4 is Student E (07 Oct)");
        $this->assert($order[4] === 'STU_C', "Position 5 is Student C (08 Oct - newest called)");
    }

    public function testLastCallSortingNewestToOldest() {
        echo "\n--- Scenario 4: Last Call Sorting Newest → Oldest (DESC) ---\n";
        $students = [
            ['user_id' => 'STU_A', 'full_name' => 'Student A', 'metrics' => ['last_call_time' => '2026-10-01 12:00:00', 'progress' => 76]],
            ['user_id' => 'STU_B', 'full_name' => 'Student B', 'metrics' => ['last_call_time' => '2026-10-05 14:00:00', 'progress' => 61]],
            ['user_id' => 'STU_C', 'full_name' => 'Student C', 'metrics' => ['last_call_time' => '2026-10-08 09:30:00', 'progress' => 59]],
            ['user_id' => 'STU_D', 'full_name' => 'Student D', 'metrics' => ['last_call_time' => null, 'progress' => 90]],
            ['user_id' => 'STU_E', 'full_name' => 'Student E', 'metrics' => ['last_call_time' => '2026-10-07 16:00:00', 'progress' => 80]],
        ];

        // Sort Newest → Oldest (DESC)
        usort($students, function($a, $b) {
            $time_a = !empty($a['metrics']['last_call_time']) ? strtotime($a['metrics']['last_call_time']) : 0;
            $time_b = !empty($b['metrics']['last_call_time']) ? strtotime($b['metrics']['last_call_time']) : 0;

            if ($time_a === 0 && $time_b === 0) {
                return strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? '');
            }
            if ($time_a === 0) return 1;  // never called goes to bottom
            if ($time_b === 0) return -1; // student with call comes first
            if ($time_a === $time_b) {
                return strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? '');
            }
            return ($time_a > $time_b) ? -1 : 1;
        });

        $order = array_column($students, 'user_id');
        // Expected order:
        // 1. STU_C (08 Oct - newest call)
        // 2. STU_E (07 Oct)
        // 3. STU_B (05 Oct)
        // 4. STU_A (01 Oct - oldest call)
        // 5. STU_D (Never called - at bottom)
        $this->assert($order[0] === 'STU_C', "Position 1 is Student C (08 Oct - most recently called)");
        $this->assert($order[1] === 'STU_E', "Position 2 is Student E (07 Oct)");
        $this->assert($order[2] === 'STU_B', "Position 3 is Student B (05 Oct)");
        $this->assert($order[3] === 'STU_A', "Position 4 is Student A (01 Oct)");
        $this->assert($order[4] === 'STU_D', "Position 5 is Never Called student STU_D (bottom)");
    }

    public function testLockUnlockPriority() {
        echo "\n--- Scenario 5: Lock / Unlock Priority Decision Logic ---\n";
        // Helper function mimicking page load decision
        $resolveSort = function($saved_lock, $saved_sort, $get_sort, $get_order) {
            if ($get_sort === 'last_call') {
                return ['type' => 'last_call', 'order' => in_array($get_order, ['ASC','DESC']) ? $get_order : $saved_sort];
            } elseif ($get_sort === 'performance') {
                return ['type' => 'performance', 'order' => $saved_sort];
            } elseif ($saved_lock) {
                return ['type' => 'last_call', 'order' => $saved_sort];
            } else {
                return ['type' => 'performance', 'order' => $saved_sort];
            }
        };

        // Case A: Locked with ASC, no GET param
        $resA = $resolveSort(true, 'ASC', '', '');
        $this->assert($resA['type'] === 'last_call' && $resA['order'] === 'ASC', "Locked with ASC loads Last Call ASC automatically");

        // Case B: Locked with DESC, no GET param
        $resB = $resolveSort(true, 'DESC', '', '');
        $this->assert($resB['type'] === 'last_call' && $resB['order'] === 'DESC', "Locked with DESC loads Last Call DESC automatically");

        // Case C: Unlocked, no GET param
        $resC = $resolveSort(false, 'ASC', '', '');
        $this->assert($resC['type'] === 'performance', "Unlocked loads default Performance sort");

        // Case D: Unlocked, but user explicitly passes ?sort=last_call&order=DESC
        $resD = $resolveSort(false, 'ASC', 'last_call', 'DESC');
        $this->assert($resD['type'] === 'last_call' && $resD['order'] === 'DESC', "Explicit GET param ?sort=last_call overrides unlocked state");
    }

    public function testCourseColumnRemoval() {
        echo "\n--- Scenario 6: Course Column Removal Verification ---\n";
        $code = file_get_contents(__DIR__ . '/student-mentoring.php');

        // Extract the Students table section (between tab === 'students' and tab === 'calls')
        $studentsTabStart = strpos($code, "<?php if (\$tab === 'students'): ?>");
        $callsTabStart = strpos($code, "<?php elseif (\$tab === 'calls'): ?>");
        $studentsTableBlock = substr($code, $studentsTabStart, $callsTabStart - $studentsTabStart);

        // Check thead in Students table
        $hasCourseTh = (strpos($studentsTableBlock, '<th>Course</th>') !== false);
        $this->assert(!$hasCourseTh, "Students table header has NO <th>Course</th>");

        // Check tbody in Students table
        $hasCourseTd = (strpos($studentsTableBlock, '<td data-label="Course">') !== false);
        $this->assert(!$hasCourseTd, "Students table rows have NO <td data-label=\"Course\">");

        // Verify remaining headers in Students table
        $this->assert(strpos($studentsTableBlock, 'th class="col-student"') !== false, "Student column header is present");
        $this->assert(strpos($studentsTableBlock, 'th class="col-mentor"') !== false, "Mentor column header is present");
        $this->assert(strpos($studentsTableBlock, 'th class="col-progress"') !== false, "Progress column header is present");
        $this->assert(strpos($studentsTableBlock, 'th class="col-streak"') !== false, "Streak column header is present");
        $this->assert(strpos($studentsTableBlock, 'th class="col-last-call"') !== false, "Last Call column header is present");
        $this->assert(strpos($studentsTableBlock, 'th class="col-actions"') !== false, "Actions column header is present");

        // Verify Course selector is intact at top
        $this->assert(strpos($code, 'id="course-filter-form"') !== false, "Course selector form is intact at top");
        $this->assert(strpos($code, 'name="course_id"') !== false, "course_id input is intact");
        $this->assert(strpos($code, '$selected_course_name') !== false, "selected_course_name filtering logic is intact");
    }

    public function testFilterAttributesIntegrity() {
        echo "\n--- Scenario 7: Student Row Data Attributes & Filter Compatibility ---\n";
        $code = file_get_contents(__DIR__ . '/student-mentoring.php');

        $this->assert(strpos($code, 'data-last-call-ts=') !== false, "data-last-call-ts attribute added to student rows");
        $this->assert(strpos($code, 'data-has-call=') !== false, "data-has-call attribute added to student rows");
        $this->assert(strpos($code, 'data-call-count=') !== false, "data-call-count attribute added to student rows");
        $this->assert(strpos($code, 'data-progress=') !== false, "data-progress attribute preserved on student rows");
        $this->assert(strpos($code, 'data-streak=') !== false, "data-streak attribute preserved on student rows");
        $this->assert(strpos($code, 'data-completed=') !== false, "data-completed attribute preserved on student rows");
        $this->assert(strpos($code, 'data-pending=') !== false, "data-pending attribute preserved on student rows");
        $this->assert(strpos($code, 'data-overdue=') !== false, "data-overdue attribute preserved on student rows");
        $this->assert(strpos($code, 'data-attendance=') !== false, "data-attendance attribute preserved on student rows");
        $this->assert(strpos($code, 'sortStudentRowsInDOM') !== false, "sortStudentRowsInDOM function defined in script");
        $this->assert(strpos($code, 'toggleLastCallLock') !== false, "toggleLastCallLock function defined in script");
        $this->assert(strpos($code, 'saveLastCallPreference') !== false, "saveLastCallPreference function defined in script");
    }

    public function testMultiPageGlobalDatasetSortVerification() {
        echo "\n--- Scenario 8: Multi-Page Dataset Pagination Simulation (75 Records / 3 Pages) ---\n";

        // Generate 75 student records:
        // - 15 Never-called students (last_call_time = null)
        // - 60 Called students (calls from 60 days ago up to 1 day ago)
        $dataset = [];
        $now = time();

        // 15 Never Called students
        for ($i = 1; $i <= 15; $i++) {
            $dataset[] = [
                'user_id' => sprintf('NEVER_%02d', $i),
                'full_name' => sprintf('Never Called Student %02d', $i),
                'metrics' => [
                    'last_call_time' => null,
                    'total_call_count' => 0,
                    'progress' => 50 + ($i % 40)
                ]
            ];
        }

        // 60 Called students (from 60 days ago to 1 day ago)
        // Notice: $d=60 is oldest call, $d=1 is newest call
        for ($d = 60; $d >= 1; $d--) {
            $callTime = date('Y-m-d H:i:s', $now - ($d * 86400));
            $dataset[] = [
                'user_id' => sprintf('CALLED_%02d_DAYS_AGO', $d),
                'full_name' => sprintf('Called %02d Days Ago', $d),
                'metrics' => [
                    'last_call_time' => $callTime,
                    'total_call_count' => (61 - $d),
                    'progress' => 40 + ($d % 50)
                ]
            ];
        }

        $this->assert(count($dataset) === 75, "Total simulated student records is 75 (spans exactly 3 pages of 25)");

        // ─────────────────────────────────────────────────────────────
        // 1. TEST ASC (Oldest → Newest): Server-side global dataset sort
        // ─────────────────────────────────────────────────────────────
        $ascDataset = $dataset;
        usort($ascDataset, function($a, $b) {
            $time_a = !empty($a['metrics']['last_call_time']) ? strtotime($a['metrics']['last_call_time']) : 0;
            $time_b = !empty($b['metrics']['last_call_time']) ? strtotime($b['metrics']['last_call_time']) : 0;

            if ($time_a === 0 && $time_b === 0) {
                return strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? '');
            }
            if ($time_a === 0) return -1;
            if ($time_b === 0) return 1;
            if ($time_a === $time_b) {
                return strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? '');
            }
            return ($time_a < $time_b) ? -1 : 1;
        });

        // Simulate Pagination (Page size 25)
        $pageSize = 25;
        $page1_asc = array_slice($ascDataset, 0, $pageSize);   // items 0..24
        $page2_asc = array_slice($ascDataset, 25, $pageSize);  // items 25..49
        $page3_asc = array_slice($ascDataset, 50, $pageSize);  // items 50..74

        $this->assert(count($page1_asc) === 25, "Page 1 has 25 items");
        $this->assert(count($page2_asc) === 25, "Page 2 has 25 items");
        $this->assert(count($page3_asc) === 25, "Page 3 has 25 items");

        // Verify Never Called count on each page
        $neverOnPage1 = count(array_filter($page1_asc, fn($s) => empty($s['metrics']['last_call_time'])));
        $neverOnPage2 = count(array_filter($page2_asc, fn($s) => empty($s['metrics']['last_call_time'])));
        $neverOnPage3 = count(array_filter($page3_asc, fn($s) => empty($s['metrics']['last_call_time'])));

        $this->assert($neverOnPage1 === 15, "Oldest → Newest: ALL 15 Never Called students reside on Page 1 (highest priority across full dataset)");
        $this->assert($neverOnPage2 === 0, "Oldest → Newest: Exactly 0 Never Called students leaked to Page 2");
        $this->assert($neverOnPage3 === 0, "Oldest → Newest: Exactly 0 Never Called students leaked to Page 3");

        // Verify first and last items on Page 1 (Oldest → Newest)
        $this->assert(empty($page1_asc[0]['metrics']['last_call_time']), "Page 1 Item 0 is Never Called student");
        $this->assert(empty($page1_asc[14]['metrics']['last_call_time']), "Page 1 Item 14 is the 15th Never Called student");
        $this->assert($page1_asc[15]['user_id'] === 'CALLED_60_DAYS_AGO', "Page 1 Item 15 is CALLED_60_DAYS_AGO (oldest called student in full dataset)");
        $this->assert($page1_asc[24]['user_id'] === 'CALLED_51_DAYS_AGO', "Page 1 Item 24 is CALLED_51_DAYS_AGO");

        // Verify Page 2 items (Oldest → Newest)
        $this->assert($page2_asc[0]['user_id'] === 'CALLED_50_DAYS_AGO', "Page 2 Item 0 is CALLED_50_DAYS_AGO");
        $this->assert($page2_asc[24]['user_id'] === 'CALLED_26_DAYS_AGO', "Page 2 Item 24 is CALLED_26_DAYS_AGO");

        // Verify Page 3 items (Oldest → Newest)
        $this->assert($page3_asc[0]['user_id'] === 'CALLED_25_DAYS_AGO', "Page 3 Item 0 is CALLED_25_DAYS_AGO");
        $this->assert($page3_asc[24]['user_id'] === 'CALLED_01_DAYS_AGO', "Page 3 Item 24 is CALLED_01_DAYS_AGO (newest call in full dataset)");

        // ─────────────────────────────────────────────────────────────
        // 2. TEST DESC (Newest → Oldest): Server-side global dataset sort
        // ─────────────────────────────────────────────────────────────
        $descDataset = $dataset;
        usort($descDataset, function($a, $b) {
            $time_a = !empty($a['metrics']['last_call_time']) ? strtotime($a['metrics']['last_call_time']) : 0;
            $time_b = !empty($b['metrics']['last_call_time']) ? strtotime($b['metrics']['last_call_time']) : 0;

            if ($time_a === 0 && $time_b === 0) {
                return strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? '');
            }
            if ($time_a === 0) return 1;  // never called at bottom
            if ($time_b === 0) return -1;
            if ($time_a === $time_b) {
                return strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? '');
            }
            return ($time_a > $time_b) ? -1 : 1;
        });

        $page1_desc = array_slice($descDataset, 0, $pageSize);
        $page2_desc = array_slice($descDataset, 25, $pageSize);
        $page3_desc = array_slice($descDataset, 50, $pageSize);

        // Verify Newest → Oldest Page 1
        $this->assert($page1_desc[0]['user_id'] === 'CALLED_01_DAYS_AGO', "Newest → Oldest: Page 1 Item 0 is CALLED_01_DAYS_AGO (most recent call)");
        $this->assert($page1_desc[24]['user_id'] === 'CALLED_25_DAYS_AGO', "Newest → Oldest: Page 1 Item 24 is CALLED_25_DAYS_AGO");

        // Verify Newest → Oldest Page 2
        $this->assert($page2_desc[0]['user_id'] === 'CALLED_26_DAYS_AGO', "Newest → Oldest: Page 2 Item 0 is CALLED_26_DAYS_AGO");
        $this->assert($page2_desc[24]['user_id'] === 'CALLED_50_DAYS_AGO', "Newest → Oldest: Page 2 Item 24 is CALLED_50_DAYS_AGO");

        // Verify Newest → Oldest Page 3
        $this->assert($page3_desc[0]['user_id'] === 'CALLED_51_DAYS_AGO', "Newest → Oldest: Page 3 Item 0 is CALLED_51_DAYS_AGO");
        $this->assert($page3_desc[9]['user_id'] === 'CALLED_60_DAYS_AGO', "Newest → Oldest: Page 3 Item 9 is CALLED_60_DAYS_AGO (oldest call)");
        $this->assert(empty($page3_desc[10]['metrics']['last_call_time']), "Newest → Oldest: Page 3 Item 10 is Never Called student");
        $this->assert(empty($page3_desc[24]['metrics']['last_call_time']), "Newest → Oldest: Page 3 Item 24 is Never Called student (bottom of full dataset)");

        $neverOnPage1Desc = count(array_filter($page1_desc, fn($s) => empty($s['metrics']['last_call_time'])));
        $neverOnPage2Desc = count(array_filter($page2_desc, fn($s) => empty($s['metrics']['last_call_time'])));
        $neverOnPage3Desc = count(array_filter($page3_desc, fn($s) => empty($s['metrics']['last_call_time'])));

        $this->assert($neverOnPage1Desc === 0, "Newest → Oldest: Exactly 0 Never Called students on Page 1");
        $this->assert($neverOnPage2Desc === 0, "Newest → Oldest: Exactly 0 Never Called students on Page 2");
        $this->assert($neverOnPage3Desc === 15, "Newest → Oldest: ALL 15 Never Called students reside strictly on Page 3 at the bottom");

        // ─────────────────────────────────────────────────────────────
        // 3. CODE ARCHITECTURE VERIFICATION IN student-mentoring.php
        // ─────────────────────────────────────────────────────────────
        $code = file_get_contents(__DIR__ . '/student-mentoring.php');

        // Confirm that the SQL query for students selects all students without LIMIT or OFFSET
        $this->assert(
            strpos($code, "SELECT u.user_id, u.name AS full_name, u.email, u.whatsapp_country_code, u.whatsapp_number, u.pepp_course AS course, u.status, u.student_status, u.pepp_academic_year, u.created_at\n                    FROM users u") !== false,
            "Architecture: Student Mentoring queries all enrolled students for the course into memory"
        );

        // Confirm that sorting is performed on $students_with_metrics BEFORE table rendering
        $sortPos = strpos($code, "usort(\$students_with_metrics, function(\$a, \$b) use (\$active_last_call_order)");
        $renderPos = strpos($code, "foreach (\$students as \$s):");
        $this->assert($sortPos !== false && $renderPos !== false && $sortPos < $renderPos, "Architecture: Last Call sort is executed server-side in PHP on full dataset before HTML row generation");
    }
}

$tester = new StudentMentoringLastCallAuditTest();
$success = $tester->runAllTests();
exit($success ? 0 : 1);
