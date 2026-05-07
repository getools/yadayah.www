<?php
/**
 * Disconnect a feed's YouTube captions OAuth — clears the stored
 * refresh token + channel info. Subsequent caption uploads for that
 * feed will fail until reconnected.
 *
 * POST JSON: { feed_key: N }
 *
 * Note: this DOESN'T revoke the token at Google's end (no /revoke
 * call). The user can do that from their Google account settings if
 * they want; this just removes the local copy.
 */
require_once __DIR__ . '/config.php';

requireAuth();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$feedKey = (int)($data['feed_key'] ?? 0);
if ($feedKey <= 0) errorResponse('feed_key required', 400);

$db = getDb();
$db->prepare("
    UPDATE yy_feed
       SET feed_yt_caption_refresh_token   = NULL,
           feed_yt_caption_channel_id      = NULL,
           feed_yt_caption_channel_title   = NULL,
           feed_yt_caption_connected_dtime = NULL
     WHERE feed_key = ?
")->execute([$feedKey]);

jsonResponse(['ok' => true]);
