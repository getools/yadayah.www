<?php
require_once __DIR__ . '/config.php';

$db = getDb();

// GET: comments for a post
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $postKey = (int)($_GET['post_key'] ?? 0);
    if (!$postKey) errorResponse('post_key required');

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $countStmt = $db->prepare("SELECT COUNT(*) FROM yy_community_comment WHERE post_key = ? AND comment_active_flag = TRUE");
    $countStmt->execute([$postKey]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT c.*, a.account_name, a.account_avatar
        FROM yy_community_comment c
        JOIN yy_account a ON a.account_key = c.account_key
        WHERE c.post_key = ? AND c.comment_active_flag = TRUE
        ORDER BY c.comment_dtime ASC
        LIMIT ? OFFSET ?");
    $stmt->execute([$postKey, $perPage, $offset]);

    jsonResponse([
        'comments' => $stmt->fetchAll(),
        'page' => $page,
        'total_pages' => max(1, ceil($total / $perPage)),
        'total' => $total,
    ]);
}

// POST: add comment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account = requireCommunityAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $postKey = (int)($input['post_key'] ?? 0);
    $text = trim($input['comment_text'] ?? '');

    if (!$postKey || !$text) errorResponse('post_key and comment_text are required');

    $stmt = $db->prepare("INSERT INTO yy_community_comment (post_key, account_key, comment_text) VALUES (?, ?, ?) RETURNING comment_key");
    $stmt->execute([$postKey, $account['account_key'], $text]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Increment denormalized count
    $db->prepare("UPDATE yy_community_post SET post_comment_count = post_comment_count + 1 WHERE post_key = ?")->execute([$postKey]);

    jsonResponse(['saved' => true, 'comment_key' => $row['comment_key']]);
}

// DELETE: soft-delete own comment
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $account = requireCommunityAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $commentKey = (int)($input['comment_key'] ?? 0);
    if (!$commentKey) errorResponse('comment_key required');

    $check = $db->prepare("SELECT account_key, post_key FROM yy_community_comment WHERE comment_key = ?");
    $check->execute([$commentKey]);
    $comment = $check->fetch();
    if (!$comment || (int)$comment['account_key'] !== $account['account_key']) {
        errorResponse('You can only delete your own comments');
    }

    $db->prepare("UPDATE yy_community_comment SET comment_active_flag = FALSE WHERE comment_key = ?")->execute([$commentKey]);
    $db->prepare("UPDATE yy_community_post SET post_comment_count = GREATEST(0, post_comment_count - 1) WHERE post_key = ?")->execute([$comment['post_key']]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
