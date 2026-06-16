<?php
session_start();
require_once 'config/database.php';

// ── AJAX: validate a coupon / referral code and return the discounted fee ──
if (isset($_GET['check_code'])) {
    header('Content-Type: application/json');
    require_once __DIR__ . '/includes/referral_helper.php';
    $course = trim($_GET['course'] ?? '');
    $year   = trim($_GET['year'] ?? '');
    $code   = trim($_GET['code'] ?? '');
    $email  = trim($_GET['email'] ?? '');
    $wa     = trim($_GET['whatsapp'] ?? '');
    $fee = course_fee($pdo, $course, $year);
    if ($code === '') { echo json_encode(['fee' => $fee]); exit; }
    $info = validate_code($pdo, $code, $course, $year, $fee, $email, $wa);
    echo json_encode([
        'ok' => $info['ok'], 'kind' => $info['kind'], 'discount' => round($info['discount'], 2),
        'fee' => $fee, 'payable' => max(0, round($fee - $info['discount'], 2)), 'message' => $info['message'],
    ]);
    exit;
}

// ── AJAX: just the fee for a course/year (no code) ──
if (isset($_GET['fee_only'])) {
    header('Content-Type: application/json');
    require_once __DIR__ . '/includes/referral_helper.php';
    echo json_encode(['fee' => course_fee($pdo, trim($_GET['course'] ?? ''), trim($_GET['year'] ?? ''))]);
    exit;
}


error_reporting(E_ALL);
ini_set('display_errors', 1);
ob_start();

// Initialize variables
$validation_errors = [];
$success_message = '';
$email_exists = false;
$whatsapp_exists = false;

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    /* Duplicate checks are per COURSE + academic year, so the same student
       can register for multiple courses within one academic year. */
    if ($_GET['ajax'] === 'check_email' && isset($_GET['email']) && isset($_GET['academic_year'])) {
        try {
            if (empty($_GET['course'])) {
                echo json_encode(['exists' => false, 'need_course' => true]);
                exit;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND pepp_course = ? AND pepp_academic_year = ?");
            $stmt->execute([$_GET['email'], $_GET['course'], $_GET['academic_year']]);
            $exists = $stmt->fetchColumn() > 0;
            echo json_encode(['exists' => $exists]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Database error']);
        }
        exit;
    }
    
    if ($_GET['ajax'] === 'check_whatsapp' && isset($_GET['whatsapp']) && isset($_GET['academic_year'])) {
        try {
            if (empty($_GET['course'])) {
                echo json_encode(['exists' => false, 'need_course' => true]);
                exit;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE whatsapp_number = ? AND pepp_course = ? AND pepp_academic_year = ?");
            $stmt->execute([$_GET['whatsapp'], $_GET['course'], $_GET['academic_year']]);
            $exists = $stmt->fetchColumn() > 0;
            echo json_encode(['exists' => $exists]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Database error']);
        }
        exit;
    }
    
    if ($_GET['ajax'] === 'pincode' && isset($_GET['pincode'])) {
        $pincode = trim($_GET['pincode']);
        if (strlen($pincode) === 6 && is_numeric($pincode)) {
            $api_url = "https://api.postalpincode.in/pincode/{$pincode}";
            $response = @file_get_contents($api_url);
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data[0]['Status']) && $data[0]['Status'] === 'Success') {
                    echo json_encode([
                        'success' => true,
                        'state' => $data[0]['PostOffice'][0]['State'],
                        'district' => $data[0]['PostOffice'][0]['District'],
                        'places' => array_map(function($office) {
                            return $office['Name'];
                        }, $data[0]['PostOffice'])
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid PIN code']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'API error']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid PIN code format']);
        }
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data = [
        'name' => trim($_POST['name'] ?? ''),
        'gender' => $_POST['gender'] ?? '',
        'date_of_birth' => $_POST['date_of_birth'] ?? '',
        'whatsapp_country_code' => $_POST['whatsapp_country_code'] ?? '+91',
        'whatsapp_number' => trim($_POST['whatsapp_number'] ?? ''),
        'mobile_same_whatsapp' => $_POST['mobile_same_whatsapp'] ?? '',
        'mobile_country_code' => $_POST['mobile_country_code'] ?? '+91',
        'mobile_number' => trim($_POST['mobile_number'] ?? ''),
        'emergency_country_code' => $_POST['emergency_country_code'] ?? '+91',
        'emergency_contact' => trim($_POST['emergency_contact'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'postal_address' => trim($_POST['postal_address'] ?? ''),
        'postal_pincode' => trim($_POST['postal_pincode'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'district' => trim($_POST['district'] ?? ''),
        'place_post_office' => trim($_POST['place_post_office'] ?? ''),
        'college_school' => trim($_POST['college_school'] ?? ''),
        'course' => trim($_POST['course'] ?? ''),
        'university_board' => $_POST['university_board'] ?? '',
        'remaining_semesters' => $_POST['remaining_semesters'] ?? [],
        'pepp_course' => $_POST['pepp_course'] ?? '',
        'pepp_academic_year' => $_POST['pepp_academic_year'] ?? '',
        'paid_amount' => trim($_POST['paid_amount'] ?? ''),
        'paid_date' => $_POST['paid_date'] ?? '',
        'instagram_id' => trim($_POST['instagram_id'] ?? ''),
        'how_know_pepp' => trim($_POST['how_know_pepp'] ?? ''),
        'terms_agreed' => isset($_POST['terms_agreed']) ? 'yes' : 'no',
        'coupon_code' => strtoupper(trim($_POST['coupon_code'] ?? '')),
    ];
    
    if (empty($form_data['name'])) $validation_errors['name'] = 'Name is required';
    if (empty($form_data['gender'])) $validation_errors['gender'] = 'Gender is required';
    if (empty($form_data['date_of_birth'])) $validation_errors['date_of_birth'] = 'Date of birth is required';
    if (empty($form_data['email'])) {
        $validation_errors['email'] = 'Email is required';
    } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $validation_errors['email'] = 'Invalid email format';
    }
    if (empty($form_data['whatsapp_number'])) $validation_errors['whatsapp_number'] = 'WhatsApp number is required';
    if (empty($form_data['mobile_same_whatsapp'])) $validation_errors['mobile_same_whatsapp'] = 'Please select if mobile is same as WhatsApp';
    if (empty($form_data['emergency_contact'])) $validation_errors['emergency_contact'] = 'Emergency contact is required';
    if (empty($form_data['postal_address'])) $validation_errors['postal_address'] = 'Postal address is required';
    if (empty($form_data['postal_pincode'])) $validation_errors['postal_pincode'] = 'PIN code is required';
    if (empty($form_data['college_school'])) $validation_errors['college_school'] = 'College/School name is required';
    if (empty($form_data['course'])) $validation_errors['course'] = 'Course is required';
    if (empty($form_data['university_board'])) $validation_errors['university_board'] = 'University/Board is required';
    if (empty($form_data['pepp_course'])) $validation_errors['pepp_course'] = 'PEPP Course is required';
    if (empty($form_data['pepp_academic_year'])) $validation_errors['pepp_academic_year'] = 'PEPP Academic Year is required';
    if (empty($form_data['paid_amount'])) $validation_errors['paid_amount'] = 'Paid amount is required';
    if (empty($form_data['paid_date'])) $validation_errors['paid_date'] = 'Paid date is required';
    if (empty($form_data['how_know_pepp'])) $validation_errors['how_know_pepp'] = 'Please tell us how you know about PEPP Learning';
    if ($form_data['terms_agreed'] !== 'yes') $validation_errors['terms_agreed'] = 'You must agree to Terms & Conditions';

    // Validate coupon/referral code (optional) and compute the discount server-side
    $applied_code_info = null;
    if (!empty($form_data['coupon_code']) && empty($validation_errors['pepp_course']) && empty($validation_errors['pepp_academic_year'])) {
        require_once __DIR__ . '/includes/referral_helper.php';
        $__fee = course_fee($pdo, $form_data['pepp_course'], $form_data['pepp_academic_year']);
        $__info = validate_code($pdo, $form_data['coupon_code'], $form_data['pepp_course'], $form_data['pepp_academic_year'], $__fee, $form_data['email'], $form_data['whatsapp_number']);
        if ($__info['ok']) { $applied_code_info = $__info; }
        else { $validation_errors['coupon_code'] = $__info['message']; }
    }
    
    if ($form_data['mobile_same_whatsapp'] === 'no' && empty($form_data['mobile_number'])) {
        $validation_errors['mobile_number'] = 'Mobile number is required when different from WhatsApp';
    }
    
    // Mandatory file uploads
    if (!isset($_FILES['payment_screenshot']) || $_FILES['payment_screenshot']['error'] !== UPLOAD_ERR_OK) {
        $validation_errors['payment_screenshot'] = 'Payment screenshot is required';
    }
    if (!isset($_FILES['photo_upload']) || $_FILES['photo_upload']['error'] !== UPLOAD_ERR_OK) {
        $validation_errors['photo_upload'] = 'Student photo is required';
    }
    
    if (!empty($form_data['email']) && !empty($form_data['pepp_course']) && !empty($form_data['pepp_academic_year'])) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND pepp_course = ? AND pepp_academic_year = ?");
            $stmt->execute([$form_data['email'], $form_data['pepp_course'], $form_data['pepp_academic_year']]);
            if ($stmt->fetchColumn() > 0) {
                $validation_errors['email'] = 'This email is already registered for this course in the selected academic year';
                $email_exists = true;
            }
        } catch (Exception $e) {
            error_log("Email check error: " . $e->getMessage());
        }
    }
    
    if (!empty($form_data['whatsapp_number']) && !empty($form_data['pepp_course']) && !empty($form_data['pepp_academic_year'])) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE whatsapp_number = ? AND pepp_course = ? AND pepp_academic_year = ?");
            $stmt->execute([$form_data['whatsapp_number'], $form_data['pepp_course'], $form_data['pepp_academic_year']]);
            if ($stmt->fetchColumn() > 0) {
                $validation_errors['whatsapp_number'] = 'This WhatsApp number is already registered for this course in the selected academic year';
                $whatsapp_exists = true;
            }
        } catch (Exception $e) {
            error_log("WhatsApp check error: " . $e->getMessage());
        }
    }
    
    // Handle file uploads
    $payment_screenshot_path = '';
    $photo_upload_path = '';
    
    if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/payments/';
        $target_dir = '../' . $upload_dir;
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $filename = uniqid() . '_' . $_FILES['payment_screenshot']['name'];
        $payment_screenshot_path = $upload_dir . $filename;
        move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $target_dir . $filename);
    }
    
    if (isset($_FILES['photo_upload']) && $_FILES['photo_upload']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/photos/';
        $target_dir = '../' . $upload_dir;
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $filename = uniqid() . '_' . $_FILES['photo_upload']['name'];
        $photo_upload_path = $upload_dir . $filename;
        move_uploaded_file($_FILES['photo_upload']['tmp_name'], $target_dir . $filename);
    }
    
    if (empty($validation_errors)) {
        try {
            $user_id = 'PEPP' . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    name, gender, date_of_birth, whatsapp_country_code, whatsapp_number, 
                    mobile_same_as_whatsapp, mobile_number, emergency_contact, email,
                    college_school, course, university_board, remaining_semesters,
                    postal_address, postal_pincode, state, district, place_post_office,
                    pepp_course, pepp_academic_year, paid_amount, paid_date,
                    payment_screenshot, user_photo, instagram_id, how_know_pepp,
                    terms_agreed, user_id, ip_address, phone,
                    applied_coupon, referral_code, coupon_discount, submit_datetime
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $form_data['name'], $form_data['gender'], $form_data['date_of_birth'],
                $form_data['whatsapp_country_code'], $form_data['whatsapp_number'],
                $form_data['mobile_same_whatsapp'], $form_data['mobile_number'],
                $form_data['emergency_contact'], $form_data['email'],
                $form_data['college_school'], $form_data['course'], $form_data['university_board'],
                implode(',', $form_data['remaining_semesters']),
                $form_data['postal_address'], $form_data['postal_pincode'],
                $form_data['state'], $form_data['district'], $form_data['place_post_office'],
                $form_data['pepp_course'], $form_data['pepp_academic_year'],
                $form_data['paid_amount'], $form_data['paid_date'],
                $payment_screenshot_path, $photo_upload_path,
                $form_data['instagram_id'], $form_data['how_know_pepp'],
                $form_data['terms_agreed'], $user_id, $_SERVER['REMOTE_ADDR'],
                ($form_data['mobile_number'] !== '' ? $form_data['mobile_number'] : $form_data['whatsapp_number']),
                ($applied_code_info && $applied_code_info['kind'] === 'coupon') ? $applied_code_info['coupon_code'] : ($form_data['coupon_code'] ?: null),
                ($applied_code_info && $applied_code_info['kind'] === 'referral') ? $applied_code_info['referral_code'] : null,
                $applied_code_info ? $applied_code_info['discount'] : 0
            ]);
            
            $inserted_id = $pdo->lastInsertId();

            // Record coupon/referral use (and create a pending referral earning)
            if ($applied_code_info && $applied_code_info['ok']) {
                require_once __DIR__ . '/includes/referral_helper.php';
                record_code_use($pdo, $applied_code_info, $user_id, $form_data['name'], $form_data['email'], $form_data['whatsapp_number'], (float)$form_data['paid_amount']);
            }

            // Redirect to success page with the record ID
            header("Location: success.php?id=" . $inserted_id);
            exit;
            
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            $validation_errors['general'] = 'Registration failed. Please try again.';
        }
    }
} else {
    $form_data = [
        'name' => '', 'gender' => '', 'date_of_birth' => '',
        'whatsapp_country_code' => '+91', 'whatsapp_number' => '',
        'mobile_same_whatsapp' => '', 'mobile_country_code' => '+91', 'mobile_number' => '',
        'emergency_country_code' => '+91', 'emergency_contact' => '', 'email' => '',
        'postal_address' => '', 'postal_pincode' => '', 'state' => '', 'district' => '', 'place_post_office' => '',
        'college_school' => '', 'course' => '', 'university_board' => '', 'remaining_semesters' => [],
        'pepp_course' => '', 'pepp_academic_year' => '', 'paid_amount' => '', 'paid_date' => '',
        'instagram_id' => '', 'how_know_pepp' => '', 'terms_agreed' => 'no'
    ];
}

