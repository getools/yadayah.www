<?php
/**
 * User auth link/unlink API.
 * GET: list auth methods for current user.
 * POST action=confirm_link: link pending OAuth to existing account (user must be logged in).
 * POST action=create_new: decline link, create a new account from pending OAuth.
 * POST action=unlink: remove an auth method.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/community-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$userKey = $_SESSION['user_key'] ?? null;

// GET: list auth methods for current user
if ($method === 'GET') {
    if (!$userKey) errorResponse('Login required', 401);

    $stmt = $db->prepare("
        SELECT user_auth_key, auth_provider, auth_email, auth_linked_dtime
        FROM yy_user_auth WHERE user_key = ? AND auth_active_flag = TRUE
        ORDER BY auth_linked_dtime
    ");
    $stmt->execute([$userKey]);
    $methods = $stmt->fetchAll();

    // Check pending link
    $pending = null;
    if (!empty($_SESSION['pending_link']) && $_SESSION['pending_link']['expires'] > time()) {
        $p = $_SESSION['pending_link'];
        $pending = [
            'provider' => $p['provider'],
            'email' => $p['email'],
            'name' => $p['name'],
            'existing_name' => $p['existing_name'] ?? null,
        ];
    }

    jsonResponse(['auth_methods' => $methods, 'pending_link' => $pending]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';

    // Confirm link: attach pending OAuth to the user's existing account
    if ($action === 'confirm_link') {
        if (!$userKey) errorResponse('You must be signed in to link accounts', 401);

        $pending = $_SESSION['pending_link'] ?? null;
        if (!$pending || $pending['expires'] < time()) {
            unset($_SESSION['pending_link']);
            errorResponse('Link request expired. Please try signing in again.');
        }

        // Verify the logged-in user matches the existing account
        if ((int)$pending['existing_user_key'] !== $userKey) {
            errorResponse('Please sign in with your existing account first, then link.');
        }

        // Insert the new auth method
        $db->prepare("INSERT INTO yy_user_auth (user_key, auth_provider, auth_provider_id, auth_email) VALUES (?, ?, ?, ?)")
            ->execute([$userKey, $pending['provider'], $pending['oauth_id'], $pending['email'] ?: null]);

        // Update avatar if the existing account doesn't have one
        if ($pending['avatar']) {
            $stmt = $db->prepare("SELECT user_avatar FROM yy_user WHERE user_key = ?");
            $stmt->execute([$userKey]);
            $currentAvatar = $stmt->fetchColumn();
            if (!$currentAvatar) {
                $db->prepare("UPDATE yy_user SET user_avatar = ? WHERE user_key = ?")->execute([$pending['avatar'], $userKey]);
            }
        }

        unset($_SESSION['pending_link']);
        jsonResponse(['linked' => true, 'provider' => $pending['provider']]);
    }

    // Create new account from pending OAuth (decline link)
    if ($action === 'create_new') {
        $pending = $_SESSION['pending_link'] ?? null;
        if (!$pending || $pending['expires'] < time()) {
            unset($_SESSION['pending_link']);
            errorResponse('Link request expired. Please try signing in again.');
        }

        // Create new user
        $userCode = $pending['provider'] . ':' . $pending['oauth_id'];
        $stmt = $db->prepare("
            INSERT INTO yy_user (user_code, user_email, user_display_name, user_avatar, user_active_flag, user_verified)
            VALUES (?, ?, ?, ?, TRUE, TRUE) RETURNING user_key
        ");
        // Use a modified email to avoid conflict
        $newEmail = $pending['email'] ? $pending['provider'] . '+' . $pending['email'] : null;
        $stmt->execute([$userCode, $newEmail, $pending['name'], $pending['avatar'] ?: null]);
        $newUserKey = (int)$stmt->fetchColumn();

        // Insert auth method
        $db->prepare("INSERT INTO yy_user_auth (user_key, auth_provider, auth_provider_id, auth_email) VALUES (?, ?, ?, ?)")
            ->execute([$newUserKey, $pending['provider'], $pending['oauth_id'], $pending['email'] ?: null]);

        // Assign public role
        $db->prepare("INSERT INTO yy_user_role (user_key, role_key) VALUES (?, (SELECT role_key FROM yy_role WHERE role_code = 'public'))")
            ->execute([$newUserKey]);

        // Log in as the new user
        $_SESSION['user_key'] = $newUserKey;
        $_SESSION['user_display_name'] = $pending['name'];
        $_SESSION['user_avatar'] = $pending['avatar'];
        unset($_SESSION['pending_link']);

        jsonResponse(['created' => true, 'user_key' => $newUserKey]);
    }

    // Unlink an auth method
    if ($action === 'unlink') {
        if (!$userKey) errorResponse('Login required', 401);

        $authKey = (int)($data['user_auth_key'] ?? 0);
        if (!$authKey) errorResponse('Auth method key required');

        // Verify ownership
        $stmt = $db->prepare("SELECT user_key, auth_provider FROM yy_user_auth WHERE user_auth_key = ? AND auth_active_flag = TRUE");
        $stmt->execute([$authKey]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['user_key'] !== $userKey) errorResponse('Not found or not authorized');

        // Must keep at least one active auth method
        $stmt = $db->prepare("SELECT COUNT(*) FROM yy_user_auth WHERE user_key = ? AND auth_active_flag = TRUE");
        $stmt->execute([$userKey]);
        if ((int)$stmt->fetchColumn() <= 1) {
            errorResponse('Cannot remove your only sign-in method');
        }

        $db->prepare("UPDATE yy_user_auth SET auth_active_flag = FALSE WHERE user_auth_key = ?")->execute([$authKey]);
        jsonResponse(['unlinked' => true, 'provider' => $row['auth_provider']]);
    }

    // Check pending link status (for frontend polling)
    if ($action === 'check_pending') {
        $pending = $_SESSION['pending_link'] ?? null;
        if ($pending && $pending['expires'] > time()) {
            jsonResponse([
                'pending' => true,
                'provider' => $pending['provider'],
                'email' => $pending['email'],
                'name' => $pending['name'],
                'existing_name' => $pending['existing_name'] ?? null,
            ]);
        }
        jsonResponse(['pending' => false]);
    }

    errorResponse('Unknown action');
}

errorResponse('Method not allowed', 405);
