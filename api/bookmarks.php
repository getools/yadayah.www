<?php
/**
 * Per-user bookmarks for flipbook volumes. Backs the bookmark icon, the
 * sidebar Bookmark tab, the slider markers, and the auto-bookmark of last
 * viewed page in /js/flipbook-viewer.js.
 *
 *   GET    ?volume_key=N                      → all bookmarks for current user in that volume, sorted by page
 *   POST   { volume_key, bookmark_page, ... } → create a manual bookmark
 *   POST   { action:"auto_bookmark", volume_key, bookmark_page, bookmark_offset? }
 *                                              → upsert THE auto bookmark for this user+volume (one per pair)
 *   PUT    ?key=N body { bookmark_label, ... } → update fields on a manual bookmark
 *   DELETE ?key=N                              → remove
 *
 * Auth: requires a logged-in session ($_SESSION['user_key']). Anonymous users
 * silently get back an empty list rather than an error so the bookmark UI
 * just renders empty without nag prompts.
 */
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = (int)($_SESSION['user_key'] ?? 0);
$method  = $_SERVER['REQUEST_METHOD'];
$db      = getDb();

// Auto-bookmark (last viewed page) for anonymous users would just be noise —
// we silently no-op rather than return 401, so the viewer's fire-and-forget
// call doesn't pollute the console with auth errors.
if ($method === 'GET' && !$userKey) jsonResponse(['bookmarks' => []]);
if (!$userKey) jsonResponse(['ok' => true, 'skipped' => 'anonymous']);

$EDITABLE_FIELDS = ['bookmark_page', 'bookmark_offset', 'bookmark_label', 'bookmark_note', 'bookmark_color'];

function readBody(): array {
    $b = json_decode(file_get_contents('php://input'), true);
    return is_array($b) ? $b : [];
}

// Flipbook viewer doesn't bake volume_key into FLIPBOOK_CONFIG (only bookCode),
// so we accept either and resolve. book_code → volume_key via yy_volume.volume_code.
function resolveVolumeKey(PDO $db, ?int $volumeKey, ?string $bookCode): ?int {
    if ($volumeKey) return $volumeKey;
    if (!$bookCode) return null;
    $s = $db->prepare("SELECT volume_key FROM yy_volume WHERE volume_code = ? LIMIT 1");
    $s->execute([$bookCode]);
    $v = $s->fetchColumn();
    return $v ? (int)$v : null;
}

