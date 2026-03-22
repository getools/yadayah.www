<?php
require_once __DIR__ . '/config.php';

$db = getDb();
$stmt = $db->query("SELECT asset_file FROM yy_asset WHERE asset_group_code = 'headerfooter' AND asset_active_flag = true AND asset_file IS NOT NULL ORDER BY RANDOM() LIMIT 1");
$row = $stmt->fetch();

if ($row) {
    $file = $row['asset_file'];
    $thumb = preg_match('/\.webm$/i', $file) ? preg_replace('/\.webm$/i', '.jpg', $file) : null;
    jsonResponse(['file' => $file, 'thumb' => $thumb]);
} else {
    jsonResponse(['file' => null, 'thumb' => null]);
}
