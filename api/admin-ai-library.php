<?php
/**
 * AI character/asset library — durable references for /admin-ai.
 *
 * The engines' only real lever for character consistency is feeding the same
 * reference file back in (only Flux.1 Dev takes an init image at all; every i2v
 * model takes a start frame). Job-owned files can't serve that: deleting a job
 * deletes its uploads dir and output. This endpoint owns copies under
 * /u/ai-library/<asset_key>/ that nothing else cleans up.
 *
 *   GET  ?action=list[&kind=image|video|audio][&character_key=N]
 *                                       library assets, newest first
 *   GET  ?action=list_characters        characters + their asset counts
 *
 *   POST application/json:
 *     {action:'save_from_job', source_kind:'t2i'|'i2v'|'t2a', job_key:N,
 *      index:N?, label?, character_key?}
 *                                       copy a finished output into the library,
 *                                       carrying model/seed/prompt provenance
 *     {action:'update', ai_asset_key:N, label?, notes?, character_key?,
 *      sort?, active?}                  partial — unsupplied fields are left alone
 *     {action:'delete', ai_asset_key:N} row + file
 *     {action:'save_character', name, description?, prompt?, negative_prompt?,
 *      seed?, tts_voice_key?}
 *     {action:'update_character', ai_character_key:N, ...same, partial}
 *     {action:'delete_character', ai_character_key:N}
 *                                       assets survive, unlinked (FK SET NULL)
 *
 *   POST multipart: action=upload, file, kind, label?, character_key?
 *                                       a reference we didn't generate here
 */

require_once __DIR__ . '/config.php';

$user = requireAuth();
$db   = getDb();
setCurrentUser($db, (int)$user['user_key']);

$method      = $_SERVER['REQUEST_METHOD'];
$action      = $_GET['action'] ?? '';
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$isMultipart = stripos($contentType, 'multipart/form-data') === 0;
$data        = [];
if ($method === 'POST') {
    if ($isMultipart) {
        $action = $_POST['action'] ?? $action;
    } else {
        $data   = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $action = $data['action'] ?? $action;
    }
}

const LIB_WEB_BASE = '/u/ai-library/';

/** Both the web container and the host see this tree at different roots. */
function lib_abs_base(): string {
    $cont = dirname(__DIR__) . '/public' . LIB_WEB_BASE;
    if (is_dir(dirname($cont))) return $cont;
    $cont2 = dirname(__DIR__) . LIB_WEB_BASE;
    if (is_dir(dirname($cont2))) return $cont2;
    return '/opt/yada-www/public' . LIB_WEB_BASE;
}

/** Resolve a /u/... web path to whichever absolute root exists here. */
function lib_resolve_web(string $webPath): ?string {
    foreach ([dirname(__DIR__) . '/public' . $webPath,
              dirname(__DIR__) . $webPath,
              '/opt/yada-www/public' . $webPath] as $cand) {
        if (is_file($cand)) return $cand;
    }
    return null;
}

function lib_ext_ok(string $ext, string $kind): bool {
    $ext = strtolower($ext);
    if ($kind === 'image') return in_array($ext, ['png','jpg','jpeg','webp'], true);
    if ($kind === 'video') return in_array($ext, ['mp4','webm'], true);
    if ($kind === 'audio') return in_array($ext, ['wav','mp3','flac','ogg','m4a'], true);
    return false;
}

function lib_jsonb($raw): array {
    if (is_array($raw)) return $raw;
    if (is_string($raw)) return json_decode($raw, true) ?: [];
    return [];
}

/**
 * Resolve a caller-supplied character_key to an int, or null for "ungrouped".
 * Checked rather than trusted: an unknown key would otherwise surface as an FK
 * violation, which reaches the operator as an opaque 500.
 */
function lib_char_key(PDO $db, $raw): ?int {
    if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') return null;
    $key  = (int)$raw;
    $stmt = $db->prepare("SELECT 1 FROM yy_ai_character WHERE ai_character_key=?");
    $stmt->execute([$key]);
    if (!$stmt->fetchColumn()) errorResponse('no such character: ' . $key);
    return $key;
}

