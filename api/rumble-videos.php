<?php
/**
 * Serves paginated video listings from a Rumble user channel.
 * Returns JSON with videos, page, and total_pages.
 * Display pages show 24 videos each.
 * Accepts ?page=N parameter (default 1).
 *
 * Data source priority:
 *  1. Per-page temp cache (15 min TTL)
 *  2. Live scrape from Rumble (works locally, blocked by Cloudflare on most servers)
 *  3. Static full cache file (rumble-all-videos.json) deployed with the app
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$CHANNEL_BASE  = 'https://rumble.com/user/Stevens';
$STATIC_CACHE  = __DIR__ . '/../rumble-all-videos.json';
$CACHE_DIR     = sys_get_temp_dir() . '/rumble_stevens';
$CACHE_TTL     = 900; // 15 minutes
$DISPLAY_SIZE  = 24;
$RUMBLE_SIZE   = 25;

if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0777, true);

$displayPage = max(1, intval($_GET['page'] ?? 1));

// 1. Check per-page display cache
$DISPLAY_CACHE = $CACHE_DIR . '/display_' . $displayPage . '.json';
if (file_exists($DISPLAY_CACHE) && (time() - filemtime($DISPLAY_CACHE)) < $CACHE_TTL) {
    echo file_get_contents($DISPLAY_CACHE);
    exit;
}

// 2. Try live scrape from Rumble
$result = tryLiveFetch($displayPage, $CHANNEL_BASE, $CACHE_DIR, $CACHE_TTL, $DISPLAY_SIZE, $RUMBLE_SIZE);

// 3. Fall back to static cache
if ($result === null && file_exists($STATIC_CACHE)) {
    $result = serveFromStaticCache($STATIC_CACHE, $displayPage, $DISPLAY_SIZE);
}

if ($result === null) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to fetch Rumble channel']);
    exit;
}

$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($DISPLAY_CACHE, $json);
echo $json;
exit;

// --- Functions ---

function serveFromStaticCache(string $path, int $displayPage, int $displaySize): ?array {
    $all = json_decode(file_get_contents($path), true);
    if (!is_array($all)) return null;

    $totalVideos = count($all);
    $totalPages = max(1, intval(ceil($totalVideos / $displaySize)));
    $offset = ($displayPage - 1) * $displaySize;
    $videos = array_slice($all, $offset, $displaySize);

    return [
        'videos'      => $videos,
        'page'        => $displayPage,
        'total_pages' => $totalPages,
    ];
}

function tryLiveFetch(int $displayPage, string $channelBase, string $cacheDir, int $cacheTtl, int $displaySize, int $rumbleSize): ?array {
    $ctx = stream_context_create(['http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36\r\nAccept: text/html,application/xhtml+xml\r\nAccept-Language: en-US,en;q=0.9\r\n",
        'timeout' => 15,
    ]]);

    $startOffset = ($displayPage - 1) * $displaySize;
    $endOffset   = $startOffset + $displaySize - 1;
    $rpStart = intval(floor($startOffset / $rumbleSize)) + 1;
    $rpEnd   = intval(floor($endOffset / $rumbleSize)) + 1;

    $totalRumblePages = 1;
    $allVideos = [];

    for ($rp = $rpStart; $rp <= $rpEnd; $rp++) {
        // Check Rumble page cache
        $rpCache = $cacheDir . '/rumble_' . $rp . '.json';
        if (file_exists($rpCache) && (time() - filemtime($rpCache)) < $cacheTtl) {
            $cached = json_decode(file_get_contents($rpCache), true);
            $allVideos = array_merge($allVideos, $cached['videos']);
            $totalRumblePages = max($totalRumblePages, $cached['total_rumble_pages']);
            continue;
        }

        // Fetch from Rumble
        $url = $channelBase . ($rp > 1 ? '?page=' . $rp : '');
        $html = @file_get_contents($url, false, $ctx);
        if ($html === false || strpos($html, 'Just a moment') !== false) {
            return null; // Signal caller to use static fallback
        }

        if (preg_match_all('#/user/Stevens\?page=(\d+)#', $html, $pgm)) {
            $totalRumblePages = max($totalRumblePages, max(array_map('intval', $pgm[1])));
        }

        $videos = parseVideos($html);
        file_put_contents($rpCache, json_encode(
            ['videos' => $videos, 'total_rumble_pages' => $totalRumblePages],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $allVideos = array_merge($allVideos, $videos);
    }

    $offsetInBuffer = $startOffset - ($rpStart - 1) * $rumbleSize;
    $pageVideos = array_slice($allVideos, $offsetInBuffer, $displaySize);
    $totalDisplayPages = intval(ceil($totalRumblePages * $rumbleSize / $displaySize));

    return [
        'videos'      => $pageVideos,
        'page'        => $displayPage,
        'total_pages' => $totalDisplayPages,
    ];
}

function parseVideos(string $html): array {
    $videos = [];
    $seen = [];

    preg_match_all(
        '#class="videostream\s+thumbnail__grid--item[^"]*"[^>]*data-video-id="(\d+)"(.*?)(?=class="videostream\s+thumbnail__grid--item|</section>)#s',
        $html, $blocks, PREG_SET_ORDER
    );

    foreach ($blocks as $block) {
        $videoId = $block[1]; $content = $block[2];
        if (isset($seen[$videoId])) continue;
        $seen[$videoId] = true;

        $title = '';
        if (preg_match('#class="thumbnail__title[^"]*"[^>]*>(.*?)</h3#s', $content, $m))
            $title = html_entity_decode(strip_tags(trim($m[1])), ENT_QUOTES, 'UTF-8');

        $url = ''; $embedId = '';
        if (preg_match('#href="(/v([a-z0-9]+)-[^"?]+\.html)#', $content, $m)) {
            $url = 'https://rumble.com' . $m[1];
            $embedId = 'v' . $m[2];
        }

        $thumbnail = '';
        if (preg_match('#src="(https://[^"]+\.(?:jpg|png|webp))"#', $content, $m))
            $thumbnail = $m[1];

        $date = ''; $dateAgo = '';
        if (preg_match('#<time[^>]*datetime="([^"]+)"[^>]*>([^<]+)</time>#', $content, $m)) {
            $date = $m[1]; $dateAgo = trim($m[2]);
        }

        if (!$title || !$url) continue;
        $videos[] = ['title'=>$title, 'url'=>$url, 'thumbnail'=>$thumbnail, 'date'=>$date, 'date_ago'=>$dateAgo, 'embed_id'=>$embedId];
    }

    usort($videos, function ($a, $b) { return strcmp($b['date'], $a['date']); });
    return $videos;
}
