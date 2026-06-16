<?php
/* Legacy AJAX endpoint. Student data is now rendered directly by
   student-details.php (authenticated). */
require_once 'includes/auth.php';
$uid = trim($_GET['user_id'] ?? $_GET['id'] ?? '');
header('Location: ' . ($uid ? 'student-details.php?user_id=' . urlencode($uid) : 'studentpage.php'));
exit();
