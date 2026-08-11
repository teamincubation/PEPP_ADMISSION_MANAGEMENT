<?php
header('Content-Type: text/plain');

echo "=== STARTING E2E STUDENT REGISTRATION VERIFICATION ===\n\n";

$rand = rand(1000, 9999);
$testName = "Adnan Reg Test {$rand}";
$testEmail = "adnan.reg.test{$rand}@gmail.com";
$waNumber = "9567276458"; // Test WhatsApp

// Delete existing users with this number to bypass duplicate WhatsApp check
require_once 'config/database.php';
$pdo->prepare("DELETE FROM users WHERE whatsapp_number = ? AND pepp_course = 'MA/MSc Psychology (Standard)' AND pepp_academic_year = '2026-27'")
    ->execute([$waNumber]);

// Create dummy temporary upload files
$screenshotFile = __DIR__ . '/test_screenshot.png';
$photoFile = __DIR__ . '/test_photo.jpg';

file_put_contents($screenshotFile, 'dummy screenshot content');
file_put_contents($photoFile, 'dummy photo content');

// Prepare Multipart Form Data
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
    'remaining_semesters' => ['Already Completed'],
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

// Fire POST request to register.php on the local loopback/server
$url = 'https://pepplearning.in/admissions/register.php';

echo "Sending registration POST request to {$url}...\n";
$startTime = microtime(true);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true); // Get headers to verify redirect
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

$endTime = microtime(true);
$durationMs = round(($endTime - $startTime) * 1000, 2);

// Clean up temporary files
@unlink($screenshotFile);
@unlink($photoFile);

if ($err) {
    echo "ERROR: POST request failed: {$err}\n";
    exit;
}

echo "HTTP Response Code: {$httpCode}\n";
echo "Response Time: {$durationMs} ms\n";

// Parse Redirect Location header
$successId = null;
if (preg_match('/Location:\s*success\.php\?id=(\d+)/i', $response, $matches)) {
    $successId = (int)$matches[1];
    echo "SUCCESS: Registration redirected successfully to success.php?id={$successId}\n\n";
} else {
    echo "WARNING: Could not parse success ID from response headers. Full response:\n";
    echo $response . "\n\n";
    exit;
}

// Now wait 6 seconds for the background async process and Meta webhook to complete
echo "Sleeping for 6 seconds to allow async worker & webhook callback processing...\n";
sleep(6);
echo "Fetching performance timestamps from database for the enqueued message...\n\n";

// Load database to verify enqueued message status and performance metrics
require_once 'config/database.php';

try {
    // Retrieve the user record first
    $uStmt = $pdo->prepare("SELECT user_id, name, email FROM users WHERE id = ?");
    $uStmt->execute([$successId]);
    $user = $uStmt->fetch();
    
    if (!$user) {
        throw new Exception("Student record with database ID {$successId} not found.");
    }
    
    $studentUid = $user['user_id'];
    echo "Created Student UID: {$studentUid}\n";
    
    // Retrieve the queued notification
    $qStmt = $pdo->prepare("SELECT * FROM communication_queue WHERE student_uid = ? AND event_name = 'student_registration' ORDER BY id DESC LIMIT 1");
    $qStmt->execute([$studentUid]);
    $item = $qStmt->fetch();
    
    if (!$item) {
        throw new Exception("No queued record found in communication_queue for Student UID {$studentUid} / event 'student_registration'.");
    }
    
    echo "DATABASE PERFORMANCE METRICS (Queue ID #{$item['id']}):\n";
    echo "  Status: " . $item['status'] . "\n";
    echo "  Template: " . $item['template_name'] . "\n";
    echo "  Message ID: " . ($item['message_id'] ?: 'NONE') . "\n";
    echo "  Error: " . ($item['error_message'] ?: 'NONE') . "\n";
    echo "  Created At (Queue Insertion): " . $item['created_at'] . "\n";
    echo "  Worker Started At: " . ($item['worker_started_at'] ?: 'N/A') . "\n";
    echo "  Meta API Requested At: " . ($item['api_requested_at'] ?: 'N/A') . "\n";
    echo "  Meta API Responded At: " . ($item['api_responded_at'] ?: 'N/A') . "\n";
    echo "  Webhook Delivered At: " . ($item['delivered_at'] ?: 'N/A') . "\n\n";
    
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
            echo "  - Total Registration-to-Delivery Time: {$totalTime} seconds\n";
        } else {
            echo "  - Meta-to-Delivery Latency: Webhook callback not received yet.\n";
        }
    } else {
        echo "LATENCY RESULTS: Async background worker failed to claim/process this item.\n";
    }
    
} catch (Exception $e) {
    echo "VERIFICATION ERROR: " . $e->getMessage() . "\n";
}
