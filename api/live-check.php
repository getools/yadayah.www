<?php
/**
 * Live feed check API.
 *
 * Primary: reads from PubSubHubbub push notification cache (no API calls).
 * Polling fallback: only used when:
 *   1. Push subscription appears broken (no push file or very stale)
 *   2. A live stream is active — polls to detect when it ends (up to 3 hours)
 *
 * GET /api/live-check.php → {"live":true,"title":"...","url":"...","source":"youtube"}
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

// Simulate/override mode: requires admin with feeds page access
if (!empty($_GET['simulate']) || !empty($_GET['live_override'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $userKey = $_SESSION['user_key'] ?? null;
    $hasFeedsAccess = false;
    if ($userKey) {
        $db = getDb();
        $permStmt = $db->prepare("
            SELECT us.user_setting_value FROM yy_user_setting us
            JOIN yy_setting s ON us.setting_key = s.setting_key
            WHERE us.user_key = ? AND s.setting_scope_code = 'admin' AND s.setting_group_code = 'pages' AND s.setting_code = 'feeds'
        ");
        $permStmt->execute([$userKey]);
        $hasFeedsAccess = $permStmt->fetchColumn() === '1';
    }
    if (!$hasFeedsAccess) {
        errorResponse('Requires admin with Feeds access', 403);
    }

    $sim = $_GET['simulate'] ?? $_GET['live_override'] ?? '';
    $simFile = sys_get_temp_dir() . '/yada_live_simulate.json';
    if ($sim === 'off') {
        @unlink($simFile);
        jsonResponse(['live' => false, 'simulated' => 'off']);
    }
    // Use a real recent YouTube video as a stand-in
    $stmt = $db->query("SELECT feed_item_external_id FROM yy_feed_item WHERE feed_key = 1 AND feed_item_active_flag = TRUE ORDER BY feed_item_publish_dtime DESC LIMIT 1");
    $videoId = $stmt->fetchColumn() ?: 'jfKfPfyJRdk';
    $result = [
        'live' => true,
        'title' => 'Simulated Live Stream (Test)',
        'url' => 'https://www.youtube.com/watch?v=' . $videoId,
        'embed_url' => 'https://www.youtube.com/embed/' . $videoId . '?autoplay=1',
        'video_id' => $videoId,
        'source' => 'youtube',
        'simulated' => true,
    ];
    file_put_contents($simFile, json_encode($result));
    jsonResponse($result);
}
// Check if simulate file exists
$simFile = sys_get_temp_dir() . '/yada_live_simulate.json';
if (file_exists($simFile)) {
    $simData = json_decode(file_get_contents($simFile), true);
    if ($simData && !empty($simData['live'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($simData);
        exit;
    }
}

$PUSH_FILE = sys_get_temp_dir() . '/yada_live_push.json';
$CACHE_FILE = sys_get_temp_dir() . '/yada_live_check.json';
$SUBS_FILE = sys_get_temp_dir() . '/yada_push_subscriptions.json';
$CACHE_TTL = 120; // 2 minutes between polls

// ── 1. Check push notification state ──
$pushData = null;
$pushAge = PHP_INT_MAX;
if (file_exists($PUSH_FILE)) {
    $pushAge = time() - filemtime($PUSH_FILE);
    $pushData = json_decode(file_get_contents($PUSH_FILE), true);
}

// ── 2. If push says live (which includes upcoming with live=true flag) and it's recent ──
if ($pushData && !empty($pushData['live']) && $pushAge < 10800) { // 3 hours
    // Stream detected via push — but poll YouTube to verify status
    if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=30');
        readfile($CACHE_FILE);
        exit;
    }

    $videoId = $pushData['video_id'] ?? '';
    if ($videoId) {
        $result = pollYouTubeVideoStatus($videoId, $pushData);
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        file_put_contents($CACHE_FILE, $json);

        // If stream ended (live becomes false), clear the push file
        if (empty($result['live'])) {
            file_put_contents($PUSH_FILE, json_encode(['live' => false]));
        } else {
            // Update push file with latest poll state (preserves upcoming → live transition)
            file_put_contents($PUSH_FILE, $json);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=30');
        echo $json;
        exit;
    }
}

// ── 3. If push says not live and push is healthy, serve that ──
$pushHealthy = isPushHealthy($SUBS_FILE, $PUSH_FILE);
if ($pushHealthy && $pushData && empty($pushData['live'])) {
    $result = ['live' => false];
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    echo json_encode($result);
    exit;
}

// ── 4. Push unhealthy — fall back to polling ──
if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    readfile($CACHE_FILE);
    exit;
}

$result = pollYouTubeLive();

$json = json_encode($result, JSON_UNESCAPED_UNICODE);
file_put_contents($CACHE_FILE, $json);

// If polling found a live stream, also write it to push file so subsequent
// requests don't keep polling
if (!empty($result['live'])) {
    $result['push_received'] = date('c');
    $result['started_at'] = $result['started_at'] ?? date('c');
    file_put_contents($PUSH_FILE, json_encode($result));
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
echo $json;

// ── Helper functions ──

function isPushHealthy(string $subsFile, string $pushFile): bool {
    // Push is healthy if subscriptions exist and were renewed within 11 days
    if (!file_exists($subsFile)) return false;
    $subs = json_decode(file_get_contents($subsFile), true);
    if (!$subs || !is_array($subs)) return false;

    foreach ($subs as $sub) {
        $subTime = strtotime($sub['subscribed_at'] ?? '');
        if ($subTime && (time() - $subTime) < 950400) { // 11 days
            return true;
        }
    }
    return false;
}

function pollYouTubeVideoStatus(string $videoId, array $pushData): array {
    $db = getDb();
    // Try every available YouTube API key (handles quota exhaustion on any single key)
    $stmt = $db->query("SELECT feed_api_key FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = TRUE AND feed_api_key IS NOT NULL AND feed_api_key != ''");
    $apiKeys = array_unique(array_column($stmt->fetchAll(), 'feed_api_key'));
    $envKey = getenv('YOUTUBE_API_KEY');
    if ($envKey) $apiKeys[] = $envKey;
    if (!$apiKeys) return $pushData;

    $data = null;
    foreach ($apiKeys as $apiKey) {
        $url = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
            'part' => 'snippet,liveStreamingDetails',
            'id' => $videoId,
            'key' => $apiKey,
        ]);
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) continue;
        $parsed = json_decode($response, true);
        if (isset($parsed['error'])) continue; // try next key (quota exhausted)
        $data = $parsed;
        break;
    }
    if (!$data) return $pushData; // all keys failed — keep existing state

    $items = $data['items'] ?? [];
    if (empty($items)) return ['live' => false];

    $snippet = $items[0]['snippet'] ?? [];
    $liveDetails = $items[0]['liveStreamingDetails'] ?? [];
    $status = $snippet['liveBroadcastContent'] ?? 'none';

    if ($status === 'live') {
        return [
            'live' => true,
            'title' => $snippet['title'] ?? $pushData['title'] ?? 'Live Now',
            'url' => $pushData['url'] ?? 'https://www.youtube.com/watch?v=' . $videoId,
            'embed_url' => 'https://www.youtube.com/embed/' . $videoId . '?autoplay=1',
            'thumbnail' => $snippet['thumbnails']['high']['url'] ?? $pushData['thumbnail'] ?? '',
            'source' => 'youtube',
            'video_id' => $videoId,
            'started_at' => $liveDetails['actualStartTime'] ?? $pushData['started_at'] ?? date('c'),
        ];
    }

    if ($status === 'upcoming') {
        return [
            'live' => true,
            'upcoming' => true,
            'title' => $snippet['title'] ?? $pushData['title'] ?? 'Upcoming',
            'url' => $pushData['url'] ?? 'https://www.youtube.com/watch?v=' . $videoId,
            'embed_url' => 'https://www.youtube.com/embed/' . $videoId . '?autoplay=1',
            'thumbnail' => $snippet['thumbnails']['high']['url'] ?? $pushData['thumbnail'] ?? '',
            'source' => 'youtube',
            'video_id' => $videoId,
            'scheduled_start' => $liveDetails['scheduledStartTime'] ?? null,
        ];
    }

    return ['live' => false];
}

function pollYouTubeLive(): array {
    $db = getDb();
    // Only check channels explicitly flagged for live-stream monitoring
    $stmt = $db->query("SELECT feed_account_id, feed_api_key FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = TRUE AND feed_stream_flag = TRUE AND feed_account_id LIKE 'UC%'");
    $streamFeeds = $stmt->fetchAll();
    if (!$streamFeeds) return ['live' => false];

    // Collect ALL available YouTube API keys as fallback (if a feed's own key is quota-exhausted)
    $allKeysStmt = $db->query("SELECT feed_api_key FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = TRUE AND feed_api_key IS NOT NULL AND feed_api_key != ''");
    $apiKeys = array_unique(array_column($allKeysStmt->fetchAll(), 'feed_api_key'));
    $envKey = getenv('YOUTUBE_API_KEY');
    if ($envKey) $apiKeys[] = $envKey;

    // Check for live first, then upcoming
    foreach (['live', 'upcoming'] as $eventType) {
        foreach ($streamFeeds as $feed) {
            $channelId = $feed['feed_account_id'];
            // Try each available API key until one succeeds (handles quota exhaustion)
            $keysToTry = array_unique(array_filter(array_merge([$feed['feed_api_key']], $apiKeys)));
            $items = [];
            $response = false;
            foreach ($keysToTry as $apiKey) {
                $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
                    'part' => 'snippet',
                    'channelId' => $channelId,
                    'eventType' => $eventType,
                    'type' => 'video',
                    'key' => $apiKey,
                    'maxResults' => 1,
                ]);
                $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
                $response = @file_get_contents($url, false, $ctx);
                if ($response === false) continue;
                $data = json_decode($response, true);
                if (isset($data['error'])) continue; // try next key
                $items = $data['items'] ?? [];
                break; // success
            }
            if ($response === false) continue;
            $data = json_decode($response, true);
            $items = $data['items'] ?? [];

            if (!empty($items)) {
                $item = $items[0];
                $videoId = $item['id']['videoId'] ?? '';
                $isUpcoming = $eventType === 'upcoming';

                $result = [
                    'live' => true,
                    'upcoming' => $isUpcoming,
                    'title' => $item['snippet']['title'] ?? ($isUpcoming ? 'Upcoming' : 'Live Now'),
                    'url' => 'https://www.youtube.com/watch?v=' . $videoId,
                    'embed_url' => 'https://www.youtube.com/embed/' . $videoId . '?autoplay=1',
                    'thumbnail' => $item['snippet']['thumbnails']['high']['url'] ?? '',
                    'source' => 'youtube',
                    'video_id' => $videoId,
                ];

                if ($isUpcoming) {
                    // Get scheduled start time from video details
                    $detailUrl = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
                        'part' => 'liveStreamingDetails',
                        'id' => $videoId,
                        'key' => $apiKey,
                    ]);
                    $detailResp = @file_get_contents($detailUrl, false, $ctx);
                    if ($detailResp) {
                        $detailData = json_decode($detailResp, true);
                        $liveDetails = $detailData['items'][0]['liveStreamingDetails'] ?? [];
                        $result['scheduled_start'] = $liveDetails['scheduledStartTime'] ?? null;
                    }
                } else {
                    $result['started_at'] = date('c');
                }

                return $result;
            }
        }
    }

    return ['live' => false];
}
