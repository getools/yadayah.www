<?php
/**
 * _timing_reflow.php — batch "segment size + timestamp accuracy" pass over
 * untouched mp3 transcripts.
 *
 *   php _timing_reflow.php <item_key...> | --list=F | --stdin
 *        [--apply] [--max-chars=N] [--max-lines=N] [--pref-lines=N]
 *        [--max-secs=F] [--min-secs=F] [--break-gap=F] [--soft=F]
 *        [--keep-size] [--csv=/path.csv] [--quiet]
 *
 * For each item it re-flows the LIVE text (text is preserved verbatim, only the
 * cue boundaries move) over a word-level baseline's timings, then snaps every
 * cue start onto that baseline (cfSnapStartsToBaseline, fail-safe).
 *
 * Reports, per item, before/after:
 *   rows, % integer-second starts, duplicate starts, median cue gap,
 *   median cue chars, and ERR = median |cue start - baseline time of the cue's
 *   first word| in seconds (the objective timestamp-accuracy number).
 *
 * --keep-size derives max_chars from the item's CURRENT median cue length so the
 * transcript keeps its present granularity and only the timing/boundaries move.
 * --apply snapshots first (yy_transcript_snapshot) so every item is revertible.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-caption-lib.php';

$items = []; $apply = false; $quiet = false; $csv = ''; $keepSize = false; $ov = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') $apply = true;
    elseif ($a === '--quiet') $quiet = true;
    elseif ($a === '--keep-size') $keepSize = true;
    elseif ($a === '--stdin') { foreach (preg_split('/\s+/', (string)stream_get_contents(STDIN)) as $t) if ($t !== '') $items[] = (int)$t; }
    elseif (strncmp($a, '--list=', 7) === 0) { foreach (preg_split('/\s+/', (string)@file_get_contents(substr($a, 7))) as $t) if ($t !== '') $items[] = (int)$t; }
    elseif (strncmp($a, '--csv=', 6) === 0) $csv = substr($a, 6);
    elseif (strncmp($a, '--max-chars=', 12) === 0) $ov['max_chars'] = (int)substr($a, 12);
    elseif (strncmp($a, '--max-lines=', 12) === 0) $ov['max_lines'] = (int)substr($a, 12);
    elseif (strncmp($a, '--pref-lines=', 13) === 0) $ov['preferred_lines'] = (int)substr($a, 13);
    elseif (strncmp($a, '--max-secs=', 11) === 0) $ov['max_secs'] = (float)substr($a, 11);
    elseif (strncmp($a, '--min-secs=', 11) === 0) $ov['min_secs'] = (float)substr($a, 11);
    elseif (strncmp($a, '--break-gap=', 12) === 0) $ov['break_gap'] = (float)substr($a, 12);
    elseif (strncmp($a, '--soft=', 7) === 0) $ov['soft_overflow'] = (float)substr($a, 7);
    elseif ($a[0] !== '-') $items[] = (int)$a;
}
$items = array_values(array_unique(array_filter($items)));
if (!$items) { fwrite(STDERR, "usage: _timing_reflow.php <keys|--list=F|--stdin> [--apply] [--keep-size] [--csv=F]\n"); exit(1); }

function med(array $v): float { if (!$v) return 0.0; sort($v); $n = count($v); return $n % 2 ? (float)$v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2.0; }
function p90(array $v): float { if (!$v) return 0.0; sort($v); return (float)$v[(int)floor(0.9 * (count($v) - 1))]; }

/** Median |cue start - baseline time of the cue's first word| over trigram-anchored
 *  cues. Scoring only — deliberately independent of cfSnapStartsToBaseline. */
function timingError(array $cues, array $stream): array {
    $tok = []; $bt = [];
    foreach ($stream as $s) foreach (cfWords($s['w']) as $w) { $tok[] = $w; $bt[] = (float)$s['t']; }
    $n = count($tok); $R = count($cues);
    if ($n < 5 || $R < 3) return ['err' => null, 'p90' => null, 'rate' => 0.0, 'n' => 0];
    $lw = []; $cueOff = [];
    foreach ($cues as $c) { $cueOff[] = count($lw); foreach (cfWords((string)$c['text']) as $w) $lw[] = $w; }
    $M = count($lw);
    if ($M < 4) return ['err' => null, 'p90' => null, 'rate' => 0.0, 'n' => 0];
    $scale = $n / $M; $cand = []; $pt = 0.0; $W = 150;
    for ($w = 0; $w < $M - 2; $w++) {
        $exp = (int)round($pt); $lo = max(0, $exp - $W); $hi = min($n - 2, $exp + $W);
        $best = -1; $bd = PHP_INT_MAX;
        for ($j = $lo; $j < $hi; $j++) {
            if ($tok[$j] !== $lw[$w] || $tok[$j + 1] !== $lw[$w + 1] || $tok[$j + 2] !== $lw[$w + 2]) continue;
            $d = abs($j - $exp); if ($d < $bd) { $bd = $d; $best = $j; }
        }
        if ($best >= 0) { $cand[$w] = $best; if (abs($best - $exp) <= 40) $pt = $best; }
        $pt += $scale;
    }
    $errs = []; $hit = 0;
    foreach ($cues as $i => $c) {
        $off = $cueOff[$i];
        if (!isset($cand[$off])) continue;
        $hit++; $errs[] = abs((float)$c['start'] - $bt[$cand[$off]]);
    }
    return ['err' => $errs ? med($errs) : null, 'p90' => $errs ? p90($errs) : null,
            'rate' => $R ? $hit / $R : 0.0, 'n' => count($errs)];
}

