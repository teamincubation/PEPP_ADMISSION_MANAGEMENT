<?php
/**
 * Helper utilities for Meta API error classification and communication status mappings.
 */
class CommunicationHelper {
    /**
     * Determines whether a Meta API error code and/or message indicates a permanent
     * (non-retryable) failure.
     *
     * @param int|string|null $errorCode
     * @param string|null $errorMessage
     * @return bool
     */
    public static function isPermanentMetaFailure($errorCode, $errorMessage) {
        $code = ($errorCode !== null) ? (int)$errorCode : 0;
        $lowerMsg = ($errorMessage !== null) ? strtolower($errorMessage) : '';

        // Explicitly transient error codes from Meta:
        // 131021: Rate limit reached
        // 131048: Spammer protection rate limit
        // 429: Too Many Requests (Transient HTTP status)
        if (in_array($code, [131021, 131048, 429], true)) {
            return false;
        }

        // Explicitly permanent/non-retryable error codes:
        // 131026: Message undeliverable (user block, non-WhatsApp number)
        // 131053: Ecosystem engagement / Policy block
        // 131047: Outside 24h window (cannot send free-form message)
        // 131045: Business account locked/suspended
        // 131051: Template is paused
        // 131052: Template is disabled
        // 100: Invalid parameters, template mismatch, invalid phone, etc.
        // 190: Invalid oauth token
        if (in_array($code, [131026, 131053, 131047, 131045, 131051, 131052, 100, 190], true)) {
            return true;
        }

        // Text-based fallback checks (if code is missing or generic)
        if (
            strpos($lowerMsg, 'healthy ecosystem engagement') !== false ||
            strpos($lowerMsg, '131026') !== false ||
            strpos($lowerMsg, 'policy') !== false ||
            strpos($lowerMsg, 'not in allowed list') !== false ||
            strpos($lowerMsg, 'invalid phone number') !== false ||
            strpos($lowerMsg, 'does not exist') !== false ||
            strpos($lowerMsg, 'recipient') !== false ||
            strpos($lowerMsg, 'undeliverable') !== false ||
            strpos($lowerMsg, 'not a whatsapp number') !== false ||
            strpos($lowerMsg, 'parameter count mismatch') !== false ||
            strpos($lowerMsg, 'not approved') !== false ||
            strpos($lowerMsg, 'not found in database') !== false ||
            (strpos($lowerMsg, 'http 400') !== false && strpos($lowerMsg, 'rate limit') === false) ||
            strpos($lowerMsg, 'param') !== false ||
            strpos($lowerMsg, 'template') !== false
        ) {
            return true;
        }

        // Default: if it's a general network/curl error or generic HTTP error, we assume it's transient/retryable.
        if (
            strpos($lowerMsg, 'curl error') !== false ||
            strpos($lowerMsg, 'timeout') !== false ||
            strpos($lowerMsg, 'http 500') !== false ||
            strpos($lowerMsg, 'http 502') !== false ||
            strpos($lowerMsg, 'http 503') !== false ||
            strpos($lowerMsg, 'http 504') !== false ||
            strpos($lowerMsg, 'rate limit') !== false
        ) {
            return false;
        }

        // Default to transient for undefined failures to preserve the retry mechanism
        return false;
    }

