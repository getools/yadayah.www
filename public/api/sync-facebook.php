<?php
/**
 * Facebook feed sync — fetches posts from Facebook Graph API and stores in yy_feed_item.
 * Syncs ALL Facebook feeds, tagging items with hashtags for page-level filtering.
 *
 * CLI:   php sync-facebook.php
 * Web:   GET /api/sync-facebook.php?key=yada2026sync
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

// Find all active Facebook feeds
$feeds = $db->query("SELECT feed_key, feed_name, feed_account_id, feed_api_key FROM yy_feed WHERE feed_site_code = 'Facebook' AND feed_active_flag = TRUE")->fetchAll();
if (!$feeds) {
    $msg = 'No active Facebook feeds found';
    if ($isCli) { echo "$msg\n"; exit; }
    jsonResponse(['error' => $msg]);
}

$results = [];

foreach ($feeds as $feed) {
    $feedKey = (int)$feed['feed_key'];
    $pageId = $feed['feed_account_id'];
    $token = $feed['feed_api_key'] ?: getenv('FB_PAGE_TOKEN') ?: '';

    if (!$token) {
        $results[] = ['feed' => $feed['feed_name'], 'error' => 'No API token'];
        continue;
    }

    // Start sync log
    $db->prepare("INSERT INTO yy_feed_sync (feed_key, feed_sync_status) VALUES (?, 'running')")->execute([$feedKey]);
    $syncKey = $db->lastInsertId('yy_feed_sync_feed_sync_key_seq');

    $totalFound = 0;
    $totalInserted = 0;
    $totalUpdated = 0;
    $error = null;

    // Thumbnail directory
    $thumbDir = __DIR__ . '/../u/fb';
    if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

    try {
        $url = "https://graph.facebook.com/v23.0/$pageId/posts"
             . "?fields=message,full_picture,created_time,parent_id,type,attachments%7Bmedia%7Bimage%7D%7D"
             . "&access_token=$token&limit=25";

        while ($url) {
            $response = @file_get_contents($url);
            if ($response === false) break;

            $json = json_decode($response, true);
            if (empty($json['data'])) break;

            foreach ($json['data'] as $post) {
                $postId = $post['id'] ?? '';
                if (!$postId) continue;

                $message = $post['message'] ?? '';
                if (!$message) continue;

                $totalFound++;

                // Extract hashtags for tagging
                preg_match_all('/#\w+/', $message, $hashMatches);
                $tags = implode(',', array_unique($hashMatches[0] ?? []));

                // Prefer full-size image from attachments
                $fullPicture = '';
                if (!empty($post['attachments']['data'])) {
                    foreach ($post['attachments']['data'] as $att) {
                        $img = $att['media']['image']['src'] ?? '';
                        if ($img) { $fullPicture = $img; break; }
                    }
                }
                if (!$fullPicture) $fullPicture = $post['full_picture'] ?? '';

                // For shared posts, try parent's image
                if (!$fullPicture && !empty($post['parent_id'])) {
                    $parentResp = @file_get_contents("https://graph.facebook.com/v23.0/{$post['parent_id']}?fields=full_picture&access_token=$token");
                    if ($parentResp !== false) {
                        $parentData = json_decode($parentResp, true);
                        $fullPicture = $parentData['full_picture'] ?? '';
                    }
                }

                // Download image locally
                $localThumb = null;
                if ($fullPicture) {
                    $safeId = preg_replace('/[^a-zA-Z0-9]/', '_', $postId);
                    $thumbFile = "thumb_$safeId.jpg";
                    $thumbPath = "$thumbDir/$thumbFile";
                    if (!file_exists($thumbPath)) {
                        $imgData = @file_get_contents($fullPicture);
                        if ($imgData !== false) {
                            file_put_contents($thumbPath, $imgData);
                        }
                    }
                    $localThumb = "u/fb/$thumbFile";
                }

                // Clean title
                $title = trim($message);
                $title = preg_replace('/^[#\w\s.]+$/m', '', $title);
                $title = trim($title);
                if (!$title) $title = 'Facebook Post';
                if (mb_strlen($title) > 300) $title = mb_substr($title, 0, 297) . '...';

                $createdTime = $post['created_time'] ?? null;
                $postType = $post['type'] ?? 'photo';

                // Upsert into yy_feed_item
                $stmt = $db->prepare("
                    INSERT INTO yy_feed_item (feed_key, feed_item_external_id, feed_item_title, feed_item_url, feed_item_thumbnail, feed_item_publish_dtime, feed_item_active_flag, feed_item_type, feed_item_tags)
                    VALUES (?, ?, ?, ?, ?, ?, TRUE, ?, ?)
                    ON CONFLICT (feed_key, feed_item_external_id) DO UPDATE SET
                        feed_item_title = EXCLUDED.feed_item_title,
                        feed_item_thumbnail = COALESCE(EXCLUDED.feed_item_thumbnail, yy_feed_item.feed_item_thumbnail),
                        feed_item_publish_dtime = COALESCE(EXCLUDED.feed_item_publish_dtime, yy_feed_item.feed_item_publish_dtime),
                        feed_item_type = EXCLUDED.feed_item_type,
                        feed_item_tags = EXCLUDED.feed_item_tags,
                        feed_item_revision_dtime = NOW()
                    RETURNING (xmax = 0) AS inserted
                ");
                $stmt->execute([
                    $feedKey, $postId, $title,
                    'https://www.facebook.com/' . $postId,
                    $localThumb, $createdTime, $postType, $tags
                ]);
                $row = $stmt->fetch();
                if ($row['inserted']) $totalInserted++;
                else $totalUpdated++;
            }

            $url = $json['paging']['next'] ?? null;
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
