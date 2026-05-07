<?php
/**
 * Batch caption upload.
 *
 * Three modes (request body is JSON, all fields optional):
 *
 *   { "count_only": true }
 *       → returns { ok, count } — eligible queued/never/error items
 *         site-wide. No upload performed. Used by the UI to confirm
 *         "Upload all N queued?" before kicking off the real run.
 *
 *   { "feed_item_keys": [123, 456, ...] }
 *       → uploads ONLY those items (subject to the same eligibility
 *         filter — must have a transcript and a connected feed).
 *
 *   {} (or no body)
 *       → uploads every eligible item.
 *
 * Returns: { ok, processed, success, failed, items: [{feed_item_key, status, message}, ...] }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/youtube-caption-helpers.php';

requireAuth();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$countOnly = !empty($body['count_only']);
$keys = isset($body['feed_item_keys']) && is_array($body['feed_item_keys'])
    ? array_values(array_filter(array_map('intval', $body['feed_item_keys']), fn($k) => $k > 0))
    : null;

set_time_limit(600);     // 10 minutes hard cap

$db = getDb();

$sql = "
    SELECT fi.feed_item_key
      FROM yy_feed_item fi
      JOIN yy_feed f ON f.feed_key = fi.feed_key
     WHERE fi.feed_item_active_flag = TRUE
       AND fi.feed_item_embed_id IS NOT NULL
       AND fi.feed_item_embed_id <> ''
       AND f.feed_yt_caption_refresh_token IS NOT NULL
       AND EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key)
";
$params = [];
if ($keys !== null) {
    // Selected-rows mode — caller decides which items, we still gate on
    // the eligibility filter (active, has transcript, feed connected) so
    // selecting unconnected/transcript-less rows doesn't crash mid-loop.
    if (!$keys) jsonResponse(['ok' => true, 'processed' => 0, 'success' => 0, 'failed' => 0, 'items' => []]);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $sql .= " AND fi.feed_item_key IN ($placeholders)";
    $params = $keys;
} else {
    // All-queued mode.
    $sql .= " AND COALESCE(fi.feed_item_yt_caption_status, 'never') IN ('never', 'queued', 'error')";
}
$sql .= " ORDER BY fi.feed_item_publish_import_dtime DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

if ($countOnly) {
    jsonResponse(['ok' => true, 'count' => count($rows)]);
}

$results = [];
$success = 0;
$failed  = 0;

foreach ($rows as $feedItemKey) {
    $r = uploadOneItem($db, (int)$feedItemKey);
    $results[] = ['feed_item_key' => (int)$feedItemKey] + $r;
    if (!empty($r['ok'])) $success++; else $failed++;
}

jsonResponse([
    'ok'        => true,
    'processed' => count($rows),
    'success'   => $success,
    'failed'    => $failed,
    'items'     => $results,
]);

/**
 * Inline copy of the single-item logic from admin-yt-caption-upload.php
 * — kept here so the batch can run without HTTP overhead per item.
 */
function uploadOneItem(PDO $db, int $feedItemKey): array {
    $stmt = $db->prepare("
        SELECT fi.feed_key, fi.feed_item_embed_id, fi.feed_item_yt_caption_track_id
          FROM yy_feed_item fi
         WHERE fi.feed_item_key = ?
    ");
    $stmt->execute([$feedItemKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'status' => 'error', 'message' => 'feed_item not found'];

    $videoId = (string)($row['feed_item_embed_id'] ?? '');
    if ($videoId === '') {
        statusW($db, $feedItemKey, 'error', null, 'No YouTube video ID', null);
        return ['ok' => false, 'status' => 'error', 'message' => 'No YouTube video ID'];
    }
    statusW($db, $feedItemKey, 'uploading', null, null, null);

    $srt = ytCaptionsBuildSrt($db, $feedItemKey);
    if (!$srt) {
        statusW($db, $feedItemKey, 'error', null, 'No transcript segments', null);
        return ['ok' => false, 'status' => 'error', 'message' => 'No transcript segments'];
    }
    $token = ytCaptionsAccessTokenForFeed($db, (int)$row['feed_key']);
    if (!$token) {
        statusW($db, $feedItemKey, 'error', null, 'Access token mint failed', null);
        return ['ok' => false, 'status' => 'error', 'message' => 'Access token mint failed'];
    }

    $candidate = $row['feed_item_yt_caption_track_id'] ?: null;
    $existingTrackId = ytCaptionsResolveUpdatableTrack($token, $videoId, $candidate);

    $r = ytCaptionsUploadSrt($token, $videoId, $srt, $existingTrackId, 'English', 'en');
    if (!$r['ok'] && $existingTrackId && in_array($r['http'], [403, 404], true)) {
        $existingTrackId = null;
        $r = ytCaptionsUploadSrt($token, $videoId, $srt, null, 'English', 'en');
    }
    if ($r['ok']) {
        $segStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript WHERE feed_item_key = ?");
        $segStmt->execute([$feedItemKey]);
        statusW($db, $feedItemKey, 'success', $r['track_id'], null, (int)$segStmt->fetchColumn());
        return ['ok' => true, 'status' => 'success'];
    }
    statusW($db, $feedItemKey, 'error', $existingTrackId, 'HTTP ' . $r['http'] . ': ' . $r['message'], null);
    return ['ok' => false, 'status' => 'error', 'message' => $r['message']];
}

function statusW(PDO $db, int $feedItemKey, string $status, ?string $trackId, ?string $message, ?int $segCount): void {
    $sql = "UPDATE yy_feed_item
               SET feed_item_yt_caption_status = ?,
                   feed_item_yt_caption_message = ?";
    $p = [$status, $message];
    if ($trackId !== null) { $sql .= ", feed_item_yt_caption_track_id = ?"; $p[] = $trackId; }
    if ($status === 'success') {
        $sql .= ", feed_item_yt_caption_uploaded_dtime = NOW()";
        if ($segCount !== null) { $sql .= ", feed_item_yt_caption_segments_at_upload = ?"; $p[] = $segCount; }
    }
    $sql .= " WHERE feed_item_key = ?";
    $p[] = $feedItemKey;
    $db->prepare($sql)->execute($p);
}
