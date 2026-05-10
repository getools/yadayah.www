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
        // select_account: ALWAYS show the Google account chooser. Without
        // this, re-clicking Connect silently re-uses the previously-signed
        // account, which makes Brand-Account mistakes hard to recover
        // from (caption writes 403 if the picked identity doesn't own
        // the channel). consent: force re-issue of refresh_token.
        'prompt'                 => 'select_account consent',
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

// ── Phase 2: SRT formatter + caption-upload helper ──────────────────

/**
 * Render a feed_item's transcript as SRT text. Returns null if the item
 * has no segments. Each cue overlaps the next by ~1 second so YouTube's
 * player runs the scrolling/rolling caption transition instead of cutting
 * abruptly between cues. The DB-stored segment timings are unchanged —
 * the overlap is applied only at upload time.
 *
 * For non-last cues:  end = nextStart + overlap
 * For the last cue:   end = feed_item_duration_seconds (or start + 5s)
 *
 * SRT format:
 *   1
 *   00:00:00,000 --> 00:00:05,000
 *   Hello world
 *
 *   2
 *   ...
 */
const YT_CAPTIONS_OVERLAP_SECONDS = 1;

function ytCaptionsBuildSrt(PDO $db, int $feedItemKey): ?string {
    $stmt = $db->prepare("
        SELECT feed_item_transcript_sort, feed_item_transcript_segment, feed_item_transcript_text
          FROM yy_feed_item_transcript
         WHERE feed_item_key = ?
         ORDER BY feed_item_transcript_sort
    ");
    $stmt->execute([$feedItemKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;

    // Total duration for the LAST row's end-time fallback + overlap clamp.
    $durStmt = $db->prepare("SELECT feed_item_duration_seconds FROM yy_feed_item WHERE feed_item_key = ?");
    $durStmt->execute([$feedItemKey]);
    $totalSeconds = (int)($durStmt->fetchColumn() ?: 0);

    $srt = '';
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        $startSec = ytCaptionsHmsToSeconds((string)$rows[$i]['feed_item_transcript_segment']);
        if ($i + 1 < $n) {
            $nextStart = ytCaptionsHmsToSeconds((string)$rows[$i + 1]['feed_item_transcript_segment']);
            // Extend this cue past the next cue's start so the two
            // overlap — that's what triggers YouTube's smooth scroll
            // animation between captions.
            $endSec = $nextStart + YT_CAPTIONS_OVERLAP_SECONDS;
        } else {
            $endSec = $totalSeconds > $startSec ? $totalSeconds : $startSec + 5;
        }
        // Don't run past the video end on the last cue or the overlap.
        if ($totalSeconds > 0 && $endSec > $totalSeconds) $endSec = $totalSeconds;
        // Clamp end > start; SRT players choke on zero-length cues.
        if ($endSec <= $startSec) $endSec = $startSec + 1;
        $srt .= ($i + 1) . "\n"
             . ytCaptionsSecondsToSrt($startSec) . ' --> ' . ytCaptionsSecondsToSrt($endSec) . "\n"
             . trim((string)$rows[$i]['feed_item_transcript_text']) . "\n\n";
    }
    return $srt;
}

function ytCaptionsHmsToSeconds(string $hms): int {
    // Accepts "HH:MM:SS" or "MM:SS" or just seconds.
    $parts = explode(':', $hms);
    $secs = 0;
    foreach ($parts as $p) {
        $secs = $secs * 60 + (int)$p;
    }
    return $secs;
}

function ytCaptionsSecondsToSrt(int $totalSec): string {
    $h = intdiv($totalSec, 3600);
    $m = intdiv($totalSec % 3600, 60);
    $s = $totalSec % 60;
    return sprintf('%02d:%02d:%02d,000', $h, $m, $s);
}

/**
 * List existing caption tracks for a video. Returns array of
 * ['id' => ..., 'name' => ..., 'language' => ...] or null on error.
 * Used to detect whether we should INSERT a new track or UPDATE the
 * existing one we previously uploaded.
 */
function ytCaptionsListTracks(string $accessToken, string $videoId): ?array {
    $url = 'https://www.googleapis.com/youtube/v3/captions?' . http_build_query([
        'part'    => 'snippet',
        'videoId' => $videoId,
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
    if (!is_array($data)) return null;
    $out = [];
    foreach (($data['items'] ?? []) as $item) {
        $out[] = [
            'id'         => (string)($item['id'] ?? ''),
            'name'       => (string)($item['snippet']['name'] ?? ''),
            'language'   => (string)($item['snippet']['language'] ?? ''),
            // 'standard' (uploaded by an editor), 'ASR' (auto-generated),
            // or 'forced' — only 'standard' is updatable via this API.
            'trackKind'  => (string)($item['snippet']['trackKind'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Resolve the right caption-track ID to UPDATE for a video, given a
 * candidate ID we may have stored from a prior run.
 *
 *   - If $candidateId exists in captions.list AND its trackKind is NOT
 *     'asr', return it as-is.
 *   - Otherwise (missing, or ASR auto-caption) look for a non-ASR
 *     track named 'English' or language 'en' and return its ID.
 *   - If nothing usable exists, return null → caller should INSERT.
 *
 * The 'asr' filter matters because YouTube auto-generated tracks
 * cannot be updated or deleted via the Data API; PUTting to one
 * returns 403 ("permissions … not sufficient") or 404 ("could not
 * be found"). We never want to target one for replace.
 */
function ytCaptionsResolveUpdatableTrack(string $accessToken, string $videoId, ?string $candidateId): ?string {
    $tracks = ytCaptionsListTracks($accessToken, $videoId);
    if (!is_array($tracks)) return null;
    if ($candidateId) {
        foreach ($tracks as $t) {
            if ($t['id'] === $candidateId) {
                return strcasecmp($t['trackKind'] ?? '', 'asr') === 0 ? null : $candidateId;
            }
        }
        // candidate not in list anymore — fall through to discovery
    }
    foreach ($tracks as $t) {
        if (strcasecmp($t['trackKind'] ?? '', 'asr') === 0) continue;
        if (strcasecmp($t['name'] ?? '', 'English') === 0 || ($t['language'] ?? '') === 'en') {
            return $t['id'];
        }
    }
    return null;
}

/**
 * Upload (insert or update) an SRT caption track on a video.
 *
 * Args:
 *   $accessToken  — fresh OAuth access token for the channel owner
 *   $videoId      — YouTube videoId (the 11-char ID)
 *   $srt          — SRT text body
 *   $existingId   — captions resource ID to UPDATE (null = INSERT new)
 *   $name         — track display name (e.g. 'English (auto)' or 'English')
 *   $language     — BCP-47 language code (e.g. 'en')
 *
 * Returns: ['ok' => bool, 'track_id' => string|null, 'http' => int, 'message' => string|null]
 *
 * The captions API uses a multipart/related body — one JSON metadata
 * part (snippet) plus the file body part. PHP's CURLFile produces
 * multipart/form-data which YouTube REJECTS for this endpoint, so we
 * build the multipart body manually.
 */
function ytCaptionsUploadSrt(
    string $accessToken,
    string $videoId,
    string $srt,
    ?string $existingId,
    string $name,
    string $language
): array {
    $boundary = 'yyboundary' . bin2hex(random_bytes(8));

    // INSERT needs videoId in the snippet; UPDATE doesn't (the track ID
    // already identifies the target). Both accept name/language updates.
    $snippet = ['name' => $name];
    if ($existingId === null) {
        $snippet['videoId'] = $videoId;
        $snippet['language'] = $language;
    } else {
        // captions.update accepts language change but typically we keep it.
        $snippet['language'] = $language;
    }
    $jsonPart = json_encode(['snippet' => $snippet], JSON_UNESCAPED_UNICODE);

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= $jsonPart . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/srt\r\n\r\n";
    $body .= $srt . "\r\n";
    $body .= "--{$boundary}--\r\n";

    if ($existingId === null) {
        // INSERT
        $url    = 'https://www.googleapis.com/upload/youtube/v3/captions?part=snippet&uploadType=multipart';
        $method = 'POST';
    } else {
        // UPDATE (PUT)
        $url    = 'https://www.googleapis.com/upload/youtube/v3/captions?part=snippet&uploadType=multipart&id=' . urlencode($existingId);
        $method = 'PUT';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http >= 200 && $http < 300 && $resp) {
        $j = json_decode($resp, true);
        $trackId = is_array($j) ? (string)($j['id'] ?? '') : '';
        return ['ok' => true, 'track_id' => $trackId ?: $existingId, 'http' => $http, 'message' => null];
    }
    // Surface the most useful error — Google returns JSON with .error.message
    $msg = $resp ?: '';
    $j = $resp ? json_decode($resp, true) : null;
    if (is_array($j) && !empty($j['error']['message'])) $msg = (string)$j['error']['message'];
    return ['ok' => false, 'track_id' => null, 'http' => $http, 'message' => substr($msg, 0, 500)];
}
