<?php
/**
 * Book / chapter listing for the Admin TTS > Books tab.
 *
 *   GET  ?action=volumes
 *     → { volumes: [{ volume_key, series_key, series_number, volume_number,
 *                     volume_label, paragraph_count, chapters_total,
 *                     chapters_with_audio, last_built_dtime }] }
 *
 *   GET  ?action=chapters&volume_key=N&tts_key=N
 *     → { volume: {...}, chapters: [{ chapter_key, chapter_number, paragraph_count,
 *                     audio_status, audio_progress, audio_path, audio_duration_secs,
 *                     audio_size_bytes, audio_completed_dtime, audio_settings }] }
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

$action = $_GET['action'] ?? 'volumes';

if ($action === 'volumes') {
    $ttsKey = (int)($_GET['tts_key'] ?? 0);
    $sql = "
        SELECT v.volume_key, v.series_key, v.volume_number, v.volume_label,
               v.volume_paragraph_count_live AS paragraph_count,
               s.series_number,
               (SELECT COUNT(*) FROM yy_chapter c WHERE c.volume_key = v.volume_key) AS chapters_total,
               COALESCE((
                   SELECT COUNT(*) FROM yy_tts_audio a
                    WHERE a.volume_key = v.volume_key
                      AND ($ttsKey = 0 OR a.tts_key = $ttsKey)
                      AND a.tts_audio_status = 'complete'
                      AND a.chapter_key IS NOT NULL
               ), 0) AS chapters_with_audio,
               (SELECT MAX(tts_audio_completed_dtime) FROM yy_tts_audio a
                 WHERE a.volume_key = v.volume_key
                   AND ($ttsKey = 0 OR a.tts_key = $ttsKey)) AS last_built_dtime
          FROM yy_volume v
          JOIN yy_series s ON v.series_key = s.series_key
         WHERE v.volume_active_flag = TRUE
         ORDER BY s.series_number, v.volume_number
    ";
    jsonResponse(['volumes' => $db->query($sql)->fetchAll()]);
}

if ($action === 'chapters') {
    $volumeKey = (int)($_GET['volume_key'] ?? 0);
    $ttsKey    = (int)($_GET['tts_key']    ?? 0);
    if (!$volumeKey || !$ttsKey) errorResponse('volume_key and tts_key required');

    $vStmt = $db->prepare("
        SELECT v.volume_key, v.series_key, v.volume_number, v.volume_label,
               v.volume_paragraph_count_live AS paragraph_count,
               v.volume_code, v.volume_flip_code, s.series_number
          FROM yy_volume v JOIN yy_series s ON v.series_key = s.series_key
         WHERE v.volume_key = ?
    ");
    $vStmt->execute([$volumeKey]);
    $volume = $vStmt->fetch();
    if (!$volume) errorResponse('volume not found', 404);

    $cStmt = $db->prepare("
        SELECT c.chapter_key, c.chapter_number, c.chapter_label, c.chapter_page,
               (SELECT COUNT(*) FROM yy_paragraph p WHERE p.chapter_key = c.chapter_key) AS paragraph_count,
               a.tts_audio_status, a.tts_audio_progress, a.tts_audio_message,
               a.tts_audio_path, a.tts_audio_duration_secs, a.tts_audio_size_bytes,
               a.tts_audio_completed_dtime, a.tts_audio_started_dtime, a.tts_audio_settings,
               a.tts_audio_error, a.tts_audio_key, a.tts_audio_failed_paragraphs
          FROM yy_chapter c
          LEFT JOIN yy_tts_audio a
            ON a.chapter_key = c.chapter_key
           AND a.tts_key = ?
         WHERE c.volume_key = ?
         ORDER BY c.chapter_number
    ");
    $cStmt->execute([$ttsKey, $volumeKey]);
    jsonResponse(['volume' => $volume, 'chapters' => $cStmt->fetchAll()]);
}

errorResponse('Unknown action');
