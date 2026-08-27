<?php
/**
 * PEPP Learning — Centralized API Guard.
 *
 * Provides standardized authentication, authorization, and CSRF verification
 * for all API endpoints. Include this at the top of any api/*.php file
 * instead of rolling ad-hoc session checks.
 *
 * USAGE:
 *   require_once __DIR__ . '/../includes/api_guard.php';
 *   api_require_auth('approvals');        // Require login + 'approvals' permission + CSRF
 *   api_require_auth();                   // Require login + CSRF only (any admin)
 *   api_require_auth(null, false);        // Require login only, skip CSRF (GET requests)
 *   api_require_super_admin();            // Require super_admin role + CSRF
 */

require_once __DIR__ . '/auth.php';

/**
 * Enforce authentication, optional permission check, and optional CSRF.
 *
 * @param string|null $permission  Page key from ADMIN_PAGES (e.g. 'approvals', 'students')
 * @param bool        $requireCsrf Whether to verify CSRF token (default: true for POST/PUT/DELETE)
 */
function api_require_auth($permission = null, $requireCsrf = true) {
    header('Content-Type: application/json');

    // 1. Session check (auth.php already enforces login redirect for HTML pages,
    //    but API endpoints need a JSON 401 response)
    if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required. Please log in.']);
        exit();
    }

    // 2. Permission check (if specified)
    if ($permission !== null && !can_access($permission)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => "Access denied. You do not have '{$permission}' permission."]);
        exit();
    }

    // 3. CSRF verification for state-changing methods
    if ($requireCsrf && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please refresh and try again.']);
            exit();
        }
    }
}

/**
 * Enforce super_admin role + CSRF.
 */
function api_require_super_admin() {
    header('Content-Type: application/json');

    if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit();
    }

    if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Super Admin privileges required.']);
        exit();
    }

    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired security token.']);
            exit();
        }
    }
}

/**
 * Require POST method for an API endpoint (rejects GET/PUT/etc with 405).
 */
function api_require_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
        exit();
    }
}
