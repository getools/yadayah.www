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

    $insStmt = $db->prepare("
        INSERT INTO yy_tts_audio
            (tts_key, volume_key, chapter_key, tts_audio_status, tts_audio_progress,
             tts_audio_message, tts_audio_settings, tts_audio_started_dtime)
        VALUES (?, ?, ?, 'pending', 0, 'Queued', ?::jsonb, NOW())
        RETURNING tts_audio_key
    ");
    $insStmt->execute([$ttsKey, $volumeKey, $chapterKey, json_encode($settings)]);
    $audioKey = (int)$insStmt->fetchColumn();

    $workerScript = __DIR__ . '/admin-tts-build-worker.php';
    if (file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/tts_build_' . $audioKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$audioKey], $logFile, [
            'cpu_secs' => 3600, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_tts_audio SET tts_audio_worker_pid = ? WHERE tts_audio_key = ?")
               ->execute([$pid, $audioKey]);
        }
    }
    jsonResponse(['tts_audio_key' => $audioKey]);
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

errorResponse('Unknown action');
