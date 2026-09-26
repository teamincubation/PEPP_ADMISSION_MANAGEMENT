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

$mediaId = $msg['media_id'];
$mimeType = $msg['media_mime_type'] ?: 'image/jpeg';

// Determine default file extension based on message_type and MIME
$defaultExt = '.jpg';
$mType = $msg['message_type'] ?? '';
if ($mType === 'audio' || strpos($mimeType, 'audio/') === 0) {
    $defaultExt = (strpos($mimeType, 'ogg') !== false) ? '.ogg' : ((strpos($mimeType, 'mp4') !== false) ? '.m4a' : '.mp3');
} elseif ($mType === 'video' || strpos($mimeType, 'video/') === 0) {
    $defaultExt = '.mp4';
} elseif ($mType === 'document' || strpos($mimeType, 'pdf') !== false) {
    $defaultExt = '.pdf';
} elseif ($mType === 'sticker' || strpos($mimeType, 'webp') !== false) {
    $defaultExt = '.webp';
}

$filename = $msg['media_filename'] ?: ($mediaId . $defaultExt);

// 4. Check cache directory and serve if cached
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
    // 5. Download from Meta API using provider
    try {
        require_once __DIR__ . '/../../../includes/communication/CommunicationEngine.php';
        $engine = CommunicationEngine::getInstance($pdo);
        $provider = $engine->getProvider('whatsapp');

        $res = $provider->downloadMedia($mediaId);
        if (!$res) {
            http_response_code(502);
            echo "Media unavailable";
            exit;
        }

        $data = $res['data'];
        $mimeType = $res['mime_type'];
        // Save to cache
        file_put_contents($cacheFile, $data);
    } catch (Exception $e) {
        http_response_code(500);
        echo "Media unavailable";
        exit;
    }
}

// 6. Output media
$safeMime = preg_replace('/[^\w\/\-\+\.\;= ]/', '', $mimeType) ?: 'application/octet-stream';
$safeFilename = str_replace(['"', "\r", "\n"], '', basename($filename));

header('Content-Type: ' . $safeMime);
header('Content-Length: ' . strlen($data));
header('X-Content-Type-Options: nosniff');
// Allow browser caching to prevent repeated download during polling
header('Cache-Control: private, max-age=86400');

$isDownload = isset($_GET['download']) && $_GET['download'] === '1';
if ($isDownload) {
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
}

echo $data;
exit;
