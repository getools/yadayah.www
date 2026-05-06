<?php
/**
 * Operator override: clear specific feed_items from any admin's recording
 * queue when a popout has gone stale or otherwise needs unjamming.
 *
 * Refuses to clear items that are *actively recording* — a key is
 * actively recording when its `/tmp/recording_active_<k>` JSON has
 * `recording_now: true` AND its mtime is fresh (within 8s; the popout
 * heartbeats every 5s, so 8s gives a one-tick grace period).
 *
 * Cleared keys get a 10-min "blocked" tombstone at
 * /tmp/recording_blocked_<k>; the heartbeat endpoint refuses to recreate
 * a lock for those and returns blocked_keys to the popout so it can drop
 * them from its in-memory queue. Without the tombstone, a still-running
 * popout would recreate the lock within 5 seconds.
 *
 * POST JSON:
 *   { item_keys: [N1, N2, ...] }
 *
 * Response:
 *   { ok: true,
 *     cleared:  [N1, ...],
 *     rejected: [{ key: N2, reason: 'recording_now', age_s: 3 }, ...] }
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
session_write_close();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$keys = [];
if (!empty($data['item_keys']) && is_array($data['item_keys'])) {
    foreach ($data['item_keys'] as $k) {
        $k = (int)$k;
        if ($k > 0) $keys[] = $k;
    }
}
if (!$keys) errorResponse('item_keys required');

$tmp      = sys_get_temp_dir();
$cleared  = [];
$rejected = [];

foreach ($keys as $k) {
    $path = "$tmp/recording_active_$k";
    if (is_file($path)) {
        $age  = time() - (int)@filemtime($path);
        $body = json_decode((string)@file_get_contents($path), true) ?: [];
        if (!empty($body['recording_now']) && $age <= 8) {
            $rejected[] = ['key' => $k, 'reason' => 'recording_now', 'age_s' => $age];
            continue;
        }
        @unlink($path);
    }
    // Tombstone so a live popout doesn't immediately recreate the lock on
    // its next heartbeat. 10 minutes is plenty for the popout to receive
    // the blocked_keys signal and drop the key from its queue.
    $tomb = "$tmp/recording_blocked_$k";
    @file_put_contents($tomb, json_encode([
        'cleared_by_user_code' => $user['user_code'] ?? '',
        'cleared_by_user_key'  => $user['user_key']  ?? null,
        'cleared_at'           => date('c'),
    ]));
    @chmod($tomb, 0664);
    $cleared[] = $k;
}

jsonResponse(['ok' => true, 'cleared' => $cleared, 'rejected' => $rejected]);
