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
                    confirmation_email_subject = ?, confirmation_email_body = ?
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
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $title, $description, $slug, $status,
                $publish_start, $publish_end,
                $is_public, $allowed_emails, $limit_per_user,
                $submission_limit, $password, $theme,
                $thank_you_title, $thank_you_text, $webhook_url,
                $enable_captcha, $notify_emails,
                $confirmation_email_subject, $confirmation_email_body,
                $admin_username
            ]);
            $form_id = $pdo->lastInsertId();
        }

        // Process fields: delete existing ones, then insert new ones (safest way to sync Visual Editor)
        $stmt = $pdo->prepare("DELETE FROM campaign_form_fields WHERE form_id = ?");
        $stmt->execute([$form_id]);

        $stmt = $pdo->prepare("
            INSERT INTO campaign_form_fields (
                form_id, type, label, placeholder, default_value, field_name,
                is_required, sort_order, validation_rules, choices, conditional_logic, error_message
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $index = 0;
        foreach ($fields as $field) {
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

            $stmt->execute([
                $form_id, $field_type, $field_label, $field_placeholder, $field_default,
                $field_name, $is_required, $index++, $validation, $choices, $cond_logic, $err_msg
            ]);
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
                created_by
            ) VALUES (?, ?, ?, 'draft', NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $title, $form['description'], $slug,
            $form['is_public'], $form['allowed_emails'], $form['limit_per_user'],
            $form['submission_limit'], $form['password'], $form['theme'],
            $form['thank_you_title'], $form['thank_you_text'], $form['webhook_url'],
            $form['enable_captcha'], $form['notify_emails'],
            $form['confirmation_email_subject'], $form['confirmation_email_body'],
            $admin_username
        ]);
        $new_id = $pdo->lastInsertId();

        // Duplicate fields
        $stmt = $pdo->prepare("SELECT * FROM campaign_form_fields WHERE form_id = ? ORDER BY sort_order ASC");
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

    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
