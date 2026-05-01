<?php
/**
 * Live stream poller — runs every minute during 10am-10pm ET to detect
 * when YouTube live streams go upcoming or start broadcasting.
 *
 * YouTube's PubSubHubbub does NOT push for live status transitions
 * (upcoming → live → ended). It only pushes for video resource creation
 * and metadata changes. So we must poll to detect status changes.
 *
 * Cron: * 10-21 * * * docker exec yada-www-web-1 php /var/www/html/api/cron-live-poll.php
 *
 * Updates /tmp/yada_live_push.json with the latest live state for any
 * feed where feed_stream_flag = TRUE.
 */
require_once __DIR__ . '/config.php';

$db = getDb();
$PUSH_FILE = sys_get_temp_dir() . '/yada_live_push.json';
$CACHE_FILE = sys_get_temp_dir() . '/yada_live_check.json';

// Time-window check (10am-10pm ET). Use America/New_York (handles DST).
$nyTz = new DateTimeZone('America/New_York');
$nyNow = new DateTime('now', $nyTz);
$nyHour = (int)$nyNow->format('G');
if ($nyHour < 10 || $nyHour >= 22) {
    if (php_sapi_name() === 'cli') echo "[" . date('c') . "] Outside broadcast window ({$nyHour}:00 ET)\n";
    exit(0);
}

// Find feeds flagged for live monitoring
$feeds = $db->query("
    SELECT feed_key, feed_name, feed_account_id, feed_api_key
    FROM yy_feed
    WHERE feed_stream_flag = TRUE AND feed_active_flag = TRUE
      AND lower(feed_site_code) = 'youtube'
")->fetchAll();

if (!$feeds) {
    if (php_sapi_name() === 'cli') echo "[" . date('c') . "] No feeds with feed_stream_flag=TRUE\n";
    exit(0);
}

$envApiKey = getenv('YOUTUBE_API_KEY') ?: '';

foreach ($feeds as $feed) {
    $channelId = $feed['feed_account_id'];
    $apiKey = $feed['feed_api_key'] ?: $envApiKey;
    if (!$apiKey || !$channelId || strpos($channelId, 'PL') === 0) continue;

    // Look for currently live broadcasts on this channel
    $liveResult = pollChannelStream($channelId, $apiKey, 'live');
    $upcomingResult = $liveResult ? null : pollChannelStream($channelId, $apiKey, 'upcoming');

    $newState = $liveResult ?: $upcomingResult;
    $existing = file_exists($PUSH_FILE) ? json_decode(@file_get_contents($PUSH_FILE), true) : null;
    $existingId = $existing['video_id'] ?? '';

    if ($newState) {
        // Stream is live or upcoming
        $newJson = json_encode($newState, JSON_UNESCAPED_UNICODE);
        $existingJson = $existing ? json_encode($existing, JSON_UNESCAPED_UNICODE) : '';

        if ($newJson !== $existingJson) {
            file_put_contents($PUSH_FILE, $newJson);
            file_put_contents($CACHE_FILE, $newJson);

            // Update DB if this is a new stream
            $isNewStream = ($newState['video_id'] !== $existingId);
            if ($isNewStream) {
                try {
                    $db->prepare("UPDATE yy_feed SET feed_stream_dtime = NOW(), feed_source_url = ? WHERE feed_account_id = ? AND feed_stream_flag = TRUE")
                       ->execute([$newState['url'], $channelId]);
                } catch (Throwable $e) {}
            }

            if (php_sapi_name() === 'cli') {
                $status = !empty($newState['live']) && empty($newState['upcoming']) ? 'LIVE' : 'UPCOMING';
                echo "[" . date('c') . "] {$status}: {$feed['feed_name']} — {$newState['title']} ({$newState['video_id']})\n";
            }
        } else {
            if (php_sapi_name() === 'cli') echo "[" . date('c') . "] No change: {$feed['feed_name']}\n";
        }
    } else {
        // No live or upcoming streams — clear if existing was for this channel
        if ($existing && ($existing['channel_id'] ?? '') === $channelId && !empty($existing['live'])) {
            file_put_contents($PUSH_FILE, json_encode(['live' => false]));
            file_put_contents($CACHE_FILE, json_encode(['live' => false]));
            if (php_sapi_name() === 'cli') echo "[" . date('c') . "] Stream ended: {$feed['feed_name']}\n";
        }
    }
}

function pollChannelStream(string $channelId, string $apiKey, string $eventType): ?array {
    $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
        'part' => 'snippet',
        'channelId' => $channelId,
        'eventType' => $eventType, // 'live' or 'upcoming'
        'type' => 'video',
        'key' => $apiKey,
        'maxResults' => 1,
    ]);

    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return null;

    $data = json_decode($resp, true);
    $items = $data['items'] ?? [];
    if (empty($items)) return null;

    $item = $items[0];
    $videoId = $item['id']['videoId'] ?? '';
    if (!$videoId) return null;

    $snippet = $item['snippet'] ?? [];

    // Get live streaming details for scheduled time
    $detailUrl = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
        'part' => 'liveStreamingDetails',
        'id' => $videoId,
        'key' => $apiKey,
    ]);
    $detailResp = @file_get_contents($detailUrl, false, $ctx);
    $liveDetails = [];
    if ($detailResp) {
        $dData = json_decode($detailResp, true);
        $liveDetails = ($dData['items'][0] ?? [])['liveStreamingDetails'] ?? [];
    }

    $isUpcoming = ($eventType === 'upcoming');
    return [
        'live' => true, // both live and upcoming flag the indicator on
        'upcoming' => $isUpcoming,
        'title' => $snippet['title'] ?? ($isUpcoming ? 'Upcoming' : 'Live Now'),
        'url' => 'https://www.youtube.com/watch?v=' . $videoId,
        'embed_url' => 'https://www.youtube.com/embed/' . $videoId . '?autoplay=1',
        'thumbnail' => $snippet['thumbnails']['high']['url'] ?? '',
        'source' => 'youtube',
        'video_id' => $videoId,
        'channel_id' => $channelId,
        'started_at' => $liveDetails['actualStartTime'] ?? date('c'),
        'scheduled_start' => $liveDetails['scheduledStartTime'] ?? null,
        'push_received' => date('c'),
    ];
}