if ($method === 'GET') {
    $volumeKey = resolveVolumeKey($db,
        (int)($_GET['volume_key'] ?? 0) ?: null,
        $_GET['book_code'] ?? null);
    if (!$volumeKey) errorResponse('volume_key or book_code required');
    $stmt = $db->prepare("
        SELECT bookmark_key, user_key, volume_key, bookmark_page, bookmark_offset,
               bookmark_label, bookmark_note, bookmark_color, bookmark_auto_flag, bookmark_dtime
          FROM yy_bookmark
         WHERE user_key = ? AND volume_key = ?
         ORDER BY bookmark_auto_flag ASC, bookmark_page ASC, bookmark_key ASC
    ");
    $stmt->execute([$userKey, $volumeKey]);
    $rows = $stmt->fetchAll();
    // Auto-bookmark defaults from admin-flipbook (color/label/note) — used by
    // the viewer to display auto bookmarks whose own fields are NULL.
    $defStmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'page' AND setting_group_code = 'flipbook' AND setting_code LIKE 'auto-bookmark-%'");
    $defStmt->execute();
    $defaults = ['color' => null, 'label' => null, 'note' => null];
    foreach ($defStmt->fetchAll() as $r) {
        $key = substr($r['setting_code'], strlen('auto-bookmark-'));
        $defaults[$key] = $r['setting_value'];
    }
    jsonResponse(['bookmarks' => $rows, 'auto_defaults' => $defaults]);
}

if ($method === 'POST') {
    $body   = readBody();
    $action = $body['action'] ?? '';

    // Auto-bookmark: idempotent upsert keyed on (user, volume, auto_flag=true).
    // Single row per pair — replaces older auto positions. Manual fields stay
    // NULL unless the admin Flipbook → Auto Bookmark defaults override them
    // when rendering.
    if ($action === 'auto_bookmark') {
        $volumeKey = resolveVolumeKey($db, (int)($body['volume_key'] ?? 0) ?: null, $body['book_code'] ?? null);
        $page      = (int)($body['bookmark_page'] ?? 0);
        if (!$volumeKey || !$page) errorResponse('volume_key/book_code + bookmark_page required');
        $offset = isset($body['bookmark_offset']) && $body['bookmark_offset'] !== ''
                  ? (int)$body['bookmark_offset'] : null;
        $upd = $db->prepare("
            UPDATE yy_bookmark
               SET bookmark_page = ?, bookmark_offset = ?, bookmark_dtime = NOW()
             WHERE user_key = ? AND volume_key = ? AND bookmark_auto_flag = TRUE
        ");
        $upd->execute([$page, $offset, $userKey, $volumeKey]);
        if ($upd->rowCount() === 0) {
            $ins = $db->prepare("
                INSERT INTO yy_bookmark (user_key, volume_key, bookmark_page, bookmark_offset, bookmark_auto_flag)
                VALUES (?, ?, ?, ?, TRUE)
            ");
            $ins->execute([$userKey, $volumeKey, $page, $offset]);
        }
        jsonResponse(['ok' => true]);
    }

    // Manual create
    $volumeKey = resolveVolumeKey($db, (int)($body['volume_key'] ?? 0) ?: null, $body['book_code'] ?? null);
    $page      = (int)($body['bookmark_page'] ?? 0);
    if (!$volumeKey || !$page) errorResponse('volume_key/book_code + bookmark_page required');
    $stmt = $db->prepare("
        INSERT INTO yy_bookmark
            (user_key, volume_key, bookmark_page, bookmark_offset,
             bookmark_label, bookmark_note, bookmark_color, bookmark_auto_flag)
        VALUES (?, ?, ?, ?, ?, ?, ?, FALSE)
        RETURNING bookmark_key
    ");
    $stmt->execute([
        $userKey, $volumeKey, $page,
        isset($body['bookmark_offset']) && $body['bookmark_offset'] !== '' ? (int)$body['bookmark_offset'] : null,
        isset($body['bookmark_label']) ? mb_substr((string)$body['bookmark_label'], 0, 200) : null,
        $body['bookmark_note']  ?? null,
        $body['bookmark_color'] ?? null,
    ]);
    jsonResponse(['ok' => true, 'bookmark_key' => (int)$stmt->fetchColumn()], 201);
}

if ($method === 'PUT') {
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('key required');
    // Verify ownership before any update
    $own = $db->prepare("SELECT user_key, bookmark_auto_flag FROM yy_bookmark WHERE bookmark_key = ?");
    $own->execute([$key]);
    $row = $own->fetch();
    if (!$row) errorResponse('Not found', 404);
    if ((int)$row['user_key'] !== $userKey) errorResponse('Forbidden', 403);

    $body   = readBody();
    $sets   = [];
    $params = [];
    foreach ($EDITABLE_FIELDS as $f) {
        if (!array_key_exists($f, $body)) continue;
        $v = $body[$f];
        if ($f === 'bookmark_page')   $v = (int)$v;
        if ($f === 'bookmark_offset') $v = ($v === '' || $v === null) ? null : (int)$v;
        if ($f === 'bookmark_label' && $v !== null) $v = mb_substr((string)$v, 0, 200);
        $sets[]   = "$f = ?";
        $params[] = $v;
    }
    if (!$sets) jsonResponse(['ok' => true, 'noop' => true]);
    $params[] = $key;
    $sql = 'UPDATE yy_bookmark SET ' . implode(', ', $sets) . ' WHERE bookmark_key = ?';
    $db->prepare($sql)->execute($params);
    jsonResponse(['ok' => true]);
}

if ($method === 'DELETE') {
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('key required');
    $stmt = $db->prepare("DELETE FROM yy_bookmark WHERE bookmark_key = ? AND user_key = ?");
    $stmt->execute([$key, $userKey]);
    jsonResponse(['ok' => true, 'deleted' => $stmt->rowCount()]);
}

errorResponse('Method not allowed', 405);
