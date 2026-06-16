<?php
/**
 * Shared file helper for PEPP admissions.
 * Handles file compression, upload, path management, and replacing old files.
 */

if (!function_exists('compress_image')) {
    function compress_image($source_path, $destination_path, $quality = 80) {
        $info = @getimagesize($source_path);
        if ($info === false) {
            return @copy($source_path, $destination_path);
        }
        
        $mime = $info['mime'];
        $success = false;
        switch ($mime) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($source_path);
                if ($image) {
                    if (function_exists('exif_read_data')) {
                        $exif = @exif_read_data($source_path);
                        if (!empty($exif['Orientation'])) {
                            switch ($exif['Orientation']) {
                                case 3: $image = imagerotate($image, 180, 0); break;
                                case 6: $image = imagerotate($image, -90, 0); break;
                                case 8: $image = imagerotate($image, 90, 0); break;
                            }
                        }
                    }
                    $success = @imagejpeg($image, $destination_path, $quality);
                    @imagedestroy($image);
                }
                break;
            case 'image/png':
                $image = @imagecreatefrompng($source_path);
                if ($image) {
                    @imagealphablending($image, false);
                    @imagesavealpha($image, true);
                    $png_quality = 7; // 0 (no compression) to 9
                    $success = @imagepng($image, $destination_path, $png_quality);
                    @imagedestroy($image);
                }
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($source_path);
                    if ($image) {
                        $success = @imagewebp($image, $destination_path, $quality);
                        @imagedestroy($image);
                    }
                }
                break;
        }
        
        if (!$success) {
            return @copy($source_path, $destination_path);
        }
        return true;
    }
}

if (!function_exists('handle_file_upload_with_replace')) {
    function handle_file_upload_with_replace($field, $sub_dir, $old_db_path = null, $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'pdf']) {
        if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts, true)) {
            return null;
        }
        
        // 5MB limit
        if ($_FILES[$field]['size'] > 5 * 1024 * 1024) {
            return null;
        }
        
        // Destination directory is outside the admissions folder:
        // __DIR__ is includes/
        $base_dir = dirname(__DIR__) . '/../uploads';
        $target_dir = $base_dir . '/' . trim($sub_dir, '/');
        
        if (!is_dir($target_dir)) {
            @mkdir($target_dir, 0755, true);
        }
        
        $filename = uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES[$field]['name']));
        $target_path = $target_dir . '/' . $filename;
        $db_path = 'uploads/' . trim($sub_dir, '/') . '/' . $filename;
        
        $temp_path = $_FILES[$field]['tmp_name'];
        
        $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
        if ($is_image) {
            $uploaded = compress_image($temp_path, $target_path, 80);
        } else {
            $uploaded = @move_uploaded_file($temp_path, $target_path);
        }
        
        if ($uploaded) {
            // Success! Delete the old file if it was specified and exists
            if ($old_db_path && strpos($old_db_path, 'uploads/') === 0) {
                $old_file_path = dirname(__DIR__) . '/../' . $old_db_path;
                if (file_exists($old_file_path)) {
                    @unlink($old_file_path);
                }
            }
            return $db_path;
        }
        
        return null;
    }
}
