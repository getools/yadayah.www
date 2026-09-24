<?php
/** _timing_preview.php <item> [--from=SECS] [--n=8] [--keep-size|--max-chars=N ...]
 *  Prints the CURRENT cues and the proposed cues for a time window, side by side. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-caption-lib.php';

$ik = 0; $from = 0.0; $n = 8; $keep = false; $ov = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--keep-size') $keep = true;
    elseif (strncmp($a, '--from=', 7) === 0) $from = (float)substr($a, 7);
    elseif (strncmp($a, '--n=', 4) === 0) $n = (int)substr($a, 4);
    elseif (strncmp($a, '--max-chars=', 12) === 0) $ov['max_chars'] = (int)substr($a, 12);
    elseif (strncmp($a, '--max-lines=', 12) === 0) $ov['max_lines'] = (int)substr($a, 12);
    elseif (strncmp($a, '--pref-lines=', 13) === 0) $ov['preferred_lines'] = (int)substr($a, 13);
    elseif (strncmp($a, '--max-secs=', 11) === 0) $ov['max_secs'] = (float)substr($a, 11);
    elseif ($a[0] !== '-') $ik = (int)$a;
}
$db = getDb();
$live = cfLoadLive($db, $ik);
$bl = cfBestWordBaseline($db, $ik);
$stream = $bl ? cfBaselineWordStream($db, $ik, $bl) : [];
$opts = cfDefaults();
if ($keep) {
    $cl = []; foreach ($live as $r) $cl[] = mb_strlen($r['text']);
    sort($cl); $m = (int)$cl[intdiv(count($cl), 2)];
    $opts['max_chars'] = max(28, min(140, $m)); $opts['max_lines'] = 1; $opts['preferred_lines'] = 1;
}
foreach ($ov as $k => $v) $opts[$k] = $v;
$words = cfRowsToWords($live, ['cps' => (float)$opts['cps']]);
$cues = cfReflow($words, $opts);
$snap = [];
if ($stream) $cues = cfSnapStartsToBaseline($cues, $stream, $snap);

echo "── CURRENT ──────────────────────────────────────────────\n";
$c = 0;
foreach ($live as $r) { if ($r['secs'] < $from) continue; if ($c++ >= $n) break;
    printf("%s  %s\n", $r['segment'], $r['text']); }
echo "── PROPOSED (max_chars={$opts['max_chars']} lines={$opts['preferred_lines']}/{$opts['max_lines']} max_secs={$opts['max_secs']}) ──\n";
$c = 0;
foreach ($cues as $q) { if ($q['start'] < $from) continue; if ($c++ >= $n * 2) break;
    printf("%s  %s\n", cfSecsToInterval((float)$q['start']), str_replace("\n", ' ', $q['text'])); }
