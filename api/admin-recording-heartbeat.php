<?php
/**
 * Per-item recording heartbeat for the admin-record-popout.
 *
 * The popout sends one heartbeat every ~5 seconds covering every item in
 * its queue (not just the currently-recording one), so the moment an
 * operator clicks "Process Selected" the entire batch is locked from
 * other operators — not just the head of the queue.
 *
 * Each heartbeat updates the mtime of /tmp/recording_active_<key>. The
 * file's contents (written once on first heartbeat, preserved on later
 * touches) are JSON identifying the locking user — admin-recordings.php
 * reads this so the Recordings tab UI can show "Locked by <user_code>".
 *
 * Endpoints (POST JSON):
 *   {action:'heartbeat', item_keys:[N1,N2,...]}        ← whole-batch lock refresh
 *   {action:'release',   item_keys:[N1,N2,...]}        ← release the batch
 *   {action:'heartbeat', item_key:N}                   ← legacy single-item form
 *   {action:'release',   item_key:N}                   ← legacy single-item form
 *
 * The mtime freshness window is 15s (admin-recordings.php). A closed
 * popout's lock auto-expires within 15s even without an explicit release.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
session_write_close();

$data    = json_decode(file_get_contents('php://input'), true) ?: [];
$action  = $data['action'] ?? '';
$keys    = [];

if (!empty($data['item_keys']) && is_array($data['item_keys'])) {
    foreach ($data['item_keys'] as $k) {
        $k = (int)$k;
        if ($k > 0) $keys[] = $k;
    }
} elseif (!empty($data['item_key'])) {
    $k = (int)$data['item_key'];
    if ($k > 0) $keys[] = $k;
}
if (!$keys)                   errorResponse('item_key or item_keys required');
if ($action !== 'heartbeat' && $action !== 'release') {
    errorResponse('action must be heartbeat or release');
}

$tmp = sys_get_temp_dir();
$results = [];

if ($action === 'release') {
    foreach ($keys as $k) {
        $path = "$tmp/recording_active_$k";
        if (is_file($path)) @unlink($path);
        $results[$k] = 'released';
    }
    jsonResponse(['ok' => true, 'count' => count($results), 'results' => $results]);
}

// heartbeat: for each key, touch the file. On first touch, write the JSON
// identity of the current user. Don't overwrite on subsequent touches —
// the original locker stays the locker even if a different operator's
// session somehow sneaks in a heartbeat (would be a security boundary
// violation we don't want to silently mask).
$identity = json_encode([
    'user_code'  => $user['user_code'] ?? '',
    'user_key'   => $user['user_key']  ?? null,
    'started_at' => date('c'),
]);

foreach ($keys as $k) {
    $path = "$tmp/recording_active_$k";
    if (!is_file($path)) {
        @file_put_contents($path, $identity);
        @chmod($path, 0664);
        $results[$k] = 'created';
    } else {
        @touch($path);
        $results[$k] = 'touched';
    }
}

jsonResponse(['ok' => true, 'count' => count($results), 'results' => $results, 'heartbeat_at' => date('c')]);
