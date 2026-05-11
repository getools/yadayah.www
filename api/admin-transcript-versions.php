<?php
/**
 * Three-version comparison data for a single transcript.
 *
 * Returns a row dataset showing, per segment timestamp:
 *   text_initial   — exactly what the auto transcriber produced (yy_feed_item_transcript_auto)
 *   text_auto_fix  — auto output + applyCorrectionDictionary (yy_feed_item_transcript_autoclean)
 *   text_current   — live row in yy_feed_item_transcript
 *
 * Rows are coordinated by SEGMENT TIMESTAMP, not record offset. The three
 * source tables can have different row counts (humans add/delete rows in
 * the live table; a fresh Whisper run produces its own segment boundaries
 * that may not exactly align with the prior live timestamps). For each
 * distinct segment value across all three sources we emit one row with
 * whichever versions are present at that exact timestamp; sources whose
 * timestamps don't match get null and are shown on their own row at the
 * timestamp they do have.
 *
 * Final output is sorted by segment ascending — the natural reading order
 * of a transcript.
 *
 * GET ?item_key=N → { item_key, has_snapshot, rows: [...], totals: {...} }
 *
 * Items that were never re-run through the snapshot pipeline have no rows
 * in _auto / _autoclean — has_snapshot=false in that case and only
 * text_current is meaningful.
 */
require_once __DIR__ . '/config.php';
requireAuth();
$db = getDb();

$itemKey = (int)($_GET['item_key'] ?? 0);
if (!$itemKey) errorResponse('item_key required');

// Fetch from each source. Cast segment to a normalized HH:MM:SS string up
// front so segment-equality comparisons in PHP behave like timestamp
// equality (avoids "00:01:23" vs "00:01:23.0" mismatches that would
// otherwise split the row).
function loadByseg(PDO $db, string $table, int $itemKey): array {
    $stmt = $db->prepare("
        SELECT to_char(feed_item_transcript_segment, 'HH24:MI:SS') AS segment,
               feed_item_transcript_text AS text
          FROM $table
         WHERE feed_item_key = ?
    ");
    $stmt->execute([$itemKey]);
    $byseg = [];
    foreach ($stmt->fetchAll() as $r) {
        // If duplicates exist at the same timestamp (rare), last write wins —
        // we don't try to merge text; alignment is per-timestamp.
        $byseg[$r['segment']] = $r['text'];
    }
    return $byseg;
}

$auto      = loadByseg($db, 'yy_feed_item_transcript_auto',      $itemKey);
$autoclean = loadByseg($db, 'yy_feed_item_transcript_autoclean', $itemKey);
$live      = loadByseg($db, 'yy_feed_item_transcript',           $itemKey);

// Edit history per segment — flag rows with human edits since auto-fix
$editStmt = $db->prepare("
    SELECT to_char(edit_segment, 'HH24:MI:SS') AS segment, edit_action,
           COUNT(*) AS n,
           MAX(edit_dtime) AS last_dtime
      FROM yy_transcript_edit_log
     WHERE feed_item_key = ?
     GROUP BY edit_segment, edit_action
");
$editStmt->execute([$itemKey]);
$editsByseg = [];
foreach ($editStmt->fetchAll() as $e) {
    $editsByseg[$e['segment']][$e['edit_action']] = [
        'count' => (int)$e['n'],
        'last_dtime' => $e['last_dtime'],
    ];
}

// Union of all segment timestamps across the three sources, sorted ascending.
$allSegs = array_keys(array_merge($auto, $autoclean, $live));
$allSegs = array_unique($allSegs);
sort($allSegs); // HH:MM:SS strings sort like real timestamps

$out = [];
$totals = ['initial_diff_auto' => 0, 'auto_diff_current' => 0, 'initial_diff_current' => 0];
foreach ($allSegs as $seg) {
    $ti = $auto[$seg]      ?? null;
    $ta = $autoclean[$seg] ?? null;
    $tc = $live[$seg]      ?? null;

    if ($ti !== null && $ta !== null && $ti !== $ta) $totals['initial_diff_auto']++;
    if ($ta !== null && $tc !== null && $ta !== $tc) $totals['auto_diff_current']++;
    if ($ti !== null && $tc !== null && $ti !== $tc) $totals['initial_diff_current']++;

    $out[] = [
        'segment'       => $seg,
        'text_initial'  => $ti,
        'text_auto_fix' => $ta,
        'text_current'  => $tc,
        'edits'         => $editsByseg[$seg] ?? null,
    ];
}

jsonResponse([
    'item_key'     => $itemKey,
    'has_snapshot' => count($auto) > 0 || count($autoclean) > 0,
    'rows'         => $out,
    'totals'       => $totals,
]);
