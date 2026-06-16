<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Set JSON content type first
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Start session
session_start();

try {
    require_once '../config/database.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database configuration error',
        'debug' => ['error' => $e->getMessage()]
    ]);
    exit;
}

$user_id = $_GET['user_id'] ?? '';

if (empty($user_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user ID provided']);
    exit;
}

try {
    if (!isset($pdo) || !$pdo) {
        throw new Exception('Database connection not established');
    }
    
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($tableCheck->rowCount() == 0) {
        throw new Exception('Users table does not exist');
    }
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . implode(', ', $pdo->errorInfo()));
    }
    
    $result = $stmt->execute([$user_id]);
    if (!$result) {
        throw new Exception('Failed to execute query: ' . implode(', ', $stmt->errorInfo()));
    }
    
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'Student not found with ID: ' . $user_id,
            'debug' => ['searched_id' => $user_id]
        ]);
        exit;
    }
    
    $course_details = null;
    if (!empty($student['pepp_course'])) {
        try {
            $courseTableCheck = $pdo->query("SHOW TABLES LIKE 'pepp_courses'");
            if ($courseTableCheck->rowCount() > 0) {
                $courseStmt = $pdo->prepare("SELECT * FROM pepp_courses WHERE course_name = ? OR course_code = ?");
                $courseStmt->execute([$student['pepp_course'], $student['pepp_course']]);
                $course_details = $courseStmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $courseError) {
            error_log("Course query error: " . $courseError->getMessage());
        }
    }
    
    $response_data = [
        'success' => true,
        'student' => [
            // Personal Details
            'user_id' => $student['user_id'] ?? '',
            'name' => $student['name'] ?? '',
            'full_name' => $student['name'] ?? '',
            'email' => $student['email'] ?? '',
            'phone' => $student['phone'] ?? $student['mobile_number'] ?? $student['whatsapp_number'] ?? '',
            'mobile_number' => $student['mobile_number'] ?? $student['phone'] ?? '',
            'whatsapp_number' => $student['whatsapp_number'] ?? '',
            'whatsapp_country_code' => $student['whatsapp_country_code'] ?? '+91',
            'mobile_same_as_whatsapp' => $student['mobile_same_as_whatsapp'] ?? 'yes',
            'date_of_birth' => $student['date_of_birth'] ?? '',
            'gender' => $student['gender'] ?? '',
            'photo' => $student['user_photo'] ?? '',
            'emergency_contact' => $student['emergency_contact'] ?? '',
            
            // Address Details
            'address' => $student['postal_address'] ?? '',
            'city' => $student['place_post_office'] ?? '',
            'state' => $student['state'] ?? '',
            'pincode' => $student['postal_pincode'] ?? '',
            'district' => $student['district'] ?? '',
            'country' => $student['country'] ?? 'India',
            'country_address' => $student['country'] ?? 'India',
            
            // Educational Details
            'college_school' => $student['college_school'] ?? '',
            'course' => $student['course'] ?? '',
            'current_course' => $student['course'] ?? '',
            'university_board' => $student['university_board'] ?? '',
            'qualification' => $student['university_board'] ?? '',
            'remaining_semesters' => $student['remaining_semesters'] ?? '',
            'year_of_study' => $student['remaining_semesters'] ?? '',
            
            // PEPP Course Details
            'pepp_course' => $student['pepp_course'] ?? '',
            'course_name' => $course_details['course_name'] ?? $student['pepp_course'] ?? '',
            'course_code' => $course_details['course_code'] ?? '',
            'pepp_academic_year' => $student['pepp_academic_year'] ?? '',
            'course_expiry_date' => $student['course_expiry_date'] ?? '',
            'course_duration' => $student['course_expiry_date'] ?? '',
            'joined_date' => $student['joined_date'] ?? $student['created_at'] ?? '',
            'approved_by' => $student['approved_by'] ?? '',
            'approval_date' => $student['approval_date'] ?? '',
            'course_access_provided' => $student['course_access_provided'] ?? 'no',
            'course_access' => $student['course_access_provided'] ?? 'no',
            
            // Fee Details - Use course details if available
            'total_fee' => floatval($course_details['total_fee'] ?? $student['total_fee'] ?? 0),
            'course_fee' => floatval($course_details['total_fee'] ?? $student['total_fee'] ?? 0),
            'paid_amount' => floatval($student['paid_amount'] ?? 0),
            'discount_amount' => floatval($student['discount_amount'] ?? 0),
            'payment_mode' => $student['payment_mode'] ?? 'Online',
            'payment_status' => $student['status'] === 'approved' ? 'Paid' : 'Pending',
            'payment_plan' => $student['payment_plan'] ?? 'One Time',
            'payment_screenshot' => $student['payment_screenshot'] ?? '',
            'paid_date' => $student['paid_date'] ?? '',
            'payment_date' => $student['paid_date'] ?? '',
            'transaction_id' => $student['transaction_id'] ?? '',
            
            // Device & Security Details
            'ip_address' => $student['ip_address'] ?? 'Not available',
            'isp' => $student['isp'] ?? 'Not available',
            'as_name' => $student['as_name'] ?? 'Not available',
            'network_type' => $student['network_type'] ?? 'Not available',
            'region' => $student['region'] ?? 'Not available',
            'country_location' => $student['country'] ?? 'Not available',
            'device_details' => $student['device_details'] ?? '',
            'os_details' => $student['os_details'] ?? '',
            'registration_source' => 'Website',
            
            // Status & Remarks
            'status' => $student['status'] ?? 'pending',
            'admin_remarks' => $student['discount_remark'] ?? '',
            'discount_remark' => $student['discount_remark'] ?? '',
            'peppkit_eligible' => $student['peppkit_eligible'] ?? 'Not Eligible',
            'mentor_assigned' => $student['mentor_assigned'] ?? '',
            'how_know_pepp' => $student['how_know_pepp'] ?? '',
            'instagram_id' => $student['instagram_id'] ?? '',
            
            // Timestamps
            'created_at' => $student['created_at'] ?? $student['entry_datetime'] ?? '',
            'updated_at' => $student['updated_at'] ?? '',
            'submit_datetime' => $student['submit_datetime'] ?? '',
            'time_spent' => $student['time_spent'] ?? 0,
            
            // Additional Information
            'terms_agreed' => $student['terms_agreed'] ?? 'no'
        ],
        'debug' => [
            'user_id_searched' => $user_id,
            'student_found' => true,
            'course_details_found' => !empty($course_details),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
    
    echo json_encode($response_data);
    
} catch (Exception $e) {
    error_log("Student details API error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred',
        'debug' => [
            'error' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'user_id' => $user_id,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>
