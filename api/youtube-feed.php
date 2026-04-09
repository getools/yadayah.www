<?php
require_once __DIR__ . '/config.php';

$type = $_GET['type'] ?? 'channel';
$limit = min((int)($_GET['limit'] ?? 15), 50);
$offset = (int)($_GET['offset'] ?? 0);
$pageToken = $_GET['pageToken'] ?? '';

$db = getDb();

// Load channel ID from yy_feed (youtube channel source, not playlist)
$feedStmt = $db->query("SELECT feed_account_id, feed_api_key FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = true AND feed_account_id LIKE 'UC%' ORDER BY feed_key LIMIT 1");
$feedRow = $feedStmt->fetch();
$CHANNEL_ID = $feedRow['feed_account_id'] ?? 'UCKxk-PvJk6rLfBxykgSGXbA';
$YT_API_KEY = $feedRow['feed_api_key'] ?: getenv('YOUTUBE_API_KEY') ?: '';
$CACHE_DIR = sys_get_temp_dir() . '/yt_cache/';
$CACHE_TTL = 900; // 15 minutes

if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0755, true);

switch ($type) {
    case 'channel':
        // Latest channel videos via RSS (15 max) or YouTube Data API (unlimited)
        if ($YT_API_KEY && ($offset > 0 || $pageToken)) {
            serveApiChannel($CHANNEL_ID, $limit, $pageToken);
        } else {
            serveRssChannel($CHANNEL_ID, $limit, $offset);
        }
        break;

    case 'shorts':
        if ($YT_API_KEY) {
            serveApiShorts($CHANNEL_ID, $limit);
        } else {
            // Fallback: return empty - shorts need API
            jsonResponse(['videos' => [], 'total' => 0, 'hasMore' => false]);
        }
        break;

    case 'playlist':
        $playlistId = $_GET['id'] ?? '';
        if (!$playlistId) errorResponse('Missing playlist id');
        serveRssPlaylist($playlistId, $limit);
        break;

    default:
        errorResponse('Invalid type');
}

// ── RSS-based feeds (no API key needed) ──

function serveRssChannel(string $channelId, int $limit, int $offset): void {
    global $CACHE_DIR, $CACHE_TTL;
    $cacheFile = $CACHE_DIR . "ch_{$channelId}.json";

    $videos = getCachedOrFetch($cacheFile, $CACHE_TTL, function() use ($channelId) {
        return fetchRss("https://www.youtube.com/feeds/videos.xml?channel_id=" . urlencode($channelId));
    });

    $slice = array_slice($videos, $offset, $limit);
    jsonResponse([
        'videos' => $slice,
        'total' => count($videos),
        'hasMore' => ($offset + $limit) < count($videos),
    ]);
}

function serveRssPlaylist(string $playlistId, int $limit): void {
    global $CACHE_DIR;
    $cacheFile = $CACHE_DIR . "pl_{$playlistId}.json";

    $videos = fetchRss("https://www.youtube.com/feeds/videos.xml?playlist_id=" . urlencode($playlistId));

    if (!empty($videos)) {
        file_put_contents($cacheFile, json_encode($videos));
    } elseif (file_exists($cacheFile)) {
        $videos = json_decode(file_get_contents($cacheFile), true) ?: [];
    }

    jsonResponse([
        'videos' => array_slice($videos, 0, $limit),
        'total' => count($videos),
        'hasMore' => false,
    ]);
}

function fetchRss(string $url): array {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0']]);
    $xml = @file_get_contents($url, false, $ctx);
    if (!$xml) return [];

    $feed = @simplexml_load_string($xml);
    if (!$feed) return [];

    $videos = [];
    foreach ($feed->entry as $entry) {
        $ns = $entry->children('yt', true);
        $media = $entry->children('media', true);
        $videoId = (string)$ns->videoId;
        $desc = '';
        if ($media && $media->group && $media->group->description) {
            $desc = (string)$media->group->description;
        }
        $videos[] = [
            'id' => $videoId,
            'title' => (string)$entry->title,
            'published' => (string)$entry->published,
            'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            'thumbnailMax' => "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg",
            'description' => mb_substr($desc, 0, 200),
        ];
    }
    return $videos;
}

// ── YouTube Data API feeds ──

