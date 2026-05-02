<?php
/**
 * Live progress for an in-flight finalize (ffmpeg concat+encode).
 *
 * The finalize action in admin-transcript-upload.php runs ffmpeg with
 *   -progress /tmp/finalize_{item}.progress
 * which writes a status block (out_time_us=…, progress=continue|end) every
 * ~500ms. We sum total parts duration into /tmp/finalize_{item}.duration so
 * we can compute fraction = current_seconds / total_seconds.
 *
 * Client polls this every ~500ms while waiting on the finalize POST. When
 * the POST resolves, polling stops.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$itemKey = (int)($_GET['item_key'] ?? 0);
if (!$itemKey) errorResponse('item_key required');

$progressFile = sys_get_temp_dir() . '/finalize_' . $itemKey . '.progress';
$durationFile = sys_get_temp_dir() . '/finalize_' . $itemKey . '.duration';

if (!is_file($progressFile)) {
    jsonResponse(['running' => false]);
}
$content = @file_get_contents($progressFile) ?: '';
$totalSec = is_file($durationFile) ? (float)@file_get_contents($durationFile) : 0.0;

// ffmpeg appends progress blocks. Find the LAST out_time_us value.
preg_match_all('/out_time_us=(\d+)/', $content, $m);
$lastUs = end($m[1]);
$currentSec = $lastUs ? ((float)$lastUs / 1000000.0) : 0.0;
$ended = (strpos($content, 'progress=end') !== false);
$frac = $totalSec > 0 ? min(1.0, $currentSec / $totalSec) : 0.0;

jsonResponse([
    'running' => !$ended,
    'fraction' => $frac,
    'current_seconds' => $currentSec,
    'total_seconds' => $totalSec,
]);