// Load academic years and courses
try {
    $academic_years = [];
    $stmt = $pdo->query("SELECT * FROM academic_years WHERE status = 'active' ORDER BY start_date DESC");
    $academic_years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $academic_years = [];
}

try {
    $pepp_courses = [];
    $stmt = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses ORDER BY course_name");
    $pepp_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pepp_courses = [];
}

// Fee lookup map: "course||year" => total_fee (used to auto-show the fee)
$fee_map = [];
try {
    foreach ($pdo->query("SELECT course_name, academic_year, total_fee FROM pepp_courses") as $r) {
        $fee_map[$r['course_name'] . '||' . $r['academic_year']] = (float)$r['total_fee'];
        if (!isset($fee_map[$r['course_name'] . '||'])) $fee_map[$r['course_name'] . '||'] = (float)$r['total_fee'];
    }
} catch (Exception $e) {}

// Complete list of international calling codes (India first as default).
// Codes shared by multiple countries (e.g. +1, +7, +44) show the main ones.
$country_codes = [
    '+91'  => 'IN (+91)',
    '+1'   => 'US/CA (+1)',
    '+7'   => 'RU/KZ (+7)',
    '+20'  => 'EG (+20)',
    '+27'  => 'ZA (+27)',
    '+30'  => 'GR (+30)',
    '+31'  => 'NL (+31)',
    '+32'  => 'BE (+32)',
    '+33'  => 'FR (+33)',
    '+34'  => 'ES (+34)',
    '+36'  => 'HU (+36)',
    '+39'  => 'IT (+39)',
    '+40'  => 'RO (+40)',
    '+41'  => 'CH (+41)',
    '+43'  => 'AT (+43)',
    '+44'  => 'UK (+44)',
    '+45'  => 'DK (+45)',
    '+46'  => 'SE (+46)',
    '+47'  => 'NO (+47)',
    '+48'  => 'PL (+48)',
    '+49'  => 'DE (+49)',
    '+51'  => 'PE (+51)',
    '+52'  => 'MX (+52)',
    '+53'  => 'CU (+53)',
    '+54'  => 'AR (+54)',
    '+55'  => 'BR (+55)',
    '+56'  => 'CL (+56)',
    '+57'  => 'CO (+57)',
    '+58'  => 'VE (+58)',
    '+60'  => 'MY (+60)',
    '+61'  => 'AU (+61)',
    '+62'  => 'ID (+62)',
    '+63'  => 'PH (+63)',
    '+64'  => 'NZ (+64)',
    '+65'  => 'SG (+65)',
    '+66'  => 'TH (+66)',
    '+81'  => 'JP (+81)',
    '+82'  => 'KR (+82)',
    '+84'  => 'VN (+84)',
    '+86'  => 'CN (+86)',
    '+90'  => 'TR (+90)',
    '+92'  => 'PK (+92)',
    '+93'  => 'AF (+93)',
    '+94'  => 'LK (+94)',
    '+95'  => 'MM (+95)',
    '+98'  => 'IR (+98)',
    '+211' => 'SS (+211)',
    '+212' => 'MA (+212)',
    '+213' => 'DZ (+213)',
    '+216' => 'TN (+216)',
    '+218' => 'LY (+218)',
    '+220' => 'GM (+220)',
    '+221' => 'SN (+221)',
    '+222' => 'MR (+222)',
    '+223' => 'ML (+223)',
    '+224' => 'GN (+224)',
    '+225' => 'CI (+225)',
    '+226' => 'BF (+226)',
    '+227' => 'NE (+227)',
    '+228' => 'TG (+228)',
    '+229' => 'BJ (+229)',
    '+230' => 'MU (+230)',
    '+231' => 'LR (+231)',
    '+232' => 'SL (+232)',
    '+233' => 'GH (+233)',
    '+234' => 'NG (+234)',
    '+235' => 'TD (+235)',
    '+236' => 'CF (+236)',
    '+237' => 'CM (+237)',
    '+238' => 'CV (+238)',
    '+239' => 'ST (+239)',
    '+240' => 'GQ (+240)',
    '+241' => 'GA (+241)',
    '+242' => 'CG (+242)',
    '+243' => 'CD (+243)',
    '+244' => 'AO (+244)',
    '+245' => 'GW (+245)',
    '+248' => 'SC (+248)',
    '+249' => 'SD (+249)',
    '+250' => 'RW (+250)',
    '+251' => 'ET (+251)',
    '+252' => 'SO (+252)',
    '+253' => 'DJ (+253)',
    '+254' => 'KE (+254)',
    '+255' => 'TZ (+255)',
    '+256' => 'UG (+256)',
    '+257' => 'BI (+257)',
    '+258' => 'MZ (+258)',
    '+260' => 'ZM (+260)',
    '+261' => 'MG (+261)',
    '+262' => 'RE (+262)',
    '+263' => 'ZW (+263)',
    '+264' => 'NA (+264)',
    '+265' => 'MW (+265)',
    '+266' => 'LS (+266)',
    '+267' => 'BW (+267)',
    '+268' => 'SZ (+268)',
    '+269' => 'KM (+269)',
    '+291' => 'ER (+291)',
    '+297' => 'AW (+297)',
    '+298' => 'FO (+298)',
    '+299' => 'GL (+299)',
    '+350' => 'GI (+350)',
    '+351' => 'PT (+351)',
    '+352' => 'LU (+352)',
    '+353' => 'IE (+353)',
    '+354' => 'IS (+354)',
    '+355' => 'AL (+355)',
    '+356' => 'MT (+356)',
    '+357' => 'CY (+357)',
    '+358' => 'FI (+358)',
    '+359' => 'BG (+359)',
    '+370' => 'LT (+370)',
    '+371' => 'LV (+371)',
    '+372' => 'EE (+372)',
    '+373' => 'MD (+373)',
    '+374' => 'AM (+374)',
    '+375' => 'BY (+375)',
    '+376' => 'AD (+376)',
    '+377' => 'MC (+377)',
    '+378' => 'SM (+378)',
    '+380' => 'UA (+380)',
    '+381' => 'RS (+381)',
    '+382' => 'ME (+382)',
    '+383' => 'XK (+383)',
    '+385' => 'HR (+385)',
    '+386' => 'SI (+386)',
    '+387' => 'BA (+387)',
    '+389' => 'MK (+389)',
    '+420' => 'CZ (+420)',
    '+421' => 'SK (+421)',
    '+423' => 'LI (+423)',
    '+500' => 'FK (+500)',
    '+501' => 'BZ (+501)',
    '+502' => 'GT (+502)',
    '+503' => 'SV (+503)',
    '+504' => 'HN (+504)',
    '+505' => 'NI (+505)',
    '+506' => 'CR (+506)',
    '+507' => 'PA (+507)',
    '+509' => 'HT (+509)',
    '+590' => 'GP (+590)',
    '+591' => 'BO (+591)',
    '+592' => 'GY (+592)',
    '+593' => 'EC (+593)',
    '+595' => 'PY (+595)',
    '+597' => 'SR (+597)',
    '+598' => 'UY (+598)',
    '+599' => 'CW (+599)',
    '+670' => 'TL (+670)',
    '+673' => 'BN (+673)',
    '+674' => 'NR (+674)',
    '+675' => 'PG (+675)',
    '+676' => 'TO (+676)',
    '+677' => 'SB (+677)',
    '+678' => 'VU (+678)',
    '+679' => 'FJ (+679)',
    '+680' => 'PW (+680)',
    '+682' => 'CK (+682)',
    '+685' => 'WS (+685)',
    '+686' => 'KI (+686)',
    '+687' => 'NC (+687)',
    '+688' => 'TV (+688)',
    '+689' => 'PF (+689)',
    '+691' => 'FM (+691)',
    '+692' => 'MH (+692)',
    '+850' => 'KP (+850)',
    '+852' => 'HK (+852)',
    '+853' => 'MO (+853)',
    '+855' => 'KH (+855)',
    '+856' => 'LA (+856)',
    '+880' => 'BD (+880)',
    '+886' => 'TW (+886)',
    '+960' => 'MV (+960)',
    '+961' => 'LB (+961)',
    '+962' => 'JO (+962)',
    '+963' => 'SY (+963)',
    '+964' => 'IQ (+964)',
    '+965' => 'KW (+965)',
    '+966' => 'SA (+966)',
    '+967' => 'YE (+967)',
    '+968' => 'OM (+968)',
    '+970' => 'PS (+970)',
    '+971' => 'AE (+971)',
    '+972' => 'IL (+972)',
    '+973' => 'BH (+973)',
    '+974' => 'QA (+974)',
    '+975' => 'BT (+975)',
    '+976' => 'MN (+976)',
    '+977' => 'NP (+977)',
    '+992' => 'TJ (+992)',
    '+993' => 'TM (+993)',
    '+994' => 'AZ (+994)',
    '+995' => 'GE (+995)',
    '+996' => 'KG (+996)',
    '+998' => 'UZ (+998)'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - PEPP Learning</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="apple-touch-icon" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ── RESET & BASE ─────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            background-color: #181003;
            background-image:
                radial-gradient(ellipse 80% 60% at 10% 10%, rgba(232,152,12,.20) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 90% 90%, rgba(240,165,17,.14) 0%, transparent 55%),
                url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23e8980c' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            min-height: 100vh;
            padding: 2.5rem 1rem 3rem;
        }

        /* ── PAGE WRAPPER ─────────────────────────────────────────── */
        .page-wrapper {
            max-width: 980px;
            margin: 0 auto;
        }

        /* ── MAIN CARD ────────────────────────────────────────────── */
        .reg-card {
            background: #ffffff;
            border-radius: 28px;
            overflow: hidden;
            box-shadow:
                0 0 0 1px rgba(255,255,255,.06),
                0 8px 32px rgba(0,0,0,.4),
                0 32px 80px rgba(0,0,0,.35);
        }

        /* ── HERO HEADER ──────────────────────────────────────────── */
        .reg-hero {
            background: linear-gradient(135deg, #2a1a03 0%, #4a2e04 45%, #6b4205 100%);
            padding: 3rem 2.5rem 0;
            position: relative;
            overflow: hidden;
        }

        .reg-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle 420px at 110% 0%, rgba(250,204,21,.22) 0%, transparent 60%),
                radial-gradient(circle 300px at -10% 110%, rgba(232,152,12,.18) 0%, transparent 50%);
            pointer-events: none;
        }

        .hero-inner {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .brand-mark {
            width: 68px;
            height: 68px;
            background: rgba(255,255,255,.12);
            border: 2px solid rgba(255,255,255,.35);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            box-shadow: 0 4px 18px rgba(0,0,0,.35);
        }
        .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .hero-text h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.5px;
            margin: 0 0 4px;
            line-height: 1.2;
        }

        .hero-text .tagline {
            font-size: 0.875rem;
            color: rgba(251,211,141,.9);
            font-weight: 500;
            margin: 0;
        }

        /* ── PROGRESS STEPS ───────────────────────────────────────── */
        .progress-track {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 2rem 0 0;
            margin-top: 1.5rem;
        }

        .progress-track::before {
            content: '';
            position: absolute;
            top: 2rem;
            left: calc(100% / 14);
            right: calc(100% / 14);
            height: 2px;
            background: rgba(255,255,255,.12);
            transform: translateY(19px);
            z-index: 0;
        }

        .prog-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            flex: 1;
            position: relative;
            z-index: 1;
        }

        .prog-dot {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,.08);
            border: 2px solid rgba(255,255,255,.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            color: rgba(255,255,255,.55);
            font-weight: 700;
            transition: all .25s ease;
        }

        .prog-label {
            font-size: 0.6rem;
            font-weight: 600;
            color: rgba(255,255,255,.45);
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            line-height: 1.3;
            max-width: 64px;
        }

        /* ── WAVE CONNECTOR ──────────────────────────────────────── */
        .hero-wave {
            position: relative;
            z-index: 1;
            margin-top: 1.5rem;
            line-height: 0;
        }
        .hero-wave svg { display: block; width: 100%; }

        /* ── FORM BODY ────────────────────────────────────────────── */
        .form-body {
            background: #f5f6fa;
            padding: 2rem 2rem 1rem;
        }

        /* ── GENERAL ERROR ────────────────────────────────────────── */
        .alert-error {
            background: #fff1f2;
            border: 1.5px solid #fca5a5;
            border-radius: 14px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            font-size: 0.875rem;
            color: #b91c1c;
            font-weight: 500;
        }
        .alert-error i { margin-top: 2px; flex-shrink: 0; }

        /* ── SECTION CARD ─────────────────────────────────────────── */
        .sec-card {
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            margin-bottom: 1.25rem;
            border: 1px solid #e8eaf0;
            box-shadow: 0 1px 4px rgba(0,0,0,.04), 0 4px 16px rgba(180,83,9,.06);
            transition: box-shadow .2s ease, border-color .2s ease;
        }
        .sec-card:hover {
            border-color: #dde0ed;
            box-shadow: 0 2px 8px rgba(0,0,0,.06), 0 8px 28px rgba(180,83,9,.10);
        }

        /* Section header strip */
        .sec-head {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.4rem;
            border-bottom: 1px solid #f0f2f8;
            position: relative;
        }

        .sec-head::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
            border-radius: 0 4px 4px 0;
        }

        /* Per-section accent colors */
        .sec-personal .sec-head::before  { background: linear-gradient(180deg, #b45309, #f59e0b); }
        .sec-contact  .sec-head::before  { background: linear-gradient(180deg, #c2410c, #fb923c); }
        .sec-address  .sec-head::before  { background: linear-gradient(180deg, #a16207, #facc15); }
        .sec-academic .sec-head::before  { background: linear-gradient(180deg, #92400e, #d97706); }
        .sec-pepp     .sec-head::before  { background: linear-gradient(180deg, #9a3412, #fb923c); }
        .sec-payment  .sec-head::before  { background: linear-gradient(180deg, #be123c, #fb7185); }

        .sec-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
        }

        /* Icon gradient per section */
        .sec-personal .sec-icon { background: linear-gradient(135deg, #b45309, #f59e0b); color: white; }
        .sec-contact  .sec-icon { background: linear-gradient(135deg, #c2410c, #fb923c); color: white; }
        .sec-address  .sec-icon { background: linear-gradient(135deg, #a16207, #facc15); color: white; }
        .sec-academic .sec-icon { background: linear-gradient(135deg, #92400e, #d97706); color: white; }
        .sec-pepp     .sec-icon { background: linear-gradient(135deg, #9a3412, #fb923c); color: white; }
        .sec-payment  .sec-icon { background: linear-gradient(135deg, #be123c, #fb7185); color: white; }

        .sec-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #3b2604;
            margin: 0 0 2px;
            line-height: 1.3;
        }
        .sec-subtitle {
            font-size: 0.72rem;
            color: #94a3b8;
            margin: 0;
            font-weight: 500;
        }
        .sec-num {
            margin-left: auto;
            font-size: 0.68rem;
            font-weight: 700;
            color: #94a3b8;
            background: #f1f5f9;
            border-radius: 50px;
            padding: 3px 10px;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .sec-body {
            padding: 1.4rem 1.4rem 1rem;
        }

        /* ── FORM LABELS ──────────────────────────────────────────── */
        .form-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 6px;
            display: block;
        }

        /* ── FORM CONTROLS ────────────────────────────────────────── */
        .form-control,
        .form-select {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.875rem;
            font-weight: 500;
            color: #1e293b;
            background: #fafbfd;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.65rem 0.9rem;
            height: auto;
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
        }
        .form-control:focus,
        .form-select:focus {
            border-color: #e8980c;
            box-shadow: 0 0 0 3px rgba(232,152,12,.18);
            background: #ffffff;
            outline: none;
        }
        .form-control::placeholder { color: #b0bec5; font-size: 0.82rem; }
        .form-control[readonly] {
            background: #f1f5f9;
            color: #64748b;
            cursor: default;
        }
        textarea.form-control {
            resize: vertical;
            min-height: 96px;
        }

        /* ── VALIDATION STATES ────────────────────────────────────── */
        .error-field {
            border-color: #ef4444 !important;
            background-color: #fff5f5 !important;
        }
        .success-field {
            border-color: #22c55e !important;
            background-color: #f0fdf4 !important;
        }
        .error-message {
            color: #dc2626;
            font-size: 0.74rem;
            font-weight: 600;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .success-message {
            color: #16a34a;
            font-size: 0.74rem;
            font-weight: 600;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* ── FIELD WRAPPER (for indicator icon) ──────────────────── */
        .field-wrapper { position: relative; }
        .validation-indicator {
            position: absolute;
            right: 11px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.9rem;
            pointer-events: none;
        }

        /* ── RADIO / CHECKBOX BUTTONS ────────────────────────────── */
        .choice-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 16px 7px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 50px;
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            color: #475569;
            background: #fafbfd;
            transition: all .18s ease;
            user-select: none;
        }
        .choice-pill:hover {
            border-color: #fcd34d;
            background: #fffbeb;
            color: #92400e;
        }
        .choice-pill input[type="radio"],
        .choice-pill input[type="checkbox"] {
            accent-color: #b45309;
            width: 15px;
            height: 15px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .choice-pill:has(input:checked) {
            border-color: #d97706;
            background: #fef3c7;
            color: #92400e;
        }

        .pill-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }

        /* Semester checkboxes - grid layout */
        .semester-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 7px;
            margin-top: 8px;
        }
        .semester-grid .choice-pill {
            border-radius: 10px;
            width: 100%;
            padding: 8px 14px;
        }

        /* ── PHONE INPUT GROUP ────────────────────────────────────── */
        .phone-group {
            display: flex;
            gap: 7px;
        }
        .phone-group .cc-select {
            flex: 0 0 108px;
            font-size: 0.78rem;
            padding-left: 0.7rem;
            padding-right: 0.5rem;
        }
        .phone-group .phone-input { flex: 1; }

        /* ── MOBILE SYNC BADGE ────────────────────────────────────── */
        .sync-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 10px;
            font-size: 0.78rem;
            color: #15803d;
            font-weight: 600;
            margin-top: 8px;
        }

        /* ── FILE UPLOAD AREAS ────────────────────────────────────── */
        .upload-area {
            position: relative;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 1.4rem 1rem;
            text-align: center;
            background: #f8fafc;
            transition: all .2s ease;
            cursor: pointer;
            overflow: hidden;
        }
        .upload-area:hover {
            border-color: #f59e0b;
            background: #fffbeb;
        }
        .upload-area input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
            z-index: 2;
        }
        .upload-icon {
            font-size: 1.6rem;
            margin-bottom: 6px;
            display: block;
        }
        .sec-payment  .upload-icon { color: #fb923c; }
        .sec-payment  .upload-area:hover .upload-icon { color: #c2410c; }
        .upload-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 3px;
        }
        .upload-hint {
            font-size: 0.7rem;
            color: #94a3b8;
            font-weight: 500;
        }
        .upload-area.upload-error {
            border-color: #ef4444;
            background: #fff5f5;
        }

        /* ── TERMS CARD ───────────────────────────────────────────── */
        .terms-wrap {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.25rem 1.4rem;
            margin-bottom: 1.25rem;
        }
        .terms-check {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .terms-check input[type="checkbox"] {
            width: 19px;
            height: 19px;
            accent-color: #b45309;
            cursor: pointer;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .terms-text {
            font-size: 0.84rem;
            color: #374151;
            font-weight: 500;
            line-height: 1.6;
        }
        .terms-text a {
            color: #b45309;
            font-weight: 700;
            text-decoration: none;
        }
        .terms-text a:hover { text-decoration: underline; }

        /* ── SUBMIT BLOCK ─────────────────────────────────────────── */
        .submit-block {
            text-align: center;
            padding: 0.5rem 0 2.5rem;
        }
        .submit-block .note {
            font-size: 0.72rem;
            color: #94a3b8;
            font-weight: 500;
            margin-bottom: 1rem;
        }
        .btn-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: linear-gradient(135deg, #d97706 0%, #f59e0b 100%);
            color: #fff;
            border: none;
            border-radius: 50px;
            padding: 1rem 3.5rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: 0.3px;
            cursor: pointer;
            transition: all .25s ease;
            box-shadow: 0 6px 20px rgba(217,119,6,.45), 0 2px 8px rgba(0,0,0,.15);
            min-width: 260px;
        }
        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(217,119,6,.5), 0 4px 12px rgba(0,0,0,.2);
        }
        .btn-submit:active { transform: translateY(-1px); }
        .btn-submit i { font-size: 1rem; }

        /* ── FOOTER STRIP ─────────────────────────────────────────── */
        .reg-footer {
            background: #2a1a03;
            padding: 1rem 2rem;
            text-align: center;
        }
        .reg-footer p {
            font-size: 0.72rem;
            color: rgba(251,211,141,.6);
            margin: 0;
            font-weight: 500;
        }

        /* ── RESPONSIVE ───────────────────────────────────────────── */
        @media (max-width: 767px) {
            body { padding: 1rem 0.5rem 2rem; }
            .reg-hero { padding: 2rem 1.25rem 0; }
            .hero-inner { gap: 1rem; }
            .hero-text h1 { font-size: 1.35rem; }
            .brand-mark { width: 54px; height: 54px; }
            .form-body { padding: 1.25rem 1rem 0.5rem; }
            .sec-body { padding: 1.1rem 1rem 0.8rem; }
            .progress-track { display: none; }
            .hero-wave { margin-top: 1rem; }
            .semester-grid { grid-template-columns: repeat(auto-fill, minmax(155px, 1fr)); }
            .btn-submit { width: 100%; min-width: unset; }
            .phone-group .cc-select { flex: 0 0 90px; font-size: 0.72rem; }
        }

        @media (max-width: 480px) {
            .reg-card { border-radius: 20px; }
            .sec-card { border-radius: 14px; }
        }

        /* ── LOADING OVERLAY (submit feedback) ────────────────────── */
        .btn-submit.loading { opacity: 0.75; pointer-events: none; }
        .btn-submit.loading::after {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2.5px solid rgba(255,255,255,.4);
            border-top-color: white;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            margin-left: 6px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    
        .fee-box { background:#fffaf2; border:1.5px solid #fcd9a8; border-radius:14px; padding:16px 18px; margin-bottom:16px; }
        .fee-row { display:flex; justify-content:space-between; align-items:center; font-size:.95rem; color:#7c5e2a; padding:4px 0; }
        .fee-row.fee-discount { color:#16a34a; font-weight:600; }
        .fee-row.fee-total { border-top:1px dashed #e6c389; margin-top:6px; padding-top:10px; font-size:1.1rem; font-weight:800; color:#9a3412; }
        .btn-apply-code { background:#E8980C; color:#fff; border:none; border-radius:10px; padding:0 20px; font-weight:700; font-size:.9rem; cursor:pointer; white-space:nowrap; transition:background .15s; }
        .btn-apply-code:hover { background:#cf850a; }
        .btn-apply-code:disabled { opacity:.6; cursor:not-allowed; }
        .code-msg { font-size:.85rem; font-weight:600; margin-top:8px; }
        .code-msg.ok { color:#16a34a; } .code-msg.err { color:#ef4444; }

        /* ── WHATSAPP FLOATING CHAT WIDGET ── */
        .wa-support-widget {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .wa-btn {
            width: 60px;
            height: 60px;
            background-color: #25d366;
            color: #ffffff !important;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.85rem;
            box-shadow: 0 4px 16px rgba(37,211,102,0.4), 0 8px 32px rgba(0,0,0,0.15);
            position: relative;
            transition: transform 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275), background-color 0.2s;
            text-decoration: none;
        }
        .wa-btn:hover {
            transform: scale(1.08);
            background-color: #20ba5a;
        }
        .wa-pulse {
            position: absolute;
            inset: -4px;
            border: 2px solid #25d366;
            border-radius: 50%;
            opacity: 0;
            animation: wa-ripple 2s infinite;
            pointer-events: none;
        }
        .wa-tooltip {
            background: #181003;
            color: #ffffff;
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 0.78rem;
            font-weight: 700;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            border: 1px solid rgba(255,255,255,0.08);
            white-space: nowrap;
            opacity: 0;
            transform: translateX(10px);
            transition: opacity 0.25s, transform 0.25s;
            pointer-events: none;
        }
        .wa-support-widget:hover .wa-tooltip {
            opacity: 1;
            transform: translateX(0);
        }
        @keyframes wa-ripple {
            0% { transform: scale(0.95); opacity: 0.8; }
            100% { transform: scale(1.25); opacity: 0; }
        }
        @media (max-width: 768px) {
            .wa-support-widget {
                bottom: 1.5rem;
                right: 1.5rem;
            }
            .wa-tooltip {
                display: none;
            }
            .wa-btn {
                width: 52px;
                height: 52px;
                font-size: 1.6rem;
            }
        }
</style>
</head>
<body>
<div class="page-wrapper">
<div class="reg-card">

    <!-- ── HERO HEADER ── -->
    <div class="reg-hero">
        <div class="hero-inner">
            <div class="brand-mark">
                <img src="logo.png" alt="PEPP Learning logo">
            </div>
            <div class="hero-text">
                <h1>PEPP Learning</h1>
                <p class="tagline">Student Admission Registration - Academic Enrolment Portal</p>
            </div>
        </div>

        <!-- Progress Steps -->
        <div class="progress-track">
            <div class="prog-step">
                <div class="prog-dot"><i class="fas fa-user" style="font-size:.75rem"></i></div>
                <div class="prog-label">Personal</div>
            </div>
            <div class="prog-step">
                <div class="prog-dot"><i class="fas fa-phone" style="font-size:.75rem"></i></div>
                <div class="prog-label">Contact</div>
            </div>
            <div class="prog-step">
                <div class="prog-dot"><i class="fas fa-map-marker-alt" style="font-size:.75rem"></i></div>
                <div class="prog-label">Address</div>
            </div>
            <div class="prog-step">
                <div class="prog-dot"><i class="fas fa-university" style="font-size:.75rem"></i></div>
                <div class="prog-label">Academic</div>
            </div>
            <div class="prog-step">
                <div class="prog-dot"><i class="fas fa-book-open" style="font-size:.75rem"></i></div>
                <div class="prog-label">PEPP<br>Course</div>
            </div>
            <div class="prog-step">
                <div class="prog-dot"><i class="fas fa-credit-card" style="font-size:.75rem"></i></div>
                <div class="prog-label">Payment</div>
            </div>
            <div class="prog-step">
                <div class="prog-dot"><i class="fas fa-check" style="font-size:.75rem"></i></div>
                <div class="prog-label">Submit</div>
            </div>
        </div>

        <!-- Wave -->
        <div class="hero-wave">
            <svg viewBox="0 0 1440 52" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0,52 C360,0 1080,52 1440,12 L1440,52 Z" fill="#f5f6fa"/>
            </svg>
        </div>
    </div><!-- /reg-hero -->

    <!-- ── FORM BODY ── -->
    <div class="form-body">

        <?php if (isset($validation_errors['general'])): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <span><?php echo htmlspecialchars($validation_errors['general']); ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($validation_errors)): ?>
        <div class="alert-error">
            <i class="fas fa-list-ul"></i>
            <span>Please fix the highlighted errors below before submitting.</span>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" novalidate id="registration-form">

            <!-- ─────────────────────────────────────────────────── -->
            <!-- SECTION 1 · PERSONAL INFORMATION                    -->
            <!-- ─────────────────────────────────────────────────── -->
            <div class="sec-card sec-personal" id="step-personal">
                <div class="sec-head">
                    <div class="sec-icon"><i class="fas fa-id-card"></i></div>
                    <div>
                        <p class="sec-title">Personal Information</p>
                        <p class="sec-subtitle">Basic details about the student</p>
                    </div>
                    <span class="sec-num">01 / 06</span>
                </div>
                <div class="sec-body">

                    <div class="row g-3">
                        <!-- Name -->
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span style="color:#ef4444">*</span></label>
                            <input type="text" name="name"
                                   class="form-control <?php echo isset($validation_errors['name']) ? 'error-field' : ''; ?>"
                                   value="<?php echo htmlspecialchars($form_data['name']); ?>"
                                   placeholder="Enter your full name" required>
                            <?php if (isset($validation_errors['name'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['name']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Gender -->
                        <div class="col-md-6">
                            <label class="form-label">Gender <span style="color:#ef4444">*</span></label>
                            <div class="pill-group">
                                <label class="choice-pill">
                                    <input type="radio" name="gender" value="Male"
                                           <?php echo $form_data['gender'] === 'Male' ? 'checked' : ''; ?> required>
                                    Male
                                </label>
                                <label class="choice-pill">
                                    <input type="radio" name="gender" value="Female"
                                           <?php echo $form_data['gender'] === 'Female' ? 'checked' : ''; ?>>
                                    Female
                                </label>
                                <label class="choice-pill">
                                    <input type="radio" name="gender" value="Other"
                                           <?php echo $form_data['gender'] === 'Other' ? 'checked' : ''; ?>>
                                    Other
                                </label>
                            </div>
                            <?php if (isset($validation_errors['gender'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['gender']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Date of Birth -->
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth <span style="color:#ef4444">*</span></label>
                            <input type="date" name="date_of_birth"
                                   class="form-control <?php echo isset($validation_errors['date_of_birth']) ? 'error-field' : ''; ?>"
                                   value="<?php echo htmlspecialchars($form_data['date_of_birth']); ?>" required>
                            <?php if (isset($validation_errors['date_of_birth'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['date_of_birth']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Email -->
                        <div class="col-md-6">
                            <label class="form-label">Email Address <span style="color:#ef4444">*</span></label>
                            <div class="field-wrapper">
                                <input type="email" name="email" id="email"
                                       class="form-control <?php echo isset($validation_errors['email']) ? 'error-field' : ($email_exists ? 'error-field' : ''); ?>"
                                       value="<?php echo htmlspecialchars($form_data['email']); ?>"
                                       placeholder="you@example.com" required>
                                <span class="validation-indicator" id="email-indicator"></span>
                            </div>
                            <?php if (isset($validation_errors['email'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['email']; ?></div>
                            <?php endif; ?>
                            <div id="email-message"></div>
                        </div>

                        <!-- Instagram ID -->
                        <div class="col-md-6">
                            <label class="form-label">Instagram ID <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0">(Optional)</span></label>
                            <input type="text" name="instagram_id" class="form-control"
                                   value="<?php echo htmlspecialchars($form_data['instagram_id']); ?>"
                                   placeholder="@yourusername">
                        </div>

                        <!-- How did you know -->
                        <div class="col-md-6">
                            <label class="form-label">How did you hear about PEPP? <span style="color:#ef4444">*</span></label>
                            <select name="how_know_pepp"
                                    class="form-select <?php echo isset($validation_errors['how_know_pepp']) ? 'error-field' : ''; ?>" required>
                                <option value="">Select an option&hellip;</option>
                                <option value="Social Media"        <?php echo $form_data['how_know_pepp'] === 'Social Media'        ? 'selected' : ''; ?>>Social Media</option>
                                <option value="Friends/Family"      <?php echo $form_data['how_know_pepp'] === 'Friends/Family'      ? 'selected' : ''; ?>>Friends / Family</option>
                                <option value="Google Search"       <?php echo $form_data['how_know_pepp'] === 'Google Search'       ? 'selected' : ''; ?>>Google Search</option>
                                <option value="College/University"  <?php echo $form_data['how_know_pepp'] === 'College/University'  ? 'selected' : ''; ?>>College / University</option>
                                <option value="Advertisement"       <?php echo $form_data['how_know_pepp'] === 'Advertisement'       ? 'selected' : ''; ?>>Advertisement</option>
                                <option value="Other"               <?php echo $form_data['how_know_pepp'] === 'Other'               ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <?php if (isset($validation_errors['how_know_pepp'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['how_know_pepp']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div><!-- /sec-personal -->


            <!-- ─────────────────────────────────────────────────── -->
            <!-- SECTION 2 · CONTACT INFORMATION                     -->
            <!-- ─────────────────────────────────────────────────── -->
            <div class="sec-card sec-contact" id="step-contact">
                <div class="sec-head">
                    <div class="sec-icon"><i class="fas fa-headset"></i></div>
                    <div>
                        <p class="sec-title">Contact Information</p>
                        <p class="sec-subtitle">Phone numbers &amp; emergency contact</p>
                    </div>
                    <span class="sec-num">02 / 06</span>
                </div>
                <div class="sec-body">

                    <div class="row g-3">
                        <!-- WhatsApp Number -->
                        <div class="col-md-6">
                            <label class="form-label">WhatsApp Number <span style="color:#ef4444">*</span></label>
                            <div class="phone-group">
                                <select name="whatsapp_country_code" class="form-select cc-select">
                                    <?php foreach ($country_codes as $code => $label): ?>
                                        <option value="<?php echo $code; ?>" <?php echo $form_data['whatsapp_country_code'] === $code ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="field-wrapper flex-fill">
                                    <input type="tel" name="whatsapp_number" id="whatsapp"
                                           class="form-control phone-input <?php echo isset($validation_errors['whatsapp_number']) ? 'error-field' : ($whatsapp_exists ? 'error-field' : ''); ?>"
                                           value="<?php echo htmlspecialchars($form_data['whatsapp_number']); ?>"
                                           placeholder="10-digit number" required>
                                    <span class="validation-indicator" id="whatsapp-indicator"></span>
                                </div>
                            </div>
                            <?php if (isset($validation_errors['whatsapp_number'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['whatsapp_number']; ?></div>
                            <?php endif; ?>
                            <div id="whatsapp-message"></div>
                        </div>

                        <!-- Mobile Same as WhatsApp? -->
                        <div class="col-md-6">
                            <label class="form-label">Mobile same as WhatsApp? <span style="color:#ef4444">*</span></label>
                            <div class="pill-group">
                                <label class="choice-pill">
                                    <input type="radio" name="mobile_same_whatsapp" value="yes"
                                           <?php echo $form_data['mobile_same_whatsapp'] === 'yes' ? 'checked' : ''; ?> required>
                                    Yes, they're the same
                                </label>
                                <label class="choice-pill">
                                    <input type="radio" name="mobile_same_whatsapp" value="no"
                                           <?php echo $form_data['mobile_same_whatsapp'] === 'no' ? 'checked' : ''; ?>>
                                    No, different number
                                </label>
                            </div>
                            <?php if (isset($validation_errors['mobile_same_whatsapp'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['mobile_same_whatsapp']; ?></div>
                            <?php endif; ?>
                            <div class="sync-badge" id="mobile-sync-indicator" style="display:none;">
                                <i class="fas fa-check-circle"></i> Mobile number synced with WhatsApp
                            </div>
                        </div>

                        <!-- Mobile Number (if different) -->
                        <div class="col-md-6" id="mobile-number-row" style="display:none;">
                            <label class="form-label">Mobile Number (if different) <span style="color:#ef4444">*</span></label>
                            <div class="phone-group">
                                <select name="mobile_country_code" class="form-select cc-select">
                                    <?php foreach ($country_codes as $code => $label): ?>
                                        <option value="<?php echo $code; ?>" <?php echo $form_data['mobile_country_code'] === $code ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="tel" name="mobile_number" id="mobile_number"
                                       class="form-control phone-input <?php echo isset($validation_errors['mobile_number']) ? 'error-field' : ''; ?>"
                                       value="<?php echo htmlspecialchars($form_data['mobile_number']); ?>"
                                       placeholder="10-digit number">
                            </div>
                            <?php if (isset($validation_errors['mobile_number'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['mobile_number']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Emergency Contact -->
                        <div class="col-md-6">
                            <label class="form-label">Emergency Contact Number <span style="color:#ef4444">*</span></label>
                            <div class="phone-group">
                                <select name="emergency_country_code" class="form-select cc-select">
                                    <?php foreach ($country_codes as $code => $label): ?>
                                        <option value="<?php echo $code; ?>" <?php echo $form_data['emergency_country_code'] === $code ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="tel" name="emergency_contact"
                                       class="form-control phone-input <?php echo isset($validation_errors['emergency_contact']) ? 'error-field' : ''; ?>"
                                       value="<?php echo htmlspecialchars($form_data['emergency_contact']); ?>"
                                       placeholder="Parent / Guardian" required>
                            </div>
                            <?php if (isset($validation_errors['emergency_contact'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['emergency_contact']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div><!-- /sec-contact -->


            <!-- ─────────────────────────────────────────────────── -->
            <!-- SECTION 3 · ADDRESS INFORMATION                     -->
            <!-- ─────────────────────────────────────────────────── -->
            <div class="sec-card sec-address" id="step-address">
                <div class="sec-head">
                    <div class="sec-icon"><i class="fas fa-location-dot"></i></div>
                    <div>
                        <p class="sec-title">Address Information</p>
                        <p class="sec-subtitle">Postal address &amp; PIN code lookup</p>
                    </div>
                    <span class="sec-num">03 / 06</span>
                </div>
                <div class="sec-body">

                    <div class="row g-3">
                        <!-- Postal Address -->
                        <div class="col-12">
                            <label class="form-label">Postal Address <span style="color:#ef4444">*</span></label>
                            <textarea name="postal_address"
                                      class="form-control <?php echo isset($validation_errors['postal_address']) ? 'error-field' : ''; ?>"
                                      placeholder="House / Flat, Street, Area, City" required><?php echo htmlspecialchars($form_data['postal_address']); ?></textarea>
                            <?php if (isset($validation_errors['postal_address'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['postal_address']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- PIN Code -->
                        <div class="col-md-3 col-6">
                            <label class="form-label">PIN Code <span style="color:#ef4444">*</span></label>
                            <input type="text" name="postal_pincode" id="pincode"
                                   class="form-control <?php echo isset($validation_errors['postal_pincode']) ? 'error-field' : ''; ?>"
                                   value="<?php echo htmlspecialchars($form_data['postal_pincode']); ?>"
                                   maxlength="6" pattern="[0-9]{6}" placeholder="6-digit PIN" required>
                            <?php if (isset($validation_errors['postal_pincode'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['postal_pincode']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- State -->
                        <div class="col-md-3 col-6">
                            <label class="form-label">State</label>
                            <input type="text" name="state" id="state" class="form-control"
                                   value="<?php echo htmlspecialchars($form_data['state']); ?>"
                                   placeholder="Auto-filled" readonly>
                        </div>

                        <!-- District -->
                        <div class="col-md-3 col-6">
                            <label class="form-label">District</label>
                            <input type="text" name="district" id="district" class="form-control"
                                   value="<?php echo htmlspecialchars($form_data['district']); ?>"
                                   placeholder="Auto-filled" readonly>
                        </div>

                        <!-- Place / Post Office -->
                        <div class="col-md-3 col-6">
                            <label class="form-label">Place / Post Office</label>
                            <select name="place_post_office" id="place_select" class="form-select">
                                <option value="">Select Place</option>
                            </select>
                        </div>
                    </div>

                </div>
            </div><!-- /sec-address -->


            <!-- ─────────────────────────────────────────────────── -->
            <!-- SECTION 4 · ACADEMIC INFORMATION                    -->
            <!-- ─────────────────────────────────────────────────── -->
            <div class="sec-card sec-academic" id="step-academic">
                <div class="sec-head">
                    <div class="sec-icon"><i class="fas fa-graduation-cap"></i></div>
                    <div>
                        <p class="sec-title">Academic Information</p>
                        <p class="sec-subtitle">Your current institution &amp; course details</p>
                    </div>
                    <span class="sec-num">04 / 06</span>
                </div>
                <div class="sec-body">

                    <div class="row g-3">
                        <!-- College / School -->
                        <div class="col-md-6">
                            <label class="form-label">College / School Name <span style="color:#ef4444">*</span></label>
                            <input type="text" name="college_school"
                                   class="form-control <?php echo isset($validation_errors['college_school']) ? 'error-field' : ''; ?>"
                                   value="<?php echo htmlspecialchars($form_data['college_school']); ?>"
                                   placeholder="e.g. Government College, Calicut" required>
                            <?php if (isset($validation_errors['college_school'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['college_school']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Course -->
                        <div class="col-md-6">
                            <label class="form-label">Current Course / Programme <span style="color:#ef4444">*</span></label>
                            <input type="text" name="course"
                                   class="form-control <?php echo isset($validation_errors['course']) ? 'error-field' : ''; ?>"
                                   value="<?php echo htmlspecialchars($form_data['course']); ?>"
                                   placeholder="e.g. B.Com, B.Sc Computer Science" required>
                            <?php if (isset($validation_errors['course'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['course']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- University / Board -->
                        <div class="col-md-6">
                            <label class="form-label">University / Board <span style="color:#ef4444">*</span></label>
                            <select name="university_board"
                                    class="form-select <?php echo isset($validation_errors['university_board']) ? 'error-field' : ''; ?>" required>
                                <option value="">Select University / Board&hellip;</option>
                                <option value="University of Calicut"  <?php echo $form_data['university_board'] === 'University of Calicut'  ? 'selected' : ''; ?>>University of Calicut</option>
                                <option value="Kerala University"      <?php echo $form_data['university_board'] === 'Kerala University'      ? 'selected' : ''; ?>>Kerala University</option>
                                <option value="Kannur University"      <?php echo $form_data['university_board'] === 'Kannur University'      ? 'selected' : ''; ?>>Kannur University</option>
                                <option value="MG University"          <?php echo $form_data['university_board'] === 'MG University'          ? 'selected' : ''; ?>>MG University</option>
                                <option value="HSE/VHSE"              <?php echo $form_data['university_board'] === 'HSE/VHSE'              ? 'selected' : ''; ?>>HSE / VHSE</option>
                                <option value="Other"                  <?php echo $form_data['university_board'] === 'Other'                  ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <?php if (isset($validation_errors['university_board'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['university_board']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Remaining Semesters -->
                        <div class="col-12">
                            <label class="form-label">Remaining Semesters</label>
                            <?php
                            $semesters = ['1st Semester','2nd Semester','3rd Semester','4th Semester','5th Semester','6th Semester','7th Semester','8th Semester','Already Completed','Higher Secondary Student'];
                            ?>
                            <div class="semester-grid">
                                <?php foreach ($semesters as $sem): ?>
                                <label class="choice-pill">
                                    <input type="checkbox" name="remaining_semesters[]"
                                           value="<?php echo $sem; ?>"
                                           <?php echo in_array($sem, $form_data['remaining_semesters']) ? 'checked' : ''; ?>>
                                    <?php echo $sem; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div><!-- /sec-academic -->


            <!-- ─────────────────────────────────────────────────── -->
            <!-- SECTION 5 · PEPP COURSE INFORMATION                 -->
            <!-- ─────────────────────────────────────────────────── -->
            <div class="sec-card sec-pepp" id="step-pepp">
                <div class="sec-head">
                    <div class="sec-icon"><i class="fas fa-book-open"></i></div>
                    <div>
                        <p class="sec-title">PEPP Course Information</p>
                        <p class="sec-subtitle">Choose your PEPP programme &amp; batch</p>
                    </div>
                    <span class="sec-num">05 / 06</span>
                </div>
                <div class="sec-body">

                    <div class="row g-3">
                        <!-- PEPP Course -->
                        <div class="col-md-6">
                            <label class="form-label">PEPP Course <span style="color:#ef4444">*</span></label>
                            <select name="pepp_course"
                                    class="form-select <?php echo isset($validation_errors['pepp_course']) ? 'error-field' : ''; ?>" required>
                                <option value="">Select PEPP Course&hellip;</option>
                                <?php foreach ($pepp_courses as $course): ?>
                                    <option value="<?php echo htmlspecialchars($course['course_name']); ?>"
                                            <?php echo $form_data['pepp_course'] === $course['course_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($course['course_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($validation_errors['pepp_course'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['pepp_course']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- PEPP Academic Year -->
                        <div class="col-md-6">
                            <label class="form-label">PEPP Academic Year <span style="color:#ef4444">*</span></label>
                            <select name="pepp_academic_year"
                                    class="form-select <?php echo isset($validation_errors['pepp_academic_year']) ? 'error-field' : ''; ?>" required>
                                <option value="">Select Academic Year&hellip;</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year['year']); ?>"
                                            <?php echo $form_data['pepp_academic_year'] === $year['year'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['year']); ?>
                                        (<?php echo date('M Y', strtotime($year['start_date'])); ?> - <?php echo date('M Y', strtotime($year['end_date'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($validation_errors['pepp_academic_year'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['pepp_academic_year']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div><!-- /sec-pepp -->


            <!-- ─────────────────────────────────────────────────── -->
            <!-- SECTION 6 · PAYMENT INFORMATION                     -->
            <!-- ─────────────────────────────────────────────────── -->
            <div class="sec-card sec-payment" id="step-payment">
                <div class="sec-head">
                    <div class="sec-icon"><i class="fas fa-wallet"></i></div>
                    <div>
                        <p class="sec-title">Payment Information</p>
                        <p class="sec-subtitle">Fee payment details &amp; proof upload</p>
                    </div>
                    <span class="sec-num">06 / 06</span>
                </div>
                <div class="sec-body">

                    <!-- Auto course fee + coupon -->
                    <div id="fee-box" class="fee-box" style="display:none;">
                        <div class="fee-row"><span>Course Fee</span><span id="fee-amount">₹0</span></div>
                        <div class="fee-row fee-discount" id="fee-discount-row" style="display:none;"><span id="fee-discount-label">Discount</span><span id="fee-discount">-₹0</span></div>
                        <div class="fee-row fee-total"><span>Total Payable</span><span id="fee-payable">₹0</span></div>
                    </div>

                    <div class="row g-3" style="margin-bottom:4px;">
                        <div class="col-md-8">
                            <label class="form-label">Add Coupon / Referral Code</label>
                            <div style="display:flex; gap:8px;">
                                <input type="text" name="coupon_code" id="coupon_code" class="form-control <?php echo isset($validation_errors['coupon_code']) ? 'error-field' : ''; ?>"
                                       value="<?php echo htmlspecialchars($form_data['coupon_code'] ?? ''); ?>" placeholder="Enter coupon or referral code" style="text-transform:uppercase;">
                                <button type="button" class="btn-apply-code" id="apply-code-btn">Apply</button>
                            </div>
                            <div id="code-msg" class="code-msg"></div>
                            <?php if (isset($validation_errors['coupon_code'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['coupon_code']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Paid Amount -->
                        <div class="col-md-6">
                            <label class="form-label">Amount Paid (₹) <span style="color:#ef4444">*</span></label>
                            <input type="number" name="paid_amount"
                                   class="form-control <?php echo isset($validation_errors['paid_amount']) ? 'error-field' : ''; ?>"
                                   value="<?php echo htmlspecialchars($form_data['paid_amount']); ?>"
                                   min="0" step="0.01" placeholder="0.00" required>
                            <?php if (isset($validation_errors['paid_amount'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['paid_amount']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Paid Date -->
                        <div class="col-md-6">
                            <label class="form-label">Date of Payment <span style="color:#ef4444">*</span></label>
                            <input type="date" name="paid_date"
                                   class="form-control <?php echo isset($validation_errors['paid_date']) ? 'error-field' : ''; ?>"
                                   value="<?php echo htmlspecialchars($form_data['paid_date']); ?>" required>
                            <?php if (isset($validation_errors['paid_date'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['paid_date']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Payment Screenshot -->
                        <div class="col-md-6">
                            <label class="form-label">Payment Screenshot <span style="color:#ef4444">*</span></label>
                            <div class="upload-area <?php echo isset($validation_errors['payment_screenshot']) ? 'upload-error' : ''; ?>">
                                <input type="file" name="payment_screenshot" accept="image/*" required>
                                <i class="fas fa-receipt upload-icon" style="color:#fb923c;font-size:1.6rem;display:block;margin-bottom:6px;"></i>
                                <div class="upload-title">Upload Payment Receipt</div>
                                <div class="upload-hint">JPG, PNG - click or tap to browse</div>
                            </div>
                            <?php if (isset($validation_errors['payment_screenshot'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['payment_screenshot']; ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Photo Upload -->
                        <div class="col-md-6">
                            <label class="form-label">Student Photo <span style="color:#ef4444">*</span></label>
                            <div class="upload-area <?php echo isset($validation_errors['photo_upload']) ? 'upload-error' : ''; ?>">
                                <input type="file" name="photo_upload" accept="image/*" required>
                                <i class="fas fa-user-circle upload-icon" style="color:#f59e0b;font-size:1.6rem;display:block;margin-bottom:6px;"></i>
                                <div class="upload-title">Upload Your Photo</div>
                                <div class="upload-hint">JPG, PNG - clear passport-style photo</div>
                            </div>
                            <?php if (isset($validation_errors['photo_upload'])): ?>
                                <div class="error-message"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['photo_upload']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div><!-- /sec-payment -->


            <!-- ─────────────────────────────────────────────────── -->
            <!-- TERMS & CONDITIONS                                   -->
            <!-- ─────────────────────────────────────────────────── -->
            <div class="terms-wrap">
                <div class="terms-check">
                    <input type="checkbox" name="terms_agreed" value="yes" id="terms_agreed"
                           class="<?php echo isset($validation_errors['terms_agreed']) ? 'error-field' : ''; ?>"
                           <?php echo $form_data['terms_agreed'] === 'yes' ? 'checked' : ''; ?> required>
                    <label for="terms_agreed" class="terms-text">
                        By submitting this form I confirm that all information provided is accurate, and I agree to
                        PEPP Learning's <a href="https://courses.pepplearning.com/learn/pages/terms-of-service.html" target="_blank" rel="noopener">Terms &amp; Conditions</a>,
                        <a href="https://courses.pepplearning.com/learn/pages/privacy-policy.html" target="_blank" rel="noopener">Privacy Policy</a> and
                        <a href="https://courses.pepplearning.com/learn/pages/refund.html" target="_blank" rel="noopener">Refund Policy</a>.
                        <span style="color:#ef4444">*</span>
                    </label>
                </div>
                <?php if (isset($validation_errors['terms_agreed'])): ?>
                    <div class="error-message" style="margin-top:10px;"><i class="fas fa-exclamation-circle"></i><?php echo $validation_errors['terms_agreed']; ?></div>
                <?php endif; ?>
            </div>

            <!-- ─────────────────────────────────────────────────── -->
            <!-- SUBMIT                                               -->
            <!-- ─────────────────────────────────────────────────── -->
            <div class="submit-block">
                <p class="note"><i class="fas fa-lock" style="color:#fcd34d;margin-right:5px;"></i>Your data is encrypted and securely stored. Fields marked <span style="color:#ef4444">*</span> are required.</p>
                <button type="submit" class="btn-submit" id="submit-btn">
                    <i class="fas fa-paper-plane"></i>
                    Submit Registration
                </button>
            </div>

        </form>
    </div><!-- /form-body -->

    <!-- Footer strip -->
    <div class="reg-footer">
        <p>&copy; <?php echo date('Y'); ?> PEPP Learning - All rights reserved. Student Admission Portal.</p>
    </div>

</div><!-- /reg-card -->
</div><!-- /page-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        let emailTimeout, whatsappTimeout;

        // ── Submit button loading state ──────────────────────────────
        const submitBtn = document.getElementById('submit-btn');
        document.getElementById('registration-form').addEventListener('submit', function() {
            submitBtn.classList.add('loading');
            submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Submitting…';
        });

        // ── File upload label feedback ───────────────────────────────
        document.querySelectorAll('.upload-area input[type="file"]').forEach(function(input) {
            input.addEventListener('change', function() {
                const area = this.closest('.upload-area');
                const title = area.querySelector('.upload-title');
                if (this.files && this.files[0]) {
                    title.textContent = this.files[0].name;
                    area.style.borderColor = '#f59e0b';
                    area.style.background  = '#fffbeb';
                }
            });
        });

        // ── Email real-time check (per course + academic year) ──────
        const emailInput = document.getElementById('email');
        const academicYearSelect = document.querySelector('select[name="pepp_academic_year"]');
        const courseSelect = document.querySelector('select[name="pepp_course"]');

        function checkEmail() {
            const email = emailInput.value.trim();
            const academicYear = academicYearSelect.value;
            const course = courseSelect ? courseSelect.value : '';
            if (email && academicYear && course) {
                clearTimeout(emailTimeout);
                emailTimeout = setTimeout(() => {
                    fetch('?ajax=check_email&email=' + encodeURIComponent(email) + '&academic_year=' + encodeURIComponent(academicYear) + '&course=' + encodeURIComponent(course))
                        .then(r => r.json())
                        .then(data => {
                            const indicator = document.getElementById('email-indicator');
                            const message   = document.getElementById('email-message');
                            if (data.exists) {
                                emailInput.classList.add('error-field');
                                emailInput.classList.remove('success-field');
                                indicator.innerHTML = '<i class="fas fa-times text-danger"></i>';
                                message.innerHTML   = '<div class="error-message"><i class="fas fa-exclamation-circle"></i>Already registered for this course in the selected academic year</div>';
                            } else {
                                emailInput.classList.remove('error-field');
                                emailInput.classList.add('success-field');
                                indicator.innerHTML = '<i class="fas fa-check text-success"></i>';
                                message.innerHTML   = '<div class="success-message"><i class="fas fa-check-circle"></i>Email available</div>';
                            }
                        });
                }, 500);
            }
        }
        emailInput.addEventListener('input', checkEmail);
        academicYearSelect.addEventListener('change', checkEmail);
        if (courseSelect) courseSelect.addEventListener('change', checkEmail);

        // ── WhatsApp real-time check ─────────────────────────────────
        const whatsappInput = document.getElementById('whatsapp');

        function checkWhatsApp() {
            const whatsapp = whatsappInput.value.trim();
            const academicYear = academicYearSelect.value;
            const course = courseSelect ? courseSelect.value : '';
            if (whatsapp && academicYear && course) {
                clearTimeout(whatsappTimeout);
                whatsappTimeout = setTimeout(() => {
                    fetch('?ajax=check_whatsapp&whatsapp=' + encodeURIComponent(whatsapp) + '&academic_year=' + encodeURIComponent(academicYear) + '&course=' + encodeURIComponent(course))
                        .then(r => r.json())
                        .then(data => {
                            const indicator = document.getElementById('whatsapp-indicator');
                            const message   = document.getElementById('whatsapp-message');
                            if (data.exists) {
                                whatsappInput.classList.add('error-field');
                                whatsappInput.classList.remove('success-field');
                                indicator.innerHTML = '<i class="fas fa-times text-danger"></i>';
                                message.innerHTML   = '<div class="error-message"><i class="fas fa-exclamation-circle"></i>Already registered for this course in the selected academic year</div>';
                            } else {
                                whatsappInput.classList.remove('error-field');
                                whatsappInput.classList.add('success-field');
                                indicator.innerHTML = '<i class="fas fa-check text-success"></i>';
                                message.innerHTML   = '<div class="success-message"><i class="fas fa-check-circle"></i>WhatsApp number available</div>';
                            }
                        });
                }, 500);
            }
        }
        whatsappInput.addEventListener('input', checkWhatsApp);
        academicYearSelect.addEventListener('change', checkWhatsApp);
        if (courseSelect) courseSelect.addEventListener('change', checkWhatsApp);

        // ── PIN Code auto-fill ───────────────────────────────────────
        const pincodeInput  = document.getElementById('pincode');
        const stateInput    = document.getElementById('state');
        const districtInput = document.getElementById('district');
        const placeSelect   = document.getElementById('place_select');

        pincodeInput.addEventListener('blur', function() {
            const pincode = this.value.trim();
            if (pincode.length === 6 && /^\d{6}$/.test(pincode)) {
                fetch('?ajax=pincode&pincode=' + pincode)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            stateInput.value    = data.state;
                            districtInput.value = data.district;
                            placeSelect.innerHTML = '<option value="">Select Place</option>';
                            data.places.forEach(place => {
                                const opt = document.createElement('option');
                                opt.value = place; opt.textContent = place;
                                placeSelect.appendChild(opt);
                            });
                        } else {
                            stateInput.value = ''; districtInput.value = '';
                            placeSelect.innerHTML = '<option value="">Select Place</option>';
                        }
                    });
            }
        });

        // ── Mobile number sync ───────────────────────────────────────
        const mobileRadios = document.querySelectorAll('input[name="mobile_same_whatsapp"]');
        const mobileInput  = document.getElementById('mobile_number');
        const mobileRow    = document.getElementById('mobile-number-row');
        const syncIndicator = document.getElementById('mobile-sync-indicator');

        mobileRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'yes') {
                    mobileRow.style.display    = 'none';
                    syncIndicator.style.display = 'flex';
                    if (whatsappInput && mobileInput) mobileInput.value = whatsappInput.value;
                } else {
                    mobileRow.style.display    = 'block';
                    syncIndicator.style.display = 'none';
                    mobileInput.value = '';
                }
            });
        });

        if (whatsappInput && mobileInput) {
            whatsappInput.addEventListener('input', function() {
                const yesRadio = document.querySelector('input[name="mobile_same_whatsapp"][value="yes"]');
                if (yesRadio && yesRadio.checked) mobileInput.value = this.value;
            });
        }

        // Initialise mobile field visibility on page load
        const checkedRadio = document.querySelector('input[name="mobile_same_whatsapp"]:checked');
        if (checkedRadio) {
            if (checkedRadio.value === 'yes') {
                mobileRow.style.display    = 'none';
                syncIndicator.style.display = 'flex';
            } else {
                mobileRow.style.display    = 'block';
                syncIndicator.style.display = 'none';
            }
        }
    });
</script>

<script>
// ── Course fee auto-display + coupon/referral apply ──
(function () {
    var feeMap = <?php echo json_encode($fee_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var courseSel = document.querySelector('select[name="pepp_course"]');
    var yearSel   = document.querySelector('select[name="pepp_academic_year"]');
    var codeInput = document.getElementById('coupon_code');
    var applyBtn  = document.getElementById('apply-code-btn');
    var paidInput = document.querySelector('input[name="paid_amount"]');
    var emailInput = document.getElementById('email');
    var waInput   = document.getElementById('whatsapp');
    var box = document.getElementById('fee-box');
    var feeAmt = document.getElementById('fee-amount');
    var discRow = document.getElementById('fee-discount-row');
    var discLbl = document.getElementById('fee-discount-label');
    var discAmt = document.getElementById('fee-discount');
    var payAmt = document.getElementById('fee-payable');
    var msg = document.getElementById('code-msg');
    var currentFee = 0, currentDiscount = 0;

    function inr(n) { return '₹' + Number(n || 0).toLocaleString('en-IN'); }

    function lookupFee() {
        var c = courseSel ? courseSel.value : '', y = yearSel ? yearSel.value : '';
        if (!c) return 0;
        if (feeMap[c + '||' + y] !== undefined) return feeMap[c + '||' + y];
        if (feeMap[c + '||'] !== undefined) return feeMap[c + '||'];
        return 0;
    }
    function renderFee() {
        currentFee = lookupFee();
        if (currentFee <= 0) { box.style.display = 'none'; return; }
        box.style.display = 'block';
        feeAmt.textContent = inr(currentFee);
        if (currentDiscount > 0) {
            discRow.style.display = 'flex';
            discAmt.textContent = '-' + inr(currentDiscount);
        } else {
            discRow.style.display = 'none';
        }
        payAmt.textContent = inr(Math.max(0, currentFee - currentDiscount));
    }
    function resetDiscount() { currentDiscount = 0; if (msg) { msg.textContent = ''; msg.className = 'code-msg'; } renderFee(); }

    if (courseSel) courseSel.addEventListener('change', resetDiscount);
    if (yearSel) yearSel.addEventListener('change', function () {
        // Re-validate an already-entered code against the new year
        resetDiscount();
        if (codeInput && codeInput.value.trim()) applyCode();
    });

    function applyCode() {
        var code = (codeInput.value || '').trim();
        if (!code) { resetDiscount(); return; }
        var c = courseSel ? courseSel.value : '', y = yearSel ? yearSel.value : '';
        if (!c || !y) { msg.textContent = 'Select your PEPP course and academic year first.'; msg.className = 'code-msg err'; return; }
        applyBtn.disabled = true; applyBtn.textContent = '…';
        var qs = 'check_code=1&course=' + encodeURIComponent(c) + '&year=' + encodeURIComponent(y) +
                 '&code=' + encodeURIComponent(code) +
                 '&email=' + encodeURIComponent(emailInput ? emailInput.value : '') +
                 '&whatsapp=' + encodeURIComponent(waInput ? waInput.value : '');
        fetch('register.php?' + qs).then(function (r) { return r.json(); }).then(function (d) {
            applyBtn.disabled = false; applyBtn.textContent = 'Apply';
            currentFee = d.fee || currentFee;
            if (d.ok) {
                currentDiscount = d.discount || 0;
                msg.textContent = d.message; msg.className = 'code-msg ok';
                if (paidInput && (!paidInput.value || +paidInput.value === 0)) paidInput.value = Math.max(0, currentFee - currentDiscount);
            } else {
                currentDiscount = 0;
                msg.textContent = d.message || 'Invalid code.'; msg.className = 'code-msg err';
            }
            renderFee();
        }).catch(function () { applyBtn.disabled = false; applyBtn.textContent = 'Apply'; msg.textContent = 'Could not validate the code. Try again.'; msg.className = 'code-msg err'; });
    }
    if (applyBtn) applyBtn.addEventListener('click', applyCode);

    // Prefill coupon from a referral URL (?ref=CODE or ?coupon=CODE)
    var params = new URLSearchParams(window.location.search);
    var pre = params.get('ref') || params.get('coupon') || params.get('code');
    if (pre && codeInput) { codeInput.value = pre.toUpperCase(); }

    renderFee();
    // If a code is prefilled and course/year already chosen (e.g. form repost), validate
    if (codeInput && codeInput.value.trim() && courseSel && courseSel.value && yearSel && yearSel.value) applyCode();
})();
</script>

<!-- WhatsApp Floating Chat Widget -->
<div class="wa-support-widget">
    <div class="wa-tooltip">Need Help? Chat with Us</div>
    <a href="https://wa.me/919567276458?text=Hi%20PEPP%20Support%20Desk,%20I%20need%20help%20with%20the%20admissions%20registration%20process." target="_blank" rel="noopener" class="wa-btn" title="Chat with support">
        <i class="fab fa-whatsapp"></i>
        <span class="wa-pulse"></span>
    </a>
</div>

</body>
</html>
