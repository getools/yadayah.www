<?php
/**
 * TEST-ONLY consensus rebuild — never touches yy_feed_item_transcript.
 *
 *   php _test_consensus_rebuild.php <item_key> [--break-gap=0.6] [--no-llm] [--no-guard]
 *                                   [--out=/tmp/x.json] [--ping]
 *
 * Reproduces admin-transcript-init-worker.php's consensus path (union spine →
 * buildComparison vote → cfReflow → corrections) from the item's existing
 * _auto baselines, then runs the Layer-3 LLM reconcile IN MEMORY with the
 * proposed engine-support guard (cfReplyEngineSupported) and writes the
 * pre-/post-L3 rows plus every accept/reject decision to a JSON file.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php';
require_once __DIR__ . '/transcript-compare-lib.php';
require_once __DIR__ . '/transcript-caption-lib.php';
require_once __DIR__ . '/gpu-client.php';

$itemKey = (int)($argv[1] ?? 0);
$opt = ['reflow2' => false, 'break_gap' => 0.6, 'llm' => true, 'guard' => true, 'out' => "/tmp/consensus_test_$itemKey.json", 'ping' => false];
foreach (array_slice($argv, 2) as $a) {
    if (strncmp($a, '--break-gap=', 12) === 0) $opt['break_gap'] = (float)substr($a, 12);
    elseif ($a === '--reflow2')  $opt['reflow2'] = true;
    elseif ($a === '--no-llm')   $opt['llm'] = false;
    elseif ($a === '--no-guard') $opt['guard'] = false;
    elseif ($a === '--ping')     $opt['ping'] = true;
    elseif (strncmp($a, '--out=', 6) === 0) $opt['out'] = substr($a, 6);
}
if (!$itemKey) { fwrite(STDERR, "usage: _test_consensus_rebuild.php <item_key> [opts]\n"); exit(1); }
$db = getDb();
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

if ($opt['ping']) {
    $r = gpuLlmChat('qwen2.5:72b', [['role' => 'user', 'content' => 'Reply with the JSON {"ok":true}']],
                    ['json' => true, 'timeout' => 240, 'keep_alive' => '20m']);
    echo json_encode($r['ok'] ? ['ok' => true, 'content' => $r['content']] : $r), "\n";
    exit(0);
}

// ── Engine-support guard (proposed for llmReconcileTranscript) ───────────────
const CFG_FILLERS = ['uh' => 1, 'um' => 1, 'uhm' => 1, 'er' => 1, 'ah' => 1, 'hmm' => 1, 'mm' => 1, 'mhm' => 1, 'huh' => 1];
function cfgNorm(string $s): array {
    $s = mb_strtolower(str_replace(['’', '‘'], "'", $s));
    $s = preg_replace("/[^\\p{L}\\p{N}']+/u", ' ', $s);
    $out = [];
    foreach (preg_split('/\s+/u', trim($s)) as $w) { $w = trim($w, "'"); if ($w !== '') $out[] = $w; }
    return $out;
}
/** Word-level engines' token streams: [code => [[secs, tok], ...]] sorted by time. */
function cfgLoadEngineWords(PDO $db, int $itemKey, array $codes): array {
    $out = [];
    foreach ($codes as $c) {
        if (!str_ends_with($c, '-word')) continue;
        $ws = [];
        foreach (cfLoadAuto($db, $itemKey, $c) as $r) foreach (cfgNorm($r['text']) as $t) $ws[] = [$r['secs'], $t];
        if ($ws) $out[$c] = $ws;
    }
    return $out;
}
function cfgWindowCounts(array $ws, float $lo, float $hi): array {
    $a = 0; $b = count($ws);
    while ($a < $b) { $m = ($a + $b) >> 1; if ($ws[$m][0] < $lo) $a = $m + 1; else $b = $m; }
    $c = [];
    for ($k = $a; $k < count($ws) && $ws[$k][0] < $hi; $k++) $c[$ws[$k][1]] = ($c[$ws[$k][1]] ?? 0) + 1;
    return $c;
}
/**
 * Accept an LLM reply only if it stays faithful to what the word-level engines
 * heard in the line's OWN time window [lo,hi): it may not drop a (non-filler)
 * word that >=2 engines heard unless it substitutes something for it (a
 * spelling/canonical fix), may not drop >2 such words, and may not add >2
 * tokens no engine heard there (a neighbour's text pulled in). Catches the
 * "shift" failure (line i replaced by line i+1's text), pure fragment drops
 * ("Lenin." deleted) and next-line swallowing. Returns [ok, reason].
 */
