<?php
/**
 * TEST endpoint — multi-section pages CRUD.
 * Parallel to api/pages.php; writes to yy_page_test / yy_page_section.
 *
 * Routes:
 *   GET                     → list pages
 *   GET ?key=N              → page with ordered sections
 *   POST                    → create page (page_test_code required)
 *   PUT ?key=N              → update page metadata
 *   DELETE ?key=N           → delete page (sections cascade)
 *   POST ?key=N&action=sections
 *                           → bulk save sections; body { sections:[...], deleted:[ids] }
 *
 * A section row looks like:
 *   { page_section_key, page_section_type, page_section_title,
 *     page_section_config (object), page_section_active_flag, page_section_sort }
 */
require_once __DIR__ . '/../config.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);
$method = $_SERVER['REQUEST_METHOD'];

function fetchPageWithSections(PDO $db, int $key): ?array {
    $stmt = $db->prepare("SELECT * FROM yy_page_test WHERE page_test_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $sec = $db->prepare("SELECT * FROM yy_page_section WHERE page_test_key = ? ORDER BY page_section_sort, page_section_key");
    $sec->execute([$key]);
    $sections = [];
    foreach ($sec->fetchAll() as $s) {
        $cfg = $s['page_section_config'];
        if (is_string($cfg) && $cfg !== '') {
            $decoded = json_decode($cfg, true);
            $s['page_section_config'] = is_array($decoded) ? $decoded : new stdClass();
        } elseif (!$cfg) {
            $s['page_section_config'] = new stdClass();
        }
        $sections[] = $s;
    }
    $row['sections'] = $sections;
    return $row;
}

switch ($method) {

case 'GET':
    if (!empty($_GET['key'])) {
        $page = fetchPageWithSections($db, (int)$_GET['key']);
        if (!$page) errorResponse('Not found', 404);
        jsonResponse($page);
    }
    $list = $db->query("SELECT p.*, (SELECT COUNT(*) FROM yy_page_section s WHERE s.page_test_key = p.page_test_key) AS section_count FROM yy_page_test p ORDER BY page_test_code")->fetchAll();
    jsonResponse($list);

case 'POST':
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) errorResponse('Invalid JSON');

    // Bulk save sections for a page
    if (($_GET['action'] ?? '') === 'sections' && !empty($_GET['key'])) {
        $pageKey = (int)$_GET['key'];
        $check = $db->prepare("SELECT page_test_key FROM yy_page_test WHERE page_test_key = ?");
        $check->execute([$pageKey]);
        if (!$check->fetch()) errorResponse('Page not found', 404);

        $allowedTypes = ['static', 'carousel', 'items', 'custom', 'layout'];
        $sections = $input['sections'] ?? [];
        $deleted  = $input['deleted']  ?? [];
        if (!is_array($sections)) errorResponse('sections must be an array');

        // Sections arrive in pre-order traversal (parent before child) so a
        // single forward pass can resolve parent_key references via the
        // local_id → db_key map we build as we go.
        $db->beginTransaction();
        try {
            foreach ($deleted as $delKey) {
                $db->prepare("DELETE FROM yy_page_section WHERE page_section_key = ? AND page_test_key = ?")
                   ->execute([(int)$delKey, $pageKey]);
            }
            // Two UPDATE variants:
            //   updWith — touches parent_key (used when the payload declares
            //             tree info via parent_local_id / parent_key /
            //             parent_resolved).
            //   updWithout — leaves parent_key alone (legacy payload from an
            //             editor build that doesn't know about layouts).
            // Avoids accidentally NULL'ing parent_key when an old editor
            // saves over a migrated row.
            $updWith    = $db->prepare("UPDATE yy_page_section SET page_section_type = ?, page_section_title = ?, page_section_config = ?::jsonb, page_section_active_flag = ?, page_section_sort = ?, page_section_parent_key = ? WHERE page_section_key = ? AND page_test_key = ?");
            $updWithout = $db->prepare("UPDATE yy_page_section SET page_section_type = ?, page_section_title = ?, page_section_config = ?::jsonb, page_section_active_flag = ?, page_section_sort = ? WHERE page_section_key = ? AND page_test_key = ?");
            $ins = $db->prepare("INSERT INTO yy_page_section (page_test_key, page_section_type, page_section_title, page_section_config, page_section_active_flag, page_section_sort, page_section_parent_key) VALUES (?, ?, ?, ?::jsonb, ?, ?, ?) RETURNING page_section_key");
            $newKeys = [];
            $localMap = []; // local_id (string|int) → db_key
            foreach ($sections as $i => $s) {
                $type = $s['page_section_type'] ?? '';
                if (!in_array($type, $allowedTypes, true)) {
                    throw new RuntimeException("Invalid section type at index $i: $type");
                }
                $title  = isset($s['page_section_title']) ? trim((string)$s['page_section_title']) : '';
                $config = $s['page_section_config'] ?? new stdClass();
                $cfgJson = json_encode($config, JSON_UNESCAPED_UNICODE) ?: '{}';
                $active = !empty($s['page_section_active_flag']) ? 't' : 'f';
                $sort   = (int)($s['page_section_sort'] ?? $i);

                // Tree info detection: the editor build that understands
                // layouts sets parent_local_id (string) or
                // page_section_parent_key (int/null) or sends an
                // explicit parent_resolved=true marker. Old payloads have
                // none of these — we preserve the existing parent_key.
                $hasTreeInfo = array_key_exists('parent_local_id', $s)
                            || array_key_exists('page_section_parent_key', $s)
                            || !empty($s['parent_resolved']);
                $parentKey = null;
                if (isset($s['parent_local_id']) && $s['parent_local_id'] !== null && $s['parent_local_id'] !== '') {
                    $pid = (string)$s['parent_local_id'];
                    if (isset($localMap[$pid])) {
                        $parentKey = $localMap[$pid];
                    } elseif (ctype_digit($pid)) {
                        $parentKey = (int)$pid;
                    }
                } elseif (array_key_exists('page_section_parent_key', $s)
                          && $s['page_section_parent_key'] !== null
                          && $s['page_section_parent_key'] !== '') {
                    $parentKey = (int)$s['page_section_parent_key'];
                }

                if (!empty($s['page_section_key'])) {
                    $dbKey = (int)$s['page_section_key'];
                    if ($hasTreeInfo) {
                        $updWith->execute([$type, $title ?: null, $cfgJson, $active, $sort, $parentKey, $dbKey, $pageKey]);
                    } else {
                        $updWithout->execute([$type, $title ?: null, $cfgJson, $active, $sort, $dbKey, $pageKey]);
                    }
                } else {
                    $ins->execute([$pageKey, $type, $title ?: null, $cfgJson, $active, $sort, $parentKey]);
                    $dbKey = (int)$ins->fetchColumn();
                }
                $newKeys[] = $dbKey;
                $localId = isset($s['local_id']) ? (string)$s['local_id'] : null;
                if ($localId !== null && $localId !== '') $localMap[$localId] = $dbKey;
                // Also map by old db_key so a child referencing an existing
                // parent by parent_local_id = old_db_key still resolves.
                $localMap[(string)$dbKey] = $dbKey;
            }
            $db->commit();
            jsonResponse(['ok' => true, 'section_keys' => $newKeys, 'local_map' => $localMap]);
        } catch (Exception $e) {
            $db->rollBack();
            errorResponse('Save failed: ' . $e->getMessage());
        }
    }

    // Create new page
    $code = trim($input['page_test_code'] ?? '');
    if (!$code) errorResponse('page_test_code is required');

    $stmt = $db->prepare("INSERT INTO yy_page_test (page_test_code, page_test_title, page_test_meta_description, page_test_url, page_test_heading, page_test_subheading, page_test_description, page_test_heading_color, page_test_heading_size, page_test_subheading_color, page_test_subheading_size, page_test_description_color, page_test_description_size, page_test_description_class, page_test_description_style, page_test_background_color, page_test_text_color, page_test_active_flag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING page_test_key");
    $stmt->execute([
        $code,
        trim($input['page_test_title'] ?? '') ?: null,
        trim($input['page_test_meta_description'] ?? '') ?: null,
        trim($input['page_test_url'] ?? '') ?: null,
        trim($input['page_test_heading'] ?? '') ?: null,
        trim($input['page_test_subheading'] ?? '') ?: null,
        trim($input['page_test_description'] ?? '') ?: null,
        trim($input['page_test_heading_color'] ?? '') ?: null,
        trim($input['page_test_heading_size'] ?? '') ?: null,
        trim($input['page_test_subheading_color'] ?? '') ?: null,
        trim($input['page_test_subheading_size'] ?? '') ?: null,
        trim($input['page_test_description_color'] ?? '') ?: null,
        trim($input['page_test_description_size'] ?? '') ?: null,
        trim($input['page_test_description_class'] ?? '') ?: null,
        trim($input['page_test_description_style'] ?? '') ?: null,
        trim($input['page_test_background_color'] ?? '') ?: null,
        trim($input['page_test_text_color'] ?? '') ?: null,
        ($input['page_test_active_flag'] ?? true) ? 't' : 'f',
    ]);
    jsonResponse(['page_test_key' => (int)$stmt->fetchColumn()], 201);

case 'PUT':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) errorResponse('Invalid JSON');

    $check = $db->prepare("SELECT page_test_key FROM yy_page_test WHERE page_test_key = ?");
    $check->execute([$key]);
    if (!$check->fetch()) errorResponse('Not found', 404);

    $code = trim($input['page_test_code'] ?? '');
    if (!$code) errorResponse('page_test_code is required');

    $stmt = $db->prepare("UPDATE yy_page_test SET page_test_code = ?, page_test_title = ?, page_test_meta_description = ?, page_test_url = ?, page_test_heading = ?, page_test_subheading = ?, page_test_description = ?, page_test_heading_color = ?, page_test_heading_size = ?, page_test_subheading_color = ?, page_test_subheading_size = ?, page_test_description_color = ?, page_test_description_size = ?, page_test_description_class = ?, page_test_description_style = ?, page_test_background_color = ?, page_test_text_color = ?, page_test_active_flag = ? WHERE page_test_key = ?");
    $stmt->execute([
        $code,
        trim($input['page_test_title'] ?? '') ?: null,
        trim($input['page_test_meta_description'] ?? '') ?: null,
        trim($input['page_test_url'] ?? '') ?: null,
        trim($input['page_test_heading'] ?? '') ?: null,
        trim($input['page_test_subheading'] ?? '') ?: null,
        trim($input['page_test_description'] ?? '') ?: null,
        trim($input['page_test_heading_color'] ?? '') ?: null,
        trim($input['page_test_heading_size'] ?? '') ?: null,
        trim($input['page_test_subheading_color'] ?? '') ?: null,
        trim($input['page_test_subheading_size'] ?? '') ?: null,
        trim($input['page_test_description_color'] ?? '') ?: null,
        trim($input['page_test_description_size'] ?? '') ?: null,
        trim($input['page_test_description_class'] ?? '') ?: null,
        trim($input['page_test_description_style'] ?? '') ?: null,
        trim($input['page_test_background_color'] ?? '') ?: null,
        trim($input['page_test_text_color'] ?? '') ?: null,
        ($input['page_test_active_flag'] ?? true) ? 't' : 'f',
        $key,
    ]);
    jsonResponse(['ok' => true]);

case 'DELETE':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');
    $db->prepare("DELETE FROM yy_page_test WHERE page_test_key = ?")->execute([$key]);
    jsonResponse(['ok' => true]);

default:
    errorResponse('Method not allowed', 405);
}
