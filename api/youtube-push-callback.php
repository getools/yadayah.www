<?php
/**
 * YouTube PubSubHubbub (WebSub) callback endpoint.
 *
 * Receives push notifications from YouTube when:
 * - A new video/live stream is published on a subscribed channel
 * - A video title or description is updated
 *
 * GET  — Hub verification (responds with hub.challenge)
 * POST — Atom feed notification from YouTube
 *
 * Stores live stream status in a cache file for live-check.php to read.
 * Also triggers a feed sync when a new video is detected.
 */

$logFile = sys_get_temp_dir() . '/youtube_push.log';
$liveStatusFile = sys_get_temp_dir() . '/yada_live_push.json';

function pushLog(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// ── GET: Hub verification ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $challenge = $_GET['hub_challenge'] ?? '';
    $mode = $_GET['hub_mode'] ?? '';
    $topic = $_GET['hub_topic'] ?? '';

    pushLog("Verification: mode={$mode} topic={$topic}");

    if ($mode === 'subscribe' || $mode === 'unsubscribe') {
        // Respond with the challenge to confirm subscription
        header('Content-Type: text/plain');
        http_response_code(200);
        echo $challenge;
        exit;
    }

    http_response_code(404);
    exit;
}

// ── POST: Atom feed notification ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    pushLog("Notification received: " . strlen($body) . " bytes");

    if (!$body) {
        http_response_code(200);
        exit;
    }

    // Verify HMAC signature if secret was provided during subscription
    $secret = getenv('YOUTUBE_PUSH_SECRET') ?: 'yada2026push';
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';
    if ($signature) {
        $expected = 'sha1=' . hash_hmac('sha1', $body, $secret);
        if (!hash_equals($expected, $signature)) {
            pushLog("HMAC verification failed");
            http_response_code(403);
            exit;
        }
    }

    // Parse Atom XML
    $xml = @simplexml_load_string($body);
    if (!$xml) {
        pushLog("Failed to parse XML");
        http_response_code(200);
        exit;
    }

    // Register YouTube namespaces
    $namespaces = $xml->getNamespaces(true);
    $yt = isset($namespaces['yt']) ? $namespaces['yt'] : 'http://www.youtube.com/xml/schemas/2015';

    $entries = $xml->entry ?? [];
    if (empty($entries)) {
        // Might be a deletion notification
        $deleted = $xml->children($yt)->videoId ?? null;
        if ($deleted) {
            pushLog("Deleted video: {$deleted}");
        }
        http_response_code(200);
        exit;
    }

    foreach ($entries as $entry) {
        $videoId = (string)($entry->children($yt)->videoId ?? '');
        $channelId = (string)($entry->children($yt)->channelId ?? '');
        $title = (string)($entry->title ?? '');
        $published = (string)($entry->published ?? '');
        $updated = (string)($entry->updated ?? '');
        $link = '';
        foreach ($entry->link as $l) {
            if ((string)$l['rel'] === 'alternate') {
                $link = (string)$l['href'];
                break;
            }
        }

        pushLog("Video: id={$videoId} channel={$channelId} title={$title} published={$published}");

        if (!$videoId) continue;

        // Check if this is a live stream by querying YouTube Data API
        $isLive = false;
        $apiKey = '';

        // Try to get API key from DB
        try {
            require_once __DIR__ . '/config.php';
            $db = getDb();
            $stmt = $db->prepare("SELECT feed_api_key FROM yy_feed WHERE feed_account_id = ? AND feed_active_flag = TRUE LIMIT 1");
            $stmt->execute([$channelId]);
            $apiKey = $stmt->fetchColumn() ?: '';
            if (!$apiKey) $apiKey = getenv('YOUTUBE_API_KEY') ?: '';
        } catch (Throwable $e) {
            pushLog("DB error: " . $e->getMessage());
        }

        if ($apiKey) {
            $checkUrl = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
                'part' => 'snippet,liveStreamingDetails',
                'id' => $videoId,
                'key' => $apiKey,
            ]);
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $resp = @file_get_contents($checkUrl, false, $ctx);
            if ($resp) {
                $vData = json_decode($resp, true);
                $items = $vData['items'] ?? [];
                if (!empty($items)) {
                    $snippet = $items[0]['snippet'] ?? [];
                    $liveDetails = $items[0]['liveStreamingDetails'] ?? [];
                    $liveBroadcastContent = $snippet['liveBroadcastContent'] ?? 'none';

                    if ($liveBroadcastContent === 'live' || $liveBroadcastContent === 'upcoming') {
                        $isLive = true; // treat upcoming as "active" for indicator purposes
                        $isUpcoming = ($liveBroadcastContent === 'upcoming');
                        $liveData = [
                            'live' => true, // show indicator for both live and upcoming
                            'upcoming' => $isUpcoming,
                            'title' => $title ?: ($snippet['title'] ?? ($isUpcoming ? 'Upcoming' : 'Live Now')),
                            'url' => $link ?: ('https://www.youtube.com/watch?v=' . $videoId),
                            'embed_url' => 'https://www.youtube.com/embed/' . $videoId . '?autoplay=1',
                            'thumbnail' => $snippet['thumbnails']['high']['url'] ?? '',
                            'source' => 'youtube',
                            'video_id' => $videoId,
                            'channel_id' => $channelId,
                            'started_at' => $liveDetails['actualStartTime'] ?? date('c'),
                            'scheduled_start' => $liveDetails['scheduledStartTime'] ?? null,
                            'push_received' => date('c'),
                        ];
                        file_put_contents($liveStatusFile, json_encode($liveData));
                        pushLog(($isUpcoming ? 'UPCOMING STREAM' : 'LIVE STREAM') . " DETECTED: {$title}");

                        // Also update the polling cache so live-check.php picks it up immediately
                        $pollingCache = sys_get_temp_dir() . '/yada_live_check.json';
                        file_put_contents($pollingCache, json_encode($liveData));

                        // Set feed_stream_dtime to capture FIRST detection of this stream
                        // (whether the trigger was the upcoming push or the live push — covers
                        // scenarios where the upcoming notification was missed).
                        // Never touch feed_stream_flag — it's manually controlled.
                        try {
                            // Check if this is a new stream event (different video_id than last detected)
                            $existingFeed = $db->prepare("SELECT feed_source_url, feed_stream_dtime FROM yy_feed WHERE feed_account_id = ? AND feed_stream_flag = TRUE LIMIT 1");
                            $existingFeed->execute([$channelId]);
                            $existing = $existingFeed->fetch();

                            $expectedUrl = 'https://www.youtube.com/watch?v=' . $videoId;
                            $isNewStream = !$existing || strpos($existing['feed_source_url'] ?? '', $videoId) === false;

                            if ($isNewStream) {
                                // First time we've seen THIS stream — set the timestamp
                                $db->prepare("UPDATE yy_feed SET feed_stream_dtime = NOW(), feed_source_url = ? WHERE feed_account_id = ? AND feed_stream_flag = TRUE")
                                   ->execute([$expectedUrl, $channelId]);
                                pushLog("Set feed_stream_dtime for new {$liveBroadcastContent} stream: {$videoId}");
                            } else {
                                // Same stream, just status change (upcoming → live) — only update URL if missing
                                $db->prepare("UPDATE yy_feed SET feed_source_url = COALESCE(feed_source_url, ?) WHERE feed_account_id = ? AND feed_stream_flag = TRUE")
                                   ->execute([$expectedUrl, $channelId]);
                            }
                        } catch (Throwable $e) {
                            pushLog("Could not update feed_stream_dtime: " . $e->getMessage());
                        }
                    } elseif ($liveBroadcastContent === 'none') {
                        // Stream ended or was a regular video — clear status if it was ours
                        $existing = @json_decode(@file_get_contents($liveStatusFile), true);
                        if ($existing && ($existing['video_id'] ?? '') === $videoId) {
                            file_put_contents($liveStatusFile, json_encode(['live' => false]));
                            // Clear polling cache too
                            $pollingCache = sys_get_temp_dir() . '/yada_live_check.json';
                            file_put_contents($pollingCache, json_encode(['live' => false]));
                            pushLog("Stream ended: {$title}");
                        }
                    }
                }
            }
        }

        // Trigger a feed sync for new videos (non-live)
        if (!$isLive && $published) {
            $pubTime = strtotime($published);
            // Only sync if published within last hour (fresh upload)
            if ($pubTime && (time() - $pubTime) < 3600) {
                pushLog("New video detected, triggering sync");
                $syncScript = __DIR__ . '/sync-youtube.php';
                if (file_exists($syncScript)) {
                    exec("php " . escapeshellarg($syncScript) . " > /dev/null 2>&1 &");
                }
            }
        }
    }

    http_response_code(200);
    exit;
}

http_response_code(405);