function tReplyEngineSupported(string $old, string $new, float $lo, float $hi, array $engineWords, float $pad = 0.6): array {
    if (count($engineWords) < 2) return [true, 'no-engines'];
    $sets = [];
    foreach ($engineWords as $ws) $sets[] = cfgWindowCounts($ws, $lo - $pad, $hi + $pad);
    $o = array_count_values(cfgNorm($old)); $n = array_count_values(cfgNorm($new));
    $supDel = 0; $gone = [];
    foreach ($o as $w => $_) {
        if (isset($n[$w]) || isset(CFG_FILLERS[$w])) continue;
        $heard = 0; foreach ($sets as $s) if (!empty($s[$w])) $heard++;
        if ($heard >= 2) { $supDel++; $gone[] = $w; }
    }
    $added = 0; $unsIns = 0; $ins = [];
    foreach ($n as $w => $cnt) {
        if (isset($o[$w])) continue;
        $added++;
        $any = false; foreach ($sets as $s) if (!empty($s[$w])) { $any = true; break; }
        if (!$any) { $unsIns += $cnt; $ins[] = $w; }
    }
    if ($supDel > 2 || $supDel > $added) return [false, 'drops heard: ' . implode(' ', $gone)];
    if ($unsIns > 2) return [false, 'adds unheard: ' . implode(' ', $ins)];
    return [true, ''];
}

/**
 * Proposed cfReflow variant ('reflow2'): identical to cfReflow except
 *  (a) a "pause" is the start-to-start gap MINUS the previous word's estimated
 *      spoken length (chars / cps), so a long word ("exceedingly") no longer
 *      looks like a pause; and
 *  (b) a short sentence-final fragment ("Lenin.") that is followed by a real
 *      pause is attached BACKWARD to the previous cue (when it fits and was
 *      spoken close to it) instead of being carried into the next sentence.
 */
function cfReflow2(array $words, array $o): array {
    $maxChars = max(10, (int)$o['max_chars']); $maxLines = max(1, (int)$o['max_lines']);
    $prefLines = max(1, min($maxLines, (int)($o['preferred_lines'] ?? 1)));
    $maxSecs = (float)$o['max_secs']; $minSecs = (float)$o['min_secs'];
    $breakP = !empty($o['break_punct']); $breakGap = (float)($o['break_gap'] ?? 0);
    $softOver = max(1.0, (float)($o['soft_overflow'] ?? 1.0)); $cps = (float)($o['cps'] ?? 15.0);
    if (!empty($o['dedup'])) $words = cfDedup($words);
    $cap = $maxChars * $maxLines; $prefCap = $maxChars * $prefLines; $softCap = (int)round($cap * $softOver);
    $cues = []; $cur = []; $curStart = null; $prevT = null; $prevW = '';
    $pack = function (array $ws) use ($maxChars) {
        $lines = cfWrap(array_map(fn($x) => $x['w'], $ws), $maxChars); $text = implode("\n", $lines);
        return ['text' => $text, 'chars' => mb_strlen(str_replace("\n", '', $text)), 'lines' => count($lines)];
    };
    $flush = function () use (&$cues, &$cur, &$curStart, $pack) {
        if (!$cur) return;
        $cues[] = ['start' => $curStart, 'words' => $cur] + $pack($cur);
        $cur = []; $curStart = null;
    };
    $fitsWords = function (array $ws) use ($maxChars, $maxLines, $softCap) {
        $tw = array_map(fn($x) => $x['w'], $ws);
        return mb_strlen(implode(' ', $tw)) <= $softCap && count(cfWrap($tw, $maxChars)) <= $maxLines;
    };
    foreach ($words as $wi) {
        if ($cur && $prevT !== null) {
            $pause = $wi['t'] - $prevT - max(0.15, mb_strlen($prevW) / $cps);
            if ($breakGap > 0 && $pause >= $breakGap) {
                $midSentence = !preg_match('/[.?!…,;:]["\')\]\x{201D}\x{2019}]?$/u', $prevW);
                if ($midSentence && count($cur) <= 2) {
                    // hesitation inside a clause ("was a ... clown") — keep going
                } elseif (($prevT - $curStart) >= 0.4) {
                    $flush();
                } elseif (preg_match('/[.?!…]["\')\]\x{201D}\x{2019}]?$/u', $prevW) && $cues) {
                    // (b) orphan sentence-end fragment: glue it onto the previous cue
                    $last = &$cues[count($cues) - 1];
                    $lastEnd = end($last['words'])['t'];
                    $merged = array_merge($last['words'], $cur);
                    if (($curStart - $lastEnd) < $pause && $fitsWords($merged)
                        && ($prevT - $last['start']) <= $maxSecs) {
                        $last = ['start' => $last['start'], 'words' => $merged] + $pack($merged);
                        $cur = []; $curStart = null;
                    } else {
                        unset($last); $flush();
                    }
                    unset($last);
                }
            }
        }
        if ($curStart === null) $curStart = $wi['t'];
        $trial = $cur; $trial[] = $wi;
        $overTime = ($wi['t'] - $curStart) > $maxSecs;
        if ($cur && (!$fitsWords($trial) || $overTime)) { $flush(); $curStart = $wi['t']; $cur = [$wi]; }
        else $cur = $trial;
        if ($breakP && preg_match('/[.?!…।,;:]["\')\]\x{201D}\x{2019}]?$/u', $wi['w'])) {
            $curText = implode(' ', array_map(fn($x) => $x['w'], $cur));
            $sentenceEnd = (bool)preg_match('/[.?!…।]["\')\]\x{201D}\x{2019}]?$/u', $wi['w']);
            if (($sentenceEnd && (mb_strlen($curText) >= $prefCap * 0.4 || ($wi['t'] - $curStart) >= $minSecs))
                || mb_strlen($curText) >= $prefCap * 0.85) $flush();
        }
        $prevT = $wi['t']; $prevW = $wi['w'];
    }
    $flush();
    return array_map(fn($c) => ['start' => $c['start'], 'text' => $c['text'], 'chars' => $c['chars'], 'lines' => $c['lines']], $cues);
}

