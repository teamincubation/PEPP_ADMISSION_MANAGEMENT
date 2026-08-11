<?php
header('Content-Type: text/plain');

echo "=== STARTING E2E STUDENT REJECTION VERIFICATION ===\n\n";

$rand = rand(1000, 9999);
$testName = "Adnan Rej Test {$rand}";
$testEmail = "adnan.rej.test{$rand}@gmail.com";
$waNumber = "9567276458"; // Test WhatsApp

// Delete existing users with this number to bypass duplicate check
require_once 'config/database.php';
$pdo->prepare("DELETE FROM users WHERE whatsapp_number = ?")->execute([$waNumber]);

// Create dummy temporary upload files
$screenshotFile = __DIR__ . '/test_screenshot.png';
$photoFile = __DIR__ . '/test_photo.jpg';
file_put_contents($screenshotFile, 'dummy screenshot content');
file_put_contents($photoFile, 'dummy photo content');

// 1. Submit Registration Form POST
$postData = [
    'name' => $testName,
    'gender' => 'Male',
    'date_of_birth' => '1996-10-02',
    'whatsapp_country_code' => '+91',
    'whatsapp_number' => $waNumber,
    'mobile_same_whatsapp' => 'yes',
    'emergency_country_code' => '+91',
    'emergency_contact' => '8078239589',
    'email' => $testEmail,
    'postal_address' => 'Malappuram PO Mongam',
    'postal_pincode' => '673642',
    'state' => 'Kerala',
    'district' => 'Malappuram',
    'place_post_office' => 'Mongam',
    'college_school' => 'Incubation Test College',
    'course' => 'Administration',
    'university_board' => 'Other',
    'remaining_semesters[]' => 'Already Completed',
    'pepp_course' => 'MA/MSc Psychology (Standard)',
    'pepp_academic_year' => '2026-27',
    'paid_amount' => '4500',
    'paid_date' => '2026-08-11',
    'instagram_id' => 'test_insta',
    'how_know_pepp' => 'Other',
    'terms_agreed' => 'yes',
    'coupon_code' => '',
    'payment_screenshot' => new CURLFile($screenshotFile, 'image/png', 'test_screenshot.png'),
    'photo_upload' => new CURLFile($photoFile, 'image/jpeg', 'test_photo.jpg')
];

$url = 'https://pepplearning.in/admissions/register.php';
echo "1. Sending registration POST request to {$url}...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
curl_close($ch);

@unlink($screenshotFile);
@unlink($photoFile);

// Parse success ID
$successId = null;
if (preg_match('/Location:\s*success\.php\?id=(\d+)/i', $response, $matches)) {
    $successId = (int)$matches[1];
    echo "   Registration successful. Database ID: {$successId}\n";
} else {
    echo "ERROR: Registration failed. Response:\n" . $response . "\n";
    exit;
}

// Fetch newly created student UID
$uStmt = $pdo->prepare("SELECT user_id FROM users WHERE id = ?");
$uStmt->execute([$successId]);
$studentUid = $uStmt->fetchColumn();

if (!$studentUid) {
    echo "ERROR: Student UID not found in database.\n";
    exit;
}
echo "   Student UID: {$studentUid}\n\n";

// 2. Perform Rejection by Mocking POST & Session inside student-approval.php
echo "2. Simulating administrator rejection action...\n";
session_start();

// Setup dummy admin in DB if admins table exists
$hasAdmins = (bool)$pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn();
if ($hasAdmins) {
    $pdo->prepare("DELETE FROM admins WHERE username = 'admin_system_test'")->execute();
    $pdo->prepare("
        INSERT INTO admins (username, password_hash, role, permissions, status)
        VALUES ('admin_system_test', 'dummy', 'super_admin', 'ALL', 'active')
    ")->execute();
}

$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'admin_system_test';
$_SESSION['admin_role'] = 'super_admin';
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = 'test_csrf_token';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'action' => 'reject',
    'user_id' => $studentUid,
    'reason' => 'Incomplete documentation / test',
    'csrf_token' => 'test_csrf_token'
];

ob_start();
include 'student-approval.php';
$rejResponse = ob_get_clean();

// Clean up dummy admin from DB
if ($hasAdmins) {
    $pdo->prepare("DELETE FROM admins WHERE username = 'admin_system_test'")->execute();
}

echo "   Rejection Response: {$rejResponse}\n\n";

// 3. Sleep 6 seconds for background worker and webhooks to process
echo "3. Sleeping for 6 seconds to allow async worker & webhook callback processing...\n";
sleep(6);
echo "4. Fetching performance timestamps from database for enqueued rejection...\n\n";

try {
    $qStmt = $pdo->prepare("
        SELECT * FROM communication_queue 
        WHERE student_uid = ? AND event_name = 'student_rejection' 
        ORDER BY id DESC LIMIT 1
    ");
    $qStmt->execute([$studentUid]);
    $item = $qStmt->fetch();
    
    if (!$item) {
        throw new Exception("No queued record found in communication_queue for Student UID {$studentUid} / event 'student_rejection'.");
    }
    
    echo "DATABASE PERFORMANCE METRICS (Queue ID #{$item['id']}):\n";
    echo "  Status: " . $item['status'] . "\n";
    echo "  Template: " . $item['template_name'] . "\n";
    echo "  Event Name: " . $item['event_name'] . "\n";
    echo "  Message ID: " . ($item['message_id'] ?: 'NONE') . "\n";
    echo "  Error: " . ($item['error_message'] ?: 'NONE') . "\n";
    echo "  Created At (Queue Insertion): " . $item['created_at'] . "\n";
    echo "  Worker Started At: " . ($item['worker_started_at'] ?: 'N/A') . "\n";
    echo "  Api Requested At: " . ($item['api_requested_at'] ?: 'N/A') . "\n";
    echo "  Api Responded At: " . ($item['api_responded_at'] ?: 'N/A') . "\n";
    echo "  Delivered At: " . ($item['delivered_at'] ?: 'N/A') . "\n\n";
    
    if ($item['worker_started_at']) {
        $qToWorker = strtotime($item['worker_started_at']) - strtotime($item['created_at']);
        echo "LATENCY RESULTS:\n";
        echo "  - Queue-to-Worker Latency: {$qToWorker} seconds\n";
        
        if ($item['api_requested_at'] && $item['api_responded_at']) {
            $workerToMeta = strtotime($item['api_responded_at']) - strtotime($item['api_requested_at']);
            echo "  - Worker-to-Meta API Latency: {$workerToMeta} seconds\n";
        }
        
        if ($item['api_responded_at'] && $item['delivered_at']) {
            $metaToDelivery = strtotime($item['delivered_at']) - strtotime($item['api_responded_at']);
            $totalTime = strtotime($item['delivered_at']) - strtotime($item['created_at']);
            echo "  - Meta-to-Delivery Latency: {$metaToDelivery} seconds\n";
            echo "  - Total Rejection-to-Delivery Time: {$totalTime} seconds\n";
        } else {
            echo "  - Meta-to-Delivery Latency: Webhook callback not received yet.\n";
        }
    } else {
        echo "LATENCY RESULTS: Async background worker failed to claim/process this item.\n";
    }
    
} catch (Exception $e) {
    echo "VERIFICATION ERROR: " . $e->getMessage() . "\n";
}
