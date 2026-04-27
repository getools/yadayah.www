<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

// GET — list all memorial records
if ($method === 'GET') {
    $stmt = $db->query('SELECT * FROM yy_memorial ORDER BY memorial_sort, memorial_key');
    jsonResponse(['items' => $stmt->fetchAll()]);
}

// POST — create new memorial
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['name_full'])) {
        errorResponse('Name is required', 400);
    }
    $stmt = $db->prepare('INSERT INTO yy_memorial (memorial_name_full, memorial_name_last, memorial_name_first, memorial_summary, memorial_image_file, memorial_image_url, memorial_bio_url, memorial_sort, memorial_active_flag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING memorial_key');
    $stmt->execute([
        trim($input['name_full']),
        trim($input['name_last'] ?? ''),
        trim($input['name_first'] ?? ''),
        trim($input['summary'] ?? ''),
        trim($input['image_file'] ?? ''),
        trim($input['image_url'] ?? ''),
        trim($input['bio_url'] ?? ''),
        intval($input['sort'] ?? 0),
        ($input['active'] ?? true) ? true : false,
    ]);
    jsonResponse(['saved' => true, 'key' => (int)$stmt->fetchColumn()]);
}

// PUT — update existing memorial
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['key'])) errorResponse('Key is required', 400);
    if (empty($input['name_full'])) errorResponse('Name is required', 400);
    $stmt = $db->prepare('UPDATE yy_memorial SET memorial_name_full = ?, memorial_name_last = ?, memorial_name_first = ?, memorial_summary = ?, memorial_image_file = ?, memorial_image_url = ?, memorial_bio_url = ?, memorial_sort = ?, memorial_active_flag = ? WHERE memorial_key = ?');
    $stmt->execute([
        trim($input['name_full']),
        trim($input['name_last'] ?? ''),
        trim($input['name_first'] ?? ''),
        trim($input['summary'] ?? ''),
        trim($input['image_file'] ?? ''),
        trim($input['image_url'] ?? ''),
        trim($input['bio_url'] ?? ''),
        intval($input['sort'] ?? 0),
        ($input['active'] ?? true) ? true : false,
        intval($input['key']),
    ]);
    jsonResponse(['saved' => true]);
}

// DELETE — delete memorial
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['key'])) errorResponse('Key is required', 400);
    $stmt = $db->prepare('DELETE FROM yy_memorial WHERE memorial_key = ?');
    $stmt->execute([intval($input['key'])]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
