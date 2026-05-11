<?php
/**
 * Multi-model Transcribe Audio backend.
 *
 *   GET  ?item_key=N&action=status
 *     → {
 *         models: [
 *           { code:'whisper-1',             label:'OpenAI whisper-1',           last_run:'2026-05-09T18:30Z' | null },
 *           { code:'gpt-4o-mini-transcribe', label:'OpenAI gpt-4o-mini-transcribe', last_run: null },
 *           ...
 *         ],
 *         active_job: { job_key, job_model, job_status, job_progress, job_message } | null
 *       }
 *
 *   POST  { action:'run', item_key:N, model:'whisper-1' }
 *     → enqueues a worker job with job_model set; returns { job_key }
 *
 *   GET  ?item_key=N&action=job&job_key=K
 *     → { job_status, job_progress, job_message, job_completed_dtime } (for UI polling)
 *
 * Per the post-refactor pipeline:
 *   - Worker writes to yy_feed_item_transcript_auto  (with model)
 *     and yy_feed_item_transcript_autoclean (with model). The live table
 *     yy_feed_item_transcript is NEVER touched here.
 *   - "Initialize Transcript" (separate endpoint) is what copies a chosen
 *     model's autoclean version into the live table.
 *
 * Last-run timestamp is computed from the FIRST segment (00:00:00) row of
 * each (item, model) — that's the canonical "this model ran successfully
 * at time T" marker.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finalize-helpers.php'; // spawnCappedWorker via spawn-helpers indirectly
require_once __DIR__ . '/spawn-helpers.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

// Internal model codes map (in the worker) to the OpenAI model name plus
// a timestamp_granularities setting. whisper-1 is exposed as TWO entries:
//   whisper-1-segment — phrase-level row per Whisper segment
//   whisper-1-word    — one row per word, sub-second precision. Same
//                       OpenAI cost as whisper-1-segment; trades coarse
//                       segment rows for fine-grained alignment.
// gpt-4o-mini-transcribe and gpt-4o-transcribe removed 2026-05-11 — they
// emit one row per ~10-min chunk (no segment timestamps) and produced
// transcriptions that were not useful enough to justify the spend.
// Existing _auto rows for those models remain in the DB and show up in
// Analyze as historical columns, but they're no longer pickable here.
$AVAILABLE_MODELS = [
    ['code' => 'whisper-1-segment',      'label' => 'OpenAI whisper-1 — segment timestamps ($0.006/min)'],
    ['code' => 'whisper-1-word',         'label' => 'OpenAI whisper-1 — word-level timestamps ($0.006/min)'],
    ['code' => 'youtube',                'label' => 'YouTube auto-captions (free, requires fresh cookies)'],
];

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data = [];
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
}

if ($method === 'GET' && $action === 'status') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');

    // Last-run timestamp per model = MAX revision_dtime where segment is 00:00:00
    // (Postgres interval '00:00:00' is one canonical first-row marker).
    $stmt = $db->prepare("
        SELECT feed_item_transcript_auto_model AS model,
               MAX(feed_item_transcript_revision_dtime) AS last_run
          FROM yy_feed_item_transcript_auto
         WHERE feed_item_key = ?
           AND feed_item_transcript_segment = '00:00:00'::interval
         GROUP BY feed_item_transcript_auto_model
    ");
    $stmt->execute([$itemKey]);
    $runByModel = [];
    foreach ($stmt->fetchAll() as $r) {
        $runByModel[$r['model']] = $r['last_run'];
    }

    $models = [];
    foreach ($AVAILABLE_MODELS as $m) {
        $models[] = [
            'code'     => $m['code'],
            'label'    => $m['label'],
            'last_run' => $runByModel[$m['code']] ?? null,
        ];
    }

    // Most recent pending/running job for this item (UI shows progress).
    $jobStmt = $db->prepare("
        SELECT feed_item_transcript_job_key AS job_key, job_model, job_status, job_progress, job_message
          FROM yy_feed_item_transcript_job
         WHERE feed_item_key = ?
           AND job_status IN ('pending', 'running')
         ORDER BY job_dtime DESC LIMIT 1
    ");
    $jobStmt->execute([$itemKey]);
    $activeJob = $jobStmt->fetch() ?: null;

    jsonResponse(['models' => $models, 'active_job' => $activeJob]);
}

if ($method === 'GET' && $action === 'job') {
    $jobKey = (int)($_GET['job_key'] ?? 0);
    if (!$jobKey) errorResponse('job_key required');
    $stmt = $db->prepare("
        SELECT feed_item_transcript_job_key AS job_key, job_model, job_status, job_progress, job_message,
               job_completed_dtime, job_error
          FROM yy_feed_item_transcript_job
         WHERE feed_item_transcript_job_key = ?
    ");
    $stmt->execute([$jobKey]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('job not found', 404);
    jsonResponse($row);
}

if ($method === 'POST' && $action === 'run') {
    $itemKey = (int)($data['item_key'] ?? 0);
    $model   = trim($data['model'] ?? '');
    if (!$itemKey) errorResponse('item_key required');
    $valid = array_column($AVAILABLE_MODELS, 'code');
    if (!in_array($model, $valid, true)) errorResponse('invalid model: ' . $model);

    // Cancel any in-flight job for this item before queuing a new one. The
    // UI runs models sequentially, so there should normally be none, but be
    // defensive if the user double-clicks or two tabs are open.
    $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'cancelled', job_completed_dtime = NOW() WHERE feed_item_key = ? AND job_status IN ('pending', 'running')")
       ->execute([$itemKey]);

    $insStmt = $db->prepare("
        INSERT INTO yy_feed_item_transcript_job
            (feed_item_key, job_status, job_message, user_key, job_model)
        VALUES (?, 'pending', ?, ?, ?)
        RETURNING feed_item_transcript_job_key
    ");
    $insStmt->execute([$itemKey, 'Queued for model: ' . $model, $user['user_key'], $model]);
    $jobKey = (int)$insStmt->fetchColumn();

    // Spawn the worker — uses the same script as before, but the worker
    // branches on job_model to pick OpenAI endpoint vs yt-dlp captions.
    $workerScript = __DIR__ . '/transcript-worker.php';
    if (file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/transcript_' . $jobKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$jobKey], $logFile, [
            'cpu_secs' => 2400, 'mem_mb' => 2000, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_feed_item_transcript_job SET job_worker_pid = ? WHERE feed_item_transcript_job_key = ?")
               ->execute([$pid, $jobKey]);
        }
    }
    jsonResponse(['job_key' => $jobKey, 'model' => $model]);
}

errorResponse('Unknown action');
