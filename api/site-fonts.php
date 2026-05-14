<?php
/**
 * Public read endpoint for the central font registry.
 *
 *   GET /api/site-fonts.php
 *     → { fonts: [{ key, display, stack, glyph, group, sort, note }, ...] }
 *
 * Returned in render order: section_group NULL first (alphabetical),
 * then group 1, then group 2, etc. Within each group, sort by font_sort
 * then alphabetical. Inactive rows are excluded.
 *
 * Cached for 60s — admin font changes propagate within a minute without
 * pummeling Postgres on every editor mount. Pages that need real-time
 * updates can ?cb=<rand>.
 */
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') errorResponse('Method not allowed', 405);

$db = getDb();
$stmt = $db->query("
    SELECT font_key, font_display_name, font_css_stack, font_glyph_text,
           font_section_group, font_sort, font_note
      FROM yy_font
     WHERE font_active_flag = TRUE
     ORDER BY (CASE WHEN font_section_group IS NULL THEN 0 ELSE 1 END),
              font_section_group NULLS FIRST,
              font_sort,
              font_display_name
");
$rows = $stmt->fetchAll();

$out = array_map(function($r) {
    return [
        'key'     => (int)$r['font_key'],
        'display' => $r['font_display_name'],
        'stack'   => $r['font_css_stack'],
        'glyph'   => $r['font_glyph_text'] ?: null,
        'group'   => $r['font_section_group'] !== null ? (int)$r['font_section_group'] : null,
        'sort'    => (int)$r['font_sort'],
        'note'    => $r['font_note'] ?: null,
    ];
}, $rows);

header('Cache-Control: public, max-age=60');
jsonResponse(['fonts' => $out]);
