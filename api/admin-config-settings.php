<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$SCOPE = 'config';
$CACHE_FILE     = sys_get_temp_dir() . '/yada_site_config.json';
$NAV_CACHE_FILE = sys_get_temp_dir() . '/yada_page_nav.json';
function bustConfigCache() {
    global $CACHE_FILE, $NAV_CACHE_FILE;
    if (file_exists($CACHE_FILE))     @unlink($CACHE_FILE);
    if (file_exists($NAV_CACHE_FILE)) @unlink($NAV_CACHE_FILE);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $key = isset($_GET['key']) ? (int)$_GET['key'] : null;
    if ($key) {
        $stmt = $db->prepare("SELECT * FROM yy_setting WHERE setting_key = ? AND setting_scope_code = ?");
        $stmt->execute([$key, $SCOPE]);
        $row = $stmt->fetch();
        if (!$row) errorResponse('Not found', 404);
        jsonResponse($row);
    }
    $stmt = $db->prepare("SELECT * FROM yy_setting WHERE setting_scope_code = ? ORDER BY setting_group_code, setting_sort, setting_code");
    $stmt->execute([$SCOPE]);
    jsonResponse($stmt->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $group = trim($input['setting_group_code'] ?? '');
    $code  = trim($input['setting_code'] ?? '');
    $vcode = trim($input['setting_value_code'] ?? 'text');
    $sort  = (int)($input['setting_sort'] ?? 0);
    $label = trim($input['setting_label'] ?? '');
    $value = $input['setting_value'] ?? null;
    if (!$code) errorResponse('Code is required');
    $stmt = $db->prepare("INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_label, setting_value_code, setting_sort, setting_value) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING setting_key");
    $stmt->execute([$SCOPE, $group, $code, $label, $vcode, $sort, $value]);
    bustConfigCache();
    jsonResponse(['setting_key' => $stmt->fetchColumn()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $key = isset($_GET['key']) ? (int)$_GET['key'] : 0;
    if (!$key) errorResponse('Key required');
    $input = json_decode(file_get_contents('php://input'), true);
    $group = trim($input['setting_group_code'] ?? '');
    $code  = trim($input['setting_code'] ?? '');
    $vcode = trim($input['setting_value_code'] ?? 'text');
    $sort  = (int)($input['setting_sort'] ?? 0);
    $label = trim($input['setting_label'] ?? '');
    $value = $input['setting_value'] ?? null;
    if (!$code) errorResponse('Code is required');
    $stmt = $db->prepare("UPDATE yy_setting SET setting_group_code=?, setting_code=?, setting_label=?, setting_value_code=?, setting_sort=?, setting_value=? WHERE setting_key=? AND setting_scope_code=?");
    $stmt->execute([$group, $code, $label, $vcode, $sort, $value, $key, $SCOPE]);
    bustConfigCache();
    jsonResponse(['saved' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $key = isset($_GET['key']) ? (int)$_GET['key'] : 0;
    if (!$key) errorResponse('Key required');
    $stmt = $db->prepare("DELETE FROM yy_setting WHERE setting_key = ? AND setting_scope_code = ?");
    $stmt->execute([$key, $SCOPE]);
    bustConfigCache();
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