function serveApiChannel(string $channelId, int $limit, string $pageToken): void {
    global $CACHE_DIR, $CACHE_TTL, $YT_API_KEY;

    // Get uploads playlist ID
    $uploadsPlId = getUploadsPlaylistId($channelId);
    if (!$uploadsPlId) errorResponse('Could not find uploads playlist');

    $cacheKey = "api_ch_{$uploadsPlId}_" . md5($pageToken . $limit);
    $cacheFile = $CACHE_DIR . $cacheKey . '.json';

    $result = getCachedOrFetch($cacheFile, $CACHE_TTL, function() use ($uploadsPlId, $limit, $pageToken, $YT_API_KEY) {
        $url = "https://www.googleapis.com/youtube/v3/playlistItems?" . http_build_query([
            'part' => 'snippet',
            'playlistId' => $uploadsPlId,
            'maxResults' => $limit,
            'pageToken' => $pageToken,
            'key' => $YT_API_KEY,
        ]);
        $ctx = stream_context_create(['http' => ['timeout' => 10]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return null;
        return json_decode($json, true);
    });

    if (!$result) errorResponse('API request failed');

    $videos = [];
    foreach (($result['items'] ?? []) as $item) {
        $s = $item['snippet'] ?? [];
        $videoId = $s['resourceId']['videoId'] ?? '';
        if (!$videoId) continue;
        $videos[] = [
            'id' => $videoId,
            'title' => $s['title'] ?? '',
            'published' => $s['publishedAt'] ?? '',
            'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            'thumbnailMax' => "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg",
            'description' => mb_substr($s['description'] ?? '', 0, 200),
        ];
    }

    jsonResponse([
        'videos' => $videos,
        'total' => $result['pageInfo']['totalResults'] ?? count($videos),
        'hasMore' => !empty($result['nextPageToken']),
        'nextPageToken' => $result['nextPageToken'] ?? null,
    ]);
}

function serveApiShorts(string $channelId, int $limit): void {
    global $CACHE_DIR, $CACHE_TTL, $YT_API_KEY;
    $cacheFile = $CACHE_DIR . "api_shorts2_{$channelId}_{$limit}.json";

    $videos = getCachedOrFetch($cacheFile, $CACHE_TTL, function() use ($channelId, $limit, $YT_API_KEY) {
        // Fetch more candidates than needed; videoDuration=short means <4 min
        $fetch = min($limit * 3, 50);
        $url = "https://www.googleapis.com/youtube/v3/search?" . http_build_query([
            'part' => 'snippet',
            'channelId' => $channelId,
            'order' => 'date',
            'type' => 'video',
            'videoDuration' => 'short',
            'maxResults' => $fetch,
            'key' => $YT_API_KEY,
        ]);
        $ctx = stream_context_create(['http' => ['timeout' => 10]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return null;
        $result = json_decode($json, true);
        if (empty($result['items'])) return [];

        // Collect candidates
        $candidates = [];
        foreach ($result['items'] as $item) {
            $videoId = $item['id']['videoId'] ?? '';
            if (!$videoId) continue;
            $s = $item['snippet'] ?? [];
            $candidates[$videoId] = [
                'id' => $videoId,
                'title' => $s['title'] ?? '',
                'published' => $s['publishedAt'] ?? '',
                'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
                'thumbnailMax' => "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg",
            ];
        }

        if (empty($candidates)) return [];

        // Verify actual duration via contentDetails — true Shorts are ≤180 seconds
        $ids = implode(',', array_keys($candidates));
        $durUrl = "https://www.googleapis.com/youtube/v3/videos?" . http_build_query([
            'part' => 'contentDetails',
            'id' => $ids,
            'key' => $YT_API_KEY,
        ]);
        $durJson = @file_get_contents($durUrl, false, $ctx);
        if ($durJson) {
            $durData = json_decode($durJson, true);
            foreach ($durData['items'] ?? [] as $v) {
                $vid = $v['id'];
                $iso = $v['contentDetails']['duration'] ?? 'PT0S';
                $secs = parseIso8601Duration($iso);
                if ($secs > 180) {
                    unset($candidates[$vid]);
                }
            }
        }

        return array_values(array_slice($candidates, 0, $limit));
    });

    if ($videos === null) errorResponse('API request failed');
    jsonResponse(['videos' => $videos ?: [], 'total' => count($videos ?: []), 'hasMore' => false]);
}

function parseIso8601Duration(string $iso): int {
    preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m);
    return ((int)($m[1] ?? 0)) * 3600 + ((int)($m[2] ?? 0)) * 60 + (int)($m[3] ?? 0);
}

function getUploadsPlaylistId(string $channelId): ?string {
    global $CACHE_DIR, $CACHE_TTL, $YT_API_KEY;
    $cacheFile = $CACHE_DIR . "uploads_{$channelId}.txt";

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        return trim(file_get_contents($cacheFile));
    }

    $url = "https://www.googleapis.com/youtube/v3/channels?" . http_build_query([
        'part' => 'contentDetails',
        'id' => $channelId,
        'key' => $YT_API_KEY,
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;

    $data = json_decode($json, true);
    $plId = $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
    if ($plId) {
        file_put_contents($cacheFile, $plId);
    }
    return $plId;
}

// ── Helpers ──

function getCachedOrFetch(string $cacheFile, int $ttl, callable $fetcher) {
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $data = $fetcher();

    // Only cache non-empty results; if fetch failed, serve stale cache
    if ($data !== null && !empty($data)) {
        file_put_contents($cacheFile, json_encode($data));
    } elseif (file_exists($cacheFile)) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    return $data;
}
