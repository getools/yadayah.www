<?php
/**
 * Upload (or replace) one feed_item's transcript as a YouTube caption
 * track. Called from the admin Recordings tab's per-row Upload button
 * AND from the batch endpoint.
 *
 * POST JSON: { feed_item_key: N }
 *
 * Flow:
 *   1. Look up feed_item → its feed_key, embed_id (videoId), language.
 *   2. Build SRT from yy_feed_item_transcript rows.
 *   3. Mint OAuth access token from the feed's stored refresh_token.
 *   4. List existing caption tracks → pick the one named 'English' (or
 *      the previously-stored track_id) → captions.update; else insert new.
 *   5. Update yy_feed_item caption tracking columns (status / track_id /
 *      uploaded_dtime / message / segments_at_upload).
 *
 * Returns: { ok: bool, status: 'success'|'error', message?, track_id? }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/youtube-caption-helpers.php';

requireAuth();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$feedItemKey = (int)($data['feed_item_key'] ?? 0);
if ($feedItemKey <= 0) errorResponse('feed_item_key required', 400);

$db = getDb();

// Fetch item details + parent feed details in one round-trip.
$stmt = $db->prepare("
    SELECT fi.feed_key, fi.feed_item_embed_id, fi.feed_item_title_import,
           f.feed_yt_caption_refresh_token IS NOT NULL AS has_token
      FROM yy_feed_item fi
      JOIN yy_feed f ON f.feed_key = fi.feed_key
     WHERE fi.feed_item_key = ?
");
$stmt->execute([$feedItemKey]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) errorResponse('feed_item not found', 404);

$videoId = (string)($row['feed_item_embed_id'] ?? '');
if ($videoId === '') {
    captionStatusUpdate($db, $feedItemKey, 'error', null, 'No YouTube video ID on this feed_item — cannot upload.', null);
    jsonResponse(['ok' => false, 'status' => 'error', 'message' => 'No YouTube video ID']);
}
if (!$row['has_token']) {
    captionStatusUpdate($db, $feedItemKey, 'error', null, 'Parent feed has no OAuth grant. Click "Connect OAuth" in Sources.', null);
    jsonResponse(['ok' => false, 'status' => 'error', 'message' => 'Feed not connected to OAuth']);
}

// Mark in-flight before the (slow) API calls so the UI can show pulse.
captionStatusUpdate($db, $feedItemKey, 'uploading', null, null, null);

// Build SRT.
$srt = ytCaptionsBuildSrt($db, $feedItemKey);
if (!$srt) {
    captionStatusUpdate($db, $feedItemKey, 'error', null, 'No transcript segments to upload.', null);
    jsonResponse(['ok' => false, 'status' => 'error', 'message' => 'No transcript segments']);
}

// Mint a token for THIS feed.
$token = ytCaptionsAccessTokenForFeed($db, (int)$row['feed_key']);
if (!$token) {
    captionStatusUpdate($db, $feedItemKey, 'error', null, 'Failed to mint access token (refresh token expired or revoked?). Re-connect OAuth on the Sources tab.', null);
    jsonResponse(['ok' => false, 'status' => 'error', 'message' => 'Access token mint failed']);
}

// Resolve which existing track (if any) we can UPDATE. The helper
// inspects captions.list, ignores ASR auto-tracks (not updatable via
// the API), and returns null if nothing usable exists → INSERT.
$prev = $db->prepare("SELECT feed_item_yt_caption_track_id FROM yy_feed_item WHERE feed_item_key = ?");
$prev->execute([$feedItemKey]);
$candidate = $prev->fetchColumn() ?: null;
$existingTrackId = ytCaptionsResolveUpdatableTrack($token, $videoId, $candidate);

$result = ytCaptionsUploadSrt($token, $videoId, $srt, $existingTrackId, 'English', 'en');

// If captions.update came back 403/404 (track gone, or not actually
// writable by this token despite passing our prefilter — possible if
// the list response was cached or raced), drop the id and retry as
// INSERT exactly once.
if (!$result['ok'] && $existingTrackId && in_array($result['http'], [403, 404], true)) {
    $existingTrackId = null;
    $result = ytCaptionsUploadSrt($token, $videoId, $srt, null, 'English', 'en');
}

if ($result['ok']) {
    // Count segments at upload time so we can detect "stale" later.
    $segStmt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript WHERE feed_item_key = ?");
    $segStmt->execute([$feedItemKey]);
    $segCount = (int)$segStmt->fetchColumn();
    captionStatusUpdate(
        $db,
        $feedItemKey,
        'success',
        $result['track_id'],
        null,
        $segCount
    );
    jsonResponse(['ok' => true, 'status' => 'success', 'track_id' => $result['track_id']]);
} else {
    captionStatusUpdate(
        $db,
        $feedItemKey,
        'error',
        $existingTrackId,                // keep prior id for later retry
        'HTTP ' . $result['http'] . ': ' . $result['message'],
        null
    );
    jsonResponse(['ok' => false, 'status' => 'error', 'message' => $result['message'], 'http' => $result['http']]);
}

/**
 * One place that writes to the caption tracking columns — keeps the
 * schema usage in one spot so adding a column later only touches here.
 */
function captionStatusUpdate(PDO $db, int $feedItemKey, string $status, ?string $trackId, ?string $message, ?int $segCount): void {
    $sql = "UPDATE yy_feed_item
               SET feed_item_yt_caption_status = ?,
                   feed_item_yt_caption_message = ?";
    $params = [$status, $message];
    if ($trackId !== null) {
        $sql .= ", feed_item_yt_caption_track_id = ?";
        $params[] = $trackId;
    }
    if ($status === 'success') {
        $sql .= ", feed_item_yt_caption_uploaded_dtime = NOW()";
        if ($segCount !== null) {
            $sql .= ", feed_item_yt_caption_segments_at_upload = ?";
            $params[] = $segCount;
        }
    }
    $sql .= " WHERE feed_item_key = ?";
    $params[] = $feedItemKey;
    $db->prepare($sql)->execute($params);
}
