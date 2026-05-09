<?php
/**
 * Three-version comparison data for a single transcript.
 *
 * Returns a row-aligned dataset showing, per segment:
 *   text_initial   — exactly what Whisper produced (from snapshot)
 *   text_auto_fix  — Whisper output + applyCorrectionDictionary (snapshot)
 *   text_current   — live row in yy_feed_item_transcript
 *
 * Pairing is by (sort, segment). When a snapshot row matches a current
 * row by (segment), we line them up; rows in only one source are
 * returned with the missing version as null.
 *
 * Also reports per-segment edit history from yy_transcript_edit_log so
 * the UI can flag rows that have human edits beyond the auto-fix.
 *
 * GET ?item_key=N → { item_key, has_snapshot, rows: [...], totals: {...} }
 *
 * Items transcribed before the snapshot table existed have no snapshot
 * rows — has_snapshot = false in that case and only text_current is
 * meaningful.
 */
require_once __DIR__ . '/config.php';
requireAuth();
$db = getDb();

$itemKey = (int)($_GET['item_key'] ?? 0);
if (!$itemKey) errorResponse('item_key required');

// Live rows
$liveStmt = $db->prepare("
    SELECT feed_item_transcript_key,
           feed_item_transcript_segment::text AS segment,
           feed_item_transcript_text          AS text_current,
           feed_item_transcript_sort          AS sort
      FROM yy_feed_item_transcript
     WHERE feed_item_key = ?
     ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
");
$liveStmt->execute([$itemKey]);
$liveRows = $liveStmt->fetchAll();

// Snapshot rows (paired by segment, since segment is the stable key)
$snapStmt = $db->prepare("
    SELECT snapshot_segment::text AS segment,
           snapshot_sort          AS sort,
           text_initial,
           text_auto_fix
      FROM yy_feed_item_transcript_snapshot
     WHERE feed_item_key = ?
     ORDER BY snapshot_sort, snapshot_segment
");
$snapStmt->execute([$itemKey]);
$snapByseg = [];
foreach ($snapStmt->fetchAll() as $s) {
    $snapByseg[$s['segment']] = $s;
}

// Edit history per segment — flag rows with human edits since auto-fix
$editStmt = $db->prepare("
    SELECT edit_segment::text AS segment, edit_action,
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

$out = [];
$totals = ['initial_diff_auto' => 0, 'auto_diff_current' => 0, 'initial_diff_current' => 0];
foreach ($liveRows as $r) {
    $seg = $r['segment'];
    $snap = $snapByseg[$seg] ?? null;
    $textInitial = $snap['text_initial']   ?? null;
    $textAuto    = $snap['text_auto_fix']  ?? null;
    $textCurrent = $r['text_current'];

    if ($textInitial !== null && $textAuto !== null && $textInitial !== $textAuto) $totals['initial_diff_auto']++;
    if ($textAuto    !== null && $textAuto    !== $textCurrent)                    $totals['auto_diff_current']++;
    if ($textInitial !== null && $textInitial !== $textCurrent)                    $totals['initial_diff_current']++;

    $out[] = [
        'segment'       => $seg,
        'sort'          => (int)$r['sort'],
        'text_initial'  => $textInitial,
        'text_auto_fix' => $textAuto,
        'text_current'  => $textCurrent,
        'edits'         => $editsByseg[$seg] ?? null,
    ];
    unset($snapByseg[$seg]);
}
// Snapshot rows that no longer have a live row (deleted in editing) —
// expose them too so the UI can render the "deleted by reviewer" case.
foreach ($snapByseg as $seg => $snap) {
    $out[] = [
        'segment'       => $seg,
        'sort'          => (int)$snap['sort'],
        'text_initial'  => $snap['text_initial'],
        'text_auto_fix' => $snap['text_auto_fix'],
        'text_current'  => null,
        'edits'         => $editsByseg[$seg] ?? null,
    ];
    if ($snap['text_initial']  !== $snap['text_auto_fix']) $totals['initial_diff_auto']++;
    $totals['auto_diff_current']++;
    $totals['initial_diff_current']++;
}
// Re-sort by (sort, segment) so the merged list reads top-to-bottom.
usort($out, function($a, $b) {
    if ($a['sort'] !== $b['sort']) return $a['sort'] - $b['sort'];
    return strcmp($a['segment'], $b['segment']);
});

jsonResponse([
    'item_key'     => $itemKey,
    'has_snapshot' => count($snapByseg) > 0 || array_filter(array_column($out, 'text_initial')) !== [],
    'rows'         => $out,
    'totals'       => $totals,
]);
