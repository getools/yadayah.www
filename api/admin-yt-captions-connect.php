<?php
/**
 * Initiate per-feed OAuth grant for YouTube captions write access.
 *
 * Admin clicks "OAuth" on a Sources-table row → /api/admin-yt-captions-connect.php?feed_key=N
 * → 302 to Google consent → /admin-yt-captions-callback.php with ?code=...&state=...
 * → tokens stored on yy_feed[feed_key].
 *
 * Admin-only. Validates that the feed exists and is a YouTube site.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/youtube-caption-helpers.php';

requireAuth();

if (session_status() === PHP_SESSION_NONE) session_start();

$feedKey = (int)($_GET['feed_key'] ?? 0);
if ($feedKey <= 0) {
    http_response_code(400);
    echo 'feed_key required';
    exit;
}

$db = getDb();
$stmt = $db->prepare("SELECT feed_site_code FROM yy_feed WHERE feed_key = ?");
$stmt->execute([$feedKey]);
$site = $stmt->fetchColumn();
if (!$site) {
    http_response_code(404);
    echo 'feed not found';
    exit;
}
if (strcasecmp($site, 'YouTube') !== 0) {
    http_response_code(400);
    echo 'OAuth captions only supported for YouTube feeds (this is ' . htmlspecialchars($site) . ')';
    exit;
}

$nonce = bin2hex(random_bytes(16));
$_SESSION['yt_caption_state'] = $nonce;

$url = ytCaptionsAuthUrl($db, $nonce, $feedKey);
if (!$url) {
    http_response_code(500);
    echo 'OAuth client not configured (oauth-google-client-id missing in yy_setting).';
    exit;
}
header('Location: ' . $url);
exit;