function stats(array $cues): array {
    $n = count($cues); $ints = 0; $starts = []; $gaps = []; $chars = [];
    for ($i = 0; $i < $n; $i++) {
        $s = round((float)$cues[$i]['start'], 3);
        if (abs($s - round($s)) < 0.0005) $ints++;
        $starts[] = $s;
        if ($i + 1 < $n) $gaps[] = round((float)$cues[$i + 1]['start'], 3) - $s;
        $chars[] = mb_strlen(str_replace("\n", ' ', (string)$cues[$i]['text']));
    }
    return ['n' => $n, 'pct_int' => $n ? round(100.0 * $ints / $n, 1) : 0.0,
            'dups' => $n - count(array_unique($starts)),
            'med_gap' => round(med($gaps), 2), 'med_chars' => round(med($chars), 0),
            'max_chars' => $chars ? max($chars) : 0];
}

$db = getDb();
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

$fh = $csv ? fopen($csv, 'w') : null;
if ($fh) fputcsv($fh, ['item', 'baseline', 'bl_words', 'rows_before', 'rows_after', 'pct_int_before', 'pct_int_after',
                       'dups_before', 'dups_after', 'err_before', 'err_after', 'p90_before', 'p90_after',
                       'medgap_before', 'medgap_after', 'medchars_before', 'medchars_after', 'snap_rate', 'status']);
$done = 0; $skip = [];
foreach ($items as $ik) {
    $live = cfLoadLive($db, $ik);
    if (count($live) < 3) { $skip[] = "$ik (no live rows)"; if ($fh) fputcsv($fh, [$ik, '', '', count($live), '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'no-live']); continue; }
    $bl = cfBestWordBaseline($db, $ik);
    if (!$bl) { $skip[] = "$ik (no word baseline)"; if ($fh) fputcsv($fh, [$ik, '', '', count($live), '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'no-baseline']); continue; }
    $stream = cfBaselineWordStream($db, $ik, $bl);
    if (count($stream) < 20) { $skip[] = "$ik (thin baseline $bl)"; if ($fh) fputcsv($fh, [$ik, $bl, count($stream), count($live), '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'thin-baseline']); continue; }

    $opts = cfDefaults();
    if ($keepSize) {
        $cl = []; foreach ($live as $r) $cl[] = mb_strlen($r['text']);
        $m = (int)round(med($cl));
        $opts['max_chars'] = max(28, min(140, $m));
        $opts['max_lines'] = 1; $opts['preferred_lines'] = 1;
    }
    foreach ($ov as $k => $v) $opts[$k] = $v;

    $before = array_map(fn($r) => ['start' => (float)$r['secs'], 'text' => $r['text']], $live);
    $bstat  = stats($before);
    $berr   = timingError($before, $stream);

    $words = cfRowsToWords($live, ($opts['break_gap'] > 0) ? ['cps' => (float)$opts['cps']] : []);
    $cues  = cfReflow($words, $opts);
    $snap  = ['applied' => false];
    $cues  = cfSnapStartsToBaseline($cues, $stream, $snap);
    $astat = stats($cues);
    $aerr  = timingError($cues, $stream);

    $spkTl = cfSpeakerTimeline($live);
    $status = 'dry';
    if ($apply) {
        if ($berr['err'] !== null && $aerr['err'] !== null && $aerr['err'] > $berr['err'] + 0.05) $status = 'refused-worse';
        elseif (!$cues) $status = 'refused-empty';
        else {
            $out = [];
            foreach ($cues as $c) $out[] = ['segment' => cfSecsToInterval((float)$c['start']),
                                            'text'    => str_replace("\n", ' ', (string)$c['text']),
                                            'speaker' => $spkTl ? cfSpeakerAt($spkTl, (float)$c['start']) : null];
            cfSnapshot($db, $ik, null, 'timing+sizing pass (word-baseline retime/reflow)');
            $ins = cfReplaceLive($db, $ik, $out);
            $status = 'applied:' . $ins; $done++;
        }
    }
    if (!$quiet) printf("item %-8d %-26s rows %5d->%-5d  int%% %5.1f->%-5.1f  dup %4d->%-4d  ERR %6s->%-6s (p90 %6s->%-6s)  gap %5.2f->%-5.2f  chars %3d->%-3d  snap=%s %s\n",
        $ik, $bl, $bstat['n'], $astat['n'], $bstat['pct_int'], $astat['pct_int'], $bstat['dups'], $astat['dups'],
        $berr['err'] === null ? '-' : number_format($berr['err'], 3), $aerr['err'] === null ? '-' : number_format($aerr['err'], 3),
        $berr['p90'] === null ? '-' : number_format($berr['p90'], 3), $aerr['p90'] === null ? '-' : number_format($aerr['p90'], 3),
        $bstat['med_gap'], $astat['med_gap'], $bstat['med_chars'], $astat['med_chars'],
        $snap['applied'] ? 'y(' . ($snap['rate'] ?? 0) . ')' : 'n', $status);
    if ($fh) fputcsv($fh, [$ik, $bl, count($stream), $bstat['n'], $astat['n'], $bstat['pct_int'], $astat['pct_int'],
        $bstat['dups'], $astat['dups'], $berr['err'], $aerr['err'], $berr['p90'] ?? '', $aerr['p90'] ?? '',
        $bstat['med_gap'], $astat['med_gap'], $bstat['med_chars'], $astat['med_chars'], $snap['rate'] ?? 0, $status]);
}
if ($fh) fclose($fh);
if (!$quiet) {
    echo "----\n";
    printf("%s: %d item(s) written.\n", $apply ? 'APPLIED' : 'DRY-RUN', $done);
    if ($skip) echo "skipped:\n  - " . implode("\n  - ", $skip) . "\n";
}
