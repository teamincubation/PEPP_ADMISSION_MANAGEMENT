<?php
require_once 'includes/auth.php';
require_permission('leads');
require_once 'includes/template_helper.php';

/* Lead Management (CRM).
   Capture and work prospective students before they register. Super Admin and
   any admin granted the 'leads' page can add individual leads or bulk-import
   from Excel/CSV, then track each lead through a standard pipeline with
   remarks, follow-up dates and a full activity timeline.

   Pipeline:  new → contacted → follow_up → interested → converted
                                                       ↘ not_interested / rejected
   next_followup_date is required until a lead is converted/rejected. */

$LEAD_STATUSES = [
    'new'            => ['New',            'blue'],
    'contacted'      => ['Contacted',      'violet'],
    'follow_up'      => ['Follow-up',      'amber'],
    'interested'     => ['Interested',     'teal'],
    'not_interested' => ['Not Interested', 'gray'],
    'converted'      => ['Converted',      'green'],
    'rejected'       => ['Rejected',       'red'],
];
$YEARS = ['First Year', 'Second Year', 'Third Year', 'Fourth Year', 'Completed'];
$CLOSED = ['converted', 'rejected', 'not_interested'];

/* ── Filter Lock/Unlock Handler ── */
if (isset($_GET['unlock'])) {
    unset($_SESSION['locked_lead_filters']);
    $cleanParams = $_GET;
    unset($cleanParams['unlock']);
    header("Location: lead-management.php" . (!empty($cleanParams) ? "?" . http_build_query($cleanParams) : ""));
    exit;
}

if (isset($_GET['lock'])) {
    $_SESSION['locked_lead_filters'] = [
        'status'           => $_GET['status'] ?? '',
        'assigned'         => $_GET['assigned'] ?? '',
        'last_remarked_by' => $_GET['last_remarked_by'] ?? '',
        'course'           => $_GET['course'] ?? '',
        'due'              => $_GET['due'] ?? '',
        'joined'           => $_GET['joined'] ?? '',
        'converted_by'     => $_GET['converted_by'] ?? [],
        'q'                => $_GET['q'] ?? ''
    ];
    $cleanParams = $_GET;
    unset($cleanParams['lock']);
    header("Location: lead-management.php" . (!empty($cleanParams) ? "?" . http_build_query($cleanParams) : ""));
    exit;
}

if (isset($_SESSION['locked_lead_filters']) && !isset($_GET['unlock'])) {
    $tempGet = $_GET;
    unset($tempGet['page']);
    if (empty($tempGet)) {
        $_GET['status']           = $_SESSION['locked_lead_filters']['status'] ?? '';
        $_GET['assigned']         = $_SESSION['locked_lead_filters']['assigned'] ?? '';
        $_GET['last_remarked_by'] = $_SESSION['locked_lead_filters']['last_remarked_by'] ?? '';
        $_GET['course']           = $_SESSION['locked_lead_filters']['course'] ?? '';
        $_GET['due']              = $_SESSION['locked_lead_filters']['due'] ?? '';
        $_GET['joined']           = $_SESSION['locked_lead_filters']['joined'] ?? '';
        $_GET['converted_by']     = $_SESSION['locked_lead_filters']['converted_by'] ?? [];
        $_GET['q']                = $_SESSION['locked_lead_filters']['q'] ?? '';
    }
}

$success_message = '';
$error_message   = '';
$import_summary  = null;

