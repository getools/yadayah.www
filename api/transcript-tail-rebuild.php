<?php
/**
 * Re-process the UNREVIEWED TAIL of a hand-edited transcript (2026-10-10).
 *
 * A transcript a reviewer is part-way through is protected from rebuilds
 * ('Editing'), so it keeps every old machine defect after the point they
 * reached. This rebuilds only what comes after that point:
 *
 *   cut  = the later of the review bookmark (validation_bookmark_seconds), any
 *          yy_feed_item_transcript_bookmark, and the last hand edit (edit log,
 *          non-bulk) — or --cut=SECS.
 *   keep = every live row that starts at or before the cut, untouched (the row
 *          straddling the cut is kept whole).
 *   tail = a fresh consensus build (txBuildConsensusRows — identical to the init
 *          worker) from the first row after the cut, with any leading words that
 *          repeat the end of the last kept row trimmed; speaker labels carried
 *          over from the old rows at the same times.
 *
 *   php transcript-tail-rebuild.php --list
 *   php transcript-tail-rebuild.php <item> [--cut=SECS]              (dry run: report only)
 *   php transcript-tail-rebuild.php <item> [--cut=SECS] --apply [--llm]
 *   php transcript-tail-rebuild.php <item> --restore=SNAPSHOT_KEY   (undo)
 *
 * --apply snapshots the whole transcript first ('pre tail rebuild …'), swaps
 * only the tail, keeps the status 'Editing', then runs the first-word anchor on
 * the tail; --llm also runs the guarded LLM reconcile on the tail (aborts if a
 * human edits a tail line meanwhile). Nothing before the cut is ever changed.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-consensus-build.php';

$args = array_slice($argv, 1);
$opt = ['apply' => in_array('--apply', $args, true), 'llm' => in_array('--llm', $args, true),
        'list' => in_array('--list', $args, true), 'cut' => null];
foreach ($args as $a) if (strncmp($a, '--cut=', 6) === 0) $opt['cut'] = (float)substr($a, 6);
$restore = 0; foreach ($args as $a) if (strncmp($a, '--restore=', 10) === 0) $restore = (int)substr($a, 10);
$itemKey = 0; foreach ($args as $a) if (ctype_digit($a)) $itemKey = (int)$a;
$db = getDb();
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

function ttHms(float $s): string { return sprintf('%d:%02d:%05.2f', (int)($s / 3600), (int)(fmod($s, 3600) / 60), fmod($s, 60)); }

/** Reviewed-up-to point: [secs, source]. */
function ttCutPoint(PDO $db, int $k): array {
    $c = [];
    $v = $db->prepare("SELECT validation_bookmark_seconds FROM yy_feed_item_transcript_validation WHERE feed_item_key = ? ORDER BY validation_dtime DESC LIMIT 1");
    $v->execute([$k]); $x = $v->fetchColumn(); if ($x !== false && $x !== null) $c[] = [(float)$x, 'review bookmark'];
    $b = $db->prepare("SELECT EXTRACT(EPOCH FROM MAX(feed_item_transcript_segment)) FROM yy_feed_item_transcript_bookmark WHERE feed_item_key = ?");
    $b->execute([$k]); $x = $b->fetchColumn(); if ($x !== false && $x !== null) $c[] = [(float)$x, 'transcript bookmark'];
    $e = $db->prepare("SELECT EXTRACT(EPOCH FROM MAX(edit_segment)) FROM yy_transcript_edit_log
                        WHERE feed_item_key = ? AND COALESCE(edit_user_key, 0) <> 0 AND edit_batch_key IS NULL");
    $e->execute([$k]); $x = $e->fetchColumn(); if ($x !== false && $x !== null) $c[] = [(float)$x, 'last hand edit'];
    if (!$c) return [0.0, 'none (no bookmark or hand edits)'];
    usort($c, fn($a, $b) => $b[0] <=> $a[0]);
    return $c[0];
}

/** First-words check over in-memory rows (same rule as cfAnchorFirstWords): count lines whose opening words aren't heard near their start. */
function ttUnanchored(array $rows, array $streams, float $tol = 3.0): array {
    $bad = []; $n = count($rows);
    foreach ($rows as $r) {
        $toks = cfGuardNorm((string)$r['text']); if (!$toks) continue;
        $s = (float)$r['secs'];
        $try = (count($toks) <= 2) ? [$toks] : [array_slice($toks, 0, 3), array_slice($toks, 0, 2)];
        $ok = false;
        foreach ($try as $w) {
            foreach ($streams as $ws) {
                $a = 0; $b = count($ws);
                while ($a < $b) { $m = ($a + $b) >> 1; if ($ws[$m][0] < $s - $tol) $a = $m + 1; else $b = $m; }
                for ($i = $a, $nw = count($ws); $i < $nw && $ws[$i][0] <= $s + $tol; $i++) {
                    $m = true; for ($q = 0; $q < count($w); $q++) if (($ws[$i + $q][1] ?? null) !== $w[$q]) { $m = false; break; }
                    if ($m) { $ok = true; break 3; }
                }
            }
        }
        if (!$ok) $bad[] = $r;
    }
    return $bad;
}

if ($restore) {
    // Undo: put a snapshot back verbatim (must belong to <item>).
    $st = $db->prepare("SELECT feed_item_key, snapshot_reason, snapshot_json FROM yy_transcript_snapshot WHERE snapshot_key = ?");
    $st->execute([$restore]); $sn = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sn || (int)$sn['feed_item_key'] !== $itemKey) { fwrite(STDERR, "snapshot $restore is not for item $itemKey
"); exit(1); }
    cfSnapshot($db, $itemKey, null, 'pre restore of snapshot ' . $restore);
    $n = cfReplaceLive($db, $itemKey, json_decode($sn['snapshot_json'], true));
    echo "restored snapshot $restore ({$sn['snapshot_reason']}): $n lines
";
    exit(0);
}
if ($opt['list']) {
    $rows = $db->query("
        SELECT k FROM (SELECT DISTINCT feed_item_key k FROM yy_transcript_edit_log WHERE COALESCE(edit_user_key,0) <> 0 AND edit_batch_key IS NULL
                       UNION SELECT feed_item_key FROM yy_feed_item_transcript_status WHERE edit_status = 'Editing'
                       UNION SELECT feed_item_key FROM yy_feed_item_transcript_validation) x
         WHERE EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = x.k)")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $k) {
        [$cut, $src] = ttCutPoint($db, (int)$k);
        $st = $db->prepare("SELECT EXTRACT(EPOCH FROM MAX(feed_item_transcript_segment)), COUNT(*) FROM yy_feed_item_transcript WHERE feed_item_key = ?");
        $st->execute([$k]); [$dur, $n] = $st->fetch(PDO::FETCH_NUM);
        $vs = $db->prepare("SELECT validation_status FROM yy_feed_item_transcript_validation WHERE feed_item_key = ?"); $vs->execute([$k]);
        $t = $db->prepare("SELECT COALESCE(feed_item_title_override, feed_item_title_import) FROM yy_feed_item WHERE feed_item_key = ?"); $t->execute([$k]);
        $out[] = [(int)$k, (float)$dur > 0 ? 100 * $cut / (float)$dur : 0, $cut, (float)$dur, $src, (string)$vs->fetchColumn(), mb_substr((string)$t->fetchColumn(), 0, 44)];
    }
    usort($out, fn($a, $b) => $a[1] <=> $b[1]);
    printf("%-9s %5s %11s %11s  %-20s %-9s %s\n", 'item', 'pct', 'cut', 'length', 'cut source', 'review', 'title');
    foreach ($out as $o) printf("%-9d %4.0f%% %11s %11s  %-20s %-9s %s\n", $o[0], $o[1], ttHms($o[2]), ttHms($o[3]), $o[4], $o[5] ?: '-', $o[6]);
    exit(0);
}
if (!$itemKey) { fwrite(STDERR, "usage: transcript-tail-rebuild.php --list | <item> [--cut=SECS] [--apply [--llm]]\n"); exit(1); }

// ── cut + keep/tail split ────────────────────────────────────────────────────
[$cut, $src] = $opt['cut'] !== null ? [$opt['cut'], '--cut'] : ttCutPoint($db, $itemKey);
$vs = $db->prepare("SELECT validation_status FROM yy_feed_item_transcript_validation WHERE feed_item_key = ? ORDER BY validation_dtime DESC LIMIT 1");
$vs->execute([$itemKey]);
if ($vs->fetchColumn() === 'Approved' && !in_array('--force', $args, true)) {
    echo "item $itemKey is Approved — refusing (add --force to override)\n"; exit(1);
}
$live = cfLoadLive($db, $itemKey);
if (!$live) { echo "item $itemKey has no live transcript\n"; exit(1); }
$C = null;
foreach ($live as $r) if ((float)$r['secs'] > $cut) { $C = (float)$r['secs']; break; }
$dur = (float)end($live)['secs'];
printf("item %d: %d lines, length %s; reviewed up to %s (%s) = %.0f%%\n", $itemKey, count($live), ttHms($dur), ttHms($cut), $src, $dur > 0 ? 100 * $cut / $dur : 0);
if ($C === null) { echo "nothing after the cut — fully reviewed, nothing to do\n"; exit(0); }
$keep = array_values(array_filter($live, fn($r) => (float)$r['secs'] < $C));
$oldTail = array_values(array_filter($live, fn($r) => (float)$r['secs'] >= $C));

// ── fresh build (same core as the init worker) ───────────────────────────────
$jq = $db->prepare("SELECT job_params FROM yy_feed_item_transcript_init_job WHERE job_item_key = ? AND job_model = 'consensus' AND job_status = 'done' ORDER BY job_key DESC LIMIT 1");
$jq->execute([$itemKey]);
$jp = json_decode((string)$jq->fetchColumn(), true) ?: [];
$avail = array_column(cfAutoModels($db, $itemKey), 'code');
$baselines = $jp['baselines'] ?? $avail;
$params = (array)($jp['params'] ?? ['max_chars' => 42, 'max_lines' => 2, 'max_secs' => 7, 'min_secs' => 1.2, 'break_punct' => true, 'dedup' => true]);
$built = txBuildConsensusRows($db, $itemKey, $baselines, $params, (string)($jp['primary'] ?? ''), ['notify' => function ($s) {}]);
$newAll = [];
foreach ($built['rows'] as $r) $newAll[] = ['segment' => $r['segment'], 'secs' => cfIntervalToSecs($r['segment']), 'text' => (string)$r['text']];
$newTail = array_values(array_filter($newAll, fn($r) => $r['secs'] >= $C - 0.005));

// Trim leading words of the new tail that repeat the end of the last kept row.
$lastKept = $keep ? (string)end($keep)['text'] : '';
if ($lastKept !== '' && $newTail) {
    $kt = cfGuardNorm($lastKept);
    $firstToks = preg_split('/\s+/u', trim($newTail[0]['text']));
    $fn = array_map(fn($w) => (cfGuardNorm($w)[0] ?? ''), $firstToks);
    for ($L = min(10, count($kt), count($fn)); $L >= 1; $L--) {
        if (array_slice($kt, -$L) === array_slice($fn, 0, $L)) {
            $rest = trim(implode(' ', array_slice($firstToks, $L)));
            if ($rest === '') array_shift($newTail); else $newTail[0]['text'] = $rest;
            printf("trimmed %d leading word(s) of the new tail that repeat the last kept line\n", $L);
            break;
        }
    }
}
// Speaker for each new row: the old row covering its start time.
$speakerAt = function (float $t) use ($live): ?string {
    $sp = null; foreach ($live as $r) { if ((float)$r['secs'] <= $t + 0.001) $sp = $r['speaker']; else break; } return $sp;
};
foreach ($newTail as &$r) $r['speaker'] = $speakerAt($r['secs']);
unset($r);

// ── report ───────────────────────────────────────────────────────────────────
$streams = cfGuardEngineWords($db, $itemKey, $built['baselines']);
$oldBad = ttUnanchored($oldTail, $streams); $newBad = ttUnanchored($newTail, $streams);
$tok = fn($rows) => array_sum(array_map(fn($r) => count(cfGuardNorm($r['text'])), $rows));
printf("keep %d lines (to %s) | old tail %d lines, %d words, %d failing the first-words check | new tail %d lines, %d words, %d failing\n",
    count($keep), ttHms($C), count($oldTail), $tok($oldTail), count($oldBad), count($newTail), $tok($newTail), count($newBad));
$sameTimes = count($oldTail) - count(array_unique(array_map(fn($r) => round((float)$r['secs'], 2), $oldTail)));
printf("old tail rows sharing a timestamp: %d\n", $sameTimes);
echo "\n-- splice preview --\n";
foreach (array_slice($keep, -3) as $r) printf("  KEEP %s  %s\n", ttHms((float)$r['secs']), $r['text']);
foreach (array_slice($newTail, 0, 4) as $r) printf("  NEW  %s  %s\n", ttHms($r['secs']), $r['text']);
echo "\n-- sample of old tail lines failing the check --\n";
foreach (array_slice($oldBad, 0, 8) as $r) printf("  %s  %s\n", ttHms((float)$r['secs']), mb_substr($r['text'], 0, 90));
if (!$opt['apply']) { echo "\n(dry run — nothing changed; add --apply to swap the tail)\n"; exit(0); }

// ── apply ────────────────────────────────────────────────────────────────────
$snapKey = cfSnapshot($db, $itemKey, null, 'pre tail rebuild (from ' . ttHms($C) . ')');
$t0 = date('c');
$db->beginTransaction();
try {
    $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ? AND feed_item_transcript_segment >= ?::interval")
       ->execute([$itemKey, cfSecsToInterval($C - 0.0005)]);
    $ins = $db->prepare("INSERT INTO yy_feed_item_transcript (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text,
                                                               feed_item_transcript_sort, feed_item_transcript_speaker)
                         VALUES (?, ?::interval, ?, ?, ?)");
    $sort = count($keep);
    foreach ($newTail as $r) $ins->execute([$itemKey, cfSecsToInterval($r['secs']), mb_substr($r['text'], 0, 2000), $sort++, $r['speaker']]);
    // keep sort order = time order across the splice
    $db->prepare("UPDATE yy_feed_item_transcript t SET feed_item_transcript_sort = s.rn - 1
                    FROM (SELECT feed_item_transcript_key k, row_number() OVER (ORDER BY feed_item_transcript_segment, feed_item_transcript_sort) rn
                            FROM yy_feed_item_transcript WHERE feed_item_key = ?) s
                   WHERE t.feed_item_transcript_key = s.k AND t.feed_item_transcript_sort <> s.rn - 1")->execute([$itemKey]);
    $db->commit();
} catch (\Throwable $e) { $db->rollBack(); throw $e; }
printf("applied: snapshot %d, tail swapped from %s (%d → %d lines)\n", $snapKey, ttHms($C), count($oldTail), count($newTail));

if ($opt['llm']) {
    // Abort if a human edits anything in the tail while the LLM runs.
    $chk = $db->prepare("SELECT COUNT(*) FROM yy_transcript_edit_log WHERE feed_item_key = ? AND edit_dtime > ?
                           AND COALESCE(edit_user_key,0) <> 0 AND edit_segment >= ?::interval");
    $abort = function () use ($chk, $itemKey, $t0, $C): bool { $chk->execute([$itemKey, $t0, cfSecsToInterval($C)]); return (int)$chk->fetchColumn() > 0; };
    $llmB = $built['baselines'];
    $w = $built['weights']; if ($w) usort($llmB, fn($a, $b) => ($w[$b] ?? 1.0) <=> ($w[$a] ?? 1.0));
    $rc = llmReconcileTranscript($db, $itemKey, $llmB, 'qwen2.5:72b', null,
                                 ['from_secs' => $C, 'snapshot_reason' => 'pre auto LLM reconcile (tail rebuild)', 'abort_if' => $abort]);
    printf("LLM reconcile on tail: ok=%d changed=%d guarded=%d%s\n", !empty($rc['ok']), $rc['changed'] ?? 0, $rc['guarded'] ?? 0,
        empty($rc['ok']) ? ' error=' . ($rc['error'] ?? '?') : '');
}
$an = cfAnchorFirstWords($db, $itemKey, $built['baselines'], ['apply' => true, 'from_secs' => $C]);
printf("first-word anchor on tail: retimed %d, unanchored %d\n", $an['retimed'], $an['unanchored']);
echo "undo: restore snapshot $snapKey\n";
