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

$action = $_GET['action'] ?? '';

if ($action === 'sync') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') { requireAuth(); }
    define('SYNC_CALLED_FROM_PARENT', true);
    require __DIR__ . '/sync-youtube.php';
    exit;
}

// Build WHERE clause using join table
$pageKey = getPageKey($db, 'music');
$where = "fi.feed_item_active_flag = TRUE AND fi.feed_item_restricted_flag = FALSE AND fip.page_key = ?";
$params = [$pageKey];

$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item fi JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$limit = (int)($_GET['limit'] ?? 0);
if ($limit > 0) {
    $stmt = $db->prepare("SELECT fi.feed_item_external_id AS music_video_id, TRIM(BOTH '~ -' FROM TRIM(REGEXP_REPLACE(COALESCE(fi.feed_item_title_override, fi.feed_item_title_import), '#\\w+\\s*', '', 'g'))) AS music_title, fi.feed_item_thumbnail AS music_thumbnail, COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS music_create FROM yy_feed_item fi JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key WHERE $where ORDER BY fi.feed_item_sort NULLS LAST, (NULLIF(regexp_replace(fi.feed_item_episode, '[^0-9]', '', 'g'), ''))::int NULLS LAST, COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) DESC NULLS LAST LIMIT ?");
    $stmt->execute(array_merge($params, [$limit]));
    jsonResponse(['videos' => $stmt->fetchAll(), 'page' => 1, 'total_pages' => 1, 'total' => $total]);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT fi.feed_item_external_id AS music_video_id, TRIM(BOTH '~ -' FROM TRIM(REGEXP_REPLACE(COALESCE(fi.feed_item_title_override, fi.feed_item_title_import), '#\\w+\\s*', '', 'g'))) AS music_title,
           fi.feed_item_thumbnail AS music_thumbnail, COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS music_create
    FROM yy_feed_item fi
    JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key
    WHERE $where
    ORDER BY fi.feed_item_sort NULLS LAST, (NULLIF(regexp_replace(fi.feed_item_episode, '[^0-9]', '', 'g'), ''))::int NULLS LAST, COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) DESC NULLS LAST
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$PER_PAGE, $offset]));

jsonResponse([
    'videos'      => $stmt->fetchAll(),
    'page'        => $page,
    'total_pages' => $totalPages,
    'total'       => $total,
]);
