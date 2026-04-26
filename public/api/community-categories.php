<?php
/**
 * Community categories API.
 * GET: list active categories with topic counts.
 * POST: create category (moderator/admin only).
 * PUT: update category (moderator/admin only).
 * DELETE: deactivate category (moderator/admin only).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/community-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$userKey = $_SESSION['user_key'] ?? null;

if ($method === 'GET') {
    $includeInactive = isset($_GET['all']) && $userKey && isModOrAdmin($db, $userKey);

    $parentKey = $_GET['parent_key'] ?? null;
    $parentSlug = trim($_GET['parent'] ?? '');
    $where = $includeInactive ? 'WHERE 1=1' : 'WHERE c.category_active_flag = TRUE';

    if ($parentKey !== null) {
        $where .= " AND c.parent_key = " . (int)$parentKey;
    } elseif ($parentSlug) {
        $where .= " AND c.parent_key = (SELECT category_key FROM yy_community_category WHERE category_slug = " . $db->quote($parentSlug) . " LIMIT 1)";
    } else {
        // Default: show topic categories (children of 'topics' section)
        $where .= " AND c.parent_key = (SELECT category_key FROM yy_community_category WHERE category_slug = 'topics' LIMIT 1)";
    }
    $stmt = $db->query("
        SELECT c.category_key, c.category_name, c.category_slug, c.category_description,
               c.category_color, c.category_sort, c.category_active_flag,
               c.parent_key,
               COUNT(t.topic_key) AS topic_count
        FROM yy_community_category c
        LEFT JOIN yy_community_topic t ON t.category_key = c.category_key AND t.topic_active_flag = TRUE
        {$where}
        GROUP BY c.category_key
        ORDER BY c.category_sort, c.category_name
    ");
    jsonResponse(['categories' => $stmt->fetchAll()]);
}

// All write operations require moderator or admin
if (!$userKey) errorResponse('Login required', 401);
if (!isModOrAdmin($db, $userKey)) errorResponse('Moderator or admin access required', 403);

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($data['category_name'] ?? '');
    if (!$name) errorResponse('Category name is required');

    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));
    $slug = trim($slug, '-');
    if (!$slug) $slug = 'category-' . time();

    // Check slug uniqueness
    $stmt = $db->prepare("SELECT 1 FROM yy_community_category WHERE category_slug = ?");
    $stmt->execute([$slug]);
    if ($stmt->fetchColumn()) $slug .= '-' . substr(uniqid(), -4);

    $stmt = $db->prepare("
        INSERT INTO yy_community_category (category_name, category_slug, category_description, category_color, category_sort)
        VALUES (?, ?, ?, ?, ?)
        RETURNING category_key
    ");
    $stmt->execute([
        $name,
        $slug,
        trim($data['category_description'] ?? ''),
        trim($data['category_color'] ?? '#31345A'),
        (int)($data['category_sort'] ?? 50),
    ]);
    jsonResponse(['saved' => true, 'category_key' => $stmt->fetchColumn()]);
}

if ($method === 'PUT') {
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('category key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $fields = [];
    $params = [];

    if (isset($data['category_name'])) {
        $name = trim($data['category_name']);
        if (!$name) errorResponse('Category name cannot be empty');
        $fields[] = 'category_name = ?';
        $params[] = $name;
    }
    if (isset($data['category_description'])) {
        $fields[] = 'category_description = ?';
        $params[] = trim($data['category_description']);
    }
    if (isset($data['category_color'])) {
        $fields[] = 'category_color = ?';
        $params[] = trim($data['category_color']);
    }
    if (isset($data['category_sort'])) {
        $fields[] = 'category_sort = ?';
        $params[] = (int)$data['category_sort'];
    }
    if (isset($data['category_active_flag'])) {
        $fields[] = 'category_active_flag = ?';
        $params[] = (bool)$data['category_active_flag'];
    }

    if (empty($fields)) errorResponse('Nothing to update');
    $params[] = $key;
    $db->prepare("UPDATE yy_community_category SET " . implode(', ', $fields) . " WHERE category_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

if ($method === 'DELETE') {
    $key = (int)($_GET['key'] ?? 0);
    if (!$key) errorResponse('category key required');
    // Soft delete
    $db->prepare("UPDATE yy_community_category SET category_active_flag = FALSE WHERE category_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
