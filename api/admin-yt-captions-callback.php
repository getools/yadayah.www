<?php
/**
 * OAuth callback for the YouTube captions grant flow. Google redirects
 * here after the channel owner clicks Allow on the consent screen.
 *
 * Verifies the state nonce (set by admin-yt-captions-connect.php),
 * exchanges the auth code for tokens, fetches the channel id/title,
 * and stores everything in yy_setting. Then redirects back to
 * /admin-feeds with a flash so the UI can show "Connected".
 *
 * Admin-only — but the redirect from Google may not carry the user's
 * session if cookies are set with strict SameSite. We defensively
 * require the session-stored state nonce; if missing, the request
 * isn't a legitimate OAuth callback.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/youtube-caption-helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$err = $_GET['error'] ?? '';
if ($err) {
    header('Location: /admin-feeds?yt_caption_err=' . urlencode($err));
    exit;
}

$code  = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$expected = $_SESSION['yt_caption_state'] ?? '';
unset($_SESSION['yt_caption_state']);

if (!$code || !$state || $state !== $expected) {
    header('Location: /admin-feeds?yt_caption_err=invalid_state');
    exit;
}

// Admin gate: callback must originate from a logged-in admin session.
// requireAdmin() exits with 401 if not — we'd rather bounce to feeds
// with a flash message so the admin sees what happened.
$user = $_SESSION['user'] ?? null;
if (!$user || empty($user['user_admin_flag'])) {
    header('Location: /admin-feeds?yt_caption_err=not_admin');
    exit;
}

$db = getDb();
$tokens = ytCaptionsExchangeCode($db, $code);
if (!$tokens || empty($tokens['refresh_token'])) {
    // Google returns refresh_token only on first consent — if we already
    // had one and the user re-grants, refresh_token may be omitted.
    // The connect endpoint forces prompt=consent specifically to avoid
    // this; if it still happens, surface a clear error.
    header('Location: /admin-feeds?yt_caption_err=no_refresh_token');
    exit;
}

$accessToken = $tokens['access_token'] ?? '';
$channel = $accessToken ? ytCaptionsFetchChannel($accessToken) : null;

ytSetSetting($db, 'youtube-captions-refresh-token', $tokens['refresh_token']);
if ($channel) {
    ytSetSetting($db, 'youtube-captions-channel-id',    $channel['id']);
    ytSetSetting($db, 'youtube-captions-channel-title', $channel['title']);
}

header('Location: /admin-feeds?yt_caption_ok=1');
exit;
