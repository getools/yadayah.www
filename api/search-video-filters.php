<?php
// Group + Category filter list for the prototype global search bar.
// "Group" = a yy_page that has at least one video attached to it via
// yy_feed_item_page; "Category" = a yy_feed_page_category attached to
// the same page. Only active rows on both sides.
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();

// Only pages whose admin opted-in via page_item_search_flag = TRUE
// appear in the Search → Video Group dropdown. The flag is editable
// in admin-pages.html; defaults to FALSE so new pages are opted-out
// until explicitly enabled. EXISTS check still filters out pages
// with no actual feed items attached.
$groups = $pdo->query("
    SELECT p.page_key,
           p.page_code,
           p.page_title,
           p.page_url
    FROM yy_page p
    WHERE p.page_active_flag = TRUE
      AND p.page_item_search_flag = TRUE
      AND EXISTS (
        SELECT 1 FROM yy_feed_item_page fip
        WHERE fip.page_key = p.page_key
      )
    ORDER BY p.page_header_sort, p.page_title
")->fetchAll();

$categories = $pdo->query("
    SELECT category_key,
           page_key,
           category_title,
           category_slug
    FROM yy_feed_page_category
    WHERE category_active_flag = TRUE
    ORDER BY page_key, category_sort, category_title
")->fetchAll();

jsonResponse([
    'groups'     => $groups,
    'categories' => $categories,
]);
