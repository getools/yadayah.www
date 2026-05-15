<?php
/**
 * Stream a feed_item's transcript as a WebVTT file (Content-Disposition:
 * attachment). Reuses the same yy_feed_item_transcript rows the YouTube
 * caption upload uses (via ytCaptionsBuildSrt's row+timestamp logic) but
 * emits WEBVTT instead of SRT — period decimal separator and a header line.
 *
 * GET ?key=N
 *
 * Auth: requireAuth() — admin only, same as the sibling upload endpoint.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/youtube-caption-helpers.php';

requireAuth();

$key = isset($_GET['key']) ? (int)$_GET['key'] : 0;
if ($key <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing or invalid 'key' parameter.";
    exit;
}

$db = getDb();

// Pull title + duration for the filename + final-cue end-time.
$itemStmt = $db->prepare("
    SELECT COALESCE(feed_item_title_override, feed_item_title_import) AS title,
           feed_item_duration_seconds
      FROM yy_feed_item
     WHERE feed_item_key = ?
");
$itemStmt->execute([$key]);
$item = $itemStmt->fetch(PDO::FETCH_ASSOC);
if (!$item) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "feed_item $key not found.";
    exit;
}

$rowsStmt = $db->prepare("
    SELECT feed_item_transcript_segment, feed_item_transcript_text
      FROM yy_feed_item_transcript
     WHERE feed_item_key = ?
     ORDER BY feed_item_transcript_sort
");
$rowsStmt->execute([$key]);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No transcript rows for feed_item $key.";
    exit;
}

$totalSeconds = (int)($item['feed_item_duration_seconds'] ?? 0);

// Filename: slugified title, max 120 chars, plus the feed_item_key suffix
// so identical-titled items never collide.
$slug = strtolower((string)($item['title'] ?? ''));
$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
$slug = trim($slug, '-');
if ($slug === '') $slug = 'transcript';
if (strlen($slug) > 120) $slug = substr($slug, 0, 120);
$filename = $slug . '-' . $key . '.vtt';

// VTT seconds-to-timestamp: HH:MM:SS.mmm (period decimal, not comma).
$fmt = function (int $totalSec): string {
    $h = intdiv($totalSec, 3600);
    $m = intdiv($totalSec % 3600, 60);
    $s = $totalSec % 60;
    return sprintf('%02d:%02d:%02d.000', $h, $m, $s);
};

header('Content-Type: text/vtt; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

echo "WEBVTT\n\n";

$n = count($rows);
for ($i = 0; $i < $n; $i++) {
    $startSec = ytCaptionsHmsToSeconds((string)$rows[$i]['feed_item_transcript_segment']);
    if ($i + 1 < $n) {
        // End at the next cue's start; no overlap (overlap is a YouTube
        // smooth-scroll trick that confuses standalone players).
        $endSec = ytCaptionsHmsToSeconds((string)$rows[$i + 1]['feed_item_transcript_segment']);
    } else {
        $endSec = $totalSeconds > $startSec ? $totalSeconds : $startSec + 5;
    }
    if ($totalSeconds > 0 && $endSec > $totalSeconds) $endSec = $totalSeconds;
    if ($endSec <= $startSec) $endSec = $startSec + 1;
    echo $fmt($startSec) . ' --> ' . $fmt($endSec) . "\n"
       . trim((string)$rows[$i]['feed_item_transcript_text']) . "\n\n";
}
