<?php
/**
 * Per-user admin UI state — generic key/value persistence backing the
 * /js/admin-state.js auto-persist helper. Lets filter values, sort columns,
 * active tabs, page numbers, etc. survive across refreshes AND browsers.
 *
 * GET  ?scope=feeds              → { items: { name1: value1, name2: value2, … } }
 * POST { scope, name, value }    → upsert one key
 * POST { scope, items: {…} }     → upsert many keys (preferred; one round-trip)
 * DELETE ?scope=feeds&name=X     → remove one key (or all of scope if name omitted)
 *
 * Values are JSON-encoded on the wire. Server stores them verbatim in TEXT.
 */
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();
$userKey = (int)($user['user_key'] ?? 0);
if ($userKey <= 0) errorResponse('No user key', 403);

$method = $_SERVER['REQUEST_METHOD'];

// All operations are scoped — no scope = nothing to do.
$scopeFromQuery = trim((string)($_GET['scope'] ?? ''));

if ($method === 'GET') {
    if ($scopeFromQuery === '') errorResponse('scope required');
    $stmt = $db->prepare("
        SELECT user_admin_state_name AS name, user_admin_state_value AS value
          FROM yy_user_admin_state
         WHERE user_key = ? AND user_admin_state_scope = ?
    ");
    $stmt->execute([$userKey, $scopeFromQuery]);
    $items = [];
    foreach ($stmt->fetchAll() as $r) {
        $v = $r['value'];
        $decoded = $v === null ? null : json_decode($v, true);
        $items[$r['name']] = ($decoded === null && $v !== null && $v !== 'null') ? $v : $decoded;
    }
    jsonResponse(['scope' => $scopeFromQuery, 'items' => $items]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) errorResponse('Invalid JSON');
    $scope = trim((string)($body['scope'] ?? ''));
    if ($scope === '') errorResponse('scope required');

    $upserts = [];
    if (isset($body['items']) && is_array($body['items'])) {
        foreach ($body['items'] as $name => $value) {
            $upserts[] = [(string)$name, $value];
        }
    } elseif (isset($body['name'])) {
        $upserts[] = [(string)$body['name'], $body['value'] ?? null];
    } else {
        errorResponse('items or name+value required');
    }

    $upsertStmt = $db->prepare("
        INSERT INTO yy_user_admin_state
            (user_key, user_admin_state_scope, user_admin_state_name,
             user_admin_state_value, user_admin_state_revision_dtime)
        VALUES (?, ?, ?, ?, NOW())
        ON CONFLICT (user_key, user_admin_state_scope, user_admin_state_name)
        DO UPDATE SET
            user_admin_state_value = EXCLUDED.user_admin_state_value,
            user_admin_state_revision_dtime = NOW()
    ");
    $deleteStmt = $db->prepare("
        DELETE FROM yy_user_admin_state
         WHERE user_key = ? AND user_admin_state_scope = ? AND user_admin_state_name = ?
    ");

    $written = 0;
    foreach ($upserts as [$name, $value]) {
        if ($name === '') continue;
        // Empty / null / undefined values clear the row so we don't leave
        // stale entries cluttering the table.
        if ($value === null || $value === '' || $value === false || (is_array($value) && empty($value))) {
            $deleteStmt->execute([$userKey, $scope, substr($name, 0, 200)]);
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
            // Clamp obviously-pathological payloads.
            if (strlen($encoded) > 16384) errorResponse("Value for '$name' too large (>16KB)");
            $upsertStmt->execute([$userKey, $scope, substr($name, 0, 200), $encoded]);
        }
        $written++;
    }
    jsonResponse(['saved' => $written]);
}

if ($method === 'DELETE') {
    if ($scopeFromQuery === '') errorResponse('scope required');
    $name = trim((string)($_GET['name'] ?? ''));
    if ($name === '') {
        $stmt = $db->prepare("DELETE FROM yy_user_admin_state WHERE user_key=? AND user_admin_state_scope=?");
        $stmt->execute([$userKey, $scopeFromQuery]);
    } else {
        $stmt = $db->prepare("DELETE FROM yy_user_admin_state WHERE user_key=? AND user_admin_state_scope=? AND user_admin_state_name=?");
        $stmt->execute([$userKey, $scopeFromQuery, $name]);
    }
    jsonResponse(['deleted' => $stmt->rowCount()]);
}

errorResponse('Method not allowed', 405);
