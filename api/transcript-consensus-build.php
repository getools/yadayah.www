<?php
/**
 * Shared consensus build core (2026-10-10) — extracted VERBATIM from
 * admin-transcript-init-worker.php (consensus mode: engine deny filter →
 * timing spine → coverage-union gap fill → vote (+majority insert) → same-time
 * spread → reflow (pause_v2, boundaries) → correction dictionary) so the
 * worker and transcript-tail-rebuild.php build identically.
 *
 * $baselines are ALWAYS intersected with the engines that actually have rows
 * (a requested baseline with no rows used to become the spine and fail
 * "primary has no rows" whenever the job had no wait_for list).
 * $ctx: notify (callable string), warn (callable string: persist a job warning),
 *       rows_written (callable int), speaker_timeline (callable code → [[secs,label],...]).
 * Returns ['rows' => [['segment','text'],...], 'baselines' => deny-filtered codes,
 *          'spine' => code, 'weights' => [...], 'speakerTimeline' => [...]].
 */
require_once __DIR__ . '/transcript-helpers.php';
require_once __DIR__ . '/transcript-compare-lib.php';
require_once __DIR__ . '/transcript-caption-lib.php';

function txBuildConsensusRows(PDO $db, int $itemKey, array $baselines, array $params, string $primaryReq, array $ctx = []): array {
    $notify = $ctx['notify'] ?? function (string $s) {};
    $warnCb = $ctx['warn'] ?? function (string $s) {};
    $rowsCb = $ctx['rows_written'] ?? function (int $n) {};
    $buildSpeakerTimeline = $ctx['speaker_timeline'] ?? function (string $c): array { return []; };
    $jp = ['primary' => $primaryReq];
    $requestedBaselines = $ctx['requested'] ?? $baselines;   // for the Layer-2 reduced-set warning
    $speakerTimeline = [];
    if ($baselines) {
        $aph = implode(',', array_fill(0, count($baselines), '?'));
        $aq = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model FROM yy_feed_item_transcript_auto WHERE feed_item_key = ? AND feed_item_transcript_auto_model IN ($aph)");
        $aq->execute(array_merge([$itemKey], $baselines));
        $baselines = array_values(array_intersect($baselines, $aq->fetchAll(PDO::FETCH_COLUMN)));
    }
    if (!$baselines) throw new Exception('consensus: none of the requested baselines has rows for this item');
    // Exclude low-reliability engines from the vote. gpu-canary-1b-flash and
    // gpu-qwen2-audio systematically hallucinate common words ("Yah" for "the",
    // "Not" for "no", "there'll" for pauses); left in, they poison the majority
    // vote. Data-driven from the learned reliability grades (cfDeniedEngines);
    // never strip below a 2-baseline quorum. Word-level spine engines are
    // high-grade and never on the denylist, so the spine is unaffected.
    $deny = cfDeniedEngines($db);
    $keptBaselines = array_values(array_diff($baselines, $deny));
    if (count($keptBaselines) >= 2) {
        $dropped = array_values(array_intersect($baselines, $deny));
        if ($dropped) { error_log('consensus: excluded low-reliability baselines ['
            . implode(',', $dropped) . "] item=$itemKey"); $notify('deny:' . implode(',', $dropped)); }
        $baselines = $keptBaselines;
    }
    // Timing spine = best word-level baseline among those checked.
    $pref = ['gpu-whisperx-word','gpu-whisper-large-v3-word','gpu-whisper-large-v3-turbo-word','gpu-parakeet-tdt-0.6b-v2-word'];
    $spine = null;
    foreach ($pref as $c) if (in_array($c, $baselines, true)) { $spine = $c; break; }
    if (!$spine) foreach ($baselines as $c) if (strpos($c, '-word') !== false) { $spine = $c; break; }
    if (!$spine) $spine = $baselines[0];
    // Operator-chosen Primary overrides the preference-list pick: it becomes
    // the spine (text + word-timing guidance) when it's among the baselines.
    // BUT only when it actually carries word-level timing (a '-word' model).
    // A segment-level Primary (e.g. gpu-whisperx-diarize) has a single start
    // per ~1-6s segment; making it the spine collapses every cue carved from a
    // segment onto that one start time, producing runs of duplicate/integer-
    // second timestamps (the 2026-06/07 diarize-spine builds). Speaker labels
    // are sourced from the diarize timeline independently below, so a
    // segment-level Primary loses nothing by not being the timing spine. Only
    // override when we have no word-level spine to protect in the first place.
    $primary = trim((string)($jp['primary'] ?? ''));
    if ($primary !== '' && in_array($primary, $baselines, true)) {
        if (str_ends_with($primary, '-word') || strpos((string)$spine, '-word') === false) {
            $spine = $primary;
        } else {
            error_log("consensus: Primary '$primary' is segment-level; keeping word-level spine '$spine' for timing (speakers still sourced from diarize) item=$itemKey");
            $notify('spine-note:kept-word-spine');
        }
    }
    $refs = array_values(array_diff($baselines, [$spine]));
    // Edit-weighted consensus: per-engine weights learned from how closely
    // each baseline tracks past HUMAN-EDITED finals (transcript-engine-grade.php
    // → yy_transcript_engine_edit_grade). Empty/thin data → [] → every
    // downstream weight defaults to 1.0 (byte-identical to the old unweighted
    // vote). Fed into the coverage-union gap-fill, the majority vote, and the
    // LLM-reconcile alternate ordering below. ⚠ NOT a denylist replacement:
    // canary/qwen2 grade mid-pack here (their artifacts are a small % of
    // tokens), so cfDeniedEngines still removes them upstream.
    $weights = cfEngineWeights($db);
    // Layer 2: if we ended up building from fewer baselines than requested —
    // especially without the operator's chosen primary or a word-level spine
    // candidate — that is a quality risk (this is exactly how a VAD-dropped
    // intro slipped through: the build ran before whisperx landed). Surface
    // it loudly instead of degrading silently. The build still proceeds
    // (Layer 1's coverage-union backfills what it can); the note is persisted
    // to job_error for post-hoc inspection and pushed live via $notify.
    $absent = array_values(array_diff($requestedBaselines, $baselines));
    if ($absent) {
        $warnParts = [];
        if ($primary !== '' && !in_array($primary, $baselines, true)) {
            $warnParts[] = "chosen primary '$primary' unavailable (spine fell back to '$spine')";
        }
        $absentWord = array_values(array_filter($absent, fn($m) => str_ends_with($m, '-word')));
        if ($absentWord) $warnParts[] = 'missing word baselines: ' . implode(',', $absentWord);
        if (!$warnParts) $warnParts[] = 'missing baselines: ' . implode(',', $absent);
        $warn = 'Reduced-baseline consensus build — ' . implode('; ', $warnParts)
              . '. Coverage/quality may be lower; re-run once the engines are back.';
        error_log("consensus: DEGRADED build item=$itemKey — $warn");
        $notify('warn:' . $warn);
        $warnCb($warn);
    }
    // Diarisation source among the baselines (whisperx-diarize preferred;
    // else the first baseline that actually carries speaker labels).
    if (in_array('gpu-whisperx-diarize', $baselines, true)) {
        $speakerTimeline = $buildSpeakerTimeline('gpu-whisperx-diarize');
    } else {
        foreach ($baselines as $bc) {
            $t = $buildSpeakerTimeline($bc);
            if ($t) { $speakerTimeline = $t; break; }
        }
    }
    // Coverage-union spine (Layer 1): before voting, fill spans the spine
    // never covered (VAD-dropped intro/outro songs, engine dropouts) with the
    // best-covering baseline's words. Without this the vote can only ever be
    // as *complete* as the single spine — a gap in the spine is discarded even
    // when another baseline heard the passage (buildComparison drops ref
    // tokens with no spine anchor). Opt out with params.fill_gaps=false.
    $spineWords = null;
    if (!array_key_exists('fill_gaps', $params) || $params['fill_gaps']) {
        $filled = [];
        $minGap = (float)($params['fill_gap_secs'] ?? 8.0);
        $spineWords = unionSpineWords($db, $itemKey, $spine, $baselines, $filled, $minGap, 3, null, $weights);
        if ($filled) {
            $tot   = array_sum(array_map(fn($f) => $f['words'], $filled));
            $spans = implode(', ', array_map(
                fn($f) => sprintf('%.0f-%.0fs<-%s(%dw)', $f['start'], $f['end'], $f['code'], $f['words']),
                $filled));
            error_log(sprintf('consensus union-spine: filled %d gap(s), +%d words [%s] item=%d',
                count($filled), $tot, $spans, $itemKey));
            $notify('union-fill:' . count($filled) . ':' . $tot);
        }
    }
    // majority_insert (default ON, 2026-10-09): add words the spine has no
    // slot for when a weighted majority of engine families heard them in
    // the same gap — whisperx-word's aligner drops numerals ("from [1909]
    // that", "[$40] trillion"); ~80 restored on item 18297621.
    // family_vote (opt-in, default OFF): one vote per model family for
    // substitutions too. Tested mixed — whisperx IS whisper-large-v3 inside,
    // so the families aren't independent on word choice ("Rosh Hashanah" →
    // "Russia Shana", "Eva Braun" → "Brown") — so it stays off.
    $cmp = buildComparison($db, $itemKey, $spine, $refs, $spineWords, $weights, [
        'family_vote'     => !empty($params['family_vote']),
        'majority_insert' => !array_key_exists('majority_insert', $params) || $params['majority_insert'],
    ]);
    if (isset($cmp['error'])) throw new Exception('consensus: ' . $cmp['error']);
    $stream = [];
    foreach ($cmp['slots'] as $sl) { $w = trim((string)$sl['consensus']); if ($w !== '') $stream[] = ['t' => $sl['t'], 'w' => $w]; }
    if (!$stream) throw new Exception('consensus: empty word stream');
    // Same-time runs (2026-10-10): with no word-level baseline the spine is
    // segment-level (youtube / deepgram / whisperx segments), so every word
    // of a segment shares the segment's start and every cue carved from it
    // got that one timestamp (57 items in the 10-10 bulk regen, ~10k rows).
    // Spread each run of identical times across its span to the next
    // distinct time, proportional to word length, capped at a slow reading
    // pace (chars / 12) so a trailing pause stays a pause.
    $ns = count($stream);
    for ($a = 0; $a < $ns; ) {
        $b = $a;
        while ($b + 1 < $ns && abs($stream[$b + 1]['t'] - $stream[$a]['t']) < 0.001) $b++;
        if ($b > $a) {
            $t0 = (float)$stream[$a]['t'];
            $chars = 0; for ($q = $a; $q <= $b; $q++) $chars += mb_strlen($stream[$q]['w']) + 1;
            $next = ($b + 1 < $ns) ? (float)$stream[$b + 1]['t'] : $t0 + $chars / 15.0;
            $span = max(0.0, min($next - $t0, $chars / 12.0));
            $cum = 0;
            for ($q = $a; $q <= $b; $q++) {
                $stream[$q]['t'] = round($t0 + $span * $cum / max(1, $chars), 3);
                $cum += mb_strlen($stream[$q]['w']) + 1;
            }
        }
        $a = $b + 1;
    }
    $opts = [
        'max_chars'   => (int)($params['max_chars'] ?? 42),
        'max_lines'   => (int)($params['max_lines'] ?? 2),
        'preferred_lines' => (int)($params['preferred_lines'] ?? 1),
        'max_secs'    => (float)($params['max_secs'] ?? 7.0),
        'min_secs'    => (float)($params['min_secs'] ?? 1.2),
        'break_punct' => array_key_exists('break_punct', $params) ? (bool)$params['break_punct'] : true,
        // Pause breaks (2026-10-09): every auto/default path used to send
        // break_gap 0 (off), so a sentence-final fragment glued onto the
        // next sentence across long pauses ("Lenin. And I have been…").
        // Consensus now uses cfReflow's pause_v2 (duration-aware pauses,
        // orphan back-merge, no mid-clause hesitation splits) with a 1.2s
        // default; an explicit positive break_gap is honoured, 0/absent →
        // 1.2. Opt out of v2 with params.pause_v2=false (then 0 = off).
        'pause_v2'    => !array_key_exists('pause_v2', $params) || (bool)$params['pause_v2'],
        'break_gap'   => (float)($params['break_gap'] ?? 0) > 0
                            ? (float)$params['break_gap']
                            : ((!array_key_exists('pause_v2', $params) || $params['pause_v2']) ? 1.2 : 0.0),
        'soft_overflow' => max(1.0, (float)($params['soft_overflow'] ?? 1.5)),
        'dedup'       => array_key_exists('dedup', $params) ? (bool)$params['dedup'] : true,
    ];
    if (!empty($params['use_boundaries']) || !empty($params['prioritize_primary_breaks'])) {
        // "Prioritize Breaks from Primary" → anchor caption breaks on the
        // spine (Primary) boundaries only; otherwise use the union of all
        // checked baselines' segment boundaries.
        // 2026-10-10: use the operator's chosen PRIMARY's boundaries (that is
        // what "Prioritize Breaks from Primary" means), not the timing spine —
        // since the 07-26 spine fix a segment-level Primary (whisperx-diarize)
        // no longer becomes the spine, and the word-level spine made EVERY
        // word a boundary (captions cut every 1-3 words: "you've heard me" /
        // "speak of"). Word-level outputs are never boundary sources.
        $bsources = !empty($params['prioritize_primary_breaks'])
            ? [($primary !== '' && in_array($primary, $baselines, true)) ? $primary : $spine]
            : $baselines;
        $bsources = array_values(array_filter($bsources, fn($c) => !str_ends_with((string)$c, '-word')));
        $bset = [];
        foreach ($bsources as $bcode) {
            $bs = $db->prepare("SELECT feed_item_transcript_segment::text AS seg FROM yy_feed_item_transcript_auto WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ?");
            $bs->execute([$itemKey, $bcode]);
            foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $br) $bset[] = round(cfIntervalToSecs($br['seg']), 2);
        }
        $bset = array_values(array_unique($bset)); sort($bset);
        $opts['boundary_set'] = $bset;
    }
    // Break on speaker change: fold diarised speaker-turn onsets into the
    // caption boundary set so cfReflow ends a cue whenever the speaker
    // changes. Opt-in (default off) → existing builds are unchanged.
    if (!empty($params['break_speaker']) && $speakerTimeline) {
        $sb = $opts['boundary_set'] ?? [];
        $prev = null;
        foreach ($speakerTimeline as $e) {
            if ($e[1] !== $prev) { $sb[] = round((float)$e[0], 2); $prev = $e[1]; }
        }
        $sb = array_values(array_unique($sb)); sort($sb);
        $opts['boundary_set'] = $sb;
    }
    $cues = cfReflow($stream, $opts);
    if (!$cues) throw new Exception('consensus: re-flow produced no cues');
    // Surface the segment count to the queue monitor as soon as it's known
    // (status still 'running' — the final value is rewritten on 'done').
    $rowsCb(count($cues));
    $notify('building:' . count($cues));
    $crows = [];
    foreach ($cues as $cue) {
        $crows[] = ['segment' => cfSecsToInterval((float)$cue['start']),
                    'text'    => str_replace("\n", ' ', (string)$cue['text'])];
    }
    $cleanedRows = applyCorrectionsAcrossRows($db, $crows);
    return ['rows' => $cleanedRows, 'baselines' => $baselines, 'spine' => $spine,
            'weights' => $weights, 'speakerTimeline' => $speakerTimeline];
}
