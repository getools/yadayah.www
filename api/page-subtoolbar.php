<?php
/**
 * Returns the sub-toolbar items for a given page (or for a parent_key).
 * Consumed by /js/site-subtoolbar.js so each public page can render its
 * own tabs from yy_page.page_parent_key + page_parent_sort instead of
 * hardcoding the link list per HTML file.
 *
 *   GET  ?path=/translations            — looks up the page by url
 *   GET  ?path=/translations&self=1     — same; client may also pass current path
 *   GET  ?parent_key=9                  — directly ask for a parent's children
 *
 * Response:
 *   {
 *     "parent_key": 9,
 *     "parent": { "page_code": "translations", "page_title": "Translations", "page_url": "/translations" },
 *     "items":  [ { "page_key", "page_code", "page_title", "page_url", "parent_sort" }, ... ]
 *   }
 *
 * Empty items array (parent_key=null) means the page has no sub-toolbar —
 * the client hides the slot entirely.
 */
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();

$parentKey = null;

// Direct parent_key wins when supplied.
if (isset($_GET['parent_key']) && $_GET['parent_key'] !== '') {
    $parentKey = (int)$_GET['parent_key'];
} else {
    // Resolve via the page's URL. Caddy strips .html, so the route a user
    // visits is the no-extension form (e.g. /dss). Match case-insensitively
    // against page_url; fall back to page_code without leading slash.
    $path = (string)($_GET['path'] ?? '');
    if ($path === '') jsonResponse(['parent_key' => null, 'parent' => null, 'items' => []]);

    $stmt = $pdo->prepare("
        SELECT page_key, page_parent_key
          FROM yy_page
         WHERE lower(page_url) = lower(?)
            OR lower(page_code) = lower(?)
         LIMIT 1
    ");
    $codeFromPath = ltrim($path, '/');
    $stmt->execute([$path, $codeFromPath]);
    $row = $stmt->fetch();
    if (!$row || !$row['page_parent_key']) {
        jsonResponse(['parent_key' => null, 'parent' => null, 'items' => []]);
    }
    $parentKey = (int)$row['page_parent_key'];
}

// Parent row (for header rendering, if the client wants it).
$pStmt = $pdo->prepare("SELECT page_key, page_code, page_title, page_url FROM yy_page WHERE page_key = ?");
$pStmt->execute([$parentKey]);
$parent = $pStmt->fetch() ?: null;

// Children — only active pages, sorted by page_parent_sort then page_code.
$cStmt = $pdo->prepare("
    SELECT page_key, page_code, page_title, page_url, page_parent_sort
      FROM yy_page
     WHERE page_parent_key = ?
       AND page_active_flag = TRUE
     ORDER BY page_parent_sort, page_code
");
$cStmt->execute([$parentKey]);
$items = $cStmt->fetchAll();

jsonResponse([
    'parent_key' => $parentKey,
    'parent'     => $parent,
    'items'      => $items,
]);
