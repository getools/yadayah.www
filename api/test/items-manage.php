<?php
/**
 * TEST endpoint — feed-item + category management for the prototype page
 * editor's Items section. Keyed by page_key (the feed/yy_page key the
 * section pulls from). Mirrors /api/admin-feed.php's writes exactly, but
 * page_key-based and scoped to the /test editor:
 *
 *   GET    ?action=categories&page_key=N
 *   POST   ?action=category&page_key=N    body {category_title, category_subtitle?, category_sort?}
 *   PUT    ?action=category&key=K         body {category_title?, category_subtitle?, category_sort?}
 *   DELETE ?action=category&key=K
 *   PUT    ?action=item&key=K&page_key=N   body {sort?, episode?, category_key?}
 *
 * Sort & Episode are item-level (yy_feed_item). Category assignment lives in
 * yy_feed_item_category, page-scoped (one category per page per item).
 *
 * ── New-system category list (yy_category, scoped per section_key) ──
 * The prototype editor's Categories panel manages each Items section's OWN
 * category set in yy_category. These are display/definition only for now:
 * item COUNTS are bridged from the matching page category (page_key + slug),
 * and per-item ASSIGNMENT still writes yy_feed_item_category (FK →
 * yy_feed_page_category), so the 1,074 live assignments and production
 * category pages are untouched.
 *   GET    ?action=section_categories&section_key=K&page_key=N
 *   POST   ?action=section_category&section_key=K   body {category_title, category_subtitle?, category_sort?}
 *   PUT    ?action=section_category&key=K           body {category_title?, category_subtitle?, category_sort?}
 *   DELETE ?action=section_category&key=K
 */
require_once __DIR__ . '/../config.php';
requireAuth();

$db      = getDb();
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? '';
$key     = (int)($_GET['key'] ?? 0);
$pageKey = (int)($_GET['page_key'] ?? 0);

function tslug(string $t): string {
    return trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($t)))), '-');
}

