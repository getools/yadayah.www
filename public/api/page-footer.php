<?php
/**
 * Public API: returns footer link columns from yy_page.
 * Cached 24h, busted when pages are saved.
 */
require_once __DIR__ . '/config.php';

$CACHE_FILE = sys_get_temp_dir() . '/yada_page_footer.json';
$CACHE_TTL  = 86400;

if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    readfile($CACHE_FILE);
    exit;
}

$db = getDb();
$stmt = $db->query("
    SELECT page_title, page_url, page_code, page_footer_col
    FROM yy_page
    WHERE page_footer_col > 0 AND page_active_flag = TRUE
    ORDER BY page_footer_col, page_footer_sort, page_title
");
$rows = $stmt->fetchAll();

$cols = [1 => [], 2 => [], 3 => []];
foreach ($rows as $r) {
    $url = $r['page_url'] ?: '/' . $r['page_code'];
    $cols[(int)$r['page_footer_col']][] = [
        'title' => $r['page_title'],
        'url' => $url,
    ];
}

$json = json_encode($cols, JSON_UNESCAPED_UNICODE);
file_put_contents($CACHE_FILE, $json);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $json;
