<?php
/**
 * Public API for Music videos (YouTube playlist).
 *
 * GET ?page=N          — paginated list (24 per page)
 * GET ?action=sync     — (auth required) refresh from YouTube Data API v3
 */
require_once __DIR__ . '/config.php';

$YT_PLAYLIST_ID = 'PLW5gXgQ3YcPCy7jFNQ_4Q759SVdS0e035';
$YT_API_KEY     = getenv('YOUTUBE_API_KEY') ?: '';
$PER_PAGE       = 24;

$db = getDb();

$action = $_GET['action'] ?? '';

// ── Sync from YouTube Data API v3 (auth required) ──
if ($action === 'sync') {
    $user = requireAuth();

    if (!$YT_API_KEY) {
        errorResponse('YOUTUBE_API_KEY not configured', 500);
    }

    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;
    $pageToken = '';

    do {
        $url = "https://www.googleapis.com/youtube/v3/playlistItems"
             . "?part=snippet&maxResults=50&playlistId=$YT_PLAYLIST_ID"
             . "&key=$YT_API_KEY";
        if ($pageToken) $url .= "&pageToken=$pageToken";

        $response = @file_get_contents($url);
        if ($response === false) break;

        $json = json_decode($response, true);
        if (empty($json['items'])) break;

        foreach ($json['items'] as $item) {
            $snippet = $item['snippet'] ?? [];
            $title = $snippet['title'] ?? '';
            $videoId = $snippet['resourceId']['videoId'] ?? '';
            if (!$videoId) continue;

            // Exclude videos with "Private" in their title
            if (stripos($title, 'Private') !== false) {
                $skipped++;
                continue;
            }

            // Skip deleted videos
            if ($title === 'Deleted video') {
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
        }

        $pageToken = $json['nextPageToken'] ?? '';
    } while ($pageToken);

    jsonResponse(['synced' => true, 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped]);
}

// ── Public: paginated video list ──
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;

$countStmt = $db->query("SELECT COUNT(*) FROM yy_music WHERE music_active_flag = TRUE");
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $PER_PAGE));

$stmt = $db->prepare("
    SELECT music_key, music_video_id, music_title, music_thumbnail, music_create
    FROM yy_music
    WHERE music_active_flag = TRUE
    ORDER BY music_key
    LIMIT ? OFFSET ?
");
$stmt->execute([$PER_PAGE, $offset]);

jsonResponse([
    'videos'      => $stmt->fetchAll(),
    'page'        => $page,
    'total_pages' => $totalPages,
    'total'       => $total,
]);
