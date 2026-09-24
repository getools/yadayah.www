<?php
/**
 * _timing_verify.php — independent accuracy check for the transcript
 * timing/sizing pass. Two modes, both usable before and after an --apply:
 *
 *   A. cross-baseline  : score the LIVE cue starts against a word baseline that
 *                        was NOT used to snap them (--model=CODE). Reports the
 *                        median / p90 |cue start - baseline word time|.
 *
 *   B. audio spot check: --clips=N cuts N real audio windows out of the item's
 *                        mp3 AT the stored cue start and re-transcribes each
 *                        window on the GPU box. If the timestamp is right, the
 *                        clip's transcript STARTS with the cue's first words.
 *                        Reports per-clip head overlap + the measured onset
 *                        offset (how far into the clip speech actually starts).
 *
 *   php _timing_verify.php <keys|--list=F> [--model=CODE] [--clips=N]
 *       [--clip-secs=6] [--lead=1.0] [--engine=/stt-whisperx/transcribe] [--csv=F]
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-caption-lib.php';
require_once __DIR__ . '/gpu-client.php';

$items = []; $model = ''; $clips = 0; $clipSecs = 6.0; $lead = 1.0; $csv = '';
$engine = '/stt-whisperx/transcribe'; $seed = 1234;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--stdin') { foreach (preg_split('/\s+/', (string)stream_get_contents(STDIN)) as $t) if ($t !== '') $items[] = (int)$t; }
    elseif (strncmp($a, '--list=', 7) === 0) { foreach (preg_split('/\s+/', (string)@file_get_contents(substr($a, 7))) as $t) if ($t !== '') $items[] = (int)$t; }
    elseif (strncmp($a, '--model=', 8) === 0) $model = substr($a, 8);
    elseif (strncmp($a, '--clips=', 8) === 0) $clips = (int)substr($a, 8);
    elseif (strncmp($a, '--clip-secs=', 12) === 0) $clipSecs = (float)substr($a, 12);
    elseif (strncmp($a, '--lead=', 7) === 0) $lead = (float)substr($a, 7);
    elseif (strncmp($a, '--engine=', 9) === 0) $engine = substr($a, 9);
    elseif (strncmp($a, '--seed=', 7) === 0) $seed = (int)substr($a, 7);
    elseif (strncmp($a, '--csv=', 6) === 0) $csv = substr($a, 6);
    elseif ($a[0] !== '-') $items[] = (int)$a;
}
$items = array_values(array_unique(array_filter($items)));
if (!$items) { fwrite(STDERR, "usage: _timing_verify.php <keys|--list=F> [--model=CODE] [--clips=N]\n"); exit(1); }

function vmed(array $v): float { if (!$v) return 0.0; sort($v); $n = count($v); return $n % 2 ? (float)$v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2.0; }
function vp90(array $v): float { if (!$v) return 0.0; sort($v); return (float)$v[(int)floor(0.9 * (count($v) - 1))]; }

/** median/p90 |cue start - baseline time of the cue's first word| (trigram anchored). */
function crossError(array $cues, array $stream): array {
    $tok = []; $bt = [];
    foreach ($stream as $s) foreach (cfWords($s['w']) as $w) { $tok[] = $w; $bt[] = (float)$s['t']; }
    $n = count($tok); $R = count($cues);
    if ($n < 5 || $R < 3) return ['err' => null, 'p90' => null, 'n' => 0, 'rate' => 0.0];
    $lw = []; $cueOff = [];
    foreach ($cues as $c) { $cueOff[] = count($lw); foreach (cfWords((string)$c['text']) as $w) $lw[] = $w; }
    $M = count($lw); if ($M < 4) return ['err' => null, 'p90' => null, 'n' => 0, 'rate' => 0.0];
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
    foreach ($cues as $i => $c) { $off = $cueOff[$i]; if (!isset($cand[$off])) continue; $hit++; $errs[] = abs((float)$c['start'] - $bt[$cand[$off]]); }
    return ['err' => $errs ? vmed($errs) : null, 'p90' => $errs ? vp90($errs) : null,
            'n' => count($errs), 'rate' => $R ? $hit / $R : 0.0];
}

$db = getDb();
$fh = $csv ? fopen($csv, 'w') : null;
if ($fh) fputcsv($fh, ['item', 'mode', 'model', 'rows', 'anchored', 'err_med', 'err_p90', 'clips_ok', 'clips_n', 'onset_med']);

