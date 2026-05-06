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
$current = (int)($data['current_item_key'] ?? 0);

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

// heartbeat: rewrite each lock file every tick so `recording_now` can flip
// as the popout advances through its queue. The first writer's `started_at`
// is preserved (read from existing JSON) so the lock continues to identify
// the original locker even if the popout advances between heartbeats.
//
// We also honour blocked-key tombstones (/tmp/recording_blocked_<key>) —
// when an operator clears an item from another popout's queue, we drop the
// lock and tell the popout to remove the key from its in-memory queue via
// the `blocked_keys` field in the response.
$blocked = [];
$writableKeys = [];
foreach ($keys as $k) {
    $bpath = "$tmp/recording_blocked_$k";
    if (is_file($bpath)) {
        $age = time() - (int)@filemtime($bpath);
        if ($age <= 600) {       // 10 min tombstone window
            $blocked[] = $k;
            // Make sure no stale active-lock is left behind for a blocked key.
            @unlink("$tmp/recording_active_$k");
            $results[$k] = 'blocked';
            continue;
        }
        @unlink($bpath);          // tombstone aged out; allow re-locking
    }
    $writableKeys[] = $k;
}

foreach ($writableKeys as $k) {
    $path     = "$tmp/recording_active_$k";
    $existing = is_file($path) ? (json_decode((string)@file_get_contents($path), true) ?: []) : [];
    $started  = $existing['started_at'] ?? date('c');
    $body     = json_encode([
        'user_code'      => $user['user_code'] ?? '',
        'user_key'       => $user['user_key']  ?? null,
        'started_at'     => $started,
        'recording_now'  => ($current > 0 && $k === $current),
        'updated_at'     => date('c'),
    ]);
    @file_put_contents($path, $body);
    @chmod($path, 0664);
    $results[$k] = isset($existing['started_at']) ? 'touched' : 'created';
}

jsonResponse([
    'ok' => true,
    'count' => count($results),
    'results' => $results,
    'blocked_keys' => $blocked,
    'heartbeat_at' => date('c'),
]);
