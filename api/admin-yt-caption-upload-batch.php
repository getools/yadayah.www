<?php
/**
 * Batch upload: walks every feed_item with a transcript whose
 * caption status is 'never' / 'queued' / 'error', invokes the single-
 * item upload pipeline serially, and returns a tally.
 *
 * POST (no body needed). Server-side serial loop — typically <50 items
 * at a time, ~3-5s per upload, well within PHP's max_execution_time.
 *
 * Returns: { ok: bool, processed: N, success: N, failed: N, items: [...] }
 *
 * Each item: { feed_item_key, status, message }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/youtube-caption-helpers.php';

requireAuth();

set_time_limit(600);     // 10 minutes hard cap

$db = getDb();
$rows = $db->query("
    SELECT fi.feed_item_key
      FROM yy_feed_item fi
      JOIN yy_feed f ON f.feed_key = fi.feed_key
     WHERE fi.feed_item_active_flag = TRUE
       AND fi.feed_item_embed_id IS NOT NULL
       AND fi.feed_item_embed_id <> ''
       AND f.feed_yt_caption_refresh_token IS NOT NULL
       AND EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key)
       AND COALESCE(fi.feed_item_yt_caption_status, 'never') IN ('never', 'queued', 'error')
     ORDER BY fi.feed_item_publish_import_dtime DESC
")->fetchAll(PDO::FETCH_COLUMN);

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
 * — kept here so the batch can run without HTTP overhead per item. The
 * two endpoints share the same status-write helper through includes.
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
    $existingTrackId = $row['feed_item_yt_caption_track_id'] ?: null;
    if (!$existingTrackId) {
        $tracks = ytCaptionsListTracks($token, $videoId);
        if (is_array($tracks)) {
            foreach ($tracks as $t) {
                if (strcasecmp($t['trackKind'] ?? '', 'asr') === 0) continue;
                if (strcasecmp($t['name'], 'English') === 0 || $t['language'] === 'en') {
                    $existingTrackId = $t['id'];
                    break;
                }
            }
        }
    }
    $r = ytCaptionsUploadSrt($token, $videoId, $srt, $existingTrackId, 'English', 'en');
    if (!$r['ok'] && $existingTrackId && $r['http'] === 404) {
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
