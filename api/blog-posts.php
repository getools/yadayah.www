<?php
/**
 * Public API for Blog posts — serves from yy_feed_item.
 *
 * GET ?page=N — paginated list (25 per page)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';

$db = getDb();

// Load feed config for per_page setting
$feedStmt = $db->query("
    SELECT fp.feed_page_per_page
    FROM yy_feed_page fp
    JOIN yy_page p ON p.page_key = fp.page_key
    WHERE p.page_code = 'blog'
    ORDER BY fp.feed_page_sort, fp.feed_page_key
    LIMIT 1
");
$feedRow = $feedStmt->fetch();
$perPage = $feedRow && (int)$feedRow['feed_page_per_page'] > 0 ? (int)$feedRow['feed_page_per_page'] : 25;

// Build WHERE clause using join table
$pageKey = getPageKey($db, 'blog');
$where = "fi.feed_item_active_flag = TRUE AND fip.page_key = ?";
$params = [$pageKey];

$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item fi JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT fi.feed_item_external_id AS blog_post_id,
           TRIM(BOTH '~ -' FROM TRIM(REGEXP_REPLACE(COALESCE(fi.feed_item_title_override, fi.feed_item_title_import), '#\w+\s*', '', 'g'))) AS blog_message,
           fi.feed_item_thumbnail AS blog_image,
           fi.feed_item_type AS blog_type_code,
           COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS blog_create_dtime,
           fi.feed_item_embed_id AS blog_video_id
    FROM yy_feed_item fi
    JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key
    WHERE $where
    ORDER BY COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) DESC NULLS LAST
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$posts = $stmt->fetchAll();

// Prepend path to local images
foreach ($posts as &$p) {
    if ($p['blog_image'] && strpos($p['blog_image'], 'http') !== 0 && strpos($p['blog_image'], '/') !== 0) {
        $p['blog_image'] = '/' . $p['blog_image'];
    }
}
unset($p);

// Fetch comment counts from community topics
$postIds = array_column($posts, 'blog_post_id');
$commentCounts = [];
if ($postIds) {
    $in = implode(',', array_fill(0, count($postIds), '?'));
    $cStmt = $db->prepare("SELECT video_id, topic_reply_count FROM yy_community_topic WHERE page_code = 'blog' AND video_id IN ($in) AND topic_active_flag = TRUE");
    $cStmt->execute($postIds);
    foreach ($cStmt->fetchAll() as $row) {
        $commentCounts[$row['video_id']] = (int)$row['topic_reply_count'];
    }
}
foreach ($posts as &$p) {
    $p['comment_count'] = $commentCounts[$p['blog_post_id']] ?? 0;
}
unset($p);

jsonResponse([
    'posts'       => $posts,
    'page'        => $page,
    'total_pages' => $totalPages,
    'total'       => $total,
]);
