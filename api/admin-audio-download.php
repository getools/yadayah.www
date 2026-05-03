<?php
/**
 * Admin API: download audio from a YouTube/Rumble URL via yt-dlp.
 *
 * POST ?key=N
 *   Spawns a background worker that runs yt-dlp -x --audio-format mp3 against
 *   the item's feed_item_url, saves to /u/audio/audio_{key}_{ts}.mp3, and
 *   updates yy_feed_item.feed_item_audio_file. Returns immediately with a
 *   status JSON file path the UI can poll.
 *
 * GET ?key=N
 *   Returns current download status (running/success/error + progress %).
 *
 * DELETE ?key=N
 *   Cancels an in-flight download (kills the worker pid).
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$key = (int)($_GET['key'] ?? 0);
if (!$key) errorResponse('key required');

$db = getDb();
$statusFile = sys_get_temp_dir() . "/audio_dl_{$key}.json";

function readStatus(string $f): ?array {
    if (!is_file($f)) return null;
    $j = json_decode(@file_get_contents($f), true);
    return is_array($j) ? $j : null;
}
function writeStatus(string $f, array $patch): void {
    $cur = readStatus($f) ?: [];
    foreach ($patch as $k => $v) $cur[$k] = $v;
    $cur['updated'] = date('c');
    @file_put_contents($f, json_encode($cur, JSON_PRETTY_PRINT));
}

if ($method === 'GET') {
    $status = readStatus($statusFile);
    if (!$status) {
        // No active download — report current durable state from DB
        $stmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
        $stmt->execute([$key]);
        $audio = $stmt->fetchColumn() ?: null;
        jsonResponse(['running' => false, 'audio_file' => $audio]);
    }
    // Active or recently-finished download
    $running = ($status['state'] ?? '') === 'running';
    if ($running && !empty($status['pid']) && !posix_kill((int)$status['pid'], 0)) {
        // PID gone but state stuck — mark as crashed
        writeStatus($statusFile, ['state' => 'error', 'message' => 'Worker died unexpectedly']);
        $status = readStatus($statusFile);
    }
    jsonResponse($status);
}

if ($method === 'DELETE') {
    $status = readStatus($statusFile);
    if ($status && !empty($status['pid'])) {
        @posix_kill((int)$status['pid'], 15); // SIGTERM
    }
    @unlink($statusFile);
    jsonResponse(['cancelled' => true]);
}

if ($method === 'POST') {
    // Look up the URL
    $stmt = $db->prepare("SELECT feed_item_url FROM yy_feed_item WHERE feed_item_key = ?");
    $stmt->execute([$key]);
    $url = $stmt->fetchColumn();
    if (!$url) errorResponse('feed_item not found or has no url', 404);

    // Refuse if a download is already in flight for this item
    $existing = readStatus($statusFile);
    if ($existing && ($existing['state'] ?? '') === 'running' && !empty($existing['pid']) && posix_kill((int)$existing['pid'], 0)) {
        errorResponse('A download is already running for this item — cancel it first or wait', 409);
    }

    // Initial status
    writeStatus($statusFile, [
        'state'    => 'running',
        'progress' => 0,
        'message'  => 'Spawning worker…',
        'url'      => $url,
        'started'  => date('c'),
        'pid'      => null,
    ]);

    // Spawn the worker — release session lock first so the GET status poll
    // doesn't serialize behind us while the worker runs.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $worker = __DIR__ . '/admin-audio-download-worker.php';
    $logFile = sys_get_temp_dir() . "/audio_dl_{$key}.log";
    $cmd = 'nohup php ' . escapeshellarg($worker) . ' ' . escapeshellarg((string)$key)
         . ' > ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';
    $out = [];
    exec($cmd, $out);
    $pid = (int)($out[0] ?? 0);
    writeStatus($statusFile, ['pid' => $pid, 'message' => 'Worker started (pid ' . $pid . ')']);

    jsonResponse(['queued' => true, 'pid' => $pid, 'status_url' => '/api/admin-audio-download.php?key=' . $key]);
}

errorResponse('Method not allowed', 405);