foreach ($items as $ik) {
    $live = cfLoadLive($db, $ik);
    if (count($live) < 3) { echo "item $ik: no live rows\n"; continue; }
    $cues = array_map(fn($r) => ['start' => (float)$r['secs'], 'text' => $r['text']], $live);

    // ── A. cross-baseline ────────────────────────────────────────────────
    $use = $model;
    if ($use === '') {
        $snapped = cfBestWordBaseline($db, $ik);
        foreach (['gpu-parakeet-tdt-0.6b-v2-word', 'gpu-whisper-large-v3-word', 'gpu-whisperx-word'] as $m) {
            if ($m === $snapped) continue;
            $c = $db->prepare("SELECT 1 FROM yy_feed_item_transcript_auto WHERE feed_item_key=? AND feed_item_transcript_auto_model=? LIMIT 1");
            $c->execute([$ik, $m]); if ($c->fetchColumn()) { $use = $m; break; }
        }
    }
    $x = ['err' => null, 'p90' => null, 'n' => 0, 'rate' => 0.0];
    if ($use !== '') {
        $stream = cfBaselineWordStream($db, $ik, $use);
        if (count($stream) >= 20) $x = crossError($cues, $stream);
    }
    printf("item %-8d rows=%-5d  cross-baseline=%-28s anchored=%d (%.0f%%)  err med=%s p90=%s\n",
        $ik, count($cues), $use ?: '(none)', $x['n'], $x['rate'] * 100,
        $x['err'] === null ? '-' : number_format($x['err'], 3), $x['p90'] === null ? '-' : number_format($x['p90'], 3));

    // ── B. audio spot check ──────────────────────────────────────────────
    $okN = 0; $tot = 0; $onsets = [];
    if ($clips > 0) {
        $st = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key=?");
        $st->execute([$ik]); $af = (string)$st->fetchColumn();
        $path = '/var/www/html/' . ltrim($af, '/');
        if (!is_file($path)) { echo "  audio missing: $path\n"; }
        else {
            mt_srand($seed + $ik);
            $n = count($cues);
            $picks = [];
            // sample evenly across the item, skipping the first/last 5%
            $lo = (int)floor($n * 0.05); $hi = (int)floor($n * 0.95);
            for ($i = 0; $i < $clips && $hi > $lo; $i++) $picks[] = $lo + (int)floor(($hi - $lo) * ($i + 0.5) / $clips);
            foreach (array_unique($picks) as $idx) {
                $cue = $cues[$idx];
                $start = max(0.0, (float)$cue['start'] - $lead);
                $tmp = sys_get_temp_dir() . "/vfy_{$ik}_{$idx}.wav";
                $cmd = sprintf('ffmpeg -nostdin -loglevel error -y -ss %.3f -t %.3f -i %s -ac 1 -ar 16000 %s 2>&1',
                               $start, $clipSecs, escapeshellarg($path), escapeshellarg($tmp));
                @exec($cmd, $o, $rc);
                if ($rc !== 0 || !is_file($tmp)) { echo "  clip $idx: ffmpeg failed\n"; continue; }
                $r = gpuTranscribe($tmp, ['path' => $engine, 'word_timestamps' => true, 'vad_filter' => false, 'timeout' => 180]);
                @unlink($tmp);
                if (empty($r['ok'])) { echo "  clip $idx: STT failed " . substr(json_encode($r['error'] ?? $r), 0, 120) . "\n"; continue; }
                $heard = (string)($r['data']['text'] ?? '');
                // Fresh word stream measured from the AUDIO ITSELF (this clip only).
                $cw = []; $ct = [];
                foreach (($r['data']['segments'] ?? []) as $sg) {
                    if (!empty($sg['words'])) {
                        foreach ($sg['words'] as $ww) {
                            foreach (cfWords((string)($ww['word'] ?? '')) as $t) { $cw[] = $t; $ct[] = (float)($ww['start'] ?? 0); }
                        }
                    } else {
                        foreach (cfWords((string)($sg['text'] ?? '')) as $t) { $cw[] = $t; $ct[] = (float)($sg['start'] ?? 0); }
                    }
                }
                $exp = array_slice(cfWords((string)$cue['text']), 0, 6);
                $got = array_slice($cw, 0, 14);
                $hitN = 0; foreach ($exp as $w) if (in_array($w, $got, true)) $hitN++;
                $ok = count($exp) ? ($hitN / count($exp)) >= 0.5 : false;
                $tot++; if ($ok) $okN++;

                // TRUE cue-start error: where in this clip does the cue's own first
                // word actually begin? Match the longest of the cue's leading
                // bigram/unigram in the clip stream, take its time, subtract the lead.
                $offset = null;
                if (count($exp) >= 2) {
                    for ($j = 0; $j + 1 < count($cw); $j++) {
                        if ($cw[$j] === $exp[0] && $cw[$j + 1] === $exp[1]) { $offset = $ct[$j] - $lead; break; }
                    }
                }
                if ($offset === null && $exp) {
                    for ($j = 0; $j < count($cw); $j++) if ($cw[$j] === $exp[0]) { $offset = $ct[$j] - $lead; break; }
                }
                if ($offset !== null) $onsets[] = $offset;
                printf("  clip @%-12s %s  head %d/%d  start err %s  exp[%s] got[%s]\n",
                    cfSecsToInterval((float)$cue['start']), $ok ? 'OK ' : 'MISS',
                    $hitN, count($exp),
                    $offset === null ? '   n/a' : sprintf('%+0.2fs', $offset),
                    implode(' ', array_slice($exp, 0, 5)), implode(' ', array_slice($got, 0, 7)));
            }
            printf("  spot check: %d/%d cues matched; measured start error |med|=%0.2fs (signed med %+0.2fs, n=%d)\n",
                   $okN, $tot, $onsets ? vmed(array_map('abs', $onsets)) : 0, $onsets ? vmed($onsets) : 0, count($onsets));
        }
    }
    if ($fh) fputcsv($fh, [$ik, $clips > 0 ? 'cross+clips' : 'cross', $use, count($cues), $x['n'],
                           $x['err'], $x['p90'], $okN, $tot, $onsets ? round(vmed($onsets), 3) : '']);
}
if ($fh) fclose($fh);
