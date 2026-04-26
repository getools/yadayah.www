<?php
/**
 * CLI cron script to sync all active feeds.
 * Iterates yy_feed_schedule for active entries, calls each feed's sync endpoint,
 * updates schedule_last_run/count/status.
 *
 * Usage: php cron-sync-feeds.php
 * Designed to run via crontab 3x/day (e.g., 6am, 2pm, 10pm).
 *
 * Note: Rumble scraping is blocked by Cloudflare on the server, so Rumble sync
 * only clears the cache; actual scraping must be done locally and rumble-all-videos.json
 * deployed via scp.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/config.php';

$db = getDb();
$startedAt = date('Y-m-d H:i:s');
echo "[{$startedAt}] Feed sync starting...\n";

// Map each site to its dedicated sync script. Each script handles all feeds
// for its site in one invocation, so we only run each script once per cron run.
$siteScripts = [
    'youtube'  => __DIR__ . '/sync-youtube.php',
    'facebook' => __DIR__ . '/sync-facebook.php',
    'rumble'   => __DIR__ . '/sync-rumble.php',
];

// Collect distinct active sites
$sitesStmt = $db->query("
    SELECT DISTINCT lower(f.feed_site_code) AS site
    FROM yy_feed_schedule s
    JOIN yy_feed f USING (feed_key)
    WHERE s.schedule_active_flag = TRUE AND f.feed_active_flag = TRUE
");
$sites = array_column($sitesStmt->fetchAll(), 'site');

$totalMatched = 0;
foreach ($sites as $site) {
    echo "  - {$site}... ";
    $script = $siteScripts[$site] ?? null;
    if (!$script || !file_exists($script)) {
        echo "no sync script\n";
        continue;
    }

    $logFile = sys_get_temp_dir() . '/cron_sync_' . $site . '.log';
    $cmd = "php " . escapeshellarg($script) . " > " . escapeshellarg($logFile) . " 2>&1";
    $rc = 0;
    exec($cmd, $out, $rc);
    $log = @file_get_contents($logFile) ?: '';

    // Parse inserted/updated counts from log output
    $inserted = 0; $updated = 0;
    if (preg_match_all('/inserted[=:\s]+(\d+)/i', $log, $m)) $inserted = array_sum(array_map('intval', $m[1]));
    if (preg_match_all('/updated[=:\s]+(\d+)/i',  $log, $m)) $updated  = array_sum(array_map('intval', $m[1]));
    $matched = $inserted + $updated;
    $totalMatched += $matched;

    echo "rc={$rc} inserted={$inserted} updated={$updated}\n";

    // Update every schedule row for this site
    $rows = $db->prepare("
        SELECT s.schedule_key FROM yy_feed_schedule s
        JOIN yy_feed f USING (feed_key)
        WHERE lower(f.feed_site_code) = ? AND s.schedule_active_flag = TRUE AND f.feed_active_flag = TRUE
    ");
    $rows->execute([$site]);
    $schedKeys = array_column($rows->fetchAll(), 'schedule_key');

    $status = ['site' => $site, 'rc' => $rc, 'inserted' => $inserted, 'updated' => $updated, 'log_tail' => substr($log, -1500)];
    $statusJson = json_encode($status);
    if (strlen($statusJson) > 4000) $statusJson = substr($statusJson, 0, 4000);

    foreach ($schedKeys as $sk) {
        try {
            $db->prepare("UPDATE yy_feed_schedule SET schedule_last_run = NOW(), schedule_last_count = ?, schedule_last_status = ? WHERE schedule_key = ?")
               ->execute([$matched, $statusJson, $sk]);
        } catch (Throwable $e) {
            echo "    (log update failed: " . $e->getMessage() . ")\n";
        }
    }
}

$endedAt = date('Y-m-d H:i:s');
echo "[{$endedAt}] Done. Total matched: {$totalMatched}\n";
