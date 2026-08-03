<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../config/database.php';
require_once '../includes/auth.php';

if (!can_access('campaigns')) {
    echo json_encode(['success' => false, 'message' => 'Forbidden access']);
    exit();
}

$action = $_REQUEST['action'] ?? '';

try {
    if ($action === 'check_slug') {
        $slug = trim($_POST['slug'] ?? '');
        $form_id = (int)($_POST['form_id'] ?? 0);

        if (empty($slug)) {
            echo json_encode(['success' => false, 'message' => 'Slug is required']);
            exit();
        }

        // Clean slug: lowercase, hyphens, alphanumeric
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

        $sql = "SELECT COUNT(*) FROM campaign_forms WHERE slug = ?";
        $params = [$slug];
        if ($form_id > 0) {
            $sql .= " AND id != ?";
            $params[] = $form_id;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $exists = $stmt->fetchColumn() > 0;

        echo json_encode([
            'success' => true,
            'unique' => !$exists,
            'slug' => $slug
        ]);
        exit();
    }

    if ($action === 'save_form') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            // fallback to $_POST if json_decode fails
            $data = $_POST;
        }

        $form_id = (int)($data['id'] ?? 0);
        $title = trim($data['title'] ?? 'Untitled Form');
        $description = trim($data['description'] ?? '');
        $slug = trim($data['slug'] ?? '');
        $status = $data['status'] ?? 'draft';
        $publish_start = !empty($data['publish_schedule_start']) ? $data['publish_schedule_start'] : null;
        $publish_end = !empty($data['publish_schedule_end']) ? $data['publish_schedule_end'] : null;
        $is_public = isset($data['is_public']) ? (int)$data['is_public'] : 1;
        $allowed_emails = trim($data['allowed_emails'] ?? '');
        $limit_per_user = (int)($data['limit_per_user'] ?? 0);
        $submission_limit = (int)($data['submission_limit'] ?? 0);
        $password = null;
        if (!empty($data['password'])) {
            $password = password_hash($data['password'], PASSWORD_DEFAULT);
        } elseif (isset($data['password_kept']) && $data['password_kept'] === true && $form_id > 0) {
            // Keep old password
            $stmt = $pdo->prepare("SELECT password FROM campaign_forms WHERE id = ?");
            $stmt->execute([$form_id]);
            $password = $stmt->fetchColumn();
        }
        
        $theme = trim($data['theme'] ?? 'default');
        $thank_you_title = trim($data['thank_you_title'] ?? 'Thank You!');
        $thank_you_text = trim($data['thank_you_text'] ?? '');
        $webhook_url = trim($data['webhook_url'] ?? '');
        $enable_captcha = isset($data['enable_captcha']) ? (int)$data['enable_captcha'] : 0;
        $notify_emails = trim($data['notify_emails'] ?? '');
        $confirmation_email_subject = trim($data['confirmation_email_subject'] ?? '');
        $confirmation_email_body = trim($data['confirmation_email_body'] ?? '');
        $auto_redirect_whatsapp = isset($data['auto_redirect_whatsapp']) ? (int)$data['auto_redirect_whatsapp'] : 0;
        $whatsapp_group_link = trim($data['whatsapp_group_link'] ?? '');
        $banner_image = trim($data['banner_image'] ?? '');
        
        $fields = $data['fields'] ?? [];

        if (empty($slug)) {
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $title)));
        } else {
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
        }

        // Verify slug uniqueness again
        $sql = "SELECT COUNT(*) FROM campaign_forms WHERE slug = ?";
        $params = [$slug];
        if ($form_id > 0) {
            $sql .= " AND id != ?";
            $params[] = $form_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Slug already exists. Please pick a unique slug/URL.']);
            exit();
        }

        $pdo->beginTransaction();

        if ($form_id > 0) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE campaign_forms SET
                    title = ?, description = ?, slug = ?, status = ?,
                    publish_schedule_start = ?, publish_schedule_end = ?,
                    is_public = ?, allowed_emails = ?, limit_per_user = ?,
                    submission_limit = ?, password = ?, theme = ?,
                    thank_you_title = ?, thank_you_text = ?, webhook_url = ?,
                    enable_captcha = ?, notify_emails = ?,
                    confirmation_email_subject = ?, confirmation_email_body = ?,
                    auto_redirect_whatsapp = ?, whatsapp_group_link = ?,
                    banner_image = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $title, $description, $slug, $status,
                $publish_start, $publish_end,
                $is_public, $allowed_emails, $limit_per_user,
                $submission_limit, $password, $theme,
                $thank_you_title, $thank_you_text, $webhook_url,
                $enable_captcha, $notify_emails,
                $confirmation_email_subject, $confirmation_email_body,
                $auto_redirect_whatsapp, $whatsapp_group_link,
                $banner_image,
                $form_id
            ]);
        } else {
            // Insert
            $stmt = $pdo->prepare("
                INSERT INTO campaign_forms (
                    title, description, slug, status,
                    publish_schedule_start, publish_schedule_end,
                    is_public, allowed_emails, limit_per_user,
                    submission_limit, password, theme,
                    thank_you_title, thank_you_text, webhook_url,
                    enable_captcha, notify_emails,
                    confirmation_email_subject, confirmation_email_body,
                    auto_redirect_whatsapp, whatsapp_group_link,
                    banner_image,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $title, $description, $slug, $status,
                $publish_start, $publish_end,
                $is_public, $allowed_emails, $limit_per_user,
                $submission_limit, $password, $theme,
                $thank_you_title, $thank_you_text, $webhook_url,
                $enable_captcha, $notify_emails,
                $confirmation_email_subject, $confirmation_email_body,
                $auto_redirect_whatsapp, $whatsapp_group_link,
                $banner_image,
                $admin_username
            ]);
            $form_id = $pdo->lastInsertId();
        }

        // Soft delete all fields first, then update or insert active fields
        $stmt_soft_del = $pdo->prepare("UPDATE campaign_form_fields SET is_deleted = 1 WHERE form_id = ?");
        $stmt_soft_del->execute([$form_id]);

        $stmt_ins = $pdo->prepare("
            INSERT INTO campaign_form_fields (
                form_id, type, label, placeholder, default_value, field_name,
                is_required, sort_order, validation_rules, choices, conditional_logic, error_message, is_deleted
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");

        $stmt_upd = $pdo->prepare("
            UPDATE campaign_form_fields SET
                type = ?, label = ?, placeholder = ?, default_value = ?,
                is_required = ?, sort_order = ?, validation_rules = ?, choices = ?,
                conditional_logic = ?, error_message = ?, is_deleted = 0
            WHERE id = ? AND form_id = ?
        ");

        $index = 0;
        foreach ($fields as $field) {
            $field_id = isset($field['id']) ? (int)$field['id'] : 0;
            $field_type = $field['type'] ?? 'short_text';
            $field_label = $field['label'] ?? 'Field label';
            $field_placeholder = $field['placeholder'] ?? '';
            $field_default = $field['default_value'] ?? '';
            
            // Generate distinct, clean field_name
            $field_name = $field['field_name'] ?? '';
            if (empty($field_name)) {
                $field_name = 'field_' . preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $field_label))) . '_' . uniqid();
            }

            $is_required = isset($field['is_required']) ? (int)$field['is_required'] : 0;
            $validation = isset($field['validation_rules']) ? json_encode($field['validation_rules']) : null;
            $choices = isset($field['choices']) ? json_encode($field['choices']) : null;
            $cond_logic = isset($field['conditional_logic']) ? json_encode($field['conditional_logic']) : null;
            $err_msg = $field['error_message'] ?? '';

            if ($field_id > 0) {
                $stmt_upd->execute([
                    $field_type, $field_label, $field_placeholder, $field_default,
                    $is_required, $index++, $validation, $choices,
                    $cond_logic, $err_msg, $field_id, $form_id
                ]);
            } else {
                $stmt_ins->execute([
                    $form_id, $field_type, $field_label, $field_placeholder, $field_default,
                    $field_name, $is_required, $index++, $validation, $choices, $cond_logic, $err_msg
                ]);
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Form saved successfully',
            'id' => $form_id,
            'slug' => $slug
        ]);
        exit();
    }

    if ($action === 'duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Form ID']);
            exit();
        }

        // Fetch original
        $stmt = $pdo->prepare("SELECT * FROM campaign_forms WHERE id = ?");
        $stmt->execute([$id]);
        $form = $stmt->fetch();

        if (!$form) {
            echo json_encode(['success' => false, 'message' => 'Original form not found']);
            exit();
        }

        // Duplicate metadata
        $title = $form['title'] . ' (Copy)';
        $slug = $form['slug'] . '-copy-' . rand(100, 999);
        
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO campaign_forms (
                title, description, slug, status,
                publish_schedule_start, publish_schedule_end,
                is_public, allowed_emails, limit_per_user,
                submission_limit, password, theme,
                thank_you_title, thank_you_text, webhook_url,
                enable_captcha, notify_emails,
                confirmation_email_subject, confirmation_email_body,
                auto_redirect_whatsapp, whatsapp_group_link,
                banner_image,
                created_by
            ) VALUES (?, ?, ?, 'draft', NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $title, $form['description'], $slug,
            $form['is_public'], $form['allowed_emails'], $form['limit_per_user'],
            $form['submission_limit'], $form['password'], $form['theme'],
            $form['thank_you_title'], $form['thank_you_text'], $form['webhook_url'],
            $form['enable_captcha'], $form['notify_emails'],
            $form['confirmation_email_subject'], $form['confirmation_email_body'],
            $form['auto_redirect_whatsapp'], $form['whatsapp_group_link'],
            $form['banner_image'],
            $admin_username
        ]);
        $new_id = $pdo->lastInsertId();

        // Duplicate fields
        $stmt = $pdo->prepare("SELECT * FROM campaign_form_fields WHERE form_id = ? AND is_deleted = 0 ORDER BY sort_order ASC");
        $stmt->execute([$id]);
        $fields = $stmt->fetchAll();

        $stmt_ins = $pdo->prepare("
            INSERT INTO campaign_form_fields (
                form_id, type, label, placeholder, default_value, field_name,
                is_required, sort_order, validation_rules, choices, conditional_logic, error_message
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($fields as $field) {
            $stmt_ins->execute([
                $new_id, $field['type'], $field['label'], $field['placeholder'], $field['default_value'],
                $field['field_name'] . '_' . rand(10, 99), // guarantee uniqueness
                $field['is_required'], $field['sort_order'], $field['validation_rules'],
                $field['choices'], $field['conditional_logic'], $field['error_message']
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Form duplicated successfully',
            'id' => $new_id
        ]);
        exit();
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Form ID']);
            exit();
        }

        // Delete form (will cascade to fields, submissions, answers, analytics via foreign keys)
        $stmt = $pdo->prepare("DELETE FROM campaign_forms WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode([
            'success' => true,
            'message' => 'Form deleted successfully'
        ]);
        exit();
    }

    if ($action === 'archive') {
        $id = (int)($_POST['id'] ?? 0);
        $archive = isset($_POST['archive']) ? (int)$_POST['archive'] : 1;

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Form ID']);
            exit();
        }

        $status = $archive ? 'archived' : 'draft';
        $stmt = $pdo->prepare("UPDATE campaign_forms SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);

        echo json_encode([
            'success' => true,
            'message' => $archive ? 'Form archived successfully' : 'Form restored as draft'
        ]);
        exit();
    }

    if ($action === 'convert_to_leads') {
        $course_name = trim($_POST['course_name'] ?? '');
        $sub_ids_raw = $_POST['sub_ids'] ?? [];
        if (is_string($sub_ids_raw)) {
            $sub_ids_raw = explode(',', $sub_ids_raw);
        }
        $sub_ids = array_map('intval', array_filter((array)$sub_ids_raw));

        if (empty($course_name)) {
            echo json_encode(['success' => false, 'message' => 'Please select a course to assign leads to.']);
            exit();
        }
        if (empty($sub_ids)) {
            echo json_encode(['success' => false, 'message' => 'No registration submissions selected for conversion.']);
            exit();
        }

        $converted_count = 0;
        $skipped_count = 0;
        $errors = [];

        foreach ($sub_ids as $sub_id) {
            // Fetch submission
            $stmt = $pdo->prepare("SELECT * FROM campaign_form_submissions WHERE id = ?");
            $stmt->execute([$sub_id]);
            $sub = $stmt->fetch();
            if (!$sub) {
                $skipped_count++;
                $errors[] = "Submission #{$sub_id} not found.";
                continue;
            }

            // Check if submission is already converted
            if (!empty($sub['is_converted_lead'])) {
                $skipped_count++;
                $errors[] = "Submission #{$sub_id} ({$name}) skipped: Already converted to Lead previously.";
                continue;
            }

            // Fetch answers
            $stmt = $pdo->prepare("
                SELECT a.answer_text, f.label, f.type, f.field_name 
                FROM campaign_form_answers a
                JOIN campaign_form_fields f ON a.field_id = f.id
                WHERE a.submission_id = ?
            ");
            $stmt->execute([$sub_id]);
            $answers = $stmt->fetchAll();

            $name = '';
            $email = '';
            $phone = '';
            $whatsapp = '';
            $location = '';

            foreach ($answers as $ans) {
                $txt = trim($ans['answer_text']);
                $lbl = strtolower(trim($ans['label']));
                $ftype = $ans['type'];

                if ($ftype === 'whatsapp' || strpos($lbl, 'whatsapp') !== false) {
                    if (empty($whatsapp)) $whatsapp = $txt;
                } elseif ($ftype === 'phone' || strpos($lbl, 'phone') !== false || strpos($lbl, 'mobile') !== false || strpos($lbl, 'contact') !== false) {
                    if (empty($phone)) $phone = $txt;
                } elseif ($ftype === 'email' || strpos($lbl, 'email') !== false) {
                    if (empty($email)) $email = $txt;
                } elseif ($ftype === 'location' || strpos($lbl, 'place') !== false || strpos($lbl, 'address') !== false || strpos($lbl, 'city') !== false || strpos($lbl, 'location') !== false) {
                    if (empty($location)) $location = $txt;
                } elseif ($ftype === 'short_text' || strpos($lbl, 'name') !== false) {
                    if (empty($name) && strpos($lbl, 'name') !== false) $name = $txt;
                }
            }

            if (empty($name)) {
                $name = $sub['respondent_identifier'] ?: 'Anonymous Respondent';
            }

            // Fallback: Use Phone number if WhatsApp number is missing
            $target_wa = !empty($whatsapp) ? $whatsapp : $phone;
            
            // Clean phone digits for WhatsApp
            $clean_number = preg_replace('/\D/', '', $target_wa);
            if (strlen($clean_number) === 10) {
                $clean_number = '91' . $clean_number;
            }

            // Validation: Prevent conversion if neither phone nor whatsapp number is included
            if (empty($clean_number) || strlen($clean_number) < 10) {
                $skipped_count++;
                $errors[] = "Submission #{$sub_id} ({$name}) skipped: Missing both Phone and WhatsApp number.";
                continue;
            }

            // Check if already exists in leads
            $stmt = $pdo->prepare("SELECT id FROM leads WHERE whatsapp_number = ?");
            $stmt->execute([$clean_number]);
            $existing_lead_id = $stmt->fetchColumn();
            if ($existing_lead_id) {
                // Mark submission as converted pointing to existing lead
                $pdo->prepare("UPDATE campaign_form_submissions SET is_converted_lead = 1, converted_lead_id = ? WHERE id = ?")->execute([$existing_lead_id, $sub_id]);
                $skipped_count++;
                $errors[] = "Submission #{$sub_id} ({$name}) marked: Lead with number +{$clean_number} already exists in CRM.";
                continue;
            }

            // Insert into leads
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO leads (whatsapp_number, name, interested_course, status, next_followup_date, assigned_to, source, created_by, created_at, last_activity_at)
                    VALUES (?, ?, ?, 'new', CURDATE(), '__ALL__', 'campaign_form', ?, NOW(), NOW())
                ");
                $stmt->execute([$clean_number, $name, $course_name, $admin_username]);
                $new_lead_id = $pdo->lastInsertId();

                // Update submission status to converted
                $pdo->prepare("UPDATE campaign_form_submissions SET is_converted_lead = 1, converted_lead_id = ? WHERE id = ?")->execute([$new_lead_id, $sub_id]);

                $converted_count++;
            } catch (Exception $e) {
                $skipped_count++;
                $errors[] = "Submission #{$sub_id} ({$name}) error: " . $e->getMessage();
            }
        }

        echo json_encode([
            'success' => true,
            'converted' => $converted_count,
            'skipped' => $skipped_count,
            'errors' => $errors
        ]);
        exit();
    }

    if ($action === 'upload_banner') {
        if (!isset($_FILES['banner_file']) || $_FILES['banner_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No image file uploaded or upload error occurred.']);
            exit();
        }

        $file = $_FILES['banner_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (!in_array($ext, $allowed, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid image format. Allowed: JPG, PNG, WEBP, GIF.']);
            exit();
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Image file size exceeds 5MB limit.']);
            exit();
        }

        $dir = '../uploads/banners/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'banner_' . uniqid() . '.' . $ext;
        $target = $dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $target)) {
            $public_url = 'uploads/banners/' . $filename;
            echo json_encode([
                'success' => true,
                'url' => $public_url,
                'message' => 'Banner image uploaded successfully!'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save uploaded banner file.']);
        }
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
