<?php
/**
 * Detached CLI worker for "Initialize Transcript".
 *
 *   setsid php admin-transcript-init-worker.php <job_key> &
 *
 * admin-transcript-init.php enqueues a yy_feed_item_transcript_init_job row and
 * spawns this worker so the (heavy, ~60-230s on long episodes) promote runs
 * outside Cloudflare's ~100s proxy window. The work is identical to the old
 * synchronous endpoint: (re)assemble the hybrid join if needed, apply the
 * correction dictionary across rows, and replace the live transcript — using
 * the batched/trigger-skip fast path (see transcript-helpers.php). On finish it
 * flips the job to done/error and pg_notify()s 'transcript_init_<job_key>' so
 * admin-transcript-init-sse.php can push the result to the browser.
 *
 * No auth here — it's a local CLI process, not an HTTP request.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php'; // applyCorrectionsAcrossRows, buildWhisperWordJoin, batchInsertRows

$jobKey = (int)($argv[1] ?? 0);
if (!$jobKey) { fwrite(STDERR, "usage: admin-transcript-init-worker.php <job_key>\n"); exit(1); }

$db = getDb();
// CLI worker is immune to the HTTP clock; don't let the request-scoped 120s
// statement_timeout kill a long batched insert on a very long episode.
try { $db->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

// Single-worker editable-build queue: whenever this process ends (done, error,
// supersede-exit, or even a fatal), spawn the next pending init job so the
// queue drains one-at-a-time, in page order, server-side — surviving the
// operator closing the popover. Fresh connection: ours may be unusable at
// shutdown. The single-worker gate inside the helper prevents overlap.
register_shutdown_function(function () {
    try { spawnNextInitWorker(getDb()); } catch (\Throwable $e) {}
});

// Atomically claim the job so a double-spawn can't run it twice; stamp
// job_started so the dispatcher's reaper can tell how long it's been running.
$claim = $db->prepare("UPDATE yy_feed_item_transcript_init_job SET job_status='running', job_started=now()
                        WHERE job_key=? AND job_status='pending' RETURNING *");
$claim->execute([$jobKey]);
$job = $claim->fetch();
if (!$job) { exit(0); }   // already claimed, or gone (shutdown handler still pokes the queue)

$itemKey = (int)$job['job_item_key'];
$model   = (string)$job['job_model'];
$userKey = (int)$job['job_user_key'];

$notify = function (string $payload) use ($db, $jobKey) {
    try { $db->prepare("SELECT pg_notify(?, ?)")->execute(['transcript_init_' . $jobKey, $payload]); }
    catch (\Throwable $e) {}
};
$fail = function (string $msg) use ($db, $jobKey, $notify) {
    try {
        $db->prepare("UPDATE yy_feed_item_transcript_init_job SET job_status='error', job_error=?, job_completed=now() WHERE job_key=?")
           ->execute([mb_substr($msg, 0, 500), $jobKey]);
    } catch (\Throwable $e) {}
    $notify('error');
};

try {
    if ($userKey) { setCurrentUser($db, $userKey); }

    // Speaker timeline (diarisation): sorted [secs, label] built from a
    // diarising baseline's _auto rows. Lets any build stamp each final cue with
    // the speaker active at its start time, and (with break_speaker) break cues
    // on speaker change. Stays empty when no diarised baseline exists → speaker
    // is written NULL and output is byte-identical to the pre-diarisation path.
    $speakerTimeline = [];
    $buildSpeakerTimeline = function (string $modelCode) use ($db, $itemKey): array {
        require_once __DIR__ . '/transcript-caption-lib.php';
        $q = $db->prepare("SELECT feed_item_transcript_segment::text AS seg, feed_item_transcript_speaker AS spk
                             FROM yy_feed_item_transcript_auto
                            WHERE feed_item_key = ? AND feed_item_transcript_auto_model = ?
                              AND feed_item_transcript_speaker IS NOT NULL
                         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment");
        $q->execute([$itemKey, $modelCode]);
        $tl = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tl[] = [round(cfIntervalToSecs($r['seg']), 3), (string)$r['spk']];
        }
        return $tl;
    };
    // Label active at $secs = the last turn whose onset is at or before it.
    // Timeline is time-sorted, so stop at the first onset that is later.
    $speakerAt = function (array $tl, float $secs): ?string {
        $lab = null;
        foreach ($tl as $e) { if ($e[0] <= $secs + 0.001) $lab = $e[1]; else break; }
        return $lab;
    };
    // Label for a cue spanning [$s,$e) = the diarised turn with the greatest
    // temporal OVERLAP, not merely the one active at the start instant. Turns
    // are [onset_i, onset_{i+1}); the final turn runs open-ended. This is robust
    // to a cue boundary landing slightly before the true speaker onset (a music
    // or trailing-word tail), which point-in-time "last onset <= start" mislabels
    // with the previous speaker — the one-cue speaker lag. Falls back to
    // $speakerAt when the cue has zero positive overlap (e.g. it ends before the
    // first onset), so behaviour is unchanged wherever a cue sits inside a turn.
    $speakerOverlap = function (array $tl, float $s, float $e) use ($speakerAt): ?string {
        $n = count($tl);
        if ($n === 0) return null;
        if ($e <= $s) $e = $s + 0.001;
        $best = null; $bestOv = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $ts = $tl[$i][0];
            $te = ($i + 1 < $n) ? $tl[$i + 1][0] : INF;
            $ov = min($e, $te) - max($s, $ts);
            if ($ov > $bestOv) { $bestOv = $ov; $best = $tl[$i][1]; }
        }
        return $best !== null ? $best : $speakerAt($tl, $s);
    };

    // ── Consensus mode: majority vote across several baselines, then re-flow
    //    into editable rows by the supplied caption params (see init endpoint). ──
    if ($model === 'consensus') {
        require_once __DIR__ . '/transcript-compare-lib.php';
        require_once __DIR__ . '/transcript-caption-lib.php';
        $jp = json_decode((string)($job['job_params'] ?? ''), true) ?: [];
        $baselines = array_values(array_filter((array)($jp['baselines'] ?? [])));
        $params    = (array)($jp['params'] ?? []);
        if (!$baselines) throw new Exception('consensus: no baselines supplied');
        // Layer 2: remember what was requested so a build from a reduced set
        // (some baselines failed to generate) can be detected and surfaced
        // rather than silently degrading.
        $requestedBaselines = $baselines;
        // Server-side chain: block until the baselines still being generated by
        // this run land in _auto, so the editable transcript builds even if the
        // operator closed the popover. Bounded wait; then build with whatever
        // landed. (This worker holds no DB locks while sleeping.)
        $waitFor = array_values(array_filter((array)($jp['wait_for'] ?? [])));
        if ($waitFor) {
            // Wait while the still-missing baselines are ACTIVELY being generated
            // (their STT jobs are pending/running). Stop as soon as all have
            // landed, OR none of the missing ones is still active (they failed/
            // cancelled and won't come). ⚠ Replaces the old fixed 1h deadline,
            // which timed out — and errored "no baselines available" — when a
            // slow/deep STT queue took >1h to reach this item's baselines. A
            // generous absolute cap remains only as a stuck-queue safety net.
            $deadline = time() + 21600;   // 6h safety cap (single-worker gate guard)
            $wph = implode(',', array_fill(0, count($waitFor), '?'));
            while (true) {
                // Bail if a newer generation superseded this build (it flips our
                // status away from 'running'); avoids two workers racing to
                // overwrite the same live transcript.
                $cs = $db->prepare("SELECT job_status FROM yy_feed_item_transcript_init_job WHERE job_key = ?");
                $cs->execute([$jobKey]);
                if ($cs->fetchColumn() !== 'running') { $notify('cancelled'); exit(0); }
                $wq = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model FROM yy_feed_item_transcript_auto WHERE feed_item_key = ? AND feed_item_transcript_auto_model IN ($wph)");
                $wq->execute(array_merge([$itemKey], $waitFor));
                $missing = array_values(array_diff($waitFor, $wq->fetchAll(PDO::FETCH_COLUMN)));
                if (!$missing) break;   // all baselines landed
                // Are any of the missing baselines still being generated?
                $mph = implode(',', array_fill(0, count($missing), '?'));
                $aq2 = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE feed_item_key = ? AND job_model IN ($mph) AND job_status IN ('pending','running')");
                $aq2->execute(array_merge([$itemKey], $missing));
                if ((int)$aq2->fetchColumn() === 0) {
                    // Layer 2: none of the missing baselines is still being
                    // generated. Before giving up and voting over a reduced set,
                    // try once more to recover the CRITICAL ones — the operator's
                    // chosen primary and any word-level spine candidate — that
                    // failed/cancelled. Re-queue them at priority 1 so the single
                    // STT worker takes them next, then resume waiting. Bounded per
                    // model (≤ 3 prior failures) so a genuinely broken engine can't
                    // loop forever; the 6h cap still applies.
                    $primaryReq = trim((string)($jp['primary'] ?? ''));
                    $critical = array_values(array_filter($missing,
                        fn($m) => $m === $primaryReq || str_ends_with($m, '-word')));
                    $requeued = [];
                    foreach ($critical as $m) {
                        $fq = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript_job
                                             WHERE feed_item_key = ? AND job_model = ?
                                               AND job_status IN ('failed','cancelled')");
                        $fq->execute([$itemKey, $m]);
                        if ((int)$fq->fetchColumn() >= 3) continue;   // give up on this engine
                        $db->prepare("INSERT INTO yy_feed_item_transcript_job
                                          (feed_item_key, job_status, job_message, user_key, job_model, job_priority)
                                      VALUES (?, 'pending', 'Re-queued by consensus init (missing critical baseline)', NULL, ?, 1)")
                           ->execute([$itemKey, $m]);
                        $requeued[] = $m;
                    }
                    if ($requeued) {
                        error_log('consensus: re-queued failed critical baselines ['
                            . implode(',', $requeued) . "] item=$itemKey");
                        $notify('requeue:' . implode(',', $requeued));
                        sleep(4);
                        continue;                // resume waiting; they're pending now
                    }
                    break;   // nothing recoverable → build with what landed (degraded; warned below)
                }
                if (time() >= $deadline) break;              // safety cap only
                sleep(4);
            }
            // Build only from baselines that actually exist now.
            $aph = implode(',', array_fill(0, count($baselines), '?'));
            $aq = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model FROM yy_feed_item_transcript_auto WHERE feed_item_key = ? AND feed_item_transcript_auto_model IN ($aph)");
            $aq->execute(array_merge([$itemKey], $baselines));
            $baselines = array_values(array_intersect($baselines, $aq->fetchAll(PDO::FETCH_COLUMN)));
            if (!$baselines) {
                // Design fallback: EVERY requested baseline failed to land (e.g.
                // GPU STT rejected truncated audio, or a YouTube bot-block killed
                // the yt-dlp fallback), but another source — most often the
                // YouTube caption track — may already be sitting in _auto. The
                // operator asked us to build the editable transcript, so honour
                // that intent by degrading to WHATEVER did land instead of
                // erroring out and leaving the item stuck "builds when baselines
                // finish". The result is Pending/rebuildable, so a real build can
                // replace it once proper baselines exist. Only genuinely nothing
                // in _auto is a hard error.
                $anyq = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model FROM yy_feed_item_transcript_auto WHERE feed_item_key = ?");
                $anyq->execute([$itemKey]);
                $baselines = array_values(array_filter($anyq->fetchAll(PDO::FETCH_COLUMN)));
                if (!$baselines) throw new Exception('consensus: no baselines available after waiting');
                $fbWarn = 'Editable transcript built from FALLBACK source(s) [' . implode(',', $baselines)
                        . '] — every requested baseline failed to produce audio-based text (likely missing/partial audio or a YouTube bot-block). Quality is limited; re-run once real baselines land.';
                error_log("consensus: FALLBACK build item=$itemKey — $fbWarn");
                $notify('warn:' . $fbWarn);
                try { $db->prepare("UPDATE yy_feed_item_transcript_init_job SET job_error = ? WHERE job_key = ?")
                         ->execute([$fbWarn, $jobKey]); } catch (\Throwable $e) {}
            }
        }
        // Build core shared with transcript-tail-rebuild.php (2026-10-10):
        // api/transcript-consensus-build.php (moved verbatim from here).
        require_once __DIR__ . '/transcript-consensus-build.php';
        $built = txBuildConsensusRows($db, $itemKey, $baselines, $params, trim((string)($jp['primary'] ?? '')), [
            'notify' => $notify,
            'requested' => $requestedBaselines,
            'warn' => function (string $w) use ($db, $jobKey) {
                try { $db->prepare("UPDATE yy_feed_item_transcript_init_job SET job_error = ? WHERE job_key = ?")
                         ->execute([$w, $jobKey]); } catch (\Throwable $e) {}
            },
            'rows_written' => function (int $n) use ($db, $jobKey) {
                try { $db->prepare("UPDATE yy_feed_item_transcript_init_job SET job_rows_written = ? WHERE job_key = ?")->execute([$n, $jobKey]); } catch (\Throwable $e) {}
            },
            'speaker_timeline' => $buildSpeakerTimeline,
        ]);
        $cleanedRows     = $built['rows'];
        $baselines       = $built['baselines'];
        $weights         = $built['weights'];
        $speakerTimeline = $built['speakerTimeline'];
    } else {
    // Derived hybrid models are assembled fresh from the current source feeds
    // every run, so a stale join can never reach the live transcript.
    if ($model === 'whisper-1-word-join' || $model === 'whisper-1-word-join-seg') {
        $useSeg = ($model === 'whisper-1-word-join-seg');
        $built = buildWhisperWordJoin($db, $itemKey, 'youtube', $useSeg);
        if ($built === 0) {
            $need = 'a word-level whisper + YouTube' . ($useSeg ? ' + a segment-level whisper' : '');
            throw new Exception('Cannot assemble ' . $model . ' — generate ' . $need . ' first.');
        }
    }

    $srcStmt = $db->prepare("
        SELECT feed_item_transcript_segment::text AS segment,
               feed_item_transcript_text          AS text,
               feed_item_transcript_sort          AS sort
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ?
           AND feed_item_transcript_auto_model = ?
         ORDER BY feed_item_transcript_sort, feed_item_transcript_segment
    ");
    $srcStmt->execute([$itemKey, $model]);
    $rows = $srcStmt->fetchAll();
    if (!$rows) throw new Exception('No rows in _auto for item=' . $itemKey . ' model=' . $model);

    // Direct build from a diarised baseline (e.g. gpu-whisperx-diarize) → carry
    // its speaker labels onto the editable rows (looked up by time below).
    $speakerTimeline = $buildSpeakerTimeline($model);

    // YadaYah enhancement: correction dictionary across the full row sequence
    // (multi-word substitutions that straddle row boundaries). Pure-PHP, done
    // before the transaction so the write window stays short.
    $cleanedRows = applyCorrectionsAcrossRows($db, $rows);
    }

    // Final supersede check before we touch the live transcript — if a newer
    // generation took over (status no longer 'running'), don't overwrite.
    $cs = $db->prepare("SELECT job_status FROM yy_feed_item_transcript_init_job WHERE job_key = ?");
    $cs->execute([$jobKey]);
    if ($cs->fetchColumn() !== 'running') { $notify('cancelled'); exit(0); }

    // Never overwrite a human-edited transcript. Overwrite is allowed only when
    // there's no live transcript yet, or its status is explicitly 'Pending'
    // (consensus-built, not edited). 'Editing' (or any non-Pending) is protected.
    $ps = $db->prepare("SELECT (SELECT COUNT(*) FROM yy_feed_item_transcript WHERE feed_item_key = ?) AS rown,
                               (SELECT edit_status FROM yy_feed_item_transcript_status WHERE feed_item_key = ?) AS st");
    $ps->execute([$itemKey, $itemKey]);
    $pr = $ps->fetch(PDO::FETCH_ASSOC);
    if ((int)$pr['rown'] > 0 && (string)($pr['st'] ?? '') !== 'Pending') {
        $fail('Editable transcript is protected (' . ($pr['st'] ?: 'edited') . ') — not overwritten.');
        exit(0);   // ⚠ must stop here — without it the build would overwrite the protected transcript
    }

    $db->beginTransaction();
    // Bulk-load fast path: suppress the live table's two per-row triggers
    // (tsv build + caption-queue mark) during the load, then replicate them
    // set-based. SET LOCAL reverts at transaction end.
    $db->exec("SET LOCAL session_replication_role = replica");

    $db->prepare("DELETE FROM yy_feed_item_transcript_autoclean WHERE feed_item_key = ? AND feed_item_transcript_autoclean_model = ?")
       ->execute([$itemKey, $model]);
    $db->prepare("DELETE FROM yy_feed_item_transcript WHERE feed_item_key = ?")
       ->execute([$itemKey]);

    $cleanRows = [];
    $liveRows  = [];
    $count = 0;
    $seen = [];   // (segment, text) → de-dupe; the live table has a UNIQUE
                  // (feed_item_key, segment, md5(text)) — uq_transcript_no_dup —
                  // and consensus re-flow can emit two identical cues at one
                  // timestamp, which would abort the whole batch insert.
    foreach ($cleanedRows as $i => $r) {
        $clean = mb_substr((string)$r['text'], 0, 2000);
        $dk = $r['segment'] . '|' . md5($clean);
        if (isset($seen[$dk])) continue;
        $seen[$dk] = true;
        // Speaker with the greatest overlap over this cue's [start,next-start)
        // span (NULL when no diarised source). Overlap, not start-instant, so a
        // cue whose boundary precedes the true onset isn't stamped with the
        // previous speaker. Span end = next cue's onset (or +5s for the last).
        $cueStart = cfIntervalToSecs($r['segment']);
        $cueEnd   = isset($cleanedRows[$i + 1])
            ? cfIntervalToSecs($cleanedRows[$i + 1]['segment'])
            : $cueStart + 5.0;
        $spk = $speakerTimeline ? $speakerOverlap($speakerTimeline, $cueStart, $cueEnd) : null;
        $cleanRows[] = [$itemKey, $r['segment'], $clean, $i, $model];
        $liveRows[]  = [$itemKey, $r['segment'], $clean, $i, $userKey, $spk];
        $count++;
    }
    batchInsertRows($db,
        "INSERT INTO yy_feed_item_transcript_autoclean (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_autoclean_model) VALUES",
        '(?, ?::interval, ?, ?, ?)', $cleanRows);
    batchInsertRows($db,
        "INSERT INTO yy_feed_item_transcript (feed_item_key, feed_item_transcript_segment, feed_item_transcript_text, feed_item_transcript_sort, feed_item_transcript_revision_user_key, feed_item_transcript_speaker) VALUES",
        '(?, ?::interval, ?, ?, ?, ?)', $liveRows);

    // Re-enable triggers and reproduce, set-based, exactly what
    // trg_yy_feed_item_transcript_tsv and trg_yy_feed_item_caption_queue
    // would have written per row.
    $db->exec("SET LOCAL session_replication_role = DEFAULT");
    $db->prepare("UPDATE yy_feed_item_transcript SET feed_item_transcript_tsv = to_tsvector('english', COALESCE(feed_item_transcript_text, '')) WHERE feed_item_key = ? AND feed_item_transcript_tsv IS NULL")
       ->execute([$itemKey]);
    $db->prepare("UPDATE yy_feed_item SET feed_item_yt_caption_status = 'queued' WHERE feed_item_key = ? AND COALESCE(feed_item_yt_caption_status, 'never') <> 'queued'")
       ->execute([$itemKey]);

    $db->commit();

    // Layer 3: auto multi-baseline LLM reconciliation of the freshly-built
    // consensus transcript — the same baseline-aware consensus-decode Smart
    // Captions does (qwen2.5:72b, primed with the learned corrections+glossary
    // that Layer 4 feeds from every admin edit), so the STARTING editable
    // transcript is already LLM-reconciled instead of raw majority-vote. Runs
    // AFTER commit (the transcript is durable) and is FAIL-OPEN: a GPU/LLM outage
    // logs and leaves the built transcript intact — it never throws or blanks a
    // line, and it snapshots first for one-click undo. Opt out per build with
    // params.llm_reconcile=false; override the model with params.llm_model.
    if ($model === 'consensus'
        && (!array_key_exists('llm_reconcile', $params) || $params['llm_reconcile'])
        && !empty($baselines)) {
        try {
            require_once __DIR__ . '/transcript-caption-lib.php';
            $llmModel = trim((string)($params['llm_model'] ?? 'qwen2.5:72b')) ?: 'qwen2.5:72b';
            $notify('llm-reconcile:start');
            // Present the highest edit-weight engines to the LLM FIRST, so its
            // "primary evidence" alternates lead with the most-trusted readings.
            // Stable order when weights are absent (all 1.0).
            $llmBaselines = $baselines;
            if (!empty($weights)) {
                usort($llmBaselines, fn($a, $b) => ($weights[$b] ?? 1.0) <=> ($weights[$a] ?? 1.0));
            }
            $rc = llmReconcileTranscript($db, $itemKey, $llmBaselines, $llmModel, $notify);
            if (!empty($rc['ok'])) {
                error_log(sprintf('consensus: LLM reconcile item=%d model=%s changed=%d chunks=%d',
                    $itemKey, $llmModel, (int)($rc['changed'] ?? 0), (int)($rc['chunks'] ?? 0)));
                $notify('llm-reconcile:done:' . (int)($rc['changed'] ?? 0));
            } else {
                error_log(sprintf('consensus: LLM reconcile INCOMPLETE item=%d — %s (changed %d before stop)',
                    $itemKey, $rc['error'] ?? 'unknown', (int)($rc['changed'] ?? 0)));
                $notify('llm-reconcile:partial:' . (int)($rc['changed'] ?? 0));
            }
        } catch (\Throwable $e) {
            error_log('consensus: LLM reconcile threw item=' . $itemKey . ' — ' . $e->getMessage());
        }
    }

    // Guard: strip faster-whisper repetition-LOOP blocks (a run of segments all
    // stamped at one time — a stuck-decoder dump of a sung/spoken passage —
    // immediately followed by the same text re-transcribed with real timing).
    // FAIL-OPEN: the transcript is already durable; a failure here logs and
    // leaves it intact. Backs up removed rows for one-click reversal.
    try {
        require_once __DIR__ . '/transcript-caption-lib.php';
        $lb = transcriptCollapseLoopBlocks($db, $itemKey, ['apply' => true]);
        if (!empty($lb['removed'])) {
            error_log(sprintf('consensus: loop-block guard item=%d removed=%d blocks=%d reattached=%d',
                $itemKey, (int)$lb['removed'], (int)$lb['blocks'], (int)$lb['reattached']));
            $count = max(0, $count - (int)$lb['removed']);
        }
    } catch (\Throwable $e) {
        error_log('consensus: loop-block guard threw item=' . $itemKey . ' — ' . $e->getMessage());
    }

    // First-words anchor (2026-10-09): snap each line's start to where its
    // opening words occur in the word-level baselines (the most reliable timing
    // source), and count lines whose opening words aren't found nearby — the
    // tell of shifted/clobbered text or coarse-only fill. Timestamps only, never
    // text; snapshots first; FAIL-OPEN. A high unanchored share is surfaced as
    // a quality note in job_error. Opt out with params.anchor_first_words=false.
    if ($model === 'consensus' && !empty($baselines)
        && (!array_key_exists('anchor_first_words', $params) || $params['anchor_first_words'])) {
        try {
            require_once __DIR__ . '/transcript-caption-lib.php';
            $an = cfAnchorFirstWords($db, $itemKey, $baselines, ['apply' => true]);
            error_log(sprintf('consensus: first-word anchor item=%d lines=%d anchored=%d retimed=%d unanchored=%d',
                $itemKey, $an['lines'], $an['anchored'], $an['retimed'], $an['unanchored']));
            $notify('anchor:' . $an['retimed'] . ':' . $an['unanchored']);
            if ($an['lines'] > 0 && $an['unanchored'] / $an['lines'] >= 0.03) {
                $note = sprintf('Quality note: %d of %d lines (%.1f%%) have opening words not found in the word-level baselines near their timestamp — review e.g. %s',
                    $an['unanchored'], $an['lines'], 100 * $an['unanchored'] / $an['lines'], implode(' | ', array_slice($an['examples'], 0, 5)));
                try { $db->prepare("UPDATE yy_feed_item_transcript_init_job SET job_error = ? WHERE job_key = ?")
                         ->execute([mb_substr($note, 0, 2000), $jobKey]); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            error_log('consensus: first-word anchor threw item=' . $itemKey . ' — ' . $e->getMessage());
        }
    }

    // Auto-name recurring speakers: match this build's raw SPEAKER_xx voices
    // against the saved global profiles and rename the confident ones in place,
    // so a freshly built diarised transcript arrives pre-named. FAIL-OPEN — the
    // transcript is already durable; a match hiccup only logs. Near-miss
    // suggestions are left for the editor to offer interactively on open.
    try {
        $named = autoNameSpeakersFromProfiles($db, $itemKey);
        if ($named > 0) {
            error_log(sprintf('consensus: auto-named %d speaker(s) from profiles item=%d', $named, $itemKey));
            $notify('speaker-autoname:' . $named);
        }
    } catch (\Throwable $e) {
        error_log('consensus: speaker auto-name threw item=' . $itemKey . ' — ' . $e->getMessage());
    }

    $db->prepare("UPDATE yy_feed_item_transcript_init_job SET job_status='done', job_rows_written=?, job_completed=now() WHERE job_key=?")
       ->execute([$count, $jobKey]);
    // A fresh consensus build is, by definition, not human-edited → 'Pending'
    // (overwritable). An admin editing it later flips it to 'Editing'.
    $db->prepare("INSERT INTO yy_feed_item_transcript_status (feed_item_key, edit_status, dtime)
                   VALUES (?, 'Pending', now())
                   ON CONFLICT (feed_item_key) DO UPDATE SET edit_status = 'Pending', dtime = now()")
       ->execute([$itemKey]);
    $notify('done:' . $count);
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('admin-transcript-init-worker job ' . $jobKey . ' failed: ' . $e->getMessage());
    $fail($e->getMessage());
}
