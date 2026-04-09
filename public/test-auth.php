<?php
require_once __DIR__ . '/../api/config.php';
header('Content-Type: text/plain');
$db = getDb();
$stmt = $db->prepare("SELECT s.setting_code, us.user_setting_value FROM yy_user_setting us JOIN yy_setting s ON s.setting_key = us.setting_key WHERE us.user_key = 8 AND s.setting_scope_code = 'admin' AND s.setting_group_code = 'pages' ORDER BY s.setting_sort");
$stmt->execute();
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    echo $r['setting_code'] . ' => ' . $r['user_setting_value'] . ' (' . var_export($r['user_setting_value'] === '1', true) . ")\n";
}
