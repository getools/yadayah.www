<?php
/**
 * One-shot: queue Deepgram Nova-3 transcription jobs for the 50 most recent
 * feed items that have an MP3 but no deepgram-nova-3 rows in
 * yy_feed_item_transcript_auto. Each job is inserted into
 * yy_feed_item_transcript_job and a worker is spawned to process it.
 *
 * Run on prod: php /opt/yada-www/api/queue-deepgram-50.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';

$db = getDb();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Stream output so we can watch progress instead of waiting for the script
// to terminate — useful when run over `docker exec`.
ob_implicit_flush(true);
while (ob_get_level() > 0) ob_end_flush();

$sql = "
    SELECT fi.feed_item_key,
           COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS title
      FROM yy_feed_item fi
     WHERE fi.feed_item_audio_file IS NOT NULL
       AND fi.feed_item_audio_file <> ''
       AND fi.feed_item_active_flag = true
       AND NOT EXISTS (
           SELECT 1 FROM yy_feed_item_transcript_auto a
            WHERE a.feed_item_key = fi.feed_item_key
              AND a.feed_item_transcript_auto_model = 'deepgram-nova-3'
       )
     ORDER BY COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) DESC NULLS LAST
     LIMIT 50
";

$items = $db->query($sql)->fetchAll();
echo "Found " . count($items) . " candidate items.\n";

$workerScript = __DIR__ . '/transcript-worker.php';
$queued = 0;
foreach ($items as $i => $r) {
    $itemKey = (int)$r['feed_item_key'];
    $title   = (string)$r['title'];

    // Skip if any pending/running deepgram job for this item already exists
    // (defensive — script can be safely re-run).
    $check = $db->prepare("
        SELECT 1 FROM yy_feed_item_transcript_job
         WHERE feed_item_key = ?
           AND job_model = 'deepgram-nova-3'
           AND job_status IN ('pending', 'running')
         LIMIT 1
    ");
    $check->execute([$itemKey]);
    if ($check->fetchColumn()) {
        echo sprintf("[%2d] skip %d (already pending/running): %s\n", $i + 1, $itemKey, $title);
        continue;
    }

    // user_key is nullable and FK'd to yy_user; NULL = "system / batch job".
    $ins = $db->prepare("
        INSERT INTO yy_feed_item_transcript_job
            (feed_item_key, job_status, job_message, user_key, job_model)
        VALUES (?, 'pending', ?, NULL, 'deepgram-nova-3')
        RETURNING feed_item_transcript_job_key
    ");
    $ins->execute([$itemKey, 'Batch-queued deepgram-nova-3']);
    $jobKey = (int)$ins->fetchColumn();

    $logFile = sys_get_temp_dir() . '/transcript_' . $jobKey . '.log';
    $pid = spawnCappedWorker($workerScript, [(string)$jobKey], $logFile, [
        'cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10,
    ]);
    if ($pid > 0) {
        $db->prepare("UPDATE yy_feed_item_transcript_job SET job_worker_pid = ? WHERE feed_item_transcript_job_key = ?")
           ->execute([$pid, $jobKey]);
    }
    echo sprintf("[%2d] item=%d job=%d pid=%d  %s\n", $i + 1, $itemKey, $jobKey, $pid, substr($title, 0, 80));
    $queued++;
    // Light stagger so we don't fork 50 processes in the same instant.
    usleep(1000 * 1000); // 1.0s
}

echo "Queued $queued jobs.\n";
