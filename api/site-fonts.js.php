<?php
/**
 * Public JS-data endpoint — emits the active font registry as a JS global
 * (window.YY_FONTS). Loaded via <script src="/api/site-fonts.js.php"> so
 * the data is available synchronously by the time TinyMCE init runs. The
 * helper /js/admin-fonts-tinymce.js prefers this over the JSON fetch
 * because TinyMCE's setup callback isn't awaited.
 */
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit;
}

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
$fonts = array_map(function($r) {
    return [
        'key'     => (int)$r['font_key'],
        'display' => $r['font_display_name'],
        'stack'   => $r['font_css_stack'],
        'glyph'   => $r['font_glyph_text'] ?: null,
        'group'   => $r['font_section_group'] !== null ? (int)$r['font_section_group'] : null,
        'sort'    => (int)$r['font_sort'],
        'note'    => $r['font_note'] ?: null,
    ];
}, $stmt->fetchAll());

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=60');
echo 'window.YY_FONTS = ' . json_encode($fonts, JSON_UNESCAPED_UNICODE) . ';' . "\n";
