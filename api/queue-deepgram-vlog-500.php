<?php
/**
 * One-shot: queue Deepgram Nova-3 transcription jobs for up to 500 of the
 * most recent Vlog-page feed items that have an MP3 but no deepgram-nova-3
 * rows in yy_feed_item_transcript_auto. Mirrors the same include/exclude
 * filter logic that admin-vlog.php uses for the Vlog page (#vlog* tags,
 * minus #Basics / #Invite / The Basics ~ / #shorts / #vlog-on-the-blog).
 *
 * Run: docker exec yada-www-web-1 php /var/www/html/api/queue-deepgram-vlog-500.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';
require_once __DIR__ . '/feed-helpers.php';

$db = getDb();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

ob_implicit_flush(true);
while (ob_get_level() > 0) ob_end_flush();

// Load the vlog page's filter config (single feed for now).
$cfg = $db->query("
    SELECT f.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude, fp.feed_page_filter_orientation
      FROM yy_feed_page fp
      JOIN yy_feed f ON f.feed_key = fp.feed_key
      JOIN yy_page p ON p.page_key = fp.page_key
     WHERE p.page_code = 'vlog' AND fp.feed_page_active_flag = TRUE
     ORDER BY fp.feed_page_sort
     LIMIT 1
")->fetch();
if (!$cfg) { fwrite(STDERR, "No vlog feed config found.\n"); exit(1); }

$where  = "feed_key = ? AND feed_item_active_flag = TRUE";
$params = [(int)$cfg['feed_key']];
buildFeedPageFilters(
    $where, $params,
    $cfg['feed_page_filter_include'],
    $cfg['feed_page_filter_exclude'],
    $cfg['feed_page_filter_orientation']
);

// Tack on the MP3-exists + no-deepgram constraints. Column refs match the
// feed_item_* names used by buildFeedPageFilters (no fi. prefix needed
// because we're querying yy_feed_item directly without joins).
$where .= " AND feed_item_audio_file IS NOT NULL"
       .  " AND feed_item_audio_file <> ''"
       .  " AND NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript_auto a"
       .  "                  WHERE a.feed_item_key = yy_feed_item.feed_item_key"
       .  "                    AND a.feed_item_transcript_auto_model = 'deepgram-nova-3')";

$sql = "SELECT feed_item_key,
               COALESCE(feed_item_title_override, feed_item_title_import) AS title,
               feed_item_tags
          FROM yy_feed_item
         WHERE $where
         ORDER BY COALESCE(feed_item_publish_override_dtime, feed_item_publish_import_dtime) DESC NULLS LAST
         LIMIT 500";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();
echo "Found " . count($items) . " vlog candidate items.\n";

$workerScript = __DIR__ . '/transcript-worker.php';
$queued = 0; $skipped = 0;
foreach ($items as $i => $r) {
    $itemKey = (int)$r['feed_item_key'];
    $title   = (string)$r['title'];

    $check = $db->prepare("
        SELECT 1 FROM yy_feed_item_transcript_job
         WHERE feed_item_key = ?
           AND job_model = 'deepgram-nova-3'
           AND job_status IN ('pending', 'running')
         LIMIT 1
    ");
    $check->execute([$itemKey]);
    if ($check->fetchColumn()) {
        echo sprintf("[%3d] skip %d (already pending/running): %s\n", $i + 1, $itemKey, $title);
        $skipped++;
        continue;
    }

    $ins = $db->prepare("
        INSERT INTO yy_feed_item_transcript_job
            (feed_item_key, job_status, job_message, user_key, job_model)
        VALUES (?, 'pending', ?, NULL, 'deepgram-nova-3')
        RETURNING feed_item_transcript_job_key
    ");
    $ins->execute([$itemKey, 'Batch-queued deepgram-nova-3 (vlog)']);
    $jobKey = (int)$ins->fetchColumn();

    $logFile = sys_get_temp_dir() . '/transcript_' . $jobKey . '.log';
    $pid = spawnCappedWorker($workerScript, [(string)$jobKey], $logFile, [
        'cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10,
    ]);
    if ($pid > 0) {
        $db->prepare("UPDATE yy_feed_item_transcript_job SET job_worker_pid = ? WHERE feed_item_transcript_job_key = ?")
           ->execute([$pid, $jobKey]);
    }
    echo sprintf("[%3d] item=%d job=%d pid=%d  %s\n", $i + 1, $itemKey, $jobKey, $pid, substr($title, 0, 80));
    $queued++;
    // 2s stagger — keeps the steady-state concurrent worker count modest
    // (most jobs finish in ~30-60s waiting on Deepgram's async API).
    usleep(2000 * 1000);
}

echo "Queued $queued jobs (skipped $skipped already-queued).\n";
