<?php
/**
 * Public API for Rumble/Vlog videos.
 * Serves from yy_feed_item table, filtered by yy_feed_page config.
 *
 * GET ?page=N — paginated list
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';

$db = getDb();

// Load feed config from yy_feed_page for vlog
$fpStmt = $db->query("
    SELECT fp.feed_page_per_page
    FROM yy_feed_page fp
    JOIN yy_page p ON p.page_key = fp.page_key
    WHERE p.page_code = 'vlog'
    ORDER BY fp.feed_page_sort
    LIMIT 1
");
$fpRow = $fpStmt->fetch();

$perPage = $fpRow ? ((int)$fpRow['feed_page_per_page'] ?: 24) : 24;

$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Build WHERE clause using join table
$pageKey = getPageKey($db, 'vlog');
$where = "fi.feed_item_active_flag = TRUE AND fip.page_key = ?";
$params = [$pageKey];

// Grouped mode — sections by category with episode sort
if (isset($_GET['grouped'])) {
    $stmt = $db->prepare("
        SELECT fi.feed_item_key, fi.feed_item_external_id, COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS feed_item_title, fi.feed_item_url,
               fi.feed_item_thumbnail, fi.feed_item_embed_id, fi.feed_item_duration,
               COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS feed_item_publish_dtime, fi.feed_item_create_dtime,
               fi.feed_item_category_key, fi.feed_item_episode, fi.feed_item_audio_file
        FROM yy_feed_item fi
        JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key
        WHERE $where
        ORDER BY feed_item_publish_dtime DESC NULLS LAST
    ");
    $stmt->execute($params);
    $allItems = $stmt->fetchAll();

    $catStmt = $db->query("
        SELECT category_key, category_title, category_subtitle, category_slug, category_sort
        FROM yy_feed_page_category
        WHERE page_key = 1 AND category_active_flag = TRUE
        ORDER BY category_sort, category_title
    ");
    $catMeta = [];
    foreach ($catStmt->fetchAll() as $c) { $catMeta[$c['category_key']] = $c; }

    $groups = [];
    foreach ($allItems as $item) {
        $ck = (int)($item['feed_item_category_key'] ?? 0);
        $groups[$ck][] = [
            'title' => trim(preg_replace('/^[~\- ]+|[~\- ]+$/', '', trim(preg_replace('/#\w+\s*/', '', $item['feed_item_title'])))),
            'url' => $item['feed_item_url'],
            'thumbnail' => $item['feed_item_thumbnail'],
            'embedId' => $item['feed_item_embed_id'],
            'videoId' => $item['feed_item_external_id'],
            'duration' => $item['feed_item_duration'],
            'date' => $item['feed_item_publish_dtime'] ?? $item['feed_item_create_dtime'],
            'episode' => $item['feed_item_episode'] ? (int)$item['feed_item_episode'] : null,
            'audio' => $item['feed_item_audio_file'] ?? null,
        ];
    }

    foreach ($groups as &$g) {
        usort($g, function ($a, $b) {
            return ($a['episode'] ?? 9999) - ($b['episode'] ?? 9999);
        });
    }
    unset($g);

    $sections = [];
    foreach ($catMeta as $key => $meta) {
        if (isset($groups[$key])) {
            $sections[] = [
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
    if (!empty($groups[0])) {
        $sections[] = [
            'category' => ['category_title' => 'Uncategorized', 'category_subtitle' => null, 'category_slug' => 'uncategorized'],
            'videos' => $groups[0],
        ];
    }

    jsonResponse(['sections' => $sections, 'total' => count($allItems)]);
}

// Count total
$countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item fi JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Fetch page
$fetchParams = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare("
    SELECT fi.feed_item_key, fi.feed_item_external_id, COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS feed_item_title, fi.feed_item_url,
           fi.feed_item_thumbnail, fi.feed_item_embed_id, fi.feed_item_duration,
           COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) AS feed_item_publish_dtime, fi.feed_item_create_dtime, fi.feed_item_audio_file
    FROM yy_feed_item fi
    JOIN yy_feed_item_page fip ON fi.feed_item_key = fip.feed_item_key
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
        'title' => trim(preg_replace('/^[~\- ]+|[~\- ]+$/', '', trim(preg_replace('/#\w+\s*/', '', $item['feed_item_title'])))),
        'url' => $item['feed_item_url'],
        'thumbnail' => $item['feed_item_thumbnail'],
        'embedId' => $item['feed_item_embed_id'],
        'videoId' => $item['feed_item_external_id'],
        'duration' => $item['feed_item_duration'],
        'date' => $item['feed_item_publish_dtime'] ?? $item['feed_item_create_dtime'],
        'audio' => $item['feed_item_audio_file'] ?? null,
    ];
}

$totalPages = max(1, ceil($total / $perPage));

jsonResponse([
    'videos' => $videos,
    'page' => $page,
    'total_pages' => $totalPages,
    'total' => $total,
]);
