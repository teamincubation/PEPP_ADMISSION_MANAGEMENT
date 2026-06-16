<?php
session_start();

if ($_POST && isset($_POST['registration_time'])) {
    $registration_time = $_POST['registration_time'];
    $formatted_time = date('d M Y, h:i A', strtotime($registration_time));
    
    // Get user's WhatsApp number from session or database
    $whatsapp_number = $_SESSION['user_whatsapp'] ?? '';
    
    // Create WhatsApp message with timestamp
    $message = "Hello! I have submitted my registration form for PEPP Learning on {$formatted_time}. Please confirm my registration. Thank you!";
    
    // WhatsApp Web URL
    $whatsapp_url = "https://wa.me/{$whatsapp_number}?text=" . urlencode($message);
    
    // Redirect to WhatsApp
    header("Location: {$whatsapp_url}");
    exit;
}

// Redirect back if accessed directly
header('Location: register.php');
exit;
?>
