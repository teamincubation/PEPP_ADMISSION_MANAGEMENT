<?php
header('Content-Type: text/plain');

echo "=== STARTING E2E INSTALLMENT REMINDER DISPATCH VERIFICATION ===\n\n";

$waNumber = "9567276458"; // Test WhatsApp

require_once 'config/database.php';

// 1. Clean up old test data
$pdo->prepare("DELETE FROM users WHERE whatsapp_number = ?")->execute([$waNumber]);

$rand = rand(1000, 9999);
$testName = "Adnan Rem Test {$rand}";
$testEmail = "adnan.rem.test{$rand}@gmail.com";

// Create temporary files
$screenshotFile = __DIR__ . '/test_screenshot.png';
$photoFile = __DIR__ . '/test_photo.jpg';
file_put_contents($screenshotFile, 'dummy screenshot content');
file_put_contents($photoFile, 'dummy photo content');

// 2. Submit Registration Form POST
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
echo "1. Registering dummy student...\n";

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

$successId = null;
if (preg_match('/Location:\s*success\.php\?id=(\d+)/i', $response, $matches)) {
    $successId = (int)$matches[1];
    echo "   Student registered successfully. Database ID: {$successId}\n";
} else {
    echo "ERROR: Registration failed. Response:\n" . $response . "\n";
    exit;
}

// Fetch Student UID
$uStmt = $pdo->prepare("SELECT user_id FROM users WHERE id = ?");
$uStmt->execute([$successId]);
$studentUid = $uStmt->fetchColumn();

// 3. Force student approval in DB
$pdo->prepare("UPDATE users SET status = 'approved' WHERE user_id = ?")->execute([$studentUid]);
echo "   Student approved status set. UID: {$studentUid}\n\n";

// 4. Create pending installment due today
$tz = new DateTimeZone('Asia/Kolkata');
$now = new DateTime('now', $tz);
$todayDate = $now->format('Y-m-d');

$pdo->prepare("DELETE FROM instalment_details WHERE user_id = ?")->execute([$studentUid]);
$stmtInst = $pdo->prepare("
    INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, created_at, updated_at)
    VALUES (?, 2, 3999, ?, 'pending', NOW(), NOW())
");
$stmtInst->execute([$studentUid, $todayDate]);
$installmentId = $pdo->lastInsertId();
echo "2. Created pending installment #2 for ₹3999 due today ({$todayDate}). ID: {$installmentId}\n\n";

// 5. Configure default event mapping for installment_reminder to pepp_installment_reminder
$pdo->prepare("
    UPDATE communication_event_mappings 
    SET template_name = 'pepp_installment_reminder',
        parameter_mappings = '{\"1\":{\"type\":\"variable\",\"value\":\"student_name\"},\"2\":{\"type\":\"variable\",\"value\":\"installment_number\"},\"3\":{\"type\":\"variable\",\"value\":\"installment_amount\"},\"4\":{\"type\":\"variable\",\"value\":\"course_name\"},\"5\":{\"type\":\"variable\",\"value\":\"installment_due_date\"},\"6\":{\"type\":\"variable\",\"value\":\"banking_details\"}}'
    WHERE event_name = 'installment_reminder'
")->execute();
echo "3. Event mapping configured: event 'installment_reminder' -> template 'pepp_installment_reminder'\n\n";

// 6. Execute the scheduler
echo "4. Executing the automatic reminder scheduler...\n";
define('FORCE_INSTALLMENT_REMINDER_TEST', true);
require_once 'includes/session_cron.php';
installments_dispatch_whatsapp_reminders($pdo);

// 7. Verify tracking row and queue item
$tStmt = $pdo->prepare("SELECT * FROM installment_whatsapp_reminders WHERE installment_id = ? AND reminder_stage = '0d'");
$tStmt->execute([$installmentId]);
$track = $tStmt->fetch();

if (!$track) {
    echo "ERROR: No tracking row found in installment_whatsapp_reminders table.\n";
    exit;
}
echo "   Tracking Row created. Status: " . $track['status'] . ", Queue ID: " . $track['queue_id'] . "\n";

$qId = $track['queue_id'];
$qStmt = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
$qStmt->execute([$qId]);
$qItem = $qStmt->fetch();

if (!$qItem) {
    echo "ERROR: Queue item #{$qId} not found in database.\n";
    exit;
}

echo "   Queue Payload Details:\n";
echo "     Event: " . $qItem['event_name'] . "\n";
echo "     Template: " . $qItem['template_name'] . "\n";
echo "     Recipient: " . $qItem['recipient'] . "\n";
echo "     Template Data (Variables): " . $qItem['template_data'] . "\n\n";

// 8. Run the scheduler a second time to verify deduplication
echo "5. Executing scheduler a second time (verifying deduplication)...\n";
installments_dispatch_whatsapp_reminders($pdo);

// Count matching queue items
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM communication_queue WHERE recipient = ? AND event_name = 'installment_reminder'");
$countStmt->execute([$qItem['recipient']]);
$count = $countStmt->fetchColumn();
echo "   Total enqueued messages for recipient: {$count} (Should be 1)\n";
if ($count > 1) {
    echo "WARNING: Deduplication failed! Duplicate queue entry created.\n";
} else {
    echo "   Deduplication check PASSED successfully!\n\n";
}

// 9. Process the queue item using CLI cron runner
echo "6. Triggering background CLI process to deliver the reminder...\n";
$cliCmd = "php cron-queue.php {$qId} > /dev/null 2>&1 &";
echo "   Executing command: {$cliCmd}\n";

$descriptorspec = [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"]
];
$process = proc_open($cliCmd, $descriptorspec, $pipes);
if (is_resource($process)) {
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

// 10. Wait 6 seconds for worker and Meta response
echo "7. Sleeping 6 seconds for async Meta Graph API call to respond...\n";
sleep(6);

// 11. Print final statuses and latency metrics
$tStmt->execute([$installmentId]);
$trackFinal = $tStmt->fetch();
$qStmt->execute([$qId]);
$qItemFinal = $qStmt->fetch();

echo "\nFINAL PERFORMANCE METRICS & STATUS:\n";
echo "  Reminder Tracking Status: " . $trackFinal['status'] . "\n";
echo "  Queue Status: " . $qItemFinal['status'] . "\n";
echo "  Message ID: " . ($qItemFinal['message_id'] ?: 'NONE') . "\n";
echo "  Error: " . ($qItemFinal['error_message'] ?: 'NONE') . "\n";
echo "  Created At (Queue Insertion): " . $qItemFinal['created_at'] . "\n";
echo "  Worker Started At: " . ($qItemFinal['worker_started_at'] ?: 'N/A') . "\n";
echo "  Api Requested At: " . ($qItemFinal['api_requested_at'] ?: 'N/A') . "\n";
echo "  Api Responded At: " . ($qItemFinal['api_responded_at'] ?: 'N/A') . "\n";
echo "  Delivered At: " . ($qItemFinal['delivered_at'] ?: 'N/A') . "\n\n";

if ($qItemFinal['worker_started_at']) {
    $qToWorker = strtotime($qItemFinal['worker_started_at']) - strtotime($qItemFinal['created_at']);
    echo "LATENCY SUMMARY:\n";
    echo "  - Queue-to-Worker Latency: {$qToWorker} seconds\n";
    
    if ($qItemFinal['api_requested_at'] && $qItemFinal['api_responded_at']) {
        $workerToMeta = strtotime($qItemFinal['api_responded_at']) - strtotime($qItemFinal['api_requested_at']);
        echo "  - Worker-to-Meta API Latency: {$workerToMeta} seconds\n";
    }
}
