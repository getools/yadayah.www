<?php
// One-shot deferred re-queue of STT baseline runs that failed on infra
// (Puget unreachable 09-24/25 outage, CUDA OOM, HTTP 502/503). Snapshot of
// (item, model) pairs lives in _stt_redo_20261012 (built 2026-10-12).
//
// Held until the TTS build queue is empty (GPU contention → OOM), then every
// still-missing pair is inserted as a normal pending job (priority 0, so
// single-item runs still jump ahead) and the single STT worker is started.
// Driven by /root/gpu-queue-tick.sh from root cron (runs this until it
// prints RELEASED, then only the tick).
//
//   php _stt_redo_release.php            # release if TTS idle, else WAITING
//   php _stt_redo_release.php --status   # counts only
//   php _stt_redo_release.php --force    # release now regardless of TTS
//
// Safe to re-run: pairs already released (new_job_key set) are skipped.
// Delete this file + the table after the jobs drain.

require_once __DIR__ . '/config.php';

$db = getDb();
$arg = $argv[1] ?? '';
$ts = date('Y-m-d H:i:s');

$tts = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio WHERE tts_audio_status IN ('pending','running')")->fetchColumn();
$left = (int)$db->query("SELECT COUNT(*) FROM _stt_redo_20261012 WHERE released_dtime IS NULL")->fetchColumn();

if ($arg === '--status') {
    $r = $db->query("SELECT COUNT(*) FILTER (WHERE new_job_key IS NOT NULL) released,
                            COUNT(*) FILTER (WHERE skip_reason IS NOT NULL) skipped,
                            COUNT(*) FILTER (WHERE released_dtime IS NULL) held
                       FROM _stt_redo_20261012")->fetch(PDO::FETCH_ASSOC);
    $st = $db->query("SELECT j.job_status, COUNT(*) FROM _stt_redo_20261012 r
                        JOIN yy_feed_item_transcript_job j ON j.feed_item_transcript_job_key = r.new_job_key
                       GROUP BY 1 ORDER BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "$ts tts_active=$tts held={$r['held']} released={$r['released']} skipped={$r['skipped']} jobs=" . json_encode($st) . "\n";
    exit(0);
}

if (!$left) { echo "$ts RELEASED (nothing left to release)\n"; exit(0); }
if ($tts > 0 && $arg !== '--force') { echo "$ts WAITING tts_active=$tts held=$left\n"; exit(0); }

$pairs = $db->query("SELECT feed_item_key, job_model FROM _stt_redo_20261012
                      WHERE released_dtime IS NULL
                      ORDER BY feed_item_key DESC, job_model")->fetchAll(PDO::FETCH_ASSOC);

$hasAuto = $db->prepare("SELECT 1 FROM yy_feed_item_transcript_auto
                          WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ? LIMIT 1");
$inFlight = $db->prepare("SELECT 1 FROM yy_feed_item_transcript_job
                           WHERE feed_item_key = ? AND job_model = ? AND job_status IN ('pending','running') LIMIT 1");
$ins = $db->prepare("INSERT INTO yy_feed_item_transcript_job
                         (feed_item_key, job_status, job_message, user_key, job_model, job_priority)
                     VALUES (?, 'pending', ?, NULL, ?, 0)
                     RETURNING feed_item_transcript_job_key");
$mark = $db->prepare("UPDATE _stt_redo_20261012 SET new_job_key = ?, skip_reason = ?, released_dtime = NOW()
                       WHERE feed_item_key = ? AND job_model = ?");

$queued = 0; $skipped = 0; $firstJob = 0;
$db->beginTransaction();
foreach ($pairs as $p) {
    $k = (int)$p['feed_item_key']; $m = $p['job_model'];
    $hasAuto->execute([$k, $m]);
    if ($hasAuto->fetchColumn()) { $mark->execute([null, 'already has rows', $k, $m]); $skipped++; continue; }
    $inFlight->execute([$k, $m]);
    if ($inFlight->fetchColumn()) { $mark->execute([null, 'already queued', $k, $m]); $skipped++; continue; }
    $ins->execute([$k, 'Queued for model: ' . $m . ' (redo of infra failure, released after TTS)', $m]);
    $jk = (int)$ins->fetchColumn();
    if (!$firstJob) $firstJob = $jk;
    $mark->execute([$jk, null, $k, $m]);
    $queued++;
}
$db->commit();

// The worker is started by gpu-queue-tick.php (run right after this by
// /root/gpu-queue-tick.sh), which respects the Puget GPU gate.
echo "$ts RELEASED queued=$queued skipped=$skipped tts_active=$tts\n";
