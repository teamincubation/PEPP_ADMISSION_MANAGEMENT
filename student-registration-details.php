<?php
date_default_timezone_set('Asia/Kolkata');
require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isset($_GET['user_id'])) {
    header('Location: student-approval.php');
    exit;
}

$user_id = $_GET['user_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        header('Location: student-approval.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: student-approval.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Details - <?php echo htmlspecialchars($student['name']); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/admin-style.css" rel="stylesheet">
</head>
<body>
    <div class="admin-container">
        <div class="main-content">
            <div class="content-header">
                <div class="header-left">
                    <h1><i class="fas fa-user-graduate"></i> Registration Details</h1>
                    <p>Review student registration information before approval</p>
                </div>
                <div class="header-right">
                    <a href="student-approval.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Approval List
                    </a>
                </div>
            </div>

            <!-- Student Header -->
            <div class="student-header">
                <div class="student-avatar">
                    <?php if ($student['photo']): ?>
                        <img src="<?php echo htmlspecialchars($student['photo']); ?>" alt="Student Photo">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="student-info">
                    <h2><?php echo htmlspecialchars($student['name']); ?></h2>
                    <p class="student-id">User ID: <?php echo htmlspecialchars($student['user_id']); ?></p>
                    <span class="status-badge status-<?php echo $student['status']; ?>">
                        <i class="fas fa-circle"></i>
                        <?php echo ucfirst($student['status']); ?>
                    </span>
                </div>
                <div class="student-actions">
                    <div class="quick-actions">
                        <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
                        <div class="action-buttons">
                            <?php if ($student['status'] === 'pending'): ?>
                                <button class="btn-action btn-approve" onclick="approveStudent('<?php echo $student['user_id']; ?>')">
                                    <i class="fas fa-check-circle"></i> Approve Student
                                </button>
                                <button class="btn-action btn-reject" onclick="rejectStudent('<?php echo $student['user_id']; ?>')">
                                    <i class="fas fa-times-circle"></i> Reject Student
                                </button>
                            <?php endif; ?>
                            <button class="btn-action btn-email" onclick="sendEmail('<?php echo htmlspecialchars($student['email']); ?>', '<?php echo htmlspecialchars($student['name']); ?>')">
                                <i class="fas fa-envelope"></i> Send Email
                            </button>
                            <button class="btn-action btn-whatsapp" onclick="sendWhatsApp('<?php echo $student['whatsapp_country_code'] . ' ' . $student['whatsapp_number']; ?>', '<?php echo htmlspecialchars($student['name']); ?>')">
                                <i class="fab fa-whatsapp"></i> Send WhatsApp
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Registration Information -->
            <div class="details-grid">
                <!-- Personal Information -->
                <div class="details-card">
                    <div class="card-header">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Full Name</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['name']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Gender</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['gender']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Date of Birth</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($student['date_of_birth'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Email</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['email']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">WhatsApp Number</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['whatsapp_country_code'] . ' ' . $student['whatsapp_number']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Mobile Number</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['mobile_number']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="details-card">
                    <div class="card-header">
                        <h3><i class="fas fa-graduation-cap"></i> Academic Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">PEPP Course</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['pepp_course']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Academic Year</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['pepp_academic_year']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">College/School</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['college_school']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Course</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['course']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">University/Board</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['university_board']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Remaining Semesters</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['remaining_semesters']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visitor Information -->
                <div class="details-card">
                    <div class="card-header">
                        <h3><i class="fas fa-globe"></i> Visitor Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Registration IP</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['last_visit_ip'] ?? 'Not recorded'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Location</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['last_visit_location'] ?? 'Not recorded'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">ISP</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['last_visit_isp'] ?? 'Not recorded'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">AS</span>
                                <span class="info-value"><?php echo htmlspecialchars($student['last_visit_as'] ?? 'Not recorded'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Registration Time</span>
                                <span class="info-value"><?php echo date('d M Y, h:i A', strtotime($student['submit_datetime'])) . ' (IST)'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function approveStudent(userId) {
            if (confirm('Are you sure you want to approve this student?')) {
                window.location.href = `student-approval.php?action=approve&user_id=${userId}`;
            }
        }

        function rejectStudent(userId) {
            const reason = prompt('Please enter the reason for rejection:');
            if (reason && reason.trim()) {
                window.location.href = `student-approval.php?action=reject&user_id=${userId}&reason=${encodeURIComponent(reason)}`;
            }
        }

        function sendEmail(email, name) {
            const subject = `Regarding your PEPP Learning Application - ${name}`;
            const body = `Dear ${name},\n\nWe hope this email finds you well.\n\nRegarding your application with PEPP Learning...\n\nBest regards,\nPEPP Learning Team`;
            window.location.href = `mailto:${email}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
        }

        function sendWhatsApp(phone, name) {
            const phoneData = phone.split(' ');
            const countryCode = phoneData[0] || '+91';
            const phoneNumber = phoneData[1] || phone;
            const formattedPhone = formatPhoneForWhatsApp(phoneNumber, countryCode);
            const message = `Hello ${name},\\n\\nThis is regarding your PEPP Learning application.\\n\\nBest regards,\\nPEPP Learning Team`;
            window.open(`https://api.whatsapp.com/send/?phone=${formattedPhone}&text=${encodeURIComponent(message)}`, '_blank');
        }

        function formatPhoneForWhatsApp(phoneNumber, countryCode = '+91') {
            let cleanPhone = phoneNumber.replace(/[^\d]/g, '');
            let cleanCountryCode = countryCode.replace(/[^\d]/g, '');
            
            if (cleanPhone.startsWith(cleanCountryCode)) {
                return cleanPhone;
            }
            
            if (cleanPhone.length === 10) {
                return cleanCountryCode + cleanPhone;
            }
            
            if (cleanPhone.length > 10 && !cleanPhone.startsWith(cleanCountryCode)) {
                return cleanCountryCode + cleanPhone;
            }
            
            return cleanCountryCode + cleanPhone;
        }
    </script>
</body>
</html>
