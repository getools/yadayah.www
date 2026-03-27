<?php
/**
 * Community session check. Returns current user info or null.
 * Includes unread notification count.
 * Updates user_last_active_dtime on every check.
 * Also handles logout via POST with action=logout.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($data['action'] ?? '') === 'logout') {
        session_destroy();
        jsonResponse(['logged_out' => true]);
    }
}

if (!empty($_SESSION['user_key'])) {
    $db = getDb();
    $userKey = $_SESSION['user_key'];

    // Update last active timestamp
    $db->prepare("UPDATE yy_user SET user_last_active_dtime = NOW() WHERE user_key = ?")
       ->execute([$userKey]);

    $stmt = $db->prepare("
        SELECT u.user_key, u.user_display_name, u.user_handle, u.user_avatar, u.user_email,
               u.user_oauth_provider, u.user_verified, u.user_reputation,
               array_agg(r.role_code) as roles
        FROM yy_user u
        LEFT JOIN yy_user_role ur ON u.user_key = ur.user_key
        LEFT JOIN yy_role r ON ur.role_key = r.role_key
        WHERE u.user_key = ?
        GROUP BY u.user_key
    ");
    $stmt->execute([$userKey]);
    $user = $stmt->fetch();
    if ($user) {
        // Parse PostgreSQL array
        $roles = trim($user['roles'], '{}');
        $user['roles'] = $roles ? explode(',', $roles) : [];

        // Unread notification count
        $nStmt = $db->prepare("SELECT COUNT(*) FROM yy_community_notification WHERE user_key = ? AND read_flag = FALSE");
        $nStmt->execute([$userKey]);
        $user['unread_notifications'] = (int)$nStmt->fetchColumn();

        jsonResponse(['user' => $user]);
    }
}

jsonResponse(['user' => null]);
