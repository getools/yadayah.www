<?php
/**
 * Public API for Invite videos (Facebook page videos tagged #Invite).
 *
 * GET ?page=N          — paginated list (24 per page)
 * GET ?action=sync     — (auth required) refresh from Facebook Graph API
 */
require_once __DIR__ . '/config.php';

$FB_PAGE_ID    = getenv('FB_PAGE_ID') ?: '102425844783696';
$FB_PAGE_TOKEN = getenv('FB_PAGE_TOKEN') ?: '';
$THUMB_DIR     = __DIR__ . '/../u/invite';
$PER_PAGE      = 24;

$db = getDb();

$action = $_GET['action'] ?? '';

// ── Sync from Facebook Graph API (auth required) ──
if ($action === 'sync') {
    $user = requireAuth();

    if (!$FB_PAGE_TOKEN) {
        errorResponse('FB_PAGE_TOKEN not configured', 500);
    }

    if (!is_dir($THUMB_DIR)) mkdir($THUMB_DIR, 0755, true);

    $inserted = 0;
    $updated  = 0;
    $url = "https://graph.facebook.com/v23.0/$FB_PAGE_ID/videos"
         . "?fields=title,description,format{picture},created_time,status"
         . "&access_token=$FB_PAGE_TOKEN&limit=100";

    while ($url) {
        $response = @file_get_contents($url);
        if ($response === false) break;

        $json = json_decode($response, true);
        if (empty($json['data'])) break;

        foreach ($json['data'] as $video) {
            $videoId = $video['id'] ?? '';
            if (!$videoId) continue;

            // Only include published videos
            if (isset($video['status']['video_status']) && $video['status']['video_status'] !== 'ready') continue;

            // Check for #Invite hashtag in title or description
            $text = ($video['title'] ?? '') . ' ' . ($video['description'] ?? '');
            if (stripos($text, '#Invite') === false && stripos($text, '#invite') === false) continue;

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
            $existing = $db->prepare("SELECT feed_key FROM yy_feed WHERE feed_video_id = ?");
            $existing->execute([$videoId]);

            if ($existing->fetch()) {
                $stmt = $db->prepare("UPDATE yy_feed SET feed_title = ?, feed_thumbnail = ?, feed_create = ? WHERE feed_video_id = ?");
                $stmt->execute([$title, $localThumb, $createdTime, $videoId]);
                $updated++;
            } else {
                $stmt = $db->prepare("INSERT INTO yy_feed (feed_video_id, feed_title, feed_thumbnail, feed_create) VALUES (?, ?, ?, ?)");
                $stmt->execute([$videoId, $title, $localThumb, $createdTime]);
                $inserted++;
            }
        }

        // Next page
        $url = $json['paging']['next'] ?? null;
    }

    jsonResponse(['synced' => true, 'inserted' => $inserted, 'updated' => $updated]);
}

// ── Public: paginated video list ──
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;

$countStmt = $db->query("SELECT COUNT(*) FROM yy_feed WHERE feed_active_flag = TRUE");
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT feed_key, feed_video_id, feed_title, feed_thumbnail, feed_create
    FROM yy_feed
    WHERE feed_active_flag = TRUE
    ORDER BY feed_create DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$PER_PAGE, $offset]);

jsonResponse([
    'videos'      => $stmt->fetchAll(),
    'page'        => $page,
    'total_pages' => $totalPages,
    'total'       => $total,
]);
