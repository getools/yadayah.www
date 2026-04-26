<?php
/**
 * YouTube feed sync — fetches videos from YouTube RSS/API and stores in yy_feed_item.
 * Syncs ALL active YouTube feeds (channels and playlists).
 *
 * CLI:   php sync-youtube.php
 * Web:   GET /api/sync-youtube.php?key=yada2026sync
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDb();
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// Find all active YouTube feeds
$feeds = $db->query("SELECT feed_key, feed_name, feed_account_id, feed_api_key, feed_api_endpoint FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = TRUE ORDER BY feed_key")->fetchAll();
if (!$feeds) {
    $msg = 'No active YouTube feeds found';
    if ($isCli) { echo "$msg\n"; exit; }
    jsonResponse(['error' => $msg]);
}

$results = [];

foreach ($feeds as $feed) {
    $feedKey = (int)$feed['feed_key'];
    $accountId = $feed['feed_account_id'];
    $apiKey = $feed['feed_api_key'] ?: getenv('YOUTUBE_API_KEY') ?: '';
    $isPlaylist = stripos($feed['feed_api_endpoint'] ?? '', 'playlist') !== false || substr($accountId, 0, 2) === 'PL';

    // Start sync log
    $db->prepare("INSERT INTO yy_feed_sync (feed_key, feed_sync_status) VALUES (?, 'running')")->execute([$feedKey]);
    $syncKey = $db->lastInsertId('yy_feed_sync_feed_sync_key_seq');

    $totalFound = 0;
    $totalInserted = 0;
    $totalUpdated = 0;
    $error = null;

    try {
        $videos = [];

        if ($isPlaylist) {
            $videos = fetchYouTubeRss("https://www.youtube.com/feeds/videos.xml?playlist_id=" . urlencode($accountId));
        } else {
            $videos = fetchYouTubeRss("https://www.youtube.com/feeds/videos.xml?channel_id=" . urlencode($accountId));

            // RSS only returns ~15 videos. If API key available, fetch more via API
            if ($apiKey) {
                $apiVideos = fetchYouTubeApi($accountId, $apiKey);
                // Merge, dedup by ID
                $existing = array_column($videos, null, 'id');
                foreach ($apiVideos as $v) {
                    if (!isset($existing[$v['id']])) {
                        $videos[] = $v;
                    }
                }
            }
        }

        // Fetch durations from YouTube Data API (batches of 50)
        if ($apiKey && $videos) {
            $durations = fetchYouTubeDurations(array_column($videos, 'id'), $apiKey);
            foreach ($videos as &$v) {
                if (isset($durations[$v['id']])) {
                    $v['duration'] = $durations[$v['id']]['seconds'];
                    $v['durationStr'] = $durations[$v['id']]['formatted'];
                }
            }
            unset($v);
        }

        $totalFound = count($videos);

        // Determine video type based on feed config
        $type = $isPlaylist ? 'video' : 'channel';

        foreach ($videos as $v) {
            $videoId = $v['id'];
            if (!$videoId) continue;

            // Detect shorts by duration
            $itemType = $type;
            if (isset($v['duration']) && $v['duration'] < 240) {
                $itemType = 'short';
            }

            $stmt = $db->prepare("
                INSERT INTO yy_feed_item (feed_key, feed_item_external_id, feed_item_title, feed_item_url, feed_item_thumbnail, feed_item_embed_id, feed_item_publish_dtime, feed_item_active_flag, feed_item_type, feed_item_duration)
                VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, ?, ?)
                ON CONFLICT (feed_key, feed_item_external_id) DO UPDATE SET
                    feed_item_title = EXCLUDED.feed_item_title,
                    feed_item_thumbnail = COALESCE(EXCLUDED.feed_item_thumbnail, yy_feed_item.feed_item_thumbnail),
                    feed_item_publish_dtime = COALESCE(EXCLUDED.feed_item_publish_dtime, yy_feed_item.feed_item_publish_dtime),
                    feed_item_type = EXCLUDED.feed_item_type,
                    feed_item_duration = COALESCE(EXCLUDED.feed_item_duration, yy_feed_item.feed_item_duration),
                    feed_item_revision_dtime = NOW()
                RETURNING (xmax = 0) AS inserted
            ");
            $stmt->execute([
                $feedKey, $videoId, $v['title'],
                'https://www.youtube.com/watch?v=' . $videoId,
                $v['thumbnail'], $videoId,
                $v['published'] ?: null,
                $itemType,
                $v['durationStr'] ?? null,
            ]);
            $row = $stmt->fetch();
            if ($row['inserted']) $totalInserted++;
            else $totalUpdated++;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    // Update sync log
    $status = $error ? 'error' : 'success';
    $db->prepare("UPDATE yy_feed_sync SET feed_sync_status = ?, feed_sync_items_found = ?, feed_sync_items_inserted = ?, feed_sync_items_updated = ?, feed_sync_error = ?, feed_sync_end_dtime = NOW() WHERE feed_sync_key = ?")
       ->execute([$status, $totalFound, $totalInserted, $totalUpdated, $error, $syncKey]);

    $results[] = [
        'feed' => $feed['feed_name'],
        'found' => $totalFound,
        'inserted' => $totalInserted,
        'updated' => $totalUpdated,
        'error' => $error,
    ];

    if ($isCli) {
        echo "{$feed['feed_name']}: found=$totalFound inserted=$totalInserted updated=$totalUpdated" . ($error ? " error=$error" : '') . "\n";
    }
}

if (!$isCli) {
    jsonResponse(['synced' => true, 'results' => $results]);
}

// ── Helper: Fetch videos from YouTube RSS ──
function fetchYouTubeRss(string $url): array {
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0']]);
    $xml = @file_get_contents($url, false, $ctx);
    if (!$xml) return [];

    $feed = @simplexml_load_string($xml);
    if (!$feed) return [];

    $videos = [];
    foreach ($feed->entry as $entry) {
        $ns = $entry->children('yt', true);
        $videoId = (string)$ns->videoId;
        if (!$videoId) continue;

        $videos[] = [
            'id' => $videoId,
            'title' => (string)$entry->title,
            'published' => (string)$entry->published,
            'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
        ];
    }
    return $videos;
}

// ── Helper: Fetch videos from YouTube Data API (channel uploads) ──
function fetchYouTubeApi(string $channelId, string $apiKey): array {
    // Get uploads playlist ID
    $url = "https://www.googleapis.com/youtube/v3/channels?" . http_build_query([
        'part' => 'contentDetails',
        'id' => $channelId,
        'key' => $apiKey,
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return [];
    $data = json_decode($json, true);
    $uploadsId = $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '';
    if (!$uploadsId) return [];

    // Fetch all pages of uploads
    $videos = [];
    $pageToken = '';
    $maxPages = 10; // ~500 videos max

    for ($p = 0; $p < $maxPages; $p++) {
        $url = "https://www.googleapis.com/youtube/v3/playlistItems?" . http_build_query(array_filter([
            'part' => 'snippet',
            'playlistId' => $uploadsId,
            'maxResults' => 50,
            'pageToken' => $pageToken,
            'key' => $apiKey,
        ]));
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) break;
        $result = json_decode($json, true);

        foreach (($result['items'] ?? []) as $item) {
            $s = $item['snippet'] ?? [];
            $videoId = $s['resourceId']['videoId'] ?? '';
            if (!$videoId) continue;
            $videos[] = [
                'id' => $videoId,
                'title' => $s['title'] ?? '',
                'published' => $s['publishedAt'] ?? '',
                'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            ];
        }

        $pageToken = $result['nextPageToken'] ?? '';
        if (!$pageToken) break;
    }

    return $videos;
}

// ── Helper: Fetch durations for a list of video IDs (batches of 50) ──
function fetchYouTubeDurations(array $videoIds, string $apiKey): array {
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $durations = [];

    foreach (array_chunk($videoIds, 50) as $batch) {
        $url = "https://www.googleapis.com/youtube/v3/videos?" . http_build_query([
            'part' => 'contentDetails',
            'id' => implode(',', $batch),
            'key' => $apiKey,
        ]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) continue;
        $result = json_decode($json, true);

        foreach (($result['items'] ?? []) as $item) {
            $id = $item['id'] ?? '';
            $iso = $item['contentDetails']['duration'] ?? '';
            if (!$id || !$iso) continue;

            $seconds = parseIsoDuration($iso);
            $durations[$id] = [
                'seconds' => $seconds,
                'formatted' => formatDuration($seconds),
            ];
        }
    }

    return $durations;
}

function parseIsoDuration(string $iso): int {
    $seconds = 0;
    if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m)) {
        $seconds += (int)($m[1] ?? 0) * 3600;
        $seconds += (int)($m[2] ?? 0) * 60;
        $seconds += (int)($m[3] ?? 0);
    }
    return $seconds;
}

function formatDuration(int $seconds): string {
    if ($seconds >= 3600) {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}
