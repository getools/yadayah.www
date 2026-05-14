<?php
/**
 * Admin CRUD for the central font registry (yy_font).
 *
 *   GET                → list all rows (active + inactive) for the admin grid
 *   POST               → create a row { display, stack, glyph, group, sort, note, active }
 *   PUT  ?key=N        → update a row
 *   DELETE ?key=N      → delete a row
 *
 * Display names are unique. Section_group: NULL = main alphabetical list,
 * 1/2/... = grouped below with a divider per group.
 */
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

$method = $_SERVER['REQUEST_METHOD'];

function readBody(): array {
    $j = file_get_contents('php://input');
    $d = $j !== false && $j !== '' ? json_decode($j, true) : null;
    return is_array($d) ? $d : [];
}

function normalizeRow(array $in): array {
    $group = $in['group'] ?? null;
    if ($group === '' || $group === null) $group = null;
    else $group = (int)$group;
    return [
        'display' => trim((string)($in['display'] ?? '')),
        'stack'   => trim((string)($in['stack']   ?? '')),
        'glyph'   => isset($in['glyph']) && $in['glyph'] !== '' ? (string)$in['glyph'] : null,
        'group'   => $group,
        'sort'    => (int)($in['sort'] ?? 0),
        'note'    => isset($in['note']) && $in['note'] !== '' ? (string)$in['note'] : null,
        'active'  => !empty($in['active']) ? 't' : 'f',
    ];
}

switch ($method) {
case 'GET':
    $stmt = $db->query("
        SELECT font_key, font_display_name, font_css_stack, font_active_flag,
               font_glyph_text, font_section_group, font_sort, font_note
          FROM yy_font
         ORDER BY (CASE WHEN font_section_group IS NULL THEN 0 ELSE 1 END),
                  font_section_group NULLS FIRST,
                  font_sort,
                  font_display_name
    ");
    jsonResponse(['rows' => $stmt->fetchAll()]);

case 'POST':
    $r = normalizeRow(readBody());
    if ($r['display'] === '') errorResponse('display is required');
    if ($r['stack']   === '') errorResponse('stack is required');
    try {
        $stmt = $db->prepare("
            INSERT INTO yy_font (font_display_name, font_css_stack, font_active_flag,
                                 font_glyph_text, font_section_group, font_sort, font_note)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING font_key
        ");
        $stmt->execute([$r['display'], $r['stack'], $r['active'], $r['glyph'], $r['group'], $r['sort'], $r['note']]);
        jsonResponse(['font_key' => (int)$stmt->fetchColumn()], 201);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'unique') !== false || strpos($e->getMessage(), 'duplicate') !== false) {
            errorResponse('A font with that display name already exists', 409);
        }
        throw $e;
    }

case 'PUT':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');
    $r = normalizeRow(readBody());
    if ($r['display'] === '') errorResponse('display is required');
    if ($r['stack']   === '') errorResponse('stack is required');
    try {
        $stmt = $db->prepare("
            UPDATE yy_font
               SET font_display_name = ?, font_css_stack = ?, font_active_flag = ?,
                   font_glyph_text = ?, font_section_group = ?, font_sort = ?, font_note = ?
             WHERE font_key = ?
        ");
        $stmt->execute([$r['display'], $r['stack'], $r['active'], $r['glyph'], $r['group'], $r['sort'], $r['note'], $key]);
        if ($stmt->rowCount() === 0) errorResponse('Not found', 404);
        jsonResponse(['ok' => true]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'unique') !== false || strpos($e->getMessage(), 'duplicate') !== false) {
            errorResponse('A font with that display name already exists', 409);
        }
        throw $e;
    }

case 'DELETE':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');
    $db->prepare("DELETE FROM yy_font WHERE font_key = ?")->execute([$key]);
    jsonResponse(['ok' => true]);

default:
    errorResponse('Method not allowed', 405);
}
