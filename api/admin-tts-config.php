<?php
/**
 * Admin TTS config endpoint. One PHP file handles all CRUD for the four
 * configuration entities (system, category voices, tunes, pauses), plus
 * a 'catalog' action that returns voice + format + category lists for UI.
 *
 *   GET  ?action=catalog
 *     → { voices: [...], formats: [...], categories: [...] }
 *
 *   GET  ?action=overview&tts_key=N
 *     → { systems: [...], current: { system, categories, tunes_count, pauses_count } }
 *
 *   GET  ?action=tunes&tts_key=N
 *   GET  ?action=pauses&tts_key=N
 *   GET  ?action=category_voices&tts_key=N
 *
 *   POST { action:'save_system',         tts_key, output_format, region }
 *   POST { action:'save_category_voice', tts_key, tts_category, voice_code, style, style_degree, rate_pct, pitch_st, volume }
 *   POST { action:'save_tune',           tts_key, tts_tune_key?, print, phonetic, phonetic_type, note, active }
 *   POST { action:'delete_tune',         tts_tune_key }
 *   POST { action:'save_pause',          tts_key, tts_pause_key?, search, ms, note, sort, active }
 *   POST { action:'delete_pause',        tts_pause_key }
 */
require_once __DIR__ . '/config.php';
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

if ($method === 'GET' && $action === 'catalog') {
    // Voices come from yy_tts_voice (active rows only). Admin curates the
    // dropdown contents in Admin → TTS → Voices. tts_key from query or
    // first registered active system.
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) {
        $row = $db->query("SELECT tts_key FROM yy_tts WHERE tts_active_flag = TRUE ORDER BY tts_sort, tts_key LIMIT 1")->fetch();
        $ttsKey = (int)($row['tts_key'] ?? 0);
    }
    jsonResponse([
        'voices'     => azureVoiceCatalog($db, $ttsKey),
        'formats'    => azureOutputFormats(),
        'categories' => ttsCategories(),
    ]);
}

if ($method === 'GET' && $action === 'overview') {
    $systems = $db->query("SELECT * FROM yy_tts WHERE tts_active_flag = TRUE ORDER BY tts_sort, tts_key")->fetchAll();
    $ttsKey = (int)($_GET['tts_key'] ?? ($systems[0]['tts_key'] ?? 0));
    $current = null;
    if ($ttsKey) {
        $cfg = loadTtsConfig($db, $ttsKey);
        $current = [
            'system'       => $cfg['system'],
            'categories'   => array_values($cfg['categories']),
            'tunes_count'  => (int)$db->query("SELECT COUNT(*) FROM yy_tts_tune  WHERE tts_key = $ttsKey")->fetchColumn(),
            'pauses_count' => (int)$db->query("SELECT COUNT(*) FROM yy_tts_pause WHERE tts_key = $ttsKey")->fetchColumn(),
        ];
    }
    jsonResponse(['systems' => $systems, 'current' => $current]);
}

if ($method === 'GET' && $action === 'tunes') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("SELECT * FROM yy_tts_tune WHERE tts_key = ? ORDER BY tts_tune_sort, tts_tune_print");
    $stmt->execute([$ttsKey]);
    jsonResponse(['rows' => $stmt->fetchAll()]);
}

if ($method === 'GET' && $action === 'pauses') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("SELECT * FROM yy_tts_pause WHERE tts_key = ? ORDER BY tts_pause_sort, tts_pause_search");
    $stmt->execute([$ttsKey]);
    jsonResponse(['rows' => $stmt->fetchAll()]);
}

if ($method === 'GET' && $action === 'fonts') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("SELECT * FROM yy_tts_font WHERE tts_key = ? ORDER BY tts_font_skip, tts_font_name");
    $stmt->execute([$ttsKey]);
    jsonResponse(['rows' => $stmt->fetchAll()]);
}

