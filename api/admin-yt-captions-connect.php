<?php
/**
 * Initiate OAuth grant flow for YouTube captions write access.
 *
 * Admin clicks "Connect YouTube" → this endpoint generates a state nonce,
 * stores it in the session, and 302-redirects the browser to Google's
 * consent screen. After consent, Google redirects to
 * /api/admin-yt-captions-callback.php with ?code=... which we then
 * exchange for tokens.
 *
 * Admin-only.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/youtube-caption-helpers.php';

$user = requireAdmin();

if (session_status() === PHP_SESSION_NONE) session_start();

$nonce = bin2hex(random_bytes(16));
$_SESSION['yt_caption_state'] = $nonce;

$db  = getDb();
$url = ytCaptionsAuthUrl($db, $nonce);
if (!$url) {
    http_response_code(500);
    echo 'OAuth client not configured (oauth-google-client-id missing in yy_setting).';
    exit;
}

header('Location: ' . $url);
exit;
