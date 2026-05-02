<?php
/**
 * Sync-check support endpoint.
 *
 * POST {action: 'reset_to_partial', item_key, resume_seconds}
 *   Truncates the durable MP3 at `resume_seconds` using ffmpeg and stores the
 *   truncated copy as a partial part (audio_{key}_part_1.mp3). Clears the
 *   item's feed_item_audio_file and sets feed_item_audio_resume_seconds so a
 *   subsequent Continue Recording session picks up from that point. Used by
 *   the automated sync-check tool when audio drift is detected: keeps the
 *   known-good portion, discards the rest, and turns the item back into a
 *   Partial so the user can re-record from the last known good moment.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();

$AUDIO_DIR_ABS = dirname(__DIR__) . '/u/audio';
$AUDIO_DIR_REL = 'u/audio';
$PARTS_DIR_ABS = $AUDIO_DIR_ABS . '/parts';

if (!is_dir($PARTS_DIR_ABS)) @mkdir($PARTS_DIR_ABS, 0775, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST required', 405);
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
$itemKey = (int)($data['item_key'] ?? 0);
if (!$itemKey) errorResponse('item_key required');

if ($action !== 'reset_to_partial') errorResponse('Unknown action');

$resumeSeconds = (int)($data['resume_seconds'] ?? -1);
if ($resumeSeconds < 1) errorResponse('resume_seconds must be > 0');

$stmt = $db->prepare("SELECT feed_item_audio_file FROM yy_feed_item WHERE feed_item_key = ?");
$stmt->execute([$itemKey]);
$rel = $stmt->fetchColumn();
if (!$rel) errorResponse('Item has no durable audio file to reset');
$srcAbs = dirname(__DIR__) . '/' . ltrim($rel, '/');
if (!is_file($srcAbs)) errorResponse('Audio file missing on disk: ' . $rel);

$ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
if (!$ffmpeg) errorResponse('ffmpeg not available');

// Wipe any existing parts before installing the truncated one — otherwise a
// stale part from a previous session would survive the reset and ruin the
// concat at finalize time.
foreach (glob($PARTS_DIR_ABS . "/audio_{$itemKey}_part_*.*") as $p) @unlink($p);

$partAbs = $PARTS_DIR_ABS . "/audio_{$itemKey}_part_1.mp3";
// -ss before -i for fast seek; -t for duration; -c copy is bit-exact and
// fast (no re-encode). Audio cuts on a frame boundary which is sub-second.
$cmd = escapeshellcmd($ffmpeg) . ' -y -i ' . escapeshellarg($srcAbs)
     . ' -t ' . escapeshellarg((string)$resumeSeconds)
     . ' -c copy ' . escapeshellarg($partAbs) . ' 2>&1';
$out = shell_exec($cmd);
if (!is_file($partAbs) || filesize($partAbs) < 1000) {
    errorResponse('ffmpeg truncate failed: ' . substr(trim($out ?? ''), -300));
}
@chmod($partAbs, 0664);

// Clear the durable audio pointer + delete the underlying file so the item
// shows as Partial in the Recordings list.
@unlink($srcAbs);
$db->prepare("UPDATE yy_feed_item
                  SET feed_item_audio_file = NULL,
                      feed_item_audio_resume_seconds = ?
                WHERE feed_item_key = ?")
   ->execute([$resumeSeconds, $itemKey]);

logMonitorEvent('transcript_sync', 'info',
    'Reset item ' . $itemKey . ' to partial at ' . $resumeSeconds . 's after sync-check found drift',
    'by user ' . ($user['user_code'] ?? '?'), true);

jsonResponse([
    'ok' => true,
    'resume_seconds' => $resumeSeconds,
    'part_file' => 'u/audio/parts/' . basename($partAbs),
    'part_size' => filesize($partAbs),
]);
