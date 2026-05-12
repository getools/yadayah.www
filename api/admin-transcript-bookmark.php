<?php
/**
 * Transcript bookmark / note endpoint.
 *
 *   GET  ?item_key=N
 *     → { bookmarks: [
 *           { key, segment, segment_secs, user_key, user_handle, user_display,
 *             note, created, is_mine },
 *           ...
 *         ] }
 *     Ordered by segment ascending, then created ascending. The client splits
 *     them into per-row groups and the full-list panel/modal.
 *
 *   POST { action:'add', item_key:N, segment:'HH:MM:SS', note:'...' }
 *     → { ok:true, bookmark: {...} }   (returns the freshly-created row)
 *
 *   POST { action:'delete', bookmark_key:K }
 *     → { ok:true }   (only succeeds if the calling user authored the row;
 *                      anyone else gets 403)
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db   = getDb();
$userKey = (int)$user['user_key'];

$method = $_SERVER['REQUEST_METHOD'];
$data = [];
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
}
$action = $data['action'] ?? ($_GET['action'] ?? '');

function bookmarkUserDisplay(array $row): array {
    // user_handle is the @-style identity; fall back to display_name / code so
    // we always have *something* to show for legacy admins without a handle.
    $handle  = trim((string)($row['user_handle']        ?? ''));
    $display = trim((string)($row['user_display_name']  ?? ''));
    $code    = trim((string)($row['user_code']          ?? ''));
    $name    = trim((string)($row['user_name_full']     ?? ''));
    $primary = $handle !== '' ? $handle
             : ($display !== '' ? $display
             : ($name !== '' ? $name
             : ($code !== '' ? $code : 'user#' . (int)$row['user_key'])));
    return ['handle' => $handle, 'display' => $primary];
}

function intervalToSecs(string $s): int {
    if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $s, $m)) {
        return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)floor((float)$m[3]);
    }
    return 0;
}

if ($method === 'GET') {
    $itemKey = (int)($_GET['item_key'] ?? 0);
    if (!$itemKey) errorResponse('item_key required');

    $stmt = $db->prepare("
        SELECT b.feed_item_transcript_bookmark_key AS key,
               to_char(b.feed_item_transcript_segment, 'HH24:MI:SS') AS segment,
               b.user_key, b.bookmark_note AS note, b.bookmark_create_dtime AS created,
               u.user_handle, u.user_display_name, u.user_code, u.user_name_full
          FROM yy_feed_item_transcript_bookmark b
          JOIN yy_user u ON u.user_key = b.user_key
         WHERE b.feed_item_key = ?
         ORDER BY b.feed_item_transcript_segment ASC,
                  b.bookmark_create_dtime ASC,
                  b.feed_item_transcript_bookmark_key ASC
    ");
    $stmt->execute([$itemKey]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $names = bookmarkUserDisplay($r);
        $out[] = [
            'key'          => (int)$r['key'],
            'segment'      => $r['segment'],
            'segment_secs' => intervalToSecs((string)$r['segment']),
            'user_key'     => (int)$r['user_key'],
            'user_handle'  => $names['handle'],
            'user_display' => $names['display'],
            'note'         => (string)$r['note'],
            'created'      => $r['created'],
            'is_mine'      => ((int)$r['user_key'] === $userKey),
        ];
    }
    jsonResponse(['bookmarks' => $out]);
}

if ($method === 'POST' && $action === 'add') {
    $itemKey = (int)($data['item_key'] ?? 0);
    $segment = trim((string)($data['segment'] ?? ''));
    $note    = trim((string)($data['note'] ?? ''));
    if (!$itemKey) errorResponse('item_key required');
    // Segment must look like HH:MM:SS (optionally with .fractional secs).
    // We accept what the row table renders so the JS can just pass the
    // displayed value back unchanged.
    if (!preg_match('/^\d{1,3}:\d{2}:\d{2}(?:\.\d+)?$/', $segment)) {
        errorResponse('segment must be HH:MM:SS');
    }
    if ($note === '') errorResponse('note cannot be empty');
    if (strlen($note) > 4000) errorResponse('note too long (max 4000 chars)');

    $ins = $db->prepare("
        INSERT INTO yy_feed_item_transcript_bookmark
            (feed_item_key, feed_item_transcript_segment, user_key, bookmark_note)
        VALUES (?, ?::interval, ?, ?)
        RETURNING feed_item_transcript_bookmark_key AS key,
                  to_char(feed_item_transcript_segment, 'HH24:MI:SS') AS segment,
                  bookmark_create_dtime AS created
    ");
    $ins->execute([$itemKey, $segment, $userKey, $note]);
    $row = $ins->fetch();

    // Pull the handle/display for the return payload so the client can
    // render the new note immediately without a second round-trip.
    $u = $db->prepare("SELECT user_handle, user_display_name, user_code, user_name_full, user_key FROM yy_user WHERE user_key = ?");
    $u->execute([$userKey]);
    $names = bookmarkUserDisplay($u->fetch() ?: ['user_key' => $userKey]);

    jsonResponse(['ok' => true, 'bookmark' => [
        'key'          => (int)$row['key'],
        'segment'      => $row['segment'],
        'segment_secs' => intervalToSecs((string)$row['segment']),
        'user_key'     => $userKey,
        'user_handle'  => $names['handle'],
        'user_display' => $names['display'],
        'note'         => $note,
        'created'      => $row['created'],
        'is_mine'      => true,
    ]]);
}

if ($method === 'POST' && $action === 'delete') {
    $bk = (int)($data['bookmark_key'] ?? 0);
    if (!$bk) errorResponse('bookmark_key required');
    // Only the author may delete their own note. We check author first so we
    // can return 403 instead of a silent no-op.
    $own = $db->prepare("SELECT user_key FROM yy_feed_item_transcript_bookmark WHERE feed_item_transcript_bookmark_key = ?");
    $own->execute([$bk]);
    $row = $own->fetch();
    if (!$row) errorResponse('not found', 404);
    if ((int)$row['user_key'] !== $userKey) errorResponse('forbidden', 403);
    $db->prepare("DELETE FROM yy_feed_item_transcript_bookmark WHERE feed_item_transcript_bookmark_key = ?")
       ->execute([$bk]);
    jsonResponse(['ok' => true]);
}

errorResponse('Unknown action');
