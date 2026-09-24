<?php
/**
 * _timing_enqueue.php — CLI enqueue of baseline STT jobs for the
 * "segment size + timestamp accuracy" pass over untouched mp3 items.
 *
 *   php _timing_enqueue.php --list=/root/x.txt --models=a,b [--apply]
 *                           [--priority=0] [--force] [--kick]
 *
 * Skips (item,model) pairs that already have _auto rows unless --force.
 * Never supersedes anything that is already running. --kick claims the
 * single worker slot (same cap=1 advisory-lock gate the web dispatch uses).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';

const TW_LOCK = 742001;

$items = []; $models = []; $apply = false; $prio = 0; $force = false; $kick = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') $apply = true;
    elseif ($a === '--force') $force = true;
    elseif ($a === '--kick') $kick = true;
    elseif (strncmp($a, '--list=', 7) === 0) { foreach (preg_split('/\s+/', (string)@file_get_contents(substr($a,7))) as $t) if ($t !== '') $items[] = (int)$t; }
    elseif (strncmp($a, '--models=', 9) === 0) { foreach (explode(',', substr($a,9)) as $m) { $m = trim($m); if ($m !== '') $models[] = $m; } }
    elseif (strncmp($a, '--priority=', 11) === 0) $prio = (int)substr($a, 11);
    elseif ($a[0] !== '-') $items[] = (int)$a;
}
$items = array_values(array_unique(array_filter($items)));
if (!$items || !$models) { fwrite(STDERR, "usage: --list=F|<keys> --models=a,b [--apply] [--priority=N] [--force] [--kick]\n"); exit(1); }

$db = getDb();
$has = $db->prepare("SELECT 1 FROM yy_feed_item_transcript_auto WHERE feed_item_key=? AND feed_item_transcript_auto_model=? LIMIT 1");
$inflight = $db->prepare("SELECT 1 FROM yy_feed_item_transcript_job WHERE feed_item_key=? AND job_model=? AND job_status IN ('pending','running') LIMIT 1");
$ins = $db->prepare("INSERT INTO yy_feed_item_transcript_job (feed_item_key, job_status, job_message, user_key, job_model, job_priority)
                     VALUES (?, 'pending', ?, NULL, ?, ?) RETURNING feed_item_transcript_job_key");

$queued = 0; $skipHas = 0; $skipFlight = 0; $first = 0;
foreach ($items as $ik) {
    foreach ($models as $m) {
        if (!$force) { $has->execute([$ik,$m]); if ($has->fetchColumn()) { $skipHas++; continue; } }
        $inflight->execute([$ik,$m]); if ($inflight->fetchColumn()) { $skipFlight++; continue; }
        if (!$apply) { $queued++; continue; }
        $ins->execute([$ik, 'Queued for model: '.$m, $m, $prio]);
        $k = (int)$ins->fetchColumn(); if (!$first) $first = $k; $queued++;
    }
}
printf("%s: queued=%d  skipped(has rows)=%d  skipped(in flight)=%d\n", $apply ? 'APPLIED' : 'DRY-RUN', $queued, $skipHas, $skipFlight);

if ($apply && $kick && $first) {
    $db->query('SELECT pg_advisory_lock('.TW_LOCK.')');
    try {
        $running = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status='running'")->fetchColumn();
        if ($running >= 1) { echo "worker already running ($running) — jobs stay pending\n"; }
        else {
            $pid = spawnCappedWorker(__DIR__.'/transcript-worker.php', [(string)$first], sys_get_temp_dir().'/transcript_'.$first.'.log',
                                     ['cpu_secs'=>2400, 'mem_mb'=>2000, 'nice'=>10]);
            if ($pid > 0) {
                $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status='running', job_worker_pid=?, job_running_since=NOW()
                               WHERE feed_item_transcript_job_key=? AND job_status='pending'")->execute([$pid,$first]);
                echo "spawned worker pid=$pid on job $first\n";
            } else echo "spawn FAILED\n";
        }
    } finally { $db->query('SELECT pg_advisory_unlock('.TW_LOCK.')'); }
}
