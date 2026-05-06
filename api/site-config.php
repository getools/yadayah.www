<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$CACHE_FILE = sys_get_temp_dir() . '/yada_site_config.json';
$CACHE_TTL  = 86400; // 24 hours (cache is busted immediately on any config update)

if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    readfile($CACHE_FILE);
    exit;
}

$db = getDb();
// Two scopes are exposed publicly to the homepage:
//   'config'           — global toolbar / site-wide values (existing).
//   'page'/'home'      — home-page component switches set via Admin → Home.
// Home keys are namespaced with the 'home-' prefix on the wire so they
// can't collide with existing site-wide config keys.
$result = [];
foreach ($db->query("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'config'")->fetchAll() as $row) {
    $result[$row['setting_code']] = $row['setting_value'];
}
$homeStmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'page' AND setting_group_code = 'home'");
$homeStmt->execute();
foreach ($homeStmt->fetchAll() as $row) {
    $result['home-' . $row['setting_code']] = $row['setting_value'];
}
$json = json_encode($result, JSON_UNESCAPED_UNICODE);
file_put_contents($CACHE_FILE, $json);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
echo $json;
