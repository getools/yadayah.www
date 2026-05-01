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
$ALLOWED_EXT = ['vtt', 'srt', 'mp3', 'm4a', 'opus', 'wav', 'ogg', 'aac'];

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
    jsonResponse(['ok' => true, 'files' => existingFiles($UPLOAD_DIR, $itemKey)]);
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
