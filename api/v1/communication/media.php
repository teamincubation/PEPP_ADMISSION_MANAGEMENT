<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';

// 1. Verify active admin session
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo "Access Denied";
    exit;
}

// 2. Verify permissions
if (!is_super_admin() && !can_access('whatsapp-inbox') && !can_access('communication')) {
    http_response_code(403);
    echo "Access Denied";
    exit;
}

$msgId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($msgId <= 0) {
    http_response_code(400);
    echo "Invalid message ID";
    exit;
}

// 3. Retrieve and verify the requested message record
$stmt = $pdo->prepare("SELECT wm.*, wc.wa_phone_number, wc.student_uid FROM whatsapp_messages wm JOIN whatsapp_conversations wc ON wm.conversation_id = wc.id WHERE wm.id = ? LIMIT 1");
$stmt->execute([$msgId]);
$msg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$msg) {
    http_response_code(404);
    echo "Message not found";
    exit;
}

if (empty($msg['media_id'])) {
    http_response_code(400);
    echo "No media associated with this message";
    exit;
}

// 4. Verify conversation authorization for non-superadmins
if (!is_super_admin()) {
    $adminId = $_SESSION['admin_id'] ?? 0;
    if (!empty($msg['student_uid'])) {
        // Fetch the student's assigned course
        $stmtStu = $pdo->prepare("SELECT pepp_course FROM users WHERE user_id = ? LIMIT 1");
        $stmtStu->execute([$msg['student_uid']]);
        $studentCourse = $stmtStu->fetchColumn();
        
        if ($studentCourse) {
            // Verify if this admin is explicitly assigned to this course
            $stmtAuth = $pdo->prepare("SELECT COUNT(*) FROM mentor_course_assignments WHERE admin_id = ? AND LOWER(TRIM(course_name)) = LOWER(TRIM(?))");
            $stmtAuth->execute([$adminId, $studentCourse]);
            $isAssigned = ($stmtAuth->fetchColumn() > 0);
            
            if (!$isAssigned) {
                http_response_code(403);
                echo "Access Denied: You are not assigned to this student's course.";
                exit;
            }
        }
    }
}

$mediaId = $msg['media_id'];
$mimeType = $msg['media_mime_type'] ?: 'image/jpeg';
$filename = $msg['media_filename'] ?: ($mediaId . '.jpg');

// 5. Check cache directory and serve if cached
$cacheDir = __DIR__ . '/../../../uploads/whatsapp_media';
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0755, true);
    // Write .htaccess to prevent directory indexing and direct public access
    $htaccessContent = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>";
    file_put_contents($cacheDir . '/.htaccess', $htaccessContent);
}

$cacheFile = $cacheDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $mediaId);

if (file_exists($cacheFile)) {
    $data = file_get_contents($cacheFile);
} else {
    // 6. Download from Meta API using provider
    try {
        require_once __DIR__ . '/../../../includes/communication/CommunicationEngine.php';
        $engine = CommunicationEngine::getInstance($pdo);
        $provider = $engine->getProvider('whatsapp');
        
        $res = $provider->downloadMedia($mediaId);
        if (!$res) {
            http_response_code(502);
            echo "Image unavailable";
            exit;
        }
        
        $data = $res['data'];
        $mimeType = $res['mime_type'];
        // Save to cache
        file_put_contents($cacheFile, $data);
    } catch (Exception $e) {
        http_response_code(500);
        echo "Image unavailable";
        exit;
    }
}

// 7. Output image
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . strlen($data));
// Allow browser caching to prevent repeated download during polling
header('Cache-Control: private, max-age=86400');

$isDownload = isset($_GET['download']) && $_GET['download'] === '1';
if ($isDownload) {
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
}

echo $data;
exit;
