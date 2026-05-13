<?php
/**
 * Kick off / monitor / cancel a chapter audio build.
 *
 *   POST  { action:'start',  tts_key, volume_key, chapter_key,
 *           output_format?, voice_overrides? }
 *     → { tts_audio_key }
 *     output_format and voice_overrides snapshot the settings into
 *     yy_tts_audio.tts_audio_settings; the worker reads from there rather
 *     than from yy_tts_category_voice so concurrent admin edits don't
 *     affect an in-flight build.
 *
 *   GET   ?action=status&tts_audio_key=N
 *     → status row from yy_tts_audio
 *
 *   POST  { action:'cancel', tts_audio_key }
 *     → marks job 'failed' with note; worker checks this each paragraph
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data = [];
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
}

if ($method === 'GET' && $action === 'status') {
    $audioKey = (int)($_GET['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');
    $stmt = $db->prepare("SELECT * FROM yy_tts_audio WHERE tts_audio_key = ?");
    $stmt->execute([$audioKey]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('not found', 404);
    jsonResponse($row);
}

if ($method !== 'POST') errorResponse('POST required');

if ($action === 'start') {
    $ttsKey     = (int)($data['tts_key']     ?? 0);
    $volumeKey  = (int)($data['volume_key']  ?? 0);
    $chapterKey = (int)($data['chapter_key'] ?? 0);
    if (!$ttsKey || !$volumeKey || !$chapterKey) errorResponse('tts_key, volume_key, chapter_key required');

    // Snapshot the current category-voice config so the worker uses a
    // stable picture even if admin edits voices mid-build.
    $cfg = loadTtsConfig($db, $ttsKey);
    if (!$cfg['system']) errorResponse('unknown tts_key', 404);

    $outputFormat = !empty($data['output_format']) ? (string)$data['output_format'] : $cfg['system']['tts_output_format'];

    $catSnapshot = [];
    foreach ($cfg['categories'] as $cat => $row) {
        $catSnapshot[$cat] = [
            'voice_code'   => $row['tts_voice_code'],
            'style'        => $row['tts_voice_style'],
            'style_degree' => (float)$row['tts_voice_style_degree'],
            'rate_pct'     => (int)$row['tts_voice_rate_pct'],
            'pitch_st'     => (float)$row['tts_voice_pitch_st'],
            'volume'       => (int)$row['tts_voice_volume'],
        ];
    }
    // Caller-supplied per-category overrides win over the saved defaults.
    foreach ((array)($data['voice_overrides'] ?? []) as $cat => $over) {
        if (!is_array($over)) continue;
        $catSnapshot[$cat] = array_merge($catSnapshot[$cat] ?? [], array_intersect_key($over, [
            'voice_code' => 1, 'style' => 1, 'style_degree' => 1, 'rate_pct' => 1, 'pitch_st' => 1, 'volume' => 1,
        ]));
    }

    $settings = [
        'output_format' => $outputFormat,
        'region'        => $cfg['system']['tts_region'] ?? null,
        'categories'    => $catSnapshot,
        'tts_code'      => $cfg['system']['tts_code'],
    ];

    // Cancel any existing pending/running build for this slot before queuing.
    $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status = 'failed',
               tts_audio_error  = 'superseded by new build',
               tts_audio_completed_dtime = NOW()
         WHERE tts_key = ? AND volume_key = ? AND chapter_key = ?
           AND tts_audio_status IN ('pending', 'running')
    ")->execute([$ttsKey, $volumeKey, $chapterKey]);

    // INSERT … ON CONFLICT reuses the existing row when a (tts_key,
    // volume_key, chapter_key) record already exists from a prior build
    // attempt (failed or complete). The partial unique index includes
    // a WHERE chapter_key IS NOT NULL predicate, so the ON CONFLICT
    // target must repeat it.
    // On a re-build we KEEP tts_audio_path, _duration_secs, _size_bytes,
    // _live_dtime and the existing markers untouched so the prior live
    // MP3 (if any) stays playable during the rebuild AND remains the
    // fallback when the rebuild has failures. The worker swaps in the
    // fresh audio + markers only when it produces a clean build.
    // tts_audio_failed_paragraphs is cleared so the queue promoter
    // treats this pending row as a fresh build, not a retry.
    $insStmt = $db->prepare("
        INSERT INTO yy_tts_audio
            (tts_key, volume_key, chapter_key, tts_audio_status, tts_audio_progress,
             tts_audio_message, tts_audio_settings, tts_audio_started_dtime)
        VALUES (?, ?, ?, 'pending', 0, 'Queued', ?::jsonb, NOW())
        ON CONFLICT (tts_key, volume_key, chapter_key) WHERE chapter_key IS NOT NULL
        DO UPDATE SET
            tts_audio_status             = 'pending',
            tts_audio_progress           = 0,
            tts_audio_message            = 'Queued',
            tts_audio_error              = NULL,
            tts_audio_settings           = EXCLUDED.tts_audio_settings,
            tts_audio_started_dtime      = NOW(),
            tts_audio_completed_dtime    = NULL,
            tts_audio_worker_pid         = NULL,
            tts_audio_failed_paragraphs  = NULL,
            tts_audio_revision_dtime     = NOW()
        RETURNING tts_audio_key
    ");
    $insStmt->execute([$ttsKey, $volumeKey, $chapterKey, json_encode($settings)]);
    $audioKey = (int)$insStmt->fetchColumn();

    // Concurrency cap: at most TTS_MAX_CONCURRENT chapter builds may run
    // simultaneously. If we're at the cap, leave the row in 'pending'
    // status; the next worker to finish will pick it up via the queue
    // promotion at the end of admin-tts-build-worker.php.
    $maxConcurrent = 2;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio WHERE tts_audio_status = 'running'")->fetchColumn();
    $queued  = false;
    $workerScript = __DIR__ . '/admin-tts-build-worker.php';
    if ($running < $maxConcurrent && file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/tts_build_' . $audioKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$audioKey], $logFile, [
            'cpu_secs' => 3600, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid = ? WHERE tts_audio_key = ?")
               ->execute([$pid, $audioKey]);
        }
    } else {
        $queued = true;
        $db->prepare("UPDATE yy_tts_audio SET tts_audio_message = ? WHERE tts_audio_key = ?")
           ->execute(["Queued — waiting for an open slot (limit: $maxConcurrent concurrent)", $audioKey]);
    }
    jsonResponse(['tts_audio_key' => $audioKey, 'queued' => $queued]);
}

if ($action === 'cancel') {
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');
    $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status = 'failed',
               tts_audio_error  = 'cancelled by admin',
               tts_audio_completed_dtime = NOW()
         WHERE tts_audio_key = ?
           AND tts_audio_status IN ('pending', 'running')
    ")->execute([$audioKey]);
    jsonResponse(['ok' => true]);
}

// Retry only the paragraphs listed in tts_audio_failed_paragraphs. The
// worker reads cached per-paragraph bytes for everything else, so the
// caller doesn't re-pay Azure for already-good paragraphs.
if ($action === 'retry_failed') {
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');

    $row = $db->prepare("SELECT tts_audio_status, tts_audio_failed_paragraphs FROM yy_tts_audio WHERE tts_audio_key = ?");
    $row->execute([$audioKey]);
    $r = $row->fetch();
    if (!$r) errorResponse('not found', 404);
    if ($r['tts_audio_status'] !== 'complete') {
        errorResponse('retry_failed: chapter status must be complete (currently ' . $r['tts_audio_status'] . ')');
    }
    $failed = $r['tts_audio_failed_paragraphs'];
    if ($failed === null || $failed === '' || $failed === '{}') {
        errorResponse('retry_failed: no failed paragraphs on this chapter');
    }

    $db->prepare("
        UPDATE yy_tts_audio
           SET tts_audio_status        = 'pending',
               tts_audio_progress      = 0,
               tts_audio_message       = 'Queued for retry',
               tts_audio_error         = NULL,
               tts_audio_started_dtime = NOW(),
               tts_audio_completed_dtime = NULL,
               tts_audio_revision_dtime  = NOW(),
               tts_audio_worker_pid    = NULL
         WHERE tts_audio_key = ?
    ")->execute([$audioKey]);

    $maxConcurrent = 2;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_tts_audio WHERE tts_audio_status = 'running'")->fetchColumn();
    $queued = false;
    $workerScript = __DIR__ . '/admin-tts-build-worker.php';
    if ($running < $maxConcurrent && file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/tts_build_' . $audioKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$audioKey, 'retry'], $logFile, [
            'cpu_secs' => 3600, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid = ? WHERE tts_audio_key = ?")
               ->execute([$pid, $audioKey]);
        }
    } else {
        $queued = true;
        $db->prepare("UPDATE yy_tts_audio SET tts_audio_message = ? WHERE tts_audio_key = ?")
           ->execute(["Queued — waiting for an open slot (limit: $maxConcurrent concurrent)", $audioKey]);
    }
    jsonResponse(['tts_audio_key' => $audioKey, 'queued' => $queued, 'retrying' => $failed]);
}

// Delete a chapter's audio entirely — the row, the markers, the live MP3
// file on disk, and the per-paragraph parts cache. Disallowed while a
// build is in-flight; admin should hit Cancel first.
if ($action === 'delete') {
    $audioKey = (int)($data['tts_audio_key'] ?? 0);
    if (!$audioKey) errorResponse('tts_audio_key required');

    $row = $db->prepare("SELECT tts_audio_status, tts_audio_path FROM yy_tts_audio WHERE tts_audio_key = ?");
    $row->execute([$audioKey]);
    $r = $row->fetch();
    if (!$r) errorResponse('not found', 404);
    if (in_array($r['tts_audio_status'], ['pending', 'running'], true)) {
        errorResponse('cannot delete while build is in-flight — cancel first');
    }

    // Wipe the markers, then the audio row. _rev tables are written by
    // triggers — no manual cleanup required.
    $db->prepare("DELETE FROM yy_tts_audio_marker WHERE tts_audio_key = ?")->execute([$audioKey]);
    $db->prepare("DELETE FROM yy_tts_audio WHERE tts_audio_key = ?")->execute([$audioKey]);

    // Remove the on-disk artifacts. Resolve via the same path-resolution
    // logic the worker uses so this works whether PHP is in the
    // container (/var/www/html bind mount) or on the host.
    $audioBase = is_dir(dirname(__DIR__) . '/u') ? dirname(__DIR__) : '/opt/yada-www/public';
    $relPath   = (string)($r['tts_audio_path'] ?? '');
    if ($relPath !== '') {
        // tts_audio_path may have a ?v=… cache buster appended by tts-audio.php.
        // The DB column itself shouldn't, but strip defensively just in case.
        $clean = preg_replace('/\?.*$/', '', $relPath);
        $diskPath = $audioBase . $clean;
        if (is_file($diskPath)) @unlink($diskPath);
        if (is_file($diskPath . '.staging')) @unlink($diskPath . '.staging');
    }
    // Per-paragraph parts directory: u/tts-parts/<audio_key>/
    $partsDir = $audioBase . '/u/tts-parts/' . $audioKey;
    if (is_dir($partsDir)) {
        foreach (glob($partsDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($partsDir);
    }

    jsonResponse(['ok' => true, 'tts_audio_key' => $audioKey]);
}

errorResponse('Unknown action');
