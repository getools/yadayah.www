<?php
/**
 * Community bookmarks API.
 * GET: list bookmarked topics for current user.
 * POST: toggle bookmark on a topic.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM yy_community_bookmark b
        JOIN yy_community_topic t ON b.topic_key = t.topic_key AND t.topic_active_flag = TRUE
        WHERE b.user_key = ?
    ");
    $countStmt->execute([$userKey]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT t.topic_key, t.topic_title, t.topic_reply_count, t.topic_last_reply_dtime, t.topic_dtime,
               u.user_name_display, u.user_avatar,
               c.category_name, c.category_slug,
               b.bookmark_dtime
        FROM yy_community_bookmark b
        JOIN yy_community_topic t ON b.topic_key = t.topic_key AND t.topic_active_flag = TRUE
        LEFT JOIN yy_user u ON t.user_key = u.user_key
        LEFT JOIN yy_community_category c ON t.category_key = c.category_key
        WHERE b.user_key = ?
        ORDER BY b.bookmark_dtime DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userKey, $limit, $offset]);

    jsonResponse([
        'bookmarks' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'pages' => (int)ceil($total / $limit),
    ]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $topicKey = (int)($data['topic_key'] ?? 0);
    if (!$topicKey) errorResponse('topic_key is required');

    // Check if already bookmarked
    $stmt = $db->prepare("SELECT bookmark_key FROM yy_community_bookmark WHERE user_key = ? AND topic_key = ?");
    $stmt->execute([$userKey, $topicKey]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $db->prepare("DELETE FROM yy_community_bookmark WHERE bookmark_key = ?")->execute([$existing]);
        jsonResponse(['bookmarked' => false]);
    } else {
        $db->prepare("INSERT INTO yy_community_bookmark (user_key, topic_key) VALUES (?, ?)")->execute([$userKey, $topicKey]);
        jsonResponse(['bookmarked' => true]);
    }
}

errorResponse('Method not allowed', 405);
