<?php
/**
 * Toggle the feed_item_allow_silent_recording flag for a single item.
 * When set, the upload validator (admin-transcript-upload.php) and the
 * finalize worker skip the -70 dB mean-volume rejection so parts of an
 * intentionally-silent section (e.g. a closing meditation) make it into
 * the final MP3 instead of being treated as a broken tab-share capture.
 *
 * GET  ?item_key=N         -> { allow_silent: bool }
 * POST JSON { item_key, allow_silent } -> { ok: true, allow_silent: bool }
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    $stmt = $db->prepare("SELECT feed_item_allow_silent_recording FROM yy_feed_item WHERE feed_item_key = ?");
    $stmt->execute([$itemKey]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('item not found');
    jsonResponse(['allow_silent' => (bool)$row['feed_item_allow_silent_recording']]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $itemKey = (int)($body['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');
    // Cast to int — PDO + Postgres rejects PHP `false` against a BOOLEAN
    // column (it binds as "") so always normalise to 0/1 first.
    $allow = (int)!empty($body['allow_silent']);
    $upd = $db->prepare("UPDATE yy_feed_item SET feed_item_allow_silent_recording = ? WHERE feed_item_key = ?");
    $upd->execute([$allow, $itemKey]);
    if ($upd->rowCount() === 0) errorResponse('item not found');
    logMonitorEvent('admin_item', 'info',
        ($allow ? 'Enabled' : 'Disabled') . ' allow_silent_recording for item ' . $itemKey,
        'by user ' . ($user['user_code'] ?? '?'), true);
    jsonResponse(['ok' => true, 'allow_silent' => (bool)$allow]);
}

errorResponse('Method not allowed', 405);
