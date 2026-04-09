<?php
require_once __DIR__ . '/config.php';

$db = getDb();

// POST: toggle like
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account = requireCommunityAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $postKey = (int)($input['post_key'] ?? 0);
    $likeType = $input['like_type_code'] ?? 'like';

    if (!$postKey) errorResponse('post_key required');

    // Check if already liked
    $check = $db->prepare("SELECT like_key FROM yy_community_like WHERE post_key = ? AND account_key = ?");
    $check->execute([$postKey, $account['account_key']]);
    $existing = $check->fetch();

    if ($existing) {
        // Unlike
        $db->prepare("DELETE FROM yy_community_like WHERE like_key = ?")->execute([$existing['like_key']]);
        $db->prepare("UPDATE yy_community_post SET post_like_count = GREATEST(0, post_like_count - 1) WHERE post_key = ?")->execute([$postKey]);
        $liked = false;
    } else {
        // Like
        $db->prepare("INSERT INTO yy_community_like (post_key, account_key, like_type_code) VALUES (?, ?, ?)")
            ->execute([$postKey, $account['account_key'], $likeType]);
        $db->prepare("UPDATE yy_community_post SET post_like_count = post_like_count + 1 WHERE post_key = ?")->execute([$postKey]);
        $liked = true;
    }

    // Get updated count
    $countStmt = $db->prepare("SELECT post_like_count FROM yy_community_post WHERE post_key = ?");
    $countStmt->execute([$postKey]);
    $count = (int)$countStmt->fetchColumn();

    jsonResponse(['liked' => $liked, 'like_count' => $count]);
}

errorResponse('Method not allowed', 405);
