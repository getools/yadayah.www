<?php
/**
 * Async finalize worker. Spawned in the background by the
 * action=finalize handler so the HTTP request returns immediately —
 * Cloudflare's 100s gateway timeout was killing long encodes (3h
 * recordings) even though the work succeeded.
 *
 * Usage:
 *   php finalize-worker.php <item_key> <user_key> <auto_transcribe>
 *
 * State file: /tmp/finalize_{item_key}.state (JSON), polled by
 * admin-transcript-finalize-progress.php for the popout UI.
 *   status: pending | validating | encoding | finalizing | complete | error
 */

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$itemKey         = (int)($argv[1] ?? 0);
$userKey         = (int)($argv[2] ?? 0);
$autoTranscribe  = (int)($argv[3] ?? 0);
if ($itemKey <= 0 || $userKey <= 0) {
    fwrite(STDERR, "usage: finalize-worker.php <item_key> <user_key> <auto_transcribe>\n");
    exit(2);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finalize-helpers.php';

set_time_limit(0); // worker is background — no PHP wall-clock limit
ignore_user_abort(true);

$AUDIO_DIR_ABS  = dirname(__DIR__) . '/u/audio';
$AUDIO_DIR_REL  = 'u/audio';
$PARTS_DIR_ABS  = $AUDIO_DIR_ABS . '/parts';

$startedAt = date('c');
writeFinalizeState($itemKey, [
    'status'    => 'validating',
    'message'   => 'Probing recorded parts...',
    'started'   => $startedAt,
    'item_key'  => $itemKey,
    'pid'       => getmypid(),
]);

$db = getDb();

$abort = function(string $err) use ($itemKey) {
    writeFinalizeState($itemKey, [
        'status'    => 'error',
        'error'     => $err,
        'message'   => $err,
        'completed' => date('c'),
    ]);
    if (function_exists('logMonitorEvent')) {
        @logMonitorEvent('transcript_upload', 'error',
            'Finalize worker failed for item ' . $itemKey . ': ' . substr($err, 0, 400),
            null, false);
    }
    exit(1);
};

$parts = listParts($PARTS_DIR_ABS, $itemKey);
if (!$parts) $abort('No parts to finalize');

// Validate without unlinking — premature deletion lost a multi-hour
// recording in the past. Byte-concat recovery handles MediaRecorder
// continuation fragments below.
$validParts = [];
$invalidParts = [];
$skipped = [];
foreach ($parts as $idx => $f) {
    $check = validateAudioFile($f);
    if ($check['ok']) {
        $validParts[$idx] = $f;
    } else {
        $invalidParts[$idx] = $f;
        $skipped[] = 'part_' . $idx . ' (' . $check['reason'] . ')';
    }
}

$stamp    = time();
$finalRel = "$AUDIO_DIR_REL/audio_{$itemKey}_{$stamp}.mp3";
$finalAbs = "$AUDIO_DIR_ABS/audio_{$itemKey}_{$stamp}.mp3";

// Strategy:
//   1. all parts probable -> concat-filter
//   2. any fail -> byte-concat all parts to merged.webm and probe that
//   3. recovery fails -> use whatever validates; abort if nothing
$mergedTmp = null;
$encodeInputs = null;
$strategy = '';
if (count($validParts) === count($parts)) {
    $encodeInputs = array_values($validParts);
    $strategy = 'concat-filter (' . count($validParts) . ' independently-valid parts)';
} else {
    $mergedTmp = "$PARTS_DIR_ABS/merged_{$itemKey}_{$stamp}.webm";
    $fpOut = @fopen($mergedTmp, 'wb');
    if ($fpOut) {
        foreach ($parts as $f) {
            $fpIn = @fopen($f, 'rb');
            if ($fpIn) { stream_copy_to_stream($fpIn, $fpOut); fclose($fpIn); }
        }
        fclose($fpOut);
    }
    $mergedCheck = is_file($mergedTmp) ? validateAudioFile($mergedTmp) : ['ok' => false, 'reason' => 'merge write failed'];
    if ($mergedCheck['ok']) {
        $encodeInputs = [$mergedTmp];
        $strategy = 'byte-concat-recovery (' . count($parts) . ' parts -> '
                  . round(filesize($mergedTmp)/1024/1024, 2) . ' MB merged.webm; '
                  . count($skipped) . ' would have failed independent probe)';
    } else {
        @unlink($mergedTmp);
        $mergedTmp = null;
        if (!$validParts) {
            $abort('No usable parts. Independent probe rejected all (' . count($skipped)
                 . '); byte-concat recovery also failed: ' . substr($mergedCheck['reason'] ?? '', 0, 300)
                 . '. Parts preserved on disk for inspection.');
        }
        $encodeInputs = array_values($validParts);
        $strategy = 'partial (' . count($validParts) . '/' . count($parts) . ' independently valid; '
                  . count($invalidParts) . ' skipped, byte-concat fallback failed)';
    }
}

$ffmpeg  = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
$ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?: '');
if (!$ffmpeg) $abort('ffmpeg not available');

