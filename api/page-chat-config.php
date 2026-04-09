<?php
/**
 * Public chat/community config. Returns label and icon settings.
 */
require_once __DIR__ . '/config.php';

$db = getDb();
$stmt = $db->query("
    SELECT setting_code, setting_value
    FROM yy_setting
    WHERE setting_scope_code = 'page' AND setting_group_code = 'chat'
      AND setting_value IS NOT NULL AND setting_value != ''
");
$cfg = [];
foreach ($stmt->fetchAll() as $r) {
    $cfg[$r['setting_code']] = $r['setting_value'];
}
jsonResponse($cfg);
