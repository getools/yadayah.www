<?php
/**
 * Background Layer-3 LLM reconcile backfill (2026-10-10).
 *
 * The 2026-10-10 bulk regeneration rebuilt every unedited consensus transcript
 * FAST (params.llm_reconcile=false — structural fixes only). This CLI then runs
 * the guarded LLM reconcile + first-word anchor pass on them one item at a
 * time, strictly BEHIND normal work:
 *   - only when the init queue has nothing pending/running (new uploads first);
 *   - only items whose transcript is still 'Pending' (never human-edited), and
 *     it aborts mid-item the moment an admin flips one to 'Editing';
 *   - snapshots first ('pre auto LLM reconcile (backfill)'), and that snapshot
 *     marks the item done, so the run is resumable and idempotent.
 *
 *   php transcript-llm-backfill.php [--one] [--dry-run] [--status]
 * Driven by cron on the host (flock'd), one item per invocation.
 * Candidate set: items rebuilt by a consensus job with llm_reconcile=false that
 * completed on/after 2026-10-10 and have no backfill snapshot since.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-caption-lib.php';

$dry    = in_array('--dry-run', $argv, true);
$status = in_array('--status', $argv, true);
$db = getDb();
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}
const BF_REASON = 'pre auto LLM reconcile (backfill)';

$candSql = "
    SELECT DISTINCT ON (j.job_item_key) j.job_item_key AS k, j.job_params
      FROM yy_feed_item_transcript_init_job j
      JOIN yy_feed_item_transcript_status s ON s.feed_item_key = j.job_item_key AND s.edit_status = 'Pending'
     WHERE j.job_model = 'consensus' AND j.job_status = 'done'
       AND j.job_completed >= '2026-10-10'
       AND j.job_params LIKE '%\"llm_reconcile\": false%'
       AND NOT EXISTS (SELECT 1 FROM yy_transcript_snapshot sn
                        WHERE sn.feed_item_key = j.job_item_key AND sn.snapshot_reason = '" . BF_REASON . "'
                          AND sn.snapshot_dtime >= j.job_completed)
       AND NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript_init_job j2
                        WHERE j2.job_item_key = j.job_item_key AND j2.job_key > j.job_key
                          AND j2.job_status IN ('pending','running','done'))
     ORDER BY j.job_item_key DESC, j.job_key DESC";

if ($status) {
    $n = count($db->query($candSql)->fetchAll());
    $done = (int)$db->query("SELECT COUNT(DISTINCT feed_item_key) FROM yy_transcript_snapshot WHERE snapshot_reason = '" . BF_REASON . "'")->fetchColumn();
    echo "remaining=$n done=$done\n";
    exit(0);
}

// Behind normal work: skip this tick if the init queue is busy.
$busy = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_init_job WHERE job_status IN ('pending','running')")->fetchColumn();
if ($busy > 0) { echo date('c') . " skip: init queue busy ($busy)\n"; exit(0); }

$c = $db->query($candSql . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$c) { echo date('c') . " DONE: nothing left to backfill\n"; exit(0); }
$itemKey = (int)$c['k'];
$jp = json_decode((string)$c['job_params'], true) ?: [];
$avail = array_column(cfAutoModels($db, $itemKey), 'code');
$baselines = array_values(array_intersect((array)($jp['baselines'] ?? []), $avail));
if (!$baselines) $baselines = $avail;
$weights = cfEngineWeights($db);
if ($weights) usort($baselines, fn($a, $b) => ($weights[$b] ?? 1.0) <=> ($weights[$a] ?? 1.0));
if ($dry) { echo "would backfill item=$itemKey baselines=" . implode(',', $baselines) . "\n"; exit(0); }

$st = $db->prepare("SELECT edit_status FROM yy_feed_item_transcript_status WHERE feed_item_key = ?");
$abort = function () use ($st, $itemKey): bool {
    $st->execute([$itemKey]);
    return $st->fetchColumn() !== 'Pending';
};
$t0 = microtime(true);
$rc = llmReconcileTranscript($db, $itemKey, $baselines, 'qwen2.5:72b', null,
                             ['snapshot_reason' => BF_REASON, 'abort_if' => $abort]);
$msg = sprintf('%s item=%d llm ok=%d changed=%d guarded=%d chunks=%d%s',
    date('c'), $itemKey, !empty($rc['ok']), (int)($rc['changed'] ?? 0), (int)($rc['guarded'] ?? 0),
    (int)($rc['chunks'] ?? 0), empty($rc['ok']) ? ' error=' . ($rc['error'] ?? '?') : '');
if (empty($rc['ok']) && ($rc['error'] ?? '') !== 'aborted') {
    // LLM/GPU failure: relabel this run's snapshot so the item is retried later
    // (the label is what marks an item done). Partial edits stay; the retry
    // re-snapshots first, and the relabelled snapshot still allows an undo.
    $db->prepare("UPDATE yy_transcript_snapshot SET snapshot_reason = ? WHERE feed_item_key = ? AND snapshot_reason = ? AND snapshot_dtime >= to_timestamp(?)")
       ->execute([BF_REASON . ' — incomplete', $itemKey, BF_REASON, (int)$t0 - 5]);
}
if (!empty($rc['ok']) && !$abort()) {
    try {
        $an = cfAnchorFirstWords($db, $itemKey, $baselines, ['apply' => true]);
        $msg .= sprintf(' anchor retimed=%d unanchored=%d/%d', $an['retimed'], $an['unanchored'], $an['lines']);
    } catch (\Throwable $e) { $msg .= ' anchor-error=' . $e->getMessage(); }
}
echo $msg . sprintf(' %.0fs', microtime(true) - $t0) . "\n";
