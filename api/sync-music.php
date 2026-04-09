<?php
/**
 * CLI-only script to sync YouTube Music playlist videos.
 * Usage: php sync-music.php
 *
 * Requires YOUTUBE_API_KEY environment variable.
 * Playlist: PLW5gXgQ3YcPCy7jFNQ_4Q759SVdS0e035
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit;
}

// DB connection
$host = getenv('PG_HOST') ?: 'localhost';
$port = getenv('PG_PORT') ?: '5432';
$name = getenv('PG_DB')   ?: 'yada';
$user = getenv('PG_USER') ?: 'postgres';
$pass = getenv('PG_PASS') ?: 'yada_password';
$dsn = "pgsql:host=$host;port=$port;dbname=$name";
$db = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Load playlist ID from yy_feed_page for the music page
$fpStmt = $db->query("
    SELECT f.feed_account_id, f.feed_api_key
    FROM yy_feed_page fp
    JOIN yy_feed f ON f.feed_key = fp.feed_key
    JOIN yy_page p ON p.page_key = fp.page_key
    WHERE p.page_code = 'music'
    ORDER BY fp.feed_page_sort LIMIT 1
");
$fpRow = $fpStmt->fetch();
$YT_PLAYLIST_ID = $fpRow['feed_account_id'] ?? 'PLW5gXgQ3YcPCy7jFNQ_4Q759SVdS0e035';
$YT_API_KEY     = $fpRow['feed_api_key'] ?: getenv('YOUTUBE_API_KEY') ?: '';

if (!$YT_API_KEY) {
    echo "Error: YOUTUBE_API_KEY not configured\n";
    exit(1);
}

$inserted = 0;
$updated  = 0;
$skipped  = 0;
$pageToken = '';
$pageNum = 0;

do {
    $pageNum++;
    echo "Fetching page $pageNum...\n";

    $url = "https://www.googleapis.com/youtube/v3/playlistItems"
         . "?part=snippet&maxResults=50&playlistId=$YT_PLAYLIST_ID"
         . "&key=$YT_API_KEY";
    if ($pageToken) $url .= "&pageToken=$pageToken";

    $ctx = stream_context_create(['http' => ['timeout' => 30]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        echo "Error: Failed to fetch from YouTube API\n";
        break;
    }

    $json = json_decode($response, true);
    if (empty($json['items'])) {
        echo "No more data.\n";
        break;
    }

    echo "  Got " . count($json['items']) . " items\n";

    foreach ($json['items'] as $item) {
        $snippet = $item['snippet'] ?? [];
        $title = $snippet['title'] ?? '';
        $videoId = $snippet['resourceId']['videoId'] ?? '';
        if (!$videoId) continue;

        // Exclude videos with "Private" in their title
        if (stripos($title, 'Private') !== false) {
            echo "  [SKIP] $videoId - $title (Private)\n";
            $skipped++;
            continue;
        }

        // Skip deleted videos
        if ($title === 'Deleted video') {
            echo "  [SKIP] $videoId - Deleted video\n";
            $skipped++;
            continue;
        }

        // Get best thumbnail
        $thumbUrl = '';
        $thumbs = $snippet['thumbnails'] ?? [];
        foreach (['high', 'medium', 'default'] as $size) {
            if (!empty($thumbs[$size]['url'])) {
                $thumbUrl = $thumbs[$size]['url'];
                break;
            }
        }

        $publishedAt = $snippet['publishedAt'] ?? null;

        // Upsert
        $existing = $db->prepare("SELECT music_key FROM yy_music WHERE music_video_id = ?");
        $existing->execute([$videoId]);

        if ($existing->fetch()) {
            $stmt = $db->prepare("UPDATE yy_music SET music_title = ?, music_thumbnail = ?, music_create = ? WHERE music_video_id = ?");
            $stmt->execute([$title, $thumbUrl, $publishedAt, $videoId]);
            $updated++;
        } else {
            $stmt = $db->prepare("INSERT INTO yy_music (music_video_id, music_title, music_thumbnail, music_create) VALUES (?, ?, ?, ?)");
            $stmt->execute([$videoId, $title, $thumbUrl, $publishedAt]);
            $inserted++;
        }

        echo "  [$videoId] " . substr($title, 0, 60) . "\n";
    }

    $pageToken = $json['nextPageToken'] ?? '';
} while ($pageToken);

echo "\nDone! Inserted: $inserted, Updated: $updated, Skipped: $skipped\n";
