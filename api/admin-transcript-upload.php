<?php
/**
 * Upload a pre-fetched audio file or caption file for a feed item.
 *
 * GET ?item_key=N            — return the current uploaded file's name + size, if any
 * POST multipart {item_key, file} — write to /tmp/transcript_uploads/{item_key}.{ext}
 * DELETE ?item_key=N         — wipe any uploaded file for this item
 *
 * Accepted extensions: vtt, srt (captions), mp3, m4a, opus, wav, ogg (audio).
 * The transcript-worker prefers files in that directory over yt-dlp for the
 * given item_key.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

// Caption files (VTT/SRT) still go to the ephemeral /tmp/ folder for the
// worker's Method 1 path. Audio files now go to a durable public folder
// and the path is recorded in yy_feed_item.feed_item_audio_file so the
// item is permanently associated with its source audio.
$UPLOAD_DIR     = sys_get_temp_dir() . '/transcript_uploads';        // captions only
$AUDIO_DIR_ABS  = dirname(__DIR__) . '/public/u/audio';              // host: /opt/yada-www/public/u/audio
$AUDIO_DIR_REL  = 'u/audio';                                         // stored in DB
$AUDIO_EXT      = ['mp3', 'm4a', 'opus', 'wav', 'ogg', 'aac', 'webm'];
$CAPTION_EXT    = ['vtt', 'srt'];
$ALLOWED_EXT    = array_merge($AUDIO_EXT, $CAPTION_EXT);

if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0775, true);
if (!is_dir($AUDIO_DIR_ABS)) @mkdir($AUDIO_DIR_ABS, 0775, true);

function existingFiles(string $dir, int $itemKey): array {
    $files = [];
    foreach (glob("$dir/{$itemKey}.*") as $f) {
        if (is_file($f)) {
            $files[] = [
                'name' => basename($f),
                'size' => filesize($f),
                'modified_dtime' => date('c', filemtime($f)),
                'extension' => strtolower(pathinfo($f, PATHINFO_EXTENSION)),
            ];
        }
    }
    return $files;
}

function durableAudioInfo(PDO $db, int $itemKey): ?array {
    $stmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
    $stmt->execute([$itemKey]);
    $rel = $stmt->fetchColumn();
    if (!$rel) return null;
    $abs = dirname(__DIR__) . '/public/' . ltrim($rel, '/');
    if (!is_file($abs)) return ['path' => $rel, 'missing' => true];
    return [
        'path' => $rel,
        'size' => filesize($abs),
        'modified_dtime' => date('c', filemtime($abs)),
        'extension' => strtolower(pathinfo($abs, PATHINFO_EXTENSION)),
    ];
}

if ($method === 'GET') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $db = getDb();
    jsonResponse([
        'files' => existingFiles($UPLOAD_DIR, $itemKey),
        'audio' => durableAudioInfo($db, $itemKey),
    ]);
}

if ($method === 'POST') {
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

    $db = getDb();
    $isAudio = in_array($ext, $AUDIO_EXT, true);

    if ($isAudio) {
        // Durable storage + DB association. Filename includes timestamp so
        // re-recording produces a new file (old reference is overwritten in DB
        // and the prior file is unlinked).
        $stamp = time();
        $relPath  = $AUDIO_DIR_REL . "/audio_{$itemKey}_{$stamp}.{$ext}";
        $absPath  = $AUDIO_DIR_ABS . "/audio_{$itemKey}_{$stamp}.{$ext}";

        // Remove any prior audio file for this item (keep DB consistent)
        $prevStmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
        $prevStmt->execute([$itemKey]);
        $prev = $prevStmt->fetchColumn();
        if ($prev) {
            $prevAbs = dirname(__DIR__) . '/public/' . ltrim($prev, '/');
            if (is_file($prevAbs)) @unlink($prevAbs);
        }

        if (!@move_uploaded_file($tmp, $absPath)) {
            errorResponse('Failed to write ' . $absPath);
        }
        @chmod($absPath, 0664);

        $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ? WHERE feed_item_key = ?")
           ->execute([$relPath, $itemKey]);
    } else {
        // Captions still ephemeral — used only as Method 1 input by the worker.
        foreach (glob("$UPLOAD_DIR/{$itemKey}.*") as $old) @unlink($old);
        $dest = "$UPLOAD_DIR/{$itemKey}.{$ext}";
        if (!@move_uploaded_file($tmp, $dest)) errorResponse('Failed to write ' . $dest);
        @chmod($dest, 0664);
    }

    logMonitorEvent('transcript_upload', 'info',
        ($isAudio ? 'Audio' : 'Caption') . ' uploaded for item ' . $itemKey . ' (.' . $ext . ', ' . $size . ' bytes)',
        'by user ' . ($user['user_code'] ?? '?'), true);

    $jobKey = null;
    // If the caller asked, kick off transcription immediately. Used by the
    // browser extension's live-mode so the user doesn't have to switch tabs
    // and click "Transcribe Audio" after the audio finishes uploading.
    if (!empty($_POST['auto_transcribe'])) {
        $db = getDb();
        // Cancel any other in-flight job for this item
        $db->prepare("UPDATE yy_feed_item_transcript_job SET job_status = 'cancelled', job_completed_dtime = NOW() WHERE feed_item_key = ? AND job_status IN ('pending', 'running')")
           ->execute([$itemKey]);
        $jobStmt = $db->prepare("INSERT INTO yy_feed_item_transcript_job (feed_item_key, job_status, job_message, user_key) VALUES (?, 'pending', 'Auto-triggered after upload', ?) RETURNING feed_item_transcript_job_key");
        $jobStmt->execute([$itemKey, $user['user_key']]);
        $jobKey = (int)$jobStmt->fetchColumn();
        $workerScript = __DIR__ . '/transcript-worker.php';
        if (file_exists($workerScript)) {
            $logFile = sys_get_temp_dir() . '/transcript_' . $jobKey . '.log';
            $cmd = "nohup php " . escapeshellarg($workerScript) . " " . escapeshellarg((string)$jobKey)
                 . " > " . escapeshellarg($logFile) . " 2>&1 < /dev/null & echo $!";
            $pidOut = [];
            exec($cmd, $pidOut);
            $pid = (int)($pidOut[0] ?? 0);
            if ($pid > 0) {
                $db->prepare("UPDATE yy_feed_item_transcript_job SET job_worker_pid = ? WHERE feed_item_transcript_job_key = ?")
                   ->execute([$pid, $jobKey]);
            }
        }
    }

    jsonResponse([
        'ok' => true,
        'files' => existingFiles($UPLOAD_DIR, $itemKey),
        'audio' => durableAudioInfo($db, $itemKey),
        'transcribe_job_key' => $jobKey,
    ]);
}

if ($method === 'DELETE') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $count = 0;
    // Wipe ephemeral captions
    foreach (glob("$UPLOAD_DIR/{$itemKey}.*") as $f) if (@unlink($f)) $count++;
    // Wipe durable audio + clear DB pointer
    $db = getDb();
    $prev = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
    $prev->execute([$itemKey]);
    $rel = $prev->fetchColumn();
    if ($rel) {
        $abs = dirname(__DIR__) . '/public/' . ltrim($rel, '/');
        if (is_file($abs) && @unlink($abs)) $count++;
        $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = NULL WHERE feed_item_key = ?")
           ->execute([$itemKey]);
    }
    jsonResponse(['ok' => true, 'deleted' => $count]);
}

errorResponse('Method not allowed', 405);
