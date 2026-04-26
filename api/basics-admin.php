<?php
/**
 * Basics admin API.
 * POST actions: toggle (active/inactive), delete, save_settings
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';

if ($action === 'toggle') {
    $key = (int)($data['basics_key'] ?? 0);
    $active = !empty($data['active']);
    if (!$key) errorResponse('basics_key required');
    $db->prepare("UPDATE yy_feed_item SET feed_item_active_flag = ?, feed_item_revision_dtime = NOW() WHERE feed_item_key = ?")
       ->execute([$active ? 'TRUE' : 'FALSE', $key]);
    jsonResponse(['saved' => true]);
}

if ($action === 'delete') {
    $key = (int)($data['basics_key'] ?? 0);
    if (!$key) errorResponse('basics_key required');
    // Soft delete so YouTube sync won't re-add it
    $db->prepare("UPDATE yy_feed_item SET feed_item_active_flag = FALSE, feed_item_revision_dtime = NOW() WHERE feed_item_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

if ($action === 'save_settings') {
    $settings = $data['settings'] ?? [];
    $allowed = ['heading', 'heading-color', 'heading-bg', 'background', 'columns', 'records', 'label-size', 'label-color', 'spacing-horizontal', 'spacing-vertical'];
    foreach ($settings as $code => $val) {
        if (!in_array($code, $allowed)) continue;
        $val = trim($val);
        $stmt = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_scope_code = 'page' AND setting_group_code = 'basics' AND setting_code = ?");
        $stmt->execute([$val, $code]);
        if ($stmt->rowCount() === 0) {
            $db->prepare("INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_value_code, setting_value) VALUES ('page', 'basics', ?, 'text', ?)")
               ->execute([$code, $val]);
        }
    }
    // Invalidate cache
    $cacheFile = sys_get_temp_dir() . '/yada_basics_config.json';
    if (file_exists($cacheFile)) unlink($cacheFile);
    jsonResponse(['saved' => true]);
}

errorResponse('Unknown action');
