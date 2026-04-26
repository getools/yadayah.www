<?php
/**
 * Public API for Blog posts — serves from yy_feed_item.
 *
 * GET ?page=N — paginated list (25 per page)
 */
require_once __DIR__ . '/config.php';

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
$includeTerms = $feedRow ? array_filter(array_map('trim', explode(',', $feedRow['feed_page_filter_include'] ?? ''))) : [];
$excludeTerms = $feedRow ? array_filter(array_map('trim', explode(',', $feedRow['feed_page_filter_exclude'] ?? ''))) : [];

// Build WHERE clause
$where = "feed_key = ? AND feed_item_active_flag = TRUE";
$params = [$feedKey];

if ($includeTerms) {
    $incClauses = [];
    foreach ($includeTerms as $term) {
        $incClauses[] = "(feed_item_tags ILIKE ? OR feed_item_title ILIKE ?)";
        $params[] = '%' . $term . '%';
        $params[] = '%' . $term . '%';
    }
    $where .= " AND (" . implode(' OR ', $incClauses) . ")";
}
if ($excludeTerms) {
    foreach ($excludeTerms as $term) {
        $where .= " AND feed_item_tags NOT ILIKE ? AND feed_item_title NOT ILIKE ?";
        $params[] = '%' . $term . '%';
        $params[] = '%' . $term . '%';
    }
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT feed_item_external_id AS blog_post_id,
           feed_item_title AS blog_message,
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

jsonResponse([
    'posts'       => $posts,
    'page'        => $page,
    'total_pages' => $totalPages,
    'total'       => $total,
]);
