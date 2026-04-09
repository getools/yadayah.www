<?php
/**
 * Admin API for Basics categories and video management.
 * GET ?type=categories — list categories
 * GET ?type=videos — list videos with category info
 * POST ?type=category — create category
 * PUT ?type=category&key=N — update category
 * DELETE ?type=category&key=N — delete category
 * PUT ?type=video&key=N — update video category/sort
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$type = $_GET['type'] ?? '';
$key = (int)($_GET['key'] ?? 0);

// ── Categories ──
if ($type === 'categories' && $method === 'GET') {
    $stmt = $db->query("
        SELECT c.*, COUNT(b.basics_key) AS video_count
        FROM yy_basics_category c
        LEFT JOIN yy_basics b ON b.basics_category_key = c.basics_category_key AND b.basics_active_flag = TRUE
        GROUP BY c.basics_category_key
        ORDER BY c.basics_category_sort, c.basics_category_title
    ");
    jsonResponse(['categories' => $stmt->fetchAll()]);
}

if ($type === 'category' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $label = trim($data['basics_category_title'] ?? '');
    if (!$label) errorResponse('Category label is required');
    $sort = (int)($data['basics_category_sort'] ?? 0);
    $subtitle = trim($data['basics_category_subtitle'] ?? '');
    $stmt = $db->prepare("INSERT INTO yy_basics_category (basics_category_title, basics_category_subtitle, basics_category_sort) VALUES (?, ?, ?) RETURNING basics_category_key");
    $stmt->execute([$label, $subtitle ?: null, $sort]);
    jsonResponse(['saved' => true, 'basics_category_key' => $stmt->fetchColumn()]);
}

if ($type === 'category' && $method === 'PUT') {
    if (!$key) errorResponse('Category key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $params = [];
    if (isset($data['basics_category_title'])) {
        $fields[] = 'basics_category_title = ?';
        $params[] = trim($data['basics_category_title']);
    }
    if (isset($data['basics_category_subtitle'])) {
        $fields[] = 'basics_category_subtitle = ?';
        $params[] = trim($data['basics_category_subtitle']) ?: null;
    }
    if (isset($data['basics_category_sort'])) {
        $fields[] = 'basics_category_sort = ?';
        $params[] = (int)$data['basics_category_sort'];
    }
    if (empty($fields)) errorResponse('Nothing to update');
    $params[] = $key;
    $db->prepare("UPDATE yy_basics_category SET " . implode(', ', $fields) . " WHERE basics_category_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

if ($type === 'category' && $method === 'DELETE') {
    if (!$key) errorResponse('Category key required');
    // Unlink videos first
    $db->prepare("UPDATE yy_basics SET basics_category_key = NULL WHERE basics_category_key = ?")->execute([$key]);
    $db->prepare("DELETE FROM yy_basics_category WHERE basics_category_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

// ── Videos ──
if ($type === 'videos' && $method === 'GET') {
    $stmt = $db->query("
        SELECT b.basics_key, b.basics_video_id, b.basics_title, b.basics_thumbnail,
               b.basics_sort, b.basics_category_key, b.basics_active_flag,
               c.basics_category_title
        FROM yy_basics b
        LEFT JOIN yy_basics_category c ON b.basics_category_key = c.basics_category_key
        ORDER BY COALESCE(c.basics_category_sort, 999), c.basics_category_title, b.basics_sort, b.basics_title
    ");
    jsonResponse(['videos' => $stmt->fetchAll()]);
}

if ($type === 'video' && $method === 'PUT') {
    if (!$key) errorResponse('Video key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $params = [];
    if (isset($data['basics_title'])) {
        $fields[] = 'basics_title = ?';
        $params[] = trim($data['basics_title']);
    }
    if (array_key_exists('basics_category_key', $data)) {
        $fields[] = 'basics_category_key = ?';
        $params[] = $data['basics_category_key'] ? (int)$data['basics_category_key'] : null;
    }
    if (isset($data['basics_sort'])) {
        $fields[] = 'basics_sort = ?';
        $params[] = (int)$data['basics_sort'];
    }
    if (isset($data['basics_active_flag'])) {
        $fields[] = 'basics_active_flag = ?';
        $params[] = (bool)$data['basics_active_flag'];
    }
    if (empty($fields)) errorResponse('Nothing to update');
    $params[] = $key;
    $db->prepare("UPDATE yy_basics SET " . implode(', ', $fields) . " WHERE basics_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

errorResponse('Invalid request', 400);
