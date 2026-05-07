<?php
/**
 * YouTube captions API helpers — separate from oauth-helpers.php (which
 * is for user-login OAuth, not service-to-API OAuth).
 *
 * Auth model: a per-feed channel-owner OAuth refresh token is stored in
 * yy_feed.feed_yt_caption_refresh_token (one column per feed row). From
 * it we mint an access token on demand. Each feed (each YouTube channel)
 * has its own refresh token, granted ONCE by the channel owner via the
 * connect/callback flow.
 *
 * Reused settings (yy_setting):
 *   oauth-google-client-id     — existing Google OAuth 2.0 client ID
 *   oauth-google-client-secret — existing client secret
 */

const YT_CAPTIONS_SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';
const YT_CAPTIONS_REDIRECT_PATH = '/api/admin-yt-captions-callback.php';

function ytCaptionsRedirectUri(): string {
    // Production-only redirect — Google requires the URL be pre-registered
    // in the OAuth client config, so it can't vary by request host.
    return 'https://yadayah.com' . YT_CAPTIONS_REDIRECT_PATH;
}

/**
 * Fetch a setting from yy_setting; null if missing/empty.
 */
function ytSetting(PDO $db, string $code): ?string {
    $stmt = $db->prepare("SELECT setting_value FROM yy_setting WHERE setting_code = ?");
    $stmt->execute([$code]);
    $v = $stmt->fetchColumn();
    return $v === false || $v === '' ? null : (string)$v;
}

/**
 * Build the URL the channel owner should be redirected to in order to
 * grant captions write access. State carries the feed_key so the
 * callback knows which feed row to update.
 */
function ytCaptionsAuthUrl(PDO $db, string $stateNonce, int $feedKey): ?string {
    $clientId = ytSetting($db, 'oauth-google-client-id');
    if (!$clientId) return null;
    $params = [
        'client_id'              => $clientId,
        'redirect_uri'           => ytCaptionsRedirectUri(),
        'response_type'          => 'code',
        'scope'                  => YT_CAPTIONS_SCOPE,
        'access_type'            => 'offline',     // we want a refresh_token
        'prompt'                 => 'consent',     // force refresh_token even on re-auth
        'include_granted_scopes' => 'true',
        'state'                  => $stateNonce . ':' . $feedKey,
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Exchange an authorization code for tokens. Returns the parsed JSON
 * response (with access_token + refresh_token + expires_in), or null
 * on failure.
 */
function ytCaptionsExchangeCode(PDO $db, string $code): ?array {
    $clientId     = ytSetting($db, 'oauth-google-client-id');
    $clientSecret = ytSetting($db, 'oauth-google-client-secret');
    if (!$clientId || !$clientSecret) return null;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => ytCaptionsRedirectUri(),
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$body) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Mint an access token for a SPECIFIC feed's refresh token. Returns the
 * access token string (~1 hour TTL) or null if no refresh token / refresh fails.
 */
function ytCaptionsAccessTokenForFeed(PDO $db, int $feedKey): ?string {
    $stmt = $db->prepare("SELECT feed_yt_caption_refresh_token FROM yy_feed WHERE feed_key = ?");
    $stmt->execute([$feedKey]);
    $refresh = $stmt->fetchColumn();
    if (!$refresh) return null;

    $clientId     = ytSetting($db, 'oauth-google-client-id');
    $clientSecret = ytSetting($db, 'oauth-google-client-secret');
    if (!$clientId || !$clientSecret) return null;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$body) return null;
    $data = json_decode($body, true);
    return is_array($data) && isset($data['access_token']) ? (string)$data['access_token'] : null;
}

/**
 * After a successful token exchange, fetch the authenticated user's
 * default YouTube channel (id + title) so the admin UI can show
 * "Connected to: <Channel Title>".
 */
function ytCaptionsFetchChannel(string $accessToken, ?string $fallbackHandle = null, ?string $fallbackChannelId = null): ?array {
    // mine=true is the canonical lookup for OAuth-authorized channels.
    // For Brand Accounts the personal Google account the user signed in
    // with may not surface the brand-managed channel here; in that case
    // fall back to the feed row's known handle/channel-id (passed by the
    // caller) and look it up by forHandle / id. The token may or may
    // not actually authorize writes to that channel — that gets verified
    // later when captions.insert runs.
    $url = 'https://www.googleapis.com/youtube/v3/channels?' . http_build_query([
        'part' => 'snippet',
        'mine' => 'true',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$body) return null;
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['items'][0])) {
        // mine=true returned empty — likely Brand Account confusion.
        // Try the public lookup using the caller-supplied handle/id.
        $lookupParams = null;
        if ($fallbackHandle)        $lookupParams = ['part' => 'snippet', 'forHandle' => $fallbackHandle];
        else if ($fallbackChannelId) $lookupParams = ['part' => 'snippet', 'id'        => $fallbackChannelId];
        if (!$lookupParams) return null;
        $ch2 = curl_init('https://www.googleapis.com/youtube/v3/channels?' . http_build_query($lookupParams));
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body2 = curl_exec($ch2);
        $http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($http2 !== 200 || !$body2) return null;
        $data = json_decode($body2, true);
        if (!is_array($data) || empty($data['items'][0])) return null;
    }
    $item = $data['items'][0];
    return [
        'id'    => (string)($item['id'] ?? ''),
        'title' => (string)($item['snippet']['title'] ?? ''),
    ];
}
