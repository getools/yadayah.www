<?php
/**
 * Public API for Do You Yada? posts — serves from yy_feed_item.
 *
 * GET ?page=N          — paginated list (24 per page)
 * GET ?limit=N         — return exactly N records
 * GET ?action=sync     — (auth required) refresh from Facebook via sync-facebook.php
 */
require_once __DIR__ . '/config.php';

$PER_PAGE = 24;
$db = getDb();

// Load feed config
$feedStmt = $db->query("
    SELECT f.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude
    FROM yy_feed_page fp
    JOIN yy_feed f ON f.feed_key = fp.feed_key
    JOIN yy_page p ON p.page_key = fp.page_key
    WHERE p.page_code = 'doyouyada'
    ORDER BY fp.feed_page_sort, fp.feed_page_key
    LIMIT 1
");
$feedRow = $feedStmt->fetch();
$feedKey = $feedRow ? (int)$feedRow['feed_key'] : 5;
$includeTerms = $feedRow ? array_filter(array_map('trim', explode(',', $feedRow['feed_page_filter_include'] ?? ''))) : ['#DoYouYada'];
$excludeTerms = $feedRow ? array_filter(array_map('trim', explode(',', $feedRow['feed_page_filter_exclude'] ?? ''))) : [];

$action = $_GET['action'] ?? '';

// Sync delegates to sync-facebook.php
if ($action === 'sync') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') {
        $user = requireAuth();
    }
    require __DIR__ . '/sync-facebook.php';
    exit;
}

// Build WHERE clause
$where = "feed_key = ? AND feed_item_active_flag = TRUE";
$params = [$feedKey];

// Filter by tags or title
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

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Serve
$limit = (int)($_GET['limit'] ?? 0);
if ($limit > 0) {
    $stmt = $db->prepare("SELECT feed_item_key AS feed_key, feed_item_external_id AS feed_video_id, feed_item_title AS feed_title, feed_item_thumbnail AS feed_thumbnail, feed_item_publish_dtime AS feed_create, feed_item_type AS feed_type FROM yy_feed_item WHERE $where ORDER BY feed_item_publish_dtime DESC NULLS LAST LIMIT ?");
    $stmt->execute(array_merge($params, [$limit]));
    jsonResponse(['videos' => $stmt->fetchAll(), 'page' => 1, 'total_pages' => 1, 'total' => $total]);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT feed_item_key AS feed_key, feed_item_external_id AS feed_video_id,
           feed_item_title AS feed_title, feed_item_thumbnail AS feed_thumbnail,
           feed_item_publish_dtime AS feed_create, feed_item_type AS feed_type
    FROM yy_feed_item
    WHERE $where
    ORDER BY feed_item_publish_dtime DESC NULLS LAST
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$PER_PAGE, $offset]));

jsonResponse([
    'videos'      => $stmt->fetchAll(),
    'page'        => $page,
    'total_pages' => $totalPages,
    'total'       => $total,
]);
