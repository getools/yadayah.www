<?php
require_once __DIR__ . '/config.php';

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

$videoId = trim($_GET['video_id'] ?? '');
$videoSource = trim($_GET['source'] ?? 'youtube');

if (!$videoId) errorResponse('video_id required');

// GET - list comments for a video
if ($method === 'GET') {
    $stmt = $db->prepare("
        SELECT c.comment_key, c.comment_text, c.comment_dtime,
               u.user_key, COALESCE(NULLIF(u.user_display_name,''), u.user_name_full, 'Anonymous') AS user_display_name,
               u.user_avatar
        FROM yy_video_comment c
        JOIN yy_user u ON u.user_key = c.user_key
        WHERE c.video_id = ? AND c.video_source = ? AND c.comment_active_flag = true
        ORDER BY c.comment_dtime ASC
    ");
    $stmt->execute([$videoId, $videoSource]);
    $comments = $stmt->fetchAll();

    // Include current session info so frontend knows if user is signed in
    $session = getCommunitySession();

    jsonResponse([
        'comments' => $comments,
        'user' => $session,
    ]);
}

// POST - add a comment
if ($method === 'POST') {
    $session = requireCommunityAuth();

    $data = json_decode(file_get_contents('php://input'), true);
    $text = trim($data['comment_text'] ?? '');
    if (!$text) errorResponse('Comment text required');
    if (mb_strlen($text) > 2000) errorResponse('Comment too long (max 2000 characters)');

    $stmt = $db->prepare("
        INSERT INTO yy_video_comment (video_id, video_source, user_key, comment_text)
        VALUES (?, ?, ?, ?)
        RETURNING comment_key, comment_dtime
    ");
    $stmt->execute([$videoId, $videoSource, $session['account_key'], $text]);
    $row = $stmt->fetch();

    jsonResponse([
        'comment_key' => $row['comment_key'],
        'comment_dtime' => $row['comment_dtime'],
        'user_display_name' => $session['account_name'],
        'user_avatar' => $session['account_avatar'],
    ]);
}

// DELETE - remove own comment
if ($method === 'DELETE') {
    $session = requireCommunityAuth();

    $data = json_decode(file_get_contents('php://input'), true);
    $commentKey = (int)($data['comment_key'] ?? 0);
    if (!$commentKey) errorResponse('comment_key required');

    // Only allow deleting own comments
    $stmt = $db->prepare("UPDATE yy_video_comment SET comment_active_flag = false WHERE comment_key = ? AND user_key = ?");
    $stmt->execute([$commentKey, $session['account_key']]);

    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
