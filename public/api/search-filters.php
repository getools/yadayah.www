<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

$pdo = getDb();

$series = $pdo->query("
    SELECT series_key, series_label, series_name
    FROM yy_series
    ORDER BY series_sort
")->fetchAll();

$volumes = $pdo->query("
    SELECT volume_key, series_key, volume_label
    FROM yy_volume
    WHERE volume_active_flag = TRUE
    ORDER BY volume_sort
")->fetchAll();

jsonResponse(['series' => $series, 'volumes' => $volumes]);
