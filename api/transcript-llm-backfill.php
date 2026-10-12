<?php
/**
 * Background Layer-3 LLM reconcile backfill (2026-10-10, rules revised 2026-10-12).
 *
 * Runs the guarded LLM reconcile + first-word anchor pass one item per tick,
 * strictly BEHIND normal work and only on text no human has touched:
 *
 *  1. EDITED transcripts (any hand edit, review bookmark or validation) — only
 *     once the last human activity is more than 7 days old, and only the lines
 *     AFTER the last edited/bookmarked line (everything up to and including it
 *     is left exactly as is). Aborts if a human edits the item meanwhile.
 *     Done-marker: snapshot 'pre auto LLM reconcile (edited tail)' (or the
 *     older '(tail rebuild)') newer than the last human activity.
 *  2. UNEDITED transcripts — consensus builds with llm_reconcile=false (the
 *     10-10 fast regen, and fresh builds whose Layer-3 pass was deferred by the
 *     GPU gate), still 'Pending', with NO hand edit / bookmark / validation.
 *     Done-marker: snapshot 'pre auto LLM reconcile (backfill)'.
 *
 * GPU: only when _gpu_gate.php class 'llm_bg' is clear (no TTS build, no
 * image/video/audio job, no transcription queued/running) and the init queue
 * is idle. Holds GPU_LLM_LOCK while running (so transcription / generation
 * wait for it), aborts mid-item if TTS, generation or a single-item
 * transcription needs the card, and unloads ollama when it is told to wait.
 * Any non-OK run relabels its snapshot '— incomplete' so the item is retried.
 *
 *   php transcript-llm-backfill.php [--dry-run] [--status]
 * Driven by root cron (flock'd), one item per invocation.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-caption-lib.php';
require_once __DIR__ . '/_gpu_gate.php';

$dry    = in_array('--dry-run', $argv, true);
$status = in_array('--status', $argv, true);
$db = getDb();
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}
const BF_REASON   = 'pre auto LLM reconcile (backfill)';
const BF_EDITED   = 'pre auto LLM reconcile (edited tail)';
const BF_MIN_IDLE = '7 days';

// Items with nothing for the LLM to do (no baselines / nothing past the cut)
// are parked here so they aren't re-picked every tick; a later human edit
// makes them eligible again (dtime < last activity).
$db->exec("CREATE TABLE IF NOT EXISTS yy_transcript_llm_backfill_skip (
              feed_item_key integer PRIMARY KEY, skip_reason text NOT NULL, skip_dtime timestamptz NOT NULL DEFAULT now())");

$HAND_EDIT = "COALESCE(edit_user_key, 0) <> 0 AND edit_batch_key IS NULL";

$candSql = "
    SELECT DISTINCT ON (j.job_item_key) j.job_item_key AS k, j.job_params
      FROM yy_feed_item_transcript_init_job j
      JOIN yy_feed_item_transcript_status s ON s.feed_item_key = j.job_item_key AND s.edit_status = 'Pending'
     WHERE j.job_model = 'consensus' AND j.job_status = 'done'
       AND EXISTS (SELECT 1 FROM yy_feed_item fi WHERE fi.feed_item_key = j.job_item_key AND fi.feed_item_active_flag)
       AND j.job_completed >= '2026-10-10'
       AND j.job_params LIKE '%\"llm_reconcile\": false%'
       AND NOT EXISTS (SELECT 1 FROM yy_transcript_edit_log el WHERE el.feed_item_key = j.job_item_key AND $HAND_EDIT)
       AND NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript_validation v WHERE v.feed_item_key = j.job_item_key)
       AND NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript_bookmark b WHERE b.feed_item_key = j.job_item_key)
       AND NOT EXISTS (SELECT 1 FROM yy_transcript_snapshot sn
                        WHERE sn.feed_item_key = j.job_item_key AND sn.snapshot_reason = '" . BF_REASON . "'
                          AND sn.snapshot_dtime >= j.job_completed)
       AND NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript_init_job j2
                        WHERE j2.job_item_key = j.job_item_key AND j2.job_key > j.job_key
                          AND j2.job_status IN ('pending','running','done'))
     ORDER BY j.job_item_key DESC, j.job_key DESC";

// Human-touched items with their last human activity and the cut point.
$editedBase = "
    WITH h AS (
        SELECT feed_item_key k, MAX(edit_dtime) t, MAX(EXTRACT(EPOCH FROM edit_segment)) secs
          FROM yy_transcript_edit_log WHERE $HAND_EDIT GROUP BY 1
        UNION ALL
        SELECT feed_item_key, MAX(GREATEST(validation_dtime, COALESCE(validation_revision_dtime, validation_dtime))),
               MAX(validation_bookmark_seconds)
          FROM yy_feed_item_transcript_validation GROUP BY 1
        UNION ALL
        SELECT feed_item_key, MAX(bookmark_create_dtime), MAX(EXTRACT(EPOCH FROM feed_item_transcript_segment))
          FROM yy_feed_item_transcript_bookmark GROUP BY 1
    ), e AS (
        SELECT k, MAX(t) last_activity, COALESCE(MAX(secs), 0) cut FROM h GROUP BY k
    )
    SELECT e.k, e.last_activity, e.cut,
           (e.last_activity < NOW() - INTERVAL '" . BF_MIN_IDLE . "') AS idle_enough
      FROM e
      JOIN yy_feed_item fi ON fi.feed_item_key = e.k AND fi.feed_item_active_flag
      LEFT JOIN yy_feed_item_transcript_status s ON s.feed_item_key = e.k
     WHERE COALESCE(s.edit_status, '') <> 'Approved'
       AND EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = e.k)
       AND NOT EXISTS (SELECT 1 FROM yy_transcript_snapshot sn WHERE sn.feed_item_key = e.k
                          AND sn.snapshot_reason IN ('" . BF_EDITED . "', 'pre auto LLM reconcile (tail rebuild)')
                          AND sn.snapshot_dtime > e.last_activity)
       AND NOT EXISTS (SELECT 1 FROM yy_transcript_llm_backfill_skip sk WHERE sk.feed_item_key = e.k
                          AND sk.skip_dtime > e.last_activity)";

if ($status) {
    $n = count($db->query($candSql)->fetchAll());
    $done = (int)$db->query("SELECT COUNT(DISTINCT feed_item_key) FROM yy_transcript_snapshot WHERE snapshot_reason = '" . BF_REASON . "'")->fetchColumn();
    $ed = $db->query($editedBase)->fetchAll(PDO::FETCH_ASSOC);
    $ready = count(array_filter($ed, fn($r) => $r['idle_enough']));
    $gate = gpuGateReason($db, 'llm_bg');
    echo "remaining=$n done=$done edited_ready=$ready edited_waiting_7d=" . (count($ed) - $ready)
       . ' gpu=' . ($gate === null ? 'clear' : "waiting ($gate)") . "\n";
    exit(0);
}

// Behind normal work: skip this tick if the init queue or the GPU is busy.
$busy = (int)$db->query("SELECT COUNT(*) FROM yy_feed_item_transcript_init_job WHERE job_status IN ('pending','running')")->fetchColumn();
if ($busy > 0) { echo date('c') . " skip: init queue busy ($busy)\n"; exit(0); }
$gate = gpuGateReason($db, 'llm_bg');
if ($gate !== null) {
    $unloaded = $dry ? [] : gpuOllamaUnloadIdle($db);
    echo date('c') . " skip: GPU busy — $gate" . ($unloaded ? ' (unloaded ' . implode(', ', $unloaded) . ')' : '') . "\n";
    exit(0);
}
if (!$dry && !gpuLlmLock($db)) { echo date('c') . " skip: another LLM pass holds the GPU\n"; exit(0); }

// Stop mid-item when something higher-priority needs the card.
$prioStt = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE job_status = 'pending' AND job_priority >= 1");
$gpuAbort = function () use ($db, $prioStt): bool {
    if (gpuTtsActive($db) > 0 || gpuGenActive($db) > 0) return true;
    $prioStt->execute(); return (int)$prioStt->fetchColumn() > 0;
};
$handSince = $db->prepare("SELECT COUNT(*) FROM yy_transcript_edit_log WHERE feed_item_key = ? AND edit_dtime > ? AND $HAND_EDIT");

$baselinesFor = function (int $itemKey, array $want) use ($db): array {
    $avail = array_column(cfAutoModels($db, $itemKey), 'code');
    $b = array_values(array_intersect($want, $avail)) ?: $avail;
    $weights = cfEngineWeights($db);
    if ($weights) usort($b, fn($x, $y) => ($weights[$y] ?? 1.0) <=> ($weights[$x] ?? 1.0));
    return $b;
};
$park = function (int $itemKey, string $why) use ($db) {
    $db->prepare("INSERT INTO yy_transcript_llm_backfill_skip (feed_item_key, skip_reason) VALUES (?, ?)
                  ON CONFLICT (feed_item_key) DO UPDATE SET skip_reason = EXCLUDED.skip_reason, skip_dtime = now()")
       ->execute([$itemKey, $why]);
};
$relabel = function (int $itemKey, string $reason, float $t0) use ($db) {
    $db->prepare("UPDATE yy_transcript_snapshot SET snapshot_reason = ? WHERE feed_item_key = ? AND snapshot_reason = ? AND snapshot_dtime >= to_timestamp(?)")
       ->execute([$reason . ' — incomplete', $itemKey, $reason, (int)$t0 - 5]);
};

// ── 1. Edited transcripts: only the lines after the last edited one, and only
//       once nobody has touched the item for 7 days.
$lineAfter = $db->prepare("SELECT EXTRACT(EPOCH FROM MIN(feed_item_transcript_segment)) FROM yy_feed_item_transcript
                            WHERE feed_item_key = ? AND EXTRACT(EPOCH FROM feed_item_transcript_segment) > ? + 0.001");
foreach ($db->query($editedBase . " AND e.last_activity < NOW() - INTERVAL '" . BF_MIN_IDLE . "' ORDER BY e.last_activity LIMIT 25")->fetchAll(PDO::FETCH_ASSOC) as $ec) {
    $itemKey = (int)$ec['k'];
    $cut = (float)$ec['cut'];
    // First line strictly after the last edited/bookmarked line.
    $lineAfter->execute([$itemKey, $cut]);
    $from = $lineAfter->fetchColumn();
    if ($from === null || $from === false) { if (!$dry) $park($itemKey, 'nothing after the last edited line'); continue; }
    $from = floor((float)$from * 1000) / 1000;   // cfLoadLive reads segments at ms precision
    $jq = $db->prepare("SELECT job_params FROM yy_feed_item_transcript_init_job WHERE job_item_key = ? AND job_model = 'consensus' AND job_status = 'done' ORDER BY job_key DESC LIMIT 1");
    $jq->execute([$itemKey]);
    $jp = json_decode((string)$jq->fetchColumn(), true) ?: [];
    $baselines = $baselinesFor($itemKey, (array)($jp['baselines'] ?? []));
    if (!$baselines) { if (!$dry) $park($itemKey, 'no engine baselines'); continue; }
    if ($dry) { printf("would backfill EDITED item=%d last_human=%s cut=%.2f from=%.2f baselines=%s\n", $itemKey, $ec['last_activity'], $cut, $from, implode(',', $baselines)); exit(0); }
    $since = date('c');
    $abort = function () use ($handSince, $itemKey, $since, $gpuAbort): bool {
        $handSince->execute([$itemKey, $since]);
        return (int)$handSince->fetchColumn() > 0 || $gpuAbort();
    };
    $t0 = microtime(true);
    $rc = llmReconcileTranscript($db, $itemKey, $baselines, 'qwen2.5:72b', null,
        ['from_secs' => $from, 'snapshot_reason' => BF_EDITED, 'abort_if' => $abort]);
    $msg = sprintf('%s EDITED item=%d from=%.0fs llm ok=%d changed=%d guarded=%d%s', date('c'), $itemKey, $from,
        !empty($rc['ok']), (int)($rc['changed'] ?? 0), (int)($rc['guarded'] ?? 0), empty($rc['ok']) ? ' error=' . ($rc['error'] ?? '?') : '');
    if (empty($rc['ok'])) $relabel($itemKey, BF_EDITED, $t0);
    if (!empty($rc['ok']) && !$abort()) {
        try {
            $an = cfAnchorFirstWords($db, $itemKey, $baselines, ['apply' => true, 'from_secs' => $from]);
            $msg .= sprintf(' anchor retimed=%d unanchored=%d', $an['retimed'], $an['unanchored']);
        } catch (\Throwable $e) { $msg .= ' anchor-error=' . $e->getMessage(); }
    }
    gpuLlmUnlock($db);
    echo $msg . sprintf(' %.0fs', microtime(true) - $t0) . "\n";
    exit(0);
}

// ── 2. Unedited transcripts: the whole thing.
$c = $db->query($candSql . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$c) { gpuLlmUnlock($db); echo date('c') . " DONE: nothing eligible to backfill\n"; exit(0); }
$itemKey = (int)$c['k'];
$jp = json_decode((string)$c['job_params'], true) ?: [];
$baselines = $baselinesFor($itemKey, (array)($jp['baselines'] ?? []));
if ($dry) { echo "would backfill item=$itemKey baselines=" . implode(',', $baselines) . "\n"; exit(0); }

$st = $db->prepare("SELECT edit_status FROM yy_feed_item_transcript_status WHERE feed_item_key = ?");
$since = date('c');
$abort = function () use ($st, $itemKey, $handSince, $since, $gpuAbort): bool {
    $st->execute([$itemKey]);
    if ($st->fetchColumn() !== 'Pending') return true;
    $handSince->execute([$itemKey, $since]);
    return (int)$handSince->fetchColumn() > 0 || $gpuAbort();
};
$t0 = microtime(true);
$rc = llmReconcileTranscript($db, $itemKey, $baselines, 'qwen2.5:72b', null,
                             ['snapshot_reason' => BF_REASON, 'abort_if' => $abort]);
$msg = sprintf('%s item=%d llm ok=%d changed=%d guarded=%d chunks=%d%s',
    date('c'), $itemKey, !empty($rc['ok']), (int)($rc['changed'] ?? 0), (int)($rc['guarded'] ?? 0),
    (int)($rc['chunks'] ?? 0), empty($rc['ok']) ? ' error=' . ($rc['error'] ?? '?') : '');
// Any stop (GPU failure, GPU needed elsewhere, human started editing): relabel
// this run's snapshot so the item is retried if still eligible. Partial edits
// stay; the retry re-snapshots first, and the relabelled snapshot allows undo.
if (empty($rc['ok'])) $relabel($itemKey, BF_REASON, $t0);
if (!empty($rc['ok']) && !$abort()) {
    try {
        $an = cfAnchorFirstWords($db, $itemKey, $baselines, ['apply' => true]);
        $msg .= sprintf(' anchor retimed=%d unanchored=%d/%d', $an['retimed'], $an['unanchored'], $an['lines']);
    } catch (\Throwable $e) { $msg .= ' anchor-error=' . $e->getMessage(); }
}
gpuLlmUnlock($db);
echo $msg . sprintf(' %.0fs', microtime(true) - $t0) . "\n";
