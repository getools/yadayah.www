<?php
/**
 * CLI-only script to sync Facebook Invite videos.
 * Usage: php sync-invite.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit;
}

$FB_PAGE_ID    = getenv('FB_PAGE_ID') ?: '102425844783696';
$THUMB_DIR     = __DIR__ . '/../u/invite';

// Prefer DB token (yy_feed.feed_api_key) over env var
$FB_PAGE_TOKEN = '';
try {
    require_once __DIR__ . '/config.php';
    $settingDb = getDb();
    $stmt = $settingDb->prepare("SELECT feed_api_key FROM yy_feed WHERE lower(feed_site_code) = 'facebook' AND feed_account_id = ? AND feed_api_key IS NOT NULL AND feed_api_key != '' LIMIT 1");
    $stmt->execute([$FB_PAGE_ID]);
    $row = $stmt->fetch();
    if ($row) $FB_PAGE_TOKEN = $row['feed_api_key'];
} catch (\Exception $e) {}

// Fall back to env var
if (!$FB_PAGE_TOKEN) $FB_PAGE_TOKEN = getenv('FB_PAGE_TOKEN') ?: '';

if (!$FB_PAGE_TOKEN) {
    echo "Error: FB_PAGE_TOKEN not set (env or DB)\n";
    exit(1);
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

if (!is_dir($THUMB_DIR)) mkdir($THUMB_DIR, 0755, true);

$inserted = 0;
$updated  = 0;
$skipped  = 0;

$url = "https://graph.facebook.com/v23.0/$FB_PAGE_ID/videos"
     . "?fields=title,description,format{picture},created_time,status"
     . "&access_token=$FB_PAGE_TOKEN&limit=100";

$pageNum = 0;
while ($url) {
    $pageNum++;
    echo "Fetching page $pageNum...\n";

    $ctx = stream_context_create(['http' => ['timeout' => 30]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        echo "Error: Failed to fetch from Facebook API\n";
        break;
    }

    $json = json_decode($response, true);
    if (empty($json['data'])) {
        echo "No more data.\n";
        break;
    }

    echo "  Got " . count($json['data']) . " videos\n";

    foreach ($json['data'] as $video) {
        $videoId = $video['id'] ?? '';
        if (!$videoId) continue;

        // Check for #Invite hashtag in title or description
        $text = ($video['title'] ?? '') . ' ' . ($video['description'] ?? '');
        if (stripos($text, '#Invite') === false && stripos($text, '#invite') === false) {
            $skipped++;
            continue;
        }

        // Get best thumbnail
        $thumbUrl = '';
        if (!empty($video['format'])) {
            foreach ($video['format'] as $fmt) {
                if (!empty($fmt['picture'])) $thumbUrl = $fmt['picture'];
            }
        }

        // Download thumbnail locally
        $localThumb = null;
        if ($thumbUrl) {
            $thumbFile = "thumb_$videoId.jpg";
            $thumbPath = "$THUMB_DIR/$thumbFile";
            $imgData = @file_get_contents($thumbUrl);
            if ($imgData !== false) {
                file_put_contents($thumbPath, $imgData);
                $localThumb = "u/invite/$thumbFile";
            }
        }

        // Clean title (remove #Invite prefix)
        $title = trim($video['title'] ?? $video['description'] ?? '');
        $title = preg_replace('/^#Invite\s*/i', '', $title);
        if (!$title) $title = 'Invitation';

        $createdTime = $video['created_time'] ?? null;

        // Upsert
        $existing = $db->prepare("SELECT feed_key FROM yy_feed_invite WHERE feed_video_id = ?");
        $existing->execute([$videoId]);

        if ($existing->fetch()) {
            $stmt = $db->prepare("UPDATE yy_feed_invite SET feed_title = ?, feed_thumbnail = ?, feed_create = ? WHERE feed_video_id = ?");
            $stmt->execute([$title, $localThumb, $createdTime, $videoId]);
            $updated++;
        } else {
            $stmt = $db->prepare("INSERT INTO yy_feed_invite (feed_video_id, feed_title, feed_thumbnail, feed_create) VALUES (?, ?, ?, ?)");
            $stmt->execute([$videoId, $title, $localThumb, $createdTime]);
            $inserted++;
        }

        echo "  [$videoId] " . substr($title, 0, 60) . "\n";
    }

    // Next page
    $url = $json['paging']['next'] ?? null;
}

echo "\nDone! Inserted: $inserted, Updated: $updated, Skipped (no #Invite): $skipped\n";
