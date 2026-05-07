<?php
/**
 * OAuth callback for the YouTube captions per-feed grant flow.
 *
 * State is "<nonce>:<feed_key>". Verify nonce against the session-stashed
 * one, exchange code for tokens, fetch channel id/title, and write
 * everything to that feed's yy_feed row.
 *
 * On success: redirect to /admin-feeds?yt_caption_ok=<feed_key>
 * On error:   redirect to /admin-feeds?yt_caption_err=<reason>
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/youtube-caption-helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$err = $_GET['error'] ?? '';
if ($err) {
    header('Location: /admin-feeds?yt_caption_err=' . urlencode($err));
    exit;
}

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$expected = $_SESSION['yt_caption_state'] ?? '';
unset($_SESSION['yt_caption_state']);

// State format: "<nonce>:<feed_key>"
$parts = explode(':', $state, 2);
$nonceFromState = $parts[0] ?? '';
$feedKey = (int)($parts[1] ?? 0);

if (!$code || !$nonceFromState || $nonceFromState !== $expected || $feedKey <= 0) {
    header('Location: /admin-feeds?yt_caption_err=invalid_state');
    exit;
}

// Admin gate. Session cookie (SameSite=Lax) is sent on the redirect
// back from Google; if it isn't present, bounce back with a clear error.
if (empty($_SESSION['user_key'])) {
    header('Location: /admin-feeds?yt_caption_err=not_signed_in');
    exit;
}

$db = getDb();
$tokens = ytCaptionsExchangeCode($db, $code);
if (!$tokens || empty($tokens['refresh_token'])) {
    header('Location: /admin-feeds?yt_caption_err=no_refresh_token');
    exit;
}

$accessToken = $tokens['access_token'] ?? '';
// Pull the feed's known handle / channel id as fallback hints — Brand
// Account confusion can make mine=true return empty even when the auth
// is for the right channel. Helper falls back to forHandle / id lookup.
$hintStmt = $db->prepare("SELECT feed_account_id, feed_name FROM yy_feed WHERE feed_key = ?");
$hintStmt->execute([$feedKey]);
$hint = $hintStmt->fetch();
$handleHint = null;
if ($hint) {
    // feed_account_id is sometimes the channel id (UC...), sometimes a
    // handle, depending on how the operator entered it. We pass both
    // possibilities; the helper picks the one that produces a hit.
    $accId = (string)($hint['feed_account_id'] ?? '');
    if ($accId !== '') {
        if (str_starts_with($accId, 'UC')) {
            $channelIdHint = $accId;
            $handleHint = null;
        } else {
            $channelIdHint = null;
            $handleHint = ($accId[0] === '@' ? $accId : '@' . $accId);
        }
    }
}
// Also try parsing a handle out of feed_name like "YouTube - @YahowahsHerald".
if (!$handleHint && isset($hint['feed_name'])) {
    if (preg_match('/@([A-Za-z0-9._-]+)/', $hint['feed_name'], $m)) {
        $handleHint = '@' . $m[1];
    }
}
$channelIdHint = $channelIdHint ?? null;
$channel = $accessToken ? ytCaptionsFetchChannel($accessToken, $handleHint, $channelIdHint) : null;

$db->prepare("
    UPDATE yy_feed
       SET feed_yt_caption_refresh_token   = ?,
           feed_yt_caption_channel_id      = ?,
           feed_yt_caption_channel_title   = ?,
           feed_yt_caption_connected_dtime = NOW()
     WHERE feed_key = ?
")->execute([
    $tokens['refresh_token'],
    $channel['id']    ?? null,
    $channel['title'] ?? null,
    $feedKey,
]);

header('Location: /admin-feeds?yt_caption_ok=' . $feedKey);
exit;