if (!function_exists('leads_table_exists')) {
    function leads_table_exists($pdo) {
        static $e = null;
        if ($e === null) {
            try {
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $e = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='leads'")->fetchColumn();
                } else {
                    $e = (bool)$pdo->query("SHOW TABLES LIKE 'leads'")->fetchColumn();
                }
            } catch (Exception $ex) { $e = false; }
        }
        return $e;
    }
}
if (!leads_table_exists($pdo)) {
    $active_page = 'leads'; $page_title = 'Lead Management'; $page_sub = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>The Lead Management system is not installed yet. Run <strong>database-update-4.sql</strong> once in phpMyAdmin, then reload.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

/** Log a lead activity row. */
function lead_log($pdo, $lead_id, $type, $remark, $old, $new, $followup, $admin) {
    try {
        $stmt = $pdo->prepare("INSERT INTO lead_activity (lead_id, activity_type, remark, old_status, new_status, followup_date, performed_by, performed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$lead_id, $type, $remark, $old, $new, $followup ?: null, $admin]);
        $pdo->prepare("UPDATE leads SET last_activity_at = NOW() WHERE id = ?")->execute([$lead_id]);
    } catch (Exception $e) { error_log('lead_log: ' . $e->getMessage()); }
}
function clean_wa($n) {
    $n = preg_replace('/\D/', '', (string)$n);
    if (strlen($n) === 10) $n = '91' . $n;     // default India
    return $n;
}
ensure_lead_converted_by_column($pdo);

// Admins that leads can be assigned to (super admin only)
$assignable = [];
if (is_super_admin() && admins_table_exists($pdo)) {
    try {
        $assignable = $pdo->query("SELECT username FROM admins WHERE status = 'active' ORDER BY role = 'super_admin' DESC, username")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
}

// All legitimate admins for conversion attribution (all valid admins, not restricted to active unless established)
$all_admins = [];
$all_admin_names = [];
if (admins_table_exists($pdo)) {
    try {
        $stmtAllAdm = $pdo->query("SELECT username, full_name FROM admins WHERE username IS NOT NULL AND TRIM(username) <> '' ORDER BY full_name ASC, username ASC");
        $all_admins = $stmtAllAdm->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all_admins as $admRow) {
            $all_admin_names[$admRow['username']] = $admRow['full_name'] ?: $admRow['username'];
        }
    } catch (Exception $e) {}
}

/* ── POST actions ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_lead') {
                $wa = clean_wa($_POST['whatsapp_number'] ?? '');
                $status = in_array($_POST['status'] ?? '', array_keys($LEAD_STATUSES), true) ? $_POST['status'] : 'new';
                $followup = $_POST['next_followup_date'] ?? '';
                $course_name = trim($_POST['interested_course'] ?? '');
                
                if (strlen($wa) < 11) {
                    $error_message = 'A valid WhatsApp number is required.';
                } elseif (!in_array($status, $CLOSED, true) && $followup === '') {
                    $error_message = 'A next follow-up date is required until the lead is converted or rejected.';
                } else {
                    $pdo->beginTransaction();
                    $lockAcquired = acquireLeadLock($pdo, $wa, $course_name);
                    try {
                        // Check duplicate lead
                        $dupRes = checkLeadDuplicate($pdo, $wa, $course_name, null, true);
                        if ($dupRes['count'] > 0) {
                            $existingLead = $dupRes['matches'][0];
                            $error_message = "Lead already exists for this contact number for this course (Lead ID: #{$existingLead['id']}, Name: " . htmlspecialchars($existingLead['name'] ?? 'Unknown') . ", Status: " . htmlspecialchars($LEAD_STATUSES[$existingLead['status']][0] ?? $existingLead['status']) . ").";
                            $pdo->rollBack();
                        } else {
                            $assigned = is_super_admin() ? (trim($_POST['assigned_to'] ?? '') ?: '__ALL__') : '__ALL__';
                            $stmt = $pdo->prepare("
                                INSERT INTO leads (whatsapp_number, name, interested_course, last_institute, last_course,
                                    is_fyugp, year_of_study, status, next_followup_date, assigned_to, source, created_by, created_at, last_activity_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, NOW(), NOW())
                            ");
                            $yr = in_array($_POST['year_of_study'] ?? '', $YEARS, true) ? $_POST['year_of_study'] : null;
                            $fy = in_array($_POST['is_fyugp'] ?? '', ['yes', 'no'], true) ? $_POST['is_fyugp'] : null;
                            $stmt->execute([
                                normalizeLeadPhone($wa), trim($_POST['name'] ?? ''), $course_name,
                                trim($_POST['last_institute'] ?? ''), trim($_POST['last_course'] ?? ''),
                                $fy, $yr, $status, in_array($status, $CLOSED, true) ? ($followup ?: null) : $followup,
                                $assigned, $admin_username
                            ]);
                            $lead_id = (int)$pdo->lastInsertId();
                            lead_log($pdo, $lead_id, 'created', trim($_POST['remarks'] ?? '') ?: 'Lead created', null, $status, $followup, $admin_username);
                            $pdo->commit();
                            log_admin_activity($pdo, $admin_username, 'lead_created', "Lead #{$lead_id} (" . htmlspecialchars($wa) . ")");
                            $success_message = 'Lead added successfully.';
                        }
                    } catch (Exception $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $ex;
                    } finally {
                        if (isset($lockAcquired) && $lockAcquired) {
                            releaseLeadLock($pdo, $wa, $course_name);
                        }
                    }
                }
            } elseif ($action === 'bulk_import') {
                if (!isset($_FILES['lead_file']) || $_FILES['lead_file']['error'] !== UPLOAD_ERR_OK) {
                    $error_message = 'Please choose a CSV or Excel file to import.';
                } else {
                    $rows = parse_lead_file($_FILES['lead_file']['tmp_name'], $_FILES['lead_file']['name']);
                    if ($rows === null) {
                        $error_message = 'Could not read the file. Please upload a .csv file (Excel: Save As → CSV).';
                    } else {
                        $added = 0; 
                        $skipped_invalid = 0;
                        $skipped_db_dup = 0;
                        $skipped_file_dup = 0;
                        $skipped_details = [];
                        $processed_in_sheet = [];
                        
                        $assigned_default = is_super_admin() ? (trim($_POST['bulk_assigned_to'] ?? '') ?: '__ALL__') : '__ALL__';
                        
                        $pdo->beginTransaction();
                        try {
                            foreach ($rows as $idx => $r) {
                                $rowNum = $idx + 2; // Row 1 is header
                                $wa = clean_wa($r['whatsapp_number'] ?? '');
                                $course_name = trim($r['interested_course'] ?? '');
                                
                                if (strlen($wa) < 11) { 
                                    $skipped_invalid++; 
                                    $skipped_details[] = [
                                        'row'         => $rowNum,
                                        'phone'       => $wa ?: 'Missing',
                                        'course'      => $course_name ?: '-',
                                        'reason'      => 'Invalid or missing phone number',
                                        'existing_id' => '-'
                                    ];
                                    continue; 
                                }
                                
                                $normPhone = normalizeLeadPhone($wa);
                                $normCourse = normalizeLeadCourse($course_name);
                                $sheetKey = $normPhone . '||' . $normCourse;
                                
                                // Check duplicate in sheet
                                if (isset($processed_in_sheet[$sheetKey])) {
                                    $skipped_file_dup++;
                                    $skipped_details[] = [
                                        'row'         => $rowNum,
                                        'phone'       => $wa,
                                        'course'      => $course_name ?: '-',
                                        'reason'      => 'Duplicate within import file',
                                        'existing_id' => '-'
                                    ];
                                    continue;
                                }
                                
                                // Acquire named lock for database-level check
                                acquireLeadLock($pdo, $wa, $course_name);
                                try {
                                    $dupRes = checkLeadDuplicate($pdo, $wa, $course_name, null, true);
                                    if ($dupRes['count'] > 0) {
                                        $existingLead = $dupRes['matches'][0];
                                        $skipped_db_dup++;
                                        $skipped_details[] = [
                                            'row'         => $rowNum,
                                            'phone'       => $wa,
                                            'course'      => $course_name ?: '-',
                                            'reason'      => 'Already exists in database',
                                            'existing_id' => '#' . $existingLead['id']
                                        ];
                                        continue;
                                    }
                                    
                                    // Mark as processed in this session
                                    $processed_in_sheet[$sheetKey] = true;
                                    
                                    $yr = in_array($r['year_of_study'] ?? '', $YEARS, true) ? $r['year_of_study'] : null;
                                    $fy = in_array(strtolower($r['is_fyugp'] ?? ''), ['yes', 'no'], true) ? strtolower($r['is_fyugp']) : null;
                                    $fu = (!empty($r['next_followup_date']) && strtotime($r['next_followup_date'])) ? date('Y-m-d', strtotime($r['next_followup_date'])) : date('Y-m-d', strtotime('+2 days'));
                                    
                                    $stmt = $pdo->prepare("
                                        INSERT INTO leads (whatsapp_number, name, interested_course, last_institute, last_course,
                                            is_fyugp, year_of_study, status, next_followup_date, assigned_to, source, created_by, created_at, last_activity_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, 'import', ?, NOW(), NOW())
                                    ");
                                    $stmt->execute([
                                        $normPhone, trim($r['name'] ?? ''), $course_name,
                                        trim($r['last_institute'] ?? ''), trim($r['last_course'] ?? ''),
                                        $fy, $yr, $fu, $assigned_default, $admin_username
                                    ]);
                                    $lead_id = (int)$pdo->lastInsertId();
                                    lead_log($pdo, $lead_id, 'created', trim($r['remarks'] ?? '') ?: 'Imported from file', null, 'new', $fu, $admin_username);
                                    $added++;
                                } finally {
                                    releaseLeadLock($pdo, $wa, $course_name);
                                }
                            }
                            $pdo->commit();
                        } catch (Exception $ex) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw $ex;
                        }
                        
                        log_admin_activity($pdo, $admin_username, 'leads_imported', "Bulk import: {$added} added, {$skipped_invalid} invalid, {$skipped_db_dup} db duplicates, {$skipped_file_dup} file duplicates");
                        
                        $import_summary = [
                            'added'            => $added,
                            'already_existing' => $skipped_db_dup,
                            'file_dup'         => $skipped_file_dup,
                            'invalid_phone'    => $skipped_invalid,
                            'skipped_details'  => $skipped_details
                        ];
                    }
                }
            } elseif ($action === 'bulk_update_followups') {
                $lead_ids = array_unique(array_filter(array_map('intval', $_POST['lead_ids'] ?? [])));
                if (empty($lead_ids)) {
                    $error_message = 'No leads selected for bulk update.';
                } else {
                    $date_action     = trim($_POST['bulk_date_action'] ?? 'keep');
                    $target_date     = ($date_action === 'set') ? trim($_POST['bulk_next_followup_date'] ?? '') : '';
                    $target_status   = trim($_POST['bulk_status'] ?? '');
                    $target_assigned = is_super_admin() ? trim($_POST['bulk_assigned_to'] ?? '') : '';

                    if ($date_action === 'keep' && $target_status === '' && $target_assigned === '') {
                        $error_message = 'Please specify at least one field to update (Follow-up Date, Status, or Assigned To).';
                    } elseif ($date_action === 'set' && (empty($target_date) || !strtotime($target_date))) {
                        $error_message = 'Please provide a valid next follow-up date.';
                    } elseif ($target_status !== '' && !isset($LEAD_STATUSES[$target_status])) {
                        $error_message = 'Invalid status selected.';
                    } elseif ($target_assigned !== '' && !in_array($target_assigned, array_merge(['__ALL__'], $assignable), true)) {
                        $error_message = 'Invalid admin assigned.';
                    } else {
                        $pdo->beginTransaction();
                        try {
                            $placeholders = implode(',', array_fill(0, count($lead_ids), '?'));
                            $stmt = $pdo->prepare("SELECT * FROM leads WHERE id IN ($placeholders) FOR UPDATE");
                            $stmt->execute($lead_ids);
                            $leads_by_id = [];
                            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                                $leads_by_id[(int)$row['id']] = $row;
                            }

                            if (count($leads_by_id) !== count($lead_ids)) {
                                $pdo->rollBack();
                                $error_message = 'One or more selected leads could not be found.';
                            } else {
                                $auth_error = false;
                                $auth_error_msg = '';
                                $validation_error = false;
                                $validation_error_msg = '';
                                $updated_count = 0;

                                foreach ($lead_ids as $lid) {
                                    $curr = $leads_by_id[$lid];
                                    if (!is_super_admin() && $curr['assigned_to'] !== $admin_username && $curr['assigned_to'] !== '__ALL__') {
                                        $auth_error = true;
                                        $auth_error_msg = "You do not have permission to update Lead #{$lid}.";
                                        break;
                                    }

                                    $effective_status = ($target_status !== '') ? $target_status : $curr['status'];
                                    $is_closed = in_array($effective_status, $CLOSED, true);

                                    if ($date_action === 'set') {
                                        $effective_followup = $target_date;
                                    } else {
                                        $effective_followup = $is_closed ? null : $curr['next_followup_date'];
                                    }

                                    if (!$is_closed && empty($effective_followup)) {
                                        $validation_error = true;
                                        $validation_error_msg = "Lead #{$lid} requires a next follow-up date because its status is active.";
                                        break;
                                    }

                                    $effective_assigned = (is_super_admin() && $target_assigned !== '') ? $target_assigned : $curr['assigned_to'];
                                    $status_changed   = ($effective_status !== $curr['status']);
                                    $followup_changed = ($effective_followup !== $curr['next_followup_date']);
                                    $assigned_changed = ($effective_assigned !== $curr['assigned_to']);

                                    if ($status_changed || $followup_changed || $assigned_changed) {
                                        $upd = $pdo->prepare("
                                            UPDATE leads
                                            SET status = ?, next_followup_date = ?, assigned_to = ?, updated_at = NOW(), last_activity_at = NOW()
                                            WHERE id = ?
                                        ");
                                        $upd->execute([$effective_status, $effective_followup ?: null, $effective_assigned, $lid]);

                                        if ($status_changed) {
                                            lead_log($pdo, $lid, 'status_change', 'Bulk update: Status changed to ' . $LEAD_STATUSES[$effective_status][0], $curr['status'], $effective_status, $effective_followup, $admin_username);
                                        }
                                        if ($followup_changed && !$status_changed) {
                                            lead_log($pdo, $lid, 'followup', 'Bulk update: Next follow-up date set to ' . date('d M Y', strtotime($effective_followup)), null, null, $effective_followup, $admin_username);
                                        }
                                        if ($assigned_changed) {
                                            $assign_label = ($effective_assigned === '__ALL__') ? 'Unassigned (Visible to all admins)' : $effective_assigned;
                                            lead_log($pdo, $lid, 'reassigned', 'Bulk update: Reassigned to ' . $assign_label, null, null, null, $admin_username);
                                        }
                                        $updated_count++;
                                    }
                                }

                                if ($auth_error) {
                                    $pdo->rollBack();
                                    $error_message = $auth_error_msg ?: 'You are not authorized to update one or more of the selected leads.';
                                } elseif ($validation_error) {
                                    $pdo->rollBack();
                                    $error_message = $validation_error_msg ?: 'Validation error on selected leads.';
                                } else {
                                    $pdo->commit();
                                    log_admin_activity($pdo, $admin_username, 'lead_bulk_updated', "Bulk updated {$updated_count} lead(s) in Follow-ups Needed");
                                    $success_message = "{$updated_count} lead(s) successfully updated.";
                                }
                            }
                        } catch (Exception $ex) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw $ex;
                        }
                    }
                }
            } elseif ($action === 'mark_converted') {
                $lead_id = (int)($_POST['lead_id'] ?? 0);
                $student_user_id = trim($_POST['student_user_id'] ?? '');
                
                $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ? FOR UPDATE");
                $stmt->execute([$lead_id]);
                $lead = $stmt->fetch();
                
                if (!$lead) {
                    $error_message = 'Lead not found.';
                } elseif ($lead['status'] === 'converted') {
                    $error_message = 'This lead is already converted.';
                } else {
                    $pdo->beginTransaction();
                    $lockAcquired = acquireLeadLock($pdo, $lead['whatsapp_number'], $lead['interested_course']);
                    try {
                        $normPhone = normalizeLeadPhone($lead['whatsapp_number']);
                        $normCourse = normalizeLeadCourse($lead['interested_course']);
                        
                        // Query matching student/admission records
                        $stmtStud = $pdo->prepare("
                            SELECT * FROM users 
                            WHERE user_id = ?
                              AND status = 'approved'
                            FOR UPDATE
                        ");
                        $stmtStud->execute([$student_user_id]);
                        $student = $stmtStud->fetch();
                        
                        if ($student) {
                            $studPhone = normalizeLeadPhone($student['whatsapp_country_code'] . $student['whatsapp_number']);
                            
                            if ($studPhone === $normPhone) {
                                $success = convertLeadFromApprovedAdmission($pdo, $lead['id'], $student['user_id'], $admin_username, $admin_username);
                                if ($success) {
                                    $pdo->commit();
                                    $success_message = "Lead #{$lead['id']} successfully marked as converted for Student #{$student['user_id']}.";
                                } else {
                                    $pdo->rollBack();
                                    $error_message = "Failed to convert lead.";
                                }
                            } else {
                                $pdo->rollBack();
                                $error_message = "Reconciliation error: Contact numbers do not match.";
                            }
                        } else {
                            $pdo->rollBack();
                            $error_message = "No approved admission found for Student ID {$student_user_id}.";
                        }
                    } catch (Exception $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $ex;
                    } finally {
                        releaseLeadLock($pdo, $lead['whatsapp_number'], $lead['interested_course']);
                    }
                }
            } elseif ($action === 'change_converted_by') {
                if (!is_super_admin()) {
                    $error_message = 'Only the Super Admin can change conversion attribution.';
                } else {
                    $lead_id = (int)($_POST['lead_id'] ?? 0);
                    $new_converted_by = trim($_POST['converted_by'] ?? '');

                    $stmtLead = $pdo->prepare("SELECT id, status, converted_by, whatsapp_number, name FROM leads WHERE id = ?");
                    $stmtLead->execute([$lead_id]);
                    $targetLead = $stmtLead->fetch(PDO::FETCH_ASSOC);

                    if (!$targetLead) {
                        $error_message = 'Lead not found.';
                    } elseif ($targetLead['status'] !== 'converted') {
                        $error_message = 'Attribution can only be changed for converted leads.';
                    } elseif ($targetLead['converted_by'] === 'alumni_referral') {
                        $error_message = 'Alumni Referral attribution is permanently locked and cannot be changed.';
                    } else {
                        try {
                            $resolved = resolveLeadConversionAttribution($pdo, false, $new_converted_by, false);
                            if ($resolved === 'alumni_referral') {
                                throw new InvalidArgumentException("Cannot manually set Alumni Referral.");
                            }

                            $old_disp = ($targetLead['converted_by'] === 'auto_converted') ? 'Auto Converted' : ($targetLead['converted_by'] ?: 'None');
                            $new_disp = ($resolved === 'auto_converted') ? 'Auto Converted' : $resolved;

                            $adminMobile = get_admin_mobile($pdo, $admin_username);
                            $mobileSuffix = ($adminMobile && $adminMobile !== 'N/A') ? " (Mobile: {$adminMobile})" : "";
                            $remark = "Converted By changed from {$old_disp} to {$new_disp} by Super Admin {$admin_username}{$mobileSuffix}";

                            $pdo->prepare("UPDATE leads SET converted_by = ?, updated_at = NOW(), last_activity_at = NOW() WHERE id = ?")->execute([$resolved, $lead_id]);

                            lead_log($pdo, $lead_id, 'converted_by_change', $remark, null, null, null, $admin_username);
                            log_admin_activity($pdo, $admin_username, 'converted_by_changed', "Lead #{$lead_id} ({$targetLead['whatsapp_number']}): Converted By changed from {$old_disp} to {$new_disp}{$mobileSuffix}");

                            $success_message = "Conversion attribution for Lead #{$lead_id} updated to {$new_disp}.";
                        } catch (Exception $e) {
                            $error_message = 'Failed to update attribution: ' . $e->getMessage();
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Lead action: ' . $e->getMessage());
            $error_message = 'Database error while saving the lead.';
        }
    }
}

/** Parse an uploaded CSV (Excel saved as CSV). Returns array of assoc rows or null.
    Recognised headers (case/space-insensitive): whatsapp/whatsapp_number/phone,
    name, interested_course/course, last_institute/institute, last_course,
    is_fyugp/fyugp, year_of_study/year, next_followup_date/followup, remarks. */
function parse_lead_file($tmp, $orig) {
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'], true)) {
        // .xlsx is a zip; without a spreadsheet library we ask for CSV.
        if (in_array($ext, ['xlsx', 'xls'], true)) return null;
    }
    if (($h = fopen($tmp, 'r')) === false) return null;
    $headers = fgetcsv($h);
    if (!$headers) { fclose($h); return null; }
    $norm = function ($s) { return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$s))); };
    $alias = [
        'whatsapp' => 'whatsapp_number', 'whatsappnumber' => 'whatsapp_number', 'phone' => 'whatsapp_number', 'mobile' => 'whatsapp_number', 'number' => 'whatsapp_number',
        'name' => 'name', 'fullname' => 'name',
        'interestedcourse' => 'interested_course', 'course' => 'interested_course', 'peppcourse' => 'interested_course',
        'lastinstitute' => 'last_institute', 'institute' => 'last_institute', 'college' => 'last_institute', 'school' => 'last_institute',
        'lastcourse' => 'last_course', 'studiedcourse' => 'last_course',
        'isfyugp' => 'is_fyugp', 'fyugp' => 'is_fyugp',
        'yearofstudy' => 'year_of_study', 'year' => 'year_of_study',
        'nextfollowupdate' => 'next_followup_date', 'followup' => 'next_followup_date', 'followupdate' => 'next_followup_date', 'nextfollowup' => 'next_followup_date',
        'remarks' => 'remarks', 'remark' => 'remarks', 'note' => 'remarks', 'notes' => 'remarks',
    ];
    $cols = [];
    foreach ($headers as $i => $hdr) {
        $key = $alias[$norm($hdr)] ?? null;
        if ($key) $cols[$i] = $key;
    }
    if (!in_array('whatsapp_number', $cols, true)) { fclose($h); return null; }
    $rows = [];
    while (($line = fgetcsv($h)) !== false) {
        if (count(array_filter($line, function ($v) { return trim((string)$v) !== ''; })) === 0) continue;
        $row = [];
        foreach ($cols as $i => $key) $row[$key] = $line[$i] ?? '';
        $rows[] = $row;
    }
    fclose($h);
    return $rows;
}

/* ── Filters ────────────────────────────────────────────────────── */
$f_status           = trim($_GET['status'] ?? '');
$f_assigned         = trim($_GET['assigned'] ?? '');
$f_last_remarked_by = trim($_GET['last_remarked_by'] ?? '');
$f_course           = trim($_GET['course'] ?? '');
$f_due              = trim($_GET['due'] ?? '');           // today | overdue | upcoming
$f_joined           = trim($_GET['joined'] ?? '');
$f_q                = trim($_GET['q'] ?? '');

// Follow-ups Needed controls: Display Limit & Date Range Filter
$raw_followup_limit = (int)($_GET['followup_limit'] ?? 50);
$followup_limit     = in_array($raw_followup_limit, [50, 100, 500], true) ? $raw_followup_limit : 50;

$raw_fn_from = trim($_GET['followup_from'] ?? '');
$raw_fn_to   = trim($_GET['followup_to'] ?? '');

$is_valid_ymd = function ($d) {
    if (!is_string($d) || trim($d) === '') return false;
    $d = trim($d);
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
};

$has_fn_from = $is_valid_ymd($raw_fn_from);
$has_fn_to   = $is_valid_ymd($raw_fn_to);

