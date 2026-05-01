<?php
/**
 * Sync Rumble videos into yy_feed_item.
 *
 * Data source: /var/www/html/api/rumble-cache.json which is produced on the
 * host by a cron that runs scrape-rumble.cjs inside the rsshub container
 * (which has Chrome + puppeteer-real-browser to bypass Cloudflare).
 *
 * Run via CLI: php sync-rumble.php
 * Or via API: GET /api/sync-rumble.php?key=yada2026sync
 */
require_once __DIR__ . '/config.php';

// Auth check for web requests
if (php_sapi_name() !== 'cli') {
    $secret = $_GET['key'] ?? '';
    if ($secret !== 'yada2026sync') {
        $user = requireAuth();
    }
}

$db = getDb();

// Get Rumble feed record
$feedStmt = $db->query("SELECT feed_key, feed_account_id FROM yy_feed WHERE lower(feed_site_code) = 'rumble' AND feed_active_flag = true LIMIT 1");
$feed = $feedStmt->fetch();
if (!$feed) {
    $msg = 'No active Rumble feed found';
    if (php_sapi_name() === 'cli') { echo "$msg\n"; exit(1); }
    errorResponse($msg);
}

$feedKey = (int)$feed['feed_key'];

// Start sync log
$db->prepare("INSERT INTO yy_feed_sync (feed_key, feed_sync_status) VALUES (?, 'running')")
   ->execute([$feedKey]);
$syncKey = $db->lastInsertId('yy_feed_sync_feed_sync_key_seq');

$totalFound = 0;
$totalInserted = 0;
$totalUpdated = 0;
$error = null;

try {
    $cacheFile = __DIR__ . '/rumble-cache.json';
    if (!file_exists($cacheFile)) {
        throw new Exception('Rumble cache file not found: ' . $cacheFile);
    }

    $cacheAge = time() - filemtime($cacheFile);
    if (php_sapi_name() === 'cli') {
        echo "Cache file age: " . round($cacheAge / 60) . " minutes\n";
    }

    $json = file_get_contents($cacheFile);
    $videos = json_decode($json, true);
    if (!is_array($videos)) {
        throw new Exception('Invalid JSON in cache file');
    }

    // Get vlog page_key for category creation
    $vlogPageKey = (int)($db->query("SELECT page_key FROM yy_page WHERE page_code = 'vlog' LIMIT 1")->fetchColumn() ?: 1);

    // Cache existing categories by slug
    $catCache = [];
    $catStmt = $db->prepare("SELECT category_key, category_slug FROM yy_feed_page_category WHERE page_key = ?");
    $catStmt->execute([$vlogPageKey]);
    foreach ($catStmt->fetchAll() as $c) {
        $catCache[$c['category_slug']] = (int)$c['category_key'];
    }

    foreach ($videos as $v) {
        $vidId = $v['video_id'] ?? '';
        $title = $v['title'] ?? '';
        $vidUrl = $v['url'] ?? '';
        $thumb = $v['thumbnail'] ?? '';
        $embedId = $v['embed_id'] ?? '';
        $publishDate = $v['date'] ?? null;
        $description = $v['description'] ?? '';

        if (!$vidId || !$title || !$vidUrl) continue;
        $totalFound++;

        // Parse #vlog|category|episode from description
        $categoryKey = null;
        $episode = null;
        $tags = '';
        if ($description && preg_match('/#vlog\|([^|]+)\|(\d+)/', $description, $m)) {
            $catTitle = trim($m[1]);
            $episode = (int)$m[2];
            $catSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $catTitle));
            $catSlug = trim($catSlug, '-');

            if (isset($catCache[$catSlug])) {
                $categoryKey = $catCache[$catSlug];
            } else {
                // Create new category
                $insCat = $db->prepare("INSERT INTO yy_feed_page_category (page_key, category_title, category_slug) VALUES (?, ?, ?) RETURNING category_key");
                $insCat->execute([$vlogPageKey, $catTitle, $catSlug]);
                $categoryKey = (int)$insCat->fetchColumn();
                $catCache[$catSlug] = $categoryKey;
                if (php_sapi_name() === 'cli') echo "Created category: {$catTitle} (slug: {$catSlug}, key: {$categoryKey})\n";
            }
        }

        // Extract all hashtags from description for feed_item_tags
        if ($description && preg_match_all('/#[a-zA-Z0-9_]+/', $description, $tagMatches)) {
            $tags = implode(', ', $tagMatches[0]);
        }

        $stmt = $db->prepare("
            INSERT INTO yy_feed_item (feed_key, feed_item_external_id, feed_item_title_import, feed_item_url, feed_item_thumbnail, feed_item_embed_id, feed_item_publish_import_dtime, feed_item_description, feed_item_tags, feed_item_episode)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?)
            ON CONFLICT (feed_key, feed_item_external_id) DO UPDATE SET
                feed_item_title_import = EXCLUDED.feed_item_title_import,
                feed_item_url = EXCLUDED.feed_item_url,
                feed_item_thumbnail = EXCLUDED.feed_item_thumbnail,
                feed_item_embed_id = EXCLUDED.feed_item_embed_id,
                feed_item_publish_import_dtime = COALESCE(EXCLUDED.feed_item_publish_import_dtime, yy_feed_item.feed_item_publish_import_dtime),
                feed_item_description = COALESCE(EXCLUDED.feed_item_description, yy_feed_item.feed_item_description),
                feed_item_tags = COALESCE(EXCLUDED.feed_item_tags, yy_feed_item.feed_item_tags),
                feed_item_episode = COALESCE(EXCLUDED.feed_item_episode, yy_feed_item.feed_item_episode),
                feed_item_revision_dtime = NOW()
            WHERE yy_feed_item.feed_item_title_import IS DISTINCT FROM EXCLUDED.feed_item_title_import
               OR yy_feed_item.feed_item_url IS DISTINCT FROM EXCLUDED.feed_item_url
               OR yy_feed_item.feed_item_thumbnail IS DISTINCT FROM EXCLUDED.feed_item_thumbnail
               OR yy_feed_item.feed_item_embed_id IS DISTINCT FROM EXCLUDED.feed_item_embed_id
               OR yy_feed_item.feed_item_publish_import_dtime IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_publish_import_dtime, yy_feed_item.feed_item_publish_import_dtime)
               OR yy_feed_item.feed_item_description IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_description, yy_feed_item.feed_item_description)
               OR yy_feed_item.feed_item_tags IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_tags, yy_feed_item.feed_item_tags)
               OR yy_feed_item.feed_item_episode IS DISTINCT FROM COALESCE(EXCLUDED.feed_item_episode, yy_feed_item.feed_item_episode)
            RETURNING (xmax = 0) as is_insert
        ");
        $stmt->execute([$feedKey, $vidId, $title, $vidUrl, $thumb, $embedId, $publishDate, $description, $tags, $episode]);
        $row = $stmt->fetch();
        if ($row) {
            if ($row['is_insert']) $totalInserted++;
            else $totalUpdated++;
        }

        // Write Vlog category to yy_feed_item_category (page-scoped to Vlog page).
        // Replace only Vlog-page rows so other pages' admin assignments are untouched.
        if ($categoryKey) {
            $itemKeyStmt = $db->prepare("SELECT feed_item_key FROM yy_feed_item WHERE feed_key = ? AND feed_item_external_id = ?");
            $itemKeyStmt->execute([$feedKey, $vidId]);
            $itemKey = (int)($itemKeyStmt->fetchColumn() ?: 0);
            if ($itemKey) {
                $db->prepare("DELETE FROM yy_feed_item_category WHERE feed_item_key = ? AND category_key IN (SELECT category_key FROM yy_feed_page_category WHERE page_key = ?)")
                   ->execute([$itemKey, $vlogPageKey]);
                $db->prepare("INSERT INTO yy_feed_item_category (feed_item_key, category_key, feed_item_category_episode) VALUES (?, ?, ?) ON CONFLICT (feed_item_key, category_key) DO UPDATE SET feed_item_category_episode = EXCLUDED.feed_item_category_episode")
                   ->execute([$itemKey, $categoryKey, $episode ? (string)$episode : null]);
            }
        }
    }
} catch (\Exception $e) {
    $error = $e->getMessage();
}

