<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

require_permission('cards');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        exit('Security token mismatch.');
    }
    
    $img = $_POST['image_data'] ?? '';
    $format = $_POST['format'] ?? 'png';
    $filename = $_POST['filename'] ?? 'card';
    
    if (preg_match('/^data:image\/(\w+);base64,/', $img, $type)) {
        $data = substr($img, strpos($img, ',') + 1);
        $data = base64_decode($data);
        
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $filename);
        
        $mime = 'image/png';
        if ($format === 'jpg' || $format === 'jpeg') {
            $mime = 'image/jpeg';
            $format = 'jpg';
        } elseif ($format === 'webp') {
            $mime = 'image/webp';
        } else {
            $format = 'png';
        }
        
        // Log generation event
        try {
            track_record($pdo, 'system', 'card_generated', "Generated card template format $format: $filename", $admin_username);
            log_admin_activity($pdo, $admin_username, 'card_generated', "Generated custom card: $filename ($format)");
        } catch (Exception $e) {}
        
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '.' . $format . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }
}
http_response_code(400);
exit('Invalid request.');
