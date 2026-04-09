<?php
/**
 * Community ban/mute API for moderators.
 * POST: ban or mute a user.
 * DELETE: unban/unmute a user.
 * GET: check ban status of a user.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/community-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$modKey = $_SESSION['user_key'] ?? null;

if (!$modKey) errorResponse('Login required', 401);
if (!isModOrAdmin($db, $modKey)) errorResponse('Moderator access required', 403);

$targetKey = (int)($_GET['user'] ?? 0);
if (!$targetKey) errorResponse('User key required');

// Don't allow banning admins
$targetRoles = [];
$stmt = $db->prepare("SELECT r.role_code FROM yy_user_role ur JOIN yy_role r ON r.role_key = ur.role_key WHERE ur.user_key = ?");
$stmt->execute([$targetKey]);
while ($row = $stmt->fetch()) $targetRoles[] = $row['role_code'];
if (in_array('admin', $targetRoles)) errorResponse('Cannot ban an admin');

if ($method === 'GET') {
    $stmt = $db->prepare("SELECT user_banned_flag, user_banned_until, user_ban_reason, user_muted_flag, user_muted_until, user_name_display FROM yy_user WHERE user_key = ?");
    $stmt->execute([$targetKey]);
    $user = $stmt->fetch();
    if (!$user) errorResponse('User not found', 404);
    jsonResponse([
        'banned' => $user['user_banned_flag'] === true || $user['user_banned_flag'] === 't',
        'banned_until' => $user['user_banned_until'],
        'ban_reason' => $user['user_ban_reason'],
        'muted' => $user['user_muted_flag'] === true || $user['user_muted_flag'] === 't',
        'muted_until' => $user['user_muted_until'],
        'display_name' => $user['user_name_display'],
    ]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? 'ban'; // ban or mute
    $reason = trim($data['reason'] ?? '');
    $duration = $data['duration'] ?? null; // null = permanent, or hours

    $until = null;
    if ($duration && is_numeric($duration)) {
        $until = date('Y-m-d H:i:s', time() + (int)$duration * 3600);
    }

    if ($action === 'mute') {
        $db->prepare("UPDATE yy_user SET user_muted_flag = TRUE, user_muted_until = ? WHERE user_key = ?")
            ->execute([$until, $targetKey]);
    } else {
        $db->prepare("UPDATE yy_user SET user_banned_flag = TRUE, user_banned_until = ?, user_ban_reason = ? WHERE user_key = ?")
            ->execute([$until, $reason ?: null, $targetKey]);
    }

    jsonResponse(['saved' => true]);
}

if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? 'unban';

    if ($action === 'unmute') {
        $db->prepare("UPDATE yy_user SET user_muted_flag = FALSE, user_muted_until = NULL WHERE user_key = ?")
            ->execute([$targetKey]);
    } else {
        $db->prepare("UPDATE yy_user SET user_banned_flag = FALSE, user_banned_until = NULL, user_ban_reason = NULL WHERE user_key = ?")
            ->execute([$targetKey]);
    }

    jsonResponse(['saved' => true]);
}

errorResponse('Method not allowed', 405);
