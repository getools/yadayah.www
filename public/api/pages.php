<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

case 'GET':
    // Single page with sections
    if (!empty($_GET['key'])) {
        $key = (int)$_GET['key'];
        $page = $db->prepare("SELECT * FROM yy_page WHERE page_key = ?");
        $page->execute([$key]);
        $row = $page->fetch();
        if (!$row) errorResponse('Not found', 404);

        $sections = $db->prepare("SELECT * FROM yy_page_section WHERE page_key = ? ORDER BY page_section_sort, page_section_key");
        $sections->execute([$key]);
        $row['sections'] = $sections->fetchAll();
        jsonResponse($row);
    }

    // List all pages
    $stmt = $db->query("SELECT p.*, (SELECT COUNT(*) FROM yy_page_section ps WHERE ps.page_key = p.page_key) AS section_count FROM yy_page p ORDER BY page_code");
    jsonResponse($stmt->fetchAll());

case 'POST':
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) errorResponse('Invalid JSON');

    // Save sections for a page
    if (!empty($input['page_key']) && isset($input['sections'])) {
        $pageKey = (int)$input['page_key'];

        // Verify page exists
        $check = $db->prepare("SELECT page_key FROM yy_page WHERE page_key = ?");
        $check->execute([$pageKey]);
        if (!$check->fetch()) errorResponse('Page not found', 404);

        $db->beginTransaction();
        try {
            foreach ($input['sections'] as $s) {
                if (!empty($s['page_section_key'])) {
                    // Update existing
                    $stmt = $db->prepare("UPDATE yy_page_section SET page_section_code = ?, page_section_value = ?, page_section_active_flag = ?, page_section_sort = ? WHERE page_section_key = ? AND page_key = ?");
                    $stmt->execute([
                        $s['page_section_code'] ?? '',
                        $s['page_section_value'] ?? '',
                        ($s['page_section_active_flag'] ?? true) ? 't' : 'f',
                        (int)($s['page_section_sort'] ?? 0),
                        (int)$s['page_section_key'],
                        $pageKey,
                    ]);
                } else {
                    // Insert new
                    $stmt = $db->prepare("INSERT INTO yy_page_section (page_key, page_section_code, page_section_value, page_section_active_flag, page_section_sort) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $pageKey,
                        $s['page_section_code'] ?? '',
                        $s['page_section_value'] ?? '',
                        ($s['page_section_active_flag'] ?? true) ? 't' : 'f',
                        (int)($s['page_section_sort'] ?? 0),
                    ]);
                }
            }

            // Delete removed sections
            if (isset($input['deleted_sections'])) {
                foreach ($input['deleted_sections'] as $delKey) {
                    $db->prepare("DELETE FROM yy_page_section WHERE page_section_key = ? AND page_key = ?")
                       ->execute([(int)$delKey, $pageKey]);
                }
            }

            $db->commit();
            jsonResponse(['ok' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            errorResponse('Save failed: ' . $e->getMessage());
        }
    }

    // Create new page
    $code = trim($input['page_code'] ?? '');
    if (!$code) errorResponse('page_code is required');

    $title = trim($input['page_title'] ?? '') ?: null;
    $active = ($input['page_active_flag'] ?? true) ? 't' : 'f';
    $toolbar = (int)($input['page_toolbar'] ?? 1);
    $headerSort = (int)($input['page_header_sort'] ?? 0);
    $url = trim($input['page_url'] ?? '') ?: null;

    $stmt = $db->prepare("INSERT INTO yy_page (page_code, page_title, page_active_flag, page_toolbar, page_header_sort, page_url) VALUES (?, ?, ?, ?, ?, ?) RETURNING page_key");
    $stmt->execute([$code, $title, $active, $toolbar, $headerSort, $url]);
    $row = $stmt->fetch();
    $navCache = sys_get_temp_dir() . '/yada_page_nav.json';
    if (file_exists($navCache)) @unlink($navCache);
    jsonResponse(['page_key' => $row['page_key']], 201);

case 'PUT':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) errorResponse('Invalid JSON');

    $existing = $db->prepare("SELECT page_key FROM yy_page WHERE page_key = ?");
    $existing->execute([$key]);
    if (!$existing->fetch()) errorResponse('Not found', 404);

    $code = trim($input['page_code'] ?? '');
    if (!$code) errorResponse('page_code is required');

    $title = trim($input['page_title'] ?? '') ?: null;
    $active = ($input['page_active_flag'] ?? true) ? 't' : 'f';
    $toolbar = (int)($input['page_toolbar'] ?? 1);
    $headerSort = (int)($input['page_header_sort'] ?? 0);
    $url = trim($input['page_url'] ?? '') ?: null;

    $stmt = $db->prepare("UPDATE yy_page SET page_code = ?, page_title = ?, page_active_flag = ?, page_toolbar = ?, page_header_sort = ?, page_url = ? WHERE page_key = ?");
    $stmt->execute([$code, $title, $active, $toolbar, $headerSort, $url, $key]);
    $navCache = sys_get_temp_dir() . '/yada_page_nav.json';
    if (file_exists($navCache)) @unlink($navCache);
    jsonResponse(['ok' => true]);

case 'DELETE':
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('Missing key');

    $db->prepare("DELETE FROM yy_page WHERE page_key = ?")->execute([$key]);
    $navCache = sys_get_temp_dir() . '/yada_page_nav.json';
    if (file_exists($navCache)) @unlink($navCache);
    jsonResponse(['ok' => true]);

default:
    errorResponse('Method not allowed', 405);
}
