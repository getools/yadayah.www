<?php
/**
 * Public transcript download. Returns a plain-text transcript for a
 * feed_item whose validation has been Approved. Gated on the validation
 * status so unreviewed/auto-only transcripts don't leak as "the official
 * one."
 *
 *   GET ?feed_item_key=N
 *     → text/plain attachment when validation_status = 'Approved'
 *     → 404 otherwise (also when no transcript rows exist)
 */
require_once __DIR__ . '/config.php';

$key = (int)($_GET['feed_item_key'] ?? 0);
if (!$key) { http_response_code(400); echo 'feed_item_key required'; exit; }

$db = getDb();

// Validate that the item has an Approved transcript.
$vStmt = $db->prepare("SELECT validation_status FROM yy_feed_item_transcript_validation WHERE feed_item_key = ?");
$vStmt->execute([$key]);
$status = $vStmt->fetchColumn();
if ($status !== 'Approved') {
    http_response_code(404);
    echo 'no approved transcript for this video';
    exit;
}

// Pull metadata for the filename + header.
$meta = $db->prepare("
    SELECT COALESCE(feed_item_title_override, feed_item_title_import) AS title,
           feed_item_external_id,
           feed_item_publish_override_dtime,
           feed_item_publish_import_dtime
      FROM yy_feed_item
     WHERE feed_item_key = ?
");
$meta->execute([$key]);
$item = $meta->fetch();
if (!$item) { http_response_code(404); echo 'feed_item not found'; exit; }

// Pull every transcript line in order.
$tStmt = $db->prepare("
    SELECT feed_item_transcript_segment, feed_item_transcript_speaker, feed_item_transcript_text
      FROM yy_feed_item_transcript
     WHERE feed_item_key = ?
     ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
");
$tStmt->execute([$key]);
$rows = $tStmt->fetchAll();
if (!$rows) { http_response_code(404); echo 'transcript empty'; exit; }

// Build a filesystem-safe filename from the title.
$title = (string)($item['title'] ?? 'transcript');
$safe  = preg_replace('/[^A-Za-z0-9._\- ]+/u', '', $title);
$safe  = preg_replace('/\s+/', ' ', $safe);
$safe  = trim($safe);
if ($safe === '') $safe = 'transcript-' . $key;
$filename = $safe . '.txt';

// Convert PostgreSQL interval (e.g. "00:03:42.500") to "[mm:ss]" or
// "[h:mm:ss]" so the readable text-file format stays compact.
function fmtInterval(?string $iv): string {
    if (!$iv) return '';
    if (preg_match('/(\d+):(\d+):(\d+(?:\.\d+)?)/', $iv, $m)) {
        $h = (int)$m[1]; $mi = (int)$m[2]; $s = (int)floor((float)$m[3]);
        return $h > 0 ? sprintf('[%d:%02d:%02d]', $h, $mi, $s) : sprintf('[%d:%02d]', $mi, $s);
    }
    return '';
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
header('Cache-Control: no-store');

echo "# " . $title . "\n";
$published = $item['feed_item_publish_override_dtime'] ?? $item['feed_item_publish_import_dtime'] ?? '';
if ($published) echo "# Published: " . $published . "\n";
echo "# Source: feed_item_key=" . $key . "\n";
echo "\n";
foreach ($rows as $r) {
    $ts = fmtInterval($r['feed_item_transcript_segment']);
    $sp = $r['feed_item_transcript_speaker'] ?? '';
    $txt = $r['feed_item_transcript_text'] ?? '';
    if ($ts !== '') echo $ts . ' ';
    if ($sp !== '') echo $sp . ': ';
    echo $txt . "\n";
}
