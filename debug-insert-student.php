<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $user_id = 'PEPP' . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Create dummy image files if they don't exist
    if (!file_exists('uploads/payments')) {
        mkdir('uploads/payments', 0777, true);
    }
    if (!file_exists('uploads/photos')) {
        mkdir('uploads/photos', 0777, true);
    }
    
    file_put_contents('uploads/payments/mock-screenshot.jpg', 'mock_data');
    file_put_contents('uploads/photos/mock-photo.jpg', 'mock_data');

    $stmt = $pdo->prepare("
        INSERT INTO users (
            name, gender, date_of_birth, whatsapp_country_code, whatsapp_number, 
            mobile_same_as_whatsapp, mobile_number, emergency_contact, email,
            college_school, course, university_board, remaining_semesters,
            postal_address, postal_pincode, state, district, place_post_office,
            pepp_course, pepp_academic_year, paid_amount, paid_date,
            payment_screenshot, user_photo, instagram_id, how_know_pepp,
            terms_agreed, user_id, ip_address, phone,
            applied_coupon, referral_code, coupon_discount, status, submit_datetime
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    
    $stmt->execute([
        'Adnan Test', 'Male', '1996-02-10',
        '+91', '9567276458',
        'yes', '',
        '8078239589', 'adnanmongam@gmail.com',
        'Incubation', 'Administration', 'Other',
        'Already Completed',
        'Test Address', '673642', 'Kerala', 'Malappuram', 'Mongam',
        'MA/MSc Psychology (Standard)', '2026-27',
        4500.00, '2026-08-11',
        'uploads/payments/mock-screenshot.jpg', 'uploads/photos/mock-photo.jpg', '', 'Other',
        'yes', $user_id, '127.0.0.1', '9567276458',
        null, null, 0
    ]);
    
    $id = $pdo->lastInsertId();
    echo "SUCCESS: Registered Adnan Test. ID: {$id}, UserID: {$user_id}\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
