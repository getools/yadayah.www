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

// GET: list feed_page rows + available feeds
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pageKey = getPageKey($db, $pageCode);

    $stmt = $db->prepare("
        SELECT fp.feed_page_key, fp.feed_key, fp.feed_page_name,
               fp.feed_page_type_code, fp.feed_page_list,
               fp.feed_page_filter_include, fp.feed_page_filter_exclude,
               fp.feed_page_duration_code, fp.feed_page_per_page,
               fp.feed_page_paging_code, fp.feed_page_api_endpoint,
               fp.feed_page_sort, fp.feed_page_active_flag,
               f.feed_name, f.feed_site_code
        FROM yy_feed_page fp
        JOIN yy_feed f ON f.feed_key = fp.feed_key
        WHERE fp.page_key = ?
        ORDER BY fp.feed_page_sort, fp.feed_page_key
    ");
    $stmt->execute([$pageKey]);
    $assigned = $stmt->fetchAll();

    $feeds = $db->query("SELECT feed_key, feed_name, feed_site_code FROM yy_feed WHERE feed_active_flag = TRUE ORDER BY feed_name")->fetchAll();

    jsonResponse(['assigned' => $assigned, 'feeds' => $feeds]);
}

// POST: add a feed_page association
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pageKey = getPageKey($db, $pageCode);
    $input   = json_decode(file_get_contents('php://input'), true);
    $feedKey = (int)($input['feed_key'] ?? 0);
    if (!$feedKey) errorResponse('feed_key required');

    $stmt = $db->prepare("
        INSERT INTO yy_feed_page (feed_key, page_key, feed_page_name, feed_page_type_code, feed_page_list,
            feed_page_filter_include, feed_page_filter_exclude, feed_page_duration_code,
            feed_page_per_page, feed_page_paging_code, feed_page_api_endpoint, feed_page_sort)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?) RETURNING feed_page_key
    ");
    $stmt->execute([
        $feedKey, $pageKey,
        trim($input['feed_page_name'] ?? '') ?: null,
        trim($input['feed_page_type_code'] ?? '') ?: null,
        trim($input['feed_page_list'] ?? '') ?: null,
        trim($input['feed_page_filter_include'] ?? '') ?: null,
        trim($input['feed_page_filter_exclude'] ?? '') ?: null,
        trim($input['feed_page_duration_code'] ?? '') ?: null,
        (int)($input['feed_page_per_page'] ?? 0),
        trim($input['feed_page_paging_code'] ?? '') ?: 'none',
        trim($input['feed_page_api_endpoint'] ?? '') ?: null,
        (int)($input['feed_page_sort'] ?? 0),
    ]);
    jsonResponse(['feed_page_key' => $stmt->fetchColumn()]);
}

// PUT: update an existing feed_page
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $fpKey = (int)($_GET['key'] ?? 0);
    if (!$fpKey) errorResponse('key required');
    $input = json_decode(file_get_contents('php://input'), true);

    $fields = [
        'feed_key', 'feed_page_name', 'feed_page_type_code', 'feed_page_list',
        'feed_page_filter_include', 'feed_page_filter_exclude', 'feed_page_duration_code',
        'feed_page_per_page', 'feed_page_paging_code', 'feed_page_api_endpoint', 'feed_page_sort',
        'feed_page_active_flag'
    ];
    $sets = [];
    $vals = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $input)) {
            $sets[] = "$f = ?";
            $vals[] = $input[$f];
        }
    }
    if ($sets) {
        $vals[] = $fpKey;
        $db->prepare("UPDATE yy_feed_page SET " . implode(', ', $sets) . " WHERE feed_page_key = ?")->execute($vals);
    }
    jsonResponse(['saved' => true]);
}

// DELETE: remove a feed_page
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $fpKey = (int)($_GET['key'] ?? 0);
    if (!$fpKey) errorResponse('key required');
    $stmt = $db->prepare("DELETE FROM yy_feed_page WHERE feed_page_key = ?");
    $stmt->execute([$fpKey]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