$followup_date_error = '';
if ($raw_fn_from !== '' && !$has_fn_from) {
    $followup_date_error = 'Invalid From date format. Please use YYYY-MM-DD.';
} elseif ($raw_fn_to !== '' && !$has_fn_to) {
    $followup_date_error = 'Invalid To date format. Please use YYYY-MM-DD.';
} elseif ($has_fn_from && $has_fn_to && $raw_fn_from > $raw_fn_to) {
    $followup_date_error = 'From date cannot be later than To date.';
}

if ($followup_date_error !== '') {
    $error_message = $error_message ?: $followup_date_error;
}

// Follow-ups Needed: Search Remark & Multi-Filters
$f_fn_remark     = trim($_GET['followup_remark'] ?? '');
$raw_fn_courses  = $_GET['followup_courses'] ?? [];
$f_fn_courses    = is_array($raw_fn_courses) ? array_values(array_unique(array_filter(array_map('trim', $raw_fn_courses)))) : [];

$raw_fn_statuses = $_GET['followup_statuses'] ?? [];
$f_fn_statuses   = is_array($raw_fn_statuses) ? array_values(array_unique(array_filter(array_map('trim', $raw_fn_statuses)))) : [];
$f_fn_statuses   = array_values(array_filter($f_fn_statuses, function($st) use ($LEAD_STATUSES) {
    return isset($LEAD_STATUSES[$st]);
}));

$raw_fn_assigned = $_GET['followup_assigned'] ?? [];
$f_fn_assigned   = is_array($raw_fn_assigned) ? array_values(array_unique(array_filter(array_map('trim', $raw_fn_assigned)))) : [];

// Tab Navigation: 'followups' (default) or 'leads'
$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'leads') ? 'leads' : 'followups';

// Leads Display Limit (50, 100, 500; default 50)
$raw_leads_limit = (int)($_GET['leads_limit'] ?? 50);
$leads_limit     = in_array($raw_leads_limit, [50, 100, 500], true) ? $raw_leads_limit : 50;

// Converted By Multi-Select Filter (Leads Tab)
$raw_converted_by = $_GET['converted_by'] ?? [];
$f_converted_by   = is_array($raw_converted_by) ? array_values(array_unique(array_filter(array_map('trim', $raw_converted_by)))) : [];

$where = ['1=1']; $params = [];
// Non-super admins see leads assigned to them OR to all admins
if (!is_super_admin()) { $where[] = "(l.assigned_to = ? OR l.assigned_to = '__ALL__')"; $params[] = $admin_username; }
if (isset($LEAD_STATUSES[$f_status])) { $where[] = "l.status = ?"; $params[] = $f_status; }
if ($f_assigned !== '' && is_super_admin()) { $where[] = "l.assigned_to = ?"; $params[] = $f_assigned; }
if (!empty($f_converted_by)) {
    $cb_placeholders = implode(',', array_fill(0, count($f_converted_by), '?'));
    $where[] = "l.converted_by IN ($cb_placeholders)";
    foreach ($f_converted_by as $cb) {
        $params[] = $cb;
    }
}
if ($f_last_remarked_by !== '') {
    $where[] = "(
        SELECT la.performed_by
        FROM lead_activity la
        WHERE la.lead_id = l.id
          AND la.remark IS NOT NULL
          AND TRIM(la.remark) <> ''
          AND la.activity_type NOT IN ('details_change', 'reassigned', 'converted_by_change')
          AND TRIM(la.remark) NOT IN ('Lead created', 'Imported from file', 'Follow-up done', 'Marked as converted')
          AND la.remark NOT LIKE 'Converted - linked to student%'
          AND la.remark NOT LIKE 'Lead marked converted via%'
          AND la.remark NOT LIKE 'Lead converted (%'
          AND la.remark NOT LIKE 'Reassigned to %'
          AND la.remark NOT LIKE 'WhatsApp number updated:%'
          AND la.remark NOT LIKE 'WhatsApp Marketing:%'
          AND la.remark NOT LIKE 'Bulk update:%'
          AND la.remark NOT LIKE 'Converted By changed from%'
        ORDER BY la.performed_at DESC, la.id DESC
        LIMIT 1
    ) = ?";
    $params[] = $f_last_remarked_by;
}
if ($f_course !== '') { $where[] = "l.interested_course = ?"; $params[] = $f_course; }
if ($f_due === 'today')    { $where[] = "l.next_followup_date = CURDATE() AND l.status NOT IN ('converted','rejected','not_interested')"; }
if ($f_due === 'overdue')  { $where[] = "l.next_followup_date < CURDATE() AND l.status NOT IN ('converted','rejected','not_interested')"; }
if ($f_due === 'upcoming') { $where[] = "l.next_followup_date > CURDATE() AND l.status NOT IN ('converted','rejected','not_interested')"; }
if ($f_joined === 'yes') {
    $where[] = "EXISTS (
        SELECT 1 FROM users u
        WHERE u.status = 'approved'
          AND (
              REPLACE(REPLACE(CONCAT(u.whatsapp_country_code, u.whatsapp_number), '+', ''), ' ', '') = l.whatsapp_number
              OR u.whatsapp_number = l.whatsapp_number
              OR (LENGTH(l.whatsapp_number) >= 10 AND u.whatsapp_number = RIGHT(l.whatsapp_number, 10))
              OR (LENGTH(l.whatsapp_number) >= 10 AND CONCAT(REPLACE(u.whatsapp_country_code, '+', ''), u.whatsapp_number) = RIGHT(l.whatsapp_number, 10))
          )
    )";
} elseif ($f_joined === 'not_converted') {
    $where[] = "l.status <> 'converted' AND EXISTS (
        SELECT 1 FROM users u
        WHERE u.status = 'approved'
          AND (
              REPLACE(REPLACE(CONCAT(u.whatsapp_country_code, u.whatsapp_number), '+', ''), ' ', '') = l.whatsapp_number
              OR u.whatsapp_number = l.whatsapp_number
              OR (LENGTH(l.whatsapp_number) >= 10 AND u.whatsapp_number = RIGHT(l.whatsapp_number, 10))
              OR (LENGTH(l.whatsapp_number) >= 10 AND CONCAT(REPLACE(u.whatsapp_country_code, '+', ''), u.whatsapp_number) = RIGHT(l.whatsapp_number, 10))
          )
    )";
} elseif ($f_joined === 'converted') {
    $where[] = "l.status = 'converted' AND EXISTS (
        SELECT 1 FROM users u
        WHERE u.status = 'approved'
          AND (
              REPLACE(REPLACE(CONCAT(u.whatsapp_country_code, u.whatsapp_number), '+', ''), ' ', '') = l.whatsapp_number
              OR u.whatsapp_number = l.whatsapp_number
              OR (LENGTH(l.whatsapp_number) >= 10 AND u.whatsapp_number = RIGHT(l.whatsapp_number, 10))
              OR (LENGTH(l.whatsapp_number) >= 10 AND CONCAT(REPLACE(u.whatsapp_country_code, '+', ''), u.whatsapp_number) = RIGHT(l.whatsapp_number, 10))
          )
    )";
}
if ($f_q !== '') {
    $where[] = "(l.whatsapp_number LIKE ? OR l.name LIKE ? OR l.interested_course LIKE ? OR l.last_institute LIKE ?)";
    $like = "%{$f_q}%"; array_push($params, $like, $like, $like, $like);
}
$where_sql = implode(' AND ', $where);

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = $leads_limit;
$total = 0; $leads = [];
$stats = ['total' => 0, 'due_today' => 0, 'overdue' => 0, 'converted' => 0];
$today_leads = [];
$today_leads_total = 0;
$admin_list = []; $course_list = [];
$converted_breakdown = [];