// Deactivate duplicate items (same feed + same title, keep earliest)
$deduped = 0;
$dedupStmt = $db->prepare("
    UPDATE yy_feed_item SET feed_item_active_flag = FALSE
    WHERE feed_item_key IN (
        SELECT fi.feed_item_key
        FROM yy_feed_item fi
        INNER JOIN (
            SELECT feed_key, feed_item_title_import, MIN(feed_item_key) as keep_key
            FROM yy_feed_item
            WHERE feed_key = ? AND feed_item_active_flag = TRUE
            GROUP BY feed_key, feed_item_title_import
            HAVING COUNT(*) > 1
        ) dups ON fi.feed_key = dups.feed_key AND fi.feed_item_title_import = dups.feed_item_title_import AND fi.feed_item_key != dups.keep_key
    )
");
$dedupStmt->execute([$feedKey]);
$deduped = $dedupStmt->rowCount();
if ($deduped > 0 && php_sapi_name() === 'cli') echo "Deactivated {$deduped} duplicate(s)\n";

// Update sync log
$status = $error ? 'error' : 'success';
$db->prepare("UPDATE yy_feed_sync SET feed_sync_status = ?, feed_sync_items_found = ?, feed_sync_items_inserted = ?, feed_sync_items_updated = ?, feed_sync_error = ?, feed_sync_end_dtime = NOW() WHERE feed_sync_key = ?")
   ->execute([$status, $totalFound, $totalInserted, $totalUpdated, $error, $syncKey]);

if ($error) {
    logMonitorEvent('sync_rumble', 'error', 'Rumble sync failed: ' . $error,
        "found=$totalFound inserted=$totalInserted updated=$totalUpdated\nfeed_sync_key=$syncKey");
}

// Update feed item → page associations after sync
require_once __DIR__ . '/feed-item-pages.php';
updateItemPagesForFeed($db, $feedKey);

$result = [
    'synced' => !$error,
    'found' => $totalFound,
    'inserted' => $totalInserted,
    'updated' => $totalUpdated,
    'error' => $error,
];

if (php_sapi_name() === 'cli') {
    echo "\nDone: found={$totalFound} inserted={$totalInserted} updated={$totalUpdated}\n";
    if ($error) echo "Error: {$error}\n";
} else {
    jsonResponse($result);
}
