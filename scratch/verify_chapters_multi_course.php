<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username']  = 'superadmin';
$_SESSION['admin_role']      = 'super_admin';

require_once '../config/database.php';

// Let's create a test chapter for courses 1, 2 and 3
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'save_chapters';
$_POST['academic_year'] = '2026-27';
$_POST['target_courses'] = [1, 2, 3];
$_POST['entry_mode'] = 'manual';
$_POST['chap_name'] = ['Verification Chapter X'];
$_POST['csrf_token'] = $_SESSION['csrf_token'] ?? '';
if (empty($_POST['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_POST['csrf_token'] = $_SESSION['csrf_token'];
}

ob_start();
include '../studyplan-chapters.php';
$html = ob_get_clean();

// Check if only one row was inserted for Verification Chapter X
$stmt = $pdo->prepare("SELECT COUNT(*) FROM study_plan_chapters WHERE academic_year = ? AND chapter_name = ?");
$stmt->execute(['2026-27', 'Verification Chapter X']);
$count = (int)$stmt->fetchColumn();

echo "CHAPTER_COUNT: " . $count . "\n";

// Fetch the row to see course_id
$stmt_row = $pdo->prepare("SELECT course_id FROM study_plan_chapters WHERE academic_year = ? AND chapter_name = ?");
$stmt_row->execute(['2026-27', 'Verification Chapter X']);
$row = $stmt_row->fetch();
echo "COURSE_IDS: " . ($row['course_id'] ?? 'NONE') . "\n";

// Clean up
$pdo->exec("DELETE FROM study_plan_chapters WHERE chapter_name = 'Verification Chapter X'");

if ($count === 1 && $row['course_id'] === '1,2,3') {
    echo "SUCCESS: Comma-separated multi-course single chapter mapping works perfectly!\n";
} else {
    echo "FAILED: chapter count or course_id did not match.\n";
}
