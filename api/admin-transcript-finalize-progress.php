<?php
/**
 * Live progress for an in-flight finalize.
 *
 * The async finalize worker (finalize-worker.php) writes two pieces of state:
 *   1. /tmp/finalize_{item}.state — JSON status block written by the worker
 *      (status: pending | validating | encoding | finalizing | complete | error,
 *      plus message, audio_file, transcribe_job_key, skipped_parts, error).
 *   2. /tmp/finalize_{item}.progress — ffmpeg's -progress output during the
 *      encoding phase, used to compute fraction = current_seconds / total.
 *
 * The popout polls this every ~500ms while waiting for finalize to complete.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finalize-helpers.php';
$user = requireAuth();
// Release the session lock immediately — this poll endpoint is read-only and
// must NOT block any concurrent writes. Without this, multiple endpoints
// serialize on the session file lock.
session_write_close();
$itemKey = (int)($_GET['item_key'] ?? 0);
if (!$itemKey) errorResponse('item_key required');

$state = readFinalizeState($itemKey);
$progressFile = sys_get_temp_dir() . '/finalize_' . $itemKey . '.progress';
$durationFile = sys_get_temp_dir() . '/finalize_' . $itemKey . '.duration';

// ffmpeg-level progress (only meaningful during encoding phase).
$frac = 0.0;
$currentSec = 0.0;
$totalSec = is_file($durationFile) ? (float)@file_get_contents($durationFile) : 0.0;
$ffmpegEnded = false;
if (is_file($progressFile)) {
    $content = @file_get_contents($progressFile) ?: '';
    preg_match_all('/out_time_us=(\d+)/', $content, $m);
    $lastUs = end($m[1]);
    $currentSec = $lastUs ? ((float)$lastUs / 1000000.0) : 0.0;
    $ffmpegEnded = (strpos($content, 'progress=end') !== false);
    if ($totalSec > 0) {
        $frac = min(1.0, $currentSec / $totalSec);
    }
}

if (!$state) {
    // No state file at all — nothing in flight.
    jsonResponse([
        'running'         => false,
        'status'          => 'idle',
        'fraction'        => 0,
        'current_seconds' => 0,
        'total_seconds'   => 0,
    ]);
}

$status = $state['status'] ?? 'unknown';
$running = !in_array($status, ['complete', 'error', 'idle'], true);

// During the encoding phase, the worker's reported total_seconds may not
// be set yet. Use the ffmpeg-side number if it's larger.
$total = max((float)($state['total_seconds'] ?? 0), $totalSec);
if ($status === 'complete') $frac = 1.0;

jsonResponse([
    'running'            => $running,
    'status'             => $status,                                // pending|validating|encoding|finalizing|complete|error
    'message'            => $state['message'] ?? '',
    'fraction'           => $frac,
    'current_seconds'    => $currentSec,
    'total_seconds'      => $total,
    'audio_file'         => $state['audio_file'] ?? null,           // set on complete
    'audio_size'         => $state['audio_size'] ?? null,
    'audio_seconds'      => $state['audio_seconds'] ?? null,
    'transcribe_job_key' => $state['transcribe_job_key'] ?? null,
    'skipped_parts'      => $state['skipped_parts'] ?? [],
    'strategy'           => $state['strategy'] ?? null,
    'error'              => $state['error'] ?? null,
    'started'            => $state['started'] ?? null,
    'completed'          => $state['completed'] ?? null,
    'updated'            => $state['updated'] ?? null,
]);