// ── 1. Consensus build (mirrors admin-transcript-init-worker.php) ────────────
$jq = $db->prepare("SELECT job_params FROM yy_feed_item_transcript_init_job WHERE job_item_key=? AND job_model='consensus' ORDER BY job_key DESC LIMIT 1");
$jq->execute([$itemKey]);
$jp = json_decode((string)$jq->fetchColumn(), true) ?: [];
$avail = array_column(cfAutoModels($db, $itemKey), 'code');
$baselines = array_values(array_intersect((array)($jp['baselines'] ?? $avail), $avail));
$params = (array)($jp['params'] ?? []);
$deny = cfDeniedEngines($db);
$kept = array_values(array_diff($baselines, $deny));
if (count($kept) >= 2) $baselines = $kept;
$pref = ['gpu-whisperx-word', 'gpu-whisper-large-v3-word', 'gpu-whisper-large-v3-turbo-word', 'gpu-parakeet-tdt-0.6b-v2-word'];
$spine = null;
foreach ($pref as $c) if (in_array($c, $baselines, true)) { $spine = $c; break; }
$refs = array_values(array_diff($baselines, [$spine]));
$weights = cfEngineWeights($db);
$filled = [];
$spineWords = unionSpineWords($db, $itemKey, $spine, $baselines, $filled, 8.0, 3, null, $weights);
$cmp = buildComparison($db, $itemKey, $spine, $refs, $spineWords, $weights);
if (isset($cmp['error'])) { fwrite(STDERR, $cmp['error'] . "\n"); exit(1); }
$stream = [];
foreach ($cmp['slots'] as $sl) { $w = trim((string)$sl['consensus']); if ($w !== '') $stream[] = ['t' => $sl['t'], 'w' => $w]; }
$ropts = [
    'max_chars' => (int)($params['max_chars'] ?? 42), 'max_lines' => (int)($params['max_lines'] ?? 2),
    'preferred_lines' => (int)($params['preferred_lines'] ?? 1), 'max_secs' => (float)($params['max_secs'] ?? 7.0),
    'min_secs' => (float)($params['min_secs'] ?? 1.2),
    'break_punct' => array_key_exists('break_punct', $params) ? (bool)$params['break_punct'] : true,
    'break_gap' => $opt['break_gap'], 'soft_overflow' => max(1.0, (float)($params['soft_overflow'] ?? 1.5)),
    'dedup' => array_key_exists('dedup', $params) ? (bool)$params['dedup'] : true,
];
$cues = $opt['reflow2'] ? cfReflow2($stream, $ropts) : cfReflow($stream, $ropts);
$crows = [];
foreach ($cues as $cue) $crows[] = ['segment' => cfSecsToInterval((float)$cue['start']), 'text' => str_replace("\n", ' ', (string)$cue['text'])];
$rows = applyCorrectionsAcrossRows($db, $crows);
$live = [];
foreach (array_values($rows) as $i => $r) $live[] = ['key' => $i, 'segment' => $r['segment'], 'secs' => cfIntervalToSecs($r['segment']), 'text' => (string)$r['text']];
$pre = array_map(fn($r) => ['segment' => $r['segment'], 'text' => $r['text']], $live);
fwrite(STDERR, sprintf("built %d rows, spine=%s, refs=%s, union-fill=%d, break_gap=%.2f\n",
    count($live), $spine, implode(',', $refs), count($filled), $opt['break_gap']));

