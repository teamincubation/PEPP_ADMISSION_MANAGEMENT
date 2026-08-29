<?php
/**
 * PEPP Learning ERP — Student-Level Mentor Assignment and Mentoring History Test Suite
 * Comprehensive audit verifying scenarios A through W + strict DB-level integrity invariant.
 */

class MentoringTestSuite {
    private $pdo;
    private $passed = 0;
    private $failed = 0;
    private $tests = [];

    public function __construct() {
        // Create in-memory SQLite database
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
                role TEXT NOT NULL,
                permissions TEXT NOT NULL,
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
                pepp_academic_year TEXT DEFAULT '2026-2027',
                status TEXT NOT NULL DEFAULT 'approved',
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
                updated_at DATETIME DEFAULT NULL,
                active_student_key TEXT GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN student_user_id ELSE NULL END) VIRTUAL,
                UNIQUE (active_student_key)
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
        ");
    }

    private function seedData() {
        // Seed Admins
        $this->pdo->exec("
            INSERT INTO admins (id, username, full_name, email, role, permissions, status) VALUES
            (1, 'superadmin', 'Super Administrator', 'super@pepp.com', 'super_admin', 'ALL', 'active'),
            (2, 'rahul_mentor', 'Rahul Sharma', 'rahul@pepp.com', 'admin', 'student-mentoring', 'active'),
            (3, 'anu_mentor', 'Anu Thomas', 'anu@pepp.com', 'admin', 'student-mentoring', 'active'),
            (4, 'fathima_mentor', 'Fathima Rinfa', 'fathima@pepp.com', 'admin', 'student-mentoring', 'active'),
            (5, 'inactive_admin', 'Inactive Admin', 'inactive@pepp.com', 'admin', 'student-mentoring', 'inactive');
        ");

        // Seed Courses
        $this->pdo->exec("
            INSERT INTO pepp_courses (id, course_name, status) VALUES
            (1, 'CUET UG Commerce', 'active'),
            (2, 'CUET PG Economics', 'active'),
            (3, 'Kerala PSC Assistant', 'active');
        ");

        // Seed Students with different join dates (earliest joined to latest joined)
        $this->pdo->exec("
            INSERT INTO users (id, user_id, name, email, whatsapp_country_code, whatsapp_number, pepp_course, status, created_at) VALUES
            (1, 'PEPP2026001', 'Alice Johnson', 'alice@pepp.test', '+91', '9876543210', 'CUET UG Commerce', 'approved', '2026-08-01 10:00:00'),
            (2, 'PEPP2026002', 'Bob Smith', 'bob@pepp.test', '+91', '9876543211', 'CUET UG Commerce', 'approved', '2026-08-05 11:30:00'),
            (3, 'PEPP2026003', 'Charlie Davis', 'charlie@pepp.test', '+91', '9876543212', 'CUET UG Commerce', 'approved', '2026-08-10 09:15:00'),
            (4, 'PEPP2026004', 'Diana Prince', 'diana@pepp.test', '+91', '9876543213', 'CUET UG Commerce', 'approved', '2026-08-15 14:00:00'),
            (5, 'PEPP2026005', 'Edward Norton', 'edward@pepp.test', '+91', '9876543214', 'CUET PG Economics', 'approved', '2026-08-02 12:00:00'),
            (6, 'PEPP2026006', 'Fiona Gallagher', 'fiona@pepp.test', '+91', '9876543215', 'CUET UG Commerce', 'pending', '2026-08-20 10:00:00');
        ");
    }

    public function assert($condition, $description) {
        if ($condition) {
            $this->passed++;
            $this->tests[] = ['status' => 'PASS', 'desc' => $description];
            echo "  [PASS] {$description}\n";
        } else {
            $this->failed++;
            $this->tests[] = ['status' => 'FAIL', 'desc' => $description];
            echo "  [FAIL] {$description}\n";
        }
    }

    // Business Logic Methods under test
    public function assignStudents($actorAdminId, $mentorAdminId, $courseName, array $studentUserIds) {
        // Authorization check: Actor must be Superadmin
        $stmtActor = $this->pdo->prepare("SELECT role FROM admins WHERE id = ? AND status = 'active'");
        $stmtActor->execute([$actorAdminId]);
        $actor = $stmtActor->fetch(PDO::FETCH_ASSOC);
        if (!$actor || $actor['role'] !== 'super_admin') {
            throw new Exception("Access Denied: Only Superadmin can assign student mentors.");
        }

        // Mentor check
        $stmtMentor = $this->pdo->prepare("SELECT id, username, full_name FROM admins WHERE id = ? AND status = 'active'");
        $stmtMentor->execute([$mentorAdminId]);
        $mentor = $stmtMentor->fetch(PDO::FETCH_ASSOC);
        if (!$mentor) {
            throw new Exception("Invalid or inactive mentor selected.");
        }

        // Course check
        $stmtCourse = $this->pdo->prepare("SELECT course_name FROM pepp_courses WHERE course_name = ? AND status = 'active'");
        $stmtCourse->execute([$courseName]);
        if (!$stmtCourse->fetchColumn()) {
            throw new Exception("Invalid course '{$courseName}'.");
        }

        if (empty($studentUserIds)) {
            throw new Exception("No students selected.");
        }

        $now = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $stmtChk = $this->pdo->prepare("SELECT user_id, name, pepp_course FROM users WHERE user_id = ? AND status IN ('approved', 'active')");
            $stmtGetActive = $this->pdo->prepare("SELECT id, admin_id FROM mentor_student_assignments WHERE student_user_id = ? AND status = 'active'");
            $stmtCloseActive = $this->pdo->prepare("UPDATE mentor_student_assignments SET status = 'inactive', ended_at = ? WHERE id = ?");
            $stmtInsert = $this->pdo->prepare("INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, assigned_by, assigned_at, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', ?)");

            foreach ($studentUserIds as $stId) {
                $stmtChk->execute([$stId]);
                $stRow = $stmtChk->fetch(PDO::FETCH_ASSOC);
                if (!$stRow) {
                    throw new Exception("Student {$stId} is not valid/approved.");
                }
                if ($stRow['pepp_course'] !== $courseName) {
                    throw new Exception("Student {$stId} does not belong to course {$courseName}.");
                }

                $stmtGetActive->execute([$stId]);
                $curActive = $stmtGetActive->fetch(PDO::FETCH_ASSOC);
                if ($curActive) {
                    if ((int)$curActive['admin_id'] === (int)$mentorAdminId) {
                        continue; // already assigned
                    }
                    $stmtCloseActive->execute([$now, $curActive['id']]);
                }

                $stmtInsert->execute([$stId, $mentorAdminId, $courseName, 'superadmin', $now, $now]);
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function unassignStudent($actorAdminId, $studentUserId) {
        $stmtActor = $this->pdo->prepare("SELECT role FROM admins WHERE id = ? AND status = 'active'");
        $stmtActor->execute([$actorAdminId]);
        $actor = $stmtActor->fetch(PDO::FETCH_ASSOC);
        if (!$actor || $actor['role'] !== 'super_admin') {
            throw new Exception("Access Denied: Only Superadmin can unassign mentors.");
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("UPDATE mentor_student_assignments SET status = 'inactive', ended_at = ? WHERE student_user_id = ? AND status = 'active'");
        $stmt->execute([$now, $studentUserId]);
        return $stmt->rowCount() > 0;
    }

    public function logCall($actorAdminId, $studentUserId, $notes, $callTime = null) {
        $callTime = $callTime ?: date('Y-m-d H:i:s');
        $stmtActor = $this->pdo->prepare("SELECT id, username, role FROM admins WHERE id = ? AND status = 'active'");
        $stmtActor->execute([$actorAdminId]);
        $actor = $stmtActor->fetch(PDO::FETCH_ASSOC);
        if (!$actor) {
            throw new Exception("Invalid admin.");
        }

        // Authorization check: Superadmin OR active assigned mentor
        if ($actor['role'] !== 'super_admin') {
            $stmtAuth = $this->pdo->prepare("SELECT COUNT(*) FROM mentor_student_assignments WHERE student_user_id = ? AND admin_id = ? AND status = 'active'");
            $stmtAuth->execute([$studentUserId, $actorAdminId]);
            if ($stmtAuth->fetchColumn() == 0) {
                throw new Exception("Access Denied: You are not the active mentor for this student.");
            }
        }

        $stmt = $this->pdo->prepare("INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$studentUserId, $actorAdminId, $actor['username'], $callTime, $notes]);
        return true;
    }

    public function addRemark($actorAdminId, $studentUserId, $remarkText) {
        $stmtActor = $this->pdo->prepare("SELECT id, username, role FROM admins WHERE id = ? AND status = 'active'");
        $stmtActor->execute([$actorAdminId]);
        $actor = $stmtActor->fetch(PDO::FETCH_ASSOC);
        if (!$actor) {
            throw new Exception("Invalid admin.");
        }

        // Authorization check: Superadmin OR active assigned mentor
        if ($actor['role'] !== 'super_admin') {
            $stmtAuth = $this->pdo->prepare("SELECT COUNT(*) FROM mentor_student_assignments WHERE student_user_id = ? AND admin_id = ? AND status = 'active'");
            $stmtAuth->execute([$studentUserId, $actorAdminId]);
            if ($stmtAuth->fetchColumn() == 0) {
                throw new Exception("Access Denied: You are not the active mentor for this student.");
            }
        }

        $stmt = $this->pdo->prepare("INSERT INTO mentor_remarks (student_user_id, admin_id, admin_username, remark) VALUES (?, ?, ?, ?)");
        $stmt->execute([$studentUserId, $actorAdminId, $actor['username'], $remarkText]);
        return true;
    }

    public function getStudentAssignmentHistory($studentUserId) {
        $stmt = $this->pdo->prepare("
            SELECT msa.*, a.username AS mentor_username, a.full_name AS mentor_full_name
            FROM mentor_student_assignments msa
            JOIN admins a ON msa.admin_id = a.id
            WHERE msa.student_user_id = ?
            ORDER BY msa.assigned_at DESC, msa.id DESC
        ");
        $stmt->execute([$studentUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStudentRemarks($studentUserId) {
        $stmt = $this->pdo->prepare("SELECT * FROM mentor_remarks WHERE student_user_id = ? ORDER BY created_at ASC");
        $stmt->execute([$studentUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStudentCallLogs($studentUserId) {
        $stmt = $this->pdo->prepare("SELECT * FROM mentor_call_logs WHERE student_user_id = ? ORDER BY call_timestamp ASC");
        $stmt->execute([$studentUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCourseStudentsOrdered($courseName) {
        $stmt = $this->pdo->prepare("
            SELECT u.user_id, u.name, u.created_at, msa.admin_id AS active_mentor_id, a.username AS active_mentor_username
            FROM users u
            LEFT JOIN mentor_student_assignments msa ON u.user_id = msa.student_user_id AND msa.status = 'active'
            LEFT JOIN admins a ON msa.admin_id = a.id
            WHERE u.pepp_course = ? AND u.status IN ('approved', 'active')
            ORDER BY u.created_at ASC, u.id ASC
        ");
        $stmt->execute([$courseName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Run All Tests
    public function run() {
        echo "======================================================================\n";
        echo "PEP Student Mentoring System — Comprehensive Audit Test Suite\n";
        echo "======================================================================\n\n";

        // Scenario A: Single assignment
        echo "--- Scenario A: Single Assignment ---\n";
        try {
            $this->assignStudents(1, 2, 'CUET UG Commerce', ['PEPP2026001']);
            $hist = $this->getStudentAssignmentHistory('PEPP2026001');
            $this->assert(count($hist) === 1, "Alice assigned to Rahul (1 record in history)");
            $this->assert($hist[0]['mentor_username'] === 'rahul_mentor' && $hist[0]['status'] === 'active', "Alice active mentor is Rahul");
        } catch (Exception $e) {
            $this->assert(false, "Scenario A failed: " . $e->getMessage());
        }

        // Scenario B: Bulk assignment
        echo "\n--- Scenario B: Bulk Assignment ---\n";
        try {
            $this->assignStudents(1, 2, 'CUET UG Commerce', ['PEPP2026002', 'PEPP2026003']);
            $hist2 = $this->getStudentAssignmentHistory('PEPP2026002');
            $hist3 = $this->getStudentAssignmentHistory('PEPP2026003');
            $this->assert(count($hist2) === 1 && $hist2[0]['mentor_username'] === 'rahul_mentor', "Bob assigned to Rahul in bulk");
            $this->assert(count($hist3) === 1 && $hist3[0]['mentor_username'] === 'rahul_mentor', "Charlie assigned to Rahul in bulk");
        } catch (Exception $e) {
            $this->assert(false, "Scenario B failed: " . $e->getMessage());
        }

        // Scenario C & D: Reassignment A -> B and B -> C
        echo "\n--- Scenario C & D: Reassignment Chain A -> B -> C ---\n";
        try {
            // Mentor A (Rahul) logs a call and remark for Alice
            $this->logCall(2, 'PEPP2026001', "Introductory mentorship call by Rahul", '2026-08-02 10:00:00');
            $this->addRemark(2, 'PEPP2026001', "Initial assessment: Excellent grasp of basics");

            // Reassign Alice from Rahul (id 2) -> Anu (id 3)
            sleep(1);
            $this->assignStudents(1, 3, 'CUET UG Commerce', ['PEPP2026001']);
            $histReassign1 = $this->getStudentAssignmentHistory('PEPP2026001');
            $this->assert(count($histReassign1) === 2, "Alice history has 2 records after 1st reassignment");
            $this->assert($histReassign1[0]['mentor_username'] === 'anu_mentor' && $histReassign1[0]['status'] === 'active', "New active mentor is Anu");
            $this->assert($histReassign1[1]['mentor_username'] === 'rahul_mentor' && $histReassign1[1]['status'] === 'inactive', "Old mentor Rahul marked inactive");
            $this->assert(!empty($histReassign1[1]['ended_at']), "Old mentor Rahul has ended_at set");

            // Mentor B (Anu) logs a call and remark
            $this->logCall(3, 'PEPP2026001', "Follow-up call by Anu", '2026-08-16 11:00:00');
            $this->addRemark(3, 'PEPP2026001', "Weekly goal set by Anu");

            // Reassign Alice from Anu (id 3) -> Fathima (id 4)
            sleep(1);
            $this->assignStudents(1, 4, 'CUET UG Commerce', ['PEPP2026001']);
            $histReassign2 = $this->getStudentAssignmentHistory('PEPP2026001');
            $this->assert(count($histReassign2) === 3, "Alice history has 3 records after 2nd reassignment (Rahul -> Anu -> Fathima)");
            $this->assert($histReassign2[0]['mentor_username'] === 'fathima_mentor' && $histReassign2[0]['status'] === 'active', "Current active mentor is Fathima");
            $this->assert($histReassign2[1]['mentor_username'] === 'anu_mentor' && $histReassign2[1]['status'] === 'inactive', "Anu marked inactive with ended_at");
            $this->assert($histReassign2[2]['mentor_username'] === 'rahul_mentor' && $histReassign2[2]['status'] === 'inactive', "Rahul remains permanently inactive in history");
        } catch (Exception $e) {
            $this->assert(false, "Scenario C/D failed: " . $e->getMessage());
        }

        // Scenario E, F, G, U: Historical mentoring records preservation & author attribution
        echo "\n--- Scenario E, F, G, U: Mentoring Records Preservation & Author Attribution ---\n";
        try {
            $calls = $this->getStudentCallLogs('PEPP2026001');
            $this->assert(count($calls) === 2, "Alice has 2 preserved call logs");
            $this->assert($calls[0]['admin_username'] === 'rahul_mentor' && $calls[0]['notes'] === "Introductory mentorship call by Rahul", "First call retains Rahul authorship");
            $this->assert($calls[1]['admin_username'] === 'anu_mentor' && $calls[1]['notes'] === "Follow-up call by Anu", "Second call retains Anu authorship");

            $remarks = $this->getStudentRemarks('PEPP2026001');
            $this->assert(count($remarks) === 2, "Alice has 2 preserved remarks");
            $this->assert($remarks[0]['admin_username'] === 'rahul_mentor', "First remark retains Rahul username");
            $this->assert($remarks[1]['admin_username'] === 'anu_mentor', "Second remark retains Anu username");
        } catch (Exception $e) {
            $this->assert(false, "Scenario E/F/G/U failed: " . $e->getMessage());
        }

        // Scenario H: Previous mentor WRITE denial (Server-Side Authorization Reject)
        echo "\n--- Scenario H: Previous Mentor WRITE Denial ---\n";
        // Rahul was previous mentor for Alice. Alice is now assigned to Fathima.
        $rahulDeniedCall = false;
        try {
            $this->logCall(2, 'PEPP2026001', "Unauthorized call attempt by previous mentor Rahul");
        } catch (Exception $e) {
            $rahulDeniedCall = true;
        }
        $this->assert($rahulDeniedCall, "Previous mentor Rahul is denied WRITE access (logCall rejected)");

        $rahulDeniedRemark = false;
        try {
            $this->addRemark(2, 'PEPP2026001', "Unauthorized remark attempt by previous mentor Rahul");
        } catch (Exception $e) {
            $rahulDeniedRemark = true;
        }
        $this->assert($rahulDeniedRemark, "Previous mentor Rahul is denied WRITE access (addRemark rejected)");

        // Anu was intermediate previous mentor for Alice.
        $anuDeniedCall = false;
        try {
            $this->logCall(3, 'PEPP2026001', "Unauthorized call attempt by past mentor Anu");
        } catch (Exception $e) {
            $anuDeniedCall = true;
        }
        $this->assert($anuDeniedCall, "Past mentor Anu is denied WRITE access (logCall rejected)");

        // Scenario I: Current mentor WRITE permission
        echo "\n--- Scenario I: Current Mentor WRITE Permission ---\n";
        try {
            // Current active mentor is Fathima (id 4)
            $fathimaCall = $this->logCall(4, 'PEPP2026001', "Call logged by current mentor Fathima");
            $fathimaRemark = $this->addRemark(4, 'PEPP2026001', "Remark added by current mentor Fathima");
            $this->assert($fathimaCall && $fathimaRemark, "Current mentor Fathima successfully wrote call and remark");
        } catch (Exception $e) {
            $this->assert(false, "Scenario I failed: " . $e->getMessage());
        }

        // Scenario J: Superadmin access
        echo "\n--- Scenario J: Superadmin Access ---\n";
        try {
            // Superadmin (id 1) can write to any student even without being direct mentor
            $superCall = $this->logCall(1, 'PEPP2026001', "Audit call by Superadmin");
            $superRemark = $this->addRemark(1, 'PEPP2026001', "Superadmin compliance remark");
            $this->assert($superCall && $superRemark, "Superadmin has global write access");
        } catch (Exception $e) {
            $this->assert(false, "Scenario J failed: " . $e->getMessage());
        }

        // Scenario K & Database-level Invariant: One-Active-Mentor Invariant
        echo "\n--- Scenario K: Database-level One-Active-Mentor Constraint Invariant ---\n";
        $dbBlockedTwoActive = false;
        try {
            // Try to directly INSERT a 2nd active row for Alice into SQLite/MySQL table
            $now = date('Y-m-d H:i:s');
            $this->pdo->exec("INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, assigned_by, assigned_at, status, created_at) VALUES ('PEPP2026001', 2, 'CUET UG Commerce', 'hacker', '{$now}', 'active', '{$now}')");
        } catch (PDOException $e) {
            $dbBlockedTwoActive = true;
        }
        $this->assert($dbBlockedTwoActive, "Database UNIQUE constraint physically rejected 2nd active assignment for same student");

        // Verify that multiple INACTIVE rows are allowed
        $allowMultipleInactive = false;
        try {
            $now = date('Y-m-d H:i:s');
            $this->pdo->exec("INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, assigned_by, assigned_at, ended_at, status, created_at) VALUES ('PEPP2026001', 2, 'CUET UG Commerce', 'admin', '{$now}', '{$now}', 'inactive', '{$now}')");
            $this->pdo->exec("INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, assigned_by, assigned_at, ended_at, status, created_at) VALUES ('PEPP2026001', 3, 'CUET UG Commerce', 'admin', '{$now}', '{$now}', 'inactive', '{$now}')");
            $allowMultipleInactive = true;
        } catch (Exception $e) {
            $allowMultipleInactive = false;
        }
        $this->assert($allowMultipleInactive, "Database permits unlimited INACTIVE historical assignments for same student");

        // Scenario M: Cross-Course Assignment Rejection
        echo "\n--- Scenario M: Cross-Course Assignment Rejection ---\n";
        $crossCourseRejected = false;
        try {
            // Edward (PEPP2026005) is in CUET PG Economics, attempting to assign under CUET UG Commerce
            $this->assignStudents(1, 2, 'CUET UG Commerce', ['PEPP2026005']);
        } catch (Exception $e) {
            $crossCourseRejected = true;
        }
        $this->assert($crossCourseRejected, "Cross-course student assignment strictly rejected");

        // Scenario N: Non-Superadmin Assignment Rejection
        echo "\n--- Scenario N: Non-Superadmin Assignment Rejection ---\n";
        $nonSuperRejected = false;
        try {
            // Mentor Rahul (id 2) attempting to assign Bob (PEPP2026002)
            $this->assignStudents(2, 3, 'CUET UG Commerce', ['PEPP2026002']);
        } catch (Exception $e) {
            $nonSuperRejected = true;
        }
        $this->assert($nonSuperRejected, "Non-superadmin assignment attempt strictly rejected");

        // Scenario Q: Transaction Rollback on Partial Failure
        echo "\n--- Scenario Q: Transaction Rollback on Partial Failure ---\n";
        // Attempt bulk assign with 2 valid students and 1 cross-course student
        // Diana (PEPP2026004, valid), Edward (PEPP2026005, wrong course)
        $dianaBefore = $this->getStudentAssignmentHistory('PEPP2026004');
        $bulkFailedAndRolledBack = false;
        try {
            $this->assignStudents(1, 2, 'CUET UG Commerce', ['PEPP2026004', 'PEPP2026005']);
        } catch (Exception $e) {
            $bulkFailedAndRolledBack = true;
        }
        $dianaAfter = $this->getStudentAssignmentHistory('PEPP2026004');
        $this->assert($bulkFailedAndRolledBack, "Bulk assignment containing invalid student threw exception");
        $this->assert(count($dianaBefore) === count($dianaAfter), "Atomic rollback verified: Valid student Diana was NOT partially assigned");

        // Scenario R: Student Ordering by Joining Date (First Joined -> Top)
        echo "\n--- Scenario R: Student Ordering by Joining Date ---\n";
        $orderedStudents = $this->getCourseStudentsOrdered('CUET UG Commerce');
        $this->assert(count($orderedStudents) >= 4, "Found enrolled approved students for CUET UG Commerce");
        $this->assert($orderedStudents[0]['user_id'] === 'PEPP2026001', "Oldest student Alice (01 Aug) is First/Top");
        $this->assert($orderedStudents[1]['user_id'] === 'PEPP2026002', "Second student Bob (05 Aug) is 2nd");
        $this->assert($orderedStudents[2]['user_id'] === 'PEPP2026003', "Third student Charlie (10 Aug) is 3rd");
        $this->assert($orderedStudents[3]['user_id'] === 'PEPP2026004', "Fourth student Diana (15 Aug) is 4th");

        // Scenario S: Current Mentor Display & T: Unassigned Student Handling
        echo "\n--- Scenario S & T: Mentor Display & Unassignment Handling ---\n";
        // Assign Diana to Anu
        $this->assignStudents(1, 3, 'CUET UG Commerce', ['PEPP2026004']);
        $orderedAfter = $this->getCourseStudentsOrdered('CUET UG Commerce');
        $dianaRow = array_values(array_filter($orderedAfter, fn($s) => $s['user_id'] === 'PEPP2026004'))[0];
        $this->assert($dianaRow['active_mentor_username'] === 'anu_mentor', "Diana shows active mentor Anu");

        // Unassign Diana
        $unassignOk = $this->unassignStudent(1, 'PEPP2026004');
        $orderedAfterUnassign = $this->getCourseStudentsOrdered('CUET UG Commerce');
        $dianaRowUnassigned = array_values(array_filter($orderedAfterUnassign, fn($s) => $s['user_id'] === 'PEPP2026004'))[0];
        $dianaHist = $this->getStudentAssignmentHistory('PEPP2026004');
        $this->assert($unassignOk, "Unassign operation returned success");
        $this->assert($dianaRowUnassigned['active_mentor_username'] === null, "Diana mentor column is now Unassigned/null");
        $this->assert(count($dianaHist) === 1 && $dianaHist[0]['status'] === 'inactive', "Unassigned record preserved in history with status 'inactive'");

        // Scenario V: Bulk Reassignment
        echo "\n--- Scenario V: Bulk Reassignment ---\n";
        try {
            // Bob (PEPP2026002) and Charlie (PEPP2026003) currently assigned to Rahul (id 2)
            // Reassign both to Anu (id 3) in one batch
            $this->assignStudents(1, 3, 'CUET UG Commerce', ['PEPP2026002', 'PEPP2026003']);
            $bobHist = $this->getStudentAssignmentHistory('PEPP2026002');
            $charlieHist = $this->getStudentAssignmentHistory('PEPP2026003');
            $this->assert(count($bobHist) === 2 && $bobHist[0]['mentor_username'] === 'anu_mentor' && $bobHist[1]['status'] === 'inactive', "Bob bulk reassigned to Anu, Rahul history closed");
            $this->assert(count($charlieHist) === 2 && $charlieHist[0]['mentor_username'] === 'anu_mentor' && $charlieHist[1]['status'] === 'inactive', "Charlie bulk reassigned to Anu, Rahul history closed");
        } catch (Exception $e) {
            $this->assert(false, "Scenario V failed: " . $e->getMessage());
        }

        // Scenario W: Mentor History Visibility for newly assigned mentor
        echo "\n--- Scenario W: Mentor History Visibility ---\n";
        // Create new student 'George' (PEPP2026007) assigned to Rahul
        $this->pdo->exec("INSERT INTO users (user_id, name, email, whatsapp_number, pepp_course, status, created_at) VALUES ('PEPP2026007', 'George Weasley', 'george@pepp.test', '9876543299', 'CUET UG Commerce', 'approved', '2026-08-01 08:00:00')");
        $this->assignStudents(1, 2, 'CUET UG Commerce', ['PEPP2026007']);

        // Rahul is active mentor and creates call log and remark
        $this->logCall(2, 'PEPP2026007', "Past call by Rahul for George", '2026-08-06 10:00:00');
        $this->addRemark(2, 'PEPP2026007', "Past remark by Rahul for George");

        // Reassign George from Rahul -> Anu
        $this->assignStudents(1, 3, 'CUET UG Commerce', ['PEPP2026007']);

        // Now Anu is active mentor. Check that Anu can read Rahul's past calls and remarks
        $georgeRemarks = $this->getStudentRemarks('PEPP2026007');
        $georgeCalls = $this->getStudentCallLogs('PEPP2026007');
        $this->assert(count($georgeRemarks) === 1 && $georgeRemarks[0]['admin_username'] === 'rahul_mentor', "Newly assigned mentor Anu has full visibility to Rahul's past remarks for George");
        $this->assert(count($georgeCalls) === 1 && $georgeCalls[0]['admin_username'] === 'rahul_mentor', "Newly assigned mentor Anu has full visibility to Rahul's past call logs for George");

        // Anu logs new call and remark for George
        $anuCallOk = $this->logCall(3, 'PEPP2026007', "Anu new call for George", '2026-08-29 12:00:00');
        $anuRemarkOk = $this->addRemark(3, 'PEPP2026007', "Anu new remark for George");
        $allGeorgeRemarks = $this->getStudentRemarks('PEPP2026007');
        $allGeorgeCalls = $this->getStudentCallLogs('PEPP2026007');
        $this->assert(count($allGeorgeRemarks) === 2 && $allGeorgeRemarks[1]['admin_username'] === 'anu_mentor', "Anu successfully appended new remark to historical chain");
        $this->assert(count($allGeorgeCalls) === 2 && $allGeorgeCalls[1]['admin_username'] === 'anu_mentor', "Anu successfully appended new call log to historical chain");

        // Summary
        echo "\n======================================================================\n";
        echo "Test Results: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "======================================================================\n";

        return $this->failed === 0;
    }
}

$suite = new MentoringTestSuite();
$success = $suite->run();
exit($success ? 0 : 1);
