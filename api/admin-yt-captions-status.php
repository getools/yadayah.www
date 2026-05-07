<?php
/**
 * Returns the current YouTube-captions connection state for the admin UI.
 *
 * Response: { connected: bool, channel_title: string|null, channel_id: string|null,
 *             queued_count: int, last_error: string|null }
 *
 * queued_count is the number of feed_items whose transcript is ready
 * to upload but hasn't been uploaded yet. Drives the badge on the
 * "Batch Upload Queued" button.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/youtube-caption-helpers.php';

requireAdmin();

$db = getDb();
$refresh   = ytSetting($db, 'youtube-captions-refresh-token');
$channelId = ytSetting($db, 'youtube-captions-channel-id');
$channelTitle = ytSetting($db, 'youtube-captions-channel-title');

// Queued = items that have a transcript and are either never-uploaded or
// flagged 'queued' / 'error'. We only count items with a YouTube embed_id
// (no embed_id = not a YouTube video, can't push captions).
$stmt = $db->prepare("
    SELECT COUNT(*)
      FROM yy_feed_item fi
     WHERE fi.feed_item_embed_id IS NOT NULL
       AND fi.feed_item_embed_id <> ''
       AND fi.feed_item_active_flag = TRUE
       AND EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key)
       AND COALESCE(fi.feed_item_yt_caption_status, 'never') IN ('never', 'queued', 'error')
");
$stmt->execute();
$queued = (int)$stmt->fetchColumn();

jsonResponse([
    'connected'      => (bool)$refresh,
    'channel_id'     => $channelId,
    'channel_title'  => $channelTitle,
    'queued_count'   => $queued,
]);
