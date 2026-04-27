<?php
require_once __DIR__ . '/config.php';

$db = getDb();
$stmt = $db->query("SELECT asset_file FROM yy_asset WHERE asset_group_code = 'headerfooter' AND asset_active_flag = true AND asset_converting_flag = false AND asset_file LIKE '%.webm' ORDER BY asset_sort, asset_name");
$rows = $stmt->fetchAll();

$items = [];
foreach ($rows as $r) {
    $video = '/' . $r['asset_file'];
    $thumb = preg_replace('/\.webm$/i', '.jpg', $video);
    $mp4 = preg_replace('/\.webm$/i', '.mp4', $video);
    $items[] = ['video' => $video, 'mp4' => $mp4, 'thumb' => $thumb];
}

jsonResponse($items);
