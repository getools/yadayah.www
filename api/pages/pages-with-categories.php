<?php
/**
 * Helper for the Items-section editor's "Also include items categorized in"
 * picker: every Items section that has its own categories (yy_category), with
 * its page and how many items it has filed under a category.
 *
 *   GET → [ { section_key, section_label, section_title, section_active_flag,
 *             page_key, page_code, page_title, page_active_flag,
 *             category_count, categorized_items }, ... ]
 *
 * Auth required. (Replaced the page + legacy-category catalogue, retired with
 * yy_feed_page / yy_feed_page_category on 2026-10-11.)
 */
require_once __DIR__ . '/../config.php';
requireAuth();
$db = getDb();

$rows = $db->query("
    SELECT s.section_key, s.section_label, s.section_title, s.section_active_flag,
           p.page_key, p.page_code, p.page_title, p.page_active_flag,
           (SELECT count(*) FROM yy_category c
             WHERE c.section_key = s.section_key AND c.category_active_flag = TRUE) AS category_count,
           (SELECT count(*) FROM yy_section_item si
             WHERE si.section_key = s.section_key AND si.category_key IS NOT NULL) AS categorized_items
    FROM yy_section s
    JOIN yy_page p ON p.page_key = s.page_key
    WHERE s.section_type = 'items'
      AND EXISTS (SELECT 1 FROM yy_category c WHERE c.section_key = s.section_key)
    ORDER BY COALESCE(NULLIF(p.page_title, ''), p.page_code), s.section_sort, s.section_key
")->fetchAll();

foreach ($rows as &$r) {
    $r['section_key']       = (int)$r['section_key'];
    $r['page_key']          = (int)$r['page_key'];
    $r['category_count']    = (int)$r['category_count'];
    $r['categorized_items'] = (int)$r['categorized_items'];
}
unset($r);
jsonResponse($rows);
