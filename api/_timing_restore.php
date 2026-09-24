<?php
/**
 * _timing_restore.php — roll one or many items back to the snapshot taken by
 * the timing/sizing pass (_timing_reflow.php --apply).
 *
 *   php _timing_restore.php <keys|--list=F|--all> [--apply]
 *       [--reason='timing+sizing pass (word-baseline retime/reflow)']
 *
 * --all restores every item that has a snapshot with the pass's reason string
 * and has not been touched since. Dry-run by default.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-caption-lib.php';

$REASON = 'timing+sizing pass (word-baseline retime/reflow)';
$items = []; $apply = false; $all = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') $apply = true;
    elseif ($a === '--all') $all = true;
    elseif (strncmp($a, '--reason=', 9) === 0) $REASON = substr($a, 9);
    elseif (strncmp($a, '--list=', 7) === 0) { foreach (preg_split('/\s+/', (string)@file_get_contents(substr($a, 7))) as $t) if ($t !== '') $items[] = (int)$t; }
    elseif ($a[0] !== '-') $items[] = (int)$a;
}
$db = getDb();
if ($all) {
    $st = $db->prepare("SELECT DISTINCT feed_item_key FROM yy_transcript_snapshot WHERE snapshot_reason = ?");
    $st->execute([$REASON]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) $items[] = (int)$k;
}
$items = array_values(array_unique(array_filter($items)));
if (!$items) { fwrite(STDERR, "usage: _timing_restore.php <keys|--list=F|--all> [--apply]\n"); exit(1); }

$sel = $db->prepare("SELECT snapshot_key, snapshot_json, snapshot_rows FROM yy_transcript_snapshot
                      WHERE feed_item_key = ? AND snapshot_reason = ?
                      ORDER BY snapshot_dtime ASC LIMIT 1");
$n = 0;
foreach ($items as $ik) {
    $sel->execute([$ik, $REASON]);
    $r = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$r) { echo "item $ik: no pass snapshot\n"; continue; }
    $rows = json_decode((string)$r['snapshot_json'], true);
    if (!is_array($rows) || !$rows) { echo "item $ik: corrupt snapshot {$r['snapshot_key']}\n"; continue; }
    printf("item %-8d restore %d rows from snapshot %d%s\n", $ik, count($rows), (int)$r['snapshot_key'], $apply ? ' [APPLY]' : ' [dry]');
    if (!$apply) continue;
    $cues = array_map(fn($x) => ['segment' => (string)$x['segment'], 'text' => (string)$x['text'],
                                 'speaker' => $x['speaker'] ?? null], $rows);
    cfReplaceLive($db, $ik, $cues);
    $n++;
}
printf("%s: %d item(s) restored.\n", $apply ? 'APPLIED' : 'DRY-RUN', $n);