try {
    if (is_super_admin()) {
        $admin_list = $pdo->query("SELECT DISTINCT assigned_to FROM leads WHERE assigned_to IS NOT NULL AND assigned_to <> '' ORDER BY assigned_to")->fetchAll(PDO::FETCH_COLUMN);
    }
    $course_list = $pdo->query("SELECT DISTINCT interested_course FROM leads WHERE interested_course IS NOT NULL AND interested_course <> '' ORDER BY interested_course")->fetchAll(PDO::FETCH_COLUMN);

    // Stats (respect non-super scoping)
    $scope = is_super_admin() ? '1=1' : '(assigned_to = ' . $pdo->quote($admin_username) . " OR assigned_to = '__ALL__')";
    $stats['total']     = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE $scope")->fetchColumn();
    $stats['due_today'] = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE $scope AND next_followup_date = CURDATE() AND status NOT IN ('converted','rejected','not_interested')")->fetchColumn();
    $stats['overdue']   = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE $scope AND next_followup_date < CURDATE() AND status NOT IN ('converted','rejected','not_interested')")->fetchColumn();
    $stats['converted'] = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE $scope AND status = 'converted'")->fetchColumn();

    // Converted Leads Breakdown (respects scoping, sum strictly equals $stats['converted'], zero duplicates)
    try {
        $stmt_cb = $pdo->query("
            SELECT COALESCE(NULLIF(TRIM(converted_by), ''), 'auto_converted') as conv_key, COUNT(*) as qty
            FROM leads
            WHERE $scope AND status = 'converted'
            GROUP BY conv_key
            ORDER BY qty DESC
        ");
        $converted_breakdown = $stmt_cb->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $converted_breakdown = [];
    }

    // Follow-ups Needed query (supports display limit 50/100/500, date range filter, search remark, and multi-filters)
    $today_leads = [];
    $today_leads_total = 0;
    if ($followup_date_error === '') {
        $fn_where = [
            $scope,
            "next_followup_date IS NOT NULL"
        ];
        $fn_params = [];

        // Status filter: use selected statuses if specified, otherwise default active leads
        if (!empty($f_fn_statuses)) {
            $st_placeholders = implode(',', array_fill(0, count($f_fn_statuses), '?'));
            $fn_where[] = "status IN ($st_placeholders)";
            foreach ($f_fn_statuses as $st) {
                $fn_params[] = $st;
            }
        } else {
            $fn_where[] = "status NOT IN ('converted','rejected','not_interested')";
        }

        // Date Range
        if ($has_fn_from && $has_fn_to) {
            $fn_where[] = "next_followup_date >= ? AND next_followup_date <= ?";
            $fn_params[] = $raw_fn_from;
            $fn_params[] = $raw_fn_to;
        } elseif ($has_fn_from) {
            $fn_where[] = "next_followup_date >= ?";
            $fn_params[] = $raw_fn_from;
        } elseif ($has_fn_to) {
            $fn_where[] = "next_followup_date <= ?";
            $fn_params[] = $raw_fn_to;
        } else {
            // Default when no custom date filter is active: today or overdue
            $fn_where[] = "next_followup_date <= CURDATE()";
        }

        // Interested In (courses) multi-filter
        if (!empty($f_fn_courses)) {
            $c_placeholders = implode(',', array_fill(0, count($f_fn_courses), '?'));
            $fn_where[] = "interested_course IN ($c_placeholders)";
            foreach ($f_fn_courses as $c) {
                $fn_params[] = $c;
            }
        }

        // Assigned To multi-filter (Super Admin only)
        if (is_super_admin() && !empty($f_fn_assigned)) {
            $a_placeholders = implode(',', array_fill(0, count($f_fn_assigned), '?'));
            $fn_where[] = "assigned_to IN ($a_placeholders)";
            foreach ($f_fn_assigned as $a) {
                $fn_params[] = $a;
            }
        }

        // Search Remark Keyword (strict explicit user remarks only via EXISTS subquery)
        if ($f_fn_remark !== '') {
            $fn_where[] = "EXISTS (
                SELECT 1 FROM lead_activity la
                WHERE la.lead_id = leads.id
                  AND la.remark IS NOT NULL
                  AND TRIM(la.remark) <> ''
                  AND la.activity_type NOT IN ('details_change', 'reassigned')
                  AND TRIM(la.remark) NOT IN ('Lead created', 'Imported from file', 'Follow-up done', 'Marked as converted')
                  AND la.remark NOT LIKE 'Converted - linked to student%'
                  AND la.remark NOT LIKE 'Lead marked converted via%'
                  AND la.remark NOT LIKE 'Reassigned to %'
                  AND la.remark NOT LIKE 'WhatsApp number updated:%'
                  AND la.remark NOT LIKE 'WhatsApp Marketing:%'
                  AND la.remark NOT LIKE 'Bulk update:%'
                  AND LOWER(la.remark) LIKE LOWER(?)
            )";
            $fn_params[] = "%{$f_fn_remark}%";
        }

        $fn_where_sql = implode(' AND ', $fn_where);

        // Matching count (logically separate from displayed count)
        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE $fn_where_sql");
        $stmt_count->execute($fn_params);
        $today_leads_total = (int)$stmt_count->fetchColumn();

        // Displayed rows using SQL LIMIT
        $stmt = $pdo->prepare("SELECT * FROM leads WHERE $fn_where_sql ORDER BY next_followup_date ASC, last_activity_at ASC LIMIT {$followup_limit}");
        $stmt->execute($fn_params);
        $today_leads = $stmt->fetchAll();
    }

    // Filtered list
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $where_sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $offset = ($page - 1) * $per_page;
    $stmt = $pdo->prepare("SELECT l.* FROM leads l WHERE $where_sql ORDER BY
        CASE WHEN l.next_followup_date <= CURDATE() AND l.status NOT IN ('converted','rejected','not_interested') THEN 0 ELSE 1 END,
        l.next_followup_date ASC, l.created_at DESC LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $leads = $stmt->fetchAll();

    // Preload matching approved/pending admission records in batched database queries to avoid N+1 query loops
    $preloadedAdmissions = [];
    $phonesToSearch = [];
    foreach (array_merge($leads, $today_leads) as $l) {
        $cleaned = preg_replace('/\D/', '', $l['whatsapp_number']);
        if (strlen($cleaned) >= 10) {
            $phonesToSearch[] = $cleaned;
            $phonesToSearch[] = substr($cleaned, -10);
        }
    }
    if (!empty($phonesToSearch)) {
        $phonesToSearch = array_values(array_unique($phonesToSearch));
        $chunks = array_chunk($phonesToSearch, 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $idCol = ($driver === 'sqlite') ? "user_id AS id" : "id";
            $stmtPreload = $pdo->prepare("
                SELECT {$idCol}, user_id, name, whatsapp_number, whatsapp_country_code, pepp_course, status, approval_date
                FROM users
                WHERE (whatsapp_number IN ({$placeholders}) OR CONCAT(whatsapp_country_code, whatsapp_number) IN ({$placeholders}))
            ");
            $stmtPreload->execute(array_merge($chunk, $chunk));
            $admissions = $stmtPreload->fetchAll(PDO::FETCH_ASSOC);

            foreach ($admissions as $adm) {
                $normPhone = normalizeLeadPhone($adm['whatsapp_country_code'] . $adm['whatsapp_number']);
                $preloadedAdmissions[$normPhone][] = $adm;
            }
        }
    }

    $remark_authors = [];
    try {
        $remark_authors = $pdo->query("
            SELECT DISTINCT la.performed_by, a.full_name
            FROM lead_activity la
            LEFT JOIN admins a ON a.username = la.performed_by
            WHERE la.performed_by IS NOT NULL
              AND la.performed_by <> ''
              AND la.remark IS NOT NULL
              AND TRIM(la.remark) <> ''
              AND la.activity_type NOT IN ('details_change', 'reassigned')
              AND TRIM(la.remark) NOT IN ('Lead created', 'Imported from file', 'Follow-up done', 'Marked as converted')
              AND la.remark NOT LIKE 'Converted - linked to student%'
              AND la.remark NOT LIKE 'Lead marked converted via%'
              AND la.remark NOT LIKE 'Reassigned to %'
              AND la.remark NOT LIKE 'WhatsApp number updated:%'
              AND la.remark NOT LIKE 'WhatsApp Marketing:%'
              AND la.remark NOT LIKE 'Bulk update:%'
            ORDER BY la.performed_by ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
} catch (Exception $e) {
    error_log('Lead list: ' . $e->getMessage());
    $error_message = $error_message ?: 'Could not load leads.';
}

// Admins that leads can be assigned to (super admin only)
if (empty($assignable) && is_super_admin() && admins_table_exists($pdo)) {
    try { $assignable = $pdo->query("SELECT username FROM admins WHERE status = 'active' ORDER BY role = 'super_admin' DESC, username")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Exception $e) {}
}
// PEPP courses for the dropdown
$pepp_courses = [];
try { $pepp_courses = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

$total_pages = max(1, (int)ceil($total / $per_page));
function lqs($overrides = []) {
    $q = array_merge($_GET, $overrides);
    unset($q['logout']);
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($q[$k]);
        }
    }
    $qs = http_build_query($q);
    return $qs ? ('?' . $qs) : '?';
}
function wa_link($num, $text = '') {
    return 'https://wa.me/' . preg_replace('/\D/', '', $num) . ($text ? '?text=' . rawurlencode($text) : '');
}

$active_page = 'leads';
$page_title  = 'Lead Management';
$page_sub    = 'Track and convert prospective students';
include 'includes/admin_nav.php';
?>

<style>
.fn-multiselect-wrap {
    position: relative;
    display: inline-block;
}
.fn-multiselect-btn {
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    padding: 4px 10px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #1e293b;
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    cursor: pointer;
    white-space: nowrap;
    user-select: none;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.fn-multiselect-btn:hover {
    border-color: #94a3b8;
}
.fn-multiselect-btn.active {
    border-color: var(--accent);
    background: #f0fdf4;
    color: var(--accent-dark);
}
.fn-multiselect-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    z-index: 1000;
    min-width: 200px;
    max-width: 320px;
    max-height: 240px;
    overflow-y: auto;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
    padding: 6px 0;
}
.fn-multiselect-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    font-size: 0.78rem;
    color: #334155;
    cursor: pointer;
    user-select: none;
    transition: background 0.12s ease;
    margin: 0;
}
.fn-multiselect-item:hover {
    background: #f1f5f9;
}
.fn-multiselect-item input[type="checkbox"] {
    margin: 0;
    cursor: pointer;
    accent-color: var(--accent);
}
.pepp-tabs-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 20px 0 16px 0;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 0;
    flex-wrap: wrap;
}
.pepp-tab-link {
    text-decoration: none;
    padding: 10px 18px;
    font-weight: 700;
    font-size: 0.92rem;
    border-bottom: 3px solid transparent;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    cursor: pointer;
    margin-bottom: -2px;
}
.pepp-tab-link:hover {
    color: var(--accent-dark);
}
.pepp-tab-link.active {
    border-bottom-color: var(--accent);
    color: var(--accent-dark);
}
</style>

<?php if (!empty($import_summary)): ?>
<div class="panel" style="border-left: 4px solid var(--accent); margin-bottom: 20px; background: #fff;">
    <div class="panel-body" style="padding: 18px 20px;">
        <div style="display:flex; align-items:flex-start; gap:14px;">
            <div style="width:40px; height:40px; border-radius:10px; background:var(--accent-soft); color:var(--accent-dark); display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">
                <i class="fas fa-file-import"></i>
            </div>
            <div style="flex:1;">
                <h3 style="margin:0 0 6px; font-size:1.05rem; font-weight:700; color:#1e293b;">Import Completed</h3>
                <p style="margin:0 0 14px; font-size:0.875rem; color:#475569;">
                    <?php if ($import_summary['added'] > 0): ?>
                        <strong><?php echo (int)$import_summary['added']; ?></strong> <?php echo $import_summary['added'] === 1 ? 'lead was' : 'leads were'; ?> successfully added.
                    <?php else: ?>
                        No new leads were added. All rows were skipped due to duplicates or invalid data.
                    <?php endif; ?>
                </p>

                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-bottom:14px;">
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                        <div style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Successfully Imported</div>
                        <div style="font-size:1.25rem; font-weight:800; color:#059669;"><?php echo number_format($import_summary['added']); ?></div>
                    </div>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                        <div style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Already Existing</div>
                        <div style="font-size:1.25rem; font-weight:800; color:#d97706;"><?php echo number_format($import_summary['already_existing']); ?></div>
                    </div>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                        <div style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Duplicate in File</div>
                        <div style="font-size:1.25rem; font-weight:800; color:#475569;"><?php echo number_format($import_summary['file_dup']); ?></div>
                    </div>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                        <div style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase;">Invalid / Missing Phone</div>
                        <div style="font-size:1.25rem; font-weight:800; color:#dc2626;"><?php echo number_format($import_summary['invalid_phone']); ?></div>
                    </div>
                </div>

                <?php if (!empty($import_summary['skipped_details'])): ?>
                <details style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px;">
                    <summary style="font-size:0.82rem; font-weight:600; color:var(--accent-dark); cursor:pointer;">
                        View details of skipped rows (<?php echo count($import_summary['skipped_details']); ?>)
                    </summary>
                    <div style="margin-top:10px; max-height:220px; overflow-y:auto; border-top:1px solid #e2e8f0; padding-top:8px;">
                        <table class="data-table" style="font-size:0.78rem; margin:0;">
                            <thead>
                                <tr>
                                    <th>Row</th>
                                    <th>Phone</th>
                                    <th>Course</th>
                                    <th>Reason</th>
                                    <th>Existing Lead</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($import_summary['skipped_details'] as $sd): ?>
                                <tr>
                                    <td>Row <?php echo (int)$sd['row']; ?></td>
                                    <td><code><?php echo e($sd['phone']); ?></code></td>
                                    <td><?php echo e($sd['course']); ?></td>
                                    <td><span style="color:#b91c1c; font-weight:600;"><?php echo e($sd['reason']); ?></span></td>
                                    <td><?php echo e($sd['existing_id']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<!-- ── STATS ── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Total Leads</span><span class="stat-icon violet"><i class="fas fa-user-tag"></i></span></div>
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-hint"><?php echo is_super_admin() ? 'All leads' : 'Assigned to you'; ?></div>
    </div>
    <a href="<?php echo e(lqs(['tab' => 'leads', 'due' => 'today', 'status' => '', 'page' => 1])); ?>" class="stat-card" style="text-decoration:none;">
        <div class="stat-top"><span class="stat-label">Due Today</span><span class="stat-icon amber"><i class="fas fa-calendar-day"></i></span></div>
        <div class="stat-value"><?php echo number_format($stats['due_today']); ?></div>
        <div class="stat-hint">Follow-ups for <?php echo date('d M Y'); ?></div>
    </a>
    <a href="<?php echo e(lqs(['tab' => 'leads', 'due' => 'overdue', 'status' => '', 'page' => 1])); ?>" class="stat-card" style="text-decoration:none;">
        <div class="stat-top"><span class="stat-label">Overdue</span><span class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></span></div>
        <div class="stat-value"><?php echo number_format($stats['overdue']); ?></div>
        <div class="stat-hint">Past follow-up date</div>
    </a>
    <div class="stat-card" style="cursor:pointer;" onclick="openConvertedBreakdownModal()" title="Click to view Converted Leads Breakdown">
        <div class="stat-top"><span class="stat-label">Converted</span><span class="stat-icon green"><i class="fas fa-circle-check"></i></span></div>
        <div class="stat-value"><?php echo number_format($stats['converted']); ?></div>
        <div class="stat-hint">Became students <i class="fas fa-chart-pie" style="font-size:0.75rem; margin-left:4px; opacity:0.7;"></i></div>
    </div>
</div>

<!-- ── TAB NAVIGATION ── -->
<div class="pepp-tabs-bar">
    <a href="<?php echo e(lqs(['tab' => 'followups', 'page' => 1])); ?>" class="pepp-tab-link <?php echo $active_tab === 'followups' ? 'active' : ''; ?>">
        <i class="fas fa-bell"></i> Follow-ups Needed
        <?php if ($stats['overdue'] > 0): ?>
            <span class="badge" style="background:#ef4444; color:#ffffff; font-size:0.75rem; font-weight:700; padding:2px 7px; border-radius:999px; margin-left:4px; box-shadow:0 1px 2px rgba(239,68,68,0.3);"><?php echo number_format($stats['overdue']); ?></span>
        <?php endif; ?>
    </a>
    <a href="<?php echo e(lqs(['tab' => 'leads', 'page' => 1])); ?>" class="pepp-tab-link <?php echo $active_tab === 'leads' ? 'active' : ''; ?>">
        <i class="fas fa-users"></i> Leads
        <span style="font-size:0.8rem; font-weight:600; opacity:0.85; margin-left:2px;">(<?php echo number_format($stats['total']); ?>)</span>
    </a>
    <div style="margin-left:auto; display:flex; align-items:center; gap:8px; padding-bottom:6px;">
        <a href="communication-campaigns.php?target=leads" class="btn btn-sm btn-success" style="border-radius:6px; font-weight:700;"><i class="fas fa-bullhorn"></i> Create WhatsApp Campaign</a>
        <button class="btn btn-sm btn-primary" onclick="openModal('add-lead-modal')"><i class="fas fa-plus"></i> Add Lead</button>
        <button class="btn btn-sm btn-outline" onclick="openModal('import-modal')"><i class="fas fa-file-import"></i> Bulk Import</button>
    </div>
</div>

<?php if ($active_tab === 'followups'): ?>
<!-- ── TODAY'S / OVERDUE FOLLOW-UPS ── -->
<div class="panel">
    <div class="panel-head" style="flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-bell"></i></span>
            <h2>Follow-ups Needed <?php
                if ($today_leads_total > 0) {
                    echo '(' . number_format($today_leads_total) . ')';
                    if (count($today_leads) < $today_leads_total) {
                        echo ' <span style="font-size:0.8rem; font-weight:500; color:var(--text-muted); opacity:0.85;">· Showing ' . count($today_leads) . '</span>';
                    }
                }
            ?></h2>
        </div>
    </div>

    <!-- Follow-ups Controls Bar: Multi-Filter + Search Remark + Date Range + Display Limit -->
    <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:10px 16px;">
        <form method="GET" action="lead-management.php" id="followups-filter-form" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin:0;">
            <input type="hidden" name="tab" value="followups">
            <?php
            $main_filter_keys = ['status', 'assigned', 'last_remarked_by', 'course', 'due', 'joined', 'q'];
            foreach ($main_filter_keys as $mfk) {
                if (isset($_GET[$mfk]) && trim((string)$_GET[$mfk]) !== '') {
                    echo '<input type="hidden" name="' . e($mfk) . '" value="' . e($_GET[$mfk]) . '">';
                }
            }
            ?>
            <div style="display:flex; align-items:center; flex-wrap:wrap; gap:8px;">
                <!-- Remark Search Keyword -->
                <div style="display:flex; align-items:center; gap:5px;">
                    <label for="fn-followup-remark" style="font-size:0.75rem; font-weight:600; color:#64748b;" title="Search user-entered remarks">
                        <i class="fas fa-search" style="color:var(--accent);"></i> Remark:
                    </label>
                    <input type="text" id="fn-followup-remark" name="followup_remark" value="<?php echo e($f_fn_remark); ?>" placeholder="Search remark..." style="padding:4px 8px; font-size:0.8rem; border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#1e293b; width:130px;">
                </div>

                <!-- Interested In (Courses) Multi-Select -->
                <?php if (!empty($course_list)): ?>
                <div class="fn-multiselect-wrap" data-placeholder="Courses">
                    <button type="button" class="fn-multiselect-btn<?php echo !empty($f_fn_courses) ? ' active' : ''; ?>" onclick="toggleFnDropdown('fn-courses-dropdown', event)">
                        <span class="fn-multiselect-label"><?php
                            $c_count = count($f_fn_courses);
                            echo $c_count > 0 ? ('Courses (' . $c_count . ')') : 'All Courses';
                        ?></span>
                        <i class="fas fa-chevron-down" style="font-size:0.65rem; margin-left:3px; opacity:0.6;"></i>
                    </button>
                    <div id="fn-courses-dropdown" class="fn-multiselect-dropdown" style="display:none;">
                        <?php foreach ($course_list as $c): ?>
                            <label class="fn-multiselect-item">
                                <input type="checkbox" name="followup_courses[]" value="<?php echo e($c); ?>" <?php echo in_array($c, $f_fn_courses, true) ? 'checked' : ''; ?>>
                                <span><?php echo e($c); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Status Multi-Select -->
                <div class="fn-multiselect-wrap" data-placeholder="Statuses">
                    <button type="button" class="fn-multiselect-btn<?php echo !empty($f_fn_statuses) ? ' active' : ''; ?>" onclick="toggleFnDropdown('fn-statuses-dropdown', event)">
                        <span class="fn-multiselect-label"><?php
                            $st_count = count($f_fn_statuses);
                            echo $st_count > 0 ? ('Status (' . $st_count . ')') : 'Active Statuses';
                        ?></span>
                        <i class="fas fa-chevron-down" style="font-size:0.65rem; margin-left:3px; opacity:0.6;"></i>
                    </button>
                    <div id="fn-statuses-dropdown" class="fn-multiselect-dropdown" style="display:none;">
                        <?php foreach ($LEAD_STATUSES as $k => $v): ?>
                            <label class="fn-multiselect-item">
                                <input type="checkbox" name="followup_statuses[]" value="<?php echo e($k); ?>" <?php echo in_array($k, $f_fn_statuses, true) ? 'checked' : ''; ?>>
                                <span><?php echo e($v[0]); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Assigned To Multi-Select (Super Admin only) -->
                <?php if (is_super_admin() && !empty($assignable)): ?>
                <div class="fn-multiselect-wrap" data-placeholder="Assigned">
                    <button type="button" class="fn-multiselect-btn<?php echo !empty($f_fn_assigned) ? ' active' : ''; ?>" onclick="toggleFnDropdown('fn-assigned-dropdown', event)">
                        <span class="fn-multiselect-label"><?php
                            $a_count = count($f_fn_assigned);
                            echo $a_count > 0 ? ('Assigned (' . $a_count . ')') : 'All Admins';
                        ?></span>
                        <i class="fas fa-chevron-down" style="font-size:0.65rem; margin-left:3px; opacity:0.6;"></i>
                    </button>
                    <div id="fn-assigned-dropdown" class="fn-multiselect-dropdown" style="display:none;">
                        <label class="fn-multiselect-item">
                            <input type="checkbox" name="followup_assigned[]" value="__ALL__" <?php echo in_array('__ALL__', $f_fn_assigned, true) ? 'checked' : ''; ?>>
                            <span>Unassigned (All Admins)</span>
                        </label>
                        <?php foreach ($assignable as $adm): ?>
                            <label class="fn-multiselect-item">
                                <input type="checkbox" name="followup_assigned[]" value="<?php echo e($adm); ?>" <?php echo in_array($adm, $f_fn_assigned, true) ? 'checked' : ''; ?>>
                                <span><?php echo e($adm); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Follow-up Date Filter -->
                <div style="display:flex; align-items:center; gap:5px;">
                    <span style="font-size:0.75rem; font-weight:600; color:#64748b; display:inline-flex; align-items:center; gap:4px;">
                        <i class="fas fa-calendar-alt" style="color:var(--amber-ink);"></i> Date:
                    </span>
                    <input type="date" id="fn-followup-from" name="followup_from" value="<?php echo e($raw_fn_from); ?>" style="padding:4px 6px; font-size:0.8rem; border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#1e293b;" title="From Date">
                    <span style="color:#94a3b8; font-size:0.8rem; font-weight:700;">&rarr;</span>
                    <input type="date" id="fn-followup-to" name="followup_to" value="<?php echo e($raw_fn_to); ?>" style="padding:4px 6px; font-size:0.8rem; border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#1e293b;" title="To Date">
                </div>

                <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 12px; font-size:0.8rem; font-weight:700; border-radius:6px;">Apply</button>
                <?php
                $has_active_fn_filters = ($raw_fn_from !== '' || $raw_fn_to !== '' || $f_fn_remark !== '' || !empty($f_fn_courses) || !empty($f_fn_statuses) || !empty($f_fn_assigned));
                if ($has_active_fn_filters): ?>
                    <a href="<?php echo e(lqs([
                        'followup_from'     => null,
                        'followup_to'       => null,
                        'followup_remark'   => null,
                        'followup_courses'  => null,
                        'followup_statuses' => null,
                        'followup_assigned' => null
                    ])); ?>" class="btn btn-sm btn-outline" style="padding:4px 10px; font-size:0.8rem; border-radius:6px; background:#fff;">Clear</a>
                <?php endif; ?>
            </div>

            <!-- Display Limit -->
            <div style="display:flex; align-items:center; gap:6px;">
                <label for="fn-followup-limit" style="font-size:0.82rem; font-weight:600; color:#475569;">Show:</label>
                <select id="fn-followup-limit" name="followup_limit" onchange="this.form.submit()" style="padding:4px 10px; font-size:0.8rem; border:1px solid #cbd5e1; border-radius:6px; background:#fff; font-weight:600; color:#1e293b; cursor:pointer;">
                    <option value="50" <?php echo $followup_limit === 50 ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $followup_limit === 100 ? 'selected' : ''; ?>>100</option>
                    <option value="500" <?php echo $followup_limit === 500 ? 'selected' : ''; ?>>500</option>
                </select>
            </div>
        </form>
    </div>

    <div class="panel-body flush table-wrap">
        <?php if (empty($today_leads)): ?>
            <div class="empty-state">
                <i class="fas fa-mug-hot"></i>
                <?php if ($has_active_fn_filters): ?>
                    <p>No follow-ups found matching the selected filters.</p>
                <?php else: ?>
                    <p>No follow-ups due today or overdue. You're all caught up!</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div id="followups-bulk-toolbar" style="display:none; align-items:center; justify-content:space-between; padding:8px 16px; background:#eff6ff; border-bottom:1px solid #bfdbfe;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span id="followups-selected-count" style="font-weight:700; font-size:0.85rem; color:#1e40af;">0 leads selected</span>
                <button type="button" class="btn btn-sm btn-outline" onclick="clearFollowupSelection()" style="padding:2px 10px; font-size:0.75rem; background:#fff;">Deselect All</button>
            </div>
            <div>
                <button type="button" class="btn btn-sm btn-primary" onclick="openBulkUpdateModal()" style="font-weight:700; font-size:0.8rem;"><i class="fas fa-pen-to-square"></i> Bulk Update</button>
            </div>
        </div>
        <table class="data-table">
            <thead><tr><th style="width:36px; text-align:center;"><input type="checkbox" id="select-all-followups" onclick="toggleSelectAllFollowups(this)" title="Select all follow-ups"></th><th>Lead</th><th>Interested In</th><th>Status</th><th>Follow-up</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($today_leads as $l): 
                $overdue = $l['next_followup_date'] < date('Y-m-d');
                $normPhone = normalizeLeadPhone($l['whatsapp_number']);
                $matches = $preloadedAdmissions[$normPhone] ?? [];
                $joinedCount = 0;
                $appliedCount = 0;
                $sameCourseApprovedStudent = null;
                $anyCourseApprovedStudent = null;
                $eligibleForManualConversion = false;
                $normLeadCourse = normalizeLeadCourse($l['interested_course']);

                foreach ($matches as $m) {
                    if ($m['status'] === 'approved') {
                        $joinedCount++;
                        if (normalizeLeadCourse($m['pepp_course']) === $normLeadCourse) {
                            $sameCourseApprovedStudent = $m;
                        }
                        if ($anyCourseApprovedStudent === null) {
                            $anyCourseApprovedStudent = $m;
                        }
                    } elseif ($m['status'] === 'pending') {
                        $appliedCount++;
                    }
                }
                $conversionStudent = $sameCourseApprovedStudent ?: $anyCourseApprovedStudent;
                if ($conversionStudent && $l['status'] !== 'converted') {
                    $eligibleForManualConversion = true;
                }
                
                $matchedDetails = [];
                foreach ($matches as $m) {
                    $matchedDetails[] = [
                        'course' => $m['pepp_course'],
                        'student_name' => $m['name'],
                        'status' => ucfirst($m['status']),
                        'date' => $m['approval_date'] ? date('d M Y', strtotime($m['approval_date'])) : 'N/A',
                        'student_id' => $m['user_id'],
                        'is_same_course' => (normalizeLeadCourse($m['pepp_course']) === normalizeLeadCourse($l['interested_course'])),
                        'lead_status' => $l['status']
                    ];
                }
                $detailsJson = htmlspecialchars(json_encode($matchedDetails));
            ?>
                <tr<?php echo $overdue ? ' style="background:#fff7f7;"' : ''; ?>>
                    <td style="text-align:center;">
                        <input type="checkbox" class="followup-row-cb" value="<?php echo (int)$l['id']; ?>" onchange="onFollowupSelectChange()">
                    </td>
                    <td>
                        <div class="cell-main"><?php echo e($l['name'] ?: 'Unknown'); ?></div>
                        <div class="cell-sub">
                            <?php echo format_credential($l['whatsapp_number'], 'phone', 'leads'); ?> · <?php echo (int)$l['followup_count']; ?> follow-up(s)
                            <?php if (!empty($matches)): ?>
                                <div style="margin-top:4px;">
                                    <button type="button" class="joined-courses-btn" data-details="<?php echo $detailsJson; ?>" onclick="showJoinedCoursesModal(this)" style="padding:1px 6px; border-radius:4px; font-size:0.62rem; line-height:1.2; font-weight:700; cursor:pointer; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46;">
                                        <?php if ($joinedCount > 0): ?>
                                            🟢 Joined: <?php echo $joinedCount; ?>
                                        <?php endif; ?>
                                        <?php if ($appliedCount > 0): ?>
                                            <?php echo $joinedCount > 0 ? ' | ' : ''; ?>🟡 Applied: <?php echo $appliedCount; ?>
                                        <?php endif; ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="cell-sub"><?php echo e($l['interested_course'] ?: '-'); ?></td>
                    <td><span class="badge <?php echo $LEAD_STATUSES[$l['status']][1]; ?>"><?php echo $LEAD_STATUSES[$l['status']][0]; ?></span></td>
                    <td><span class="badge <?php echo $overdue ? 'red' : 'amber'; ?>"><?php echo date('d M', strtotime($l['next_followup_date'])); ?><?php echo $overdue ? ' · overdue' : ' · today'; ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <?php if ($eligibleForManualConversion): ?>
                            <button type="button" class="btn btn-sm btn-success" 
                                    data-lead-id="<?php echo $l['id']; ?>" 
                                    data-lead-name="<?php echo htmlspecialchars($l['name']); ?>" 
                                    data-lead-course="<?php echo htmlspecialchars($l['interested_course']); ?>" 
                                    data-lead-phone="<?php echo htmlspecialchars($l['whatsapp_number']); ?>" 
                                    data-student-id="<?php echo htmlspecialchars($conversionStudent['user_id']); ?>" 
                                    data-student-name="<?php echo htmlspecialchars($conversionStudent['name']); ?>" 
                                    data-student-course="<?php echo htmlspecialchars($conversionStudent['pepp_course']); ?>" 
                                    data-student-date="<?php echo $conversionStudent['approval_date'] ? date('d M Y', strtotime($conversionStudent['approval_date'])) : 'N/A'; ?>" 
                                    onclick="confirmManualConversion(this)" 
                                    title="Mark as Converted" 
                                    style="border-radius:6px; padding:2px 8px; font-size:0.7rem; font-weight:700; margin-right:4px;">
                                <i class="fas fa-check-circle"></i> Mark Converted
                            </button>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline" href="tel:<?php echo preg_replace('/\D/', '', $l['whatsapp_number']); ?>" title="Call"><i class="fas fa-phone"></i></a>
                        <a class="btn btn-sm btn-whatsapp" href="<?php echo e(wa_link($l['whatsapp_number'])); ?>" target="_blank" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                        <a class="btn btn-sm btn-primary" href="lead-details.php?id=<?php echo (int)$l['id']; ?>"><i class="fas fa-pen"></i> Update</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ── FILTERS ── -->