/** Delete an asset's directory (each asset owns one). */
function lib_rmdir_asset(int $key): void {
    $dir = lib_abs_base() . $key;
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}

// ── GET list ──────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $where  = ['a.ai_asset_active_flag = TRUE'];
    $params = [];
    $kind = (string)($_GET['kind'] ?? '');
    if ($kind !== '') {
        if (!in_array($kind, ['image','video','audio'], true)) errorResponse('bad kind');
        $where[]  = 'a.ai_asset_kind = ?';
        $params[] = $kind;
    }
    if (isset($_GET['character_key']) && $_GET['character_key'] !== '') {
        $where[]  = 'a.ai_character_key = ?';
        $params[] = (int)$_GET['character_key'];
    }
    $sql = "SELECT a.ai_asset_key, a.ai_character_key, a.ai_asset_kind, a.ai_asset_label,
                   a.ai_asset_path, a.ai_asset_notes, a.ai_asset_model_id, a.ai_asset_prompt,
                   a.ai_asset_negative_prompt, a.ai_asset_seed, a.ai_asset_params,
                   a.ai_asset_source_kind, a.ai_asset_source_job_key, a.ai_asset_size_bytes,
                   a.ai_asset_sort, a.ai_asset_dtime, c.ai_character_name
              FROM yy_ai_asset a
              LEFT JOIN yy_ai_character c ON c.ai_character_key = a.ai_character_key
             WHERE " . implode(' AND ', $where) . "
             ORDER BY a.ai_asset_sort, a.ai_asset_key DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['ai_asset_params'] = lib_jsonb($r['ai_asset_params']); }
    unset($r);
    jsonResponse(['assets' => $rows]);
}

