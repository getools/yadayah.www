<?php
/**
 * Public API: returns series for the homepage carousel.
 * Only includes series with series_number > 0, ordered by series_number.
 */
require_once __DIR__ . '/config.php';

$CACHE_FILE = sys_get_temp_dir() . '/yada_series_carousel.json';
$CACHE_TTL  = 86400;

if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    readfile($CACHE_FILE);
    exit;
}

$db = getDb();
$stmt = $db->query("
    SELECT series_key, series_number, series_label, series_name, series_summary, series_image
    FROM yy_series
    WHERE series_number > 0
    ORDER BY series_number
");

$json = json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
file_put_contents($CACHE_FILE, $json);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $json;