    public static function getERPVariables() {
        return [
            // Student Details
            'student_name' => [
                'label' => 'Student Name',
                'category' => 'Student Details',
                'description' => 'Registered full name of the student',
                'sample' => 'John Doe',
                'is_financial' => false
            ],
            'student_uid' => [
                'label' => 'Student UID',
                'category' => 'Student Details',
                'description' => 'Unique ERP user ID or registration identifier',
                'sample' => 'PEPP20268575',
                'is_financial' => false
            ],
            'student_id' => [
                'label' => 'Student ID',
                'category' => 'Student Details',
                'description' => 'Unique ERP roll number or student ID',
                'sample' => 'PEPP20268575',
                'is_financial' => false
            ],
            'whatsapp_number' => [
                'label' => 'WhatsApp Number',
                'category' => 'Student Details',
                'description' => 'Primary WhatsApp contact number with country code',
                'sample' => '919876543210',
                'is_financial' => false
            ],
            'student_phone' => [
                'label' => 'Student Phone',
                'category' => 'Student Details',
                'description' => 'Registered mobile/contact number alias',
                'sample' => '919876543210',
                'is_financial' => false
            ],
            'email' => [
                'label' => 'Email',
                'category' => 'Student Details',
                'description' => 'Primary registered email address',
                'sample' => 'student@pepplearning.in',
                'is_financial' => false
            ],
            'gender' => [
                'label' => 'Gender',
                'category' => 'Student Details',
                'description' => 'Registered student gender',
                'sample' => 'Male',
                'is_financial' => false
            ],
            'date_of_birth' => [
                'label' => 'Date of Birth',
                'category' => 'Student Details',
                'description' => 'Student date of birth details',
                'sample' => '25 Aug 2005',
                'is_financial' => false
            ],
            'college_school' => [
                'label' => 'College / School',
                'category' => 'Student Details',
                'description' => 'Student affiliated academic institution',
                'sample' => 'Model High School',
                'is_financial' => false
            ],
            'source' => [
                'label' => 'Source',
                'category' => 'Student Details',
                'description' => 'How student knew about PEPP (registration source)',
                'sample' => 'Instagram',
                'is_financial' => false
            ],
            'how_know_pepp' => [
                'label' => 'How Know PEPP',
                'category' => 'Student Details',
                'description' => 'Alias for student registration source field',
                'sample' => 'Instagram',
                'is_financial' => false
            ],

            // Academic Details
            'course_name' => [
                'label' => 'Course Name',
                'category' => 'Academic Details',
                'description' => 'Name of the enrolled course',
                'sample' => 'M. Clin. Psy. (Standard Plan)',
                'is_financial' => false
            ],
            'current_course_name' => [
                'label' => 'Current Course Name',
                'category' => 'Academic Details',
                'description' => 'Alias for current enrolled course name',
                'sample' => 'M. Clin. Psy. (Standard Plan)',
                'is_financial' => false
            ],
            'academic_year' => [
                'label' => 'Academic Year',
                'category' => 'Academic Details',
                'description' => 'Enrolled academic session year',
                'sample' => '2026-27',
                'is_financial' => false
            ],
            'payment_plan' => [
                'label' => 'Payment Plan',
                'category' => 'Academic Details',
                'description' => 'Structured payment arrangement plan selected',
                'sample' => 'Installment Plan (2 Parts)',
                'is_financial' => false
            ],

            // Financial Details
            'current_course_fee' => [
                'label' => 'Current Net Course Fee',
                'category' => 'Financial Details',
                'description' => 'Net fee of current course after discounts',
                'sample' => '₹9,499',
                'is_financial' => true
            ],
            'registration_fee' => [
                'label' => 'Registration Fee',
                'category' => 'Financial Details',
                'description' => 'Assigned registration base fee',
                'sample' => '₹2,500',
                'is_financial' => true
            ],
            'registration_paid' => [
                'label' => 'Registration Paid',
                'category' => 'Financial Details',
                'description' => 'Registration payment amount collected',
                'sample' => '₹2,500',
                'is_financial' => true
            ],
            'registration_paid_date' => [
                'label' => 'Registration Paid Date',
                'category' => 'Financial Details',
                'description' => 'Date on which registration payment was completed',
                'sample' => '25 Aug 2026',
                'is_financial' => false
            ],
            'registration_payment_amount' => [
                'label' => 'Registration Payment Amount',
                'category' => 'Financial Details',
                'description' => 'Amount collected in registration transaction',
                'sample' => '₹2,500',
                'is_financial' => true
            ],
            'registration_payment_date' => [
                'label' => 'Registration Payment Date',
                'category' => 'Financial Details',
                'description' => 'Date on which registration transaction was recorded',
                'sample' => '25 Aug 2026',
                'is_financial' => false
            ],
            'installment_amount' => [
                'label' => 'Installment Amount',
                'category' => 'Financial Details',
                'description' => 'Unpaid installment payment value due',
                'sample' => '₹3,499',
                'is_financial' => true
            ],
            'installment_number' => [
                'label' => 'Installment Count / Number',
                'category' => 'Financial Details',
                'description' => 'Next pending installment index description',
                'sample' => '1st',
                'is_financial' => false
            ],
            'installment_count' => [
                'label' => 'Total Installment Count',
                'category' => 'Financial Details',
                'description' => 'Total count of scheduled installments',
                'sample' => '2',
                'is_financial' => false
            ],
            'installment_due_date' => [
                'label' => 'Installment Due Date',
                'category' => 'Financial Details',
                'description' => 'Due date of the next pending installment',
                'sample' => '10 Sep 2026',
                'is_financial' => false
            ],
            'total_paid' => [
                'label' => 'Total Paid',
                'category' => 'Financial Details',
                'description' => 'Cumulative sum paid by student (Reg + Installments)',
                'sample' => '₹5,999',
                'is_financial' => true
            ],
            'total_collected' => [
                'label' => 'Total Collected',
                'category' => 'Financial Details',
                'description' => 'Alias for cumulative sum collected',
                'sample' => '₹5,999',
                'is_financial' => true
            ],
            'outstanding_balance' => [
                'label' => 'Outstanding Balance',
                'category' => 'Financial Details',
                'description' => 'Outstanding course balance to be paid',
                'sample' => '₹3,500',
                'is_financial' => true
            ],
            'payment_date' => [
                'label' => 'Payment Date',
                'category' => 'Financial Details',
                'description' => 'Date of registration or recent payment transaction',
                'sample' => '25 Aug 2026',
                'is_financial' => false
            ],
            'amount_paid' => [
                'label' => 'Amount Paid',
                'category' => 'Financial Details',
                'description' => 'Assigned transaction paid value',
                'sample' => '₹2,500',
                'is_financial' => true
            ],
            'balance' => [
                'label' => 'Balance',
                'category' => 'Financial Details',
                'description' => 'Remaining outstanding course balance',
                'sample' => '₹3,500',
                'is_financial' => true
            ],

            // Course Migration
            'previous_course_name' => [
                'label' => 'Previous Course Name',
                'category' => 'Course Migration',
                'description' => 'Original course name before migration',
                'sample' => 'M. Clin. Psy. (Basic Plan)',
                'is_financial' => false
            ],
            'new_course_name' => [
                'label' => 'New Course Name',
                'category' => 'Course Migration',
                'description' => 'Target course name after migration',
                'sample' => 'M. Clin. Psy. (Standard Plan)',
                'is_financial' => false
            ],
            'new_course_fee' => [
                'label' => 'New Course Fee',
                'category' => 'Course Migration',
                'description' => 'Fee of the course migrated to',
                'sample' => '₹9,499',
                'is_financial' => true
            ],
            'migration_amount_paid' => [
                'label' => 'Migration Amount Paid',
                'category' => 'Course Migration',
                'description' => 'Immediate payment paid at migration time',
                'sample' => '₹3,500',
                'is_financial' => true
            ],
            'new_outstanding_balance' => [
                'label' => 'New Outstanding Balance',
                'category' => 'Course Migration',
                'description' => 'Outstanding balance remaining after migration',
                'sample' => '₹4,499',
                'is_financial' => true
            ],
            'updated_payment_details' => [
                'label' => 'Updated Course / Payment Details',
                'category' => 'Course Migration',
                'description' => 'Summary of revised installments payments schedule',
                'sample' => '2 installments of ₹2,499 each, starting 24 Sep 2026',
                'is_financial' => false
            ],

            // Legacy / Compatibility
            'student_email' => [
                'label' => 'Student Email (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Legacy primary email address key',
                'sample' => 'student@pepplearning.in',
                'is_financial' => false
            ],
            'application_id' => [
                'label' => 'Application ID (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Legacy student roll number alias key',
                'sample' => 'PEPP20268575',
                'is_financial' => false
            ],
            'mobile_number' => [
                'label' => 'Mobile Number (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Legacy contact mobile key',
                'sample' => '9876543210',
                'is_financial' => false
            ],
            'course_fee' => [
                'label' => 'Course Catalog Fee (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Base catalog fee before discount',
                'sample' => '₹9,499',
                'is_financial' => true
            ],
            'registration_fee_paid' => [
                'label' => 'Registration Fee Paid (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Registration payment collected alias key',
                'sample' => '₹2,500',
                'is_financial' => true
            ],
            'paid_amount' => [
                'label' => 'Paid Amount (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Initial payment amount alias key',
                'sample' => '₹2,500',
                'is_financial' => true
            ],
            'paid_date' => [
                'label' => 'Paid Date (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Registration payment date alias key',
                'sample' => '25 Aug 2026',
                'is_financial' => false
            ],
            'payment_amount' => [
                'label' => 'Payment Amount (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Amount collected in registration or transaction',
                'sample' => '₹2,500',
                'is_financial' => true
            ],
            'payment_mode' => [
                'label' => 'Payment Mode (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Payment method used (e.g. Online)',
                'sample' => 'Online',
                'is_financial' => false
            ],
            'discount_amount' => [
                'label' => 'Discount Amount (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Total discount amount granted',
                'sample' => '₹1,500',
                'is_financial' => true
            ],
            'total_payable' => [
                'label' => 'Total Net Payable (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Net fee of current course after discounts',
                'sample' => '₹9,499',
                'is_financial' => true
            ],
            'balance_amount' => [
                'label' => 'Balance Amount (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Outstanding course balance to be paid',
                'sample' => '₹3,500',
                'is_financial' => true
            ],
            'number_of_installments' => [
                'label' => 'Number of Installments (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Scheduled installments count alias key',
                'sample' => '2',
                'is_financial' => false
            ],
            'installment_paid' => [
                'label' => 'Installment Paid (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Sum of approved installment payments',
                'sample' => '₹3,499',
                'is_financial' => true
            ],
            'next_due_date' => [
                'label' => 'Next Due Date (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Due date of the next pending installment',
                'sample' => '10 Sep 2026',
                'is_financial' => false
            ],
            'invoice_number' => [
                'label' => 'Invoice Number (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Generated transaction invoice number',
                'sample' => 'INV-2026-0034',
                'is_financial' => false
            ],
            'invoice_link' => [
                'label' => 'Secure Invoice Link (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Secure invoice PDF download link',
                'sample' => 'https://pepplearning.in/invoice/INV-0034',
                'is_financial' => false
            ],
            'banking_details' => [
                'label' => 'Banking Details (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Public bank account details alias key',
                'sample' => 'PEPP Learning HDFC A/C: 502000...',
                'is_financial' => false
            ],
            'session_date' => [
                'label' => 'Session Date (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Scheduled activity session date',
                'sample' => '26 Aug 2026',
                'is_financial' => false
            ],
            'trainer_name' => [
                'label' => 'Trainer Name (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Assigned activity trainer name',
                'sample' => 'Prof. Sarah',
                'is_financial' => false
            ],
            'meeting_link' => [
                'label' => 'Meeting Link (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Session meeting video URL',
                'sample' => 'https://meet.google.com/abc-defg-hij',
                'is_financial' => false
            ],
            'rejection_reason' => [
                'label' => 'Rejection Reason (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Approval rejection reason from log',
                'sample' => 'Payment proof invalid or screenshot unclear',
                'is_financial' => false
            ],
            'new_access_end' => [
                'label' => 'New Access End Date (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Extended course access end date',
                'sample' => '12 Jun 2026',
                'is_financial' => false
            ],
            'access_end' => [
                'label' => 'Access End Date (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Access expiry date limit',
                'sample' => '12 Jun 2026',
                'is_financial' => false
            ],
            'course_duration_date' => [
                'label' => 'Course Duration Date (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Course expiry end date key alias',
                'sample' => '12 Jun 2026',
                'is_financial' => false
            ],
            'current_datetime' => [
                'label' => 'Current Date/Time (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Current formatted datetime timestamp',
                'sample' => '25 Aug 2026 02:10 PM',
                'is_financial' => false
            ],
            'previous_course_fee' => [
                'label' => 'Previous Course Fee (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Fee of the course migrated from',
                'sample' => '₹5,999',
                'is_financial' => true
            ],
            'upgrade_amount' => [
                'label' => 'Upgrade Amount (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Upgrade fee net difference cost',
                'sample' => '₹3,500',
                'is_financial' => true
            ],
            'migration_date' => [
                'label' => 'Migration Date (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Date on which course migration was saved',
                'sample' => '25 Aug 2026',
                'is_financial' => false
            ],
            'migration_reason' => [
                'label' => 'Migration Reason (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Administrative migration reason log note',
                'sample' => 'Upgrade plan request',
                'is_financial' => false
            ],
            'previous_academic_year' => [
                'label' => 'Previous Academic Year (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Academic year before migration',
                'sample' => '2026-27',
                'is_financial' => false
            ],
            'new_academic_year' => [
                'label' => 'New Academic Year (Legacy)',
                'category' => 'Legacy / Compatibility',
                'description' => 'Academic year after migration',
                'sample' => '2026-27',
                'is_financial' => false
            ],

            // Alumni / Referral Details
            'alumni_name' => [
                'label' => 'Alumni Name',
                'category' => 'Alumni / Referral',
                'description' => 'Full name of the verified PEPPian/alumnus.',
                'sample' => 'Adnan',
                'is_financial' => false
            ],
            'referral_code' => [
                'label' => 'Referral Code',
                'category' => 'Alumni / Referral',
                'description' => 'Unique referral code generated for the PEPPian.',
                'sample' => 'CODE123',
                'is_financial' => false
            ],
            'referral_link' => [
                'label' => 'Referral Link',
                'category' => 'Alumni / Referral',
                'description' => 'Complete PEPP registration referral URL generated from the alumnus referral code.',
                'sample' => 'https://pepplearning.in/admissions/register.php?ref=CODE123',
                'is_financial' => false
            ]
        ];
    }
}
