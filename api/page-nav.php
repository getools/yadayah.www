<?php
/**
 * Public API: site navigation from yy_page.
 * Returns main nav (page_toolbar=1) and sub-toolbar (page_toolbar=2) items,
 * ordered by page_header_sort. Cached 24h.
 */
require_once __DIR__ . '/config.php';

$CACHE_FILE = sys_get_temp_dir() . '/yada_page_nav.json';
$CACHE_TTL  = 86400;

if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    readfile($CACHE_FILE);
    exit;
}

$db = getDb();
$stmt = $db->query("
    SELECT page_code, page_title, page_url, page_toolbar, page_header_sort
    FROM yy_page
    WHERE page_active_flag = TRUE AND page_toolbar > 0
    ORDER BY page_toolbar, page_header_sort, page_title
");
$pages = $stmt->fetchAll();

$result = ['main' => [], 'sub' => [], 'logo' => null];
foreach ($pages as $p) {
    $url  = $p['page_url'] ?: '/' . $p['page_code'];
    $item = ['title' => $p['page_title'], 'url' => $url, 'code' => $p['page_code']];
    if ($p['page_toolbar'] == 1) {
        $result['main'][] = $item;
    } elseif ($p['page_toolbar'] == 2) {
        $result['sub'][] = $item;
    }
}

$cfgStmt = $db->query("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'config' AND setting_code IN ('logo','logo-height','title-prefix','toolbar-main-text-size','toolbar-sub-text-size','logo-margin-top','logo-margin-bottom','toolbar-main-margin-top','toolbar-main-margin-bottom','toolbar-main-text-color','toolbar-sub-margin-top','toolbar-sub-margin-bottom','toolbar-sub-bg-color','toolbar-sub-text-color')");
foreach ($cfgStmt->fetchAll() as $row) {
    $v = $row['setting_value'];
    if (!strlen((string)$v)) continue;
    switch ($row['setting_code']) {
        case 'logo':                         $result['logo']                        = $v; break;
        case 'title-prefix':                 $result['title_prefix']                = $v; break;
        case 'toolbar-main-text-size':       $result['toolbar_main_text_size']      = $v; break;
        case 'toolbar-main-text-color':      $result['toolbar_main_text_color']     = $v; break;
        case 'toolbar-main-margin-top':      $result['toolbar_main_margin_top']     = $v; break;
        case 'toolbar-main-margin-bottom':   $result['toolbar_main_margin_bottom']  = $v; break;
        case 'toolbar-sub-text-size':        $result['toolbar_sub_text_size']       = $v; break;
        case 'toolbar-sub-text-color':       $result['toolbar_sub_text_color']      = $v; break;
        case 'toolbar-sub-bg-color':         $result['toolbar_sub_bg_color']        = $v; break;
        case 'toolbar-sub-margin-top':       $result['toolbar_sub_margin_top']      = $v; break;
        case 'toolbar-sub-margin-bottom':    $result['toolbar_sub_margin_bottom']   = $v; break;
        case 'logo-height':                  $result['logo_height']                 = $v; break;
        case 'logo-margin-top':              $result['logo_margin_top']             = $v; break;
        case 'logo-margin-bottom':           $result['logo_margin_bottom']          = $v; break;
    }
}

$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($CACHE_FILE, $json);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
echo $json;
