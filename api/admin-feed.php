<?php
/**
 * Unified Admin API for feed-based pages (Basics, Vlog, Invite, DoYouYada, Music).
 * The ?page= parameter determines which page's categories and items to manage.
 *
 * GET  ?page=X&type=categories   — list categories for this page
 * GET  ?page=X&type=videos       — list items for this page
 * POST ?page=X&type=category     — create category
 * PUT  ?page=X&type=category&key=N — update category
 * DELETE ?page=X&type=category&key=N — delete category
 * PUT  ?page=X&type=video&key=N  — update item
 * DELETE ?page=X&type=video&key=N — soft-delete item
 * POST ?page=X&type=audio&key=N  — upload MP3
 * DELETE ?page=X&type=audio&key=N — remove MP3
 * GET  ?page=X&type=config       — load page settings
 * PUT  ?page=X&type=config       — save page settings
 * POST ?page=X&type=sync         — trigger feed sync
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/feed-helpers.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$type = $_GET['type'] ?? '';
$key = (int)($_GET['key'] ?? 0);
$pageCode = $_GET['page'] ?? '';

if (!$pageCode) errorResponse('page parameter required');

// Resolve page_key from page_code
$pageStmt = $db->prepare("SELECT page_key, page_code, page_title FROM yy_page WHERE page_code = ?");
$pageStmt->execute([$pageCode]);
$pageRow = $pageStmt->fetch();
if (!$pageRow) errorResponse('Unknown page: ' . $pageCode);
$PAGE_KEY = (int)$pageRow['page_key'];
$PAGE_CODE = $pageRow['page_code'];
$PAGE_TITLE = $pageRow['page_title'];

// Load feed configuration for this page
function loadFeedConfig(PDO $db, string $pageCode): array {
    $stmt = $db->prepare("
        SELECT f.feed_key, fp.feed_page_filter_include, fp.feed_page_filter_exclude, fp.feed_page_filter_orientation
        FROM yy_feed_page fp
        JOIN yy_feed f ON f.feed_key = fp.feed_key
        JOIN yy_page p ON p.page_key = fp.page_key
        WHERE p.page_code = ?
        ORDER BY fp.feed_page_sort, fp.feed_page_key
        LIMIT 1
    ");
    $stmt->execute([$pageCode]);
    $row = $stmt->fetch();
    return [
        'feed_key'    => $row ? (int)$row['feed_key'] : 1,
        'include'     => $row ? array_filter(array_map('trim', explode(',', $row['feed_page_filter_include'] ?? ''))) : [],
        'exclude'     => $row ? array_filter(array_map('trim', explode(',', $row['feed_page_filter_exclude'] ?? ''))) : [],
        'orientation' => $row['feed_page_filter_orientation'] ?? null,
    ];
}

function slugify(string $text): string {
    return trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($text)))), '-');
}

// ── Categories ──
if ($type === 'categories' && $method === 'GET') {
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
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($data['category_title'] ?? '');
    if (!$title) errorResponse('Category title is required');
    $subtitle = trim($data['category_subtitle'] ?? '');
    $sort = (int)($data['category_sort'] ?? 0);
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
    if (isset($data['category_title'])) {
        $fields[] = 'category_title = ?';
        $params[] = trim($data['category_title']);
        $fields[] = 'category_slug = ?';
        $params[] = slugify($data['category_title']);
    }
    if (array_key_exists('category_subtitle', $data)) {
        $fields[] = 'category_subtitle = ?';
        $params[] = trim($data['category_subtitle']) ?: null;
    }
    if (isset($data['category_sort'])) {
        $fields[] = 'category_sort = ?';
        $params[] = (int)$data['category_sort'];
    }
    if (isset($data['category_slug'])) {
        $fields[] = 'category_slug = ?';
        $params[] = slugify($data['category_slug']);
    }
    if (empty($fields)) errorResponse('Nothing to update');
    $fields[] = 'category_revision_dtime = NOW()';
    $params[] = $key;
    $db->prepare("UPDATE yy_feed_page_category SET " . implode(', ', $fields) . " WHERE category_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

if ($type === 'category' && $method === 'DELETE') {
    if (!$key) errorResponse('Category key required');
    $db->prepare("UPDATE yy_feed_item SET feed_item_category_key = NULL, feed_item_episode = NULL, feed_item_revision_dtime = NOW() WHERE feed_item_category_key = ?")->execute([$key]);
    $db->prepare("DELETE FROM yy_feed_page_category WHERE category_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

// ── Videos / Items ──
if ($type === 'videos' && $method === 'GET') {
    $cfg = loadFeedConfig($db, $PAGE_CODE);

    $where = "fi.feed_key = ?";
    $params = [$cfg['feed_key']];

    // Include filters
    if ($cfg['include']) {
        $inc = [];
        foreach ($cfg['include'] as $term) {
            $like = filterLikePattern($term);
            $inc[] = "(fi.feed_item_tags ILIKE ? OR fi.feed_item_title ILIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        $where .= " AND (" . implode(' OR ', $inc) . ")";
    }
    // Exclude filters
    foreach ($cfg['exclude'] as $term) {
        $like = filterLikePattern($term);
        $where .= " AND fi.feed_item_title NOT ILIKE ? AND COALESCE(fi.feed_item_tags,'') NOT ILIKE ?";
        $params[] = $like;
        $params[] = $like;
    }
    // Orientation filter
    if ($cfg['orientation']) {
        $where .= " AND fi.feed_item_orientation = ?";
        $params[] = $cfg['orientation'];
    }

    $stmt = $db->prepare("
        SELECT fi.feed_item_key, fi.feed_item_external_id, fi.feed_item_title,
               fi.feed_item_thumbnail, fi.feed_item_url, fi.feed_item_sort, fi.feed_item_active_flag,
               fi.feed_item_category_key, fi.feed_item_episode, fi.feed_item_audio_file,
               c.category_title, c.category_sort
        FROM yy_feed_item fi
        LEFT JOIN yy_feed_page_category c ON fi.feed_item_category_key = c.category_key
        WHERE $where
        ORDER BY COALESCE(c.category_sort, 999), c.category_title NULLS LAST,
                 fi.feed_item_sort,
                 CASE WHEN fi.feed_item_episode ~ '^\d+$' THEN fi.feed_item_episode::integer ELSE 2147483647 END,
                 fi.feed_item_episode NULLS LAST,
                 fi.feed_item_publish_dtime DESC NULLS LAST, fi.feed_item_title
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $videos = [];
    foreach ($rows as $r) {
        $videos[] = [
            'video_key'       => (int)$r['feed_item_key'],
            'video_id'        => $r['feed_item_external_id'],
            'title'           => $r['feed_item_title'],
            'thumbnail'       => $r['feed_item_thumbnail'],
            'url'             => $r['feed_item_url'],
            'sort'            => (int)$r['feed_item_sort'],
            'active_flag'     => $r['feed_item_active_flag'] === true || $r['feed_item_active_flag'] === 't',
            'category_key'    => $r['feed_item_category_key'] ? (int)$r['feed_item_category_key'] : null,
            'category_title'  => $r['category_title'] ?? null,
            'episode'         => $r['feed_item_episode'] ?? null,
            'audio_file'      => $r['feed_item_audio_file'] ?? null,
        ];
    }

    jsonResponse(['videos' => $videos]);
}

if ($type === 'video' && $method === 'PUT') {
    if (!$key) errorResponse('Video key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $fields = [];
    $params = [];

    if (isset($data['title'])) {
        $fields[] = 'feed_item_title = ?';
        $params[] = trim($data['title']);
    }
    if (array_key_exists('category_key', $data)) {
        $fields[] = 'feed_item_category_key = ?';
        $params[] = $data['category_key'] ? (int)$data['category_key'] : null;
    }
    if (isset($data['sort'])) {
        $fields[] = 'feed_item_sort = ?';
        $params[] = (int)$data['sort'];
    }
    if (array_key_exists('episode', $data)) {
        $fields[] = 'feed_item_episode = ?';
        $params[] = $data['episode'] !== null && $data['episode'] !== '' ? trim($data['episode']) : null;
    }
    if (isset($data['active_flag'])) {
        $fields[] = 'feed_item_active_flag = ?';
        $params[] = $data['active_flag'] ? 't' : 'f';
    }
    if (empty($fields)) errorResponse('Nothing to update');

    $fields[] = 'feed_item_revision_dtime = NOW()';
    $params[] = $key;
    $db->prepare("UPDATE yy_feed_item SET " . implode(', ', $fields) . " WHERE feed_item_key = ?")->execute($params);
    jsonResponse(['saved' => true]);
}

if ($type === 'video' && $method === 'DELETE') {
    if (!$key) errorResponse('Video key required');
    $db->prepare("UPDATE yy_feed_item SET feed_item_active_flag = FALSE, feed_item_revision_dtime = NOW() WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

// ── Audio Upload ──
if ($type === 'audio' && $method === 'POST' && $key) {
    $file = $_FILES['audio'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) errorResponse('No file uploaded');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'mp3') errorResponse('Only MP3 files are allowed');

    $filename = 'audio_' . $key . '_' . time() . '.mp3';
    $destDir = '/var/www/html/u/audio';
    if (!is_dir($destDir)) $destDir = dirname(__DIR__) . '/public/u/audio';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $dest = $destDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) errorResponse('Failed to save file');

    $path = 'u/audio/' . $filename;
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = ? WHERE feed_item_key = ?")->execute([$path, $key]);
    jsonResponse(['saved' => true, 'audio_file' => $path]);
}

if ($type === 'audio' && $method === 'DELETE' && $key) {
    $db->prepare("UPDATE yy_feed_item SET feed_item_audio_file = NULL WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['removed' => true]);
}

// ── Page Settings (Config) ──
if ($type === 'config' && $method === 'GET') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'page' AND setting_group_code = ?");
    $stmt->execute([$PAGE_CODE]);
    $cfg = [];
    foreach ($stmt->fetchAll() as $r) $cfg[$r['setting_code']] = $r['setting_value'];
    jsonResponse($cfg);
}

if ($type === 'config' && $method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach ($data as $code => $value) {
        $db->prepare("
            INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_value)
            VALUES ('page', ?, ?, ?)
            ON CONFLICT (setting_scope_code, setting_group_code, setting_code)
            DO UPDATE SET setting_value = EXCLUDED.setting_value
        ")->execute([$PAGE_CODE, $code, $value]);
    }
    jsonResponse(['saved' => true]);
}

// ── Sync ──
if ($type === 'sync' && ($method === 'POST' || $method === 'GET')) {
    $cfg = loadFeedConfig($db, $PAGE_CODE);
    if ($cfg['feed_key']) {
        $_GET['feed_key'] = $cfg['feed_key'];
        define('SYNC_CALLED_FROM_PARENT', true);
        require __DIR__ . '/sync-youtube.php';
        exit;
    }
    jsonResponse(['synced' => false, 'message' => 'No feed configured for ' . $PAGE_CODE]);
}

errorResponse('Invalid request', 400);
