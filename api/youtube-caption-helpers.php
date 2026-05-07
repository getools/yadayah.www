<?php
/**
 * YouTube captions API helpers — separate from oauth-helpers.php (which is
 * for user-login OAuth, not service-to-API OAuth).
 *
 * Auth model: a single channel-owner OAuth refresh token is stored in
 * yy_setting under 'youtube-captions-refresh-token'. From it we mint an
 * access token on demand. The refresh token is granted ONCE by the
 * channel owner via the connect/callback flow.
 *
 * Reused settings:
 *   oauth-google-client-id     — existing Google OAuth 2.0 client ID
 *   oauth-google-client-secret — existing client secret
 *
 * New settings populated by the callback:
 *   youtube-captions-refresh-token — long-lived refresh token
 *   youtube-captions-channel-id    — the channel we're authorized for
 *   youtube-captions-channel-title — for display in admin UI
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
 * Upsert a setting row.
 */
function ytSetSetting(PDO $db, string $code, string $value): void {
    $db->prepare(
        "INSERT INTO yy_setting (setting_code, setting_value) VALUES (?, ?)
         ON CONFLICT (setting_code) DO UPDATE SET setting_value = EXCLUDED.setting_value"
    )->execute([$code, $value]);
}

/**
 * Build the URL the channel owner should be redirected to in order to
 * grant captions write access. Caller is responsible for storing
 * $_SESSION['yt_caption_state'] and verifying it on callback.
 */
function ytCaptionsAuthUrl(PDO $db, string $stateNonce): ?string {
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
        'state'                  => $stateNonce,
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
 * Mint an access token from the stored refresh token. Returns the access
 * token string (~1 hour TTL) or null if no refresh token / refresh fails.
 */
function ytCaptionsAccessToken(PDO $db): ?string {
    $refresh      = ytSetting($db, 'youtube-captions-refresh-token');
    $clientId     = ytSetting($db, 'oauth-google-client-id');
    $clientSecret = ytSetting($db, 'oauth-google-client-secret');
    if (!$refresh || !$clientId || !$clientSecret) return null;

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
function ytCaptionsFetchChannel(string $accessToken): ?array {
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
    if (!is_array($data) || empty($data['items'][0])) return null;
    $item = $data['items'][0];
    return [
        'id'    => (string)($item['id'] ?? ''),
        'title' => (string)($item['snippet']['title'] ?? ''),
    ];
}
