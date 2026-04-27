<?php
/**
 * Public API for Basics videos — serves from yy_feed_item.
 *
 * GET ?page=N          — paginated list (24 per page)
 * GET ?grouped=1       — grouped by category (from feed_item_tags)
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
    define('SYNC_CALLED_FROM_PARENT', true);
    require __DIR__ . '/sync-youtube.php';
    exit;
}

function cleanBasicsTitle(string $title): string {
    $title = preg_replace('/\s*#\w+/', '', $title); // strip hashtags
    $title = preg_replace('/^The Basics\s*~\s*/i', '', $title); // strip prefix
    return trim($title) ?: 'Basics';
}

// Build WHERE clause using join table
$pageKey = getPageKey($db, 'basics');
$where = "fi.feed_item_active_flag = TRUE AND fip.page_key = ?";
$params = [$pageKey];

// Grouped mode
if (isset($_GET['grouped'])) {
    $stmt = $db->prepare("
        SELECT fi.feed_item_external_id AS basics_video_id, TRIM(BOTH '~ -' FROM TRIM(REGEXP_REPLACE(COALESCE(fi.feed_item_title_override, fi.feed_item_title_import), '#\\w+\\s*', '', 'g'))) AS basics_title,
               fi.feed_item_thumbnail AS basics_thumbnail, COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS basics_create,
               fi.feed_item_tags, fi.feed_item_sort AS basics_sort, fi.feed_item_category_key, fi.feed_item_audio_file AS basics_audio
        FROM yy_feed_item fi
        JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key
        WHERE $where
        ORDER BY fi.feed_item_sort, COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) DESC NULLS LAST
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
    $catStmt = $db->query("SELECT category_key, category_title, category_subtitle, category_slug, category_sort FROM yy_feed_page_category WHERE page_key = 20 AND category_active_flag = TRUE ORDER BY category_sort");
    foreach ($catStmt->fetchAll() as $c) {
        $catMeta[$c['category_key']] = $c;
    }

    // Group by feed_item_category_key
    $groups = [];
    foreach ($items as $item) {
        $ck = (int)($item['feed_item_category_key'] ?? 0);
        unset($item['feed_item_tags'], $item['feed_item_category_key']);
        if (!isset($groups[$ck])) $groups[$ck] = [];
        $groups[$ck][] = $item;
    }

    // Build result sorted by category sort order
    $result = [];
    foreach ($catMeta as $key => $meta) {
        if (isset($groups[$key])) {
            $result[] = [
                'category' => [
                    'category_title' => $meta['category_title'],
                    'category_subtitle' => $meta['category_subtitle'] ?? null,
                    'category_slug' => $meta['category_slug'],
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
                'category_title' => 'Uncategorized',
                'category_subtitle' => null,
                'category_slug' => 'uncategorized',
            ],
            'videos' => $videos,
        ];
    }

    jsonResponse(['sections' => $result, 'total' => count($items)]);
}

// Flat paginated mode
$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item fi JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT fi.feed_item_external_id AS basics_video_id, TRIM(BOTH '~ -' FROM TRIM(REGEXP_REPLACE(COALESCE(fi.feed_item_title_override, fi.feed_item_title_import), '#\\w+\\s*', '', 'g'))) AS basics_title,
           fi.feed_item_thumbnail AS basics_thumbnail, COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS basics_create
    FROM yy_feed_item fi
    JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key
    WHERE $where
    ORDER BY fi.feed_item_sort, COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) DESC NULLS LAST
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
