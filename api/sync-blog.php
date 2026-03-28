<?php
/**
 * Sync Facebook page posts for the Blog.
 * Works both as CLI script and via HTTP with auth key.
 *
 * CLI:  php sync-blog.php
 * HTTP: /api/sync-blog.php?action=sync&key=yada2026sync
 */
$isCli = php_sapi_name() === 'cli';
$isHttpSync = !$isCli && ($_GET['action'] ?? '') === 'sync' && ($_GET['key'] ?? '') === 'yada2026sync';

if (!$isCli && !$isHttpSync) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// For HTTP mode, capture output and return JSON
if ($isHttpSync) {
    ob_start();
}

$FB_PAGE_ID    = getenv('FB_PAGE_ID') ?: '102425844783696';
$FB_TOKEN_TYPE  = getenv('FB_TOKEN_TYPE') ?: 'user';
$IMG_DIR       = __DIR__ . '/../u/blog';

// Prefer DB token (yy_feed.feed_api_key) over env var — DB is always up to date
$FB_TOKEN = '';
try {
    require_once __DIR__ . '/config.php';
    $settingDb = getDb();
    $stmt = $settingDb->prepare("SELECT feed_api_key FROM yy_feed WHERE feed_site_code = 'Facebook' AND feed_site_id = ? AND feed_api_key IS NOT NULL AND feed_api_key != '' LIMIT 1");
    $stmt->execute([$FB_PAGE_ID]);
    $row = $stmt->fetch();
    if ($row) {
        $FB_TOKEN = $row['feed_api_key'];
        $FB_TOKEN_TYPE = 'page'; // DB token is always a page token
    }
} catch (\Exception $e) {}

// Fall back to env var if DB lookup failed
if (!$FB_TOKEN) {
    $FB_TOKEN = getenv('FB_PAGE_TOKEN') ?: '';
}

if (!$FB_TOKEN) {
    $msg = "Error: FB_PAGE_TOKEN not set (env or DB)\n";
    if ($isHttpSync) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['error' => $msg]); exit; }
    echo $msg;
    exit(1);
}

function curlGet($url, $debug = false) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($debug) {
        echo "  curl HTTP $code\n";
        if ($err) echo "  curl error: $err\n";
        if ($code >= 400) echo "  response: " . substr($resp, 0, 300) . "\n";
    }
    if ($code >= 400 || $resp === false) return false;
    return $resp;
}

