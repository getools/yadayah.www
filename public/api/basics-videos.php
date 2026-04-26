<?php
/**
 * Public API for Basics videos — serves from yy_feed_item.
 *
 * GET ?page=N          — paginated list (24 per page)
 * GET ?grouped=1       — grouped by category (from feed_item_tags)
 * GET ?action=sync     — (auth required) refresh via sync-youtube.php
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
    WHERE p.page_code = 'basics'
    ORDER BY fp.feed_page_sort, fp.feed_page_key
    LIMIT 1
");
$feedRow = $feedStmt->fetch();
$feedKey = $feedRow ? (int)$feedRow['feed_key'] : 1;
$includeTerms = $feedRow ? array_filter(array_map('trim', explode(',', $feedRow['feed_page_filter_include'] ?? ''))) : ['#Basics'];
$excludeTerms = $feedRow ? array_filter(array_map('trim', explode(',', $feedRow['feed_page_filter_exclude'] ?? ''))) : [];

$action = $_GET['action'] ?? '';

if ($action === 'sync') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') { requireAuth(); }
    require __DIR__ . '/sync-youtube.php';
    exit;
}

function cleanBasicsTitle(string $title): string {
    $title = preg_replace('/\s*#\w+/', '', $title); // strip hashtags
    $title = preg_replace('/^The Basics\s*~\s*/i', '', $title); // strip prefix
    return trim($title) ?: 'Basics';
}

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
        $where .= " AND feed_item_title NOT ILIKE ?";
        $params[] = '%' . $term . '%';
    }
}

// Grouped mode
if (isset($_GET['grouped'])) {
    $stmt = $db->prepare("
        SELECT feed_item_external_id AS basics_video_id, feed_item_title AS basics_title,
               feed_item_thumbnail AS basics_thumbnail, feed_item_publish_dtime AS basics_create,
               feed_item_tags, feed_item_sort AS basics_sort
        FROM yy_feed_item
        WHERE $where
        ORDER BY feed_item_sort, feed_item_publish_dtime DESC NULLS LAST
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    // Clean titles: strip hashtags and "The Basics ~" prefix
    foreach ($items as &$item) {
        $item['basics_title'] = cleanBasicsTitle($item['basics_title']);
    }
    unset($item);

    // Load category metadata keyed by ID
    $catMeta = [];
    try {
        $catStmt = $db->query("SELECT basics_category_key, basics_category_title, basics_category_subtitle, basics_category_sort FROM yy_basics_category ORDER BY basics_category_sort");
        foreach ($catStmt->fetchAll() as $c) {
            $catMeta[$c['basics_category_key']] = $c;
        }
    } catch (Exception $e) {}

    // Group by category key (second part of tags after #Basics,)
    $groups = [];
    foreach ($items as $item) {
        $tags = $item['feed_item_tags'] ?? '';
        $parts = explode(',', $tags, 2);
        $catKey = trim($parts[1] ?? '0');
        unset($item['feed_item_tags']);
        if (!isset($groups[$catKey])) $groups[$catKey] = [];
        $groups[$catKey][] = $item;
    }

    // Build result sorted by category sort order
    $result = [];
    foreach ($catMeta as $key => $meta) {
        if (isset($groups[$key])) {
            $result[] = [
                'category' => [
                    'basics_category_title' => $meta['basics_category_title'],
                    'basics_category_subtitle' => $meta['basics_category_subtitle'] ?? null,
                ],
                'videos' => $groups[$key],
            ];
            unset($groups[$key]);
        }
    }
    // Add any uncategorized
    foreach ($groups as $catKey => $videos) {
        $result[] = [
            'category' => [
                'basics_category_title' => 'Uncategorized',
                'basics_category_subtitle' => null,
            ],
            'videos' => $videos,
        ];
    }

    jsonResponse(['sections' => $result, 'total' => count($items)]);
}

// Flat paginated mode
$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT feed_item_external_id AS basics_video_id, feed_item_title AS basics_title,
           feed_item_thumbnail AS basics_thumbnail, feed_item_publish_dtime AS basics_create
    FROM yy_feed_item
    WHERE $where
    ORDER BY feed_item_sort, feed_item_publish_dtime DESC NULLS LAST
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$PER_PAGE, $offset]));
$videos = $stmt->fetchAll();
foreach ($videos as &$v) { $v['basics_title'] = cleanBasicsTitle($v['basics_title']); }
unset($v);

jsonResponse([
    'videos'      => $videos,
    'page'        => $page,
    'total_pages' => $totalPages,
    'total'       => $total,
]);
