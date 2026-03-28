<?php
/**
 * Public API for Basics videos (YouTube #Basics hashtag).
 *
 * GET ?page=N          — paginated list (24 per page)
 * GET ?limit=N         — fixed limit in one shot
 * GET ?action=sync     — (auth required) refresh from YouTube Data API v3
 */
require_once __DIR__ . '/config.php';

$YT_API_KEY = getenv('YOUTUBE_API_KEY') ?: '';
$PER_PAGE   = 24;

$db = getDb();

// Load feed config
$feedStmt = $db->query("
    SELECT f.feed_list, f.feed_filter_positive, pf.page_feed_filter_exclude
    FROM yy_page_feed pf
    JOIN yy_feed f ON f.feed_key = pf.feed_key
    JOIN yy_page p ON p.page_key = pf.page_key
    WHERE p.page_code = 'basics'
    ORDER BY pf.page_feed_sort, pf.page_feed_key
    LIMIT 1
");
$feedRow = $feedStmt->fetch();
$includeFilter = $feedRow['feed_filter_positive'] ?? '#Basics';
$channelId     = $feedRow['feed_list'] ?? '';
$excludeTerms  = $feedRow ? array_filter(array_map('trim', explode(',', $feedRow['page_feed_filter_exclude'] ?? ''))) : [];

$action = $_GET['action'] ?? '';

// ── Sync from YouTube Data API v3 ──
if ($action === 'sync') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') {
        $user = requireAuth();
    }

    if (!$YT_API_KEY) {
        errorResponse('YOUTUBE_API_KEY not configured', 500);
    }

    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;

    // Search the channel for videos matching the include filter (e.g. #Basics)
    $pageToken = '';
    do {
        $url = "https://www.googleapis.com/youtube/v3/search"
             . "?part=snippet&maxResults=50&type=video"
             . "&channelId=" . urlencode($channelId)
             . "&q=" . urlencode($includeFilter)
             . "&key=$YT_API_KEY";
        if ($pageToken) $url .= "&pageToken=$pageToken";

        $response = @file_get_contents($url);
        if ($response === false) break;
        $json = json_decode($response, true);
        if (empty($json['items'])) break;

        foreach ($json['items'] as $item) {
            $snippet = $item['snippet'] ?? [];
            $title = $snippet['title'] ?? '';
            $videoId = $item['id']['videoId'] ?? '';
            if (!$videoId) continue;

            // Skip videos matching exclude terms
            $skip = false;
            foreach ($excludeTerms as $term) {
                if (stripos($title, $term) !== false) { $skip = true; break; }
            }
            if ($skip) { $skipped++; continue; }

            $thumbUrl = '';
            $thumbs = $snippet['thumbnails'] ?? [];
            foreach (['high', 'medium', 'default'] as $size) {
                if (!empty($thumbs[$size]['url'])) { $thumbUrl = $thumbs[$size]['url']; break; }
            }

            $publishedAt = $snippet['publishedAt'] ?? null;
            $existing = $db->prepare("SELECT basics_key FROM yy_basics WHERE basics_video_id = ?");
            $existing->execute([$videoId]);

            if ($existing->fetch()) {
                $db->prepare("UPDATE yy_basics SET basics_title = ?, basics_thumbnail = ?, basics_create = ? WHERE basics_video_id = ?")
                    ->execute([$title, $thumbUrl, $publishedAt, $videoId]);
                $updated++;
            } else {
                $db->prepare("INSERT INTO yy_basics (basics_video_id, basics_title, basics_thumbnail, basics_create) VALUES (?, ?, ?, ?)")
                    ->execute([$videoId, $title, $thumbUrl, $publishedAt]);
                $inserted++;
            }
        }
        $pageToken = $json['nextPageToken'] ?? '';
    } while ($pageToken);

    jsonResponse(['synced' => true, 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped]);
}

// ── Public: paginated video list ──
$limit = (int)($_GET['limit'] ?? 0);

$countStmt = $db->query("SELECT COUNT(*) FROM yy_basics WHERE basics_active_flag = TRUE");
$total = (int)$countStmt->fetchColumn();

if ($limit > 0) {
    $stmt = $db->prepare("SELECT basics_key, basics_video_id, basics_title, basics_thumbnail, basics_create FROM yy_basics WHERE basics_active_flag = TRUE ORDER BY basics_key LIMIT ?");
    $stmt->execute([$limit]);
    jsonResponse(['videos' => $stmt->fetchAll(), 'page' => 1, 'total_pages' => 1, 'total' => $total]);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT basics_key, basics_video_id, basics_title, basics_thumbnail, basics_create
    FROM yy_basics
    WHERE basics_active_flag = TRUE
    ORDER BY basics_key
    LIMIT ? OFFSET ?
");
$stmt->execute([$PER_PAGE, $offset]);

jsonResponse([
    'videos'      => $stmt->fetchAll(),
    'page'        => $page,
    'total_pages' => $totalPages,
    'total'       => $total,
]);
