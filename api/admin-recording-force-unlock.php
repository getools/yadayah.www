<?php
/**
 * Force-release every kind of lock that admin-recordings.php's in_progress
 * signal looks at, EXCEPT the recording-active heartbeat — that one is held
 * by an actively-open popout window and the operator has been warned not
 * to override it.
 *
 * Used when the Recordings tab UI offers "this video is locked for
 * processing — force unlock and process now". Effects per item_key:
 *
 *   1. Kill the finalize worker (PID in /tmp/finalize_<key>.state),
 *      remove the state file + the ffmpeg progress + duration files.
 *   2. Cancel any pending/running yy_feed_item_transcript_job rows for
 *      the item and kill their worker PIDs.
 *   3. Leave /tmp/recording_active_<key> alone — that lock is owned by a
 *      live popout, the UI handles that separately.
 *
 * This is destructive: the worker is genuinely interrupted mid-encode and
 * the transcript job mid-Whisper. Caller must confirm before invoking.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$itemKey = (int)($data['item_key'] ?? 0);
if (!$itemKey) errorResponse('item_key required');

$db = getDb();
$out = ['item_key' => $itemKey, 'killed' => [], 'removed' => [], 'cancelled_jobs' => 0];

$tmp = sys_get_temp_dir();

// 1. finalize worker
$stateFile = "$tmp/finalize_$itemKey.state";
if (is_file($stateFile)) {
    $st = json_decode((string)@file_get_contents($stateFile), true) ?: [];
    $pid = (int)($st['pid'] ?? 0);
    if ($pid > 0 && @posix_kill($pid, 0)) {  // process still alive
        @posix_kill($pid, 9);
        $out['killed'][] = "finalize_pid=$pid";
    }
    @unlink($stateFile);
    $out['removed'][] = basename($stateFile);
}
foreach (["finalize_$itemKey.progress", "finalize_$itemKey.duration"] as $f) {
    if (is_file("$tmp/$f")) { @unlink("$tmp/$f"); $out['removed'][] = $f; }
}

// 2. transcript jobs
$jobStmt = $db->prepare("
    SELECT feed_item_transcript_job_key, job_worker_pid
    FROM yy_feed_item_transcript_job
    WHERE feed_item_key = ?
      AND job_status IN ('pending', 'running')
");
$jobStmt->execute([$itemKey]);
$activeJobs = $jobStmt->fetchAll();
foreach ($activeJobs as $j) {
    $jpid = (int)($j['job_worker_pid'] ?? 0);
    if ($jpid > 0 && @posix_kill($jpid, 0)) {
        @posix_kill($jpid, 9);
        $out['killed'][] = "transcribe_pid=$jpid";
    }
}
if ($activeJobs) {
    $upd = $db->prepare("
        UPDATE yy_feed_item_transcript_job
        SET job_status = 'cancelled',
            job_message = COALESCE(job_message, '') || ' [force-cancelled by ' || ? || ']',
            job_completed_dtime = NOW()
        WHERE feed_item_key = ?
          AND job_status IN ('pending', 'running')
    ");
    $upd->execute([$user['user_code'] ?? 'unknown', $itemKey]);
    $out['cancelled_jobs'] = $upd->rowCount();
}

logMonitorEvent('transcript_upload', 'warning',
    "Force-unlock invoked for item $itemKey by " . ($user['user_code'] ?? '?')
    . ': killed=' . implode(',', $out['killed'])
    . '; cancelled_jobs=' . $out['cancelled_jobs'],
    null, true);

jsonResponse($out);
