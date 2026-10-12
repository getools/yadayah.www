<?php
/**
 * Puget GPU gate tick (2026-10-12) — root cron via /root/gpu-queue-tick.sh.
 *
 * The STT dequeue (transcript-worker.php shutdown + claimWorkerSlot) leaves
 * the next job pending while _gpu_gate.php class 'stt' is closed (an image/
 * video/audio job or an LLM transcript pass holds the card). Nothing else
 * would restart the queue afterwards, so this tick starts the single worker
 * when there are pending jobs, none running, and the gate is open — same
 * lock + claim as claimWorkerSlot() in admin-transcript-models.php.
 *
 *   php gpu-queue-tick.php            # start the STT worker if allowed
 *   php gpu-queue-tick.php --status   # print each gate class
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';
require_once __DIR__ . '/_gpu_gate.php';

$db = getDb();
$ts = date('Y-m-d H:i:s');

if (in_array('--status', $argv, true)) {
    $s = gpuSttCounts($db);
    echo "$ts tts=" . gpuTtsActive($db) . " gen=" . gpuGenActive($db) . " stt_running={$s['running']} stt_pending={$s['pending']} llm=" . (gpuLlmActive($db) ? 'yes' : 'no') . "\n";
    foreach (['stt', 'gen', 'llm', 'llm_bg'] as $c) echo "  $c: " . (gpuGateReason($db, $c) ?? 'clear') . "\n";
    exit(0);
}

// Clear 'running' jobs whose worker died (same rule as reapOrphanedJobs() in
// admin-transcript-models.php) so one orphan can't hold the queue forever.
foreach ($db->query("SELECT feed_item_transcript_job_key k, job_worker_pid pid FROM yy_feed_item_transcript_job
                      WHERE job_status = 'running' AND COALESCE(job_running_since, job_dtime) < NOW() - INTERVAL '90 seconds'")->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $pid = (int)$o['pid'];
    if ($pid > 0 && (@file_exists("/proc/$pid") || (function_exists('posix_kill') && @posix_kill($pid, 0)))) continue;
    $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'failed', job_completed_dtime = NOW(),
                         job_error = 'Orphaned: worker PID ' || COALESCE(job_worker_pid::text, '(none)') || ' no longer alive — auto-reaped by gpu-queue-tick',
                         job_message = 'Failed: orphaned worker (auto-reaped)'
                   WHERE feed_item_transcript_job_key = ? AND job_status = 'running'")->execute([$o['k']]);
}

$s = gpuSttCounts($db);
if (!$s['pending'] || $s['running']) exit(0);   // nothing to start, or the worker is already going
$why = gpuGateReason($db, 'stt');
if ($why !== null) {
    $db->prepare("UPDATE yy_feed_item_transcript_job SET job_message = ?
                   WHERE job_status = 'pending' AND COALESCE(job_message, '') NOT LIKE 'Waiting for GPU%'
                     AND feed_item_transcript_job_key = (SELECT feed_item_transcript_job_key FROM yy_feed_item_transcript_job
                                                          WHERE job_status = 'pending' ORDER BY job_priority DESC, feed_item_transcript_job_key LIMIT 1)")
       ->execute(['Waiting for GPU: ' . $why]);
    echo "$ts STT held: $why ({$s['pending']} pending)\n";
    exit(0);
}

$started = 'no';
$db->query('SELECT pg_advisory_lock(742001)');
try {
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status = 'running'")->fetchColumn();
    if ($running === 0) {
        $next = (int)$db->query("SELECT feed_item_transcript_job_key FROM yy_feed_item_transcript_job
                                  WHERE job_status = 'pending'
                                  ORDER BY job_priority DESC, feed_item_transcript_job_key LIMIT 1")->fetchColumn();
        if ($next) {
            $pid = spawnCappedWorker(__DIR__ . '/transcript-worker.php', [(string)$next],
                sys_get_temp_dir() . '/transcript_' . $next . '.log',
                ['cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10]);
            if ($pid > 0) {
                $db->prepare("UPDATE yy_feed_item_transcript_job
                                 SET job_status = 'running', job_worker_pid = ?, job_running_since = NOW()
                               WHERE feed_item_transcript_job_key = ? AND job_status = 'pending'")
                   ->execute([$pid, $next]);
                $started = "job $next pid $pid";
            }
        }
    }
} finally {
    $db->query('SELECT pg_advisory_unlock(742001)');
}
echo "$ts STT worker started: $started ({$s['pending']} pending)\n";
