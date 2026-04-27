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
// Support both user_key (legacy) and account_key (community auth)
$userKey = $_SESSION['user_key'] ?? $_SESSION['account_key'] ?? null;
$userKey = $userKey ? (int)$userKey : null;

// Helper: check if user has a role
function userHasRole(PDO $db, int $userKey, string $roleCode): bool {
    $stmt = $db->prepare("SELECT 1 FROM yy_user_role ur JOIN yy_role r ON ur.role_key = r.role_key WHERE ur.user_key = ? AND r.role_code = ?");
    $stmt->execute([$userKey, $roleCode]);
    return (bool)$stmt->fetchColumn();
}

// ── GET ──
if ($method === 'GET') {
    $showDeleted = false;
    if ($topicKey) {
        // Single topic with replies
        $isMod = $userKey ? isModOrAdmin($db, $userKey) : false;
        $showDeleted = $isMod && !empty($_GET['show_deleted']);

        // First check if topic exists at all (regardless of active/deleted state)
        $existsStmt = $db->prepare("SELECT topic_key, topic_active_flag, topic_delete_dtime FROM yy_community_topic WHERE topic_key = ?");
        $existsStmt->execute([$topicKey]);
        $existsRow = $existsStmt->fetch();

        if (!$existsRow) {
            // Truly does not exist
            errorResponse('Topic not found', 404);
        }

        // Topic exists but is deleted/inactive - give appropriate response
        if (!$isMod && (!$existsRow['topic_active_flag'] || $existsRow['topic_delete_dtime'] !== null)) {
            if ($existsRow['topic_delete_dtime'] !== null) {
                errorResponse('This topic has been removed', 410);
            } else {
                // Inactive but not deleted - may need auth
                if (!$userKey) {
                    errorResponse('Sign in required to view this topic', 401);
                }
                errorResponse('This topic is not available', 410);
            }
        }

        $topicWhere = $isMod
            ? ($showDeleted ? "WHERE t.topic_key = ?" : "WHERE t.topic_key = ? AND t.topic_active_flag = TRUE AND t.topic_delete_dtime IS NULL")
            : "WHERE t.topic_key = ? AND t.topic_active_flag = TRUE AND t.topic_delete_dtime IS NULL";
        $stmt = $db->prepare("
            SELECT t.*, u.user_display_name AS user_name_display, u.user_avatar, u.user_handle,
                   c.category_key AS cat_key, c.category_name, c.category_slug
            FROM yy_community_topic t
            LEFT JOIN yy_user u ON t.user_key = u.user_key
            LEFT JOIN yy_community_category c ON t.category_key = c.category_key
            {$topicWhere}
        ");
        $stmt->execute([$topicKey]);
        $topic = $stmt->fetch();
        if (!$topic) errorResponse('Topic not found', 404);

        // Increment view count
        $db->prepare("UPDATE yy_community_topic SET topic_view_count = topic_view_count + 1 WHERE topic_key = ?")
           ->execute([$topicKey]);

        // Track user view
        if ($userKey) {
            $db->prepare("INSERT INTO yy_community_view (user_key, topic_key) VALUES (?, ?) ON CONFLICT (user_key, topic_key) DO UPDATE SET view_dtime = NOW()")
               ->execute([$userKey, $topicKey]);
        }

        // Replies — mods see removed posts too
        $replyWhere = $showDeleted
            ? "WHERE r.topic_key = ?"
            : "WHERE r.topic_key = ? AND r.reply_active_flag = TRUE AND r.reply_delete_dtime IS NULL";
        $stmt = $db->prepare("
            SELECT r.*, u.user_display_name AS user_name_display, u.user_avatar, u.user_handle
            FROM yy_community_reply r
            LEFT JOIN yy_user u ON r.user_key = u.user_key
            {$replyWhere}
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
            'is_mod' => $isMod,
        ]);
    }

    // List topics
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $categorySlug = trim($_GET['category'] ?? '');

    $isMod = $userKey ? isModOrAdmin($db, $userKey) : false;
    $parentSlug = trim($_GET['parent_slug'] ?? '');

    $showDeleted = $isMod && !empty($_GET['show_deleted']);
    $where = $showDeleted
        ? "WHERE 1=1"
        : "WHERE t.topic_active_flag = TRUE AND t.topic_delete_dtime IS NULL";
    $joins = "LEFT JOIN yy_user u ON t.user_key = u.user_key LEFT JOIN yy_community_category c ON t.category_key = c.category_key";
    $params = [];

    // Scope to categories under a parent
    if ($parentSlug) {
        $where .= " AND t.category_key IN (SELECT category_key FROM yy_community_category WHERE parent_key = (SELECT category_key FROM yy_community_category WHERE category_slug = ? LIMIT 1))";
        $params[] = $parentSlug;
    } else {
        // Default: topics section (exclude comment categories)
        $where .= " AND (t.category_key IS NULL OR t.category_key IN (SELECT category_key FROM yy_community_category WHERE parent_key = (SELECT category_key FROM yy_community_category WHERE category_slug = 'topics' LIMIT 1)))";
    }

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
               t.topic_reply_count, t.topic_view_count, t.topic_like_count,
               t.topic_like_count + COALESCE((SELECT SUM(r.reply_like_count) FROM yy_community_reply r WHERE r.topic_key = t.topic_key AND r.reply_active_flag = TRUE AND r.reply_delete_dtime IS NULL), 0) AS total_like_count,
               t.topic_last_reply_dtime, t.topic_dtime,
               t.topic_delete_dtime, t.topic_active_flag,
               u.user_display_name AS user_name_display, u.user_avatar,
               u.user_key, u.user_last_active_dtime,
               c.category_name, c.category_slug,
               t.video_id, t.topic_thumbnail
        FROM yy_community_topic t
        {$joins}
        {$where}
        ORDER BY t.topic_pinned DESC, COALESCE(t.topic_last_reply_dtime, t.topic_dtime) DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($listParams);
    $topics = $stmt->fetchAll();

    // Populate user_liked and user_replied per topic for the current user
    $likedTopicSet = [];
    if ($userKey && $topics) {
        $topicKeys = array_map('intval', array_column($topics, 'topic_key'));
        $in = implode(',', $topicKeys);

        // User liked the topic itself
        $likeStmt = $db->prepare("
            SELECT target_key FROM yy_community_like
            WHERE user_key = ? AND target_type = 'topic'
              AND target_key IN ({$in})
        ");
        $likeStmt->execute([$userKey]);
        $likedTopicSet = array_flip(array_map('intval', $likeStmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    // Mark liked topics
    foreach ($topics as &$t) {
        $t['user_liked'] = isset($likedTopicSet[(int)$t['topic_key']]);
    }
    unset($t);

    jsonResponse([
        'topics' => $topics,
        'total' => $total,
        'page' => $page,
        'pages' => (int)ceil($total / $limit),
    ]);
}
