<?php
/**
 * Public API for Do You Yada? posts (Facebook page photo posts tagged #DoYouYada).
 *
 * GET ?page=N          — paginated list (24 per page)
 * GET ?action=sync     — (auth required) refresh from Facebook Graph API
 */
require_once __DIR__ . '/config.php';

$THUMB_DIR     = __DIR__ . '/../u/doyou';
$PER_PAGE      = 24;

$db = getDb();

// Load feed config from yy_page_feed + yy_feed for the doyouyada page
$feedStmt = $db->query("
    SELECT f.feed_site_id, f.feed_api_key, pf.page_feed_filter_include, pf.page_feed_filter_exclude
    FROM yy_page_feed pf
    JOIN yy_feed f ON f.feed_key = pf.feed_key
    JOIN yy_page p ON p.page_key = pf.page_key
    WHERE p.page_code = 'doyouyada'
    ORDER BY pf.page_feed_sort, pf.page_feed_key
    LIMIT 1
");
$feedRow      = $feedStmt->fetch();
$FB_PAGE_ID   = $feedRow['feed_site_id'] ?? '102425844783696';
$FB_PAGE_TOKEN = $feedRow['feed_api_key'] ?? '';
$includeTerms = $feedRow ? array_filter(array_map('trim', explode(',', $feedRow['page_feed_filter_include'] ?? ''))) : ['#DoYouYada'];
$excludeTerms = $feedRow ? array_filter(array_map('trim', explode(',', $feedRow['page_feed_filter_exclude'] ?? ''))) : [];

// Fall back to env var
if (!$FB_PAGE_TOKEN) $FB_PAGE_TOKEN = getenv('FB_PAGE_TOKEN') ?: '';

$action = $_GET['action'] ?? '';

// ── Sync from Facebook Graph API (auth required) ──
if ($action === 'sync') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') {
        $user = requireAuth();
    }

    if (!$FB_PAGE_TOKEN) {
        errorResponse('FB_PAGE_TOKEN not configured', 500);
    }

    if (!is_dir($THUMB_DIR)) mkdir($THUMB_DIR, 0755, true);

    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;
    $url = "https://graph.facebook.com/v23.0/$FB_PAGE_ID/posts"
         . "?fields=message,full_picture,created_time,parent_id,type,attachments%7Bmedia%7Bimage%7D%7D"
         . "&access_token=$FB_PAGE_TOKEN&limit=25";

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

            // Check include terms
            $included = empty($includeTerms);
            foreach ($includeTerms as $term) {
                if (stripos($message, $term) !== false) { $included = true; break; }
            }
            if (!$included) { $skipped++; continue; }

            // Check exclude terms
            $excluded = false;
            foreach ($excludeTerms as $term) {
                if (stripos($message, $term) !== false) { $excluded = true; break; }
            }
            if ($excluded) { $skipped++; continue; }

            // Prefer full-size image from attachments over the resized full_picture
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
                $parentResp = @file_get_contents("https://graph.facebook.com/v23.0/{$post['parent_id']}?fields=full_picture&access_token=$FB_PAGE_TOKEN");
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
                $thumbPath = "$THUMB_DIR/$thumbFile";
                $imgData = @file_get_contents($fullPicture);
                if ($imgData !== false) {
                    file_put_contents($thumbPath, $imgData);
                    $localThumb = "u/doyou/$thumbFile";
                }
            }

            // Clean title (remove hashtag lines, keep substantive text)
            $title = trim($message);
            // Remove lines that are just hashtags
            $title = preg_replace('/^[#\w\s.]+$/m', '', $title);
            $title = trim($title);
            if (!$title) $title = 'Do You Yada?';
            // Truncate long titles
            if (mb_strlen($title) > 300) $title = mb_substr($title, 0, 297) . '...';

            $createdTime = $post['created_time'] ?? null;
            $postType = $post['type'] ?? 'photo';

            // Upsert using post ID
            $existing = $db->prepare("SELECT feed_key FROM yy_feed_doyou WHERE feed_video_id = ?");
            $existing->execute([$postId]);

            if ($existing->fetch()) {
                $stmt = $db->prepare("UPDATE yy_feed_doyou SET feed_title = ?, feed_thumbnail = ?, feed_create = ?, feed_type = ? WHERE feed_video_id = ?");
                $stmt->execute([$title, $localThumb, $createdTime, $postType, $postId]);
                $updated++;
            } else {
                $stmt = $db->prepare("INSERT INTO yy_feed_doyou (feed_video_id, feed_title, feed_thumbnail, feed_create, feed_type) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$postId, $title, $localThumb, $createdTime, $postType]);
                $inserted++;
            }
        }

        // Next page
        $url = $json['paging']['next'] ?? null;
    }

    jsonResponse(['synced' => true, 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped]);
}

// ── Public: paginated video list ──
// ?limit=N (N>0) returns exactly N records in one shot; absent/0 = paginated 24/page
$limit = (int)($_GET['limit'] ?? 0);

$countStmt = $db->query("SELECT COUNT(*) FROM yy_feed_doyou WHERE feed_active_flag = TRUE");
$total = (int)$countStmt->fetchColumn();

if ($limit > 0) {
    $stmt = $db->prepare("SELECT feed_key, feed_video_id, feed_title, feed_thumbnail, feed_create, feed_type FROM yy_feed_doyou WHERE feed_active_flag = TRUE ORDER BY feed_create DESC LIMIT ?");
    $stmt->execute([$limit]);
    jsonResponse(['videos' => $stmt->fetchAll(), 'page' => 1, 'total_pages' => 1, 'total' => $total]);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT feed_key, feed_video_id, feed_title, feed_thumbnail, feed_create
    FROM yy_feed_doyou
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