// Total duration so the progress endpoint can compute fraction.
$totalDur = 0.0;
if ($ffprobe) {
    foreach ($encodeInputs as $f) {
        $cmdP = escapeshellcmd($ffprobe)
              . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
              . escapeshellarg($f);
        $totalDur += (float)trim(shell_exec($cmdP) ?: '0');
    }
}

$progressFile = sys_get_temp_dir() . '/finalize_' . $itemKey . '.progress';
$durationFile = sys_get_temp_dir() . '/finalize_' . $itemKey . '.duration';
@unlink($progressFile);
@file_put_contents($durationFile, (string)$totalDur);

$inputArgs = '';
$filterIn = '';
foreach (array_values($encodeInputs) as $i => $f) {
    $inputArgs .= ' -i ' . escapeshellarg($f);
    $filterIn .= "[{$i}:a]";
}
$filter = $filterIn . 'concat=n=' . count($encodeInputs) . ':v=0:a=1[out]';
$cmd = escapeshellcmd($ffmpeg) . ' -y' . $inputArgs
     . ' -filter_complex ' . escapeshellarg($filter)
     . ' -map ' . escapeshellarg('[out]')
     . ' -codec:a libmp3lame -qscale:a 4 -ar 44100 -ac 2'
     . ' -progress ' . escapeshellarg($progressFile)
     . ' ' . escapeshellarg($finalAbs) . ' 2>&1';

writeFinalizeState($itemKey, [
    'status'         => 'encoding',
    'message'        => 'Encoding MP3 (' . round($totalDur) . 's of audio)',
    'strategy'       => $strategy,
    'total_seconds'  => $totalDur,
    'skipped_parts'  => $skipped,
]);

$encodeStart = microtime(true);
$out = shell_exec($cmd);
$encodeDur = microtime(true) - $encodeStart;

@unlink($progressFile);
@unlink($durationFile);

if (!file_exists($finalAbs) || filesize($finalAbs) < 1000) {
    if ($mergedTmp && is_file($mergedTmp)) @unlink($mergedTmp);
    $abort('ffmpeg encode failed (strategy: ' . $strategy . ', took '
         . round($encodeDur, 1) . 's): ' . substr(trim($out ?? ''), -300));
}
@chmod($finalAbs, 0664);

writeFinalizeState($itemKey, [
    'status'  => 'finalizing',
    'message' => 'Saving MP3 + cleaning up parts',
]);

// Wipe prior MP3 the DB pointed to.
$prevStmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
$prevStmt->execute([$itemKey]);
$prevFile = $prevStmt->fetchColumn();
if ($prevFile) {
    $prevAbs = dirname(__DIR__) . '/' . ltrim($prevFile, '/');
    if (is_file($prevAbs)) @unlink($prevAbs);
}

// Update DB
$db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ?, feed_item_audio_resume_seconds = NULL WHERE feed_item_key = ?")
   ->execute([$finalRel, $itemKey]);

// Drop originals + any byte-concat temp.
foreach ($parts as $f) @unlink($f);
if ($mergedTmp && is_file($mergedTmp)) @unlink($mergedTmp);

$jobKey = null;
if ($autoTranscribe) {
    $jobKey = spawnTranscribeJob($db, $itemKey, $userKey);
}

$completedAt = date('c');
$mp3SizeMB   = round(filesize($finalAbs) / 1024 / 1024, 2);
$mp3DurSec   = $ffprobe ? (float)trim(shell_exec(
    escapeshellcmd($ffprobe) . ' -v error -show_entries format=duration -of default=nw=1:nk=1 '
    . escapeshellarg($finalAbs)) ?: '0') : 0;

writeFinalizeState($itemKey, [
    'status'             => 'complete',
    'message'            => "Saved {$mp3SizeMB} MB MP3 (" . round($mp3DurSec) . "s)",
    'audio_file'         => $finalRel,
    'audio_size'         => filesize($finalAbs),
    'audio_seconds'      => $mp3DurSec,
    'transcribe_job_key' => $jobKey,
    'completed'          => $completedAt,
    'encode_seconds'     => round($encodeDur, 2),
]);

if (function_exists('logMonitorEvent')) {
    @logMonitorEvent('transcript_upload', 'info',
        'Finalized recording for item ' . $itemKey . ' (' . count($parts) . ' parts -> '
        . $mp3SizeMB . ' MB MP3, ' . round($mp3DurSec) . 's, strategy: ' . $strategy
        . ', encode took ' . round($encodeDur, 1) . 's)',
        '', true);
}

exit(0);
