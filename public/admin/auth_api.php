<?php
// ============================================================
// auth_api.php  —  Auth check for API endpoints
// Returns JSON 401 if not authenticated (instead of login HTML)
// Include this in API files; use auth.php for page files only
// ============================================================

require_once __DIR__ . '/config.php';

// Allow unauthenticated access from localhost — scripts running on the same
// machine don't need a session. Requests from any other origin still require login.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$is_local = in_array($remote, ['127.0.0.1', '::1', 'localhost'], true);

if (!$is_local) {
    session_name('dso_admin');
    session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();

    if (empty($_SESSION['authenticated'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
}
