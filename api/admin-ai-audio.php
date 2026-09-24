<?php
/**
 * AI generation admin endpoint — Audio tab (yy_t2a_job).
 *
 *   GET   ?action=list_providers           providers mapped to functionality='t2a'
 *   GET   ?action=list_jobs[&limit=N]      recent yy_t2a_job rows
 *   GET   ?action=status&t2a_job_key=N     one job row
 *   GET   ?action=list_recent_outputs[&limit=N]
 *                                          recent COMPLETE audio for the
 *                                          "use a previous take as reference" picker
 *   POST  multipart:
 *         provider_key, prompt, lyrics?, negative_prompt?, params(json),
 *         ref_audio[]?    optional style / init reference clip(s)
 *         prev_ref?       JSON {kind:'audio', key:N, index?:N} — copies a
 *                         previous output in as the reference clip.
 *         → {t2a_job_key, queued}
 *
 *   POST  application/json {action:'cancel'|'delete', t2a_job_key:N}
 *
 * Concurrency cap = 1 (single GPU; mirrors the image/video tabs).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/spawn-helpers.php';
require_once __DIR__ . '/ai-schema-lib.php';

$user = requireAuth();
$db   = getDb();
setCurrentUser($db, (int)$user['user_key']);

$method      = $_SERVER['REQUEST_METHOD'];
$action      = $_GET['action'] ?? '';
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$isMultipart = stripos($contentType, 'multipart/form-data') === 0;
$data        = [];
if ($method === 'POST' && !$isMultipart) {
    $data   = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
}

const T2A_AUDIO_EXTS = ['wav', 'mp3', 'flac', 'ogg', 'm4a', 'opus'];

function _flagTrue($v): bool {
    return ($v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true');
}

// ── GET list_providers ────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list_providers') {
    $stmt = $db->prepare("
      SELECT p.provider_key, p.provider_label AS label, p.provider_model_id AS model_id,
             p.provider_endpoint AS endpoint, p.provider_settings AS settings,
             p.provider_active_flag AS provider_active,
             fp.functionality_provider_sort AS sort,
             fp.functionality_provider_active_flag AS map_active
        FROM yy_functionality f
        JOIN yy_functionality_provider fp ON fp.functionality_key = f.functionality_key
        JOIN yy_provider p ON p.provider_key = fp.provider_key
       WHERE f.functionality_code = 't2a'
       ORDER BY fp.functionality_provider_sort, p.provider_label
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $s = is_string($r['settings']) ? (json_decode($r['settings'], true) ?: []) : ($r['settings'] ?: []);
        $r['settings']            = $s;
        $r['family']              = (string)($s['family'] ?? 'music');
        $r['supports_lyrics']     = !empty($s['supports_lyrics']);
        $r['supports_ref_audio']  = !empty($s['supports_ref_audio']);
        $r['max_duration_sec']    = (float)($s['max_duration_sec'] ?? 60);
        $r['max_batch']           = (int)($s['max_batch'] ?? 4);
        $r['sample_rate']         = (int)($s['sample_rate'] ?? 44100);
        $r['notes']               = (string)($s['notes'] ?? '');
        $r['online']              = !empty($s['online']);
        $r['provider_active']     = _flagTrue($r['provider_active']);
        $r['map_active']          = _flagTrue($r['map_active']);
        $r['sort']                = (int)$r['sort'];
    }
    unset($r);
    // Attach each engine's self-described input schema (from /models, cached).
    $schemas = ai_fetch_param_schemas('t2a', $rows);
    foreach ($rows as &$r) { $r['param_schema'] = $schemas[$r['model_id']] ?? null; }
    unset($r);
    jsonResponse(['providers' => $rows]);
}

// ── GET list_recent_outputs (reference-clip picker) ───────────────────
if ($method === 'GET' && $action === 'list_recent_outputs') {
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 24)));
    $stmt = $db->prepare("
      SELECT t2a_job_key AS key, t2a_job_prompt AS prompt, t2a_job_outputs AS outputs,
             t2a_job_completed_dtime AS completed, t2a_job_model_id AS model_id
        FROM yy_t2a_job
       WHERE t2a_job_status='complete' AND jsonb_array_length(t2a_job_outputs) > 0
       ORDER BY t2a_job_completed_dtime DESC LIMIT ?
    ");
    $stmt->execute([$limit]);
    $audios = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $outs = is_string($r['outputs']) ? (json_decode($r['outputs'], true) ?: []) : ($r['outputs'] ?: []);
        foreach ($outs as $i => $out) {
            $audios[] = [
                'key'       => (int)$r['key'],
                'index'     => $i,
                'prompt'    => $r['prompt'],
                'path'      => is_array($out) ? ($out['path'] ?? '') : (string)$out,
                'completed' => $r['completed'],
                'model_id'  => $r['model_id'],
            ];
        }
    }
    jsonResponse(['audios' => $audios]);
}

// ── GET list_jobs ─────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list_jobs') {
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $stmt = $db->prepare("
      SELECT j.*, p.provider_label, p.provider_model_id
        FROM yy_t2a_job j
        LEFT JOIN yy_provider p ON p.provider_key = j.provider_key
       ORDER BY j.t2a_job_dtime DESC LIMIT ?
    ");
    $stmt->execute([$limit]);
    jsonResponse(['jobs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── GET status ────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'status') {
    $jobKey = (int)($_GET['t2a_job_key'] ?? 0);
    if (!$jobKey) errorResponse('t2a_job_key required');
    $stmt = $db->prepare("
      SELECT j.*, p.provider_label, p.provider_model_id
        FROM yy_t2a_job j
        LEFT JOIN yy_provider p ON p.provider_key = j.provider_key
       WHERE j.t2a_job_key = ?
    ");
    $stmt->execute([$jobKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) errorResponse('not found', 404);
    jsonResponse($row);
}

// ── POST cancel ───────────────────────────────────────────────────────
if ($method === 'POST' && !$isMultipart && $action === 'cancel') {
    $jobKey = (int)($data['t2a_job_key'] ?? 0);
    if (!$jobKey) errorResponse('t2a_job_key required');
    $db->prepare("
      UPDATE yy_t2a_job
         SET t2a_job_status='cancelled', t2a_job_completed_dtime=NOW(),
             t2a_job_message='cancelled by admin'
       WHERE t2a_job_key=? AND t2a_job_status IN ('pending','running')
    ")->execute([$jobKey]);
    jsonResponse(['ok' => true]);
}

// ── POST delete ───────────────────────────────────────────────────────
if ($method === 'POST' && !$isMultipart && $action === 'delete') {
    $jobKey = (int)($data['t2a_job_key'] ?? 0);
    if (!$jobKey) errorResponse('t2a_job_key required');

    $hostBase = '/opt/yada-www/public';
    $contBase = dirname(__DIR__);
    $fsBase   = is_dir($contBase) ? $contBase : $hostBase;

    foreach (['/u/t2a-uploads/', '/u/t2a-outputs/'] as $base) {
        $d = $fsBase . $base . $jobKey;
        if (is_dir($d)) {
            foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
            @rmdir($d);
        }
    }
    $db->prepare("DELETE FROM yy_t2a_job WHERE t2a_job_key = ?")->execute([$jobKey]);
    jsonResponse(['ok' => true]);
}

// ── POST multipart start ──────────────────────────────────────────────
if ($method === 'POST' && $isMultipart) {
    $providerKey    = (int)($_POST['provider_key'] ?? 0);
    $prompt         = trim((string)($_POST['prompt'] ?? ''));
    $lyrics         = trim((string)($_POST['lyrics'] ?? ''));
    $negativePrompt = trim((string)($_POST['negative_prompt'] ?? ''));
    $paramsRaw      = (string)($_POST['params'] ?? '{}');
    $prevRefRaw     = trim((string)($_POST['prev_ref'] ?? ''));
    if (!$providerKey || $prompt === '') errorResponse('provider_key and prompt required');
    $params = json_decode($paramsRaw, true);
    if (!is_array($params)) $params = [];

    // Provider must be t2a-mapped.
    $pStmt = $db->prepare("
      SELECT p.provider_model_id, p.provider_endpoint, p.provider_active_flag, p.provider_settings
        FROM yy_provider p
        JOIN yy_functionality_provider fp ON fp.provider_key = p.provider_key
        JOIN yy_functionality f ON f.functionality_key = fp.functionality_key
       WHERE p.provider_key = ? AND f.functionality_code = 't2a'
    ");
    $pStmt->execute([$providerKey]);
    $prov = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$prov) errorResponse('provider not registered for t2a', 400);

    $ins = $db->prepare("
      INSERT INTO yy_t2a_job
        (provider_key, t2a_job_model_id, t2a_job_prompt, t2a_job_lyrics,
         t2a_job_negative_prompt, t2a_job_params, t2a_job_status, t2a_job_message)
      VALUES (?, ?, ?, ?, ?, ?::jsonb, 'pending', 'Queued')
      RETURNING t2a_job_key
    ");
    $ins->execute([
        $providerKey,
        $prov['provider_model_id'],
        $prompt,
        $lyrics !== '' ? $lyrics : null,
        $negativePrompt !== '' ? $negativePrompt : null,
        json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $jobKey = (int)$ins->fetchColumn();

    // Per-job uploads dir under /public/u/t2a-uploads/<key>/
    $hostUploadsBase = '/opt/yada-www/public/u/t2a-uploads/';
    $contUploadsBase = dirname(__DIR__) . '/u/t2a-uploads/';
    $uploadsBase     = is_dir(dirname(__DIR__)) ? $contUploadsBase : $hostUploadsBase;
    $jobDir          = $uploadsBase . $jobKey;
    if (!is_dir($jobDir) && !@mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        $db->prepare("UPDATE yy_t2a_job SET t2a_job_status='failed', t2a_job_error='cannot create uploads dir' WHERE t2a_job_key=?")->execute([$jobKey]);
        errorResponse('cannot create uploads dir');
    }

    $inputAudio = [];

    // Resolve prev_ref (a previously generated take reused as the reference).
    if ($prevRefRaw !== '') {
        $ref = json_decode($prevRefRaw, true);
        if (is_array($ref) && !empty($ref['key'])) {
            $stmt = $db->prepare("SELECT t2a_job_outputs FROM yy_t2a_job WHERE t2a_job_key=?");
            $stmt->execute([(int)$ref['key']]);
            $outsRaw = $stmt->fetchColumn();
            $outs = is_string($outsRaw) ? (json_decode($outsRaw, true) ?: []) : ($outsRaw ?: []);
            $idx = (int)($ref['index'] ?? 0);
            $sel = $outs[$idx] ?? null;
            $refPath = $sel ? (is_array($sel) ? ($sel['path'] ?? null) : (string)$sel) : null;
            if ($refPath) {
                $hostPath = '/opt/yada-www/public' . $refPath;
                $contPath = dirname(__DIR__) . $refPath;
                $absSrc   = is_file($contPath) ? $contPath : $hostPath;
                $ext      = strtolower(pathinfo($absSrc, PATHINFO_EXTENSION));
                if (is_file($absSrc) && in_array($ext, T2A_AUDIO_EXTS, true)) {
                    $dest = sprintf('%s/ref_00.%s', $jobDir, $ext);
                    if (@copy($absSrc, $dest)) {
                        $inputAudio[] = [
                            'path'   => '/u/t2a-uploads/' . $jobKey . '/' . basename($dest),
                            'role'   => 'reference',
                            'source' => 'prev_ref',
                            'ref'    => $ref,
                        ];
                    }
                }
            }
        }
    }

    // Handle ref_audio uploads (multiple optional).
    $files = $_FILES['ref_audio'] ?? null;
    if ($files && !empty($files['tmp_name'])) {
        $names = is_array($files['name'])     ? $files['name']     : [$files['name']];
        $tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errs  = is_array($files['error'])    ? $files['error']    : [$files['error']];
        foreach ($tmps as $i => $tmp) {
            if ($errs[$i] !== UPLOAD_ERR_OK) continue;
            $orig = (string)($names[$i] ?? "audio_$i");
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, T2A_AUDIO_EXTS, true)) continue;
            $idx  = count($inputAudio);
            $dest = sprintf('%s/ref_%02d.%s', $jobDir, $idx, $ext);
            if (@move_uploaded_file($tmp, $dest)) {
                $inputAudio[] = [
                    'path'   => '/u/t2a-uploads/' . $jobKey . '/' . basename($dest),
                    'role'   => 'reference',
                    'source' => 'upload',
                ];
            }
        }
    }

    $db->prepare("UPDATE yy_t2a_job SET t2a_job_input_audio = ?::jsonb WHERE t2a_job_key = ?")
       ->execute([json_encode($inputAudio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $jobKey]);

    // Spawn worker (cap = 1; mirrors image/video).
    $maxConcurrent = 1;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_t2a_job WHERE t2a_job_status='running'")->fetchColumn();
    $queued  = false;
    $workerScript = __DIR__ . '/admin-ai-audio-build-worker.php';
    if ($running < $maxConcurrent && file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/ai_audio_build_' . $jobKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$jobKey], $logFile, [
            'cpu_secs' => 10800, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_t2a_job SET t2a_job_worker_pid=?, t2a_job_started_dtime=NOW() WHERE t2a_job_key=?")
               ->execute([$pid, $jobKey]);
        }
    } else {
        $queued = true;
        $db->prepare("UPDATE yy_t2a_job SET t2a_job_message=? WHERE t2a_job_key=?")
           ->execute(["Queued — waiting for an open slot (limit: $maxConcurrent)", $jobKey]);
    }
    jsonResponse(['t2a_job_key' => $jobKey, 'queued' => $queued]);
}

// ── POST retry ─────────────────────────────────────────────────────────
// Re-queue a finished job with its own settings. `reroll` drops the pinned
// seed so the same prompt produces a different take; without it the job is
// reproduced exactly. The source row is left untouched — a retry is a NEW
// job, so the history of what failed (and why) survives.
if ($method === 'POST' && !$isMultipart && $action === 'retry') {
    $jobKey = (int)($data['t2a_job_key'] ?? 0);
    $reroll = !empty($data['reroll']);
    if (!$jobKey) errorResponse('t2a_job_key required');

    $sStmt = $db->prepare("SELECT * FROM yy_t2a_job WHERE t2a_job_key=?");
    $sStmt->execute([$jobKey]);
    $src = $sStmt->fetch(PDO::FETCH_ASSOC);
    if (!$src) errorResponse('not found', 404);
    $srcStatus = (string)$src['t2a_job_status'];
    if ($srcStatus === 'pending' || $srcStatus === 'running') {
        errorResponse("job #$jobKey is still $srcStatus — cancel it first", 409);
    }

    $params = is_string($src['t2a_job_params']) ? (json_decode($src['t2a_job_params'], true) ?: []) : ($src['t2a_job_params'] ?: []);
    if (!is_array($params)) $params = [];
    if ($reroll) unset($params['seed']);

    $ins = $db->prepare("
      INSERT INTO yy_t2a_job
        (provider_key, t2a_job_model_id, t2a_job_prompt, t2a_job_lyrics, t2a_job_negative_prompt, t2a_job_params, t2a_job_status, t2a_job_message)
      VALUES (?, ?, ?, ?, ?, ?::jsonb, 'pending', ?)
      RETURNING t2a_job_key
    ");
    $ins->execute([
        (int)$src['provider_key'],
        $src['t2a_job_model_id'],
        $src['t2a_job_prompt'],
        $src['t2a_job_lyrics'],
        $src['t2a_job_negative_prompt'],
        json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ($reroll ? 'Reroll' : 'Retry') . ' of job #' . $jobKey,
    ]);
    $newKey = (int)$ins->fetchColumn();

    // Copy the operator's own inputs into the new job's directory, so deleting
    // either job leaves the other intact.
    $srcInputs = is_string($src['t2a_job_input_audio']) ? (json_decode($src['t2a_job_input_audio'], true) ?: []) : ($src['t2a_job_input_audio'] ?: []);
    $fsBase  = is_dir(dirname(__DIR__)) ? dirname(__DIR__) : '/opt/yada-www/public';
    $newDir  = $fsBase . '/u/t2a-uploads/' . $newKey;
    $newInputs = [];
    foreach ($srcInputs as $in) {
        if (!empty($in['generated'])) continue;
        $rel = (string)($in['path'] ?? '');
        if ($rel === '') continue;
        $absSrc = $fsBase . $rel;
        if (!is_file($absSrc)) continue;
        if (!is_dir($newDir) && !@mkdir($newDir, 0775, true) && !is_dir($newDir)) break;
        $base = basename($absSrc);
        if (!@copy($absSrc, $newDir . '/' . $base)) continue;
        $in['path'] = '/u/t2a-uploads/' . $newKey . '/' . $base;
        $newInputs[] = $in;
    }
    $db->prepare("UPDATE yy_t2a_job SET t2a_job_input_audio = ?::jsonb WHERE t2a_job_key = ?")
       ->execute([json_encode($newInputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $newKey]);

    $maxConcurrent = 1;
    $running = (int)$db->query("SELECT COUNT(*) FROM yy_t2a_job WHERE t2a_job_status='running'")->fetchColumn();
    $queued  = false;
    $workerScript = __DIR__ . '/admin-ai-audio-build-worker.php';
    if ($running < $maxConcurrent && file_exists($workerScript)) {
        $logFile = sys_get_temp_dir() . '/ai_audio_build_' . $newKey . '.log';
        $pid = spawnCappedWorker($workerScript, [(string)$newKey], $logFile, [
            'cpu_secs' => 10800, 'mem_mb' => 1500, 'nice' => 10,
        ]);
        if ($pid > 0) {
            $db->prepare("UPDATE yy_t2a_job SET t2a_job_worker_pid=?, t2a_job_started_dtime=NOW() WHERE t2a_job_key=?")
               ->execute([$pid, $newKey]);
        }
    } else {
        $queued = true;
        $db->prepare("UPDATE yy_t2a_job SET t2a_job_message=? WHERE t2a_job_key=?")
           ->execute(["Queued — waiting for an open slot (limit: $maxConcurrent)", $newKey]);
    }
    jsonResponse(['t2a_job_key' => $newKey, 'from_job_key' => $jobKey, 'queued' => $queued, 'reroll' => $reroll]);
}

errorResponse('unknown action', 400);