<div class="panel">
    <div class="panel-body">
        <form method="GET" class="filter-bar">
            <input type="hidden" name="tab" value="leads">
            <?php if (isset($_GET['leads_limit'])): ?>
                <input type="hidden" name="leads_limit" value="<?php echo e($leads_limit); ?>">
            <?php endif; ?>
            <?php if (isset($_GET['followup_limit'])): ?>
                <input type="hidden" name="followup_limit" value="<?php echo e($_GET['followup_limit']); ?>">
            <?php endif; ?>
            <?php if (!empty($raw_fn_from)): ?>
                <input type="hidden" name="followup_from" value="<?php echo e($raw_fn_from); ?>">
            <?php endif; ?>
            <?php if (!empty($raw_fn_to)): ?>
                <input type="hidden" name="followup_to" value="<?php echo e($raw_fn_to); ?>">
            <?php endif; ?>
            <?php if ($f_fn_remark !== ''): ?>
                <input type="hidden" name="followup_remark" value="<?php echo e($f_fn_remark); ?>">
            <?php endif; ?>
            <?php foreach ($f_fn_courses as $fc): ?>
                <input type="hidden" name="followup_courses[]" value="<?php echo e($fc); ?>">
            <?php endforeach; ?>
            <?php foreach ($f_fn_statuses as $fs): ?>
                <input type="hidden" name="followup_statuses[]" value="<?php echo e($fs); ?>">
            <?php endforeach; ?>
            <?php foreach ($f_fn_assigned as $fa): ?>
                <input type="hidden" name="followup_assigned[]" value="<?php echo e($fa); ?>">
            <?php endforeach; ?>
            <div class="field"><label>Status</label>
                <select name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($LEAD_STATUSES as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $f_status === $k ? 'selected' : ''; ?>><?php echo $v[0]; ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Follow-up</label>
                <select name="due">
                    <option value="">Any</option>
                    <option value="today"    <?php echo $f_due === 'today' ? 'selected' : ''; ?>>Due today</option>
                    <option value="overdue"  <?php echo $f_due === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                    <option value="upcoming" <?php echo $f_due === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                </select>
            </div>
            <?php if (is_super_admin() && $admin_list): ?>
            <div class="field"><label>Assigned to</label>
                <select name="assigned">
                    <option value="">All admins</option>
                    <?php foreach ($admin_list as $a): ?><option value="<?php echo e($a); ?>" <?php echo $f_assigned === $a ? 'selected' : ''; ?>><?php echo e($a); ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if (!empty($remark_authors)): ?>
            <div class="field"><label>Last Remarked By</label>
                <select name="last_remarked_by">
                    <option value="">All</option>
                    <?php foreach ($remark_authors as $ra): ?>
                        <option value="<?php echo e($ra['performed_by']); ?>" <?php echo $f_last_remarked_by === $ra['performed_by'] ? 'selected' : ''; ?>>
                            <?php echo e(!empty($ra['full_name']) ? $ra['full_name'] . ' (' . $ra['performed_by'] . ')' : $ra['performed_by']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($course_list): ?>
            <div class="field"><label>Course</label>
                <select name="course">
                    <option value="">All courses</option>
                    <?php foreach ($course_list as $c): ?><option value="<?php echo e($c); ?>" <?php echo $f_course === $c ? 'selected' : ''; ?>><?php echo e($c); ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="field"><label>Joined Status</label>
                <select name="joined">
                    <option value="">All</option>
                    <option value="yes" <?php echo $f_joined === 'yes' ? 'selected' : ''; ?>>Joined any course</option>
                    <option value="not_converted" <?php echo $f_joined === 'not_converted' ? 'selected' : ''; ?>>Joined &amp; Not Converted</option>
                    <option value="converted" <?php echo $f_joined === 'converted' ? 'selected' : ''; ?>>Joined &amp; Converted</option>
                </select>
            </div>
            <div class="field" style="min-width:140px;">
                <label>Converted By</label>
                <div class="fn-multiselect-wrap" data-placeholder="Converted By">
                    <button type="button" class="fn-multiselect-btn<?php echo !empty($f_converted_by) ? ' active' : ''; ?>" onclick="toggleFnDropdown('leads-converted-by-dropdown', event)">
                        <span class="fn-multiselect-label"><?php
                            $cb_count = count($f_converted_by);
                            echo $cb_count > 0 ? ('Converted (' . $cb_count . ')') : 'All Attributions';
                        ?></span>
                        <i class="fas fa-chevron-down" style="font-size:0.65rem; margin-left:3px; opacity:0.6;"></i>
                    </button>
                    <div id="leads-converted-by-dropdown" class="fn-multiselect-dropdown" style="display:none; min-width:210px;">
                        <label class="fn-multiselect-item">
                            <input type="checkbox" name="converted_by[]" value="auto_converted" <?php echo in_array('auto_converted', $f_converted_by, true) ? 'checked' : ''; ?>>
                            <span>Auto Converted</span>
                        </label>
                        <label class="fn-multiselect-item">
                            <input type="checkbox" name="converted_by[]" value="alumni_referral" <?php echo in_array('alumni_referral', $f_converted_by, true) ? 'checked' : ''; ?>>
                            <span style="color:#6b21a8; font-weight:600;"><i class="fas fa-lock" style="font-size:0.7rem;"></i> Alumni Referral</span>
                        </label>
                        <?php foreach ($all_admins as $adm): ?>
                            <label class="fn-multiselect-item">
                                <input type="checkbox" name="converted_by[]" value="<?php echo e($adm['username']); ?>" <?php echo in_array($adm['username'], $f_converted_by, true) ? 'checked' : ''; ?>>
                                <span><?php echo e(!empty($adm['full_name']) ? $adm['full_name'] : $adm['username']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="field grow-2"><label>Search</label><input type="text" name="q" value="<?php echo e($f_q); ?>" placeholder="Name, WhatsApp, course or institute"></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <?php if (isset($_SESSION['locked_lead_filters'])): ?>
                <button type="submit" name="unlock" value="1" class="btn btn-danger" style="border-radius:8px; background:#ef4444; border:none; color:#fff; cursor:pointer;"><i class="fas fa-lock-open"></i> Unlock</button>
            <?php else: ?>
                <button type="submit" name="lock" value="1" class="btn btn-outline" style="border-radius:8px; border-color:#cbd5e1; color:#475569; cursor:pointer;"><i class="fas fa-lock"></i> Lock</button>
            <?php endif; ?>
            <a href="lead-management.php?tab=leads&unlock=1" class="btn btn-outline">Reset</a>
        </form>
    </div>
</div>

<!-- ── ALL LEADS ── -->
<div class="panel">
    <div class="panel-head" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <span class="head-icon" style="background:var(--accent-soft);color:var(--accent-dark);"><i class="fas fa-list"></i></span>
            <h2>Leads (<?php echo number_format($total); ?>) <?php
                if ($total > 0) {
                    echo ' <span style="font-size:0.8rem; font-weight:500; color:var(--text-muted); opacity:0.85;">· Showing ' . count($leads) . '</span>';
                }
            ?> <?php if (isset($_SESSION['locked_lead_filters'])): ?><i class="fas fa-lock" style="color:#ef4444; font-size:0.9rem; margin-left:6px;" title="Filters Locked"></i><?php endif; ?></h2>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <form method="GET" action="lead-management.php" id="leads-limit-form" style="display:inline-flex; align-items:center; gap:6px; margin:0;">
                <input type="hidden" name="tab" value="leads">
                <?php
                foreach ($_GET as $gk => $gv) {
                    if ($gk !== 'leads_limit' && $gk !== 'page' && $gk !== 'tab') {
                        if (is_array($gv)) {
                            foreach ($gv as $item) {
                                echo '<input type="hidden" name="' . e($gk) . '[]" value="' . e($item) . '">';
                            }
                        } else {
                            echo '<input type="hidden" name="' . e($gk) . '" value="' . e($gv) . '">';
                        }
                    }
                }
                ?>
                <label for="leads-limit-select" style="font-size:0.82rem; font-weight:600; color:#475569;">Show:</label>
                <select name="leads_limit" id="leads-limit-select" onchange="this.form.submit()" style="padding:4px 10px; font-size:0.8rem; border:1px solid #cbd5e1; border-radius:6px; background:#fff; font-weight:600; color:#1e293b; cursor:pointer;">
                    <option value="50" <?php echo $leads_limit === 50 ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $leads_limit === 100 ? 'selected' : ''; ?>>100</option>
                    <option value="500" <?php echo $leads_limit === 500 ? 'selected' : ''; ?>>500</option>
                </select>
            </form>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($leads)): ?>
            <div class="empty-state"><i class="fas fa-user-tag"></i><p>No leads match these filters. Add your first lead or import a list.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Lead</th><th>Interested In</th><th>Education</th><th>Status</th><th>Next Follow-up</th><th>Assigned</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($leads as $l):
                $overdue = $l['next_followup_date'] && $l['next_followup_date'] < date('Y-m-d') && !in_array($l['status'], $CLOSED, true);
                $istoday = $l['next_followup_date'] === date('Y-m-d') && !in_array($l['status'], $CLOSED, true);
                $normPhone = normalizeLeadPhone($l['whatsapp_number']);
                $matches = $preloadedAdmissions[$normPhone] ?? [];
                $joinedCount = 0;
                $appliedCount = 0;
                $sameCourseApprovedStudent = null;
                $eligibleForManualConversion = false;
                $normLeadCourse = normalizeLeadCourse($l['interested_course']);

                $joinedCount = 0;
                $appliedCount = 0;
                $sameCourseApprovedStudent = null;
                $anyCourseApprovedStudent = null;
                $eligibleForManualConversion = false;
                $normLeadCourse = normalizeLeadCourse($l['interested_course']);

                foreach ($matches as $m) {
                    if ($m['status'] === 'approved') {
                        $joinedCount++;
                        if (normalizeLeadCourse($m['pepp_course']) === $normLeadCourse) {
                            $sameCourseApprovedStudent = $m;
                        }
                        if ($anyCourseApprovedStudent === null) {
                            $anyCourseApprovedStudent = $m;
                        }
                    } elseif ($m['status'] === 'pending') {
                        $appliedCount++;
                    }
                }
                $conversionStudent = $sameCourseApprovedStudent ?: $anyCourseApprovedStudent;
                if ($conversionStudent && $l['status'] !== 'converted') {
                    $eligibleForManualConversion = true;
                }
                
                $matchedDetails = [];
                foreach ($matches as $m) {
                    $matchedDetails[] = [
                        'course' => $m['pepp_course'],
                        'student_name' => $m['name'],
                        'status' => ucfirst($m['status']),
                        'date' => $m['approval_date'] ? date('d M Y', strtotime($m['approval_date'])) : 'N/A',
                        'student_id' => $m['user_id'],
                        'is_same_course' => (normalizeLeadCourse($m['pepp_course']) === normalizeLeadCourse($l['interested_course'])),
                        'lead_status' => $l['status']
                    ];
                }
                $detailsJson = htmlspecialchars(json_encode($matchedDetails));
            ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($l['name'] ?: 'Unknown'); ?></div>
                        <div class="cell-sub">
                            <?php echo format_credential($l['whatsapp_number'], 'phone', 'leads'); ?> · <?php echo (int)$l['followup_count']; ?> follow-up(s)
                            <?php if (!empty($matches)): ?>
                                <div style="margin-top:4px;">
                                    <button type="button" class="joined-courses-btn" data-details="<?php echo $detailsJson; ?>" onclick="showJoinedCoursesModal(this)" style="padding:1px 6px; border-radius:4px; font-size:0.62rem; line-height:1.2; font-weight:700; cursor:pointer; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46;">
                                        <?php if ($joinedCount > 0): ?>
                                            🟢 Joined: <?php echo $joinedCount; ?>
                                        <?php endif; ?>
                                        <?php if ($appliedCount > 0): ?>
                                            <?php echo $joinedCount > 0 ? ' | ' : ''; ?>🟡 Applied: <?php echo $appliedCount; ?>
                                        <?php endif; ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="cell-sub"><?php echo e($l['interested_course'] ?: '-'); ?></td>
                    <td class="cell-sub">
                        <?php echo e($l['last_institute'] ?: '-'); ?>
                        <?php if ($l['year_of_study']): ?><div><span class="badge gray" style="font-size:.62rem;"><?php echo e($l['year_of_study']); ?><?php echo $l['is_fyugp'] === 'yes' ? ' · FYUGP' : ''; ?></span></div><?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $LEAD_STATUSES[$l['status']][1]; ?>"><?php echo $LEAD_STATUSES[$l['status']][0]; ?></span>
                        <?php if ($l['status'] === 'converted'):
                            $cbVal = $l['converted_by'] ?: 'auto_converted';
                        ?>
                            <div style="margin-top:4px;">
                                <?php if ($cbVal === 'alumni_referral'): ?>
                                    <span class="badge" style="background:#f3e8ff; color:#6b21a8; font-size:0.65rem; font-weight:700; border:1px solid #d8b4fe;" title="Locked: Alumni Referral"><i class="fas fa-lock"></i> Alumni Ref</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#ecfdf5; color:#047857; font-size:0.65rem; font-weight:600; border:1px solid #a7f3d0;" title="Converted By: <?php echo e($cbVal); ?>">
                                        By: <?php echo e($cbVal === 'auto_converted' ? 'Auto' : ($all_admin_names[$cbVal] ?? $cbVal)); ?>
                                    </span>
                                    <?php if (is_super_admin()): ?>
                                        <button type="button" onclick="openEditConvertedByModal(<?php echo (int)$l['id']; ?>, '<?php echo e(addslashes($l['name'] ?: 'Lead #' . $l['id'])); ?>', '<?php echo e(addslashes($cbVal)); ?>')" title="Edit Converted By Attribution (Super Admin)" style="background:none; border:none; color:#64748b; cursor:pointer; font-size:0.7rem; padding:0 2px; vertical-align:middle;">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (in_array($l['status'], $CLOSED, true)): ?>
                            <span class="cell-sub">-</span>
                        <?php elseif ($l['next_followup_date']): ?>
                            <span class="badge <?php echo $overdue ? 'red' : ($istoday ? 'amber' : 'gray'); ?>"><?php echo date('d M Y', strtotime($l['next_followup_date'])); ?></span>
                        <?php else: ?><span class="cell-sub">-</span><?php endif; ?>
                    </td>
                    <td class="cell-sub"><?php echo $l['assigned_to'] === '__ALL__' ? '<span class="badge violet">All Admins</span>' : e($l['assigned_to'] ?: '-'); ?></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <?php if ($eligibleForManualConversion): ?>
                            <button type="button" class="btn btn-sm btn-success" 
                                    data-lead-id="<?php echo $l['id']; ?>" 
                                    data-lead-name="<?php echo htmlspecialchars($l['name']); ?>" 
                                    data-lead-course="<?php echo htmlspecialchars($l['interested_course']); ?>" 
                                    data-lead-phone="<?php echo htmlspecialchars($l['whatsapp_number']); ?>" 
                                    data-student-id="<?php echo htmlspecialchars($conversionStudent['user_id']); ?>" 
                                    data-student-name="<?php echo htmlspecialchars($conversionStudent['name']); ?>" 
                                    data-student-course="<?php echo htmlspecialchars($conversionStudent['pepp_course']); ?>" 
                                    data-student-date="<?php echo $conversionStudent['approval_date'] ? date('d M Y', strtotime($conversionStudent['approval_date'])) : 'N/A'; ?>" 
                                    onclick="confirmManualConversion(this)" 
                                    title="Mark as Converted" 
                                    style="border-radius:6px; padding:2px 8px; font-size:0.7rem; font-weight:700; margin-right:4px;">
                                <i class="fas fa-check-circle"></i> Mark Converted
                            </button>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline" href="tel:<?php echo preg_replace('/\D/', '', $l['whatsapp_number']); ?>" title="Call"><i class="fas fa-phone"></i></a>
                        <a class="btn btn-sm btn-whatsapp" href="<?php echo e(wa_link($l['whatsapp_number'])); ?>" target="_blank" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                        <a class="btn btn-sm btn-primary" href="lead-details.php?id=<?php echo (int)$l['id']; ?>" title="Open lead"><i class="fas fa-arrow-right"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a class="page-link" href="<?php echo e(lqs(['page' => $page - 1])); ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
            <?php for ($p = max(1, $page - 3); $p <= min($total_pages, $page + 3); $p++): ?>
                <a class="page-link <?php echo $p === $page ? 'active' : ''; ?>" href="<?php echo e(lqs(['page' => $p])); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?><a class="page-link" href="<?php echo e(lqs(['page' => $page + 1])); ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── ADD LEAD MODAL ── -->
<div class="modal-backdrop" id="add-lead-modal">
    <div class="modal" style="max-width:620px;">
        <div class="modal-head"><h3><i class="fas fa-user-plus" style="color:var(--accent);"></i> Add Lead</h3><button class="modal-close" onclick="closeModal('add-lead-modal')"><i class="fas fa-xmark"></i></button></div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_lead">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field"><label>WhatsApp Number <span class="req">*</span></label>
                        <input type="text" name="whatsapp_number" required placeholder="10-digit or with country code"></div>
                    <div class="field"><label>Name</label><input type="text" name="name" placeholder="Lead name"></div>
                    <div class="field"><label>Interested PEPP Course</label>
                        <input type="text" name="interested_course" list="course-options" placeholder="Course of interest">
                        <datalist id="course-options"><?php foreach ($pepp_courses as $c): ?><option value="<?php echo e($c); ?>"><?php endforeach; ?></datalist>
                    </div>
                    <div class="field"><label>Last Studied Institute</label><input type="text" name="last_institute"></div>
                    <div class="field"><label>Last Studied Course</label><input type="text" name="last_course"></div>
                    <div class="field"><label>FYUGP Student?</label>
                        <select name="is_fyugp"><option value="">-</option><option value="yes">Yes</option><option value="no">No</option></select></div>
                    <div class="field"><label>Year of Study</label>
                        <select name="year_of_study"><option value="">-</option><?php foreach ($YEARS as $y): ?><option value="<?php echo $y; ?>"><?php echo $y; ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Lead Status</label>
                        <select name="status" id="add-status" onchange="toggleFollowupReq('add')">
                            <?php foreach ($LEAD_STATUSES as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo $v[0]; ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field"><label>Next Follow-up Date <span class="req" id="add-fu-req">*</span></label>
                        <input type="date" name="next_followup_date" id="add-followup" value="<?php echo date('Y-m-d', strtotime('+2 days')); ?>"></div>
                    <?php if (is_super_admin() && $assignable): ?>
                    <div class="field"><label>Assign To</label>
                        <select name="assigned_to">
                            <option value="__ALL__" selected>All Admins</option>
                            <?php foreach ($assignable as $a): ?><option value="<?php echo e($a); ?>"><?php echo e($a); ?></option><?php endforeach; ?>
                        </select></div>
                    <?php endif; ?>
                    <div class="field full"><label>Remarks</label><textarea name="remarks" rows="2" placeholder="First note about this lead"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('add-lead-modal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Lead</button></div>
        </form>
    </div>
</div>

<!-- ── IMPORT MODAL ── -->
<div class="modal-backdrop" id="import-modal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-head"><h3><i class="fas fa-file-import" style="color:var(--accent);"></i> Bulk Import Leads</h3><button class="modal-close" onclick="closeModal('import-modal')"><i class="fas fa-xmark"></i></button></div>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_import">
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-circle-info"></i>
                    <span>Upload a <strong>CSV file</strong> (in Excel: <em>Save As → CSV</em>). The first row must be column headers. Recognised columns:
                    <code>whatsapp_number</code> (required), <code>name</code>, <code>interested_course</code>, <code>last_institute</code>,
                    <code>last_course</code>, <code>is_fyugp</code> (yes/no), <code>year_of_study</code>, <code>next_followup_date</code>, <code>remarks</code>.
                    Imported leads start as <strong>New</strong>; rows without a valid WhatsApp number are skipped.</span>
                </div>
                <div class="field"><label>CSV file <span class="req">*</span></label>
                    <input type="file" name="lead_file" accept=".csv,.txt" required></div>
                <?php if (is_super_admin() && $assignable): ?>
                <div class="field"><label>Assign all imported leads to</label>
                    <select name="bulk_assigned_to">
                        <option value="__ALL__" selected>All Admins</option>
                        <?php foreach ($assignable as $a): ?><option value="<?php echo e($a); ?>"><?php echo e($a); ?></option><?php endforeach; ?>
                    </select></div>
                <?php endif; ?>
                <a href="lead-sample.csv" download style="font-size:.78rem;font-weight:600;color:var(--accent);"><i class="fas fa-download"></i> Download a sample CSV</a>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('import-modal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-file-import"></i> Import Leads</button></div>
        </form>
    </div>
</div>

<!-- ── BULK UPDATE MODAL (FOLLOW-UPS) ── -->
<div class="modal-backdrop" id="bulk-update-modal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-head">
            <h3><i class="fas fa-pen-to-square" style="color:var(--accent);"></i> Bulk Update (<span id="bulk-selected-count">0</span> Leads)</h3>
            <button type="button" class="modal-close" onclick="closeModal('bulk-update-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="lead-management.php<?php echo e(lqs()); ?>" onsubmit="return validateBulkUpdateForm();">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_update_followups">
            <div id="bulk-update-lead-ids"></div>

            <div class="modal-body">
                <p style="font-size:0.8rem; color:#64748b; margin-bottom:14px;">
                    Update selected leads simultaneously. Each field defaults to <strong>No change</strong>. Only modified fields will be applied.
                </p>

                <div class="field">
                    <label>Next Follow-up Date</label>
                    <select name="bulk_date_action" id="bulk_date_action" onchange="toggleBulkDateField()">
                        <option value="keep" selected>No change</option>
                        <option value="set">Set new follow-up date...</option>
                    </select>
                    <div id="bulk_date_picker_wrap" style="display:none; margin-top:8px;">
                        <input type="date" name="bulk_next_followup_date" id="bulk_next_followup_date" min="<?php echo date('Y-m-d'); ?>">
                        <span class="field-hint" style="font-size:0.75rem; color:#64748b; display:block; margin-top:4px;">Applies to selected active leads.</span>
                    </div>
                </div>

                <div class="field">
                    <label>Lead Status</label>
                    <select name="bulk_status" id="bulk_status">
                        <option value="" selected>No change</option>
                        <?php foreach ($LEAD_STATUSES as $k => $v): ?>
                            <option value="<?php echo $k; ?>"><?php echo $v[0]; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (is_super_admin() && !empty($assignable)): ?>
                <div class="field">
                    <label>Assigned To</label>
                    <select name="bulk_assigned_to" id="bulk_assigned_to">
                        <option value="" selected>No change</option>
                        <option value="__ALL__">Unassigned (Visible to all admins)</option>
                        <?php foreach ($assignable as $a): ?>
                            <option value="<?php echo e($a); ?>"><?php echo e($a); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('bulk-update-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Apply Bulk Update</button>
            </div>
        </form>
    </div>
</div>

<!-- ── CONVERTED LEADS BREAKDOWN MODAL ── -->
<div id="converted-breakdown-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(2px);">
    <div style="background:#fff; border-radius:16px; width:100%; max-width:550px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); max-height:90vh; display:flex; flex-direction:column; margin:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
            <div>
                <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-circle-check" style="color:#10b981;"></i> Converted Leads Breakdown
                </h3>
                <span style="font-size:0.75rem; color:#64748b;">Distribution of all <?php echo number_format($stats['converted']); ?> converted leads</span>
            </div>
            <button type="button" onclick="closeConvertedBreakdownModal()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#94a3b8; line-height:1;">&times;</button>
        </div>
        <div style="overflow-y:auto; flex:1; margin-bottom:16px;">
            <table class="data-table" style="width:100%; font-size:0.85rem; margin:0;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:8px 12px; text-align:left; font-weight:700; color:#475569;">Converted By</th>
                        <th style="padding:8px 12px; text-align:right; font-weight:700; color:#475569;">Quantity</th>
                        <th style="padding:8px 12px; text-align:center; font-weight:700; color:#475569;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($converted_breakdown)): ?>
                        <tr><td colspan="3" style="text-align:center; padding:16px; color:#94a3b8;">No converted leads found.</td></tr>
                    <?php else: ?>
                        <?php
                        foreach ($converted_breakdown as $cb):
                            $qty = (int)$cb['qty'];
                            $k = $cb['conv_key'];
                            $badgeStyle = 'background:#f1f5f9; color:#334155;';
                            if ($k === 'alumni_referral') {
                                $label = 'Alumni Referral 🔒';
                                $badgeStyle = 'background:#f3e8ff; color:#6b21a8; font-weight:700; border:1px solid #d8b4fe;';
                            } elseif ($k === 'auto_converted') {
                                $label = 'Auto Converted';
                                $badgeStyle = 'background:#f8fafc; color:#64748b; font-weight:600; border:1px solid #cbd5e1;';
                            } else {
                                $adminName = $all_admin_names[$k] ?? null;
                                $label = $adminName ? "{$adminName} ({$k})" : $k;
                                $badgeStyle = 'background:#ecfdf5; color:#065f46; font-weight:600; border:1px solid #a7f3d0;';
                            }
                        ?>
                            <tr>
                                <td style="padding:10px 12px;">
                                    <span class="badge" style="<?php echo $badgeStyle; ?> padding:3px 8px; border-radius:6px; font-size:0.8rem;">
                                        <?php echo e($label); ?>
                                    </span>
                                </td>
                                <td style="padding:10px 12px; text-align:right; font-weight:700; font-size:0.9rem; color:#1e293b;">
                                    <?php echo number_format($qty); ?>
                                </td>
                                <td style="padding:10px 12px; text-align:center;">
                                    <a href="lead-management.php?tab=leads&status=converted&converted_by[]=<?php echo urlencode($k); ?>" class="btn btn-sm btn-outline" style="padding:3px 10px; font-size:0.75rem; border-radius:6px; font-weight:600;">
                                        View Leads <i class="fas fa-arrow-right" style="font-size:0.7rem; margin-left:4px;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc; border-top:2px solid #cbd5e1; font-weight:700;">
                        <td style="padding:10px 12px; color:#1e293b;">TOTAL CONVERTED</td>
                        <td style="padding:10px 12px; text-align:right; font-size:0.95rem; color:#10b981;"><?php echo number_format($stats['converted']); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e2e8f0; padding-top:12px;">
            <a href="lead-management.php?tab=leads&status=converted" class="btn btn-sm btn-soft-green" style="font-weight:600;">
                <i class="fas fa-list"></i> View All Converted Leads
            </a>
            <button type="button" onclick="closeConvertedBreakdownModal()" class="btn btn-sm btn-outline">Close</button>
        </div>
    </div>
</div>

<?php if (is_super_admin()): ?>
<!-- ── SUPER ADMIN EDIT CONVERTED BY MODAL ── -->
<div id="edit-converted-by-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(2px);">
    <div style="background:#fff; border-radius:16px; width:100%; max-width:440px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); margin:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
            <h4 style="margin:0; font-size:1rem; font-weight:700; color:#1e293b;">
                <i class="fas fa-pen" style="color:var(--accent); margin-right:6px;"></i> Edit Converted By Attribution
            </h4>
            <button type="button" onclick="closeEditConvertedByModal()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>
        <form method="POST" action="lead-management.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="change_converted_by">
            <input type="hidden" name="lead_id" id="edit-conv-lead-id">

            <div style="margin-bottom:14px; font-size:0.85rem; color:#475569; background:#f8fafc; padding:8px 12px; border-radius:8px; border:1px solid #e2e8f0;">
                Lead: <strong id="edit-conv-lead-name" style="color:#1e293b;">-</strong>
            </div>

            <div class="field" style="margin-bottom:16px;">
                <label style="font-weight:700; color:#1e293b;">Attributed To <span class="req">*</span></label>
                <select name="converted_by" id="edit-conv-select" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.88rem;">
                    <option value="auto_converted">Auto Converted</option>
                    <?php foreach ($all_admins as $adm): ?>
                        <option value="<?php echo e($adm['username']); ?>">
                            <?php echo e(!empty($adm['full_name']) ? $adm['full_name'] . ' (' . $adm['username'] . ')' : $adm['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint" style="font-size:0.75rem; color:#64748b; margin-top:6px; display:block;">
                    Super Admin modification will be permanently logged with your name and mobile number.
                </span>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="closeEditConvertedByModal()" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Update Attribution</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
$extra_scripts = "
<!-- Hidden POST Form for Manual Conversion -->
<form id='manual-conversion-form' method='POST' action='lead-management.php' style='display:none;'>
    " . csrf_field() . "
    <input type='hidden' name='action' value='mark_converted'>
    <input type='hidden' name='lead_id' id='post-convert-lead-id'>
    <input type='hidden' name='student_user_id' id='post-convert-student-id'>
</form>

<!-- Joined Courses Modal -->
<div id='joined-courses-modal' style='display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;'>
    <div style='background:#fff; border-radius:16px; width:100%; max-width:400px; padding:20px; box-shadow:0 10px 25px rgba(0,0,0,0.1);'>
        <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;'>
            <h4 style='margin:0; font-size:0.95rem; font-weight:700; color:#1e293b;'><i class='fas fa-user-graduate' style='color:#10b981; margin-right:6px;'></i> Joined PEPP Courses</h4>
            <button onclick=\"document.getElementById('joined-courses-modal').style.display='none'\" style='background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8;'>&times;</button>
        </div>
        <div id='joined-courses-modal-list' style='max-height:300px; overflow-y:auto; margin-bottom:16px; text-align:left;'></div>
        <div style='text-align:right;'>
            <button onclick=\"document.getElementById('joined-courses-modal').style.display='none'\" class='btn btn-secondary' style='border-radius:8px; font-size:0.8rem; padding:6px 14px;'>Close</button>
        </div>
    </div>
</div>

<!-- Manual Conversion Confirmation Modal -->
<div id='confirm-conversion-modal' style='display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;'>
    <div style='background:#fff; border-radius:16px; width:100%; max-width:420px; padding:20px; box-shadow:0 10px 25px rgba(0,0,0,0.1); text-align:left;'>
        <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;'>
            <h4 style='margin:0; font-size:0.95rem; font-weight:700; color:#1e293b;'><i class='fas fa-check-circle' style='color:#10b981; margin-right:6px;'></i> Confirm Conversion</h4>
            <button onclick=\"document.getElementById('confirm-conversion-modal').style.display='none'\" style='background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8;'>&times;</button>
        </div>
        <p style='font-size:0.8rem; color:#64748b; margin-bottom:14px;'>Mark this lead as Converted?</p>
        
        <div style='background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; margin-bottom:16px; font-size:0.75rem; color:#475569;'>
            <strong>Lead Record:</strong><br>
            Name: <span id='modal-lead-name'></span><br>
            Course: <span id='modal-lead-course'></span><br>
            Phone: <span id='modal-lead-phone'></span>
            
            <hr style='border:0; border-top:1px solid #e2e8f0; margin:10px 0;'>
            
            <strong>Matched Admission:</strong><br>
            Student: <span id='modal-student-name'></span> (<span id='modal-student-id'></span>)<br>
            Course: <span id='modal-student-course'></span><br>
            Status: <span style='color:#10b981; font-weight:700;'>Approved</span> on <span id='modal-student-date'></span>
        </div>
        
        <div style='display:flex; justify-content:end; gap:8px;'>
            <button onclick=\"document.getElementById('confirm-conversion-modal').style.display='none'\" class='btn btn-outline' style='border-radius:8px; font-size:0.8rem; padding:6px 14px;'>Cancel</button>
            <button onclick='submitManualConversion()' class='btn btn-success' style='border-radius:8px; font-size:0.8rem; padding:6px 14px; font-weight:700;'>Yes, Mark Converted</button>
        </div>
    </div>
</div>

<script>
var selectedLeadId = null;
var selectedStudentId = null;

function confirmManualConversion(btn) {
    selectedLeadId = btn.getAttribute('data-lead-id');
    selectedStudentId = btn.getAttribute('data-student-id');
    
    document.getElementById('modal-lead-name').textContent = btn.getAttribute('data-lead-name');
    document.getElementById('modal-lead-course').textContent = btn.getAttribute('data-lead-course');
    document.getElementById('modal-lead-phone').textContent = btn.getAttribute('data-lead-phone');
    
    document.getElementById('modal-student-name').textContent = btn.getAttribute('data-student-name');
    document.getElementById('modal-student-id').textContent = selectedStudentId;
    document.getElementById('modal-student-course').textContent = btn.getAttribute('data-student-course');
    document.getElementById('modal-student-date').textContent = btn.getAttribute('data-student-date');
    
    document.getElementById('confirm-conversion-modal').style.display = 'flex';
}

function submitManualConversion() {
    document.getElementById('post-convert-lead-id').value = selectedLeadId;
    document.getElementById('post-convert-student-id').value = selectedStudentId;
    document.getElementById('manual-conversion-form').submit();
}

function showJoinedCoursesModal(btn) {
    var details = JSON.parse(btn.getAttribute('data-details'));
    var listHtml = '<div style=\"display:flex; flex-direction:column; gap:12px;\">';
    
    details.forEach(function(item) {
        var statusColor = '#94a3b8'; // Default grey
        var indicator = '○';
        if (item.status === 'Approved') {
            statusColor = '#10b981'; // Green
            indicator = '✓';
        } else if (item.status === 'Pending') {
            statusColor = '#f59e0b'; // Amber
            indicator = '⚡';
        } else if (item.status === 'Rejected') {
            statusColor = '#ef4444'; // Red
            indicator = '✗';
        }
        
        var courseTypeBadge = '';
        if (item.is_same_course) {
            courseTypeBadge = ' <span style=\"background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:2px 6px; border-radius:4px; font-size:0.65rem; font-weight:700;\">Same Course</span>';
        } else {
            courseTypeBadge = ' <span style=\"background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; padding:2px 6px; border-radius:4px; font-size:0.65rem; font-weight:700;\">Other Course</span>';
        }
        
        listHtml += '<div style=\"background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px;\">';
        listHtml += '  <div style=\"display:flex; justify-content:space-between; align-items:center; font-weight:700; color:#1e293b; font-size:0.82rem; margin-bottom:4px;\">';
        listHtml += '    <span>' + indicator + ' ' + escapeHtml(item.course) + '</span>';
        listHtml += '    ' + courseTypeBadge;
        listHtml += '  </div>';
        listHtml += '  <div style=\"font-size:0.75rem; color:#64748b; margin-top:4px;\">';
        listHtml += '    Name: ' + escapeHtml(item.student_name) + ' (' + escapeHtml(item.student_id) + ')<br>';
        listHtml += '    Status: <span style=\"font-weight:700; color:' + statusColor + ';\">' + escapeHtml(item.status) + '</span>';
        if (item.status === 'Approved') {
            listHtml += ' • Approved on ' + escapeHtml(item.date);
        }
        listHtml += '    <br>Current Lead Status: <span style=\"font-weight:700; color:#475569;\">' + escapeHtml(item.lead_status) + '</span>';
        listHtml += '  </div>';
        listHtml += '</div>';
    });
    listHtml += '</div>';
    
    document.getElementById('joined-courses-modal-list').innerHTML = listHtml;
    document.getElementById('joined-courses-modal').style.display = 'flex';
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function toggleFollowupReq(prefix) {
    var status = document.getElementById(prefix + '-status').value;
    var closed = ['converted','rejected','not_interested'].indexOf(status) !== -1;
    var fu = document.getElementById(prefix + '-followup');
    var req = document.getElementById(prefix + '-fu-req');
    if (fu) fu.required = !closed;
    if (req) req.style.display = closed ? 'none' : 'inline';
}

function getSelectedFollowupIds() {
    var cbs = document.querySelectorAll('.followup-row-cb:checked');
    var ids = [];
    for (var i = 0; i < cbs.length; i++) {
        ids.push(cbs[i].value);
    }
    return ids;
}

function updateFollowupsToolbar() {
    var selected = getSelectedFollowupIds();
    var toolbar = document.getElementById('followups-bulk-toolbar');
    var countEl = document.getElementById('followups-selected-count');
    var selectAllCb = document.getElementById('select-all-followups');
    var allRowCbs = document.querySelectorAll('.followup-row-cb');

    if (selected.length > 0) {
        if (toolbar) toolbar.style.display = 'flex';
        if (countEl) countEl.textContent = selected.length + (selected.length === 1 ? ' lead selected' : ' leads selected');
    } else {
        if (toolbar) toolbar.style.display = 'none';
    }

    if (selectAllCb && allRowCbs.length > 0) {
        selectAllCb.checked = (selected.length === allRowCbs.length);
        selectAllCb.indeterminate = (selected.length > 0 && selected.length < allRowCbs.length);
    }
}

function onFollowupSelectChange() {
    updateFollowupsToolbar();
}

function toggleSelectAllFollowups(master) {
    var cbs = document.querySelectorAll('.followup-row-cb');
    for (var i = 0; i < cbs.length; i++) {
        cbs[i].checked = master.checked;
    }
    updateFollowupsToolbar();
}

function clearFollowupSelection() {
    var cbs = document.querySelectorAll('.followup-row-cb');
    for (var i = 0; i < cbs.length; i++) {
        cbs[i].checked = false;
    }
    var selectAllCb = document.getElementById('select-all-followups');
    if (selectAllCb) {
        selectAllCb.checked = false;
        selectAllCb.indeterminate = false;
    }
    updateFollowupsToolbar();
}

function openBulkUpdateModal() {
    var selected = getSelectedFollowupIds();
    if (selected.length === 0) {
        alert('Please select at least one lead from Follow-ups Needed.');
        return;
    }
    var container = document.getElementById('bulk-update-lead-ids');
    if (container) {
        container.innerHTML = '';
        selected.forEach(function(id) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'lead_ids[]';
            inp.value = id;
            container.appendChild(inp);
        });
    }

    var countSpan = document.getElementById('bulk-selected-count');
    if (countSpan) countSpan.textContent = selected.length;

    var dateAction = document.getElementById('bulk_date_action');
    if (dateAction) { dateAction.value = 'keep'; toggleBulkDateField(); }
    var bulkDate = document.getElementById('bulk_next_followup_date');
    if (bulkDate) bulkDate.value = '';
    var statusSelect = document.getElementById('bulk_status');
    if (statusSelect) statusSelect.value = '';
    var assignedSelect = document.getElementById('bulk_assigned_to');
    if (assignedSelect) assignedSelect.value = '';

    openModal('bulk-update-modal');
}

function toggleBulkDateField() {
    var action = document.getElementById('bulk_date_action') ? document.getElementById('bulk_date_action').value : 'keep';
    var wrap = document.getElementById('bulk_date_picker_wrap');
    var input = document.getElementById('bulk_next_followup_date');
    if (wrap && input) {
        if (action === 'set') {
            wrap.style.display = 'block';
            input.required = true;
        } else {
            wrap.style.display = 'none';
            input.required = false;
            input.value = '';
        }
    }
}

function validateBulkUpdateForm() {
    var dateAction = document.getElementById('bulk_date_action') ? document.getElementById('bulk_date_action').value : 'keep';
    var status = document.getElementById('bulk_status') ? document.getElementById('bulk_status').value : '';
    var assignedEl = document.getElementById('bulk_assigned_to');
    var assigned = assignedEl ? assignedEl.value : '';

    if (dateAction === 'keep' && status === '' && assigned === '') {
        alert('Please specify at least one field to update (Follow-up Date, Status, or Assigned To).');
        return false;
    }
    if (dateAction === 'set') {
        var dateInput = document.getElementById('bulk_next_followup_date');
        if (!dateInput || !dateInput.value) {
            alert('Please select a valid follow-up date.');
            return false;
        }
    }
    return true;
}

function toggleFnDropdown(dropdownId, event) {
    if (event) {
        event.stopPropagation();
        event.preventDefault();
    }
    var dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;
    var isOpen = dropdown.style.display === 'block';
    document.querySelectorAll('.fn-multiselect-dropdown').forEach(function(el) {
        el.style.display = 'none';
    });
    if (!isOpen) {
        dropdown.style.display = 'block';
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.fn-multiselect-wrap')) {
        document.querySelectorAll('.fn-multiselect-dropdown').forEach(function(el) {
            el.style.display = 'none';
        });
    }
});

document.addEventListener('change', function(e) {
    if (e.target && e.target.closest('.fn-multiselect-dropdown')) {
        var wrap = e.target.closest('.fn-multiselect-wrap');
        if (wrap) {
            var label = wrap.querySelector('.fn-multiselect-label');
            var btn = wrap.querySelector('.fn-multiselect-btn');
            var placeholder = wrap.getAttribute('data-placeholder') || 'Filter';
            var checkedCount = wrap.querySelectorAll('input[type=\"checkbox\"]:checked').length;
            if (label) {
                if (checkedCount === 0) {
                    label.textContent = 'All ' + placeholder;
                    if (btn) btn.classList.remove('active');
                } else {
                    label.textContent = placeholder + ' (' + checkedCount + ')';
                    if (btn) btn.classList.add('active');
                }
            }
        }
    }
});

function openConvertedBreakdownModal() {
    var modal = document.getElementById('converted-breakdown-modal');
    if (modal) modal.style.display = 'flex';
}

function closeConvertedBreakdownModal() {
    var modal = document.getElementById('converted-breakdown-modal');
    if (modal) modal.style.display = 'none';
}

function openEditConvertedByModal(leadId, leadName, currentVal) {
    var idInput = document.getElementById('edit-conv-lead-id');
    var nameSpan = document.getElementById('edit-conv-lead-name');
    var sel = document.getElementById('edit-conv-select');
    if (idInput) idInput.value = leadId;
    if (nameSpan) nameSpan.textContent = leadName;
    if (sel) sel.value = currentVal || 'auto_converted';
    var modal = document.getElementById('edit-converted-by-modal');
    if (modal) modal.style.display = 'flex';
}

function closeEditConvertedByModal() {
    var modal = document.getElementById('edit-converted-by-modal');
    if (modal) modal.style.display = 'none';
}
</script>";
include 'includes/admin_footer.php';
?>
