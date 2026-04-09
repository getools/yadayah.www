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
    $db->prepare("UPDATE yy_basics SET basics_active_flag = ? WHERE basics_key = ?")
       ->execute([$active ? 'TRUE' : 'FALSE', $key]);
    jsonResponse(['saved' => true]);
}

if ($action === 'delete') {
    $key = (int)($data['basics_key'] ?? 0);
    if (!$key) errorResponse('basics_key required');
    $db->prepare("DELETE FROM yy_basics WHERE basics_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

if ($action === 'save_settings') {
    $settings = $data['settings'] ?? [];
    $allowed = ['heading', 'heading-color', 'background', 'columns', 'records', 'label-size', 'label-color', 'spacing-horizontal', 'spacing-vertical'];
    foreach ($settings as $code => $val) {
        if (!in_array($code, $allowed)) continue;
        $stmt = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_scope_code = 'page' AND setting_group_code = 'basics' AND setting_code = ?");
        $stmt->execute([trim($val), $code]);
    }
    // Invalidate cache
    $cacheFile = sys_get_temp_dir() . '/yada_basics_config.json';
    if (file_exists($cacheFile)) unlink($cacheFile);
    jsonResponse(['saved' => true]);
}

errorResponse('Unknown action');
