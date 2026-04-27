<?php
/**
 * Community notifications API.
 * GET: list notifications for current user (with unread count).
 * POST: mark_read (single) or mark_all_read.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 30;
    $offset = ($page - 1) * $limit;

    // Unread count
    $stmt = $db->prepare("SELECT COUNT(*) FROM yy_community_notification WHERE user_key = ? AND read_flag = FALSE");
    $stmt->execute([$userKey]);
    $unreadCount = (int)$stmt->fetchColumn();

    // Notifications list
    $stmt = $db->prepare("
        SELECT n.notification_key, n.notification_type, n.target_type, n.target_key,
               n.topic_key, n.notification_text, n.read_flag, n.notification_dtime,
               u.user_name_display AS actor_name, u.user_avatar AS actor_avatar
        FROM yy_community_notification n
        LEFT JOIN yy_user u ON n.actor_key = u.user_key
        WHERE n.user_key = ?
        ORDER BY n.notification_dtime DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userKey, $limit, $offset]);

    jsonResponse([
        'notifications' => $stmt->fetchAll(),
        'unread_count' => $unreadCount,
    ]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';

    if ($action === 'mark_read') {
        $nk = (int)($data['notification_key'] ?? 0);
        if (!$nk) errorResponse('notification_key is required');
        $db->prepare("UPDATE yy_community_notification SET read_flag = TRUE WHERE notification_key = ? AND user_key = ?")
           ->execute([$nk, $userKey]);
        jsonResponse(['success' => true]);
    }

    if ($action === 'mark_all_read') {
        $db->prepare("UPDATE yy_community_notification SET read_flag = TRUE WHERE user_key = ? AND read_flag = FALSE")
           ->execute([$userKey]);
        jsonResponse(['success' => true]);
    }

    errorResponse('Unknown action');
}

errorResponse('Method not allowed', 405);
