<?php
/**
 * transcript-gap-sweep.php (2026-10-17): the machine side of the "Generating"
 * transcript status, so admins never have to start or rescue a transcript.
 *
 * Picks up videos from the auto-transcribed channels (TX_AUTO_FEED_KEYS) that have an MP3 and nothing in flight (no pending/running
 * STT or build job) but either
 *   - have NO editable transcript (fell through the cracks: queued while
 *     auto-transcribe was off, jobs cancelled by a lock cleanup, …), or
 *   - whose latest editable build FAILED.
 * For each one: if it already has enough usable baselines, queue a consensus
 * build (_timing_consensus.php, which refuses anything a person has edited or
 * validated); otherwise queue the standard baseline suite
 * (autoEnqueueBaselineSuite, which also opts the item in to the auto build).
 * The single STT worker is started by gpu-queue-tick.php, as for any pending job.
 *
 * At most 3 tries per item (yy_transcript_gap_sweep). After that it stops and
 * logs ONE 'info' monitor event (source transcript_gap_sweep) for developers:
 * auto-fix only handles warning+, and transcript failures are operational,
 * not code bugs (see feedback_transcript_errors_not_autofix).
 *
 * Runs from gpu-queue-tick.php at most every 30 minutes, 10 items per run.
 *   php transcript-gap-sweep.php            # run now (ignores the 30-min throttle)
 *   php transcript-gap-sweep.php --dry      # list what it would do
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php';   // autoEnqueueBaselineSuite()

// Channels transcribed automatically. Admin request 2026-10-13: only
// @YahowahsHerald (feed 1); other channels are transcribed by hand (Generate).
// admin-recordings.php reads this too, to show "Not transcribed" vs "Generating".
const TX_AUTO_FEED_KEYS = [1];
const TX_GAP_MAX_TRIES = 3;
const TX_GAP_PER_RUN   = 10;
const TX_GAP_STAMP     = '/var/tmp/transcript-gap-sweep.stamp';

function transcriptGapSweep(PDO $db, bool $force = false, bool $dry = false): array {
    if (!$force && @filemtime(TX_GAP_STAMP) > time() - 1800) return [];
    $feeds = implode(',', array_map('intval', TX_AUTO_FEED_KEYS));
    if (!$dry) @touch(TX_GAP_STAMP);

    $cands = $db->query("
        SELECT fi.feed_item_key AS k,
               EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key) AS has_tx,
               COALESCE(g.attempts, 0) AS attempts
          FROM yy_feed_item fi
          LEFT JOIN yy_transcript_gap_sweep g ON g.feed_item_key = fi.feed_item_key
         WHERE fi.feed_item_type = 'video'
           AND fi.feed_key IN ($feeds)
           AND NULLIF(btrim(fi.feed_item_audio_file), '') IS NOT NULL
           AND g.gave_up_dtime IS NULL
           AND NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript_job j
                            WHERE j.feed_item_key = fi.feed_item_key AND j.job_status IN ('pending', 'running'))
           AND NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript_init_job ij
                            WHERE ij.job_item_key = fi.feed_item_key AND ij.job_status IN ('pending', 'running'))
           AND (NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript t WHERE t.feed_item_key = fi.feed_item_key)
                OR (SELECT ij.job_status FROM yy_feed_item_transcript_init_job ij
                     WHERE ij.job_item_key = fi.feed_item_key ORDER BY ij.job_key DESC LIMIT 1) = 'error')
         ORDER BY fi.feed_item_key DESC
         LIMIT " . TX_GAP_PER_RUN)->fetchAll(PDO::FETCH_ASSOC);

    $mark = $db->prepare("INSERT INTO yy_transcript_gap_sweep (feed_item_key, attempts, last_action, last_dtime, gave_up_dtime)
                          VALUES (?, ?, ?, NOW(), CASE WHEN ?::boolean THEN NOW() END)
                          ON CONFLICT (feed_item_key) DO UPDATE SET attempts = EXCLUDED.attempts, last_action = EXCLUDED.last_action,
                                 last_dtime = NOW(), gave_up_dtime = COALESCE(yy_transcript_gap_sweep.gave_up_dtime, EXCLUDED.gave_up_dtime)");
    $out = [];
    foreach ($cands as $c) {
        $k = (int)$c['k']; $tries = (int)$c['attempts']; $hasTx = (bool)$c['has_tx'];
        if ($tries >= TX_GAP_MAX_TRIES) {
            $out[] = "item $k: gave up after $tries tries";
            if ($dry) continue;
            $mark->execute([$k, $tries, 'gave up', 't']);
            $err = (string)$db->query("SELECT COALESCE(job_error, '') FROM yy_feed_item_transcript_init_job
                                        WHERE job_item_key = $k ORDER BY job_key DESC LIMIT 1")->fetchColumn();
            logMonitorEvent('transcript_gap_sweep', 'info',
                "Transcript for item $k still " . ($hasTx ? 'failing to rebuild' : 'missing') . " after $tries automatic tries",
                "item_key=$k\nlast build error: " . ($err !== '' ? $err : '(none)') . "\nThe sweep has stopped retrying. Clear the row in yy_transcript_gap_sweep to let it try again.");
            continue;
        }
        // No transcript yet usually means no status row, which _timing_consensus
        // reads as "protected/no-status". Give it the default unedited status.
        // (INSERT … WHERE NOT EXISTS, not ON CONFLICT: see the _rev trigger note.)
        if (!$hasTx && !$dry) {
            $db->prepare("INSERT INTO yy_feed_item_transcript_status (feed_item_key, edit_status)
                          SELECT ?, 'Pending' WHERE NOT EXISTS (SELECT 1 FROM yy_feed_item_transcript_status WHERE feed_item_key = ?)")
               ->execute([$k, $k]);
        }
        // Ask _timing_consensus.php first: it builds only from enough usable
        // baselines and refuses transcripts a person has edited or validated.
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/_timing_consensus.php') . ' ' . $k . ($dry ? '' : ' --apply') . ' 2>&1';
        $res = (string)shell_exec($cmd);
        if (preg_match('/^(APPLIED|DRY-RUN): 1 consensus/m', $res)) {
            $out[] = "item $k: consensus build" . ($dry ? ' (dry)' : '');
            if (!$dry) $mark->execute([$k, $tries + 1, 'consensus build', 'f']);
        } elseif (preg_match('/skipped (protected\/(?!no-status)|human edits)/', $res)) {
            // A person has worked on it: never rebuild. The list shows its
            // normal status; just stop looking at it.
            $out[] = "item $k: protected (human edits), left alone";
            if (!$dry) $mark->execute([$k, $tries, 'protected: human edits', 't']);
        } elseif (!$hasTx) {
            $out[] = "item $k: baseline suite" . ($dry ? ' (dry)' : '');
            if (!$dry) { autoEnqueueBaselineSuite($db, $k, null, 0); $mark->execute([$k, $tries + 1, 'baseline suite', 'f']); }
        } else {
            // Has a transcript and too few baselines to rebuild from: regenerate them
            // (the consensus auto-builds when the last one lands, if not protected).
            $out[] = "item $k: baseline suite (rebuild)" . ($dry ? ' (dry)' : '');
            if (!$dry) { autoEnqueueBaselineSuite($db, $k, null, 0); $mark->execute([$k, $tries + 1, 'baseline suite (rebuild)', 'f']); }
        }
    }
    return $out;
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $dry = in_array('--dry', $argv, true);
    $lines = transcriptGapSweep(getDb(), true, $dry);
    echo date('Y-m-d H:i:s') . ' transcript-gap-sweep' . ($dry ? ' [dry]' : '') . ': ' . count($lines) . " item(s)\n";
    foreach ($lines as $l) echo "  $l\n";
}
