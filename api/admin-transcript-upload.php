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

$UPLOAD_DIR = sys_get_temp_dir() . '/transcript_uploads';
$ALLOWED_EXT = ['vtt', 'srt', 'mp3', 'm4a', 'opus', 'wav', 'ogg', 'aac', 'webm'];

if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0775, true);

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

if ($method === 'GET') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    jsonResponse(['files' => existingFiles($UPLOAD_DIR, $itemKey)]);
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
    // Cap audio uploads at 500MB (Whisper API will chunk anyway)
    if ($size > 500 * 1024 * 1024) errorResponse('File too large (>500MB)');

    // Wipe any prior file for this item — only one effective uploaded source per item
    foreach (glob("$UPLOAD_DIR/{$itemKey}.*") as $old) @unlink($old);

    $dest = "$UPLOAD_DIR/{$itemKey}.{$ext}";
    if (!@move_uploaded_file($tmp, $dest)) {
        errorResponse('Failed to write ' . $dest);
    }
    @chmod($dest, 0664);
    logMonitorEvent('transcript_upload', 'info',
        'Audio/caption uploaded for item ' . $itemKey . ' (.' . $ext . ', ' . $size . ' bytes)',
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
        'transcribe_job_key' => $jobKey,
    ]);
}

if ($method === 'DELETE') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $count = 0;
    foreach (glob("$UPLOAD_DIR/{$itemKey}.*") as $f) {
        if (@unlink($f)) $count++;
    }
    jsonResponse(['ok' => true, 'deleted' => $count]);
}

errorResponse('Method not allowed', 405);
