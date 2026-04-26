<?php
/**
 * Public API for Rumble/Vlog videos.
 * Serves from yy_feed_item table, filtered by yy_feed_page config.
 *
 * GET ?page=N — paginated list
 */
require_once __DIR__ . '/config.php';

$db = getDb();

// Load feed config from yy_feed_page for vlog
$fpStmt = $db->query("
    SELECT fp.feed_key, fp.feed_page_per_page, fp.feed_page_paging_code,
           fp.feed_page_filter_include, fp.feed_page_filter_exclude,
           fp.feed_page_listing_type
    FROM yy_feed_page fp
    JOIN yy_page p ON p.page_key = fp.page_key
    WHERE p.page_code = 'vlog'
    ORDER BY fp.feed_page_sort
    LIMIT 1
");
$fpRow = $fpStmt->fetch();

$feedKey = $fpRow ? (int)$fpRow['feed_key'] : 9;
$perPage = $fpRow ? ((int)$fpRow['feed_page_per_page'] ?: 24) : 24;
$includeFilter = $fpRow ? trim($fpRow['feed_page_filter_include'] ?? '') : '';
$excludeFilter = $fpRow ? trim($fpRow['feed_page_filter_exclude'] ?? '') : '';

$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Build WHERE clause with filters
$where = "feed_key = ? AND feed_item_active_flag = TRUE";
$params = [$feedKey];

// Include filter: title must contain at least one of these terms
$includeTerms = $includeFilter ? array_filter(array_map('trim', explode(',', $includeFilter))) : [];
if ($includeTerms) {
    $includeClauses = [];
    foreach ($includeTerms as $term) {
        $includeClauses[] = "feed_item_title ILIKE ?";
        $params[] = '%' . $term . '%';
    }
    $where .= " AND (" . implode(' OR ', $includeClauses) . ")";
}

// Exclude filter: title must NOT contain any of these terms
$excludeTerms = $excludeFilter ? array_filter(array_map('trim', explode(',', $excludeFilter))) : [];
foreach ($excludeTerms as $term) {
    $where .= " AND feed_item_title NOT ILIKE ?";
    $params[] = '%' . $term . '%';
}

// Count total
$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Fetch page
$fetchParams = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare("
    SELECT feed_item_key, feed_item_external_id, feed_item_title, feed_item_url,
           feed_item_thumbnail, feed_item_embed_id, feed_item_duration,
           feed_item_publish_dtime, feed_item_create_dtime
    FROM yy_feed_item
    WHERE $where
    ORDER BY feed_item_publish_dtime DESC NULLS LAST
    LIMIT ? OFFSET ?
");
$stmt->execute($fetchParams);
$items = $stmt->fetchAll();

// Map to frontend format
$videos = [];
foreach ($items as $item) {
    $videos[] = [
        'title' => $item['feed_item_title'],
        'url' => $item['feed_item_url'],
        'thumbnail' => $item['feed_item_thumbnail'],
        'embedId' => $item['feed_item_embed_id'],
        'videoId' => $item['feed_item_external_id'],
        'duration' => $item['feed_item_duration'],
        'date' => $item['feed_item_publish_dtime'] ?? $item['feed_item_create_dtime'],
    ];
}

$totalPages = max(1, ceil($total / $perPage));

jsonResponse([
    'videos' => $videos,
    'page' => $page,
    'total_pages' => $totalPages,
    'total' => $total,
]);
