<?php
/**
 * TEST helper — feed item search/lookup for the admin Items section editor.
 *
 *   GET ?q=<text>&limit=8     — title contains <text>; returns matches with thumb
 *   GET ?keys=1,2,3           — fetch by exact feed_item_keys (for chip rendering)
 *
 * Returns: { items: [{feed_item_key, title, thumbnail, feed_name, posted_dtime}] }
 *
 * Auth required (admin tool).
 */
require_once __DIR__ . '/../config.php';
requireAuth();
$db = getDb();

$q        = trim($_GET['q']         ?? '');
$keys     = trim($_GET['keys']      ?? '');
$feedKeys = trim($_GET['feed_keys'] ?? '');
$limit    = (int)($_GET['limit']    ?? 8);
if ($limit < 1) $limit = 8;
if ($limit > 50) $limit = 50;

$where  = "i.feed_item_active_flag = TRUE";
$params = [];

if ($keys !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', $keys))));
    if (!$ids) jsonResponse(['items' => []]);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $where .= " AND i.feed_item_key IN ($place)";
    array_push($params, ...$ids);
} elseif ($q !== '') {
    $where .= " AND COALESCE(i.feed_item_title_override, i.feed_item_title_import) ILIKE ?";
    $params[] = '%' . $q . '%';
    // Title search may be scoped to a subset of feeds — when the parent Items section
    // has feeds checked, the pinned-items typeahead only suggests items from those feeds.
    if ($feedKeys !== '') {
        $fids = array_values(array_filter(array_map('intval', explode(',', $feedKeys))));
        if ($fids) {
            $place = implode(',', array_fill(0, count($fids), '?'));
            $where .= " AND i.feed_key IN ($place)";
            array_push($params, ...$fids);
        }
    }
} else {
    jsonResponse(['items' => []]);
}

$sql = "SELECT i.feed_item_key, i.feed_item_thumbnail, i.feed_item_embed_id,
               COALESCE(i.feed_item_title_override, i.feed_item_title_import) AS title,
               COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) AS posted_dtime,
               f.feed_name
        FROM yy_feed_item i
        JOIN yy_feed f ON f.feed_key = i.feed_key
        WHERE $where
        ORDER BY COALESCE(i.feed_item_publish_override_dtime, i.feed_item_publish_import_dtime) DESC
        LIMIT " . (int)$limit;
$stmt = $db->prepare($sql);
$stmt->execute($params);
jsonResponse(['items' => $stmt->fetchAll()]);
