<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();

// Resolve page_key from page_code
function getPageKey(PDO $db, string $code): int {
    $stmt = $db->prepare("SELECT page_key FROM yy_page WHERE page_code = ?");
    $stmt->execute([$code]);
    $key = $stmt->fetchColumn();
    if (!$key) {
        http_response_code(404);
        echo json_encode(['error' => "Page '$code' not found"]);
        exit;
    }
    return (int)$key;
}

$pageCode = trim($_GET['page'] ?? '');
if (!$pageCode) errorResponse('page parameter required');

// GET: list page_feed rows + available feeds
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pageKey = getPageKey($db, $pageCode);

    $stmt = $db->prepare("
        SELECT pf.page_feed_key, pf.feed_key, pf.page_feed_filter_include,
               pf.page_feed_filter_exclude, pf.page_feed_sort,
               f.feed_name, f.feed_site_code, f.feed_type_code
        FROM yy_page_feed pf
        JOIN yy_feed f ON f.feed_key = pf.feed_key
        WHERE pf.page_key = ?
        ORDER BY pf.page_feed_sort, pf.page_feed_key
    ");
    $stmt->execute([$pageKey]);
    $assigned = $stmt->fetchAll();

    $feeds = $db->query("SELECT feed_key, feed_name, feed_site_code, feed_type_code FROM yy_feed WHERE feed_active_flag = TRUE ORDER BY feed_sort, feed_name")->fetchAll();

    jsonResponse(['assigned' => $assigned, 'feeds' => $feeds]);
}

// POST: add a feed association
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pageKey = getPageKey($db, $pageCode);
    $input   = json_decode(file_get_contents('php://input'), true);
    $feedKey = (int)($input['feed_key'] ?? 0);
    $include = trim($input['page_feed_filter_include'] ?? '');
    $exclude = trim($input['page_feed_filter_exclude'] ?? '');
    $sort    = (int)($input['page_feed_sort'] ?? 0);
    if (!$feedKey) errorResponse('feed_key required');
    $stmt = $db->prepare("INSERT INTO yy_page_feed (page_key, feed_key, page_feed_filter_include, page_feed_filter_exclude, page_feed_sort) VALUES (?,?,?,?,?) RETURNING page_feed_key");
    $stmt->execute([$pageKey, $feedKey, $include ?: null, $exclude ?: null, $sort]);
    jsonResponse(['page_feed_key' => $stmt->fetchColumn()]);
}

// PUT: update an existing association
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $pfKey = (int)($_GET['key'] ?? 0);
    if (!$pfKey) errorResponse('key required');
    $input   = json_decode(file_get_contents('php://input'), true);
    $feedKey = (int)($input['feed_key'] ?? 0);
    $include = trim($input['page_feed_filter_include'] ?? '');
    $exclude = trim($input['page_feed_filter_exclude'] ?? '');
    $sort    = (int)($input['page_feed_sort'] ?? 0);
    $sql = $feedKey
        ? "UPDATE yy_page_feed SET feed_key=?, page_feed_filter_include=?, page_feed_filter_exclude=?, page_feed_sort=? WHERE page_feed_key=?"
        : "UPDATE yy_page_feed SET page_feed_filter_include=?, page_feed_filter_exclude=?, page_feed_sort=? WHERE page_feed_key=?";
    $params = $feedKey
        ? [$feedKey, $include ?: null, $exclude ?: null, $sort, $pfKey]
        : [$include ?: null, $exclude ?: null, $sort, $pfKey];
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['saved' => true]);
}

// DELETE: remove an association
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $pfKey = (int)($_GET['key'] ?? 0);
    if (!$pfKey) errorResponse('key required');
    $stmt = $db->prepare("DELETE FROM yy_page_feed WHERE page_feed_key = ?");
    $stmt->execute([$pfKey]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
