<?php
/**
 * YouTube feed API — serves from yy_feed_item, routed via yy_feed_page.
 *
 * GET ?type=channel&limit=N&offset=N  — channel videos (long-form)
 * GET ?type=shorts&limit=N            — short videos (duration cap from yy_feed_page)
 * GET ?type=playlist&id=PLxxx&limit=N — playlist videos
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';

$type = $_GET['type'] ?? 'channel';
$limit = min((int)($_GET['limit'] ?? 15), 50);
$offset = (int)($_GET['offset'] ?? 0);

$db = getDb();

// Load feed + filter config for a given page_code. Picks the row joined to the
// main YouTube channel feed (feed_account_id LIKE 'UC%') for that page.
function loadFeedPageForPage(PDO $db, string $pageCode): ?array {
    $stmt = $db->prepare("
        SELECT f.feed_key,
               fp.feed_page_filter_include,
               fp.feed_page_filter_exclude,
               fp.feed_page_filter_duration_min,
               fp.feed_page_filter_duration_max,
               fp.feed_page_filter_orientation
        FROM yy_feed_page fp
        JOIN yy_feed f ON f.feed_key = fp.feed_key
        JOIN yy_page p ON p.page_key = fp.page_key
        WHERE p.page_code = ?
          AND fp.feed_page_active_flag = TRUE
          AND f.feed_active_flag = TRUE
          AND lower(f.feed_site_code) = 'youtube'
          AND f.feed_account_id LIKE 'UC%'
        ORDER BY fp.feed_page_sort, fp.feed_page_key
        LIMIT 1
    ");
    $stmt->execute([$pageCode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Fallback: pick the first active YouTube channel feed if no yy_feed_page row exists.
function fallbackChannelFeedKey(PDO $db): int {
    $stmt = $db->query("
        SELECT feed_key FROM yy_feed
        WHERE lower(feed_site_code) = 'youtube'
          AND feed_active_flag = TRUE
          AND feed_account_id LIKE 'UC%'
        ORDER BY feed_key LIMIT 1
    ");
    return (int)($stmt->fetchColumn() ?: 1);
}

// Build WHERE clause from a yy_feed_page config row.
function buildFeedPageWhere(array $cfg, int $feedKey): array {
    $where = "feed_key = ? AND feed_item_active_flag = TRUE";
    $params = [$feedKey];

    buildFeedPageFilters($where, $params, $cfg['feed_page_filter_include'] ?? '', $cfg['feed_page_filter_exclude'] ?? '', $cfg['feed_page_filter_orientation'] ?? null);

    if (!empty($cfg['feed_page_filter_duration_min'])) {
        $where .= " AND feed_item_duration_seconds >= ?";
        $params[] = (int)$cfg['feed_page_filter_duration_min'];
    }
    if (!empty($cfg['feed_page_filter_duration_max'])) {
        $where .= " AND feed_item_duration_seconds <= ? AND feed_item_duration_seconds IS NOT NULL";
        $params[] = (int)$cfg['feed_page_filter_duration_max'];
    }

    $orientation = $cfg['feed_page_filter_orientation'] ?? null;
    if ($orientation && in_array($orientation, ['horizontal', 'vertical'])) {
        $where .= " AND feed_item_orientation = ?";
        $params[] = $orientation;
    }

    return [$where, $params];
}

switch ($type) {
    case 'channel':
        // Channel listing = long-form YouTube videos on the home grid.
        // Exclude anything that the shorts page would match (inverse of its duration cap).
        $cfg = loadFeedPageForPage($db, 'home');
        $feedKey = $cfg ? (int)$cfg['feed_key'] : fallbackChannelFeedKey($db);
        [$where, $params] = $cfg
            ? buildFeedPageWhere($cfg, $feedKey)
            : ["feed_key = ? AND feed_item_active_flag = TRUE", [$feedKey]];

        $shortsCfg = loadFeedPageForPage($db, 'shorts');
        $maxShort = $shortsCfg ? (int)($shortsCfg['feed_page_filter_duration_max'] ?? 0) : 0;
        if ($maxShort > 0) {
            // Allow NULL durations through (legacy rows not yet backfilled)
            $where .= " AND (feed_item_duration_seconds > ? OR feed_item_duration_seconds IS NULL)";
            $params[] = $maxShort;
        }
        serveItems($db, $where, $params, $limit, $offset);
        break;

    case 'shorts':
        $cfg = loadFeedPageForPage($db, 'shorts');
        if ($cfg) {
            $feedKey = (int)$cfg['feed_key'];
            [$where, $params] = buildFeedPageWhere($cfg, $feedKey);
        } else {
            // Fallback: channel feed, legacy feed_item_type='short' flag
            $feedKey = fallbackChannelFeedKey($db);
            $where = "feed_key = ? AND feed_item_active_flag = TRUE AND feed_item_type = 'short'";
            $params = [$feedKey];
        }
        serveItems($db, $where, $params, $limit, 0);
        break;

    case 'playlist':
        $playlistId = $_GET['id'] ?? '';
        if (!$playlistId) errorResponse('Missing playlist id');
        $plStmt = $db->prepare("SELECT feed_key FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_account_id = ? AND feed_active_flag = TRUE LIMIT 1");
        $plStmt->execute([$playlistId]);
        $plFeedKey = (int)$plStmt->fetchColumn();
        if (!$plFeedKey) {
            jsonResponse(['videos' => [], 'total' => 0, 'hasMore' => false]);
        }
        $where = "feed_key = ? AND feed_item_active_flag = TRUE";
        $params = [$plFeedKey];
        serveItems($db, $where, $params, $limit, 0);
        break;

    default:
        errorResponse('Invalid type');
}

function serveItems(PDO $db, string $where, array $params, int $limit, int $offset): void {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT feed_item_external_id AS id, COALESCE(feed_item_title_override, feed_item_title_import) AS title,
               COALESCE(feed_item_publish_override_dtime, feed_item_publish_import_dtime) AS published,
               feed_item_thumbnail AS thumbnail,
               REPLACE(feed_item_thumbnail, 'hqdefault', 'maxresdefault') AS \"thumbnailMax\",
               feed_item_duration AS duration,
               feed_item_duration_seconds AS duration_seconds,
               feed_item_audio_file AS audio
        FROM yy_feed_item
        WHERE $where
        ORDER BY COALESCE(feed_item_publish_override_dtime, feed_item_publish_import_dtime) DESC NULLS LAST
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));

    jsonResponse([
        'videos' => $stmt->fetchAll(),
        'total' => $total,
        'hasMore' => ($offset + $limit) < $total,
    ]);
}
