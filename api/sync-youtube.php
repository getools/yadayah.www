<?php
/**
 * YouTube feed sync — fetches videos from YouTube RSS/API and stores in yy_feed_item.
 * Syncs ALL active YouTube feeds (channels and playlists).
 *
 * CLI:   php sync-youtube.php
 * Web:   GET /api/sync-youtube.php?key=yada2026sync
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDb();
$isCli = php_sapi_name() === 'cli';

if (!$isCli && !defined('SYNC_CALLED_FROM_PARENT')) {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') {
        requireAuth();
    }
}

// Find all active YouTube feeds
$feeds = $db->query("SELECT feed_key, feed_name, feed_account_id, feed_api_key, feed_api_endpoint FROM yy_feed WHERE lower(feed_site_code) = 'youtube' AND feed_active_flag = TRUE ORDER BY feed_key")->fetchAll();
if (!$feeds) {
    $msg = 'No active YouTube feeds found';
    if ($isCli) { echo "$msg\n"; exit; }
    jsonResponse(['error' => $msg]);
}

$results = [];

foreach ($feeds as $feed) {
    $feedKey = (int)$feed['feed_key'];
    $accountId = $feed['feed_account_id'];
    $apiKey = $feed['feed_api_key'] ?: getenv('YOUTUBE_API_KEY') ?: '';
    $isPlaylist = stripos($feed['feed_api_endpoint'] ?? '', 'playlist') !== false || substr($accountId, 0, 2) === 'PL';

    // Start sync log
    $db->prepare("INSERT INTO yy_feed_sync (feed_key, feed_sync_status) VALUES (?, 'running')")->execute([$feedKey]);
    $syncKey = $db->lastInsertId('yy_feed_sync_feed_sync_key_seq');

    $totalFound = 0;
    $totalInserted = 0;
    $totalUpdated = 0;
    $error = null;

    try {
        $videos = [];

        if ($isPlaylist) {
            $videos = fetchYouTubeRss("https://www.youtube.com/feeds/videos.xml?playlist_id=" . urlencode($accountId));
        } else {
            $videos = fetchYouTubeRss("https://www.youtube.com/feeds/videos.xml?channel_id=" . urlencode($accountId));

            // RSS only returns ~15 videos. If API key available, fetch more via API
            if ($apiKey) {
                $apiVideos = fetchYouTubeApi($accountId, $apiKey);
                // Merge, dedup by ID
                $existing = array_column($videos, null, 'id');
                foreach ($apiVideos as $v) {
                    if (!isset($existing[$v['id']])) {
                        $videos[] = $v;
                    }
                }
            }
        }

        // Fetch durations + descriptions from YouTube Data API (batches of 50)
        if ($apiKey && $videos) {
            $details = fetchYouTubeVideoDetails(array_column($videos, 'id'), $apiKey);
            foreach ($videos as &$v) {
                if (isset($details[$v['id']])) {
                    $v['duration'] = $details[$v['id']]['seconds'];
                    $v['durationStr'] = $details[$v['id']]['formatted'];
                    $v['orientation'] = $details[$v['id']]['orientation'] ?? null;
                    if (empty($v['description'])) {
                        $v['description'] = $details[$v['id']]['description'];
                    }
                }
            }
            unset($v);
        }

        $totalFound = count($videos);

        // Determine video type based on feed config
        $type = $isPlaylist ? 'video' : 'channel';

        foreach ($videos as $v) {
            $videoId = $v['id'];
            if (!$videoId) continue;

            // Type is just 'channel' or 'video' now — shorts are identified by duration,
            // filtered at query time via yy_feed_page.feed_page_filter_duration_max.
            $itemType = $type;
            $durationSeconds = isset($v['duration']) ? (int)$v['duration'] : null;
            // Detect orientation: #shorts in title/description = vertical, otherwise use thumbnail aspect ratio
            $searchText = strtolower(($v['title'] ?? '') . ' ' . ($v['description'] ?? ''));
            $isShort = strpos($searchText, '#shorts') !== false;
            if ($isShort) {
                $orientation = 'vertical';
            } else {
                $orientation = $v['orientation'] ?? null;
                // Fallback: if no thumbnail orientation and very short duration, leave as null (unknown)
            }

            // Defensively decode HTML entities (handles double-encoding)
            $cleanTitle = (string)$v['title'];
            do {
                $prev = $cleanTitle;
                $cleanTitle = html_entity_decode($cleanTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } while ($cleanTitle !== $prev);

            // Auto-detect ALL #vlog|[category]|[episode] hashtags in title or description.
            // A single item can belong to multiple categories — collect every match.
            // Skip #vlog parsing for Basics videos — they have their own category system.
            $categoryAssignments = []; // [['category_key' => N, 'episode' => '###'], ...]
            $searchText = $cleanTitle . "\n" . ($v['description'] ?? '');
            $isBasics = stripos($cleanTitle, '#Basics') !== false;
            if (!$isBasics && preg_match_all('/#vlog\|([^|\s]+)\|(\d+)/i', $searchText, $htMatches, PREG_SET_ORDER)) {
                $seenSlugs = [];
                foreach ($htMatches as $htMatch) {
                    $catSlug = strtolower(trim($htMatch[1]));
                    if (isset($seenSlugs[$catSlug])) continue;
                    $seenSlugs[$catSlug] = true;
                    $epNum = $htMatch[2];
                    $catLookup = $db->prepare("SELECT category_key FROM yy_feed_page_category WHERE page_key = 1 AND category_slug = ?");
                    $catLookup->execute([$catSlug]);
                    $catKey = $catLookup->fetchColumn() ?: null;
                    if (!$catKey) {
                        $catTitle = ucwords(str_replace('-', ' ', $catSlug));
                        $catIns = $db->prepare("INSERT INTO yy_feed_page_category (page_key, category_title, category_slug, category_sort) VALUES (1, ?, ?, 0) ON CONFLICT (page_key, category_slug) DO UPDATE SET category_revision_dtime = NOW() RETURNING category_key");
                        $catIns->execute([$catTitle, $catSlug]);
                        $catKey = (int)$catIns->fetchColumn();
                    }
                    $categoryAssignments[] = ['category_key' => (int)$catKey, 'episode' => $epNum];
                }
            }
            // Legacy single-column fields: use first match for backwards compatibility
            $categoryKey = $categoryAssignments[0]['category_key'] ?? null;
            $episode = $categoryAssignments[0]['episode'] ?? null;

            // Build tags from hashtags found in title + description
            // Place #vlog first so the starts-with filter (#vlog*) works on the comma-separated string
            $itemTags = null;
            if (preg_match_all('/#[a-zA-Z][a-zA-Z0-9_-]+/', $searchText, $tagMatches)) {
                $tags = array_unique($tagMatches[0]);
                $vlogTag = null;
                $otherTags = [];
                foreach ($tags as $tag) {
                    if (strtolower($tag) === '#vlog') $vlogTag = $tag;
                    else $otherTags[] = $tag;
                }
                if ($vlogTag) array_unshift($otherTags, $vlogTag);
                $itemTags = implode(',', $otherTags);
            }

            $stmt = $db->prepare("
                INSERT INTO yy_feed_item (feed_key, feed_item_external_id, feed_item_title_import, feed_item_url, feed_item_thumbnail, feed_item_embed_id, feed_item_publish_import_dtime, feed_item_active_flag, feed_item_type, feed_item_duration, feed_item_duration_seconds, feed_item_orientation, feed_item_category_key, feed_item_episode, feed_item_tags)
                VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (feed_key, feed_item_external_id) DO UPDATE SET
                    feed_item_title_import = EXCLUDED.feed_item_title_import,
                    feed_item_thumbnail = COALESCE(EXCLUDED.feed_item_thumbnail, yy_feed_item.feed_item_thumbnail),
                    feed_item_publish_import_dtime = COALESCE(EXCLUDED.feed_item_publish_import_dtime, yy_feed_item.feed_item_publish_import_dtime),
                    feed_item_type = EXCLUDED.feed_item_type,
                    feed_item_duration = COALESCE(EXCLUDED.feed_item_duration, yy_feed_item.feed_item_duration),
                    feed_item_duration_seconds = COALESCE(EXCLUDED.feed_item_duration_seconds, yy_feed_item.feed_item_duration_seconds),
                    feed_item_orientation = COALESCE(EXCLUDED.feed_item_orientation, yy_feed_item.feed_item_orientation),
                    feed_item_category_key = COALESCE(EXCLUDED.feed_item_category_key, yy_feed_item.feed_item_category_key),
                    feed_item_episode = COALESCE(EXCLUDED.feed_item_episode, yy_feed_item.feed_item_episode),
                    feed_item_tags = COALESCE(EXCLUDED.feed_item_tags, yy_feed_item.feed_item_tags),
                    feed_item_revision_dtime = NOW()
                WHERE yy_feed_item.feed_item_title_import IS DISTINCT FROM EXCLUDED.feed_item_title_import
                   OR yy_feed_item.feed_item_thumbnail IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_thumbnail, yy_feed_item.feed_item_thumbnail)
                   OR yy_feed_item.feed_item_type IS DISTINCT FROM EXCLUDED.feed_item_type
                   OR yy_feed_item.feed_item_duration IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_duration, yy_feed_item.feed_item_duration)
                   OR yy_feed_item.feed_item_category_key IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_category_key, yy_feed_item.feed_item_category_key)
                   OR yy_feed_item.feed_item_episode IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_episode, yy_feed_item.feed_item_episode)
                   OR yy_feed_item.feed_item_tags IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_tags, yy_feed_item.feed_item_tags)
                   OR yy_feed_item.feed_item_orientation IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_orientation, yy_feed_item.feed_item_orientation)
                RETURNING (xmax = 0) AS inserted
            ");

            $stmt->execute([
                $feedKey, $videoId, $cleanTitle,
                'https://www.youtube.com/watch?v=' . $videoId,
                $v['thumbnail'], $videoId,
                $v['published'] ?: null,
                $itemType,
                $v['durationStr'] ?? null,
                $durationSeconds,
                $orientation,
                $categoryKey,
                $episode,
                $itemTags,
            ]);
            $row = $stmt->fetch();
            if ($row) {
                if ($row['inserted']) $totalInserted++;
                else $totalUpdated++;
            }

            // Populate yy_feed_item_category for all detected category assignments
            if ($categoryAssignments) {
                $itemKeyStmt = $db->prepare("SELECT feed_item_key FROM yy_feed_item WHERE feed_key = ? AND feed_item_external_id = ?");
                $itemKeyStmt->execute([$feedKey, $videoId]);
                $itemKey = (int)($itemKeyStmt->fetchColumn() ?: 0);
                if ($itemKey) {
                    $catUp = $db->prepare("INSERT INTO yy_feed_item_category (feed_item_key, category_key, feed_item_category_episode) VALUES (?, ?, ?) ON CONFLICT (feed_item_key, category_key) DO UPDATE SET feed_item_category_episode = EXCLUDED.feed_item_category_episode");
                    foreach ($categoryAssignments as $ca) {
                        $catUp->execute([$itemKey, $ca['category_key'], $ca['episode']]);
                    }
                }
            }
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

// ── Check for restricted/private videos ──
// Spot-check items whose thumbnails return 404 (indicates private/deleted on YouTube)
$restrictStmt = $db->query("
    SELECT feed_item_key, feed_item_thumbnail, feed_item_external_id
    FROM yy_feed_item
    WHERE feed_item_active_flag = TRUE AND feed_item_restricted_flag = FALSE
      AND feed_item_thumbnail LIKE 'https://i.ytimg.com/%'
    ORDER BY feed_item_publish_import_dtime DESC
    LIMIT 50
");
$restrictCount = 0;
foreach ($restrictStmt->fetchAll() as $ri) {
    $headers = @get_headers($ri['feed_item_thumbnail'], true);
    $httpCode = $headers ? (int)substr($headers[0], 9, 3) : 0;
    if ($httpCode === 404) {
        $db->prepare("UPDATE yy_feed_item SET feed_item_restricted_flag = TRUE WHERE feed_item_key = ?")
           ->execute([$ri['feed_item_key']]);
        $restrictCount++;
        if ($isCli) echo "Restricted: {$ri['feed_item_external_id']} (thumbnail 404)\n";
    }
}
if ($restrictCount > 0 && $isCli) echo "Marked {$restrictCount} item(s) as restricted\n";

// Update feed item → page associations after sync
require_once __DIR__ . '/feed-item-pages.php';
foreach ($feeds as $feed) {
    updateItemPagesForFeed($db, (int)$feed['feed_key']);
}

if (!$isCli) {
    jsonResponse(['synced' => true, 'results' => $results]);
}

// ── Helper: Fetch videos from YouTube RSS ──
function fetchYouTubeRss(string $url): array {
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0']]);
    $xml = @file_get_contents($url, false, $ctx);
    if (!$xml) return [];

    $feed = @simplexml_load_string($xml);
    if (!$feed) return [];

    $videos = [];
    foreach ($feed->entry as $entry) {
        $ns = $entry->children('yt', true);
        $videoId = (string)$ns->videoId;
        if (!$videoId) continue;

        $videos[] = [
            'id' => $videoId,
            'title' => (string)$entry->title,
            'published' => (string)$entry->published,
            'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
        ];
    }
    return $videos;
}

// ── Helper: Fetch videos from YouTube Data API (channel uploads) ──
function fetchYouTubeApi(string $channelId, string $apiKey): array {
    // Get uploads playlist ID
    $url = "https://www.googleapis.com/youtube/v3/channels?" . http_build_query([
        'part' => 'contentDetails',
        'id' => $channelId,
        'key' => $apiKey,
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return [];
    $data = json_decode($json, true);
    $uploadsId = $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '';
    if (!$uploadsId) return [];

    // Fetch all pages of uploads
    $videos = [];
    $pageToken = '';
    $maxPages = 40; // ~2000 videos max

    for ($p = 0; $p < $maxPages; $p++) {
        $url = "https://www.googleapis.com/youtube/v3/playlistItems?" . http_build_query(array_filter([
            'part' => 'snippet',
            'playlistId' => $uploadsId,
            'maxResults' => 50,
            'pageToken' => $pageToken,
            'key' => $apiKey,
        ]));
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) break;
        $result = json_decode($json, true);

        foreach (($result['items'] ?? []) as $item) {
            $s = $item['snippet'] ?? [];
            $videoId = $s['resourceId']['videoId'] ?? '';
            if (!$videoId) continue;
            $videos[] = [
                'id' => $videoId,
                'title' => $s['title'] ?? '',
                'description' => $s['description'] ?? '',
                'published' => $s['publishedAt'] ?? '',
                'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            ];
        }

        $pageToken = $result['nextPageToken'] ?? '';
        if (!$pageToken) break;
    }

    return $videos;
}

// ── Helper: Fetch durations + descriptions for a list of video IDs (batches of 50) ──
function fetchYouTubeVideoDetails(array $videoIds, string $apiKey): array {
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $details = [];

    foreach (array_chunk($videoIds, 50) as $batch) {
        $url = "https://www.googleapis.com/youtube/v3/videos?" . http_build_query([
            'part' => 'contentDetails,snippet',
            'id' => implode(',', $batch),
            'key' => $apiKey,
        ]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) continue;
        $result = json_decode($json, true);

        foreach (($result['items'] ?? []) as $item) {
            $id = $item['id'] ?? '';
            if (!$id) continue;
            $iso = $item['contentDetails']['duration'] ?? '';
            $seconds = $iso ? parseIsoDuration($iso) : 0;
            // Determine orientation from thumbnail aspect ratio
            $thumbs = $item['snippet']['thumbnails'] ?? [];
            $thumbInfo = $thumbs['high'] ?? $thumbs['medium'] ?? $thumbs['default'] ?? [];
            $tw = (int)($thumbInfo['width'] ?? 0);
            $th = (int)($thumbInfo['height'] ?? 0);
            $orient = null;
            if ($tw > 0 && $th > 0) {
                $orient = ($th > $tw) ? 'vertical' : 'horizontal';
            }

            $details[$id] = [
                'seconds' => $seconds,
                'formatted' => formatDuration($seconds),
                'description' => $item['snippet']['description'] ?? '',
                'orientation' => $orient,
            ];
        }
    }

    return $details;
}

function fetchYouTubeDurations(array $videoIds, string $apiKey): array {
    return fetchYouTubeVideoDetails($videoIds, $apiKey);
}

function parseIsoDuration(string $iso): int {
    $seconds = 0;
    if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m)) {
        $seconds += (int)($m[1] ?? 0) * 3600;
        $seconds += (int)($m[2] ?? 0) * 60;
        $seconds += (int)($m[3] ?? 0);
    }
    return $seconds;
}

function formatDuration(int $seconds): string {
    if ($seconds >= 3600) {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}
