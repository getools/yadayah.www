<?php
// Group + Category filter list for the prototype global search bar.
// "Group" = a yy_page that has at least one video attached to it via
// yy_feed_item_page; "Category" = a yy_category of an Items section on
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

// Categories come from the page system: each Items section's own
// yy_category set, keyed to the page that owns the section. Only
// categories with at least one filed item (yy_section_item) are listed.
$categories = $pdo->query("
    SELECT c.category_key,
           s.page_key,
           c.category_title,
           c.category_slug
    FROM yy_category c
    JOIN yy_section s ON s.section_key = c.section_key
    WHERE c.category_active_flag = TRUE
      AND s.section_active_flag = TRUE
      AND s.page_key IS NOT NULL
      AND EXISTS (
        SELECT 1 FROM yy_section_item si
        WHERE si.category_key = c.category_key
      )
    ORDER BY s.page_key, c.category_sort, c.category_title
")->fetchAll();

jsonResponse([
    'groups'     => $groups,
    'categories' => $categories,
]);
