<?php
/**
 * YouTube WebSub (PubSubHubbub) callback.
 * Receives push notifications when the channel publishes, goes live, or updates.
 *
 * GET  — hub verification (returns hub.challenge)
 * POST — feed update notification (Atom XML payload)
 *
 * Subscribe via:
 *   POST https://pubsubhubbub.appspot.com/subscribe
 *   hub.mode=subscribe
 *   hub.topic=https://www.youtube.com/xml/feeds/videos.xml?channel_id=CHANNEL_ID
 *   hub.callback=https://yadayah.com/api/youtube-webhook.php
 *   hub.verify=async
 *   hub.lease_seconds=864000 (10 days, must re-subscribe periodically)
 */
require_once __DIR__ . '/config.php';

// ── GET: Hub verification ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $challenge = $_GET['hub_challenge'] ?? '';
    if ($challenge) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(200);
    exit;
}

// ── POST: Feed update notification ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    if (!$body) { http_response_code(200); exit; }

    // Log the notification
    $db = getDb();
    $db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag) VALUES ('youtube_webhook', 'info', 'YouTube push notification received', ?, TRUE)")
       ->execute([substr($body, 0, 2000)]);

    // Parse Atom XML
    try {
        $xml = new SimpleXMLElement($body);
        $xml->registerXPathNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
        $xml->registerXPathNamespace('at', 'http://www.w3.org/2005/Atom');

        // Check for deleted entry (video removed/unpublished)
        $deleted = $xml->xpath('//at:deleted-entry');
        if ($deleted) {
            http_response_code(200);
            exit;
        }

        // Get video details
        $videoId = (string)($xml->xpath('//yt:videoId')[0] ?? '');
        $channelId = (string)($xml->xpath('//yt:channelId')[0] ?? '');
        $title = (string)($xml->xpath('//at:entry/at:title')[0] ?? '');
        $published = (string)($xml->xpath('//at:entry/at:published')[0] ?? '');
        $updated = (string)($xml->xpath('//at:entry/at:updated')[0] ?? '');

        if (!$videoId || !$channelId) {
            http_response_code(200);
            exit;
        }

        // Check if this is a livestream using YouTube Data API
        $feedStmt = $db->prepare("SELECT feed_key, feed_api_key FROM yy_feed WHERE feed_account_id = ? AND feed_active_flag = TRUE LIMIT 1");
        $feedStmt->execute([$channelId]);
        $feed = $feedStmt->fetch();
        $apiKey = $feed ? ($feed['feed_api_key'] ?: getenv('YOUTUBE_API_KEY')) : getenv('YOUTUBE_API_KEY');

        if ($apiKey) {
            $url = "https://www.googleapis.com/youtube/v3/videos?" . http_build_query([
                'part' => 'snippet,liveStreamingDetails',
                'id' => $videoId,
                'key' => $apiKey,
            ]);
            $ctx = stream_context_create(['http' => ['timeout' => 10]]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp) {
                $data = json_decode($resp, true);
                $item = $data['items'][0] ?? null;
                if ($item) {
                    $liveBroadcast = $item['snippet']['liveBroadcastContent'] ?? 'none';
                    $isLive = ($liveBroadcast === 'live');
                    $isUpcoming = ($liveBroadcast === 'upcoming');

                    if ($feed) {
                        $feedKey = (int)$feed['feed_key'];

                        if ($isLive) {
                            // Set stream_dtime to indicate a live stream is happening now
                            // Only update feeds where stream_flag is TRUE (manually opted in to livestream detection)
                            $liveUrl = 'https://www.youtube.com/watch?v=' . $videoId;
                            $db->prepare("UPDATE yy_feed SET feed_stream_dtime = NOW(), feed_source_url = ? WHERE feed_key = ? AND feed_stream_flag = TRUE")
                               ->execute([$liveUrl, $feedKey]);
                            // Clear cache
                            @unlink(sys_get_temp_dir() . '/yada_livestream_status.json');

                            $db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag) VALUES ('youtube_webhook', 'info', ?, ?, TRUE)")
                               ->execute(["LIVESTREAM STARTED: $title", "Video: $videoId\nChannel: $channelId\nURL: $liveUrl"]);
                        }
                        // Never touch feed_stream_flag — it's manually controlled
                        // stream_dtime naturally ages out after 3 hours
                    }
                }
            }
        }

        // Also trigger a sync for this video (update title, thumbnail, etc.)
        if ($feed) {
            $syncScript = __DIR__ . '/sync-youtube.php';
            if (file_exists($syncScript)) {
                @exec('php ' . escapeshellarg($syncScript) . ' > /dev/null 2>&1 &');
            }
        }

    } catch (Exception $e) {
        $db->prepare("INSERT INTO yy_monitor_event (event_source, event_severity, event_message, event_detail, event_resolved_flag) VALUES ('youtube_webhook', 'error', 'Webhook parse error', ?, TRUE)")
           ->execute([$e->getMessage()]);
    }

    http_response_code(200);
    exit;
}

http_response_code(405);