if ($FB_TOKEN_TYPE === 'page') {
    // Token is already a never-expiring Page Access Token
    $FB_PAGE_TOKEN = $FB_TOKEN;
    echo "Using Page Access Token directly.\n";
} else {
    // Derive Page token from User token via /me/accounts
    echo "Getting Page Access Token...\n";
    $acctResp = curlGet("https://graph.facebook.com/v23.0/me/accounts?access_token=$FB_TOKEN");
    if ($acctResp === false) {
        echo "Error: Failed to get Page Access Token\n";
        exit(1);
    }
    $acctJson = json_decode($acctResp, true);
    $FB_PAGE_TOKEN = '';
    foreach (($acctJson['data'] ?? []) as $page) {
        if (($page['id'] ?? '') === $FB_PAGE_ID) {
            $FB_PAGE_TOKEN = $page['access_token'];
            break;
        }
    }
    if (!$FB_PAGE_TOKEN) {
        echo "Error: Could not find Page Access Token for page $FB_PAGE_ID\n";
        exit(1);
    }
    echo "Got Page Access Token.\n";
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

if (!is_dir($IMG_DIR)) mkdir($IMG_DIR, 0755, true);

$inserted = 0;
$updated  = 0;

$url = "https://graph.facebook.com/v23.0/$FB_PAGE_ID/posts"
     . "?fields=message,full_picture,created_time,parent_id,attachments{type,media_type,url,media}"
     . "&access_token=$FB_PAGE_TOKEN&limit=25";

$pageNum = 0;
while ($url) {
    $pageNum++;
    echo "Fetching page $pageNum...\n";

    $response = curlGet($url, true);
    if ($response === false) {
        echo "Error: Failed to fetch from Facebook API\n";
        break;
    }

    $json = json_decode($response, true);
    if (empty($json['data'])) {
        echo "No more data.\n";
        break;
    }

    echo "  Got " . count($json['data']) . " posts\n";

    foreach ($json['data'] as $post) {
        $postId = $post['id'] ?? '';
        if (!$postId) continue;

        $message = $post['message'] ?? '';
        // Skip posts with no message content
        if (!$message) continue;

        $fullPicture = $post['full_picture'] ?? '';
        $createdTime = $post['created_time'] ?? null;

        // For shared link posts (native_templates), full_picture isn't in the list response
        // Fetch it from the single post endpoint
        if (!$fullPicture && !empty($post['attachments']['data'])) {
            $singleResp = curlGet("https://graph.facebook.com/v23.0/$postId?fields=full_picture&access_token=$FB_PAGE_TOKEN");
            if ($singleResp !== false) {
                $singleData = json_decode($singleResp, true);
                $fullPicture = $singleData['full_picture'] ?? '';
            }
        }

        // For shared posts, try to get the parent post's image
        if (!$fullPicture && !empty($post['parent_id'])) {
            $parentId = $post['parent_id'];
            $parentResp = curlGet("https://graph.facebook.com/v23.0/$parentId?fields=full_picture&access_token=$FB_PAGE_TOKEN");
            if ($parentResp !== false) {
                $parentData = json_decode($parentResp, true);
                $fullPicture = $parentData['full_picture'] ?? '';
                if ($fullPicture) echo "    Got image from parent post $parentId\n";
            }
        }

        // Last resort: scrape og:image from the post's permalink
        if (!$fullPicture) {
            $parts = explode('_', $postId);
            $permalink = "https://www.facebook.com/{$parts[0]}/posts/{$parts[1]}/";
            $ch = curl_init($permalink);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'facebookexternalhit/1.1');
            $html = curl_exec($ch);
            curl_close($ch);
            if ($html && preg_match('/property="og:image"\s+content="([^"]+)"/', $html, $m)) {
                $fullPicture = html_entity_decode($m[1]);
                echo "    Got og:image from permalink\n";
            }
        }

        // Detect video attachments
        $videoId = null;
        $postType = 'text';
        $attachments = $post['attachments']['data'] ?? [];
        foreach ($attachments as $att) {
            $attType = $att['type'] ?? '';
            $mediaType = $att['media_type'] ?? '';
            $attUrl = $att['url'] ?? '';

            // Log attachment info for debugging
            echo "    att: type=$attType media_type=$mediaType url=" . substr($attUrl, 0, 80) . "\n";

            // Video: video_inline (native), share with video media_type, video_share_youtube
            if ($mediaType === 'video' || $attType === 'video_inline' || $attType === 'video_share_youtube') {
                // Extract video/reel ID from URL like /reel/123456/ or /videos/123456/
                if (preg_match('/\/(?:reel|videos)\/(\d+)/', $attUrl, $m)) {
                    $videoId = $m[1];
                }
                $postType = 'video';
                break;
            }
            if ($attType === 'photo' || $attType === 'album') {
                $postType = 'photo';
            }
        }
        if ($postType === 'text' && $fullPicture) {
            $postType = 'photo';
        }

        // Video posts: keep them, use thumbnail image + embed
        // full_picture serves as the video thumbnail

        // Skip posts matching exclude filters (e.g. #DoYouYada goes to its own feed)
        $excludePatterns = ['#DoYouYada', '#DoUYada'];
        $skipPost = false;
        foreach ($excludePatterns as $pat) {
            if (stripos($message, $pat) !== false) {
                echo "  [$postId] SKIP (matched $pat)\n";
                $skipPost = true;
                break;
            }
        }
        if ($skipPost) continue;

        // Download image locally
        $localImage = null;
        if ($fullPicture) {
            $safeId = preg_replace('/[^a-zA-Z0-9]/', '_', $postId);
            $imgFile = "img_$safeId.jpg";
            $imgPath = "$IMG_DIR/$imgFile";
            $imgData = curlGet($fullPicture);
            if ($imgData !== false) {
                file_put_contents($imgPath, $imgData);
                $localImage = "u/blog/$imgFile";
            }
        }

        // Upsert
        $existing = $db->prepare("SELECT blog_key FROM yy_blog WHERE blog_post_id = ?");
        $existing->execute([$postId]);

        if ($existing->fetch()) {
            $stmt = $db->prepare("UPDATE yy_blog SET blog_message = ?, blog_image = ?, blog_image_url = ?, blog_type_code = ?, blog_create_dtime = ?, blog_source_code = ?, blog_video_id = ? WHERE blog_post_id = ?");
            $stmt->execute([$message, $localImage, $fullPicture, $postType, $createdTime, $FB_PAGE_ID, $videoId, $postId]);
            $updated++;
        } else {
            $stmt = $db->prepare("INSERT INTO yy_blog (blog_post_id, blog_message, blog_image, blog_image_url, blog_type_code, blog_create_dtime, blog_source_code, blog_video_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$postId, $message, $localImage, $fullPicture, $postType, $createdTime, $FB_PAGE_ID, $videoId]);
            $inserted++;
        }

        $typeTag = $videoId ? " [VIDEO:$videoId]" : ($localImage ? " [IMG]" : "");
        echo "  [$postId]$typeTag " . substr($message, 0, 60) . "\n";
    }

    // Next page
    $url = $json['paging']['next'] ?? null;
}

echo "\nDone! Inserted: $inserted, Updated: $updated\n";

// Return JSON for HTTP mode
if ($isHttpSync) {
    $output = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['inserted' => $inserted, 'updated' => $updated, 'output' => $output]);
    exit;
}
