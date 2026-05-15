<?php
/**
 * Stream a zip of WebVTT transcripts for the given feed_item keys.
 *
 *   GET ?keys=11,22,33
 *
 * Builds the same VTT content as admin-transcript-vtt.php (one item at
 * a time) but packs them into a single .zip download. Skips any key
 * that has no transcript rows. Inside-zip filenames mirror the
 * single-file endpoint: <slugified-title>-<key>.vtt.
 *
 * Auth: requireAuth() — admin only.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/youtube-caption-helpers.php';

requireAuth();

$keysRaw = $_GET['keys'] ?? '';
$keys = array_values(array_filter(array_map('intval', explode(',', $keysRaw)), function ($k) {
    return $k > 0;
}));
if (!$keys) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing or invalid 'keys' parameter.";
    exit;
}

$db = getDb();
$ph = implode(',', array_fill(0, count($keys), '?'));
$itemsStmt = $db->prepare("
    SELECT feed_item_key,
           COALESCE(feed_item_title_override, feed_item_title_import) AS title,
           feed_item_duration_seconds
      FROM yy_feed_item
     WHERE feed_item_key IN ($ph)
");
$itemsStmt->execute($keys);
$items = [];
foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $items[(int)$r['feed_item_key']] = $r;
}

$rowsStmt = $db->prepare("
    SELECT feed_item_transcript_segment, feed_item_transcript_text
      FROM yy_feed_item_transcript
     WHERE feed_item_key = ?
     ORDER BY feed_item_transcript_sort
");

// Reused inline (mirror of admin-transcript-vtt.php's fmt closure).
$fmt = function (int $totalSec): string {
    $h = intdiv($totalSec, 3600);
    $m = intdiv($totalSec % 3600, 60);
    $s = $totalSec % 60;
    return sprintf('%02d:%02d:%02d.000', $h, $m, $s);
};

$slugify = function (string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if ($s === '') $s = 'transcript';
    if (strlen($s) > 120) $s = substr($s, 0, 120);
    return $s;
};

$tmpZip = tempnam(sys_get_temp_dir(), 'vttzip_');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmpZip);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Failed to create zip file.";
    exit;
}

$added = 0;
foreach ($keys as $key) {
    $item = $items[$key] ?? null;
    if (!$item) continue;
    $rowsStmt->execute([$key]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) continue;

    $totalSeconds = (int)($item['feed_item_duration_seconds'] ?? 0);
    $vtt = "WEBVTT\n\n";
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        $startSec = ytCaptionsHmsToSeconds((string)$rows[$i]['feed_item_transcript_segment']);
        if ($i + 1 < $n) {
            $endSec = ytCaptionsHmsToSeconds((string)$rows[$i + 1]['feed_item_transcript_segment']);
        } else {
            $endSec = $totalSeconds > $startSec ? $totalSeconds : $startSec + 5;
        }
        if ($totalSeconds > 0 && $endSec > $totalSeconds) $endSec = $totalSeconds;
        if ($endSec <= $startSec) $endSec = $startSec + 1;
        $vtt .= $fmt($startSec) . ' --> ' . $fmt($endSec) . "\n"
              . trim((string)$rows[$i]['feed_item_transcript_text']) . "\n\n";
    }

    $name = $slugify((string)($item['title'] ?? '')) . '-' . $key . '.vtt';
    $zip->addFromString($name, $vtt);
    $added++;
}
$zip->close();

if ($added === 0) {
    @unlink($tmpZip);
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "None of the requested items have transcript rows.";
    exit;
}

$zipName = 'transcripts-' . date('Y-m-d-His') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-store');
readfile($tmpZip);
@unlink($tmpZip);
