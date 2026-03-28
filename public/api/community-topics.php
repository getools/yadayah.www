<?php
/**
 * Community discussion board API.
 * GET: list topics or single topic with replies
 * POST: create topic or reply
 * DELETE: soft-delete topic or reply (admin or author only)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/community-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$topicKey = isset($_GET['topic']) ? (int)$_GET['topic'] : null;
$userKey = $_SESSION['user_key'] ?? null;

// Helper: check if user has a role
function userHasRole(PDO $db, int $userKey, string $roleCode): bool {
    $stmt = $db->prepare("SELECT 1 FROM yy_user_role ur JOIN yy_role r ON ur.role_key = r.role_key WHERE ur.user_key = ? AND r.role_code = ?");
    $stmt->execute([$userKey, $roleCode]);
    return (bool)$stmt->fetchColumn();
}

// ── GET ──
if ($method === 'GET') {
    $isMod = $userKey && isModOrAdmin($db, $userKey);

    if ($topicKey) {
        // Single topic with replies — mods can see hidden topics
        $activeFilter = $isMod ? '' : 'AND t.topic_active_flag = TRUE';
        $stmt = $db->prepare("
            SELECT t.*, u.user_display_name, u.user_avatar, u.user_handle,
                   c.category_key AS cat_key, c.category_name, c.category_slug,
                   hider.user_display_name AS hidden_by_name
            FROM yy_community_topic t
            LEFT JOIN yy_user u ON t.user_key = u.user_key
            LEFT JOIN yy_community_category c ON t.category_key = c.category_key
            LEFT JOIN yy_user hider ON t.topic_hidden_by = hider.user_key
            WHERE t.topic_key = ? {$activeFilter}
        ");
        $stmt->execute([$topicKey]);
        $topic = $stmt->fetch();
        if (!$topic) errorResponse('Topic not found', 404);

        // Increment view count
        $db->prepare("UPDATE yy_community_topic SET topic_view_count = topic_view_count + 1 WHERE topic_key = ?")
           ->execute([$topicKey]);

        // Replies — mods can see hidden replies
        $replyActiveFilter = $isMod ? '' : 'AND r.reply_active_flag = TRUE';
        $stmt = $db->prepare("
            SELECT r.*, u.user_display_name, u.user_avatar, u.user_handle,
                   rhider.user_display_name AS hidden_by_name
            FROM yy_community_reply r
            LEFT JOIN yy_user u ON r.user_key = u.user_key
            LEFT JOIN yy_user rhider ON r.reply_hidden_by = rhider.user_key
            WHERE r.topic_key = ? {$replyActiveFilter}
            ORDER BY r.reply_dtime
        ");
        $stmt->execute([$topicKey]);
        $replies = $stmt->fetchAll();

        // Current user status for this topic
        $userStatus = null;
        if ($userKey) {
            $liked = $db->prepare("SELECT 1 FROM yy_community_like WHERE user_key = ? AND target_type = 'topic' AND target_key = ?");
            $liked->execute([$userKey, $topicKey]);

            $bookmarked = $db->prepare("SELECT 1 FROM yy_community_bookmark WHERE user_key = ? AND topic_key = ?");
            $bookmarked->execute([$userKey, $topicKey]);

            $watching = $db->prepare("SELECT 1 FROM yy_community_watch WHERE user_key = ? AND topic_key = ?");
            $watching->execute([$userKey, $topicKey]);

            $userStatus = [
                'liked' => (bool)$liked->fetchColumn(),
                'bookmarked' => (bool)$bookmarked->fetchColumn(),
                'watching' => (bool)$watching->fetchColumn(),
            ];

            // Also check which replies user has liked
            $stmt = $db->prepare("SELECT target_key FROM yy_community_like WHERE user_key = ? AND target_type = 'reply' AND target_key = ANY(?)");
            $replyKeys = array_column($replies, 'reply_key');
            if ($replyKeys) {
                $stmt = $db->prepare("
                    SELECT target_key FROM yy_community_like
                    WHERE user_key = ? AND target_type = 'reply' AND target_key IN (" . implode(',', array_map('intval', $replyKeys)) . ")
                ");
                $stmt->execute([$userKey]);
                $userStatus['liked_replies'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $userStatus['liked_replies'] = [];
            }
        }

        jsonResponse([
            'topic' => $topic,
            'replies' => $replies,
            'user_status' => $userStatus,
        ]);
    }

    // List topics
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $categorySlug = trim($_GET['category'] ?? '');

    $where = $isMod ? "WHERE 1=1" : "WHERE t.topic_active_flag = TRUE";
    $joins = "LEFT JOIN yy_user u ON t.user_key = u.user_key LEFT JOIN yy_community_category c ON t.category_key = c.category_key";
    $params = [];

    if ($categorySlug) {
        $where .= " AND c.category_slug = ?";
        $params[] = $categorySlug;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM yy_community_topic t {$joins} {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listParams = array_merge($params, [$limit, $offset]);
    $stmt = $db->prepare("
        SELECT t.topic_key, t.topic_title, t.topic_pinned, t.topic_locked,
               t.topic_active_flag, t.topic_hide_reason,
               t.topic_reply_count, t.topic_view_count, t.topic_like_count,
               t.topic_last_reply_dtime, t.topic_dtime,
               u.user_display_name, u.user_avatar,
               c.category_name, c.category_slug
        FROM yy_community_topic t
        {$joins}
        {$where}
        ORDER BY t.topic_pinned DESC, COALESCE(t.topic_last_reply_dtime, t.topic_dtime) DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($listParams);

    jsonResponse([
        'topics' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'pages' => (int)ceil($total / $limit),
        'category' => $categorySlug ?: null,
    ]);
}

// ── POST ── (requires login + verified)
if ($method === 'POST') {
    if (!$userKey) errorResponse('Login required', 401);

    // Check banned/muted
    checkBanned($db, $userKey);

    // Check verified
    $vstmt = $db->prepare("SELECT user_verified FROM yy_user WHERE user_key = ?");
    $vstmt->execute([$userKey]);
    $verified = $vstmt->fetchColumn();
    if ($verified === false || $verified === 'f') errorResponse('Please verify your email before posting. Check your inbox or resend from your profile.');

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? 'create_topic';

    if ($action === 'create_topic') {
        $title = trim($data['title'] ?? '');
        $body = trim($data['body'] ?? '');
        $bodyHtml = isset($data['topic_body_html']) ? sanitizeHtml($data['topic_body_html']) : null;
        $categoryKey = (int)($data['category_key'] ?? 0) ?: null;

        // Resolve category slug to key if provided
        if (!$categoryKey && !empty($data['category'])) {
            $stmt = $db->prepare("SELECT category_key FROM yy_community_category WHERE category_slug = ? AND category_active_flag = TRUE");
            $stmt->execute([trim($data['category'])]);
            $categoryKey = (int)$stmt->fetchColumn() ?: null;
        }

        if (!$title) errorResponse('Title is required');

        // Check word filter
        checkWordFilter($db, $title . ' ' . $body . ' ' . ($bodyHtml ?? ''));

        // Validate category if provided
        if ($categoryKey) {
            $stmt = $db->prepare("SELECT 1 FROM yy_community_category WHERE category_key = ? AND category_active_flag = TRUE");
            $stmt->execute([$categoryKey]);
            if (!$stmt->fetchColumn()) errorResponse('Invalid category');
        }

        $stmt = $db->prepare("
            INSERT INTO yy_community_topic (topic_title, topic_body, topic_body_html, category_key, user_key)
            VALUES (?, ?, ?, ?, ?)
            RETURNING topic_key
        ");
        $stmt->execute([$title, $body, $bodyHtml, $categoryKey, $userKey]);
        $newTopicKey = $stmt->fetchColumn();

        // Auto-watch
        $db->prepare("INSERT INTO yy_community_watch (user_key, topic_key) VALUES (?, ?) ON CONFLICT DO NOTHING")
           ->execute([$userKey, $newTopicKey]);

        // Update user topic count
        $db->prepare("UPDATE yy_user SET user_topic_count = user_topic_count + 1 WHERE user_key = ?")
           ->execute([$userKey]);

        // Detect @mentions
        $mentionText = $body ?: strip_tags($bodyHtml ?? '');
        detectMentions($db, $mentionText, $userKey, 'topic', $newTopicKey, $newTopicKey);

        jsonResponse(['saved' => true, 'topic_key' => $newTopicKey]);
    }

    if ($action === 'reply') {
        $tk = (int)($data['topic_key'] ?? 0);
        $body = trim($data['body'] ?? '');
        $bodyHtml = isset($data['reply_body_html']) ? sanitizeHtml($data['reply_body_html']) : (isset($data['topic_body_html']) ? sanitizeHtml($data['topic_body_html']) : null);

        if (!$tk) errorResponse('topic_key is required');
        if (!$body && !$bodyHtml) errorResponse('body is required');

        // Check word filter
        checkWordFilter($db, $body . ' ' . ($bodyHtml ?? ''));

        // Check topic exists and isn't locked
        $stmt = $db->prepare("SELECT topic_locked, user_key AS topic_author_key FROM yy_community_topic WHERE topic_key = ? AND topic_active_flag = TRUE");
        $stmt->execute([$tk]);
        $topic = $stmt->fetch();
        if (!$topic) errorResponse('Topic not found', 404);
        if ($topic['topic_locked']) errorResponse('Topic is locked');

        $stmt = $db->prepare("
            INSERT INTO yy_community_reply (topic_key, reply_body, reply_body_html, user_key)
            VALUES (?, ?, ?, ?)
            RETURNING reply_key
        ");
        $stmt->execute([$tk, $body, $bodyHtml, $userKey]);
        $replyKey = $stmt->fetchColumn();

        // Update topic reply count and last reply time
        $db->prepare("UPDATE yy_community_topic SET topic_reply_count = topic_reply_count + 1, topic_last_reply_dtime = NOW() WHERE topic_key = ?")
           ->execute([$tk]);

        // Auto-watch
        $db->prepare("INSERT INTO yy_community_watch (user_key, topic_key) VALUES (?, ?) ON CONFLICT DO NOTHING")
           ->execute([$userKey, $tk]);

        // Update user reply count
        $db->prepare("UPDATE yy_user SET user_reply_count = user_reply_count + 1 WHERE user_key = ?")
           ->execute([$userKey]);

        // Detect @mentions
        $mentionText = $body ?: strip_tags($bodyHtml ?? '');
        detectMentions($db, $mentionText, $userKey, 'reply', $replyKey, $tk);

        // Notify topic author if different from replier
        $topicAuthorKey = (int)$topic['topic_author_key'];
        if ($topicAuthorKey !== $userKey) {
            require_once __DIR__ . '/community-helpers.php';
            notifyUser($db, $topicAuthorKey, $userKey, 'reply', 'topic', $tk, $tk, 'replied to your topic');
        }

        // Email watchers (except the replier)
        $stmt = $db->prepare("SELECT user_key FROM yy_community_watch WHERE topic_key = ? AND user_key != ?");
        $stmt->execute([$tk, $userKey]);
        $watchers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get replier display name for email
        $nameStmt = $db->prepare("SELECT user_display_name FROM yy_user WHERE user_key = ?");
        $nameStmt->execute([$userKey]);
        $replierName = $nameStmt->fetchColumn() ?: 'Someone';

        // Get topic title for email
        $titleStmt = $db->prepare("SELECT topic_title FROM yy_community_topic WHERE topic_key = ?");
        $titleStmt->execute([$tk]);
        $topicTitle = $titleStmt->fetchColumn();

        foreach ($watchers as $watcherKey) {
            // Create notification (if not already the topic author who got one)
            if ((int)$watcherKey !== $topicAuthorKey) {
                notifyUser($db, (int)$watcherKey, $userKey, 'watch_reply', 'topic', $tk, $tk, 'New reply in a topic you\'re watching');
            }
            // Send email
            $emailBody = '<h2 style="color:#31345A;">New Reply</h2>'
                . '<p><strong>' . htmlspecialchars($replierName) . '</strong> replied to <strong>' . htmlspecialchars($topicTitle) . '</strong></p>'
                . '<p>' . htmlspecialchars(mb_substr($body ?: strip_tags($bodyHtml ?? ''), 0, 200)) . '</p>'
                . '<p><a href="https://yadayah.com/community#topic/' . $tk . '" style="color:#31345A;font-weight:600;">View Topic</a></p>';
            sendNotificationEmail($db, (int)$watcherKey, 'New reply: ' . mb_substr($topicTitle, 0, 60), $emailBody);
        }

        jsonResponse(['saved' => true, 'reply_key' => $replyKey]);
    }

    errorResponse('Unknown action');
}

// ── PATCH ── restore hidden topic/reply (moderator/admin only)
if ($method === 'PATCH') {
    if (!$userKey) errorResponse('Login required', 401);
    if (!isModOrAdmin($db, $userKey)) errorResponse('Moderator access required', 403);

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $type = $body['type'] ?? 'topic';

    if ($type === 'reply' && !empty($body['reply_key'])) {
        $rk = (int)$body['reply_key'];
        $db->prepare("UPDATE yy_community_reply SET reply_active_flag = TRUE, reply_hide_reason = NULL, reply_hidden_by = NULL, reply_delete_dtime = NULL, reply_delete_note = NULL WHERE reply_key = ?")
            ->execute([$rk]);
        // Re-increment reply count
        $stmt = $db->prepare("SELECT topic_key FROM yy_community_reply WHERE reply_key = ?");
        $stmt->execute([$rk]);
        $tk = $stmt->fetchColumn();
        if ($tk) {
            $db->prepare("UPDATE yy_community_topic SET topic_reply_count = topic_reply_count + 1 WHERE topic_key = ?")->execute([$tk]);
        }
        jsonResponse(['restored' => true]);
    }

    if ($type === 'topic' && $topicKey) {
        $db->prepare("UPDATE yy_community_topic SET topic_active_flag = TRUE, topic_hide_reason = NULL, topic_hidden_by = NULL, topic_delete_dtime = NULL, topic_delete_note = NULL WHERE topic_key = ?")
            ->execute([$topicKey]);
        jsonResponse(['restored' => true]);
    }

    errorResponse('Invalid restore request');
}

// ── DELETE ── (admin, moderator, or author)
if ($method === 'DELETE') {
    if (!$userKey) errorResponse('Login required', 401);

    $isMod = isModOrAdmin($db, $userKey);
    $type = $_GET['type'] ?? 'topic';

    // Accept reason from query string or request body (supports both 'reason' and 'note' keys)
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $reason = $_GET['reason'] ?? $body['reason'] ?? $body['note'] ?? null;

    if ($type === 'topic' && $topicKey) {
        if (!$isMod) {
            $stmt = $db->prepare("SELECT user_key FROM yy_community_topic WHERE topic_key = ?");
            $stmt->execute([$topicKey]);
            if ((int)$stmt->fetchColumn() !== $userKey) errorResponse('Not authorized', 403);
        }
        $db->prepare("UPDATE yy_community_topic SET topic_active_flag = FALSE, topic_hide_reason = ?, topic_hidden_by = ?, topic_delete_dtime = NOW(), topic_delete_note = ? WHERE topic_key = ?")
            ->execute([$reason, $isMod ? $userKey : null, $reason, $topicKey]);
        jsonResponse(['deleted' => true]);
    }

    $replyKey = (int)($_GET['reply'] ?? 0);
    if ($type === 'reply' && $replyKey) {
        if (!$isMod) {
            $stmt = $db->prepare("SELECT user_key FROM yy_community_reply WHERE reply_key = ?");
            $stmt->execute([$replyKey]);
            if ((int)$stmt->fetchColumn() !== $userKey) errorResponse('Not authorized', 403);
        }
        $db->prepare("UPDATE yy_community_reply SET reply_active_flag = FALSE, reply_hide_reason = ?, reply_hidden_by = ?, reply_delete_dtime = NOW(), reply_delete_note = ? WHERE reply_key = ?")
            ->execute([$reason, $isMod ? $userKey : null, $reason, $replyKey]);
        // Decrement reply count
        $stmt = $db->prepare("SELECT topic_key FROM yy_community_reply WHERE reply_key = ?");
        $stmt->execute([$replyKey]);
        $tk = $stmt->fetchColumn();
        if ($tk) {
            $db->prepare("UPDATE yy_community_topic SET topic_reply_count = GREATEST(0, topic_reply_count - 1) WHERE topic_key = ?")->execute([$tk]);
        }
        jsonResponse(['deleted' => true]);
    }

    errorResponse('Invalid delete request');
}

errorResponse('Method not allowed', 405);