if ($action === 'save_font') {
    $ttsKey   = (int)($data['tts_key'] ?? 0);
    $fontKey  = (int)($data['tts_font_key'] ?? 0);
    $name     = trim((string)($data['name'] ?? ''));
    $skip     = !empty($data['skip']);
    $pauseMs  = (int)($data['pause_ms'] ?? 0);
    if (!$ttsKey || $name === '') errorResponse('tts_key and name required');
    if ($fontKey > 0) {
        $stmt = $db->prepare("
            UPDATE yy_tts_font
               SET tts_font_name = ?, tts_font_skip = ?, tts_font_pause_ms = ?,
                   tts_font_revision_dtime = NOW()
             WHERE tts_font_key = ? AND tts_key = ?
        ");
        $stmt->execute([$name, (int)$skip, $pauseMs, $fontKey, $ttsKey]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO yy_tts_font (tts_key, tts_font_name, tts_font_skip, tts_font_pause_ms)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (tts_key, tts_font_name) DO UPDATE SET
                tts_font_skip = EXCLUDED.tts_font_skip,
                tts_font_pause_ms = EXCLUDED.tts_font_pause_ms,
                tts_font_revision_dtime = NOW()
            RETURNING tts_font_key
        ");
        $stmt->execute([$ttsKey, $name, (int)$skip, $pauseMs]);
        $fontKey = (int)$stmt->fetchColumn();
    }
    jsonResponse(['ok' => true, 'tts_font_key' => $fontKey]);
}

if ($action === 'delete_font') {
    $fontKey = (int)($data['tts_font_key'] ?? 0);
    if (!$fontKey) errorResponse('tts_font_key required');
    $db->prepare("DELETE FROM yy_tts_font WHERE tts_font_key = ?")->execute([$fontKey]);
    jsonResponse(['ok' => true]);
}

if ($method === 'GET' && $action === 'category_voices') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("SELECT * FROM yy_tts_category_voice WHERE tts_key = ? ORDER BY tts_category_voice_key");
    $stmt->execute([$ttsKey]);
    jsonResponse(['rows' => $stmt->fetchAll()]);
}

if ($method !== 'POST') errorResponse('Unknown action');

if ($action === 'save_system') {
    $ttsKey = (int)($data['tts_key'] ?? 0);
    if (!$ttsKey) errorResponse('tts_key required');
    $stmt = $db->prepare("UPDATE yy_tts SET tts_output_format = ?, tts_region = COALESCE(NULLIF(?, ''), tts_region) WHERE tts_key = ?");
    $stmt->execute([
        trim((string)($data['output_format'] ?? 'audio-24khz-48kbitrate-mono-mp3')),
        trim((string)($data['region'] ?? '')),
        $ttsKey,
    ]);
    jsonResponse(['ok' => true]);
}

if ($action === 'save_category_voice') {
    $ttsKey   = (int)($data['tts_key'] ?? 0);
    $category = trim((string)($data['tts_category'] ?? ''));
    $voice    = trim((string)($data['voice_code'] ?? ''));
    if (!$ttsKey || $category === '' || $voice === '') errorResponse('tts_key, tts_category, voice_code required');
    $stmt = $db->prepare("
        INSERT INTO yy_tts_category_voice
            (tts_key, tts_category, tts_voice_code, tts_voice_style, tts_voice_style_degree,
             tts_voice_rate_pct, tts_voice_pitch_st, tts_voice_volume)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (tts_key, tts_category) DO UPDATE SET
            tts_voice_code = EXCLUDED.tts_voice_code,
            tts_voice_style = EXCLUDED.tts_voice_style,
            tts_voice_style_degree = EXCLUDED.tts_voice_style_degree,
            tts_voice_rate_pct = EXCLUDED.tts_voice_rate_pct,
            tts_voice_pitch_st = EXCLUDED.tts_voice_pitch_st,
            tts_voice_volume = EXCLUDED.tts_voice_volume,
            tts_category_voice_revision_dtime = NOW()
    ");
    $stmt->execute([
        $ttsKey, $category, $voice,
        $data['style'] ?? null,
        $data['style_degree'] ?? 1.0,
        (int)($data['rate_pct'] ?? 0),
        (float)($data['pitch_st'] ?? 0),
        (int)($data['volume'] ?? 100),
    ]);
    jsonResponse(['ok' => true]);
}

if ($action === 'save_tune') {
    $ttsKey = (int)($data['tts_key'] ?? 0);
    $print  = trim((string)($data['print'] ?? ''));
    $sub    = trim((string)($data['phonetic_sub']  ?? ''));
    $ipa    = trim((string)($data['phonetic_ipa']  ?? ''));
    $sapi   = trim((string)($data['phonetic_sapi'] ?? ''));
    $type   = in_array(($data['phonetic_type'] ?? 'sub'), ['sub', 'ipa', 'sapi'], true) ? $data['phonetic_type'] : 'sub';
    $note   = trim((string)($data['note'] ?? ''));
    $active = !empty($data['active']);
    $mBold   = !empty($data['match_bold']);
    $mItalic = !empty($data['match_italic']);
    $mCase   = !empty($data['match_case_sensitive']);
    if (!$ttsKey || $print === '') errorResponse('tts_key, print required');
    // Legacy tts_tune_phonetic mirror — kept in sync with whichever type
    // is currently chosen so older code paths keep working. If the chosen
    // column is empty, fall back to whichever of the three is populated.
    $chosen = ['sub' => $sub, 'ipa' => $ipa, 'sapi' => $sapi][$type] ?? '';
    $mirror = $chosen !== '' ? $chosen : ($sub !== '' ? $sub : ($ipa !== '' ? $ipa : $sapi));
    if ($mirror === '') $mirror = $print; // never let the not-null column go empty

    $tuneKey = (int)($data['tts_tune_key'] ?? 0);
    if ($tuneKey > 0) {
        $stmt = $db->prepare("
            UPDATE yy_tts_tune
               SET tts_tune_print = ?, tts_tune_phonetic = ?,
                   tts_tune_phonetic_sub = ?, tts_tune_phonetic_ipa = ?, tts_tune_phonetic_sapi = ?,
                   tts_tune_phonetic_type = ?, tts_tune_note = ?, tts_tune_active_flag = ?,
                   tts_tune_match_bold = ?, tts_tune_match_italic = ?, tts_tune_match_case_sensitive = ?,
                   tts_tune_revision_dtime = NOW()
             WHERE tts_tune_key = ? AND tts_key = ?
        ");
        // Cast PHP booleans to int (0/1) because PDO's PostgreSQL driver
        // serialises bool false as "" which Postgres rejects.
        $stmt->execute([$print, $mirror, $sub, $ipa, $sapi, $type, $note ?: null, (int)$active, (int)$mBold, (int)$mItalic, (int)$mCase, $tuneKey, $ttsKey]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO yy_tts_tune
                (tts_key, tts_tune_print, tts_tune_phonetic,
                 tts_tune_phonetic_sub, tts_tune_phonetic_ipa, tts_tune_phonetic_sapi,
                 tts_tune_phonetic_type, tts_tune_note, tts_tune_active_flag,
                 tts_tune_match_bold, tts_tune_match_italic, tts_tune_match_case_sensitive)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (tts_key, tts_tune_print) DO UPDATE SET
                tts_tune_phonetic              = EXCLUDED.tts_tune_phonetic,
                tts_tune_phonetic_sub          = EXCLUDED.tts_tune_phonetic_sub,
                tts_tune_phonetic_ipa          = EXCLUDED.tts_tune_phonetic_ipa,
                tts_tune_phonetic_sapi         = EXCLUDED.tts_tune_phonetic_sapi,
                tts_tune_phonetic_type         = EXCLUDED.tts_tune_phonetic_type,
                tts_tune_note                  = EXCLUDED.tts_tune_note,
                tts_tune_active_flag           = EXCLUDED.tts_tune_active_flag,
                tts_tune_match_bold            = EXCLUDED.tts_tune_match_bold,
                tts_tune_match_italic          = EXCLUDED.tts_tune_match_italic,
                tts_tune_match_case_sensitive  = EXCLUDED.tts_tune_match_case_sensitive,
                tts_tune_revision_dtime = NOW()
            RETURNING tts_tune_key
        ");
        $stmt->execute([$ttsKey, $print, $mirror, $sub, $ipa, $sapi, $type, $note ?: null, (int)$active, (int)$mBold, (int)$mItalic, (int)$mCase]);
        $tuneKey = (int)$stmt->fetchColumn();
    }
    jsonResponse(['ok' => true, 'tts_tune_key' => $tuneKey]);
}

if ($action === 'delete_tune') {
    $tuneKey = (int)($data['tts_tune_key'] ?? 0);
    if (!$tuneKey) errorResponse('tts_tune_key required');
    $db->prepare("DELETE FROM yy_tts_tune WHERE tts_tune_key = ?")->execute([$tuneKey]);
    jsonResponse(['ok' => true]);
}

if ($action === 'save_pause') {
    $ttsKey = (int)($data['tts_key'] ?? 0);
    $search = (string)($data['search'] ?? '');  // preserve spaces, don't trim
    $ms     = (int)($data['ms'] ?? 300);
    $note   = trim((string)($data['note'] ?? ''));
    $sort   = (int)($data['sort'] ?? 0);
    $active = !empty($data['active']);
    if (!$ttsKey || $search === '') errorResponse('tts_key, search required');

    $pauseKey = (int)($data['tts_pause_key'] ?? 0);
    if ($pauseKey > 0) {
        $stmt = $db->prepare("
            UPDATE yy_tts_pause
               SET tts_pause_search = ?, tts_pause_ms = ?, tts_pause_note = ?,
                   tts_pause_sort = ?, tts_pause_active_flag = ?,
                   tts_pause_revision_dtime = NOW()
             WHERE tts_pause_key = ? AND tts_key = ?
        ");
        $stmt->execute([$search, $ms, $note ?: null, $sort, (int)$active, $pauseKey, $ttsKey]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO yy_tts_pause
                (tts_key, tts_pause_search, tts_pause_ms, tts_pause_note, tts_pause_sort, tts_pause_active_flag)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT (tts_key, tts_pause_search) DO UPDATE SET
                tts_pause_ms = EXCLUDED.tts_pause_ms,
                tts_pause_note = EXCLUDED.tts_pause_note,
                tts_pause_sort = EXCLUDED.tts_pause_sort,
                tts_pause_active_flag = EXCLUDED.tts_pause_active_flag,
                tts_pause_revision_dtime = NOW()
            RETURNING tts_pause_key
        ");
        $stmt->execute([$ttsKey, $search, $ms, $note ?: null, $sort, (int)$active]);
        $pauseKey = (int)$stmt->fetchColumn();
    }
    jsonResponse(['ok' => true, 'tts_pause_key' => $pauseKey]);
}

if ($action === 'delete_pause') {
    $pauseKey = (int)($data['tts_pause_key'] ?? 0);
    if (!$pauseKey) errorResponse('tts_pause_key required');
    $db->prepare("DELETE FROM yy_tts_pause WHERE tts_pause_key = ?")->execute([$pauseKey]);
    jsonResponse(['ok' => true]);
}

errorResponse('Unknown action: ' . $action);
