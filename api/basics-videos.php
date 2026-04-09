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
    SELECT f.feed_account_id, fp.feed_page_filter_include, fp.feed_page_filter_exclude, f.feed_account_id
    FROM yy_feed_page fp
    JOIN yy_feed f ON f.feed_key = fp.feed_key
    JOIN yy_page p ON p.page_key = fp.page_key
    WHERE p.page_code = 'basics'
    ORDER BY fp.feed_page_sort, fp.feed_page_key
    LIMIT 1
");
$feedRow = $feedStmt->fetch();
$includeFilter = $feedRow['feed_page_filter_include'] ?? '#Basics';
$channelId     = $feedRow['feed_account_id'] ?? '';
$excludeTerms  = $feedRow ? array_filter(array_map('trim', explode(',', $feedRow['feed_page_filter_exclude'] ?? ''))) : [];

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

    // Search the channel for candidate videos, then verify #Basics hashtag in description
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

        // Collect video IDs from search results
        $candidateIds = [];
        $candidateSnippets = [];
        foreach ($json['items'] as $item) {
            $videoId = $item['id']['videoId'] ?? '';
            if ($videoId) {
                $candidateIds[] = $videoId;
                $candidateSnippets[$videoId] = $item['snippet'] ?? [];
            }
        }

        // Fetch full video details to check description for #Basics hashtag
        if ($candidateIds) {
            $chunks = array_chunk($candidateIds, 50);
            foreach ($chunks as $chunk) {
                $detailUrl = "https://www.googleapis.com/youtube/v3/videos"
                    . "?part=snippet&id=" . urlencode(implode(',', $chunk))
                    . "&key=$YT_API_KEY";
                $detailResp = @file_get_contents($detailUrl);
                if (!$detailResp) continue;
                $detailJson = json_decode($detailResp, true);

                foreach ($detailJson['items'] ?? [] as $video) {
                    $videoId = $video['id'] ?? '';
                    $snippet = $video['snippet'] ?? [];
                    $description = $snippet['description'] ?? '';
                    $title = $snippet['title'] ?? '';
                    $tags = $snippet['tags'] ?? [];

                    // Verify: title or description must contain literal #Basics hashtag
                    $hasHashtag = (bool)preg_match('/#Basics\b/i', $title . ' ' . $description);

                    // Strip hashtag words from title (e.g. #Basics, #DoYouYada)
                    $title = trim(preg_replace('/\s*#\w+/', '', $title));
                    // Strip "The Basics ~ " prefix
                    $title = preg_replace('/^The Basics\s*~\s*/i', '', $title);
                    if (!$hasHashtag) { $skipped++; continue; }

                    // Skip videos matching exclude terms
                    $skip = false;
                    foreach ($excludeTerms as $term) {
                        if (stripos($title, $term) !== false) { $skip = true; break; }
                    }
                    if ($skip) { $skipped++; continue; }

                    $thumbUrl = '';
                    $thumbs = $snippet['thumbnails'] ?? [];
                    foreach (['maxres', 'high', 'medium', 'default'] as $size) {
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

// Grouped mode: return videos organized by category
if (isset($_GET['grouped'])) {
    $cats = $db->query("
        SELECT basics_category_key, basics_category_title, basics_category_subtitle, basics_category_sort
        FROM yy_basics_category ORDER BY basics_category_sort, basics_category_title
    ")->fetchAll();
    $videos = $db->query("
        SELECT b.basics_key, b.basics_video_id, b.basics_title, b.basics_thumbnail, b.basics_category_key, b.basics_sort
        FROM yy_basics b WHERE b.basics_active_flag = TRUE
        ORDER BY b.basics_sort ASC, b.basics_title ASC
    ")->fetchAll();
    // Group videos by category
    $grouped = [];
    foreach ($cats as $c) {
        $catVideos = array_values(array_filter($videos, function($v) use ($c) {
            return (int)$v['basics_category_key'] === (int)$c['basics_category_key'];
        }));
        if ($catVideos) {
            $grouped[] = [
                'category' => $c,
                'videos' => $catVideos,
            ];
        }
    }
    // Uncategorized videos
    $uncategorized = array_values(array_filter($videos, function($v) { return !$v['basics_category_key']; }));
    if ($uncategorized) {
        $grouped[] = ['category' => null, 'videos' => $uncategorized];
    }
    jsonResponse(['sections' => $grouped, 'total' => $total]);
}

if ($limit > 0) {
    $stmt = $db->prepare("SELECT basics_key, basics_video_id, basics_title, basics_thumbnail, basics_create, basics_category_key FROM yy_basics WHERE basics_active_flag = TRUE ORDER BY basics_sort ASC, basics_create DESC LIMIT ?");
    $stmt->execute([$limit]);
    jsonResponse(['videos' => $stmt->fetchAll(), 'page' => 1, 'total_pages' => 1, 'total' => $total]);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT basics_key, basics_video_id, basics_title, basics_thumbnail, basics_create, basics_category_key
    FROM yy_basics
    WHERE basics_active_flag = TRUE
    ORDER BY basics_sort ASC, basics_create DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$PER_PAGE, $offset]);

jsonResponse([
    'videos'      => $stmt->fetchAll(),
    'page'        => $page,
    'total_pages' => $totalPages,
    'total'       => $total,
]);
