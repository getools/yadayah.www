<?php
/**
 * Admin API for Basics categories and video management.
 * Uses yy_feed_page_category (page_key=20) for categories
 * and feed_item_category_key on yy_feed_item for assignment.
 *
 * GET ?type=categories — list categories
 * GET ?type=videos — list basics videos from yy_feed_item
 * POST ?type=category — create category
 * PUT ?type=category&key=N — update category
 * DELETE ?type=category&key=N — delete category (videos get uncategorized)
 * PUT ?type=video&key=N — update video (key = feed_item_key)
 * DELETE ?type=video&key=N — soft-delete video
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$type = $_GET['type'] ?? '';
$key = (int)($_GET['key'] ?? 0);

$PAGE_KEY = 20; // basics

function loadBasicsFeedConfig(PDO $db): array {
    $stmt = $db->query("
        SELECT f.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude
        FROM yy_feed_page fp
        JOIN yy_feed f ON f.feed_key = fp.feed_key
        JOIN yy_page p ON p.page_key = fp.page_key
        WHERE p.page_code = 'basics'
        ORDER BY fp.feed_page_sort, fp.feed_page_key
        LIMIT 1
    ");
    $row = $stmt->fetch();
    return [
        'feed_key' => $row ? (int)$row['feed_key'] : 1,
        'include'  => $row ? array_filter(array_map('trim', explode(',', $row['feed_page_filter_include'] ?? ''))) : ['#Basics'],
        'exclude'  => $row ? array_filter(array_map('trim', explode(',', $row['feed_page_filter_exclude'] ?? ''))) : [],
    ];
}

function slugify(string $text): string {
    return trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($text)))), '-');
}

// ── Categories ──
if ($type === 'categories' && $method === 'GET') {
    global $PAGE_KEY;
    $catStmt = $db->prepare("
        SELECT c.category_key, c.category_title, c.category_subtitle, c.category_slug, c.category_sort,
               (SELECT COUNT(*) FROM yy_feed_item fi WHERE fi.feed_item_category_key = c.category_key AND fi.feed_item_active_flag = TRUE) AS video_count
        FROM yy_feed_page_category c
        WHERE c.page_key = ? AND c.category_active_flag = TRUE
        ORDER BY c.category_sort, c.category_title
    ");
    $catStmt->execute([$PAGE_KEY]);
    jsonResponse(['categories' => $catStmt->fetchAll()]);
}

if ($type === 'category' && $method === 'POST') {
    global $PAGE_KEY;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($data['category_title'] ?? $data['basics_category_title'] ?? '');
    if (!$title) errorResponse('Category title is required');
    $subtitle = trim($data['category_subtitle'] ?? $data['basics_category_subtitle'] ?? '');
    $sort = (int)($data['category_sort'] ?? $data['basics_category_sort'] ?? 0);
    $slug = slugify($title);
    $stmt = $db->prepare("INSERT INTO yy_feed_page_category (page_key, category_title, category_subtitle, category_slug, category_sort) VALUES (?, ?, ?, ?, ?) RETURNING category_key");
    $stmt->execute([$PAGE_KEY, $title, $subtitle ?: null, $slug, $sort]);
    jsonResponse(['saved' => true, 'category_key' => $stmt->fetchColumn()]);
}

if ($type === 'category' && $method === 'PUT') {
    if (!$key) errorResponse('Category key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $params = [];
    foreach (['category_title' => 'basics_category_title', 'category_subtitle' => 'basics_category_subtitle'] as $newName => $oldName) {
        $val = $data[$newName] ?? $data[$oldName] ?? null;
        if ($val !== null) {
            $fields[] = "$newName = ?";
            $params[] = trim($val) ?: null;
        }
    }
    $sortVal = $data['category_sort'] ?? $data['basics_category_sort'] ?? null;
    if ($sortVal !== null) {
        $fields[] = 'category_sort = ?';
        $params[] = (int)$sortVal;
    }
    if (isset($data['category_slug'])) {
        $fields[] = 'category_slug = ?';
        $params[] = slugify($data['category_slug']);
    } elseif (isset($data['category_title'])) {
        $fields[] = 'category_slug = ?';
        $params[] = slugify($data['category_title']);
    }
    if (empty($fields)) errorResponse('Nothing to update');
    $fields[] = 'category_revision_dtime = NOW()';
    $params[] = $key;
    $db->prepare("UPDATE yy_feed_page_category SET " . implode(', ', $fields) . " WHERE category_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

if ($type === 'category' && $method === 'DELETE') {
    if (!$key) errorResponse('Category key required');
    $db->prepare("UPDATE yy_feed_item SET feed_item_category_key = NULL, feed_item_revision_dtime = NOW() WHERE feed_item_category_key = ?")->execute([$key]);
    $db->prepare("DELETE FROM yy_feed_page_category WHERE category_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

// ── Videos ──
if ($type === 'videos' && $method === 'GET') {
    global $PAGE_KEY;
    $cfg = loadBasicsFeedConfig($db);

    $where = "fi.feed_key = ?";
    $params = [$cfg['feed_key']];
    if ($cfg['include']) {
        $inc = [];
        foreach ($cfg['include'] as $term) {
            $inc[] = "(fi.feed_item_tags ILIKE ? OR COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) ILIKE ?)";
            $params[] = '%' . $term . '%';
            $params[] = '%' . $term . '%';
        }
        $where .= " AND (" . implode(' OR ', $inc) . ")";
    }
    foreach ($cfg['exclude'] as $term) {
        $where .= " AND COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) NOT ILIKE ?";
        $params[] = '%' . $term . '%';
    }

    $stmt = $db->prepare("
        SELECT fi.feed_item_key, fi.feed_item_external_id, COALESCE(fi.feed_item_title_override, fi.feed_item_title_import) AS feed_item_title,
               fi.feed_item_thumbnail, fi.feed_item_url, fi.feed_item_sort, fi.feed_item_active_flag,
               fi.feed_item_category_key, fi.feed_item_audio_file,
               c.category_title, c.category_sort
        FROM yy_feed_item fi
        LEFT JOIN yy_feed_page_category c ON fi.feed_item_category_key = c.category_key
        WHERE $where
        ORDER BY COALESCE(c.category_sort, 999), fi.feed_item_sort, COALESCE(fi.feed_item_publish_override_dtime, fi.feed_item_publish_import_dtime) DESC NULLS LAST, COALESCE(fi.feed_item_title_override, fi.feed_item_title_import)
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $videos = [];
    foreach ($rows as $r) {
        $videos[] = [
            'basics_key'            => (int)$r['feed_item_key'],
            'basics_video_id'       => $r['feed_item_external_id'],
            'basics_title'          => $r['feed_item_title'],
            'basics_thumbnail'      => $r['feed_item_thumbnail'],
            'basics_url'            => $r['feed_item_url'],
            'basics_sort'           => (int)$r['feed_item_sort'],
            'basics_active_flag'    => $r['feed_item_active_flag'] === true || $r['feed_item_active_flag'] === 't',
            'basics_category_key'   => $r['feed_item_category_key'] ? (int)$r['feed_item_category_key'] : null,
            'basics_category_title' => $r['category_title'] ?? null,
            'basics_audio_file'    => $r['feed_item_audio_file'] ?? null,
        ];
    }

    jsonResponse(['videos' => $videos]);
}

if ($type === 'video' && $method === 'PUT') {
    if (!$key) errorResponse('Video key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $params = [];

    if (isset($data['basics_title'])) {
        $fields[] = 'feed_item_title_override = ?';
        $params[] = trim($data['basics_title']);
    }
    if (array_key_exists('basics_category_key', $data)) {
        $catKey = $data['basics_category_key'] ? (int)$data['basics_category_key'] : null;
        $fields[] = 'feed_item_category_key = ?';
        $params[] = $catKey;
        // Keep tags in sync for backward compat
        $fields[] = 'feed_item_tags = ?';
        $params[] = $catKey ? ('#Basics,' . $catKey) : '#Basics';
    }
    if (isset($data['basics_sort'])) {
        $fields[] = 'feed_item_sort = ?';
        $params[] = (int)$data['basics_sort'];
    }
    if (isset($data['basics_active_flag'])) {
        $fields[] = 'feed_item_active_flag = ?';
        $params[] = (bool)$data['basics_active_flag'];
    }
    if (empty($fields)) errorResponse('Nothing to update');

    $fields[] = 'feed_item_revision_dtime = NOW()';
    $params[] = $key;
    $db->prepare("UPDATE yy_feed_item SET " . implode(', ', $fields) . " WHERE feed_item_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

// Upload MP3 for a feed item
if ($type === 'audio' && $_SERVER['REQUEST_METHOD'] === 'POST' && $key) {
    $file = $_FILES['audio'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) errorResponse('No file uploaded');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'mp3') errorResponse('Only MP3 files are allowed');
    $filename = 'audio_' . $key . '_' . time() . '.mp3';
    $destDir = dirname(__DIR__) . '/public/u/audio';
    if (is_dir('/var/www/html/u/audio')) $destDir = '/var/www/html/u/audio';
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename)) errorResponse('Failed to save file');
    $path = 'u/audio/' . $filename;
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ? WHERE feed_item_key = ?")->execute([$path, $key]);
    jsonResponse(['saved' => true, 'audio_file' => $path]);
}

if ($type === 'audio' && $_SERVER['REQUEST_METHOD'] === 'DELETE' && $key) {
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = NULL WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['removed' => true]);
}

if ($type === 'video' && $method === 'DELETE') {
    if (!$key) errorResponse('Video key required');
    $db->prepare("UPDATE yy_feed_item SET feed_item_active_flag = FALSE, feed_item_revision_dtime = NOW() WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Invalid request', 400);
