<?php
/**
 * CLI helper to finalize a recording bypassing the Apache/Caddy HTTP timeout.
 *
 * Mirrors the `action=finalize` block in admin-transcript-upload.php, but runs
 * from the shell so long encodes (multi-hour videos) aren't killed by upstream
 * timeouts.
 *
 * Usage:
 *   docker exec -u www-data yada-www-web-1 \
 *     php /var/www/html/api/cli-finalize.php <item_key> [--auto-transcribe] [--user-key=N]
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$itemKey = (int)($argv[1] ?? 0);
if (!$itemKey) {
    fwrite(STDERR, "usage: cli-finalize.php <item_key> [--auto-transcribe] [--user-key=N]\n");
    exit(2);
}
$autoTranscribe = in_array('--auto-transcribe', $argv, true);
$userKey = 2;
foreach ($argv as $a) {
    if (preg_match('/^--user-key=(\d+)$/', $a, $m)) $userKey = (int)$m[1];
}

require_once __DIR__ . '/config.php';
$db = getDb();

$AUDIO_DIR_ABS = dirname(__DIR__) . '/u/audio';
$AUDIO_DIR_REL = 'u/audio';
$PARTS_DIR_ABS = $AUDIO_DIR_ABS . '/parts';

$parts = glob("$PARTS_DIR_ABS/audio_{$itemKey}_part_*.webm");
if (!$parts) {
    fwrite(STDERR, "no parts for item $itemKey\n");
    exit(1);
}
usort($parts, function ($a, $b) {
    preg_match('/_part_(\d+)\.webm$/', $a, $am);
    preg_match('/_part_(\d+)\.webm$/', $b, $bm);
    return ((int)($am[1] ?? 0)) - ((int)($bm[1] ?? 0));
});
echo "[" . date('c') . "] found " . count($parts) . " parts for item $itemKey\n";

// Validate each part — same fail-fast policy as the HTTP handler.
$valid = [];
$skipped = [];
foreach ($parts as $f) {
    $cmd = 'ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($f) . ' 2>&1';
    $out = trim(shell_exec($cmd) ?: '');
    if (is_numeric($out) && (float)$out > 0.5) {
        $valid[] = $f;
    } else {
        $skipped[] = basename($f) . " (probe: $out)";
        @unlink($f);
    }
}
if (!$valid) {
    fwrite(STDERR, "all parts invalid: " . implode('; ', $skipped) . "\n");
    exit(1);
}
if ($skipped) echo "skipped: " . implode(', ', $skipped) . "\n";

$stamp = time();
$finalRel = "$AUDIO_DIR_REL/audio_{$itemKey}_{$stamp}.mp3";
$finalAbs = "$AUDIO_DIR_ABS/audio_{$itemKey}_{$stamp}.mp3";

$inputArgs = '';
$filterIn = '';
foreach (array_values($valid) as $i => $f) {
    $inputArgs .= ' -i ' . escapeshellarg($f);
    $filterIn .= "[{$i}:a]";
}
$filter = $filterIn . 'concat=n=' . count($valid) . ':v=0:a=1[out]';
$cmd = 'ffmpeg -y' . $inputArgs
     . ' -filter_complex ' . escapeshellarg($filter)
     . ' -map ' . escapeshellarg('[out]')
     . ' -codec:a libmp3lame -qscale:a 4 -ar 44100 -ac 2 '
     . escapeshellarg($finalAbs) . ' 2>&1';

echo "[" . date('c') . "] encoding " . count($valid) . " parts -> $finalRel\n";
$start = time();
$out = shell_exec($cmd);
$dur = time() - $start;
if (!file_exists($finalAbs) || filesize($finalAbs) < 1000) {
    fwrite(STDERR, "ffmpeg failed (ran {$dur}s):\n" . substr(trim($out ?? ''), -2000) . "\n");
    exit(1);
}
@chmod($finalAbs, 0664);

$probedDur = (float)trim(shell_exec('ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($finalAbs)) ?: '0');
echo "[" . date('c') . "] encode ok in {$dur}s — " . round(filesize($finalAbs) / 1024 / 1024, 2)
     . " MB, " . round($probedDur, 1) . "s (" . gmdate('H:i:s', (int)$probedDur) . ")\n";

// Remove any prior MP3 the DB pointed to.
$prevStmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
$prevStmt->execute([$itemKey]);
$prevFile = $prevStmt->fetchColumn();
if ($prevFile) {
    $prevAbs = dirname(__DIR__) . '/' . ltrim($prevFile, '/');
    if (is_file($prevAbs)) {
        @unlink($prevAbs);
        echo "removed prior MP3: $prevFile\n";
    }
}

$db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ?, feed_item_audio_resume_seconds = NULL WHERE feed_item_key = ?")
   ->execute([$finalRel, $itemKey]);
echo "DB updated: feed_item_audio_file = $finalRel\n";

$dropped = 0;
foreach ($valid as $f) {
    if (@unlink($f)) $dropped++;
}
echo "deleted $dropped part files\n";

if ($autoTranscribe) {
    $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'cancelled', job_completed_dtime = NOW() WHERE feed_item_key = ? AND job_status IN ('pending', 'running')")
       ->execute([$itemKey]);
    $delStmt = $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?");
    $delStmt->execute([$itemKey]);
    echo "cleared " . $delStmt->rowCount() . " stale transcript rows\n";

    $jobStmt = $db->prepare("INSERT INTO yy_feed_item_transcript_job (feed_item_key, job_status, job_message, user_key) VALUES (?, 'pending', 'CLI finalize triggered transcribe', ?) RETURNING feed_item_transcript_job_key");
    $jobStmt->execute([$itemKey, $userKey]);
    $jobKey = (int)$jobStmt->fetchColumn();
    echo "spawned transcript job #$jobKey\n";

    $worker = __DIR__ . '/transcript-worker.php';
    if (file_exists($worker)) {
        $logFile = sys_get_temp_dir() . "/transcript_$jobKey.log";
        $launch = "nohup php " . escapeshellarg($worker) . " " . escapeshellarg((string)$jobKey)
                . " > " . escapeshellarg($logFile) . " 2>&1 < /dev/null & echo $!";
        $pidOut = [];
        exec($launch, $pidOut);
        $pid = (int)($pidOut[0] ?? 0);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_feed_item_transcript_job SET job_worker_pid = ? WHERE feed_item_transcript_job_key = ?")
               ->execute([$pid, $jobKey]);
            echo "worker pid $pid launched (log: $logFile)\n";
        } else {
            echo "WARN: worker launch returned no pid\n";
        }
    }
}

echo "[" . date('c') . "] done.\n";
