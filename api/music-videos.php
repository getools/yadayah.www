<?php
/**
 * Public API for Music videos — serves from yy_feed_item.
 *
 * GET ?page=N          — paginated list (24 per page)
 * GET ?limit=N         — return exactly N records
 * GET ?action=sync     — (auth required) refresh via sync-youtube.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';

$PER_PAGE = 24;
$db = getDb();

// Load feed config
$feedStmt = $db->query("
    SELECT f.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude
    FROM yy_feed_page fp
    JOIN yy_feed f ON f.feed_key = fp.feed_key
    JOIN yy_page p ON p.page_key = fp.page_key
    WHERE p.page_code = 'music'
    ORDER BY fp.feed_page_sort, fp.feed_page_key
    LIMIT 1
");
$feedRow = $feedStmt->fetch();
$feedKey = $feedRow ? (int)$feedRow['feed_key'] : 4;

$action = $_GET['action'] ?? '';

if ($action === 'sync') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') { requireAuth(); }
    define('SYNC_CALLED_FROM_PARENT', true);
    require __DIR__ . '/sync-youtube.php';
    exit;
}

// Build WHERE clause
$where = "feed_key = ? AND feed_item_active_flag = TRUE";
$params = [$feedKey];

buildFeedPageFilters($where, $params, $feedRow['feed_page_filter_include'] ?? '', $feedRow['feed_page_filter_exclude'] ?? '', $feedRow['feed_page_filter_orientation'] ?? null);

$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$limit = (int)($_GET['limit'] ?? 0);
if ($limit > 0) {
    $stmt = $db->prepare("SELECT feed_item_external_id AS music_video_id, TRIM(BOTH '~ -' FROM TRIM(REGEXP_REPLACE(feed_item_title, '#\\w+\\s*', '', 'g'))) AS music_title, feed_item_thumbnail AS music_thumbnail, feed_item_publish_dtime AS music_create FROM yy_feed_item WHERE $where ORDER BY feed_item_publish_dtime DESC NULLS LAST LIMIT ?");
    $stmt->execute(array_merge($params, [$limit]));
    jsonResponse(['videos' => $stmt->fetchAll(), 'page' => 1, 'total_pages' => 1, 'total' => $total]);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT feed_item_external_id AS music_video_id, TRIM(BOTH '~ -' FROM TRIM(REGEXP_REPLACE(feed_item_title, '#\\w+\\s*', '', 'g'))) AS music_title,
           feed_item_thumbnail AS music_thumbnail, feed_item_publish_dtime AS music_create
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