// ── GET list_characters ───────────────────────────────────────────────
if ($method === 'GET' && $action === 'list_characters') {
    $stmt = $db->query("
      SELECT c.ai_character_key, c.ai_character_name, c.ai_character_description,
             c.ai_character_prompt, c.ai_character_negative_prompt, c.ai_character_seed,
             c.tts_voice_key, v.tts_voice_label, c.ai_character_sort,
             COUNT(a.ai_asset_key) FILTER (WHERE a.ai_asset_active_flag) AS asset_count
        FROM yy_ai_character c
        LEFT JOIN yy_tts_voice v ON v.tts_voice_key = c.tts_voice_key
        LEFT JOIN yy_ai_asset  a ON a.ai_character_key = c.ai_character_key
       WHERE c.ai_character_active_flag = TRUE
       GROUP BY c.ai_character_key, v.tts_voice_label
       ORDER BY c.ai_character_sort, c.ai_character_name
    ");
    jsonResponse(['characters' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── POST save_from_job ────────────────────────────────────────────────
if ($method === 'POST' && $action === 'save_from_job') {
    $srcKind = (string)($data['source_kind'] ?? '');
    $jobKey  = (int)($data['job_key'] ?? 0);
    $index   = (int)($data['index'] ?? 0);
    if (!in_array($srcKind, ['t2i','i2v','t2a'], true)) errorResponse('bad source_kind');
    if ($jobKey <= 0) errorResponse('job_key required');

    // Pull the source file path plus the provenance worth keeping.
    $srcPath = null; $meta = [];
    if ($srcKind === 't2i') {
        $stmt = $db->prepare("SELECT provider_key, t2i_job_model_id, t2i_job_prompt,
                                     t2i_job_negative_prompt, t2i_job_params, t2i_job_outputs
                                FROM yy_t2i_job WHERE t2i_job_key=?");
        $stmt->execute([$jobKey]);
        $j = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$j) errorResponse('job not found', 404);
        $outs = lib_jsonb($j['t2i_job_outputs']);
        $sel  = $outs[$index] ?? null;
        if ($sel) $srcPath = is_array($sel) ? ($sel['path'] ?? null) : (string)$sel;
        $meta = ['kind' => 'image', 'provider_key' => $j['provider_key'],
                 'model_id' => $j['t2i_job_model_id'], 'prompt' => $j['t2i_job_prompt'],
                 'neg' => $j['t2i_job_negative_prompt'], 'params' => lib_jsonb($j['t2i_job_params'])];
    } elseif ($srcKind === 'i2v') {
        $stmt = $db->prepare("SELECT provider_key, i2v_job_model_id, i2v_job_prompt,
                                     i2v_job_negative_prompt, i2v_job_params, i2v_job_output_path
                                FROM yy_i2v_job WHERE i2v_job_key=?");
        $stmt->execute([$jobKey]);
        $j = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$j) errorResponse('job not found', 404);
        $srcPath = $j['i2v_job_output_path'];
        $meta = ['kind' => 'video', 'provider_key' => $j['provider_key'],
                 'model_id' => $j['i2v_job_model_id'], 'prompt' => $j['i2v_job_prompt'],
                 'neg' => $j['i2v_job_negative_prompt'], 'params' => lib_jsonb($j['i2v_job_params'])];
    } else {
        $stmt = $db->prepare("SELECT provider_key, t2a_job_model_id, t2a_job_prompt,
                                     t2a_job_negative_prompt, t2a_job_params, t2a_job_outputs
                                FROM yy_t2a_job WHERE t2a_job_key=?");
        $stmt->execute([$jobKey]);
        $j = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$j) errorResponse('job not found', 404);
        $outs = lib_jsonb($j['t2a_job_outputs']);
        $sel  = $outs[$index] ?? null;
        if ($sel) $srcPath = is_array($sel) ? ($sel['path'] ?? null) : (string)$sel;
        $meta = ['kind' => 'audio', 'provider_key' => $j['provider_key'],
                 'model_id' => $j['t2a_job_model_id'], 'prompt' => $j['t2a_job_prompt'],
                 'neg' => $j['t2a_job_negative_prompt'], 'params' => lib_jsonb($j['t2a_job_params'])];
    }

    if (!$srcPath) errorResponse('job has no output at that index', 404);
    $absSrc = lib_resolve_web($srcPath);
    if (!$absSrc) errorResponse('output file missing on disk', 404);

    $ext = strtolower(pathinfo($absSrc, PATHINFO_EXTENSION));
    if (!lib_ext_ok($ext, $meta['kind'])) errorResponse('unsupported file type: ' . $ext);

    $label = trim((string)($data['label'] ?? ''));
    if ($label === '') $label = ucfirst($meta['kind']) . ' from ' . $srcKind . ' #' . $jobKey;
    $charKey = lib_char_key($db, $data['character_key'] ?? null);
    // Seed is the one param worth promoting to a column — it is what makes a
    // render repeatable, so the library should be able to sort and show it.
    $seed = isset($meta['params']['seed']) && $meta['params']['seed'] !== ''
        ? (int)$meta['params']['seed'] : null;

    $ins = $db->prepare("INSERT INTO yy_ai_asset
        (ai_character_key, ai_asset_kind, ai_asset_label, ai_asset_path, provider_key,
         ai_asset_model_id, ai_asset_prompt, ai_asset_negative_prompt, ai_asset_seed,
         ai_asset_params, ai_asset_source_kind, ai_asset_source_job_key)
        VALUES (?,?,?,'',?,?,?,?,?,?::jsonb,?,?) RETURNING ai_asset_key");
    $ins->execute([
        $charKey, $meta['kind'], $label, $meta['provider_key'], $meta['model_id'],
        $meta['prompt'], $meta['neg'], $seed, json_encode($meta['params']), $srcKind, $jobKey,
    ]);
    $assetKey = (int)$ins->fetchColumn();

    $dir = lib_abs_base() . $assetKey;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        $db->prepare("DELETE FROM yy_ai_asset WHERE ai_asset_key=?")->execute([$assetKey]);
        errorResponse('cannot create library dir', 500);
    }
    $destName = 'asset.' . $ext;
    if (!@copy($absSrc, $dir . '/' . $destName)) {
        lib_rmdir_asset($assetKey);
        $db->prepare("DELETE FROM yy_ai_asset WHERE ai_asset_key=?")->execute([$assetKey]);
        errorResponse('copy failed', 500);
    }
    $webPath = LIB_WEB_BASE . $assetKey . '/' . $destName;
    $db->prepare("UPDATE yy_ai_asset SET ai_asset_path=?, ai_asset_size_bytes=?
                   WHERE ai_asset_key=?")
       ->execute([$webPath, (int)@filesize($dir . '/' . $destName), $assetKey]);

    jsonResponse(['ai_asset_key' => $assetKey, 'path' => $webPath, 'label' => $label]);
}

// ── POST upload (multipart) ───────────────────────────────────────────
if ($method === 'POST' && $isMultipart && $action === 'upload') {
    $kind = (string)($_POST['kind'] ?? '');
    if (!in_array($kind, ['image','video','audio'], true)) errorResponse('bad kind');
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) errorResponse('no file');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!lib_ext_ok($ext, $kind)) errorResponse('unsupported file type: ' . $ext);

    $label = trim((string)($_POST['label'] ?? ''));
    if ($label === '') $label = pathinfo((string)$f['name'], PATHINFO_FILENAME);
    $charKey = lib_char_key($db, $_POST['character_key'] ?? null);

    $ins = $db->prepare("INSERT INTO yy_ai_asset
        (ai_character_key, ai_asset_kind, ai_asset_label, ai_asset_path, ai_asset_source_kind)
        VALUES (?,?,?,'','upload') RETURNING ai_asset_key");
    $ins->execute([$charKey, $kind, $label]);
    $assetKey = (int)$ins->fetchColumn();

    $dir = lib_abs_base() . $assetKey;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        $db->prepare("DELETE FROM yy_ai_asset WHERE ai_asset_key=?")->execute([$assetKey]);
        errorResponse('cannot create library dir', 500);
    }
    $destName = 'asset.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $destName)) {
        lib_rmdir_asset($assetKey);
        $db->prepare("DELETE FROM yy_ai_asset WHERE ai_asset_key=?")->execute([$assetKey]);
        errorResponse('upload failed', 500);
    }
    $webPath = LIB_WEB_BASE . $assetKey . '/' . $destName;
    $db->prepare("UPDATE yy_ai_asset SET ai_asset_path=?, ai_asset_size_bytes=?
                   WHERE ai_asset_key=?")
       ->execute([$webPath, (int)@filesize($dir . '/' . $destName), $assetKey]);

    jsonResponse(['ai_asset_key' => $assetKey, 'path' => $webPath, 'label' => $label]);
}

// ── POST update (partial) ─────────────────────────────────────────────
if ($method === 'POST' && $action === 'update') {
    $key = (int)($data['ai_asset_key'] ?? 0);
    if ($key <= 0) errorResponse('ai_asset_key required');

    // Partial by design: only keys actually present are touched, so a caller
    // editing the label can never blank the notes.
    $sets = []; $params = [];
    if (array_key_exists('label', $data))  { $sets[] = 'ai_asset_label = ?'; $params[] = trim((string)$data['label']); }
    if (array_key_exists('notes', $data))  { $sets[] = 'ai_asset_notes = ?'; $params[] = (string)$data['notes']; }
    if (array_key_exists('sort', $data))   { $sets[] = 'ai_asset_sort = ?';  $params[] = (int)$data['sort']; }
    if (array_key_exists('active', $data)) { $sets[] = 'ai_asset_active_flag = ?'; $params[] = (int)!!$data['active']; }
    if (array_key_exists('character_key', $data)) {
        $sets[]   = 'ai_character_key = ?';
        $params[] = lib_char_key($db, $data['character_key']);
    }
    if (!$sets) errorResponse('nothing to update');
    $params[] = $key;
    $stmt = $db->prepare("UPDATE yy_ai_asset SET " . implode(', ', $sets) . " WHERE ai_asset_key = ?");
    $stmt->execute($params);
    if (!$stmt->rowCount()) errorResponse('asset not found', 404);
    jsonResponse(['ok' => true]);
}

// ── POST delete ───────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    $key = (int)($data['ai_asset_key'] ?? 0);
    if ($key <= 0) errorResponse('ai_asset_key required');
    $stmt = $db->prepare("DELETE FROM yy_ai_asset WHERE ai_asset_key=?");
    $stmt->execute([$key]);
    if (!$stmt->rowCount()) errorResponse('asset not found', 404);
    lib_rmdir_asset($key);   // row first: the rev trigger keeps the metadata
    jsonResponse(['ok' => true]);
}

// ── POST save_character / update_character / delete_character ─────────
if ($method === 'POST' && $action === 'save_character') {
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') errorResponse('name required');
    $ins = $db->prepare("INSERT INTO yy_ai_character
        (ai_character_name, ai_character_description, ai_character_prompt,
         ai_character_negative_prompt, ai_character_seed, tts_voice_key)
        VALUES (?,?,?,?,?,?) RETURNING ai_character_key");
    $ins->execute([
        $name,
        $data['description'] ?? null,
        $data['prompt'] ?? null,
        $data['negative_prompt'] ?? null,
        (isset($data['seed']) && $data['seed'] !== '') ? (int)$data['seed'] : null,
        (isset($data['tts_voice_key']) && $data['tts_voice_key'] !== '') ? (int)$data['tts_voice_key'] : null,
    ]);
    jsonResponse(['ai_character_key' => (int)$ins->fetchColumn(), 'name' => $name]);
}

if ($method === 'POST' && $action === 'update_character') {
    $key = (int)($data['ai_character_key'] ?? 0);
    if ($key <= 0) errorResponse('ai_character_key required');
    $map = [
        'name'            => 'ai_character_name',
        'description'     => 'ai_character_description',
        'prompt'          => 'ai_character_prompt',
        'negative_prompt' => 'ai_character_negative_prompt',
    ];
    $sets = []; $params = [];
    foreach ($map as $in => $col) {
        if (array_key_exists($in, $data)) { $sets[] = "$col = ?"; $params[] = (string)$data[$in]; }
    }
    if (array_key_exists('seed', $data)) {
        $sets[]   = 'ai_character_seed = ?';
        $params[] = ($data['seed'] === '' || $data['seed'] === null) ? null : (int)$data['seed'];
    }
    if (array_key_exists('tts_voice_key', $data)) {
        $sets[]   = 'tts_voice_key = ?';
        $params[] = ($data['tts_voice_key'] === '' || $data['tts_voice_key'] === null)
            ? null : (int)$data['tts_voice_key'];
    }
    if (array_key_exists('sort', $data))   { $sets[] = 'ai_character_sort = ?'; $params[] = (int)$data['sort']; }
    if (array_key_exists('active', $data)) { $sets[] = 'ai_character_active_flag = ?'; $params[] = (int)!!$data['active']; }
    if (!$sets) errorResponse('nothing to update');
    $params[] = $key;
    $stmt = $db->prepare("UPDATE yy_ai_character SET " . implode(', ', $sets) . " WHERE ai_character_key = ?");
    $stmt->execute($params);
    if (!$stmt->rowCount()) errorResponse('character not found', 404);
    jsonResponse(['ok' => true]);
}

if ($method === 'POST' && $action === 'delete_character') {
    $key = (int)($data['ai_character_key'] ?? 0);
    if ($key <= 0) errorResponse('ai_character_key required');
    // Assets outlive their character — the FK is ON DELETE SET NULL, so the
    // files stay in the library, just ungrouped.
    $stmt = $db->prepare("DELETE FROM yy_ai_character WHERE ai_character_key=?");
    $stmt->execute([$key]);
    if (!$stmt->rowCount()) errorResponse('character not found', 404);
    jsonResponse(['ok' => true]);
}

errorResponse('unknown action: ' . $action, 404);
