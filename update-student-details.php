<?php
/* SECURITY FIX: the old version of this AJAX endpoint had NO authentication,
   allowing anyone to modify student records. Editing now happens inside
   student-details.php (authenticated + CSRF protected). */
require_once 'includes/auth.php';
header('Location: studentpage.php');
exit();
