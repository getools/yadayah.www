<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$SCOPE = 'page';
$GROUP = 'timeline';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = ? AND setting_group_code = ? ORDER BY setting_sort");
    $stmt->execute([$SCOPE, $GROUP]);
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[$row['setting_code']] = $row['setting_value'];
    }
    jsonResponse($result);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $stmt = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_scope_code = ? AND setting_group_code = ? AND setting_code = ?");
    foreach ($input as $code => $value) {
        $stmt->execute([$value, $SCOPE, $GROUP, $code]);
    }
    // Bust page config cache
    $cacheFile = sys_get_temp_dir() . '/yada_timeline_config.json';
    if (file_exists($cacheFile)) @unlink($cacheFile);
    jsonResponse(['saved' => true]);
}

errorResponse('Method not allowed', 405);
