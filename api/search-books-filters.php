<?php
/**
 * Public list of active series + volumes for the search-books filter
 * dropdowns. No auth — same visibility as /api/search.php itself.
 *
 *   GET → { series: [...], volumes: [...] }
 *     series:  [{ series_key, series_number, series_label, series_sort }]
 *     volumes: [{ volume_key, series_key, volume_number, volume_label, volume_sort }]
 *
 * Volumes are returned in full so the client can filter to "volumes for
 * the picked series" without a second fetch.
 */
require_once __DIR__ . '/config.php';

$pdo = getDb();
$series = $pdo->query("
    SELECT series_key, series_number, series_label, series_sort
      FROM yy_series
     ORDER BY series_sort, series_number
")->fetchAll();

$volumes = $pdo->query("
    SELECT volume_key, series_key, volume_number, volume_label, volume_sort
      FROM yy_volume
     WHERE volume_active_flag = TRUE
     ORDER BY series_key, volume_sort, volume_number
")->fetchAll();

jsonResponse([
    'series'  => $series,
    'volumes' => $volumes,
]);
