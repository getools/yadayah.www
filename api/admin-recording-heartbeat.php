<?php
/**
 * Lightweight per-item recording heartbeat for the admin-record-popout.
 *
 * While the popout is open for a given feed_item_key, the JS pings this
 * endpoint every ~5 seconds. Each ping touches /tmp/recording_active_<key>
 * with the current timestamp. The Recordings tab's `in_progress` signal
 * counts an item as locked if its heartbeat file's mtime is within the
 * last 15s — so a closed window auto-unlocks 15s later even if the
 * explicit release call (below) doesn't make it through.
 *
 * Endpoints:
 *   POST {action:'heartbeat', item_key:N}  → touches /tmp/recording_active_<key>
 *   POST {action:'release',   item_key:N}  → unlinks /tmp/recording_active_<key>
 *
 * Why a file-mtime instead of a DB row: avoids a write-heavy table that
 * would only ever be transient state, and survives PHP-pool restarts
 * cleanly. The release call is "best effort" — staleness expiry is the
 * authoritative recovery path.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
session_write_close();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action  = $data['action'] ?? '';
$itemKey = (int)($data['item_key'] ?? 0);
if (!$itemKey)              errorResponse('item_key required');
if ($action !== 'heartbeat' && $action !== 'release') {
    errorResponse('action must be heartbeat or release');
}

$path = sys_get_temp_dir() . "/recording_active_$itemKey";

if ($action === 'release') {
    @unlink($path);
    jsonResponse(['ok' => true, 'released' => true]);
}

// heartbeat: touch the file (create if missing, update mtime if present).
@touch($path);
@chmod($path, 0664);
jsonResponse(['ok' => true, 'heartbeat_at' => date('c')]);
