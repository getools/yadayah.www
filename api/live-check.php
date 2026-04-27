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

// ── 2. If push says live and it's recent, serve it (no polling needed) ──
if ($pushData && !empty($pushData['live']) && $pushAge < 10800) { // 3 hours
    // Live stream detected via push — but poll YouTube to verify it's still live
    if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
        // Serve cached poll result
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=30');
        readfile($CACHE_FILE);
        exit;
    }

    // Poll to check if stream is still live
    $videoId = $pushData['video_id'] ?? '';
    if ($videoId) {
        $result = pollYouTubeVideoStatus($videoId, $pushData);
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        file_put_contents($CACHE_FILE, $json);

        // If stream ended, clear the push file
        if (empty($result['live'])) {
            $pushData['live'] = false;
            file_put_contents($PUSH_FILE, json_encode($pushData));
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
    $stmt = $db->query("SELECT feed_api_key FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = TRUE AND feed_api_key IS NOT NULL LIMIT 1");
    $apiKey = $stmt->fetchColumn() ?: getenv('YOUTUBE_API_KEY') ?: '';
    if (!$apiKey) return $pushData; // Can't poll without key, return push data as-is

    $url = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
        'part' => 'snippet,liveStreamingDetails',
        'id' => $videoId,
        'key' => $apiKey,
    ]);

    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return $pushData;

    $data = json_decode($response, true);
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

    return ['live' => false];
}

function pollYouTubeLive(): array {
    $db = getDb();
    $stmt = $db->query("SELECT feed_account_id, feed_api_key FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = TRUE AND feed_api_key IS NOT NULL");
    $feeds = $stmt->fetchAll();

    foreach ($feeds as $feed) {
        $channelId = $feed['feed_account_id'];
        $apiKey = $feed['feed_api_key'] ?: getenv('YOUTUBE_API_KEY') ?: '';
        if (!$apiKey || !$channelId || strpos($channelId, 'PL') === 0) continue;

        $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
            'part' => 'snippet',
            'channelId' => $channelId,
            'eventType' => 'live',
            'type' => 'video',
            'key' => $apiKey,
            'maxResults' => 1,
        ]);

        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) continue;

        $data = json_decode($response, true);
        $items = $data['items'] ?? [];

        if (!empty($items)) {
            $item = $items[0];
            $videoId = $item['id']['videoId'] ?? '';
            return [
                'live' => true,
                'title' => $item['snippet']['title'] ?? 'Live Now',
                'url' => 'https://www.youtube.com/watch?v=' . $videoId,
                'embed_url' => 'https://www.youtube.com/embed/' . $videoId . '?autoplay=1',
                'thumbnail' => $item['snippet']['thumbnails']['high']['url'] ?? '',
                'source' => 'youtube',
                'video_id' => $videoId,
                'started_at' => date('c'),
            ];
        }
    }

    return ['live' => false];
}
