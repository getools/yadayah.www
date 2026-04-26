<?php
/**
 * YouTube feed API — serves from yy_feed_item.
 *
 * GET ?type=channel&limit=N&offset=N  — channel videos
 * GET ?type=shorts&limit=N            — short videos (duration < 240s)
 * GET ?type=playlist&id=PLxxx&limit=N — playlist videos
 */
require_once __DIR__ . '/config.php';

$type = $_GET['type'] ?? 'channel';
$limit = min((int)($_GET['limit'] ?? 15), 50);
$offset = (int)($_GET['offset'] ?? 0);

$db = getDb();

// Load channel feed
$feedStmt = $db->query("SELECT feed_key, feed_account_id FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = true AND feed_account_id LIKE 'UC%' ORDER BY feed_key LIMIT 1");
$feedRow = $feedStmt->fetch();
$channelFeedKey = $feedRow ? (int)$feedRow['feed_key'] : 1;

switch ($type) {
    case 'channel':
        serveFromFeedItem($db, $channelFeedKey, $limit, $offset, "feed_item_type != 'short' OR feed_item_type IS NULL");
        break;

    case 'shorts':
        // Shorts are channel videos with duration < 240 or tagged as short
        serveFromFeedItem($db, $channelFeedKey, $limit, 0, "feed_item_type = 'short'");
        break;

    case 'playlist':
        $playlistId = $_GET['id'] ?? '';
        if (!$playlistId) errorResponse('Missing playlist id');
        // Find the feed_key for this playlist
        $plStmt = $db->prepare("SELECT feed_key FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_account_id = ? AND feed_active_flag = true LIMIT 1");
        $plStmt->execute([$playlistId]);
        $plFeedKey = (int)$plStmt->fetchColumn();
        if (!$plFeedKey) {
            jsonResponse(['videos' => [], 'total' => 0, 'hasMore' => false]);
        }
        serveFromFeedItem($db, $plFeedKey, $limit, 0);
        break;

    default:
        errorResponse('Invalid type');
}

function serveFromFeedItem(PDO $db, int $feedKey, int $limit, int $offset, string $extraWhere = ''): void {
    $where = "feed_key = ? AND feed_item_active_flag = TRUE";
    $params = [$feedKey];

    if ($extraWhere) {
        $where .= " AND ($extraWhere)";
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT feed_item_external_id AS id, feed_item_title AS title,
               feed_item_publish_dtime AS published,
               feed_item_thumbnail AS thumbnail,
               REPLACE(feed_item_thumbnail, 'hqdefault', 'maxresdefault') AS \"thumbnailMax\"
        FROM yy_feed_item
        WHERE $where
        ORDER BY feed_item_publish_dtime DESC NULLS LAST
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));

    jsonResponse([
        'videos' => $stmt->fetchAll(),
        'total' => $total,
        'hasMore' => ($offset + $limit) < $total,
    ]);
}
