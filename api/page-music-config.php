<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$CACHE_FILE = sys_get_temp_dir() . '/yada_music_config.json';
$CACHE_TTL  = 86400;

if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    readfile($CACHE_FILE);
    exit;
}

$db = getDb();
$stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'page' AND setting_group_code = 'music' ORDER BY setting_sort");
$stmt->execute();
$rows = $stmt->fetchAll();
$result = [];
foreach ($rows as $row) {
    $result[$row['setting_code']] = $row['setting_value'];
}
$json = json_encode($result, JSON_UNESCAPED_UNICODE);
file_put_contents($CACHE_FILE, $json);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
echo $json;