// ── 2. Layer-3 LLM reconcile, in memory, with the guard ──────────────────────
$decisions = [];
if ($opt['llm']) {
    $engineWords = cfgLoadEngineWords($db, $itemKey, $baselines);
    $llmBaselines = $baselines;
    if ($weights) usort($llmBaselines, fn($a, $b) => ($weights[$b] ?? 1.0) <=> ($weights[$a] ?? 1.0));
    $alignByModel = [];
    foreach ($llmBaselines as $code) {
        $brows = cfLoadAuto($db, $itemKey, $code);
        $alignByModel[$code] = cfAlignBaselineToLive($live, $brows, cfEstimateShift($live, $brows));
    }
    $ctx = cfCorrectionContext($db);
    $total = count($live); $limit = 40;
    for ($offset = 0; $offset < $total; $offset += $limit) {
        $slice = array_slice($live, $offset, $limit);
        $lines = [];
        foreach ($slice as $idx => $r) {
            $g = $offset + $idx; $alts = [];
            foreach ($llmBaselines as $code) {
                $t = $alignByModel[$code][$g] ?? '';
                if ($t !== '' && $t !== $r['text'] && cfAltLooksAligned($r['text'], $t)) $alts[] = ['engine' => $code, 'text' => mb_substr($t, 0, 400)];
            }
            $lines[] = ['i' => $idx, 'text' => $r['text'], 'old' => $r['text'], 'alts' => $alts, 'g' => $g];
        }
        $resp = gpuLlmChat('qwen2.5:72b', cfBuildMessages($ctx, $lines),
            ['json' => true, 'temperature' => 0.1, 'timeout' => 280, 'keep_alive' => '20m', 'num_ctx' => 16384]);
        if (empty($resp['ok'])) { fwrite(STDERR, "LLM error at $offset: " . ($resp['error'] ?? '?') . "\n"); break; }
        $parsed = json_decode((string)$resp['content'], true); $byIdx = [];
        foreach ((array)($parsed['lines'] ?? []) as $l) if (isset($l['i']) && array_key_exists('text', $l)) $byIdx[(int)$l['i']] = (string)$l['text'];
        foreach ($lines as $pos => $l) {
            $new = array_key_exists($l['i'], $byIdx) ? trim($byIdx[$l['i']]) : $l['old'];
            if ($new === '' || $new === $l['old']) continue;
            $g = $l['g'];
            $why = '';
            if ($pos + 1 < count($lines) && cfReplySwallowsNext($new, $l['old'], (string)$lines[$pos + 1]['old'])) $why = 'swallows next (existing guard)';
            elseif ($opt['guard']) {
                $hi = isset($live[$g + 1]) ? $live[$g + 1]['secs'] : $live[$g]['secs'] + 6;
                [$ok, $reason] = tReplyEngineSupported($l['old'], $new, $live[$g]['secs'], min($hi, $live[$g]['secs'] + 12), $engineWords);
                if (!$ok) $why = $reason;
            }
            $decisions[] = ['segment' => $live[$g]['segment'], 'old' => $l['old'], 'new' => $new, 'accepted' => $why === '', 'reason' => $why];
            if ($why === '') $live[$g]['text'] = $new;
        }
        fwrite(STDERR, sprintf("llm %d/%d decisions=%d\n", min($offset + $limit, $total), $total, count($decisions)));
    }
}
file_put_contents($opt['out'], json_encode([
    'item' => $itemKey, 'opts' => $opt, 'spine' => $spine, 'baselines' => $baselines,
    'pre' => $pre, 'post' => array_map(fn($r) => ['segment' => $r['segment'], 'text' => $r['text']], $live),
    'decisions' => $decisions,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fwrite(STDERR, "wrote {$opt['out']}\n");
