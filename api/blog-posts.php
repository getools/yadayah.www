<?php
/**
 * Public API for Blog posts — serves from yy_feed_item.
 *
 * GET ?page=N — paginated list (25 per page)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';

$db = getDb();

// Load feed config
$feedStmt = $db->query("
    SELECT f.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude, fp.feed_page_per_page, fp.feed_page_listing_type
    FROM yy_feed_page fp
    JOIN yy_feed f ON f.feed_key = fp.feed_key
    JOIN yy_page p ON p.page_key = fp.page_key
    WHERE p.page_code = 'blog'
    ORDER BY fp.feed_page_sort, fp.feed_page_key
    LIMIT 1
");
$feedRow = $feedStmt->fetch();
$feedKey = $feedRow ? (int)$feedRow['feed_key'] : 5;
$perPage = $feedRow && (int)$feedRow['feed_page_per_page'] > 0 ? (int)$feedRow['feed_page_per_page'] : 25;

// Build WHERE clause
$where = "feed_key = ? AND feed_item_active_flag = TRUE";
$params = [$feedKey];

buildFeedPageFilters($where, $params, $feedRow['feed_page_filter_include'] ?? '', $feedRow['feed_page_filter_exclude'] ?? '', $feedRow['feed_page_filter_orientation'] ?? null);

$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT feed_item_external_id AS blog_post_id,
           TRIM(BOTH '~ -' FROM TRIM(REGEXP_REPLACE(feed_item_title, '#\w+\s*', '', 'g'))) AS blog_message,
           feed_item_thumbnail AS blog_image,
           feed_item_type AS blog_type_code,
           feed_item_publish_dtime AS blog_create_dtime,
           feed_item_embed_id AS blog_video_id
    FROM yy_feed_item
    WHERE $where
    ORDER BY feed_item_publish_dtime DESC NULLS LAST
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
