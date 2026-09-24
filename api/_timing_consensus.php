<?php
/**
 * _timing_consensus.php — enqueue the consensus (Initialize Edits) rebuild for
 * items whose baseline suite has finished, using KEEP-SIZE cue params (the cue
 * width stays at the item's own current median; only overlong cues are split).
 *
 *   php _timing_consensus.php <keys|--list=F|--stdin> [--apply]
 *        [--min-baselines=3] [--min-word=1] [--max=N] [--quiet]
 *
 * Skips an item when: STT jobs are still pending/running for it, an init job is
 * already pending/running, its transcript is protected (edit_status <> 'Pending'),
 * or it has too few baselines. Idempotent — safe to re-run on a cron/loop.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-caption-lib.php';
require_once __DIR__ . '/transcript-helpers.php';

$items = []; $apply = false; $quiet = false; $minB = 3; $minW = 1; $max = 0;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') $apply = true;
    elseif ($a === '--quiet') $quiet = true;
    elseif ($a === '--stdin') { foreach (preg_split('/\s+/', (string)stream_get_contents(STDIN)) as $t) if ($t !== '') $items[] = (int)$t; }
    elseif (strncmp($a, '--list=', 7) === 0) { foreach (preg_split('/\s+/', (string)@file_get_contents(substr($a, 7))) as $t) if ($t !== '') $items[] = (int)$t; }
    elseif (strncmp($a, '--min-baselines=', 16) === 0) $minB = (int)substr($a, 16);
    elseif (strncmp($a, '--min-word=', 11) === 0) $minW = (int)substr($a, 11);
    elseif (strncmp($a, '--max=', 6) === 0) $max = (int)substr($a, 6);
    elseif ($a[0] !== '-') $items[] = (int)$a;
}
$items = array_values(array_unique(array_filter($items)));
if (!$items) { fwrite(STDERR, "usage: _timing_consensus.php <keys|--list=F|--stdin> [--apply]\n"); exit(1); }

$db = getDb();
$qFlight = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript_job WHERE feed_item_key=? AND job_status IN ('pending','running')");
$qInit   = $db->prepare("SELECT COUNT(*) FROM yy_feed_item_transcript_init_job WHERE job_item_key=? AND job_status IN ('pending','running')");
$qStatus = $db->prepare("SELECT edit_status FROM yy_feed_item_transcript_status WHERE feed_item_key=?");
$qModels = $db->prepare("SELECT DISTINCT feed_item_transcript_auto_model m FROM yy_feed_item_transcript_auto WHERE feed_item_key=?");
$qTouch  = $db->prepare("SELECT (SELECT COUNT(*) FROM yy_transcript_edit_log e WHERE e.feed_item_key=? AND COALESCE(e.edit_user_key,0)<>0)
                              + (SELECT COUNT(*) FROM yy_feed_item_transcript_validation v WHERE v.feed_item_key=?)");
$ins = $db->prepare("INSERT INTO yy_feed_item_transcript_init_job (job_item_key, job_model, job_user_key, job_params, job_priority)
                     VALUES (?, 'consensus', NULL, ?, 0) RETURNING job_key");

$n = 0; $reasons = [];
foreach ($items as $ik) {
    if ($max && $n >= $max) break;
    $qFlight->execute([$ik]);  if ((int)$qFlight->fetchColumn() > 0) { $reasons['stt in flight'] = ($reasons['stt in flight'] ?? 0) + 1; continue; }
    $qInit->execute([$ik]);    if ((int)$qInit->fetchColumn() > 0)   { $reasons['init in flight'] = ($reasons['init in flight'] ?? 0) + 1; continue; }
    $qStatus->execute([$ik]);  $st = (string)$qStatus->fetchColumn();
    if ($st !== 'Pending')     { $reasons['protected/' . ($st ?: 'no-status')] = ($reasons['protected/' . ($st ?: 'no-status')] ?? 0) + 1; continue; }
    $qTouch->execute([$ik, $ik]);
    if ((int)$qTouch->fetchColumn() > 0) { $reasons['human edits'] = ($reasons['human edits'] ?? 0) + 1; continue; }

    $qModels->execute([$ik]);
    $models = array_values(array_filter($qModels->fetchAll(PDO::FETCH_COLUMN)));
    $denied = function_exists('cfDeniedEngines') ? cfDeniedEngines($db) : [];
    $useable = array_values(array_diff($models, $denied));
    $wordN = count(array_filter($useable, fn($m) => str_ends_with($m, '-word')));
    if (count($useable) < $minB || $wordN < $minW) {
        $reasons['too few baselines (' . count($useable) . '/' . $wordN . 'w)'] = ($reasons['too few baselines (' . count($useable) . '/' . $wordN . 'w)'] ?? 0) + 1;
        continue;
    }

    // keep-size: cue width = this item's CURRENT median cue length
    $live = cfLoadLive($db, $ik);
    $cl = []; foreach ($live as $r) $cl[] = mb_strlen($r['text']);
    sort($cl);
    $medChars = $cl ? (int)$cl[intdiv(count($cl), 2)] : 80;
    $params = [
        'max_chars'       => max(28, min(140, $medChars)),
        'max_lines'       => 1,
        'preferred_lines' => 1,
        'max_secs'        => 7.0,
        'min_secs'        => 1.2,
        'break_punct'     => true,
        'break_gap'       => 0.6,
        'soft_overflow'   => 1.5,
        'dedup'           => true,
    ];
    $jobParams = json_encode(['baselines' => $useable, 'primary' => '', 'params' => $params, 'wait_for' => []]);
    if (!$quiet) printf("item %-8d baselines=%d (%dw) max_chars=%d  %s\n", $ik, count($useable), $wordN, $params['max_chars'], $apply ? '[APPLY]' : '[dry]');
    if (!$apply) { $n++; continue; }
    $ins->execute([$ik, $jobParams]);
    $n++;
}
if ($apply && $n) spawnNextInitWorker($db);
if (!$quiet) {
    printf("%s: %d consensus job(s).\n", $apply ? 'APPLIED' : 'DRY-RUN', $n);
    foreach ($reasons as $r => $c) printf("  skipped %-34s %d\n", $r, $c);
}
