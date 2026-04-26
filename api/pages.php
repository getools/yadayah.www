<?php
require_once __DIR__ . '/config.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);
$method = $_SERVER['REQUEST_METHOD'];

function rebuildAliasCache(PDO $db): void {
    $stmt = $db->query("
        SELECT a.alias_path, p.page_code
        FROM yy_page_alias a
        JOIN yy_page p ON a.page_key = p.page_key
        WHERE a.alias_active_flag = TRUE AND p.page_active_flag = TRUE
    ");
    $aliases = [];
    foreach ($stmt->fetchAll() as $row) {
        $aliases[$row['alias_path']] = $row['page_code'];
    }
    $cacheFile = sys_get_temp_dir() . '/yada_page_aliases.json';
    file_put_contents($cacheFile, json_encode($aliases));
}

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

        $aliases = $db->prepare("SELECT * FROM yy_page_alias WHERE page_key = ? ORDER BY alias_key");
        $aliases->execute([$key]);
        $row['aliases'] = $aliases->fetchAll();
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

    // Save aliases for a page
    if (!empty($input['page_key']) && isset($input['aliases'])) {
        $pageKey = (int)$input['page_key'];
        $check = $db->prepare("SELECT page_key FROM yy_page WHERE page_key = ?");
        $check->execute([$pageKey]);
        if (!$check->fetch()) errorResponse('Page not found', 404);

        $db->beginTransaction();
        try {
            foreach ($input['aliases'] as $a) {
                $path = trim($a['alias_path'] ?? '', ' /');
                if (!$path) continue;
                if (!empty($a['alias_key'])) {
                    $db->prepare("UPDATE yy_page_alias SET alias_path = ?, alias_active_flag = ? WHERE alias_key = ? AND page_key = ?")
                       ->execute([$path, ($a['alias_active_flag'] ?? true) ? 't' : 'f', (int)$a['alias_key'], $pageKey]);
                } else {
                    $db->prepare("INSERT INTO yy_page_alias (page_key, alias_path, alias_active_flag) VALUES (?, ?, ?) ON CONFLICT (alias_path) DO UPDATE SET page_key = EXCLUDED.page_key, alias_active_flag = EXCLUDED.alias_active_flag")
                       ->execute([$pageKey, $path, ($a['alias_active_flag'] ?? true) ? 't' : 'f']);
                }
            }
            if (isset($input['deleted_aliases'])) {
                foreach ($input['deleted_aliases'] as $delKey) {
                    $db->prepare("DELETE FROM yy_page_alias WHERE alias_key = ? AND page_key = ?")
                       ->execute([(int)$delKey, $pageKey]);
                }
            }
            // Rebuild alias redirect cache
            rebuildAliasCache($db);
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
    $footerSort = (int)($input['page_footer_sort'] ?? 0);
    $url = trim($input['page_url'] ?? '') ?: null;

    $heading = trim($input['page_heading'] ?? '') ?: null;
    $subheading = trim($input['page_subheading'] ?? '') ?: null;
    $description = trim($input['page_description'] ?? '') ?: null;
    $body = trim($input['page_body'] ?? '') ?: null;
    $footerCol = (int)($input['page_footer_col'] ?? 0);

    $headingColor = trim($input['page_heading_color'] ?? '') ?: null;
    $headingSize = trim($input['page_heading_size'] ?? '') ?: null;
    $subheadingColor = trim($input['page_subheading_color'] ?? '') ?: null;
    $subheadingSize = trim($input['page_subheading_size'] ?? '') ?: null;
    $descColor = trim($input['page_description_color'] ?? '') ?: null;
    $descSize = trim($input['page_description_size'] ?? '') ?: null;
    $bgColor = trim($input['page_background_color'] ?? '') ?: null;

    $stmt = $db->prepare("INSERT INTO yy_page (page_code, page_title, page_active_flag, page_toolbar, page_header_sort, page_footer_sort, page_footer_col, page_url, page_heading, page_subheading, page_description, page_body, page_heading_color, page_heading_size, page_subheading_color, page_subheading_size, page_description_color, page_description_size, page_background_color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING page_key");
    $stmt->execute([$code, $title, $active, $toolbar, $headerSort, $footerSort, $footerCol, $url, $heading, $subheading, $description, $body, $headingColor, $headingSize, $subheadingColor, $subheadingSize, $descColor, $descSize, $bgColor]);
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
    $footerSort = (int)($input['page_footer_sort'] ?? 0);
    $url = trim($input['page_url'] ?? '') ?: null;

    $heading = trim($input['page_heading'] ?? '') ?: null;
    $subheading = trim($input['page_subheading'] ?? '') ?: null;
    $description = trim($input['page_description'] ?? '') ?: null;
    $body = trim($input['page_body'] ?? '') ?: null;

    $headingColor = trim($input['page_heading_color'] ?? '') ?: null;
    $headingSize = trim($input['page_heading_size'] ?? '') ?: null;
    $subheadingColor = trim($input['page_subheading_color'] ?? '') ?: null;
    $subheadingSize = trim($input['page_subheading_size'] ?? '') ?: null;
    $descColor = trim($input['page_description_color'] ?? '') ?: null;
    $descSize = trim($input['page_description_size'] ?? '') ?: null;
    $bgColor = trim($input['page_background_color'] ?? '') ?: null;

    $stmt = $db->prepare("UPDATE yy_page SET page_code = ?, page_title = ?, page_active_flag = ?, page_toolbar = ?, page_header_sort = ?, page_footer_sort = ?, page_footer_col = ?, page_url = ?, page_heading = ?, page_subheading = ?, page_description = ?, page_body = ?, page_heading_color = ?, page_heading_size = ?, page_subheading_color = ?, page_subheading_size = ?, page_description_color = ?, page_description_size = ?, page_background_color = ? WHERE page_key = ?");
    $stmt->execute([$code, $title, $active, $toolbar, $headerSort, $footerSort, (int)($input['page_footer_col'] ?? 0), $url, $heading, $subheading, $description, $body, $headingColor, $headingSize, $subheadingColor, $subheadingSize, $descColor, $descSize, $bgColor, $key]);
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
