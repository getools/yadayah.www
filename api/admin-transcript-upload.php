<?php
/**
 * Audio + caption upload endpoint for transcript pipeline.
 *
 * GET ?item_key=N
 *   Returns current state: ephemeral caption file (if any), durable audio
 *   pointer (yy_feed_item.feed_item_audio_file), partial-recording state
 *   (resume_seconds + parts list).
 *
 * POST multipart {item_key, file [, partial=1, seconds=N] [, auto_transcribe=1]}
 *   - partial=1: append the file as the next recording part under
 *     /u/audio/parts/audio_{key}_part_{N}.webm; update
 *     yy_feed_item.feed_item_audio_resume_seconds to seconds.
 *   - default (no partial): single complete upload; replaces the durable
 *     audio file at /u/audio/audio_{key}_{ts}.{ext}, clears any partial state.
 *   - auto_transcribe=1: kick off the worker after the upload lands.
 *
 * POST {action: 'finalize', item_key, auto_transcribe?}
 *   Concatenates all parts via ffmpeg, transcodes to MP3, replaces
 *   feed_item_audio_file, clears resume state, deletes parts.
 *
 * DELETE ?item_key=N
 *   Wipes captions, parts, durable audio, and clears DB pointer +
 *   resume_seconds.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finalize-helpers.php';
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

$UPLOAD_DIR     = sys_get_temp_dir() . '/transcript_uploads';        // captions only
$AUDIO_DIR_ABS  = dirname(__DIR__) . '/u/audio';
$AUDIO_DIR_REL  = 'u/audio';
$PARTS_DIR_ABS  = $AUDIO_DIR_ABS . '/parts';
$AUDIO_EXT      = ['mp3', 'm4a', 'opus', 'wav', 'ogg', 'aac', 'webm'];
$CAPTION_EXT    = ['vtt', 'srt'];
$ALLOWED_EXT    = array_merge($AUDIO_EXT, $CAPTION_EXT);

if (!is_dir($UPLOAD_DIR))   @mkdir($UPLOAD_DIR, 0775, true);
if (!is_dir($AUDIO_DIR_ABS)) @mkdir($AUDIO_DIR_ABS, 0775, true);
if (!is_dir($PARTS_DIR_ABS)) @mkdir($PARTS_DIR_ABS, 0775, true);

// Helper functions live in finalize-helpers.php (shared with the async
// finalize-worker.php). require_once is at the top of this file.

if ($method === 'GET') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $db = getDb();
    jsonResponse([
        'files' => existingCaptionFiles($UPLOAD_DIR, $itemKey),
        'audio' => durableAudioInfo($db, $itemKey),
        'partial' => partialState($db, $PARTS_DIR_ABS, $itemKey),
    ]);
}

if ($method === 'POST') {
    $db = getDb();
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // JSON body for non-upload actions (e.g. finalize)
    if (stripos($contentType, 'application/json') !== false) {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $data['action'] ?? '';
        $itemKey = (int)($data['item_key'] ?? 0);
        if (!$itemKey) errorResponse('item_key required');

        if ($action === 'finalize') {
            // Cheap up-front check so the user gets an immediate error if
            // there are no recorded parts to finalize.
            $parts = listParts($PARTS_DIR_ABS, $itemKey);
            if (!$parts) errorResponse('No parts to finalize');

            // ASYNC pivot (2026-05-03): the encode used to run inline here,
            // which works on local dev but Cloudflare's 100s gateway timeout
            // kills any long encode (3h recordings = 10–25 min ffmpeg run)
            // before we can return JSON — the UI then thinks finalize
            // failed even though the MP3 was actually saved.
            //
            // Now we initialize the state file, fork finalize-worker.php,
            // and return immediately. The popout polls
            // admin-transcript-finalize-progress.php for status.
            $autoTranscribe = !empty($data['auto_transcribe']) ? 1 : 0;

            writeFinalizeState($itemKey, [
                'status'     => 'pending',
                'message'    => 'Queued for encoder',
                'item_key'   => $itemKey,
                'started'    => date('c'),
                'part_count' => count($parts),
            ]);

            require_once __DIR__ . '/spawn-helpers.php';
            $worker = __DIR__ . '/finalize-worker.php';
            $logFile = sys_get_temp_dir() . '/finalize_' . $itemKey . '.log';
            // 3-hour recordings can encode for ~30min CPU on libmp3lame -q4;
            // 40min cap leaves headroom but still kills truly stuck ffmpeg.
            $pid = spawnCappedWorker($worker, [
                (string)$itemKey, (string)$user['user_key'], (string)$autoTranscribe,
            ], $logFile, [
                'cpu_secs' => 2400, 'mem_mb' => 1500, 'nice' => 10,
            ]);
            if ($pid <= 0) {
                writeFinalizeState($itemKey, [
                    'status'    => 'error',
                    'error'     => 'Failed to spawn finalize-worker',
                    'completed' => date('c'),
                ]);
                errorResponse('Failed to spawn finalize-worker');
            }
            writeFinalizeState($itemKey, ['pid' => $pid]);

            jsonResponse([
                'ok'          => true,
                'async'       => true,
                'status'      => 'pending',
                'item_key'    => $itemKey,
                'pid'         => $pid,
                'message'     => 'Encoder started in background. Poll /api/admin-transcript-finalize-progress.php?item_key=' . $itemKey,
            ]);
        }
        errorResponse('Unknown action');
    }

    // Multipart upload
    $itemKey = (int)($_POST['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        errorResponse('No file uploaded (error code ' . ($_FILES['file']['error'] ?? '?') . ')');
    }
    $tmp = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'] ?? '';
    $size = $_FILES['file']['size'] ?? 0;
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXT, true)) {
        errorResponse('File type ".' . $ext . '" not allowed. Accepted: ' . implode(', ', $ALLOWED_EXT));
    }
    if ($size > 500 * 1024 * 1024) errorResponse('File too large (>500MB)');

    $isAudio = in_array($ext, $AUDIO_EXT, true);
    $isPartial = !empty($_POST['partial']);
    $partialSeconds = (int)($_POST['seconds'] ?? 0);

    // Per-item override: when feed_item_allow_silent_recording is TRUE, the
    // -70 dB silence rejection in validateAudioFile() is skipped. Used for
    // videos with intentional silent sections (e.g. closing meditation).
    $allowSilent = false;
    if ($isAudio) {
        $asStmt = $db->prepare("SELECT feed_item_allow_silent_recording FROM yy_feed_item WHERE feed_item_key = ?");
        $asStmt->execute([$itemKey]);
        $allowSilent = (bool)$asStmt->fetchColumn();
    }

    if ($isAudio && $isPartial) {
        // Append as next part. Browser captures webm so we save as .webm
        // regardless of inbound extension; ffmpeg will read it correctly.
        $existing = listParts($PARTS_DIR_ABS, $itemKey);
        $next = $existing ? max(array_keys($existing)) + 1 : 1;
        $partAbs = $PARTS_DIR_ABS . "/audio_{$itemKey}_part_{$next}.webm";
        if (!@move_uploaded_file($tmp, $partAbs)) errorResponse('Failed to write part: ' . $partAbs);
        @chmod($partAbs, 0664);
        // AUTOMATED VALIDATION: probe the file as soon as it lands. Reject
        // and remove it if the container is corrupt or the audio is silent
        // (mean volume < -70 dB → tab share missed the audio source). This
        // stops bad parts from accumulating and poisoning the finalize.
        // The silence check is skipped when the item is flagged
        // allow_silent_recording.
        $check = validateAudioFile($partAbs, $allowSilent);
        if (!$check['ok']) {
            @unlink($partAbs);
            logMonitorEvent('transcript_upload', 'warning',
                'Rejected invalid part upload for item ' . $itemKey . ': ' . $check['reason'],
                'by user ' . ($user['user_code'] ?? '?'), false);
            errorResponse('Upload rejected — ' . $check['reason']);
        }
        // Never regress: a stale or out-of-order partial upload should not
        // overwrite a later position. Always take the MAX of the existing
        // value and the incoming one.
        $db->prepare("UPDATE yy_feed_item
                          SET feed_item_audio_resume_seconds = GREATEST(COALESCE(feed_item_audio_resume_seconds, 0), ?)
                        WHERE feed_item_key = ?")
           ->execute([$partialSeconds, $itemKey]);
        logMonitorEvent('transcript_upload', 'info',
            'Partial recording part ' . $next . ' for item ' . $itemKey . ' (' . $partialSeconds . 's, '
            . round($size/1024/1024, 2) . ' MB)',
            'by user ' . ($user['user_code'] ?? '?'), true);
        jsonResponse([
            'ok' => true,
            'partial' => partialState($db, $PARTS_DIR_ABS, $itemKey),
        ]);
    }

    if ($isAudio) {
        // Single-shot full upload — replaces existing audio + clears any partials
        $stamp = time();
        $relPath  = $AUDIO_DIR_REL . "/audio_{$itemKey}_{$stamp}.{$ext}";
        $absPath  = $AUDIO_DIR_ABS . "/audio_{$itemKey}_{$stamp}.{$ext}";

        $prevStmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
        $prevStmt->execute([$itemKey]);
        $prev = $prevStmt->fetchColumn();
        if ($prev) {
            $prevAbs = dirname(__DIR__) . '/' . ltrim($prev, '/');
            if (is_file($prevAbs)) @unlink($prevAbs);
        }
        if (!@move_uploaded_file($tmp, $absPath)) errorResponse('Failed to write ' . $absPath);
        @chmod($absPath, 0664);

        // Wipe any leftover parts (this upload supersedes them)
        foreach (listParts($PARTS_DIR_ABS, $itemKey) as $f) @unlink($f);

        $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ?, feed_item_audio_resume_seconds = NULL WHERE feed_item_key = ?")
           ->execute([$relPath, $itemKey]);
    } else {
        // Captions still ephemeral
        foreach (glob("$UPLOAD_DIR/{$itemKey}.*") as $old) @unlink($old);
        $dest = "$UPLOAD_DIR/{$itemKey}.{$ext}";
        if (!@move_uploaded_file($tmp, $dest)) errorResponse('Failed to write ' . $dest);
        @chmod($dest, 0664);
    }

    logMonitorEvent('transcript_upload', 'info',
        ($isAudio ? 'Audio' : 'Caption') . ' uploaded for item ' . $itemKey . ' (.' . $ext . ', ' . $size . ' bytes)',
        'by user ' . ($user['user_code'] ?? '?'), true);

    $jobKey = null;
    if (!empty($_POST['auto_transcribe'])) {
        $jobKey = spawnTranscribeJob($db, $itemKey, $user['user_key']);
    }

    jsonResponse([
        'ok' => true,
        'files' => existingCaptionFiles($UPLOAD_DIR, $itemKey),
        'audio' => durableAudioInfo($db, $itemKey),
        'partial' => partialState($db, $PARTS_DIR_ABS, $itemKey),
        'transcribe_job_key' => $jobKey,
    ]);
}

if ($method === 'DELETE') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $count = 0;
    foreach (glob("$UPLOAD_DIR/{$itemKey}.*") as $f) if (@unlink($f)) $count++;
    foreach (listParts($PARTS_DIR_ABS, $itemKey) as $f) if (@unlink($f)) $count++;
    $db = getDb();
    $prev = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
    $prev->execute([$itemKey]);
    $rel = $prev->fetchColumn();
    if ($rel) {
        $abs = dirname(__DIR__) . '/' . ltrim($rel, '/');
        if (is_file($abs) && @unlink($abs)) $count++;
    }
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = NULL, feed_item_audio_resume_seconds = NULL WHERE feed_item_key = ?")
       ->execute([$itemKey]);
    jsonResponse(['ok' => true, 'deleted' => $count]);
}

errorResponse('Method not allowed', 405);
