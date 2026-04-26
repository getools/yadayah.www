<?php
/**
 * Sync Rumble videos into yy_feed_item.
 * Scrapes the Rumble channel page and upserts video records.
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
$account = $feed['feed_account_id'] ?: 'YadaYahowah7';
$channelUrl = "https://rumble.com/c/{$account}";

// Start sync log
$db->prepare("INSERT INTO yy_feed_sync (feed_key, feed_sync_status) VALUES (?, 'running')")
   ->execute([$feedKey]);
$syncKey = $db->lastInsertId('yy_feed_sync_feed_sync_key_seq');

$totalFound = 0;
$totalInserted = 0;
$totalUpdated = 0;
$error = null;

try {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    for ($page = 1; $page <= 25; $page++) {
        $url = "{$channelUrl}?page={$page}";
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => $ua]]);
        $html = @file_get_contents($url, false, $ctx);

        if (!$html || strlen($html) < 10000) {
            // Cloudflare block or empty page
            if ($page === 1) $error = 'Cloudflare blocked scrape (HTML too small: ' . strlen($html ?? '') . ' bytes)';
            break;
        }

        // Parse video entries
        $pattern = '/data-video-id="(\d+)".*?<img[^>]+src="([^"]+)"[^>]+alt="([^"]*)".*?href="(\/v[^"]+\.html)[^"]*".*?<h3[^>]+class="thumbnail__title[^"]*"[^>]*>\s*(.*?)\s*<\/h3>/s';
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        if (empty($matches)) break;

        // Build a map of video-id to date from <time> tags
        $datemap = [];
        if (preg_match_all('/data-video-id="(\d+)"(.*?)(?=data-video-id="|$)/s', $html, $dblocks, PREG_SET_ORDER)) {
            foreach ($dblocks as $db2) {
                if (preg_match('/<time[^>]*datetime="([^"]+)"[^>]*>([^<]+)<\/time>/', $db2[2], $tm)) {
                    $datemap[$db2[1]] = ['iso' => $tm[1], 'ago' => trim($tm[2])];
                }
            }
        }

        $pageCount = 0;
        foreach ($matches as $m) {
            $vidId = $m[1];
            $thumb = $m[2];
            $title = html_entity_decode(strip_tags(trim($m[5])), ENT_QUOTES, 'UTF-8');
            $href = $m[4];
            $vidUrl = "https://rumble.com{$href}";

            // Extract embed ID from URL
            $embedId = '';
            if (preg_match('/\/v([a-z0-9]+)-/', $href, $em)) {
                $embedId = $em[1];
            }

            // Extract publish date
            $publishDate = null;
            if (isset($datemap[$vidId])) {
                $iso = $datemap[$vidId]['iso'];
                $ago = $datemap[$vidId]['ago'];
                if ($iso) {
                    $publishDate = $iso;
                } elseif ($ago) {
                    $publishDate = dateAgoToIso($ago);
                }
            }

            $totalFound++;

            // Upsert
            $stmt = $db->prepare("
                INSERT INTO yy_feed_item (feed_key, feed_item_external_id, feed_item_title, feed_item_url, feed_item_thumbnail, feed_item_embed_id, feed_item_publish_dtime)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (feed_key, feed_item_external_id) DO UPDATE SET
                    feed_item_title = EXCLUDED.feed_item_title,
                    feed_item_url = EXCLUDED.feed_item_url,
                    feed_item_thumbnail = EXCLUDED.feed_item_thumbnail,
                    feed_item_embed_id = EXCLUDED.feed_item_embed_id,
                    feed_item_publish_dtime = COALESCE(EXCLUDED.feed_item_publish_dtime, yy_feed_item.feed_item_publish_dtime),
                    feed_item_revision_dtime = NOW()
                RETURNING (xmax = 0) as is_insert
            ");
            $stmt->execute([$feedKey, $vidId, $title, $vidUrl, $thumb, $embedId, $publishDate]);
            $row = $stmt->fetch();
            if ($row['is_insert']) $totalInserted++;
            else $totalUpdated++;

            $pageCount++;
        }

        if (php_sapi_name() === 'cli') echo "Page {$page}: {$pageCount} videos\n";
        if ($pageCount === 0) break;
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
            SELECT feed_key, feed_item_title, MIN(feed_item_key) as keep_key
            FROM yy_feed_item
            WHERE feed_key = ? AND feed_item_active_flag = TRUE
            GROUP BY feed_key, feed_item_title
            HAVING COUNT(*) > 1
        ) dups ON fi.feed_key = dups.feed_key AND fi.feed_item_title = dups.feed_item_title AND fi.feed_item_key != dups.keep_key
    )
");
$dedupStmt->execute([$feedKey]);
$deduped = $dedupStmt->rowCount();
if ($deduped > 0 && php_sapi_name() === 'cli') echo "Deactivated {$deduped} duplicate(s)\n";

// Update sync log
$status = $error ? 'error' : 'success';
$db->prepare("UPDATE yy_feed_sync SET feed_sync_status = ?, feed_sync_items_found = ?, feed_sync_items_inserted = ?, feed_sync_items_updated = ?, feed_sync_error = ?, feed_sync_end_dtime = NOW() WHERE feed_sync_key = ?")
   ->execute([$status, $totalFound, $totalInserted, $totalUpdated, $error, $syncKey]);

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

function dateAgoToIso(string $ago): ?string {
    $ago = strtolower(trim($ago));
    if (preg_match('/(\d+)\s*(second|minute|hour|day|week|month|year)/', $ago, $m)) {
        $n = (int)$m[1];
        $unit = $m[2] . ($n === 1 ? '' : 's');
        $dt = new DateTime('now', new DateTimeZone('America/New_York'));
        $dt->modify("-{$n} {$unit}");
        return $dt->format('c');
    }
    return null;
}