// ── Categories ──────────────────────────────────────────────────────────
if ($action === 'categories' && $method === 'GET') {
    if (!$pageKey) errorResponse('page_key required');
    $st = $db->prepare("
        SELECT c.category_key, c.category_title, c.category_subtitle, c.category_slug, c.category_sort,
               (SELECT COUNT(*) FROM yy_feed_item_category fic
                  JOIN yy_feed_item fi ON fi.feed_item_key = fic.feed_item_key
                 WHERE fic.category_key = c.category_key AND fi.feed_item_active_flag = TRUE) AS item_count
          FROM yy_feed_page_category c
         WHERE c.page_key = ? AND c.category_active_flag = TRUE
         ORDER BY c.category_sort, c.category_title
    ");
    $st->execute([$pageKey]);
    jsonResponse(['categories' => $st->fetchAll()]);
}

if ($action === 'category' && $method === 'POST') {
    if (!$pageKey) errorResponse('page_key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($d['category_title'] ?? '');
    if ($title === '') errorResponse('Category title is required');
    $sub  = trim($d['category_subtitle'] ?? '');
    $sort = (int)($d['category_sort'] ?? 0);
    $st = $db->prepare("INSERT INTO yy_feed_page_category (page_key, category_title, category_subtitle, category_slug, category_sort) VALUES (?, ?, ?, ?, ?) RETURNING category_key");
    $st->execute([$pageKey, $title, $sub ?: null, tslug($title), $sort]);
    jsonResponse(['saved' => true, 'category_key' => (int)$st->fetchColumn()]);
}

if ($action === 'category' && $method === 'PUT') {
    if (!$key) errorResponse('category key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $f = []; $p = [];
    if (isset($d['category_title'])) {
        $f[] = 'category_title = ?'; $p[] = trim($d['category_title']);
        $f[] = 'category_slug = ?';  $p[] = tslug($d['category_title']);
    }
    if (array_key_exists('category_subtitle', $d)) {
        $f[] = 'category_subtitle = ?'; $p[] = trim($d['category_subtitle']) ?: null;
    }
    if (isset($d['category_sort'])) {
        $f[] = 'category_sort = ?'; $p[] = (int)$d['category_sort'];
    }
    if (!$f) errorResponse('Nothing to update');
    $f[] = 'category_revision_dtime = NOW()'; $p[] = $key;
    $db->prepare("UPDATE yy_feed_page_category SET " . implode(', ', $f) . " WHERE category_key = ?")->execute($p);
    jsonResponse(['saved' => true]);
}

if ($action === 'category' && $method === 'DELETE') {
    if (!$key) errorResponse('category key required');
    // yy_feed_item_category rows removed by ON DELETE CASCADE.
    $db->prepare("DELETE FROM yy_feed_page_category WHERE category_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

// ── Section categories (NEW system — yy_category, scoped by section_key) ──
// The editor's Categories panel reads/writes these. Item counts are bridged
// from the page's yy_feed_page_category (matched by page_key + slug, which is
// unique within a page) so the column stays meaningful without re-pointing the
// live yy_feed_item_category FK.
if ($action === 'section_categories' && $method === 'GET') {
    $sectionKey = (int)($_GET['section_key'] ?? 0);
    if (!$sectionKey) errorResponse('section_key required');
    $st = $db->prepare("
        SELECT yc.category_key, yc.category_title, yc.category_subtitle, yc.category_slug, yc.category_sort,
               COALESCE((
                   SELECT COUNT(*) FROM yy_feed_item_category fic
                     JOIN yy_feed_item fi ON fi.feed_item_key = fic.feed_item_key
                     JOIN yy_feed_page_category pc ON pc.category_key = fic.category_key
                    WHERE pc.page_key = ? AND pc.category_slug = yc.category_slug
                      AND fi.feed_item_active_flag = TRUE
               ), 0) AS item_count
          FROM yy_category yc
         WHERE yc.section_key = ? AND yc.category_active_flag = TRUE
         ORDER BY yc.category_sort, yc.category_title
    ");
    $st->execute([$pageKey, $sectionKey]);
    jsonResponse(['categories' => $st->fetchAll()]);
}

if ($action === 'section_category' && $method === 'POST') {
    $sectionKey = (int)($_GET['section_key'] ?? 0);
    if (!$sectionKey) errorResponse('section_key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($d['category_title'] ?? '');
    if ($title === '') errorResponse('Category title is required');
    $sub  = trim($d['category_subtitle'] ?? '');
    $sort = (int)($d['category_sort'] ?? 0);
    $st = $db->prepare("INSERT INTO yy_category (section_key, category_title, category_subtitle, category_slug, category_sort) VALUES (?, ?, ?, ?, ?) RETURNING category_key");
    $st->execute([$sectionKey, $title, $sub ?: null, tslug($title), $sort]);
    jsonResponse(['saved' => true, 'category_key' => (int)$st->fetchColumn()]);
}

if ($action === 'section_category' && $method === 'PUT') {
    if (!$key) errorResponse('category key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $f = []; $p = [];
    if (isset($d['category_title'])) {
        $f[] = 'category_title = ?'; $p[] = trim($d['category_title']);
        $f[] = 'category_slug = ?';  $p[] = tslug($d['category_title']);
    }
    if (array_key_exists('category_subtitle', $d)) {
        $f[] = 'category_subtitle = ?'; $p[] = trim($d['category_subtitle']) ?: null;
    }
    if (isset($d['category_sort'])) {
        $f[] = 'category_sort = ?'; $p[] = (int)$d['category_sort'];
    }
    if (!$f) errorResponse('Nothing to update');
    $p[] = $key;
    // category_revision_dtime/num are bumped by the yy_category _rev trigger.
    $db->prepare("UPDATE yy_category SET " . implode(', ', $f) . " WHERE category_key = ?")->execute($p);
    jsonResponse(['saved' => true]);
}

if ($action === 'section_category' && $method === 'DELETE') {
    if (!$key) errorResponse('category key required');
    // yy_category is NOT referenced by yy_feed_item_category, so removing a
    // section category leaves live item assignments intact (interim design).
    // The _rev trigger records the delete.
    $db->prepare("DELETE FROM yy_category WHERE category_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

// ── Item (sort / episode / category) ─────────────────────────────────────
if ($action === 'item' && $method === 'PUT') {
    if (!$key)     errorResponse('item key required');
    if (!$pageKey) errorResponse('page_key required');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $f = []; $p = [];
    if (isset($d['sort'])) {
        $f[] = 'feed_item_sort = ?'; $p[] = (int)$d['sort'];
    }
    if (array_key_exists('episode', $d)) {
        $f[] = 'feed_item_episode = ?';
        $p[] = ($d['episode'] !== null && $d['episode'] !== '') ? trim($d['episode']) : null;
    }
    // Category → yy_feed_item_category, page-scoped (one per page per item).
    if (array_key_exists('category_key', $d)) {
        $catKey = $d['category_key'] ? (int)$d['category_key'] : null;
        $db->prepare("DELETE FROM yy_feed_item_category WHERE feed_item_key = ? AND category_key IN (SELECT category_key FROM yy_feed_page_category WHERE page_key = ?)")
           ->execute([$key, $pageKey]);
        if ($catKey) {
            $db->prepare("INSERT INTO yy_feed_item_category (feed_item_key, category_key) VALUES (?, ?) ON CONFLICT (feed_item_key, category_key) DO NOTHING")
               ->execute([$key, $catKey]);
        }
    }
    if ($f) {
        $f[] = 'feed_item_revision_dtime = NOW()'; $p[] = $key;
        $db->prepare("UPDATE yy_feed_item SET " . implode(', ', $f) . " WHERE feed_item_key = ?")->execute($p);
    }
    jsonResponse(['saved' => true]);
}

errorResponse('Unknown action');
