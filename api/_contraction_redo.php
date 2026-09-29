<?php
/**
 * Surgical per-paragraph re-synth of paragraphs containing English
 * contractions (2026-09-29). Before the English-dictionary apostrophe check in
 * substituteTunes (ttsTuneFlexesEnglishWord), apostrophe-free tunes hijacked
 * contractions: tune 214 "im" turned every "I’m" into "im", 489 "shed" took
 * "she’d", 818 "lets" took "let’s".
 *
 * Part-index logic is copied verbatim from _word_redo.php (mirrors the build
 * worker / status endpoint: NO paragraph_active_flag filter, continuations
 * coalesced, tables / skip-pages / back-matter dropped). Deletes only the
 * matched paragraphs' own p%05d.mp3 and reflags complete chapters to 'pending'
 * so the worker gap-fills exactly those clips. Paused (held) chapters get their
 * parts deleted only; they stay paused.
 *
 *   php _contraction_redo.php --mode=affected            # dry-run: only hijacked contractions
 *   php _contraction_redo.php --mode=all                 # dry-run: every contraction
 *   php _contraction_redo.php --mode=all --ak=NNN        # one chapter
 *   php _contraction_redo.php --mode=all --apply         # do it
 *   add --quiet to print totals only
 *
 * "Contraction" = a word in api/data/english-apostrophe-words.txt ending in
 * n't / 'm / 're / 've / 'll / 'd, or a pronoun-ish 's (it's, that's, let's,
 * he's …). Plain possessives (God's, Isaac's) are NOT contractions.
 * MUST run in the container.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$apply  = in_array('--apply', $argv, true);
$quiet  = in_array('--quiet', $argv, true);
$mode   = null;
$onlyAk = null;
foreach ($argv as $a) {
    if (preg_match('/^--mode=(affected|all)$/', $a, $m)) $mode = $m[1];
    if (preg_match('/^--ak=(\d+)$/', $a, $m))            $onlyAk = (int)$m[1];
}
if ($mode === null) { fwrite(STDERR, "--mode=affected|all required\n"); exit(1); }

$db    = getDb();
$audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
if (!is_dir($audioBase . '/u/tts-parts')) {
    fwrite(STDERR, "ABORT: no tts-parts under {$audioBase}/u -- run INSIDE the web container.\n");
    exit(1);
}

// Candidate words: letters + English apostrophe + letters. Half-rings are not English apostrophes.
$WORD_RE = "/(?<![\\p{L}\\p{N}'\x{2019}\x{2018}\x{02BC}`\x{00B4}\x{02BE}\x{02BF}])"
         . "\\p{L}+(?:['\x{2019}\x{2018}\x{02BC}`\x{00B4}]\\p{L}+)+"
         . "(?![\\p{L}\\p{N}])/u";
$S_BASES = ['it','that','there','here','what','where','who','how','why','when','let','he','she',
            'this','everyone','everybody','someone','somebody','nobody','one'];
$isContraction = function (string $w) use ($S_BASES): bool {
    if (!ttsIsEnglishApostropheWord($w)) return false;
    $n = str_replace(["\u{2019}", "\u{2018}", "\u{02BC}", '`', "\u{00B4}"], "'", mb_strtolower($w, 'UTF-8'));
    if (preg_match("/(?:n't|'m|'re|'ve|'ll|'d)$/", $n)) return true;
    if (preg_match("/^(.+)'s$/", $n, $mm)) return in_array($mm[1], $S_BASES, true);
    return false;
};

// affected mode: cores of active tunes whose Print has no apostrophe (the ones
// that could flex into a contraction). "I’m" → core "im" → tune 214.
$tuneCores = [];
if ($mode === 'affected') {
    foreach ($db->query("SELECT tts_tune_print FROM yy_tts_tune WHERE tts_tune_active_flag") as $t) {
        $p = (string)$t['tts_tune_print'];
        if ($p === '' || preg_match('/[' . TTS_APOS_CHARS . ']/u', $p)) continue;
        $tuneCores[mb_strtolower($p, 'UTF-8')] = true;
    }
}
$paraMatches = function (string $plain) use ($WORD_RE, $isContraction, $mode, $tuneCores): ?string {
    if (!preg_match_all($WORD_RE, $plain, $mm)) return null;
    foreach ($mm[0] as $w) {
        if (!$isContraction($w)) continue;
        if ($mode === 'all') return $w;
        $core = mb_strtolower(preg_replace('/[' . TTS_APOS_CHARS . ']/u', '', $w), 'UTF-8');
        if (isset($tuneCores[$core])) return $w;
    }
    return null;
};

$sql = "SELECT tts_audio_key, chapter_key, volume_key, tts_audio_status
          FROM yy_tts_audio
         WHERE tts_audio_active_flag AND tts_audio_status IN ('complete','paused','pending')
           AND tts_audio_status <> 'running'";
if ($onlyAk) $sql .= " AND tts_audio_key = " . $onlyAk;
$sql .= " ORDER BY tts_audio_key";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$totAudio = 0; $totParas = 0; $totParts = 0; $reflagged = 0; $primedPending = 0; $primedPaused = 0;
$byWord = []; $vols = []; $staleSkipped = [];

foreach ($rows as $a) {
    $audioKey   = (int)$a['tts_audio_key'];
    $chapterKey = (int)$a['chapter_key'];
    $volumeKey  = (int)$a['volume_key'];
    $status     = $a['tts_audio_status'];

    $skipRanges = [];
    if ($volumeKey) {
        $sr = $db->prepare("SELECT volume_skip_pages FROM yy_volume WHERE volume_key = ?");
        $sr->execute([$volumeKey]);
        foreach (preg_split('/\s*,\s*/', (string)($sr->fetchColumn() ?: ''), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $tok, $m)) $skipRanges[] = [(int)$m[1], (int)$m[2]];
            elseif (preg_match('/^\s*(\d+)\s*$/', $tok, $m))         $skipRanges[] = [(int)$m[1], (int)$m[1]];
        }
    }
    $inSkip = function (?int $pg) use ($skipRanges): bool {
        if ($pg === null) return false;
        foreach ($skipRanges as $r) if ($pg >= $r[0] && $pg <= $r[1]) return true;
        return false;
    };
    $backMatterFrom = $volumeKey ? ttsBackMatterCutoff($db, $volumeKey) : null;
    $isBackMatter = function (int $num) use ($backMatterFrom): bool {
        return $backMatterFrom !== null && $num >= $backMatterFrom;
    };

    // NO active_flag filter — matches worker/endpoint numbering.
    $pst = $db->prepare("SELECT paragraph_number, paragraph_page, paragraph_text_plain, paragraph_text_html,
                                paragraph_is_table, paragraph_is_continuation
                           FROM yy_paragraph WHERE chapter_key = ? ORDER BY paragraph_number");
    $pst->execute([$chapterKey]);
    $prows = $pst->fetchAll(PDO::FETCH_ASSOC);

    // Worker order: coalesce continuations into the previous entry FIRST
    // (a leading continuation with nothing before it stands alone), THEN
    // filter tables / skip-pages / back-matter on the head. A continuation
    // whose head is filtered is dropped with it.
    $partIdxByNum = [];
    $widx = 0; $lastEntryNum = null; $headByCont = [];
    foreach ($prows as $r) {
        $num = (int)$r['paragraph_number'];
        $pg  = $r['paragraph_page'] !== null ? (int)$r['paragraph_page'] : null;
        if (!empty($r['paragraph_is_continuation']) && $lastEntryNum !== null) { $headByCont[$num] = $lastEntryNum; continue; }
        $lastEntryNum = $num;
        if (!empty($r['paragraph_is_table']) || $inSkip($pg) || $isBackMatter($num)) continue;
        $partIdxByNum[$num] = $widx;
        $widx++;
    }

    $targets = [];
    foreach ($prows as $r) {
        $plain = (string)$r['paragraph_text_plain'];
        if ($plain === '') continue;
        $hit = $paraMatches($plain);
        if ($hit === null) continue;
        $num = (int)$r['paragraph_number'];
        // A continuation's audio lives in its head's part — redo the head.
        $own = isset($partIdxByNum[$num]) ? $num : ($headByCont[$num] ?? null);
        if ($own === null || !isset($partIdxByNum[$own])) continue;
        $targets[$partIdxByNum[$own]] = [$num, $r['paragraph_page'], $hit];
        $key = str_replace(["\u{2019}", "\u{2018}", "\u{02BC}", '`', "\u{00B4}"], "'", mb_strtolower($hit, 'UTF-8'));
        $byWord[$key] = ($byWord[$key] ?? 0) + 1;
    }
    if (!$targets) continue;

    $partsDir = $audioBase . '/u/tts-parts/' . $audioKey;

    // Prove the index map before deleting anything: rebuild the worker's
    // survivor list (coalesce, then filter) and compare its fingerprint with
    // the .corpus-sig the worker wrote when it built these parts. A mismatch
    // means the text changed since the build (re-parse / edit) — the worker
    // would wipe and re-narrate the whole chapter, which is a separate
    // decision, so leave such chapters untouched and report them.
    $merged = [];
    foreach ($prows as $r) {
        if (!empty($r['paragraph_is_continuation']) && $merged) {
            $h =& $merged[count($merged) - 1];
            $t = (string)($r['paragraph_text_html'] ?? '');
            if ($t !== '') $h['paragraph_text_html'] = rtrim((string)$h['paragraph_text_html']) . ' ' . ltrim($t);
            unset($h);
            continue;
        }
        $merged[] = $r;
    }
    $surv = array_values(array_filter($merged, function ($p) use ($skipRanges, $backMatterFrom) {
        if (!empty($p['paragraph_is_table'])) return false;
        if ($backMatterFrom !== null && (int)$p['paragraph_number'] >= $backMatterFrom) return false;
        $pg = (int)($p['paragraph_page'] ?? 0);
        foreach ($skipRanges as $r) if ($pg >= $r[0] && $pg <= $r[1]) return false;
        return true;
    }));
    $sig      = md5(implode("\x1e", array_map(fn($p) => $p['paragraph_number'] . '|' . (string)($p['paragraph_text_html'] ?? ''), $surv)));
    $priorSig = is_file($partsDir . '/.corpus-sig') ? trim((string)@file_get_contents($partsDir . '/.corpus-sig')) : '';
    $onDisk   = count(glob($partsDir . '/p*.mp3') ?: []);
    if (count($surv) !== $widx || ($priorSig !== '' && $priorSig !== $sig) || ($priorSig === '' && $onDisk > $widx)) {
        $staleSkipped[] = $audioKey;
        if (!$quiet) printf("ak=%-5d SKIP: text changed since this chapter was built (%d paragraphs affected) — not touched\n", $audioKey, count($targets));
        continue;
    }
    $totAudio++; $vols[$volumeKey] = true;
    foreach ($targets as $idx => $info) {
        $pf = $partsDir . sprintf('/p%05d.mp3', $idx);
        $present = is_file($pf);
        $totParas++;
        if (!$quiet) printf("ak=%-5d st=%-8s p#%-5d page=%-4s part=p%05d %s :: %s\n",
               $audioKey, $status, $info[0], $info[1] ?? '-', $idx, $present ? 'HAVE' : 'absent', $info[2]);
        if ($apply && $present) { @unlink($pf); $totParts++; }
        elseif (!$apply && $present) { $totParts++; }
    }

    if ($apply) {
        if ($status === 'complete') {
            $db->prepare("UPDATE yy_tts_audio
                             SET tts_audio_status='pending', tts_audio_worker_pid=NULL, tts_audio_progress=0,
                                 tts_audio_message=?
                           WHERE tts_audio_key=? AND tts_audio_status='complete'")
               ->execute(["re-queued: contraction redo ({$mode})", $audioKey]);
            $reflagged++;
        } elseif ($status === 'pending') { $primedPending++; }
        else { $primedPaused++; }
    } else {
        if ($status === 'complete') $reflagged++; elseif ($status === 'pending') $primedPending++; else $primedPaused++;
    }
}

arsort($byWord);
printf("\n%s (mode=%s): %d volumes, %d chapters, %d paragraphs, %d cached parts %s\n"
     . "complete->pending=%d  pending(primed)=%d  paused(parts deleted, stays paused)=%d\n",
       $apply ? 'APPLIED' : 'DRY-RUN', $mode, count($vols), $totAudio, $totParas, $totParts,
       $apply ? 'deleted' : 'would be deleted', $reflagged, $primedPending, $primedPaused);
if ($staleSkipped) printf("SKIPPED %d stale chapters (text changed since build): ak=%s\n", count($staleSkipped), implode(',', $staleSkipped));
echo "top words: ";
$i = 0; foreach ($byWord as $w => $c) { if ($i++ >= 15) break; echo "$w=$c  "; }
echo "\n";
