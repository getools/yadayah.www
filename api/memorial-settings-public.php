<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();
$keys = ['memorial_title', 'memorial_subtitle', 'memorial_columns', 'memorial_auto_scroll'];
$result = [];
foreach ($keys as $key) {
    $stmt = $db->prepare("SELECT setting_value FROM yy_setting WHERE setting_code = ?");
    $stmt->execute([$key]);
    $result[$key] = $stmt->fetchColumn() ?: '';
}
jsonResponse($result);
